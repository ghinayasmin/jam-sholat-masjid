<?php

declare(strict_types=1);

namespace App\Backup;

use App\Config;
use App\Db\Database;
use App\Repository\BackupRepository;
use RuntimeException;

/**
 * REQ-F-050 (Data Backup), REQ-F-051 (Data Restore).
 *
 * Scope backup mengikuti §5.6 SRS secara literal: "data pengaturan,
 * laporan keuangan, dan publikasi" — TIDAK termasuk motion_events
 * (rekaman kamera), karena SRS tidak menyebutnya dan volumenya besar
 * (lihat asumsi di analisis SRS, bagian E "Catatan desain").
 *
 * Format backup: JSON tunggal (mudah dibaca ulang & divalidasi sebelum
 * restore, tidak butuh dependency ZipArchive yang belum tentu ada).
 * File publikasi (gambar) disalin sebagai base64 di dalam JSON yang sama
 * supaya satu file backup = satu file yang mandiri (self-contained),
 * mempermudah proses restore secara atomik (REQ-F-051: TC-BKP-002).
 */
final class BackupManager
{
    public function __construct(
        private readonly BackupRepository $backupRepo = new BackupRepository()
    ) {
    }

    public function createBackup(string $type = 'manual'): array
    {
        $pdo = Database::connection();

        $settings = $pdo->query('SELECT * FROM mosque_settings WHERE id = 1')->fetch();
        $financial = $pdo->query('SELECT * FROM financial_entries ORDER BY id')->fetchAll();
        $publications = $pdo->query('SELECT * FROM publications ORDER BY id')->fetchAll();

        $publicationsPath = (string) Config::get('publications_path');
        foreach ($publications as &$pub) {
            $fullPath = $publicationsPath . '/' . basename($pub['file_path']);
            $pub['file_base64'] = $pub['file_path'] !== '' && is_file($fullPath) ? base64_encode((string) file_get_contents($fullPath)) : null;
        }
        unset($pub);

        // Foto masjid ikut dicadangkan (DECISIONS.md #28).
        $photoPath = $publicationsPath . '/' . basename((string) ($settings['mosque_photo'] ?? ''));
        $photoBase64 = ($settings['mosque_photo'] ?? '') !== '' && is_file($photoPath)
            ? base64_encode((string) file_get_contents($photoPath))
            : null;

        $payload = [
            'backup_version' => 1,
            'created_at' => gmdate('c'),
            'mosque_settings' => $settings,
            'financial_entries' => $financial,
            'publications' => $publications,
            'mosque_photo_base64' => $photoBase64,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Gagal membuat backup: encoding JSON gagal.');
        }

        $backupsDir = (string) Config::get('backups_path');
        if (!is_dir($backupsDir)) {
            mkdir($backupsDir, 0775, true);
        }
        $fileName = 'backup-' . gmdate('Ymd-His') . '.json';
        $filePath = $backupsDir . '/' . $fileName;
        file_put_contents($filePath, $json);

        $id = $this->backupRepo->create($fileName, $filePath, strlen($json), $type);

        return ['id' => $id, 'file_name' => $fileName, 'file_path' => $filePath, 'size_bytes' => strlen($json)];
    }

