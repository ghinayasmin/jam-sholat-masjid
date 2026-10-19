<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

/**
 * REQ-F-020: admin bisa mengatur jam/tanggal perangkat secara manual.
 * Ini pelengkap untuk NTP (REQ-F-060, otomatis lewat systemd-timesyncd) —
 * dipakai saat perangkat benar-benar offline dan jam meleset.
 *
 * Mengubah jam sistem OS dari PHP butuh privilege root. Solusi yang dipakai:
 * PHP memanggil `sudo /usr/bin/date` lewat exec(), dan sudoers dikonfigurasi
 * (lihat scripts/install.sh) agar user web server HANYA boleh menjalankan
 * perintah `date` tanpa password — tidak ada privilege lain yang diberikan.
 */

$errors = [];
$success = null;

$canSet = \App\SystemClock::canSet();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canSet) {
    Csrf::requireValid($_POST['csrf_token'] ?? null);

    $dateStr = trim((string) ($_POST['date'] ?? ''));
    $timeStr = trim((string) ($_POST['time'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr) || !preg_match('/^\d{2}:\d{2}$/', $timeStr)) {
        $errors[] = 'Format tanggal/jam tidak valid.';
    } else {
        $dt = \DateTime::createFromFormat('Y-m-d H:i', "$dateStr $timeStr");
        if ($dt === false) {
            $errors[] = 'Tanggal/jam tidak valid.';
        } else {
            $formatted = $dt->format('Y-m-d H:i:00');
            $cmd = 'sudo /usr/bin/date -s ' . escapeshellarg($formatted) . ' 2>&1';
            exec($cmd, $output, $exitCode);

            if ($exitCode === 0) {
                \App\SystemClock::markSet();
                $success = 'Jam perangkat diatur ke ' . $dt->format('H:i') . ', ' . \App\View\Format::longDate($dt->format('Y-m-d')) . '.';
            } else {
                error_log('[settings_time] ' . implode(' ', $output));
                $errors[] = 'Jam perangkat gagal diubah. Minta teknisi memeriksa pengaturan sudoers (scripts/install.sh langkah 8).';
            }
        }
    }
}

$localNow = \App\LocalTime::now();

$pageTitle = 'Jam & tanggal';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<?php if ($success !== null): ?>
    <div class="flash flash-success" role="status"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($errors !== []): ?>
    <div class="flash flash-error" role="alert"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
<?php endif; ?>

<div class="balance">
    <div><div class="s">Jam perangkat sekarang</div><div class="n"><?= gmdate('H:i', $localNow) ?></div></div>
    <div class="s" style="padding-top:12px;box-shadow:inset 0 1px 0 var(--line)"><?= htmlspecialchars(\App\View\Format::longDate(gmdate('Y-m-d', $localNow))) ?></div>
</div>

<?php if (!$canSet): ?>
<div class="note">Jam mengikuti jam komputer ini. Untuk mengubahnya, buka pengaturan Date &amp; time di Windows pada komputer masjid.</div>
<?php else: ?>
<div class="note">Saat ada internet, jam diperbaiki otomatis. Halaman ini hanya untuk koreksi bila perangkat lama offline dan jamnya meleset.</div>

<form method="post" class="stack">
    <?= Csrf::field() ?>
    <div class="pair">
        <div class="field">
            <label class="field-label" for="date">Tanggal</label>
            <input type="date" id="date" name="date" value="<?= gmdate('Y-m-d', $localNow) ?>" required>
        </div>
        <div class="field">
            <label class="field-label" for="time">Jam</label>
            <input type="time" id="time" name="time" value="<?= gmdate('H:i', $localNow) ?>" required>
        </div>
    </div>
    <div class="foot"><button type="submit" class="btn">Atur jam perangkat</button></div>
</form>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
