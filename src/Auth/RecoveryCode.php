<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Kode pemulihan kata sandi untuk takmir (DECISIONS.md #33).
 *
 * Masjid sering tanpa internet dan tanpa email, jadi "lupa kata sandi" tidak bisa
 * lewat email. Takmir menyimpan kode ini (dicetak/difoto); di halaman login kode
 * dipakai sekali untuk membuat kata sandi baru. Hanya hash bcrypt yang disimpan.
 */
final class RecoveryCode
{
    /** Tanpa 0/O, 1/I/L agar tidak tertukar saat diketik dari kertas. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const GROUPS = 4;
    private const GROUP_LENGTH = 4;

    /** "K7QM-3HXT-9PWA-RD4N" (16 karakter acak, ±79 bit). */
    public static function generate(): string
    {
        $chars = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::GROUPS * self::GROUP_LENGTH; $i++) {
            $chars .= self::ALPHABET[random_int(0, $max)];
        }
        return implode('-', str_split($chars, self::GROUP_LENGTH));
    }

    /** Huruf kecil, spasi, dan tanda hubung diabaikan saat dicocokkan. */
    public static function normalize(string $input): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($input));
    }

    public static function hash(string $code): string
    {
        return password_hash(self::normalize($code), PASSWORD_BCRYPT);
    }

    public static function verify(string $input, ?string $hash): bool
    {
        $normalized = self::normalize($input);
        if ($hash === null || $hash === '' || strlen($normalized) !== self::GROUPS * self::GROUP_LENGTH) {
            // Tetap hitung bcrypt agar waktu respons tidak membocorkan apakah akun punya kode.
            password_verify($normalized, '$2y$10$abcdefghijklmnopqrstuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuuu');
            return false;
        }
        return password_verify($normalized, $hash);
    }
}
