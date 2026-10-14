# Cara Berkontribusi

Panduan ini untuk anggota tim yang belum pernah atau jarang memakai git. Ikuti
urutannya sekali, setelah itu cukup bagian 4 setiap kali mengerjakan tugas.

Repo: https://github.com/ghinayasmin/jam-sholat-masjid (private)

| Anggota | Akun GitHub |
|---|---|
| Azhar | `azharm2412` |
| Dhiwa | `positivityDhiwss` |
| Rahmanda | `RahmandaPutriSetiawati` |
| Ghina | `ghinayasmin` (pemilik repo) |

---

## 1. Supaya kontribusimu muncul di profil GitHub

GitHub hanya menghitung commit yang memenuhi **semua** syarat ini:

1. **Email commit terdaftar di akun GitHub-mu.** Cek di GitHub → Settings →
   Emails. Email yang kamu pakai di langkah 2 harus ada di daftar itu dan
   sudah terverifikasi.
2. **Commit masuk ke branch `main`.** Commit di branch lain baru terhitung
   setelah Pull Request-nya di-merge ke `main`.
3. **Repo ini private, jadi kamu harus menyalakan satu pengaturan:** klik foto
   profil → Settings → Public profile → centang **"Include private
   contributions on my profile"**. Tanpa ini, kotak hijau di profilmu tetap
   kosong walau commit-nya ada.

Membuka Pull Request, memberi review, dan membuat Issue juga tercatat sebagai
kontribusi.

Setiap orang commit dari laptop dan akunnya sendiri, dengan pekerjaannya
sendiri. Jangan commit atas nama anggota lain. Riwayat git adalah bukti siapa
mengerjakan apa, dan dosen bisa melihatnya.

## 2. Persiapan (sekali saja)

### Pilihan A: GitHub Desktop (paling mudah)

1. Unduh https://desktop.github.com, login dengan akun GitHub-mu.
2. File → Clone repository → pilih `ghinayasmin/jam-sholat-masjid`.
3. File → Options → Git: isi nama dan email yang sama dengan email di GitHub.

Setelah itu kamu bisa lompat ke bagian 4 dan memakai tombol di GitHub Desktop.

### Pilihan B: Terminal

```bash
# 1. Pasang git (Mac: sudah ada; Windows: https://git-scm.com)
git --version

# 2. Identitas commit. Email HARUS sama dengan yang terverifikasi di GitHub.
git config --global user.name "Nama Lengkap"
git config --global user.email "email-kamu@contoh.com"

# 3. Login ke GitHub (pasang GitHub CLI dulu: https://cli.github.com)
gh auth login          # pilih GitHub.com → HTTPS → login lewat browser

# 4. Ambil repo
gh repo clone ghinayasmin/jam-sholat-masjid
cd jam-sholat-masjid
```

Kalau tidak mau email pribadi tampil di commit, pakai email noreply dari
GitHub → Settings → Emails (bentuknya `12345678+username@users.noreply.github.com`).
Email itu tetap terhitung sebagai kontribusimu.

## 3. Aturan main tim

- **Jangan commit langsung ke `main`.** Buat branch, lalu Pull Request (PR).
- **Satu PR = satu perubahan yang jelas**, misalnya satu test baru atau satu perbaikan.
- **Nama branch:** `nama/topik`, misalnya `ghina/test-login`, `dhiwa/test-jendela-diam`.
- **Pesan commit:** kalimat perintah dalam bahasa Indonesia yang menjelaskan
  apa yang berubah, misalnya `Tambah test login untuk TC-AUTH-001`.
- **Jalankan test sebelum push** (lihat `README.md` bagian 10, Running Tests).
- **Minta satu orang lain me-review** sebelum merge, sebaiknya anggota yang
  bagiannya berhubungan dengan perubahanmu.

## 4. Alur kerja setiap tugas

```bash
# 1. Mulai dari main yang terbaru
git switch main
git pull

# 2. Buat branch untuk tugasmu
git switch -c ghina/test-login

# 3. Kerjakan. Lihat apa yang berubah:
git status
git diff

# 4. Simpan perubahan sebagai commit
git add tests/integration/test_core_integration.php
git commit -m "Tambah test login untuk TC-AUTH-001"

# 5. Kirim ke GitHub
git push -u origin ghina/test-login

# 6. Buka Pull Request
gh pr create --fill --reviewer azharm2412
```

Setelah reviewer menyetujui, klik **Merge pull request** di halaman PR. Sejak
saat itu commit-mu ada di `main` dan muncul di profil.

Lalu bersihkan:

```bash
git switch main
git pull
git branch -d ghina/test-login
```

## 5. Kalau terjadi konflik

Konflik muncul kalau kamu dan anggota lain mengubah baris yang sama. Git akan
menandainya di file seperti ini:

```
<<<<<<< HEAD
versi di main
=======
versi kamu
>>>>>>> ghina/test-login
```

Cara menyelesaikannya:

```bash
git switch ghina/test-login
git pull origin main          # ambil perubahan terbaru dari main
# buka file yang konflik, pilih isi yang benar, hapus tanda <<<<<<< ======= >>>>>>>
git add <file>
git commit
git push
```

Kalau ragu, tanya pemilik file itu sebelum memilih.

## 6. Hal yang tidak boleh masuk git

File berikut sudah diabaikan lewat `.gitignore`. Jangan memaksanya masuk:

- `config/config.php` (konfigurasi per perangkat)
- `storage/` (database, foto kamera, backup, sesi)
- `public/uploads/` (poster yang diunggah takmir)
- file audio adzan/murottal (sekitar 1 GB, lisensi pihak lain)

Jangan pernah commit password, termasuk password admin panel dan password WiFi masjid.

## 7. Memasang perubahan ke Raspberry Pi

Setelah PR di-merge, satu orang yang memegang Pi memasangnya:

```bash
# di laptop, dari folder repo yang sudah di-pull
rsync -az --exclude .git --exclude storage/ --exclude public/uploads/ \
      --exclude config/config.php ./ admincapstone@jamsholat.local:~/jam-sholat-masjid/
ssh -t admincapstone@jamsholat.local 'cd ~/jam-sholat-masjid && sudo bash scripts/update.sh'
```

## 8. Ide kontribusi pertama

Lihat bagian **Latihan** di handbook PDF masing-masing. Latihan bertanda
*opsional* menambah test otomatis untuk test case yang sudah Pass, cocok
sebagai PR pertama.
