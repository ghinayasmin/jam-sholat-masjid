<?php

declare(strict_types=1);

namespace App\Repository;

use App\Db\Database;

/** REQ-F-023, REQ-F-041, REQ-F-042, REQ-F-044. */
final class MotionEventRepository
{
    /** Dipakai admin panel (REQ-F-023), opsional filter tanggal (YYYY-MM-DD). */
    public function list(?string $date = null, int $limit = 100): array
    {
        if ($date !== null) {
            $stmt = Database::connection()->prepare(
                "SELECT * FROM motion_events WHERE date(detected_at) = :d
                 ORDER BY detected_at DESC LIMIT :lim"
            );
            $stmt->bindValue(':d', $date);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        } else {
            $stmt = Database::connection()->prepare(
                'SELECT * FROM motion_events ORDER BY detected_at DESC LIMIT :lim'
            );
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function markReviewed(int $id): void
    {
        $stmt = Database::connection()->prepare('UPDATE motion_events SET reviewed = 1 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** Dipakai retention_cleanup.py lewat sqlite3 langsung, dan berguna untuk test PHP. */
    public function olderThan(string $cutoffDatetime): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM motion_events WHERE detected_at < :cutoff ORDER BY detected_at ASC'
        );
        $stmt->execute(['cutoff' => $cutoffDatetime]);
        return $stmt->fetchAll();
    }

    public function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM motion_events WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
