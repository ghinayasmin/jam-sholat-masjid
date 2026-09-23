# Sistem Jam Sholat Masjid

Jam digital penunjuk waktu sholat berbasis web untuk masjid, berjalan pada
Raspberry Pi 2/3, dengan tampilan jamaah fullscreen, panel admin untuk
takmir, alarm adzan/iqomah/murottal otomatis, dan pemantauan keamanan
berbasis kamera. Dibangun sesuai SRS v0.1 "Jam Digital Penunjuk Waktu
Sholat Berbasis Web untuk Masjid" (Prodi Ilmu Komputer UGM).

Status implementasi lengkap per-requirement: lihat `docs/SRS_COMPLIANCE.md`.
Alasan di balik tiap keputusan desain yang tidak literal tertulis di SRS:
lihat `docs/DECISIONS.md`.

## 1. Project Overview

Sistem terdiri dari 3 komponen yang berjalan independen tapi berbagi satu
database SQLite:

1. **Aplikasi web (PHP)** — melayani tampilan jamaah (fullscreen, kiosk) dan
   panel admin (dilindungi login) untuk takmir mengelola pengaturan,
   publikasi, laporan keuangan, rekaman kamera, dan backup.
2. **Layanan kamera (Python)** — mendeteksi gerakan lewat webcam
   (OpenCV background subtraction) dan menyimpan gambar+metadata.
3. **Layanan alarm (Python)** — memicu suara adzan/iqomah tepat waktu dan
   memutar murottal di sela-sela waktu sholat.

Sistem berjalan **sepenuhnya offline** untuk semua fungsi utamanya; koneksi
internet hanya dipakai opsional untuk sinkronisasi jadwal online dan NTP.

## 2. Architecture

