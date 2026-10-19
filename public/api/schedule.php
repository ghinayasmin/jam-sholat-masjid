<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\LocalTime;
use App\Repository\SettingsRepository;
use App\Schedule\ScheduleEngine;

header('Content-Type: application/json; charset=utf-8');

// REQ-F-010, REQ-F-013: hanya baca, tanpa auth (dipakai layar jamaah di LAN).
$settings = (new SettingsRepository())->get();

// Tanggal bawaan = tanggal lokal masjid, bukan UTC (tanpa ini jam 00:00–07:00 WIB
// masih dianggap kemarin).
$date = $_GET['date'] ?? LocalTime::today();
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Format tanggal tidak valid, gunakan YYYY-MM-DD.']);
    exit;
}

try {
    $engine = new ScheduleEngine();
    $schedule = $engine->getScheduleForDate($date);
    $fastingInfo = $engine->getFastingInfo($date);

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
        // Jeda adzan→iqomah per waktu sholat, dalam menit (DECISIONS.md #30).
        'iqomah_minutes' => [
            'fajr' => (int) $settings['iqomah_fajr_minutes'],
            'dhuhr' => (int) $settings['iqomah_dhuhr_minutes'],
            'asr' => (int) $settings['iqomah_asr_minutes'],
            'maghrib' => (int) $settings['iqomah_maghrib_minutes'],
            'isha' => (int) $settings['iqomah_isha_minutes'],
        ],
        'is_senin_kamis' => $fastingInfo['is_senin_kamis'],
        'is_ayyamul_bidh' => $fastingInfo['is_ayyamul_bidh'],
        'upcoming_fasting' => $engine->getUpcomingFasting($date),
        'mosque_name' => $settings['mosque_name'],
        'mosque_address' => $settings['mosque_address'],
        'reminder' => [
            'enabled' => (int) $settings['reminder_enabled'] === 1,
            'minutes' => (int) $settings['reminder_minutes'],
            'friday_minutes' => (int) $settings['reminder_friday_minutes'],
            'message' => $settings['reminder_message'],
        ],
        'show_finance' => (int) $settings['show_finance_on_display'] === 1,
        // Rotasi slide (poster, kas, foto masjid) — DECISIONS.md #27, #28.
        'slides' => [
            'gap_seconds' => (int) $settings['poster_gap_seconds'],
            'show_seconds' => (int) $settings['poster_show_seconds'],
        ],
        'mosque_photo' => $settings['mosque_photo'] !== ''
            ? '/uploads/publications/' . rawurlencode(basename($settings['mosque_photo']))
            : null,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[api/schedule] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal memuat jadwal sholat.']);
}
