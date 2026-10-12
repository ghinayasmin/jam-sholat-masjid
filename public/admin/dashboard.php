<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\LocalTime;
use App\Repository\FinancialRepository;
use App\Repository\MotionEventRepository;
use App\Repository\PublicationRepository;
use App\Repository\SettingsRepository;
use App\Schedule\ScheduleEngine;
use App\View\Format;
use App\View\Icon;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$settings = (new SettingsRepository())->get();
$today = LocalTime::today();
$engine = new ScheduleEngine();
$schedule = $engine->getScheduleForDate($today);
$balance = (new FinancialRepository())->currentBalance();
$showingCount = count((new PublicationRepository())->showingOn($today));
$motionToday = count((new MotionEventRepository())->list($today, 500));

// Waktu sholat berikutnya (hanya waktu adzan; Terbit bukan waktu sholat).
$nowMinutes = (int) gmdate('G', LocalTime::now()) * 60 + (int) gmdate('i', LocalTime::now());
$toMinutes = static fn (string $hhmm): int => (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
$isFriday = gmdate('w', LocalTime::now()) === '5';
$names = ['fajr' => 'Subuh', 'dhuhr' => $isFriday ? 'Jumat' : 'Dzuhur', 'asr' => 'Ashar', 'maghrib' => 'Maghrib', 'isha' => 'Isya'];
$next = null;
foreach ($names as $key => $name) {
    if ($toMinutes($schedule[$key]) > $nowMinutes) {
        $next = ['name' => $name, 'time' => $schedule[$key], 'in' => $toMinutes($schedule[$key]) - $nowMinutes, 'tomorrow' => false];
        break;
    }
}
if ($next === null) {
    $tomorrow = $engine->getScheduleForDate(gmdate('Y-m-d', LocalTime::now() + 86400));
    $next = ['name' => 'Subuh', 'time' => $tomorrow['fajr'], 'in' => 1440 - $nowMinutes + $toMinutes($tomorrow['fajr']), 'tomorrow' => true];
}
$inText = $next['in'] >= 60
    ? intdiv($next['in'], 60) . ' jam' . ($next['in'] % 60 ? ' ' . $next['in'] % 60 . ' menit' : '') . ' lagi'
    : $next['in'] . ' menit lagi';
$reminderAt = null;
if ((int) $settings['reminder_enabled'] === 1) {
    $lead = ($next['name'] === 'Jumat') ? (int) $settings['reminder_friday_minutes'] : (int) $settings['reminder_minutes'];
    $at = $toMinutes($next['time']) - $lead;
    $reminderAt = sprintf('%02d:%02d', intdiv(($at + 1440) % 1440, 60), ($at + 1440) % 60);
}

$rows = [
    ['fajr', 'Subuh'], ['sunrise', 'Terbit'], ['dhuhr', $isFriday ? 'Jumat' : 'Dzuhur'],
    ['asr', 'Ashar'], ['maghrib', 'Maghrib'], ['isha', 'Isya'],
];

$murottalOn = (int) $settings['murottal_enabled'] === 1;
$murottalReady = \App\Murottal::tracks() !== [];
$murottalStatus = \App\Murottal::status($settings, $schedule, $nowMinutes);

$syncOk = $settings['last_sync_status'] === null || stripos((string) $settings['last_sync_status'], 'gagal') === false;

$pageTitle = "Assalamu'alaikum";
$pageSubtitle = $settings['mosque_name'] . ' · ' . Format::longDate($today);
$activeTab = 'home';
require __DIR__ . '/_header.php';
?>

<div class="next-card">
    <div><div class="s">Berikutnya<?= $next['tomorrow'] ? ' (besok)' : '' ?></div><div class="w"><?= Format::e($next['name'] . ' ' . $next['time']) ?></div></div>
    <div class="c"><?= Format::e($inText) ?></div>
    <?php if ($reminderAt !== null): ?>
        <div class="r">Pengingat tampil di layar jamaah pukul <?= $reminderAt ?></div>
    <?php else: ?>
        <div class="r">Pengingat sebelum adzan sedang dimatikan</div>
    <?php endif; ?>
</div>

<section class="group" aria-labelledby="sched-label">
    <div class="label" id="sched-label">Jadwal hari ini</div>
    <div class="sched">
        <?php foreach ($rows as [$key, $label]):
            $cls = '';
            if (!$next['tomorrow'] && $label === $next['name']) {
                $cls = 'nx';
            } elseif ($next['tomorrow'] || $toMinutes($schedule[$key]) <= $nowMinutes) {
                $cls = 'past';
            } ?>
            <div class="<?= $cls ?>"><span><?= $label ?></span><b><?= Format::e($schedule[$key]) ?></b></div>
        <?php endforeach; ?>
    </div>
</section>

<section class="group" aria-labelledby="murottal-label">
    <div class="label" id="murottal-label">Murottal</div>
    <div class="card murottal-card"<?= $murottalOn ? ' data-murottal-live' : '' ?>>
        <span class="k"><?= $murottalOn ? 'Menyala' : 'Mati' ?><small data-murottal-status><?= Format::e($murottalStatus) ?></small></span>
        <?php if ($murottalReady): ?>
            <form method="post" action="/admin/murottal.php"><?= Csrf::field() ?>
                <input type="hidden" name="action" value="<?= $murottalOn ? 'pause' : 'play' ?>">
                <button type="submit" class="btn small<?= $murottalOn ? ' ghost' : '' ?>"><?= $murottalOn ? 'Jeda' : 'Putar' ?></button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($murottalReady): ?>
        <div class="card" style="margin-top:8px"><a class="row" href="/admin/murottal.php"><span></span><span class="k">Pilih surat</span><span class="v"><?= Icon::svg('chevron', 'icon chev') ?></span></a></div>
    <?php endif; ?>
</section>

<section class="group" aria-labelledby="tasks-label">
    <div class="label" id="tasks-label">Yang sering dilakukan</div>
    <div class="tiles">
        <a class="tile" href="/admin/publications_add.php"><?= Icon::svg('announce') ?><div><b>Pasang pengumuman</b><small><?= $showingCount ?> sedang tampil</small></div></a>
        <a class="tile" href="/admin/financial_add.php?type=masuk"><?= Icon::svg('cash') ?><div><b>Catat kas</b><small>Saldo <?= Format::e(Format::rupiahShort($balance)) ?></small></div></a>
        <a class="tile" href="/admin/reminder.php"><?= Icon::svg('bell') ?><div><b>Pengingat adzan</b><small><?= (int) $settings['reminder_enabled'] === 1 ? (int) $settings['reminder_minutes'] . ' menit sebelum' : 'Dimatikan' ?></small></div></a>
        <a class="tile" href="/admin/camera.php?date=<?= $today ?>"><?= Icon::svg('camera') ?><div><b>Rekaman kamera</b><small><?= $motionToday ?> gerakan hari ini</small></div></a>
    </div>
</section>

<section class="group" aria-labelledby="sys-label">
    <div class="label" id="sys-label">Kondisi sistem</div>
    <div class="card">
        <a class="row" href="/display.php" target="_blank" rel="noopener"><span class="dot"></span><span class="k">Layar jamaah<small>Ketuk untuk melihat tampilannya</small></span><span class="v ok">Buka</span></a>
        <div class="row"><span class="dot<?= $syncOk ? '' : ' warn' ?>"></span><span class="k">Jadwal sholat<small><?= $schedule['source'] === 'online' ? 'Dicocokkan dengan jadwal online' : 'Dihitung di perangkat (offline)' ?></small></span><span class="v<?= $syncOk ? ' ok' : '' ?>"><?= $syncOk ? 'Normal' : 'Cek' ?></span></div>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
