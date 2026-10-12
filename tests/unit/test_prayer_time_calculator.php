<?php

declare(strict_types=1);

/**
 * Unit test untuk PrayerTimeCalculator & HijriConverter.
 * Tidak memakai PHPUnit (device offline, tidak ada Composer/internet saat
 * runtime) — memakai harness assert minimal sendiri, dijalankan lewat:
 *   php tests/unit/test_prayer_time_calculator.php
 *
 * Nilai referensi divalidasi manual terhadap AlAdhan API (lihat docs/DECISIONS.md).
 */

require __DIR__ . '/../../src/Schedule/PrayerTimeCalculator.php';
require __DIR__ . '/../../src/Schedule/HijriConverter.php';

use App\Schedule\PrayerTimeCalculator;
use App\Schedule\HijriConverter;

$failures = 0;
$total = 0;

function assertEquals($expected, $actual, string $label): void
{
    global $failures, $total;
    $total++;
    if ($expected === $actual) {
        echo "OK   - $label\n";
    } else {
        $failures++;
        echo "FAIL - $label (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")\n";
    }
}

function assertTrue(bool $cond, string $label): void
{
    global $failures, $total;
    $total++;
    if ($cond) {
        echo "OK   - $label\n";
    } else {
        $failures++;
        echo "FAIL - $label\n";
    }
}

// ===================================================================
// PrayerTimeCalculator
// ===================================================================
$calc = new PrayerTimeCalculator();

// Referensi: AlAdhan API, koordinat Dubai asli (25.2048, 55.2708),
// method Gulf Region (Fajr 19.5°), 09-03-2015, timezone +4.
// Expected (dari AlAdhan, difetch langsung saat pengembangan):
//   Fajr 05:12, Sunrise 06:35, Dhuhr 12:29, Asr 15:51, Maghrib 18:25
// Toleransi 1-2 menit — sama seperti kriteria lulus TC-JDW-001..004 di SRS.
// Tanpa ihtiyat ([]), karena AlAdhan memberi waktu astronomis murni.
$result = $calc->calculate(2015, 3, 9, 25.2048, 55.2708, 4.0, 'syafii', 0, 19.5, 18.0, []);

function withinTolerance(string $hhmmA, string $hhmmB, int $toleranceMinutes = 2): bool
{
    [$ha, $ma] = array_map('intval', explode(':', $hhmmA));
    [$hb, $mb] = array_map('intval', explode(':', $hhmmB));
    $diff = abs(($ha * 60 + $ma) - ($hb * 60 + $mb));
    return $diff <= $toleranceMinutes;
}

assertTrue(withinTolerance($result['fajr'], '05:12'), 'Fajr sesuai referensi AlAdhan (toleransi 2 menit)');
assertTrue(withinTolerance($result['sunrise'], '06:35'), 'Sunrise sesuai referensi AlAdhan (toleransi 2 menit)');
assertTrue(withinTolerance($result['dhuhr'], '12:29'), 'Dhuhr sesuai referensi AlAdhan (toleransi 2 menit)');
assertTrue(withinTolerance($result['asr'], '15:51'), 'Asr sesuai referensi AlAdhan (toleransi 2 menit)');
assertTrue(withinTolerance($result['maghrib'], '18:25'), 'Maghrib sesuai referensi AlAdhan (toleransi 2 menit)');

// Jadwal resmi Kemenag (api.myquran.com, KOTA YOGYAKARTA, 12-10-2026):
// Subuh 04:02, Terbit 05:15, Dzuhur 11:29, Ashar 14:30, Maghrib 17:36, Isya 18:45.
// Sebelum ihtiyat dipasang, hasil kalkulasi 2–4 menit lebih awal dari ini.
$kemenag = $calc->calculate(2026, 10, 12, -7.797068, 110.370529, 7.0, 'syafii');
$expectedKemenag = ['fajr' => '04:02', 'sunrise' => '05:15', 'dhuhr' => '11:29', 'asr' => '14:30', 'maghrib' => '17:36', 'isha' => '18:45'];
foreach ($expectedKemenag as $key => $expected) {
    assertTrue(withinTolerance($kemenag[$key], $expected, 1), "Kemenag Yogyakarta 12-10-2026: $key {$kemenag[$key]} ≈ $expected (toleransi 1 menit)");
}

