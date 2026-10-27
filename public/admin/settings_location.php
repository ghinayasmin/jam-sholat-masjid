<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Repository\SettingsRepository;
use App\Schedule\LocationInput;
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
    [$fields, $errors] = LocationInput::fromPost($_POST);
    if ($errors === []) {
        $settingsRepo->update($fields);
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

    <?php
    $loc = ['location_name' => $v('location_name'), 'latitude' => $v('latitude'), 'longitude' => $v('longitude')];
    $manualOpen = $errors !== [];
    require __DIR__ . '/_location_fields.php';
    ?>

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
