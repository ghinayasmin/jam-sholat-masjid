<?php

declare(strict_types=1);

/**
 * Buat database dari database/schema.sql bila belum ada (dipakai installer Windows,
 * packaging/windows/setup.ps1). File terpisah, bukan `php -r`, karena PowerShell 5.1
 * membuang tanda kutip ganda di argumen program lain sehingga kode PHP-nya rusak.
 *
 *   php scripts/init_db_cli.php <path-database>
 */

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    fwrite(STDERR, "Pemakaian: php scripts/init_db_cli.php <path-database>\n");
    exit(1);
}

$db = $argv[1];
if (is_file($db) && filesize($db) > 0) {
    echo "Database sudah ada: $db\n";
    exit(0);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
echo "Database dibuat: $db\n";
