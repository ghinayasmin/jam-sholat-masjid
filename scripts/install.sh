#!/bin/bash
#
# install.sh — Instalasi Sistem Jam Sholat Masjid di Raspberry Pi OS Lite.
# Jalankan sebagai root (atau lewat sudo) SEKALI SAJA saat provisioning awal,
# selagi perangkat online (untuk apt/pip). Setelah itu sistem berjalan
# sepenuhnya offline (Scope §1.2 SRS).
#
# Penggunaan: sudo bash scripts/install.sh
#
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Script ini harus dijalankan sebagai root (sudo bash scripts/install.sh)." >&2
    exit 1
fi

INSTALL_DIR="/opt/jamsholat"
SERVICE_USER="jamsholat"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "=== [1/10] Memastikan paket sistem terpasang ==="
apt-get update -qq
# Nama paket Chromium beda antar versi Raspberry Pi OS: rilis lama memakai
# "chromium-browser", rilis baru (Bookworm akhir/Trixie) memakai "chromium".
if apt-cache show chromium-browser >/dev/null 2>&1; then
    CHROMIUM_PKG="chromium-browser"
else
    CHROMIUM_PKG="chromium"
fi
apt-get install -y \
    apache2 libapache2-mod-php \
    php php-sqlite3 php-mbstring php-gd \
    sqlite3 \
    python3 python3-pip python3-opencv \
    cage "$CHROMIUM_PKG" fonts-noto-color-emoji \
    mpg123 alsa-utils \
    fake-hwclock \
    rsync sudo

# fake-hwclock: solusi standar Debian/Raspbian utk device tanpa RTC (REQ-F-061).
# Sudah aktif otomatis begitu paket terpasang (systemd service bawaan paket).
echo "fake-hwclock terpasang — menjaga jam tetap masuk akal walau tanpa RTC fisik saat offline."

echo "=== [2/10] Membuat user layanan ($SERVICE_USER) ==="
if ! id "$SERVICE_USER" &>/dev/null; then
    useradd -m -s /bin/bash -G audio,video "$SERVICE_USER"
    echo "User $SERVICE_USER dibuat."
else
    echo "User $SERVICE_USER sudah ada, dilewati."
fi

echo "=== [3/10] Menyalin project ke $INSTALL_DIR ==="
mkdir -p "$INSTALL_DIR"
rsync -a --exclude 'storage/jamsholat.db*' --exclude 'config/config.php' --exclude 'config/.env' \
    "$SCRIPT_DIR"/ "$INSTALL_DIR"/
chown -R "$SERVICE_USER":"$SERVICE_USER" "$INSTALL_DIR"

echo "=== [4/10] Menyiapkan konfigurasi ==="
if [ ! -f "$INSTALL_DIR/config/config.php" ]; then
    cp "$INSTALL_DIR/config/config.example.php" "$INSTALL_DIR/config/config.php"
fi
if [ ! -f "$INSTALL_DIR/config/.env" ]; then
    cp "$INSTALL_DIR/config/.env.example" "$INSTALL_DIR/config/.env"
fi

mkdir -p "$INSTALL_DIR"/storage/{captures,backups,sessions}
mkdir -p "$INSTALL_DIR"/public/uploads/publications
# Apache/PHP berjalan sebagai www-data, tapi storage & uploads dimiliki user
# layanan. Masukkan www-data ke grup $SERVICE_USER supaya PHP bisa menulis
# database, sesi login, backup, dan upload publikasi. Bit setgid (2775) membuat
# file baru otomatis ikut grup $SERVICE_USER.
usermod -aG "$SERVICE_USER" www-data
chown -R "$SERVICE_USER":"$SERVICE_USER" "$INSTALL_DIR/storage" "$INSTALL_DIR/public/uploads"
find "$INSTALL_DIR/storage" "$INSTALL_DIR/public/uploads" -type d -exec chmod 2775 {} +

echo "=== [5/10] Inisialisasi database ==="
if [ ! -f "$INSTALL_DIR/storage/jamsholat.db" ]; then
    sudo -u "$SERVICE_USER" sqlite3 "$INSTALL_DIR/storage/jamsholat.db" < "$INSTALL_DIR/database/schema.sql"
    echo "Database dibuat."
else
    echo "Database sudah ada, dilewati (jalankan restore lewat panel admin bila perlu)."
fi

# Grup harus bisa menulis DB (file -wal/-shm SQLite ikut izin file DB).
chmod 664 "$INSTALL_DIR/storage/jamsholat.db"

