<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/ImportReader.php';
Auth::requireLogin();

$pdo = Database::connection();
$year = (int)date('Y');
$error = '';
$result = null;
$state = $_SESSION['member_import'] ?? null;

$fieldLabels = [
    '' => '— Kolom negeren —',
    'full_name' => 'Volledige naam (voornaam + familienaam) *',
    'first_name' => 'Voornaam *',
    'last_name' => 'Familienaam *',
    'email' => 'E-mail *',
    'address' => 'Straat + nr',
    'postal_code' => 'Postcode',
    'city' => 'Stad',
    'birth_date' => 'Geboortedatum',
    'mobile' => 'GSM',
    'weight_kg' => 'Gewicht (kg)',
    'member_since' => 'Lid sinds',
    'member_type' => 'Type lid',
    'status' => 'Status',
    'notes' => 'Vrije notities',
    'payment_status' => 'Betaalstatus ' . $year,
    'paid_at' => 'Betaald op',
    'free_flight_entitled' => 'Recht op gratis vlucht',
    'free_flight_date' => 'Datum gratis vlucht',
    'tags' => 'Tags uit Excel',
];

function import_date(mixed $value): ?string
{
    $v = trim((string)$value);
    if ($v === '') return null;
    if (is_numeric($v) && (float)$v > 1000) {
        $days = (int)floor((float)$v);
        return (new DateTimeImmutable('1899-12-30'))->modify('+' . $days . ' days')->format('Y-m-d');
    }
    foreach (['Y-m-d','d/m/Y','d-m-Y','d.m.Y','m/d/Y'] as $fmt) {
        $d = DateTimeImmutable::createFromFormat('!' . $fmt, $v);
        if ($d && $d->format($fmt) === $v) return $d->format('Y-m-d');
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

function bool_value(mixed $value): int
{
    return in_array(strtolower(trim((string)$value)), ['1','ja','yes','true','y','x','recht','j'], true) ? 1 : 0;
}

function clean_type(mixed $value): ?string
{
    $v = strtolower(trim((string)$value));
    if (in_array($v, ['flying','vliegend','vlieger'], true)) return 'flying';
    if (in_array($v, ['supporting','steunend','steunend lid'], true)) return 'supporting';
    return null;
}

function clean_status(mixed $value): ?string
{
    $v = strtolower(trim((string)$value));
    if (in_array($v, ['active','actief','1','ja'], true)) return 'active';
    if (in_array($v, ['inactive','inactief','0','neen','nee'], true)) return 'inactive';
    return null;
}

function clean_payment(mixed $value): ?string
{
    $v = strtolower(trim((string)$value));
    if (in_array($v, ['paid','betaald','1','ja'], true)) return 'paid';
    if (in_array($v, ['unpaid','niet betaald','open','0','neen','nee'], true)) return 'unpaid';
    return null;
}

function split_full_name(string $name, string $order): array
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') return ['', ''];
    if (str_contains($name, ',')) {
        [$left, $right] = array_map('trim', explode(',', $name, 2));
        if ($left !== '' && $right !== '') return [$right, $left];
    }
    $parts = preg_split('/\s+/u', $name) ?: [];
    if (count($parts) < 2) return [$name, ''];
    if ($order === 'last_first') {
        $first = array_pop($parts);
        return [trim((string)$first), trim(implode(' ', $parts))];
    }
    $first = array_shift($parts);
    return [trim((string)$first), trim(implode(' ', $parts))];
}

function member_number(PDO $pdo, int $id): void
{
    $number = 'HO-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE members SET member_number=:n WHERE id=:id AND (member_number IS NULL OR member_number="")')->execute(['n'=>$number,'id'=>$id]);
}

