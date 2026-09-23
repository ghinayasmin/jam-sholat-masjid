#!/usr/bin/env python3
"""
Retention Cleanup — REQ-F-044 (Image Retention Policy).

Dijalankan berkala lewat systemd timer (lihat systemd/jamsholat-retention.timer).
Menghapus gambar motion_events yang:
  (a) lebih tua dari `image_retention_days` HARI (dibaca dari mosque_settings), ATAU
  (b) storage terpakai sudah melewati `image_retention_storage_pct` persen —
      dalam kasus ini hapus gambar TERLAMA dulu sampai di bawah ambang.
Mana pun yang tercapai lebih dulu (sesuai deskripsi REQ-F-044: "N hari ATAU
storage mendekati penuh").
"""

from __future__ import annotations

import datetime
import logging
import os
import shutil
import sqlite3
import sys

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEFAULT_DB_PATH = os.environ.get("JAMSHOLAT_DB_PATH", os.path.join(PROJECT_ROOT, "storage", "jamsholat.db"))
DEFAULT_CAPTURES_PATH = os.environ.get(
    "JAMSHOLAT_CAPTURES_PATH", os.path.join(PROJECT_ROOT, "storage", "captures")
)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [retention_cleanup] %(levelname)s: %(message)s",
)
logger = logging.getLogger("retention_cleanup")


def _connect(db_path: str) -> sqlite3.Connection:
    conn = sqlite3.connect(db_path, timeout=5)
    conn.execute("PRAGMA journal_mode = WAL")
    conn.execute("PRAGMA busy_timeout = 5000")
    return conn


def get_settings(conn: sqlite3.Connection) -> dict:
    row = conn.execute(
        "SELECT image_retention_days, image_retention_storage_pct FROM mosque_settings WHERE id = 1"
    ).fetchone()
    if row is None:
        return {"days": 30, "storage_pct": 80}
    return {"days": row[0], "storage_pct": row[1]}


def delete_event(conn: sqlite3.Connection, captures_path: str, event_id: int, image_path: str) -> None:
    full_path = os.path.join(captures_path, os.path.basename(image_path))
    if os.path.isfile(full_path):
        try:
            os.remove(full_path)
        except OSError as exc:
            logger.warning("Gagal hapus file %s: %s", full_path, exc)
    conn.execute("DELETE FROM motion_events WHERE id = ?", (event_id,))


def cleanup_by_age(conn: sqlite3.Connection, captures_path: str, retention_days: int) -> int:
    cutoff = (datetime.datetime.utcnow() - datetime.timedelta(days=retention_days)).strftime("%Y-%m-%d %H:%M:%S")
    rows = conn.execute(
        "SELECT id, image_path FROM motion_events WHERE detected_at < ?", (cutoff,)
    ).fetchall()
    for event_id, image_path in rows:
        delete_event(conn, captures_path, event_id, image_path)
    conn.commit()
    if rows:
        logger.info("Hapus %d gambar lebih tua dari %d hari.", len(rows), retention_days)
    return len(rows)


def storage_usage_pct(path: str) -> float:
    """Persentase pemakaian filesystem tempat `path` berada."""
    usage = shutil.disk_usage(path)
    return (usage.used / usage.total) * 100.0 if usage.total > 0 else 0.0


def cleanup_by_storage(conn: sqlite3.Connection, captures_path: str, threshold_pct: int) -> int:
    deleted = 0
    guard = 0  # jaga-jaga agar tidak infinite loop kalau disk_usage tidak pernah turun
    while storage_usage_pct(captures_path) >= threshold_pct and guard < 10000:
        row = conn.execute(
            "SELECT id, image_path FROM motion_events ORDER BY detected_at ASC LIMIT 1"
        ).fetchone()
        if row is None:
            break  # tidak ada lagi yang bisa dihapus
        delete_event(conn, captures_path, row[0], row[1])
        deleted += 1
        guard += 1
    conn.commit()
    if deleted:
        logger.info("Hapus %d gambar tertua karena storage >= %d%%.", deleted, threshold_pct)
    return deleted


def run(db_path: str = DEFAULT_DB_PATH, captures_path: str = DEFAULT_CAPTURES_PATH) -> None:
    os.makedirs(captures_path, exist_ok=True)
    conn = _connect(db_path)
    try:
        settings = get_settings(conn)
        by_age = cleanup_by_age(conn, captures_path, int(settings["days"]))
        by_storage = cleanup_by_storage(conn, captures_path, int(settings["storage_pct"]))
        logger.info("Selesai. Dihapus karena umur: %d, karena storage: %d.", by_age, by_storage)
    finally:
        conn.close()


if __name__ == "__main__":
    sys.exit(run() or 0)
