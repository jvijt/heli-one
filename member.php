<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
Auth::requireLogin();

$pdo = Database::connection();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$year = (int)date('Y');
$error = '';
$message = '';

$member = [
    'id' => 0, 'member_number' => '', 'first_name' => '', 'last_name' => '', 'address' => '',
    'postal_code' => '', 'city' => '', 'birth_date' => '', 'mobile' => '', 'email' => '', 'weight_kg' => '',
    'member_since' => date('Y-m-d'), 'member_type' => 'supporting', 'status' => 'active', 'photo_path' => '', 'notes' => ''
];
$membership = ['payment_status' => 'unpaid', 'paid_at' => '', 'free_flight_entitled' => 1, 'free_flight_date' => ''];
$tagNames = [];

function load_member(PDO $pdo, int $id, int $year): array
{
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $m = $stmt->fetch();
    if (!$m) { http_response_code(404); exit('Lid niet gevonden.'); }
    $ms = $pdo->prepare('SELECT * FROM memberships WHERE member_id = :id AND membership_year = :year');
    $ms->execute(['id' => $id, 'year' => $year]);
    $membership = $ms->fetch() ?: ['payment_status' => 'unpaid', 'paid_at' => '', 'free_flight_entitled' => 1, 'free_flight_date' => ''];
    $tags = $pdo->prepare('SELECT t.name FROM tags t INNER JOIN member_tags mt ON mt.tag_id=t.id WHERE mt.member_id=:id ORDER BY t.name');
    $tags->execute(['id' => $id]);
    return [$m, $membership, array_column($tags->fetchAll(), 'name')];
}

