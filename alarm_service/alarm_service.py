#!/usr/bin/env python3
"""
Alarm & Audio Service: REQ-F-030 (Adzan Alarm), REQ-F-032 (Murottal Playback),
REQ-F-033 (Background Alarm Service). Suara adzan dan iqomah bisa dimatikan
takmir; iqomah fitur tambahan di luar SRS v0.2, bawaan mati (DECISIONS.md #23).

PENTING: skrip ini membandingkan jam LOKAL sistem (datetime.now(), bukan
UTC) terhadap jadwal sholat yang disimpan sebagai jam dinding lokal
(HH:MM) oleh PrayerTimeCalculator. Ini mengasumsikan timezone OS Raspberry
Pi sudah diset ke zona waktu masjid (mis. `timedatectl set-timezone
Asia/Jakarta`): langkah ini ada di scripts/install.sh dan README.

Desain dipecah jadi kelas-kelas kecil yang testable tanpa hardware audio
sungguhan: AlarmScheduler (murni logika waktu, tidak menyentuh I/O) dan
AudioPlayer (wrapper subprocess, bisa di-mock di unit test). Lihat tests/unit/test_alarm_service.py.
"""

from __future__ import annotations

import datetime
import logging
import os
import shutil
import sqlite3
import subprocess
import sys
import re
import signal
import time
from glob import glob

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import volume_guard  # noqa: E402

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEFAULT_DB_PATH = os.environ.get("JAMSHOLAT_DB_PATH", os.path.join(PROJECT_ROOT, "storage", "jamsholat.db"))
AUDIO_DIR = os.environ.get("JAMSHOLAT_AUDIO_PATH", os.path.join(PROJECT_ROOT, "public", "assets", "audio"))
PHP_BIN = os.environ.get("JAMSHOLAT_PHP_BIN", "php")
COMPUTE_SCRIPT = os.path.join(PROJECT_ROOT, "scripts", "compute_schedule_cli.php")

# Prayer yang punya adzan (sunrise sengaja TIDAK termasuk, bukan waktu sholat).
ADZAN_PRAYERS = ["fajr", "dhuhr", "asr", "maghrib", "isha"]

# REQ-F-032 pass criteria (TC-ALM-003): murottal tidak boleh tumpang tindih
# dengan adzan/iqomah: beri jeda "diam" di sekitar tiap waktu sholat.
QUIET_WINDOW_BEFORE_MIN = 2
QUIET_WINDOW_AFTER_MIN = 20  # cukup untuk adzan + iqomah + sholat singkat
# Setelah iqomah masih ada sholat berjamaah: murottal baru boleh lagi sesudahnya.
QUIET_AFTER_IQOMAH_MIN = 15
# Iqomah tetap dibunyikan bila menitnya terlewat karena adzan masih diputar.
IQOMAH_LATE_TOLERANCE_MIN = 3

# Jeda adzan→iqomah bawaan per waktu sholat (DECISIONS.md #30). Subuh lebih panjang.
DEFAULT_IQOMAH_MINUTES = {"fajr": 20, "dhuhr": 10, "asr": 10, "maghrib": 10, "isha": 10}
# Jumat: khutbah + sholat Jumat jauh lebih lama; murottal jangan menyela khutbah.
QUIET_WINDOW_AFTER_FRIDAY_DHUHR_MIN = 60
# Tombol "Coba putar suara" di panel (DECISIONS.md #43): potongan awal adzan, dan
# permintaan yang lebih tua dari ini diabaikan (mis. tertahan adzan sungguhan).
SOUND_TEST_SECONDS = 10
SOUND_TEST_MAX_AGE_SECONDS = 60

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [alarm_service] %(levelname)s: %(message)s",
)
logger = logging.getLogger("alarm_service")


