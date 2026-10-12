<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Murottal;
use App\LocalTime;
use App\Repository\SettingsRepository;
use App\Schedule\ScheduleEngine;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$repo = new SettingsRepository();
$tracks = Murottal::tracks();

// Putar/Jeda dari Beranda, atau pilih surat di halaman ini. Layanan alarm membaca
// murottal_enabled dan murottal_request tiap 3 detik (DECISIONS.md #18).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    if ($action === 'choose') {
        $file = basename((string) ($_POST['file'] ?? ''));
        if (in_array($file, array_column($tracks, 'file'), true)) {
            // Cap waktu membuat permintaan ulang untuk surat yang sama tetap terbaca baru.
            $repo->update(['murottal_enabled' => 1, 'murottal_request' => $file . '|' . microtime(true)]);
            $_SESSION['flash_success'] = 'Memutar Surah ' . Murottal::title($file) . ' dalam beberapa detik.';
        }
        header('Location: /admin/murottal.php');
        exit;
    }
    $play = $action === 'play';
    $repo->update(['murottal_enabled' => $play ? 1 : 0]);
    $_SESSION['flash_success'] = $play ? 'Murottal diputar dalam beberapa detik.' : 'Murottal dijeda.';
    header('Location: ' . (($_POST['back'] ?? '') === 'list' ? '/admin/murottal.php' : '/admin/'));
    exit;
}

$s = $repo->get();
$on = (int) $s['murottal_enabled'] === 1;
$now = (string) $s['murottal_now_playing'];
$nowMinutes = (int) gmdate('G', LocalTime::now()) * 60 + (int) gmdate('i', LocalTime::now());
$status = Murottal::status($s, (new ScheduleEngine())->getScheduleForDate(LocalTime::today()), $nowMinutes);

$pageTitle = 'Pilih surat';
$backHref = '/admin/';
$backLabel = 'Beranda';
require __DIR__ . '/_header.php';
?>

<?php if ($tracks === []): ?>
    <div class="card"><div class="empty">Belum ada file murottal di perangkat. Lihat public/assets/audio/README.md.</div></div>
<?php else: ?>
    <div class="card murottal-card" data-murottal-live>
        <span class="k"><?= $on ? 'Menyala' : 'Dijeda' ?><small data-murottal-status><?= Format::e($on ? $status : 'Ketuk surat untuk memutar') ?></small></span>
        <?php if ($on): ?>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="pause"><input type="hidden" name="back" value="list"><button type="submit" class="btn small ghost">Jeda</button></form>
        <?php endif; ?>
    </div>

    <section class="group" aria-labelledby="tracks-label">
        <div class="field">
            <input type="search" class="input" placeholder="Cari surat, mis. yasin atau 36" aria-label="Cari surat" data-filter="#trackList" autocomplete="off">
        </div>
        <div class="label" id="tracks-label"><?= count($tracks) ?> surat · diputar berurutan</div>
        <div class="card" id="trackList">
            <?php foreach ($tracks as $i => $t):
                $playing = $now !== '' && $now === $t['title']; ?>
                <form method="post" data-filter-text="<?= Format::e(($i + 1) . ' ' . $t['title'] . ' ' . $t['file']) ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="choose"><input type="hidden" name="file" value="<?= Format::e($t['file']) ?>">
                    <button type="submit" class="row track<?= $playing ? ' playing' : '' ?>" data-title="<?= Format::e($t['title']) ?>"<?= $playing ? ' aria-current="true"' : '' ?>>
                        <span class="n"><?= $i + 1 ?></span>
                        <span class="k">Surah <?= Format::e($t['title']) ?></span>
                        <span class="v<?= $playing ? ' ok' : '' ?>"><?= $playing ? 'Diputar' : 'Putar' ?></span>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