if ($id > 0) { [$member, $membership, $tagNames] = load_member($pdo, $id, $year); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $memberType = in_array($_POST['member_type'] ?? '', ['supporting','flying'], true) ? (string)$_POST['member_type'] : 'supporting';
    $status = in_array($_POST['status'] ?? '', ['active','inactive'], true) ? (string)$_POST['status'] : 'active';
    $paymentStatus = ($_POST['payment_status'] ?? '') === 'paid' ? 'paid' : 'unpaid';
    $freeFlightEntitled = isset($_POST['free_flight_entitled']) ? 1 : 0;

    if ($firstName === '' || $lastName === '') $error = 'Voornaam en familienaam zijn verplicht.';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Vul een geldig e-mailadres in.';
    else {
        try {
            $pdo->beginTransaction();
            $data = [
                'first_name'=>$firstName,'last_name'=>$lastName,'address'=>trim((string)($_POST['address']??''))?:null,
                'postal_code'=>trim((string)($_POST['postal_code']??''))?:null,'city'=>trim((string)($_POST['city']??''))?:null,
                'birth_date'=>($_POST['birth_date']??'')?:null,'mobile'=>trim((string)($_POST['mobile']??''))?:null,
                'email'=>$email?:null,'weight_kg'=>($_POST['weight_kg']??'')!==''?(float)str_replace(',','.',(string)$_POST['weight_kg']):null,
                'member_since'=>($_POST['member_since']??'')?:null,'member_type'=>$memberType,'status'=>$status,
                'notes'=>trim((string)($_POST['notes']??''))?:null,
            ];
            if ($id > 0) {
                $data['id']=$id;
                $pdo->prepare('UPDATE members SET first_name=:first_name,last_name=:last_name,address=:address,postal_code=:postal_code,city=:city,birth_date=:birth_date,mobile=:mobile,email=:email,weight_kg=:weight_kg,member_since=:member_since,member_type=:member_type,status=:status,notes=:notes WHERE id=:id')->execute($data);
            } else {
                $pdo->prepare('INSERT INTO members (first_name,last_name,address,postal_code,city,birth_date,mobile,email,weight_kg,member_since,member_type,status,notes) VALUES (:first_name,:last_name,:address,:postal_code,:city,:birth_date,:mobile,:email,:weight_kg,:member_since,:member_type,:status,:notes)')->execute($data);
                $id=(int)$pdo->lastInsertId();
                $number='HO-'.str_pad((string)$id,6,'0',STR_PAD_LEFT);
                $pdo->prepare('UPDATE members SET member_number=:number WHERE id=:id')->execute(['number'=>$number,'id'=>$id]);
            }
            $paidAt=$paymentStatus==='paid'?(($_POST['paid_at']??'')?:date('Y-m-d')):null;
            $freeFlightDate=($_POST['free_flight_date']??'')?:null;
            $pdo->prepare('INSERT INTO memberships (member_id,membership_year,membership_type,payment_status,paid_at,free_flight_entitled,free_flight_date) VALUES (:member_id,:year,:type,:payment_status,:paid_at,:entitled,:flight_date) ON DUPLICATE KEY UPDATE membership_type=VALUES(membership_type),payment_status=VALUES(payment_status),paid_at=VALUES(paid_at),free_flight_entitled=VALUES(free_flight_entitled),free_flight_date=VALUES(free_flight_date)')->execute(['member_id'=>$id,'year'=>$year,'type'=>$memberType,'payment_status'=>$paymentStatus,'paid_at'=>$paidAt,'entitled'=>$freeFlightEntitled,'flight_date'=>$freeFlightDate]);

            $pdo->prepare('DELETE FROM member_tags WHERE member_id=:id')->execute(['id'=>$id]);
            $decodedTags=json_decode((string)($_POST['tags_json']??'[]'),true);
            $rawTags=is_array($decodedTags)?$decodedTags:[];
            $rawTags=array_values(array_unique(array_filter(array_map(static fn($v)=>trim((string)$v),$rawTags))));
            foreach($rawTags as $tagName){
                if(mb_strlen($tagName)>100)$tagName=mb_substr($tagName,0,100);
                $find=$pdo->prepare('SELECT id FROM tags WHERE name=:name');$find->execute(['name'=>$tagName]);
                $tagId=(int)($find->fetchColumn()?:0);
                if($tagId===0){$pdo->prepare('INSERT INTO tags (name) VALUES (:name)')->execute(['name'=>$tagName]);$tagId=(int)$pdo->lastInsertId();}
                $pdo->prepare('INSERT IGNORE INTO member_tags (member_id,tag_id) VALUES (:member_id,:tag_id)')->execute(['member_id'=>$id,'tag_id'=>$tagId]);
            }

            if(!empty($_FILES['photo']['name'])&&($_FILES['photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                if($_FILES['photo']['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Upload van de pasfoto is mislukt.');
                if((int)$_FILES['photo']['size']>5*1024*1024)throw new RuntimeException('Pasfoto mag maximaal 5 MB groot zijn.');
                $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($_FILES['photo']['tmp_name']);
                $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                if(!isset($extensions[$mime]))throw new RuntimeException('Gebruik een JPG, PNG of WEBP als pasfoto.');
                $dir=__DIR__.'/uploads/members';if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('Uploadmap kon niet worden aangemaakt.');
                $filename='member-'.$id.'-'.bin2hex(random_bytes(8)).'.'.$extensions[$mime];$target=$dir.'/'.$filename;
                if(!move_uploaded_file($_FILES['photo']['tmp_name'],$target))throw new RuntimeException('Pasfoto kon niet worden opgeslagen.');
                $path='/uploads/members/'.$filename;$pdo->prepare('UPDATE members SET photo_path=:path WHERE id=:id')->execute(['path'=>$path,'id'=>$id]);
            }
            $pdo->commit();header('Location: /member.php?id='.$id.'&saved=1');exit;
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
    }
}
if($id>0){[$member,$membership,$tagNames]=load_member($pdo,$id,$year);}if(isset($_GET['saved']))$message='Lid opgeslagen.';
$allTags=array_column($pdo->query('SELECT name FROM tags ORDER BY name')->fetchAll(),'name');
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $id ? 'Lid bewerken' : 'Nieuw lid' ?> - Heli One Members</title><style>
body{font-family:Arial,sans-serif;background:#f5f6f8;margin:0;color:#171717}header{background:#111;color:#fff;padding:18px 28px;display:flex;justify-content:space-between;align-items:center}header a{color:#fff}main{max-width:1050px;margin:32px auto;padding:0 22px}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}.topactions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.card{background:#fff;padding:24px;border-radius:14px;box-shadow:0 4px 18px #0000000d;margin:18px 0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.full{grid-column:1/-1}label{display:block;font-weight:700;font-size:14px;margin-bottom:6px}input,select,textarea{width:100%;box-sizing:border-box;padding:11px;border:1px solid #d4d9df;border-radius:8px;font:inherit}textarea{min-height:120px;resize:vertical}.btn{padding:12px 18px;border:0;border-radius:9px;background:#111;color:#fff;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}.secondary{background:#e7eaee;color:#111}.muted{color:#6b7280}.ok{background:#e9f8ee;padding:12px;border-radius:8px}.err{background:#feecec;padding:12px;border-radius:8px}.photo{width:130px;height:160px;object-fit:cover;border-radius:10px;background:#e7eaee}.check{display:flex;align-items:center;gap:8px}.check input{width:auto}.sectiontitle{margin:0 0 4px}.tagbox{border:1px solid #d4d9df;border-radius:9px;padding:7px;display:flex;flex-wrap:wrap;gap:7px;align-items:center;min-height:44px;background:#fff}.tagbox input{border:0;outline:0;width:auto;min-width:180px;flex:1;padding:6px}.tagchip{display:inline-flex;align-items:center;gap:7px;background:#eef1f4;border-radius:999px;padding:6px 9px;font-size:13px}.tagchip button{border:0;background:transparent;cursor:pointer;font-size:16px;line-height:1;padding:0;color:#666}.suggestions{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}.suggestion{border:1px solid #d8dde3;background:#fff;border-radius:999px;padding:6px 10px;cursor:pointer;font-size:13px}.suggestion:hover{background:#f1f3f5}.suggestion.selected{opacity:.45;cursor:default}.taghelp{font-size:13px;color:#6b7280;margin-top:7px}@media(max-width:700px){.grid{grid-template-columns:1fr}.full{grid-column:auto}}
</style></head><body><header><strong>Heli One Members</strong><div><a href="/members.php">Leden</a> · <a href="/">Dashboard</a> · <a href="/logout.php">Afmelden</a></div></header><main><div class="top"><div><h1><?= $id ? e($member['first_name'].' '.$member['last_name']) : 'Nieuw lid' ?></h1><?php if($id):?><div class="muted">Lidnummer: <strong><?=e($member['member_number'])?></strong></div><?php endif;?></div><div class="topactions"><?php if($id):?><a class="btn" target="_blank" rel="noopener" href="/badge.php?id=<?=$id?>&mode=print">Print lidkaart</a><a class="btn secondary" target="_blank" rel="noopener" href="/badge.php?id=<?=$id?>&mode=download">Download Badge</a><?php endif;?><button class="btn" type="submit" form="memberForm">OPSLAAN</button><a class="btn secondary" href="/members.php">← Terug</a></div></div><?php if($message):?><p class="ok"><?=e($message)?></p><?php endif;?><?php if($error):?><p class="err"><?=e($error)?></p><?php endif;?><form method="post" enctype="multipart/form-data" id="memberForm"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="tags_json" id="tags_json" value="<?=e(json_encode(array_values($tagNames),JSON_UNESCAPED_UNICODE))?>"><div class="card"><h2 class="sectiontitle">Persoonsgegevens</h2><div class="grid"><div><label>Voornaam</label><input name="first_name" required value="<?=e($member['first_name'])?>"></div><div><label>Familienaam</label><input name="last_name" required value="<?=e($member['last_name'])?>"></div><div class="full"><label>Straat + nr</label><input name="address" value="<?=e($member['address'])?>"></div><div><label>Postcode</label><input name="postal_code" value="<?=e($member['postal_code'])?>"></div><div><label>Stad</label><input name="city" value="<?=e($member['city'])?>"></div><div><label>Geboortedatum</label><input type="date" name="birth_date" value="<?=e($member['birth_date'])?>"></div><div><label>GSM</label><input name="mobile" value="<?=e($member['mobile'])?>"></div><div><label>E-mail</label><input type="email" name="email" value="<?=e($member['email'])?>"></div><div><label>Gewicht (kg)</label><input type="number" min="0" max="999" step="0.1" name="weight_kg" value="<?=e((string)$member['weight_kg'])?>"></div><div><label>Lid sinds</label><input type="date" name="member_since" value="<?=e($member['member_since'])?>"></div><div><label>Type lid</label><select name="member_type"><option value="supporting" <?=$member['member_type']==='supporting'?'selected':''?>>Steunend</option><option value="flying" <?=$member['member_type']==='flying'?'selected':''?>>Vliegend</option></select></div><div><label>Status</label><select name="status"><option value="active" <?=$member['status']==='active'?'selected':''?>>Actief</option><option value="inactive" <?=$member['status']==='inactive'?'selected':''?>>Inactief</option></select></div></div></div><div class="card"><h2 class="sectiontitle">Lidmaatschap <?=$year?></h2><div class="grid"><div><label>Betaalstatus</label><select name="payment_status"><option value="unpaid" <?=($membership['payment_status']??'unpaid')==='unpaid'?'selected':''?>>Niet betaald</option><option value="paid" <?=($membership['payment_status']??'')==='paid'?'selected':''?>>Betaald</option></select></div><div><label>Betaald op</label><input type="date" name="paid_at" value="<?=e((string)($membership['paid_at']??''))?>"></div><div class="check full"><input type="checkbox" id="free_flight_entitled" name="free_flight_entitled" value="1" <?=((int)($membership['free_flight_entitled']??1)===1)?'checked':''?>><label for="free_flight_entitled" style="margin:0">Recht op gratis vlucht</label></div><div><label>Datum gratis vlucht uitgevoerd</label><input type="date" name="free_flight_date" value="<?=e((string)($membership['free_flight_date']??''))?>"></div></div></div><div class="card"><h2 class="sectiontitle">Pasfoto & tags</h2><div class="grid"><div><?php if(!empty($member['photo_path'])):?><img class="photo" src="<?=e($member['photo_path'])?>" alt="Pasfoto"><p class="muted">Nieuwe foto uploaden vervangt de huidige.</p><?php else:?><div class="photo"></div><?php endif;?></div><div><label>Pasfoto</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"><p class="muted">JPG, PNG of WEBP, maximaal 5 MB.</p><label>Tags</label><div class="tagbox" id="tagbox"><input type="text" id="tagInput" autocomplete="off" placeholder="Typ een tag en druk ENTER"></div><div class="taghelp">Druk ENTER om een tag toe te voegen. Klik op × om een tag te verwijderen.</div><?php if($allTags):?><div class="taghelp"><strong>Bestaande tags — klik om toe te voegen:</strong></div><div class="suggestions"><?php foreach($allTags as $existingTag):?><button type="button" class="suggestion" data-tag="<?=e($existingTag)?>"><?=e($existingTag)?></button><?php endforeach;?></div><?php endif;?></div></div></div><div class="card"><h2 class="sectiontitle">Notities</h2><textarea name="notes" placeholder="Vrije notities over dit lid"><?=e((string)$member['notes'])?></textarea></div><button class="btn" type="submit">Lid opslaan</button></form></main><script>(()=>{const hidden=document.getElementById('tags_json'),box=document.getElementById('tagbox'),input=document.getElementById('tagInput'),suggestions=[...document.querySelectorAll('.suggestion')];let tags=[];try{tags=JSON.parse(hidden.value||'[]')}catch(e){}tags=[...new Set(tags.map(v=>String(v).trim()).filter(Boolean))];const key=v=>v.toLocaleLowerCase('nl');const sync=()=>hidden.value=JSON.stringify(tags);const refresh=()=>suggestions.forEach(b=>{const s=tags.some(t=>key(t)===key(b.dataset.tag||''));b.classList.toggle('selected',s);b.disabled=s});const render=()=>{box.querySelectorAll('.tagchip').forEach(el=>el.remove());tags.forEach((tag,idx)=>{const chip=document.createElement('span');chip.className='tagchip';const text=document.createElement('span');text.textContent=tag;const rm=document.createElement('button');rm.type='button';rm.textContent='×';rm.addEventListener('click',()=>{tags.splice(idx,1);render();input.focus()});chip.append(text,rm);box.insertBefore(chip,input)});sync();refresh()};const add=v=>{const tag=String(v||'').trim();if(!tag)return;if(!tags.some(t=>key(t)===key(tag)))tags.push(tag);input.value='';render()};input.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();add(input.value)}else if(e.key==='Backspace'&&input.value===''&&tags.length){tags.pop();render()}});input.addEventListener('blur',()=>{if(input.value.trim())add(input.value)});suggestions.forEach(b=>b.addEventListener('click',()=>add(b.dataset.tag)));document.getElementById('memberForm').addEventListener('submit',()=>{if(input.value.trim())add(input.value);sync()});render()})();</script></body></html>