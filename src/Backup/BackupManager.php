<?php

declare(strict_types=1);

namespace App\Backup;

use App\Config;
use App\Db\Database;
use App\Repository\BackupRepository;
use App\Repository\SettingsRepository;
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
            // mosque_settings: semua kolom pengaturan yang ada di cadangan dan di tabel.
            // Cadangan lama yang belum punya kolom baru: kolom itu tetap bernilai sekarang.
            $s = $data['mosque_settings'];
            $fields = [];
            foreach (SettingsRepository::settingColumns() as $column) {
                if (array_key_exists($column, $s)) {
                    $fields[$column] = $s[$column];
                }
            }
            if (isset($fields['mosque_photo'])) {
                $fields['mosque_photo'] = basename((string) $fields['mosque_photo']);
            }
            (new SettingsRepository())->update($fields);

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
