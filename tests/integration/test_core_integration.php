<?php

declare(strict_types=1);

/**
 * Integration test: DB + Repository layer + Auth + Backup/Restore end-to-end.
 * Dijalankan lewat: php tests/integration/test_core_integration.php
 *
 * Memakai database SQLite sementara terpisah (tests/integration/tmp/) —
 * TIDAK menyentuh storage/jamsholat.db yang sesungguhnya.
 */

$root = dirname(__DIR__, 2);
require $root . '/src/Config.php';

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Auth\AuthManager;
use App\Backup\BackupManager;
use App\Repository\AdminUserRepository;
use App\Repository\FinancialRepository;
use App\Repository\PublicationRepository;
use App\Repository\SettingsRepository;
use App\Schedule\ScheduleEngine;

$tmpDir = __DIR__ . '/tmp';
if (is_dir($tmpDir)) {
    array_map('unlink', glob($tmpDir . '/*'));
} else {
    mkdir($tmpDir, 0775, true);
}
mkdir($tmpDir . '/publications', 0775, true);
mkdir($tmpDir . '/backups', 0775, true);
mkdir($tmpDir . '/sessions', 0775, true);

$dbPath = $tmpDir . '/test.db';
exec('sqlite3 ' . escapeshellarg($dbPath) . ' < ' . escapeshellarg($root . '/database/schema.sql'));

App\Config::override([
    'db_path' => $dbPath,
    'publications_path' => $tmpDir . '/publications',
    'captures_path' => $tmpDir . '/captures',
    'backups_path' => $tmpDir . '/backups',
    'session_path' => $tmpDir . '/sessions',
    'session_idle_timeout' => 1800,
]);

$failures = 0;
$total = 0;
function check(string $label, bool $cond): void
{
    global $failures, $total;
    $total++;
    echo ($cond ? 'OK   - ' : 'FAIL - ') . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

// --- Settings ---
$settingsRepo = new SettingsRepository();
check('Settings: default madzhab = syafii', $settingsRepo->get()['madzhab'] === 'syafii');
$settingsRepo->update(['madzhab' => 'hanafi']);
check('Settings: update tersimpan', $settingsRepo->get()['madzhab'] === 'hanafi');
$settingsRepo->update(['madzhab' => 'syafii']);

// --- Schedule Engine (REQ-F-001..006) ---
$engine = new ScheduleEngine();
$sched = $engine->getScheduleForDate('2026-09-21');
check('ScheduleEngine: hasil punya semua field waktu', isset($sched['fajr'], $sched['dhuhr'], $sched['isha']));
check('ScheduleEngine: cache konsisten di panggilan kedua', $engine->getScheduleForDate('2026-09-21')['fajr'] === $sched['fajr']);

// --- Auth (REQ-F-070, REQ-F-071) ---
$userRepo = new AdminUserRepository();
$userRepo->create('testadmin', password_hash('Passw0rd!', PASSWORD_BCRYPT));
$auth = new AuthManager();
$auth->startSession();
check('Auth: password salah ditolak', $auth->attempt('testadmin', 'salah') === false);
check('Auth: password benar diterima', $auth->attempt('testadmin', 'Passw0rd!') === true);
check('Auth: isLoggedIn true', $auth->isLoggedIn() === true);
$auth->logout();
check('Auth: logout membersihkan sesi', $auth->isLoggedIn() === false);

// --- Publications & Financial ---
$pubRepo = new PublicationRepository();
file_put_contents($tmpDir . '/publications/p1.jpg', 'x');
$pubRepo->create('p1.jpg', 'p1.jpg', 'Caption 1');
check('Publications: tersimpan & aktif', count($pubRepo->allActive()) === 1);

$finRepo = new FinancialRepository();
$finRepo->create('2026-09-01', 'Infaq', 'masuk', 100000, null);
$finRepo->create('2026-09-02', 'Listrik', 'keluar', 30000, null);
check('Financial: saldo berjalan benar (100000-30000=70000)', $finRepo->currentBalance() === 70000);

// --- Backup & Restore round-trip (REQ-F-050, REQ-F-051) ---
$backupMgr = new BackupManager();
$backup = $backupMgr->createBackup('manual');
check('Backup: file dibuat', is_file($backup['file_path']));

$settingsRepo->update(['mosque_name' => 'BERUBAH']);
$finRepo->create('2026-09-03', 'Entry ekstra', 'masuk', 999, null);
check('Sanity: saldo berubah sebelum restore', $finRepo->currentBalance() !== 70000);

$backupMgr->restoreFromFile($backup['file_path']);
check('Restore: saldo pulih ke 70000', $finRepo->currentBalance() === 70000);
check('Restore: mosque_name pulih (bukan "BERUBAH")', $settingsRepo->get()['mosque_name'] !== 'BERUBAH');
check('Restore: file publikasi tetap ada', is_file($tmpDir . '/publications/p1.jpg'));

echo "\n=== $total assertion, " . ($failures === 0 ? 'SEMUA LULUS' : "$failures GAGAL") . " ===\n";
exit($failures === 0 ? 0 : 1);
