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

**Pembaruan 19 Okt 2026 (revisi dosen):** saat menyala, kas tidak lagi tampil
sebagai baris kecil di pojok kanan bawah, tetapi sebagai slide seukuran poster
(lihat #27). API kini juga memuat total pemasukan dan pengeluaran bulan
berjalan serta tanggal transaksi terakhir. Rincian transaksi tetap internal.
Untuk **instalasi baru, sakelar bawaannya kini nyala** (`schema.sql` DEFAULT 1)
sesuai permintaan dosen agar kas tampil. Database lama tidak diubah: masjid yang
sudah memasang tetap memakai pilihannya sendiri.

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
  alarm tetap berjalan seperti sebelumnya.

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
  Pengaturan → Suara adzan & iqomah. Di banyak masjid iqomah dikumandangkan
  langsung, jadi rekaman otomatis justru mengganggu (lihat juga #23).
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

## 23. Suara adzan dan iqomah bisa dimatikan (DIKONFIRMASI)

- Di masjid sungguhan adzan dikumandangkan muadzin, jadi sistem tidak boleh
  memaksa memutar rekaman. `mosque_settings.adzan_sound_enabled` (bawaan 1)
  dan `iqomah_sound_enabled` (bawaan 0) diatur di Pengaturan → Suara adzan &
  iqomah (`admin/sound.php`, menggantikan `iqomah.php`).
- Yang mati hanya suara dari speaker. Layar jamaah tetap menampilkan pengingat
  dan layar adzan, dan murottal tetap berhenti di jendela diam sekitar waktu
  sholat agar tidak bertabrakan dengan adzan muadzin.
- Iqomah (dulu REQ-F-025/031) dihapus dari SRS v0.2 karena iqomah sebaiknya
  dikumandangkan langsung. Kodenya dipertahankan sebagai fitur opsional.
  **Diperbarui di #30:** dikembalikan atas revisi dosen, bawaan nyala untuk
  instalasi baru.
- Baris "Murottal" di layar hanya tampil selama sakelar Putar menyala dan
  layanan alarm sedang memutar surat. Layanan mengosongkan judul saat dihentikan
  (SIGTERM dari `systemctl stop`/`update.sh`), jadi judul tidak tertinggal.

## 24. Jam dicocokkan dengan jam HP setelah listrik padam (KOREKSI)

- **Masalah (TC-SYNC-002 gagal):** Pi tidak punya RTC. Setelah listrik padam
  tanpa internet, `fake-hwclock` memulihkan jam dari simpanan terakhir sehingga
  jam tertinggal sepanjang padamnya listrik.
- **Solusi:** `App\SystemClock` menganggap jam terpercaya bila sudah sinkron NTP
  sejak boot atau sudah dicocokkan admin sejak boot. Bila belum, panel takmir
  (`admin.js` → `admin/clock_sync.php`) diam-diam mengirim jam HP, dan jam
  perangkat diatur lewat aturan sudoers `date` yang sama dengan REQ-F-020.
  Jam HP dianggap acuan karena mengikuti jaringan seluler.
- Layar jamaah tidak menampilkan peringatan jam (tidak relevan bagi jamaah).
  Pengurus cukup membuka panel dari HP sekali setelah listrik kembali.
- PHP di Raspberry Pi OS (armhf) 32-bit: `PHP_INT_MAX` = 2.147.483.647. Jam HP
  dikirim dalam detik, bukan milidetik (milidetik meluap dan selalu ditolak 400,
  ditemukan saat uji ulang TC-SYNC-002). Jumlah kas per catatan dibatasi
  Rp 999.999.999 karena alasan yang sama.

## 25. Deteksi gerakan lebih tahan salah tangkap (KOREKSI)

- **Masalah:** di perangkat uji, ±80% dari 249 foto sehari diambil di detik
  :01–:08 tiap menit. Kamera melihat monitor, dan poster layar jamaah muncul
  tiap menit (detik :00–:12), sehingga pergantian poster terbaca gerakan.
- **Zona abaikan** (`JAMSHOLAT_CAMERA_IGNORE`, persen terhadap foto yang tampil
  di panel): area seperti layar TV atau jendela tidak dihitung. Koordinat
  diterjemahkan ke frame deteksi sesuai `JAMSHOLAT_CAMERA_ROTATION`.
- **Perubahan cahaya:** bila >50% area yang diawasi berubah sekaligus (lampu
  dinyalakan, awan), latar belakang dipelajari ulang selama ±5 detik dan tidak
  ada foto.
- **Konfirmasi 2 frame** (±0,4 detik pada 5 fps): kedipan satu frame tidak memicu foto.
- Penempatan kamera tetap faktor terbesar: hari instalasi, arahkan ke pintu atau
  kotak amal, jangan ke layar, jendela, atau lampu. Camera V2 NoIR butuh lampu
  inframerah untuk ruangan gelap di malam hari.

## 26. Lama penyimpanan foto kamera diatur takmir (DIKONFIRMASI)

- Tab Kamera → "Simpan foto selama" 3 / 7 / 14 / 30 hari
  (`mosque_settings.image_retention_days`, bawaan 7 untuk pemasangan baru).
  Foto lebih lama langsung dihapus saat pilihan diubah, lalu tiap malam oleh
  `jamsholat-retention.timer` (03:00). Batas memori 80% tetap berlaku.
- 7 hari disarankan: pencurian kotak amal sering baru ketahuan saat kotak dibuka
  Jumat berikutnya, jadi 3 hari berisiko bukti sudah terhapus. Satu foto ±185 KB;
  7 hari pada 300 foto/hari ±400 MB.

## 27. Slide bergantian di layar jamaah, waktunya diatur takmir (DIKONFIRMASI)

- **Revisi dosen 19 Okt 2026:** poster tampil lebih lama dan lebih sering, saldo
  kas tampil seukuran poster, dan waktunya bisa diatur dari panel.
- Poster pengumuman, slide kas (#14), dan foto masjid (#28) masuk satu rotasi
  dengan urutan itu. Layar jam tampil `poster_gap_seconds` detik, lalu satu
  slide tampil `poster_show_seconds` detik, berulang. Bawaan 15 dan 15
  (sebelumnya tertanam di kode: tiap 60 detik, tampil 12 detik).
- Diatur di Pengaturan → Layar jamaah → Pergantian slide. Batas: jeda 5–600
  detik, tampil 5–120 detik. Slide tidak muncul saat pengingat dan layar adzan.
- Slide kas menempati panel kiri seperti poster portrait, jadi jadwal sholat
  tetap terlihat. Angka ≥ Rp 100 juta diperkecil otomatis agar muat.
- Gaya slide kas dipilih pengguna dari mockup (opsi G1, setelah motif kawung
  dan bintang delapan terlihat kurang pas di monitor): gradasi zamrud diagonal
  tanpa motif, bingkai kuningan tipis, label "Baitul Maal بيت المال", isi di
  tengah kotak, tulisan besar dan tebal. Pemasukan dan pengeluaran bertumpuk
  dua baris supaya angkanya bisa lebih besar. Warna gradasinya kemudian
  diperdalam mendekati warna panel jadwal. Tanpa efek blur karena Chromium
  di Pi 3 merender lewat CPU.
- Karena slide kini berganti tiap 30 detik, zona abaikan kamera (#25) wajib
  menutupi layar TV bila layar terlihat oleh kamera.

## 28. Foto masjid sebagai slide layar penuh (DIKONFIRMASI)

- Dari tiga mockup (latar panel jam, foto kecil di samping nama, slide layar
  penuh), pengguna memilih **slide layar penuh**: foto memenuhi layar dengan
  "Selamat datang di <nama masjid>" di kiri atas dan strip jam + sholat
  berikutnya di bawah, sama seperti poster landscape (#21).
- Diunggah di Pengaturan → Profil masjid (JPG/PNG, batas ukuran sama dengan
  poster). Disimpan di folder poster dengan nama `masjid_<acak>`; nama file di
  `mosque_settings.mosque_photo`. Foto lama dihapus saat diganti.
- Foto dipotong (`object-fit: cover`) agar memenuhi layar, jadi foto mendatar
  paling cocok. Pojok kiri atas digelapkan pekat (gradasi radial + gradasi atas,
  opsi "A++" dari mockup) supaya nama masjid tetap terbaca di foto yang cerah. Foto ikut masuk file cadangan dan dipulihkan saat restore.

## 29. Foto unggahan diperkecil otomatis, batas unggahan 20 MB (KOREKSI)

- **Masalah:** saat uji di Pi, foto masjid PNG 3,3 MB ditolak dengan pesan
  "lebih kecil dari 5 MB". Penyebabnya batas bawaan PHP di Debian
  (`upload_max_filesize = 2M`), yang lebih kecil dari batas aplikasi. Poster
  dari HP juga terkena.
- `config/php-jamsholat.ini` (20 MB per foto) dipasang ke
  `/etc/php/*/apache2/conf.d/90-jamsholat.ini` oleh `install.sh` dan
  `update.sh`. Batas aplikasi `publication_max_bytes` naik dari 5 ke 20 MB.
- `src/Media/UploadedImage.php` menyimpan ulang setiap poster dan foto masjid
  sebagai JPEG kualitas 85 dengan sisi terpanjang maks 1920 px, diputar sesuai
  EXIF. Foto HP 12 MP terlalu berat untuk didekode Chromium di Pi 3. Contoh:
  PNG 3,3 MB menjadi JPEG 476 KB. Bila GD tidak ada, file asli disimpan.
- Pesan error menyebut batas yang benar-benar berlaku (yang lebih kecil antara
  pengaturan PHP dan aplikasi).

## 30. Jeda iqomah per waktu sholat, Subuh 20 menit (DIKONFIRMASI)

- **Revisi dosen 19 Okt 2026:** jeda adzan ke iqomah bisa diatur khusus Subuh
  (20 menit). Iqomah (REQ-F-025/031) kembali masuk lingkup.
- Lima kolom `iqomah_<sholat>_minutes` (bawaan Subuh 20, lainnya 10)
  menggantikan `azan_iqomah_interval_minutes` yang dulu berlaku untuk semua.
  Diatur di Pengaturan → Suara adzan & iqomah, batas 5–60 menit (suara adzan
  sendiri 3–5 menit). Instalasi baru: suara iqomah bawaan **nyala**.
- Layanan alarm membaca ulang jeda tiap menit, jadi perubahan di panel berlaku
  tanpa restart (sebelumnya baru berlaku besok).
- **Jumat:** iqomah Dzuhur tidak dibunyikan otomatis karena dikumandangkan
  setelah khutbah, yang lamanya tidak tetap.
- Bila menit iqomah terlewat karena rekaman adzan masih diputar, iqomah tetap
  dibunyikan sampai 3 menit sesudahnya, satu kali.
- Murottal tetap diam sampai 15 menit setelah iqomah (perkiraan sholat
  berjamaah). Sebelumnya jendela diam tetap 20 menit setelah adzan, sehingga
  dengan jeda Subuh 20 menit murottal bisa menyala saat sholat.

## 31. Hitung mundur iqomah di layar, bisa dimatikan (DIKONFIRMASI)

- Dulu dihapus saat desain ulang layar (#15). Atas permintaan pengguna
  dikembalikan sebagai pilihan: Pengaturan → Layar jamaah → Hitung mundur
  iqomah (`iqomah_countdown_enabled`, bawaan nyala untuk instalasi baru).
- Setelah layar adzan (3 menit), tampil layar penuh "Iqomah <sholat> dalam
  MM:SS" dengan bar, gaya sama dengan pengingat sebelum adzan. Tepat saat
  iqomah, selama 1 menit tampil "قَدْ قَامَتِ الصَّلَاةُ · Luruskan dan rapatkan
  shaf". Jedanya mengikuti `iqomah_<sholat>_minutes` (#30).
- Tidak tampil untuk Dzuhur hari Jumat (iqomah setelah khutbah). Layar ini tidak
  bergantung pada sakelar suara iqomah: masjid yang iqomahnya dikumandangkan
  muadzin tetap bisa memakai hitung mundurnya.

## 32. Layar adzan bertahan sampai rekaman adzan selesai (KOREKSI)

- **Masalah (uji Maghrib di Pi, 19 Okt 2026):** layar adzan dipatok 3 menit,
  tetapi rekaman adzan 3:23 (Subuh 3:07). Hitung mundur iqomah muncul saat suara
  adzan masih berbunyi.
- Layanan alarm menulis `mosque_settings.alarm_now_playing` ("adzan|maghrib")
  selama rekaman diputar dan mengosongkannya sesudahnya (juga saat start, untuk
  sisa layanan yang berhenti di tengah adzan). `api/murottal.php`, yang sudah
  dibaca layar tiap 5 detik, ikut mengirim nilai ini.
- Layar adzan tetap minimal 3 menit, diperpanjang selama rekaman berbunyi,
  paling lama 10 menit bila sinyal tertinggal. Bila suara adzan dimatikan,
  layar adzan tetap 3 menit.

## 33. Reset kata sandi: kode pemulihan + perintah teknisi (KOREKSI)

- **Masalah (revisi dosen 19 Okt 2026):** satu-satunya cara reset adalah
  `scripts/reset_admin_password.sh`, yang harus dicari di dalam `/opt` dan
  memakai database di folder tempat skrip itu berada: bila dijalankan dari
  salinan repo, yang direset database lain. Username juga dirangkai langsung
  ke SQL.
- **Takmir:** di Akun takmir dibuat kode pemulihan 16 karakter
  (`K7QM-3HXT-9PWA-RD4N`, tanpa 0/O/1/I/L), wajib memasukkan kata sandi saat
  ini. Hanya hash bcrypt yang disimpan (`admin_users.recovery_hash`). Halaman
  masuk punya tautan "Lupa kata sandi?" (`admin/forgot.php`): username + kode +
  kata sandi baru. Kode sekali pakai; setelah dipakai langsung diganti kode
  baru yang ditampilkan sekali. Tanpa email/internet, cocok untuk masjid.
- **Teknisi:** `sudo jamsholat-reset-password` (dipasang ke `/usr/local/sbin`
  oleh install.sh dan update.sh) selalu memakai `/opt/jamsholat`, berjalan
  sebagai user layanan, dan mencetak kode pemulihan baru. Nama lama
  `reset_admin_password.sh` tetap ada sebagai pengalih.
- **Batas percobaan** (tabel `auth_failures`): pemulihan 5 kali salah dan login
  10 kali salah dalam 15 menit per alamat IP, sebelumnya tidak dibatasi.
- install.sh membuat akun pertama lewat PHP (prepared statement) dan langsung
  menampilkan kode pemulihan pertama.

## 34. Menu Tentang aplikasi (DIKONFIRMASI)

- **Revisi dosen 19 Okt 2026:** Pengaturan → Sistem → Tentang aplikasi
  (`admin/about.php`): nama dan versi aplikasi, tim pengembang (Azhar Maulana,
  Rahmanda Putri Setiawati, Ghina Yasmin Azizah, Muhammad Dhiwaul Akbar), dosen
  pembimbing (Faizal Makhrus, S.Kom., M.Sc., Ph.D.), Program Studi Ilmu Komputer UGM, serta sumber dan
  lisensi (kriteria Kemenag, audio adzan/iqomah/murottal, huruf). Atribusi
  murottal CC BY-SA 4.0 sekaligus terpenuhi di sini.
- Hanya di panel takmir, tidak di layar jamaah, supaya layar tetap fokus pada
  jadwal sholat.

## 35. Koreksi jadwal per waktu sholat (DIKONFIRMASI)

- Atas permintaan pengguna: Pengaturan → Waktu sholat → Koreksi jadwal per
  sholat (`admin/schedule_adjust.php`). Lima kolom `adjust_<sholat>_minutes`,
  −30 sampai +30 menit, bawaan 0. Terbit tidak ikut (bukan waktu adzan).
- Berupa geseran menit, bukan jam tetap, supaya jadwal tetap mengikuti
  perubahan harian matahari. Halaman menampilkan jam hasil hitungan dan jam
  yang akan tampil untuk hari ini, berubah langsung saat angka diubah.
  `location_offset_minutes` (Pengaturan → Lokasi) tetap ada untuk geseran yang
  sama ke semua sholat; keduanya dijumlahkan.
- Koreksi disimpan ke `prayer_schedules` saat jadwal dihitung, jadi layar,
  API, dan layanan alarm memakai jam yang sama. Cache jadwal dihapus saat
  disimpan.
- **Perbaikan ikutan:** layanan alarm dulu membaca jadwal hanya saat start dan
  ganti hari, sehingga koreksi (atau perubahan lokasi) baru berlaku besok.
  Sekarang jadwal dibaca ulang tiap menit dari database; PHP hanya dijalankan
  bila jadwal hari ini belum ada.

## 36. Penataan ulang panel setelah tinjauan UX (KOREKSI)

- **Tinjauan alur 19 Okt 2026** (14 halaman di lebar HP + detector desain, 0
  temuan teknis). Perubahan yang disetujui pengguna:
- **Sakelar "Cocokkan dengan jadwal online" dihapus** dari Lokasi. Sakelar itu
  menyala tetapi `ScheduleSync` tidak pernah dipanggil, sehingga menyesatkan.
  Kolom `online_sync_enabled` tetap ada di database.
- **Koreksi menit disatukan.** "Koreksi menit" semua sholat pindah dari Lokasi
  ke baris "Semua sholat" di Koreksi jadwal; hasilnya dijumlahkan dengan
  koreksi per sholat dan pratinjau jamnya ikut keduanya. Batas −30..+30.
- **Pengaturan dikelompokkan ulang:** Masjid (Profil & foto) · Waktu sholat
  (Lokasi, Koreksi jadwal, Tanggal Hijriah) · Adzan & iqomah (Suara & jeda
  iqomah, Pengingat sebelum adzan, Hitung mundur iqomah) · Layar jamaah (Saldo
  kas, Pergantian slide) · Sistem.
- **Beranda:** pintasan "Pengingat adzan" (jarang diubah) diganti "Koreksi
  jadwal". Kondisi sistem menambah "Jam perangkat" (tepat / sedang dicocokkan
  dengan jam HP) dan, bila belum ada, peringatan "Kode pemulihan belum dibuat".

## 37. Konsistensi kecil panel: sakelar, murottal, ubah catatan kas (DIKONFIRMASI)

- Lanjutan tinjauan UX (#36), poin P3 yang disetujui pengguna:
- **Sakelar geser di semua pilihan nyala/mati.** Tombol "Nyala | Mati" di
  Suara & jeda iqomah dan Pengingat sebelum adzan diganti sakelar yang sama
  dengan di Pengaturan. Kotak centang yang tidak dicentang tidak terkirim,
  jadi bawaan "mati" saat kolomnya tidak ada di POST.
- **Kartu murottal:** "Murottal menyala / Murottal berhenti", dengan keterangan
  "Ketuk Putar untuk memulai" (sebelumnya "Mati · Dijeda").
- **Catatan kas bisa diubah.** Ketuk catatan di tab Kas → formulir yang sama
  (`financial_add.php?id=`) berisi nilai lama, "Simpan perubahan", dan "Hapus
  catatan ini". Jenis masuk/keluar tidak bisa diubah (hapus lalu catat ulang).
  Keterangan "Jenis · catatan" dipecah kembali ke pilihan jenis; yang tidak
  cocok masuk "Lainnya". Hitungan "saldo menjadi" berangkat dari saldo tanpa
  catatan lama. Mode hapus terpisah ("Hapus catatan yang salah") dihilangkan.
- Tautan panduan pengguna di panel ditunda ke Tahap D bersama isinya.

## 38. Perapian kode (KOREKSI)

- **Kode mati dihapus:** `src/Schedule/ScheduleSync.php` (sinkron AlAdhan, tidak
  pernah dipanggil) dan `aladhan_base_url` di config. Kolom `online_sync_enabled`,
  `last_sync_at`, `last_sync_status`, dan `azan_iqomah_interval_minutes` tetap di
  database (menghapus kolom SQLite di database lama berisiko) dan ditandai tidak
  dipakai.
- **Kolom pengaturan dibaca dari tabel.** `SettingsRepository::update()` dan
  pemulihan cadangan tidak lagi memakai daftar kolom tertulis tangan. Pengaturan
  baru cukup ditambahkan di `schema.sql` dan `Database::ADDED_COLUMNS`; sebelumnya
  juga harus di daftar izin repository dan di SQL restore, dan lupa satu tempat
  membuat pengaturan itu tidak pulih dari cadangan. Kolom status layanan alarm
  (murottal/adzan yang sedang diputar) tidak ikut dipulihkan. Cadangan lama yang
  belum punya suatu kolom: kolom itu tetap bernilai sekarang (dulu: nilai bawaan).
- `ScheduleEngine::PRAYER_NAMES` menggantikan empat salinan daftar nama sholat
  di halaman panel. Gaya inline yang berulang diganti kelas `.row.ctl`,
  `.row.solo`, dan `.aside` (lihat DESIGN.md).
- Pemeriksaan: PHPStan level 5, pyflakes, shellcheck, `node --check`, dan
  semua test lolos.

## 39. Paket untuk komputer biasa: .deb dan installer Windows (DIKONFIRMASI)

- **Revisi dosen 19 Okt 2026:** aplikasi harus bisa dipasang di komputer masjid
  tanpa Raspberry Pi, lengkap dengan Apache dan PHP, untuk Windows dan Linux
  keluarga Debian (Debian, Ubuntu, Linux Mint, Raspberry Pi OS).
- **Linux:** satu paket `jamsholat_<versi>_all.deb` (`packaging/debian/build-deb.sh`).
  `apt` menarik Apache, PHP, Python, mpg123 dari repositori distro; `postinst`
  menyiapkan user `jamsholat`, database, situs Apache, sudoers `date`, dan
  layanan. Komputer berdesktop (graphical.target): alarm, kamera, dan kiosk
  berjalan di sesi pengguna lewat XDG autostart (`packaging/linux/jamsholat-session`),
  karena suara di desktop hanya lewat PulseAudio/PipeWire milik sesi; pengguna
  desktop ditambahkan ke grup `jamsholat`. Tanpa desktop: layanan systemd biasa.
  Raspberry Pi OS Lite tetap memakai `scripts/install.sh` (kiosk cage).
- **Windows 10/11 64-bit:** `JamSholat-Setup-<versi>.exe` (Inno Setup,
  `packaging/windows/`). Membawa Apache 2.4.66 (Apache Lounge VS17), PHP 8.4.26
  TS, Python 3.12 embeddable + OpenCV, mpg123 1.33.7, dan VC++ Runtime. Program di
  `C:\Program Files\JamSholat`, data di `C:\ProgramData\JamSholat`. Apache
  menjadi layanan Windows; alarm, kamera, dan Edge mode kiosk berjalan saat
  pengguna masuk (pintasan Startup → `session.pyw`), alasannya sama dengan
  desktop Linux. Windows 7/8 tidak didukung: PHP 8 dan Python 3.12 sudah tidak
  berjalan di sana.
- **Akun pertama** dibuat di halaman `admin/setup.php` yang hanya bisa dibuka dari
  komputer itu sendiri (localhost) dan hanya selama belum ada akun, jadi
  installer tidak perlu bertanya apa pun.
- **Penyesuaian kode:** keluaran mpg123 per platform (`JAMSHOLAT_MPG123_OUTPUT`),
  WAV lewat PowerShell di Windows, webcam DirectShow di Windows dan V4L2 di
  Linux, jam dianggap terpercaya di Windows atau bila ada RTC (`/dev/rtc0`);
  hanya Linux yang bisa mengatur jam dari panel.
- **Audio ikut di paket** atas permintaan pengguna (±1 GB, murottal 114 surah).
  Audio tidak boleh masuk repo, jadi disimpan sebagai rilis `audio-v1`
  (`jamsholat-audio-v1.zip`, SHA256 dicek) dan diambil saat build. iqomah.wav
  dikonversi ke MP3 64 kbps agar satu pemutar (mpg123) cukup di semua OS.
- **Build & uji di GitHub Actions** (`.github/workflows/package.yml`, disetujui
  pemilik repo): .deb diuji di Debian 12/13, Ubuntu 22.04/24.04, Linux Mint 22;
  installer diuji di Windows Server 2022/2025 (instal senyap, API, setup akun,
  unggah foto, reset kata sandi, alarm, OpenCV, uninstall). Paket diunggah ke
  Releases hanya saat tag `v*`.
- **Bug yang ditemukan uji paket:** SIGTERM ganda (runuser/timeout) memotong
  pembersihan layanan alarm; sinyal berikutnya kini diabaikan.
- **Temuan saat uji installer Windows** (Actions, 19 Okt 2026): (1) PowerShell 5.1
  membuang tanda kutip ganda di argumen `php -r` → database dibuat lewat
  `scripts/init_db_cli.php`; (2) port 80 dipegang Windows sendiri (http.sys, PID 4)
  → `setup.ps1` memakai 80 bila kosong, selain itu 8080 dst., disimpan di
  `C:\ProgramData\JamSholat\port.txt` dan diikuti kiosk, pintasan, firewall;
  (3) `robocopy /XF config.php` ikut membuang `src\Config.php` karena nama file
  Windows tidak peka huruf besar-kecil; (4) `Set-Content -Encoding UTF8` menulis
  BOM yang terkirim sebelum respons PHP (JSON rusak, redirect gagal) → ASCII.
  Hasil akhir: semua pemeriksaan lolos di Windows Server 2022 dan 2025.

- **Temuan uji manual Windows 11** (Azhar, 21 Okt 2026) dan tinjauan alur pengguna
  sesudahnya (v1.1.1):
  1. Shortcut *Layar Jamaah* tidak membuka apa pun setelah layar ditutup dengan
     Alt+F4: sesi dari Startup masih berjalan dan penjaga satu-instans membuat
     sesi kedua keluar diam-diam (uji no. 2 dan 19 kosong). Sekarang sesi kedua
     memberi sinyal lewat event `Local\JamSholatShowDisplay`; sesi pertama membuka
     Edge lagi, atau membawanya ke depan bila masih terbuka tapi tertutup jendela
     lain (`AllowSetForegroundWindow` dari proses yang diklik, lalu
     `SetForegroundWindow`). Layanan alarm tetap satu. Edge kiosk memakai profil
     sendiri (`%LOCALAPPDATA%\JamSholat\edge-kiosk`) supaya `--kiosk` tetap berlaku
     saat Edge biasa terbuka.
  2. Di Linux, menu aplikasi kini punya *Layar Jamaah* dan *Panel Takmir*.
     `jamsholat-session` memakai `flock`: klik kedua tidak menjalankan alarm lagi,
     hanya membuka layar. Bila layar masih terbuka, layar ditutup lalu dibuka lagi
     dari proses yang diklik, karena hanya proses itu yang boleh tampil di depan
     (juga di Wayland, yang melarang aplikasi menaikkan jendela lain). Profil
     browser kiosk bernama `jamsholat-kiosk` (di `~/snap/<browser>/common` untuk
     browser snap Ubuntu).
  3. Cara menutup layar penuh tidak terlihat: layar jamaah menampilkan petunjuk
     "Alt+F4 … suara adzan tetap berjalan" selama 6 detik saat dibuka dan 3 detik
     setiap mouse bergerak, hanya bila dibuka dengan `?layar=desktop` (Windows &
     Linux). Pi tidak memakai parameter itu.
  4. Linux tidak lagi wajib login ulang setelah `apt install`: `postinst` memberi
     akun desktop izin tulis ke `storage/` lewat ACL (langsung berlaku), plus ACL
     bawaan folder agar file baru (mis. `-wal` SQLite) tetap bisa ditulis Apache.
     Grup `jamsholat` tetap dipasang. Akun yang dibuat sesudah instalasi:
     `sudo dpkg-reconfigure jamsholat`.
  5. Instalasi pertama di Windows hanya membuka panel (buat akun, atur lokasi);
     suara adzan sudah aktif (`session.pyw --tanpa-layar`), layar jamaah dibuka
     dari Start. Pintasan Startup diganti nama menjadi *Layar Jamaah*; pintasan
     lama dihapus installer.
  6. Pintasan menampilkan ikon Python karena menunjuk ke `pythonw.exe`. Aplikasi
     kini punya ikon sendiri (`packaging/icon/`, `public/favicon.ico`) untuk
     pintasan, installer, daftar aplikasi Windows, menu Linux, dan tab browser.
  Semua alur ini diuji otomatis: `test-windows.ps1` (Windows Server 2022/2025) dan
  `packaging/debian/test-desktop.sh` (Debian 12, Chromium & Firefox, Xvfb).

## 40. Perbaikan v1.1.2 dari uji instalasi (DIKONFIRMASI)

Hasil uji Linux pengguna (22 Okt 2026) dan tinjauan alur sesudahnya. Semua
perbaikan berlaku untuk Raspberry Pi, Windows, dan Linux, dan dirilis bersama
sebagai satu versi supaya tim cukup memasang ulang sekali.

- **Satu sumber versi:** berkas `VERSION` di akar repo (baris 1 nomor versi,
  baris 2 bulan rilis). Halaman Tentang, `build-deb.sh`, `build.ps1`, dan
  workflow membacanya; tag rilis yang tidak sama dengan isi `VERSION` membuat
  build gagal. Sebelumnya halaman Tentang menulis 1.1 saat paket 1.1.1.
- **Hitung mundur Beranda** (`data-countdown`) berjalan di browser dan memuat
  ulang halaman saat waktu sholat tiba. Tab yang dibiarkan terbuka tidak lagi
  menampilkan angka lama ("8 jam 50 menit" yang sebenarnya dari 2 jam sebelumnya).
- **Pengingat lokasi:** kolom `location_confirmed` (instalasi baru 0, database
  lama 1) menjadi 1 saat Pengaturan → Lokasi disimpan. Selama 0, Beranda
  menampilkan kartu "Atur lokasi masjid dulu" dan layar jamaah menulis kecil
  "Lokasi masjid belum diatur". Tanpa ini, masjid di luar Yogyakarta bisa memakai
  jadwal Yogyakarta tanpa sadar.
- **Slide kas:** judul besar "Kas Masjid", dengan "Baitul Maal بيت المال" kecil di
  bawahnya. Tidak semua jamaah paham istilah Baitul Maal.
- **Deskripsi Tentang** menyebut bahwa aplikasi berjalan di Raspberry Pi, Windows,
  dan Linux.
- **Iqomah baru:** rekaman Syaikh Abdul Karim Al-Makki (gaya Madinah) dari koleksi
  "IQOMAH" di Internet Archive, dipilih pengguna dari tujuh kandidat karena jernih.
  Rekaman lama (Commons, 1:33) terdengar salah dan terlalu panjang. Hening di awal
  dan akhir dipotong, volumenya disamakan dengan adzan (0,58×), MP3 64 kbps mono,
  52 detik. Pengunggah tidak mencantumkan lisensi; atas keputusan pengguna rekaman
  ini tetap dibawa installer, dengan sumber dicantumkan di Tentang dan
  `CREDITS.txt`. Disimpan sebagai aset `iqomah-almakki.mp3` di rilis `audio-v1`
  (SHA256 dicek), ditimpakan ke paket audio saat build.
- **Penjaga volume** (`alarm_service/volume_guard.py`): saat layanan mulai dan
  sebelum setiap adzan/iqomah, volume bisu dibunyikan lagi dan volume di bawah 30%
  dinaikkan ke 70%. PipeWire (`wpctl`) atau PulseAudio (`pactl`) di Linux desktop,
  ALSA (`amixer`) di Raspberry Pi dan server, API volume Windows lewat
  `packaging/windows/ensure-volume.ps1`. Volume di atas 30% tidak disentuh, dan
  kegagalan tidak pernah menghentikan adzan.
- **Layanan adzan di Beranda:** layanan alarm menulis `alarm_heartbeat` tiap menit
  dan hasil cek volume ke `alarm_audio_status`. Beranda menampilkan "Berjalan",
  "Belum pernah berjalan", atau "Tidak berjalan sejak HH:MM" (lebih dari 10 menit,
  karena adzan Subuh menahan loop sekitar 3 menit). Di komputer biasa, layanan
  hanya berjalan setelah ada yang masuk (login), dan sebelumnya takmir tidak
  punya cara untuk tahu.
- **Waktu acara di pengumuman:** kolom baru `event_date`, `event_end_date`,
  `event_start_time`, `event_end_time` (semua opsional). Ditulis seragam oleh
  `Format::eventWhen()`: "Minggu, 25 Okt · 07.00–12.30", "Rabu–Kamis, 28–29 Okt
  · 08.00", "Sabtu, 31 Okt – Senin, 2 Nov". Nama hari mengikuti aplikasi
  (Minggu), sama dengan baris puasa sunnah. Tampil sebagai baris kedua berwarna
  kuningan di baris Pengumuman layar jamaah, dan sebagai label di bawah poster
  portrait (gambar diperkecil, tidak ditimpa). Bila tanggal acara diisi dan
  "Sampai" kosong, "Sampai" otomatis tanggal (selesai) acara, sehingga poster
  hilang sendiri setelah acara. Jam selesai tanpa jam mulai, dan jam selesai
  sebelum jam mulai pada acara satu hari, ditolak. Ikut cadangan dan pemulihan.
- **Ubah pengumuman:** `publications_add.php?id=` memakai formulir yang sama
  dengan isi lama, "Ganti gambar" (gambar lama dihapus setelah gambar baru
  tersimpan), "Simpan perubahan", dan "Hapus pengumuman ini". Status tampil dan
  urutan tidak berubah. Sebelumnya poster yang salah hanya bisa dihapus lalu
  diunggah ulang.
- **Pemutar murottal dengan lanjut dari posisi terakhir:** sebelumnya Jeda lalu
  Putar mengulang surat dari awal. Layanan alarm kini menyimpan posisi
  `murottal_position` ("file|detik|ditulis|1/0") tiap 10 detik selama diputar dan
  setiap kali berhenti (Jeda, jendela diam, adzan, layanan dimatikan). Putar
  berikutnya memakai `mpg123 -k <frame>`; frame per detik dibaca dari header MP3
  per berkas (44,1 kHz dan 48 kHz sama-sama ada di koleksi murottal). Posisi
  bertahan setelah komputer mati, dan pergantian hari tidak lagi mengembalikan
  murottal ke Al-Fatihah. Memilih surat, Berikutnya, Sebelumnya, dan "Mulai ulang
  dari awal surat" memutar surat itu dari awal (permintaan `__next__`,
  `__prev__`, `__restart__` di `murottal_request`). Panel menampilkan pemutar yang
  sama di Beranda (ringkas) dan halaman Murottal (gelap): judul, "Surah n dari
  114", progres dengan durasi (dihitung PHP dari ukuran berkas dan bitrate),
  ⏮ ⏯ ⏭, dan tombol "Lanjutkan dari mm:ss" saat dijeda. Progres berjalan di
  browser dan dicocokkan dengan `/api/murottal.php` tiap 3 detik.
- Keterangan "mulai lagi pukul …" di panel kini memakai jendela diam yang sama
  dengan layanan alarm sejak #30 (15 menit setelah iqomah, minimal 20 menit).
- **Panduan di panel** (`admin/help.php`, Pengaturan → Sistem → Panduan): bantuan
  singkat yang tetap terbuka tanpa internet, per topik (pertama kali dipakai,
  buka/tutup layar, adzan tidak berbunyi, menyala sendiri setelah listrik padam,
  panel dari HP, poster, murottal, lupa kata sandi, cadangan). Langkahnya
  menyesuaikan perangkat (`App\Platform::kind()`: Raspberry Pi, Windows, Linux
  desktop/server); mis. bagian masuk otomatis (netplwiz di Windows, Automatic
  Login di Ubuntu) dan "Restore on AC Power Loss" di BIOS hanya tampil di
  komputer biasa. Baris "Layanan adzan" di Beranda menaut ke bagian "Adzan tidak
  berbunyi". Masuk otomatis tidak dinyalakan diam-diam oleh installer karena
  menyangkut keamanan komputer; takmir yang memutuskan.
- **Buka panel dari HP** (`admin/phone.php`): alamat jaringan lokal komputer
  (alamat yang sedang dipakai browser, `hostname -I` di Linux, alamat host di
  Windows; hanya 10.x, 172.16–31.x, 192.168.x) dan port yang dipakai, dengan kode
  QR yang dibuat di perangkat oleh qrcode-generator 1.4.4 (MIT, disimpan di
  `public/assets/js/vendor/`, tanpa akses jaringan). Bisa dicetak untuk ditempel.
  Di Pi/Linux juga disebut alamat `<host>.local`. Ini menggantikan rencana stiker
  QR Pi yang belum pernah dibuat.
