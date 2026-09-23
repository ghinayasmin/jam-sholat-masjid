<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Repository\SettingsRepository;
use App\Repository\FinancialRepository;
use App\Repository\MotionEventRepository;
use App\Schedule\ScheduleEngine;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$settings = (new SettingsRepository())->get();
$schedule = (new ScheduleEngine())->getScheduleForToday();
$balance = (new FinancialRepository())->currentBalance();
$recentMotion = (new MotionEventRepository())->list(null, 5);

$pageTitle = 'Dashboard';
$activeMenu = 'dashboard';
require __DIR__ . '/_header.php';
?>

<div class="grid grid-3">
    <div class="card">
        <h2>Jadwal Hari Ini</h2>
        <p class="hijri"><?= htmlspecialchars($schedule['hijri_date']) ?></p>
        <table class="schedule-table">
            <tr><td>Subuh</td><td><?= htmlspecialchars($schedule['fajr']) ?></td></tr>
            <tr><td>Terbit</td><td><?= htmlspecialchars($schedule['sunrise']) ?></td></tr>
            <tr><td>Dzuhur</td><td><?= htmlspecialchars($schedule['dhuhr']) ?></td></tr>
            <tr><td>Ashar</td><td><?= htmlspecialchars($schedule['asr']) ?></td></tr>
            <tr><td>Maghrib</td><td><?= htmlspecialchars($schedule['maghrib']) ?></td></tr>
            <tr><td>Isya</td><td><?= htmlspecialchars($schedule['isha']) ?></td></tr>
        </table>
        <p class="muted">Sumber: <?= $schedule['source'] === 'online' ? 'Online (AlAdhan)' : 'Lokal (offline)' ?></p>
    </div>

    <div class="card">
        <h2>Status Sistem</h2>
        <p><strong>Masjid:</strong> <?= htmlspecialchars($settings['mosque_name'] ?: '(belum diisi)') ?></p>
        <p><strong>Madzhab:</strong> <?= htmlspecialchars(ucfirst($settings['madzhab'])) ?></p>
        <p><strong>Jarak Adzan-Iqomah:</strong> <?= (int) $settings['azan_iqomah_interval_minutes'] ?> menit</p>
        <p><strong>Sinkronisasi waktu (NTP):</strong> dikelola otomatis oleh sistem operasi (systemd-timesyncd)</p>
        <p><strong>Sync jadwal online terakhir:</strong>
            <?= $settings['last_sync_at'] ? htmlspecialchars($settings['last_sync_at']) . ' UTC' : 'belum pernah' ?>
        </p>
        <?php if ($settings['last_sync_status']): ?>
            <p class="muted"><?= htmlspecialchars($settings['last_sync_status']) ?></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Ringkasan</h2>
        <p><strong>Saldo kas masjid:</strong> Rp <?= number_format($balance, 0, ',', '.') ?></p>
        <p><strong>Deteksi gerakan terbaru:</strong> <?= count($recentMotion) ?> kejadian (5 terakhir)</p>
        <ul class="motion-mini-list">
            <?php foreach ($recentMotion as $m): ?>
                <li><?= htmlspecialchars($m['detected_at']) ?></li>
            <?php endforeach; ?>
            <?php if ($recentMotion === []): ?>
                <li class="muted">Belum ada deteksi gerakan.</li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
