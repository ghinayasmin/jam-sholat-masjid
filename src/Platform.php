<?php

declare(strict_types=1);

namespace App;

/**
 * Perangkat tempat aplikasi berjalan, untuk Panduan dan halaman "Buka panel dari HP"
 * (DECISIONS.md #40): langkahnya berbeda di Raspberry Pi, Windows, dan Linux.
 */
final class Platform
{
    public const PI = 'pi';
    public const WINDOWS = 'windows';
    public const LINUX_DESKTOP = 'linux-desktop';
    public const LINUX_SERVER = 'linux-server';

    public static function kind(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return self::WINDOWS;
        }
        $model = @file_get_contents('/proc/device-tree/model');
        if ($model !== false && str_contains($model, 'Raspberry Pi')) {
            return self::PI;
        }
        $mode = @file_get_contents('/etc/jamsholat/mode');
        return $mode !== false && trim($mode) === 'desktop' ? self::LINUX_DESKTOP : self::LINUX_SERVER;
    }

    /**
     * Alamat IPv4 jaringan lokal komputer ini (WiFi/LAN), untuk dibuka dari HP.
     *
     * @return list<string>
     */
    public static function lanAddresses(?string $serverAddr = null, ?string $httpHost = null): array
    {
        $candidates = [];
        if ($serverAddr !== null) {
            $candidates[] = $serverAddr;   // panel dibuka dari HP: alamat yang sedang dipakai
        }
        if ($httpHost !== null) {
            $candidates[] = (string) preg_replace('/:\d+$/', '', $httpHost);   // alamat di bilah browser
        }
        // Alamat adapter yang dipakai ke jaringan luar didahulukan, supaya QR tidak
        // menunjuk adapter virtual (VirtualBox 192.168.56.1, Hyper-V, WSL) di Windows.
        $route = self::routeAddress();
        if ($route !== null) {
            $candidates[] = $route;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates = array_merge($candidates, gethostbynamel((string) gethostname()) ?: []);
        } else {
            $out = @shell_exec('hostname -I 2>/dev/null');
            $candidates = array_merge($candidates, preg_split('/\s+/', trim((string) $out)) ?: []);
        }
        $lan = array_filter($candidates, static function (string $ip): bool {
            return self::isPrivate($ip);
        });
        return array_values(array_unique($lan));
    }

    /**
     * Alamat lokal yang dipilih sistem untuk rute default. Soket UDP yang di-"connect"
     * tidak mengirim paket apa pun, jadi tetap aman tanpa internet; null bila tidak ada rute.
     */
    public static function routeAddress(): ?string
    {
        $sock = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
        if ($sock === false) {
            return null;
        }
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        $ip = (string) preg_replace('/:\d+$/', '', $name);
        return self::isPrivate($ip) ? $ip : null;
    }

    /** 10.x.x.x, 172.16–31.x.x, atau 192.168.x.x (bukan 127.x atau 169.254.x). */
    public static function isPrivate(string $ip): bool
    {
        return (bool) preg_match('/^(10\.\d{1,3}|172\.(1[6-9]|2\d|3[01])|192\.168)\.\d{1,3}\.\d{1,3}$/', $ip);
    }
}
