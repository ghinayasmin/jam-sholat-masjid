<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Config;
use App\Repository\PublicationRepository;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$repo = new PublicationRepository();
$publicationsPath = (string) Config::get('publications_path');
$maxBytes = (int) Config::get('publication_max_bytes', 5 * 1024 * 1024);
$allowedMime = (array) Config::get('publication_allowed_mime', ['image/jpeg', 'image/png']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $file = $_FILES['poster'] ?? null;
        $caption = trim((string) ($_POST['caption'] ?? ''));

        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Upload gagal. Pastikan file dipilih dan tidak melebihi batas ukuran.';
        } elseif ($file['size'] > $maxBytes) {
            $_SESSION['flash_error'] = 'Ukuran file melebihi batas ' . round($maxBytes / 1024 / 1024, 1) . ' MB.';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowedMime, true)) {
                $_SESSION['flash_error'] = 'Format file tidak didukung. Gunakan JPG atau PNG.';
            } else {
                $ext = $mime === 'image/png' ? 'png' : 'jpg';
                $safeName = 'pub_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $destination = $publicationsPath . '/' . $safeName;

                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    $repo->create($safeName, $safeName, $caption !== '' ? $caption : null);
                    $_SESSION['flash_success'] = 'Poster berhasil diunggah.';
                } else {
                    $_SESSION['flash_error'] = 'Gagal menyimpan file ke server.';
                }
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $active = ($_POST['active'] ?? '0') === '1';
        $repo->setActive($id, $active);
        $_SESSION['flash_success'] = 'Status publikasi diperbarui.';
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pub = $repo->find($id);
        if ($pub !== null) {
            $filePath = $publicationsPath . '/' . basename($pub['file_path']);
            if (is_file($filePath)) {
                @unlink($filePath);
            }
            $repo->delete($id);
            $_SESSION['flash_success'] = 'Poster dihapus.';
        }
    } elseif ($action === 'reorder') {
        $ids = array_map('intval', explode(',', (string) ($_POST['order'] ?? '')));
        $ids = array_filter($ids, static fn ($v) => $v > 0);
        if ($ids !== []) {
            $repo->reorder($ids);
        }
    }

    header('Location: /admin/publications.php');
    exit;
}

$items = $repo->all();

$pageTitle = 'Publikasi';
$activeMenu = 'publications';
require __DIR__ . '/_header.php';
?>

<div class="card form-card">
    <h2>Unggah Poster Baru</h2>
    <form method="post" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="upload">
        <label>File Poster (JPG/PNG, maks <?= round($maxBytes / 1024 / 1024, 1) ?> MB)
            <input type="file" name="poster" accept="image/jpeg,image/png" required>
        </label>
        <label>Keterangan (opsional)
            <input type="text" name="caption" maxlength="200">
        </label>
        <button type="submit">Unggah</button>
    </form>
</div>

<div class="card">
    <h2>Daftar Publikasi (urutan tampil di layar jamaah)</h2>
    <table class="data-table">
        <thead><tr><th>Preview</th><th>Keterangan</th><th>Status</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($items as $pub): ?>
            <tr>
                <td><img class="thumb" src="/uploads/publications/<?= rawurlencode(basename($pub['file_path'])) ?>" alt=""></td>
                <td><?= htmlspecialchars($pub['caption'] ?? '') ?></td>
                <td><?= ((int) $pub['is_active'] === 1) ? 'Aktif' : 'Nonaktif' ?></td>
                <td class="actions">
                    <form method="post" class="inline-form">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $pub['id'] ?>">
                        <input type="hidden" name="active" value="<?= (int) $pub['is_active'] === 1 ? '0' : '1' ?>">
                        <button type="submit" class="btn-small"><?= (int) $pub['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                    </form>
                    <form method="post" class="inline-form" onsubmit="return confirm('Hapus poster ini?');">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $pub['id'] ?>">
                        <button type="submit" class="btn-small btn-danger">Hapus</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($items === []): ?>
            <tr><td colspan="4" class="muted">Belum ada publikasi diunggah.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
