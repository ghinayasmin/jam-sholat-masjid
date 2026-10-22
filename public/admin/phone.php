<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Platform;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

// Buka panel dari HP (#40): alamat jaringan lokal komputer ini + kode QR yang bisa
// dicetak dan ditempel di dekat layar. Port ikut alamat yang sedang dipakai (Windows
// memakai 8080 bila port 80 terpakai).
$port = (int) ($_SERVER['SERVER_PORT'] ?? 80);
$suffix = $port === 80 ? '' : ':' . $port;
$addresses = Platform::lanAddresses($_SERVER['SERVER_ADDR'] ?? null, $_SERVER['HTTP_HOST'] ?? null);
$urls = array_map(static fn (string $ip): string => 'http://' . $ip . $suffix . '/admin/', $addresses);
$kind = Platform::kind();
$host = strtolower((string) gethostname());
$mdns = $kind !== Platform::WINDOWS && $host !== '' && !str_contains($host, '.')
    ? 'http://' . $host . '.local' . $suffix . '/admin/' : null;

$pageTitle = 'Buka panel dari HP';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<?php if ($urls === []): ?>
    <div class="flash flash-error" role="alert">Alamat jaringan perangkat ini belum ditemukan. Pastikan perangkat tersambung ke WiFi atau kabel LAN masjid, kemudian muat ulang halaman ini.</div>
<?php else: ?>
    <div class="card phone-qr">
        <div class="qr" data-qr="<?= Format::e($urls[0]) ?>" role="img" aria-label="Kode QR alamat panel takmir"></div>
        <div class="qr-url"><?= Format::e($urls[0]) ?></div>
        <p>Arahkan kamera HP ke kode ini, kemudian ketuk tautan yang muncul. Pastikan HP tersambung ke <b>jaringan WiFi yang sama</b> dengan perangkat ini.</p>
        <?php foreach (array_slice($urls, 1) as $other): ?>
            <p class="aside tight">Alamat lain perangkat ini: <?= Format::e($other) ?></p>
        <?php endforeach; ?>
        <?php if ($mdns !== null): ?>
            <p class="aside tight">Alamat ini juga dapat diketik langsung: <?= Format::e($mdns) ?>. Alamat tersebut mungkin tidak dikenali oleh sebagian HP Android.</p>
        <?php endif; ?>
    </div>
    <button type="button" class="btn ghost no-print" data-print>Cetak kode QR</button>
    <div class="note no-print">Alamat ini dapat berubah apabila router memberikan alamat baru, misalnya setelah router dinyalakan ulang. Agar alamat tidak berubah, mintalah teknisi mengatur IP tetap (DHCP reservation) untuk perangkat ini pada router. Lihat <a href="/admin/help.php#hp">Panduan</a>.</div>
<?php endif; ?>

<script src="/assets/js/vendor/qrcode-generator-1.4.4.js?v=1"></script>
<?php require __DIR__ . '/_footer.php'; ?>
