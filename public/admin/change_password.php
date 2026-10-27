<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Repository\AdminUserRepository;

// Masuk pertama dengan akun bawaan admin / 000, atau setelah direset ke bawaan: kata
// sandi wajib diganti sebelum panel bisa dipakai (DECISIONS.md #41).
$auth = new AuthManager();
AuthMiddleware::requireLogin($auth, allowDefaultPassword: true);
if (!$auth->mustChangePassword()) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    // Minimal 8 karakter, jadi kata sandi bawaan 000 tidak mungkin dipakai lagi.
    if (mb_strlen($new) < 8) {
        $error = 'Kata sandi baru minimal 8 karakter.';
    } elseif ($new !== $confirm) {
        $error = 'Ulangi kata sandi baru dengan sama persis.';
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        (new AdminUserRepository())->updatePasswordHash((int) $_SESSION['admin_id'], $hash);
        $auth->passwordChanged($hash);
        $_SESSION['flash_success'] = 'Kata sandi berhasil diganti.';
        // Instalasi baru: lanjut ke Langkah awal (#42).
        $setupDone = (int) (new \App\Repository\SettingsRepository())->get()['setup_done'] === 1;
        header('Location: ' . ($setupDone ? '/admin/dashboard.php' : '/admin/welcome.php'));
        exit;
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
<title>Ganti kata sandi bawaan · Panel Takmir</title>
<link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/admin.css') ?>">
</head>
<body>
<main class="login">
    <form method="post">
        <div class="brand">
            <h1>Ganti kata sandi bawaan</h1>
            <p>Akun ini masih menggunakan kata sandi bawaan. Demi keamanan, silakan buat kata sandi baru sebelum menggunakan panel.</p>
        </div>
        <?php if ($error !== null): ?>
            <div class="flash flash-error" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?= Csrf::field() ?>
        <input type="text" name="username" value="<?= htmlspecialchars((string) $auth->currentUsername()) ?>" autocomplete="username" hidden>
        <label class="field" for="new_password"><span class="field-label">Kata sandi baru</span>
            <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
            <span class="hint">Minimal 8 karakter.</span>
        </label>
        <label class="field" for="confirm_password"><span class="field-label">Ulangi kata sandi baru</span>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
        </label>
        <button type="submit" class="btn">Simpan kata sandi</button>
    </form>
</main>
<script src="/assets/js/admin.js?v=<?= filemtime(dirname(__DIR__) . '/assets/js/admin.js') ?>"></script>
</body>
</html>
