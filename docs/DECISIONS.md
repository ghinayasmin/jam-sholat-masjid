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

## 14. Kas masjid tidak tampil di layar jamaah secara bawaan — REQ-F-012 (DEVIASI, DIKONFIRMASI)

**Isu:** REQ-F-012 meminta laporan keuangan tampil di layar jamaah. Saat
meninjau desain, pengguna (tim capstone) menilai saldo dan rincian kas sebagai
informasi internal pengurus yang tidak pantas dipajang ke publik.

**Keputusan:** fitur tetap ada, tetapi dikendalikan sakelar
`mosque_settings.show_finance_on_display` yang **bawaannya 0 (mati)**. Saat
mati, `api/financial.php` (endpoint publik) hanya menjawab
`{"enabled": false}` tanpa angka apa pun, jadi data kas juga tidak bisa
dibaca dari jaringan lewat API. Takmir bisa menyalakannya bila masjid memang
ingin transparan ke jamaah; saat menyala, layar dan API hanya memuat saldo,
tanpa rincian transaksi.

## 15. Desain ulang layar jamaah & pengingat sebelum adzan (DIKONFIRMASI)

**Keputusan:** arah desain "Malam teduh" (latar gelap, tekstur batik kawung
7%, satu warna aksen kuningan, font Plus Jakarta Sans + Reem Kufi yang
disimpan lokal di `public/assets/fonts/` beserta lisensi OFL-nya). Mockup dan
referensi yang disetujui: lihat riwayat diskusi desain 2026-10-01.

Perilaku otomatis yang dipilih pengguna:
- **Pengingat** layar penuh dengan hitung mundur, bawaan 10 menit sebelum
  setiap adzan; Dzuhur hari Jumat 30 menit dengan pesan "Persiapan sholat
  Jumat". Bisa dimatikan/diubah (`reminder_*` di `mosque_settings`).
- **Layar adzan** selama 3 menit sejak waktu adzan.
- **Tidak ada** hitung mundur iqomah dan layar redup saat sholat di tampilan,
  karena lama sholat tidak bisa diketahui pasti. Suara iqomah dari layanan
  alarm (REQ-F-031) tetap berjalan seperti sebelumnya.

Baris puasa sunnah selalu menampilkan puasa terdekat (hari ini, besok, atau
tanggalnya), bukan hanya hari ini, supaya bagian bawah layar tidak kosong.
Hari terlarang berpuasa (1 Syawal, 10–13 Dzulhijjah) dan bulan Ramadhan tidak
ditandai sebagai puasa sunnah (REQ-F-013); sebelumnya, misalnya Idul Adha yang
jatuh hari Senin tetap tertulis "puasa Senin".

Perbaikan yang ikut masuk: `api/schedule.php` kini memakai tanggal lokal
masjid (sebelumnya tanggal UTC, sehingga jam 00:00–07:00 WIB tanggal,
Hijriah, dan info puasa masih milik hari kemarin).

## 16. Panel takmir dirancang untuk HP (DIKONFIRMASI)

**Isu:** pengurus masjid umumnya membuka panel dari HP lewat WiFi masjid dan
tidak semuanya paham istilah teknis. Panel lama dirancang untuk layar lebar,
memakai 8 menu sejajar, dan menampilkan istilah seperti latitude, NTP, dan UTC.

**Keputusan (mockup disetujui pengguna 2026-10-01):**
- Lima tab di bawah layar: Beranda, Pengumuman, Kas, Kamera, Pengaturan.
  Halaman lain (profil, lokasi, jeda iqomah, pengingat, jam, cadangan, akun)
  dikelompokkan di bawah Pengaturan.
- Bahasa pengurus: "Uang masuk/keluar", "Pasang pengumuman", "Cadangan data".
  Koordinat dan madzhab ada di halaman Lokasi dengan peringatan "untuk teknisi";
  pengurus melihat nama lokasi (`mosque_settings.location_name`).
- Pengumuman bisa berupa poster atau teks saja, dengan tanggal mulai dan
  selesai (`publications.starts_on/ends_on`). Pengumuman yang sudah lewat
  hilang sendiri dari layar dan bisa dipasang lagi.
