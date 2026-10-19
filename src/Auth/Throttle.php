<?php

declare(strict_types=1);

namespace App\Auth;

use App\Db\Database;

/**
 * Batas percobaan gagal per alamat IP untuk login dan pemulihan kata sandi
 * (DECISIONS.md #33). Disimpan di tabel auth_failures; baris lama dibuang otomatis.
 */
final class Throttle
{
    public function __construct(
        private readonly string $action,
        private readonly int $maxFailures,
        private readonly int $windowSeconds = 900
    ) {
    }

    /** Detik yang harus ditunggu sebelum boleh mencoba lagi; 0 = boleh sekarang. */
    public function waitSeconds(string $ip): int
    {
        $pdo = Database::connection();
        $since = time() - $this->windowSeconds;
        $pdo->prepare('DELETE FROM auth_failures WHERE failed_at < :t')->execute(['t' => $since]);
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS n, MIN(failed_at) AS first FROM auth_failures
             WHERE action = :a AND ip = :ip AND failed_at >= :t'
        );
        $stmt->execute(['a' => $this->action, 'ip' => $ip, 't' => $since]);
        $row = $stmt->fetch();
        if ((int) $row['n'] < $this->maxFailures) {
            return 0;
        }
        return max(1, (int) $row['first'] + $this->windowSeconds - time());
    }

    public function recordFailure(string $ip): void
    {
        Database::connection()
            ->prepare('INSERT INTO auth_failures (action, ip, failed_at) VALUES (:a, :ip, :t)')
            ->execute(['a' => $this->action, 'ip' => $ip, 't' => time()]);
    }

    public function clear(string $ip): void
    {
        Database::connection()
            ->prepare('DELETE FROM auth_failures WHERE action = :a AND ip = :ip')
            ->execute(['a' => $this->action, 'ip' => $ip]);
    }

    /** "3 menit" untuk pesan ke pengguna. */
    public static function minutesLabel(int $seconds): string
    {
        return max(1, (int) ceil($seconds / 60)) . ' menit';
    }
}
