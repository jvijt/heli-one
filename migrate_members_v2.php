<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
Auth::requireLogin();

$pdo = Database::connection();
$message = '';
$error = '';

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (!column_exists($pdo, 'members', 'mobile')) {
            if (column_exists($pdo, 'members', 'phone')) {
                $pdo->exec('ALTER TABLE members CHANGE phone mobile VARCHAR(50) NULL');
            } else {
                $pdo->exec('ALTER TABLE members ADD mobile VARCHAR(50) NULL AFTER birth_date');
            }
        }
        if (!column_exists($pdo, 'members', 'weight_kg')) {
            $pdo->exec('ALTER TABLE members ADD weight_kg DECIMAL(5,2) NULL AFTER email');
        }
        if (!column_exists($pdo, 'members', 'member_since')) {
            $pdo->exec('ALTER TABLE members ADD member_since DATE NULL AFTER weight_kg');
        }
        if (!column_exists($pdo, 'members', 'photo_path')) {
            $pdo->exec('ALTER TABLE members ADD photo_path VARCHAR(255) NULL AFTER member_since');
        }
        $pdo->exec("ALTER TABLE members MODIFY status ENUM('active','inactive','pending','cancelled') NOT NULL DEFAULT 'active'");

        if (!column_exists($pdo, 'memberships', 'payment_status')) {
            $pdo->exec("ALTER TABLE memberships ADD payment_status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid' AFTER membership_type");
        }
        if (!column_exists($pdo, 'memberships', 'free_flight_entitled')) {
            $pdo->exec('ALTER TABLE memberships ADD free_flight_entitled TINYINT(1) NOT NULL DEFAULT 1 AFTER paid_at');
        }
        if (!column_exists($pdo, 'memberships', 'free_flight_date')) {
            $pdo->exec('ALTER TABLE memberships ADD free_flight_date DATE NULL AFTER free_flight_entitled');
        }

        $pdo->exec("UPDATE memberships SET payment_status = CASE WHEN paid_at IS NULL THEN 'unpaid' ELSE 'paid' END");
        if (column_exists($pdo, 'memberships', 'annual_flight_entitlement')) {
            $pdo->exec('UPDATE memberships SET free_flight_entitled = CASE WHEN annual_flight_entitlement > 0 THEN 1 ELSE 0 END');
        }

        $message = 'Database is bijgewerkt voor het uitgebreide ledenprofiel.';
    } catch (Throwable $e) {
        $error = 'Migratie mislukt: ' . $e->getMessage();
    }
}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Database bijwerken</title><style>body{font-family:Arial,sans-serif;background:#f5f6f8;margin:0;padding:40px;color:#171717}.card{max-width:650px;margin:auto;background:#fff;padding:32px;border-radius:14px;box-shadow:0 4px 18px #0001}.btn{padding:12px 18px;border:0;border-radius:9px;background:#111;color:#fff;font-weight:700;cursor:pointer}.ok{background:#e9f8ee;padding:12px;border-radius:8px}.err{background:#feecec;padding:12px;border-radius:8px}a{color:#111}</style></head><body><div class="card"><h1>Database bijwerken</h1><p>Deze migratie voegt de nieuwe ledenvelden toe aan de bestaande Heli One database.</p><?php if($message):?><p class="ok"><?=e($message)?></p><p><a href="/members.php">Naar ledenbeheer</a></p><?php elseif($error):?><p class="err"><?=e($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button class="btn" type="submit">Database nu bijwerken</button></form></div></body></html>