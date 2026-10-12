#!/usr/bin/env python3
"""
Alarm & Audio Service — REQ-F-030 (Adzan Alarm), REQ-F-031 (Iqomah Alarm),
REQ-F-032 (Murottal Playback), REQ-F-033 (Background Alarm Service).

PENTING: skrip ini membandingkan jam LOKAL sistem (datetime.now(), bukan
UTC) terhadap jadwal sholat yang disimpan sebagai jam dinding lokal
(HH:MM) oleh PrayerTimeCalculator. Ini mengasumsikan timezone OS Raspberry
Pi sudah diset ke zona waktu masjid (mis. `timedatectl set-timezone
Asia/Jakarta`) — langkah ini ada di scripts/install.sh dan README.

Desain dipecah jadi kelas-kelas kecil yang testable tanpa hardware audio
sungguhan: AlarmScheduler (murni logika waktu, tidak menyentuh I/O) dan
AudioPlayer (wrapper subprocess, bisa di-mock di unit test) —
lihat tests/unit/test_alarm_service.py.
"""

from __future__ import annotations

import datetime
import logging
import os
import shutil
import sqlite3
import subprocess
import sys
import time
from glob import glob

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEFAULT_DB_PATH = os.environ.get("JAMSHOLAT_DB_PATH", os.path.join(PROJECT_ROOT, "storage", "jamsholat.db"))
AUDIO_DIR = os.environ.get("JAMSHOLAT_AUDIO_PATH", os.path.join(PROJECT_ROOT, "public", "assets", "audio"))
PHP_BIN = os.environ.get("JAMSHOLAT_PHP_BIN", "php")
COMPUTE_SCRIPT = os.path.join(PROJECT_ROOT, "scripts", "compute_schedule_cli.php")

# Prayer yang punya adzan (sunrise sengaja TIDAK termasuk — bukan waktu sholat).
ADZAN_PRAYERS = ["fajr", "dhuhr", "asr", "maghrib", "isha"]

# REQ-F-032 pass criteria (TC-ALM-003): murottal tidak boleh tumpang tindih
# dengan adzan/iqomah — beri jeda "diam" di sekitar tiap waktu sholat.
QUIET_WINDOW_BEFORE_MIN = 2
QUIET_WINDOW_AFTER_MIN = 20  # cukup untuk adzan + iqomah + sholat singkat

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [alarm_service] %(levelname)s: %(message)s",
)
logger = logging.getLogger("alarm_service")


class AlarmScheduler:
    """Logika murni: kapan adzan/iqomah harus dibunyikan, berdasar jadwal
    hari ini + jarak iqomah. Tidak menyentuh file/audio/DB — mudah diuji."""

    def __init__(self, schedule: dict, iqomah_interval_minutes: int) -> None:
        self.schedule = schedule  # {'fajr': '04:11', 'dhuhr': '11:32', ...}
        self.iqomah_interval_minutes = iqomah_interval_minutes
        self._fired: set[str] = set()  # kunci: "2026-09-21-fajr-adzan"

    @staticmethod
    def _parse_hhmm(value: str) -> tuple[int, int]:
        h, m = value.split(":")
        return int(h), int(m)

    def _time_key(self, now: datetime.datetime, prayer: str, kind: str) -> str:
        return f"{now:%Y-%m-%d}-{prayer}-{kind}"

    def check(self, now: datetime.datetime) -> list[tuple[str, str]]:
        """Return daftar (kind, prayer) yang harus dipicu SEKARANG (biasanya 0 atau 1)."""
        actions: list[tuple[str, str]] = []

        for prayer in ADZAN_PRAYERS:
            if prayer not in self.schedule:
                continue
            h, m = self._parse_hhmm(self.schedule[prayer])

            # Adzan: cocok persis di menit tsb.
            adzan_key = self._time_key(now, prayer, "adzan")
            if now.hour == h and now.minute == m and adzan_key not in self._fired:
                self._fired.add(adzan_key)
                actions.append(("adzan", prayer))

            # Iqomah: adzan_time + interval menit.
            iqomah_dt = now.replace(hour=h, minute=m, second=0, microsecond=0) \
                + datetime.timedelta(minutes=self.iqomah_interval_minutes)
            iqomah_key = self._time_key(now, prayer, "iqomah")
            if now.hour == iqomah_dt.hour and now.minute == iqomah_dt.minute and iqomah_key not in self._fired:
                self._fired.add(iqomah_key)
                actions.append(("iqomah", prayer))

        return actions

    def in_quiet_window(self, now: datetime.datetime) -> bool:
        """True jika `now` berada dalam jendela 'diam' di sekitar waktu sholat manapun
        (dipakai untuk menahan murottal — REQ-F-032)."""
        for prayer in ADZAN_PRAYERS:
            if prayer not in self.schedule:
                continue
            h, m = self._parse_hhmm(self.schedule[prayer])
            prayer_dt = now.replace(hour=h, minute=m, second=0, microsecond=0)
            window_start = prayer_dt - datetime.timedelta(minutes=QUIET_WINDOW_BEFORE_MIN)
            window_end = prayer_dt + datetime.timedelta(minutes=QUIET_WINDOW_AFTER_MIN)
            if window_start <= now <= window_end:
                return True
        return False

    def reset_daily(self) -> None:
        self._fired.clear()


