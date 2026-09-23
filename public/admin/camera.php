<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Repository\MotionEventRepository;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$date = $_GET['date'] ?? null;
if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = null;
}

$events = (new MotionEventRepository())->list($date, 200);

$pageTitle = 'Rekaman Kamera';
$activeMenu = 'camera';
require __DIR__ . '/_header.php';
?>

<div class="card">
    <form method="get" class="filter-form">
        <label>Filter tanggal
            <input type="date" name="date" value="<?= htmlspecialchars((string) $date) ?>">
        </label>
        <button type="submit">Tampilkan</button>
        <a href="/admin/camera.php" class="btn-secondary">Reset</a>
    </form>
</div>

<div class="card">
    <h2>Kejadian Terdeteksi (<?= count($events) ?>)</h2>
    <div class="motion-grid">
        <?php foreach ($events as $e): ?>
            <figure class="motion-item">
                <img src="/admin/capture.php?id=<?= (int) $e['id'] ?>" loading="lazy" alt="Deteksi gerakan">
                <figcaption><?= htmlspecialchars($e['detected_at']) ?></figcaption>
            </figure>
        <?php endforeach; ?>
        <?php if ($events === []): ?>
            <p class="muted">Tidak ada rekaman untuk filter ini.</p>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
