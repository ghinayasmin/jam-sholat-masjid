# Sistem Jam Sholat Masjid

Jam digital penunjuk waktu sholat berbasis web untuk masjid, berjalan pada
Raspberry Pi 2/3, dengan tampilan jamaah fullscreen, panel admin untuk
takmir di HP, alarm adzan/iqomah, murottal, dan pemantauan keamanan berbasis
kamera. Dibangun sesuai SRS v0.2 "Jam Digital Penunjuk Waktu Sholat Berbasis
Web untuk Masjid" (Prodi Ilmu Komputer UGM) dan sudah diuji di Raspberry Pi 3
Model B (Raspberry Pi OS Lite 13 Trixie) pada 12 Oktober 2026.

Status implementasi lengkap per-requirement: lihat `docs/SRS_COMPLIANCE.md`.
Alasan di balik tiap keputusan desain yang tidak literal tertulis di SRS:
lihat `docs/DECISIONS.md`.
Panduan tampilan (warna, huruf, komponen layar jamaah & panel takmir):
lihat `docs/DESIGN.md`.

## 1. Project Overview

Sistem terdiri dari 3 komponen yang berjalan independen tapi berbagi satu
database SQLite:

1. **Aplikasi web (PHP)** — melayani tampilan jamaah (fullscreen, kiosk) dan
   panel admin (dilindungi login) untuk takmir mengelola pengaturan,
   publikasi, laporan keuangan, rekaman kamera, dan backup.
2. **Layanan kamera (Python)** — mendeteksi gerakan lewat Raspberry Pi
   Camera Module (Picamera2) atau webcam USB (OpenCV), lalu menyimpan
   gambar+metadata.
3. **Layanan alarm (Python)** — memutar adzan (adzan Subuh terpisah) dan
   iqomah tepat waktu (masing-masing bisa dimatikan dari panel), serta murottal yang diputar/dijeda takmir dari panel.

### Fitur utama

| Untuk | Fitur |
|---|---|
| Jamaah (TV) | Jam besar, jadwal sholat sesuai Kemenag (dengan ihtiyat), tanggal Hijriah yang berganti saat Maghrib, hitung mundur dan pengingat sebelum adzan, info puasa sunnah, pengumuman/poster (landscape tampil layar penuh), nama surat murottal yang diputar |
| Takmir (HP) | Beranda, pengumuman terjadwal, kas (catat, ubah, hapus), rekaman kamera (lihat dan hapus), murottal (putar/jeda/pilih dari 114 surat), profil & foto masjid, lokasi dan madzhab, koreksi jadwal per sholat, suara adzan dan iqomah dengan jeda per sholat (Subuh 20 menit), hitung mundur iqomah, pengingat adzan, pergantian slide, koreksi tanggal Hijriah ±1 hari, jam & tanggal, kode pemulihan kata sandi, backup/restore, tentang aplikasi |

Sistem berjalan **sepenuhnya offline** untuk semua fungsi utamanya; koneksi
internet hanya dipakai saat instalasi dan (opsional) untuk mencocokkan jam lewat NTP.

## 2. Architecture

```
Jamaah & Admin (browser, Chromium kiosk)
        │
        ▼
 Apache/Nginx + PHP 8.x  ──────┐
        │                      │
        ▼                      ▼
  SQLite (WAL mode)  ◄──  Python: motion_detect.py (kamera, systemd service)
        ▲                      
        │                      
  Python: alarm_service.py (baca jadwal, putar audio, systemd service)
```

Detail lengkap (data flow, auth, hardware integration): lihat bagian
"C. System Architecture" di analisis SRS awal (dibagikan sebagai artifact
terpisah) dan komentar docblock di `src/Schedule/ScheduleEngine.php`,
`src/Auth/AuthManager.php`.

## 3. Technology Stack

| Komponen | Teknologi |
|---|---|
| Backend web | PHP 8.x (tanpa framework/Composer — lihat `docs/DECISIONS.md`), PDO_SQLITE |
| Web server | Apache 2 (`libapache2-mod-php`) |
| Frontend | HTML/CSS/JavaScript vanilla (tanpa build step) |
| Database | SQLite 3 (WAL mode) |
| Kamera | Python 3, OpenCV (deteksi gerakan), Picamera2/libcamera (Camera Module CSI) |
| Alarm/audio | Python 3 (stdlib saja) + `mpg123 -o alsa`/`aplay` |
| Kiosk display | `cage` (Wayland kiosk compositor) + Chromium |
| OS | Raspberry Pi OS Lite |
| Service manager | systemd: service untuk kiosk, alarm, dan kamera; timer harian untuk retensi foto |

## 4. Requirements

- Raspberry Pi 2 atau 3 (RAM 1GB), kartu SD ≥8GB (audio murottal lengkap
  butuh ±1 GB). Pi 3 hanya bisa WiFi 2,4 GHz.
