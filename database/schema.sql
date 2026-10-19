-- Sistem Jam Sholat Masjid — SQLite Schema
-- REQ traceability: lihat docs/REQUIREMENT_TRACEABILITY.md

PRAGMA foreign_keys = ON;

-- Singleton config table (REQ-F-020, REQ-F-021, REQ-F-026, REQ-F-030, REQ-F-044)
CREATE TABLE IF NOT EXISTS mosque_settings (
    id                              INTEGER PRIMARY KEY CHECK (id = 1),
    mosque_name                     TEXT NOT NULL DEFAULT 'Masjid',
    mosque_address                  TEXT NOT NULL DEFAULT '',
    latitude                        REAL NOT NULL DEFAULT -7.797068,
    longitude                       REAL NOT NULL DEFAULT 110.370529,
    timezone_offset_hours           REAL NOT NULL DEFAULT 7.0,   -- WIB = UTC+7
    madzhab                         TEXT NOT NULL DEFAULT 'syafii' CHECK (madzhab IN ('syafii','hanafi')),
    location_offset_minutes         INTEGER NOT NULL DEFAULT 0,
    azan_iqomah_interval_minutes    INTEGER NOT NULL DEFAULT 10,  -- lama, diganti iqomah_<sholat>_minutes
    -- Jeda adzan→iqomah per waktu sholat (DECISIONS.md #30). Subuh lebih panjang.
    iqomah_fajr_minutes             INTEGER NOT NULL DEFAULT 20,
    iqomah_dhuhr_minutes            INTEGER NOT NULL DEFAULT 10,
    iqomah_asr_minutes              INTEGER NOT NULL DEFAULT 10,
    iqomah_maghrib_minutes          INTEGER NOT NULL DEFAULT 10,
    iqomah_isha_minutes             INTEGER NOT NULL DEFAULT 10,
    iqomah_countdown_enabled        INTEGER NOT NULL DEFAULT 1 CHECK (iqomah_countdown_enabled IN (0,1)),  -- layar, #31
    image_retention_days            INTEGER NOT NULL DEFAULT 7,    -- 3/7/14/30, diatur di tab Kamera
    image_retention_storage_pct     INTEGER NOT NULL DEFAULT 80,
    online_sync_enabled             INTEGER NOT NULL DEFAULT 1 CHECK (online_sync_enabled IN (0,1)),
    -- Layar jamaah: pengingat sebelum adzan & visibilitas kas (lihat DECISIONS.md #14, #15).
    -- Kolom yang sama ditambahkan ke database lama lewat Database::migrate().
    reminder_enabled                INTEGER NOT NULL DEFAULT 1 CHECK (reminder_enabled IN (0,1)),
    reminder_minutes                INTEGER NOT NULL DEFAULT 10,
    reminder_friday_minutes         INTEGER NOT NULL DEFAULT 30,
    reminder_message                TEXT NOT NULL DEFAULT 'Mari bersiap dan mengambil air wudhu',
    show_finance_on_display         INTEGER NOT NULL DEFAULT 1 CHECK (show_finance_on_display IN (0,1)),
    location_name                   TEXT NOT NULL DEFAULT '',   -- label lokasi untuk pengurus, mis. "Sleman, DIY"
    -- Murottal: diputar/dijeda takmir dari panel; layanan alarm menulis surat yang sedang diputar.
    murottal_enabled                INTEGER NOT NULL DEFAULT 0 CHECK (murottal_enabled IN (0,1)),
    murottal_now_playing            TEXT NOT NULL DEFAULT '',
    murottal_request                TEXT NOT NULL DEFAULT '',   -- "file|cap waktu": surat yang dipilih takmir
    alarm_now_playing               TEXT NOT NULL DEFAULT '',   -- "adzan|maghrib" selama rekaman diputar (#32)
    -- Suara adzan/iqomah dari speaker. Bisa dimatikan bila dikumandangkan langsung oleh muadzin;
    -- Instalasi baru: iqomah bawaan nyala (revisi dosen, DECISIONS.md #30).
    adzan_sound_enabled             INTEGER NOT NULL DEFAULT 1 CHECK (adzan_sound_enabled IN (0,1)),
    iqomah_sound_enabled            INTEGER NOT NULL DEFAULT 1 CHECK (iqomah_sound_enabled IN (0,1)),
    -- Koreksi tanggal Hijriah agar sama dengan keputusan isbat (−1, 0, +1 hari).
    hijri_offset_days               INTEGER NOT NULL DEFAULT 0 CHECK (hijri_offset_days BETWEEN -2 AND 2),
    -- Slide di layar jamaah (poster, kas, foto masjid): jeda layar jam lalu lama slide tampil (DECISIONS.md #27).
    poster_gap_seconds              INTEGER NOT NULL DEFAULT 15,
    poster_show_seconds             INTEGER NOT NULL DEFAULT 15,
    mosque_photo                    TEXT NOT NULL DEFAULT '',   -- nama file di uploads/publications, '' = tanpa foto
    last_sync_at                    DATETIME,
    last_sync_status                TEXT,
    updated_at                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- REQ-F-070, REQ-F-071, REQ-F-072
CREATE TABLE IF NOT EXISTS admin_users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME,
    recovery_hash TEXT,             -- hash kode pemulihan sekali pakai (DECISIONS.md #33)
    recovery_created_at DATETIME
);

-- Percobaan login/pemulihan yang gagal, untuk membatasi tebak-tebakan (DECISIONS.md #33).
CREATE TABLE IF NOT EXISTS auth_failures (
    action    TEXT NOT NULL,        -- 'login' | 'recover'
    ip        TEXT NOT NULL,
    failed_at INTEGER NOT NULL      -- detik Unix
);
CREATE INDEX IF NOT EXISTS idx_auth_failures ON auth_failures(action, ip, failed_at);

-- REQ-F-001 s/d REQ-F-006
CREATE TABLE IF NOT EXISTS prayer_schedules (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    schedule_date TEXT NOT NULL UNIQUE,   -- format YYYY-MM-DD
    fajr          TEXT NOT NULL,          -- format HH:MM
    sunrise       TEXT NOT NULL,
    dhuhr         TEXT NOT NULL,
    asr           TEXT NOT NULL,
    maghrib       TEXT NOT NULL,
    isha          TEXT NOT NULL,
    hijri_date    TEXT NOT NULL,
    madzhab_used  TEXT NOT NULL,
    source        TEXT NOT NULL DEFAULT 'local' CHECK (source IN ('local','online')),
    computed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_prayer_schedules_date ON prayer_schedules(schedule_date);

-- REQ-F-011, REQ-F-022
CREATE TABLE IF NOT EXISTS publications (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    file_name     TEXT NOT NULL,
    file_path     TEXT NOT NULL,
    caption       TEXT,
    display_order INTEGER NOT NULL DEFAULT 0,
    is_active     INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    uploaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Pengumuman berjadwal (YYYY-MM-DD, NULL = tanpa batas). Pengumuman teks saja
    -- disimpan dengan file_name/file_path kosong.
    starts_on     TEXT,
    ends_on       TEXT
);
CREATE INDEX IF NOT EXISTS idx_publications_active_order ON publications(is_active, display_order);

-- REQ-F-012, REQ-F-024 (struktur ledger — dikonfirmasi user)
CREATE TABLE IF NOT EXISTS financial_entries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    entry_date   TEXT NOT NULL,          -- format YYYY-MM-DD
    description  TEXT NOT NULL,
    type         TEXT NOT NULL CHECK (type IN ('masuk','keluar')),
    amount       INTEGER NOT NULL CHECK (amount >= 0),
    created_by   INTEGER REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_financial_entries_date ON financial_entries(entry_date);

-- REQ-F-040 s/d REQ-F-044
CREATE TABLE IF NOT EXISTS motion_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    detected_at DATETIME NOT NULL,
    image_path  TEXT NOT NULL,
    reviewed    INTEGER NOT NULL DEFAULT 0 CHECK (reviewed IN (0,1))
);
CREATE INDEX IF NOT EXISTS idx_motion_events_detected_at ON motion_events(detected_at);

-- REQ-F-050, REQ-F-051
CREATE TABLE IF NOT EXISTS backup_records (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    file_name   TEXT NOT NULL,
    file_path   TEXT NOT NULL,
    size_bytes  INTEGER NOT NULL,
    type        TEXT NOT NULL CHECK (type IN ('manual','scheduled')),
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Seed baris tunggal default settings (password admin TIDAK di-seed di sini,
-- lihat scripts/install.sh — REQ-NF-121 melarang credential hardcoded)
INSERT OR IGNORE INTO mosque_settings (id) VALUES (1);
