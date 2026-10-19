<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Db\Database;
use App\Repository\SettingsRepository;

/**
 * REQ-F-001..REQ-F-006. Titik masuk utama untuk mendapatkan jadwal sholat
 * suatu tanggal: cek cache di prayer_schedules dulu, kalau belum ada,
 * hitung lewat PrayerTimeCalculator + HijriConverter lalu simpan.
 */
final class ScheduleEngine
{
    public function __construct(
        private readonly PrayerTimeCalculator $calculator = new PrayerTimeCalculator(),
        private readonly HijriConverter $hijri = new HijriConverter(),
        private readonly SettingsRepository $settingsRepo = new SettingsRepository()
    ) {
    }

    /** @return array{schedule_date:string,fajr:string,sunrise:string,dhuhr:string,asr:string,maghrib:string,isha:string,hijri_date:string,source:string} */
    public function getScheduleForDate(string $dateYmd): array
    {
        $cached = $this->findCached($dateYmd);
        if ($cached !== null) {
            return $cached;
        }
        return $this->computeAndStore($dateYmd);
    }

    public function getScheduleForToday(): array
    {
        // Tanggal lokal masjid, bukan UTC (00:00–07:00 WIB masih "kemarin" di UTC).
        return $this->getScheduleForDate(\App\LocalTime::today());
    }

    /**
     * Dipanggil ulang setiap kali admin mengubah lokasi/madzhab/offset
     * (REQ-F-002, REQ-F-003) — cache lama untuk tanggal tsb dihapus supaya
     * dihitung ulang dengan pengaturan baru pada request berikutnya.
     */
    public function invalidateCache(?string $fromDate = null): void
    {
        if ($fromDate === null) {
            Database::connection()->exec('DELETE FROM prayer_schedules');
            return;
        }
        $stmt = Database::connection()->prepare('DELETE FROM prayer_schedules WHERE schedule_date >= :d');
        $stmt->execute(['d' => $fromDate]);
    }

