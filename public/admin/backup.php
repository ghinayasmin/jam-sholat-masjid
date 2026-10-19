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
            $_SESSION['flash_success'] = 'Cadangan berhasil dibuat. Unduh dan simpan di tempat lain agar aman.';
        } catch (\Throwable $e) {
            error_log('[backup create] ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Cadangan gagal dibuat. Coba lagi.';
        }
    } elseif ($action === 'restore') {
        $file = $_FILES['backup_file'] ?? null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Pilih file cadangan (.json) terlebih dulu.';
        } else {
            try {
                $backupMgr->restoreFromFile($file['tmp_name']);
                $_SESSION['flash_success'] = 'Data berhasil dipulihkan dari cadangan.';
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = 'Pemulihan gagal, data tidak diubah. ' . $e->getMessage();
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

$pageTitle = 'Cadangan data';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<div class="note">Cadangan berisi pengaturan masjid, catatan kas, dan pengumuman. Rekaman kamera tidak ikut.</div>

<form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">
    <button type="submit" class="btn">Buat cadangan sekarang</button>
</form>

<section class="group" aria-labelledby="hist-label">
    <div class="label" id="hist-label">Riwayat cadangan</div>
    <div class="card">
        <?php foreach ($backups as $b): ?>
            <a class="row ctl" href="?download=<?= (int) $b['id'] ?>">
                <span class="k"><?php $local = (int) strtotime($b['created_at'] . ' UTC') + \App\LocalTime::offsetSeconds(); // created_at disimpan UTC ?>
                    <?= htmlspecialchars(\App\View\Format::shortDate(gmdate('Y-m-d', $local))) ?>, <?= gmdate('H:i', $local) ?>
                    <small><?= $b['type'] === 'manual' ? 'Dibuat manual' : 'Otomatis' ?> · <?= round((int) $b['size_bytes'] / 1024, 1) ?> KB</small></span>
                <span class="v ok">Unduh</span>
            </a>
        <?php endforeach; ?>
        <?php if ($backups === []): ?>
            <div class="empty">Belum ada cadangan.</div>
        <?php endif; ?>
    </div>
</section>

<section class="group" aria-labelledby="restore-label">
    <div class="label" id="restore-label">Pulihkan dari cadangan</div>
    <form method="post" enctype="multipart/form-data" class="stack" data-confirm="Data saat ini akan ditimpa isi file cadangan. Lanjutkan?">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="restore">
        <div class="note warn">Memulihkan akan <b>menimpa</b> pengaturan, catatan kas, dan pengumuman yang ada sekarang.</div>
        <div class="field">
            <label class="field-label" for="backup_file">File cadangan (.json)</label>
            <input type="file" id="backup_file" name="backup_file" accept="application/json,.json" required class="input">
        </div>
        <button type="submit" class="btn danger">Pulihkan data</button>
    </form>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
