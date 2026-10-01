<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\LocalTime;
use App\Repository\FinancialRepository;
use App\View\Format;
use App\View\Icon;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$repo = new FinancialRepository();

// REQ-F-024: hapus catatan. Catatan baru ditambahkan lewat financial_add.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    if (($_POST['action'] ?? '') === 'delete') {
        $repo->delete((int) ($_POST['id'] ?? 0));
        $_SESSION['flash_success'] = 'Catatan dihapus.';
    }
    header('Location: /admin/financial.php' . (isset($_GET['all']) ? '?all=1' : ''));
    exit;
}

$today = LocalTime::today();
$month = substr($today, 0, 7);
$showAll = isset($_GET['all']);
$entries = $showAll ? $repo->all() : $repo->recent(6);
$total = count($repo->all());
$balance = $repo->currentBalance();
$monthTotals = $repo->monthTotals($month);
$monthName = Format::monthName($month);

$pageTitle = 'Kas masjid';
$pageSubtitle = 'Hanya terlihat oleh pengurus';
$activeTab = 'cash';
require __DIR__ . '/_header.php';
?>

<div class="balance">
    <div><div class="s">Saldo saat ini</div><div class="n"><?= Format::e(Format::rupiah($balance)) ?></div></div>
    <div class="split">
        <div>Masuk <?= $monthName ?><b class="in">+ <?= Format::e(Format::rupiah($monthTotals['masuk'])) ?></b></div>
        <div>Keluar <?= $monthName ?><b class="out">− <?= Format::e(Format::rupiah($monthTotals['keluar'])) ?></b></div>
    </div>
</div>

<div class="pair">
    <a class="btn" href="/admin/financial_add.php?type=masuk">+ Uang masuk</a>
    <a class="btn ghost" href="/admin/financial_add.php?type=keluar">− Uang keluar</a>
</div>

<section class="group" aria-labelledby="entries-label">
    <div class="label" id="entries-label"><?= $showAll ? 'Semua catatan · ' . $total : 'Terakhir dicatat' ?></div>
    <div class="card">
        <?php foreach ($entries as $e): ?>
            <div class="row">
                <span class="badge-ico<?= $e['type'] === 'keluar' ? ' out' : '' ?>"><?= Icon::svg($e['type'] === 'masuk' ? 'in' : 'out') ?></span>
                <span class="k"><?= Format::e($e['description']) ?><small><?= Format::e(Format::relativeDate($e['entry_date'], $today)) ?></small></span>
                <span class="amount <?= $e['type'] === 'masuk' ? 'in' : 'out' ?>"><?= Format::signedAmount($e['type'], (int) $e['amount']) ?></span>
            </div>
            <?php if ($showAll): ?>
                <form method="post" data-confirm="Hapus catatan &quot;<?= Format::e($e['description']) ?>&quot;?" style="margin:-10px 0 0;padding:0 16px 8px 62px">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                    <button type="submit" class="link-btn" style="min-height:32px;font-size:13px">Hapus catatan ini</button>
                </form>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($entries === []): ?>
            <div class="empty">Belum ada catatan. Mulai dengan menekan "Uang masuk" atau "Uang keluar".</div>
        <?php endif; ?>
        <?php if (!$showAll && $total > count($entries)): ?>
            <a class="row" href="/admin/financial.php?all=1"><span></span><span class="k" style="color:var(--primary)">Lihat semua catatan (<?= $total ?>)</span><?= Icon::svg('chevron', 'icon chev') ?></a>
        <?php elseif (!$showAll && $entries !== []): ?>
            <a class="row" href="/admin/financial.php?all=1"><span></span><span class="k" style="color:var(--primary)">Hapus catatan yang salah</span><?= Icon::svg('chevron', 'icon chev') ?></a>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
