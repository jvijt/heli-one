<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
Auth::requireLogin();

$pdo = Database::connection();
ensure_member_soft_delete_schema($pdo);
$message = '';
$error = '';

$pdo->exec("CREATE TABLE IF NOT EXISTS duplicate_exceptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id_a INT UNSIGNED NOT NULL,
    member_id_b INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_duplicate_pair (member_id_a, member_id_b),
    CONSTRAINT fk_dup_exc_a FOREIGN KEY (member_id_a) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_dup_exc_b FOREIGN KEY (member_id_b) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function dup_norm(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    $value = mb_strtolower($value, 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) $value = strtolower($ascii);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}
function pair_ids(int $a, int $b): array { return $a < $b ? [$a,$b] : [$b,$a]; }
function load_dup_member(PDO $pdo, int $id): array {
    $s=$pdo->prepare('SELECT * FROM members WHERE id=:id AND deleted_at IS NULL');
    $s->execute(['id'=>$id]);
    $m=$s->fetch();
    if(!$m) throw new RuntimeException('Lid niet gevonden of reeds verwijderd.');
    return $m;
}
function same_exact_name(array $a,array $b):bool {
    return dup_norm($a['first_name']??'')!=='' && dup_norm($a['first_name']??'')===dup_norm($b['first_name']??'') && dup_norm($a['last_name']??'')===dup_norm($b['last_name']??'');
}
function dup_audit(PDO $pdo,string $action,int $id,array $details=[]):void {
    $s=$pdo->prepare('INSERT INTO audit_log (admin_id,action,entity_type,entity_id,details) VALUES (:admin,:action,"member",:id,:details)');
    $s->execute(['admin'=>(int)($_SESSION['admin_id']??0)?:null,'action'=>$action,'id'=>$id,'details'=>json_encode($details,JSON_UNESCAPED_UNICODE)]);
}
function merge_type(string $a,string $b):string {
    $types=[$a,$b];
    if(in_array('flyer',$types,true)) return 'flyer';
    if(in_array('pilot',$types,true)) return 'pilot';
    return 'viewer';
}
function merge_dup_members(PDO $pdo,int $keepId,int $removeId):void {
    $keep=load_dup_member($pdo,$keepId); $remove=load_dup_member($pdo,$removeId);
    if(!same_exact_name($keep,$remove)) throw new RuntimeException('Deze leden hebben niet exact dezelfde naam.');
    $fields=['address','postal_code','city','birth_date','mobile','email','weight_kg','member_since','photo_path'];
    $updates=[];
    foreach($fields as $f) if(($keep[$f]===null||trim((string)$keep[$f])==='') && $remove[$f]!==null && trim((string)$remove[$f])!=='') $updates[$f]=$remove[$f];
    $mergedMemberType=merge_type((string)($keep['member_type']??'viewer'),(string)($remove['member_type']??'viewer'));
    if(($keep['member_type']??'viewer')!==$mergedMemberType) $updates['member_type']=$mergedMemberType;
    if(($keep['status']??'inactive')!=='active' && ($remove['status']??'')==='active') $updates['status']='active';
    $n1=trim((string)($keep['notes']??'')); $n2=trim((string)($remove['notes']??''));
    if($n2!=='' && $n2!==$n1) $updates['notes']=trim($n1.($n1!==''?"\n\n":'').$n2);
    if($updates){$sets=[];$p=['id'=>$keepId];foreach($updates as$f=>$v){$sets[]="$f=:$f";$p[$f]=$v;}$pdo->prepare('UPDATE members SET '.implode(',',$sets).' WHERE id=:id')->execute($p);}
    $pdo->prepare('INSERT IGNORE INTO member_tags (member_id,tag_id) SELECT :keep,tag_id FROM member_tags WHERE member_id=:remove')->execute(['keep'=>$keepId,'remove'=>$removeId]);
    $src=$pdo->prepare('SELECT * FROM memberships WHERE member_id=:id ORDER BY membership_year');$src->execute(['id'=>$removeId]);
    foreach($src->fetchAll() as $r){
        $d=$pdo->prepare('SELECT * FROM memberships WHERE member_id=:id AND membership_year=:year');$d->execute(['id'=>$keepId,'year'=>$r['membership_year']]);$dst=$d->fetch();
        if(!$dst){$pdo->prepare('UPDATE memberships SET member_id=:keep WHERE id=:id')->execute(['keep'=>$keepId,'id'=>$r['id']]);continue;}
        $type=merge_type((string)$dst['membership_type'],(string)$r['membership_type']);
        $pay=($dst['payment_status']==='paid'||$r['payment_status']==='paid')?'paid':'unpaid';
        $paid=$dst['paid_at']?:$r['paid_at'];
        $ent=$type==='flyer'?1:0;
        $flight=$type==='flyer'?($dst['free_flight_date']?:$r['free_flight_date']):null;
        $dn=trim((string)($dst['notes']??''));$sn=trim((string)($r['notes']??''));$notes=$dn;if($sn!==''&&$sn!==$dn)$notes=trim($dn.($dn!==''?"\n\n":'').$sn);
        $pdo->prepare('UPDATE memberships SET membership_type=:t,payment_status=:p,paid_at=:pa,free_flight_entitled=:e,free_flight_date=:f,notes=:n WHERE id=:id')->execute(['t'=>$type,'p'=>$pay,'pa'=>$paid,'e'=>$ent,'f'=>$flight,'n'=>$notes?:null,'id'=>$dst['id']]);
        $pdo->prepare('DELETE FROM memberships WHERE id=:id')->execute(['id'=>$r['id']]);
    }
    dup_audit($pdo,'duplicate_merge',$keepId,['merged_member_id'=>$removeId,'merged_member_number'=>$remove['member_number']]);
    $pdo->prepare('DELETE FROM members WHERE id=:id')->execute(['id'=>$removeId]);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=(string)($_POST['action']??'');$a=(int)($_POST['member_a']??0);$b=(int)($_POST['member_b']??0);
    try{
        if($a<=0||$b<=0||$a===$b) throw new RuntimeException('Ongeldige ledenselectie.');
        [$small,$large]=pair_ids($a,$b);$ma=load_dup_member($pdo,$a);$mb=load_dup_member($pdo,$b);
        if(!same_exact_name($ma,$mb)) throw new RuntimeException('De geselecteerde leden hebben niet exact dezelfde naam.');
        if($action==='keep_both'){
            $pdo->prepare('INSERT IGNORE INTO duplicate_exceptions (member_id_a,member_id_b) VALUES (:a,:b)')->execute(['a'=>$small,'b'=>$large]);
            dup_audit($pdo,'duplicate_keep_both',$a,['other_member_id'=>$b]);
            $message='Beide leden worden behouden en verschijnen samen niet meer als duplicaat.';
        }elseif($action==='delete_a'||$action==='delete_b'){
            $deleteId=$action==='delete_a'?$a:$b;$otherId=$deleteId===$a?$b:$a;$dm=$deleteId===$a?$ma:$mb;
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE members SET deleted_at=NOW(), deleted_by_admin_id=:admin WHERE id=:id AND deleted_at IS NULL')->execute(['admin'=>(int)($_SESSION['admin_id']??0)?:null,'id'=>$deleteId]);
            dup_audit($pdo,'duplicate_soft_delete',$deleteId,['member_number'=>$dm['member_number'],'paired_with'=>$otherId,'recoverable_days'=>60]);
            $pdo->commit();
            $message='Het dubbele lid is naar de Prullenbak verplaatst en kan 60 dagen worden hersteld.';
        }elseif($action==='merge_keep_a'||$action==='merge_keep_b'){
            $keep=$action==='merge_keep_a'?$a:$b;$remove=$keep===$a?$b:$a;
            $pdo->beginTransaction();merge_dup_members($pdo,$keep,$remove);$pdo->commit();
            $message='De twee leden zijn samengevoegd. Tags, lidmaatschappen en ontbrekende gegevens zijn behouden.';
        }else throw new RuntimeException('Onbekende actie.');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}

$members=$pdo->query('SELECT m.*,(SELECT COUNT(*) FROM memberships x WHERE x.member_id=m.id) membership_count,(SELECT COUNT(*) FROM member_tags x WHERE x.member_id=m.id) tag_count FROM members m WHERE m.deleted_at IS NULL ORDER BY m.last_name,m.first_name,m.id')->fetchAll();
$ignored=[];foreach($pdo->query('SELECT member_id_a,member_id_b FROM duplicate_exceptions')->fetchAll() as$r)$ignored[$r['member_id_a'].'-'.$r['member_id_b']]=true;
$pairs=[];for($i=0,$n=count($members);$i<$n;$i++)for($j=$i+1;$j<$n;$j++){if(!same_exact_name($members[$i],$members[$j]))continue;[$x,$y]=pair_ids((int)$members[$i]['id'],(int)$members[$j]['id']);if(isset($ignored[$x.'-'.$y]))continue;$pairs[]=['a'=>$members[$i],'b'=>$members[$j]];}
function dup_card(array$m,string$side):string{$d=[];if($m['birth_date']??'')$d[]='Geb. '.e($m['birth_date']);if($m['mobile']??'')$d[]='GSM: '.e($m['mobile']);if($m['email']??'')$d[]='E-mail: '.e($m['email']);$ad=trim(($m['address']??'').' '.($m['postal_code']??'').' '.($m['city']??''));if($ad!=='')$d[]=e($ad);return '<div><a class="name" href="/member.php?id='.(int)$m['id'].'">'.e(($m['first_name']??'').' '.($m['last_name']??'')).'</a><div class="muted">'.e($m['member_number']?:'Geen lidnummer').' · ID '.(int)$m['id'].' · '.e(strtoupper((string)($m['member_type']??'viewer'))).'</div><div class="details">'.implode('<br>',$d).'</div><div class="muted">'.(int)$m['membership_count'].' lidmaatschap(pen) · '.(int)$m['tag_count'].' tag(s)</div><div class="member-actions"><button type="submit" name="action" value="merge_keep_'.$side.'" class="btn merge">Dit lid behouden & samenvoegen</button><button type="submit" name="action" value="delete_'.$side.'" class="btn danger" data-confirm="Dit lid naar de Prullenbak verplaatsen?">Naar Prullenbak</button></div></div>';}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Duplicaten - Heli One Members</title><style>body{font-family:Arial,sans-serif;background:#f5f6f8;margin:0;color:#171717}header{background:#111;color:#fff;padding:18px 28px;display:flex;justify-content:space-between}header a{color:#fff}main{max-width:1280px;margin:32px auto;padding:0 22px}.muted{color:#68707a}.notice,.ok,.err{padding:14px 16px;border-radius:10px;margin:16px 0}.notice{background:#eef4ff}.ok{background:#e9f8ee}.err{background:#feecec}.top{display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap}.btn{display:inline-block;padding:10px 14px;border-radius:9px;background:#111;color:#fff;text-decoration:none;border:0;cursor:pointer;font-weight:700}.secondary{background:#e8ebef;color:#111}.danger{background:#8f1d1d}.merge{background:#1f5f3b}.keep{background:#315b85}.pair{background:#fff;border-radius:14px;box-shadow:0 4px 18px #0000000d;margin:16px 0;overflow:hidden}.pairhead{padding:13px 18px;background:#fafbfc;border-bottom:1px solid #eceff2;display:flex;justify-content:space-between}.compare{display:grid;grid-template-columns:1fr 1fr}.side{padding:20px}.side+.side{border-left:1px solid #eceff2}.name{font-size:18px;font-weight:800;color:#111;text-decoration:none}.details{line-height:1.55;margin:9px 0}.member-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px}.empty{background:#fff;border-radius:14px;padding:26px}@media(max-width:700px){.compare{grid-template-columns:1fr}.side+.side{border-left:0;border-top:1px solid #eceff2}}</style></head><body><header><strong>Heli One Members</strong><div><a href="/">Dashboard</a> · <a href="/members.php">Leden</a> · <a href="/trash.php">Prullenbak</a> · <a href="/logout.php">Afmelden</a></div></header><main><div class="top"><div><h1>Duplicaten controleren</h1><p class="muted">Alleen exact dezelfde voornaam én familienaam worden getoond.</p></div><a class="btn secondary" href="/">← Dashboard</a></div><?php if($message):?><div class="ok"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?><div class="notice"><strong><?=count($pairs)?> mogelijk(e) duplicaatparen.</strong> Verwijderen verplaatst een lid voortaan naar de <strong>Prullenbak</strong>; herstel blijft 60 dagen mogelijk.</div><?php if(!$pairs):?><div class="empty"><strong>Geen openstaande duplicaten.</strong></div><?php endif;?><?php foreach($pairs as$p):?><form method="post" class="pair"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="member_a" value="<?=(int)$p['a']['id']?>"><input type="hidden" name="member_b" value="<?=(int)$p['b']['id']?>"><div class="pairhead"><strong>Identieke naam</strong><button class="btn keep" type="submit" name="action" value="keep_both">Beide behouden</button></div><div class="compare"><div class="side"><?=dup_card($p['a'],'a')?></div><div class="side"><?=dup_card($p['b'],'b')?></div></div></form><?php endforeach;?></main><script>document.querySelectorAll('[data-confirm]').forEach(b=>b.addEventListener('click',e=>{if(!confirm(b.dataset.confirm))e.preventDefault();}));</script></body></html>