- Pencatatan kas memakai pilihan siap ketuk (Kotak Jumat, Infaq, ...) dan
  tombol nominal cepat. Keterangan tersimpan sebagai teks biasa di
  `financial_entries.description`, jadi struktur ledger (#3) tidak berubah.
- `/admin` langsung membuka Beranda (alamat pendek untuk stiker QR).
- **Sesi login 30 hari** (`admin_session_days`), sebelumnya habis setelah
  30 menit tidak aktif. Pertimbangannya: panel hanya bisa diakses dari jaringan
  lokal masjid (REQ-NF-122), cookie tetap `HttpOnly` + `SameSite=Strict`, dan
  pengurus bisa keluar manual dari Pengaturan. Masjid yang ingin lebih ketat
  cukup menurunkan nilai `admin_session_days` di `config/config.php`.

Yang tidak ikut dibuat: memilih kota dari daftar (lokasi tetap diisi lewat
koordinat oleh teknisi) dan mengatur ulang urutan pengumuman (urutan mengikuti
waktu dibuat).

## 17. Kamera: Raspberry Pi Camera Module lewat Picamera2 (KEPUTUSAN TEKNIS)

- Perangkat uji memakai **Raspberry Pi Camera V2** (CSI), bukan webcam USB.
  Node `/dev/video0`-nya (`unicam-image`) hanya memberi data sensor mentah,
  sehingga `cv2.VideoCapture` terbuka tapi tidak pernah mendapat frame.
- `motion_detect.py` kini memilih kamera otomatis (`JAMSHOLAT_CAMERA_TYPE=auto`):
  Picamera2 bila ada Camera Module, selain itu webcam USB lewat V4L2. Bisa
  dipaksa `picamera` atau `usb`.
- `python3-picamera2` dipasang dengan `--no-install-recommends` agar Qt/OpenGL
  (untuk jendela pratinjau) tidak ikut memakan RAM dan disk Pi 3.
- Foto disimpan dari aliran 1280x960 pada mode sensor penuh, deteksi memakai
  aliran 320x240. Putaran foto (`JAMSHOLAT_CAMERA_ROTATION`, 0/90/180/270 searah
  jarum jam) dikerjakan OpenCV karena ISP Pi 3 tidak bisa memutar 90°. Diatur
  sekali di unit systemd, tidak lewat panel (dikonfirmasi pengguna).
- Perangkat uji memakai Camera V2 **NoIR** (papan hitam); profil
  `imx219_noir.json` dipakai lewat `JAMSHOLAT_CAMERA_TUNING`.

## 18. Murottal diputar/dijeda dari panel, nama surat tampil di layar (DIKONFIRMASI)

- Murottal **tidak** lagi diputar otomatis sejak perangkat menyala. Takmir
  menekan **Putar/Jeda** di Beranda (`mosque_settings.murottal_enabled`,
  bawaan 0). Layanan alarm membaca sakelar ini tiap 3 detik.
- **Jeda** menghentikan surat yang sedang diputar; **Putar** mengulang surat
  yang sama dari awal. Surat berganti hanya bila selesai dengan sendirinya.
  Pertimbangan: jeda sungguhan (menahan proses `mpg123`) membuat keluaran HDMI
  tetap terkunci sehingga adzan bisa gagal berbunyi.
- Layanan alarm menulis judul surat ke `murottal_now_playing`; layar jamaah
  membacanya lewat `api/murottal.php` tiap 5 detik dan menampilkan baris
  "Murottal · Surah …". Judul diambil dari nama file (`002_An-Naba'.mp3`
  → "An-Naba'"), jadi takmir cukup memberi nama file yang benar.
- Jendela diam sekitar waktu sholat (REQ-F-032) tetap berlaku walau sakelar
  menyala.
- Adzan Subuh memakai `adzan_subuh.*` bila ada (lafaz tambahan "ash-shalatu
  khairum minan naum").
- **Suara iqomah bisa dimatikan** (`iqomah_sound_enabled`, bawaan 0) di
  Pengaturan → Suara iqomah. Di banyak masjid iqomah dikumandangkan langsung,
  jadi rekaman otomatis justru mengganggu. Fitur REQ-F-031 tetap ada dan
  berjalan bila dinyalakan.
- **Pilih surat** (`admin/murottal.php`): mengetuk surat menyalakan murottal
  dan menulis `murottal_request = "file|cap waktu"`. Layanan alarm pindah ke
  surat itu lalu lanjut berurutan. Cap waktu membuat memilih ulang surat yang
  sama tetap terbaca sebagai permintaan baru. Daftar dan judul diambil dari
  nama file oleh `App\Murottal` dengan aturan yang sama seperti Python.

## 19. Ihtiyat Kemenag pada jadwal sholat (KOREKSI)

- **Masalah:** kalkulasi astronomis murni (sudut Kemenag 20°/18°) 2–4 menit
  lebih awal dari jadwal resmi Kemenag setiap hari, sehingga adzan bisa
  berbunyi sebelum waktu masuk. Kemenag menambahkan ihtiyat lalu membulatkan
  ke atas.
- **Keputusan:** `PrayerTimeCalculator::IHTIYAT_KEMENAG` (Subuh +2, Terbit
  −3,5, Dzuhur +3, Ashar +2, Maghrib +2,5, Isya +2 menit, dibulatkan ke atas).
  Nilai dicocokkan dengan jadwal resmi (api.myquran.com) untuk Kota
  Yogyakarta, Jakarta, Makassar, dan Medan pada Januari, April, Juli, dan
  Oktober 2026 (492 hari): tepat sama 79–95% hari, sisanya selisih 1 menit.
  Selisih 1 menit itu berasal dari perbedaan koordinat titik kota dan
  pembulatan Kemenag, dan masih di dalam ihtiyat 2 menit.
- Sinkron online AlAdhan (belum dipanggil di mana pun) memakai `tune` yang
  dipilih agar tidak pernah lebih awal dari Kemenag; karena AlAdhan sudah
  membulatkan, ketepatannya di bawah kalkulasi lokal.
- `update.sh` menghapus cache `prayer_schedules` agar perubahan rumus langsung
  berlaku. `compute_schedule_cli.php` memakai tanggal lokal, bukan UTC.
- Ketepatan di lapangan tetap bergantung pada **koordinat masjid** yang diisi
  di Pengaturan → Lokasi.

## 20. Tanggal Hijriah berganti saat Maghrib, dengan koreksi isbat (DIKONFIRMASI)

- Layar jamaah menampilkan tanggal Hijriah besok mulai waktu Maghrib, sesuai
  awal hari dalam kalender Islam (mis. malam tarawih pertama sudah "1 Ramadhan").
- `mosque_settings.hijri_offset_days` (−1, 0, +1) di Pengaturan → Tanggal Hijriah
  menggeser hasil hitungan agar sama dengan keputusan sidang isbat. Koreksi ini
  juga dipakai untuk puasa sunnah (Ayyamul Bidh, hari terlarang) lewat
  `ScheduleEngine::hijriFor()`, dan cache jadwal dihapus saat disimpan.

## 21. Poster landscape tampil layar penuh (DIKONFIRMASI)

- Orientasi poster dibaca dari ukuran gambar saat dimuat: rasio lebar/tinggi
  ≥ 1,3 dianggap landscape dan tampil memenuhi layar, dengan strip bawah
  berisi jam, tanggal, dan sholat berikutnya beserta hitung mundurnya.
  Portrait dan persegi (format umum Canva/Instagram) tetap di panel kiri.
- Takmir tidak perlu memilih apa pun saat mengunggah.
- Perbaikan yang ikut masuk: poster tinggi sebelumnya terpotong bagian
  bawahnya karena tinggi persen tidak berlaku di sel grid; gambar kini
  diposisikan absolut dengan `object-fit: contain`.

## 22. Panel tetap HTTP di jaringan lokal (KETERBATASAN DISADARI)

- Browser menandai `http://192.168.x.x` sebagai "Not secure". HTTPS butuh
  sertifikat yang dipercaya HP: sertifikat buatan sendiri memunculkan halaman
  peringatan penuh, memasang CA di tiap HP takmir tidak praktis, dan sertifikat
  publik (Let's Encrypt) butuh domain, internet terus-menerus, dan perpanjangan
  90 hari.
- Panel tetap HTTP karena hanya bisa diakses dari jaringan masjid (REQ-NF-122),
  wajib login (REQ-NF-120), dan password disimpan bcrypt (REQ-NF-121).
  Pengiriman formulir lewat `fetch` (lihat `admin.js`) menghindari pop-up
  "not secure" Chrome di HP; ikon di bilah alamat tetap tampil.