- Raspberry Pi OS Lite (versi terbaru) sudah terpasang & bisa SSH/akses
  terminal.
- Raspberry Pi Camera Module (CSI) atau webcam USB; speaker TV lewat HDMI
  atau speaker aktif lewat jack 3,5 mm; monitor/TV dengan HDMI.
- Koneksi internet **hanya saat instalasi** (untuk `apt`/`pip`) — setelah
  itu opsional.

## 5. Installation

```bash
# Repo privat: salin dari komputer ke Pi, bukan git clone di Pi
rsync -av --exclude .git jam-sholat-masjid/ <user>@<ip-pi>:~/jam-sholat-masjid/
ssh <user>@<ip-pi>
cd ~/jam-sholat-masjid
sudo bash scripts/install.sh
```

Script akan: memasang seluruh paket sistem, membuat user layanan
`jamsholat`, menyalin project ke `/opt/jamsholat`, inisialisasi database,
menyiapkan akun takmir bawaan **admin / 000** (wajib diganti saat pertama masuk), memasang
dependency Python, mengatur timezone `Asia/Jakarta`, mengonfigurasi Apache
vhost + pembatasan akses LAN, dan mengaktifkan semua systemd service.

Setelah instalasi selesai, **reboot** perangkat agar kiosk Chromium
otomatis tampil di layar:

```bash
sudo reboot
```

## 6. Configuration

Salin dan sesuaikan dua file konfigurasi (dilakukan otomatis oleh
`install.sh`, tapi bisa diedit manual):

- `config/config.php` (dari `config/config.example.php`) — path
  penyimpanan, batas ukuran upload, base URL AlAdhan API, dsb. **Tidak
  berisi rahasia**, boleh dilihat siapa saja.
- `config/.env` (dari `config/.env.example`) — untuk nilai rahasia bila
  diperlukan di masa depan. Saat ini kosong: kredensial admin dibuat
  interaktif lewat `install.sh`, **tidak pernah** hardcoded di file.

Pengaturan operasional sehari-hari (lokasi, madzhab, profil masjid, jarak
adzan-iqomah, retensi gambar) diatur lewat **panel admin**, tersimpan di
tabel `mosque_settings` — bukan file config.

## 7. Database Setup

Skema: `database/schema.sql` (SQLite, 7 tabel — lihat komentar REQ di tiap
tabel). Dibuat otomatis oleh `install.sh`:

```bash
sqlite3 storage/jamsholat.db < database/schema.sql
```

Idempotent (`CREATE TABLE IF NOT EXISTS`) — aman dijalankan ulang.
WAL mode diaktifkan otomatis oleh `src/Db/Database.php` saat runtime,
supaya PHP dan Python (`motion_detect.py`) bisa mengakses file yang sama
secara bersamaan.

## 8. Environment Variables

Semua nilai konfigurasi bisa dioverride lewat environment variable (nama
KEY di `config.php` di-uppercase), berguna untuk testing atau override
lewat systemd `Environment=`:

| Variable | Dipakai oleh | Default |
|---|---|---|
| `JAMSHOLAT_DB_PATH` | `motion_detect.py`, `retention_cleanup.py`, `alarm_service.py` | `storage/jamsholat.db` |
| `JAMSHOLAT_CAPTURES_PATH` | `motion_detect.py`, `retention_cleanup.py` | `storage/captures` |
| `JAMSHOLAT_CAMERA_TYPE` | `motion_detect.py` (`auto`, `picamera`, `usb`) | `auto` |
| `JAMSHOLAT_CAMERA_INDEX` | `motion_detect.py` (webcam USB) | `0` |
| `JAMSHOLAT_CAMERA_ROTATION` | `motion_detect.py` (0/90/180/270 searah jarum jam) | `0` |
| `JAMSHOLAT_CAMERA_TUNING` | `motion_detect.py` (mis. `imx219_noir.json` untuk Camera V2 NoIR) | kosong |
| `JAMSHOLAT_AUDIO_PATH` | `alarm_service.py` | `public/assets/audio` |
| `JAMSHOLAT_PHP_BIN` | `alarm_service.py` (memanggil `compute_schedule_cli.php`) | `php` |

Nilai-nilai ini sudah di-set di masing-masing file `systemd/*.service`. Untuk
mengubahnya per perangkat tanpa tertimpa `update.sh`, pakai drop-in:
`sudo systemctl edit jamsholat-camera` lalu tulis `[Service]` dan
`Environment=JAMSHOLAT_CAMERA_ROTATION=90`.

## 8b. Instalasi di komputer biasa (tanpa Raspberry Pi)

Paket siap pakai ada di halaman **Releases** (dibangun oleh `.github/workflows/package.yml`):

- **Windows 10/11 64-bit:** jalankan `JamSholat-Setup-<versi>.exe`. Apache, PHP,
  Python, dan audio sudah termasuk.
