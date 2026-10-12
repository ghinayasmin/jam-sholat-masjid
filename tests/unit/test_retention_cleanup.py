"""
Unit test untuk retention_cleanup.py — REQ-F-044. Jalankan dengan:
    python3 -m unittest tests/unit/test_retention_cleanup.py -v
"""

import datetime
import os
import sqlite3
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', 'camera_service'))
from retention_cleanup import cleanup_by_age, get_settings, _connect  # noqa: E402


class TestRetentionCleanup(unittest.TestCase):
    def setUp(self):
        self.tmpdir = tempfile.mkdtemp()
        self.db_path = os.path.join(self.tmpdir, 'test.db')
        self.captures_path = os.path.join(self.tmpdir, 'captures')
        os.makedirs(self.captures_path)

        conn = sqlite3.connect(self.db_path)
        conn.execute("""
            CREATE TABLE motion_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                detected_at DATETIME NOT NULL,
                image_path TEXT NOT NULL,
                reviewed INTEGER NOT NULL DEFAULT 0
            )
        """)
        conn.execute("""
            CREATE TABLE mosque_settings (
                id INTEGER PRIMARY KEY,
                image_retention_days INTEGER,
                image_retention_storage_pct INTEGER
            )
        """)
        conn.execute(
            "INSERT INTO mosque_settings (id, image_retention_days, image_retention_storage_pct) "
            "VALUES (1, 30, 80)"
        )
        conn.commit()
        conn.close()

    def _add_event(self, filename: str, days_ago: int):
        date = (datetime.datetime.now() - datetime.timedelta(days=days_ago)).strftime("%Y-%m-%d %H:%M:%S")
        open(os.path.join(self.captures_path, filename), 'w').write('dummy')
        conn = sqlite3.connect(self.db_path)
        conn.execute("INSERT INTO motion_events (detected_at, image_path) VALUES (?, ?)", (date, filename))
        conn.commit()
        conn.close()

    def test_settings_read_correctly(self):
        conn = _connect(self.db_path)
        settings = get_settings(conn)
        conn.close()
        self.assertEqual(settings['days'], 30)
        self.assertEqual(settings['storage_pct'], 80)

    def test_old_files_deleted_recent_files_kept(self):
        self._add_event('old1.jpg', days_ago=40)
        self._add_event('old2.jpg', days_ago=35)
        self._add_event('recent.jpg', days_ago=1)

        conn = _connect(self.db_path)
        deleted = cleanup_by_age(conn, self.captures_path, 30)
        remaining = conn.execute("SELECT COUNT(*) FROM motion_events").fetchone()[0]
        conn.close()

        self.assertEqual(deleted, 2)
        self.assertEqual(remaining, 1)
        self.assertFalse(os.path.isfile(os.path.join(self.captures_path, 'old1.jpg')))
        self.assertFalse(os.path.isfile(os.path.join(self.captures_path, 'old2.jpg')))
        self.assertTrue(os.path.isfile(os.path.join(self.captures_path, 'recent.jpg')))

    def test_no_files_deleted_when_all_recent(self):
        self._add_event('a.jpg', days_ago=1)
        self._add_event('b.jpg', days_ago=5)

        conn = _connect(self.db_path)
        deleted = cleanup_by_age(conn, self.captures_path, 30)
        conn.close()

        self.assertEqual(deleted, 0)


if __name__ == '__main__':
    unittest.main()
