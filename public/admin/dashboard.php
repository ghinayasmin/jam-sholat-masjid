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
$names = array_merge(ScheduleEngine::PRAYER_NAMES, $isFriday ? ['dhuhr' => 'Jumat'] : []);
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
// Sisa detik sampai waktu sholat berikutnya: admin.js menghitung mundur dari sini supaya
// tab yang dibiarkan terbuka tidak menampilkan angka lama (temuan uji v1.1.1).
$nextInSeconds = $next['in'] * 60 - (int) gmdate('s', LocalTime::now());
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
$murottalStatus = \App\Murottal::status($settings, $schedule, $nowMinutes, $isFriday);

// Kondisi sistem (DECISIONS.md #36): jam perangkat dan kode pemulihan akun ini.
$clockOk = \App\SystemClock::isTrusted();
$me = (new \App\Repository\AdminUserRepository())->findById((int) ($_SESSION['admin_id'] ?? 0));
$hasRecovery = !empty($me['recovery_created_at']);
// Layanan adzan (DECISIONS.md #40): tanda hidup ditulis tiap menit; adzan Subuh yang
// panjang menahan loop beberapa menit, jadi batasnya 10 menit.
$beat = (int) $settings['alarm_heartbeat'];
$beatAge = time() - $beat;
$audioStatus = (string) $settings['alarm_audio_status'];
// Hasil cek volume dari volume_guard.py diterjemahkan ke kalimat untuk takmir.
$volumeNote = '';
if (preg_match('/^normal \((\d+)%\)/', $audioStatus, $m)) {
    $volumeNote = ' Volume ' . $m[1] . '%.';
} elseif (str_contains($audioStatus, 'TV')) {
    $volumeNote = ' Volume diatur melalui TV.';
} elseif (str_contains($audioStatus, 'dibunyikan lagi') && str_contains($audioStatus, 'dinaikkan')) {
    $volumeNote = ' Suara yang sebelumnya dimatikan telah diaktifkan kembali dan volumenya telah dinaikkan.';
} elseif (str_contains($audioStatus, 'dinaikkan')) {
    $volumeNote = ' Volume yang terlalu kecil telah dinaikkan.';
} elseif (str_contains($audioStatus, 'dibunyikan lagi')) {
    $volumeNote = ' Suara yang sebelumnya dimatikan telah diaktifkan kembali.';
} elseif (str_starts_with($audioStatus, 'tidak bisa dicek')) {
    $volumeNote = ' Volume tidak dapat diperiksa.';
}
if ($beat === 0) {
    $alarmRow = ['warn', 'Belum berjalan', 'Pada komputer Windows atau Linux, layanan ini berjalan setelah komputer masuk ke akun pengguna.', 'Cek'];
} elseif ($beatAge > 600) {
    $alarmRow = ['warn', 'Tidak berjalan sejak ' . gmdate('H.i', $beat + LocalTime::offsetSeconds()),
        'Adzan tidak akan terdengar. Pastikan komputer dalam keadaan menyala dan sudah masuk ke akun pengguna.', 'Cek'];
} else {
    $alarmRow = ['', 'Berjalan', 'Adzan dan iqomah siap diputar.' . $volumeNote, 'Aktif'];
}
$shifts = (int) $settings['location_offset_minutes'] !== 0;
foreach (ScheduleEngine::ADJUSTABLE as $key) {
    $shifts = $shifts || (int) $settings["adjust_{$key}_minutes"] !== 0;
}

$pageTitle = "Assalamu'alaikum";
$pageSubtitle = $settings['mosque_name'] . ' · ' . Format::longDate($today);
$activeTab = 'home';
require __DIR__ . '/_header.php';
?>

<?php if ((int) $settings['location_confirmed'] !== 1): ?>
    <a class="setup-card" href="/admin/settings_location.php">
        <b>Lokasi masjid belum diatur</b>
        <span>Jadwal sholat dan adzan masih menggunakan lokasi bawaan (Yogyakarta). Silakan atur lokasi masjid agar waktu sholat sesuai.</span>
        <span class="go">Atur lokasi</span>
    </a>
<?php endif; ?>

