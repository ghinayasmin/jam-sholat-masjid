<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\LocalTime;
use App\Repository\MotionEventRepository;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$today = LocalTime::today();
$date = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = $today;
}

// REQ-F-023: rekaman hanya bisa dilihat admin; gambar dilayani lewat capture.php.
$events = (new MotionEventRepository())->list($date, 200);

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
                    <figcaption>Pukul <?= Format::e(substr($e['detected_at'], 11, 5)) ?></figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card"><div class="empty">Tidak ada gerakan terekam pada tanggal ini.</div></div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
