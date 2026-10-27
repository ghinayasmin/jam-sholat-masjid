<?php

declare(strict_types=1);

namespace App\Repository;

use App\Db\Database;

/** REQ-F-070, REQ-F-071, REQ-F-072. */
final class AdminUserRepository
{
    public function findByUsername(string $username): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM admin_users WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM admin_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(string $username, string $passwordHash): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO admin_users (username, password_hash) VALUES (:u, :p)'
        );
        $stmt->execute(['u' => $username, 'p' => $passwordHash]);
        return (int) Database::connection()->lastInsertId();
    }

    /** Kata sandi baru dari takmir sendiri, jadi akun tidak lagi wajib ganti kata sandi. */
    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE admin_users SET password_hash = :p, must_change_password = 0 WHERE id = :id'
        );
        $stmt->execute(['p' => $passwordHash, 'id' => $id]);
    }

    public function touchLogin(int $id): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE admin_users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }
}