class AlarmScheduler:
    """Logika murni: kapan adzan/iqomah harus dibunyikan, berdasar jadwal
    hari ini + jarak iqomah. Tidak menyentuh file/audio/DB, mudah diuji."""

    def __init__(self, schedule: dict, iqomah_minutes: dict | int) -> None:
        self.schedule = schedule  # {'fajr': '04:11', 'dhuhr': '11:32', ...}
        self.set_iqomah_minutes(iqomah_minutes)
        self._fired: set[str] = set()  # kunci: "2026-09-21-fajr-adzan"

    def set_iqomah_minutes(self, iqomah_minutes: dict | int) -> None:
        """Jeda per waktu sholat ({'fajr': 20, ...}); angka tunggal = sama untuk semua."""
        if isinstance(iqomah_minutes, int):
            iqomah_minutes = {p: iqomah_minutes for p in ADZAN_PRAYERS}
        self.iqomah_minutes = {p: int(iqomah_minutes.get(p, DEFAULT_IQOMAH_MINUTES[p])) for p in ADZAN_PRAYERS}

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

            # Iqomah: adzan_time + jeda waktu sholat itu. Jumat siang dilewati:
            # iqomah Jumat dikumandangkan setelah khutbah, yang lamanya tidak tetap.
            if prayer == "dhuhr" and now.weekday() == 4:
                continue
            iqomah_dt = now.replace(hour=h, minute=m, second=0, microsecond=0) \
                + datetime.timedelta(minutes=self.iqomah_minutes[prayer])
            iqomah_key = self._time_key(now, prayer, "iqomah")
            late = now - iqomah_dt
            if datetime.timedelta(0) <= late < datetime.timedelta(minutes=IQOMAH_LATE_TOLERANCE_MIN) \
                    and iqomah_key not in self._fired:
                self._fired.add(iqomah_key)
                actions.append(("iqomah", prayer))

        return actions

    def in_quiet_window(self, now: datetime.datetime) -> bool:
        """True jika `now` berada dalam jendela 'diam' di sekitar waktu sholat manapun
        (dipakai untuk menahan murottal, REQ-F-032)."""
        for prayer in ADZAN_PRAYERS:
            if prayer not in self.schedule:
                continue
            h, m = self._parse_hhmm(self.schedule[prayer])
            prayer_dt = now.replace(hour=h, minute=m, second=0, microsecond=0)
            window_start = prayer_dt - datetime.timedelta(minutes=QUIET_WINDOW_BEFORE_MIN)
            after = QUIET_WINDOW_AFTER_FRIDAY_DHUHR_MIN if prayer == "dhuhr" and now.weekday() == 4 \
                else max(QUIET_WINDOW_AFTER_MIN, self.iqomah_minutes[prayer] + QUIET_AFTER_IQOMAH_MIN)
            window_end = prayer_dt + datetime.timedelta(minutes=after)
            if window_start <= now <= window_end:
                return True
        return False

    def reset_daily(self) -> None:
        self._fired.clear()


def mpg123_output_args(platform: str | None = None) -> list[str]:
    """Modul keluaran mpg123. Di Raspberry Pi OS Lite harus ALSA langsung (tanpa -o
    mpg123 mencoba JACK lebih dulu dan segfault). Di desktop Linux sesi pengguna
    memakai PulseAudio/PipeWire, dan di Windows modul bawaan sudah benar, jadi
    paket untuk komputer biasa mengatur JAMSHOLAT_MPG123_OUTPUT ("" = bawaan)."""
    platform = platform or sys.platform
    default = "alsa" if platform.startswith("linux") else ""
    module = os.environ.get("JAMSHOLAT_MPG123_OUTPUT", default)
    return ["-o", module] if module else []


def mp3_frames_per_second(path: str) -> float | None:
    """Jumlah frame MP3 per detik dari header frame pertama (lewati tag ID3v2), untuk
    melompat ke detik tertentu dengan "mpg123 -k <frame>" (murottal dilanjutkan, #40)."""
    try:
        with open(path, "rb") as fh:
            data = fh.read(64 * 1024)
    except OSError:
        return None
    pos = 0
    if data[:3] == b"ID3" and len(data) >= 10:
        pos = 10 + ((data[6] & 0x7F) << 21 | (data[7] & 0x7F) << 14 | (data[8] & 0x7F) << 7 | (data[9] & 0x7F))
        if pos + 4 > len(data):
            with open(path, "rb") as fh:
                fh.seek(pos)
                data = fh.read(4096)
            pos = 0
    rates = {3: (44100, 48000, 32000), 2: (22050, 24000, 16000), 0: (11025, 12000, 8000)}  # MPEG1, 2, 2.5
    while pos + 4 <= len(data):
        if data[pos] == 0xFF and (data[pos + 1] & 0xE0) == 0xE0:
            version = (data[pos + 1] >> 3) & 0x03
            sr_index = (data[pos + 2] >> 2) & 0x03
            if version in rates and sr_index < 3:
                samples = 1152 if version == 3 else 576
                return rates[version][sr_index] / samples
        pos += 1
    return None


