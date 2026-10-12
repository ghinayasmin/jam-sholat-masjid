# File Audio (belum disertakan)

Folder ini butuh file audio berikut sebelum `alarm_service.py` bisa memutar suara
(REQ-F-030, REQ-F-031, REQ-F-032). File audio TIDAK disertakan dalam paket
ini karena rekaman adzan/murottal biasanya berlisensi/hak cipta pihak lain —
silakan sediakan sendiri file yang sudah dimiliki/berlisensi legal masjid Anda.

Letakkan file berikut:

```
public/assets/audio/
├── adzan.mp3              # diputar saat masuk waktu sholat (REQ-F-030)
├── iqomah.mp3              # diputar saat masuk waktu iqomah (REQ-F-031)
│                            # (adzan/iqomah boleh .wav bila tidak ada .mp3)
└── murottal/
    ├── 001.mp3              # diputar bergantian di luar waktu adzan/iqomah (REQ-F-032)
    ├── 002.mp3
    └── ...
```

Format: MP3 (didukung lewat `mpg123`, yang di-install otomatis oleh
`scripts/install.sh`). Kalau file belum ada, sistem tetap berjalan normal
(REQ-NF-112 graceful error handling) — hanya akan mencatat peringatan di log
("File audio tidak ditemukan") dan melewati pemutaran suara, tanpa membuat
layanan berhenti.

## Sumber bebas lisensi yang dipakai saat uji coba

Adzan, iqomah, dan murottal Juz 'Amma (Al-Fatihah + An-Naba s.d. An-Nas,
bacaan Aaqib Azeez) diambil dari Wikimedia Commons dengan lisensi CC BY-SA 4.0
dan CC0. Daftar file, lisensi, dan tautan sumbernya ada di `CREDITS.txt`
(ikut disalin bersama file audio). CC BY-SA mewajibkan atribusi: cantumkan
nama pembaca dan tautan sumber bila rekaman ini disebarkan ulang.
