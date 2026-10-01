<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Config;
use App\LocalTime;
use App\Repository\PublicationRepository;
use App\View\Format;
use App\View\Icon;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$repo = new PublicationRepository();
$today = LocalTime::today();
$publicationsPath = (string) Config::get('publications_path');
$maxBytes = (int) Config::get('publication_max_bytes', 5 * 1024 * 1024);
$allowedMime = (array) Config::get('publication_allowed_mime', ['image/jpeg', 'image/png']);
$maxMb = round($maxBytes / 1024 / 1024, 1);

$values = ['caption' => '', 'starts_on' => $today, 'ends_on' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);

    $values['caption'] = trim((string) ($_POST['caption'] ?? ''));
    $values['starts_on'] = trim((string) ($_POST['starts_on'] ?? ''));
    $values['ends_on'] = trim((string) ($_POST['ends_on'] ?? ''));
    $file = $_FILES['poster'] ?? null;
    $hasFile = $file !== null && $file['error'] !== UPLOAD_ERR_NO_FILE;

    if (!$hasFile && $values['caption'] === '') {
        $errors[] = 'Pilih foto poster atau tulis teks pengumumannya.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['starts_on'])) {
        $errors[] = 'Tanggal mulai tidak valid.';
    }
    if ($values['ends_on'] !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['ends_on']) || $values['ends_on'] < $values['starts_on'])) {
        $errors[] = 'Tanggal selesai harus sama atau setelah tanggal mulai.';
    }

    $safeName = '';
    if ($errors === [] && $hasFile) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Foto gagal diunggah. Coba lagi, atau pilih foto yang lebih kecil dari ' . $maxMb . ' MB.';
        } elseif ($file['size'] > $maxBytes) {
            $errors[] = 'Ukuran foto melebihi ' . $maxMb . ' MB.';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowedMime, true)) {
                $errors[] = 'Format foto tidak didukung. Gunakan JPG atau PNG.';
            } else {
                $safeName = 'pub_' . bin2hex(random_bytes(8)) . ($mime === 'image/png' ? '.png' : '.jpg');
                if (!move_uploaded_file($file['tmp_name'], $publicationsPath . '/' . $safeName)) {
                    $errors[] = 'Foto gagal disimpan di perangkat.';
                    $safeName = '';
                }
            }
        }
    }

    if ($errors === []) {
        $repo->create(
            $safeName,
            $safeName,
            $values['caption'] !== '' ? mb_substr($values['caption'], 0, 200) : null,
            $values['starts_on'],
            $values['ends_on'] !== '' ? $values['ends_on'] : null
        );
        $_SESSION['flash_success'] = $values['starts_on'] > $today
            ? 'Pengumuman terjadwal, mulai tampil ' . Format::shortDate($values['starts_on']) . '.'
            : 'Pengumuman tampil di layar jamaah dalam 1 menit.'
                . ($values['ends_on'] !== '' ? ' Otomatis hilang setelah ' . Format::shortDate($values['ends_on']) . '.' : '');
        header('Location: /admin/publications.php');
        exit;
    }
}

$pageTitle = 'Pasang pengumuman';
$backHref = '/admin/publications.php';
$backLabel = 'Pengumuman';
require __DIR__ . '/_header.php';
?>

<?php if ($errors !== []): ?>
    <div class="flash flash-error" role="alert"><?= Format::e(implode(' ', $errors)) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="stack">
    <?= Csrf::field() ?>

    <label class="drop">
        <?= Icon::svg('upload') ?>
        <b>Pilih foto poster</b>
        <small>Dari galeri HP atau ambil foto · JPG/PNG, maks <?= $maxMb ?> MB</small>
        <input type="file" name="poster" accept="image/jpeg,image/png">
    </label>

    <div class="field">
        <label class="field-label" for="caption">Teks pengumuman <span class="opt">(wajib bila tanpa poster)</span></label>
        <input type="text" id="caption" name="caption" maxlength="200" value="<?= Format::e($values['caption']) ?>"
               placeholder="Contoh: Kajian tafsir Ahad ba'da Subuh">
        <p class="hint">Teks ini muncul di baris "Pengumuman" pada layar jamaah.</p>
    </div>

    <div class="pair">
        <div class="field">
            <label class="field-label" for="starts_on">Tampil mulai</label>
            <input type="date" id="starts_on" name="starts_on" value="<?= Format::e($values['starts_on']) ?>" required>
        </div>
        <div class="field">
            <label class="field-label" for="ends_on">Sampai <span class="opt">(opsional)</span></label>
            <input type="date" id="ends_on" name="ends_on" value="<?= Format::e($values['ends_on']) ?>">
        </div>
    </div>
    <p class="hint muted" style="margin:-8px 0 0;padding-left:4px;font-size:13px">Kosongkan "Sampai" bila pengumuman tampil terus sampai dihentikan.</p>

    <div class="foot"><button type="submit" class="btn">Pasang pengumuman</button></div>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
