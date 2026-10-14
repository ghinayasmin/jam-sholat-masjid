<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Repository\SettingsRepository;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Hanya baca, tanpa auth (dipakai layar jamaah di LAN). Ditulis oleh layanan alarm.
// Judul hanya dikirim selama sakelar Putar menyala, jadi baris Murottal di layar
// langsung hilang saat dijeda walau layanan alarm belum sempat mengosongkannya.
try {
    $s = (new SettingsRepository())->get();
    $title = (int) $s['murottal_enabled'] === 1 ? (string) $s['murottal_now_playing'] : '';
    echo json_encode(['success' => true, 'now_playing' => $title !== '' ? $title : null], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[api/murottal] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'now_playing' => null]);
}
