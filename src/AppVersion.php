<?php

declare(strict_types=1);

namespace App;

/**
 * Versi aplikasi dari berkas VERSION di akar repo: baris 1 nomor versi (x.y.z),
 * baris 2 bulan rilis. Satu sumber untuk halaman Tentang, installer Windows, paket
 * .deb, dan tag rilis (temuan uji v1.1.1: halaman Tentang masih menulis 1.1).
 */
final class AppVersion
{
    /** @return array{0: string, 1: string} */
    private static function lines(): array
    {
        $raw = @file_get_contents(dirname(__DIR__) . '/VERSION');
        $lines = $raw === false ? [] : array_values(array_filter(array_map('trim', explode("\n", $raw)), 'strlen'));
        return [$lines[0] ?? '?', $lines[1] ?? ''];
    }

    public static function number(): string
    {
        return self::lines()[0];
    }

    public static function release(): string
    {
        return self::lines()[1];
    }
}
