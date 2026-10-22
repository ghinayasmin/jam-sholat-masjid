<?php

declare(strict_types=1);

namespace App\Repository;

use App\Db\Database;
use PDO;

/**
 * REQ-F-020, REQ-F-021, REQ-F-026, REQ-F-030, REQ-F-044.
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
     * Kolom yang diisi langsung oleh layanan alarm (status saat ini), bukan pengaturan takmir:
     * tidak ikut dipulihkan dari cadangan. id dan updated_at diatur sendiri.
     */
    private const RUNTIME_COLUMNS = [
        'id', 'updated_at', 'murottal_now_playing', 'murottal_request', 'alarm_now_playing',
        'alarm_heartbeat', 'alarm_audio_status',
        'last_sync_at', 'last_sync_status',
    ];

    /** @var \WeakMap<PDO, list<string>>|null daftar kolom per koneksi (test berganti database) */
    private static ?\WeakMap $columns = null;

    /**
     * Semua kolom mosque_settings, dibaca dari tabel. Kolom baru cukup ditambahkan di
     * schema.sql dan Database::ADDED_COLUMNS; update() dan pemulihan cadangan ikut otomatis.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        $pdo = Database::connection();
        self::$columns ??= new \WeakMap();
        if (!isset(self::$columns[$pdo])) {
            self::$columns[$pdo] = array_column($pdo->query('PRAGMA table_info(mosque_settings)')->fetchAll(), 'name');
        }
        return self::$columns[$pdo];
    }

    /** Kolom pengaturan yang ikut dicadangkan dan dipulihkan. @return list<string> */
    public static function settingColumns(): array
    {
        return array_values(array_diff(self::columns(), self::RUNTIME_COLUMNS));
    }

    /**
     * Update parsial — hanya kolom yang ada di $fields yang diubah. Nama kolom yang
     * tidak ada di tabel diabaikan (bukan dirangkai ke SQL).
     */
    public function update(array $fields): void
    {
        $allowed = array_diff(self::columns(), ['id', 'updated_at']);
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
