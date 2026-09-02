<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$configFile = __DIR__ . '/config/runtime.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Runtimeconfiguratie ontbreekt.');
}
$runtime = require $configFile;
$expectedToken = (string)($runtime['setup_token'] ?? '');
$providedToken = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    exit('Geen toegang.');
}

$pdo = Database::connection();
$schema = file_get_contents(__DIR__ . '/database/schema.sql');
if ($schema === false) {
    throw new RuntimeException('Schema kon niet worden gelezen.');
}
foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

$adminCount = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $adminCount === 0) {
    verify_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
        $error = 'Vul een naam, geldig e-mailadres en wachtwoord van minstens 12 tekens in.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO admins (name, email, password_hash) VALUES (:name, :email, :password_hash)');
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $adminCount = 1;
        $message = 'Administrator aangemaakt. Je kunt nu aanmelden.';
    }
}
?><!doctype html>
<html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Heli One Members Setup</title>
<style>body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:40px}.card{max-width:520px;margin:auto;background:white;padding:32px;border-radius:14px;box-shadow:0 8px 30px #0001}label{display:block;margin:18px 0 6px}input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #ccd3da;border-radius:8px}button{margin-top:22px;padding:12px 18px;border:0;border-radius:8px;background:#111;color:#fff;font-weight:700}.ok{background:#e9f8ee;padding:12px;border-radius:8px}.err{background:#feecec;padding:12px;border-radius:8px}</style></head><body><div class="card"><h1>Heli One Members</h1><h2>Eerste installatie</h2>
<?php if ($message): ?><p class="ok"><?= e($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
<?php if ($adminCount > 0): ?><p>De database is geïnstalleerd en er bestaat al een administrator.</p><p><a href="/login.php">Naar aanmelden</a></p>
<?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($providedToken) ?>"><label>Naam</label><input name="name" required><label>E-mailadres</label><input type="email" name="email" required><label>Wachtwoord</label><input type="password" name="password" minlength="12" required><button type="submit">Administrator aanmaken</button></form><?php endif; ?>
</div></body></html>