# Panduan Desain

Panduan tampilan untuk **layar jamaah** (`public/display.php`) dan **panel takmir**
(`public/admin/`). Alasan di balik keputusan desain ada di `docs/DECISIONS.md`
#14–#16; dokumen ini menjelaskan cara menjaga tampilan tetap konsisten saat
menambah halaman atau fitur.

Nilai warna dan ukuran di bawah disalin dari token CSS. Kalau mengubah token,
ubah di CSS lalu perbarui tabel di sini.

## 1. Prinsip

1. **Rasa masjid dari bahan, bukan ornamen.** Tidak memakai kubah, siluet
   menara, atau gradasi hijau-emas. Rasa masjid datang dari warna kayu jati dan
   kuningan, tekstur batik kawung yang samar, dan nama waktu sholat dalam huruf
   Arab. Referensi: Masjid Salman ITB, Masjid Al-Irsyad Kota Baru Parahyangan,
   saka guru Masjid Agung Demak, papan JWS LED.
2. **Informasi terpenting paling besar.** Di layar jamaah: jam, lalu waktu
   sholat berikutnya beserta hitung mundurnya. Di panel takmir: apa yang perlu
   dilakukan hari ini.
3. **Tidak ada kotak kosong.** Bagian yang tidak punya isi disembunyikan, tidak
   ditampilkan dengan tulisan "Belum ada data".
4. **Bahasa pengurus, bukan bahasa sistem.** "Uang masuk", bukan "transaksi
   pemasukan". "Cadangan data", bukan "backup". Istilah teknis (koordinat,
   madzhab) hanya di halaman Lokasi, dengan peringatan "untuk teknisi".
