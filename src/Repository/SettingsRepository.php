<?php

declare(strict_types=1);

namespace App\Repository;

use App\Db\Database;
use PDO;

/**
 * REQ-F-020, REQ-F-021, REQ-F-025, REQ-F-026, REQ-F-044.
 * mosque_settings selalu berupa satu baris (id=1).
 */
final class SettingsRepository
{
    public function get(): array
    {
        $stmt = Database::connection()->query('SELECT * FROM mosque_settings WHERE id = 1');
        $row = $stmt->fetch();
        if ($row === false) {
            // Seharusnya tidak pernah terjadi (schema.sql sudah seed baris ini),
            // tapi kita jaga-jaga agar sistem tidak crash — REQ-NF-112.
            Database::connection()->exec('INSERT OR IGNORE INTO mosque_settings (id) VALUES (1)');
            $row = Database::connection()->query('SELECT * FROM mosque_settings WHERE id = 1')->fetch();
        }
        return $row;
    }

    /**
     * Update parsial — hanya kolom yang ada di $fields yang diubah.
     * $fields harus subset dari kolom valid mosque_settings (whitelist di caller).
     */
    public function update(array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $allowed = [
            'mosque_name', 'mosque_address', 'latitude', 'longitude',
            'timezone_offset_hours', 'madzhab', 'location_offset_minutes',
            'azan_iqomah_interval_minutes', 'image_retention_days',
            'image_retention_storage_pct', 'online_sync_enabled',
            'last_sync_at', 'last_sync_status',
            'reminder_enabled', 'reminder_minutes', 'reminder_friday_minutes',
            'reminder_message', 'show_finance_on_display', 'location_name',
        ];
        $set = [];
        $params = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue; // abaikan field tak dikenal — jangan biarkan mass-assignment sembarang
            }
            $set[] = "$key = :$key";
            $params[$key] = $value;
        }
        if ($set === []) {
            return;
        }
        $set[] = 'updated_at = CURRENT_TIMESTAMP';
        $sql = 'UPDATE mosque_settings SET ' . implode(', ', $set) . ' WHERE id = 1';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }
}
