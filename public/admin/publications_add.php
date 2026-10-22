<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Config;
use App\LocalTime;
use App\Media\UploadedImage;
use App\Repository\PublicationRepository;
use App\View\Format;
use App\View\Icon;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$repo = new PublicationRepository();
$today = LocalTime::today();
$publicationsPath = (string) Config::get('publications_path');
$maxBytes = UploadedImage::maxBytes((int) Config::get('publication_max_bytes', 20 * 1024 * 1024));
$maxMb = round($maxBytes / 1024 / 1024, 1);

// ?id= : ubah pengumuman yang sudah dipasang (#40), selain itu pasang baru.
$editId = (int) ($_GET['id'] ?? 0);
$editing = $editId > 0 ? $repo->find($editId) : null;
if ($editId > 0 && $editing === null) {
    header('Location: /admin/publications.php');
    exit;
}

$fields = ['caption', 'starts_on', 'ends_on', 'event_date', 'event_end_date', 'event_start_time', 'event_end_time'];
$values = ['caption' => '', 'starts_on' => $today, 'ends_on' => '', 'event_date' => '', 'event_end_date' => '',
    'event_start_time' => '', 'event_end_time' => ''];
if ($editing !== null) {
    foreach ($fields as $f) {
        $values[$f] = (string) ($editing[$f] ?? '');
    }
}
$errors = [];
$isDate = static fn (string $v): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
$isTime = static fn (string $v): bool => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);

    foreach ($fields as $f) {
        $values[$f] = trim((string) ($_POST[$f] ?? ''));
    }
    $file = $_FILES['poster'] ?? null;
    $hasFile = $file !== null && $file['error'] !== UPLOAD_ERR_NO_FILE;
    $hasOldImage = $editing !== null && $editing['file_path'] !== '';

    if (!$hasFile && !$hasOldImage && $values['caption'] === '') {
        $errors[] = 'Pilih foto poster atau tulis teks pengumumannya.';
    }
    if (!$isDate($values['starts_on'])) {
        $errors[] = 'Tanggal mulai tampil tidak valid.';
    }
    // Waktu acara (opsional). Tanpa tanggal, jam dan tanggal selesai diabaikan.
    if ($values['event_date'] === '') {
        $values['event_end_date'] = $values['event_start_time'] = $values['event_end_time'] = '';
    } elseif (!$isDate($values['event_date'])) {
        $errors[] = 'Tanggal acara tidak valid.';
    } else {
        if ($values['event_end_date'] !== '' && (!$isDate($values['event_end_date']) || $values['event_end_date'] < $values['event_date'])) {
            $errors[] = 'Tanggal selesai acara tidak boleh sebelum tanggal acara.';
        }
        if ($values['event_start_time'] !== '' && !$isTime($values['event_start_time'])) {
            $errors[] = 'Jam mulai acara tidak valid.';
        }
        if ($values['event_end_time'] !== '' && !$isTime($values['event_end_time'])) {
            $errors[] = 'Jam selesai acara tidak valid.';
        }
        if ($values['event_end_time'] !== '' && $values['event_start_time'] === '') {
            $errors[] = 'Jam mulai wajib diisi apabila jam selesai diisi.';
        }
        $oneDay = $values['event_end_date'] === '' || $values['event_end_date'] === $values['event_date'];
        if ($oneDay && $values['event_start_time'] !== '' && $values['event_end_time'] !== ''
            && $values['event_end_time'] <= $values['event_start_time']) {
            $errors[] = 'Jam selesai harus setelah jam mulai.';
        }
        // Poster acara hilang sendiri setelah acaranya lewat.
        if ($values['ends_on'] === '') {
            $values['ends_on'] = $values['event_end_date'] !== '' ? $values['event_end_date'] : $values['event_date'];
        }
    }
    if ($values['ends_on'] !== '' && (!$isDate($values['ends_on']) || $values['ends_on'] < $values['starts_on'])) {
        $errors[] = 'Tanggal "Sampai" tidak boleh sebelum tanggal mulai tampil.';
    }

    $newImage = null;
    if ($errors === [] && $hasFile) {
        [$stored, $uploadError] = UploadedImage::store($file, $publicationsPath, 'pub', $maxBytes);
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        } else {
            $newImage = (string) $stored;
        }
    }

    if ($errors === []) {
        $caption = $values['caption'] !== '' ? mb_substr($values['caption'], 0, 200) : null;
        $endsOn = $values['ends_on'] !== '' ? $values['ends_on'] : null;
        $event = ['date' => $values['event_date'], 'end_date' => $values['event_end_date'],
            'start_time' => $values['event_start_time'], 'end_time' => $values['event_end_time']];
        if ($editing !== null) {
            $repo->update($editId, $newImage, $caption, $values['starts_on'], $endsOn, $event);
            if ($newImage !== null && $hasOldImage) {
                $old = $publicationsPath . '/' . basename($editing['file_path']);
                if (is_file($old)) {
                    @unlink($old);
                }
            }
            $_SESSION['flash_success'] = 'Perubahan telah disimpan. Layar jamaah akan diperbarui dalam satu menit.';
        } else {
            $repo->create($newImage ?? '', $newImage ?? '', $caption, $values['starts_on'], $endsOn, $event);
            $_SESSION['flash_success'] = $values['starts_on'] > $today
                ? 'Pengumuman terjadwal, mulai tampil ' . Format::shortDate($values['starts_on']) . '.'
                : 'Pengumuman tampil di layar jamaah dalam 1 menit.'
                    . ($endsOn !== null ? ' Otomatis hilang setelah ' . Format::shortDate($endsOn) . '.' : '');
        }
        header('Location: /admin/publications.php');
        exit;
    }
}

