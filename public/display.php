<!DOCTYPE html>
<html lang="id" translate="no">
<head>
<meta charset="UTF-8">
<meta name="google" content="notranslate">
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
<title>Jam Sholat Masjid</title>
<link rel="stylesheet" href="/assets/css/display.css">
</head>
<body>
<div class="display-wrap">
    <header class="top-bar">
        <div class="mosque-name" id="mosqueName">Memuat...</div>
        <div class="clock-block">
            <div class="clock" id="clock">--:--:--</div>
            <div class="date-line">
                <span id="gregorianDate">-</span> &middot; <span id="hijriDate">-</span>
            </div>
        </div>
    </header>

    <section class="prayer-times" id="prayerTimes">
        <div class="prayer-item" data-key="fajr"><span class="prayer-label">Subuh</span><span class="prayer-value">--:--</span></div>
        <div class="prayer-item" data-key="sunrise"><span class="prayer-label">Terbit</span><span class="prayer-value">--:--</span></div>
        <div class="prayer-item" data-key="dhuhr"><span class="prayer-label">Dzuhur</span><span class="prayer-value">--:--</span></div>
        <div class="prayer-item" data-key="asr"><span class="prayer-label">Ashar</span><span class="prayer-value">--:--</span></div>
        <div class="prayer-item" data-key="maghrib"><span class="prayer-label">Maghrib</span><span class="prayer-value">--:--</span></div>
        <div class="prayer-item" data-key="isha"><span class="prayer-label">Isya</span><span class="prayer-value">--:--</span></div>
    </section>

    <div class="fasting-banner" id="fastingBanner" hidden>
        🌙 Hari ini dianjurkan puasa sunnah: <span id="fastingLabel"></span>
    </div>

    <main class="main-grid">
        <section class="slideshow" id="slideshow" aria-label="Publikasi">
            <div class="slideshow-empty" id="slideshowEmpty">Belum ada publikasi.</div>
        </section>

        <aside class="financial-panel" id="financialPanel">
            <h2>Laporan Keuangan Masjid</h2>
            <div class="balance" id="financialBalance">Rp 0</div>
            <ul class="financial-list" id="financialList"></ul>
        </aside>
    </main>
</div>

<script src="/assets/js/display.js"></script>
</body>
</html>