class AudioPlayer:
    """Wrapper pemutaran audio via player command-line. Player dipilih
    otomatis (mpg123 untuk mp3, aplay untuk wav) — bisa di-mock di test
    dengan meng-inject `runner` custom."""

    def __init__(self, runner=None) -> None:
        self._runner = runner or self._default_runner
        self._current_process: "subprocess.Popen | None" = None

    @staticmethod
    def _default_runner(cmd: list[str]) -> "subprocess.Popen":
        return subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    def _player_for(self, path: str) -> list[str] | None:
        ext = os.path.splitext(path)[1].lower()
        if ext == ".mp3" and shutil.which("mpg123"):
            # -o alsa: tanpa ini mpg123 di Pi OS mencoba JACK lebih dulu dan segfault.
            return ["mpg123", "-q", "-o", "alsa", path]
        if ext == ".wav" and shutil.which("aplay"):
            return ["aplay", "-q", path]
        if shutil.which("ffplay"):  # fallback universal
            return ["ffplay", "-nodisp", "-autoexit", "-loglevel", "quiet", path]
        return None

    def play_blocking(self, path: str) -> bool:
        cmd = self._player_for(path)
        if cmd is None:
            logger.warning("Tidak ada player audio yang cocok untuk %s (file/hardware tidak ditemukan).", path)
            return False
        proc = self._runner(cmd)
        proc.wait()
        return True

    def start_background(self, path: str) -> None:
        self.stop_background()
        cmd = self._player_for(path)
        if cmd is None:
            logger.warning("Tidak ada player audio untuk murottal: %s", path)
            return
        self._current_process = self._runner(cmd)

    def stop_background(self) -> None:
        if self._current_process is not None and self._current_process.poll() is None:
            self._current_process.terminate()
        self._current_process = None

    def is_playing(self) -> bool:
        return self._current_process is not None and self._current_process.poll() is None


def load_today_schedule(db_path: str) -> tuple[dict, int]:
    """Baca jadwal hari ini + jarak iqomah dari DB. Kalau belum ada, panggil
    compute_schedule_cli.php dulu (lihat docstring modul)."""
    ensure_schedule_computed()

    conn = sqlite3.connect(db_path, timeout=5)
    conn.row_factory = sqlite3.Row
    try:
        today = datetime.date.today().strftime("%Y-%m-%d")
        row = conn.execute(
            "SELECT * FROM prayer_schedules WHERE schedule_date = ?", (today,)
        ).fetchone()
        settings = conn.execute(
            "SELECT azan_iqomah_interval_minutes FROM mosque_settings WHERE id = 1"
        ).fetchone()

        interval = settings["azan_iqomah_interval_minutes"] if settings else 10
        if row is None:
            return {}, interval

        schedule = {k: row[k] for k in ["fajr", "sunrise", "dhuhr", "asr", "maghrib", "isha"]}
        return schedule, interval
    finally:
        conn.close()