class AudioPlayer:
    """Wrapper pemutaran audio via player command-line. Player dipilih
    otomatis (mpg123 untuk mp3, aplay untuk wav), bisa di-mock di test
    dengan meng-inject `runner` custom."""

    def __init__(self, runner=None) -> None:
        self._runner = runner or self._default_runner
        self._current_process: "subprocess.Popen | None" = None

    @staticmethod
    def _default_runner(cmd: list[str]) -> "subprocess.Popen":
        # stderr diteruskan ke journal agar kegagalan pemutar (mis. perangkat sibuk) terlihat.
        return subprocess.Popen(cmd, stdout=subprocess.DEVNULL)

    def _player_for(self, path: str) -> list[str] | None:
        ext = os.path.splitext(path)[1].lower()
        if ext == ".mp3" and shutil.which("mpg123"):
            return ["mpg123", "-q", *mpg123_output_args(), path]
        if ext == ".wav" and shutil.which("aplay"):
            return ["aplay", "-q", path]
        if ext == ".wav" and sys.platform == "win32":
            # Windows tidak punya aplay; pemutar WAV bawaan .NET (DECISIONS.md #39).
            return ["powershell", "-NoProfile", "-Command",
                    f"(New-Object Media.SoundPlayer '{path}').PlaySync()"]
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

    def play_for(self, path: str, seconds: float) -> bool:
        """Putar paling lama `seconds` detik lalu hentikan (uji suara dari panel)."""
        cmd = self._player_for(path)
        if cmd is None:
            logger.warning("Tidak ada player audio yang cocok untuk %s (file/hardware tidak ditemukan).", path)
            return False
        proc = self._runner(cmd)
        try:
            proc.wait(timeout=seconds)
        except subprocess.TimeoutExpired:
            proc.terminate()
            try:
                proc.wait(timeout=3)
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.wait()
        return True

    def start_background(self, path: str, offset_seconds: float = 0) -> None:
        """offset_seconds > 0: mulai dari detik itu (mpg123 -k), untuk murottal yang dijeda."""
        self.stop_background()
        cmd = self._player_for(path)
        if cmd is None:
            logger.warning("Tidak ada player audio untuk murottal: %s", path)
            return
        if offset_seconds > 0 and cmd[0] == "mpg123":
            fps = mp3_frames_per_second(path)
            if fps:
                cmd = cmd[:2] + ["-k", str(int(offset_seconds * fps))] + cmd[2:]
        self._current_process = self._runner(cmd)

    def stop_background(self) -> None:
        proc = self._current_process
        if proc is not None and proc.poll() is None:
            proc.terminate()
            # Tunggu sampai benar-benar berhenti: keluaran HDMI hanya bisa dipakai satu
            # proses, jadi pemutar berikutnya gagal "device busy" bila yang lama masih hidup.
            try:
                proc.wait(timeout=3)
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.wait()
        self._current_process = None

    def is_playing(self) -> bool:
        return self._current_process is not None and self._current_process.poll() is None


def load_iqomah_minutes(db_path: str) -> dict:
    """Jeda adzan→iqomah per waktu sholat dari panel takmir (kolom iqomah_<sholat>_minutes)."""
    minutes = dict(DEFAULT_IQOMAH_MINUTES)
    try:
        conn = sqlite3.connect(db_path, timeout=5)
        conn.row_factory = sqlite3.Row
        try:
            row = conn.execute("SELECT * FROM mosque_settings WHERE id = 1").fetchone()
        finally:
            conn.close()
    except sqlite3.Error:
        return minutes
    if row is None:
        return minutes
    for prayer in ADZAN_PRAYERS:
        column = f"iqomah_{prayer}_minutes"
        if column in row.keys() and row[column] is not None:
            minutes[prayer] = int(row[column])
    return minutes


