<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Repository\SettingsRepository;

// Tombol "Coba putar suara" (DECISIONS.md #43): layanan alarm membaca permintaan ini dalam
// satu detik lalu memutar awal suara adzan selama 10 detik dari speaker masjid.
$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$back = [
    'home' => '/admin/dashboard.php',
    'sound' => '/admin/sound.php',
    'welcome' => '/admin/welcome.php?langkah=3',
][$_POST['back'] ?? ''] ?? '/admin/dashboard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $repo = new SettingsRepository();
    // Sama dengan "Pemutar adzan" di Beranda: tanda hidup ditulis tiap menit.
    $beat = (int) $repo->get()['alarm_heartbeat'];
    if ($beat === 0 || time() - $beat > 600) {
        $_SESSION['flash_error'] = 'Suara tidak dapat diputar karena pemutar adzan sedang tidak aktif. Pastikan perangkat menyala dan sudah masuk ke akun pengguna.';
    } else {
        $repo->update(['sound_test_request' => time()]);
        $_SESSION['flash_success'] = 'Awal suara adzan akan diputar selama 10 detik. Bila tidak terdengar, periksa sambungan speaker dan volumenya.';
    }
}
header('Location: ' . $back);
exit;
