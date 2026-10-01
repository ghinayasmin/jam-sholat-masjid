<?php

declare(strict_types=1);

namespace App\Repository;

use App\Db\Database;

/** REQ-F-011, REQ-F-022. */
final class PublicationRepository
{
    public function allActive(): array
    {
        return Database::connection()
            ->query('SELECT * FROM publications WHERE is_active = 1 ORDER BY display_order ASC, id ASC')
            ->fetchAll();
    }

    /** Pengumuman yang tampil di layar jamaah pada tanggal $date (YYYY-MM-DD). */
    public function showingOn(string $date): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM publications
             WHERE is_active = 1
               AND (starts_on IS NULL OR starts_on <= :d1)
               AND (ends_on IS NULL OR ends_on >= :d2)
             ORDER BY display_order ASC, id ASC'
        );
        $stmt->execute(['d1' => $date, 'd2' => $date]);
        return $stmt->fetchAll();
    }

    /**
     * Semua pengumuman dikelompokkan untuk panel takmir:
     * 'showing' (sedang tampil), 'scheduled' (mulai nanti), 'finished' (lewat/nonaktif).
     */
    public function groupedFor(string $today): array
    {
        $groups = ['showing' => [], 'scheduled' => [], 'finished' => []];
        foreach ($this->all() as $p) {
            if ((int) $p['is_active'] !== 1 || ($p['ends_on'] !== null && $p['ends_on'] < $today)) {
                $groups['finished'][] = $p;
            } elseif ($p['starts_on'] !== null && $p['starts_on'] > $today) {
                $groups['scheduled'][] = $p;
            } else {
                $groups['showing'][] = $p;
            }
        }
        return $groups;
    }

    public function all(): array
    {
        return Database::connection()
            ->query('SELECT * FROM publications ORDER BY display_order ASC, id ASC')
            ->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM publications WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * $fileName/$filePath kosong ('') untuk pengumuman teks saja.
     * $startsOn/$endsOn: YYYY-MM-DD atau null (tanpa batas).
     */
    public function create(
        string $fileName,
        string $filePath,
        ?string $caption,
        ?string $startsOn = null,
        ?string $endsOn = null
    ): int {
        $nextOrder = (int) Database::connection()
            ->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM publications')
            ->fetchColumn();

        $stmt = Database::connection()->prepare(
            'INSERT INTO publications (file_name, file_path, caption, display_order, is_active, starts_on, ends_on)
             VALUES (:fn, :fp, :cap, :ord, 1, :start, :end)'
        );
        $stmt->execute([
            'fn' => $fileName, 'fp' => $filePath, 'cap' => $caption, 'ord' => $nextOrder,
            'start' => $startsOn, 'end' => $endsOn,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    /** Pasang ulang pengumuman lama: aktifkan lagi mulai hari ini tanpa batas akhir. */
    public function republish(int $id, string $today): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE publications SET is_active = 1, starts_on = :today, ends_on = NULL WHERE id = :id'
        );
        $stmt->execute(['today' => $today, 'id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = Database::connection()->prepare('UPDATE publications SET is_active = :a WHERE id = :id');
        $stmt->execute(['a' => $active ? 1 : 0, 'id' => $id]);
    }

    public function reorder(array $orderedIds): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE publications SET display_order = :ord WHERE id = :id');
            foreach ($orderedIds as $index => $id) {
                $stmt->execute(['ord' => $index, 'id' => (int) $id]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM publications WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