- **Debian, Ubuntu, Linux Mint, Raspberry Pi OS (desktop):**
  `sudo apt install ./jamsholat_<versi>_all.deb`

Lalu buka `http://localhost/admin/` dan masuk dengan **admin / 000**. Panel meminta kata sandi
baru, lalu menuntun Langkah awal (nama masjid, lokasi, periksa suara).
Layar jamaah dibuka dari menu **Layar Jamaah** (Start di Windows, menu aplikasi di
Linux) dan ditutup dengan Alt+F4; suara adzan tetap berjalan. Setelah itu layar dan
suara menyala sendiri setiap kali pengguna masuk.
Detail rancangan: `docs/DECISIONS.md` #39, sumber paket: `packaging/`.

## 9. Running the Application

**Development/testing di komputer lokal** (bukan Raspberry Pi):

```bash
cp config/config.example.php config/config.php
sqlite3 storage/jamsholat.db < database/schema.sql
php -r "require 'bootstrap.php'; (new App\Repository\AdminUserRepository())->create('admin', password_hash('test1234', PASSWORD_BCRYPT));"
php -S localhost:8080 -t public
```

Buka `http://localhost:8080/display.php` (tampilan jamaah) dan
`http://localhost:8080/admin/login.php` (panel admin).

**Di Raspberry Pi (produksi):** otomatis berjalan via Apache + systemd
setelah `install.sh` + reboot. Tidak perlu perintah manual.

Menjalankan layanan Python secara manual (untuk debug):

```bash
python3 camera_service/motion_detect.py
python3 alarm_service/alarm_service.py
```

## 10. Running Tests

```bash
# Unit test PHP (kalkulasi waktu sholat & Hijriyah — divalidasi vs AlAdhan API)
php tests/unit/test_prayer_time_calculator.php

# Integration test PHP (DB, repository, auth, backup/restore end-to-end)
php tests/integration/test_core_integration.php

# Unit test Python (motion detection, alarm scheduling, retention)
python3 -m unittest discover -s tests/unit -p "test_*.py" -v

# Unit test JavaScript (logika layar jamaah: berikutnya, pengingat, adzan, Jumat)
TZ=Asia/Jakarta node tests/unit/test_display_logic.js
```

Untuk melihat layar pengingat atau adzan tanpa menunggu waktu sholat, buka
`display.php?jam=17:28` (jam disimulasikan; tanggal tetap hari ini).

Semua test di atas **tidak butuh hardware fisik** (kamera/speaker/Raspberry
Pi) — memakai frame sintetis dan mock audio player. Total 88 assertion PHP/JS
dan 25 test Python, semua lulus. Test kamera Python butuh OpenCV
(`pip install opencv-python-headless "numpy<2"`).

Hasil uji di Raspberry Pi 3 sungguhan (RAM 469/700 MB, jadwal cocok dengan
Kemenag, temuan dan perbaikannya) tercatat per requirement di
`docs/SRS_COMPLIANCE.md`.

## 11. Hardware Setup

1. Pasang kamera (Camera Module ke soket CSI, atau webcam ke USB) dan speaker.
2. Sambungkan monitor/TV lewat HDMI **sebelum** Pi dinyalakan.
3. Setelah `install.sh` + reboot, tampilan jamaah otomatis fullscreen.
4. Uji kamera: lambaikan tangan di depan kamera, lalu buka tab Kamera di
   panel. Foto miring? Atur `JAMSHOLAT_CAMERA_ROTATION` (bagian 8).
5. Pilih keluaran suara: `sudo bash scripts/set_audio_output.sh hdmi` (speaker
   TV/monitor) atau `... jack` (speaker aktif 3,5 mm). Bawaan Pi OS adalah jack,
   jadi speaker HDMI diam sampai langkah ini dijalankan.
6. Uji speaker: `speaker-test -c 2 -t wav -l 1`, lalu
   `mpg123 -o alsa /opt/jamsholat/public/assets/audio/adzan.mp3`.
7. Isi koordinat masjid di panel (Pengaturan → Lokasi). Jadwal dihitung dari
   titik ini.
8. **Rekomendasi opsional** (di luar scope SRS): modul RTC (mis. DS3231)
   untuk akurasi waktu offline jangka panjang — lihat `docs/DECISIONS.md` #9.

## 12. Deployment

Instalasi pertama: `scripts/install.sh` (bagian 5). Untuk memperbarui Pi
yang sudah terpasang, kirim kode terbaru dari komputer lalu jalankan
`update.sh` di Pi:

```bash
# di komputer
rsync -av --delete --exclude .git jam-sholat-masjid/ <user>@<ip-pi>:~/jam-sholat-masjid/
# di Pi
cd ~/jam-sholat-masjid && sudo bash scripts/update.sh
```

