#!/bin/bash
#
# add_wifi.sh: Tambahkan jaringan WiFi (WPA2-Personal) yang diingat Raspberry Pi.
#
# Jaringan lama tidak dihapus: Pi otomatis memilih jaringan yang tersedia,
# yang prioritasnya lebih tinggi didahulukan. Kalau password salah ketik,
# Pi tetap bisa kembali ke jaringan lama sehingga tidak "hilang".
# Password diminta di layar (tidak tampil) supaya tidak tersimpan di riwayat shell.
#
# Raspberry Pi 3 hanya bisa 2,4 GHz, dan WiFi dengan halaman login (captive
# portal) atau WPA2-Enterprise/SSO tidak didukung skrip ini.
#
# Penggunaan: sudo bash scripts/add_wifi.sh "NAMA WIFI" [prioritas]
#             prioritas bawaan 10; jaringan lama bernilai 0.
#
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Jalankan sebagai root: sudo bash scripts/add_wifi.sh \"NAMA WIFI\" [prioritas]" >&2
    exit 1
fi

SSID="${1:-}"
PRIORITY="${2:-10}"
if [ -z "$SSID" ]; then
    echo "Penggunaan: sudo bash scripts/add_wifi.sh \"NAMA WIFI\" [prioritas]" >&2
    exit 1
fi

CON_NAME="wifi-$SSID"
read -rsp "Password WiFi \"$SSID\": " PSK
echo
if [ "${#PSK}" -lt 8 ]; then
    echo "Password WPA2 minimal 8 karakter." >&2
    exit 1
fi

if nmcli -t -f NAME connection show | grep -Fxq "$CON_NAME"; then
    nmcli connection delete "$CON_NAME" > /dev/null
fi
nmcli connection add type wifi con-name "$CON_NAME" ifname wlan0 ssid "$SSID" \
    wifi-sec.key-mgmt wpa-psk wifi-sec.psk "$PSK" \
    connection.autoconnect yes connection.autoconnect-priority "$PRIORITY" \
    ipv6.method disabled > /dev/null   # lihat install.sh: IPv6 publik -> 403
unset PSK

echo "Tersimpan: \"$SSID\" (prioritas $PRIORITY). Jaringan yang diingat Pi:"
nmcli -f NAME,AUTOCONNECT-PRIORITY connection show | grep -v "^lo "
