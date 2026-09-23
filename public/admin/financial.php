<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\Repository\FinancialRepository;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$repo = new FinancialRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $date = trim((string) ($_POST['entry_date'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $type = $_POST['type'] ?? '';
        $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_INT);

        $errors = [];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors[] = 'Tanggal tidak valid.';
        }
        if ($description === '') {
            $errors[] = 'Keterangan wajib diisi.';
        }
        if (!in_array($type, ['masuk', 'keluar'], true)) {
            $errors[] = 'Jenis transaksi tidak valid.';
        }
        if ($amount === false || $amount === null || $amount <= 0) {
            $errors[] = 'Jumlah harus angka lebih dari 0.';
        }

        if ($errors === []) {
            $repo->create($date, $description, $type, $amount, $_SESSION['admin_id'] ?? null);
            $_SESSION['flash_success'] = 'Transaksi berhasil ditambahkan.';
        } else {
            $_SESSION['flash_error'] = implode(' ', $errors);
        }
    } elseif ($action === 'delete') {
        $repo->delete((int) ($_POST['id'] ?? 0));
        $_SESSION['flash_success'] = 'Transaksi dihapus.';
    }

    header('Location: /admin/financial.php');
    exit;
}

$entries = $repo->all();
$balance = $repo->currentBalance();

$pageTitle = 'Laporan Keuangan';
$activeMenu = 'financial';
require __DIR__ . '/_header.php';
?>

<div class="card">
    <h2>Saldo Berjalan</h2>
    <p class="mono-large">Rp <?= number_format($balance, 0, ',', '.') ?></p>
</div>

<div class="card form-card">
    <h2>Tambah Transaksi</h2>
    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create">
        <label>Tanggal
            <input type="date" name="entry_date" value="<?= htmlspecialchars(gmdate('Y-m-d')) ?>" required>
        </label>
        <label>Keterangan
            <input type="text" name="description" maxlength="200" required>
        </label>
        <label>Jenis
            <select name="type" required>
                <option value="masuk">Pemasukan</option>
                <option value="keluar">Pengeluaran</option>
            </select>
        </label>
        <label>Jumlah (Rp)
            <input type="number" name="amount" min="1" required>
        </label>
        <button type="submit">Tambah</button>
    </form>
</div>

<div class="card">
    <h2>Riwayat Transaksi</h2>
    <table class="data-table">
        <thead><tr><th>Tanggal</th><th>Keterangan</th><th>Jenis</th><th>Jumlah</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $e): ?>
            <tr>
                <td><?= htmlspecialchars($e['entry_date']) ?></td>
                <td><?= htmlspecialchars($e['description']) ?></td>
                <td><?= $e['type'] === 'masuk' ? 'Pemasukan' : 'Pengeluaran' ?></td>
                <td>Rp <?= number_format((int) $e['amount'], 0, ',', '.') ?></td>
                <td>
                    <form method="post" class="inline-form" onsubmit="return confirm('Hapus transaksi ini?');">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                        <button type="submit" class="btn-small btn-danger">Hapus</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($entries === []): ?>
            <tr><td colspan="5" class="muted">Belum ada transaksi.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
