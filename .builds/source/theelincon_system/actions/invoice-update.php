<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['invoice_id'])) {
    exit;
}

$invoice_id = (int) $_POST['invoice_id'];
$company_id = (int) $_POST['company_id'];
$customer_id = (int) $_POST['customer_id'];
$issue_date = trim((string) ($_POST['issue_date'] ?? ''));
$vat_enabled = isset($_POST['vat_enabled']);
$withholding_enabled = isset($_POST['withholding_enabled']);
$retention_amount = (float) ($_POST['retention_amount'] ?? 0);

Db::deleteWhereEquals('invoice_items', 'invoice_id', (string) $invoice_id);

$subtotal = 0.0;
foreach ($_POST['description'] ?? [] as $key => $desc) {
    $qty = (float) ($_POST['quantity'][$key] ?? 0);
    $u_price = (float) ($_POST['price'][$key] ?? 0);
    $line_total = $qty * $u_price;
    $subtotal += $line_total;
    $unit = (string) ($_POST['unit'][$key] ?? '');

    $iid = Db::nextNumericId('invoice_items', 'id');
    Db::setRow('invoice_items', (string) $iid, [
        'id' => $iid,
        'invoice_id' => $invoice_id,
        'description' => (string) $desc,
        'quantity' => $qty,
        'unit' => $unit,
        'unit_price' => $u_price,
        'total' => $line_total,
    ]);
}

$vat_amount = $vat_enabled ? ($subtotal * 0.07) : 0.0;
$wht_amount = $withholding_enabled ? ($subtotal * 0.03) : 0.0;
$total_amount = $subtotal + $vat_amount - $wht_amount - $retention_amount;

$cur = Db::row('invoices', (string) $invoice_id) ?? [];
Db::setRow('invoices', (string) $invoice_id, array_merge($cur, [
    'company_id' => $company_id,
    'customer_id' => $customer_id,
    'issue_date' => $issue_date,
    'subtotal' => $subtotal,
    'vat_amount' => $vat_amount,
    'withholding_tax' => $wht_amount,
    'retention_amount' => $retention_amount,
    'total_amount' => $total_amount,
]));

$taxRow = Db::findFirst('tax_invoices', static function (array $r) use ($invoice_id): bool {
    return isset($r['invoice_id']) && (int) $r['invoice_id'] === $invoice_id;
});
if ($taxRow !== null) {
    $tpk = (string) ($taxRow['id'] ?? '');
    if ($tpk !== '') {
        Db::mergeRow('tax_invoices', $tpk, [
            'tax_date' => $issue_date,
            'subtotal' => $subtotal,
            'vat_amount' => $vat_amount,
            'withholding_tax' => $wht_amount,
            'retention_amount' => $retention_amount,
            'grand_total' => $total_amount,
        ]);
    }
}

header('Location: ' . app_path('index.php') . '?invoice_updated=1');
exit;
