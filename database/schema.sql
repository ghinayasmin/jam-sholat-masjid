-- Sistem Jam Sholat Masjid — SQLite Schema
-- REQ traceability: lihat docs/REQUIREMENT_TRACEABILITY.md

PRAGMA foreign_keys = ON;

-- Singleton config table (REQ-F-020, REQ-F-021, REQ-F-025, REQ-F-026, REQ-F-044)
CREATE TABLE IF NOT EXISTS mosque_settings (
    id                              INTEGER PRIMARY KEY CHECK (id = 1),
    mosque_name                     TEXT NOT NULL DEFAULT 'Masjid',
    mosque_address                  TEXT NOT NULL DEFAULT '',
    latitude                        REAL NOT NULL DEFAULT -7.797068,
    longitude                       REAL NOT NULL DEFAULT 110.370529,
    timezone_offset_hours           REAL NOT NULL DEFAULT 7.0,   -- WIB = UTC+7
    madzhab                         TEXT NOT NULL DEFAULT 'syafii' CHECK (madzhab IN ('syafii','hanafi')),
    location_offset_minutes         INTEGER NOT NULL DEFAULT 0,
    azan_iqomah_interval_minutes    INTEGER NOT NULL DEFAULT 10,
    image_retention_days            INTEGER NOT NULL DEFAULT 30,
    image_retention_storage_pct     INTEGER NOT NULL DEFAULT 80,
    online_sync_enabled             INTEGER NOT NULL DEFAULT 1 CHECK (online_sync_enabled IN (0,1)),
    -- Layar jamaah: pengingat sebelum adzan & visibilitas kas (lihat DECISIONS.md #14, #15).
    -- Kolom yang sama ditambahkan ke database lama lewat Database::migrate().
    reminder_enabled                INTEGER NOT NULL DEFAULT 1 CHECK (reminder_enabled IN (0,1)),
    reminder_minutes                INTEGER NOT NULL DEFAULT 10,
    reminder_friday_minutes         INTEGER NOT NULL DEFAULT 30,
    reminder_message                TEXT NOT NULL DEFAULT 'Mari bersiap dan mengambil air wudhu',
    show_finance_on_display         INTEGER NOT NULL DEFAULT 0 CHECK (show_finance_on_display IN (0,1)),
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
    last_login_at DATETIME
);

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
    uploaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
