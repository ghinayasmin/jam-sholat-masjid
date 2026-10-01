<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Config;
use App\LocalTime;
use App\Repository\PublicationRepository;
use App\View\Format;
use App\View\Icon;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$repo = new PublicationRepository();
$today = LocalTime::today();

// REQ-F-022: hentikan, pasang ulang, atau hapus. Pengumuman baru lewat publications_add.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'stop') {
        $repo->setActive($id, false);
        $_SESSION['flash_success'] = 'Pengumuman dihentikan dan tidak tampil lagi di layar jamaah.';
    } elseif ($action === 'republish') {
        $repo->republish($id, $today);
        $_SESSION['flash_success'] = 'Pengumuman dipasang lagi mulai hari ini.';
    } elseif ($action === 'delete') {
        $pub = $repo->find($id);
        if ($pub !== null) {
            if ($pub['file_path'] !== '') {
                $filePath = Config::get('publications_path') . '/' . basename($pub['file_path']);
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
            }
            $repo->delete($id);
            $_SESSION['flash_success'] = 'Pengumuman dihapus.';
        }
    }
    header('Location: /admin/publications.php');
    exit;
}

$groups = $repo->groupedFor($today);

/** Satu baris pengumuman beserta tombol aksinya. */
$renderItem = static function (array $p, string $group) use ($today): void {
    $title = $p['caption'] !== null && $p['caption'] !== '' ? $p['caption'] : 'Poster tanpa keterangan';
    if ($group === 'showing') {
        $when = $p['ends_on'] !== null ? 'Sampai ' . Format::shortDate($p['ends_on']) : 'Tanpa batas waktu';
    } elseif ($group === 'scheduled') {
        $when = 'Mulai ' . Format::shortDate($p['starts_on']);
    } else {
        $when = (int) $p['is_active'] !== 1 ? 'Dihentikan' : 'Selesai ' . Format::shortDate($p['ends_on']);
    }
    ?>
    <div class="row" style="grid-template-columns:52px minmax(0,1fr) auto">
        <?php if ($p['file_path'] !== ''): ?>
            <img class="thumb" src="/uploads/publications/<?= rawurlencode(basename($p['file_path'])) ?>" alt="" loading="lazy">
        <?php else: ?>
            <span class="thumb"><?= Icon::svg('text') ?></span>
        <?php endif; ?>
        <span class="k"><?= Format::e($title) ?><small><?= Format::e($when) ?></small></span>
        <?php if ($group === 'scheduled'): ?><span class="tag">Menunggu</span><?php else: ?><span></span><?php endif; ?>
    </div>
    <div style="display:flex;gap:16px;padding:0 16px 10px 80px;margin-top:-8px">
        <?php if ($group === 'finished'): ?>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="republish"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="link-btn" style="color:var(--primary);min-height:32px;font-size:14px">Pasang lagi</button></form>
        <?php else: ?>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="stop"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="link-btn" style="color:var(--ink-2);min-height:32px;font-size:14px">Hentikan</button></form>
        <?php endif; ?>
        <form method="post" data-confirm="Hapus pengumuman ini? Tidak bisa dibatalkan."><?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button type="submit" class="link-btn" style="min-height:32px;font-size:14px">Hapus</button></form>
    </div>
    <?php
};

$pageTitle = 'Pengumuman';
$pageSubtitle = 'Tampil bergantian di layar jamaah';
$activeTab = 'announce';
require __DIR__ . '/_header.php';
?>

<a class="btn" href="/admin/publications_add.php">+ Pasang pengumuman</a>

<section class="group" aria-labelledby="showing-label">
    <div class="label" id="showing-label">Sedang tampil · <?= count($groups['showing']) ?></div>
    <div class="card">
        <?php foreach ($groups['showing'] as $p) { $renderItem($p, 'showing'); } ?>
        <?php if ($groups['showing'] === []): ?>
            <div class="empty">Belum ada pengumuman yang tampil di layar jamaah.</div>
        <?php endif; ?>
    </div>
</section>

<?php if ($groups['scheduled'] !== []): ?>
<section class="group" aria-labelledby="scheduled-label">
    <div class="label" id="scheduled-label">Terjadwal · <?= count($groups['scheduled']) ?></div>
    <div class="card"><?php foreach ($groups['scheduled'] as $p) { $renderItem($p, 'scheduled'); } ?></div>
</section>
<?php endif; ?>

<?php if ($groups['finished'] !== []): ?>
<section class="group" aria-labelledby="finished-label">
    <div class="label" id="finished-label">Sudah selesai · <?= count($groups['finished']) ?></div>
    <div class="card"><?php foreach ($groups['finished'] as $p) { $renderItem($p, 'finished'); } ?></div>
</section>
<?php endif; ?>

<div class="note">Poster paling bagus berbentuk mendatar (16:9). Foto dari kamera HP langsung bisa dipakai.</div>

<?php require __DIR__ . '/_footer.php'; ?>