echo "=== [6/10] Membuat akun admin pertama (REQ-F-070) ==="
ADMIN_COUNT=$(sqlite3 "$INSTALL_DIR/storage/jamsholat.db" "SELECT COUNT(*) FROM admin_users;")
if [ "$ADMIN_COUNT" -eq 0 ]; then
    read -rp "Username admin takmir: " ADMIN_USER
    read -rsp "Password admin takmir: " ADMIN_PASS
    echo
    HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT);' "$ADMIN_PASS")
    sqlite3 "$INSTALL_DIR/storage/jamsholat.db" \
        "INSERT INTO admin_users (username, password_hash) VALUES ('$ADMIN_USER', '$HASH');"
    echo "Admin '$ADMIN_USER' berhasil dibuat."
else
    echo "Akun admin sudah ada, dilewati. Gunakan scripts/reset_admin_password.sh untuk reset."
fi

echo "=== [7/10] Instalasi dependency Python (butuh internet, sekali saja) ==="
# python3-opencv dari apt (langkah 1) sudah membawa cv2 + numpy. pip hanya
# dipakai bila import gagal — di Pi 2/3 (armhf) pip bisa mencoba compile OpenCV
# dari source berjam-jam, jadi jangan dijalankan kalau tidak perlu.
if python3 -c "import cv2, numpy" 2>/dev/null; then
    echo "OpenCV & numpy dari apt sudah tersedia, pip dilewati."
else
    pip3 install --break-system-packages -r "$INSTALL_DIR/camera_service/requirements.txt" || \
        echo "PERINGATAN: gagal install dependency Python via pip."
fi

echo "=== [8/10] Konfigurasi timezone & sudoers untuk fitur set-jam manual (REQ-F-020) ==="
timedatectl set-timezone Asia/Jakarta || echo "PERINGATAN: gagal set timezone otomatis, set manual: timedatectl set-timezone Asia/Jakarta"

SUDOERS_FILE="/etc/sudoers.d/jamsholat-date"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/date -s *" > "$SUDOERS_FILE"
chmod 440 "$SUDOERS_FILE"
visudo -c -f "$SUDOERS_FILE" || { echo "File sudoers tidak valid, dibatalkan."; rm -f "$SUDOERS_FILE"; }

echo "=== [9/10] Konfigurasi Apache virtual host ==="
cat > /etc/apache2/sites-available/jamsholat.conf << EOF
<VirtualHost *:80>
    DocumentRoot $INSTALL_DIR/public
    <Directory $INSTALL_DIR/public>
        AllowOverride All
        Require ip 127.0.0.1 ::1 192.168.0.0/16 10.0.0.0/8 172.16.0.0/12
    </Directory>
    <Directory $INSTALL_DIR/public/admin>
        Require ip 127.0.0.1 ::1 192.168.0.0/16 10.0.0.0/8 172.16.0.0/12
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/jamsholat-error.log
    CustomLog \${APACHE_LOG_DIR}/jamsholat-access.log combined
</VirtualHost>
EOF
# REQ-NF-122: panel admin (dan idealnya seluruh app) hanya boleh diakses dari
# jaringan lokal masjid — baris "Require ip" di atas membatasi ke rentang
# private (LAN/WiFi masjid), sesuaikan rentang IP dengan jaringan Anda.
a2dissite 000-default.conf || true
a2ensite jamsholat.conf
a2enmod rewrite
systemctl reload apache2

echo "=== [10/10] Memasang & mengaktifkan systemd services ==="
# Tema kursor transparan untuk kiosk (dipakai jamsholat-kiosk.service lewat XCURSOR_PATH).
rm -rf /usr/share/icons/jamsholat-blank   # lokasi lama, tidak dipakai lagi
mkdir -p /usr/share/jamsholat/cursors/default
cp -r "$INSTALL_DIR"/systemd/blank-cursor/. /usr/share/jamsholat/cursors/default/
cp "$INSTALL_DIR"/systemd/*.service "$INSTALL_DIR"/systemd/*.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now jamsholat-camera.service
systemctl enable --now jamsholat-alarm.service
systemctl enable --now jamsholat-retention.timer
# getty dimatikan supaya tidak berebut tty1 dengan kiosk.
systemctl disable getty@tty1.service || true
systemctl enable jamsholat-kiosk.service
echo "Layanan kiosk (Chromium fullscreen) akan aktif setelah reboot berikutnya."

echo ""
echo "=================================================================="
echo " Instalasi selesai."
echo " - Tampilan jamaah : http://localhost/display.php (atau reboot utk kiosk fullscreen)"
echo " - Panel admin     : http://<ip-raspberry-pi>/admin/login.php"
echo " - Jangan lupa: sesuaikan koordinat lokasi & profil masjid di panel admin."
echo "=================================================================="
