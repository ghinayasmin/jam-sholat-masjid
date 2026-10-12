<?php

declare(strict_types=1);

namespace App;

/**
 * Daftar file murottal di perangkat. Urutan dan judulnya harus sama dengan
 * alarm_service.py (murottal_files / murottal_title): diurutkan menurut nama
 * file, judul = nama file tanpa nomor depan.
 */
final class Murottal
{
    public static function dir(): string
    {
        return dirname(__DIR__) . '/public/assets/audio/murottal';
    }

    /** @return list<array{file: string, title: string}> */
    public static function tracks(): array
    {
        $files = glob(self::dir() . '/*.mp3') ?: [];
        sort($files, SORT_STRING);
        return array_map(static fn (string $path): array => [
            'file' => basename($path),
            'title' => self::title(basename($path)),
        ], $files);
    }

    public static function title(string $file): string
    {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $title = trim(str_replace('_', ' ', (string) preg_replace('/^[0-9]+[ _-]*/', '', $name)));
        return $title !== '' ? $title : $name;
    }
}
