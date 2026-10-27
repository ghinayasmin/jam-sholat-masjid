<?php

declare(strict_types=1);

/**
 * Kembalikan akun panel takmir ke akun bawaan admin / 000 (REQ-F-072, DECISIONS.md #41).
 * Seperti tombol reset pada modem: hanya bisa dari perangkat itu sendiri. Data masjid
 * tidak terhapus, dan kata sandi wajib diganti saat masuk berikutnya.
 * Jangan dipanggil langsung; pakai:
 *
 *   sudo jamsholat-reset-password          # Raspberry Pi dan Linux
 *   Start > Jam Sholat Masjid > Reset kata sandi   # Windows (reset-password.cmd)
 *
 * --ya melewati pertanyaan konfirmasi (untuk uji otomatis).
 */

require __DIR__ . '/../bootstrap.php';

use App\Auth\DefaultAccount;
use App\Db\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$args = array_slice($_SERVER['argv'] ?? [], 1);

echo 'Kata sandi panel takmir akan dikembalikan ke kata sandi bawaan.' . PHP_EOL;
echo 'Data masjid (jadwal, pengumuman, kas, dan foto) tidak akan terhapus.' . PHP_EOL;
if (!in_array('--ya', $args, true)) {
    echo 'Lanjutkan? (y/t): ';
    $answer = strtolower(trim((string) fgets(STDIN)));
    if (!in_array($answer, ['y', 'ya'], true)) {
        echo 'Dibatalkan. Kata sandi tidak diubah.' . PHP_EOL;
        exit(1);
    }
}

DefaultAccount::reset(Database::connection());

echo PHP_EOL . 'Selesai. Silakan masuk ke panel dengan nama pengguna ' . DefaultAccount::USERNAME
    . ' dan kata sandi ' . DefaultAccount::PASSWORD . ', lalu buat kata sandi baru.' . PHP_EOL;
