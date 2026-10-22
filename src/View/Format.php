<?php

declare(strict_types=1);

namespace App\View;

/** Format tampilan untuk panel takmir: rupiah dan tanggal berbahasa Indonesia. */
final class Format
{
    private const DAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
        'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    private const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public static function rupiah(int $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }

    /** "+350.000" / "−200.000" untuk daftar transaksi. */
    public static function signedAmount(string $type, int $amount): string
    {
        return ($type === 'masuk' ? '+' : '−') . number_format($amount, 0, ',', '.');
    }

    /** "Rp 4,8 jt" untuk ringkasan singkat. */
    public static function rupiahShort(int $amount): string
    {
        $abs = abs($amount);
        $sign = $amount < 0 ? '−' : '';
        if ($abs >= 1_000_000) {
            return $sign . 'Rp ' . str_replace('.', ',', rtrim(rtrim(number_format($abs / 1_000_000, 1, '.', ''), '0'), '.')) . ' jt';
        }
        if ($abs >= 1_000) {
            return $sign . 'Rp ' . (int) floor($abs / 1_000) . ' rb';
        }
        return $sign . 'Rp ' . $abs;
    }

    /** "Kamis, 1 Okt" */
    public static function shortDate(string $ymd): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $ymd));
        $dow = (int) gmdate('w', gmmktime(0, 0, 0, $m, $d, $y));
        return self::DAYS[$dow] . ', ' . $d . ' ' . self::MONTHS_SHORT[$m - 1];
    }

    /** "Kamis, 1 Oktober 2026" */
    /**
     * Waktu acara pengumuman (#40): "Minggu, 25 Okt · 07.00–12.30",
     * "Rabu–Kamis, 28–29 Okt · 08.00", atau "Sabtu, 31 Okt – Senin, 2 Nov".
     * null bila tanggal acara tidak diisi.
     */
    public static function eventWhen(?string $date, ?string $endDate = null, ?string $start = null, ?string $end = null): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        $dow = (int) gmdate('w', gmmktime(0, 0, 0, $m, $d, $y));
        $text = self::DAYS[$dow] . ', ' . $d . ' ' . self::MONTHS_SHORT[$m - 1];
        if ($endDate !== null && $endDate !== '' && $endDate > $date) {
            [$y2, $m2, $d2] = array_map('intval', explode('-', $endDate));
            $dow2 = (int) gmdate('w', gmmktime(0, 0, 0, $m2, $d2, $y2));
            $text = ($m2 === $m && $y2 === $y)
                ? self::DAYS[$dow] . '–' . self::DAYS[$dow2] . ', ' . $d . '–' . $d2 . ' ' . self::MONTHS_SHORT[$m - 1]
                : $text . ' – ' . self::DAYS[$dow2] . ', ' . $d2 . ' ' . self::MONTHS_SHORT[$m2 - 1];
        }
        $clock = static fn (string $hm): string => str_replace(':', '.', $hm);
        if ($start !== null && $start !== '') {
            $text .= ' · ' . $clock($start) . ($end !== null && $end !== '' ? '–' . $clock($end) : '');
        }
        return $text;
    }

    public static function longDate(string $ymd): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $ymd));
        $dow = (int) gmdate('w', gmmktime(0, 0, 0, $m, $d, $y));
        return self::DAYS[$dow] . ', ' . $d . ' ' . self::MONTHS[$m - 1] . ' ' . $y;
    }

    /** "Hari ini", "Kemarin", atau shortDate. */
    public static function relativeDate(string $ymd, string $today): string
    {
        if ($ymd === $today) {
            return 'Hari ini';
        }
        $yesterday = gmdate('Y-m-d', strtotime($today . ' 00:00:00 UTC') - 86400);
        return $ymd === $yesterday ? 'Kemarin' : self::shortDate($ymd);
    }

    public static function monthName(string $yearMonth): string
    {
        return self::MONTHS[(int) substr($yearMonth, 5, 2) - 1];
    }

    /** Escape HTML singkat untuk template. */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
