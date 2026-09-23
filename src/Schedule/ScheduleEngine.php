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
        return $this->getScheduleForDate(gmdate('Y-m-d'));
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

    private function computeAndStore(string $dateYmd): array
    {
        $settings = $this->settingsRepo->get();
        [$y, $m, $d] = array_map('intval', explode('-', $dateYmd));

        $times = $this->calculator->calculate(
            $y,
            $m,
            $d,
            (float) $settings['latitude'],
            (float) $settings['longitude'],
            (float) $settings['timezone_offset_hours'],
            (string) $settings['madzhab'],
            (int) $settings['location_offset_minutes']
        );

        $hijri = $this->hijri->fromGregorian($y, $m, $d);

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

    /** REQ-F-013: info puasa sunnah dari tanggal Hijriyah berjalan. */
    public function getFastingInfo(string $dateYmd): array
    {
        [$y, $m, $d] = array_map('intval', explode('-', $dateYmd));
        $hijri = $this->hijri->fromGregorian($y, $m, $d);
        $dow = (int) gmdate('N', gmmktime(0, 0, 0, $m, $d, $y)); // 1=Senin ... 7=Minggu

        return [
            'is_senin_kamis' => $dow === 1 || $dow === 4,
            'is_ayyamul_bidh' => $this->hijri->isAyyamulBidh($hijri['day']),
            'hijri_label' => $hijri['label'],
        ];
    }
}
