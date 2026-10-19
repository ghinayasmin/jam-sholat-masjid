<?php

declare(strict_types=1);

namespace App\Repository;

use App\Db\Database;

/** REQ-F-012, REQ-F-024. */
final class FinancialRepository
{
    /** Entries terbaru dulu, dibatasi $limit untuk tampilan jamaah/dashboard. */
    public function recent(int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM financial_entries ORDER BY entry_date DESC, id DESC LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function all(): array
    {
        return Database::connection()
            ->query('SELECT * FROM financial_entries ORDER BY entry_date DESC, id DESC')
            ->fetchAll();
    }

    /** Saldo berjalan = total masuk - total keluar, dari seluruh histori. */
    public function currentBalance(): int
    {
        $row = Database::connection()->query(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'masuk' THEN amount ELSE 0 END), 0) AS masuk,
                COALESCE(SUM(CASE WHEN type = 'keluar' THEN amount ELSE 0 END), 0) AS keluar
             FROM financial_entries"
        )->fetch();
        return (int) $row['masuk'] - (int) $row['keluar'];
    }

    /** Total masuk & keluar untuk satu bulan ('YYYY-MM'). */
    public function monthTotals(string $yearMonth): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'masuk' THEN amount ELSE 0 END), 0) AS masuk,
                COALESCE(SUM(CASE WHEN type = 'keluar' THEN amount ELSE 0 END), 0) AS keluar
             FROM financial_entries WHERE substr(entry_date, 1, 7) = :ym"
        );
        $stmt->execute(['ym' => $yearMonth]);
        $row = $stmt->fetch();
        return ['masuk' => (int) $row['masuk'], 'keluar' => (int) $row['keluar']];
    }

    /** Tanggal transaksi terakhir ('YYYY-MM-DD'), null bila belum ada transaksi. */
    public function lastEntryDate(): ?string
    {
        $date = Database::connection()->query('SELECT MAX(entry_date) FROM financial_entries')->fetchColumn();
        return $date === false || $date === null ? null : (string) $date;
    }

    public function create(string $entryDate, string $description, string $type, int $amount, ?int $createdBy): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO financial_entries (entry_date, description, type, amount, created_by)
             VALUES (:d, :desc, :t, :a, :by)'
        );
        $stmt->execute([
            'd' => $entryDate, 'desc' => $description, 't' => $type, 'a' => $amount, 'by' => $createdBy,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM financial_entries WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Perbaiki catatan yang salah ketik (jenis masuk/keluar tidak berubah). */
    public function update(int $id, string $entryDate, string $description, int $amount): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE financial_entries SET entry_date = :d, description = :desc, amount = :a WHERE id = :id'
        );
        $stmt->execute(['d' => $entryDate, 'desc' => $description, 'a' => $amount, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM financial_entries WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
