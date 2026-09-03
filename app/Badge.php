<?php
declare(strict_types=1);

function ensure_badge_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS badge_templates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        tag_id INT UNSIGNED NULL,
        pdf_path VARCHAR(255) NOT NULL,
        layout_json LONGTEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_badge_tag (tag_id),
        INDEX idx_badge_active (is_active),
        CONSTRAINT fk_badge_template_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function badge_field_catalog(): array
{
    return [
        'full_name' => 'Volledige naam',
        'first_name' => 'Voornaam',
        'last_name' => 'Familienaam',
        'member_number' => 'Lidnummer',
        'photo' => 'Pasfoto',
        'member_type' => 'Type lid',
        'status' => 'Status',
        'member_since' => 'Lid sinds',
        'birth_date' => 'Geboortedatum',
        'email' => 'E-mail',
        'mobile' => 'GSM',
        'address' => 'Straat + nr',
        'postal_city' => 'Postcode + stad',
        'weight_kg' => 'Gewicht',
        'tags' => 'Tags',
        'payment_status' => 'Betaling huidig jaar',
        'free_flight_entitled' => 'Recht op vlucht',
        'free_flight_date' => 'Datum gratis vlucht',
        'qr_member_number' => 'QR-code lidnummer',
        'barcode_member_number' => 'Barcode lidnummer',
    ];
}

function badge_member_data(PDO $pdo, int $memberId): array
{
    $year = (int)date('Y');
    $stmt = $pdo->prepare('SELECT m.*, ms.payment_status, ms.free_flight_entitled, ms.free_flight_date FROM members m LEFT JOIN memberships ms ON ms.member_id=m.id AND ms.membership_year=:year WHERE m.id=:id AND m.deleted_at IS NULL');
    $stmt->execute(['id'=>$memberId,'year'=>$year]);
    $m = $stmt->fetch();
    if (!$m) throw new RuntimeException('Lid niet gevonden.');
    $tags = $pdo->prepare('SELECT t.id,t.name FROM tags t INNER JOIN member_tags mt ON mt.tag_id=t.id WHERE mt.member_id=:id ORDER BY t.name');
    $tags->execute(['id'=>$memberId]);
    $m['tag_rows'] = $tags->fetchAll();
    $m['tags'] = implode(', ', array_column($m['tag_rows'], 'name'));
    $m['full_name'] = trim((string)$m['first_name'].' '.(string)$m['last_name']);
    $m['postal_city'] = trim((string)($m['postal_code']??'').' '.(string)($m['city']??''));
    $type=(string)($m['member_type']??'viewer');
    $m['member_type_label'] = strtoupper(in_array($type,['viewer','flyer','pilot'],true)?$type:'viewer');
    $m['status_label'] = ($m['status']??'active') === 'active' ? 'Actief' : 'Inactief';
    $m['payment_status_label'] = ($m['payment_status']??'unpaid') === 'paid' ? 'Betaald' : 'Niet betaald';
    $m['free_flight_entitled_label'] = (int)($m['free_flight_entitled']??0) === 1 ? 'Ja' : 'Nee';
    return $m;
}

function badge_pick_template(PDO $pdo, array $member): array|false
{
    $tagIds = array_map('intval', array_column($member['tag_rows']??[], 'id'));
    if ($tagIds) {
        $ph = implode(',', array_fill(0, count($tagIds), '?'));
        $stmt = $pdo->prepare("SELECT bt.*,t.name tag_name FROM badge_templates bt LEFT JOIN tags t ON t.id=bt.tag_id WHERE bt.is_active=1 AND bt.tag_id IN ($ph) ORDER BY bt.updated_at DESC,bt.id DESC LIMIT 1");
        $stmt->execute($tagIds);
        $row = $stmt->fetch();
        if ($row) return $row;
    }
    $stmt = $pdo->query('SELECT bt.*,NULL tag_name FROM badge_templates bt WHERE bt.is_active=1 AND bt.tag_id IS NULL ORDER BY bt.updated_at DESC,bt.id DESC LIMIT 1');
    return $stmt->fetch();
}

function badge_layout(array $template): array
{
    $layout = json_decode((string)($template['layout_json']??''), true);
    return is_array($layout) ? $layout : [];
}
