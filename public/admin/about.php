<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

// Menu Tentang (revisi dosen 19 Okt 2026, DECISIONS.md #34).
const APP_VERSION = '1.1';
const APP_RELEASE = 'Oktober 2026';

$team = ['Azhar Maulana', 'Rahmanda Putri Setiawati', 'Ghina Yasmin Azizah', 'Muhammad Dhiwaul Akbar'];

$pageTitle = 'Tentang aplikasi';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<div class="about-hero">
    <div class="about-name">Jam Sholat Masjid</div>
    <div class="about-ver">Versi <?= APP_VERSION ?> · <?= APP_RELEASE ?></div>
    <p>Jadwal sholat, pengingat adzan dan iqomah, pengumuman, kas, murottal, dan kamera untuk masjid. Jadwal dihitung dengan kriteria Kemenag RI dan tampil di layar masjid, sementara pengurus mengatur semuanya dari HP.</p>
</div>

<section class="group" aria-labelledby="g-tim">
    <div class="label" id="g-tim">Tim pengembang</div>
    <div class="card">
        <?php foreach ($team as $name): ?>
            <div class="row" style="grid-template-columns:minmax(0,1fr)"><span class="k"><?= Format::e($name) ?></span></div>
        <?php endforeach; ?>
    </div>
</section>

<section class="group" aria-labelledby="g-dosen">
    <div class="label" id="g-dosen">Dosen pembimbing</div>
    <div class="card">
        <div class="row" style="grid-template-columns:minmax(0,1fr)"><span class="k">Faizal Makhrus, S.Kom., M.Sc., Ph.D.</span></div>
    </div>
</section>

<section class="group" aria-labelledby="g-kampus">
    <div class="label" id="g-kampus">Proyek</div>
    <div class="card">
        <div class="row" style="grid-template-columns:minmax(0,1fr)"><span class="k">Capstone Project 2026<small>Program Studi Ilmu Komputer, Universitas Gadjah Mada</small></span></div>
    </div>
</section>

<section class="group" aria-labelledby="g-sumber">
    <div class="label" id="g-sumber">Sumber &amp; lisensi</div>
    <div class="card about-list">
        <div class="row" style="grid-template-columns:minmax(0,1fr)"><span class="k">Waktu sholat<small>Dihitung di perangkat dengan kriteria Kemenag RI (Subuh 20°, Isya 18°, dengan ihtiyat).</small></span></div>
        <div class="row" style="grid-template-columns:minmax(0,1fr)"><span class="k">Adzan<small>Rekaman Internet Archive (AdzanMisyariRasyid), label CC0 dari pengunggah.</small></span></div>
        <div class="row" style="grid-template-columns:minmax(0,1fr)"><span class="k">Iqomah<small>Wikimedia Commons, Iqamah.ogg, CC0.</small></span></div>
        <div class="row" style="grid-template-columns:minmax(0,1fr)"><span class="k">Murottal<small>Aaqib Azeez, Wikimedia Commons, CC0 dan CC BY-SA 4.0. Rincian per surat di public/assets/audio/CREDITS.txt.</small></span></div>
        <div class="row" style="grid-template-columns:minmax(0,1fr)"><span class="k">Huruf<small>Plus Jakarta Sans dan Reem Kufi, SIL Open Font License.</small></span></div>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
