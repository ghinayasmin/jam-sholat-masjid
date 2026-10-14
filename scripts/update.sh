#!/bin/bash
#
# update.sh — Pasang versi kode terbaru ke Raspberry Pi yang SUDAH terinstal.
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

echo "=== [2/3] Memperbarui unit systemd ==="
# Tema kursor transparan untuk kiosk (dipakai jamsholat-kiosk.service lewat XCURSOR_PATH).
rm -rf /usr/share/icons/jamsholat-blank   # lokasi lama, tidak dipakai lagi
mkdir -p /usr/share/jamsholat/cursors/default
cp -r "$INSTALL_DIR"/systemd/blank-cursor/. /usr/share/jamsholat/cursors/default/
cp "$INSTALL_DIR"/systemd/*.service "$INSTALL_DIR"/systemd/*.timer /etc/systemd/system/
systemctl daemon-reload

# Jadwal sholat di database hanya cache; hitung ulang dengan rumus terbaru
# (mis. perubahan ihtiyat) supaya alarm & layar tidak memakai hasil lama.
sudo -u "$SERVICE_USER" sqlite3 "$INSTALL_DIR/storage/jamsholat.db" "DELETE FROM prayer_schedules;"

echo "=== [3/3] Memulai ulang layanan ==="
systemctl restart jamsholat-alarm.service jamsholat-camera.service
systemctl restart jamsholat-kiosk.service   # memuat ulang tampilan jamaah
# Panggil API sekali supaya migrasi database jalan sekarang, bukan saat jamaah melihat layar.
curl -fsS -o /dev/null http://localhost/api/schedule.php && echo "Database & API OK." \
    || echo "PERINGATAN: API belum merespons, cek: sudo tail /var/log/apache2/jamsholat-error.log"

echo "Selesai. Layar jamaah akan muncul kembali dalam sekitar 30 detik."
