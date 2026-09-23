#!/bin/bash
# Dipanggil oleh systemd/jamsholat-kiosk.service lewat `startx`.
# REQ-F-010, REQ-NF-131: tampilan jamaah fullscreen tanpa elemen browser.

# Matikan screen blanking/DPMS — layar TIDAK boleh mati (dipasang di masjid, tampil 24/7).
xset s off
xset s noblank
xset -dpms

# Window manager ringan — cukup untuk menghilangkan border/dekorasi window Chromium.
openbox-session &
sleep 2

CHROMIUM_BIN=$(command -v chromium-browser || command -v chromium)

exec "$CHROMIUM_BIN" \
    --kiosk \
    --noerrdialogs \
    --disable-infobars \
    --disable-session-crashed-bubble \
    --disable-translate \
    --no-first-run \
    --autoplay-policy=no-user-gesture-required \
    --check-for-update-interval=31536000 \
    "http://localhost/display.php"
