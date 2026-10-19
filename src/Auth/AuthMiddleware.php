<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * REQ-F-071: Restricted Settings Access. Di-include di baris pertama
 * setiap file public/admin/*.php kecuali login.php.
 */
final class AuthMiddleware
{
    public static function requireLogin(AuthManager $auth): void
    {
        $auth->startSession();
        if (!$auth->isLoggedIn()) {
            // Instalasi baru tanpa akun: arahkan ke pembuatan akun pertama (DECISIONS.md #39).
            $setupNeeded = (new \App\Repository\AdminUserRepository())->count() === 0;
            header('Location: ' . ($setupNeeded ? '/admin/setup.php' : '/admin/login.php'));
            exit;
        }
    }
}
