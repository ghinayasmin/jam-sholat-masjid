<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Repository\MotionEventRepository;

header('Content-Type: application/json; charset=utf-8');

// REQ-F-023: hanya admin yang boleh melihat rekaman kamera.
$auth = new AuthManager();
$auth->startSession();
if (!$auth->isLoggedIn() || $auth->mustChangePassword()) { // kata sandi bawaan belum diganti (#41)
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Butuh login admin.']);
    exit;
}

try {
    $date = $_GET['date'] ?? null;
    if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = null;
    }
    $items = (new MotionEventRepository())->list($date, 200);

    $out = array_map(static function (array $e): array {
        return [
            'id' => (int) $e['id'],
            'detected_at' => $e['detected_at'],
            'image_url' => '/admin/capture.php?id=' . (int) $e['id'],
            'reviewed' => (bool) $e['reviewed'],
        ];
    }, $items);

    echo json_encode(['success' => true, 'items' => $out], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[api/motion_events] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal memuat rekaman kamera.']);
}
