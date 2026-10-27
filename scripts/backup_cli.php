#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * backup_cli.sh (wrapper) memanggil file PHP ini, REQ-F-050 lewat command
 * line, berguna untuk dijadwalkan via cron/systemd timer terpisah dari
 * panel admin, atau dipanggil manual oleh developer.
 *
 * Usage: php scripts/backup_cli.php
 */

require __DIR__ . '/../bootstrap.php';

use App\Backup\BackupManager;

try {
    $manager = new BackupManager();
    $result = $manager->createBackup('scheduled');
    echo "Backup berhasil: {$result['file_name']} ({$result['size_bytes']} bytes)\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Backup gagal: ' . $e->getMessage() . "\n");
    exit(1);
}
