Jam Sholat Masjid untuk komputer biasa, tanpa Raspberry Pi.

**Windows 10/11 (64-bit):** unduh `JamSholat-Setup-<versi>.exe`, jalankan, lalu ikuti petunjuk. Apache, PHP, Python, dan audio sudah termasuk.

**Debian, Ubuntu, Linux Mint, Raspberry Pi OS (desktop):** unduh `jamsholat_<versi>_all.deb`, lalu:

```
sudo apt install ./jamsholat_<versi>_all.deb
```

Setelah terpasang, buka `http://localhost/admin/` di komputer itu untuk membuat akun takmir. Panel bisa dibuka dari HP lewat WiFi yang sama di `http://<IP komputer>/admin/`.

Paket berisi audio adzan, iqomah, dan 114 surah murottal (±1 GB). Sumber dan lisensi audio: `public/assets/audio/CREDITS.txt` atau Pengaturan → Tentang aplikasi.
