Jam Sholat Masjid untuk komputer biasa, tanpa Raspberry Pi.

**Windows 10/11 (64-bit):** unduh `JamSholat-Setup-<versi>.exe`, jalankan, lalu ikuti petunjuk. Apache, PHP, Python, dan audio sudah termasuk.

**Debian, Ubuntu, Linux Mint, Raspberry Pi OS (desktop):** unduh `jamsholat_<versi>_all.deb`, lalu:

```
sudo apt install ./jamsholat_<versi>_all.deb
```

Setelah terpasang, buka panel takmir (`http://localhost/admin/`) dan masuk dengan nama pengguna `admin` dan kata sandi `000`. Panel meminta kata sandi baru, lalu menuntun Langkah awal: nama masjid, lokasi, dan pemeriksaan suara. Layar jamaah dibuka dari menu **Layar Jamaah** (Start di Windows, menu aplikasi di Linux) dan ditutup dengan Alt+F4; suara adzan tetap berjalan. Panel bisa dibuka dari HP lewat WiFi yang sama di `http://<IP komputer>/admin/`.

Paket berisi audio adzan, iqomah, dan 114 surah murottal (±1 GB). Sumber dan lisensi audio: `public/assets/audio/CREDITS.txt` atau Pengaturan → Tentang aplikasi.

**Yang baru di 1.1.3** (pasang langsung di atas 1.1.2; data dan kata sandi tetap):
- Akun bawaan `admin` / `000` yang wajib diganti saat pertama masuk. Lupa kata sandi: kembalikan ke akun bawaan dari perangkat itu sendiri (Start → Jam Sholat Masjid → Reset kata sandi di Windows, menu Reset Kata Sandi Jam Sholat atau `sudo jamsholat-reset-password` di Linux). Kode pemulihan tidak dipakai lagi.
- Langkah awal setelah instalasi: nama masjid, lokasi, dan periksa suara.
- Lokasi masjid dipilih dari daftar 514 kabupaten/kota, tanpa perlu mengetik koordinat.
- Tombol "Coba putar suara adzan" untuk memeriksa speaker selama 10 detik.
- Istilah di panel diganti agar lebih mudah dipahami, misalnya "Geser waktu adzan" dan "Pemutar adzan: Siap".
- Kode QR di halaman "Buka panel dari HP" tidak lagi menunjuk adaptor jaringan virtual di Windows.
