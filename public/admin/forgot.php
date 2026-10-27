<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Platform;

// Lupa kata sandi (DECISIONS.md #41): seperti tombol reset pada modem, kata sandi hanya
// bisa dikembalikan ke bawaan dari perangkat tempat aplikasi terpasang, tidak dari HP.
$steps = match (Platform::kind()) {
    Platform::WINDOWS => 'Pada komputer masjid, buka menu Start, pilih <b>Jam Sholat Masjid</b>, lalu klik <b>Reset kata sandi</b>.',
    Platform::LINUX_DESKTOP => 'Pada komputer masjid, buka menu aplikasi, lalu pilih <b>Reset Kata Sandi Jam Sholat</b>. Masukkan kata sandi komputer bila diminta.',
    Platform::PI => 'Mintalah teknisi menjalankan perintah <code>sudo jamsholat-reset-password</code> pada Raspberry Pi melalui SSH.',
    default => 'Mintalah teknisi menjalankan perintah <code>sudo jamsholat-reset-password</code> pada perangkat ini.',
};
?>
<!DOCTYPE html>
<html lang="id" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="google" content="notranslate">
<meta name="theme-color" content="#F2F3EF">
<title>Lupa kata sandi · Panel Takmir</title>
<link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/admin.css') ?>">
</head>
<body>
<main class="login">
    <div style="display:grid;gap:20px;width:100%;max-width:380px">
        <div class="brand">
            <h1>Lupa kata sandi</h1>
            <p>Kata sandi dapat dikembalikan ke kata sandi bawaan melalui perangkat tempat aplikasi ini terpasang. Data masjid tidak akan terhapus.</p>
        </div>
        <div class="note"><?= $steps ?></div>
        <p class="muted" style="margin:0">Setelah itu, masuklah dengan nama pengguna dan kata sandi bawaan yang tercantum di Panduan Pengguna, lalu buat kata sandi baru.</p>
        <a class="btn ghost" href="/admin/login.php">Kembali ke halaman masuk</a>
    </div>
</main>
</body>
</html>
