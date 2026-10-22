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

    // Status murottal ikut berubah tanpa memuat ulang: layanan alarm butuh beberapa
    // detik untuk pindah surat setelah takmir mengetuk.
    const live = document.querySelector('[data-murottal-live]');
    if (live) {
        const statusEl = live.querySelector('[data-murottal-status]');
        const tracks = document.querySelectorAll('button.track[data-title]');
        const refresh = async () => {
            try {
                const data = await (await fetch('/api/murottal.php', { cache: 'no-store' })).json();
                const title = data.success ? data.now_playing : null;
                if (title) statusEl.textContent = 'Sedang diputar: ' + title;
                tracks.forEach((btn) => {
                    const on = title !== null && btn.dataset.title === title;
                    btn.classList.toggle('playing', on);
                    btn.toggleAttribute('aria-current', on);
                    const v = btn.querySelector('.v');
                    v.textContent = on ? 'Diputar' : 'Putar';
                    v.classList.toggle('ok', on);
                });
            } catch (err) { /* jaringan putus sebentar: coba lagi di putaran berikutnya */ }
        };
        refresh();
        // Halaman bisa diganti lewat document.write (lihat pengiriman formulir di bawah)
        // tanpa membuang timer lama, jadi simpan di window agar tidak menumpuk.
        clearInterval(window.murottalTimer);
        window.murottalTimer = setInterval(() => { if (!document.hidden) refresh(); }, 3000);
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
