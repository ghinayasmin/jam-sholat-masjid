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

// Sakelar langsung di halaman ini: tampilkan saldo kas di layar jamaah (DECISIONS.md #14).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
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
$backups = (new BackupRepository())->list();
$lastBackup = $backups !== [] ? Format::shortDate(substr($backups[0]['created_at'], 0, 10)) : 'Belum pernah';
$chev = Icon::svg('chevron', 'icon chev');

$pageTitle = 'Pengaturan';
$pageSubtitle = 'Biasanya cukup diatur sekali';
$activeTab = 'settings';
require __DIR__ . '/_header.php';
?>

<section class="group" aria-labelledby="g-masjid">
    <div class="label" id="g-masjid">Masjid</div>
    <div class="card">
        <a class="row" href="/admin/mosque_profile.php"><span></span><span class="k">Profil masjid<small><?= Format::e($s['mosque_name']) ?></small></span><?= $chev ?></a>
    </div>
</section>

<section class="group" aria-labelledby="g-waktu">
    <div class="label" id="g-waktu">Waktu sholat</div>
    <div class="card">
        <a class="row" href="/admin/settings_location.php"><span></span><span class="k">Lokasi</span><span class="v"><?= Format::e($s['location_name'] !== '' ? $s['location_name'] : 'Atur lokasi') ?> <?= $chev ?></span></a>
        <a class="row" href="/admin/iqomah.php"><span></span><span class="k">Suara iqomah</span><span class="v"><?= (int) $s['iqomah_sound_enabled'] === 1 ? (int) $s['azan_iqomah_interval_minutes'] . ' menit setelah adzan' : 'Mati' ?> <?= $chev ?></span></a>
        <a class="row" href="/admin/reminder.php"><span></span><span class="k">Pengingat adzan</span><span class="v"><?= (int) $s['reminder_enabled'] === 1 ? (int) $s['reminder_minutes'] . ' menit' : 'Mati' ?> <?= $chev ?></span></a>
    </div>
</section>

<section class="group" aria-labelledby="g-layar">
    <div class="label" id="g-layar">Layar jamaah</div>
    <div class="card">
        <form method="post" class="row">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="finance_toggle">
            <span></span>
            <label class="k" for="show_finance_on_display">Tampilkan saldo kas<small>Bawaan: tidak ditampilkan</small></label>
            <span class="switch"><input type="checkbox" id="show_finance_on_display" name="show_finance_on_display" data-autosubmit<?= (int) $s['show_finance_on_display'] === 1 ? ' checked' : '' ?>><span></span></span>
            <noscript><button type="submit" class="btn small">Simpan</button></noscript>
        </form>
    </div>
</section>

<section class="group" aria-labelledby="g-sistem">
    <div class="label" id="g-sistem">Sistem</div>
    <div class="card">
        <a class="row" href="/admin/account.php"><span></span><span class="k">Akun takmir</span><span class="v">Ganti kata sandi <?= $chev ?></span></a>
        <a class="row" href="/admin/settings_time.php"><span></span><span class="k">Jam &amp; tanggal</span><span class="v">Koreksi manual <?= $chev ?></span></a>
        <a class="row" href="/admin/backup.php"><span></span><span class="k">Cadangan data</span><span class="v"><?= Format::e($lastBackup) ?> <?= $chev ?></span></a>
    </div>
</section>

<form method="post" action="/admin/logout.php">
    <?= Csrf::field() ?>
    <button type="submit" class="btn danger">Keluar dari panel</button>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
