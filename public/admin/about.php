<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\AppVersion;
use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

// Menu Tentang (revisi dosen 19 Okt 2026, DECISIONS.md #34). Versi dari berkas VERSION.

$team = ['Azhar Maulana', 'Rahmanda Putri Setiawati', 'Ghina Yasmin Azizah', 'Muhammad Dhiwaul Akbar'];

$pageTitle = 'Tentang aplikasi';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<div class="about-hero">
    <div class="about-name">Jam Sholat Masjid</div>
    <div class="about-ver">Versi <?= Format::e(AppVersion::number()) ?><?= AppVersion::release() !== '' ? ' · ' . Format::e(AppVersion::release()) : '' ?></div>
    <p>Jadwal sholat, pengingat adzan dan iqomah, pengumuman, kas, murottal, dan kamera untuk masjid. Berjalan di Raspberry Pi, komputer Windows, atau Linux milik masjid, tanpa perlu internet. Pengurus mengatur semuanya dari HP lewat WiFi masjid.</p>
</div>

<section class="group" aria-labelledby="g-tim">
    <div class="label" id="g-tim">Tim pengembang</div>
    <div class="card">
        <?php foreach ($team as $name): ?>
            <div class="row solo"><span class="k"><?= Format::e($name) ?></span></div>
        <?php endforeach; ?>
    </div>
</section>

<section class="group" aria-labelledby="g-dosen">
    <div class="label" id="g-dosen">Dosen pembimbing</div>
    <div class="card">
        <div class="row solo"><span class="k">Faizal Makhrus, S.Kom., M.Sc., Ph.D.</span></div>
    </div>
</section>

<section class="group" aria-labelledby="g-kampus">
    <div class="label" id="g-kampus">Proyek</div>
    <div class="card">
        <div class="row solo"><span class="k">Capstone Project 2026<small>Program Studi Ilmu Komputer, Universitas Gadjah Mada</small></span></div>
    </div>
</section>

<section class="group" aria-labelledby="g-sumber">
    <div class="label" id="g-sumber">Sumber &amp; lisensi</div>
    <div class="card about-list">
        <div class="row solo"><span class="k">Waktu sholat<small>Dihitung di perangkat dengan kriteria Kemenag RI (Subuh 20°, Isya 18°, dengan ihtiyat).</small></span></div>
        <div class="row solo"><span class="k">Adzan<small>Rekaman adzan Masjidil Haram dari Internet Archive, ditandai CC0 oleh pengunggahnya.</small></span></div>
        <div class="row solo"><span class="k">Iqomah<small>Syaikh Abdul Karim Al-Makki (gaya Madinah), dari Internet Archive, koleksi "IQOMAH" (archive.org/details/IQOMAH). Pengunggah tidak mencantumkan lisensi.</small></span></div>
        <div class="row solo"><span class="k">Murottal<small>Bacaan Aaqib Azeez dari Wikimedia Commons, lisensi CC0 dan CC BY-SA 4.0, dikompres ke MP3 64 kbps. Sumber dan lisensi tiap surat: <a href="/assets/audio/CREDITS.txt" target="_blank" rel="noopener">daftar sumber audio</a>.</small></span></div>
        <div class="row solo"><span class="k">Huruf<small>Plus Jakarta Sans dan Reem Kufi, SIL Open Font License.</small></span></div>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