def _read_schedule_row(db_path: str):
    try:
        conn = sqlite3.connect(db_path, timeout=5)
        conn.row_factory = sqlite3.Row
        try:
            today = datetime.date.today().strftime("%Y-%m-%d")
            return conn.execute("SELECT * FROM prayer_schedules WHERE schedule_date = ?", (today,)).fetchone()
        finally:
            conn.close()
    except sqlite3.Error as exc:
        logger.error("Gagal membaca jadwal: %s", exc)
        return None


def load_today_schedule(db_path: str) -> tuple[dict, dict]:
    """Baca jadwal hari ini + jeda iqomah dari DB. Jadwal yang belum ada (hari baru,
    atau baru dihapus karena takmir mengubah lokasi/koreksi) dihitung dulu lewat
    compute_schedule_cli.php (lihat docstring modul)."""
    row = _read_schedule_row(db_path)
    if row is None:
        ensure_schedule_computed()
        row = _read_schedule_row(db_path)

    minutes = load_iqomah_minutes(db_path)
    if row is None:
        return {}, minutes
    return {k: row[k] for k in ["fajr", "sunrise", "dhuhr", "asr", "maghrib", "isha"]}, minutes


def ensure_schedule_computed() -> None:
    try:
        subprocess.run(
            [PHP_BIN, COMPUTE_SCRIPT], check=True, capture_output=True, timeout=30, text=True
        )
    except Exception as exc:  # noqa: BLE001 (REQ-NF-112)
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


def murottal_title(path: str) -> str:
    """Nama surat dari nama file: "002_An-Naba.mp3" -> "An-Naba"."""
    name = os.path.splitext(os.path.basename(path))[0]
    return re.sub(r"^[0-9]+[ _-]*", "", name).replace("_", " ").strip() or name


class MurottalState:
    """Penghubung dengan panel takmir lewat mosque_settings: baca sakelar
    Putar/Jeda (murottal_enabled) dan tulis surat yang sedang diputar
    (murottal_now_playing) untuk layar jamaah."""

    def __init__(self, db_path: str) -> None:
        self.db_path = db_path
        self._written: str | None = None

    def _flag(self, column: str, default: bool = False) -> bool:
        try:
            conn = sqlite3.connect(self.db_path, timeout=5)
            try:
                row = conn.execute(f"SELECT {column} FROM mosque_settings WHERE id = 1").fetchone()
            finally:
                conn.close()
        except sqlite3.Error:
            return default  # kolom belum dimigrasi: pakai nilai bawaan
        return bool(row[0]) if row else default

    def enabled(self) -> bool:
        return self._flag("murottal_enabled")

    def adzan_enabled(self) -> bool:
        return self._flag("adzan_sound_enabled", default=True)

    def iqomah_enabled(self) -> bool:
        return self._flag("iqomah_sound_enabled")

    def request(self) -> str:
        """Surat yang dipilih takmir, "nama_file|cap_waktu" ('' bila belum ada)."""
        try:
            conn = sqlite3.connect(self.db_path, timeout=5)
            try:
                row = conn.execute("SELECT murottal_request FROM mosque_settings WHERE id = 1").fetchone()
            finally:
                conn.close()
        except sqlite3.Error:
            return ""
        return (row[0] or "") if row else ""

    def sound_test_request(self) -> int:
        """Waktu (detik Unix) takmir menekan "Coba putar suara", 0 bila belum pernah."""
        try:
            conn = sqlite3.connect(self.db_path, timeout=5)
            try:
                row = conn.execute("SELECT sound_test_request FROM mosque_settings WHERE id = 1").fetchone()
            finally:
                conn.close()
        except sqlite3.Error:
            return 0  # kolom belum dimigrasi
        return int(row[0] or 0) if row else 0

    def set_alarm_playing(self, value: str) -> None:
        """Catat adzan/iqomah yang sedang diputar ("adzan|maghrib", '' = tidak ada), supaya
        layar adzan bertahan sampai rekaman selesai (DECISIONS.md #32)."""
        try:
            conn = sqlite3.connect(self.db_path, timeout=5)
            try:
                conn.execute("UPDATE mosque_settings SET alarm_now_playing = ? WHERE id = 1", (value,))
                conn.commit()
            finally:
                conn.close()
        except sqlite3.Error as exc:
            logger.error("Gagal mencatat adzan yang diputar: %s", exc)

    def save_position(self, file: str, seconds: float, playing: bool) -> None:
        """Posisi murottal "file|detik|ditulis|1/0" (#40): Jeda lalu Putar melanjutkan
        dari detik yang sama, juga setelah komputer mati. Panel menampilkan progresnya."""
        value = f"{file}|{int(seconds)}|{int(time.time())}|{1 if playing else 0}" if file else ""
        try:
            conn = sqlite3.connect(self.db_path, timeout=5)
            try:
                conn.execute("UPDATE mosque_settings SET murottal_position = ? WHERE id = 1", (value,))
                conn.commit()
            finally:
                conn.close()
        except sqlite3.Error as exc:
            logger.error("Gagal menyimpan posisi murottal: %s", exc)

    def saved_position(self) -> tuple[str, float]:
        try:
            conn = sqlite3.connect(self.db_path, timeout=5)
            try:
                row = conn.execute("SELECT murottal_position FROM mosque_settings WHERE id = 1").fetchone()
            finally:
                conn.close()
        except sqlite3.Error:
            return "", 0.0
        parts = (row[0] if row and row[0] else "").split("|")
        if len(parts) < 2:
            return "", 0.0
        try:
            return parts[0], float(parts[1])
        except ValueError:
            return "", 0.0

    def heartbeat(self, audio_status: str | None = None) -> None:
        """Tanda hidup untuk Beranda panel (DECISIONS.md #40): ditulis tiap menit,
        beserta hasil cek volume terakhir bila ada."""
        try:
            conn = sqlite3.connect(self.db_path, timeout=5)
            try:
                if audio_status is None:
                    conn.execute("UPDATE mosque_settings SET alarm_heartbeat = ? WHERE id = 1", (int(time.time()),))
                else:
                    conn.execute("UPDATE mosque_settings SET alarm_heartbeat = ?, alarm_audio_status = ? WHERE id = 1",
                                 (int(time.time()), audio_status))
                conn.commit()
            finally:
                conn.close()
        except sqlite3.Error as exc:
            logger.error("Gagal menulis tanda hidup layanan: %s", exc)

    def set_now_playing(self, title: str) -> None:
        if title == self._written:
            return
        try:
            conn = sqlite3.connect(self.db_path, timeout=5)
            try:
                conn.execute("UPDATE mosque_settings SET murottal_now_playing = ? WHERE id = 1", (title,))
                conn.commit()
            finally:
                conn.close()
            self._written = title
        except sqlite3.Error as exc:
            logger.error("Gagal mencatat murottal yang diputar: %s", exc)


