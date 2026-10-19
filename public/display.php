<!DOCTYPE html>
<html lang="id" translate="no">
<head>
<meta charset="UTF-8">
<meta name="google" content="notranslate">
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
<title>Jam Sholat Masjid</title>
<link rel="preload" href="/assets/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/css/display.css?v=<?= filemtime(__DIR__ . '/assets/css/display.css') ?>">
</head>
<body>
<div class="screen">
    <section class="now" aria-label="Waktu sekarang">
        <div class="kawung" aria-hidden="true"></div>
        <header class="mosque">
            <div class="mosque-name" id="mosqueName">Masjid</div>
            <div class="mosque-address" id="mosqueAddress"></div>
        </header>
        <div class="clock-block">
            <div class="clock" id="clock">--:--</div>
            <div class="date-line"><span id="gregorianDate">-</span><span class="hijri" id="hijriDate">-</span></div>
        </div>
        <div class="countdown" id="countdown">
            <span class="countdown-value" id="countdownValue">-</span>
            <span class="countdown-text" id="countdownText"></span>
        </div>
        <div class="poster" id="poster" hidden><img id="posterImg" alt=""></div>
        <!-- Slide saldo kas seukuran poster (DECISIONS.md #27). -->
        <div class="kas" id="kasSlide" hidden>
            <div>
                <div class="kas-eyebrow">Baitul Maal <span class="ar">بيت المال</span></div>
                <div class="kas-label">Saldo saat ini</div>
                <div class="kas-total"><span>Rp</span><b id="kasTotal">-</b></div>
            </div>
            <div>
                <div class="kas-rows">
                    <div><small id="kasInLabel">Pemasukan</small><b class="in" id="kasIn">-</b></div>
                    <div><small id="kasOutLabel">Pengeluaran</small><b class="out" id="kasOut">-</b></div>
                </div>
                <div class="kas-updated" id="kasUpdated"></div>
            </div>
        </div>
    </section>

    <aside class="today" aria-label="Jadwal sholat hari ini">
        <div class="list-caption" id="listCaption" hidden>Jadwal besok</div>
        <ol class="prayers" id="prayers">
            <li data-key="fajr"><span class="p-name">Subuh</span><span class="p-ar">الفجر</span><span class="p-time">--:--</span></li>
            <li data-key="sunrise"><span class="p-name">Terbit</span><span class="p-ar">الشروق</span><span class="p-time">--:--</span></li>
            <li data-key="dhuhr"><span class="p-name">Dzuhur</span><span class="p-ar">الظهر</span><span class="p-time">--:--</span></li>
            <li data-key="asr"><span class="p-name">Ashar</span><span class="p-ar">العصر</span><span class="p-time">--:--</span></li>
            <li data-key="maghrib"><span class="p-name">Maghrib</span><span class="p-ar">المغرب</span><span class="p-time">--:--</span></li>
            <li data-key="isha"><span class="p-name">Isya</span><span class="p-ar">العشاء</span><span class="p-time">--:--</span></li>
        </ol>
        <dl class="info">
            <div id="infoAnnouncement" hidden><dt>Pengumuman</dt><dd id="announcementText"></dd></div>
            <div id="infoMurottal" hidden><dt>Murottal</dt><dd id="murottalTitle"></dd></div>
            <div id="infoFasting" hidden><dt id="fastingWhen">Puasa sunnah hari ini</dt><dd id="fastingLabel"></dd></div>
        </dl>
    </aside>
</div>

<!-- Poster landscape: memenuhi layar, jam & sholat berikutnya tetap terlihat di strip bawah. -->
<div class="poster-full" id="posterFull" hidden>
    <div class="poster-full-img"><img id="posterFullImg" alt=""></div>
    <div class="strip">
        <span class="strip-clock">--:--</span>
        <span class="strip-date"></span>
        <span class="strip-next"></span>
    </div>
</div>

<!-- Foto masjid sebagai slide layar penuh (DECISIONS.md #28). -->
<div class="poster-full photo-full" id="photoFull" hidden>
    <div class="poster-full-img">
        <img id="photoFullImg" alt="Foto masjid">
        <div class="photo-title"><small>Selamat datang di</small><strong id="photoName">Masjid</strong></div>
    </div>
    <div class="strip">
        <span class="strip-clock">--:--</span>
        <span class="strip-date"></span>
        <span class="strip-next"></span>
    </div>
</div>

<div class="overlay" id="reminder" hidden>
    <div class="overlay-inner">
        <div class="overlay-sub">Adzan <b id="reminderPrayer">-</b> dalam</div>
        <div class="overlay-count" id="reminderCount">--:--</div>
        <div class="overlay-bar"><i id="reminderBar"></i></div>
        <div class="overlay-sub" id="reminderMessage"></div>
    </div>
</div>

<!-- Hitung mundur iqomah setelah layar adzan; bisa dimatikan takmir (DECISIONS.md #31). -->
<div class="overlay" id="iqomah" hidden>
    <div class="overlay-inner" id="iqomahCountdown">
        <div class="overlay-sub">Iqomah <b id="iqomahPrayer">-</b> dalam</div>
        <div class="overlay-count" id="iqomahCount">--:--</div>
        <div class="overlay-bar"><i id="iqomahBar"></i></div>
        <div class="overlay-sub">Mari mengisi shaf terdepan</div>
    </div>
    <div class="overlay-inner" id="iqomahNow" hidden>
        <div class="overlay-ar">قَدْ قَامَتِ الصَّلَاةُ</div>
        <div class="overlay-title">Luruskan dan rapatkan shaf</div>
        <div class="overlay-sub">Mohon matikan atau senyapkan HP</div>
    </div>
</div>

<div class="overlay" id="adzan" hidden>
    <div class="overlay-inner">
        <div class="overlay-ar">حَيَّ عَلَى الصَّلَاةِ</div>
        <div class="overlay-title" id="adzanTitle">Adzan</div>
        <div class="overlay-sub" id="adzanTime">-</div>
    </div>
</div>

<script src="/assets/js/display.js?v=<?= filemtime(__DIR__ . '/assets/js/display.js') ?>"></script>
</body>
</html>
