<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\LocalTime;
use App\SystemClock;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

/**
 * REQ-F-061: cocokkan jam perangkat dengan jam HP pengurus setelah listrik
 * padam tanpa internet. Dipanggil otomatis oleh admin.js saat halaman panel
 * dibuka dan jam perangkat belum terpercaya (lihat App\SystemClock).
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

if (SystemClock::isTrusted()) {
    echo json_encode(['success' => true, 'changed' => false]);
    exit;
}

// Detik UTC dari HP. Bukan milidetik: PHP di Raspberry Pi OS 32-bit (PHP_INT_MAX
// ≈ 2,1 miliar), jadi milidetik meluap dan selalu dianggap tidak valid.
$clientEpoch = $_POST['epoch'] ?? '';
if (!ctype_digit((string) $clientEpoch)) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

// Jam HP di luar 2024–2037 hampir pasti salah; jangan ikuti. Dibandingkan sebagai
// float sebelum diubah ke int, karena int 32-bit mentok di 2147483647 (tahun 2038).
if ((float) $clientEpoch < 1704067200 || (float) $clientEpoch > 2147483647) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}
$clientEpoch = (int) $clientEpoch;

$before = LocalTime::now();
if (abs($clientEpoch - time()) <= SystemClock::TOLERANCE_SECONDS) {
    SystemClock::markSet();
    echo json_encode(['success' => true, 'changed' => false]);
    exit;
}

if (!SystemClock::set($clientEpoch)) {
    http_response_code(500);
    echo json_encode(['success' => false]);
    exit;
}

$_SESSION['flash_success'] = 'Jam perangkat dicocokkan dengan jam HP: ' . gmdate('H:i', $before)
    . ' menjadi ' . gmdate('H:i', LocalTime::now()) . '.';
echo json_encode(['success' => true, 'changed' => true]);
