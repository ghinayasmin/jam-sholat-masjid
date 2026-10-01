<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Repository\SettingsRepository;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$settingsRepo = new SettingsRepository();
$error = null;

// REQ-F-025: jarak adzan → iqomah, dipakai layanan alarm untuk memutar suara iqomah.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $minutes = filter_input(INPUT_POST, 'azan_iqomah_interval_minutes', FILTER_VALIDATE_INT);
    if ($minutes === false || $minutes === null || $minutes < 1 || $minutes > 60) {
        $error = 'Jeda harus antara 1 dan 60 menit.';
    } else {
        $settingsRepo->update(['azan_iqomah_interval_minutes' => $minutes]);
        $_SESSION['flash_success'] = "Suara iqomah akan diputar $minutes menit setelah adzan.";
        header('Location: /admin/settings.php');
        exit;
    }
}

$minutes = (int) ($_POST['azan_iqomah_interval_minutes'] ?? $settingsRepo->get()['azan_iqomah_interval_minutes']);

$pageTitle = 'Jeda adzan–iqomah';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<?php if ($error !== null): ?>
    <div class="flash flash-error" role="alert"><?= Format::e($error) ?></div>
<?php endif; ?>

<form method="post" class="stack">
    <?= Csrf::field() ?>
    <div class="card">
        <div class="row" style="grid-template-columns:minmax(0,1fr) auto">
            <label class="k" for="azan_iqomah_interval_minutes">Menit setelah adzan<small>Berlaku untuk semua waktu sholat</small></label>
            <span class="stepper"><button type="button" data-step="-1" aria-label="Kurangi">−</button><input type="number" id="azan_iqomah_interval_minutes" name="azan_iqomah_interval_minutes" min="1" max="60" inputmode="numeric" value="<?= $minutes ?>"><button type="button" data-step="1" aria-label="Tambah">+</button></span>
        </div>
    </div>
    <div class="note">Setelah jeda ini, speaker masjid memutar suara iqomah secara otomatis. Layar jamaah tidak menampilkan hitung mundur iqomah.</div>
    <div class="foot"><button type="submit" class="btn">Simpan</button></div>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
