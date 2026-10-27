<?php

declare(strict_types=1);

namespace App\Auth;

use App\Config;
use App\Repository\AdminUserRepository;

/**
 * REQ-F-070 (Admin Authentication), REQ-NF-121 (password harus di-hash).
 * Satu akun admin per instalasi (lihat asumsi #4 di analisis SRS).
 */
final class AuthManager
{
    private bool $mustChange = false;

    public function __construct(
        private readonly AdminUserRepository $users = new AdminUserRepository()
    ) {
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $path = Config::get('session_path');
        if ($path !== null && is_dir($path)) {
            session_save_path($path);
        }
        session_name('jamsholat_admin');
        // Pengurus membuka panel dari HP: sesi bertahan selama admin_session_days
        // (bawaan 30 hari) agar tidak perlu login setiap kali. Lihat DECISIONS.md #16.
        ini_set('session.gc_maxlifetime', (string) self::sessionLifetime());
        session_set_cookie_params([
            'lifetime' => self::sessionLifetime(),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public function attempt(string $username, string $password): bool
    {
        $user = $this->users->findByUsername($username);
        if ($user === null) {
            // Tetap panggil password_verify dengan hash dummy agar waktu respons
            // konsisten antara "user tidak ada" dan "password salah" (mitigasi
            // user-enumeration timing attack, praktik keamanan standar).
            password_verify($password, '$2y$10$abcdefghijklmnopqrstuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuu');
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        $this->regenerateSession();
        $_SESSION['admin_id'] = (int) $user['id'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['last_activity'] = time();
        $_SESSION['password_tag'] = self::passwordTag($user['password_hash']);
        $this->users->touchLogin((int) $user['id']);

        return true;
    }

    public function isLoggedIn(): bool
    {
        if (!isset($_SESSION['admin_id'])) {
            return false;
        }
        $timeout = self::sessionLifetime();
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
            $this->logout();
            return false;
        }
        // Kata sandi diganti atau direset ke bawaan (DECISIONS.md #41): sesi lama di HP lain
        // ikut keluar. Sesi dari sebelum v1.1.3 belum punya tanda ini, jadi tandanya diisi.
        $user = $this->users->findById((int) $_SESSION['admin_id']);
        $tag = $user === null ? null : self::passwordTag($user['password_hash']);
        if ($tag === null || ($_SESSION['password_tag'] ?? $tag) !== $tag) {
            $this->logout();
            return false;
        }
        $_SESSION['password_tag'] = $tag;
        $_SESSION['last_activity'] = time();
        $this->mustChange = (int) $user['must_change_password'] === 1;
        return true;
    }

    /** Masih memakai kata sandi bawaan (admin / 000): panel belum boleh dipakai. */
    public function mustChangePassword(): bool
    {
        return $this->mustChange;
    }

    /** Setelah takmir mengganti kata sandinya sendiri, sesi ini tetap masuk. */
    public function passwordChanged(string $newHash): void
    {
        $_SESSION['password_tag'] = self::passwordTag($newHash);
        $this->mustChange = false;
    }

    private static function passwordTag(string $hash): string
    {
        return substr(hash('sha256', $hash), 0, 16);
    }

    public function currentUsername(): ?string
    {
        return $_SESSION['admin_username'] ?? null;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private static function sessionLifetime(): int
    {
        return (int) Config::get('admin_session_days', 30) * 86400;
    }

    private function regenerateSession(): void
    {
        session_regenerate_id(true);
    }
}
