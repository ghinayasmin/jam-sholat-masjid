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

    /**
     * Keterangan status untuk panel. Jendela diam sama dengan alarm_service.py:
     * 2 menit sebelum s.d. 20 menit sesudah tiap adzan.
     *
     * @param array<string, mixed>  $settings baris mosque_settings
     * @param array<string, string> $schedule jadwal hari ini (HH:MM)
     */
    public static function status(array $settings, array $schedule, int $nowMinutes): string
    {
        if (self::tracks() === []) {
            return 'Belum ada file murottal di perangkat';
        }
        if ((int) $settings['murottal_enabled'] !== 1) {
            return 'Dijeda';
        }
        if ((string) $settings['murottal_now_playing'] !== '') {
            return 'Sedang diputar: ' . $settings['murottal_now_playing'];
        }
        foreach (['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'] as $key) {
            $at = (int) substr($schedule[$key], 0, 2) * 60 + (int) substr($schedule[$key], 3, 2);
            $delta = $nowMinutes - $at;
            if ($delta >= -2 && $delta <= 20) {
                return sprintf('Diam sekitar waktu sholat, mulai lagi pukul %02d:%02d', intdiv($at + 21, 60) % 24, ($at + 21) % 60);
            }
        }
        return 'Memulai…';
    }

    public static function title(string $file): string
    {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $title = trim(str_replace('_', ' ', (string) preg_replace('/^[0-9]+[ _-]*/', '', $name)));
        return $title !== '' ? $title : $name;
    }
}
