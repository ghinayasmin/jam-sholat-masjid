<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Config;
use App\Repository\SettingsRepository;
use App\View\Format;
use App\View\Icon;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$settingsRepo = new SettingsRepository();
$publicationsPath = (string) Config::get('publications_path');
$maxBytes = (int) Config::get('publication_max_bytes', 5 * 1024 * 1024);
$maxMb = round($maxBytes / 1024 / 1024, 1);

/** Hapus file foto masjid lama (bila ada). */
$removePhoto = static function (string $photo) use ($publicationsPath): void {
    if ($photo !== '') {
        @unlink($publicationsPath . '/' . basename($photo));
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $current = (string) $settingsRepo->get()['mosque_photo'];

    if (($_POST['action'] ?? '') === 'delete_photo') {
        $removePhoto($current);
        $settingsRepo->update(['mosque_photo' => '']);
        $_SESSION['flash_success'] = 'Foto masjid dihapus dari layar jamaah.';
        header('Location: /admin/mosque_profile.php');
        exit;
    }

    $name = trim((string) ($_POST['mosque_name'] ?? ''));
    $address = trim((string) ($_POST['mosque_address'] ?? ''));
    $file = $_FILES['mosque_photo'] ?? null;
    $error = null;
    $newPhoto = null;

    if ($name === '') {
        $error = 'Nama masjid tidak boleh kosong.';
    } elseif ($file !== null && $file['error'] !== UPLOAD_ERR_NO_FILE) {
        // Foto masjid tampil sebagai slide layar penuh (DECISIONS.md #28).
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Foto gagal diunggah. Coba lagi, atau pilih foto yang lebih kecil dari ' . $maxMb . ' MB.';
        } elseif ($file['size'] > $maxBytes) {
            $error = 'Ukuran foto melebihi ' . $maxMb . ' MB.';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
                $error = 'Format foto tidak didukung. Gunakan JPG atau PNG.';
            } else {
                $newPhoto = 'masjid_' . bin2hex(random_bytes(8)) . ($mime === 'image/png' ? '.png' : '.jpg');
                if (!move_uploaded_file($file['tmp_name'], $publicationsPath . '/' . $newPhoto)) {
                    $error = 'Foto gagal disimpan di perangkat.';
                    $newPhoto = null;
                }
            }
        }
    }

    if ($error !== null) {
        $_SESSION['flash_error'] = $error;
        header('Location: /admin/mosque_profile.php');
        exit;
    }

    $fields = ['mosque_name' => $name, 'mosque_address' => $address];
    if ($newPhoto !== null) {
        $fields['mosque_photo'] = $newPhoto;
        $removePhoto($current);
    }
    $settingsRepo->update($fields);
    $_SESSION['flash_success'] = $newPhoto !== null
        ? 'Profil masjid tersimpan. Foto masjid tampil di layar jamaah dalam 1 menit.'
        : 'Profil masjid berhasil disimpan.';
    header('Location: /admin/settings.php');
    exit;
}

$settings = $settingsRepo->get();
$photoUrl = $settings['mosque_photo'] !== ''
    ? '/uploads/publications/' . rawurlencode(basename($settings['mosque_photo']))
    : null;

$pageTitle = 'Profil masjid';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<form method="post" enctype="multipart/form-data" class="stack">
    <?= Csrf::field() ?>
    <div class="field">
        <label class="field-label" for="mosque_name">Nama masjid</label>
        <input type="text" id="mosque_name" name="mosque_name" maxlength="80" value="<?= Format::e($settings['mosque_name']) ?>" required>
        <p class="hint">Tampil besar di pojok kiri atas layar jamaah.</p>
    </div>
    <div class="field">
        <label class="field-label" for="mosque_address">Alamat <span class="opt">(boleh kosong)</span></label>
        <textarea id="mosque_address" name="mosque_address" rows="3" maxlength="160"><?= Format::e($settings['mosque_address']) ?></textarea>
        <p class="hint">Tampil kecil di bawah nama masjid.</p>
    </div>
    <div class="field">
        <span class="field-label">Foto masjid <span class="opt">(boleh kosong)</span></span>
        <label class="drop">
            <?php if ($photoUrl !== null): ?>
                <img src="<?= Format::e($photoUrl) ?>" alt="Foto masjid sekarang">
                <b>Ganti foto</b>
            <?php else: ?>
                <?= Icon::svg('upload') ?>
                <b>Pilih foto masjid</b>
            <?php endif; ?>
            <small>Foto mendatar (landscape) paling bagus · JPG/PNG, maks <?= $maxMb ?> MB</small>
            <input type="file" name="mosque_photo" accept="image/jpeg,image/png">
        </label>
        <p class="hint">Tampil layar penuh di layar jamaah, bergantian dengan poster pengumuman.</p>
    </div>
    <div class="foot"><button type="submit" class="btn">Simpan</button></div>
</form>

<?php if ($photoUrl !== null): ?>
<form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="delete_photo">
    <button type="submit" class="btn danger">Hapus foto masjid</button>
</form>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
