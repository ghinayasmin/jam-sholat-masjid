<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Repository\SettingsRepository;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$settingsRepo = new SettingsRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);

    $name = trim((string) ($_POST['mosque_name'] ?? ''));
    $address = trim((string) ($_POST['mosque_address'] ?? ''));

    if ($name === '') {
        $_SESSION['flash_error'] = 'Nama masjid tidak boleh kosong.';
    } else {
        $settingsRepo->update(['mosque_name' => $name, 'mosque_address' => $address]);
        $_SESSION['flash_success'] = 'Profil masjid berhasil disimpan.';
    }
    header('Location: ' . ($name === '' ? '/admin/mosque_profile.php' : '/admin/settings.php'));
    exit;
}

$settings = $settingsRepo->get();

$pageTitle = 'Profil masjid';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<form method="post" class="stack">
    <?= Csrf::field() ?>
    <div class="field">
        <label class="field-label" for="mosque_name">Nama masjid</label>
        <input type="text" id="mosque_name" name="mosque_name" maxlength="80" value="<?= htmlspecialchars($settings['mosque_name']) ?>" required>
        <p class="hint">Tampil besar di pojok kiri atas layar jamaah.</p>
    </div>
    <div class="field">
        <label class="field-label" for="mosque_address">Alamat <span class="opt">(boleh kosong)</span></label>
        <textarea id="mosque_address" name="mosque_address" rows="3" maxlength="160"><?= htmlspecialchars($settings['mosque_address']) ?></textarea>
        <p class="hint">Tampil kecil di bawah nama masjid.</p>
    </div>
    <div class="foot"><button type="submit" class="btn">Simpan</button></div>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
