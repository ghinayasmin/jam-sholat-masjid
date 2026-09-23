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

            self::$instance = $pdo;
        }

        return self::$instance;
    }

    /** Untuk testing: paksa reset koneksi supaya bisa connect ke DB lain. */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
