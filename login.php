<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

if (Auth::check()) {
    header('Location: /index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = (string)($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    try {
        if (Auth::attempt($email, $password)) {
            header('Location: /index.php');
            exit;
        }
        $error = 'E-mailadres of wachtwoord is niet correct.';
    } catch (Throwable $e) {
        $error = 'De toepassing is nog niet volledig geïnstalleerd.';
    }
}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Aanmelden | Heli One Members</title><style>body{font-family:Arial,sans-serif;background:#f3f5f7;margin:0;display:grid;place-items:center;min-height:100vh}.card{width:min(420px,calc(100% - 40px));background:#fff;padding:32px;border-radius:16px;box-shadow:0 12px 40px #0001}h1{margin:0 0 6px}p{color:#667085}label{display:block;margin:18px 0 6px;font-weight:700}input{box-sizing:border-box;width:100%;padding:13px;border:1px solid #cbd2d9;border-radius:9px}button{width:100%;margin-top:22px;padding:13px;border:0;border-radius:9px;background:#111;color:#fff;font-weight:700}.err{background:#feecec;color:#8b1d1d;padding:11px;border-radius:8px}</style></head><body><main class="card"><h1>Heli One Members</h1><p>Administrator toegang</p><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>E-mailadres</label><input type="email" name="email" autocomplete="username" required><label>Wachtwoord</label><input type="password" name="password" autocomplete="current-password" required><button type="submit">Aanmelden</button></form></main></body></html>