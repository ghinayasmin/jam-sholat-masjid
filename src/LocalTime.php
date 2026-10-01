<?php

declare(strict_types=1);

namespace App;

use App\Repository\SettingsRepository;

/**
 * Waktu lokal masjid. Basis internal PHP adalah UTC (bootstrap.php); pengurus dan
 * jamaah melihat waktu lokal sesuai mosque_settings.timezone_offset_hours (WIB = 7).
 */
final class LocalTime
{
    private static ?int $offsetSeconds = null;

    public static function offsetSeconds(): int
    {
        if (self::$offsetSeconds === null) {
            $settings = (new SettingsRepository())->get();
            self::$offsetSeconds = (int) round((float) $settings['timezone_offset_hours'] * 3600);
        }
        return self::$offsetSeconds;
    }

    /** Unix timestamp yang digeser ke waktu lokal, untuk dipakai dengan gmdate(). */
    public static function now(): int
    {
        return time() + self::offsetSeconds();
    }

    public static function today(): string
    {
        return gmdate('Y-m-d', self::now());
    }

    /** Untuk testing. */
    public static function reset(): void
    {
        self::$offsetSeconds = null;
    }
}
