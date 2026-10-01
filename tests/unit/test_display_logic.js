// Unit test logika layar jamaah (public/assets/js/display.js).
// Dijalankan lewat: node tests/unit/test_display_logic.js
'use strict';

const path = require('path');
const { computeView, formatDuration, formatCountdown, fastingRow } =
    require(path.join(__dirname, '../../public/assets/js/display.js'));

let total = 0;
let failures = 0;
function check(label, cond) {
    total++;
    if (cond) {
        console.log('  ✓ ' + label);
    } else {
        failures++;
        console.log('  ✗ ' + label);
    }
}

// Jadwal asli Sleman, Kamis 1 Oktober 2026 (dari Pi).
const today = { date: '2026-10-01', fajr: '04:06', sunrise: '05:23', dhuhr: '11:28', asr: '14:37', maghrib: '17:33', isha: '18:43' };
const tomorrow = { date: '2026-10-02', fajr: '04:05', sunrise: '05:22', dhuhr: '11:28', asr: '14:36', maghrib: '17:33', isha: '18:43' };
const reminder = { enabled: true, minutes: 10, friday_minutes: 30, message: 'Mari bersiap' };
const at = (date, hms) => new Date(date + 'T' + hms);

console.log('computeView — kondisi normal');
let v = computeView(at('2026-10-01', '17:21:00'), today, tomorrow, reminder);
check('berikutnya Maghrib', v.next.key === 'maghrib' && !v.next.tomorrow);
check('sisa 12 menit', v.remaining === 12 * 60);
check('mode normal di luar jendela pengingat', v.mode === 'normal');
check('Ashar dan sebelumnya ditandai lewat', v.rows.asr === 'past' && v.rows.fajr === 'past' && v.rows.sunrise === 'past');
check('Maghrib ditandai berikutnya', v.rows.maghrib === 'next');
check('Isya belum lewat', v.rows.isha === '');

console.log('computeView — pengingat');
v = computeView(at('2026-10-01', '17:23:00'), today, tomorrow, reminder);
check('tepat 10 menit sebelum adzan masuk pengingat', v.mode === 'reminder' && v.remaining === 600);
v = computeView(at('2026-10-01', '17:22:59'), today, tomorrow, reminder);
check('1 detik sebelum jendela masih normal', v.mode === 'normal');
v = computeView(at('2026-10-01', '17:28:08'), today, tomorrow, reminder);
check('hitung mundur 04:52', formatCountdown(v.remaining) === '04:52');
v = computeView(at('2026-10-01', '17:25:00'), today, tomorrow, { ...reminder, enabled: false });
check('pengingat mati = tetap normal', v.mode === 'normal');

console.log('computeView — adzan');
v = computeView(at('2026-10-01', '17:33:00'), today, tomorrow, reminder);
check('tepat waktu Maghrib masuk layar adzan', v.mode === 'adzan' && v.adzan.key === 'maghrib');
check('saat adzan, berikutnya sudah Isya', v.next.key === 'isha');
v = computeView(at('2026-10-01', '17:35:59'), today, tomorrow, reminder);
check('masih adzan di detik ke-179', v.mode === 'adzan');
v = computeView(at('2026-10-01', '17:36:00'), today, tomorrow, reminder);
check('kembali normal setelah 3 menit', v.mode === 'normal');

console.log('computeView — setelah Isya & dini hari');
v = computeView(at('2026-10-01', '21:00:00'), today, tomorrow, reminder);
check('berikutnya Subuh besok', v.next.key === 'fajr' && v.next.tomorrow);
check('sisa dihitung melewati tengah malam', v.remaining === (24 * 3600 - 21 * 3600) + 4 * 3600 + 5 * 60);
check('daftar beralih ke jadwal besok tanpa tanda lewat', v.listDay === 'tomorrow' && v.rows.isha === '' && v.rows.fajr === 'next');
v = computeView(at('2026-10-02', '03:00:00'), tomorrow, null, reminder);
check('jam 03:00 berikutnya Subuh hari itu', v.next.key === 'fajr' && !v.next.tomorrow && v.remaining === 65 * 60);

console.log('computeView — Jumat');
const friday = { ...tomorrow };
v = computeView(at('2026-10-02', '11:00:00'), friday, null, reminder);
check('Jumat 28 menit sebelum Dzuhur sudah pengingat (bawaan 30)', v.mode === 'reminder' && v.fridayDhuhr && v.lead === 1800);
v = computeView(at('2026-10-01', '11:00:00'), today, tomorrow, reminder);
check('Kamis 28 menit sebelum Dzuhur masih normal', v.mode === 'normal' && !v.fridayDhuhr);

console.log('formatDuration');
check('12 menit', formatDuration(720) === '12 menit');
check('dibulatkan ke atas: 11 menit 1 detik → 12 menit', formatDuration(661) === '12 menit');
check('1 jam 5 menit', formatDuration(3900) === '1 jam 5 menit');
check('2 jam pas', formatDuration(7200) === '2 jam');
check('di bawah 1 menit', formatDuration(30) === 'kurang dari 1 menit');

console.log('fastingRow');
const upcoming = [
    { date: '2026-10-01', is_senin_kamis: true, is_ayyamul_bidh: false },
    { date: '2026-10-05', is_senin_kamis: true, is_ayyamul_bidh: false },
    { date: '2026-10-08', is_senin_kamis: true, is_ayyamul_bidh: false },
];
let r = fastingRow(upcoming, '2026-10-01', '2026-10-02', false);
check('sebelum Maghrib Kamis: hari ini puasa Kamis', r.title === 'Puasa sunnah hari ini' && r.value === 'Puasa Kamis');
r = fastingRow(upcoming, '2026-10-01', '2026-10-02', true);
check('setelah Maghrib Kamis: terdekat Senin, 5 Oktober', r.title === 'Puasa sunnah terdekat' && r.value === 'Senin, 5 Oktober');
r = fastingRow([{ date: '2026-10-05', is_senin_kamis: true, is_ayyamul_bidh: false }], '2026-10-04', '2026-10-05', true);
check('Ahad malam: besok puasa Senin', r.title === 'Puasa sunnah besok' && r.value === 'Puasa Senin');
r = fastingRow([{ date: '2026-11-04', is_senin_kamis: false, is_ayyamul_bidh: true }], '2026-11-01', '2026-11-02', false);
check('Ayyamul Bidh terdekat ditandai', r.value === 'Rabu, 4 November · Ayyamul Bidh');
r = fastingRow([{ date: '2026-10-05', is_senin_kamis: true, is_ayyamul_bidh: true }], '2026-10-05', '2026-10-06', false);
check('gabungan Senin & Ayyamul Bidh', r.value === 'Puasa Senin & Ayyamul Bidh');
check('daftar kosong (mis. Ramadhan) = baris disembunyikan', fastingRow([], '2027-02-10', '2027-02-11', false) === null);

console.log('\n' + (total - failures) + '/' + total + ' assertion lolos');
process.exit(failures === 0 ? 0 : 1);
