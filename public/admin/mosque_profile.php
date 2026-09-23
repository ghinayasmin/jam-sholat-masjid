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
    header('Location: /admin/mosque_profile.php');
    exit;
}

$settings = $settingsRepo->get();

$pageTitle = 'Profil Masjid';
$activeMenu = 'profile';
require __DIR__ . '/_header.php';
?>

<form method="post" class="card form-card">
    <?= Csrf::field() ?>
    <label>Nama Masjid
        <input type="text" name="mosque_name" value="<?= htmlspecialchars($settings['mosque_name']) ?>" required>
    </label>
    <label>Alamat Masjid
        <textarea name="mosque_address" rows="3"><?= htmlspecialchars($settings['mosque_address']) ?></textarea>
    </label>
    <button type="submit">Simpan</button>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
