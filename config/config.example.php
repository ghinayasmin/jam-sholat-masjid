<?php

declare(strict_types=1);

// Salin file ini menjadi config.php lalu sesuaikan path untuk device kamu.
// Nilai di sini TIDAK rahasia (path, opsi). Untuk kredensial/secret,
// gunakan config/.env (lihat .env.example) — jangan taruh di sini.

return [
    // Lokasi file database SQLite.
    'db_path' => dirname(__DIR__) . '/storage/jamsholat.db',

    // Direktori penyimpanan file upload publikasi.
    'publications_path' => dirname(__DIR__) . '/public/uploads/publications',

    // Direktori penyimpanan gambar hasil deteksi kamera (dibaca admin panel,
    // ditulis oleh camera_service/motion_detect.py).
    'captures_path' => dirname(__DIR__) . '/storage/captures',

    // Direktori penyimpanan file backup.
    'backups_path' => dirname(__DIR__) . '/storage/backups',

    // Direktori session PHP (REQ-F-071).
    'session_path' => dirname(__DIR__) . '/storage/sessions',

    // Batas ukuran upload poster publikasi, dalam byte. Default 5 MB.
    'publication_max_bytes' => 5 * 1024 * 1024,

    // Format gambar publikasi yang diterima.
    'publication_allowed_mime' => ['image/jpeg', 'image/png'],

    // Lama sesi login panel takmir (hari). Panel hanya bisa dibuka dari jaringan
    // lokal masjid, jadi pengurus tidak perlu login ulang setiap membuka HP.
    'admin_session_days' => 30,

    // Endpoint online sync (REQ-F-004) — AlAdhan API, method=20 (KEMENAG),
    // tidak butuh API key. Lihat docs/DECISIONS.md untuk alasan pemilihan.
    'aladhan_base_url' => 'https://api.aladhan.com/v1',
];
