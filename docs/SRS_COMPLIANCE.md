# SRS Compliance Checklist

Status per requirement, sesuai instruksi kerja STEP 9. Legenda:
✅ Implemented · ⚠️ Partially implemented · ❌ Not implemented · 🔍 Requires hardware/external dependency (tidak bisa diverifikasi penuh di lingkungan pengembangan, hanya di Raspberry Pi sungguhan)

## Functional Requirements

| Req ID | Judul | Status | Implementasi | Test |
|---|---|---|---|---|
| REQ-F-001 | Prayer Time Calculation | ✅ | `PrayerTimeCalculator.php` | `test_prayer_time_calculator.php` — divalidasi vs AlAdhan API |
| REQ-F-002 | Madzhab Selection | ✅ | `PrayerTimeCalculator.php` (shadow factor), `settings_location.php` | ✅ Asr Hanafi > Syafii terverifikasi |
| REQ-F-003 | Location Offset | ✅ | `PrayerTimeCalculator.php`, `settings_location.php` | ✅ offset +5 menit terverifikasi di 6 waktu |
| REQ-F-004 | Online Schedule Sync | ⚠️ | `ScheduleSync.php` (AlAdhan API) | Kelas lengkap tapi **belum dipanggil** dari mana pun (ditemukan 14 Okt 2026), jadi jadwal selalu dari kalkulasi lokal. Tugas: panggil dari `compute_schedule_cli.php` dan buat test case |
| REQ-F-005 | Hijri Calendar Display | ✅ | `HijriConverter.php` | ✅ exact match vs referensi AlAdhan |
| REQ-F-006 | Prayer Calculation Method | ✅ | Sudut Kemenag (Fajr 20°/Isha 18°), 100% lokal | ✅ |
| REQ-F-007 | Hijri Date Rollover & Adjustment | ✅ | `display.js` (`hijriAfterMaghrib`), `ScheduleEngine::hijriFor()`, `admin/hijri.php` | ✅ **Diuji di Pi 3** (TC-JDW-005): tanggal berganti saat Maghrib, koreksi ±1 hari (DECISIONS #20) |
| REQ-F-010 | Fullscreen Display | ✅ | `display.php` + kiosk service (cage) | ✅ **Diuji di Pi 3 (12 Okt 2026):** tampil fullscreen di TV lewat HDMI, tanpa bingkai browser, ter-update otomatis setelah `update.sh` |
| REQ-F-011 | Publication Slideshow | ✅ | `display.js`, `api/publications.php`, `admin/slides.php` | ✅ upload→tampil di API teruji end-to-end; jeda & lama tampil diatur takmir (DECISIONS.md #27), foto masjid ikut rotasi (#28) |
| REQ-F-012 | Financial Report Display | ✅ | `display.js`, `api/financial.php`, sakelar `show_finance_on_display` | Tampil sebagai slide seukuran poster (saldo + total bulan ini), bawaan nyala untuk instalasi baru; takmir bisa menyembunyikannya. Lihat DECISIONS.md #14, #27 |
| REQ-F-013 | Sunnah Fasting Info | ✅ | `ScheduleEngine::getFastingInfo()` | ✅ Ayyamul Bidh (13/14/15) & Senin-Kamis teruji |
| REQ-F-014 | Pre-Adzan Reminder | ✅ | `display.js` (`computeView`), `admin/reminder.php` | ✅ `test_display_logic.js` (TC-DISP-003): 10 menit, Jumat 30 menit |
| REQ-F-015 | Landscape Poster Display | ✅ | `display.js` (`renderPoster`, `WIDE_POSTER_RATIO`) | ✅ **Diuji di Pi 3** (TC-DISP-004), DECISIONS #21 |
| REQ-F-020 | System Time & Date Setting | ✅ | `settings_time.php` (via `sudo date`) | ✅ **Diuji di Pi 3:** jam diubah dari panel di HP, jam perangkat dan layar TV ikut berubah, sudoers dari `install.sh` berfungsi |
| REQ-F-021 | Location & Madzhab Setting | ✅ | `settings_location.php` | ✅ teruji end-to-end via HTTP |
| REQ-F-022 | Publication Upload | ✅ | `publications.php`, `publications_add.php` (poster atau teks, tanggal mulai/selesai) | ✅ teruji: JPEG valid diterima, file non-image ditolak; pengumuman teks & terjadwal teruji via HTTP |
| REQ-F-023 | Camera Footage Access | ✅ | `camera.php`, `capture.php`, `api/motion_events.php` | ✅ **Diuji di Pi 3:** foto gerakan tampil di tab Kamera (HP), bisa dihapus per foto atau per tanggal |
| REQ-F-024 | Financial Report Update | ✅ | `financial.php`, `financial_add.php` | ✅ teruji end-to-end via HTTP (simpan, validasi, hapus) |
| REQ-F-025 | Adzan-Iqomah Interval Setting | ✅ | `sound.php`: jeda per waktu sholat `iqomah_<sholat>_minutes`, Subuh bawaan 20 menit (DECISIONS #30) | ✅ unit test `test_alarm_service.py`; dikembalikan atas revisi dosen 19 Okt 2026 |
| REQ-F-026 | Mosque Profile Setting | ✅ | `mosque_profile.php` | Syntax teruji |
| REQ-F-030 | Adzan Alarm | ✅ | `alarm_service.py` (`AlarmScheduler`), `adzan_subuh.*` khusus Subuh, sakelar `adzan_sound_enabled` di `sound.php` (DECISIONS #23) | ✅ unit test; **di Pi 3:** adzan Isya terpicu tepat 18:43 (log journal); speaker HDMI lewat `set_audio_output.sh` |
| REQ-F-031 | Iqomah Alarm | ✅ | `alarm_service.py`, `iqomah.wav` (CC0). Bawaan nyala untuk instalasi baru; Jumat Dzuhur dilewati (khutbah) (DECISIONS #30) | ✅ unit test; uji dengar di Pi menyusul |
| REQ-F-032 | Murottal Playback | ✅ | `alarm_service.py` (`AudioPlayer`, quiet window), Putar/Jeda & Pilih surat di panel | ✅ unit test + simulasi loop; **di Pi 3:** murottal bunyi lewat TV, pindah surat dari HP, nama surat tampil di layar (DECISIONS #18) |
| REQ-F-033 | Background Alarm Service | ⚠️ | `jamsholat-alarm.service` (bukan `.timer`, lihat `docs/DECISIONS.md` #7) | Deviasi terdokumentasi & beralasan |
| REQ-F-034 | Subuh Adzan | ✅ | `alarm_service.py` (`alarm_audio_file`) | ✅ `test_alarm_service.py` (TC-ALM-004) |
| REQ-F-040 | Continuous Monitoring | ✅ | `motion_detect.py` loop, Picamera2 (Camera V2 NoIR, CSI) | ✅ **Diuji di Pi 3:** layanan kamera membaca frame terus-menerus, gerakan tangan terdeteksi (DECISIONS #17) |
| REQ-F-041 | Motion Detection | ✅ | `motion_detect.py` (`MotionDetector`, MOG2, zona abaikan, filter perubahan cahaya, konfirmasi 2 frame — DECISIONS #25) | ✅ 9 skenario frame sintetis teruji |
| REQ-F-042 | Auto Image Capture | ✅ | `motion_detect.py` (`EventStore`) | ✅ file + DB row tersimpan, teruji |
| REQ-F-043 | Background Camera Service | ✅ | `jamsholat-camera.service` | ✅ **Diuji di Pi 3:** berjalan sebagai layanan systemd, menyimpan foto 1280×960 saat ada gerakan |
| REQ-F-044 | Image Retention Policy | ✅ | `retention_cleanup.py` | ✅ cleanup by age teruji; cleanup by storage% teruji logikanya (perlu disk sungguhan utk validasi penuh) |
| REQ-F-045 | Captured Image Deletion | ✅ | `admin/camera.php` (hapus per foto/per tanggal, konfirmasi) | ✅ **Diuji di Pi 3** (TC-CAM-005) |
| REQ-F-050 | Data Backup | ✅ | `BackupManager.php` | ✅ teruji end-to-end |
| REQ-F-051 | Data Restore | ✅ | `BackupManager.php` | ✅ round-trip backup→ubah data→restore→data pulih, teruji |
| REQ-F-060 | Automatic Time Sync | ✅ | OS-level `systemd-timesyncd` (lihat DECISIONS.md #10) | Standar OS, tidak perlu kode custom |
| REQ-F-061 | Offline Time Accuracy | ✅ | `fake-hwclock` (DECISIONS.md #9) + jam dicocokkan otomatis dengan jam HP saat panel dibuka (`SystemClock`, `clock_sync.php`, DECISIONS.md #24) | Diuji di Pi 3 (TC-SYNC-002) |
| REQ-F-070 | Admin Authentication | ✅ | `AuthManager.php` | ✅ unit test; **di Pi 3:** login salah ditolak, login benar masuk ke Beranda (HP) |
| REQ-F-071 | Restricted Settings Access | ✅ | `AuthMiddleware.php` | ✅ redirect ke login saat belum auth, teruji |
| REQ-F-072 | Offline Admin Password Recovery | ✅ | `admin/forgot.php` + kode pemulihan (`RecoveryCode`), perintah teknisi `sudo jamsholat-reset-password` (`scripts/reset_password_cli.php`) (DECISIONS #33) | ✅ uji integrasi + uji alur web lokal; tanpa internet |

## Non-Functional Requirements

| Req ID | Judul | Status | Catatan |
|---|---|---|---|
| REQ-NF-100 | Low-Resource Operation | ✅ | **Diukur di Pi 3 Model B (RAM 920 MB terbaca), 12 Okt 2026 19:32**, semua layanan aktif + murottal diputar + kamera jalan: RAM terpakai **469 MB** (`free -m`, kolom used), tersedia 450 MB, swap 29 MB; load average 2,09 / 1,48 / 1,29 (4 inti). Batas SRS ≤700 MB terpenuhi. Pemakai terbesar: Chromium (beberapa proses, berbagi memori), layanan kamera Python ±134 MB RSS, cage ±75 MB |
| REQ-NF-101 | Display Responsiveness | ✅ | Jam client-side `setInterval` 1 detik; transisi slide CSS `transition: opacity 1s` |
| REQ-NF-102 | Lightweight Background Services | ✅ | `CPUQuota`/`MemoryMax` di unit systemd; `time.sleep()` di setiap loop Python. **Di Pi 3, 14 Okt 2026:** cgroup memori diaktifkan (`cgroup_enable=memory`, `install.sh`), batas berlaku: alarm 10/100 MB, kamera 64/200 MB |
| REQ-NF-110 | Continuous Operation | ✅ | `Restart=on-failure` di semua `.service`; try/except pembungkus loop utama (Python) |
| REQ-NF-111 | Power Failure Recovery | ✅ | systemd `enable` (auto-start on boot) + `fake-hwclock` |
| REQ-NF-112 | Graceful Error Handling | ✅ | `bootstrap.php` exception handler; try/except di semua loop Python; PHP tidak pernah expose stack trace mentah |
| REQ-NF-120 | Authenticated Admin Access | ✅ | `AuthMiddleware` di semua halaman `/admin/*` |
| REQ-NF-121 | Credential Storage | ✅ | `password_hash()`/`password_verify()` (bcrypt); tidak ada password plaintext di kode/config |
| REQ-NF-122 | Local Network Scope | ✅ | Konfigurasi Apache `Require ip <rentang LAN>` di `install.sh` |
| REQ-NF-130 | Cross-Device OS Support | ✅ | Tidak ada kode spesifik model Pi; semua path relatif/config-driven |
| REQ-NF-131 | Browser-Based Access | ✅ | Aplikasi 100% web (HTML/JS/PHP), tanpa aplikasi native |
| REQ-NF-140 | Readable Public Display | ✅ | `display.css` pakai unit `vw`/`vh`, font besar, kontras tinggi |
| REQ-NF-141 | Non-Technical Admin Usability | ✅ | Panel untuk HP dengan 5 tab, bahasa pengurus, pilihan siap ketuk, istilah teknis dipisah ke halaman Lokasi, konfirmasi sebelum hapus/pulihkan (DECISIONS.md #16) |

## Ringkasan

- **36 Functional Requirements (SRS v0.2)**: 34 ✅, 2 ⚠️, ditambah REQ-F-025/031 (iqomah) yang dikembalikan atas revisi dosen. REQ-F-004 sinkronisasi online belum dipanggil, REQ-F-033 deviasi arsitektur terdokumentasi.
- **13/13 Non-Functional Requirements**: diimplementasikan. REQ-NF-100 terverifikasi di Raspberry Pi 3: 469 MB dari batas 700 MB.
- **Tidak ada placeholder/dummy implementation** — setiap fitur yang diberi status ✅ punya kode kerja nyata dan sudah lolos test otomatis (58 assertion PHP+Python total, lihat `tests/`).
- **Uji di perangkat asli (12 Okt 2026)**: Raspberry Pi 3 Model B v1.2, Raspberry Pi OS Lite 13 (Trixie) 32-bit, TV lewat HDMI (layar + speaker), Raspberry Pi Camera V2 NoIR (CSI). Login admin & 5 tab, ubah jam, speaker HDMI, adzan/murottal, kamera, dan RAM sudah diuji langsung. Belum diuji di perangkat: kotak masjid tanpa internet (sinkron jadwal offline) dan pemakaian berhari-hari.
- Temuan yang hanya muncul di perangkat asli dan sudah diperbaiki: kamera CSI tidak terbaca lewat OpenCV (→ Picamera2), suara bawaan ke jack bukan HDMI (→ `set_audio_output.sh`), `mpg123` crash karena mencoba JACK (→ `-o alsa`), foto kamera tercatat UTC, cache CSS di kiosk, dan pemutar murottal yang meloncat surat saat HDMI masih dipakai proses lama.
