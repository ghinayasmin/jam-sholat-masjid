<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;

/**
 * Akun bawaan seperti pada modem atau DVR CCTV (revisi dosen 27 Okt 2026, DECISIONS.md #41).
 * Instalasi baru langsung punya akun admin / 000 yang wajib diganti saat pertama masuk.
 * Lupa kata sandi: kembalikan ke akun bawaan dari perangkat itu sendiri
 * (scripts/reset_password_cli.php). Nama dan kata sandi ini disebut di Panduan Pengguna.
 */
final class DefaultAccount
{
    public const USERNAME = 'admin';
    public const PASSWORD = '000';

    /** Dipanggil Database::migrate: buat akun bawaan bila belum ada akun sama sekali. */
    public static function ensure(PDO $pdo): void
    {
        if ((int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() > 0) {
            return;
        }
        // OR IGNORE: dua permintaan pertama yang datang bersamaan tidak saling menggagalkan.
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO admin_users (username, password_hash, must_change_password) VALUES (:u, :p, 1)'
        );
        $stmt->execute(['u' => self::USERNAME, 'p' => password_hash(self::PASSWORD, PASSWORD_BCRYPT)]);
    }

    /**
     * Kembalikan akun ke admin / 000 dan wajibkan ganti kata sandi. Data masjid tidak
     * disentuh. Akun bernama admin yang direset; bila tidak ada (instalasi lama dengan
     * nama lain), akun pertama yang diganti namanya menjadi admin.
     *
     * @return string nama akun sebelum direset
     */
    public static function reset(PDO $pdo): string
    {
        self::ensure($pdo);
        $row = $pdo->query(
            "SELECT id, username FROM admin_users ORDER BY username = 'admin' DESC, id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare(
            'UPDATE admin_users SET username = :u, password_hash = :p, must_change_password = 1 WHERE id = :id'
        );
        $stmt->execute([
            'u' => self::USERNAME,
            'p' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'id' => (int) $row['id'],
        ]);
        return (string) $row['username'];
    }
}
