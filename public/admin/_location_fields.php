<?php
/**
 * Kolom lokasi masjid untuk Pengaturan → Lokasi dan Langkah awal (DECISIONS.md #42).
 * Variabel yang harus diset sebelum include:
 *   $loc         ['location_name' => ..., 'latitude' => ..., 'longitude' => ...] nilai saat ini
 *   $manualOpen  true: bagian koordinat manual langsung terbuka (mis. ada kesalahan isian)
 * Pencarian kabupaten/kota berjalan di HP (admin.js), data dari assets/data/kabupaten_kota.json.
 */
use App\View\Format;

$cityData = '/assets/data/kabupaten_kota.json?v=' . filemtime(dirname(__DIR__) . '/assets/data/kabupaten_kota.json');
?>
<div class="field">
    <label class="field-label" for="city_search">Kabupaten/kota masjid</label>
    <input type="search" class="input" id="city_search" placeholder="Contoh: Sleman" autocomplete="off" data-city-search="<?= Format::e($cityData) ?>">
    <p class="hint">Ketik nama kabupaten atau kota, lalu pilih dari daftar yang muncul. Jadwal sholat dihitung dari titik pusat kabupaten/kota tersebut.</p>
</div>
<div class="card city-results" data-city-results hidden></div>
<p class="aside" data-city-empty hidden>Kabupaten/kota tidak ditemukan. Periksa kembali ejaannya, atau isi koordinat secara manual di bawah.</p>
<div class="note" data-city-chosen<?= trim((string) $loc['location_name']) === '' ? ' hidden' : '' ?>>Lokasi: <b data-city-name><?= Format::e((string) $loc['location_name']) ?></b></div>

<details class="manual"<?= $manualOpen ? ' open' : '' ?>>
    <summary>Isi koordinat secara manual (lebih tepat)</summary>
    <div class="manual-body">
        <p class="aside">Gunakan bila waktu sholat ingin dihitung tepat dari titik masjid. Buka Google Maps, tekan lama titik masjid, lalu salin dua angka yang muncul. Kedua angka tersebut dapat ditempel sekaligus pada kolom Garis lintang.</p>
        <div class="field">
            <label class="field-label" for="location_name">Nama lokasi</label>
            <input type="text" id="location_name" name="location_name" maxlength="60" value="<?= Format::e((string) $loc['location_name']) ?>">
        </div>
        <div class="pair">
            <div class="field">
                <label class="field-label" for="latitude">Garis lintang (latitude)</label>
                <input type="text" id="latitude" name="latitude" value="<?= Format::e((string) $loc['latitude']) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="longitude">Garis bujur (longitude)</label>
                <input type="text" id="longitude" name="longitude" value="<?= Format::e((string) $loc['longitude']) ?>">
            </div>
        </div>
    </div>
</details>