def murottal_files() -> list[str]:
    if not os.path.isdir(AUDIO_DIR):
        return []
    files = sorted(glob(os.path.join(AUDIO_DIR, "murottal", "*.mp3")))
    return files


def run_loop(db_path: str = DEFAULT_DB_PATH) -> None:
    player = AudioPlayer()
    schedule, iqomah_minutes = load_today_schedule(db_path)
    scheduler = AlarmScheduler(schedule, iqomah_minutes)
    current_day = datetime.date.today()
    settings_loaded = time.monotonic()

    murottal_queue = murottal_files()
    murottal_index = 0
    murottal_playing = False  # True selama proses murottal yang kita mulai masih dipantau
    state = MurottalState(db_path)
    state.set_now_playing("")
    state.set_alarm_playing("")  # sisa dari layanan yang berhenti di tengah adzan
    # Setelah restart (mis. update.sh atau listrik padam), lanjut dari posisi tersimpan
    # (#40), atau dari surat terakhir yang dipilih takmir.
    last_request = state.request()
    chosen = last_request.split("|", 1)[0]
    names = [os.path.basename(f) for f in murottal_queue]
    resume_file, resume_pos = state.saved_position()
    if resume_file in names:
        murottal_index = names.index(resume_file)
    elif chosen in names:
        murottal_index = names.index(chosen)
    murottal_started = 0.0
    murottal_offset = 0.0     # detik surat saat pemutar terakhir dimulai
    last_sound_test = state.sound_test_request()  # permintaan lama tidak diputar setelah restart
    position_saved = 0.0

    def current_track() -> str:
        return murottal_queue[murottal_index % len(murottal_queue)] if murottal_queue else ""

    def murottal_position() -> float:
        return murottal_offset + (time.monotonic() - murottal_started)

    def check_volume(reason: str) -> None:
        status = volume_guard.ensure_audible()
        logger.info("Cek volume (%s): %s", reason, status)
        state.heartbeat(status)

    check_volume("layanan mulai")  # volume bisa kembali 0 setelah restart (#40)

    def stop_murottal(save: bool = True) -> None:
        """Hentikan murottal (Jeda, jendela diam, adzan) dan simpan posisinya, supaya
        Putar berikutnya melanjutkan dari detik yang sama (#40)."""
        nonlocal murottal_playing, resume_file, resume_pos
        if murottal_playing and save:
            resume_file, resume_pos = os.path.basename(current_track()), murottal_position()
            state.save_position(resume_file, resume_pos, False)
        player.stop_background()
        murottal_playing = False
        state.set_now_playing("")

    while True:
        try:
            now = datetime.datetime.now()

            if now.date() != current_day:
                logger.info("Hari berganti, memuat ulang jadwal.")
                current_day = now.date()
                schedule, iqomah_minutes = load_today_schedule(db_path)
                scheduler = AlarmScheduler(schedule, iqomah_minutes)
                murottal_queue = murottal_files()
                stop_murottal()  # posisi disimpan; murottal berlanjut, tidak kembali ke Al-Fatihah

            # Jadwal (koreksi per sholat, lokasi) dan jeda iqomah yang diubah takmir di panel
            # berlaku dalam 1 menit, tanpa restart. Adzan yang sudah berbunyi tidak diulang.
            if time.monotonic() - settings_loaded >= 60:
                fresh_schedule, iqomah_minutes = load_today_schedule(db_path)
                if fresh_schedule:
                    scheduler.schedule = fresh_schedule
                scheduler.set_iqomah_minutes(iqomah_minutes)
                settings_loaded = time.monotonic()
                state.heartbeat()

            for kind, prayer in scheduler.check(now):
                # Dimatikan takmir bila adzan/iqomah dikumandangkan langsung oleh muadzin.
                # Murottal tetap berhenti di jendela diam sekitar waktu sholat.
                if kind == "adzan" and not state.adzan_enabled():
                    logger.info("Waktu adzan %s: suara adzan dimatikan di panel, tidak diputar.", prayer)
                    continue
                if kind == "iqomah" and not state.iqomah_enabled():
                    logger.info("Waktu iqomah %s: suara iqomah dimatikan di panel, tidak diputar.", prayer)
                    continue
                stop_murottal()  # hentikan murottal dulu (REQ-F-032, TC-ALM-003)
                check_volume(f"{kind} {prayer}")
                audio_file = alarm_audio_file(kind, prayer)
                logger.info("Memutar %s untuk %s (%s)", kind, prayer, audio_file)
                if os.path.isfile(audio_file):
                    state.set_alarm_playing(f"{kind}|{prayer}")
                    try:
                        player.play_blocking(audio_file)
                    finally:
                        state.set_alarm_playing("")
                else:
                    logger.warning("File audio tidak ditemukan: %s", audio_file)

            # Coba putar suara dari panel (#43): murottal dijeda dulu, lalu berlanjut sendiri.
            sound_test = state.sound_test_request()
            if sound_test != last_sound_test:
                last_sound_test = sound_test
                if 0 <= time.time() - sound_test < SOUND_TEST_MAX_AGE_SECONDS:
                    stop_murottal()
                    check_volume("uji suara")
                    audio_file = alarm_audio_file("adzan", "dhuhr")
                    if os.path.isfile(audio_file):
                        logger.info("Uji suara dari panel: %s selama %d detik", audio_file, SOUND_TEST_SECONDS)
                        player.play_for(audio_file, SOUND_TEST_SECONDS)
                    else:
                        logger.warning("Uji suara: file audio tidak ditemukan: %s", audio_file)

            # Surat selesai dengan sendirinya: lanjut ke surat berikutnya. Kalau dihentikan
            # (Jeda, jendela diam, adzan), indeks tetap sehingga surat yang sama diulang.
            if murottal_playing and not player.is_playing():
                murottal_playing = False
                if time.monotonic() - murottal_started < 5 and murottal_offset == 0:
                    logger.warning("Pemutar murottal berhenti seketika (file rusak atau speaker sibuk), lanjut ke surat berikutnya.")
                murottal_index += 1
                resume_file, resume_pos = os.path.basename(current_track()), 0.0
                state.save_position(resume_file, 0, False)
                state.set_now_playing("")

            # Takmir memilih surat, Berikutnya, Sebelumnya, atau Mulai ulang di panel:
            # surat itu diputar dari awal, lalu lanjut berurutan.
            request = state.request()
            if request and request != last_request and murottal_queue:
                last_request = request
                chosen = request.split("|", 1)[0]
                names = [os.path.basename(f) for f in murottal_queue]
                step = {"__next__": 1, "__prev__": -1, "__restart__": 0}.get(chosen)
                if step is not None or chosen in names:
                    murottal_index = (murottal_index + step) % len(murottal_queue) if step is not None else names.index(chosen)
                    resume_file, resume_pos = os.path.basename(current_track()), 0.0
                    if murottal_playing:
                        stop_murottal(save=False)

            # REQ-F-032 (Should): murottal hanya bila dinyalakan takmir dan di luar jendela diam.
            wanted = bool(murottal_queue) and state.enabled() and not scheduler.in_quiet_window(now)
            if wanted and not murottal_playing:
                track = current_track()
                offset = resume_pos if os.path.basename(track) == resume_file else 0.0
                player.start_background(track, offset)
                murottal_playing = player.is_playing()
                if murottal_playing:
                    murottal_started = time.monotonic()
                    murottal_offset = offset
                    state.set_now_playing(murottal_title(track))
                    state.save_position(os.path.basename(track), offset, True)
                    position_saved = time.monotonic()
                    logger.info("Murottal: %s%s", murottal_title(track), f" dari detik {int(offset)}" if offset else "")
            elif not wanted and murottal_playing:
                stop_murottal()
            elif murottal_playing and time.monotonic() - position_saved >= 10:
                # Posisi berkala: listrik padam pun hanya kehilangan beberapa detik.
                state.save_position(os.path.basename(current_track()), murottal_position(), True)
                position_saved = time.monotonic()

            # 1 detik: Putar/Jeda/pindah surat dari panel terasa langsung (uji Pi v1.1.2: 3 detik
            # terasa macet). Tiap putaran hanya beberapa baca SQLite kecil (REQ-NF-102).
            time.sleep(1)

        except KeyboardInterrupt:
            break
        except Exception as exc:  # noqa: BLE001 (REQ-NF-112)
            logger.error("Error tak terduga di loop alarm: %s", exc)
            time.sleep(5)

    # Jangan biarkan layar jamaah tetap menampilkan surat yang sudah tidak diputar.
    # Sinyal berhenti kedua yang datang hampir bersamaan dengan yang pertama tidak
    # boleh memotong pembersihan ini.
    _ignore_stop_signals()
    for _ in range(2):
        try:
            stop_murottal()
            state.set_alarm_playing("")
            logger.info("Dihentikan.")
            break
        except KeyboardInterrupt:
            continue


def _ignore_stop_signals() -> None:
    """Abaikan SIGTERM/SIGINT berikutnya. Python 3.12 bisa menolak dengan OSError ("ignored
    due to race condition") bila sinyal kedua datang tepat saat ini dipanggil, mis.
    timeout + runuser di uji paket. Penolakan itu aman diabaikan: layanan tetap berhenti."""
    for sig in (signal.SIGTERM, signal.SIGINT):
        try:
            signal.signal(sig, signal.SIG_IGN)
        except (OSError, ValueError):
            pass


def _stop_on_sigterm(signum, frame) -> None:  # noqa: ARG001
    # systemctl stop / update.sh mengirim SIGTERM: berhenti lewat jalur yang sama dengan Ctrl+C.
    # Sinyal berikutnya diabaikan supaya pembersihan (mengosongkan judul murottal di
    # layar) tidak terputus bila SIGTERM datang dua kali.
    _ignore_stop_signals()
    raise KeyboardInterrupt


if __name__ == "__main__":
    signal.signal(signal.SIGTERM, _stop_on_sigterm)
    logger.info("Memulai layanan alarm. DB=%s, Audio=%s", DEFAULT_DB_PATH, AUDIO_DIR)
    sys.exit(run_loop() or 0)
