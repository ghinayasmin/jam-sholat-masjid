<?php

declare(strict_types=1);

namespace App\Schedule;

/**
 * Menghitung jadwal waktu sholat murni dari posisi matahari (deklinasi &
 * equation of time), tanpa dependency eksternal — REQ-F-001, berjalan
 * sepenuhnya lokal/offline.
 *
 * Metode sudut: REQ-F-006 mengacu ke "metode Kemenag RI atau library
 * astronomis standar". Sudut default yang dipakai (Fajr 20°, Isha 18°)
 * sama persis dengan definisi method id=20 (KEMENAG) pada AlAdhan API,
 * supaya hasil kalkulasi lokal dan hasil online sync (REQ-F-004) konsisten
 * satu sama lain — lihat docs/DECISIONS.md.
 *
 * Formula yang dipakai adalah formula astronomi hour-angle standar
 * (deklinasi matahari + equation of time via posisi ekliptika matahari),
 * metode publik yang sama dipakai oleh berbagai implementasi kalkulasi
 * waktu sholat sumber terbuka. Akurasi khas: dalam toleransi 1-2 menit,
 * sesuai kriteria TC-JDW-001..004 pada SRS.
 */
final class PrayerTimeCalculator
{
    public const FAJR_ANGLE_KEMENAG = 20.0;
    public const ISHA_ANGLE_KEMENAG = 18.0;
    private const SUNRISE_SUNSET_ANGLE = 0.833; // koreksi refraksi atmosfer + radius matahari

    /**
     * @return array{fajr:string,sunrise:string,dhuhr:string,asr:string,maghrib:string,isha:string}
     */
    public function calculate(
        int $year,
        int $month,
        int $day,
        float $latitude,
        float $longitude,
        float $timezoneOffsetHours,
        string $madzhab,
        int $locationOffsetMinutes = 0,
        float $fajrAngle = self::FAJR_ANGLE_KEMENAG,
        float $ishaAngle = self::ISHA_ANGLE_KEMENAG
    ): array {
        $jd = $this->julianDay($year, $month, $day);
        [$decl, $eqt] = $this->sunPosition($jd);

        $dhuhr = 12.0 + $timezoneOffsetHours - ($longitude / 15.0) - $eqt;

        $hSunrise = $this->hourAngle(-self::SUNRISE_SUNSET_ANGLE, $latitude, $decl);
        $hFajr = $this->hourAngle(-$fajrAngle, $latitude, $decl);
        $hIsha = $this->hourAngle(-$ishaAngle, $latitude, $decl);

        $shadowFactor = strtolower($madzhab) === 'hanafi' ? 2 : 1;
        $asrAltitude = rad2deg(atan(1.0 / ($shadowFactor + tan(deg2rad(abs($latitude - $decl))))));
        $hAsr = $this->hourAngle($asrAltitude, $latitude, $decl);

        $offsetHours = $locationOffsetMinutes / 60.0;

        return [
            'fajr' => $this->hoursToHHMM($dhuhr - $hFajr + $offsetHours),
            'sunrise' => $this->hoursToHHMM($dhuhr - $hSunrise + $offsetHours),
            'dhuhr' => $this->hoursToHHMM($dhuhr + $offsetHours),
            'asr' => $this->hoursToHHMM($dhuhr + $hAsr + $offsetHours),
            'maghrib' => $this->hoursToHHMM($dhuhr + $hSunrise + $offsetHours),
            'isha' => $this->hoursToHHMM($dhuhr + $hIsha + $offsetHours),
        ];
    }

    private function julianDay(int $year, int $month, int $day): float
    {
        if ($month <= 2) {
            $year -= 1;
            $month += 12;
        }
        $a = intdiv($year, 100);
        $b = 2 - $a + intdiv($a, 4);
        return floor(365.25 * ($year + 4716)) + floor(30.6001 * ($month + 1)) + $day + $b - 1524.5;
    }

    /** @return array{0:float,1:float} [deklinasi (derajat), equation of time (jam)] */
    private function sunPosition(float $jd): array
    {
        $d = $jd - 2451545.0;
        $g = $this->fixAngle(357.529 + 0.98560028 * $d);
        $q = $this->fixAngle(280.459 + 0.98564736 * $d);
        $l = $this->fixAngle($q + 1.915 * sin(deg2rad($g)) + 0.020 * sin(deg2rad(2 * $g)));
        $e = 23.439 - 0.00000036 * $d;

        $ra = rad2deg(atan2(cos(deg2rad($e)) * sin(deg2rad($l)), cos(deg2rad($l)))) / 15.0;
        $ra = $this->fixHour($ra);
        $decl = rad2deg(asin(sin(deg2rad($e)) * sin(deg2rad($l))));
        $eqt = ($q / 15.0) - $ra;

        return [$decl, $eqt];
    }

    /** Sudut ketinggian matahari (derajat, negatif = di bawah horizon) -> selisih jam dari tengah hari. */
    private function hourAngle(float $altitudeDeg, float $latitudeDeg, float $declDeg): float
    {
        $lat = deg2rad($latitudeDeg);
        $decl = deg2rad($declDeg);
        $alt = deg2rad($altitudeDeg);

        $cosH = (sin($alt) - sin($lat) * sin($decl)) / (cos($lat) * cos($decl));
        $cosH = max(-1.0, min(1.0, $cosH)); // clamp — hindari domain error di lintang ekstrem

        return rad2deg(acos($cosH)) / 15.0;
    }

    private function fixAngle(float $angle): float
    {
        $a = fmod($angle, 360.0);
        return $a < 0 ? $a + 360.0 : $a;
    }

    private function fixHour(float $hour): float
    {
        $h = fmod($hour, 24.0);
        return $h < 0 ? $h + 24.0 : $h;
    }

    private function hoursToHHMM(float $hours): string
    {
        $h = fmod($hours, 24.0);
        if ($h < 0) {
            $h += 24.0;
        }
        $totalMinutes = (int) round($h * 60);
        $totalMinutes %= (24 * 60);
        if ($totalMinutes < 0) {
            $totalMinutes += 24 * 60;
        }
        return sprintf('%02d:%02d', intdiv($totalMinutes, 60), $totalMinutes % 60);
    }
}
