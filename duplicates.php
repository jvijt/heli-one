<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
Auth::requireLogin();

$pdo = Database::connection();
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

function dup_norm(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $value = mb_strtolower($value, 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) $value = strtolower($ascii);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function pair_ids(int $a, int $b): array
{
    return $a < $b ? [$a, $b] : [$b, $a];
}

function load_member_for_action(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id=:id');
    $stmt->execute(['id'=>$id]);
    $m = $stmt->fetch();
    if (!$m) throw new RuntimeException('Lid niet gevonden.');
    return $m;
}

function same_exact_name(array $a, array $b): bool
{
    return dup_norm((string)$a['first_name']) !== ''
        && dup_norm((string)$a['first_name']) === dup_norm((string)$b['first_name'])
        && dup_norm((string)$a['last_name']) === dup_norm((string)$b['last_name']);
}

function audit_action(PDO $pdo, string $action, int $entityId, array $details=[]): void
{
    $stmt = $pdo->prepare('INSERT INTO audit_log (admin_id,action,entity_type,entity_id,details) VALUES (:admin,:action,"member",:id,:details)');
    $stmt->execute([
        'admin'=>(int)($_SESSION['admin_id'] ?? 0) ?: null,
        'action'=>$action,
        'id'=>$entityId,
        'details'=>json_encode($details, JSON_UNESCAPED_UNICODE),
    ]);
}

function merge_members(PDO $pdo, int $keepId, int $removeId): void
{
    if ($keepId === $removeId) throw new RuntimeException('Selecteer twee verschillende leden.');
    $keep = load_member_for_action($pdo, $keepId);
    $remove = load_member_for_action($pdo, $removeId);
    if (!same_exact_name($keep, $remove)) throw new RuntimeException('Deze leden hebben niet exact dezelfde naam en kunnen vanuit deze pagina niet worden samengevoegd.');

    $fields = ['address','postal_code','city','birth_date','mobile','email','weight_kg','member_since','photo_path'];
    $updates = [];
    foreach ($fields as $field) {
        if (($keep[$field] === null || trim((string)$keep[$field]) === '') && $remove[$field] !== null && trim((string)$remove[$field]) !== '') {
            $updates[$field] = $remove[$field];
        }
    }
    if (($keep['member_type'] ?? 'supporting') !== 'flying' && ($remove['member_type'] ?? '') === 'flying') $updates['member_type'] = 'flying';
    if (($keep['status'] ?? 'inactive') !== 'active' && ($remove['status'] ?? '') === 'active') $updates['status'] = 'active';
    $notes = trim((string)($keep['notes'] ?? ''));
    $removeNotes = trim((string)($remove['notes'] ?? ''));
    if ($removeNotes !== '' && $removeNotes !== $notes) $updates['notes'] = trim($notes . ($notes !== '' ? "\n\n" : '') . $removeNotes);
    if ($updates) {
        $sets=[]; $params=['id'=>$keepId];
        foreach($updates as $f=>$v){$sets[]="$f=:$f";$params[$f]=$v;}
        $pdo->prepare('UPDATE members SET '.implode(',',$sets).' WHERE id=:id')->execute($params);
    }

    $pdo->prepare('INSERT IGNORE INTO member_tags (member_id,tag_id) SELECT :keep,tag_id FROM member_tags WHERE member_id=:remove')
        ->execute(['keep'=>$keepId,'remove'=>$removeId]);

    $years = $pdo->prepare('SELECT * FROM memberships WHERE member_id=:id ORDER BY membership_year');
    $years->execute(['id'=>$removeId]);
    foreach ($years->fetchAll() as $src) {
        $dstStmt = $pdo->prepare('SELECT * FROM memberships WHERE member_id=:id AND membership_year=:year');
        $dstStmt->execute(['id'=>$keepId,'year'=>$src['membership_year']]);
        $dst = $dstStmt->fetch();
        if (!$dst) {
            $pdo->prepare('UPDATE memberships SET member_id=:keep WHERE id=:id')->execute(['keep'=>$keepId,'id'=>$src['id']]);
            continue;
        }
        $membershipType = ($dst['membership_type']==='flying' || $src['membership_type']==='flying') ? 'flying' : 'supporting';
        $paymentStatus = ($dst['payment_status']==='paid' || $src['payment_status']==='paid') ? 'paid' : 'unpaid';
        $paidAt = $dst['paid_at'] ?: $src['paid_at'];
        $entitled = max((int)$dst['free_flight_entitled'], (int)$src['free_flight_entitled']);
        $flightDate = $dst['free_flight_date'] ?: $src['free_flight_date'];
        $dn = trim((string)($dst['notes'] ?? ''));
        $sn = trim((string)($src['notes'] ?? ''));
        $mergedNotes = $dn;
        if ($sn !== '' && $sn !== $dn) $mergedNotes = trim($dn . ($dn !== '' ? "\n\n" : '') . $sn);
        $pdo->prepare('UPDATE memberships SET membership_type=:type,payment_status=:pay,paid_at=:paid,free_flight_entitled=:ent,free_flight_date=:flight,notes=:notes WHERE id=:id')
            ->execute(['type'=>$membershipType,'pay'=>$paymentStatus,'paid'=>$paidAt,'ent'=>$entitled,'flight'=>$flightDate,'notes'=>$mergedNotes?:null,'id'=>$dst['id']]);
        $pdo->prepare('DELETE FROM memberships WHERE id=:id')->execute(['id'=>$src['id']]);
    }

    audit_action($pdo, 'duplicate_merge', $keepId, ['merged_member_id'=>$removeId,'merged_member_number'=>$remove['member_number']]);
    $pdo->prepare('DELETE FROM members WHERE id=:id')->execute(['id'=>$removeId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $a = (int)($_POST['member_a'] ?? 0);
    $b = (int)($_POST['member_b'] ?? 0);
    try {
        if ($a <= 0 || $b <= 0 || $a === $b) throw new RuntimeException('Ongeldige ledenselectie.');
        [$small,$large] = pair_ids($a,$b);
        $ma = load_member_for_action($pdo,$a);
        $mb = load_member_for_action($pdo,$b);
        if (!same_exact_name($ma,$mb)) throw new RuntimeException('De geselecteerde leden hebben niet exact dezelfde naam.');

        if ($action === 'keep_both') {
            $pdo->prepare('INSERT IGNORE INTO duplicate_exceptions (member_id_a,member_id_b) VALUES (:a,:b)')->execute(['a'=>$small,'b'=>$large]);
            audit_action($pdo,'duplicate_keep_both',$a,['other_member_id'=>$b]);
            $message = 'Beide leden worden behouden en dit paar verschijnt niet meer als duplicaat.';
        } elseif ($action === 'delete_a' || $action === 'delete_b') {
            $deleteId = $action === 'delete_a' ? $a : $b;
            $deleteMember = $action === 'delete_a' ? $ma : $mb;
            $pdo->beginTransaction();
            audit_action($pdo,'duplicate_delete',$deleteId,['member_number'=>$deleteMember['member_number'],'paired_with'=>$deleteId===$a?$b:$a]);
            $pdo->prepare('DELETE FROM members WHERE id=:id')->execute(['id'=>$deleteId]);
            $pdo->commit();
            $message = 'Het geselecteerde dubbele lid is verwijderd.';
        } elseif ($action === 'merge_keep_a' || $action === 'merge_keep_b') {
            $keepId = $action === 'merge_keep_a' ? $a : $b;
            $removeId = $keepId === $a ? $b : $a;
            $pdo->beginTransaction();
            merge_members($pdo,$keepId,$removeId);
            $pdo->commit();
            $message = 'De twee leden zijn samengevoegd. Tags, lidmaatschappen en ontbrekende gegevens zijn behouden.';
        } else {
            throw new RuntimeException('Onbekende actie.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$members = $pdo->query('SELECT m.*,(SELECT COUNT(*) FROM memberships x WHERE x.member_id=m.id) membership_count,(SELECT COUNT(*) FROM member_tags x WHERE x.member_id=m.id) tag_count FROM members m ORDER BY m.last_name,m.first_name,m.id')->fetchAll();
$ignored = [];
foreach ($pdo->query('SELECT member_id_a,member_id_b FROM duplicate_exceptions')->fetchAll() as $row) $ignored[$row['member_id_a'].'-'.$row['member_id_b']] = true;
$pairs = [];
for ($i=0,$n=count($members);$i<$n;$i++) {
    for ($j=$i+1;$j<$n;$j++) {
        if (!same_exact_name($members[$i],$members[$j])) continue;
        [$x,$y]=pair_ids((int)$members[$i]['id'],(int)$members[$j]['id']);
        if (isset($ignored[$x.'-'.$y])) continue;
        $pairs[]=['a'=>$members[$i],'b'=>$members[$j]];
    }
}

function member_card(array $m, string $side): string
{
    $details=[];
    if (!empty($m['birth_date'])) $details[]='Geb. '.e((string)$m['birth_date']);
    if (!empty($m['mobile'])) $details[]='GSM: '.e((string)$m['mobile']);
    if (!empty($m['email'])) $details[]='E-mail: '.e((string)$m['email']);
    $address=trim((string)($m['address']??'').' '.(string)($m['postal_code']??'').' '.(string)($m['city']??''));
    if ($address!=='') $details[]=e($address);
    $html='<div class="member"><a class="name" href="/member.php?id='.(int)$m['id'].'">'.e((string)$m['first_name'].' '.(string)$m['last_name']).'</a><div class="muted">'.e((string)($m['member_number']?:'Geen lidnummer')).' · ID '.(int)$m['id'].'</div><div class="details">'.implode('<br>',$details).'</div><div class="muted">'.(int)$m['membership_count'].' lidmaatschap(pen) · '.(int)$m['tag_count'].' tag(s)</div></div>';
    $html.='<div class="member-actions"><button type="submit" name="action" value="merge_keep_'.$side.'" class="btn merge">Dit lid behouden & samenvoegen</button><button type="submit" name="action" value="delete_'.$side.'" class="btn danger" data-confirm="Dit lid definitief verwijderen?">Dit lid verwijderen</button></div>';
    return $html;
}
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Duplicaten - Heli One Members</title><style>
body{font-family:Arial,sans-serif;background:#f5f6f8;margin:0;color:#171717}header{background:#111;color:#fff;padding:18px 28px;display:flex;justify-content:space-between;align-items:center}header a{color:#fff}main{max-width:1280px;margin:32px auto;padding:0 22px}.muted{color:#68707a}.notice,.ok,.err{padding:14px 16px;border-radius:10px;margin:16px 0}.notice{background:#eef4ff}.ok{background:#e9f8ee}.err{background:#feecec}.top{display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap}.btn{display:inline-block;padding:10px 14px;border-radius:9px;background:#111;color:#fff;text-decoration:none;border:0;cursor:pointer;font-weight:700}.secondary{background:#e8ebef;color:#111}.danger{background:#8f1d1d}.merge{background:#1f5f3b}.keep{background:#315b85}.pair{background:#fff;border-radius:14px;box-shadow:0 4px 18px #0000000d;margin:16px 0;overflow:hidden}.pairhead{padding:13px 18px;background:#fafbfc;border-bottom:1px solid #eceff2;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.compare{display:grid;grid-template-columns:1fr 1fr}.side{padding:20px;min-width:0}.side+.side{border-left:1px solid #eceff2}.name{font-size:18px;font-weight:800;color:#111;text-decoration:none}.details{line-height:1.55;margin:9px 0;overflow-wrap:anywhere}.member-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px}.empty{background:#fff;border-radius:14px;padding:26px;box-shadow:0 4px 18px #0000000d}@media(max-width:700px){.compare{grid-template-columns:1fr}.side+.side{border-left:0;border-top:1px solid #eceff2}}
</style></head><body><header><strong>Heli One Members</strong><div><a href="/">Dashboard</a> · <a href="/members.php">Leden</a> · <a href="/logout.php">Afmelden</a></div></header><main><div class="top"><div><h1>Duplicaten controleren</h1><p class="muted">Alleen leden met exact dezelfde voornaam én familienaam worden getoond. Hoofdletters maken geen verschil.</p></div><a class="btn secondary" href="/">← Dashboard</a></div><?php if($message):?><div class="ok"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?><div class="notice"><strong><?=count($pairs)?> mogelijk(e) duplicaatparen.</strong> Kies per paar welk lid moet blijven bij samenvoegen, verwijder één record, of kies <strong>Beide behouden</strong> als het effectief twee verschillende personen zijn. Die keuze wordt onthouden.</div><?php if(!$pairs):?><div class="empty"><strong>Geen openstaande duplicaten.</strong><p class="muted">Alle identieke namen zijn verwerkt of als “beide behouden” gemarkeerd.</p></div><?php endif;?><?php foreach($pairs as$p):?><form method="post" class="pair"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="member_a" value="<?=(int)$p['a']['id']?>"><input type="hidden" name="member_b" value="<?=(int)$p['b']['id']?>"><div class="pairhead"><strong>Identieke naam: <?=e((string)$p['a']['first_name'].' '.(string)$p['a']['last_name'])?></strong><button type="submit" name="action" value="keep_both" class="btn keep">Beide behouden</button></div><div class="compare"><div class="side"><?=member_card($p['a'],'a')?></div><div class="side"><?=member_card($p['b'],'b')?></div></div></form><?php endforeach;?></main><script>document.querySelectorAll('[data-confirm]').forEach(b=>b.addEventListener('click',e=>{if(!confirm(b.dataset.confirm||'Zeker?'))e.preventDefault()}));document.querySelectorAll('.merge').forEach(b=>b.addEventListener('click',e=>{if(!confirm('Deze twee leden samenvoegen? Het gekozen lid blijft bestaan en het andere record wordt verwijderd nadat tags, lidmaatschappen en ontbrekende gegevens zijn overgenomen.'))e.preventDefault()}));</script></body></html>