# Catatan Keputusan Teknis (Engineering Decisions)

Dokumen ini mengumpulkan semua keputusan desain yang dibuat di luar apa yang
literal tertulis di SRS — baik yang sudah dikonfirmasi user maupun yang
merupakan *engineering judgment* untuk detail kecil (sesuai instruksi kerja:
"untuk detail kecil ... gunakan engineering judgment yang wajar dan
dokumentasikan keputusan tersebut").

## 1. Konflik Raspberry Pi OS Lite vs kebutuhan Chromium kiosk (DIKONFIRMASI)

**Isu:** SRS §3.5 memilih Raspberry Pi OS **Lite** (headless), tapi §5.2/§4.1
mensyaratkan tampilan **Chromium kiosk fullscreen** di monitor.

**Keputusan (dikonfirmasi user, opsi "a"):** Tetap pakai Raspberry Pi OS Lite,
tambahkan komponen GUI minimal: `cage` (compositor Wayland khusus kiosk, satu
aplikasi fullscreen) + Chromium. Dijalankan lewat systemd
(`jamsholat-kiosk.service`) dengan `PAMName=login` + `TTYPath=/dev/tty1`
sehingga cage mendapat sesi logind di tty1, tanpa display manager penuh
(lightdm dkk) yang lebih berat.

**Alasan:** cage + Chromium jauh lebih hemat RAM dibanding beralih ke
Raspberry Pi OS Desktop penuh, sehingga tetap sejalan dengan REQ-NF-100
(RAM total ≤700MB pada perangkat 1GB).

**Revisi setelah uji hardware (2026-10-01):** desain awal memakai
`xserver-xorg` + `xinit` + `openbox` lewat `startx`. Di Raspberry Pi 3 Model B
dengan Raspberry Pi OS 13 (Trixie) 32-bit, X berjalan dan Chromium merender
halaman dengan benar (terbukti dari screenshot `scrot`), tetapi monitor HDMI
tetap hitam — juga pada X polos (`X -retro`) dan setelah mematikan glamor /
page flip. Raspberry Pi OS sendiri sudah beralih ke Wayland, jadi kiosk
dipindah ke cage. Chromium dijalankan dengan `--ozone-platform=wayland` dan
`--disable-gpu` (render lewat CPU, cukup untuk halaman statis ini).

## 2. Provider online schedule sync — REQ-F-004 (DIKONFIRMASI)

**Keputusan:** [AlAdhan API](https://aladhan.com) (`api.aladhan.com`), method
`id=20` — "Kementerian Agama Republik Indonesia" (KEMENAG).

**Alasan:**
1. Sudut yang dipakai method KEMENAG (**Fajr 20°, Isha 18°** — dikonfirmasi
   langsung dari `GET /v1/methods`) **identik** dengan sudut yang dipakai
   `PrayerTimeCalculator.php` lokal, jadi hasil online & offline konsisten
   satu sama lain — tidak membingungkan jamaah saat sumber berpindah.
2. Gratis, tanpa API key, endpoint sederhana (GET + JSON).
3. Menerima koordinat lat/long langsung, cocok dengan REQ-F-003 (offset
   lokasi berbasis koordinat, bukan nama kota tetap).

**Validasi:** Formula astronomis lokal (`PrayerTimeCalculator.php`) diuji
langsung terhadap respons nyata AlAdhan API (koordinat Dubai asli,
09-03-2015, method Gulf Region) — hasil cocok dalam toleransi 1-2 menit,
sama dengan kriteria lulus TC-JDW-001..004 di SRS. Lihat
`tests/unit/test_prayer_time_calculator.php`.

## 3. Struktur data laporan keuangan — REQ-F-012, REQ-F-024 (DIKONFIRMASI)

**Keputusan:** Ledger terstruktur (`financial_entries`: tanggal, keterangan,
jenis masuk/keluar, jumlah), saldo berjalan dihitung sebagai
SUM(masuk) - SUM(keluar).

## 4. Jumlah akun admin (ASUMSI — engineering judgment)

Satu akun admin per instalasi, dibuat interaktif saat `scripts/install.sh`
dijalankan. Tidak ada halaman manajemen multi-user karena tidak tercantum di
daftar halaman admin (SRS §4.1).

## 5. File audio adzan/iqomah/murottal (ASUMSI)

Bundled default di `public/assets/audio/{adzan,iqomah}.mp3` dan
`public/assets/audio/murottal/*.mp3`, diganti manual lewat akses filesystem
oleh developer/takmir — bukan fitur upload lewat panel admin, karena tidak
ada di daftar halaman SRS §4.1.

## 6. Default retention gambar kamera — REQ-F-044 (ASUMSI)

Default: 30 hari **ATAU** storage terpakai ≥80% (mana lebih dulu tercapai),
keduanya admin-configurable lewat pengaturan lokasi. Nilai spesifik ini
tidak ditentukan SRS.

## 7. Layanan alarm: `systemd .service` bukan `.timer` (DEVIASI DARI APPENDIX C, DIJELASKAN)

**SRS Appendix C** mencantumkan REQ-F-033 (Background Alarm Service)
diimplementasikan lewat "systemd timer". Implementasi ini memakai
`jamsholat-alarm.service` (proses **terus-menerus**), bukan `.timer` yang
memicu proses singkat secara periodik.

**Alasan:** REQ-F-032 (Murottal Playback, "Should") butuh audio yang
diputar **di sela-sela** waktu sholat, dengan syarat harus berhenti tepat
saat masuk jendela adzan/iqomah (TC-ALM-003: "murottal tidak boleh tumpang
tindih"). Sebuah `.timer` yang memicu proses singkat cocok untuk aksi
diskret (seperti retention cleanup di `jamsholat-retention.timer`), tapi
tidak cocok untuk audio latar yang perlu bisa "disela" kapan saja. Proses
`.service` yang jalan terus dengan loop internal (cek jadwal tiap 15 detik)
memenuhi maksud arsitektur SRS ("berjalan independen dari status halaman
web") dengan cara yang benar-benar mendukung REQ-F-032.

`jamsholat-retention.service` + `.timer` (REQ-F-044) TETAP memakai pola
timer sesuai SRS, karena cleanup memang aksi diskret harian.

## 8. Hijri date: algoritma tabular, bukan rukyat resmi (LIMITASI, DIDOKUMENTASIKAN)

`HijriConverter.php` memakai algoritma tabular Islamic calendar (civil
epoch) — murni matematis, offline. **Divalidasi exact match** terhadap
referensi AlAdhan API (18 Jumadil Awal 1436 H untuk 09-03-2015).

**Limitasi yang diketahui:** tanggal 1 Ramadhan/1 Syawal versi tabular bisa
berbeda ±1 hari dari pengumuman rukyat resmi Kemenag RI, karena rukyat
bergantung pada observasi hilal aktual yang tidak bisa dihitung matematis
di muka. Untuk tampilan harian rutin (bukan penentuan awal bulan resmi
Ramadhan/Syawal), perbedaan ini dapat diterima.

## 9. Offline time accuracy tanpa RTC — REQ-F-061 (KEPUTUSAN TEKNIS)

Memakai paket `fake-hwclock` (bawaan Debian/Raspbian, solusi standar untuk
device tanpa RTC fisik) alih-alih mekanisme custom di level aplikasi.
`fake-hwclock` menyimpan waktu ke disk secara berkala dan memulihkannya
saat boot, mencegah jam "reset" ke tanggal tidak masuk akal.

**Limitasi yang diketahui:** ini hanya mencegah reset drastis, TIDAK
menjamin akurasi penuh selama mati listrik berkepanjangan tanpa RTC fisik.
Modul RTC (mis. DS3231) direkomendasikan sebagai penambahan hardware di
roadmap masa depan bila akurasi offline jangka panjang jadi prioritas.

## 10. NTP sync — REQ-F-060 (KEPUTUSAN TEKNIS)

Memakai `systemd-timesyncd` bawaan OS, bukan implementasi custom di
aplikasi. Aplikasi (dashboard admin) hanya menampilkan status
"terakhir sync" yang dicatat saat `ScheduleSync` berjalan — sinkronisasi
jam sistem itu sendiri sepenuhnya ditangani OS.

## 11. Autentikasi & keamanan tambahan (DI LUAR SRS, praktik standar)

CSRF token di semua form admin dan pembatasan akses jaringan lokal via
konfigurasi Apache (`Require ip ...` di `install.sh`) ditambahkan sebagai
praktik keamanan standar, melengkapi REQ-NF-120/121/122 — bukan requirement
eksplisit SRS, tapi tidak mengubah scope fungsional.

## 12. Mengubah jam sistem dari panel admin — REQ-F-020 (KEPUTUSAN TEKNIS)

`settings_time.php` memanggil `sudo /usr/bin/date -s ...`. Akses `sudo`
dibatasi lewat `/etc/sudoers.d/jamsholat-date` HANYA untuk perintah
`date -s`, tanpa password — dikonfigurasi otomatis oleh `install.sh`. Tidak
ada privilege root lain yang diberikan ke user web server.

## 13. Format file backup — REQ-F-050, REQ-F-051 (KEPUTUSAN TEKNIS)

JSON tunggal (bukan ZIP) berisi data `mosque_settings`, `financial_entries`,
`publications` — file gambar publikasi disertakan sebagai base64 di dalam
JSON yang sama, supaya satu file = satu unit backup yang mandiri (tidak
butuh ekstensi PHP `ZipArchive` yang belum tentu terpasang). Motion events
(rekaman kamera) sengaja TIDAK termasuk — lihat SRS §5.6 yang hanya
menyebut "pengaturan, laporan keuangan, dan publikasi".
