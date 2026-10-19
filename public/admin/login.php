<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\Csrf;
use App\Auth\Throttle;

$auth = new AuthManager();
$auth->startSession();

if ($auth->isLoggedIn()) {
    header('Location: /admin/dashboard.php');
    exit;
}
if ((new \App\Repository\AdminUserRepository())->count() === 0) {
    header('Location: /admin/setup.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    // 10 kali salah dalam 15 menit dari satu perangkat: tunggu dulu (DECISIONS.md #33).
    $throttle = new Throttle('login', 10);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $wait = $throttle->waitSeconds($ip);

    if ($wait > 0) {
        $error = 'Terlalu banyak percobaan masuk. Coba lagi dalam ' . Throttle::minutesLabel($wait) . '.';
    } elseif ($username === '' || $password === '') {
        $error = 'Nama pengguna dan kata sandi wajib diisi.';
    } elseif ($auth->attempt($username, $password)) {
        $throttle->clear($ip);
        header('Location: /admin/dashboard.php');
        exit;
    } else {
        $throttle->recordFailure($ip);
        $error = 'Nama pengguna atau kata sandi salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="google" content="notranslate">
<meta name="theme-color" content="#F2F3EF">
<title>Masuk · Panel Takmir</title>
<link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/admin.css') ?>">
</head>
<body>
<main class="login">
    <form method="post">
        <div class="brand">
            <h1>Panel Takmir</h1>
            <p>Masuk untuk mengatur layar jamaah masjid.</p>
        </div>
        <?php if ($error !== null): ?>
            <div class="flash flash-error" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?= Csrf::field() ?>
        <label class="field" for="username"><span class="field-label">Nama pengguna</span>
            <input type="text" id="username" name="username" required autocomplete="username" autocapitalize="none" autocorrect="off">
        </label>
        <label class="field" for="password"><span class="field-label">Kata sandi</span>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </label>
        <button type="submit" class="btn">Masuk</button>
        <p class="muted" style="margin:0;font-size:14px"><a href="/admin/forgot.php">Lupa kata sandi?</a> Pakai kode pemulihan yang disimpan takmir.</p>
    </form>
</main>
<script src="/assets/js/admin.js?v=<?= filemtime(dirname(__DIR__) . '/assets/js/admin.js') ?>"></script>
</body>
</html>
