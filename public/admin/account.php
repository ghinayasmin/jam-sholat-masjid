<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Repository\AdminUserRepository;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$users = new AdminUserRepository();
$user = $users->findById((int) ($_SESSION['admin_id'] ?? 0));
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null) {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!password_verify($current, $user['password_hash'])) {
        $errors[] = 'Kata sandi saat ini salah.';
    }
    if (mb_strlen($new) < 8) {
        $errors[] = 'Kata sandi baru minimal 8 karakter.';
    }
    if ($new !== $confirm) {
        $errors[] = 'Ulangi kata sandi baru dengan sama persis.';
    }

    if ($errors === []) {
        // REQ-NF-121: hanya hash bcrypt yang disimpan.
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $users->updatePasswordHash((int) $user['id'], $hash);
        $auth->passwordChanged($hash); // HP lain yang masih masuk ikut keluar, HP ini tetap masuk
        $_SESSION['flash_success'] = 'Kata sandi berhasil diganti.';
        header('Location: /admin/settings.php');
        exit;
    }
}

$pageTitle = 'Akun takmir';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<?php if ($errors !== []): ?>
    <div class="flash flash-error" role="alert"><?= Format::e(implode(' ', $errors)) ?></div>
<?php endif; ?>

<div class="card"><div class="row ctl"><span class="k">Nama pengguna</span><span class="v"><?= Format::e($user['username'] ?? '-') ?></span></div></div>

<div class="note" style="margin-top:12px">Bila lupa kata sandi, kata sandi dapat dikembalikan ke kata sandi bawaan dari perangkat tempat aplikasi ini terpasang. Caranya ada di halaman <a href="/admin/help.php#sandi">Panduan</a>.</div>

<div class="label" style="margin-top:8px">Ganti kata sandi</div>
<form method="post" class="stack">
    <?= Csrf::field() ?>
    <input type="text" name="username" value="<?= Format::e($user['username'] ?? '') ?>" autocomplete="username" hidden>
    <div class="field">
        <label class="field-label" for="current_password">Kata sandi saat ini</label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
    </div>
    <div class="field">
        <label class="field-label" for="new_password">Kata sandi baru</label>
        <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
        <p class="hint">Minimal 8 karakter.</p>
    </div>
    <div class="field">
        <label class="field-label" for="confirm_password">Ulangi kata sandi baru</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
    </div>
    <div class="foot"><button type="submit" class="btn">Ganti kata sandi</button></div>
</form>


<?php require __DIR__ . '/_footer.php'; ?>