`update.sh` menyalin kode ke `/opt/jamsholat` tanpa menyentuh database,
rekaman kamera, backup, upload, dan konfigurasi lokal, menghitung ulang cache
jadwal, lalu memulai ulang layanan. CSS/JS diberi versi otomatis, jadi layar
TV langsung memakai tampilan terbaru.

Panduan memasang di masjid (WiFi, kunci IP, koordinat, stiker QR, serah
terima ke takmir) disusun terpisah sebagai dokumen tim.

## 13. Troubleshooting

| Gejala | Kemungkinan Penyebab | Solusi |
|---|---|---|
| Layar jamaah blank/putih | Apache belum jalan atau vhost salah | `sudo systemctl status apache2`; cek `/etc/apache2/sites-enabled/jamsholat.conf` |
| Jadwal sholat tidak muncul | Database belum di-seed atau path config salah | Cek `storage/jamsholat.db` ada; cek `config/config.php` |
| Gagal login admin | Lupa password | Kembalikan ke akun bawaan admin / 000 dari perangkat: `sudo jamsholat-reset-password` (Pi/Linux), menu "Reset Kata Sandi Jam Sholat" (Linux desktop), atau Start → Jam Sholat Masjid → Reset kata sandi (Windows). Data tidak terhapus; kata sandi wajib diganti saat masuk (REQ-F-072, DECISIONS #41) |
| "Gagal mengubah jam sistem" di panel admin | Sudoers belum terpasang | Jalankan ulang bagian [8/10] `install.sh`, atau cek `/etc/sudoers.d/jamsholat-date` |
| Kamera tidak mendeteksi gerakan | Camera Module: kabel pita terbalik/longgar. Webcam: index salah | `journalctl -u jamsholat-camera -n 30`; Camera Module: `python3 -c "from picamera2 import Picamera2; print(Picamera2.global_camera_info())"`; webcam: `ls /dev/video*` dan `JAMSHOLAT_CAMERA_INDEX` |
| Murottal tidak bunyi | Belum diputar di panel, atau sedang jendela diam sekitar waktu sholat | Lihat status di Beranda panel; `journalctl -u jamsholat-alarm -n 30` |
| Jadwal beda >2 menit dari Kemenag | Koordinat masjid belum diisi | Pengaturan → Lokasi |
| Suara adzan tidak bunyi | File audio belum disediakan, `mpg123` tidak terpasang, atau keluaran suara salah (HDMI vs jack) | Cek `public/assets/audio/README.md`; `which mpg123`; `sudo bash scripts/set_audio_output.sh hdmi` |
| Chromium tidak fullscreen setelah reboot | cage/Chromium gagal start | `sudo journalctl -u jamsholat-kiosk.service` untuk lihat error |
| Storage penuh oleh rekaman kamera | Retention belum jalan | Cek `systemctl status jamsholat-retention.timer`; jalankan manual: `python3 camera_service/retention_cleanup.py` |

## 14. Known Limitations

Dikumpulkan dari `docs/DECISIONS.md` — ringkasan:

1. **Hijri date** memakai algoritma tabular matematis, bisa berbeda ±1 hari
   dari keputusan sidang isbat. Takmir mengoreksinya di Pengaturan → Tanggal
   Hijriah (DECISIONS #20).
2. **Akurasi waktu offline** tanpa modul RTC fisik hanya terjaga untuk
   pemadaman singkat (via `fake-hwclock`); pemadaman berkepanjangan tanpa
   internet bisa menyebabkan jam sedikit meleset sampai NTP sync berikutnya.
3. **Deteksi kamera** hanya deteksi gerakan sederhana (background
   subtraction) — bukan pengenalan wajah/penghitungan orang (memang di luar
   scope SRS, lihat Appendix B).
4. **Tanpa sinkron jadwal online** (REQ-F-004 tidak dipakai, DECISIONS #36).
   Jadwal dihitung lokal dengan ihtiyat Kemenag dan sudah tepat sama dengan
   jadwal resmi pada 79–95% hari (sisanya selisih 1 menit, DECISIONS #19).
   Selisih setempat bisa diatur di Koreksi jadwal.
5. **Satu akun admin** per instalasi — tidak ada manajemen multi-user
   (lihat `docs/DECISIONS.md` #4).
6. **File audio** (adzan/iqomah/murottal, ±1 GB) tidak masuk git. Sumber
   berlisensi bebas yang dipakai saat uji tercatat di
   `public/assets/audio/CREDITS.txt`; salin ke Pi bersama kode.
7. **Panel memakai HTTP** di jaringan lokal, sehingga browser menampilkan
   ikon "Not secure" (DECISIONS #22).
8. **Camera V2 NoIR** (tanpa filter inframerah) menghasilkan warna merah muda
   di ruangan; cukup untuk deteksi gerakan.
