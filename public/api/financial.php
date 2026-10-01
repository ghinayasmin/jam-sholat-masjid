<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Repository\FinancialRepository;
use App\Repository\SettingsRepository;

header('Content-Type: application/json; charset=utf-8');

try {
    // Endpoint ini publik (dipakai layar jamaah). Data kas internal masjid hanya
    // dikeluarkan bila takmir menyalakan "Tampilkan saldo kas" — DECISIONS.md #14.
    $settings = (new SettingsRepository())->get();
    if ((int) $settings['show_finance_on_display'] !== 1) {
        echo json_encode(['success' => true, 'enabled' => false]);
        exit;
    }

    $repo = new FinancialRepository();
    $entries = $repo->recent(10);

    $out = array_map(static function (array $e): array {
        return [
            'date' => $e['entry_date'],
            'description' => $e['description'],
            'type' => $e['type'],
            'amount' => (int) $e['amount'],
        ];
    }, $entries);

    echo json_encode([
        'success' => true,
        'enabled' => true,
        'saldo_berjalan' => $repo->currentBalance(),
        'entries' => $out,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[api/financial] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal memuat laporan keuangan.']);
}
