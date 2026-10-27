<?php

declare(strict_types=1);

/**
 * Bootstrap tunggal untuk seluruh entry point (public/*.php).
 * Tidak memakai Composer secara sengaja: device sering berjalan offline
 * (Scope §1.2 SRS), jadi autoload PSR-4 manual menghindari dependency
 * pada `composer install` saat provisioning. Lihat docs/DECISIONS.md.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require_once __DIR__ . '/src/Config.php';

use App\Config;

date_default_timezone_set('UTC'); // Waktu sholat dihitung manual dengan offset lokasi;
                                    // UTC dipakai sebagai basis internal yang konsisten.

// REQ-NF-112 (graceful error handling): jangan tampilkan stack trace mentah ke jamaah/takmir,
// tapi tetap log ke error_log server untuk debugging developer.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(static function (Throwable $e): void {
    error_log('[UNCAUGHT] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/') || str_contains($_SERVER['REQUEST_URI'] ?? '', '/admin/')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Terjadi kesalahan pada server.']);
    } else {
        echo '<h1>Terjadi kesalahan</h1><p>Sistem sedang mengalami gangguan. Silakan coba lagi.</p>';
    }
});

// Pastikan direktori storage yang dibutuhkan tersedia (aman dipanggil berkali-kali).
foreach (['db_path', 'publications_path', 'captures_path', 'backups_path', 'session_path'] as $key) {
    $path = Config::get($key);
    if ($path === null) {
        continue;
    }
    $dir = str_ends_with((string) $key, '_path') && str_ends_with((string) $path, '.db')
        ? dirname((string) $path)
        : (string) $path;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}
