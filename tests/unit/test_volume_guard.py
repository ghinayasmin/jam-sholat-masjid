"""
Unit test untuk volume_guard (DECISIONS.md #40) — perintah sistem disimulasikan,
jadi tidak butuh speaker. Jalankan dengan:
    python3 -m unittest tests/unit/test_volume_guard.py -v
"""

import os
import sys
import unittest
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', 'alarm_service'))
import volume_guard  # noqa: E402


class FakeRunner:
    """Menjawab perintah sesuai awalan, mencatat semua perintah yang dijalankan."""

    def __init__(self, answers: dict):
        self.answers = answers
        self.calls = []

    def __call__(self, cmd):
        self.calls.append(cmd)
        for prefix, out in self.answers.items():
            if " ".join(cmd).startswith(prefix):
                if isinstance(out, Exception):
                    raise out
                return out
        return ""


def which_only(*names):
    return lambda name: f"/usr/bin/{name}" if name in names else None


class TestVolumeGuard(unittest.TestCase):
    def setUp(self):
        patcher = mock.patch.dict(os.environ, {"XDG_RUNTIME_DIR": "/run/user/1000"})
        patcher.start()
        self.addCleanup(patcher.stop)

    def test_pipewire_zero_volume_is_raised(self):
        run = FakeRunner({"wpctl get-volume": "Volume: 0.00\n"})
        status = volume_guard.ensure_audible("linux", run, which_only("wpctl"))
        self.assertIn(["wpctl", "set-volume", "@DEFAULT_AUDIO_SINK@", "0.70"], run.calls)
        self.assertNotIn(["wpctl", "set-mute", "@DEFAULT_AUDIO_SINK@", "0"], run.calls)
        self.assertEqual(status, "suara dinaikkan 0% ke 70%")

    def test_pipewire_muted_is_unmuted_keeping_volume(self):
        run = FakeRunner({"wpctl get-volume": "Volume: 0.55 [MUTED]\n"})
        status = volume_guard.ensure_audible("linux", run, which_only("wpctl"))
        self.assertIn(["wpctl", "set-mute", "@DEFAULT_AUDIO_SINK@", "0"], run.calls)
        self.assertFalse(any(c[:2] == ["wpctl", "set-volume"] for c in run.calls))
        self.assertEqual(status, "suara dibunyikan lagi")

    def test_takmir_volume_above_minimum_is_left_alone(self):
        run = FakeRunner({"wpctl get-volume": "Volume: 0.45\n"})
        status = volume_guard.ensure_audible("linux", run, which_only("wpctl"))
        self.assertEqual(len(run.calls), 1)
        self.assertEqual(status, "normal (45%)")

    def test_pulseaudio_fallback(self):
        run = FakeRunner({"pactl get-sink-mute": "Mute: yes\n",
                          "pactl get-sink-volume": "Volume: front-left: 6554 /  10% / -60.00 dB\n"})
        status = volume_guard.ensure_audible("linux", run, which_only("pactl"))
        self.assertIn(["pactl", "set-sink-mute", "@DEFAULT_SINK@", "0"], run.calls)
        self.assertIn(["pactl", "set-sink-volume", "@DEFAULT_SINK@", "70%"], run.calls)
        self.assertEqual(status, "suara dibunyikan lagi dan dinaikkan 10% ke 70%")

    def test_raspberry_pi_alsa_without_session(self):
        with mock.patch.dict(os.environ, {"XDG_RUNTIME_DIR": ""}):
            run = FakeRunner({"amixer scontrols": "Simple mixer control 'PCM',0\n",
                              "amixer sget PCM": "  Mono: Playback -10239 [0%] [-102.39dB] [off]\n"})
            status = volume_guard.ensure_audible("linux", run, which_only("amixer", "wpctl"))
        self.assertIn(["amixer", "-q", "sset", "PCM", "70%", "unmute"], run.calls)
        self.assertEqual(status, "suara dibunyikan lagi dan dinaikkan 0% ke 70%")

    def test_windows_uses_powershell_script(self):
        run = FakeRunner({"powershell.exe": "suara dibunyikan lagi\r\n"})
        status = volume_guard.ensure_audible("win32", run, which_only())
        self.assertTrue(run.calls[0][-5].endswith("ensure-volume.ps1"))
        self.assertEqual(status, "suara dibunyikan lagi")

    def test_failure_never_raises(self):
        run = FakeRunner({"wpctl get-volume": RuntimeError("no default sink")})
        status = volume_guard.ensure_audible("linux", run, which_only("wpctl"))
        self.assertTrue(status.startswith("tidak bisa dicek"))

    def test_no_mixer_at_all(self):
        with mock.patch.dict(os.environ, {"XDG_RUNTIME_DIR": ""}):
            status = volume_guard.ensure_audible("linux", FakeRunner({}), which_only())
        self.assertTrue(status.startswith("tidak bisa dicek"))


if __name__ == "__main__":
    unittest.main()
