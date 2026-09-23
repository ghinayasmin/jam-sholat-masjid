<?php

declare(strict_types=1);

namespace App\Repository;

use App\Db\Database;

/** REQ-F-050, REQ-F-051. */
final class BackupRepository
{
    public function list(): array
    {
        return Database::connection()
            ->query('SELECT * FROM backup_records ORDER BY created_at DESC')
            ->fetchAll();
    }

    public function create(string $fileName, string $filePath, int $sizeBytes, string $type): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO backup_records (file_name, file_path, size_bytes, type)
             VALUES (:fn, :fp, :sz, :t)'
        );
        $stmt->execute(['fn' => $fileName, 'fp' => $filePath, 'sz' => $sizeBytes, 't' => $type]);
        return (int) Database::connection()->lastInsertId();
    }
}
