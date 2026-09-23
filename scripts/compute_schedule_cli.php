#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Dipanggil dari alarm_service.py (subprocess) untuk memastikan jadwal hari
 * ini sudah dihitung & tersimpan di DB SEBELUM waktu sholat pertama tiba —
 * penting karena ScheduleEngine biasanya dipicu oleh kunjungan browser ke
 * display.php/api, yang belum tentu terjadi sebelum Subuh.
 *
 * Menjaga logika kalkulasi astronomis tetap di SATU tempat (PHP), supaya
 * hasil yang dipakai alarm & yang ditampilkan di layar selalu identik
 * (lihat catatan konsistensi di src/Schedule/ScheduleSync.php).
 *
 * Usage: php scripts/compute_schedule_cli.php [YYYY-MM-DD]
 * Output: JSON jadwal ke stdout, exit code 0 = sukses, 1 = gagal.
 */

require __DIR__ . '/../bootstrap.php';

use App\Schedule\ScheduleEngine;

$date = $argv[1] ?? gmdate('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "Format tanggal tidak valid, gunakan YYYY-MM-DD.\n");
    exit(1);
}

try {
    $engine = new ScheduleEngine();
    $schedule = $engine->getScheduleForDate($date);
    echo json_encode($schedule, JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Gagal menghitung jadwal: ' . $e->getMessage() . "\n");
    exit(1);
}
