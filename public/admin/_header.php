<?php
/**
 * Include di baris pertama SETIAP halaman admin (setelah bootstrap + auth check).
 * Variabel yang boleh diset sebelum include: $pageTitle, $activeMenu, $flash (opsional).
 */
use App\Auth\AuthManager;
use App\Auth\Csrf;

$pageTitle = $pageTitle ?? 'Admin';
$activeMenu = $activeMenu ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> — Panel Admin Masjid</title>
<link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
<div class="layout">
    <nav class="sidebar">
        <div class="sidebar-brand">🕌 Panel Takmir</div>
        <ul class="sidebar-menu">
            <li><a href="/admin/dashboard.php" class="<?= $activeMenu === 'dashboard' ? 'active' : '' ?>">Dashboard</a></li>
            <li><a href="/admin/settings_location.php" class="<?= $activeMenu === 'location' ? 'active' : '' ?>">Lokasi &amp; Jadwal</a></li>
            <li><a href="/admin/settings_time.php" class="<?= $activeMenu === 'time' ? 'active' : '' ?>">Jam &amp; Tanggal</a></li>
            <li><a href="/admin/mosque_profile.php" class="<?= $activeMenu === 'profile' ? 'active' : '' ?>">Profil Masjid</a></li>
            <li><a href="/admin/publications.php" class="<?= $activeMenu === 'publications' ? 'active' : '' ?>">Publikasi</a></li>
            <li><a href="/admin/financial.php" class="<?= $activeMenu === 'financial' ? 'active' : '' ?>">Laporan Keuangan</a></li>
            <li><a href="/admin/camera.php" class="<?= $activeMenu === 'camera' ? 'active' : '' ?>">Rekaman Kamera</a></li>
            <li><a href="/admin/backup.php" class="<?= $activeMenu === 'backup' ? 'active' : '' ?>">Backup &amp; Restore</a></li>
        </ul>
        <form method="post" action="/admin/logout.php" class="sidebar-logout">
            <?= Csrf::field() ?>
            <button type="submit">Keluar</button>
        </form>
    </nav>
    <main class="content">
        <header class="content-header">
            <h1><?= htmlspecialchars($pageTitle) ?></h1>
            <a href="/display.php" target="_blank" class="btn-secondary">Lihat Tampilan Jamaah ↗</a>
        </header>
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="flash flash-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="flash flash-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>
