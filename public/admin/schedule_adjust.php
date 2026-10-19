<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\LocalTime;
use App\Repository\SettingsRepository;
use App\Schedule\ScheduleEngine;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$settingsRepo = new SettingsRepository();
$engine = new ScheduleEngine();
$prayers = ScheduleEngine::PRAYER_NAMES;
$error = null;

// Koreksi menit per waktu sholat (DECISIONS.md #35): mis. masjid ingin Dzuhur selalu
// 3 menit lebih lambat dari hitungan. Berlaku setiap hari, jadwal tetap ikut bergeser.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $fields = [];
    $all = filter_input(INPUT_POST, 'location_offset_minutes', FILTER_VALIDATE_INT);
    if ($all === false || $all === null || $all < -30 || $all > 30) {
        $error = 'Koreksi semua sholat harus antara -30 dan +30 menit.';
    } else {
        $fields['location_offset_minutes'] = $all;
    }
    foreach ($error === null ? $prayers : [] as $key => $name) {
        $value = filter_input(INPUT_POST, "adjust_{$key}_minutes", FILTER_VALIDATE_INT);
        if ($value === false || $value === null || $value < -30 || $value > 30) {
            $error = "Koreksi $name harus antara -30 dan +30 menit.";
            break;
        }
        $fields["adjust_{$key}_minutes"] = $value;
    }
    if ($error === null) {
        $settingsRepo->update($fields);
        // Jadwal tersimpan dihitung ulang; layar & alarm memakai jadwal baru dalam 1 menit.
        $engine->invalidateCache();
        $changed = array_filter($fields, static fn (int $v): bool => $v !== 0);
        $_SESSION['flash_success'] = $changed === []
            ? 'Jadwal sholat kembali sesuai hitungan, tanpa koreksi.'
            : 'Koreksi jadwal tersimpan. Layar jamaah dan adzan memakai jadwal baru dalam 1 menit.';
        header('Location: /admin/settings.php');
        exit;
    }
}

$s = $settingsRepo->get();
$today = LocalTime::today();
$base = $engine->calculatedFor($today, false);
$all = (int) ($_POST['location_offset_minutes'] ?? $s['location_offset_minutes'] ?? 0);
$adjust = [];
foreach ($prayers as $key => $name) {
    $adjust[$key] = (int) ($_POST["adjust_{$key}_minutes"] ?? $s["adjust_{$key}_minutes"] ?? 0);
}

$pageTitle = 'Koreksi jadwal';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<?php if ($error !== null): ?>
    <div class="flash flash-error" role="alert"><?= Format::e($error) ?></div>
<?php endif; ?>

<form method="post" class="stack">
    <?= Csrf::field() ?>
    <p class="aside">
        Geser waktu adzan tiap sholat bila masjid memakai jadwal yang sedikit berbeda dari hitungan.
        Koreksi berlaku setiap hari. Contoh jam di bawah untuk hari ini, <?= Format::e(Format::longDate($today)) ?>.
    </p>

    <div class="card">
        <div class="row ctl">
            <label class="k" for="location_offset_minutes">Semua sholat<small>Geser kelima waktu sekaligus</small></label>
            <span class="stepper"><button type="button" data-step="-1" aria-label="Kurangi 1 menit">−</button><input type="number" id="location_offset_minutes" name="location_offset_minutes" min="-30" max="30" inputmode="numeric" value="<?= $all ?>" data-all><button type="button" data-step="1" aria-label="Tambah 1 menit">+</button></span>
        </div>
    </div>

    <div class="card">
        <?php foreach ($prayers as $key => $name): ?>
        <div class="row ctl adjust-row" data-base="<?= Format::e($base[$key]) ?>">
            <label class="k" for="adjust_<?= $key ?>_minutes"><?= $name ?>
                <small>Hitungan <?= Format::e($base[$key]) ?> → tampil <b data-result><?= Format::e(ScheduleEngine::shiftTime($base[$key], $all + $adjust[$key])) ?></b></small>
            </label>
            <span class="stepper"><button type="button" data-step="-1" aria-label="Kurangi 1 menit">−</button><input type="number" id="adjust_<?= $key ?>_minutes" name="adjust_<?= $key ?>_minutes" min="-30" max="30" inputmode="numeric" value="<?= $adjust[$key] ?>"><button type="button" data-step="1" aria-label="Tambah 1 menit">+</button></span>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="note">Angka plus = adzan lebih lambat, minus = lebih cepat. Koreksi semua sholat dan koreksi per sholat dijumlahkan. Jeda iqomah, pengingat, dan hitung mundur ikut menyesuaikan.</div>
    <div class="foot"><button type="submit" class="btn">Simpan</button></div>
</form>

<script>
// Jam "tampil" berubah langsung saat angka koreksi (semua sholat + per sholat) diubah.
const allInput = document.querySelector('[data-all]');
const rows = [...document.querySelectorAll('.adjust-row')].map((row) => {
    const input = row.querySelector('input');
    const out = row.querySelector('[data-result]');
    const [h, m] = row.dataset.base.split(':').map(Number);
    const update = () => {
        const shift = (parseInt(allInput.value, 10) || 0) + (parseInt(input.value, 10) || 0);
        const t = Math.min(23 * 60 + 59, Math.max(0, h * 60 + m + shift));
        out.textContent = String(Math.floor(t / 60)).padStart(2, '0') + ':' + String(t % 60).padStart(2, '0');
    };
    input.addEventListener('input', update);
    return update;
});
allInput.addEventListener('input', () => rows.forEach((update) => update()));
</script>

<?php require __DIR__ . '/_footer.php'; ?>
