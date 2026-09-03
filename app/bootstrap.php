<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('heli_one_members');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Ongeldige of verlopen aanvraag. Vernieuw de pagina en probeer opnieuw.');
    }
}

function ensure_member_soft_delete_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME="members" AND COLUMN_NAME IN ("deleted_at","deleted_by_admin_id")');
    $stmt->execute(['db'=>$dbName]);
    $cols = array_column($stmt->fetchAll(), 'COLUMN_NAME');
    if (!in_array('deleted_at', $cols, true)) {
        $pdo->exec('ALTER TABLE members ADD COLUMN deleted_at DATETIME NULL AFTER notes, ADD INDEX idx_members_deleted_at (deleted_at)');
    }
    if (!in_array('deleted_by_admin_id', $cols, true)) {
        $pdo->exec('ALTER TABLE members ADD COLUMN deleted_by_admin_id INT UNSIGNED NULL AFTER deleted_at');
    }
    $done = true;
}

function ensure_member_type_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $s=$pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME="members" AND COLUMN_NAME="member_type"');
    $s->execute(['db'=>$dbName]);
    $type=(string)$s->fetchColumn();
    if ($type !== '' && !str_contains($type, "'pilot'")) {
        $pdo->exec("ALTER TABLE members MODIFY member_type ENUM('supporting','flying','viewer','flyer','pilot') NOT NULL DEFAULT 'viewer'");
        $pdo->exec("UPDATE members SET member_type='viewer' WHERE member_type='supporting'");
        $pdo->exec("UPDATE members SET member_type='flyer' WHERE member_type='flying'");
        $pdo->exec("ALTER TABLE members MODIFY member_type ENUM('viewer','flyer','pilot') NOT NULL DEFAULT 'viewer'");
    }
    $s=$pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME="memberships" AND COLUMN_NAME="membership_type"');
    $s->execute(['db'=>$dbName]);
    $type=(string)$s->fetchColumn();
    if ($type !== '' && !str_contains($type, "'pilot'")) {
        $pdo->exec("ALTER TABLE memberships MODIFY membership_type ENUM('supporting','flying','viewer','flyer','pilot') NOT NULL");
        $pdo->exec("UPDATE memberships SET membership_type='viewer' WHERE membership_type='supporting'");
        $pdo->exec("UPDATE memberships SET membership_type='flyer' WHERE membership_type='flying'");
        $pdo->exec("ALTER TABLE memberships MODIFY membership_type ENUM('viewer','flyer','pilot') NOT NULL");
    }
    // De jaarlijkse vluchttoekenning volgt het lidtype: alleen FLYER heeft recht op 1 gratis vlucht.
    $pdo->exec("UPDATE memberships ms INNER JOIN members m ON m.id=ms.member_id SET ms.free_flight_entitled=CASE WHEN m.member_type='flyer' THEN 1 ELSE 0 END");
    $done = true;
}

// De ledenfiche krijgt een client-side cropper voor pasfoto's (vaste verhouding 3:4).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'member.php') {
    register_shutdown_function(static function (): void {
        echo '<script src="/assets/member-photo-cropper.js?v=1"></script>';
    });
}
