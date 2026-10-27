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

<div class="note">Panduan ini disesuaikan untuk <?= $device ?> ini. Ketuk judul untuk melihat penjelasan.</div>

<div class="help">

<details id="mulai" open>
    <summary>Langkah pertama</summary>
    <ol>
        <li>Buka Pengaturan, lalu <a href="/admin/settings_location.php">Lokasi</a>. Isikan koordinat masjid. Koordinat dapat diperoleh dari Google Maps dengan menekan lama titik masjid, kemudian menyalin angka yang muncul. Tanpa langkah ini, jadwal sholat menggunakan lokasi Yogyakarta.</li>
        <li>Buka Pengaturan, lalu <a href="/admin/mosque_profile.php">Profil &amp; foto masjid</a>. Isikan nama, alamat, dan foto masjid.</li>
        <li>Buka <a href="/admin/account.php">Akun takmir</a>, lalu simpan kode pemulihan di tempat yang aman.</li>
        <li>Pada Beranda, bagian Kondisi sistem, pastikan tertulis <b>Layanan adzan · Berjalan</b>.</li>
    </ol>
</details>

<details id="layar">
    <summary>Membuka dan menutup layar jamaah</summary>
    <?php if ($isPi): ?>
        <p>Pada Raspberry Pi, layar jamaah tampil dengan sendirinya setiap kali perangkat dinyalakan. Layar tidak perlu dibuka atau ditutup.</p>
    <?php else: ?>
        <ul>
            <li><b>Membuka:</b> pilih <?= $isWin ? 'Start, lalu <b>Layar Jamaah</b>' : 'menu aplikasi, lalu <b>Layar Jamaah</b>' ?>. Apabila layar sudah terbuka tetapi tertutup jendela lain, menu yang sama akan menampilkannya kembali di depan.</li>
            <li><b>Menutup:</b> tekan <b>Alt+F4</b>. Suara adzan tetap diputar meskipun layar ditutup.</li>
            <li>Setelah komputer dinyalakan dan masuk ke akun pengguna, layar jamaah terbuka dengan sendirinya.</li>
        </ul>
    <?php endif; ?>
</details>

<details id="adzan-tidak-bunyi">
    <summary>Adzan tidak terdengar</summary>
    <ol>
        <li>Pada Beranda, lihat baris <b>Layanan adzan</b> di bagian Kondisi sistem. Apabila tertulis "Tidak berjalan", <?= $isPi ? 'nyalakan ulang Raspberry Pi dengan mencabut lalu memasang kembali kabel listriknya.' : 'pastikan komputer dalam keadaan menyala dan sudah masuk ke akun pengguna. Lihat juga bagian "Komputer menyala kembali setelah listrik padam".' ?></li>
        <li>Buka Pengaturan, lalu <a href="/admin/sound.php">Suara &amp; jeda iqomah</a>. Sakelar <b>Suara adzan</b> harus dalam keadaan menyala. Apabila sakelar dimatikan, adzan memang tidak diputar. Pengaturan ini disediakan bagi masjid yang adzannya dikumandangkan langsung oleh muadzin.</li>
        <li>Periksa speaker, meliputi kabel, tombol daya, dan pengatur volume pada speaker. Aplikasi menaikkan volume <?= $isPi ? 'perangkat' : 'komputer' ?> yang dimatikan atau terlalu kecil sebelum adzan, tetapi tidak dapat menyalakan speaker yang mati.</li>
        <?php if ($isPi): ?>
            <li>Suara dikeluarkan melalui HDMI (TV) atau lubang audio 3,5 mm. Teknisi dapat memindahkan keluaran suara dengan perintah <code>sudo bash scripts/set_audio_output.sh</code>.</li>
        <?php endif; ?>
    </ol>
</details>

