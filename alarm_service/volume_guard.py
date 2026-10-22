"""
volume_guard.py — memastikan suara sistem tidak bisu sebelum adzan/iqomah diputar.

Temuan uji v1.1.1 (DECISIONS.md #40): di Ubuntu volume kembali ke 0 setelah
restart, sehingga adzan "berbunyi" tanpa terdengar. Sebelum adzan dan iqomah, dan
sekali saat layanan mulai, volume yang bisu dibunyikan lagi dan volume di bawah
MIN_LEVEL dinaikkan ke RAISE_TO. Volume yang sengaja diatur takmir di atas
MIN_LEVEL tidak disentuh.

- Linux desktop: PipeWire (wpctl) atau PulseAudio (pactl), sesi pengguna.
- Raspberry Pi / server tanpa desktop: ALSA (amixer).
- Windows: ensure-volume.ps1 (API volume Windows lewat PowerShell).

Mengembalikan kalimat status singkat untuk log dan Beranda panel. Tidak pernah
melempar exception: kegagalan menjadi status "tidak bisa dicek".
"""

from __future__ import annotations

import os
import re
import shutil
import subprocess
import sys
from typing import Callable

MIN_LEVEL = 0.30
RAISE_TO = 0.70
WINDOWS_SCRIPT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                              "packaging", "windows", "ensure-volume.ps1")
ALSA_CONTROLS = ("Master", "PCM", "HDMI", "Speaker", "Headphone")

Runner = Callable[[list[str]], str]


def _run(cmd: list[str]) -> str:
    flags = 0x08000000 if sys.platform == "win32" else 0  # CREATE_NO_WINDOW
    out = subprocess.run(cmd, capture_output=True, text=True, timeout=20, creationflags=flags)
    if out.returncode != 0:
        raise RuntimeError((out.stderr or out.stdout).strip()[:200])
    return out.stdout


def _decide(level: float, muted: bool) -> tuple[bool, float | None, str]:
    """(bunyikan?, level baru atau None, status)."""
    new_level = RAISE_TO if level < MIN_LEVEL else None
    if not muted and new_level is None:
        return False, None, f"normal ({round(level * 100)}%)"
    parts = []
    if muted:
        parts.append("dibunyikan lagi")
    if new_level is not None:
        parts.append(f"dinaikkan {round(level * 100)}% ke {round(new_level * 100)}%")
    return muted, new_level, "suara " + " dan ".join(parts)


def guard_wpctl(run: Runner) -> str:
    out = run(["wpctl", "get-volume", "@DEFAULT_AUDIO_SINK@"])  # "Volume: 0.40 [MUTED]"
    m = re.search(r"Volume:\s*([0-9.]+)", out)
    if not m:
        raise RuntimeError(f"keluaran wpctl tidak dikenal: {out.strip()[:80]}")
    unmute, level, status = _decide(float(m.group(1)), "MUTED" in out)
    if unmute:
        run(["wpctl", "set-mute", "@DEFAULT_AUDIO_SINK@", "0"])
    if level is not None:
        run(["wpctl", "set-volume", "@DEFAULT_AUDIO_SINK@", f"{level:.2f}"])
    return status


def guard_pactl(run: Runner) -> str:
    muted = "yes" in run(["pactl", "get-sink-mute", "@DEFAULT_SINK@"]).lower()
    m = re.search(r"(\d+)%", run(["pactl", "get-sink-volume", "@DEFAULT_SINK@"]))
    if not m:
        raise RuntimeError("keluaran pactl tidak dikenal")
    unmute, level, status = _decide(int(m.group(1)) / 100, muted)
    if unmute:
        run(["pactl", "set-sink-mute", "@DEFAULT_SINK@", "0"])
    if level is not None:
        run(["pactl", "set-sink-volume", "@DEFAULT_SINK@", f"{round(level * 100)}%"])
    return status


def guard_amixer(run: Runner) -> str:
    controls = re.findall(r"Simple mixer control '([^']+)'", run(["amixer", "scontrols"]))
    control = next((c for c in ALSA_CONTROLS if c in controls), controls[0] if controls else None)
    if control is None:
        # HDMI Raspberry Pi (vc4-hdmi) tidak punya pengatur volume: volumenya di TV.
        return "diatur di TV/speaker (HDMI)"
    out = run(["amixer", "sget", control])  # "... [52%] [-12.00dB] [on]"
    m = re.search(r"\[(\d+)%\]", out)
    if not m:
        raise RuntimeError(f"kontrol {control} tanpa volume")
    unmute, level, status = _decide(int(m.group(1)) / 100, "[off]" in out)
    if unmute or level is not None:
        args = ["amixer", "-q", "sset", control]
        if level is not None:
            args.append(f"{round(level * 100)}%")
        if unmute:
            args.append("unmute")
        run(args)
    return status


def guard_windows(run: Runner) -> str:
    out = run(["powershell.exe", "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", WINDOWS_SCRIPT,
               "-Min", str(MIN_LEVEL), "-RaiseTo", str(RAISE_TO)])
    return out.strip().splitlines()[-1] if out.strip() else "tidak ada keluaran"


def ensure_audible(platform: str | None = None, run: Runner = _run,
                   which: Callable[[str], str | None] = shutil.which) -> str:
    platform = platform or sys.platform
    try:
        if platform == "win32":
            return guard_windows(run)
        if os.environ.get("XDG_RUNTIME_DIR") and which("wpctl"):
            return guard_wpctl(run)
        if os.environ.get("XDG_RUNTIME_DIR") and which("pactl"):
            return guard_pactl(run)
        if which("amixer"):
            return guard_amixer(run)
        return "tidak bisa dicek (tidak ada pengatur volume)"
    except Exception as exc:  # noqa: BLE001 — volume tidak boleh menghentikan adzan
        return f"tidak bisa dicek ({exc})"[:160]
