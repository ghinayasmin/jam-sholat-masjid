(function () {
    'use strict';

    // REQ-NF-101: jam berjalan update tiap 1 detik, transisi slide <=1 detik.
    const SCHEDULE_POLL_MS = 60 * 1000;       // jadwal jarang berubah — cukup tiap 1 menit
    const CONTENT_POLL_MS = 5 * 60 * 1000;    // publikasi & keuangan — tiap 5 menit
    const SLIDE_INTERVAL_MS = 8000;

    let scheduleData = null;
    let slides = [];
    let slideIndex = 0;
    let slideTimer = null;

    function pad(n) { return String(n).padStart(2, '0'); }

    function tickClock() {
        const now = new Date();
        document.getElementById('clock').textContent =
            pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
        document.getElementById('gregorianDate').textContent = now.toLocaleDateString('id-ID', {
            weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
        });
        highlightCurrentPrayer(now);
    }

    function highlightCurrentPrayer(now) {
        if (!scheduleData) return;
        const order = ['fajr', 'sunrise', 'dhuhr', 'asr', 'maghrib', 'isha'];
        const nowMinutes = now.getHours() * 60 + now.getMinutes();

        let currentKey = null;
        for (const key of order) {
            const [h, m] = (scheduleData[key] || '00:00').split(':').map(Number);
            const minutes = h * 60 + m;
            if (nowMinutes >= minutes) {
                currentKey = key;
            }
        }
        document.querySelectorAll('.prayer-item').forEach((el) => {
            el.classList.toggle('current', el.dataset.key === currentKey);
        });
    }

    async function fetchJson(url) {
        const res = await fetch(url, { cache: 'no-store' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    async function loadSchedule() {
        try {
            const data = await fetchJson('/api/schedule.php');
            if (!data.success) return;
            scheduleData = data;

            document.getElementById('mosqueName').textContent = data.mosque_name || 'Masjid';
            document.getElementById('hijriDate').textContent = data.hijri_date;

            document.querySelectorAll('.prayer-item').forEach((el) => {
                const key = el.dataset.key;
                const valueEl = el.querySelector('.prayer-value');
                if (valueEl && data[key]) valueEl.textContent = data[key];
            });

            const banner = document.getElementById('fastingBanner');
            if (data.is_senin_kamis || data.is_ayyamul_bidh) {
                const labels = [];
                if (data.is_senin_kamis) labels.push('Senin/Kamis');
                if (data.is_ayyamul_bidh) labels.push("Ayyamul Bidh");
                document.getElementById('fastingLabel').textContent = labels.join(' & ');
                banner.hidden = false;
            } else {
                banner.hidden = true;
            }
        } catch (e) {
            console.error('Gagal memuat jadwal:', e);
        }
    }

    async function loadPublications() {
        try {
            const data = await fetchJson('/api/publications.php');
            if (!data.success) return;
            slides = data.items || [];
            renderSlideshow();
        } catch (e) {
            console.error('Gagal memuat publikasi:', e);
        }
    }

    function renderSlideshow() {
        const container = document.getElementById('slideshow');
        const empty = document.getElementById('slideshowEmpty');
        container.querySelectorAll('img').forEach((img) => img.remove());

        if (slides.length === 0) {
            empty.style.display = 'flex';
            return;
        }
        empty.style.display = 'none';

        slides.forEach((slide, i) => {
            const img = document.createElement('img');
            img.src = slide.url;
            img.alt = slide.caption || '';
            if (i === 0) img.classList.add('active');
            container.appendChild(img);
        });

        slideIndex = 0;
        if (slideTimer) clearInterval(slideTimer);
        if (slides.length > 1) {
            slideTimer = setInterval(advanceSlide, SLIDE_INTERVAL_MS);
        }
    }

    function advanceSlide() {
        const imgs = document.querySelectorAll('.slideshow img');
        if (imgs.length === 0) return;
        imgs[slideIndex].classList.remove('active');
        slideIndex = (slideIndex + 1) % imgs.length;
        imgs[slideIndex].classList.add('active');
    }

    async function loadFinancial() {
        try {
            const data = await fetchJson('/api/financial.php');
            if (!data.success) return;

            document.getElementById('financialBalance').textContent =
                'Rp ' + Number(data.saldo_berjalan).toLocaleString('id-ID');

            const list = document.getElementById('financialList');
            list.innerHTML = '';
            (data.entries || []).forEach((entry) => {
                const li = document.createElement('li');
                const sign = entry.type === 'masuk' ? '+' : '-';
                const cls = entry.type === 'masuk' ? 'amount-masuk' : 'amount-keluar';
                li.innerHTML =
                    '<span>' + entry.date + ' — ' + escapeHtml(entry.description) + '</span>' +
                    '<span class="' + cls + '">' + sign + 'Rp ' + Number(entry.amount).toLocaleString('id-ID') + '</span>';
                list.appendChild(li);
            });
        } catch (e) {
            console.error('Gagal memuat laporan keuangan:', e);
        }
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Init
    tickClock();
    setInterval(tickClock, 1000);

    loadSchedule();
    loadPublications();
    loadFinancial();

    setInterval(loadSchedule, SCHEDULE_POLL_MS);
    setInterval(loadPublications, CONTENT_POLL_MS);
    setInterval(loadFinancial, CONTENT_POLL_MS);
})();
