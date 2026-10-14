<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Auth\AuthManager;
use App\Auth\AuthMiddleware;
use App\Auth\Csrf;
use App\LocalTime;
use App\Repository\FinancialRepository;
use App\View\Format;

$auth = new AuthManager();
AuthMiddleware::requireLogin($auth);

$repo = new FinancialRepository();
$type = ($_GET['type'] ?? $_POST['type'] ?? 'masuk') === 'keluar' ? 'keluar' : 'masuk';
$today = LocalTime::today();

// Pilihan siap ketuk. Pilihan "Lainnya" membiarkan pengurus menulis sendiri di catatan.
$sources = $type === 'masuk'
    ? ['Kotak Jumat', 'Infaq', 'Zakat', 'Donatur tetap', 'Lainnya']
    : ['Listrik', 'Air', 'Kebersihan', 'Kegiatan', 'Perbaikan', 'Lainnya'];

$values = ['amount' => '', 'source' => $sources[0], 'note' => '', 'entry_date' => $today];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['csrf_token'] ?? null);

    $values['amount'] = (string) ($_POST['amount'] ?? '');
    $values['source'] = (string) ($_POST['source'] ?? '');
    $values['note'] = trim((string) ($_POST['note'] ?? ''));
    $values['entry_date'] = trim((string) ($_POST['entry_date'] ?? ''));

    // PHP di Pi 32-bit: int mentok di ±2,1 miliar, jadi batasi di bawahnya (10 digit
    // ke atas ditolak sebelum diubah ke int agar tidak diam-diam terpotong).
    $digits = ltrim((string) preg_replace('/\D/', '', $values['amount']), '0');
    $amount = strlen($digits) > 9 ? -1 : (int) $digits;
    if ($amount === -1) {
        $errors[] = 'Jumlah per catatan maksimal Rp 999.999.999.';
    } elseif ($amount <= 0) {
        $errors[] = 'Isi jumlah uangnya, lebih dari Rp 0.';
    }
    if (!in_array($values['source'], $sources, true)) {
        $errors[] = 'Pilih salah satu jenis.';
    }
    if ($values['source'] === 'Lainnya' && $values['note'] === '') {
        $errors[] = 'Untuk "Lainnya", tulis keterangannya di catatan.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['entry_date']) || $values['entry_date'] > $today) {
        $errors[] = 'Tanggal tidak valid atau masih di masa depan.';
    }

    if ($errors === []) {
        // Keterangan = jenis, ditambah catatan bila ada: "Kotak Jumat · Kotak amal 25 Sep".
        $description = $values['source'] === 'Lainnya'
            ? $values['note']
            : $values['source'] . ($values['note'] !== '' ? ' · ' . $values['note'] : '');
        $repo->create($values['entry_date'], mb_substr($description, 0, 200), $type, $amount, $_SESSION['admin_id'] ?? null);
        $_SESSION['flash_success'] = ($type === 'masuk' ? 'Uang masuk ' : 'Uang keluar ') . Format::rupiah($amount)
            . ' tersimpan. Saldo sekarang ' . Format::rupiah($repo->currentBalance()) . '.';
        header('Location: /admin/financial.php');
        exit;
    }
}

$balance = $repo->currentBalance();

$pageTitle = $type === 'masuk' ? 'Uang masuk' : 'Uang keluar';
$backHref = '/admin/financial.php';
$backLabel = 'Kas';
require __DIR__ . '/_header.php';
?>

<?php if ($errors !== []): ?>
    <div class="flash flash-error" role="alert"><?= Format::e(implode(' ', $errors)) ?></div>
<?php endif; ?>

<form method="post" class="stack" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="type" value="<?= $type ?>">

    <div class="field">
        <label class="field-label" for="amount">Jumlah</label>
        <div class="money"><span>Rp</span>
            <input type="text" id="amount" name="amount" inputmode="numeric" autocomplete="off" placeholder="0"
                   value="<?= Format::e($values['amount']) ?>" data-money required>
        </div>
        <div class="quick">
            <button type="button" data-quick="50000">50 rb</button>
            <button type="button" data-quick="100000">100 rb</button>
            <button type="button" data-quick="200000">200 rb</button>
            <button type="button" data-quick="500000">500 rb</button>
        </div>
    </div>

    <fieldset class="field" style="border:0;margin:0;padding:0">
        <legend class="field-label" style="margin-bottom:8px"><?= $type === 'masuk' ? 'Dari mana?' : 'Untuk apa?' ?></legend>
        <div class="chips">
            <?php foreach ($sources as $i => $source): ?>
                <label class="chip"><input type="radio" name="source" value="<?= Format::e($source) ?>"<?= $values['source'] === $source ? ' checked' : '' ?>><span><?= Format::e($source) ?></span></label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <div class="field">
        <label class="field-label" for="note">Catatan <span class="opt">(boleh kosong)</span></label>
        <input type="text" id="note" name="note" maxlength="150" value="<?= Format::e($values['note']) ?>"
               placeholder="<?= $type === 'masuk' ? 'Contoh: kotak amal Jumat pekan ini' : 'Contoh: token listrik 100 kWh' ?>">
    </div>

    <div class="field">
        <label class="field-label" for="entry_date">Tanggal</label>
        <input type="date" id="entry_date" name="entry_date" max="<?= $today ?>" value="<?= Format::e($values['entry_date']) ?>" required>
    </div>

    <div class="note" data-balance-after="<?= $balance ?>" data-sign="<?= $type === 'masuk' ? 1 : -1 ?>">
        Saldo menjadi <b><?= Format::e(Format::rupiah($balance)) ?></b> setelah disimpan.
    </div>

    <div class="foot"><button type="submit" class="btn"><?= $type === 'masuk' ? 'Simpan uang masuk' : 'Simpan uang keluar' ?></button></div>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
