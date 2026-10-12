"""
Unit test untuk MotionDetector & EventStore — tidak butuh webcam sungguhan,
memakai frame sintetis (numpy array). Jalankan dengan:
    python3 -m unittest tests/unit/test_motion_detect.py -v
"""

import datetime
import os
import sqlite3
import sys
import tempfile
import unittest

import numpy as np

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', 'camera_service'))
from motion_detect import MotionDetector, EventStore  # noqa: E402


class TestMotionDetector(unittest.TestCase):
    def test_static_frame_no_motion(self):
        detector = MotionDetector()
        static_frame = np.full((480, 640, 3), 100, dtype=np.uint8)
        for _ in range(15):  # background subtractor perlu beberapa frame untuk "belajar"
            detector.detect(static_frame)
        self.assertFalse(detector.detect(static_frame))

    def test_sudden_large_object_detected(self):
        detector = MotionDetector()
        base = np.full((480, 640, 3), 100, dtype=np.uint8)
        for _ in range(15):
            detector.detect(base)

        moving = base.copy()
        moving[100:300, 100:300] = 250
        self.assertTrue(detector.detect(moving))

    def test_grayscale_lores_frame_detected(self):
        # Camera Module mengirim bidang Y 320x240 (tanpa kanal warna).
        detector = MotionDetector()
        base = np.full((240, 320), 100, dtype=np.uint8)
        for _ in range(15):
            detector.detect(base)

        moving = base.copy()
        moving[50:150, 50:150] = 250
        self.assertTrue(detector.detect(moving))

    def test_small_noise_ignored(self):
        detector = MotionDetector(min_contour_area=5000)
        base = np.full((480, 640, 3), 100, dtype=np.uint8)
        for _ in range(15):
            detector.detect(base)

        tiny_change = base.copy()
        tiny_change[10:15, 10:15] = 250  # 5x5 px, jauh di bawah ambang 5000
        self.assertFalse(detector.detect(tiny_change))


class TestEventStore(unittest.TestCase):
    def setUp(self):
        self.tmpdir = tempfile.mkdtemp()
        self.db_path = os.path.join(self.tmpdir, 'test.db')
        self.captures_path = os.path.join(self.tmpdir, 'captures')

        conn = sqlite3.connect(self.db_path)
        conn.execute("""
            CREATE TABLE motion_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                detected_at DATETIME NOT NULL,
                image_path TEXT NOT NULL,
                reviewed INTEGER NOT NULL DEFAULT 0
            )
        """)
        conn.commit()
        conn.close()

    def test_save_event_writes_file_and_db_row(self):
        store = EventStore(self.db_path, self.captures_path)
        frame = np.zeros((480, 640, 3), dtype=np.uint8)

        filename = store.save_event(frame)

        self.assertTrue(os.path.isfile(os.path.join(self.captures_path, filename)))

        conn = sqlite3.connect(self.db_path)
        row = conn.execute("SELECT image_path, detected_at FROM motion_events").fetchone()
        conn.close()

        self.assertIsNotNone(row)
        self.assertEqual(row[0], filename)
        # detected_at harus bisa di-parse sebagai datetime valid
        datetime.datetime.strptime(row[1], "%Y-%m-%d %H:%M:%S")


if __name__ == '__main__':
    unittest.main()