function ensure_tag(PDO $pdo, string $name): int
{
    $name = trim($name);
    if ($name === '') return 0;
    $find = $pdo->prepare('SELECT id FROM tags WHERE name=:name');
    $find->execute(['name'=>$name]);
    $id = (int)($find->fetchColumn() ?: 0);
    if ($id === 0) {
        $pdo->prepare('INSERT INTO tags (name) VALUES (:name)')->execute(['name'=>$name]);
        $id = (int)$pdo->lastInsertId();
    }
    return $id;
}

if (isset($_GET['reset'])) {
    if (!empty($state['path']) && is_file($state['path'])) @unlink($state['path']);
    unset($_SESSION['member_import']);
    header('Location: /import.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    verify_csrf();
    try {
        if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Selecteer een Excel- of CSV-bestand.');
        if ((int)$_FILES['file']['size'] > 15 * 1024 * 1024) throw new RuntimeException('Bestand mag maximaal 15 MB groot zijn.');
        $original = (string)$_FILES['file']['name'];
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','csv'], true)) throw new RuntimeException('Gebruik een .xlsx- of .csv-bestand.');
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'heli-one-imports';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Tijdelijke importmap kon niet worden aangemaakt.');
        $path = $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) throw new RuntimeException('Upload kon niet worden opgeslagen.');
        $rows = ImportReader::read($path, $original);
        if (count($rows) < 2) throw new RuntimeException('Het bestand bevat geen gegevensrijen.');
        $headers = array_map(static fn($v, $i) => trim((string)$v) !== '' ? trim((string)$v) : 'Kolom ' . ($i+1), $rows[0], array_keys($rows[0]));
        $_SESSION['member_import'] = $state = ['path'=>$path,'original'=>$original,'headers'=>$headers,'row_count'=>count($rows)-1,'preview'=>array_slice($rows,1,5)];
        $pdo->prepare('INSERT INTO import_jobs (admin_id,original_filename,status,total_rows) VALUES (:admin,:file,"mapping",:total)')->execute(['admin'=>(int)$_SESSION['admin_id'],'file'=>$original,'total'=>count($rows)-1]);
        $_SESSION['member_import']['job_id'] = (int)$pdo->lastInsertId();
        $state = $_SESSION['member_import'];
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'execute') {
    verify_csrf();
    try {
        if (!$state || empty($state['path']) || !is_file($state['path'])) throw new RuntimeException('Importsessie is verlopen. Upload het bestand opnieuw.');
        $mapping = $_POST['mapping'] ?? [];
        if (!is_array($mapping)) throw new RuntimeException('Ongeldige kolomkoppeling.');
        $mapped = array_values(array_filter(array_map('strval', $mapping)));
        $fullNameCount = count(array_keys($mapped, 'full_name', true));
        $firstCount = count(array_keys($mapped, 'first_name', true));
        $lastCount = count(array_keys($mapped, 'last_name', true));
        $emailCount = count(array_keys($mapped, 'email', true));
        if ($emailCount !== 1) throw new RuntimeException('Koppel exact één kolom aan E-mail.');
        if (!(($fullNameCount === 1 && $firstCount === 0 && $lastCount === 0) || ($fullNameCount === 0 && $firstCount === 1 && $lastCount === 1))) {
            throw new RuntimeException('Koppel óf één kolom aan Volledige naam, óf aparte kolommen aan Voornaam en Familienaam.');
        }
        if (count($mapped) !== count(array_unique($mapped))) throw new RuntimeException('Eén Heli One-veld mag maar aan één Excel-kolom gekoppeld worden.');
        $nameOrder = ($_POST['name_order'] ?? '') === 'last_first' ? 'last_first' : 'first_last';

        $defaults = [
            'member_since' => import_date($_POST['default_member_since'] ?? ''),
            'member_type' => in_array($_POST['default_member_type'] ?? '', ['supporting','flying'], true) ? (string)$_POST['default_member_type'] : null,
            'status' => in_array($_POST['default_status'] ?? '', ['active','inactive'], true) ? (string)$_POST['default_status'] : null,
            'payment_status' => in_array($_POST['default_payment_status'] ?? '', ['paid','unpaid'], true) ? (string)$_POST['default_payment_status'] : null,
            'free_flight_entitled' => ($_POST['default_free_flight_entitled'] ?? '') === '' ? null : (int)$_POST['default_free_flight_entitled'],
        ];
        $batchTags = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($_POST['batch_tags'] ?? '')) ?: []))));
        $rows = ImportReader::read($state['path'], $state['original']);
        array_shift($rows);
        $created = $updated = $skipped = $errors = 0;
        $errorDetails = [];
        $pdo->beginTransaction();

        foreach ($rows as $rowIndex => $row) {
            $line = $rowIndex + 2;
            $data = [];
            foreach ($mapping as $idx => $field) {
                $field = (string)$field;
                if ($field === '') continue;
                $data[$field] = trim((string)($row[(int)$idx] ?? ''));
            }
            if (!empty($data['full_name'])) {
                [$data['first_name'], $data['last_name']] = split_full_name((string)$data['full_name'], $nameOrder);
                unset($data['full_name']);
            }
            foreach ($defaults as $key=>$value) if ($value !== null) $data[$key] = $value;
            $first = trim((string)($data['first_name'] ?? ''));
            $last = trim((string)($data['last_name'] ?? ''));
            $email = strtolower(trim((string)($data['email'] ?? '')));
            if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++; $errorDetails[] = 'Rij '.$line.': naam kon niet correct opgesplitst worden of geldig e-mailadres ontbreekt.'; continue;
            }

            foreach (['birth_date','member_since','paid_at','free_flight_date'] as $df) if (isset($data[$df]) && $data[$df] !== '') $data[$df] = import_date($data[$df]);
            if (isset($data['weight_kg']) && $data['weight_kg'] !== '') $data['weight_kg'] = (float)str_replace(',', '.', (string)$data['weight_kg']);
            if (isset($data['member_type']) && $defaults['member_type'] === null) $data['member_type'] = clean_type($data['member_type']);
            if (isset($data['status']) && $defaults['status'] === null) $data['status'] = clean_status($data['status']);
            if (isset($data['payment_status']) && $defaults['payment_status'] === null) $data['payment_status'] = clean_payment($data['payment_status']);
            if (isset($data['free_flight_entitled']) && $defaults['free_flight_entitled'] === null) $data['free_flight_entitled'] = bool_value($data['free_flight_entitled']);

            $find = $pdo->prepare('SELECT id,member_type,status FROM members WHERE LOWER(email)=:email LIMIT 1');
            $find->execute(['email'=>$email]);
            $existing = $find->fetch();
            $memberFields = ['first_name','last_name','email','address','postal_code','city','birth_date','mobile','weight_kg','member_since','member_type','status','notes'];
            $values = [];
            foreach ($memberFields as $f) if (array_key_exists($f,$data) && $data[$f] !== null && $data[$f] !== '') $values[$f] = $data[$f];
            $values['first_name']=$first; $values['last_name']=$last; $values['email']=$email;

            if ($existing) {
                $id = (int)$existing['id'];
                $sets=[]; $params=['id'=>$id];
                foreach ($values as $f=>$v) { $sets[]="$f=:$f"; $params[$f]=$v; }
                if ($sets) $pdo->prepare('UPDATE members SET '.implode(',',$sets).' WHERE id=:id')->execute($params);
                $updated++;
            } else {
                $values['member_type'] = $values['member_type'] ?? 'supporting';
                $values['status'] = $values['status'] ?? 'active';
                $cols=array_keys($values); $ph=array_map(static fn($c)=>':'.$c,$cols);
                $pdo->prepare('INSERT INTO members ('.implode(',',$cols).') VALUES ('.implode(',',$ph).')')->execute($values);
                $id=(int)$pdo->lastInsertId(); member_number($pdo,$id); $created++;
            }

            $currentType = $data['member_type'] ?? ($existing['member_type'] ?? 'supporting');
            $hasMembershipData = isset($data['payment_status']) || isset($data['paid_at']) || isset($data['free_flight_entitled']) || isset($data['free_flight_date']) || $defaults['member_type'] !== null || isset($data['member_since']);
            if ($hasMembershipData) {
                $payment = $data['payment_status'] ?? 'unpaid';
                $paidAt = $payment === 'paid' ? ($data['paid_at'] ?? date('Y-m-d')) : null;
                $entitled = array_key_exists('free_flight_entitled',$data) ? (int)$data['free_flight_entitled'] : 1;
                $flightDate = $data['free_flight_date'] ?? null;
                $ms=$pdo->prepare('INSERT INTO memberships (member_id,membership_year,membership_type,payment_status,paid_at,free_flight_entitled,free_flight_date) VALUES (:id,:y,:t,:p,:pa,:e,:fd) ON DUPLICATE KEY UPDATE membership_type=VALUES(membership_type),payment_status=VALUES(payment_status),paid_at=VALUES(paid_at),free_flight_entitled=VALUES(free_flight_entitled),free_flight_date=VALUES(free_flight_date)');
                $ms->execute(['id'=>$id,'y'=>$year,'t'=>$currentType,'p'=>$payment,'pa'=>$paidAt,'e'=>$entitled,'fd'=>$flightDate]);
            }

            $rowTags = [];
            if (!empty($data['tags'])) $rowTags = array_filter(array_map('trim', preg_split('/[,;]+/', (string)$data['tags']) ?: []));
            foreach (array_values(array_unique(array_merge($batchTags,$rowTags))) as $tagName) {
                $tagId = ensure_tag($pdo,$tagName);
                if ($tagId) $pdo->prepare('INSERT IGNORE INTO member_tags (member_id,tag_id) VALUES (:m,:t)')->execute(['m'=>$id,'t'=>$tagId]);
            }
        }

        $pdo->commit();
        $jobId=(int)($state['job_id'] ?? 0);
        if ($jobId) $pdo->prepare('UPDATE import_jobs SET status="completed",imported_rows=:i,skipped_rows=:s,error_rows=:e,field_mapping=:m,completed_at=NOW() WHERE id=:id')->execute(['i'=>$created+$updated,'s'=>$skipped,'e'=>$errors,'m'=>json_encode(['mapping'=>$mapping,'name_order'=>$nameOrder,'defaults'=>$defaults,'batch_tags'=>$batchTags],JSON_UNESCAPED_UNICODE),'id'=>$jobId]);
        @unlink($state['path']);
        unset($_SESSION['member_import']);
        $state=null;
        $result=['created'=>$created,'updated'=>$updated,'skipped'=>$skipped,'errors'=>$errors,'details'=>array_slice($errorDetails,0,20)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error=$e->getMessage();
    }
}

