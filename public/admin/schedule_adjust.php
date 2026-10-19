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
$prayers = ['fajr' => 'Subuh', 'dhuhr' => 'Dzuhur', 'asr' => 'Ashar', 'maghrib' => 'Maghrib', 'isha' => 'Isya'];
$error = null;

// Koreksi menit per waktu sholat (DECISIONS.md #35): mis. masjid ingin Dzuhur selalu
// 3 menit lebih lambat dari hitungan. Berlaku setiap hari, jadwal tetap ikut bergeser.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $fields = [];
    foreach ($prayers as $key => $name) {
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
$base = $engine->calculatedFor($today);
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
    <p class="hint muted" style="margin:0;padding-left:4px;font-size:14px">
        Geser waktu adzan tiap sholat bila masjid memakai jadwal yang sedikit berbeda dari hitungan.
        Koreksi berlaku setiap hari. Contoh jam di bawah untuk hari ini, <?= Format::e(Format::longDate($today)) ?>.
    </p>

    <div class="card">
        <?php foreach ($prayers as $key => $name): ?>
        <div class="row adjust-row" style="grid-template-columns:minmax(0,1fr) auto" data-base="<?= Format::e($base[$key]) ?>">
            <label class="k" for="adjust_<?= $key ?>_minutes"><?= $name ?>
                <small>Hitungan <?= Format::e($base[$key]) ?> → tampil <b data-result><?= Format::e(ScheduleEngine::shiftTime($base[$key], $adjust[$key])) ?></b></small>
            </label>
            <span class="stepper"><button type="button" data-step="-1" aria-label="Kurangi 1 menit">−</button><input type="number" id="adjust_<?= $key ?>_minutes" name="adjust_<?= $key ?>_minutes" min="-30" max="30" inputmode="numeric" value="<?= $adjust[$key] ?>"><button type="button" data-step="1" aria-label="Tambah 1 menit">+</button></span>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="note">Angka plus = adzan lebih lambat, minus = lebih cepat. Jeda iqomah, pengingat, dan hitung mundur ikut menyesuaikan. Koreksi yang sama untuk semua sholat ada di Pengaturan → Lokasi.</div>
    <div class="foot"><button type="submit" class="btn">Simpan</button></div>
</form>

<script>
// Jam "tampil" berubah langsung saat angka koreksi diubah.
document.querySelectorAll('.adjust-row').forEach((row) => {
    const input = row.querySelector('input');
    const out = row.querySelector('[data-result]');
    const [h, m] = row.dataset.base.split(':').map(Number);
    const update = () => {
        const t = Math.min(23 * 60 + 59, Math.max(0, h * 60 + m + (parseInt(input.value, 10) || 0)));
        out.textContent = String(Math.floor(t / 60)).padStart(2, '0') + ':' + String(t % 60).padStart(2, '0');
    };
    input.addEventListener('input', update);
});
</script>

<?php require __DIR__ . '/_footer.php'; ?>
