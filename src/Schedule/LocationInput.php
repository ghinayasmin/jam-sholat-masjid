<?php

declare(strict_types=1);

namespace App\Schedule;

/**
 * Isian lokasi masjid dari Pengaturan → Lokasi dan dari Langkah awal (DECISIONS.md #42).
 * Takmir biasanya memilih kabupaten/kota dari daftar (public/assets/data/kabupaten_kota.json),
 * yang mengisi nama dan koordinat; teknisi masih bisa mengisi koordinat sendiri.
 */
final class LocationInput
{
    /**
     * @return array{0: array<string, mixed>, 1: list<string>} kolom mosque_settings dan pesan kesalahan
     */
    public static function fromPost(array $post, bool $withMadzhab = true): array
    {
        $errors = [];
        $name = trim((string) ($post['location_name'] ?? ''));
        $latitude = filter_var(self::number($post['latitude'] ?? ''), FILTER_VALIDATE_FLOAT);
        $longitude = filter_var(self::number($post['longitude'] ?? ''), FILTER_VALIDATE_FLOAT);

        if ($name === '') {
            $errors[] = 'Pilih kabupaten/kota masjid dari daftar.';
        }
        if ($latitude === false || $latitude < -90 || $latitude > 90) {
            $errors[] = 'Garis lintang harus berupa angka antara -90 dan 90.';
        }
        if ($longitude === false || $longitude < -180 || $longitude > 180) {
            $errors[] = 'Garis bujur harus berupa angka antara -180 dan 180.';
        }
        $fields = [
            'location_name' => mb_substr($name, 0, 60),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_confirmed' => 1,
        ];
        if ($withMadzhab) {
            $madzhab = $post['madzhab'] ?? '';
            if (!in_array($madzhab, ['syafii', 'hanafi'], true)) {
                $errors[] = 'Pilih madzhab untuk waktu Ashar.';
            }
            $fields['madzhab'] = $madzhab;
        }
        return [$fields, $errors];
    }

    /** "-7,68" dari papan ketik HP berbahasa Indonesia dibaca sebagai -7.68. */
    private static function number(mixed $value): string
    {
        return str_replace(',', '.', trim((string) $value));
    }
}
