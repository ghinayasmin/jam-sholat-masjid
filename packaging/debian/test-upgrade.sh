#!/bin/bash
#
# test-upgrade.sh — uji upgrade paket .deb di container Debian/Ubuntu bersih
# (DECISIONS.md #40): pasang versi lama, isi data lewat panel, pasang versi baru di
# atasnya, lalu pastikan data tetap utuh dan fitur baru berjalan.
#
#   bash packaging/debian/test-upgrade.sh /path/jamsholat_<lama>_all.deb /path/jamsholat_<baru>_all.deb
#
set -uo pipefail
OLD="${1:?deb lama}"
NEW="${2:?deb baru}"
export DEBIAN_FRONTEND=noninteractive
FAIL=0
pass() { echo "  ✓ $1"; }
fail() { echo "  ✗ $1"; FAIL=1; }
DB=/opt/jamsholat/storage/jamsholat.db
B=http://localhost
J=/tmp/cj
csrf() { curl -s -c $J -b $J "$1" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

. /etc/os-release
echo "== upgrade $(basename "$OLD") → $(basename "$NEW"), $PRETTY_NAME =="
apt-get -qq update
apt-get -qq install -y --no-install-recommends "$OLD" curl > /tmp/apt.log 2>&1 || { tail -30 /tmp/apt.log; exit 1; }
apache2ctl start > /dev/null 2>&1; sleep 2
pass "versi lama terpasang ($(dpkg-query -W -f='${Version}' jamsholat))"

# Data seperti dipakai takmir: akun, lokasi, profil, kas, pengumuman.
T=$(csrf $B/admin/setup.php)
curl -s -c $J -b $J -o /dev/null -d "csrf_token=$T&username=takmir&password=rahasia123&confirm_password=rahasia123" $B/admin/setup.php
T=$(csrf $B/admin/settings_location.php)
curl -s -c $J -b $J -o /dev/null --data-urlencode "location_name=Duri, Riau" -d "csrf_token=$T&latitude=1.257955&longitude=101.195658&madzhab=syafii" $B/admin/settings_location.php
sqlite3 "$DB" "UPDATE mosque_settings SET mosque_name='Masjid Uji Upgrade';
  INSERT INTO financial_entries (entry_date, description, type, amount) VALUES ('2026-10-20', 'Infaq', 'masuk', 500000);
  INSERT INTO publications (file_name, file_path, caption, starts_on) VALUES ('', '', 'Kajian Ahad', '2026-10-20');"
before=$(sqlite3 "$DB" "SELECT (SELECT COUNT(*) FROM admin_users)||'/'||(SELECT COUNT(*) FROM financial_entries)||'/'||(SELECT COUNT(*) FROM publications)||'/'||latitude FROM mosque_settings")
[ -n "$before" ] && pass "data versi lama: akun/kas/pengumuman/lintang = $before" || fail "data versi lama tidak terbaca"

apt-get -qq install -y --no-install-recommends "$NEW" > /tmp/apt.log 2>&1 || { tail -30 /tmp/apt.log; exit 1; }
apache2ctl restart > /dev/null 2>&1; sleep 2
curl -fs -o /dev/null $B/api/schedule.php   # migrasi seperti postinst di systemd
pass "versi baru terpasang di atasnya ($(dpkg-query -W -f='${Version}' jamsholat))"

after=$(sqlite3 "$DB" "SELECT (SELECT COUNT(*) FROM admin_users)||'/'||(SELECT COUNT(*) FROM financial_entries)||'/'||(SELECT COUNT(*) FROM publications)||'/'||latitude FROM mosque_settings")
[ "$after" = "$before" ] && pass "akun, kas, pengumuman, dan lokasi tetap ($after)" || fail "data berubah: $before → $after"
[ "$(sqlite3 "$DB" "SELECT mosque_name FROM mosque_settings")" = "Masjid Uji Upgrade" ] && pass "nama masjid tetap" || fail "nama masjid berubah"
[ "$(sqlite3 "$DB" "SELECT location_confirmed FROM mosque_settings")" = "1" ] && pass "lokasi lama dianggap sudah diatur (tanpa pengingat)" || fail "pengingat lokasi muncul di database lama"
cols=$(sqlite3 "$DB" "SELECT group_concat(name) FROM pragma_table_info('publications') WHERE name LIKE 'event_%'")
[ "$cols" = "event_date,event_end_date,event_start_time,event_end_time" ] && pass "kolom baru ditambahkan (waktu acara)" || fail "kolom waktu acara: '$cols'"

rm -f $J
T=$(csrf $B/admin/login.php)
code=$(curl -s -c $J -b $J -o /dev/null -w '%{redirect_url}' -d "csrf_token=$T&username=takmir&password=rahasia123" $B/admin/login.php)
case "$code" in *dashboard*|*/admin/) pass "login dengan kata sandi lama tetap bisa" ;; *) fail "login gagal setelah upgrade ($code)" ;; esac
code=$(curl -s -c $J -b $J -o /dev/null -w '%{http_code}' $B/admin/dashboard.php)
[ "$code" = 200 ] && pass "akun lama tidak diminta ganti kata sandi (#41)" || fail "Beranda HTTP $code setelah upgrade"
version=$(head -1 /opt/jamsholat/VERSION)
out=$(curl -s -c $J -b $J $B/admin/about.php)
grep -q "Versi $version" <<< "$out" && pass "halaman Tentang: Versi $version" || fail "halaman Tentang tidak menampilkan versi $version"
for page in help phone murottal; do
    code=$(curl -s -c $J -b $J -o /dev/null -w '%{http_code}' $B/admin/$page.php)
    [ "$code" = 200 ] && pass "halaman baru $page.php terbuka" || fail "$page.php HTTP $code"
done
[ ! -e /opt/jamsholat/public/admin/setup.php ] && pass "setup.php lama terhapus saat upgrade" || fail "setup.php lama masih ada"
# Reset ke bawaan (#41): akun lama "takmir" menjadi admin / 000, data tetap.
jamsholat-reset-password --ya > /dev/null 2>&1
[ "$(sqlite3 "$DB" "SELECT username||'/'||must_change_password FROM admin_users")" = "admin/1" ] \
    && pass "reset: akun lama menjadi admin / 000, wajib ganti" || fail "reset akun lama: $(sqlite3 "$DB" "SELECT username, must_change_password FROM admin_users")"
[ "$(sqlite3 "$DB" "SELECT COUNT(*) FROM financial_entries")" = 1 ] && pass "reset tidak menghapus data kas" || fail "data kas berubah setelah reset"
api=$(curl -s $B/api/schedule.php)
grep -q "\"app_version\":\"$version\"" <<< "$api" && pass "API mengirim versi (layar jamaah memuat ulang)" || fail "API tanpa app_version"

[ "$FAIL" = 0 ] && echo "SEMUA LULUS (upgrade, $PRETTY_NAME)" || { echo "ADA YANG GAGAL (upgrade, $PRETTY_NAME)"; exit 1; }
