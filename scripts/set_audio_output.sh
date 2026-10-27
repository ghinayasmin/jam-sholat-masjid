#!/bin/bash
#
# set_audio_output.sh: Pilih keluaran suara bawaan Raspberry Pi (REQ-F-030..033).
#
# Raspberry Pi OS Lite (Trixie) memakai jack 3,5 mm sebagai keluaran bawaan,
# dan HDMI (kartu vc4hdmi) hanya menerima format IEC958 sehingga harus lewat
# plugin "hdmi:". Skrip ini menulis /etc/asound.conf agar mpg123/aplay di
# jamsholat-alarm.service memakai keluaran yang dipilih.
#
# Penggunaan: sudo bash scripts/set_audio_output.sh hdmi   # speaker TV/monitor
#             sudo bash scripts/set_audio_output.sh jack   # speaker aktif 3,5 mm
#
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Jalankan sebagai root: sudo bash scripts/set_audio_output.sh hdmi|jack" >&2
    exit 1
fi

case "${1:-}" in
    hdmi) CARD="vc4hdmi";    PCM="hdmi:CARD=vc4hdmi,DEV=0" ;;
    jack) CARD="Headphones"; PCM="hw:CARD=Headphones,DEV=0" ;;
    *)
        echo "Penggunaan: sudo bash scripts/set_audio_output.sh hdmi|jack" >&2
        exit 1
        ;;
esac

if ! aplay -l | grep -q "\[$CARD\]\|: $CARD \["; then
    echo "Kartu suara '$CARD' tidak ditemukan. Daftar kartu yang ada:" >&2
    aplay -l >&2
    exit 1
fi

cat > /etc/asound.conf << EOF
# Dibuat oleh scripts/set_audio_output.sh ($1)
pcm.!default {
    type plug
    slave.pcm "$PCM"
}
ctl.!default {
    type hw
    card $CARD
}
EOF

systemctl restart jamsholat-alarm.service 2>/dev/null || true
echo "Keluaran suara bawaan: $1. Uji dengan: speaker-test -c 2 -t wav -l 1"
