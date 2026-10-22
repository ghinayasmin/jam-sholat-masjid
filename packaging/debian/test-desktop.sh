#!/bin/bash
#
# test-desktop.sh — uji paket .deb dalam mode desktop di container Debian:
# izin tulis tanpa login ulang (ACL), lalu alur layar jamaah lewat menu
# (buka, tutup dengan Alt+F4, buka lagi, bawa ke depan) tanpa suara dobel.
#
#   bash packaging/debian/test-desktop.sh /path/jamsholat_<versi>_all.deb chromium|firefox-esr
#
# Container tidak punya systemd maupun layar: systemctl dipalsukan agar postinst
# memilih mode desktop, dan layar memakai Xvfb. Chromium butuh container dengan
# --security-opt seccomp=unconfined untuk sandbox-nya.
#
set -uo pipefail
DEB="${1:?path .deb}"
BROWSER="${2:?chromium atau firefox-esr}"
export DEBIAN_FRONTEND=noninteractive
FAIL=0
pass() { echo "  ✓ $1"; }
fail() { echo "  ✗ $1"; FAIL=1; }

. /etc/os-release
echo "== desktop $BROWSER, $PRETTY_NAME =="
apt-get -qq update
apt-get -qq install -y --no-install-recommends xvfb sqlite3 util-linux "$BROWSER" > /tmp/apt.log 2>&1 || { tail -20 /tmp/apt.log; exit 1; }

# Akun desktop sudah ada sebelum paket dipasang (seperti laptop takmir).
useradd -m -s /bin/bash takmir
U=$(id -u takmir)

# Dependensi dulu (bisa membawa systemd), lalu systemctl diganti versi palsu.
deps=$(dpkg-deb -f "$DEB" Depends | tr -d ' ' | tr ',' ' ')
apt-get -qq install -y --no-install-recommends $deps > /tmp/apt.log 2>&1 || { tail -20 /tmp/apt.log; exit 1; }
mkdir -p /run/systemd/system
for f in /usr/bin/systemctl /bin/systemctl; do
    [ -L "$f" ] && continue
    printf '#!/bin/sh\n[ "$1" = get-default ] && echo graphical.target\nexit 0\n' > "$f"
    chmod 755 "$f"
done

apt-get -qq install -y --no-install-recommends "$DEB" > /tmp/apt.log 2>&1 || { tail -30 /tmp/apt.log; exit 1; }
[ "$(cat /etc/jamsholat/mode)" = desktop ] && pass "mode desktop" || fail "mode $(cat /etc/jamsholat/mode)"
apache2ctl start > /dev/null 2>&1; sleep 2

# Proses yang belum login ulang: grup jamsholat belum aktif (--clear-groups).
as_fresh() { setpriv --reuid="$U" --regid="$U" --clear-groups env HOME=/home/takmir "$@"; }
DB=/opt/jamsholat/storage/jamsholat.db
if as_fresh sh -c "umask 022; sqlite3 $DB \"PRAGMA journal_mode=WAL; UPDATE mosque_settings SET mosque_name='Uji ACL' WHERE id=1;\"" > /dev/null 2>&1; then
    pass "akun desktop bisa menulis database tanpa login ulang (ACL)"
else
    fail "akun desktop belum bisa menulis database"; getfacl -p $DB 2>&1 | head -20
fi
if runuser -u www-data -- sqlite3 $DB "UPDATE mosque_settings SET mosque_name='Uji Apache' WHERE id=1;" > /dev/null 2>&1; then
    pass "Apache tetap bisa menulis setelah file WAL dibuat akun desktop"
else
    fail "Apache tidak bisa menulis"; ls -l /opt/jamsholat/storage
fi
as_fresh sh -c "touch /opt/jamsholat/storage/captures/uji.jpg" 2> /dev/null && pass "kamera (akun desktop) bisa menyimpan foto" || fail "folder foto tidak bisa ditulis"

# Sesi desktop: Xvfb + menu "Layar Jamaah" (skrip yang sama dengan autostart).
mkdir -p /run/user/$U && chown takmir /run/user/$U
Xvfb :1 -screen 0 1280x720x24 > /dev/null 2>&1 &
sleep 2
SESSION=$(sed -n 's/^Exec=//p' /usr/share/applications/jamsholat-display.desktop)
click() { as_fresh env DISPLAY=:1 XDG_RUNTIME_DIR=/run/user/$U "$SESSION" > /dev/null 2>&1; }
kiosk_pids() {
    for p in $(pgrep -u $U -f -- jamsholat-kiosk); do
        tr '\0' ' ' < /proc/$p/cmdline 2>/dev/null | grep -qv -- '--type=\|-contentproc\|-childID' && echo $p
    done
}
alarms() { pgrep -u $U -f -- alarm_service.py | wc -l; }

click &                       # login: autostart
sleep 25
first=$(kiosk_pids | head -1)
[ -n "$first" ] && pass "login membuka layar jamaah" || { fail "layar tidak terbuka"; tail -20 /home/takmir/.local/state/jamsholat/kiosk.log; }
tr '\0' ' ' < /proc/$first/cmdline 2>/dev/null | grep -q 'layar=desktop' && pass "layar dibuka dengan petunjuk Alt+F4 (?layar=desktop)" || fail "URL tanpa layar=desktop"
[ "$(alarms)" = 1 ] && pass "layanan alarm berjalan" || fail "alarm berjalan $(alarms) kali"

pkill -u $U -f -- jamsholat-kiosk; sleep 4
[ -z "$(kiosk_pids)" ] && pass "layar ditutup (Alt+F4)" || fail "layar belum tertutup"
[ "$(alarms)" = 1 ] && pass "alarm tetap berjalan saat layar ditutup" || fail "alarm berhenti"

click; sleep 20
[ -n "$(kiosk_pids)" ] && pass "menu Layar Jamaah membuka lagi layar" || fail "layar tidak terbuka lagi"

before=$(kiosk_pids | head -1)
click; sleep 20
after=$(kiosk_pids)
if [ "$(echo "$after" | grep -c .)" = 1 ] && [ "$after" != "$before" ]; then
    pass "klik saat layar terbuka: layar dibuka ulang di depan, tetap satu jendela"
else
    fail "jendela kiosk: sebelum $before, sesudah $(echo $after)"
fi
[ "$(alarms)" = 1 ] && pass "alarm tetap satu setelah 3 klik (suara tidak dobel)" || fail "alarm berjalan $(alarms) kali"

[ "$FAIL" = 0 ] && echo "SEMUA LULUS (desktop $BROWSER)" || { echo "ADA YANG GAGAL (desktop $BROWSER)"; exit 1; }
