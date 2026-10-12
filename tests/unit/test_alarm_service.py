"""
Unit test untuk AlarmScheduler & AudioPlayer — tidak butuh hardware audio
sungguhan (AudioPlayer di-inject runner palsu). Jalankan dengan:
    python3 -m unittest tests/unit/test_alarm_service.py -v
"""

import datetime
import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', 'alarm_service'))
from alarm_service import AlarmScheduler, AudioPlayer, MurottalState, alarm_audio_file, murottal_title  # noqa: E402


SCHEDULE = {
    "fajr": "04:11", "sunrise": "05:29", "dhuhr": "11:32",
    "asr": "14:45", "maghrib": "17:35", "isha": "18:44",
}


class TestAlarmScheduler(unittest.TestCase):
    def setUp(self):
        self.scheduler = AlarmScheduler(SCHEDULE, iqomah_interval_minutes=10)

    def test_no_action_before_prayer_time(self):
        t = datetime.datetime(2026, 9, 21, 4, 10, 0)
        self.assertEqual(self.scheduler.check(t), [])

    def test_adzan_triggers_exactly_at_prayer_time(self):
        t = datetime.datetime(2026, 9, 21, 4, 11, 0)
        self.assertEqual(self.scheduler.check(t), [("adzan", "fajr")])

    def test_adzan_does_not_double_trigger_same_minute(self):
        t = datetime.datetime(2026, 9, 21, 4, 11, 0)
        self.scheduler.check(t)
        self.assertEqual(self.scheduler.check(t), [])

    def test_iqomah_triggers_after_interval(self):
        t_adzan = datetime.datetime(2026, 9, 21, 4, 11, 0)
        self.scheduler.check(t_adzan)
        t_iqomah = datetime.datetime(2026, 9, 21, 4, 21, 0)  # +10 menit
        self.assertEqual(self.scheduler.check(t_iqomah), [("iqomah", "fajr")])

    def test_quiet_window_around_prayer_time(self):
        t_near = datetime.datetime(2026, 9, 21, 4, 12, 0)
        self.assertTrue(self.scheduler.in_quiet_window(t_near))

        t_far = datetime.datetime(2026, 9, 21, 9, 0, 0)
        self.assertFalse(self.scheduler.in_quiet_window(t_far))

    def test_reset_daily_allows_retrigger_next_day(self):
        t_day1 = datetime.datetime(2026, 9, 21, 4, 11, 0)
        self.scheduler.check(t_day1)
        self.scheduler.reset_daily()

        t_day2 = datetime.datetime(2026, 9, 22, 4, 11, 0)
        self.assertEqual(self.scheduler.check(t_day2), [("adzan", "fajr")])

    def test_all_five_daily_prayers_trigger_adzan(self):
        scheduler = AlarmScheduler(SCHEDULE, iqomah_interval_minutes=10)
        triggered = []
        for prayer, hhmm in SCHEDULE.items():
            if prayer == "sunrise":
                continue  # bukan waktu sholat, tidak ada adzan
            h, m = map(int, hhmm.split(":"))
            t = datetime.datetime(2026, 9, 21, h, m, 0)
            actions = scheduler.check(t)
            triggered.extend(a for a in actions if a[0] == "adzan")
        self.assertEqual(len(triggered), 5)


class TestAudioPlayer(unittest.TestCase):
    class FakeProcess:
        def __init__(self):
            self._terminated = False

        def wait(self):
            pass

        def poll(self):
            return None if not self._terminated else 0

        def terminate(self):
            self._terminated = True

    def setUp(self):
        self.calls = []

        def fake_runner(cmd):
            self.calls.append(cmd)
            return self.FakeProcess()

        self.player = AudioPlayer(runner=fake_runner)
        self.player._player_for = lambda path: ["dummy-player", path]

    def test_play_blocking_invokes_runner(self):
        result = self.player.play_blocking("/tmp/adzan.mp3")
        self.assertTrue(result)
        self.assertEqual(self.calls[-1], ["dummy-player", "/tmp/adzan.mp3"])

    def test_start_stop_background(self):
        self.player.start_background("/tmp/murottal1.mp3")
        self.assertTrue(self.player.is_playing())
        self.player.stop_background()
        self.assertFalse(self.player.is_playing())

    def test_start_background_stops_previous_track(self):
        self.player.start_background("/tmp/murottal1.mp3")
        first_call_count = len(self.calls)
        self.player.start_background("/tmp/murottal2.mp3")
        self.assertEqual(len(self.calls), first_call_count + 1)


class TestAlarmAudioFile(unittest.TestCase):
    def setUp(self):
        import tempfile
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = self.tmp.name

    def tearDown(self):
        self.tmp.cleanup()

    def touch(self, name):
        open(os.path.join(self.dir, name), "w").close()

    def test_subuh_uses_its_own_adzan(self):
        self.touch("adzan.mp3")
        self.touch("adzan_subuh.mp3")
        self.assertTrue(alarm_audio_file("adzan", "fajr", self.dir).endswith("adzan_subuh.mp3"))
        self.assertTrue(alarm_audio_file("adzan", "dhuhr", self.dir).endswith("/adzan.mp3"))

    def test_subuh_falls_back_to_regular_adzan(self):
        self.touch("adzan.mp3")
        self.assertTrue(alarm_audio_file("adzan", "fajr", self.dir).endswith("/adzan.mp3"))

    def test_wav_used_when_no_mp3(self):
        self.touch("iqomah.wav")
        self.assertTrue(alarm_audio_file("iqomah", "fajr", self.dir).endswith("iqomah.wav"))


class TestMurottal(unittest.TestCase):
    def test_title_from_filename(self):
        self.assertEqual(murottal_title("/a/murottal/002_An-Naba.mp3"), "An-Naba")
        self.assertEqual(murottal_title("Surah_Yasin.mp3"), "Surah Yasin")

    def test_state_reads_switch_and_writes_now_playing(self):
        import sqlite3
        import tempfile
        with tempfile.TemporaryDirectory() as d:
            db = os.path.join(d, "t.db")
            conn = sqlite3.connect(db)
            conn.execute("CREATE TABLE mosque_settings (id INTEGER PRIMARY KEY, murottal_enabled INTEGER, murottal_now_playing TEXT)")
            conn.execute("INSERT INTO mosque_settings VALUES (1, 0, '')")
            conn.commit()
            state = MurottalState(db)
            self.assertFalse(state.enabled())
            conn.execute("UPDATE mosque_settings SET murottal_enabled = 1")
            conn.commit()
            self.assertTrue(state.enabled())
            self.assertFalse(state.iqomah_enabled())  # kolom tidak ada di tabel uji
            self.assertEqual(state.request(), "")  # kolom tidak ada di tabel uji
            state.set_now_playing("An-Naba")
            self.assertEqual(conn.execute("SELECT murottal_now_playing FROM mosque_settings").fetchone()[0], "An-Naba")
            conn.close()

    def test_state_off_when_column_missing(self):
        import sqlite3
        import tempfile
        with tempfile.TemporaryDirectory() as d:
            db = os.path.join(d, "t.db")
            sqlite3.connect(db).execute("CREATE TABLE mosque_settings (id INTEGER PRIMARY KEY)").connection.commit()
            self.assertFalse(MurottalState(db).enabled())


if __name__ == '__main__':
    unittest.main()
