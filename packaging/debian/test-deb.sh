#!/bin/bash
#
# test-deb.sh — uji asap paket .deb di container Debian/Ubuntu/Mint yang bersih.
#
#   bash packaging/debian/test-deb.sh /path/jamsholat_<versi>_all.deb
#
# Container tidak menjalankan systemd, jadi Apache dinyalakan manual dan layanan
# alarm dijalankan sebentar di latar depan. Keluar dengan kode ≠ 0 bila ada yang gagal.
#
set -euo pipefail
DEB="${1:?path .deb}"
export DEBIAN_FRONTEND=noninteractive
FAIL=0
pass() { echo "  ✓ $1"; }
fail() { echo "  ✗ $1"; FAIL=1; }

. /etc/os-release
echo "== $PRETTY_NAME =="
apt-get -qq update
apt-get -qq install -y --no-install-recommends "$DEB" curl > /tmp/apt.log 2>&1 || { tail -30 /tmp/apt.log; exit 1; }
pass "apt install berhasil ($(dpkg-query -W -f='${Version}' jamsholat))"

[ "$(cat /etc/jamsholat/mode)" = "headless" ] && pass "mode headless (tanpa desktop)" || fail "mode tidak terdeteksi"
php -v | head -1
apache2ctl start > /dev/null 2>&1 || service apache2 start > /dev/null 2>&1
sleep 2

code=$(curl -s -o /tmp/api.json -w '%{http_code}' http://localhost/api/schedule.php)
if [ "$code" = 200 ] && grep -q '"success":true' /tmp/api.json; then
    pass "API jadwal: $(php -r '$d=json_decode(file_get_contents("/tmp/api.json"),true); echo "Subuh ".$d["fajr"]." · Maghrib ".$d["maghrib"];')"
else
    fail "API jadwal HTTP $code"; cat /tmp/api.json; tail -20 /var/log/apache2/jamsholat-error.log || true
fi

code=$(curl -s -o /dev/null -w '%{http_code}' http://localhost/display.php)
[ "$code" = 200 ] && pass "layar jamaah HTTP 200" || fail "layar jamaah HTTP $code"
loc=$(curl -s -L -o /dev/null -w '%{url_effective}' http://localhost/admin/)
case "$loc" in *setup.php) pass "panel mengarah ke pembuatan akun pertama" ;; *) fail "panel tidak ke setup ($loc)" ;; esac

# Akun pertama lewat halaman setup (dari localhost).
J=/tmp/cj; T=$(curl -s -c $J -b $J http://localhost/admin/setup.php | grep -o 'name="csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
curl -s -c $J -b $J -o /dev/null -d "csrf_token=$T&username=takmir&password=rahasia123&confirm_password=rahasia123" http://localhost/admin/setup.php
curl -s -c $J -b $J http://localhost/admin/setup.php | grep -q 'recovery-code' && pass "akun takmir dibuat + kode pemulihan tampil" || fail "setup akun gagal"
code=$(curl -s -c $J -b $J -o /dev/null -w '%{http_code}' http://localhost/admin/dashboard.php)
[ "$code" = 200 ] && pass "panel takmir terbuka setelah login" || fail "panel HTTP $code"

# Perintah teknisi & audio.
jamsholat-reset-password --kode takmir | grep -q "Kode pemulihan baru" && pass "sudo jamsholat-reset-password --kode" || fail "reset password CLI"
n=$(find /opt/jamsholat/public/assets/audio/murottal -name '*.mp3' | wc -l)
[ "$n" -ge 114 ] && pass "audio: adzan, iqomah, $n surah murottal" || fail "murottal hanya $n file"

# Layanan alarm: start, hitung jadwal, lalu dihentikan.
cd /opt/jamsholat
timeout 8 runuser -u jamsholat -- env JAMSHOLAT_DB_PATH=/opt/jamsholat/storage/jamsholat.db \
    JAMSHOLAT_AUDIO_PATH=/opt/jamsholat/public/assets/audio JAMSHOLAT_PHP_BIN=/usr/bin/php \
    python3 alarm_service/alarm_service.py > /tmp/alarm.log 2>&1 || true
if grep -q "Memulai layanan alarm" /tmp/alarm.log && ! grep -q "Traceback" /tmp/alarm.log; then
    pass "layanan alarm berjalan tanpa error"
else
    fail "layanan alarm"; cat /tmp/alarm.log
fi

# Lepas paket: data tetap; purge: data hilang.
apt-get -qq remove -y jamsholat > /dev/null 2>&1
[ -f /opt/jamsholat/storage/jamsholat.db ] && pass "remove: database tetap ada" || fail "remove menghapus database"
apt-get -qq purge -y jamsholat > /dev/null 2>&1
[ ! -e /opt/jamsholat/storage ] && pass "purge: data dihapus" || fail "purge menyisakan data"

[ "$FAIL" = 0 ] && echo "SEMUA LULUS ($PRETTY_NAME)" || { echo "ADA YANG GAGAL ($PRETTY_NAME)"; exit 1; }
