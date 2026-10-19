<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\Csrf;
use App\Auth\RecoveryCode;
use App\Auth\Throttle;
use App\Repository\AdminUserRepository;

// Lupa kata sandi: takmir memakai kode pemulihan sekali pakai (DECISIONS.md #33).
// Bila kode juga hilang, teknisi menjalankan `sudo jamsholat-reset-password` di perangkat.
$auth = new AuthManager();
$auth->startSession();

$error = null;
$newCode = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);

    $username = trim((string) ($_POST['username'] ?? ''));
    $code = (string) ($_POST['recovery_code'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    // 5 kali salah dalam 15 menit dari satu perangkat: tunggu dulu.
    $throttle = new Throttle('recover', 5);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $wait = $throttle->waitSeconds($ip);
    $users = new AdminUserRepository();

    if ($wait > 0) {
        $error = 'Terlalu banyak percobaan. Coba lagi dalam ' . Throttle::minutesLabel($wait) . '.';
    } elseif ($username === '' || trim($code) === '') {
        $error = 'Isi nama pengguna dan kode pemulihan.';
    } elseif (mb_strlen($new) < 8) {
        $error = 'Kata sandi baru minimal 8 karakter.';
    } elseif ($new !== $confirm) {
        $error = 'Ulangi kata sandi baru dengan sama persis.';
    } else {
        $user = $users->findByUsername($username);
        if ($user === null || !RecoveryCode::verify($code, $user['recovery_hash'] ?? null)) {
            if ($user === null) {
                RecoveryCode::verify($code, null); // waktu respons sama dengan kode salah
            }
            $throttle->recordFailure($ip);
            $error = 'Nama pengguna atau kode pemulihan salah.';
        } else {
            // Kode sekali pakai: langsung diganti kode baru yang ditampilkan sekarang.
            $users->updatePasswordHash((int) $user['id'], password_hash($new, PASSWORD_BCRYPT));
            $newCode = RecoveryCode::generate();
            $users->setRecoveryHash((int) $user['id'], RecoveryCode::hash($newCode));
            $throttle->clear($ip);
        }
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
<title>Lupa kata sandi · Panel Takmir</title>
<link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/admin.css') ?>">
</head>
<body>
<main class="login">
<?php if ($newCode !== null): ?>
    <div style="display:grid;gap:20px;width:100%;max-width:380px">
        <div class="brand">
            <h1>Kata sandi diganti</h1>
            <p>Silakan masuk dengan kata sandi baru.</p>
        </div>
        <div class="recovery">
            <span class="field-label">Kode pemulihan baru</span>
            <code class="recovery-code"><?= htmlspecialchars($newCode) ?></code>
            <p class="hint">Kode lama sudah tidak berlaku. Tulis atau foto kode baru ini dan simpan di tempat aman. Kode ini hanya ditampilkan sekali.</p>
        </div>
        <a class="btn" href="/admin/login.php">Masuk</a>
    </div>
<?php else: ?>
    <form method="post">
        <div class="brand">
            <h1>Lupa kata sandi</h1>
            <p>Masukkan kode pemulihan yang dibuat di menu Akun takmir.</p>
        </div>
        <?php if ($error !== null): ?>
            <div class="flash flash-error" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?= Csrf::field() ?>
        <label class="field" for="username"><span class="field-label">Nama pengguna</span>
            <input type="text" id="username" name="username" required autocomplete="username" autocapitalize="none" autocorrect="off" value="<?= htmlspecialchars($username) ?>">
        </label>
        <label class="field" for="recovery_code"><span class="field-label">Kode pemulihan</span>
            <input type="text" id="recovery_code" name="recovery_code" required autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" placeholder="XXXX-XXXX-XXXX-XXXX">
        </label>
        <label class="field" for="new_password"><span class="field-label">Kata sandi baru</span>
            <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
        </label>
        <label class="field" for="confirm_password"><span class="field-label">Ulangi kata sandi baru</span>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
        </label>
        <button type="submit" class="btn">Ganti kata sandi</button>
        <p class="muted" style="margin:0;font-size:14px">Kode pemulihan juga hilang? Minta teknisi menjalankan <code>sudo jamsholat-reset-password</code> di perangkat. <a href="/admin/login.php">Kembali ke halaman masuk</a></p>
    </form>
<?php endif; ?>
</main>
</body>
</html>
