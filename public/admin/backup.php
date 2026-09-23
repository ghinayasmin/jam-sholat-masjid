<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Backup\BackupManager;
use App\Repository\BackupRepository;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$backupMgr = new BackupManager();
$backupRepo = new BackupRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            $backup = $backupMgr->createBackup('manual');
            $_SESSION['flash_success'] = 'Backup berhasil dibuat: ' . $backup['file_name'];
        } catch (\Throwable $e) {
            error_log('[backup create] ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Gagal membuat backup.';
        }
    } elseif ($action === 'restore') {
        $file = $_FILES['backup_file'] ?? null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Pilih file backup (.json) yang valid.';
        } else {
            try {
                $backupMgr->restoreFromFile($file['tmp_name']);
                $_SESSION['flash_success'] = 'Restore berhasil. Data telah dipulihkan.';
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = 'Restore gagal: ' . $e->getMessage();
            }
        }
    }

    header('Location: /admin/backup.php');
    exit;
}

if (isset($_GET['download'])) {
    $backups = $backupRepo->list();
    $id = (int) $_GET['download'];
    $target = null;
    foreach ($backups as $b) {
        if ((int) $b['id'] === $id) {
            $target = $b;
            break;
        }
    }
    if ($target !== null && is_file($target['file_path'])) {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . basename($target['file_path']) . '"');
        readfile($target['file_path']);
        exit;
    }
    http_response_code(404);
    exit('Backup tidak ditemukan.');
}

$backups = $backupRepo->list();

$pageTitle = 'Backup & Restore';
$activeMenu = 'backup';
require __DIR__ . '/_header.php';
?>

<div class="card">
    <h2>Buat Backup Baru</h2>
    <p class="muted">Backup mencakup: pengaturan masjid, laporan keuangan, dan publikasi. Rekaman kamera
        TIDAK termasuk (volumenya besar dan bisa dilihat langsung di menu Rekaman Kamera).</p>
    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create">
        <button type="submit">Buat Backup Sekarang</button>
    </form>
</div>

<div class="card form-card">
    <h2>Restore dari Backup</h2>
    <p class="muted"><strong>Perhatian:</strong> restore akan menimpa pengaturan, laporan keuangan, dan publikasi
        yang ada saat ini dengan isi file backup.</p>
    <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Yakin? Data saat ini akan ditimpa oleh isi backup.');">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="restore">
        <label>File Backup (.json)
            <input type="file" name="backup_file" accept="application/json,.json" required>
        </label>
        <button type="submit" class="btn-danger">Restore</button>
    </form>
</div>

<div class="card">
    <h2>Riwayat Backup</h2>
    <table class="data-table">
        <thead><tr><th>Nama File</th><th>Ukuran</th><th>Tipe</th><th>Dibuat</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
            <tr>
                <td><?= htmlspecialchars($b['file_name']) ?></td>
                <td><?= round((int) $b['size_bytes'] / 1024, 1) ?> KB</td>
                <td><?= htmlspecialchars($b['type']) ?></td>
                <td><?= htmlspecialchars($b['created_at']) ?></td>
                <td><a href="?download=<?= (int) $b['id'] ?>" class="btn-small">Unduh</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($backups === []): ?>
            <tr><td colspan="5" class="muted">Belum ada backup.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
