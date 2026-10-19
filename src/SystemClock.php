<?php

declare(strict_types=1);

namespace App;

/**
 * Jam sistem perangkat (REQ-F-061). Raspberry Pi tidak punya RTC: setelah listrik
 * padam tanpa internet, jam melanjutkan dari waktu terakhir yang disimpan
 * fake-hwclock, sehingga tertinggal sepanjang padamnya listrik.
 *
 * Jam dianggap "terpercaya" bila sudah tersinkron NTP sejak boot, atau sudah
 * dicocokkan oleh admin (manual atau otomatis dari jam HP) sejak boot.
 * Selain itu, panel admin mencocokkan jam perangkat dengan jam HP pengurus,
 * yang selalu tepat karena mengikuti jaringan seluler.
 */
final class SystemClock
{
    /** Penanda dibuat systemd-timesyncd setelah sinkron NTP; hilang saat reboot (/run = tmpfs). */
    private const NTP_SYNCED_FLAG = '/run/systemd/timesync/synchronized';

    /** Selisih di bawah ini dianggap sudah cocok (latensi jaringan + pembulatan detik). */
    public const TOLERANCE_SECONDS = 60;

    /**
     * Jam hanya bisa diatur dari panel di Linux (sudo date, lihat install.sh). Di Windows
     * jam diatur oleh Windows sendiri (DECISIONS.md #39).
     */
    public static function canSet(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    public static function isTrusted(): bool
    {
        // Komputer biasa (Windows, atau Linux dengan RTC berbaterai /dev/rtc0) tetap
        // ingat jamnya saat listrik padam. Hanya Raspberry Pi tanpa RTC yang perlu dicocokkan.
        if (!self::canSet() || file_exists(self::NTP_SYNCED_FLAG) || file_exists('/dev/rtc0')) {
            return true;
        }
        $markedAt = @filemtime(self::markerPath());
        return $markedAt !== false && $markedAt >= self::bootTime();
    }

    /**
     * Atur jam sistem ke $epoch (detik UTC). Memakai aturan sudoers dari
     * install.sh langkah 8 yang hanya mengizinkan `date -s`.
     */
    public static function set(int $epoch): bool
    {
        if (!self::canSet()) {
            return false;
        }
        exec('sudo /usr/bin/date -s ' . escapeshellarg('@' . $epoch) . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            error_log('[SystemClock] ' . implode(' ', $output));
            return false;
        }
        self::markSet();
        return true;
    }

    /** Catat bahwa jam sudah dicocokkan admin sejak boot ini. */
    public static function markSet(): void
    {
        @touch(self::markerPath());
    }

    private static function markerPath(): string
    {
        return dirname((string) Config::get('db_path')) . '/clock_set_at';
    }

    private static function bootTime(): int
    {
        $uptime = (float) @file_get_contents('/proc/uptime');
        return $uptime > 0 ? (int) (time() - $uptime) : 0;
    }
}
