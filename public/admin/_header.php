<?php
/**
 * Include di setiap halaman admin (setelah bootstrap + auth check).
 * Variabel yang boleh diset sebelum include:
 *   $pageTitle     judul halaman
 *   $pageSubtitle  teks kecil di bawah judul (halaman tab)
 *   $activeTab     'home' | 'announce' | 'cash' | 'camera' | 'settings' (tab bawah yang menyala)
 *   $backHref      bila diisi, halaman tampil sebagai sub-halaman dengan tombol kembali (tanpa tab bawah)
 *   $backLabel     teks tombol kembali
 */
use App\View\Format;
use App\View\Icon;

$pageTitle = $pageTitle ?? 'Panel Takmir';
$pageSubtitle = $pageSubtitle ?? null;
$activeTab = $activeTab ?? null;
$backHref = $backHref ?? null;
$backLabel = $backLabel ?? 'Kembali';
$showTabs = $backHref === null;
?>
<!DOCTYPE html>
<html lang="id" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="google" content="notranslate">
<meta name="theme-color" content="#F2F3EF">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Panel Takmir">
<meta name="mobile-web-app-capable" content="yes">
<title><?= Format::e($pageTitle) ?> · Panel Takmir</title>
<link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/admin.css') ?>">
</head>
<body>
<div class="shell<?= $showTabs ? ' has-tabs' : '' ?>">
<?php if ($backHref !== null): ?>
    <header class="topbar">
        <a class="back" href="<?= Format::e($backHref) ?>" aria-label="Kembali ke <?= Format::e($backLabel) ?>"><?= Icon::svg('back') ?></a>
        <h1><?= Format::e($pageTitle) ?></h1>
        <span></span>
    </header>
<?php else: ?>
    <header class="page-head">
        <h1><?= Format::e($pageTitle) ?></h1>
        <?php if ($pageSubtitle !== null): ?><p><?= Format::e($pageSubtitle) ?></p><?php endif; ?>
    </header>
<?php endif; ?>
    <main class="content">
<?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="flash flash-success" role="status"><?= Format::e($_SESSION['flash_success']) ?></div>
        <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="flash flash-error" role="alert"><?= Format::e($_SESSION['flash_error']) ?></div>
        <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
