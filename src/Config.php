<?php

declare(strict_types=1);

namespace App;

/**
 * Config loader sederhana tanpa dependency eksternal.
 * - config/config.php  : nilai non-secret (path, timezone default, dsb)
 * - config/.env         : nilai secret (tidak di-commit ke VCS) — REQ-NF-121/kode quality §8
 *
 * Semua nilai bisa dioverride lewat environment variable asli (getenv),
 * berguna untuk testing dan untuk systemd EnvironmentFile.
 */
final class Config
{
    private static ?array $values = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        if (($env = getenv(strtoupper($key))) !== false) {
            return $env;
        }
        return self::$values[$key] ?? $default;
    }

    private static function load(): void
    {
        if (self::$values !== null) {
            return;
        }

        $root = dirname(__DIR__);
        $configFile = $root . '/config/config.php';
        $envFile = $root . '/config/.env';

        self::$values = is_file($configFile) ? require $configFile : [];

        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                self::$values[trim($k)] = trim($v);
            }
        }
    }

    /** Untuk testing: paksa reload config dari path lain. */
    public static function reset(): void
    {
        self::$values = null;
    }

    public static function override(array $values): void
    {
        self::load();
        self::$values = array_merge(self::$values, $values);
    }
}
