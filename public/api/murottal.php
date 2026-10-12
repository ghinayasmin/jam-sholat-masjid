<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Repository\SettingsRepository;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Hanya baca, tanpa auth (dipakai layar jamaah di LAN). Ditulis oleh layanan alarm.
try {
    $title = (string) (new SettingsRepository())->get()['murottal_now_playing'];
    echo json_encode(['success' => true, 'now_playing' => $title !== '' ? $title : null], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[api/murottal] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'now_playing' => null]);
}
