<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Proteksi CSRF untuk semua form admin — bukan requirement eksplisit SRS,
 * ditambahkan sebagai praktik keamanan standar (lihat Assumption Log di
 * analisis SRS). Butuh session aktif (panggil setelah AuthManager::startSession()).
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    public static function verify(?string $submitted): bool
    {
        if (empty($_SESSION['csrf_token']) || $submitted === null) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $submitted);
    }

    public static function requireValid(?string $submitted): void
    {
        if (!self::verify($submitted)) {
            http_response_code(419);
            die('Sesi form kedaluwarsa atau tidak valid. Silakan muat ulang halaman dan coba lagi.');
        }
    }
}
