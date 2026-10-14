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

// REQ-F-030: suara adzan dan iqomah dari speaker. Keduanya bisa dimatikan karena di
// masjid adzan dan iqomah biasanya dikumandangkan langsung oleh muadzin (DECISIONS.md #23).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $adzan = ($_POST['adzan_sound_enabled'] ?? '0') === '1';
    $iqomah = ($_POST['iqomah_sound_enabled'] ?? '0') === '1';
    $minutes = filter_input(INPUT_POST, 'azan_iqomah_interval_minutes', FILTER_VALIDATE_INT);
    if ($minutes === false || $minutes === null || $minutes < 1 || $minutes > 60) {
        $error = 'Jeda harus antara 1 dan 60 menit.';
    } else {
        $settingsRepo->update([
            'adzan_sound_enabled' => $adzan ? 1 : 0,
            'iqomah_sound_enabled' => $iqomah ? 1 : 0,
            'azan_iqomah_interval_minutes' => $minutes,
        ]);
        $_SESSION['flash_success'] = 'Suara adzan ' . ($adzan ? 'menyala' : 'mati') . ', suara iqomah '
            . ($iqomah ? "menyala ($minutes menit setelah adzan)" : 'mati') . '.';
        header('Location: /admin/settings.php');
        exit;
    }
}

$saved = $settingsRepo->get();
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$minutes = (int) ($_POST['azan_iqomah_interval_minutes'] ?? $saved['azan_iqomah_interval_minutes']);
$adzan = $isPost ? ($_POST['adzan_sound_enabled'] ?? '0') === '1' : (int) $saved['adzan_sound_enabled'] === 1;
$iqomah = $isPost ? ($_POST['iqomah_sound_enabled'] ?? '0') === '1' : (int) $saved['iqomah_sound_enabled'] === 1;

$pageTitle = 'Suara adzan & iqomah';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<?php if ($error !== null): ?>
    <div class="flash flash-error" role="alert"><?= Format::e($error) ?></div>
<?php endif; ?>

<form method="post" class="stack">
    <?= Csrf::field() ?>

    <section class="group" aria-labelledby="adzan-label">
        <div class="label" id="adzan-label">Suara adzan</div>
        <div class="seg" role="radiogroup" aria-labelledby="adzan-label">
            <label><input type="radio" name="adzan_sound_enabled" value="1"<?= $adzan ? ' checked' : '' ?>><span>Nyala</span></label>
            <label><input type="radio" name="adzan_sound_enabled" value="0"<?= !$adzan ? ' checked' : '' ?>><span>Mati</span></label>
        </div>
    </section>

    <section class="group" aria-labelledby="iqomah-label">
        <div class="label" id="iqomah-label">Suara iqomah</div>
        <div class="seg" role="radiogroup" aria-labelledby="iqomah-label">
            <label><input type="radio" name="iqomah_sound_enabled" value="1"<?= $iqomah ? ' checked' : '' ?>><span>Nyala</span></label>
            <label><input type="radio" name="iqomah_sound_enabled" value="0"<?= !$iqomah ? ' checked' : '' ?>><span>Mati</span></label>
        </div>
        <div class="card" style="margin-top:8px">
            <div class="row" style="grid-template-columns:minmax(0,1fr) auto">
                <label class="k" for="azan_iqomah_interval_minutes">Menit setelah adzan<small>Berlaku untuk semua waktu sholat</small></label>
                <span class="stepper"><button type="button" data-step="-1" aria-label="Kurangi">−</button><input type="number" id="azan_iqomah_interval_minutes" name="azan_iqomah_interval_minutes" min="1" max="60" inputmode="numeric" value="<?= $minutes ?>"><button type="button" data-step="1" aria-label="Tambah">+</button></span>
            </div>
        </div>
    </section>

    <div class="note">Matikan bila adzan atau iqomah dikumandangkan langsung oleh muadzin. Layar jamaah tetap menampilkan waktu adzan, dan murottal tetap berhenti sekitar waktu sholat.</div>
    <div class="foot"><button type="submit" class="btn">Simpan</button></div>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
