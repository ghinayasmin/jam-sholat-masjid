#!/bin/bash
# Dijalankan oleh systemd/jamsholat-kiosk.service di dalam compositor `cage`.
# REQ-F-010, REQ-NF-131: tampilan jamaah fullscreen tanpa elemen browser.
# cage tidak punya screen blanking, jadi layar tetap menyala 24/7.

CHROMIUM_BIN=$(command -v chromium-browser || command -v chromium)

exec "$CHROMIUM_BIN" \
    --kiosk \
    --ozone-platform=wayland \
    --disable-gpu \
    --noerrdialogs \
    --disable-infobars \
    --disable-session-crashed-bubble \
    --disable-features=Translate \
    --lang=id \
    --no-first-run \
    --autoplay-policy=no-user-gesture-required \
    --check-for-update-interval=31536000 \
    "http://localhost/display.php"