def ensure_schedule_computed() -> None:
    try:
        subprocess.run(
            [PHP_BIN, COMPUTE_SCRIPT], check=True, capture_output=True, timeout=30, text=True
        )
    except Exception as exc:  # noqa: BLE001 — REQ-NF-112
        logger.error("Gagal memastikan jadwal hari ini terhitung: %s", exc)


def alarm_audio_file(kind: str, prayer: str = "", audio_dir: str | None = None) -> str:
    """adzan/iqomah boleh .mp3 atau .wav (sumber tertentu hanya tersedia sebagai
    OGG dan lebih mudah dikonversi ke WAV daripada MP3). MP3 didahulukan.

    Subuh memakai adzan_subuh.* (lafaz "ash-shalatu khairum minan naum") bila
    ada, selain itu kembali ke adzan biasa."""
    audio_dir = audio_dir or AUDIO_DIR
    names = [kind]
    if kind == "adzan" and prayer == "fajr":
        names.insert(0, "adzan_subuh")
    for name in names:
        for ext in (".mp3", ".wav"):
            path = os.path.join(audio_dir, name + ext)
            if os.path.isfile(path):
                return path
    return os.path.join(audio_dir, kind + ".mp3")


def murottal_files() -> list[str]:
    if not os.path.isdir(AUDIO_DIR):
        return []
    files = sorted(glob(os.path.join(AUDIO_DIR, "murottal", "*.mp3")))
    return files


def run_loop(db_path: str = DEFAULT_DB_PATH) -> None:
    player = AudioPlayer()
    schedule, interval = load_today_schedule(db_path)
    scheduler = AlarmScheduler(schedule, interval)
    current_day = datetime.date.today()

    murottal_queue = murottal_files()
    murottal_index = 0

    while True:
        try:
            now = datetime.datetime.now()

            if now.date() != current_day:
                logger.info("Hari berganti, memuat ulang jadwal.")
                current_day = now.date()
                schedule, interval = load_today_schedule(db_path)
                scheduler = AlarmScheduler(schedule, interval)
                murottal_queue = murottal_files()
                murottal_index = 0

            for kind, prayer in scheduler.check(now):
                player.stop_background()  # hentikan murottal dulu (REQ-F-032, TC-ALM-003)
                audio_file = alarm_audio_file(kind, prayer)
                logger.info("Memutar %s untuk %s (%s)", kind, prayer, audio_file)
                if os.path.isfile(audio_file):
                    player.play_blocking(audio_file)
                else:
                    logger.warning("File audio tidak ditemukan: %s", audio_file)

            # REQ-F-032 (Should): murottal di luar jendela diam, kalau tidak sedang main apa pun.
            if murottal_queue and not scheduler.in_quiet_window(now) and not player.is_playing():
                track = murottal_queue[murottal_index % len(murottal_queue)]
                player.start_background(track)
                murottal_index += 1
            elif scheduler.in_quiet_window(now) and player.is_playing():
                player.stop_background()

            time.sleep(15)  # cukup presisi untuk alarm per menit, hemat CPU (REQ-NF-102)

        except KeyboardInterrupt:
            logger.info("Dihentikan oleh pengguna.")
            break
        except Exception as exc:  # noqa: BLE001 — REQ-NF-112
            logger.error("Error tak terduga di loop alarm: %s", exc)
            time.sleep(5)


if __name__ == "__main__":
    logger.info("Memulai layanan alarm. DB=%s, Audio=%s", DEFAULT_DB_PATH, AUDIO_DIR)
    sys.exit(run_loop() or 0)
