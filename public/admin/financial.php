<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\LocalTime;
use App\Repository\FinancialRepository;
use App\View\Format;
use App\View\Icon;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$repo = new FinancialRepository();

// REQ-F-024: catatan baru, perbaikan, dan penghapusan lewat financial_add.php
// (ketuk catatan untuk mengubah atau menghapus, DECISIONS.md #37).

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
            <a class="row" href="/admin/financial_add.php?id=<?= (int) $e['id'] ?>" aria-label="Ubah catatan <?= Format::e($e['description']) ?>">
                <span class="badge-ico<?= $e['type'] === 'keluar' ? ' out' : '' ?>"><?= Icon::svg($e['type'] === 'masuk' ? 'in' : 'out') ?></span>
                <span class="k"><?= Format::e($e['description']) ?><small><?= Format::e(Format::relativeDate($e['entry_date'], $today)) ?></small></span>
                <span class="amount <?= $e['type'] === 'masuk' ? 'in' : 'out' ?>"><?= Format::signedAmount($e['type'], (int) $e['amount']) ?></span>
            </a>
        <?php endforeach; ?>
        <?php if ($entries === []): ?>
            <div class="empty">Belum ada catatan. Mulai dengan menekan "Uang masuk" atau "Uang keluar".</div>
        <?php endif; ?>
        <?php if (!$showAll && $total > count($entries)): ?>
            <a class="row" href="/admin/financial.php?all=1"><span></span><span class="k" style="color:var(--primary)">Lihat semua catatan (<?= $total ?>)</span><?= Icon::svg('chevron', 'icon chev') ?></a>
        <?php endif; ?>
    </div>
    <?php if ($entries !== []): ?>
        <p class="aside spaced">Salah catat? Ketuk catatannya untuk memperbaiki atau menghapus.</p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
