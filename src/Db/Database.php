<?php

declare(strict_types=1);

namespace App\Db;

use App\Config;
use PDO;
use PDOException;

/**
 * Wrapper PDO_SQLITE tunggal (singleton) dengan WAL mode diaktifkan
 * agar PHP (read/write) dan Python motion_detect.py (write) bisa
 * mengakses file database yang sama secara bersamaan tanpa lock
 * berkepanjangan (REQ-F-043, arsitektur data flow).
 */
final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $dbPath = Config::get('db_path');

            $dsn = 'sqlite:' . $dbPath;
            try {
                $pdo = new PDO($dsn);
            } catch (PDOException $e) {
                // REQ-NF-112: graceful error handling — jangan biarkan
                // seluruh sistem berhenti tanpa pesan yang jelas.
                throw new PDOException(
                    'Tidak dapat membuka database di ' . $dbPath . ': ' . $e->getMessage()
                );
            }

            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');

            self::migrate($pdo);

            self::$instance = $pdo;
        }

        return self::$instance;
    }

    /**
     * Kolom yang ditambahkan setelah rilis awal, per tabel. Database yang sudah
     * terpasang (misalnya di Pi) dibuat dari schema.sql versi lama, jadi kolom ini
     * ditambahkan di sini bila belum ada. Definisinya harus sama dengan schema.sql.
     */
    private const ADDED_COLUMNS = [
        'mosque_settings' => [
            'reminder_enabled' => 'INTEGER NOT NULL DEFAULT 1 CHECK (reminder_enabled IN (0,1))',
            'reminder_minutes' => 'INTEGER NOT NULL DEFAULT 10',
            'reminder_friday_minutes' => 'INTEGER NOT NULL DEFAULT 30',
            'reminder_message' => "TEXT NOT NULL DEFAULT 'Mari bersiap dan mengambil air wudhu'",
            'show_finance_on_display' => 'INTEGER NOT NULL DEFAULT 0 CHECK (show_finance_on_display IN (0,1))',
            'location_name' => "TEXT NOT NULL DEFAULT ''",
            'murottal_enabled' => 'INTEGER NOT NULL DEFAULT 0 CHECK (murottal_enabled IN (0,1))',
            'murottal_now_playing' => "TEXT NOT NULL DEFAULT ''",
            'murottal_request' => "TEXT NOT NULL DEFAULT ''",
            'adzan_sound_enabled' => 'INTEGER NOT NULL DEFAULT 1 CHECK (adzan_sound_enabled IN (0,1))',
            'iqomah_sound_enabled' => 'INTEGER NOT NULL DEFAULT 0 CHECK (iqomah_sound_enabled IN (0,1))',
            'hijri_offset_days' => 'INTEGER NOT NULL DEFAULT 0 CHECK (hijri_offset_days BETWEEN -2 AND 2)',
        ],
        'publications' => [
            'starts_on' => 'TEXT',
            'ends_on' => 'TEXT',
        ],
    ];

    private static function migrate(PDO $pdo): void
    {
        foreach (self::ADDED_COLUMNS as $table => $columns) {
            $existing = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
            if ($existing === []) {
                continue; // tabel belum dibuat (schema.sql belum dijalankan)
            }
            foreach ($columns as $column => $definition) {
                if (!in_array($column, $existing, true)) {
                    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
                }
            }
        }
    }

    /** Untuk testing: paksa reset koneksi supaya bisa connect ke DB lain. */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
