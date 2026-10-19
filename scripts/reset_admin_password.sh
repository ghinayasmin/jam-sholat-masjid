#!/bin/bash
#
# reset_admin_password.sh — nama lama, tetap ada agar panduan lama masih berlaku.
# Sekarang cukup: sudo jamsholat-reset-password   (lihat DECISIONS.md #33)
#
set -euo pipefail
if [ "$(id -u)" -ne 0 ]; then
    echo "Jalankan dengan sudo: sudo jamsholat-reset-password" >&2
    exit 1
fi
exec runuser -u jamsholat -- php /opt/jamsholat/scripts/reset_password_cli.php "$@"