5. **Data internal tidak tampil ke publik.** Kas masjid tidak tampil di layar
   jamaah kecuali takmir menyalakannya (DECISIONS.md #14).

## 2. Batasan teknis

| Batasan | Akibatnya pada desain |
|---|---|
| Sistem berjalan **offline** di masjid | Font, ikon, dan gambar disimpan di repo (`public/assets/fonts/`, ikon SVG di `src/View/Icon.php`). Jangan memakai Google Fonts, CDN, atau gambar dari internet. |
| Raspberry Pi 3 menggambar lewat **CPU** (Chromium `--disable-gpu`) | Hindari `backdrop-filter`, `filter: blur`, bayangan besar, animasi terus-menerus, dan teks berjalan. Transisi cukup `opacity`. |
| Monitor di masjid **berbeda-beda ukuran** | Layar jamaah memakai satuan `--u` (lihat bagian 3), bukan px. |
| Pengurus membuka panel dari **HP** | Panel dirancang untuk lebar 390px dulu; lebar maksimum 560px. |
| Atribut `hidden` kalah oleh `display: grid/flex` | Kedua CSS punya aturan `[hidden] { display: none !important; }`. Jangan dihapus. |

## 3. Layar jamaah

**File:** `public/display.php`, `public/assets/css/display.css`, `public/assets/js/display.js`

### Warna — "Malam teduh"

| Token | Nilai | Dipakai untuk |
|---|---|---|
| `--bg` | `#0F1915` | Latar panel kiri dan layar penuh |
| `--panel` | `#15221D` | Latar panel kanan (daftar jadwal) |
| `--ink` | `#ECE6D8` | Teks utama |
| `--ink-2` | `#C7CCC6` | Teks pendukung |
| `--muted` | `#8F9C95` | Nama Arab, keterangan |
| `--past` | `#6F7D76` | Waktu sholat yang sudah lewat |
| `--brass` | `#D9B77A` | **Satu-satunya warna aksen**: nama masjid, tanggal Hijriah, hitung mundur, baris waktu berikutnya |
| `--brass-ink` | `#1B1408` | Teks di atas latar kuningan |

### Ukuran: satuan `--u`

`--u` = 1/100 lebar area 16:9 yang muat di layar (`min(1vw, 1.7778vh)`). Di
monitor 1920×1080, `1 --u` = 19,2px. Semua ukuran ditulis
`calc(var(--u) * N)`, sehingga tata letak tetap utuh di monitor 16:10 atau 4:3.

| Elemen | Ukuran |
|---|---|
| Jam | 11 u, tebal 700 |
| Hitung mundur | 4,4 u |
| Tanggal Masehi & Hijriah (baris terpisah), teks "menuju adzan …" | 2,1 u |
| Waktu sholat (angka) | 2,7 u |
| Nama sholat | 1,8 u |
| Nama masjid | 1,9 u |
| Teks informasi | 1,3 u |

### Tipografi

- **Plus Jakarta Sans** (variabel, 200–800) untuk semua teks Latin. Huruf buatan
  desainer Indonesia, lisensi OFL.
- **Reem Kufi** untuk huruf Arab dan tanggal Hijriah. Lisensi OFL.
- Semua angka memakai `font-variant-numeric: tabular-nums` agar jam tidak
  bergoyang saat detik berganti.

### Tata letak

```
┌────────────────────────────┬──────────────────────────┐
│ Nama masjid                │ (Jadwal besok)           │
│ Alamat                     │ Subuh      الفجر   04:06 │
│                            │ Terbit     الشروق  05:23 │
│ 17:21                      │ Dzuhur     الظهر   11:28 │
│ Kamis, 1 Oktober · 18 R.A. │ Ashar      العصر   14:37 │
│                            │▐Maghrib    المغرب  17:33▌│ ← berikutnya
│ ────────────────────────── │ Isya       العشاء  18:43 │
│ 12 menit  menuju adzan     │                          │
│           Maghrib 17:33    │ Pengumuman · Puasa · Kas │
└────────────────────────────┴──────────────────────────┘
   kiri: "sekarang" (1,15)        kanan: "hari ini" (1)
```

### Kondisi layar

| Kondisi | Kapan | Tampilan |
|---|---|---|
| Normal | Sepanjang hari | Tata letak di atas |
| Pengingat | `reminder_minutes` sebelum adzan (bawaan 10; Jumat 30) | Layar penuh: "Adzan Maghrib dalam 04:52", garis yang memendek, pesan pengingat |
| Adzan | 3 menit sejak waktu adzan | Layar penuh: حَيَّ عَلَى الصَّلَاةِ, "Adzan Maghrib" |
| Jadwal besok | Setelah Isya sampai tengah malam | Daftar kanan berganti ke jadwal besok, diberi label "Jadwal besok" |
| Poster | 12 detik setiap menit, hanya di kondisi normal | Poster pengumuman menutupi panel kiri |

Pada hari Jumat, baris Dzuhur berganti menjadi "Jumat" / الجمعة.

**Mencoba tanpa menunggu waktu sholat:** buka `display.php?jam=17:28` (jam
disimulasikan, tanggal tetap hari ini). Contoh: `?jam=17:28` untuk pengingat,
`?jam=17:34` untuk adzan, `?jam=21:00` untuk jadwal besok.

### Baris informasi (kanan bawah)

Setiap baris hanya muncul bila ada isinya:

- **Pengumuman**: teks pengumuman yang sedang tampil, bergantian tiap 10 detik.
- **Puasa sunnah**: hari ini, besok, atau yang terdekat. Tidak tampil pada
  hari terlarang berpuasa dan selama Ramadhan.
- **Kas masjid**: hanya saldo, dan hanya bila takmir menyalakannya.

## 4. Panel takmir

**File:** `public/admin/*.php`, `public/assets/css/admin.css`, `public/assets/js/admin.js`

### Warna

| Token | Nilai | Dipakai untuk |
|---|---|---|
| `--bg` | `#F2F3EF` | Latar halaman |
| `--surface` | `#FFFFFF` | Kartu, kolom isian |
| `--ink` | `#17221E` | Teks utama |
| `--muted` | `#66736E` | Keterangan, label kelompok |
| `--line` | `#E3E7E1` | Garis pemisah baris |
| `--primary` | `#1F5A3C` | Tombol utama, tab aktif, tautan |
| `--jati` | `#7A5530` | Ikon, pilihan (chip) yang terpilih, tanda "Menunggu" |
| `--in` / `--out` | `#22744A` / `#A2453A` | Uang masuk / keluar, tombol hapus |
| `--night` | `#0F1915` | Kartu "Berikutnya" dan pratinjau layar jamaah |

### Jarak dan ukuran

- **Skala jarak:** 4 · 8 · 12 · 16 · 20 · 24 · 32 px. Jarak antarbagian 24px,
  jarak di dalam bagian 8px, tepi kiri-kanan halaman 20px.
- **Target sentuh minimal 44px.** Baris daftar 56px, tombol utama 52px.
- **Sudut:** kartu 14px, kolom isian dan tombol 12px, chip bulat penuh.
- **Huruf:** judul halaman 26px/800, teks 16px, keterangan 13–14px, label
  kelompok 12px huruf kapital.
- Kolom isian **minimal 16px** agar Safari di iPhone tidak memperbesar layar
  saat diketuk.

### Struktur halaman

Setiap halaman memakai `_header.php` dan `_footer.php`.

- **Halaman tab** (Beranda, Pengumuman, Kas, Kamera, Pengaturan): isi
  `$pageTitle`, `$pageSubtitle`, `$activeTab`. Tab bawah tampil.
- **Sub-halaman** (formulir dan pengaturan rinci): isi `$pageTitle` dan
  `$backHref`. Muncul tombol kembali di kiri atas, tab bawah disembunyikan,
  tombol simpan menempel di bawah (`.foot`).

Setelah simpan, arahkan kembali ke halaman asal dan tulis hasilnya di
`$_SESSION['flash_success']` dengan kalimat yang menyebut akibatnya, misalnya
"Pengumuman tampil di layar jamaah dalam 1 menit."

### Komponen

| Komponen | Kelas | Catatan |
|---|---|---|
| Kelompok berlabel | `.group` + `.label` | Label kapital kecil di atas kartu |
| Kartu daftar | `.card` + `.row` | Baris berisi ikon/titik, `.k` (judul + `<small>`), dan nilai `.v` atau panah. `.row.ctl` = label + kontrol tanpa ikon, `.row.solo` = label saja |
| Tombol | `.btn`, `.btn.ghost`, `.btn.danger`, `.btn.small` | Satu tombol utama per layar |
| Ubin tugas | `.tiles` + `.tile` | Hanya di Beranda |
| Pilihan siap ketuk | `.chips` + `.chip` (radio) | Lebih mudah daripada dropdown |
| Dua sampai empat pilihan | `.seg` (radio) | Contoh: simpan foto 3 / 7 / 14 / 30 hari. Untuk nyala/mati pakai `.switch` (DECISIONS.md #37) |
| Angka − / + | `.stepper` | Tombol berisi `data-step` |
| Sakelar | `.switch` (checkbox, `value="1"`) | Semua pilihan nyala/mati. Tambah `data-autosubmit` agar tersimpan saat diubah; di formulir biasa, kotak yang tidak dicentang tidak terkirim, jadi bawaannya mati |
| Rupiah | `.money` + `data-money` | Format ribuan otomatis, ditambah tombol `.quick` |
| Pesan | `.flash-success`, `.flash-error`, `.note`, `.note.warn` | Tanpa tanda seru |
| Catatan kecil | `.aside` (`.tight`, `.spaced`) | Keterangan di bawah kartu, di luar `.field` |
| Konfirmasi | `form[data-confirm="..."]` | Wajib untuk hapus dan pulihkan data |

### Penulisan

- Huruf kapital hanya di awal kalimat: "Pasang pengumuman", bukan "Pasang
  Pengumuman".
- Tombol menyebut tindakannya: "Simpan uang masuk", "Pulihkan data".
- Pesan kesalahan menyebut cara memperbaikinya: "Isi jumlah uangnya, lebih dari
  Rp 0."
- Format tanggal dan uang memakai `App\View\Format` (contoh: "Kamis, 1 Okt",
  "Rp 4.812.500").

## 5. Memeriksa tampilan

- **Layar jamaah:** buka `/display.php` di browser lebar 1920×1080, lalu coba
  ketiga kondisi lewat `?jam=`.
- **Panel takmir:** buka di HP, atau di mode responsif browser dengan lebar
  390px. Pastikan tidak ada yang terpotong atau perlu digeser ke samping.
- **Logika layar:** `TZ=Asia/Jakarta node tests/unit/test_display_logic.js`.