```
Jamaah & Admin (browser, Chromium kiosk)
        │
        ▼
 Apache/Nginx + PHP 8.x  ──────┐
        │                      │
        ▼                      ▼
  SQLite (WAL mode)  ◄──  Python: motion_detect.py (webcam, systemd service)
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
| Kamera | Python 3, OpenCV (`opencv-python-headless`) |
| Alarm/audio | Python 3 (stdlib saja) + `mpg123`/`aplay`/`ffplay` |
| Kiosk display | Xorg minimal + Openbox + Chromium |
| OS | Raspberry Pi OS Lite |
| Service manager | systemd (service + timer) |

## 4. Requirements

- Raspberry Pi 2 atau 3 (RAM 1GB), kartu SD ≥8GB.
- Raspberry Pi OS Lite (versi terbaru) sudah terpasang & bisa SSH/akses
  terminal.
- Webcam USB, speaker (jack audio atau USB), monitor/TV dengan HDMI.
- Koneksi internet **hanya saat instalasi** (untuk `apt`/`pip`) — setelah
  itu opsional.

## 5. Installation

```bash
git clone <repo-anda> jam-sholat-masjid   # atau salin project ini ke Raspberry Pi
cd jam-sholat-masjid
sudo bash scripts/install.sh
```

Script akan: memasang seluruh paket sistem, membuat user layanan
`jamsholat`, menyalin project ke `/opt/jamsholat`, inisialisasi database,
**meminta Anda membuat akun admin pertama secara interaktif**, memasang
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
| `JAMSHOLAT_CAMERA_INDEX` | `motion_detect.py` | `0` |
| `JAMSHOLAT_AUDIO_PATH` | `alarm_service.py` | `public/assets/audio` |
| `JAMSHOLAT_PHP_BIN` | `alarm_service.py` (memanggil `compute_schedule_cli.php`) | `php` |

Nilai-nilai ini sudah di-set di masing-masing file `systemd/*.service`.

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
```

Semua test di atas **tidak butuh hardware fisik** (webcam/speaker/Raspberry
Pi) — memakai frame sintetis dan mock audio player. Total 58 assertion,
semua lulus di lingkungan pengembangan.

Test manual functional (butuh Raspberry Pi sungguhan, mengikuti Appendix D
SRS): checklist ada di `docs/SRS_COMPLIANCE.md`.

## 11. Hardware Setup

1. Pasang webcam USB dan speaker ke Raspberry Pi.
2. Sambungkan monitor/TV lewat HDMI.
3. Setelah `install.sh` + reboot, tampilan jamaah otomatis fullscreen.
4. Uji webcam: `python3 camera_service/motion_detect.py` lalu lambaikan
   tangan di depan kamera — cek `storage/captures/` ada file baru.
5. Uji speaker: `mpg123 public/assets/audio/adzan.mp3` (setelah file audio
   Anda sediakan sendiri — lihat `public/assets/audio/README.md`).
6. **Rekomendasi opsional** (di luar scope SRS): modul RTC (mis. DS3231)
   untuk akurasi waktu offline jangka panjang — lihat `docs/DECISIONS.md` #9.

## 12. Deployment

Sudah tercakup di `scripts/install.sh` (bagian 5 di atas). Untuk
memperbarui instalasi yang sudah ada (mis. setelah `git pull`):

```bash
sudo rsync -a --exclude 'storage/jamsholat.db*' --exclude 'config/config.php' --exclude 'config/.env' \
    . /opt/jamsholat/
sudo systemctl restart jamsholat-camera jamsholat-alarm apache2
```

## 13. Troubleshooting

| Gejala | Kemungkinan Penyebab | Solusi |
|---|---|---|
| Layar jamaah blank/putih | Apache belum jalan atau vhost salah | `sudo systemctl status apache2`; cek `/etc/apache2/sites-enabled/jamsholat.conf` |
| Jadwal sholat tidak muncul | Database belum di-seed atau path config salah | Cek `storage/jamsholat.db` ada; cek `config/config.php` |
| Gagal login admin | Lupa password | `sudo bash scripts/reset_admin_password.sh <username>` (REQ-F-072, offline) |
| "Gagal mengubah jam sistem" di panel admin | Sudoers belum terpasang | Jalankan ulang bagian [8/10] `install.sh`, atau cek `/etc/sudoers.d/jamsholat-date` |
| Kamera tidak mendeteksi gerakan | Webcam tidak terdeteksi/index salah | `ls /dev/video*`; set `JAMSHOLAT_CAMERA_INDEX` di systemd unit sesuai |
| Suara adzan tidak bunyi | File audio belum disediakan, atau `mpg123` tidak terpasang | Cek `public/assets/audio/README.md`; `which mpg123` |
| Chromium tidak fullscreen setelah reboot | X server gagal start | `sudo journalctl -u jamsholat-kiosk.service` untuk lihat error |
| Storage penuh oleh rekaman kamera | Retention belum jalan | Cek `systemctl status jamsholat-retention.timer`; jalankan manual: `python3 camera_service/retention_cleanup.py` |

## 14. Known Limitations

Dikumpulkan dari `docs/DECISIONS.md` — ringkasan:

1. **Hijri date** memakai algoritma tabular matematis, bisa berbeda ±1 hari
   dari pengumuman rukyat resmi Kemenag RI (khususnya awal Ramadhan/Syawal).
2. **Akurasi waktu offline** tanpa modul RTC fisik hanya terjaga untuk
   pemadaman singkat (via `fake-hwclock`); pemadaman berkepanjangan tanpa
   internet bisa menyebabkan jam sedikit meleset sampai NTP sync berikutnya.
3. **Deteksi kamera** hanya deteksi gerakan sederhana (background
   subtraction) — bukan pengenalan wajah/penghitungan orang (memang di luar
   scope SRS, lihat Appendix B).
4. **Online schedule sync** (REQ-F-004) butuh internet aktif saat dipanggil;
   kalau gagal, sistem otomatis tetap pakai hasil kalkulasi lokal (tidak
   ada gangguan ke fungsi utama).
5. **Satu akun admin** per instalasi — tidak ada manajemen multi-user
   (lihat `docs/DECISIONS.md` #4).
6. **File audio** (adzan/iqomah/murottal) tidak disertakan dalam paket ini
   karena isu lisensi — harus disediakan sendiri oleh pengguna.