    /** @throws RuntimeException jika file backup tidak valid — REQ-F-051 pass criteria: data pulih tanpa kehilangan. */
    public function restoreFromFile(string $filePath): void
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('File backup tidak ditemukan.');
        }
        $json = file_get_contents($filePath);
        $data = json_decode((string) $json, true);

        if (!is_array($data) || !isset($data['backup_version'], $data['mosque_settings'])) {
            throw new RuntimeException('File backup tidak valid atau rusak.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            // mosque_settings
            $s = $data['mosque_settings'];
            $stmt = $pdo->prepare(
                'UPDATE mosque_settings SET
                    mosque_name=:mosque_name, mosque_address=:mosque_address, latitude=:latitude,
                    longitude=:longitude, timezone_offset_hours=:timezone_offset_hours, madzhab=:madzhab,
                    location_offset_minutes=:location_offset_minutes,
                    azan_iqomah_interval_minutes=:azan_iqomah_interval_minutes,
                    image_retention_days=:image_retention_days,
                    image_retention_storage_pct=:image_retention_storage_pct,
                    online_sync_enabled=:online_sync_enabled,
                    reminder_enabled=:reminder_enabled, reminder_minutes=:reminder_minutes,
                    reminder_friday_minutes=:reminder_friday_minutes, reminder_message=:reminder_message,
                    show_finance_on_display=:show_finance_on_display, location_name=:location_name,
                    adzan_sound_enabled=:adzan_sound_enabled, iqomah_sound_enabled=:iqomah_sound_enabled,
                    hijri_offset_days=:hijri_offset_days,
                    poster_gap_seconds=:poster_gap_seconds, poster_show_seconds=:poster_show_seconds,
                    mosque_photo=:mosque_photo,
                    iqomah_fajr_minutes=:iqomah_fajr_minutes, iqomah_dhuhr_minutes=:iqomah_dhuhr_minutes,
                    iqomah_asr_minutes=:iqomah_asr_minutes, iqomah_maghrib_minutes=:iqomah_maghrib_minutes,
                    iqomah_isha_minutes=:iqomah_isha_minutes,
                    updated_at=CURRENT_TIMESTAMP
                 WHERE id = 1'
            );
            $stmt->execute([
                'mosque_name' => $s['mosque_name'], 'mosque_address' => $s['mosque_address'],
                'latitude' => $s['latitude'], 'longitude' => $s['longitude'],
                'timezone_offset_hours' => $s['timezone_offset_hours'], 'madzhab' => $s['madzhab'],
                'location_offset_minutes' => $s['location_offset_minutes'],
                'azan_iqomah_interval_minutes' => $s['azan_iqomah_interval_minutes'],
                'image_retention_days' => $s['image_retention_days'],
                'image_retention_storage_pct' => $s['image_retention_storage_pct'],
                'online_sync_enabled' => $s['online_sync_enabled'],
                // Backup lama (sebelum kolom ini ada) memakai nilai bawaan.
                'reminder_enabled' => $s['reminder_enabled'] ?? 1,
                'reminder_minutes' => $s['reminder_minutes'] ?? 10,
                'reminder_friday_minutes' => $s['reminder_friday_minutes'] ?? 30,
                'reminder_message' => $s['reminder_message'] ?? 'Mari bersiap dan mengambil air wudhu',
                'show_finance_on_display' => $s['show_finance_on_display'] ?? 0,
                'location_name' => $s['location_name'] ?? '',
                'adzan_sound_enabled' => $s['adzan_sound_enabled'] ?? 1,
                'iqomah_sound_enabled' => $s['iqomah_sound_enabled'] ?? 0,
                'hijri_offset_days' => $s['hijri_offset_days'] ?? 0,
                'poster_gap_seconds' => $s['poster_gap_seconds'] ?? 15,
                'poster_show_seconds' => $s['poster_show_seconds'] ?? 15,
                'mosque_photo' => basename((string) ($s['mosque_photo'] ?? '')),
                'iqomah_fajr_minutes' => $s['iqomah_fajr_minutes'] ?? 20,
                'iqomah_dhuhr_minutes' => $s['iqomah_dhuhr_minutes'] ?? 10,
                'iqomah_asr_minutes' => $s['iqomah_asr_minutes'] ?? 10,
                'iqomah_maghrib_minutes' => $s['iqomah_maghrib_minutes'] ?? 10,
                'iqomah_isha_minutes' => $s['iqomah_isha_minutes'] ?? 10,
            ]);

            // financial_entries: replace seluruhnya supaya restore idempotent & konsisten
            $pdo->exec('DELETE FROM financial_entries');
            $stmtF = $pdo->prepare(
                'INSERT INTO financial_entries (id, entry_date, description, type, amount, created_by, created_at)
                 VALUES (:id, :entry_date, :description, :type, :amount, :created_by, :created_at)'
            );
            foreach ($data['financial_entries'] ?? [] as $f) {
                $stmtF->execute([
                    'id' => $f['id'], 'entry_date' => $f['entry_date'], 'description' => $f['description'],
                    'type' => $f['type'], 'amount' => $f['amount'], 'created_by' => $f['created_by'],
                    'created_at' => $f['created_at'],
                ]);
            }

            // publications: replace seluruhnya + tulis ulang file gambar dari base64
            $pdo->exec('DELETE FROM publications');
            $publicationsPath = (string) Config::get('publications_path');
            $stmtP = $pdo->prepare(
                'INSERT INTO publications
                    (id, file_name, file_path, caption, display_order, is_active, uploaded_at, starts_on, ends_on)
                 VALUES (:id, :file_name, :file_path, :caption, :display_order, :is_active, :uploaded_at,
                    :starts_on, :ends_on)'
            );
            foreach ($data['publications'] ?? [] as $p) {
                $stmtP->execute([
                    'id' => $p['id'], 'file_name' => $p['file_name'], 'file_path' => $p['file_path'],
                    'caption' => $p['caption'], 'display_order' => $p['display_order'],
                    'is_active' => $p['is_active'], 'uploaded_at' => $p['uploaded_at'],
                    'starts_on' => $p['starts_on'] ?? null, 'ends_on' => $p['ends_on'] ?? null,
                ]);
                if (!empty($p['file_base64']) && $p['file_path'] !== '') {
                    $target = $publicationsPath . '/' . basename($p['file_path']);
                    file_put_contents($target, base64_decode($p['file_base64']));
                }
            }

            $photo = basename((string) ($s['mosque_photo'] ?? ''));
            if ($photo !== '' && !empty($data['mosque_photo_base64'])) {
                file_put_contents($publicationsPath . '/' . $photo, base64_decode($data['mosque_photo_base64']));
            } elseif ($photo !== '' && !is_file($publicationsPath . '/' . $photo)) {
                $pdo->exec("UPDATE mosque_settings SET mosque_photo = '' WHERE id = 1");
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException('Restore gagal, data tidak diubah: ' . $e->getMessage());
        }
    }
}