$tags = $pdo->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Excel import - Heli One Members</title><style>
body{font-family:Arial,sans-serif;background:#f5f6f8;margin:0;color:#171717}header{background:#111;color:#fff;padding:18px 28px;display:flex;justify-content:space-between;align-items:center}header a{color:#fff}main{max-width:1250px;margin:32px auto;padding:0 22px}.card{background:#fff;padding:24px;border-radius:14px;box-shadow:0 4px 18px #0000000d;margin:18px 0}.btn{padding:11px 16px;border:0;border-radius:9px;background:#111;color:#fff;text-decoration:none;font-weight:700;cursor:pointer;display:inline-block}.secondary{background:#e8ebef;color:#111}.err{background:#feecec;padding:12px;border-radius:8px}.ok{background:#e9f8ee;padding:16px;border-radius:10px}.muted{color:#6b7280}.mapping{overflow:auto}.mapping table{border-collapse:collapse;width:100%;min-width:800px}.mapping th,.mapping td{padding:10px;border-bottom:1px solid #e8ebef;text-align:left}.mapping th{background:#fafbfc}.mapping select,input[type=text],input[type=date],select{box-sizing:border-box;padding:10px;border:1px solid #d4d9df;border-radius:8px;font:inherit;max-width:100%}.defaults{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}.tagbox{border:1px solid #d4d9df;border-radius:9px;padding:8px;display:flex;gap:7px;flex-wrap:wrap;align-items:center;min-height:44px}.tagbox input{border:0;outline:0;flex:1;min-width:180px;padding:5px;font:inherit}.chip{background:#eef1f4;border-radius:999px;padding:6px 10px;display:inline-flex;gap:6px;align-items:center}.chip button{border:0;background:none;cursor:pointer;font-weight:700}.suggestions{display:flex;gap:7px;flex-wrap:wrap;margin-top:8px}.suggestion{border:1px solid #d4d9df;background:#fff;border-radius:999px;padding:6px 10px;cursor:pointer}.actions{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}.name-order{margin-top:14px;padding:14px;background:#f7f8fa;border-radius:10px;display:none}.name-order.show{display:block}@media(max-width:850px){.defaults{grid-template-columns:1fr 1fr}}@media(max-width:520px){.defaults{grid-template-columns:1fr}}
</style></head><body><header><strong>Heli One Members</strong><div><a href="/">Dashboard</a> · <a href="/members.php">Leden</a> · <a href="/logout.php">Afmelden</a></div></header><main><h1>Excel import</h1><p class="muted">Importeer leden uit XLSX of CSV. De eerste rij wordt gebruikt als kolomkop.</p><?php if($error):?><p class="err"><?=e($error)?></p><?php endif;?>
<?php if($result):?><div class="ok"><strong>Import voltooid.</strong><p><?=$result['created']?> nieuwe leden · <?=$result['updated']?> bestaande leden bijgewerkt · <?=$result['skipped']?> rijen overgeslagen.</p><?php if($result['details']):?><details><summary>Overgeslagen rijen bekijken</summary><ul><?php foreach($result['details'] as $d):?><li><?=e($d)?></li><?php endforeach;?></ul></details><?php endif;?></div><div class="actions"><a class="btn" href="/">Naar dashboard</a><a class="btn secondary" href="/import.php">Nieuwe import</a></div>
<?php elseif(!$state):?><div class="card"><h2>1. Bestand uploaden</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="upload"><input type="file" name="file" accept=".xlsx,.csv" required><p class="muted">Maximaal 15 MB. Ondersteund: Excel .xlsx en .csv.</p><button class="btn" type="submit">Uploaden en kolommen lezen</button></form></div>
<?php else:?><form method="post" id="importForm"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="execute"><div class="card"><h2>2. Kolommen koppelen</h2><p><strong><?=e($state['original'])?></strong> · <?=(int)$state['row_count']?> gegevensrijen</p><div class="mapping"><table><thead><tr><th>Excel-kolom</th><th>Heli One-veld</th><th>Voorbeeld</th></tr></thead><tbody><?php foreach($state['headers'] as $i=>$header):?><tr><td><strong><?=e($header)?></strong></td><td><select class="mapping-select" name="mapping[<?=$i?>]"><?php foreach($fieldLabels as $key=>$label):?><option value="<?=e($key)?>"><?=e($label)?></option><?php endforeach;?></select></td><td><?=e((string)($state['preview'][0][$i] ?? ''))?></td></tr><?php endforeach;?></tbody></table></div><div class="name-order" id="nameOrderBox"><strong>Hoe staat de volledige naam in deze Excel?</strong><p class="muted">Kies de volgorde die voor deze import geldt.</p><label><input type="radio" name="name_order" value="first_last" checked> Voornaam eerst — bv. <strong>Jan Peeters</strong></label><br><br><label><input type="radio" name="name_order" value="last_first"> Familienaam eerst — bv. <strong>Van den Bossche Jan</strong></label><p class="muted">Ook notatie “Peeters, Jan” wordt automatisch herkend.</p></div><p class="muted">Gebruik óf aparte kolommen Voornaam + Familienaam, óf één kolom Volledige naam. E-mail blijft verplicht.</p></div>
<div class="card"><h2>3. Vaste waarden voor deze batch</h2><p class="muted">Een ingevulde batchwaarde heeft voor alle rijen voorrang op een waarde uit Excel.</p><div class="defaults"><div><label>Lid sinds</label><br><input type="date" name="default_member_since"></div><div><label>Type lid</label><br><select name="default_member_type"><option value="">Niet vast instellen</option><option value="supporting">Steunend</option><option value="flying">Vliegend</option></select></div><div><label>Status</label><br><select name="default_status"><option value="">Niet vast instellen</option><option value="active">Actief</option><option value="inactive">Inactief</option></select></div><div><label>Betaalstatus <?=$year?></label><br><select name="default_payment_status"><option value="">Niet vast instellen</option><option value="paid">Betaald</option><option value="unpaid">Niet betaald</option></select></div><div><label>Gratis vlucht <?=$year?></label><br><select name="default_free_flight_entitled"><option value="">Niet vast instellen</option><option value="1">Recht aanwezig</option><option value="0">Geen recht</option></select></div></div></div>
<div class="card"><h2>4. Tag(s) voor de hele import</h2><div class="tagbox" id="tagbox"><input id="tagInput" placeholder="Typ tag en druk ENTER"></div><input type="hidden" name="batch_tags" id="batchTags"><p class="muted">Deze tags worden aan iedere geïmporteerde rij toegevoegd. Bestaande tags blijven behouden.</p><?php if($tags):?><div class="suggestions"><?php foreach($tags as $tag):?><button type="button" class="suggestion" data-tag="<?=e((string)$tag)?>">+ <?=e((string)$tag)?></button><?php endforeach;?></div><?php endif;?></div>
<div class="card"><h2>5. Controle</h2><div class="mapping"><table><thead><tr><?php foreach($state['headers'] as $h):?><th><?=e($h)?></th><?php endforeach;?></tr></thead><tbody><?php foreach($state['preview'] as $r):?><tr><?php foreach($state['headers'] as $i=>$h):?><td><?=e((string)($r[$i]??''))?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><div class="actions"><button class="btn" type="submit">Import uitvoeren</button><a class="btn secondary" href="/import.php?reset=1">Annuleren / ander bestand</a></div></div></form>
<script>(()=>{const box=document.getElementById('tagbox'),input=document.getElementById('tagInput'),hidden=document.getElementById('batchTags'),orderBox=document.getElementById('nameOrderBox');let tags=[];function sync(){hidden.value=tags.join('\n')}function add(v){v=v.trim();if(!v||tags.some(t=>t.toLowerCase()===v.toLowerCase()))return;tags.push(v);const c=document.createElement('span');c.className='chip';c.textContent=v+' ';const b=document.createElement('button');b.type='button';b.textContent='×';b.onclick=()=>{tags=tags.filter(t=>t!==v);c.remove();sync()};c.appendChild(b);box.insertBefore(c,input);sync()}function toggleNameOrder(){const usesFull=[...document.querySelectorAll('.mapping-select')].some(s=>s.value==='full_name');orderBox.classList.toggle('show',usesFull)}input.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();add(input.value);input.value=''}});document.querySelectorAll('.suggestion').forEach(b=>b.onclick=()=>add(b.dataset.tag||''));document.querySelectorAll('.mapping-select').forEach(s=>s.addEventListener('change',toggleNameOrder));document.getElementById('importForm').addEventListener('submit',()=>{if(input.value.trim())add(input.value)});toggleNameOrder();})();</script><?php endif;?></main></body></html>