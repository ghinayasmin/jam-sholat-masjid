<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Auth\RecoveryCode;
use App\Repository\AdminUserRepository;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$users = new AdminUserRepository();
$user = $users->findById((int) ($_SESSION['admin_id'] ?? 0));
$errors = [];
$newCode = null;

// Kode pemulihan baru (DECISIONS.md #33): minta kata sandi saat ini supaya orang yang
// sekadar memegang HP yang masih masuk tidak bisa membuat kode sendiri.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null && ($_POST['action'] ?? '') === 'recovery_new') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    if (!password_verify((string) ($_POST['recovery_password'] ?? ''), $user['password_hash'])) {
        $errors[] = 'Kata sandi saat ini salah, kode pemulihan tidak dibuat.';
    } else {
        $newCode = RecoveryCode::generate();
        $users->setRecoveryHash((int) $user['id'], RecoveryCode::hash($newCode));
        $user = $users->findById((int) $user['id']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null) {
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
        $users->updatePasswordHash((int) $user['id'], password_hash($new, PASSWORD_BCRYPT));
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

<section class="group" aria-labelledby="recovery-label" id="kode-pemulihan">
    <div class="label" id="recovery-label">Kode pemulihan</div>
    <?php if ($newCode !== null): ?>
        <div class="recovery">
            <span class="field-label">Kode pemulihan baru</span>
            <code class="recovery-code"><?= Format::e($newCode) ?></code>
            <p class="hint">Tulis atau foto kode ini dan simpan di tempat aman, misalnya bersama dokumen masjid. Kode ini hanya ditampilkan sekali dan kode lama sudah tidak berlaku.</p>
        </div>
    <?php elseif (!empty($user['recovery_created_at'])): ?>
        <div class="note">Kode pemulihan sudah dibuat pada <?= Format::e(Format::longDate(substr((string) $user['recovery_created_at'], 0, 10))) ?>. Bila lupa kata sandi, buka "Lupa kata sandi?" di halaman masuk.</div>
    <?php else: ?>
        <div class="note warn">Belum ada kode pemulihan. Buat sekarang supaya kata sandi bisa diganti sendiri bila lupa, tanpa teknisi.</div>
    <?php endif; ?>
    <form method="post" style="display:grid;gap:12px;margin-top:12px">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="recovery_new">
        <input type="text" name="username" value="<?= Format::e($user['username'] ?? '') ?>" autocomplete="username" hidden>
        <div class="field">
            <label class="field-label" for="recovery_password">Kata sandi saat ini</label>
            <input type="password" id="recovery_password" name="recovery_password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn ghost"><?= empty($user['recovery_created_at']) ? 'Buat kode pemulihan' : 'Buat kode pemulihan baru' ?></button>
    </form>
</section>

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
