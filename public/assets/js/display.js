(function (root) {
    'use strict';

    // REQ-NF-101: jam diperbarui tiap 1 detik.
    const SCHEDULE_POLL_MS = 60 * 1000;      // pengaturan (pengingat, nama masjid) bisa berubah dari admin
    const CONTENT_POLL_MS = 60 * 1000;       // pengumuman & kas
    const MUROTTAL_POLL_MS = 5 * 1000;       // surat berganti tiap beberapa menit, Putar/Jeda harus cepat terlihat
    const ANNOUNCEMENT_ROTATE_MS = 10 * 1000;
    const WIDE_POSTER_RATIO = 1.3;           // lebar/tinggi ≥ ini = landscape → layar penuh
    const ADZAN_SCREEN_S = 180;              // layar adzan tampil 3 menit sejak waktu adzan
    const IQOMAH_NOW_S = 60;                 // "Luruskan dan rapatkan shaf" tampil 1 menit saat iqomah

    const ADZAN_KEYS = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'];
    const ROW_KEYS = ['fajr', 'sunrise', 'dhuhr', 'asr', 'maghrib', 'isha'];
    const NAMES = { fajr: 'Subuh', sunrise: 'Terbit', dhuhr: 'Dzuhur', asr: 'Ashar', maghrib: 'Maghrib', isha: 'Isya' };
    const DAY_NAMES = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    function pad(n) { return String(n).padStart(2, '0'); }
    function toSeconds(hhmm) {
        const [h, m] = String(hhmm || '00:00').split(':').map(Number);
        return h * 3600 + m * 60;
    }
    function localDate(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function addDays(d, n) { const c = new Date(d.getTime()); c.setDate(c.getDate() + n); return c; }
    function dayOf(dateStr) {
        const [y, m, d] = dateStr.split('-').map(Number);
        return new Date(y, m - 1, d).getDay();
    }
    function prayerName(key, isFriday) { return key === 'dhuhr' && isFriday ? 'Jumat' : NAMES[key]; }

    /**
     * Inti logika layar (tanpa DOM, diuji di tests/unit/test_display_logic.js).
     * now: Date lokal. today/tomorrow: jadwal dari /api/schedule.php. reminder: pengaturan pengingat.
     * iqomah: { countdown: bool, minutes: { fajr: 20, ... } } — hitung mundur iqomah (DECISIONS.md #31).
     */
    function computeView(now, today, tomorrow, reminder, iqomah) {
        const sec = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
        const isFriday = now.getDay() === 5;

        let next = null;
        for (const key of ADZAN_KEYS) {
            const at = toSeconds(today[key]);
            if (at > sec) { next = { key, time: today[key], at, tomorrow: false }; break; }
        }
        if (!next && tomorrow) {
            next = { key: 'fajr', time: tomorrow.fajr, at: toSeconds(tomorrow.fajr) + 86400, tomorrow: true };
        }

        let adzan = null;
        let iqomahView = null;
        for (const key of ADZAN_KEYS) {
            const at = toSeconds(today[key]);
            if (sec >= at && sec < at + ADZAN_SCREEN_S) adzan = { key, time: today[key] };
            // Hitung mundur iqomah setelah layar adzan. Jumat siang tidak: iqomah setelah khutbah.
            const minutes = iqomah && iqomah.countdown && iqomah.minutes ? Number(iqomah.minutes[key]) : 0;
            if (minutes > 0 && !(key === 'dhuhr' && isFriday)) {
                const iqomahAt = at + minutes * 60;
                if (sec >= at + ADZAN_SCREEN_S && sec < iqomahAt) {
                    iqomahView = { key, now: false, remaining: iqomahAt - sec, total: iqomahAt - at - ADZAN_SCREEN_S };
                } else if (sec >= iqomahAt && sec < iqomahAt + IQOMAH_NOW_S) {
                    iqomahView = { key, now: true, remaining: 0, total: 0 };
                }
            }
        }

        // Setelah Isya, daftar menampilkan jadwal besok dengan Subuh sebagai berikutnya.
        const listDay = next && next.tomorrow ? 'tomorrow' : 'today';
        const rows = {};
        ROW_KEYS.forEach((key) => {
            rows[key] = listDay === 'today' && toSeconds(today[key]) <= sec ? 'past' : '';
        });
        if (next) rows[next.key] = 'next';

        const remaining = next ? next.at - sec : null;
        const fridayDhuhr = Boolean(next && !next.tomorrow && next.key === 'dhuhr' && isFriday);
        const lead = (fridayDhuhr ? reminder.friday_minutes : reminder.minutes) * 60;

        let mode = 'normal';
        if (adzan) mode = 'adzan';
        else if (iqomahView) mode = 'iqomah';
        else if (reminder.enabled && next && lead > 0 && remaining <= lead) mode = 'reminder';

        return { mode, next, adzan, iqomah: iqomahView, remaining, lead, rows, listDay, isFriday, fridayDhuhr };
    }

    /** "12 menit", "1 jam 5 menit", "kurang dari 1 menit". */
    function formatDuration(seconds) {
        const minutes = Math.ceil(seconds / 60);
        if (seconds < 60) return 'kurang dari 1 menit';
        if (minutes < 60) return minutes + ' menit';
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        return h + ' jam' + (m ? ' ' + m + ' menit' : '');
    }

    function formatCountdown(seconds) {
        const s = Math.max(0, seconds);
        return pad(Math.floor(s / 60)) + ':' + pad(s % 60);
    }

    const MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
        'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /**
     * Baris "puasa sunnah" di layar: hari ini, besok, atau yang terdekat.
     * upcoming: daftar dari API (mulai hari ini). Setelah Maghrib, hari ini tidak dihitung lagi.
     * Mengembalikan { title, value } atau null bila tidak ada dalam daftar.
     */
    function fastingRow(upcoming, todayStr, tomorrowStr, afterMaghrib) {
        const entry = (upcoming || []).find((f) => f.date > todayStr || (f.date === todayStr && !afterMaghrib));
        if (!entry) return null;
        const day = DAY_NAMES[dayOf(entry.date)];
        const kinds = [];
        if (entry.is_senin_kamis) kinds.push('Puasa ' + day);
        if (entry.is_ayyamul_bidh) kinds.push('Ayyamul Bidh');
        if (entry.date === todayStr) return { title: 'Puasa sunnah hari ini', value: kinds.join(' & ') };
        if (entry.date === tomorrowStr) return { title: 'Puasa sunnah besok', value: kinds.join(' & ') };
        const [, m, d] = entry.date.split('-').map(Number);
        const when = day + ', ' + d + ' ' + MONTH_NAMES[m - 1];
        return {
            title: 'Puasa sunnah terdekat',
            value: when + (entry.is_ayyamul_bidh ? ' · Ayyamul Bidh' : ''),
        };
    }

    /**
     * Slide (poster, kas, foto masjid) bergantian dengan layar jam: jam tampil gapS detik,
     * lalu slide tampil showS detik, berulang. Diatur takmir di admin (DECISIONS.md #27).
     */
    function slideVisible(epochSeconds, gapS, showS) {
        const gap = Math.max(1, Number(gapS) || 15);
        const show = Math.max(1, Number(showS) || 15);
        return epochSeconds % (gap + show) >= gap;
    }

    /** "12.450.000" (tanpa "Rp"); angka negatif diberi tanda minus. */
    function formatRupiah(amount) {
        const n = Number(amount) || 0;
        return (n < 0 ? '−' : '') + Math.abs(n).toLocaleString('id-ID');
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { computeView, formatDuration, formatCountdown, fastingRow, slideVisible, formatRupiah };
        return;
    }

    // ------------------------------------------------------------------ DOM

    const $ = (id) => document.getElementById(id);

    // Mode simulasi untuk uji coba & demo: display.php?jam=17:28 menjalankan jam
    // seolah-olah sekarang pukul 17:28 (tanggal tetap hari ini).
    const simulated = /^(\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec(new URLSearchParams(location.search).get('jam') || '');
    let clockOffsetMs = 0;
    if (simulated) {
        const target = new Date();
        target.setHours(Number(simulated[1]), Number(simulated[2]), Number(simulated[3] || 0), 0);
        clockOffsetMs = target.getTime() - Date.now();
    }
    function currentTime() { return new Date(Date.now() + clockOffsetMs); }
    const state = {
        loadedDate: null,
        today: null,
        tomorrow: null,
        reminder: { enabled: false, minutes: 10, friday_minutes: 30, message: '' },
        iqomah: { countdown: false, minutes: {} },
        showFinance: false,
        finance: null,           // data slide kas; null bila disembunyikan takmir
        mosquePhoto: null,       // URL foto masjid; null bila belum diunggah
        slideTiming: { gap_seconds: 15, show_seconds: 15 },
        posters: [],
        captions: [],
        slideIndex: 0,
        currentSlide: null,      // { kind: 'poster'|'finance'|'photo', item? } yang sedang tampil
        captionIndex: 0,
    };

    async function fetchJson(url) {
        const res = await fetch(url, { cache: 'no-store' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    async function loadSchedule() {
        const now = currentTime();
        try {
            const [today, tomorrow] = await Promise.all([
                fetchJson('/api/schedule.php?date=' + localDate(now)),
                fetchJson('/api/schedule.php?date=' + localDate(addDays(now, 1))),
            ]);
            if (!today.success) return;
            state.today = today;
            state.tomorrow = tomorrow.success ? tomorrow : null;
            state.loadedDate = localDate(now);
            state.reminder = today.reminder || state.reminder;
            state.iqomah = { countdown: Boolean(today.iqomah_countdown), minutes: today.iqomah_minutes || {} };

            const financeWasOn = state.showFinance;
            state.showFinance = Boolean(today.show_finance);
            if (state.showFinance !== financeWasOn) loadFinance();

            state.slideTiming = today.slides || state.slideTiming;
            if (today.mosque_photo !== state.mosquePhoto) {
                state.mosquePhoto = today.mosque_photo || null;
                if (state.mosquePhoto) new Image().src = state.mosquePhoto; // muat lebih dulu
            }

            $('mosqueName').textContent = today.mosque_name || 'Masjid';
            $('photoName').textContent = today.mosque_name || 'Masjid';
            $('mosqueAddress').textContent = today.mosque_address || '';
            render();
        } catch (e) {
            console.error('Gagal memuat jadwal:', e);
        }
    }

    async function loadPublications() {
        try {
            const data = await fetchJson('/api/publications.php');
            if (!data.success) return;
            const items = data.items || [];
            state.posters = items.filter((p) => p.url);
            state.captions = items.map((p) => (p.caption || '').trim()).filter(Boolean);
            // Muat lebih dulu, sekaligus catat orientasi: landscape tampil layar penuh.
            state.posters.forEach((p) => {
                const img = new Image();
                img.onload = () => { p.wide = img.naturalWidth / img.naturalHeight >= WIDE_POSTER_RATIO; };
                img.src = p.url;
            });
            renderAnnouncement();
        } catch (e) {
            console.error('Gagal memuat pengumuman:', e);
        }
    }

    async function loadMurottal() {
        try {
            const data = await fetchJson('/api/murottal.php');
            const title = data.success ? data.now_playing : null;
            $('infoMurottal').hidden = !title;
            if (title) $('murottalTitle').textContent = /^surah\b/i.test(title) ? title : 'Surah ' + title;
        } catch (e) {
            $('infoMurottal').hidden = true;
        }
    }

    async function loadFinance() {
        if (!state.showFinance) { state.finance = null; return; }
        try {
            const data = await fetchJson('/api/financial.php');
            if (!data.success || !data.enabled) { state.finance = null; return; }
            state.finance = data;
            const total = formatRupiah(data.saldo_berjalan);
            $('kasTotal').textContent = total;
            $('kasTotal').classList.toggle('long', total.length > 10); // ≥ Rp 100 juta: perkecil agar muat
            $('kasInLabel').textContent = 'Pemasukan ' + data.bulan;
            $('kasOutLabel').textContent = 'Pengeluaran ' + data.bulan;
            $('kasIn').textContent = '+ Rp ' + formatRupiah(data.masuk_bulan_ini);
            $('kasOut').textContent = '− Rp ' + formatRupiah(data.keluar_bulan_ini);
            // Angka ≥ Rp 1 miliar diperkecil agar tidak menabrak labelnya.
            const longRow = $('kasIn').textContent.length > 17 || $('kasOut').textContent.length > 17;
            $('kasIn').classList.toggle('long', longRow);
            $('kasOut').classList.toggle('long', longRow);
            let updated = 'Belum ada transaksi';
            if (data.diperbarui) {
                const [y, m, d] = data.diperbarui.split('-').map(Number);
                updated = 'Diperbarui ' + d + ' ' + MONTH_NAMES[m - 1] + ' ' + y;
            }
            $('kasUpdated').textContent = updated;
        } catch (e) {
            console.error('Gagal memuat kas:', e);
        }
    }

    function renderAnnouncement() {
        const row = $('infoAnnouncement');
        if (state.captions.length === 0) { row.hidden = true; return; }
        state.captionIndex %= state.captions.length;
        $('announcementText').textContent = state.captions[state.captionIndex];
        row.hidden = false;
    }

    function render() {
        const now = currentTime();
        $('clock').textContent = pad(now.getHours()) + ':' + pad(now.getMinutes());
        $('gregorianDate').textContent = now.toLocaleDateString('id-ID', {
            weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
        });

        if (state.loadedDate && state.loadedDate !== localDate(now)) {
            loadSchedule(); // ganti hari
            return;
        }
        if (!state.today) return;

        const view = computeView(now, state.today, state.tomorrow, state.reminder, state.iqomah);
        // Hari dalam kalender Hijriah dimulai saat Maghrib.
        const hijriAfterMaghrib = state.tomorrow && now.getHours() * 3600 + now.getMinutes() * 60 >= toSeconds(state.today.maghrib);
        $('hijriDate').textContent = hijriAfterMaghrib ? state.tomorrow.hijri_date : state.today.hijri_date;
        const list = view.listDay === 'tomorrow' ? state.tomorrow : state.today;
        const listFriday = view.listDay === 'tomorrow' ? dayOf(state.tomorrow.date) === 5 : view.isFriday;

        $('listCaption').hidden = view.listDay !== 'tomorrow';
        document.querySelectorAll('#prayers li').forEach((li) => {
            const key = li.dataset.key;
            li.className = view.rows[key];
            li.querySelector('.p-time').textContent = list[key];
            if (key === 'dhuhr') {
                li.querySelector('.p-name').textContent = prayerName('dhuhr', listFriday);
                li.querySelector('.p-ar').textContent = listFriday ? 'الجمعة' : 'الظهر';
            }
        });

        if (view.next) {
            const name = prayerName(view.next.key, view.fridayDhuhr);
            $('countdownValue').textContent = formatDuration(view.remaining);
            $('countdownText').innerHTML = 'menuju adzan ' + name + (view.next.tomorrow ? ' besok' : '') +
                '<br>pukul ' + view.next.time;
        }

        // Puasa sunnah terdekat. Setelah Maghrib, puasa hari ini sudah selesai.
        const afterMaghrib = now.getHours() * 3600 + now.getMinutes() * 60 >= toSeconds(state.today.maghrib);
        const fasting = fastingRow(state.today.upcoming_fasting, state.today.date,
            state.tomorrow ? state.tomorrow.date : localDate(addDays(now, 1)), afterMaghrib);
        $('infoFasting').hidden = !fasting;
        if (fasting) {
            $('fastingWhen').textContent = fasting.title;
            $('fastingLabel').textContent = fasting.value;
        }

        $('reminder').hidden = view.mode !== 'reminder';
        $('adzan').hidden = view.mode !== 'adzan';
        $('iqomah').hidden = view.mode !== 'iqomah';
        if (view.mode === 'reminder') {
            $('reminderPrayer').textContent = prayerName(view.next.key, view.fridayDhuhr);
            $('reminderCount').textContent = formatCountdown(view.remaining);
            $('reminderBar').style.width = Math.max(0, Math.min(100, view.remaining / view.lead * 100)) + '%';
            $('reminderMessage').textContent = view.fridayDhuhr ? 'Persiapan sholat Jumat' : state.reminder.message;
        } else if (view.mode === 'iqomah') {
            const iq = view.iqomah;
            $('iqomahCountdown').hidden = iq.now;
            $('iqomahNow').hidden = !iq.now;
            if (!iq.now) {
                $('iqomahPrayer').textContent = prayerName(iq.key, false);
                $('iqomahCount').textContent = formatCountdown(iq.remaining);
                $('iqomahBar').style.width = Math.max(0, Math.min(100, iq.remaining / iq.total * 100)) + '%';
            }
        } else if (view.mode === 'adzan') {
            $('adzanTitle').textContent = 'Adzan ' + prayerName(view.adzan.key, view.isFriday);
            $('adzanTime').textContent = view.adzan.time;
        }

        if (view.next) {
            const next = prayerName(view.next.key, view.fridayDhuhr) + ' ' + view.next.time +
                ' · ' + formatDuration(view.remaining) + ' lagi';
            document.querySelectorAll('.strip').forEach((strip) => {
                strip.querySelector('.strip-clock').textContent = $('clock').textContent;
                strip.querySelector('.strip-date').textContent = $('gregorianDate').textContent;
                strip.querySelector('.strip-next').textContent = next;
            });
        }

        renderSlide(now, view.mode);
    }

    /** Urutan slide: poster pengumuman, lalu saldo kas, lalu foto masjid. */
    function slideList() {
        const slides = state.posters.map((item) => ({ kind: 'poster', item }));
        if (state.finance) slides.push({ kind: 'finance' });
        if (state.mosquePhoto) slides.push({ kind: 'photo', item: { url: state.mosquePhoto } });
        return slides;
    }

    function renderSlide(now, mode) {
        const slides = slideList();
        const sinceEpoch = Math.floor(now.getTime() / 1000);
        const show = mode === 'normal' && slides.length > 0 &&
            slideVisible(sinceEpoch, state.slideTiming.gap_seconds, state.slideTiming.show_seconds);
        if (show && !state.currentSlide) {
            const slide = slides[state.slideIndex % slides.length];
            state.slideIndex++;
            state.currentSlide = slide;
            if (slide.kind === 'poster') {
                // Portrait/persegi di panel kiri; landscape memenuhi layar.
                const item = slide.item;
                const img = item.wide ? $('posterFullImg') : $('posterImg');
                img.onerror = () => { state.posters = state.posters.filter((p) => p !== item); state.currentSlide = null; };
                img.src = item.url;
                img.alt = item.caption || 'Pengumuman';
            } else if (slide.kind === 'photo') {
                const img = $('photoFullImg');
                img.onerror = () => { state.mosquePhoto = null; state.currentSlide = null; };
                if (img.getAttribute('src') !== slide.item.url) img.src = slide.item.url;
            }
        }
        // Slide kas berhenti seketika bila takmir menyembunyikannya.
        if (!show || (state.currentSlide && state.currentSlide.kind === 'finance' && !state.finance)) {
            state.currentSlide = null;
        }
        const c = state.currentSlide;
        $('poster').hidden = !(c && c.kind === 'poster' && !c.item.wide);
        $('posterFull').hidden = !(c && c.kind === 'poster' && c.item.wide);
        $('kasSlide').hidden = !(c && c.kind === 'finance');
        $('photoFull').hidden = !(c && c.kind === 'photo');
    }

    render();
    setInterval(render, 1000);

    loadSchedule();
    loadPublications();
    setInterval(loadSchedule, SCHEDULE_POLL_MS);
    setInterval(loadPublications, CONTENT_POLL_MS);
    setInterval(loadFinance, CONTENT_POLL_MS);
    loadMurottal();
    setInterval(loadMurottal, MUROTTAL_POLL_MS);
    setInterval(() => { state.captionIndex++; renderAnnouncement(); }, ANNOUNCEMENT_ROTATE_MS);
})(this);
