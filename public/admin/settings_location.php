<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Repository\SettingsRepository;
use App\Schedule\ScheduleEngine;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$settingsRepo = new SettingsRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);

    $latitude = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
    $longitude = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);
    $offset = filter_input(INPUT_POST, 'location_offset_minutes', FILTER_VALIDATE_INT);
    $madzhab = $_POST['madzhab'] ?? '';
    $iqomahInterval = filter_input(INPUT_POST, 'azan_iqomah_interval_minutes', FILTER_VALIDATE_INT);
    $onlineSync = isset($_POST['online_sync_enabled']) ? 1 : 0;

    $errors = [];
    if ($latitude === false || $latitude === null || $latitude < -90 || $latitude > 90) {
        $errors[] = 'Latitude harus angka antara -90 dan 90.';
    }
    if ($longitude === false || $longitude === null || $longitude < -180 || $longitude > 180) {
        $errors[] = 'Longitude harus angka antara -180 dan 180.';
    }
    if (!in_array($madzhab, ['syafii', 'hanafi'], true)) {
        $errors[] = 'Madzhab tidak valid.';
    }
    if ($offset === false || $offset === null || $offset < -120 || $offset > 120) {
        $errors[] = 'Offset lokasi harus antara -120 dan 120 menit.';
    }
    if ($iqomahInterval === false || $iqomahInterval === null || $iqomahInterval < 1 || $iqomahInterval > 60) {
        $errors[] = 'Jarak adzan-iqomah harus antara 1 dan 60 menit.';
    }

    if ($errors === []) {
        $settingsRepo->update([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_offset_minutes' => $offset,
            'madzhab' => $madzhab,
            'azan_iqomah_interval_minutes' => $iqomahInterval,
            'online_sync_enabled' => $onlineSync,
        ]);
        // REQ-F-002/003: jadwal yang sudah di-cache harus dihitung ulang dengan pengaturan baru.
        (new ScheduleEngine())->invalidateCache();

        $_SESSION['flash_success'] = 'Pengaturan lokasi & jadwal berhasil disimpan. Jadwal akan dihitung ulang.';
        header('Location: /admin/settings_location.php');
        exit;
    } else {
        $_SESSION['flash_error'] = implode(' ', $errors);
    }
}

$settings = $settingsRepo->get();

$pageTitle = 'Lokasi & Jadwal Sholat';
$activeMenu = 'location';
require __DIR__ . '/_header.php';
?>

<form method="post" class="card form-card">
    <?= Csrf::field() ?>

    <label>Latitude
        <input type="number" step="any" name="latitude" value="<?= htmlspecialchars((string) $settings['latitude']) ?>" required>
    </label>
    <label>Longitude
        <input type="number" step="any" name="longitude" value="<?= htmlspecialchars((string) $settings['longitude']) ?>" required>
    </label>
    <p class="hint">Default: Yogyakarta (-7.797068, 110.370529). Cari koordinat masjid Anda lewat Google Maps
        (klik kanan lokasi → koordinat akan muncul).</p>

    <label>Madzhab (memengaruhi waktu Ashar)
        <select name="madzhab" required>
            <option value="syafii" <?= $settings['madzhab'] === 'syafii' ? 'selected' : '' ?>>Syafi'i</option>
            <option value="hanafi" <?= $settings['madzhab'] === 'hanafi' ? 'selected' : '' ?>>Hanafi</option>
        </select>
    </label>

    <label>Offset lokasi (menit, boleh negatif)
        <input type="number" name="location_offset_minutes" value="<?= (int) $settings['location_offset_minutes'] ?>" required>
    </label>
    <p class="hint">Gunakan ini bila jadwal untuk lokasi Anda perlu digeser dari hasil hitung default (misalnya
        menyesuaikan dengan jadwal resmi setempat).</p>

    <label>Jarak Adzan → Iqomah (menit)
        <input type="number" name="azan_iqomah_interval_minutes" value="<?= (int) $settings['azan_iqomah_interval_minutes'] ?>" min="1" max="60" required>
    </label>

    <label class="checkbox-label">
        <input type="checkbox" name="online_sync_enabled" <?= (int) $settings['online_sync_enabled'] === 1 ? 'checked' : '' ?>>
        Aktifkan sinkronisasi jadwal online saat internet tersedia (REQ-F-004)
    </label>

    <button type="submit">Simpan Pengaturan</button>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
