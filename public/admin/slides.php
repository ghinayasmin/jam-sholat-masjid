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

// Pergantian slide di layar jamaah: poster, saldo kas, foto masjid (DECISIONS.md #27).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);

    $gap = filter_input(INPUT_POST, 'poster_gap_seconds', FILTER_VALIDATE_INT);
    $show = filter_input(INPUT_POST, 'poster_show_seconds', FILTER_VALIDATE_INT);

    if ($gap === false || $gap === null || $gap < 5 || $gap > 600) {
        $errors[] = 'Jeda antar slide harus antara 5 dan 600 detik.';
    }
    if ($show === false || $show === null || $show < 5 || $show > 120) {
        $errors[] = 'Lama slide tampil harus antara 5 dan 120 detik.';
    }

    if ($errors === []) {
        $settingsRepo->update(['poster_gap_seconds' => $gap, 'poster_show_seconds' => $show]);
        $_SESSION['flash_success'] = "Tersimpan: slide tampil $show detik, lalu jam tampil $gap detik.";
        header('Location: /admin/settings.php');
        exit;
    }
}

$s = $settingsRepo->get();
$v = [
    'gap' => $_POST['poster_gap_seconds'] ?? $s['poster_gap_seconds'],
    'show' => $_POST['poster_show_seconds'] ?? $s['poster_show_seconds'],
];
$inRotation = ['poster pengumuman'];
if ((int) $s['show_finance_on_display'] === 1) {
    $inRotation[] = 'saldo kas';
}
if ($s['mosque_photo'] !== '') {
    $inRotation[] = 'foto masjid';
}

$pageTitle = 'Pergantian slide';
$backHref = '/admin/settings.php';
$backLabel = 'Pengaturan';
require __DIR__ . '/_header.php';
?>

<?php if ($errors !== []): ?>
    <div class="flash flash-error" role="alert"><?= Format::e(implode(' ', $errors)) ?></div>
<?php endif; ?>

<form method="post" class="stack">
    <?= Csrf::field() ?>

    <div class="card">
        <div class="row" style="grid-template-columns:minmax(0,1fr) auto">
            <label class="k" for="poster_show_seconds">Lama slide tampil<small>Detik, bawaan 15</small></label>
            <span class="stepper"><button type="button" data-step="-5" aria-label="Kurangi 5">−</button><input type="number" id="poster_show_seconds" name="poster_show_seconds" min="5" max="120" inputmode="numeric" value="<?= (int) $v['show'] ?>"><button type="button" data-step="5" aria-label="Tambah 5">+</button></span>
        </div>
        <div class="row" style="grid-template-columns:minmax(0,1fr) auto">
            <label class="k" for="poster_gap_seconds">Jeda antar slide<small>Detik jam tampil di antara slide, bawaan 15</small></label>
            <span class="stepper"><button type="button" data-step="-5" aria-label="Kurangi 5">−</button><input type="number" id="poster_gap_seconds" name="poster_gap_seconds" min="5" max="600" inputmode="numeric" value="<?= (int) $v['gap'] ?>"><button type="button" data-step="5" aria-label="Tambah 5">+</button></span>
        </div>
    </div>

    <p class="hint muted" style="margin:0;padding-left:4px;font-size:13px">
        Yang bergantian sekarang: <?= Format::e(implode(', ', $inRotation)) ?>.
        Slide tidak muncul saat pengingat adzan dan layar adzan tampil.
    </p>

    <div class="foot"><button type="submit" class="btn">Simpan</button></div>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
