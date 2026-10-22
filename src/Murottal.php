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
     * 2 menit sebelum s.d. 20 menit sesudah tiap adzan, atau 15 menit sesudah iqomah
     * bila lebih lama (Jumat: 60 menit sesudah adzan Dzuhur, agar tidak menyela khutbah).
     *
     * @param array<string, mixed>  $settings baris mosque_settings
     * @param array<string, string> $schedule jadwal hari ini (HH:MM)
     */
    public static function status(array $settings, array $schedule, int $nowMinutes, bool $isFriday = false): string
    {
        if (self::tracks() === []) {
            return 'Belum ada file murottal di perangkat';
        }
        if ((int) $settings['murottal_enabled'] !== 1) {
            $saved = explode('|', (string) ($settings['murottal_position'] ?? ''));
            return (int) ($saved[1] ?? 0) > 0
                ? 'Murottal dijeda. Tekan Lanjutkan untuk meneruskan dari posisi terakhir.'
                : 'Tekan Putar untuk memulai.';
        }
        if ((string) $settings['murottal_now_playing'] !== '') {
            return 'Sedang diputar: ' . $settings['murottal_now_playing'];
        }
        foreach (['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'] as $key) {
            $at = (int) substr($schedule[$key], 0, 2) * 60 + (int) substr($schedule[$key], 3, 2);
            $delta = $nowMinutes - $at;
            // Sama dengan alarm_service.py: 20 menit, atau 15 menit setelah iqomah bila lebih lama (#30).
            $after = $key === 'dhuhr' && $isFriday ? 60 : max(20, (int) ($settings["iqomah_{$key}_minutes"] ?? 10) + 15);
            if ($delta >= -2 && $delta <= $after) {
                $resume = $at + $after + 1;
                return sprintf('Diam sekitar waktu sholat, mulai lagi pukul %02d:%02d', intdiv($resume, 60) % 24, $resume % 60);
            }
        }
        return 'Memulai…';
    }

    /** Durasi (detik) MP3 CBR dari ukuran berkas dan bitrate frame pertama; null bila tidak terbaca. */
    public static function duration(string $file): ?int
    {
        $path = self::dir() . '/' . basename($file);
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $data = (string) fread($fh, 65536);
        $offset = 0;
        if (str_starts_with($data, 'ID3') && strlen($data) >= 10) {
            $b = array_map('ord', str_split(substr($data, 6, 4)));
            $offset = 10 + (($b[0] & 0x7F) << 21 | ($b[1] & 0x7F) << 14 | ($b[2] & 0x7F) << 7 | ($b[3] & 0x7F));
            fseek($fh, $offset);
            $data = (string) fread($fh, 4096);
        }
        fclose($fh);
        $kbps = [3 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
            2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160]];
        for ($i = 0; $i + 4 <= strlen($data); $i++) {
            $h1 = ord($data[$i]);
            $h2 = ord($data[$i + 1]);
            if ($h1 === 0xFF && ($h2 & 0xE0) === 0xE0) {
                $version = ($h2 >> 3) & 0x03;            // 3 = MPEG1, 2/0 = MPEG2/2.5
                $rate = $kbps[$version === 3 ? 3 : 2][(ord($data[$i + 2]) >> 4) & 0x0F] ?? 0;
                if ($rate > 0) {
                    return (int) round((filesize($path) - $offset) * 8 / ($rate * 1000));
                }
            }
        }
        return null;
    }

    /**
     * Keadaan pemutar untuk panel (#40), dari posisi yang ditulis layanan alarm:
     * surat, detik saat ini (ditambah waktu sejak ditulis bila sedang diputar), durasi.
     *
     * @param array<string, mixed> $settings baris mosque_settings
     * @return array{file: string, title: string, number: int, count: int, position: int, duration: ?int, playing: bool}|null
     */
    public static function player(array $settings): ?array
    {
        $tracks = self::tracks();
        if ($tracks === []) {
            return null;
        }
        $parts = explode('|', (string) ($settings['murottal_position'] ?? ''));
        $files = array_column($tracks, 'file');
        $index = array_search($parts[0], $files, true);
        if ($index === false) {
            $request = explode('|', (string) ($settings['murottal_request'] ?? ''))[0];
            $index = array_search($request, $files, true);
        }
        $index = $index === false ? 0 : (int) $index;
        $file = $files[$index];
        $playing = (int) $settings['murottal_enabled'] === 1 && ($parts[3] ?? '0') === '1'
            && (string) $settings['murottal_now_playing'] !== '';
        $position = ($parts[0] ?? '') === $file ? (int) ($parts[1] ?? 0) : 0;
        // Layanan alarm masih mencatat "sedang diputar" (mis. tepat setelah Jeda, sebelum
        // ia sempat menyimpan posisi akhir): taksir dari waktu putar sejak dicatat.
        if (($parts[3] ?? '0') === '1' && ($parts[0] ?? '') === $file) {
            $position += max(0, time() - (int) ($parts[2] ?? time()));
        }
        $duration = self::duration($file);
        if ($duration !== null) {
            $position = min($position, $duration);
        }
        return ['file' => $file, 'title' => self::title($file), 'number' => $index + 1, 'count' => count($files),
            'position' => $position, 'duration' => $duration, 'playing' => $playing];
    }

    public static function clock(int $seconds): string
    {
        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public static function title(string $file): string
    {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $title = trim(str_replace('_', ' ', (string) preg_replace('/^[0-9]+[ _-]*/', '', $name)));
        return $title !== '' ? $title : $name;
    }
}
