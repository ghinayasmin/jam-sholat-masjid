// Interaksi kecil panel takmir. Semua formulir tetap berfungsi tanpa JavaScript;
// skrip ini hanya mempermudah pengisian di HP.
(function () {
    'use strict';

    // Tombol − / + di samping angka.
    document.querySelectorAll('.stepper').forEach((stepper) => {
        const input = stepper.querySelector('input');
        stepper.querySelectorAll('button[data-step]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const step = Number(btn.dataset.step);
                const min = input.min === '' ? -Infinity : Number(input.min);
                const max = input.max === '' ? Infinity : Number(input.max);
                const next = Math.min(max, Math.max(min, (Number(input.value) || 0) + step));
                input.value = next;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
    });

    // Nominal cepat & format ribuan pada kolom rupiah.
    document.querySelectorAll('[data-money]').forEach((input) => {
        const format = () => {
            const digits = input.value.replace(/\D/g, '');
            input.value = digits ? Number(digits).toLocaleString('id-ID') : '';
            input.dispatchEvent(new CustomEvent('money-change', { bubbles: true }));
        };
        input.addEventListener('input', format);
        document.querySelectorAll('[data-quick]').forEach((btn) => {
            btn.addEventListener('click', () => {
                input.value = btn.dataset.quick;
                format();
                input.focus();
            });
        });
    });

    // Saldo setelah disimpan, dihitung langsung saat mengetik.
    const balanceNote = document.querySelector('[data-balance-after]');
    if (balanceNote) {
        const base = Number(balanceNote.dataset.balanceAfter);
        const sign = Number(balanceNote.dataset.sign);
        const out = balanceNote.querySelector('b');
        const input = document.querySelector('[data-money]');
        const update = () => {
            const amount = Number((input.value || '').replace(/\D/g, '')) || 0;
            out.textContent = 'Rp ' + (base + sign * amount).toLocaleString('id-ID');
        };
        input.addEventListener('money-change', update);
        update();
    }

    // Pratinjau foto poster sebelum diunggah.
    document.querySelectorAll('.drop input[type=file]').forEach((input) => {
        const drop = input.closest('.drop');
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            if (!file) return;
            drop.classList.add('has-file');
            let img = drop.querySelector('img');
            if (!img) {
                img = document.createElement('img');
                img.alt = 'Pratinjau poster';
                drop.prepend(img);
            }
            img.src = URL.createObjectURL(file);
            drop.querySelector('b').textContent = file.name;
            drop.querySelector('small').textContent = 'Ketuk untuk mengganti foto';
            drop.querySelector('.icon') && drop.querySelector('.icon').remove();
        });
    });

    // Ubah pengumuman: gambar pengganti langsung tampil di kartu poster saat ini.
    document.querySelectorAll('.current-poster input[type=file]').forEach((input) => {
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            if (file) input.closest('.current-poster').querySelector('img').src = URL.createObjectURL(file);
        });
    });

    // Kode QR alamat panel (halaman Buka panel dari HP, #40), dibuat di perangkat tanpa internet.
    document.querySelectorAll('[data-qr]').forEach((box) => {
        if (typeof window.qrcode !== 'function') return;
        const qr = window.qrcode(0, 'M');
        qr.addData(box.dataset.qr);
        qr.make();
        box.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
    });
    document.querySelectorAll('[data-print]').forEach((btn) => btn.addEventListener('click', () => window.print()));

    // Pratinjau pengingat adzan mengikuti angka & pesan.
    const preview = document.querySelector('[data-reminder-preview]');
    if (preview) {
        const minutes = document.getElementById('reminder_minutes');
        const message = document.getElementById('reminder_message');
        const update = () => {
            preview.querySelector('.b').textContent = String(Number(minutes.value) || 0).padStart(2, '0') + ':00';
            preview.querySelector('.c').textContent = message.value || ' ';
        };
        minutes.addEventListener('input', update);
        message.addEventListener('input', update);
    }

    // Sakelar yang langsung tersimpan saat diubah.
    // data-confirm-change: tanya dulu (mis. pilihan yang menghapus data); batal = kembali ke pilihan semula.
    document.querySelectorAll('[data-autosubmit]').forEach((input) => {
        const initial = input.form.querySelector('[data-autosubmit]:checked');
        input.addEventListener('change', () => {
            if (input.dataset.confirmChange && !window.confirm(input.dataset.confirmChange)) {
                if (initial) initial.checked = true;
                return;
            }
            input.form.requestSubmit(); // lewat handler fetch di bawah
        });
    });

    // Kolom cari yang menyaring daftar (mis. Pilih surat). Tanda baca diabaikan
    // supaya "yasin", "Ya-Sin", dan "al baqarah" sama-sama ketemu.
    const plain = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, '');
    document.querySelectorAll('input[data-filter]').forEach((input) => {
        const items = document.querySelectorAll(input.dataset.filter + ' [data-filter-text]');
        input.addEventListener('input', () => {
            const q = plain(input.value);
            items.forEach((el) => { el.hidden = q !== '' && !plain(el.dataset.filterText).includes(q); });
        });
    });

    // Pilih kabupaten/kota masjid (#42): mengisi nama lokasi dan koordinat. Daftar baru
    // dimuat saat kolom dipakai; Enter memilih hasil teratas, bukan mengirim formulir.
    const city = document.querySelector('[data-city-search]');
    if (city) {
        const form = city.form;
        const results = form.querySelector('[data-city-results]');
        const empty = form.querySelector('[data-city-empty]');
        const chosen = form.querySelector('[data-city-chosen]');
        const bare = (n) => plain(n.replace(/^(Kabupaten|Kota)( Administrasi)? /, ''));
        let cities = null;
        const load = async () => {
            if (cities === null) {
                cities = [];
                try {
                    cities = await (await fetch(city.dataset.citySearch)).json();
                } catch (err) {
                    cities = null; // coba lagi saat mengetik berikutnya
                }
            }
            return cities || [];
        };
        const pick = (c) => {
            const name = `${c.n}, ${c.p}`.slice(0, 60);
            form.elements.location_name.value = name;
            form.elements.latitude.value = c.la;
            form.elements.longitude.value = c.lo;
            chosen.querySelector('[data-city-name]').textContent = name;
            chosen.hidden = false;
            city.value = '';
            results.hidden = true;
            empty.hidden = true;
        };
        let shown = [];
        const search = async () => {
            const q = plain(city.value);
            const list = q.length < 2 ? [] : await load();
            // Nama yang diawali kata yang diketik tampil lebih dulu ("bantul" sebelum "...bantul...").
            shown = list.filter((c) => plain(`${c.n} ${c.p}`).includes(q))
                .sort((a, b) => (bare(b.n).startsWith(q) ? 1 : 0) - (bare(a.n).startsWith(q) ? 1 : 0))
                .slice(0, 8);
            results.replaceChildren(...shown.map((c) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'row ctl';
                const k = document.createElement('span');
                k.className = 'k';
                k.textContent = c.n;
                const small = document.createElement('small');
                small.textContent = c.p;
                k.append(small);
                const v = document.createElement('span');
                v.className = 'v';
                v.textContent = 'Pilih';
                b.append(k, v);
                b.addEventListener('click', () => pick(c));
                return b;
            }));
            results.hidden = shown.length === 0;
            empty.hidden = q.length < 2 || shown.length > 0;
        };
        city.addEventListener('input', search);
        city.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            if (shown.length > 0) pick(shown[0]);
        });
        // "-7.68, 110.32" dari Google Maps ditempel sekaligus di kolom garis lintang.
        const lat = form.elements.latitude;
        lat.addEventListener('input', () => {
            const m = lat.value.match(/^\s*(-?\d{1,3}\.\d+)\s*[,;]?\s+(-?\d{1,3}\.\d+)\s*$/) ||
                lat.value.match(/^\s*(-?\d{1,3}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)\s*$/);
            if (m) {
                lat.value = m[1];
                form.elements.longitude.value = m[2];
            }
        });
    }

    // Pemutar murottal (#40): progres berjalan tiap detik, dan keadaan dari layanan
    // alarm (pindah surat, jeda, jendela diam) dibaca ulang tiap 3 detik.
    const live = document.querySelector('[data-murottal-live]');
    if (live) {
        const q = (sel) => live.querySelector(sel);
        const tracks = document.querySelectorAll('button.track[data-title]');
        const clock = (t) => String(Math.floor(t / 60)).padStart(2, '0') + ':' + String(Math.floor(t % 60)).padStart(2, '0');
        let pos = Number(live.dataset.position) || 0;
        let duration = Number(live.dataset.duration) || 0;
        let playing = live.dataset.playing === '1';
        const enabledAtLoad = Boolean(live.querySelector('input[name=action][value=pause]'));
        const paint = () => {
            if (q('[data-mp-pos]')) q('[data-mp-pos]').textContent = clock(pos);
            if (q('[data-mp-fill]') && duration) q('[data-mp-fill]').style.width = Math.min(100, pos / duration * 100) + '%';
            // Tombol saat dijeda ikut posisi terakhir yang disimpan layanan alarm.
            if (q('[data-mp-resume]')) q('[data-mp-resume]').textContent = pos > 0 ? 'Lanjutkan dari ' + clock(pos) : 'Putar';
        };
        const refresh = async () => {
            try {
                const data = await (await fetch('/api/murottal.php', { cache: 'no-store' })).json();
                if (!data.success) return;
                if (data.enabled !== enabledAtLoad) { location.reload(); return; }  // Putar/Jeda dari HP lain
                const p = data.player;
                if (p) {
                    pos = p.position; duration = p.duration || 0; playing = p.playing;
                    if (q('[data-mp-title]')) q('[data-mp-title]').textContent = 'Surah ' + p.title;
                    if (q('[data-mp-number]')) q('[data-mp-number]').textContent = p.number;
                    if (q('[data-mp-state]')) q('[data-mp-state]').textContent = playing ? 'Sedang diputar' : (data.enabled ? 'Memulai' : (pos > 0 ? 'Dijeda' : 'Berhenti'));
                    paint();
                }
                const title = data.now_playing;
                if (title && q('[data-murottal-status]')) q('[data-murottal-status]').textContent = 'Sedang diputar: ' + title;
                tracks.forEach((btn) => {
                    const on = p !== null && p !== undefined && btn.dataset.title === p.title && (data.enabled || pos > 0);
                    btn.classList.toggle('playing', on);
                    btn.toggleAttribute('aria-current', on);
                    const v = btn.querySelector('.v');
                    v.textContent = on ? (data.enabled ? 'Diputar' : 'Dijeda') : 'Putar';
                    v.classList.toggle('ok', on);
                });
            } catch (err) { /* jaringan putus sebentar: coba lagi di putaran berikutnya */ }
        };
        refresh();
        // Halaman bisa diganti lewat document.write (lihat pengiriman formulir di bawah)
        // tanpa membuang timer lama, jadi simpan di window agar tidak menumpuk.
        clearInterval(window.murottalTimer);
        clearInterval(window.murottalTick);
        window.murottalTimer = setInterval(() => { if (!document.hidden) refresh(); }, 3000);
        window.murottalTick = setInterval(() => { if (playing && (!duration || pos < duration)) { pos += 1; paint(); } }, 1000);
    }

    // Konfirmasi sebelum tindakan yang menghapus/menimpa data.
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (!window.confirm(form.dataset.confirm)) e.preventDefault();
        });
    });

    // Kirim formulir POST lewat fetch. Panel dibuka lewat http:// di jaringan lokal,
    // dan Chrome di HP menampilkan peringatan "not secure" setiap kali formulir
    // dikirim biasa. Hasilnya sama dengan pengiriman biasa.
    document.addEventListener('submit', async (e) => {
        const form = e.target;
        // getAttribute, bukan form.action/form.method: formulir panel punya kolom
        // bernama "action" yang menutupi properti itu.
        if (e.defaultPrevented || (form.getAttribute('method') || '').toLowerCase() !== 'post') return;
        e.preventDefault();
        const body = new FormData(form);
        if (e.submitter && e.submitter.name) body.append(e.submitter.name, e.submitter.value);
        form.querySelectorAll('button').forEach((b) => { b.disabled = true; });
        try {
            const url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
            const res = await fetch(url, { method: 'POST', body, credentials: 'same-origin' });
            // Tampilkan halaman yang sudah diterima (memuat ulang akan menghabiskan pesan
            // sukses sekali-tampil), lalu samakan alamat di bilah alamat.
            const html = await res.text();
            if (res.redirected) window.history.replaceState(null, '', res.url);
            document.open();
            document.write(html);
            document.close();
        } catch (err) {
            HTMLFormElement.prototype.submit.call(form); // jaringan putus: kirim cara biasa
        }
    });

    // Hitung mundur "x jam y menit lagi" di Beranda berjalan sendiri. Saat waktu sholat
    // tiba, halaman dimuat ulang agar sholat berikutnya dan jadwal yang lewat ikut berganti.
    const countdown = document.querySelector('[data-countdown]');
    if (countdown) {
        const endsAt = Date.now() + Number(countdown.dataset.countdown) * 1000;
        const tick = () => {
            const minutes = Math.ceil((endsAt - Date.now()) / 60000);
            if (minutes <= 0) { location.reload(); return; }
            const h = Math.floor(minutes / 60), m = minutes % 60;
            countdown.textContent = (h ? h + ' jam' + (m ? ' ' + m + ' menit' : '') : m + ' menit') + ' lagi';
        };
        setInterval(tick, 15000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
    }

    // Jam perangkat belum tersinkron (listrik padam tanpa internet): cocokkan
    // dengan jam HP ini, lalu muat ulang agar jam yang tampil sudah benar.
    const clockSync = document.querySelector('[data-clock-sync]');
    if (clockSync) {
        const body = new FormData();
        body.append('csrf_token', clockSync.dataset.csrf);
        // Detik, bukan milidetik: PHP di Pi 32-bit, milidetik melebihi batas bilangan bulatnya.
        body.append('epoch', String(Math.floor(Date.now() / 1000)));
        fetch('/admin/clock_sync.php', { method: 'POST', body, credentials: 'same-origin' })
            .then((res) => res.json())
            .then((data) => { if (data.changed) window.location.reload(); })
            .catch(() => {});
    }
})();
