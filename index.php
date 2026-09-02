<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
Auth::requireLogin();

$pdo = Database::connection();
$memberCount = (int)$pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
$activeCount = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status = 'active'")->fetchColumn();
$flyingCount = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE member_type = 'flying'")->fetchColumn();
$tagCount = (int)$pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn();

$year = (int)date('Y');
$q = trim((string)($_GET['q'] ?? ''));
$type = (string)($_GET['type'] ?? '');
$status = (string)($_GET['status'] ?? '');
$payment = (string)($_GET['payment'] ?? '');
$tagId = (int)($_GET['tag'] ?? 0);

$where = [];
$params = ['year' => $year];
if ($q !== '') {
    $where[] = '(m.first_name LIKE :q OR m.last_name LIKE :q OR m.member_number LIKE :q OR m.email LIKE :q OR m.mobile LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if (in_array($type, ['supporting', 'flying'], true)) {
    $where[] = 'm.member_type = :type';
    $params['type'] = $type;
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'm.status = :status';
    $params['status'] = $status;
}
if (in_array($payment, ['paid', 'unpaid'], true)) {
    $where[] = "COALESCE(ms.payment_status, 'unpaid') = :payment";
    $params['payment'] = $payment;
}
if ($tagId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM member_tags mt2 WHERE mt2.member_id = m.id AND mt2.tag_id = :tag_id)';
    $params['tag_id'] = $tagId;
}

$sql = 'SELECT m.*, ms.payment_status, ms.free_flight_entitled, ms.free_flight_date,
        GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ", ") AS tag_names
        FROM members m
        LEFT JOIN memberships ms ON ms.member_id = m.id AND ms.membership_year = :year
        LEFT JOIN member_tags mt ON mt.member_id = m.id
        LEFT JOIN tags t ON t.id = mt.tag_id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' GROUP BY m.id ORDER BY m.last_name, m.first_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();
$tags = $pdo->query('SELECT id, name FROM tags ORDER BY name')->fetchAll();
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Heli One Members</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f8;margin:0;color:#171717}header{background:#111;color:#fff;padding:18px 28px;display:flex;justify-content:space-between;align-items:center}header a{color:#fff}main{max-width:1320px;margin:36px auto;padding:0 22px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px}.card{background:#fff;border-radius:14px;padding:22px;box-shadow:0 4px 18px #0000000d}.value{font-size:34px;font-weight:800;margin-top:8px}.muted{color:#69707a}.actions{margin-top:28px;display:flex;gap:12px;flex-wrap:wrap}.btn{display:inline-block;padding:11px 16px;border-radius:9px;background:#111;color:#fff;text-decoration:none;border:0;cursor:pointer}.disabled{background:#dfe3e8;color:#6b7280}.members-section{margin-top:42px}.members-head{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}.filters{background:#fff;padding:18px;border-radius:14px;box-shadow:0 4px 18px #0000000d;display:grid;grid-template-columns:2fr repeat(4,1fr) auto auto;gap:10px;margin:20px 0}.filters input,.filters select{padding:10px;border:1px solid #d6dbe1;border-radius:8px;width:100%;box-sizing:border-box}.reset{background:#eef1f4;color:#222}.tablewrap{overflow:auto;background:#fff;border-radius:14px;box-shadow:0 4px 18px #0000000d}table{border-collapse:collapse;width:100%;min-width:1050px}th,td{padding:13px 14px;border-bottom:1px solid #eceff2;text-align:left;vertical-align:middle}th{font-size:13px;color:#68707a;background:#fafbfc}.badge{display:inline-block;padding:5px 8px;border-radius:999px;background:#eef1f4;font-size:12px}.active,.paid{background:#e7f7ed}.inactive{background:#f0f0f0}.unpaid{background:#fff0e2}.photo{width:42px;height:42px;border-radius:50%;object-fit:cover;background:#e7eaee;display:block}.name a{font-weight:700;color:#111;text-decoration:none}@media(max-width:900px){.grid{grid-template-columns:1fr 1fr}.filters{grid-template-columns:1fr 1fr}}@media(max-width:560px){.grid,.filters{grid-template-columns:1fr}}
</style>
</head>
<body>
<header><strong>Heli One Members</strong><div><?=e((string)($_SESSION['admin_name'] ?? 'Administrator'))?> · <a href="/logout.php">Afmelden</a></div></header>
<main>
<h1>Dashboard</h1>
<p class="muted">Beheeromgeving voor de Heli One leden.</p>
<section class="grid">
<div class="card"><div class="muted">Totaal leden</div><div class="value"><?=$memberCount?></div></div>
<div class="card"><div class="muted">Actieve leden</div><div class="value"><?=$activeCount?></div></div>
<div class="card"><div class="muted">Vliegende leden</div><div class="value"><?=$flyingCount?></div></div>
<div class="card"><div class="muted">Tags</div><div class="value"><?=$tagCount?></div></div>
</section>
<div class="actions">
<a class="btn" href="/members.php">Ledenbeheer</a>
<span class="btn disabled">Excel import — volgende stap</span>
<span class="btn disabled">Tags & batchacties — volgende stap</span>
</div>