<?php if (!$isPi): ?>
<details id="login-otomatis">
    <summary>Komputer menyala kembali setelah listrik padam</summary>
    <p>Suara adzan dan layar jamaah baru berjalan setelah komputer masuk ke akun pengguna. Agar keduanya tetap berjalan setelah listrik padam ketika masjid kosong, lakukan dua pengaturan berikut.</p>
    <ol>
        <li><b>Masuk otomatis ke akun pengguna.</b>
            <?php if ($isWin): ?>
                Tekan Win+R, ketik <code>netplwiz</code>, hilangkan centang "Users must enter a user name and password", lalu isikan kata sandi akun. Pada Windows 11, apabila pilihan tersebut tidak tersedia, matikan terlebih dahulu pengaturan Settings → Accounts → Sign-in options → "For improved security, only allow Windows Hello sign-in".
            <?php else: ?>
                Pada Ubuntu, buka Settings → System → Users, lalu aktifkan <b>Automatic Login</b>. Pada Linux Mint, buka Login Window → Users, lalu aktifkan login otomatis.
            <?php endif; ?>
        </li>
        <li><b>Komputer menyala sendiri ketika listrik kembali.</b> Pada BIOS komputer, atur pilihan seperti "Restore on AC Power Loss" atau "After Power Failure" ke <b>Power On</b>. Mintalah bantuan teknisi apabila diperlukan.</li>
    </ol>
    <p class="aside">Dengan masuk otomatis, siapa pun yang menyalakan komputer akan langsung masuk ke akun tersebut. Gunakan akun khusus masjid, bukan akun pribadi.</p>
</details>
<?php endif; ?>

<details id="hp">
    <summary>Membuka panel dari HP</summary>
    <p>Buka halaman <a href="/admin/phone.php">Buka panel dari HP</a> pada perangkat ini, lalu pindai kode QR dengan kamera HP. Pastikan HP tersambung ke jaringan WiFi yang sama. Kode QR dapat dicetak dan ditempel di dekat layar agar pengurus lain mudah membuka panel.</p>
    <p class="aside">Apabila alamat panel sering berubah, mintalah teknisi mengatur IP tetap (DHCP reservation) untuk perangkat ini pada router.</p>
</details>

<details id="pengumuman">
    <summary>Poster dan pengumuman</summary>
    <ul>
        <li>Buka tab Pengumuman, lalu pilih <b>+ Pasang pengumuman</b>. Poster mendatar (16:9) ditampilkan memenuhi layar, sedangkan poster tegak ditampilkan di samping jadwal.</li>
        <li>Isikan <b>Waktu acara</b> agar tanggal dan jam acara tampil di layar jamaah. Pengumuman akan hilang dengan sendirinya setelah acara selesai.</li>
        <li>Untuk memperbaiki pengumuman, pilih <b>Ubah</b>. Teks, waktu acara, dan gambar poster dapat diganti.</li>
    </ul>
</details>

<details id="murottal">
    <summary>Murottal</summary>
    <ul>
        <li>Pada Beranda, bagian Murottal, tersedia tombol Putar, Jeda, Sebelumnya, dan Berikutnya. Tombol <b>Jeda</b> menyimpan posisi bacaan, sehingga murottal dapat dilanjutkan dari bagian yang sama.</li>
        <li>Murottal berhenti dengan sendirinya menjelang waktu sholat dan diputar kembali setelah sholat berjamaah selesai.</li>
    </ul>
</details>

<details id="sandi">
    <summary>Lupa kata sandi</summary>
    <p>Kata sandi dapat dikembalikan ke kata sandi bawaan melalui perangkat tempat aplikasi ini terpasang. Data masjid tidak akan terhapus.</p>
    <ul>
        <?php if ($isWin): ?>
            <li>Pada komputer masjid, buka menu Start, pilih <b>Jam Sholat Masjid</b>, lalu klik <b>Reset kata sandi</b>.</li>
        <?php elseif ($kind === Platform::LINUX_DESKTOP): ?>
            <li>Pada komputer masjid, buka menu aplikasi, lalu pilih <b>Reset Kata Sandi Jam Sholat</b>. Masukkan kata sandi komputer bila diminta.</li>
        <?php else: ?>
            <li>Mintalah teknisi menjalankan perintah <code>sudo jamsholat-reset-password</code> pada <?= $isPi ? 'Raspberry Pi melalui SSH' : 'perangkat ini' ?>.</li>
        <?php endif; ?>
        <li>Setelah itu, masuklah dengan nama pengguna dan kata sandi bawaan yang tercantum di Panduan Pengguna, lalu buat kata sandi baru.</li>
    </ul>
</details>

<details id="cadangan">
    <summary>Cadangan data</summary>
    <p>Buka Pengaturan, lalu <a href="/admin/backup.php">Cadangan data</a>. Pilih Buat cadangan sekarang, kemudian <b>Unduh</b> dan simpan berkas cadangan di HP atau flashdisk. Cadangan berisi pengaturan, catatan kas, dan pengumuman, serta dapat dipulihkan pada perangkat baru.</p>
</details>

</div>

<?php require __DIR__ . '/_footer.php'; ?>
