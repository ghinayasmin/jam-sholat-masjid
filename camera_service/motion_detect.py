#!/usr/bin/env python3
"""
Camera Surveillance Service — REQ-F-040 (Continuous Monitoring),
REQ-F-041 (Motion Detection), REQ-F-042 (Auto Image Capture),
REQ-F-043 (Background Camera Service).

Berjalan sebagai layanan latar belakang independen (systemd), terpisah dari
aplikasi web PHP, tapi menulis ke SQLite database yang sama (WAL mode agar
aman diakses bersamaan — lihat src/Db/Database.php).

Desain sengaja dipecah dua kelas kecil (MotionDetector, EventStore) supaya
logika deteksi bisa diuji tanpa hardware kamera sungguhan — lihat
tests/unit/test_motion_detect.py.
"""

from __future__ import annotations

import argparse
import datetime
import logging
import os
import sqlite3
import sys
import time

import cv2
import numpy as np

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEFAULT_DB_PATH = os.environ.get("JAMSHOLAT_DB_PATH", os.path.join(PROJECT_ROOT, "storage", "jamsholat.db"))
DEFAULT_CAPTURES_PATH = os.environ.get(
    "JAMSHOLAT_CAPTURES_PATH", os.path.join(PROJECT_ROOT, "storage", "captures")
)
CAMERA_INDEX = int(os.environ.get("JAMSHOLAT_CAMERA_INDEX", "0"))

# REQ-NF-102: layanan latar belakang tidak boleh membebani resource berlebihan —
# nilai-nilai ini sengaja konservatif untuk Raspberry Pi 2/3 (RAM 1GB).
MIN_CONTOUR_AREA = 1500          # abaikan noise kecil
MIN_SECONDS_BETWEEN_CAPTURES = 5  # debounce, jangan banjiri DB/disk saat gerakan berkelanjutan
FRAME_WIDTH = 640
FRAME_HEIGHT = 480

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [motion_detect] %(levelname)s: %(message)s",
)
logger = logging.getLogger("motion_detect")


class MotionDetector:
    """Deteksi gerakan murni dari array frame — tidak menyentuh hardware,
    supaya bisa diuji dengan frame sintetis (lihat unit test)."""

    def __init__(self, min_contour_area: int = MIN_CONTOUR_AREA) -> None:
        self.min_contour_area = min_contour_area
        self.bg_subtractor = cv2.createBackgroundSubtractorMOG2(
            history=300, varThreshold=32, detectShadows=False
        )

    def detect(self, frame: "np.ndarray") -> bool:
        """Return True jika ada gerakan signifikan pada frame ini."""
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        gray = cv2.GaussianBlur(gray, (5, 5), 0)

        fg_mask = self.bg_subtractor.apply(gray)
        fg_mask = cv2.morphologyEx(fg_mask, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
        fg_mask = cv2.dilate(fg_mask, np.ones((5, 5), np.uint8), iterations=2)

        contours, _ = cv2.findContours(fg_mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        for c in contours:
            if cv2.contourArea(c) >= self.min_contour_area:
                return True
        return False


class EventStore:
    """Menulis event + gambar ke DB/disk — REQ-F-042."""

    def __init__(self, db_path: str, captures_path: str) -> None:
        self.db_path = db_path
        self.captures_path = captures_path
        os.makedirs(self.captures_path, exist_ok=True)

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.db_path, timeout=5)
        conn.execute("PRAGMA journal_mode = WAL")
        conn.execute("PRAGMA busy_timeout = 5000")
        return conn

    def save_event(self, frame: "np.ndarray") -> str:
        now = datetime.datetime.utcnow()
        filename = "motion_" + now.strftime("%Y%m%d_%H%M%S") + ".jpg"
        full_path = os.path.join(self.captures_path, filename)

        cv2.imwrite(full_path, frame, [cv2.IMWRITE_JPEG_QUALITY, 80])

        conn = self._connect()
        try:
            conn.execute(
                "INSERT INTO motion_events (detected_at, image_path) VALUES (?, ?)",
                (now.strftime("%Y-%m-%d %H:%M:%S"), filename),
            )
            conn.commit()
        finally:
            conn.close()

        logger.info("Gerakan terdeteksi, gambar disimpan: %s", filename)
        return filename


def run_loop(camera_index: int, db_path: str, captures_path: str) -> None:
    """Loop utama — REQ-F-040 (continuous), dengan graceful error handling (REQ-NF-112):
    kegagalan membuka webcam TIDAK menghentikan proses, hanya retry berkala."""
    detector = MotionDetector()
    store = EventStore(db_path, captures_path)
    last_capture_time = 0.0

    cap = None
    while True:
        try:
            if cap is None or not cap.isOpened():
                logger.info("Membuka webcam index %d...", camera_index)
                cap = cv2.VideoCapture(camera_index)
                cap.set(cv2.CAP_PROP_FRAME_WIDTH, FRAME_WIDTH)
                cap.set(cv2.CAP_PROP_FRAME_HEIGHT, FRAME_HEIGHT)
                if not cap.isOpened():
                    logger.warning("Webcam tidak tersedia, coba lagi dalam 10 detik.")
                    time.sleep(10)
                    continue

            ok, frame = cap.read()
            if not ok or frame is None:
                logger.warning("Gagal membaca frame dari webcam, coba lagi.")
                cap.release()
                cap = None
                time.sleep(5)
                continue

            if detector.detect(frame):
                now = time.time()
                if now - last_capture_time >= MIN_SECONDS_BETWEEN_CAPTURES:
                    store.save_event(frame)
                    last_capture_time = now

            time.sleep(0.2)  # ~5 fps cukup untuk deteksi gerakan, hemat CPU (REQ-NF-102)

        except KeyboardInterrupt:
            logger.info("Dihentikan oleh pengguna.")
            break
        except Exception as exc:  # noqa: BLE001 — REQ-NF-112: jangan biarkan service mati total
            logger.error("Error tak terduga: %s", exc)
            time.sleep(5)

    if cap is not None:
        cap.release()


def main() -> None:
    parser = argparse.ArgumentParser(description="Layanan deteksi gerakan kamera masjid.")
    parser.add_argument("--camera-index", type=int, default=CAMERA_INDEX)
    parser.add_argument("--db-path", default=DEFAULT_DB_PATH)
    parser.add_argument("--captures-path", default=DEFAULT_CAPTURES_PATH)
    args = parser.parse_args()

    logger.info("Memulai layanan deteksi gerakan. DB=%s, Captures=%s", args.db_path, args.captures_path)
    run_loop(args.camera_index, args.db_path, args.captures_path)


if __name__ == "__main__":
    sys.exit(main() or 0)
