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
// Jeda adzan→iqomah per waktu sholat (DECISIONS.md #30). Jumat siang tidak ada iqomah
// otomatis karena iqomah Jumat dikumandangkan setelah khutbah.
$prayers = ['fajr' => 'Subuh', 'dhuhr' => 'Dzuhur', 'asr' => 'Ashar', 'maghrib' => 'Maghrib', 'isha' => 'Isya'];
$defaults = ['fajr' => 20, 'dhuhr' => 10, 'asr' => 10, 'maghrib' => 10, 'isha' => 10];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $adzan = ($_POST['adzan_sound_enabled'] ?? '0') === '1';
    $iqomah = ($_POST['iqomah_sound_enabled'] ?? '0') === '1';
    $fields = [
        'adzan_sound_enabled' => $adzan ? 1 : 0,
        'iqomah_sound_enabled' => $iqomah ? 1 : 0,
    ];
    foreach ($prayers as $key => $name) {
        $value = filter_input(INPUT_POST, "iqomah_{$key}_minutes", FILTER_VALIDATE_INT);
        // Minimal 5 menit: suara adzan sendiri berlangsung 3–5 menit.
        if ($value === false || $value === null || $value < 5 || $value > 60) {
            $error = "Jeda iqomah $name harus antara 5 dan 60 menit.";
            break;
        }
        $fields["iqomah_{$key}_minutes"] = $value;
    }
    if ($error === null) {
        $settingsRepo->update($fields);
        $_SESSION['flash_success'] = 'Suara adzan ' . ($adzan ? 'menyala' : 'mati') . ', suara iqomah '
            . ($iqomah ? 'menyala (Subuh ' . $fields['iqomah_fajr_minutes'] . ' menit setelah adzan)' : 'mati') . '.';
        header('Location: /admin/settings.php');
        exit;
    }
}

$saved = $settingsRepo->get();
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$minutes = [];
foreach ($prayers as $key => $name) {
    $minutes[$key] = (int) ($_POST["iqomah_{$key}_minutes"] ?? $saved["iqomah_{$key}_minutes"] ?? $defaults[$key]);
}
$adzan = $isPost ? ($_POST['adzan_sound_enabled'] ?? '0') === '1' : (int) $saved['adzan_sound_enabled'] === 1;
$iqomah = $isPost ? ($_POST['iqomah_sound_enabled'] ?? '0') === '1' : (int) $saved['iqomah_sound_enabled'] === 1;

$pageTitle = 'Suara & jeda iqomah';
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
            <?php foreach ($prayers as $key => $name): ?>
            <div class="row" style="grid-template-columns:minmax(0,1fr) auto">
                <label class="k" for="iqomah_<?= $key ?>_minutes"><?= $name ?><small><?= $key === 'dhuhr' ? 'Menit setelah adzan · Jumat: setelah khutbah' : 'Menit setelah adzan · bawaan ' . $defaults[$key] ?></small></label>
                <span class="stepper"><button type="button" data-step="-1" aria-label="Kurangi">−</button><input type="number" id="iqomah_<?= $key ?>_minutes" name="iqomah_<?= $key ?>_minutes" min="5" max="60" inputmode="numeric" value="<?= $minutes[$key] ?>"><button type="button" data-step="1" aria-label="Tambah">+</button></span>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="note">Matikan bila adzan atau iqomah dikumandangkan langsung oleh muadzin. Layar jamaah tetap menampilkan waktu adzan, dan murottal tetap berhenti sampai sholat selesai. Hari Jumat, iqomah Dzuhur tidak dibunyikan otomatis karena dikumandangkan setelah khutbah.</div>
    <div class="foot"><button type="submit" class="btn">Simpan</button></div>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
