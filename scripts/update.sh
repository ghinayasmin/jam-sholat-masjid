#!/bin/bash
#
# update.sh: Pasang versi kode terbaru ke Raspberry Pi yang SUDAH terinstal.
# Jalankan dari folder project hasil clone/salinan terbaru:
#
#   sudo bash scripts/update.sh
#
# Data tidak disentuh: database, rekaman kamera, backup, sesi login, upload
# publikasi, dan konfigurasi lokal tetap seperti semula. Kolom database baru
# ditambahkan otomatis oleh src/Db/Database.php saat halaman pertama dibuka.
#
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Jalankan sebagai root: sudo bash scripts/update.sh" >&2
    exit 1
fi

INSTALL_DIR="/opt/jamsholat"
SERVICE_USER="jamsholat"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ ! -d "$INSTALL_DIR" ]; then
    echo "$INSTALL_DIR belum ada. Untuk instalasi pertama gunakan: sudo bash scripts/install.sh" >&2
    exit 1
fi

echo "=== [1/3] Menyalin kode ke $INSTALL_DIR ==="
rsync -a --chown="$SERVICE_USER":"$SERVICE_USER" \
    --exclude '.git' \
    --exclude 'storage/' \
    --exclude 'public/uploads/' \
    --exclude 'config/config.php' --exclude 'config/.env' \
    --exclude 'tests/' \
    "$SCRIPT_DIR"/ "$INSTALL_DIR"/
# File yang sudah dihapus dari repo (rsync di atas tidak menghapus).
rm -f "$INSTALL_DIR"/public/admin/iqomah.php   # diganti sound.php (DECISIONS.md #23)
rm -f "$INSTALL_DIR"/src/Schedule/ScheduleSync.php   # tidak dipakai (DECISIONS.md #38)
rm -f "$INSTALL_DIR"/public/admin/setup.php "$INSTALL_DIR"/src/Auth/RecoveryCode.php   # akun bawaan (#41)
# Foto kamera lama dibuat tanpa izin tulis grup; panel (www-data) perlu menimpanya saat menghapus.
find "$INSTALL_DIR/storage/captures" -type f -name "*.jpg" -exec chmod 664 {} +

# Batas unggahan foto: pasang pengaturan PHP dan naikkan batas lama 5 MB di config.php.
for d in /etc/php/*/apache2/conf.d; do
    cp "$INSTALL_DIR"/config/php-jamsholat.ini "$d"/90-jamsholat.ini
done
sed -i "s/'publication_max_bytes' => 5 \* 1024 \* 1024/'publication_max_bytes' => 20 * 1024 * 1024/" \
    "$INSTALL_DIR"/config/config.php
systemctl reload apache2

# Perintah reset kata sandi untuk teknisi (DECISIONS.md #33).
install -m 755 "$INSTALL_DIR"/scripts/jamsholat-reset-password /usr/local/sbin/jamsholat-reset-password

echo "=== [2/3] Memperbarui unit systemd ==="
# Tema kursor transparan untuk kiosk (dipakai jamsholat-kiosk.service lewat XCURSOR_PATH).
rm -rf /usr/share/icons/jamsholat-blank   # lokasi lama, tidak dipakai lagi
mkdir -p /usr/share/jamsholat/cursors/default
cp -r "$INSTALL_DIR"/systemd/blank-cursor/. /usr/share/jamsholat/cursors/default/
cp "$INSTALL_DIR"/systemd/*.service "$INSTALL_DIR"/systemd/*.timer /etc/systemd/system/
systemctl daemon-reload

# Jadwal sholat di database hanya cache; hitung ulang dengan rumus terbaru
# (mis. perubahan ihtiyat) supaya alarm & layar tidak memakai hasil lama.
# .timeout: layanan kamera/alarm bisa sedang menulis; tunggu sampai 15 detik, jangan langsung gagal.
sudo -u "$SERVICE_USER" sqlite3 -cmd ".timeout 15000" "$INSTALL_DIR/storage/jamsholat.db" "DELETE FROM prayer_schedules;"

echo "=== [3/3] Memulai ulang layanan ==="
systemctl restart jamsholat-alarm.service jamsholat-camera.service
systemctl restart jamsholat-kiosk.service   # memuat ulang tampilan jamaah
# Panggil API sekali supaya migrasi database jalan sekarang, bukan saat jamaah melihat layar.
curl -fsS -o /dev/null http://localhost/api/schedule.php && echo "Database & API OK." \
    || echo "PERINGATAN: API belum merespons, cek: sudo tail /var/log/apache2/jamsholat-error.log"

echo "Selesai. Layar jamaah akan muncul kembali dalam sekitar 30 detik."
