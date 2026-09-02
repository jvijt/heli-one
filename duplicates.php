<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
Auth::requireLogin();

$pdo = Database::connection();

function dup_normalize(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $value = mb_strtolower($value, 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) $value = strtolower($ascii);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function dup_name_key(array $m): string
{
    return dup_normalize((string)($m['first_name'] ?? '')) . '|' . dup_normalize((string)($m['last_name'] ?? ''));
}

function dup_digits(?string $value): string
{
    return preg_replace('/\D+/', '', (string)$value) ?? '';
}

function dup_similarity(string $a, string $b): float
{
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;
    $max = max(strlen($a), strlen($b));
    if ($max === 0 || $max > 255) return 0.0;
    return 1 - (levenshtein($a, $b) / $max);
}

function dup_pair_score(array $a, array $b): array
{
    $score = 0;
    $reasons = [];

    $firstA = dup_normalize((string)$a['first_name']);
    $firstB = dup_normalize((string)$b['first_name']);
    $lastA = dup_normalize((string)$a['last_name']);
    $lastB = dup_normalize((string)$b['last_name']);
    $nameA = $firstA . $lastA;
    $nameB = $firstB . $lastB;

    if ($firstA !== '' && $lastA !== '' && $firstA === $firstB && $lastA === $lastB) {
        $score += 40;
        $reasons[] = 'zelfde voor- en familienaam';
    } elseif ($nameA !== '' && $nameB !== '' && $lastA === $lastB && dup_similarity($nameA, $nameB) >= 0.88) {
        $score += 25;
        $reasons[] = 'sterk gelijkende naam';
    }

    $emailA = strtolower(trim((string)($a['email'] ?? '')));
    $emailB = strtolower(trim((string)($b['email'] ?? '')));
    if ($emailA !== '' && $emailA === $emailB) {
        $score += 45;
        $reasons[] = 'zelfde e-mailadres';
    }

    $mobileA = dup_digits((string)($a['mobile'] ?? ''));
    $mobileB = dup_digits((string)($b['mobile'] ?? ''));
    if ($mobileA !== '' && strlen($mobileA) >= 7 && $mobileA === $mobileB) {
        $score += 35;
        $reasons[] = 'zelfde GSM';
    }

    $birthA = trim((string)($a['birth_date'] ?? ''));
    $birthB = trim((string)($b['birth_date'] ?? ''));
    if ($birthA !== '' && $birthA === $birthB) {
        $score += 30;
        $reasons[] = 'zelfde geboortedatum';
    }

    $addressA = dup_normalize((string)($a['address'] ?? '')) . dup_normalize((string)($a['postal_code'] ?? ''));
    $addressB = dup_normalize((string)($b['address'] ?? '')) . dup_normalize((string)($b['postal_code'] ?? ''));
    if ($addressA !== '' && strlen($addressA) >= 6 && $addressA === $addressB) {
        $score += 10;
        $reasons[] = 'zelfde adres';
    }

    return [$score, $reasons];
}

$members = $pdo->query(
    'SELECT m.*,
        (SELECT COUNT(*) FROM memberships ms WHERE ms.member_id=m.id) AS membership_count,
        (SELECT COUNT(*) FROM member_tags mt WHERE mt.member_id=m.id) AS tag_count
     FROM members m
     ORDER BY m.last_name, m.first_name, m.id'
)->fetchAll();

$pairs = [];
$count = count($members);
for ($i = 0; $i < $count; $i++) {
    for ($j = $i + 1; $j < $count; $j++) {
        [$score, $reasons] = dup_pair_score($members[$i], $members[$j]);
        if ($score < 65) continue;
        $pairs[] = [
            'a' => $members[$i],
            'b' => $members[$j],
            'score' => $score,
            'reasons' => $reasons,
            'confidence' => $score >= 85 ? 'high' : 'medium',
        ];
    }
}

usort($pairs, static function(array $x, array $y): int {
    if ($x['score'] === $y['score']) return ((int)$x['a']['id']) <=> ((int)$y['a']['id']);
    return $y['score'] <=> $x['score'];
});

$filter = (string)($_GET['confidence'] ?? 'all');
if (!in_array($filter, ['all','high','medium'], true)) $filter = 'all';
$visiblePairs = array_values(array_filter($pairs, static fn(array $p): bool => $filter === 'all' || $p['confidence'] === $filter));
$highCount = count(array_filter($pairs, static fn(array $p): bool => $p['confidence'] === 'high'));
$mediumCount = count($pairs) - $highCount;

function member_cell(array $m): string
{
    $photo = !empty($m['photo_path'])
        ? '<img class="photo" src="' . e((string)$m['photo_path']) . '" alt="">'
        : '<span class="photo initials">' . e(mb_strtoupper(mb_substr((string)$m['first_name'],0,1) . mb_substr((string)$m['last_name'],0,1))) . '</span>';
    $details = [];
    if (!empty($m['birth_date'])) $details[] = 'Geb. ' . e((string)$m['birth_date']);
    if (!empty($m['mobile'])) $details[] = e((string)$m['mobile']);
    if (!empty($m['email'])) $details[] = e((string)$m['email']);
    $address = trim((string)($m['address'] ?? '') . ' ' . (string)($m['postal_code'] ?? '') . ' ' . (string)($m['city'] ?? ''));
    if ($address !== '') $details[] = e($address);
    return '<div class="memberbox">' . $photo . '<div><a class="membername" href="/member.php?id=' . (int)$m['id'] . '">' . e((string)$m['first_name'] . ' ' . (string)$m['last_name']) . '</a><div class="memberno">' . e((string)($m['member_number'] ?: 'Geen lidnummer')) . ' · ID ' . (int)$m['id'] . '</div><div class="details">' . implode('<br>', $details) . '</div><div class="meta">' . (int)$m['membership_count'] . ' lidmaatschap(pen) · ' . (int)$m['tag_count'] . ' tag(s)</div></div></div>';
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Duplicaten - Heli One Members</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f8;margin:0;color:#171717}header{background:#111;color:#fff;padding:18px 28px;display:flex;justify-content:space-between;align-items:center}header a{color:#fff}main{max-width:1280px;margin:32px auto;padding:0 22px}.top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}.muted{color:#68707a}.btn{display:inline-block;padding:11px 16px;border-radius:9px;background:#111;color:#fff;text-decoration:none;border:0;cursor:pointer;font-weight:700}.secondary{background:#e8ebef;color:#111}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:22px 0}.stat{background:#fff;border-radius:12px;padding:18px;box-shadow:0 4px 18px #0000000d}.stat strong{display:block;font-size:28px;margin-top:5px}.filters{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0}.filters a{padding:9px 13px;border-radius:999px;text-decoration:none;background:#e8ebef;color:#222}.filters a.active{background:#111;color:#fff}.pair{background:#fff;border-radius:14px;box-shadow:0 4px 18px #0000000d;margin:16px 0;overflow:hidden}.pairhead{padding:14px 18px;background:#fafbfc;border-bottom:1px solid #e9ecef;display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap}.reason{font-size:14px;color:#565d66}.confidence{display:inline-block;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700}.high{background:#ffe9e5;color:#8d281b}.medium{background:#fff1d7;color:#745000}.compare{display:grid;grid-template-columns:1fr 1fr}.side{padding:20px;min-width:0}.side+ .side{border-left:1px solid #eceff2}.memberbox{display:flex;gap:14px;min-width:0}.photo{width:58px;height:58px;border-radius:50%;object-fit:cover;background:#e7eaee;flex:0 0 58px}.initials{display:flex;align-items:center;justify-content:center;font-weight:800;color:#555}.membername{font-size:18px;font-weight:800;color:#111;text-decoration:none}.memberno,.meta{font-size:12px;color:#7a8087;margin-top:4px}.details{font-size:14px;line-height:1.55;margin-top:9px;overflow-wrap:anywhere}.empty{background:#fff;border-radius:14px;padding:28px;box-shadow:0 4px 18px #0000000d}.notice{background:#eef4ff;padding:14px 16px;border-radius:10px;margin:18px 0;line-height:1.5}@media(max-width:700px){.stats{grid-template-columns:1fr}.compare{grid-template-columns:1fr}.side+ .side{border-left:0;border-top:1px solid #eceff2}}
</style>
</head>
<body>
<header><strong>Heli One Members</strong><div><a href="/">Dashboard</a> · <a href="/members.php">Leden</a> · <a href="/logout.php">Afmelden</a></div></header>
<main>
<div class="top"><div><h1>Duplicaten controleren</h1><p class="muted">Mogelijke dubbele leden op basis van meerdere overeenkomende persoonsgegevens.</p></div><a class="btn secondary" href="/">← Dashboard</a></div>
<div class="notice"><strong>Veilige controlemodus:</strong> deze pagina wijzigt of verwijdert nog niets. Een gedeeld e-mailadres alleen is onvoldoende om leden als dubbel te markeren. Samenvoegen en verwijderen voegen we als volgende actie aan deze selectie toe.</div>
<section class="stats"><div class="stat"><span class="muted">Mogelijke paren</span><strong><?=count($pairs)?></strong></div><div class="stat"><span class="muted">Hoge waarschijnlijkheid</span><strong><?=$highCount?></strong></div><div class="stat"><span class="muted">Te controleren</span><strong><?=$mediumCount?></strong></div></section>
<div class="filters"><a class="<?=$filter==='all'?'active':''?>" href="/duplicates.php">Alles (<?=count($pairs)?>)</a><a class="<?=$filter==='high'?'active':''?>" href="/duplicates.php?confidence=high">Hoge waarschijnlijkheid (<?=$highCount?>)</a><a class="<?=$filter==='medium'?'active':''?>" href="/duplicates.php?confidence=medium">Te controleren (<?=$mediumCount?>)</a></div>
<?php if(!$visiblePairs):?><div class="empty"><strong>Geen mogelijke duplicaten gevonden in deze selectie.</strong><p class="muted">Dat betekent dat er momenteel geen leden zijn die aan de ingestelde combinaties voldoen.</p></div><?php endif;?>
<?php foreach($visiblePairs as $pair):?><section class="pair"><div class="pairhead"><div><span class="confidence <?=$pair['confidence']==='high'?'high':'medium'?>"><?=$pair['confidence']==='high'?'Hoge waarschijnlijkheid':'Controleren'?></span> <strong>Score <?=$pair['score']?></strong></div><div class="reason"><?=e(implode(' · ', $pair['reasons']))?></div></div><div class="compare"><div class="side"><?=member_cell($pair['a'])?></div><div class="side"><?=member_cell($pair['b'])?></div></div></section><?php endforeach;?>
</main>
</body>
</html>
