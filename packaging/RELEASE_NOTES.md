Jam Sholat Masjid untuk komputer biasa, tanpa Raspberry Pi.

**Windows 10/11 (64-bit):** unduh `JamSholat-Setup-<versi>.exe`, jalankan, lalu ikuti petunjuk. Apache, PHP, Python, dan audio sudah termasuk.

**Debian, Ubuntu, Linux Mint, Raspberry Pi OS (desktop):** unduh `jamsholat_<versi>_all.deb`, lalu:

```
sudo apt install ./jamsholat_<versi>_all.deb
```

Setelah terpasang, buka `http://localhost/admin/` di komputer itu untuk membuat akun takmir. Layar jamaah dibuka dari menu **Layar Jamaah** (Start di Windows, menu aplikasi di Linux) dan ditutup dengan Alt+F4; suara adzan tetap berjalan. Panel bisa dibuka dari HP lewat WiFi yang sama di `http://<IP komputer>/admin/`.

Paket berisi audio adzan, iqomah, dan 114 surah murottal (±1 GB). Sumber dan lisensi audio: `public/assets/audio/CREDITS.txt` atau Pengaturan → Tentang aplikasi.

**Yang baru di 1.1.2** (pasang langsung di atas 1.1.1; data tetap):
- Murottal bisa dijeda dan dilanjutkan dari posisi terakhir, dengan tombol Sebelumnya/Berikutnya.
- Pengumuman bisa diubah, dan bisa diberi tanggal serta jam acara yang tampil di layar jamaah.
- Beranda menampilkan status "Layanan adzan" dan mengingatkan bila lokasi masjid belum diatur.
- Suara yang bisu atau terlalu kecil dinaikkan otomatis sebelum adzan dan iqomah.
- Rekaman iqomah baru (Syaikh Abdul Karim Al-Makki), slide "Kas Masjid", menu Panduan, dan kode QR untuk membuka panel dari HP.
