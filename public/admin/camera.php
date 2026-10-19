<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Config;
use App\LocalTime;
use App\Repository\MotionEventRepository;
use App\Repository\SettingsRepository;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$repo = new MotionEventRepository();
$settingsRepo = new SettingsRepository();
// REQ-F-044: lama penyimpanan foto. Pilihan dibatasi agar mudah dipahami takmir;
// 7 hari mencakup satu siklus Jumat ke Jumat (DECISIONS.md #26).
$retentionChoices = [3, 7, 14, 30];
$today = LocalTime::today();
$date = $_GET['date'] ?? $_POST['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = $today;
}

/**
 * Hapus file foto lalu barisnya di DB. Isi file ditimpa nol dulu supaya foto tidak
 * bisa dipulihkan dari kartu SD dengan alat pemulihan biasa. Baris DB hanya dihapus
 * bila filenya benar-benar hilang, jadi tidak ada foto tersembunyi yang tertinggal.
 */
$deleteEvent = static function (array $event) use ($repo): bool {
    $file = Config::get('captures_path') . '/' . basename($event['image_path']);
    if (is_file($file)) {
        $size = (int) filesize($file);
        $fh = @fopen($file, 'r+b');
        if ($fh !== false) {
            for ($left = $size; $left > 0; $left -= 65536) {
                fwrite($fh, str_repeat("\0", min(65536, $left)));
            }
            fflush($fh);
            fsync($fh);
            fclose($fh);
        } else {
            error_log('[camera] Tidak bisa menimpa ' . $file . ' sebelum dihapus (izin file).');
        }
        if (!@unlink($file)) {
            error_log('[camera] Gagal menghapus ' . $file);
            return false;
        }
    }
    $repo->delete((int) $event['id']);
    return true;
};

/** @param list<array> $events  @return int jumlah yang gagal dihapus */
$deleteEvents = static function (array $events) use ($deleteEvent): int {
    $failed = 0;
    foreach ($events as $event) {
        $failed += $deleteEvent($event) ? 0 : 1;
    }
    if ($failed > 0) {
        $_SESSION['flash_error'] = "$failed foto gagal dihapus dari perangkat. Minta teknisi memeriksa izin folder storage/captures.";
    }
    return $failed;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $event = $repo->find((int) ($_POST['id'] ?? 0));
        if ($event !== null && $deleteEvents([$event]) === 0) {
            $_SESSION['flash_success'] = 'Foto dihapus.';
        }
    } elseif ($action === 'delete_day') {
        $events = $repo->list($date, 10000);
        $failed = $deleteEvents($events);
        $_SESSION['flash_success'] = (count($events) - $failed) . ' foto dihapus.';
    } elseif ($action === 'retention') {
        $days = (int) ($_POST['image_retention_days'] ?? 0);
        if (in_array($days, $retentionChoices, true)) {
            $settingsRepo->update(['image_retention_days' => $days]);
            // Langsung terapkan; pembersihan otomatis (03:00) juga memakai nilai ini.
            $cutoff = gmdate('Y-m-d H:i:s', LocalTime::now() - $days * 86400);
            $old = $repo->olderThan($cutoff);
            $removed = count($old) - $deleteEvents($old);
            $_SESSION['flash_success'] = "Foto disimpan selama $days hari."
                . ($removed > 0 ? " $removed foto lama dihapus." : '');
        }
    }
    header('Location: /admin/camera.php?date=' . urlencode($date));
    exit;
}

// REQ-F-023: rekaman hanya bisa dilihat admin; gambar dilayani lewat capture.php.
$events = $repo->list($date, 200);
$retentionDays = (int) $settingsRepo->get()['image_retention_days'];

$pageTitle = 'Rekaman kamera';
$pageSubtitle = 'Foto otomatis saat ada gerakan';
$activeTab = 'camera';
require __DIR__ . '/_header.php';
?>

<form method="get" class="date-filter">
    <div class="field">
        <label class="field-label" for="date">Tanggal</label>
        <input type="date" id="date" name="date" max="<?= $today ?>" value="<?= Format::e($date) ?>">
    </div>
    <button type="submit" class="btn ghost small" style="min-height:50px">Tampilkan</button>
</form>

<section class="group" aria-labelledby="events-label">
    <div class="label" id="events-label"><?= Format::e(Format::relativeDate($date, $today)) ?> · <?= count($events) ?> gerakan</div>
    <?php if ($events !== []): ?>
        <div class="motion-grid">
            <?php foreach ($events as $e): ?>
                <figure>
                    <a href="/admin/capture.php?id=<?= (int) $e['id'] ?>" target="_blank" rel="noopener">
                        <img src="/admin/capture.php?id=<?= (int) $e['id'] ?>" loading="lazy" alt="Gerakan terdeteksi pukul <?= Format::e(substr($e['detected_at'], 11, 5)) ?>">
                    </a>
                    <figcaption>
                        <span>Pukul <?= Format::e(substr($e['detected_at'], 11, 5)) ?></span>
                        <form method="post" data-confirm="Hapus foto ini? Tidak bisa dibatalkan."><?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="date" value="<?= Format::e($date) ?>"><button type="submit" class="link-btn">Hapus</button></form>
                    </figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
        <form method="post" class="motion-clear" data-confirm="Hapus semua <?= count($events) ?> foto pada tanggal ini? Tidak bisa dibatalkan."><?= Csrf::field() ?><input type="hidden" name="action" value="delete_day"><input type="hidden" name="date" value="<?= Format::e($date) ?>"><button type="submit" class="btn danger">Hapus semua foto tanggal ini</button></form>
    <?php else: ?>
        <div class="card"><div class="empty">Tidak ada gerakan terekam pada tanggal ini.</div></div>
    <?php endif; ?>
</section>

<section class="group" aria-labelledby="retention-label">
    <div class="label" id="retention-label">Simpan foto selama</div>
    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="retention">
        <input type="hidden" name="date" value="<?= Format::e($date) ?>">
        <div class="seg four" role="radiogroup" aria-labelledby="retention-label">
            <?php foreach ($retentionChoices as $d): ?>
                <label><input type="radio" name="image_retention_days" value="<?= $d ?>" data-autosubmit<?= $d < $retentionDays ? ' data-confirm-change="Foto yang lebih lama dari ' . $d . ' hari akan langsung dihapus. Lanjutkan?"' : '' ?><?= $d === $retentionDays ? ' checked' : '' ?>><span><?= $d ?> hari</span></label>
            <?php endforeach; ?>
        </div>
        <noscript><button type="submit" class="btn small">Simpan</button></noscript>
    </form>
    <div class="note">Foto lebih lama dihapus otomatis tiap malam. Bila memori hampir penuh, foto terlama dihapus lebih dulu.</div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
