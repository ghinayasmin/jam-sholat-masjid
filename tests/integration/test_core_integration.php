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
// Bersihkan sisa run sebelumnya (termasuk subfolder) supaya test selalu mulai dari kosong.
if (is_dir($tmpDir)) {
    exec('rm -rf ' . escapeshellarg($tmpDir));
}
mkdir($tmpDir, 0775, true);
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

// --- Pengaturan layar jamaah (pengingat adzan, visibilitas kas) ---
$s = $settingsRepo->get();
check('Settings: pengingat bawaan nyala 10 menit, Jumat 30', (int) $s['reminder_enabled'] === 1
    && (int) $s['reminder_minutes'] === 10 && (int) $s['reminder_friday_minutes'] === 30);
check('Settings: kas bawaan TIDAK tampil di layar jamaah', (int) $s['show_finance_on_display'] === 0);
$settingsRepo->update(['reminder_minutes' => 15, 'show_finance_on_display' => 1]);
$s = $settingsRepo->get();
check('Settings: pengingat & kas bisa diubah', (int) $s['reminder_minutes'] === 15 && (int) $s['show_finance_on_display'] === 1);

// --- Puasa sunnah: hari terlarang & Ramadhan tidak ditandai ---
$hijriConv = new App\Schedule\HijriConverter();
$fastEngine = new ScheduleEngine();
$forbiddenFlagged = 0; $ramadanFlagged = 0; $forbiddenSeen = 0; $ramadanSeen = 0;
for ($t = gmmktime(0, 0, 0, 1, 1, 2027); $t < gmmktime(0, 0, 0, 1, 1, 2028); $t += 86400) {
    $h = $hijriConv->fromGregorian((int) gmdate('Y', $t), (int) gmdate('n', $t), (int) gmdate('j', $t));
    $info = $fastEngine->getFastingInfo(gmdate('Y-m-d', $t));
    $flagged = $info['is_senin_kamis'] || $info['is_ayyamul_bidh'];
    if (($h['month'] === 10 && $h['day'] === 1) || ($h['month'] === 12 && $h['day'] >= 10 && $h['day'] <= 13)) {
        $forbiddenSeen++;
        $forbiddenFlagged += $flagged ? 1 : 0;
    }
    if ($h['month'] === 9) {
        $ramadanSeen++;
        $ramadanFlagged += $flagged ? 1 : 0;
    }
}
check('Puasa: Idul Fitri, Idul Adha & Tasyrik 2027 tidak ditandai sunnah', $forbiddenSeen === 5 && $forbiddenFlagged === 0);
check('Puasa: Ramadhan 2027 tidak ditandai sunnah', $ramadanSeen >= 29 && $ramadanFlagged === 0);
$up = $fastEngine->getUpcomingFasting('2026-10-02');
check('Puasa: terdekat setelah Jumat 2 Okt 2026 adalah Senin 5 Okt', ($up[0]['date'] ?? null) === '2026-10-05');

// --- Migrasi: database lama (sebelum kolom baru) dilengkapi otomatis ---
$legacyDb = $tmpDir . '/legacy.db';
$legacy = new PDO('sqlite:' . $legacyDb);
$legacy->exec("CREATE TABLE mosque_settings (id INTEGER PRIMARY KEY CHECK (id = 1),
    mosque_name TEXT NOT NULL DEFAULT 'Masjid', updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$legacy->exec("INSERT INTO mosque_settings (id, mosque_name) VALUES (1, 'Masjid Lama')");
$legacy = null;
App\Db\Database::reset();
App\Config::override(['db_path' => $legacyDb]);
$s = (new SettingsRepository())->get();
check('Migrasi: kolom pengingat ditambahkan dengan nilai bawaan', (int) $s['reminder_minutes'] === 10
    && $s['reminder_message'] === 'Mari bersiap dan mengambil air wudhu');
check('Migrasi: kas bawaan tersembunyi', (int) $s['show_finance_on_display'] === 0);
check('Migrasi: data lama tetap utuh', $s['mosque_name'] === 'Masjid Lama');
App\Db\Database::reset();
check('Migrasi: aman dijalankan ulang', (int) (new SettingsRepository())->get()['reminder_friday_minutes'] === 30);

echo "\n=== $total assertion, " . ($failures === 0 ? 'SEMUA LULUS' : "$failures GAGAL") . " ===\n";
exit($failures === 0 ? 0 : 1);
