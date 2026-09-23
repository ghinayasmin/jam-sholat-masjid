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

    public function create(string $fileName, string $filePath, ?string $caption): int
    {
        $nextOrder = (int) Database::connection()
            ->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM publications')
            ->fetchColumn();

        $stmt = Database::connection()->prepare(
            'INSERT INTO publications (file_name, file_path, caption, display_order, is_active)
             VALUES (:fn, :fp, :cap, :ord, 1)'
        );
        $stmt->execute(['fn' => $fileName, 'fp' => $filePath, 'cap' => $caption, 'ord' => $nextOrder]);
        return (int) Database::connection()->lastInsertId();
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
