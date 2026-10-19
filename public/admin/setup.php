<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\Csrf;
use App\Auth\RecoveryCode;
use App\Repository\AdminUserRepository;

// Akun takmir pertama untuk instalasi dari paket .deb / installer Windows (DECISIONS.md #39).
// Hanya bisa dibuka dari komputer masjid itu sendiri, dan hanya selama belum ada akun,
// supaya orang lain di WiFi masjid tidak bisa mendahului membuat akun.
$auth = new AuthManager();
$auth->startSession();
$users = new AdminUserRepository();

if ($users->count() > 0 && !isset($_SESSION['setup_code'])) {
    header('Location: /admin/login.php');
    exit;
}

$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $local && $users->count() === 0) {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
        $error = 'Nama pengguna 3–32 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.';
    } elseif (mb_strlen($password) < 8) {
        $error = 'Kata sandi minimal 8 karakter.';
    } elseif ($password !== $confirm) {
        $error = 'Ulangi kata sandi dengan sama persis.';
    } else {
        $id = $users->create($username, password_hash($password, PASSWORD_BCRYPT));
        $code = RecoveryCode::generate();
        $users->setRecoveryHash($id, RecoveryCode::hash($code));
        $auth->attempt($username, $password);
        $_SESSION['setup_code'] = $code;
        header('Location: /admin/setup.php');
        exit;
    }
}

$code = $_SESSION['setup_code'] ?? null;
unset($_SESSION['setup_code']); // kode hanya ditampilkan sekali
?>
<!DOCTYPE html>
<html lang="id" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="google" content="notranslate">
<meta name="theme-color" content="#F2F3EF">
<title>Buat akun takmir · Panel Takmir</title>
<link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/admin.css') ?>">
</head>
<body>
<main class="login">
<?php if ($code !== null): ?>
    <div style="display:grid;gap:20px;width:100%;max-width:380px">
        <div class="brand">
            <h1>Akun takmir siap</h1>
            <p>Panel bisa dibuka dari HP pengurus lewat WiFi masjid.</p>
        </div>
        <div class="recovery">
            <span class="field-label">Kode pemulihan</span>
            <code class="recovery-code"><?= htmlspecialchars($code) ?></code>
            <p class="hint">Dipakai bila lupa kata sandi. Tulis atau foto kode ini dan simpan di tempat aman; kode hanya ditampilkan sekali.</p>
        </div>
        <a class="btn" href="/admin/settings.php">Lanjut ke pengaturan masjid</a>
    </div>
<?php elseif (!$local): ?>
    <div style="display:grid;gap:20px;width:100%;max-width:380px">
        <div class="brand">
            <h1>Belum ada akun takmir</h1>
            <p>Akun pertama dibuat dari komputer masjid itu sendiri: buka <b>http://localhost<?= in_array((string) ($_SERVER['SERVER_PORT'] ?? '80'), ['80', ''], true) ? '' : ':' . (int) $_SERVER['SERVER_PORT'] ?>/admin/</b> di komputer tersebut.</p>
        </div>
    </div>
<?php else: ?>
    <form method="post">
        <div class="brand">
            <h1>Buat akun takmir</h1>
            <p>Akun ini dipakai pengurus untuk membuka panel dari HP.</p>
        </div>
        <?php if ($error !== null): ?>
            <div class="flash flash-error" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?= Csrf::field() ?>
        <label class="field" for="username"><span class="field-label">Nama pengguna</span>
            <input type="text" id="username" name="username" required autocomplete="username" autocapitalize="none" autocorrect="off" value="<?= htmlspecialchars($username) ?>" placeholder="mis. takmir">
        </label>
        <label class="field" for="password"><span class="field-label">Kata sandi</span>
            <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
        </label>
        <label class="field" for="confirm_password"><span class="field-label">Ulangi kata sandi</span>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
        </label>
        <button type="submit" class="btn">Buat akun</button>
    </form>
<?php endif; ?>
</main>
</body>
</html>
