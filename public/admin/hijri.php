<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\LocalTime;
use App\Repository\SettingsRepository;
use App\Schedule\HijriConverter;
use App\Schedule\ScheduleEngine;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$settingsRepo = new SettingsRepository();
$engine = new ScheduleEngine();
$today = LocalTime::today();

// Tanggal Hijriah hasil hitungan kadang selisih sehari dari keputusan isbat
// Kemenag (awal Ramadhan, Syawal, Dzulhijjah). Takmir menggeser ±1 hari di sini.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $offset = max(-1, min(1, (int) ($_POST['hijri_offset_days'] ?? 0)));
    $settingsRepo->update(['hijri_offset_days' => $offset]);
    $engine->invalidateCache(); // label Hijriah tersimpan bersama jadwal
    $_SESSION['flash_success'] = 'Tanggal Hijriah hari ini menjadi ' . $engine->hijriFor($today)['label'] . '.';
    header('Location: /admin/settings.php');
    exit;
}

$current = (int) $settingsRepo->get()['hijri_offset_days'];
[$y, $m, $d] = array_map('intval', explode('-', $today));
$hijri = new HijriConverter();
$labels = [];
foreach ([-1, 0, 1] as $offset) {
    [$yy, $mm, $dd] = array_map('intval', explode('-', gmdate('Y-m-d', gmmktime(0, 0, 0, $m, $d + $offset, $y))));
    $labels[$offset] = $hijri->fromGregorian($yy, $mm, $dd)['label'];
}

$pageTitle = 'Tanggal Hijriah';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<form method="post" class="stack">
    <?= Csrf::field() ?>
    <div class="label">Hari ini (<?= Format::e(Format::longDate($today)) ?>) adalah</div>
    <div class="card">
        <?php foreach ([-1 => 'Mundur 1 hari', 0 => 'Sesuai hitungan', 1 => 'Maju 1 hari'] as $offset => $name): ?>
            <label class="row">
                <input type="radio" name="hijri_offset_days" value="<?= $offset ?>"<?= $offset === $current ? ' checked' : '' ?>>
                <span class="k"><?= Format::e($labels[$offset]) ?><small><?= $name ?></small></span>
                <span></span>
            </label>
        <?php endforeach; ?>
    </div>
    <div class="note">Pilih yang sama dengan pengumuman sidang isbat Kemenag, biasanya hanya perlu diubah di awal Ramadhan, Syawal, atau Dzulhijjah. Di layar jamaah, tanggal Hijriah berganti saat Maghrib.</div>
    <div class="foot"><button type="submit" class="btn">Simpan</button></div>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
