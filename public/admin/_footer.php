<?php
use App\View\Icon;

$tabs = [
    'home' => ['/admin/dashboard.php', 'home', 'Beranda'],
    'announce' => ['/admin/publications.php', 'announce', 'Pengumuman'],
    'cash' => ['/admin/financial.php', 'cash', 'Kas'],
    'camera' => ['/admin/camera.php', 'camera', 'Kamera'],
    'settings' => ['/admin/settings.php', 'settings', 'Pengaturan'],
];
?>
    </main>
<?php if ($showTabs): ?>
    <div class="tabs">
        <nav aria-label="Menu utama">
        <?php foreach ($tabs as $key => [$href, $icon, $label]): ?>
            <a href="<?= $href ?>"<?= $activeTab === $key ? ' class="on" aria-current="page"' : '' ?>><?= Icon::svg($icon) ?><?= $label ?></a>
        <?php endforeach; ?>
        </nav>
    </div>
<?php endif; ?>
</div>
<?php if (!\App\SystemClock::isTrusted()): ?>
<div hidden data-clock-sync data-csrf="<?= \App\View\Format::e(\App\Auth\Csrf::token()) ?>"></div>
<?php endif; ?>
<script src="/assets/js/admin.js?v=<?= filemtime(dirname(__DIR__) . '/assets/js/admin.js') ?>"></script>
</body>
</html>
