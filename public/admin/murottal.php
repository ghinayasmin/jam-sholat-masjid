<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Repository\SettingsRepository;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

// Tombol Putar/Jeda murottal di Beranda. Layanan alarm membaca sakelar ini tiap 3 detik.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $play = ($_POST['action'] ?? '') === 'play';
    (new SettingsRepository())->update(['murottal_enabled' => $play ? 1 : 0]);
    $_SESSION['flash_success'] = $play
        ? 'Murottal diputar dalam beberapa detik.'
        : 'Murottal dijeda.';
}
header('Location: /admin/');
exit;
