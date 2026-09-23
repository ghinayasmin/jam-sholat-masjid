<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\Csrf;

$auth = new AuthManager();
$auth->startSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $auth->logout();
}

header('Location: /admin/login.php');
exit;
