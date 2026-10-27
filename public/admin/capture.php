<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Config;
use App\Db\Database;

// REQ-F-023: gambar hanya boleh diakses admin yang sudah login — file fisik
// TIDAK diletakkan di public/ agar tidak bisa diakses langsung tanpa auth.
$auth = new AuthManager();
$auth->startSession();
if (!$auth->isLoggedIn() || $auth->mustChangePassword()) { // kata sandi bawaan belum diganti (#41)
    http_response_code(401);
    exit('Unauthorized');
}

$id = (int) ($_GET['id'] ?? 0);
$stmt = Database::connection()->prepare('SELECT image_path FROM motion_events WHERE id = :id');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();

if ($row === false) {
    http_response_code(404);
    exit('Not found');
}

$capturesPath = (string) Config::get('captures_path');
$fullPath = $capturesPath . '/' . basename($row['image_path']);

if (!is_file($fullPath)) {
    http_response_code(404);
    exit('File not found');
}

header('Content-Type: image/jpeg');
// Jangan simpan di cache browser: foto yang sudah dihapus tidak boleh masih terbuka dari HP.
header('Cache-Control: no-store, private');
readfile($fullPath);
