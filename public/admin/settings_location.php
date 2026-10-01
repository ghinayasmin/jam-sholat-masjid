<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Repository\SettingsRepository;
use App\Schedule\ScheduleEngine;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$settingsRepo = new SettingsRepository();
$errors = [];

// REQ-F-002, REQ-F-003, REQ-F-004, REQ-F-021: lokasi & dasar perhitungan jadwal.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);

    $locationName = trim((string) ($_POST['location_name'] ?? ''));
    $latitude = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
    $longitude = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);
    $offset = filter_input(INPUT_POST, 'location_offset_minutes', FILTER_VALIDATE_INT);
    $madzhab = $_POST['madzhab'] ?? '';
    $onlineSync = isset($_POST['online_sync_enabled']) ? 1 : 0;

    if ($locationName === '') {
        $errors[] = 'Tulis nama lokasi, misalnya "Sleman, DIY".';
    }
    if ($latitude === false || $latitude === null || $latitude < -90 || $latitude > 90) {
        $errors[] = 'Latitude harus angka antara -90 dan 90.';
    }
    if ($longitude === false || $longitude === null || $longitude < -180 || $longitude > 180) {
        $errors[] = 'Longitude harus angka antara -180 dan 180.';
    }
    if (!in_array($madzhab, ['syafii', 'hanafi'], true)) {
        $errors[] = 'Pilih madzhab untuk waktu Ashar.';
    }
    if ($offset === false || $offset === null || $offset < -120 || $offset > 120) {
        $errors[] = 'Koreksi menit harus antara -120 dan 120.';
    }

    if ($errors === []) {
        $settingsRepo->update([
            'location_name' => mb_substr($locationName, 0, 60),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_offset_minutes' => $offset,
            'madzhab' => $madzhab,
            'online_sync_enabled' => $onlineSync,
        ]);
        // REQ-F-002/003: jadwal yang sudah di-cache dihitung ulang dengan pengaturan baru.
        (new ScheduleEngine())->invalidateCache();
        $_SESSION['flash_success'] = 'Lokasi tersimpan. Jadwal sholat dihitung ulang.';
        header('Location: /admin/settings.php');
        exit;
    }
}

$s = $settingsRepo->get();
$v = static fn (string $key) => $_POST[$key] ?? $s[$key];

$pageTitle = 'Lokasi';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<?php if ($errors !== []): ?>
    <div class="flash flash-error" role="alert"><?= Format::e(implode(' ', $errors)) ?></div>
<?php endif; ?>

<form method="post" class="stack">
    <?= Csrf::field() ?>

    <div class="field">
        <label class="field-label" for="location_name">Nama lokasi</label>
        <input type="text" id="location_name" name="location_name" maxlength="60" value="<?= Format::e((string) $v('location_name')) ?>" placeholder="Contoh: Sleman, DIY">
        <p class="hint">Hanya sebagai penanda untuk pengurus. Jadwal dihitung dari koordinat di bawah.</p>
    </div>

    <div class="note warn">Bagian di bawah ini untuk teknisi. Salah isi koordinat membuat jadwal sholat bergeser.</div>

    <div class="pair">
        <div class="field">
            <label class="field-label" for="latitude">Latitude</label>
            <input type="text" id="latitude" name="latitude" inputmode="decimal" value="<?= Format::e((string) $v('latitude')) ?>" required>
        </div>
        <div class="field">
            <label class="field-label" for="longitude">Longitude</label>
            <input type="text" id="longitude" name="longitude" inputmode="decimal" value="<?= Format::e((string) $v('longitude')) ?>" required>
        </div>
    </div>
    <p class="hint muted" style="margin:-8px 0 0;padding-left:4px;font-size:13px">Buka Google Maps, tekan lama titik masjid, lalu salin dua angka koordinatnya.</p>

    <div class="field">
        <label class="field-label" for="madzhab">Madzhab waktu Ashar</label>
        <select id="madzhab" name="madzhab">
            <option value="syafii"<?= $v('madzhab') === 'syafii' ? ' selected' : '' ?>>Syafi'i (umum di Indonesia)</option>
            <option value="hanafi"<?= $v('madzhab') === 'hanafi' ? ' selected' : '' ?>>Hanafi (Ashar lebih akhir)</option>
        </select>
    </div>

    <div class="card">
        <div class="row" style="grid-template-columns:minmax(0,1fr) auto">
            <label class="k" for="location_offset_minutes">Koreksi menit<small>Geser semua jadwal agar sama dengan jadwal resmi setempat</small></label>
            <span class="stepper"><button type="button" data-step="-1" aria-label="Kurangi">−</button><input type="number" id="location_offset_minutes" name="location_offset_minutes" min="-120" max="120" inputmode="numeric" value="<?= (int) $v('location_offset_minutes') ?>"><button type="button" data-step="1" aria-label="Tambah">+</button></span>
        </div>
        <div class="row" style="grid-template-columns:minmax(0,1fr) auto">
            <label class="k" for="online_sync_enabled">Cocokkan dengan jadwal online<small>Saat ada internet (AlAdhan, metode Kemenag)</small></label>
            <span class="switch"><input type="checkbox" id="online_sync_enabled" name="online_sync_enabled"<?= ($_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['online_sync_enabled']) : (int) $s['online_sync_enabled'] === 1) ? ' checked' : '' ?>><span></span></span>
        </div>
    </div>

    <div class="foot"><button type="submit" class="btn">Simpan lokasi</button></div>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
