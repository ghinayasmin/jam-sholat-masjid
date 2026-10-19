<?php

declare(strict_types=1);

/**
 * Reset kata sandi takmir dari perangkat (REQ-F-072, DECISIONS.md #33).
 * Jangan dipanggil langsung; pakai perintah yang dipasang install.sh/update.sh:
 *
 *   sudo jamsholat-reset-password              # pilih akun, ketik kata sandi baru
 *   sudo jamsholat-reset-password admin        # langsung untuk akun "admin"
 *   sudo jamsholat-reset-password --kode admin # hanya buat kode pemulihan baru
 *
 * Selalu memakai database instalasi (/opt/jamsholat), dari folder mana pun dijalankan.
 */

require __DIR__ . '/../bootstrap.php';

use App\Auth\RecoveryCode;
use App\Repository\AdminUserRepository;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$args = array_slice($argv, 1);
$codeOnly = in_array('--kode', $args, true);
$args = array_values(array_filter($args, static fn (string $a): bool => $a !== '--kode'));
$users = new AdminUserRepository();

function ask(string $prompt, bool $hidden = false): string
{
    echo $prompt;
    $tty = $hidden && function_exists('posix_isatty') ? posix_isatty(STDIN) : $hidden;
    if ($tty) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
        echo PHP_EOL;
    }
    return $line === false ? '' : rtrim($line, "\r\n");
}

$names = $users->usernames();
if ($names === []) {
    fwrite(STDERR, "Belum ada akun takmir. Jalankan install.sh untuk membuat akun pertama.\n");
    exit(1);
}

$username = $args[0] ?? '';
if ($username === '') {
    echo 'Akun takmir: ' . implode(', ', $names) . PHP_EOL;
    $username = count($names) === 1 ? $names[0] : trim(ask('Akun yang direset: '));
    if (count($names) === 1) {
        echo "Akun: $username" . PHP_EOL;
    }
}
$user = $users->findByUsername($username);
if ($user === null) {
    fwrite(STDERR, "Akun \"$username\" tidak ditemukan. Akun yang ada: " . implode(', ', $names) . "\n");
    exit(1);
}

if (!$codeOnly) {
    $new = ask('Kata sandi baru (min. 8 karakter): ', true);
    $confirm = ask('Ulangi kata sandi baru: ', true);
    if ($new !== $confirm) {
        fwrite(STDERR, "Kata sandi tidak sama, dibatalkan.\n");
        exit(1);
    }
    if (mb_strlen($new) < 8) {
        fwrite(STDERR, "Kata sandi minimal 8 karakter, dibatalkan.\n");
        exit(1);
    }
    $users->updatePasswordHash((int) $user['id'], password_hash($new, PASSWORD_BCRYPT));
    echo "Kata sandi untuk \"$username\" berhasil diganti." . PHP_EOL;
}

$code = RecoveryCode::generate();
$users->setRecoveryHash((int) $user['id'], RecoveryCode::hash($code));
echo PHP_EOL . "Kode pemulihan baru: $code" . PHP_EOL;
echo 'Berikan kode ini ke takmir untuk disimpan. Dengan kode ini takmir bisa mengganti' . PHP_EOL;
echo 'kata sandi sendiri lewat "Lupa kata sandi?" di halaman masuk panel.' . PHP_EOL;
