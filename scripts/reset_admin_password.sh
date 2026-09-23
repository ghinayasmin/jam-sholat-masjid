#!/bin/bash
#
# reset_admin_password.sh — REQ-F-072 (Offline Admin Password Recovery).
#
# Dipakai saat takmir lupa password dan tidak bisa login. TIDAK bergantung
# pada internet — hanya butuh akses fisik ke perangkat (keyboard+monitor
# langsung, atau SSH ke jaringan lokal masjid).
#
# Penggunaan: sudo bash scripts/reset_admin_password.sh <username>
#
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Jalankan sebagai root: sudo bash scripts/reset_admin_password.sh <username>" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB_PATH="$SCRIPT_DIR/storage/jamsholat.db"

if [ ! -f "$DB_PATH" ]; then
    echo "Database tidak ditemukan di $DB_PATH" >&2
    exit 1
fi

USERNAME="${1:-}"
if [ -z "$USERNAME" ]; then
    echo "Akun admin yang ada:"
    sqlite3 "$DB_PATH" "SELECT username FROM admin_users;"
    read -rp "Masukkan username yang akan direset: " USERNAME
fi

EXISTS=$(sqlite3 "$DB_PATH" "SELECT COUNT(*) FROM admin_users WHERE username = '$USERNAME';")
if [ "$EXISTS" -eq 0 ]; then
    echo "Username '$USERNAME' tidak ditemukan." >&2
    exit 1
fi

read -rsp "Password baru: " NEW_PASS
echo
read -rsp "Ulangi password baru: " NEW_PASS_CONFIRM
echo

if [ "$NEW_PASS" != "$NEW_PASS_CONFIRM" ]; then
    echo "Password tidak cocok, dibatalkan." >&2
    exit 1
fi

if [ ${#NEW_PASS} -lt 8 ]; then
    echo "Password minimal 8 karakter." >&2
    exit 1
fi

HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT);' "$NEW_PASS")
sqlite3 "$DB_PATH" "UPDATE admin_users SET password_hash = '$HASH' WHERE username = '$USERNAME';"

echo "Password untuk '$USERNAME' berhasil direset."
