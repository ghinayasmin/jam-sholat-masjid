<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Repository\PublicationRepository;

header('Content-Type: application/json; charset=utf-8');

try {
    $items = (new PublicationRepository())->allActive();
    $out = array_map(static function (array $p): array {
        return [
            'id' => (int) $p['id'],
            'url' => '/uploads/publications/' . rawurlencode(basename($p['file_path'])),
            'caption' => $p['caption'],
            'order' => (int) $p['display_order'],
        ];
    }, $items);

    echo json_encode(['success' => true, 'items' => $out], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[api/publications] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal memuat publikasi.']);
}
