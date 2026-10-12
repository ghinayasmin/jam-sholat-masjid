<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Config;
use App\LocalTime;
use App\Repository\MotionEventRepository;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$repo = new MotionEventRepository();
$today = LocalTime::today();
$date = $_GET['date'] ?? $_POST['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = $today;
}

/** Hapus baris DB beserta file fotonya. */
$deleteEvent = static function (array $event) use ($repo): void {
    $file = Config::get('captures_path') . '/' . basename($event['image_path']);
    if (is_file($file)) {
        @unlink($file);
    }
    $repo->delete((int) $event['id']);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $event = $repo->find((int) ($_POST['id'] ?? 0));
        if ($event !== null) {
            $deleteEvent($event);
            $_SESSION['flash_success'] = 'Foto dihapus.';
        }
    } elseif ($action === 'delete_day') {
        $events = $repo->list($date, 10000);
        foreach ($events as $event) {
            $deleteEvent($event);
        }
        $_SESSION['flash_success'] = count($events) . ' foto dihapus.';
    }
    header('Location: /admin/camera.php?date=' . urlencode($date));
    exit;
}

// REQ-F-023: rekaman hanya bisa dilihat admin; gambar dilayani lewat capture.php.
$events = $repo->list($date, 200);

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

<?php require __DIR__ . '/_footer.php'; ?>
