<?php

declare(strict_types=1);

namespace App\Schedule;

/**
 * Konversi tanggal Masehi -> Hijriyah — REQ-F-005, REQ-F-013.
 *
 * Memakai algoritma tabular Islamic calendar (civil epoch, basis 30-tahun
 * kabisat berulang) — formula matematis publik yang umum dipakai berbagai
 * pustaka kalender open-source, dihitung murni dari tanggal (offline,
 * tanpa observasi rukyat).
 *
 * KETERBATASAN PENTING (dicatat juga di README & docs/DECISIONS.md):
 * Tanggal 1 Syawal/1 Ramadhan versi tabular ini BISA berbeda ±1 hari dari
 * pengumuman rukyat resmi Kemenag RI, karena rukyat bergantung pada
 * observasi hilal aktual yang tidak bisa dihitung murni matematis di muka.
 * Untuk kebutuhan tampilan harian rutin (bukan penentuan awal bulan resmi)
 * perbedaan ini dapat diterima.
 */
final class HijriConverter
{
    private const MONTH_NAMES = [
        1 => 'Muharram', 2 => 'Safar', 3 => "Rabi'ul Awal", 4 => "Rabi'ul Akhir",
        5 => 'Jumadil Awal', 6 => 'Jumadil Akhir', 7 => 'Rajab', 8 => "Sya'ban",
        9 => 'Ramadhan', 10 => 'Syawal', 11 => "Dzulqa'dah", 12 => 'Dzulhijjah',
    ];

    /** @return array{year:int,month:int,day:int,label:string} */
    public function fromGregorian(int $year, int $month, int $day): array
    {
        $jd = $this->gregorianToJulianDay($year, $month, $day);
        [$hYear, $hMonth, $hDay] = $this->julianDayToIslamic($jd);

        return [
            'year' => $hYear,
            'month' => $hMonth,
            'day' => $hDay,
            'label' => sprintf('%d %s %d H', $hDay, self::MONTH_NAMES[$hMonth], $hYear),
        ];
    }

    /**
     * REQ-F-013: Ayyamul Bidh = tanggal 13, 14, 15 tiap bulan Hijriyah.
     */
    public function isAyyamulBidh(int $hijriDay): bool
    {
        return in_array($hijriDay, [13, 14, 15], true);
    }

    private function gregorianToJulianDay(int $year, int $month, int $day): int
    {
        $a = intdiv(14 - $month, 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;

        return $day
            + intdiv(153 * $m + 2, 5)
            + 365 * $y
            + intdiv($y, 4)
            - intdiv($y, 100)
            + intdiv($y, 400)
            - 32045;
    }

    /** @return array{0:int,1:int,2:int} [tahun, bulan, tanggal Hijriyah] */
    private function julianDayToIslamic(int $jd): array
    {
        $jd = $jd - 1948440 + 10632;
        $n = intdiv($jd - 1, 10631);
        $jd = $jd - 10631 * $n + 354;
        $j = intdiv(10985 - $jd, 5316) * intdiv(50 * $jd, 17719)
            + intdiv($jd, 5670) * intdiv(43 * $jd, 15238);
        $jd = $jd - intdiv(30 - $j, 15) * intdiv(17719 * $j, 50)
            - intdiv($j, 16) * intdiv(15238 * $j, 43) + 29;
        $month = intdiv(24 * $jd, 709);
        $day = $jd - intdiv(709 * $month, 24);
        $year = 30 * $n + $j - 30;

        return [$year, $month, $day];
    }
}
