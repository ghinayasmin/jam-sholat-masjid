<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Repository\BackupRepository;
use App\Repository\SettingsRepository;
use App\View\Format;
use App\View\Icon;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$settingsRepo = new SettingsRepository();

// Sakelar langsung di halaman ini: saldo kas (DECISIONS.md #14) dan hitung mundur
// iqomah (#31) di layar jamaah.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    if (($_POST['action'] ?? '') === 'iqomah_countdown_toggle') {
        $on = isset($_POST['iqomah_countdown_enabled']);
        $settingsRepo->update(['iqomah_countdown_enabled' => $on ? 1 : 0]);
        $_SESSION['flash_success'] = $on
            ? 'Hitung mundur iqomah tampil di layar jamaah setelah adzan.'
            : 'Hitung mundur iqomah tidak lagi tampil di layar jamaah.';
    }
    if (($_POST['action'] ?? '') === 'finance_toggle') {
        $on = isset($_POST['show_finance_on_display']);
        $settingsRepo->update(['show_finance_on_display' => $on ? 1 : 0]);
        $_SESSION['flash_success'] = $on
            ? 'Saldo kas sekarang tampil di layar jamaah.'
            : 'Saldo kas tidak lagi tampil di layar jamaah.';
    }
    header('Location: /admin/settings.php');
    exit;
}

$s = $settingsRepo->get();
$me = (new \App\Repository\AdminUserRepository())->findById((int) ($_SESSION['admin_id'] ?? 0));
$hasRecovery = !empty($me['recovery_created_at']);
$adjustParts = [];
foreach (\App\Schedule\ScheduleEngine::PRAYER_NAMES as $key => $name) {
    $m = (int) ($s["adjust_{$key}_minutes"] ?? 0);
    if ($m !== 0) {
        $adjustParts[] = $name . ' ' . ($m > 0 ? '+' : '−') . abs($m);
    }
}
$allShift = (int) $s['location_offset_minutes'];
if ($allShift !== 0) {
    array_unshift($adjustParts, 'Semua ' . ($allShift > 0 ? '+' : '−') . abs($allShift));
}
$adjustSummary = $adjustParts === [] ? 'Tidak ada' : implode(', ', $adjustParts);
$backups = (new BackupRepository())->list();
$lastBackup = $backups !== [] ? Format::shortDate(substr($backups[0]['created_at'], 0, 10)) : 'Belum pernah';
$chev = Icon::svg('chevron', 'icon chev');
$hijriToday = (new \App\Schedule\ScheduleEngine())->hijriFor(\App\LocalTime::today())['label'];

$pageTitle = 'Pengaturan';
$pageSubtitle = 'Biasanya cukup diatur sekali';
$activeTab = 'settings';
require __DIR__ . '/_header.php';
?>

<section class="group" aria-labelledby="g-masjid">
    <div class="label" id="g-masjid">Masjid</div>
    <div class="card">
        <a class="row" href="/admin/mosque_profile.php"><span></span><span class="k">Profil &amp; foto masjid<small><?= Format::e($s['mosque_name']) ?><?= $s['mosque_photo'] !== '' ? ' · ada foto' : ' · belum ada foto' ?></small></span><?= $chev ?></a>
    </div>
</section>

<section class="group" aria-labelledby="g-waktu">
    <div class="label" id="g-waktu">Waktu sholat</div>
    <div class="card">
        <a class="row" href="/admin/settings_location.php"><span></span><span class="k">Lokasi</span><span class="v"><?= Format::e($s['location_name'] !== '' ? $s['location_name'] : 'Atur lokasi') ?> <?= $chev ?></span></a>
        <a class="row" href="/admin/schedule_adjust.php"><span></span><span class="k">Koreksi jadwal</span><span class="v"><?= Format::e($adjustSummary) ?> <?= $chev ?></span></a>
        <a class="row" href="/admin/hijri.php"><span></span><span class="k">Tanggal Hijriah</span><span class="v"><?= Format::e($hijriToday) ?> <?= $chev ?></span></a>
    </div>
</section>

<section class="group" aria-labelledby="g-adzan">
    <div class="label" id="g-adzan">Adzan &amp; iqomah</div>
    <div class="card">
        <a class="row" href="/admin/sound.php"><span></span><span class="k">Suara &amp; jeda iqomah<small>Adzan <?= (int) $s['adzan_sound_enabled'] === 1 ? 'nyala' : 'mati' ?> · iqomah <?= (int) $s['iqomah_sound_enabled'] === 1 ? 'nyala' : 'mati' ?></small></span><span class="v">Subuh <?= (int) $s['iqomah_fajr_minutes'] ?> mnt <?= $chev ?></span></a>
        <a class="row" href="/admin/reminder.php"><span></span><span class="k">Pengingat sebelum adzan</span><span class="v"><?= (int) $s['reminder_enabled'] === 1 ? (int) $s['reminder_minutes'] . ' menit' : 'Mati' ?> <?= $chev ?></span></a>
        <form method="post" class="row">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="iqomah_countdown_toggle">
            <span></span>
            <label class="k" for="iqomah_countdown_enabled">Hitung mundur iqomah<small>Layar penuh setelah adzan sampai iqomah</small></label>
            <span class="switch"><input type="checkbox" id="iqomah_countdown_enabled" name="iqomah_countdown_enabled" data-autosubmit<?= (int) $s['iqomah_countdown_enabled'] === 1 ? ' checked' : '' ?>><span></span></span>
            <noscript><button type="submit" class="btn small">Simpan</button></noscript>
        </form>
    </div>
</section>

<section class="group" aria-labelledby="g-layar">
    <div class="label" id="g-layar">Layar jamaah</div>
    <div class="card">
        <form method="post" class="row">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="finance_toggle">
            <span></span>
            <label class="k" for="show_finance_on_display">Tampilkan saldo kas<small>Sebagai slide, bergantian dengan poster</small></label>
            <span class="switch"><input type="checkbox" id="show_finance_on_display" name="show_finance_on_display" data-autosubmit<?= (int) $s['show_finance_on_display'] === 1 ? ' checked' : '' ?>><span></span></span>
            <noscript><button type="submit" class="btn small">Simpan</button></noscript>
        </form>
        <a class="row" href="/admin/slides.php"><span></span><span class="k">Pergantian slide<small>Poster, saldo kas, foto masjid</small></span><span class="v">Tampil <?= (int) $s['poster_show_seconds'] ?> dtk · jeda <?= (int) $s['poster_gap_seconds'] ?> dtk <?= $chev ?></span></a>
    </div>
</section>

<section class="group" aria-labelledby="g-sistem">
    <div class="label" id="g-sistem">Sistem</div>
    <div class="card">
        <a class="row" href="/admin/account.php"><span></span><span class="k">Akun takmir<?= $hasRecovery ? '' : '<small>Belum ada kode pemulihan</small>' ?></span><span class="v">Kata sandi <?= $chev ?></span></a>
        <a class="row" href="/admin/settings_time.php"><span></span><span class="k">Jam &amp; tanggal</span><span class="v">Koreksi manual <?= $chev ?></span></a>
        <a class="row" href="/admin/backup.php"><span></span><span class="k">Cadangan data</span><span class="v"><?= Format::e($lastBackup) ?> <?= $chev ?></span></a>
        <a class="row" href="/admin/about.php"><span></span><span class="k">Tentang aplikasi</span><span class="v">Tim &amp; versi <?= $chev ?></span></a>
    </div>
</section>

<form method="post" action="/admin/logout.php">
    <?= Csrf::field() ?>
    <button type="submit" class="btn danger">Keluar dari panel</button>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
