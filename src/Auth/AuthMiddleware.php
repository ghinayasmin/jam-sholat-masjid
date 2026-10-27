<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * REQ-F-071: Restricted Settings Access. Di-include di baris pertama
 * setiap file public/admin/*.php kecuali login.php.
 */
final class AuthMiddleware
{
    /**
     * $allowDefaultPassword hanya untuk halaman ganti kata sandi bawaan: selama akun
     * masih admin / 000, halaman lain diarahkan ke sana (DECISIONS.md #41).
     */
    public static function requireLogin(AuthManager $auth, bool $allowDefaultPassword = false): void
    {
        $auth->startSession();
        if (!$auth->isLoggedIn()) {
            header('Location: /admin/login.php');
            exit;
        }
        if ($auth->mustChangePassword() && !$allowDefaultPassword) {
            header('Location: /admin/change_password.php');
            exit;
        }
    }
}
