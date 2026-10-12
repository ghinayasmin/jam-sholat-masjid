<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Config;
use App\Db\Database;
use App\Repository\SettingsRepository;

/**
 * REQ-F-004 (Should): perbarui jadwal sholat dari sumber online ketika
 * tersedia koneksi internet.
 *
 * Provider: AlAdhan API (https://aladhan.com), method id=20 ("Kementerian
 * Agama Republik Indonesia"/KEMENAG) — dipilih karena:
 *  1) sudut Fajr(20°)/Isha(18°) yang dipakai IDENTIK dengan yang dipakai
 *     PrayerTimeCalculator lokal (REQ-F-006), jadi hasil online & offline
 *     konsisten, tidak membingungkan jamaah saat berpindah sumber;
 *  2) gratis, tanpa API key, endpoint sederhana (GET, JSON);
 *  3) menerima koordinat lat/long langsung — cocok dengan REQ-F-003
 *     (location offset berbasis koordinat, bukan nama kota tetap).
 * Lihat docs/DECISIONS.md untuk detail pertimbangan.
 *
 * Fitur ini bersifat best-effort: jika tidak ada koneksi internet, sistem
 * tetap berjalan penuh dari PrayerTimeCalculator lokal (REQ-F-001, Must) —
 * kegagalan sync TIDAK BOLEH membuat sistem berhenti (REQ-NF-112).
 */
final class ScheduleSync
{
    public function __construct(
        private readonly SettingsRepository $settingsRepo = new SettingsRepository()
    ) {
    }

    /** @return array{success:bool,message:string} */
    public function syncDate(string $dateYmd): array
    {
        $settings = $this->settingsRepo->get();
        if ((int) $settings['online_sync_enabled'] !== 1) {
            return ['success' => false, 'message' => 'Online sync dinonaktifkan di pengaturan.'];
        }

        [$y, $m, $d] = explode('-', $dateYmd);
        $dateParam = sprintf('%02d-%02d-%04d', (int) $d, (int) $m, (int) $y); // DD-MM-YYYY

        $school = strtolower((string) $settings['madzhab']) === 'hanafi' ? 1 : 0;
        $baseUrl = Config::get('aladhan_base_url', 'https://api.aladhan.com/v1');
        $url = sprintf(
            // tune = ihtiyat menit (Imsak,Fajr,Sunrise,Dhuhr,Asr,Maghrib,Sunset,Isha,Midnight).
            // AlAdhan memberi waktu astronomis yang sudah dibulatkan, jadi nilai ini
            // dipilih agar tidak pernah lebih awal dari jadwal Kemenag (DECISIONS.md #19).
            '%s/timings/%s?latitude=%s&longitude=%s&method=20&school=%d&tune=0,3,-4,4,3,4,0,3,0',
            $baseUrl,
            $dateParam,
            rawurlencode((string) $settings['latitude']),
            rawurlencode((string) $settings['longitude']),
            $school
        );

        $json = $this->fetchWithTimeout($url, 5);
        if ($json === null) {
            $this->recordSyncResult(false, 'Tidak dapat menghubungi server online (offline atau timeout).');
            return ['success' => false, 'message' => 'Tidak dapat menghubungi server online (offline atau timeout).'];
        }

        $data = json_decode($json, true);
        if (!is_array($data) || ($data['code'] ?? null) !== 200 || !isset($data['data']['timings'])) {
            $this->recordSyncResult(false, 'Respons server online tidak valid.');
            return ['success' => false, 'message' => 'Respons server online tidak valid.'];
        }

        $timings = $data['data']['timings'];

        try {
            // Label Hijriah lokal (dengan koreksi isbat takmir), bukan dari AlAdhan,
            // supaya sama dengan jadwal hasil kalkulasi lokal.
            $hijriLocal = (new ScheduleEngine())->hijriFor($dateYmd);

            $stmt = Database::connection()->prepare(
                'INSERT INTO prayer_schedules
                    (schedule_date, fajr, sunrise, dhuhr, asr, maghrib, isha, hijri_date, madzhab_used, source)
                 VALUES (:d, :fajr, :sunrise, :dhuhr, :asr, :maghrib, :isha, :hijri, :madzhab, "online")
                 ON CONFLICT(schedule_date) DO UPDATE SET
                    fajr=excluded.fajr, sunrise=excluded.sunrise, dhuhr=excluded.dhuhr,
                    asr=excluded.asr, maghrib=excluded.maghrib, isha=excluded.isha,
                    hijri_date=excluded.hijri_date, madzhab_used=excluded.madzhab_used,
                    source="online", computed_at=CURRENT_TIMESTAMP'
            );
            $stmt->execute([
                'd' => $dateYmd,
                'fajr' => $this->normalizeTime($timings['Fajr']),
                'sunrise' => $this->normalizeTime($timings['Sunrise']),
                'dhuhr' => $this->normalizeTime($timings['Dhuhr']),
                'asr' => $this->normalizeTime($timings['Asr']),
                'maghrib' => $this->normalizeTime($timings['Maghrib']),
                'isha' => $this->normalizeTime($timings['Isha']),
                'hijri' => $hijriLocal['label'],
                'madzhab' => $settings['madzhab'],
            ]);
        } catch (\Throwable $e) {
            $this->recordSyncResult(false, 'Gagal menyimpan hasil sync: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Gagal menyimpan hasil sync.'];
        }

        $this->recordSyncResult(true, 'Berhasil sinkron untuk ' . $dateYmd);
        return ['success' => true, 'message' => 'Berhasil sinkron untuk ' . $dateYmd];
    }

    private function normalizeTime(string $raw): string
    {
        // AlAdhan kadang mengembalikan "04:53 (WIB)" — ambil HH:MM saja.
        return substr(trim($raw), 0, 5);
    }

    private function fetchWithTimeout(string $url, int $timeoutSeconds): ?string
    {
        $context = stream_context_create([
            'http' => ['timeout' => $timeoutSeconds, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $result = @file_get_contents($url, false, $context);
        return $result === false ? null : $result;
    }

    private function recordSyncResult(bool $success, string $message): void
    {
        $this->settingsRepo->update([
            'last_sync_at' => gmdate('Y-m-d H:i:s'),
            'last_sync_status' => ($success ? 'OK: ' : 'GAGAL: ') . $message,
        ]);
    }
}