<div class="next-card">
    <div><div class="s">Berikutnya<?= $next['tomorrow'] ? ' (besok)' : '' ?></div><div class="w"><?= Format::e($next['name'] . ' ' . $next['time']) ?></div></div>
    <div class="c" data-countdown="<?= $nextInSeconds ?>"><?= Format::e($inText) ?></div>
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
    <?php if ($murottalReady):
        $mp = \App\Murottal::player($settings);
        $mpOn = $murottalOn;
        $mpStatus = $murottalStatus;
        $mpVariant = 'compact';
        $mpBack = 'home';
        require __DIR__ . '/_murottal_player.php';
    else: ?>
        <div class="card murottal-card"><span class="k">Murottal<small data-murottal-status><?= Format::e($murottalStatus) ?></small></span></div>
    <?php endif; ?>
    <?php if ($murottalReady): ?>
        <div class="card" style="margin-top:8px"><a class="row" href="/admin/murottal.php"><span></span><span class="k">Pilih surat</span><span class="v"><?= Icon::svg('chevron', 'icon chev') ?></span></a></div>
    <?php endif; ?>
</section>

<section class="group" aria-labelledby="tasks-label">
    <div class="label" id="tasks-label">Yang sering dilakukan</div>
    <div class="tiles">
        <a class="tile" href="/admin/publications_add.php"><?= Icon::svg('announce') ?><div><b>Pasang pengumuman</b><small><?= $showingCount ?> sedang tampil</small></div></a>
        <a class="tile" href="/admin/financial_add.php?type=masuk"><?= Icon::svg('cash') ?><div><b>Catat kas</b><small>Saldo <?= Format::e(Format::rupiahShort($balance)) ?></small></div></a>
        <a class="tile" href="/admin/schedule_adjust.php"><?= Icon::svg('bell') ?><div><b>Koreksi jadwal</b><small><?= $shifts ? 'Ada koreksi menit' : 'Sesuai hitungan' ?></small></div></a>
        <a class="tile" href="/admin/camera.php?date=<?= $today ?>"><?= Icon::svg('camera') ?><div><b>Rekaman kamera</b><small><?= $motionToday ?> gerakan hari ini</small></div></a>
    </div>
</section>

<section class="group" aria-labelledby="sys-label">
    <div class="label" id="sys-label">Kondisi sistem</div>
    <div class="card">
        <a class="row" href="/display.php" target="_blank" rel="noopener"><span class="dot"></span><span class="k">Layar jamaah<small>Ketuk untuk melihat tampilannya</small></span><span class="v ok">Buka</span></a>
        <?php if ($alarmRow[0] !== ''): // bermasalah: menaut ke Panduan "Adzan tidak berbunyi" ?>
            <a class="row wrap" href="/admin/help.php#adzan-tidak-bunyi"><span class="dot warn"></span><span class="k">Layanan adzan · <?= Format::e($alarmRow[1]) ?><small><?= Format::e($alarmRow[2]) ?></small></span><span class="v"><?= $alarmRow[3] ?></span></a>
        <?php else: ?>
            <div class="row wrap"><span class="dot"></span><span class="k">Layanan adzan · <?= Format::e($alarmRow[1]) ?><small><?= Format::e($alarmRow[2]) ?></small></span><span class="v ok"><?= $alarmRow[3] ?></span></div>
        <?php endif; ?>
        <div class="row"><span class="dot"></span><span class="k">Jadwal sholat<small>Dihitung di perangkat, kriteria Kemenag<?= $shifts ? ', dengan koreksi menit' : '' ?></small></span><span class="v ok">Normal</span></div>
        <a class="row" href="/admin/settings_time.php"><span class="dot<?= $clockOk ? '' : ' warn' ?>"></span><span class="k">Jam perangkat<small><?= $clockOk ? 'Sudah dicocokkan, sesuai jam sebenarnya' : 'Sedang dicocokkan dengan jam HP ini' ?></small></span><span class="v<?= $clockOk ? ' ok' : '' ?>"><?= $clockOk ? 'Tepat' : 'Cek' ?></span></a>
        <?php if (!$hasRecovery): ?>
            <a class="row" href="/admin/account.php#kode-pemulihan"><span class="dot warn"></span><span class="k">Kode pemulihan<small>Belum dibuat. Perlu bila lupa kata sandi</small></span><span class="v">Buat</span></a>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
