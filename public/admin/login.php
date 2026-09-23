<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\Csrf;

$auth = new AuthManager();
$auth->startSession();

if ($auth->isLoggedIn()) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } elseif ($auth->attempt($username, $password)) {
        header('Location: /admin/dashboard.php');
        exit;
    } else {
        $error = 'Username atau password salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login Admin — Panel Masjid</title>
<link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="login-body">
<form method="post" class="login-card">
    <h1>🕌 Login Takmir</h1>
    <?php if ($error !== null): ?>
        <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?= Csrf::field() ?>
    <label>Username
        <input type="text" name="username" autofocus required autocomplete="username">
    </label>
    <label>Password
        <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit">Masuk</button>
</form>
</body>
</html>