    private function findCached(string $dateYmd): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM prayer_schedules WHERE schedule_date = :d');
        $stmt->execute(['d' => $dateYmd]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Lima waktu sholat (yang punya adzan) dan namanya di panel. Terbit tidak termasuk. */
    public const PRAYER_NAMES = ['fajr' => 'Subuh', 'dhuhr' => 'Dzuhur', 'asr' => 'Ashar', 'maghrib' => 'Maghrib', 'isha' => 'Isya'];

    /** Waktu sholat yang dikoreksi takmir per sholat (DECISIONS.md #35). */
    public const ADJUSTABLE = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'];

    /**
     * Hasil hitungan sebelum koreksi per sholat. Tanpa $withGlobalOffset: murni hitungan,
     * sebelum koreksi "semua sholat" juga (untuk halaman koreksi jadwal).
     *
     * @return array{fajr:string,sunrise:string,dhuhr:string,asr:string,maghrib:string,isha:string}
     */
    public function calculatedFor(string $dateYmd, bool $withGlobalOffset = true): array
    {
        $settings = $this->settingsRepo->get();
        [$y, $m, $d] = array_map('intval', explode('-', $dateYmd));
        return $this->calculator->calculate(
            $y,
            $m,
            $d,
            (float) $settings['latitude'],
            (float) $settings['longitude'],
            (float) $settings['timezone_offset_hours'],
            (string) $settings['madzhab'],
            $withGlobalOffset ? (int) $settings['location_offset_minutes'] : 0
        );
    }

    /** "11:27" + 3 menit → "11:30" (tetap di dalam hari yang sama). */
    public static function shiftTime(string $hhmm, int $minutes): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        $total = min(23 * 60 + 59, max(0, $h * 60 + $m + $minutes));
        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    private function computeAndStore(string $dateYmd): array
    {
        $settings = $this->settingsRepo->get();
        $times = $this->calculatedFor($dateYmd);
        foreach (self::ADJUSTABLE as $key) {
            $times[$key] = self::shiftTime($times[$key], (int) ($settings["adjust_{$key}_minutes"] ?? 0));
        }

        $hijri = $this->hijriFor($dateYmd);

        $row = array_merge($times, [
            'schedule_date' => $dateYmd,
            'hijri_date' => $hijri['label'],
            'madzhab_used' => $settings['madzhab'],
            'source' => 'local',
        ]);

        $stmt = Database::connection()->prepare(
            'INSERT INTO prayer_schedules
                (schedule_date, fajr, sunrise, dhuhr, asr, maghrib, isha, hijri_date, madzhab_used, source)
             VALUES (:schedule_date, :fajr, :sunrise, :dhuhr, :asr, :maghrib, :isha, :hijri_date, :madzhab_used, :source)
             ON CONFLICT(schedule_date) DO UPDATE SET
                fajr=excluded.fajr, sunrise=excluded.sunrise, dhuhr=excluded.dhuhr,
                asr=excluded.asr, maghrib=excluded.maghrib, isha=excluded.isha,
                hijri_date=excluded.hijri_date, madzhab_used=excluded.madzhab_used,
                source=excluded.source, computed_at=CURRENT_TIMESTAMP'
        );
        $stmt->execute($row);

        return $this->findCached($dateYmd) ?? $row;
    }

    /**
     * Tanggal Hijriah untuk tanggal Masehi ini, setelah koreksi takmir
     * (hijri_offset_days) agar sama dengan keputusan isbat Kemenag. Dipakai
     * untuk label di layar dan penentuan puasa sunnah.
     *
     * @return array{year:int,month:int,day:int,label:string}
     */
    public function hijriFor(string $dateYmd): array
    {
        $offset = (int) ($this->settingsRepo->get()['hijri_offset_days'] ?? 0);
        [$y, $m, $d] = array_map('intval', explode('-', gmdate('Y-m-d', strtotime($dateYmd . ' UTC') + $offset * 86400)));
        return $this->hijri->fromGregorian($y, $m, $d);
    }

    /** REQ-F-013: info puasa sunnah dari tanggal Hijriyah berjalan. */
    public function getFastingInfo(string $dateYmd): array
    {
        [$y, $m, $d] = array_map('intval', explode('-', $dateYmd));
        $hijri = $this->hijriFor($dateYmd);
        $dow = (int) gmdate('N', gmmktime(0, 0, 0, $m, $d, $y)); // 1=Senin ... 7=Minggu

        // Hari terlarang berpuasa (1 Syawal, 10 Dzulhijjah, hari Tasyrik 11–13 Dzulhijjah)
        // dan bulan Ramadhan (puasa wajib) tidak ditandai sebagai puasa sunnah.
        $forbidden = ($hijri['month'] === 10 && $hijri['day'] === 1)
            || ($hijri['month'] === 12 && $hijri['day'] >= 10 && $hijri['day'] <= 13);
        $sunnahApplies = !$forbidden && $hijri['month'] !== 9;

        return [
            'is_senin_kamis' => $sunnahApplies && ($dow === 1 || $dow === 4),
            'is_ayyamul_bidh' => $sunnahApplies && $this->hijri->isAyyamulBidh($hijri['day']),
            'hijri_label' => $hijri['label'],
        ];
    }

    /**
     * Hari-hari puasa sunnah dalam $days hari mulai $startYmd (termasuk hari itu),
     * untuk baris "puasa sunnah terdekat" di layar jamaah.
     */
    public function getUpcomingFasting(string $startYmd, int $days = 8): array
    {
        [$y, $m, $d] = array_map('intval', explode('-', $startYmd));
        $start = gmmktime(0, 0, 0, $m, $d, $y);
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = gmdate('Y-m-d', $start + $i * 86400);
            $info = $this->getFastingInfo($date);
            if ($info['is_senin_kamis'] || $info['is_ayyamul_bidh']) {
                $out[] = [
                    'date' => $date,
                    'is_senin_kamis' => $info['is_senin_kamis'],
                    'is_ayyamul_bidh' => $info['is_ayyamul_bidh'],
                ];
            }
        }
        return $out;
    }
}
