<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Repository\SettingsRepository;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$settingsRepo = new SettingsRepository();
$errors = [];

// Pengingat layar penuh sebelum adzan (DECISIONS.md #15).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);

    $enabled = ($_POST['reminder_enabled'] ?? '1') === '1';
    $minutes = filter_input(INPUT_POST, 'reminder_minutes', FILTER_VALIDATE_INT);
    $friday = filter_input(INPUT_POST, 'reminder_friday_minutes', FILTER_VALIDATE_INT);
    $message = trim((string) ($_POST['reminder_message'] ?? ''));

    if ($minutes === false || $minutes === null || $minutes < 1 || $minutes > 60) {
        $errors[] = 'Menit sebelum adzan harus antara 1 dan 60.';
    }
    if ($friday === false || $friday === null || $friday < 1 || $friday > 90) {
        $errors[] = 'Menit khusus Jumat harus antara 1 dan 90.';
    }
    if ($message === '') {
        $errors[] = 'Pesan di layar tidak boleh kosong.';
    }

    if ($errors === []) {
        $settingsRepo->update([
            'reminder_enabled' => $enabled ? 1 : 0,
            'reminder_minutes' => $minutes,
            'reminder_friday_minutes' => $friday,
            'reminder_message' => mb_substr($message, 0, 80),
        ]);
        $_SESSION['flash_success'] = $enabled
            ? "Pengingat tersimpan: tampil $minutes menit sebelum adzan."
            : 'Pengingat sebelum adzan dimatikan.';
        header('Location: /admin/settings.php');
        exit;
    }
}

$s = $settingsRepo->get();
$v = [
    'enabled' => $_SERVER['REQUEST_METHOD'] === 'POST' ? (($_POST['reminder_enabled'] ?? '1') === '1') : (int) $s['reminder_enabled'] === 1,
    'minutes' => $_POST['reminder_minutes'] ?? $s['reminder_minutes'],
    'friday' => $_POST['reminder_friday_minutes'] ?? $s['reminder_friday_minutes'],
    'message' => $_POST['reminder_message'] ?? $s['reminder_message'],
];

$pageTitle = 'Pengingat sebelum adzan';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<?php if ($errors !== []): ?>
    <div class="flash flash-error" role="alert"><?= Format::e(implode(' ', $errors)) ?></div>
<?php endif; ?>

<form method="post" class="stack">
    <?= Csrf::field() ?>

    <div class="seg" role="radiogroup" aria-label="Pengingat">
        <label><input type="radio" name="reminder_enabled" value="1"<?= $v['enabled'] ? ' checked' : '' ?>><span>Nyala</span></label>
        <label><input type="radio" name="reminder_enabled" value="0"<?= !$v['enabled'] ? ' checked' : '' ?>><span>Mati</span></label>
    </div>

    <div class="card">
        <div class="row" style="grid-template-columns:minmax(0,1fr) auto">
            <label class="k" for="reminder_minutes">Menit sebelum adzan<small>Semua waktu sholat</small></label>
            <span class="stepper"><button type="button" data-step="-1" aria-label="Kurangi">−</button><input type="number" id="reminder_minutes" name="reminder_minutes" min="1" max="60" inputmode="numeric" value="<?= (int) $v['minutes'] ?>"><button type="button" data-step="1" aria-label="Tambah">+</button></span>
        </div>
        <div class="row" style="grid-template-columns:minmax(0,1fr) auto">
            <label class="k" for="reminder_friday_minutes">Khusus Jumat<small>Sebelum adzan Jumat</small></label>
            <span class="stepper"><button type="button" data-step="-5" aria-label="Kurangi 5">−</button><input type="number" id="reminder_friday_minutes" name="reminder_friday_minutes" min="1" max="90" inputmode="numeric" value="<?= (int) $v['friday'] ?>"><button type="button" data-step="5" aria-label="Tambah 5">+</button></span>
        </div>
    </div>

    <div class="field">
        <label class="field-label" for="reminder_message">Pesan di layar</label>
        <input type="text" id="reminder_message" name="reminder_message" maxlength="80" value="<?= Format::e((string) $v['message']) ?>">
    </div>

    <section class="group" aria-labelledby="pv-label">
        <div class="label" id="pv-label">Seperti ini di layar jamaah</div>
        <div class="preview" data-reminder-preview>
            <div><div class="a">Adzan <b>Maghrib</b> dalam</div><div class="b"><?= sprintf('%02d', (int) $v['minutes']) ?>:00</div><div class="c"><?= Format::e((string) $v['message']) ?></div></div>
        </div>
        <p class="hint muted" style="margin:0;padding-left:4px;font-size:13px">Untuk melihat langsung di layar, buka <a href="/display.php?jam=17:28" target="_blank" rel="noopener">layar jamaah dalam mode simulasi</a>.</p>
    </section>

    <div class="foot"><button type="submit" class="btn">Simpan</button></div>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
