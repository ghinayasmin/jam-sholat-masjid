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
            <div id="infoFinance" hidden><dt>Kas masjid</dt><dd id="financeBalance"></dd></div>
        </dl>
    </aside>
</div>

<!-- Poster landscape: memenuhi layar, jam & sholat berikutnya tetap terlihat di strip bawah. -->
<div class="poster-full" id="posterFull" hidden>
    <div class="poster-full-img"><img id="posterFullImg" alt=""></div>
    <div class="strip">
        <span class="strip-clock" id="stripClock">--:--</span>
        <span class="strip-date" id="stripDate"></span>
        <span class="strip-next" id="stripNext"></span>
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
