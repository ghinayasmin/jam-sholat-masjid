"""
Unit test untuk MotionDetector & EventStore, tidak butuh webcam sungguhan,
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
import cv2  # noqa: E402
from motion_detect import MotionDetector, EventStore, WARMUP_FRAMES, parse_zones, unrotate_zone  # noqa: E402


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
        self.assertFalse(detector.detect(moving))  # baru 1 frame, belum dikonfirmasi
        self.assertTrue(detector.detect(moving))

    def test_grayscale_lores_frame_detected(self):
        # Camera Module mengirim bidang Y 320x240 (tanpa kanal warna).
        detector = MotionDetector()
        base = np.full((240, 320), 100, dtype=np.uint8)
        for _ in range(15):
            detector.detect(base)

        moving = base.copy()
        moving[50:150, 50:150] = 250
        detector.detect(moving)
        self.assertTrue(detector.detect(moving))

    def test_small_noise_ignored(self):
        detector = MotionDetector(min_contour_area=5000)
        base = np.full((480, 640, 3), 100, dtype=np.uint8)
        for _ in range(15):
            detector.detect(base)

        tiny_change = base.copy()
        tiny_change[10:15, 10:15] = 250  # 5x5 px, jauh di bawah ambang 5000
        self.assertFalse(detector.detect(tiny_change))

    def _learned(self, **kwargs):
        detector = MotionDetector(**kwargs)
        base = np.full((480, 640), 100, dtype=np.uint8)
        for _ in range(15):
            detector.detect(base)
        return detector, base

    def test_single_frame_flicker_ignored(self):
        detector, base = self._learned()
        flash = base.copy()
        flash[100:300, 100:300] = 250
        self.assertFalse(detector.detect(flash))
        self.assertFalse(detector.detect(base))  # hilang lagi di frame berikutnya
        self.assertFalse(detector.detect(base))

    def test_ignore_zone(self):
        # Bawah 40% foto diabaikan (mis. layar TV).
        detector, base = self._learned(ignore_zones=parse_zones("0,60,100,100"))
        screen = base.copy()
        screen[350:470, 100:500] = 250  # berubah di dalam zona
        for _ in range(3):
            self.assertFalse(detector.detect(screen))
        person = screen.copy()
        person[50:250, 250:400] = 250  # di luar zona
        detector.detect(person)
        self.assertTrue(detector.detect(person))

    def test_lighting_change_relearns(self):
        detector, base = self._learned()
        lamp_on = np.full((480, 640), 200, dtype=np.uint8)
        self.assertFalse(detector.detect(lamp_on))
        self.assertEqual(detector.skip_left, WARMUP_FRAMES)
        for _ in range(WARMUP_FRAMES + 5):
            self.assertFalse(detector.detect(lamp_on))

    def test_zone_follows_photo_rotation(self):
        # Zona ditulis terhadap foto yang sudah diputar; harus kena area yang sama
        # pada frame deteksi yang belum diputar.
        codes = {90: cv2.ROTATE_90_CLOCKWISE, 180: cv2.ROTATE_180, 270: cv2.ROTATE_90_COUNTERCLOCKWISE}
        for rotation, code in codes.items():
            frame = np.zeros((240, 320), dtype=np.uint8)
            frame[30:90, 40:120] = 255  # y 1/8..3/8, x 1/8..3/8
            photo = cv2.rotate(frame, code)
            ys, xs = np.nonzero(photo)
            h, w = photo.shape
            zone = (xs.min() / w, ys.min() / h, (xs.max() + 1) / w, (ys.max() + 1) / h)
            got = unrotate_zone(zone, rotation)
            for a, b in zip(got, (40 / 320, 30 / 240, 120 / 320, 90 / 240)):
                self.assertAlmostEqual(a, b, places=3, msg=f"rotasi {rotation}")

    def test_parse_zones(self):
        self.assertEqual(parse_zones(""), [])
        self.assertEqual(parse_zones("0,60,100,100; 50,50,10,10"), [(0, .6, 1, 1), (.1, .1, .5, .5)])
        self.assertEqual(parse_zones("rusak"), [])


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
