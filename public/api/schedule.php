<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Repository\SettingsRepository;
use App\Schedule\ScheduleEngine;

header('Content-Type: application/json; charset=utf-8');

// REQ-F-010, REQ-F-013: hanya baca, tanpa auth (dipakai layar jamaah di LAN).
$date = $_GET['date'] ?? gmdate('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Format tanggal tidak valid, gunakan YYYY-MM-DD.']);
    exit;
}

try {
    $engine = new ScheduleEngine();
    $schedule = $engine->getScheduleForDate($date);
    $fastingInfo = $engine->getFastingInfo($date);
    $settings = (new SettingsRepository())->get();

    echo json_encode([
        'success' => true,
        'date' => $date,
        'hijri_date' => $schedule['hijri_date'],
        'fajr' => $schedule['fajr'],
        'sunrise' => $schedule['sunrise'],
        'dhuhr' => $schedule['dhuhr'],
        'asr' => $schedule['asr'],
        'maghrib' => $schedule['maghrib'],
        'isha' => $schedule['isha'],
        'source' => $schedule['source'],
        'azan_iqomah_interval_minutes' => (int) $settings['azan_iqomah_interval_minutes'],
        'is_senin_kamis' => $fastingInfo['is_senin_kamis'],
        'is_ayyamul_bidh' => $fastingInfo['is_ayyamul_bidh'],
        'mosque_name' => $settings['mosque_name'],
        'mosque_address' => $settings['mosque_address'],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[api/schedule] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal memuat jadwal sholat.']);
}