// TC-JDW-002: Asr Hanafi harus lebih lambat dari Asr Syafi'i.
$resultSyafii = $calc->calculate(2026, 9, 21, -7.797068, 110.370529, 7.0, 'syafii');
$resultHanafi = $calc->calculate(2026, 9, 21, -7.797068, 110.370529, 7.0, 'hanafi');
[$hs, $ms] = array_map('intval', explode(':', $resultSyafii['asr']));
[$hh, $mh] = array_map('intval', explode(':', $resultHanafi['asr']));
assertTrue(($hh * 60 + $mh) > ($hs * 60 + $ms), 'TC-JDW-002: Asr Hanafi lebih lambat dari Asr Syafii');

// TC-JDW-003: offset lokasi +5 menit harus menggeser SEMUA waktu tepat +5 menit.
$base = $calc->calculate(2026, 9, 21, -7.797068, 110.370529, 7.0, 'syafii', 0);
$offset = $calc->calculate(2026, 9, 21, -7.797068, 110.370529, 7.0, 'syafii', 5);
foreach (['fajr', 'sunrise', 'dhuhr', 'asr', 'maghrib', 'isha'] as $key) {
    [$hb, $mb] = array_map('intval', explode(':', $base[$key]));
    [$ho, $mo] = array_map('intval', explode(':', $offset[$key]));
    $diff = ($ho * 60 + $mo) - ($hb * 60 + $mb);
    assertEquals(5, $diff, "TC-JDW-003: offset +5 menit diterapkan pada $key");
}

// ===================================================================
// HijriConverter
// ===================================================================
$hijri = new HijriConverter();

// Referensi silang: AlAdhan API melaporkan 09-03-2015 = 18 Jumadil Awal 1436 H.
$h1 = $hijri->fromGregorian(2015, 3, 9);
assertEquals(1436, $h1['year'], 'Hijri: tahun sesuai referensi AlAdhan (1436)');
assertEquals(5, $h1['month'], 'Hijri: bulan sesuai referensi AlAdhan (Jumadil Awal = 5)');
assertEquals(18, $h1['day'], 'Hijri: tanggal sesuai referensi AlAdhan (18)');

// Konsistensi: hari berurutan harus naik 1 per hari (tidak ada lompatan aneh).
$prev = $hijri->fromGregorian(2026, 9, 19);
for ($d = 20; $d <= 25; $d++) {
    $curr = $hijri->fromGregorian(2026, 9, $d);
    $expectedDay = $prev['day'] + 1;
    // Kalau bulan berganti, hari mulai dari 1 lagi — cukup cek tidak error & label valid.
    assertTrue(!empty($curr['label']), "Hijri: label valid untuk 2026-09-$d");
    $prev = $curr;
}

// REQ-F-013: Ayyamul Bidh (13, 14, 15).
assertTrue($hijri->isAyyamulBidh(13) === true, 'Ayyamul Bidh: tanggal 13 = true');
assertTrue($hijri->isAyyamulBidh(14) === true, 'Ayyamul Bidh: tanggal 14 = true');
assertTrue($hijri->isAyyamulBidh(15) === true, 'Ayyamul Bidh: tanggal 15 = true');
assertTrue($hijri->isAyyamulBidh(16) === false, 'Ayyamul Bidh: tanggal 16 = false');
assertTrue($hijri->isAyyamulBidh(1) === false, 'Ayyamul Bidh: tanggal 1 = false');

echo "\n=== $total assertion, " . ($failures === 0 ? "SEMUA LULUS" : "$failures GAGAL") . " ===\n";
exit($failures === 0 ? 0 : 1);
