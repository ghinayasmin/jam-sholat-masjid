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
                // REQ-NF-112 (graceful error handling): jangan biarkan
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
            'murottal_position' => "TEXT NOT NULL DEFAULT ''",
            'alarm_now_playing' => "TEXT NOT NULL DEFAULT ''",
            'alarm_heartbeat' => 'INTEGER NOT NULL DEFAULT 0',
            'alarm_audio_status' => "TEXT NOT NULL DEFAULT ''",
            'sound_test_request' => 'INTEGER NOT NULL DEFAULT 0',
            'adzan_sound_enabled' => 'INTEGER NOT NULL DEFAULT 1 CHECK (adzan_sound_enabled IN (0,1))',
            'iqomah_sound_enabled' => 'INTEGER NOT NULL DEFAULT 0 CHECK (iqomah_sound_enabled IN (0,1))',
            'hijri_offset_days' => 'INTEGER NOT NULL DEFAULT 0 CHECK (hijri_offset_days BETWEEN -2 AND 2)',
            'poster_gap_seconds' => 'INTEGER NOT NULL DEFAULT 15',
            'poster_show_seconds' => 'INTEGER NOT NULL DEFAULT 15',
            'mosque_photo' => "TEXT NOT NULL DEFAULT ''",
            'iqomah_fajr_minutes' => 'INTEGER NOT NULL DEFAULT 20',
            'iqomah_dhuhr_minutes' => 'INTEGER NOT NULL DEFAULT 10',
            'iqomah_asr_minutes' => 'INTEGER NOT NULL DEFAULT 10',
            'iqomah_maghrib_minutes' => 'INTEGER NOT NULL DEFAULT 10',
            'iqomah_isha_minutes' => 'INTEGER NOT NULL DEFAULT 10',
            'iqomah_countdown_enabled' => 'INTEGER NOT NULL DEFAULT 1 CHECK (iqomah_countdown_enabled IN (0,1))',
            'adjust_fajr_minutes' => 'INTEGER NOT NULL DEFAULT 0',
            'adjust_dhuhr_minutes' => 'INTEGER NOT NULL DEFAULT 0',
            'adjust_asr_minutes' => 'INTEGER NOT NULL DEFAULT 0',
            'adjust_maghrib_minutes' => 'INTEGER NOT NULL DEFAULT 0',
            'adjust_isha_minutes' => 'INTEGER NOT NULL DEFAULT 0',
            // Database lama sudah dipakai, jadi lokasinya dianggap sudah diatur (instalasi baru: 0).
            'location_confirmed' => 'INTEGER NOT NULL DEFAULT 1 CHECK (location_confirmed IN (0,1))',
            // Sama: instalasi lama tidak perlu Langkah awal (#42).
            'setup_done' => 'INTEGER NOT NULL DEFAULT 1 CHECK (setup_done IN (0,1))',
        ],
        'publications' => [
            'starts_on' => 'TEXT',
            'ends_on' => 'TEXT',
            'event_date' => 'TEXT',
            'event_end_date' => 'TEXT',
            'event_start_time' => 'TEXT',
            'event_end_time' => 'TEXT',
        ],
        'admin_users' => [
            'must_change_password' => 'INTEGER NOT NULL DEFAULT 0 CHECK (must_change_password IN (0,1))',
        ],
    ];

    /** Tabel baru untuk database lama (sama dengan schema.sql). */
    private const ADDED_TABLES = [
        'CREATE TABLE IF NOT EXISTS auth_failures (action TEXT NOT NULL, ip TEXT NOT NULL, failed_at INTEGER NOT NULL)',
        'CREATE INDEX IF NOT EXISTS idx_auth_failures ON auth_failures(action, ip, failed_at)',
    ];

    private static function migrate(PDO $pdo): void
    {
        foreach (self::ADDED_TABLES as $sql) {
            $pdo->exec($sql);
        }
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
        // Instalasi baru langsung punya akun admin / 000 (DECISIONS.md #41).
        if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'admin_users'")->fetchColumn()) {
            \App\Auth\DefaultAccount::ensure($pdo);
        }
    }

    /** Untuk testing: paksa reset koneksi supaya bisa connect ke DB lain. */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
