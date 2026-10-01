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
    document.querySelectorAll('[data-autosubmit]').forEach((input) => {
        input.addEventListener('change', () => input.form.submit());
    });

    // Konfirmasi sebelum tindakan yang menghapus/menimpa data.
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (!window.confirm(form.dataset.confirm)) e.preventDefault();
        });
    });
})();
