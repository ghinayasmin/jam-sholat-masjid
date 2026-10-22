<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Platform;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

// Panduan singkat di panel (#40), tetap bisa dibuka tanpa internet. Langkah yang
// berbeda per perangkat mengikuti Platform::kind().
$kind = Platform::kind();
$isPi = $kind === Platform::PI;
$isWin = $kind === Platform::WINDOWS;
$device = ['pi' => 'Raspberry Pi', 'windows' => 'komputer Windows', 'linux-desktop' => 'komputer Linux', 'linux-server' => 'komputer Linux'][$kind];

$pageTitle = 'Panduan';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<div class="note">Panduan untuk <?= $device ?> ini. Ketuk judul untuk membuka.</div>

<div class="help">

<details id="mulai" open>
    <summary>Pertama kali dipakai</summary>
    <ol>
        <li>Pengaturan → <a href="/admin/settings_location.php">Lokasi</a>: isi koordinat masjid (dari Google Maps: tekan lama di masjid, salin angkanya). Tanpa ini jadwal memakai Yogyakarta.</li>
        <li>Pengaturan → <a href="/admin/mosque_profile.php">Profil &amp; foto masjid</a>: nama, alamat, dan foto.</li>
        <li><a href="/admin/account.php">Akun takmir</a>: simpan kode pemulihan di tempat aman.</li>
        <li>Cek Beranda → Kondisi sistem: <b>Layanan adzan · Berjalan</b>.</li>
    </ol>
</details>

<details id="layar">
    <summary>Membuka dan menutup layar jamaah</summary>
    <?php if ($isPi): ?>
        <p>Di Raspberry Pi, layar jamaah menyala sendiri setiap kali perangkat dinyalakan. Tidak ada yang perlu dibuka atau ditutup.</p>
    <?php else: ?>
        <ul>
            <li><b>Buka:</b> <?= $isWin ? 'Start → <b>Layar Jamaah</b>' : 'menu aplikasi → <b>Layar Jamaah</b>' ?>. Bila layar sudah terbuka tapi tertutup jendela lain, menu yang sama membawanya ke depan.</li>
            <li><b>Tutup:</b> tekan <b>Alt+F4</b>. Suara adzan tetap berbunyi walau layar ditutup.</li>
            <li>Setelah komputer dinyalakan dan masuk (login), layar jamaah terbuka sendiri.</li>
        </ul>
    <?php endif; ?>
</details>

<details id="adzan-tidak-bunyi">
    <summary>Adzan tidak berbunyi</summary>
    <ol>
        <li>Beranda → Kondisi sistem → <b>Layanan adzan</b>. Bila tertulis "tidak berjalan", <?= $isPi ? 'nyalakan ulang Raspberry Pi (cabut lalu pasang listriknya).' : 'pastikan komputer menyala dan sudah masuk (login). Lihat "Komputer menyala sendiri setelah listrik padam" di bawah.' ?></li>
        <li>Pengaturan → <a href="/admin/sound.php">Suara &amp; jeda iqomah</a>: sakelar <b>Suara adzan</b> harus menyala. Bila dimatikan, adzan memang tidak diputar (untuk masjid yang adzannya dikumandangkan muadzin).</li>
        <li>Cek speaker: kabel, tombol daya, dan volume speaker sendiri. Aplikasi menaikkan volume <?= $isPi ? 'perangkat' : 'komputer' ?> yang bisu atau terlalu kecil sebelum adzan, tapi tidak bisa menyalakan speaker yang mati.</li>
        <?php if ($isPi): ?>
            <li>Suara keluar lewat HDMI (TV) atau colokan 3,5 mm. Teknisi bisa memindahkannya dengan <code>sudo bash scripts/set_audio_output.sh</code>.</li>
        <?php endif; ?>
    </ol>
</details>

<?php if (!$isPi): ?>
<details id="login-otomatis">
    <summary>Komputer menyala sendiri setelah listrik padam</summary>
    <p>Suara adzan dan layar jamaah baru berjalan setelah ada yang masuk (login) ke komputer. Agar tetap berjalan setelah listrik padam saat masjid kosong:</p>
    <ol>
        <li><b>Masuk otomatis:</b>
            <?php if ($isWin): ?>
                tekan Win+R, ketik <code>netplwiz</code>, hilangkan centang "Users must enter a user name and password", lalu isi kata sandi akun. (Windows 11: bila pilihan itu tidak ada, matikan dulu Settings → Accounts → Sign-in options → "For improved security, only allow Windows Hello sign-in".)
            <?php else: ?>
                Ubuntu: Settings → System → Users → <b>Automatic Login</b>. Linux Mint: Login Window → Users → login otomatis.
            <?php endif; ?>
        </li>
        <li><b>Menyala sendiri saat listrik kembali:</b> di BIOS komputer, pilihan seperti "Restore on AC Power Loss" atau "After Power Failure" diatur ke <b>Power On</b>. Minta bantuan teknisi bila perlu.</li>
    </ol>
    <p class="aside">Masuk otomatis berarti siapa pun yang menyalakan komputer langsung masuk. Gunakan akun khusus masjid, bukan akun pribadi.</p>
</details>
<?php endif; ?>

<details id="hp">
    <summary>Membuka panel dari HP</summary>
    <p>Buka <a href="/admin/phone.php">Buka panel dari HP</a> di komputer ini, lalu pindai kode QR dengan kamera HP. HP harus tersambung ke WiFi yang sama. Cetak kodenya dan tempel di dekat layar agar pengurus lain mudah membuka panel.</p>
    <p class="aside">Bila alamatnya berubah-ubah, minta teknisi mengatur "IP tetap" (DHCP reservation) di router untuk perangkat ini.</p>
</details>

<details id="pengumuman">
    <summary>Poster dan pengumuman</summary>
    <ul>
        <li>Tab Pengumuman → <b>+ Pasang pengumuman</b>. Poster mendatar (16:9) tampil layar penuh; poster tegak tampil di samping jadwal.</li>
        <li>Isi <b>Waktu acara</b> agar tanggal dan jamnya tampil di layar, dan poster hilang sendiri setelah acara.</li>
        <li>Poster yang salah: ketuk <b>Ubah</b> untuk mengganti teks, tanggal, atau gambarnya.</li>
    </ul>
</details>

<details id="murottal">
    <summary>Murottal</summary>
    <ul>
        <li>Beranda → Murottal: Putar, Jeda, Sebelumnya, Berikutnya. <b>Jeda</b> menyimpan posisi; Putar melanjutkan dari detik yang sama.</li>
        <li>Murottal berhenti sendiri sekitar waktu sholat dan menyala lagi setelah sholat berjamaah.</li>
    </ul>
</details>

<details id="sandi">
    <summary>Lupa kata sandi</summary>
    <ul>
        <li>Di halaman masuk, ketuk <b>Lupa kata sandi</b> dan masukkan kode pemulihan.</li>
        <li>Kode pemulihan juga hilang: teknisi menjalankan
            <?= $isWin ? 'Start → <b>Reset kata sandi</b> di komputer ini' : '<code>sudo jamsholat-reset-password</code> di perangkat ini' ?>.</li>
    </ul>
</details>

<details id="cadangan">
    <summary>Cadangan data</summary>
    <p>Pengaturan → <a href="/admin/backup.php">Cadangan data</a> → Buat cadangan sekarang, lalu <b>Unduh</b> dan simpan di HP atau flashdisk. Cadangan berisi pengaturan, kas, dan pengumuman, dan bisa dipulihkan di perangkat baru.</p>
</details>

</div>

<?php require __DIR__ . '/_footer.php'; ?>