$pageTitle = $editing !== null ? 'Ubah pengumuman' : 'Pasang pengumuman';
$backHref = '/admin/publications.php';
$backLabel = 'Pengumuman';
require __DIR__ . '/_header.php';
?>

<?php if ($errors !== []): ?>
    <div class="flash flash-error" role="alert"><?= Format::e(implode(' ', $errors)) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="stack">
    <?= Csrf::field() ?>

    <?php if ($editing !== null && $editing['file_path'] !== ''): ?>
        <div class="card current-poster">
            <img src="/uploads/publications/<?= rawurlencode(basename($editing['file_path'])) ?>" alt="Poster saat ini">
            <label class="btn small ghost">Ganti gambar<input type="file" name="poster" accept="image/jpeg,image/png" hidden></label>
        </div>
    <?php else: ?>
        <label class="drop">
            <?= Icon::svg('upload') ?>
            <b>Pilih foto poster</b>
            <small>Dari galeri HP atau ambil foto · JPG/PNG, maks <?= $maxMb ?> MB</small>
            <input type="file" name="poster" accept="image/jpeg,image/png">
        </label>
    <?php endif; ?>

    <div class="field">
        <label class="field-label" for="caption">Teks pengumuman <span class="opt">(wajib bila tanpa poster)</span></label>
        <input type="text" id="caption" name="caption" maxlength="200" value="<?= Format::e($values['caption']) ?>"
               placeholder="Contoh: Kajian tafsir Ahad ba'da Subuh">
        <p class="hint">Teks ini muncul di baris "Pengumuman" pada layar jamaah.</p>
    </div>

    <fieldset class="event-box" data-event aria-labelledby="event-title">
        <div class="event-head"><?= Icon::svg('calendar') ?><span id="event-title">Waktu acara</span><span class="event-opt">opsional</span></div>
        <div class="field">
            <label class="field-label" for="event_date">Tanggal</label>
            <input type="date" id="event_date" name="event_date" value="<?= Format::e($values['event_date']) ?>">
        </div>
        <div class="pair">
            <div class="field">
                <label class="field-label" for="event_start_time">Jam mulai</label>
                <input type="time" id="event_start_time" name="event_start_time" value="<?= Format::e($values['event_start_time']) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="event_end_time">Jam selesai</label>
                <input type="time" id="event_end_time" name="event_end_time" value="<?= Format::e($values['event_end_time']) ?>">
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="event_end_date">Sampai tanggal <span class="opt">(untuk acara lebih dari satu hari)</span></label>
            <input type="date" id="event_end_date" name="event_end_date" value="<?= Format::e($values['event_end_date']) ?>">
        </div>
        <p class="aside tight">Waktu acara ditampilkan di layar jamaah di bawah teks pengumuman, misalnya "Minggu, 25 Okt · 07.00–12.30". Jam mulai dan jam selesai boleh dikosongkan. Kosongkan jam selesai apabila acara berlangsung hingga selesai.</p>
    </fieldset>

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
    <p class="aside tight">Apabila tanggal acara diisi dan kolom "Sampai" dikosongkan, pengumuman akan hilang dengan sendirinya setelah acara selesai. Tanpa tanggal acara, kosongkan kolom "Sampai" agar pengumuman tetap tampil hingga dihentikan.</p>

    <div class="foot"><button type="submit" class="btn"><?= $editing !== null ? 'Simpan perubahan' : 'Pasang pengumuman' ?></button></div>
</form>

<?php if ($editing !== null): ?>
    <form method="post" action="/admin/publications.php" data-confirm="Hapus pengumuman ini? Tindakan ini tidak dapat dibatalkan." class="foot">
        <?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $editId ?>">
        <button type="submit" class="btn danger">Hapus pengumuman ini</button>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
