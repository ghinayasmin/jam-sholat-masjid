#!/bin/bash
#
# build-deb.sh — membuat paket jamsholat_<versi>_all.deb (DECISIONS.md #39).
#
#   bash packaging/debian/build-deb.sh <versi> <folder-audio> <folder-keluaran>
#   (<versi> kosong "" = isi berkas VERSION)
#
# <folder-audio> berisi adzan.mp3, adzan_subuh.mp3, iqomah.mp3, CREDITS.txt, dan
# murottal/*.mp3 (isi jamsholat-audio-v1.zip). Butuh dpkg-deb, jadi jalankan di
# Debian/Ubuntu atau di container Docker (lihat .github/workflows/package.yml).
#
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
VERSION="${1:-$(head -1 "$REPO/VERSION" | tr -d '[:space:]')}"   # bawaan: berkas VERSION
AUDIO_DIR="${2:?folder audio}"
OUT_DIR="${3:?folder keluaran}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

for f in adzan.mp3 adzan_subuh.mp3 iqomah.mp3 CREDITS.txt; do
    [ -f "$AUDIO_DIR/$f" ] || { echo "Audio tidak lengkap: $AUDIO_DIR/$f tidak ada" >&2; exit 1; }
done

APP="$STAGE/opt/jamsholat"
mkdir -p "$APP" "$STAGE/DEBIAN"

# Aplikasi: tanpa test, data lokal, dan audio dari repo (audio diambil dari bundel).
rsync -a \
    --exclude '.git*' --exclude '.github' --exclude 'tests/' --exclude 'storage/' \
    --exclude 'public/uploads/' --exclude 'public/assets/audio/' --exclude 'config/config.php' \
    --exclude '__pycache__' --exclude '.DS_Store' --exclude 'packaging/windows' --exclude 'packaging/debian' \
    "$REPO"/ "$APP"/
mkdir -p "$APP/public/assets/audio"
rsync -a --exclude '*.wav' "$AUDIO_DIR"/ "$APP/public/assets/audio/"
cp "$REPO/public/assets/audio/README.md" "$APP/public/assets/audio/" 2>/dev/null || true
chmod 755 "$APP/packaging/linux/jamsholat-session"

# Layanan systemd (dipakai di server tanpa desktop). Kamera: tanpa pengaturan khusus
# Raspberry Pi Camera V2 NoIR (rotasi & tuning hanya untuk perangkat uji di masjid).
mkdir -p "$STAGE/lib/systemd/system"
cp "$REPO"/systemd/jamsholat-alarm.service "$REPO"/systemd/jamsholat-retention.service \
   "$REPO"/systemd/jamsholat-retention.timer "$STAGE/lib/systemd/system/"
grep -v -e '^Environment=JAMSHOLAT_CAMERA_ROTATION' -e '^Environment=JAMSHOLAT_CAMERA_TUNING' \
    "$REPO/systemd/jamsholat-camera.service" > "$STAGE/lib/systemd/system/jamsholat-camera.service"

mkdir -p "$STAGE/etc/apache2/sites-available" "$STAGE/etc/xdg/autostart" "$STAGE/usr/sbin"
cp "$REPO/packaging/linux/apache-jamsholat.conf" "$STAGE/etc/apache2/sites-available/jamsholat.conf"
cp "$REPO/packaging/linux/jamsholat-session.desktop" "$STAGE/etc/xdg/autostart/"
install -m 755 "$REPO/scripts/jamsholat-reset-password" "$STAGE/usr/sbin/jamsholat-reset-password"
install -Dm 644 "$REPO/packaging/icon/jamsholat-256.png" "$STAGE/usr/share/icons/hicolor/256x256/apps/jamsholat.png"
install -Dm 644 "$REPO/packaging/icon/jamsholat.svg" "$STAGE/usr/share/icons/hicolor/scalable/apps/jamsholat.svg"
install -Dm 644 -t "$STAGE/usr/share/applications" \
    "$REPO/packaging/linux/jamsholat-display.desktop" "$REPO/packaging/linux/jamsholat-panel.desktop"

install -m 755 "$REPO/packaging/debian/postinst" "$REPO/packaging/debian/prerm" \
    "$REPO/packaging/debian/postrm" "$STAGE/DEBIAN/"
echo "/etc/apache2/sites-available/jamsholat.conf" > "$STAGE/DEBIAN/conffiles"

SIZE_KB="$(du -sk "$STAGE" | cut -f1)"
cat > "$STAGE/DEBIAN/control" << EOF
Package: jamsholat
Version: $VERSION
Architecture: all
Maintainer: Tim Capstone Jam Sholat Masjid <noreply@jamsholat.local>
Installed-Size: $SIZE_KB
Depends: apache2, libapache2-mod-php, php-cli, php-sqlite3, php-mbstring, php-gd, sqlite3, python3, mpg123, alsa-utils, sudo, curl, adduser, rsync, procps, xdg-utils, acl
Recommends: python3-opencv, chromium | chromium-browser | firefox-esr | firefox, libnotify-bin
Section: misc
Priority: optional
Homepage: https://github.com/ghinayasmin/jam-sholat-masjid
Description: Jam sholat masjid: layar jamaah, adzan & iqomah, panel takmir
 Jadwal sholat kriteria Kemenag RI dihitung di perangkat, layar jamaah layar
 penuh dengan pengingat dan hitung mundur iqomah, suara adzan, iqomah, dan
 murottal, pengumuman, kas, kamera, serta panel takmir yang dibuka dari HP
 lewat WiFi masjid. Capstone Project Ilmu Komputer UGM 2026.
EOF

mkdir -p "$OUT_DIR"
dpkg-deb --root-owner-group -Zxz --build "$STAGE" "$OUT_DIR/jamsholat_${VERSION}_all.deb"
ls -la "$OUT_DIR/jamsholat_${VERSION}_all.deb"
