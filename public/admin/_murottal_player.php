<?php
/**
 * Pemutar murottal (#40): judul, progres, Sebelumnya / Putar-Jeda / Berikutnya.
 * Dipakai di Beranda ($mpVariant = 'compact') dan halaman Pilih surat ('full').
 * Butuh: $mp (Murottal::player()), $mpOn (sakelar Putar), $mpStatus, $mpVariant, $mpBack.
 * admin.js memperbarui isinya tiap 3 detik dari /api/murottal.php.
 */

use App\Auth\Csrf;
use App\Murottal;
use App\View\Format;

$mpPercent = $mp['duration'] ? min(100, round($mp['position'] / $mp['duration'] * 100, 1)) : 0;
$mpResume = !$mpOn && $mp['position'] > 0;
$mpButton = static function (string $action, string $label, string $icon, string $class) use ($mpBack): void { ?>
    <form method="post" action="/admin/murottal.php" class="mp-form"><?= Csrf::field() ?>
        <input type="hidden" name="action" value="<?= $action ?>"><input type="hidden" name="back" value="<?= $mpBack ?>">
        <button type="submit" class="<?= $class ?>" aria-label="<?= Format::e($label) ?>"><?= $icon ?></button>
    </form>
<?php };
$svgPrev = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 20L9 12l10-8v16z"/><path d="M5 19V5"/></svg>';
$svgNext = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4l10 8-10 8V4z"/><path d="M19 5v14"/></svg>';
$svgPlay = '<svg viewBox="0 0 24 24" aria-hidden="true" class="fill"><path d="M7 4l13 8-13 8V4z"/></svg>';
$svgPause = '<svg viewBox="0 0 24 24" aria-hidden="true" class="fill"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>';
?>
<div class="mp mp-<?= $mpVariant ?>" data-murottal-live data-position="<?= $mp['position'] ?>" data-duration="<?= (int) $mp['duration'] ?>" data-playing="<?= $mp['playing'] ? '1' : '0' ?>">
    <div class="mp-head">
        <span class="mp-eyebrow" data-mp-state><?= $mp['playing'] ? 'Sedang diputar' : ($mpOn ? 'Menyala' : ($mpResume ? 'Dijeda' : 'Berhenti')) ?></span>
        <span class="mp-count">Surah <span data-mp-number><?= $mp['number'] ?></span> dari <?= $mp['count'] ?></span>
    </div>
    <div class="mp-title" data-mp-title>Surah <?= Format::e($mp['title']) ?></div>
    <div class="mp-status" data-murottal-status><?= Format::e($mpStatus) ?></div>
    <div class="mp-bar" role="progressbar" aria-label="Posisi surat" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $mpPercent ?>"><i data-mp-fill style="width:<?= $mpPercent ?>%"></i></div>
    <div class="mp-times"><span data-mp-pos><?= Murottal::clock($mp['position']) ?></span><span><?= $mp['duration'] ? Murottal::clock($mp['duration']) : '--:--' ?></span></div>
    <div class="mp-controls">
        <?php $mpButton('prev', 'Surat sebelumnya', $svgPrev, 'mp-btn'); ?>
        <form method="post" action="/admin/murottal.php" class="mp-form mp-main-form"><?= Csrf::field() ?>
            <input type="hidden" name="action" value="<?= $mpOn ? 'pause' : 'play' ?>"><input type="hidden" name="back" value="<?= $mpBack ?>">
            <?php if ($mpOn): ?>
                <button type="submit" class="mp-main" aria-label="Jeda"><?= $svgPause ?><span>Jeda</span></button>
            <?php else: ?>
                <button type="submit" class="mp-main"><?= $svgPlay ?><span data-mp-resume><?= $mpResume ? 'Lanjutkan dari ' . Murottal::clock($mp['position']) : 'Putar' ?></span></button>
            <?php endif; ?>
        </form>
        <?php $mpButton('next', 'Surat berikutnya', $svgNext, 'mp-btn'); ?>
    </div>
    <?php if ($mp['position'] > 0): ?>
        <form method="post" action="/admin/murottal.php" class="mp-restart"><?= Csrf::field() ?>
            <input type="hidden" name="action" value="restart"><input type="hidden" name="back" value="<?= $mpBack ?>">
            <button type="submit" class="link-btn">Mulai ulang dari awal surat</button>
        </form>
    <?php endif; ?>
</div>