<section class="members-section">
<div class="members-head"><div><h2>Leden</h2><div class="muted"><?=count($members)?> resultaat/resultaten · lidmaatschap <?=$year?></div></div><a class="btn" href="/member.php">+ Nieuw lid</a></div>
<form class="filters" method="get" action="/">
<input name="q" value="<?=e($q)?>" placeholder="Zoek op naam, lidnummer, e-mail of GSM">
<select name="type"><option value="">Alle types</option><option value="supporting" <?=$type==='supporting'?'selected':''?>>Steunend</option><option value="flying" <?=$type==='flying'?'selected':''?>>Vliegend</option></select>
<select name="status"><option value="">Alle statussen</option><option value="active" <?=$status==='active'?'selected':''?>>Actief</option><option value="inactive" <?=$status==='inactive'?'selected':''?>>Inactief</option></select>
<select name="payment"><option value="">Alle betalingen</option><option value="paid" <?=$payment==='paid'?'selected':''?>>Betaald</option><option value="unpaid" <?=$payment==='unpaid'?'selected':''?>>Niet betaald</option></select>
<select name="tag"><option value="0">Alle tags</option><?php foreach($tags as $tag):?><option value="<?=$tag['id']?>" <?=$tagId===(int)$tag['id']?'selected':''?>><?=e($tag['name'])?></option><?php endforeach;?></select>
<button class="btn" type="submit">Filter</button><a class="btn reset" href="/">Wis filters</a>
</form>
<div class="tablewrap"><table><thead><tr><th></th><th>Lid</th><th>Type</th><th>Status</th><th>GSM</th><th>E-mail</th><th>Betaling <?=$year?></th><th>Gratis vlucht</th><th>Tags</th></tr></thead><tbody>
<?php if(!$members):?><tr><td colspan="9" class="muted">Nog geen leden gevonden.</td></tr><?php endif;?>
<?php foreach($members as $m):?><tr>
<td><?php if(!empty($m['photo_path'])):?><img class="photo" src="<?=e($m['photo_path'])?>" alt=""><?php else:?><span class="photo"></span><?php endif;?></td>
<td class="name"><a href="/member.php?id=<?=$m['id']?>"><?=e($m['last_name'] . ' ' . $m['first_name'])?></a><div class="muted"><?=e($m['member_number'] ?: 'Nog geen lidnummer')?></div></td>
<td><span class="badge"><?=$m['member_type']==='flying'?'Vliegend':'Steunend'?></span></td>
<td><span class="badge <?=$m['status']==='active'?'active':'inactive'?>"><?=$m['status']==='active'?'Actief':'Inactief'?></span></td>
<td><?=e($m['mobile'] ?? '')?></td><td><?=e($m['email'] ?? '')?></td>
<td><span class="badge <?=($m['payment_status'] ?? 'unpaid')==='paid'?'paid':'unpaid'?>"><?=(($m['payment_status'] ?? 'unpaid')==='paid')?'Betaald':'Niet betaald'?></span></td>
<td><?php if((int)($m['free_flight_entitled'] ?? 0)===1):?><?=!empty($m['free_flight_date'])?'Uitgevoerd ' . e($m['free_flight_date']):'Recht aanwezig'?><?php else:?>Geen recht<?php endif;?></td>
<td><?=e($m['tag_names'] ?? '')?></td>
</tr><?php endforeach;?></tbody></table></div>
</section>
</main>
</body>
</html>