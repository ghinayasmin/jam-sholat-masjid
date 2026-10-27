<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Repository\SettingsRepository;
use App\Schedule\LocationInput;
use App\Schedule\ScheduleEngine;
use App\View\Format;

// Langkah awal setelah instalasi (DECISIONS.md #42): nama masjid, lokasi, lalu periksa suara,
// supaya takmir tidak perlu mencari sendiri di Pengaturan. Bisa dilewati; Beranda
// mengingatkan sampai selesai atau dilewati (setup_done).
$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$repo = new SettingsRepository();
$step = max(1, min(3, (int) ($_GET['langkah'] ?? 1)));
$errors = [];

$finish = static function (SettingsRepository $repo, string $message): never {
    $repo->update(['setup_done' => 1]);
    $_SESSION['flash_success'] = $message;
    header('Location: /admin/dashboard.php');
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'skip') {
        $finish($repo, 'Langkah awal dilewati. Semua pengaturan dapat diubah melalui menu Pengaturan.');
    }
    if ($action === 'done') {
        $finish($repo, 'Langkah awal selesai. Jadwal sholat sudah tampil di layar jamaah.');
    }
    if ($step === 1) {
        $name = trim((string) ($_POST['mosque_name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Nama masjid tidak boleh kosong.';
        } else {
            $repo->update([
                'mosque_name' => mb_substr($name, 0, 80),
                'mosque_address' => mb_substr(trim((string) ($_POST['mosque_address'] ?? '')), 0, 160),
            ]);
            header('Location: /admin/welcome.php?langkah=2');
            exit;
        }
    } elseif ($step === 2) {
        [$fields, $errors] = LocationInput::fromPost($_POST, withMadzhab: false);
        if ($errors === []) {
            $repo->update($fields);
            (new ScheduleEngine())->invalidateCache();
            header('Location: /admin/welcome.php?langkah=3');
            exit;
        }
    }
}

$s = $repo->get();
$v = static fn (string $key) => $_POST[$key] ?? $s[$key];
// Nama bawaan "Masjid" dikosongkan supaya contoh di kolom terlihat.
$mosqueName = (string) $v('mosque_name') === 'Masjid' ? '' : (string) $v('mosque_name');
$titles = [1 => 'Nama masjid', 2 => 'Lokasi masjid', 3 => 'Periksa suara adzan'];

$pageTitle = 'Langkah awal';
$backHref = $step === 1 ? '/admin/dashboard.php' : '/admin/welcome.php?langkah=' . ($step - 1);
$backLabel = $step === 1 ? 'Beranda' : 'Langkah sebelumnya';
require __DIR__ . '/_header.php';
?>

<div class="steps" aria-label="Langkah <?= $step ?> dari 3">
    <?php for ($i = 1; $i <= 3; $i++): ?><span class="<?= $i < $step ? 'done' : ($i === $step ? 'now' : '') ?>"></span><?php endfor; ?>
</div>
<p class="step-of">Langkah <?= $step ?> dari 3</p>
<h2 class="step-title"><?= $titles[$step] ?></h2>

<?php if ($errors !== []): ?>
    <div class="flash flash-error" role="alert"><?= Format::e(implode(' ', $errors)) ?></div>
<?php endif; ?>

<?php if ($step === 1): ?>
    <p class="step-lead">Nama masjid akan tampil di layar jamaah.</p>
    <form method="post" class="stack">
        <?= Csrf::field() ?>
        <div class="field">
            <label class="field-label" for="mosque_name">Nama masjid</label>
            <input type="text" id="mosque_name" name="mosque_name" maxlength="80" value="<?= Format::e($mosqueName) ?>" placeholder="Contoh: Masjid Al-Ikhlas" required>
        </div>
        <div class="field">
            <label class="field-label" for="mosque_address">Alamat <span class="opt">(tidak wajib)</span></label>
            <textarea id="mosque_address" name="mosque_address" rows="2" maxlength="160"><?= Format::e((string) $v('mosque_address')) ?></textarea>
        </div>
        <div class="foot"><button type="submit" class="btn">Lanjut</button></div>
    </form>

<?php elseif ($step === 2): ?>
    <p class="step-lead">Lokasi menentukan waktu sholat dan adzan.</p>
    <form method="post" class="stack">
        <?= Csrf::field() ?>
        <?php
        $loc = ['location_name' => $v('location_name'), 'latitude' => $v('latitude'), 'longitude' => $v('longitude')];
        $manualOpen = $errors !== [];
        require __DIR__ . '/_location_fields.php';
        ?>
        <div class="foot"><button type="submit" class="btn">Lanjut</button></div>
    </form>

<?php else: ?>
    <p class="step-lead">Pastikan speaker sudah tersambung dan menyala, lalu tekan <b>Putar</b>. Awal suara adzan akan diputar selama 10 detik.</p>
    <div class="card">
        <form method="post" action="/admin/sound_test.php" class="row ctl wrap"><?= Csrf::field() ?><input type="hidden" name="back" value="welcome"><span class="k">Coba putar suara adzan<small>Memutar awal suara adzan selama 10 detik untuk memeriksa speaker.</small></span><button type="submit" class="btn small">Putar</button></form>
    </div>
    <p class="aside spaced">Bila suara tidak terdengar, periksa sambungan dan volume speaker, atau buka <a href="/admin/help.php#adzan-tidak-bunyi">Panduan</a> bagian Adzan tidak terdengar.</p>
    <form method="post" class="stack" style="margin-top:16px">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="done">
        <div class="foot"><button type="submit" class="btn">Selesai</button></div>
    </form>
<?php endif; ?>

<form method="post" class="skip">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="skip">
    <button type="submit" class="link">Lewati dan atur nanti</button>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
