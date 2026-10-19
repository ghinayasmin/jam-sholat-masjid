<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\LocalTime;
use App\Repository\FinancialRepository;
use App\Repository\SettingsRepository;
use App\View\Format;

header('Content-Type: application/json; charset=utf-8');

try {
    // Endpoint ini publik (dipakai layar jamaah). Data kas internal masjid hanya
    // dikeluarkan bila takmir menyalakan "Tampilkan saldo kas" — DECISIONS.md #14.
    $settings = (new SettingsRepository())->get();
    if ((int) $settings['show_finance_on_display'] !== 1) {
        echo json_encode(['success' => true, 'enabled' => false]);
        exit;
    }

    // Layar jamaah menampilkan saldo dan total bulan ini; rincian transaksi tetap internal.
    $repo = new FinancialRepository();
    $month = substr(LocalTime::today(), 0, 7);
    $totals = $repo->monthTotals($month);
    echo json_encode([
        'success' => true,
        'enabled' => true,
        'saldo_berjalan' => $repo->currentBalance(),
        'bulan' => Format::monthName($month),
        'masuk_bulan_ini' => $totals['masuk'],
        'keluar_bulan_ini' => $totals['keluar'],
        'diperbarui' => $repo->lastEntryDate(),
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[api/financial] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal memuat laporan keuangan.']);
}
