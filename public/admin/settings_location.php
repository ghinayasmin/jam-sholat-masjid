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

// REQ-F-002, REQ-F-003, REQ-F-021: lokasi & dasar perhitungan jadwal. Koreksi menit pindah ke
// schedule_adjust.php; sakelar jadwal online dihapus karena tidak dipakai (DECISIONS.md #36).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);

    $locationName = trim((string) ($_POST['location_name'] ?? ''));
    $latitude = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
    $longitude = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);
    $madzhab = $_POST['madzhab'] ?? '';

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

    if ($errors === []) {
        $settingsRepo->update([
            'location_name' => mb_substr($locationName, 0, 60),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'madzhab' => $madzhab,
            'location_confirmed' => 1,
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
    <p class="aside tight">Buka Google Maps, tekan lama titik masjid, lalu salin dua angka koordinatnya.</p>

    <div class="field">
        <label class="field-label" for="madzhab">Madzhab waktu Ashar</label>
        <select id="madzhab" name="madzhab">
            <option value="syafii"<?= $v('madzhab') === 'syafii' ? ' selected' : '' ?>>Syafi'i (umum di Indonesia)</option>
            <option value="hanafi"<?= $v('madzhab') === 'hanafi' ? ' selected' : '' ?>>Hanafi (Ashar lebih akhir)</option>
        </select>
    </div>

    <p class="aside">Untuk menggeser jam adzan beberapa menit, pakai Pengaturan → Koreksi jadwal.</p>

    <div class="foot"><button type="submit" class="btn">Simpan lokasi</button></div>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
