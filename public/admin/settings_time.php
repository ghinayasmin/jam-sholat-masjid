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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                $success = 'Jam sistem berhasil diperbarui ke ' . $formatted . '.';
            } else {
                $errors[] = 'Gagal mengubah jam sistem. Detail: ' . implode(' ', $output)
                    . ' (Pastikan scripts/install.sh sudah dijalankan agar sudoers dikonfigurasi.)';
            }
        }
    }
}

$now = new \DateTime('now', new \DateTimeZone('UTC'));
// Catatan: nilai default form ini UTC murni (bootstrap.php set timezone UTC secara sengaja,
// lihat komentar di bootstrap.php). Takmir memasukkan jam LOKAL yang diinginkan;
// sinkronisasi zona ditangani lewat timezone_offset_hours di pengaturan lokasi.

$pageTitle = 'Jam & Tanggal Sistem';
$activeMenu = 'time';
require __DIR__ . '/_header.php';
?>

<?php if ($success !== null): ?>
    <div class="flash flash-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($errors !== []): ?>
    <div class="flash flash-error"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
<?php endif; ?>

<div class="card">
    <h2>Waktu Server Saat Ini (UTC)</h2>
    <p class="mono-large"><?= htmlspecialchars($now->format('Y-m-d H:i:s')) ?></p>
    <p class="muted">Sistem menyimpan waktu internal dalam UTC. Sinkronisasi otomatis (NTP) berjalan lewat
        systemd-timesyncd bawaan OS ketika perangkat online — halaman ini hanya untuk koreksi manual saat offline.</p>
</div>

<form method="post" class="card form-card">
    <?= Csrf::field() ?>
    <label>Tanggal
        <input type="date" name="date" required>
    </label>
    <label>Jam (format 24 jam)
        <input type="time" name="time" required>
    </label>
    <button type="submit">Perbarui Jam Sistem</button>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
