<?php

/**
 * Smoke test: เขียนและลบข้อมูลทดสอบใน tax_invoices เพื่อยืนยันว่า Firebase RTDB เขียนได้
 *
 * Usage (จากโฟลเดอร์โปรเจกต์):
 *   php scripts/rtdb-tax-smoke-write.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/connect_database.php';

use Kreait\Firebase\Exception\DatabaseException;
use Theelincon\Rtdb\Db;

$diagPk = '__diag_tax_smoke_' . gmdate('YmdHis');
$diagRow = [
    'id' => $diagPk,
    'tax_invoice_number' => 'DIAG-TAX-SMOKE',
    'invoice_id' => 0,
    'tax_date' => gmdate('Y-m-d'),
    'subtotal' => 0.0,
    'vat_amount' => 0.0,
    'withholding_tax' => 0.0,
    'retention_amount' => 0.0,
    'grand_total' => 0.0,
];

try {
    Db::setRow('tax_invoices', $diagPk, $diagRow);
    $read = Db::row('tax_invoices', $diagPk);
    Db::deleteRow('tax_invoices', $diagPk);
    if ($read === null || ($read['tax_invoice_number'] ?? '') !== 'DIAG-TAX-SMOKE') {
        fwrite(STDERR, "FAILED: wrote but read-back mismatch.\n");
        exit(1);
    }
    echo "OK: Firebase RTDB write/read/delete succeeded for tax_invoices.\n";
    echo 'Path looks like: ' . Db::rootPath() . "/tax_invoices/{$diagPk}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    if ($e instanceof DatabaseException && $e->getPrevious() !== null) {
        fwrite(STDERR, 'Cause: ' . $e->getPrevious()->getMessage() . "\n");
    }
    exit(1);
}
