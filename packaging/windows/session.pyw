"""
session.pyw — dijalankan otomatis saat pengguna masuk ke Windows (pintasan di folder
Startup, dipasang installer). Padanan packaging/linux/jamsholat-session.

Suara hanya bisa keluar dari sesi pengguna, jadi layanan alarm, kamera, dan layar
jamaah (Edge mode kiosk) berjalan di sini; Apache berjalan sebagai layanan Windows
(DECISIONS.md #39). Dijalankan dengan pythonw.exe sehingga tidak ada jendela konsol.
"""

from __future__ import annotations

import ctypes
import os
import subprocess
import sys
import threading
import time
import urllib.request
from pathlib import Path

APP = Path(__file__).resolve().parents[2]          # ...\JamSholat\app
ROOT = APP.parent                                    # ...\JamSholat
DATA = Path(os.environ.get("PROGRAMDATA", r"C:\ProgramData")) / "JamSholat"
LOGS = DATA / "logs"


def display_url() -> str:
    """Port dipilih installer (80, atau 8080 dst. bila 80 terpakai): lihat setup.ps1."""
    try:
        port = int((DATA / "port.txt").read_text().strip())
    except (OSError, ValueError):
        port = 80
    return "http://localhost/display.php" if port == 80 else f"http://localhost:{port}/display.php"


URL = display_url()
EDGE_PATHS = [
    Path(os.environ.get("PROGRAMFILES(X86)", r"C:\Program Files (x86)")) / "Microsoft/Edge/Application/msedge.exe",
    Path(os.environ.get("PROGRAMFILES", r"C:\Program Files")) / "Microsoft/Edge/Application/msedge.exe",
]
NO_WINDOW = 0x08000000  # CREATE_NO_WINDOW


SHOW_EVENT = "Local\\JamSholatShowDisplay"


def single_instance() -> bool:
    """Satu sesi per pengguna: login ulang tidak menggandakan suara adzan."""
    ctypes.windll.kernel32.CreateMutexW(None, False, "Local\\JamSholatSession")
    return ctypes.windll.kernel32.GetLastError() != 183  # ERROR_ALREADY_EXISTS


def ask_running_session_to_show() -> None:
    """Shortcut Layar Jamaah diklik saat sesi sudah jalan (mis. layar ditutup dengan
    Alt+F4): minta sesi itu membuka layar lagi, alih-alih keluar tanpa tanda apa pun."""
    kernel32 = ctypes.windll.kernel32
    kernel32.OpenEventW.restype = ctypes.c_void_p
    handle = kernel32.OpenEventW(0x0002, False, SHOW_EVENT)  # EVENT_MODIFY_STATE
    if handle:
        kernel32.SetEvent(ctypes.c_void_p(handle))
        kernel32.CloseHandle(ctypes.c_void_p(handle))


def service_env() -> dict:
    env = os.environ.copy()
    env.update({
        "JAMSHOLAT_DB_PATH": str(DATA / "storage" / "jamsholat.db"),
        "JAMSHOLAT_AUDIO_PATH": str(APP / "public" / "assets" / "audio"),
        "JAMSHOLAT_CAPTURES_PATH": str(DATA / "storage" / "captures"),
        "JAMSHOLAT_PHP_BIN": str(ROOT / "php" / "php.exe"),
        "PATH": str(ROOT / "mpg123") + os.pathsep + env.get("PATH", ""),
        "PYTHONIOENCODING": "utf-8",
    })
    return env


def keep_running(name: str, args: list[str]) -> None:
    """Jalankan ulang bila berhenti (mis. webcam dicabut), seperti Restart= di systemd."""
    while True:
        with open(LOGS / f"{name}.log", "a", encoding="utf-8") as log:
            proc = subprocess.Popen(args, cwd=APP, env=service_env(), stdout=log, stderr=log,
                                    creationflags=NO_WINDOW)
            proc.wait()
        time.sleep(10)


def wait_for_web(timeout: int = 90) -> None:
    deadline = time.time() + timeout
    while time.time() < deadline:
        try:
            with urllib.request.urlopen(URL, timeout=3) as res:
                if res.status == 200:
                    return
        except OSError:
            pass
        time.sleep(2)


def open_kiosk() -> subprocess.Popen | None:
    edge = next((p for p in EDGE_PATHS if p.is_file()), None)
    if edge is None:
        os.startfile(URL)  # browser bawaan, tanpa mode kiosk
        return None
    # Profil Edge terpisah: kalau Edge biasa sedang terbuka, --kiosk tetap berlaku dan
    # proses ini tetap hidup selama layar terbuka (dipakai serve_show_requests).
    profile = Path(os.environ.get("LOCALAPPDATA", str(DATA))) / "JamSholat" / "edge-kiosk"
    return subprocess.Popen([str(edge), "--kiosk", URL, "--edge-kiosk-type=fullscreen", "--no-first-run",
                             f"--user-data-dir={profile}", "--disable-features=Translate"],
                            creationflags=NO_WINDOW)


def serve_show_requests(event: int, kiosk: subprocess.Popen | None) -> None:
    """Buka layar lagi setiap kali shortcut Layar Jamaah diklik, kecuali masih terbuka."""
    while True:
        ctypes.windll.kernel32.WaitForSingleObject(ctypes.c_void_p(event), 0xFFFFFFFF)  # INFINITE
        if kiosk is None or kiosk.poll() is not None:
            kiosk = open_kiosk()


def main() -> None:
    if not single_instance():
        ask_running_session_to_show()
        return
    # Dibuat sekarang, bukan setelah Apache siap, supaya klik di awal tidak hilang.
    ctypes.windll.kernel32.CreateEventW.restype = ctypes.c_void_p
    show_event = ctypes.windll.kernel32.CreateEventW(None, False, False, SHOW_EVENT)
    LOGS.mkdir(parents=True, exist_ok=True)
    python = str(ROOT / "python" / "python.exe")
    services = [("alarm", [python, str(APP / "alarm_service" / "alarm_service.py")])]
    if not (DATA / "camera-off").exists():  # kamera bisa dimatikan dengan membuat file ini
        services.append(("camera", [python, str(APP / "camera_service" / "motion_detect.py")]))
    for name, args in services:
        threading.Thread(target=keep_running, args=(name, args), daemon=True).start()
    wait_for_web()
    serve_show_requests(show_event, open_kiosk())


if __name__ == "__main__":
    if sys.platform == "win32":
        main()
