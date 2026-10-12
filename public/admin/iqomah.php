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
    $enabled = ($_POST['iqomah_sound_enabled'] ?? '0') === '1';
    $minutes = filter_input(INPUT_POST, 'azan_iqomah_interval_minutes', FILTER_VALIDATE_INT);
    if ($minutes === false || $minutes === null || $minutes < 1 || $minutes > 60) {
        $error = 'Jeda harus antara 1 dan 60 menit.';
    } else {
        $settingsRepo->update(['azan_iqomah_interval_minutes' => $minutes, 'iqomah_sound_enabled' => $enabled ? 1 : 0]);
        $_SESSION['flash_success'] = $enabled
            ? "Suara iqomah akan diputar $minutes menit setelah adzan."
            : 'Suara iqomah dimatikan.';
        header('Location: /admin/settings.php');
        exit;
    }
}

$saved = $settingsRepo->get();
$minutes = (int) ($_POST['azan_iqomah_interval_minutes'] ?? $saved['azan_iqomah_interval_minutes']);
$enabled = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['iqomah_sound_enabled'] ?? '0') === '1'
    : (int) $saved['iqomah_sound_enabled'] === 1;

$pageTitle = 'Suara iqomah';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<?php if ($error !== null): ?>
    <div class="flash flash-error" role="alert"><?= Format::e($error) ?></div>
<?php endif; ?>

<form method="post" class="stack">
    <?= Csrf::field() ?>
    <div class="seg" role="radiogroup" aria-label="Suara iqomah">
        <label><input type="radio" name="iqomah_sound_enabled" value="1"<?= $enabled ? ' checked' : '' ?>><span>Nyala</span></label>
        <label><input type="radio" name="iqomah_sound_enabled" value="0"<?= !$enabled ? ' checked' : '' ?>><span>Mati</span></label>
    </div>
    <div class="card">
        <div class="row" style="grid-template-columns:minmax(0,1fr) auto">
            <label class="k" for="azan_iqomah_interval_minutes">Menit setelah adzan<small>Berlaku untuk semua waktu sholat</small></label>
            <span class="stepper"><button type="button" data-step="-1" aria-label="Kurangi">−</button><input type="number" id="azan_iqomah_interval_minutes" name="azan_iqomah_interval_minutes" min="1" max="60" inputmode="numeric" value="<?= $minutes ?>"><button type="button" data-step="1" aria-label="Tambah">+</button></span>
        </div>
    </div>
    <div class="note">Bila menyala, speaker memutar rekaman iqomah setelah jeda ini. Matikan bila iqomah dikumandangkan langsung oleh muadzin. Layar jamaah tidak menampilkan hitung mundur iqomah.</div>
    <div class="foot"><button type="submit" class="btn">Simpan</button></div>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
