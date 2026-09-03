<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
Auth::requireLogin();
$pdo = Database::connection();
ensure_member_soft_delete_schema($pdo);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Ongeldig lid.'); }
$stmt = $pdo->prepare('SELECT * FROM members WHERE id=:id AND deleted_at IS NULL');
$stmt->execute(['id'=>$id]);
$member = $stmt->fetch();
if (!$member) { http_response_code(404); exit('Lid niet gevonden of reeds in de prullenbak.'); }
$tagsStmt = $pdo->prepare('SELECT t.name FROM tags t INNER JOIN member_tags mt ON mt.tag_id=t.id WHERE mt.member_id=:id ORDER BY t.name');
$tagsStmt->execute(['id'=>$id]);
$tags = array_column($tagsStmt->fetchAll(), 'name');
$membershipsStmt = $pdo->prepare('SELECT * FROM memberships WHERE member_id=:id ORDER BY membership_year DESC');
$membershipsStmt->execute(['id'=>$id]);
$memberships = $membershipsStmt->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE members SET deleted_at=NOW(), deleted_by_admin_id=:admin WHERE id=:id AND deleted_at IS NULL')->execute(['admin'=>(int)($_SESSION['admin_id'] ?? 0) ?: null,'id'=>$id]);
        $pdo->prepare('INSERT INTO audit_log (admin_id,action,entity_type,entity_id,details) VALUES (:admin,"member_trash","member",:id,:details)')->execute(['admin'=>(int)($_SESSION['admin_id'] ?? 0) ?: null,'id'=>$id,'details'=>json_encode(['member_number'=>$member['member_number'],'name'=>trim($member['first_name'].' '.$member['last_name'])],JSON_UNESCAPED_UNICODE)]);
        $pdo->commit();
        header('Location: /members.php?trashed=1'); exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}
function row(string $label, mixed $value): string { $v=trim((string)$value); return $v===''?'':'<div><strong>'.e($label).'</strong><span>'.e($v).'</span></div>'; }
function type_label(mixed $value): string { $v=strtolower(trim((string)$value)); return in_array($v,['viewer','flyer','pilot'],true)?strtoupper($v):strtoupper($v); }
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lid verwijderen - Heli One Members</title><style>body{font-family:Arial,sans-serif;background:#f5f6f8;margin:0;color:#171717}header{background:#111;color:#fff;padding:18px 28px;display:flex;justify-content:space-between}header a{color:#fff}main{max-width:900px;margin:32px auto;padding:0 22px}.card{background:#fff;border-radius:14px;padding:24px;box-shadow:0 4px 18px #0000000d;margin:18px 0}.warning{background:#fff1e7;border:1px solid #ffd5ba;padding:16px;border-radius:10px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 24px}.grid div{padding:8px 0;border-bottom:1px solid #eee}.grid strong{display:block;font-size:12px;color:#68707a;margin-bottom:4px}.btn{display:inline-block;padding:11px 16px;border-radius:9px;border:0;text-decoration:none;cursor:pointer;font-weight:700;background:#111;color:#fff}.danger{background:#9c1c1c}.secondary{background:#e7eaee;color:#111}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}.photo{width:120px;height:150px;object-fit:cover;border-radius:10px;background:#e8ebef}.head{display:flex;gap:18px;align-items:flex-start}.muted{color:#68707a}.err{background:#feecec;padding:12px;border-radius:8px}@media(max-width:650px){.grid{grid-template-columns:1fr}.head{flex-direction:column}}</style></head><body><header><strong>Heli One Members</strong><div><a href="/members.php">Leden</a> · <a href="/trash.php">Prullenbak</a></div></header><main><h1>Lid naar prullenbak verplaatsen</h1><div class="warning"><strong>Controleer eerst de gegevens.</strong> Na bevestiging verdwijnt dit lid uit de gewone ledenlijst. Het kan nog 60 dagen vanuit de prullenbak worden hersteld.</div><?php if(!empty($error)):?><p class="err"><?=e($error)?></p><?php endif;?><div class="card"><div class="head"><?php if(!empty($member['photo_path'])):?><img class="photo" src="<?=e($member['photo_path'])?>" alt=""><?php endif;?><div><h2><?=e($member['first_name'].' '.$member['last_name'])?></h2><p class="muted"><?=e($member['member_number'] ?: 'Geen lidnummer')?></p></div></div><div class="grid"><?=row('Voornaam',$member['first_name'])?><?=row('Familienaam',$member['last_name'])?><?=row('Straat + nr',$member['address'])?><?=row('Postcode',$member['postal_code'])?><?=row('Stad',$member['city'])?><?=row('Geboortedatum',$member['birth_date'])?><?=row('GSM',$member['mobile'])?><?=row('E-mail',$member['email'])?><?=row('Gewicht',$member['weight_kg']!==null?$member['weight_kg'].' kg':'')?><?=row('Lid sinds',$member['member_since'])?><?=row('Type',type_label($member['member_type']))?><?=row('Status',$member['status']==='active'?'Actief':'Inactief')?><?=row('Tags',implode(', ',$tags))?><?=row('Notities',$member['notes'])?></div></div><?php if($memberships):?><div class="card"><h2>Lidmaatschapshistoriek</h2><div class="grid"><?php foreach($memberships as $ms):?><?=row((string)$ms['membership_year'],type_label($ms['membership_type']).' · '.($ms['payment_status']==='paid'?'Betaald':'Niet betaald').(!empty($ms['free_flight_date'])?' · vlucht '.$ms['free_flight_date']:''))?><?php endforeach;?></div></div><?php endif;?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><div class="actions"><button class="btn danger" type="submit">Ja, verplaats naar prullenbak</button><a class="btn secondary" href="/members.php">Annuleren</a></div></form></main></body></html>