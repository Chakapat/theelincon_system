<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/tnc_action_response.php';
require_once __DIR__ . '/../includes/tnc_audit_log.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_can_edit_invoice()) {
    http_response_code(403);
    exit;
}

if (!csrf_verify_request()) {
    http_response_code(403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['invoice_id'])) {
    exit;
}

$invoice_id = (int) $_POST['invoice_id'];
$company_id = (int) $_POST['company_id'];
$customer_id = (int) $_POST['customer_id'];
$invoice_number = trim((string) ($_POST['invoice_number'] ?? ''));
$issue_date = trim((string) ($_POST['issue_date'] ?? ''));
$vat_enabled = isset($_POST['vat_enabled']);
$withholding_enabled = isset($_POST['withholding_enabled']);
$retention_amount = (float) ($_POST['retention_amount'] ?? 0);
$rounding_enabled = isset($_POST['rounding_enabled']);

$money2 = static function (float $value) use ($rounding_enabled): float {
    if ($rounding_enabled) {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }
    return $value >= 0 ? floor($value * 100) / 100 : ceil($value * 100) / 100;
};

if ($invoice_number === '') {
    $curInv = Db::row('invoices', (string) $invoice_id) ?? [];
    $invoice_number = trim((string) ($curInv['invoice_number'] ?? ''));
}

$beforeInv = Db::row('invoices', (string) $invoice_id) ?? [];
$beforeLines = [];
foreach (Db::filter('invoice_items', static function (array $r) use ($invoice_id): bool {
    return isset($r['invoice_id']) && (int) $r['invoice_id'] === $invoice_id;
}) as $ln) {
    if (!is_array($ln)) {
        continue;
    }
    $beforeLines[] = $ln;
    if (count($beforeLines) >= 120) {
        break;
    }
}

Db::deleteWhereEquals('invoice_items', 'invoice_id', (string) $invoice_id);

$subtotal = 0.0;
$current_running = 0.0;
foreach ($_POST['description'] ?? [] as $key => $desc) {
    $qty = (float) ($_POST['quantity'][$key] ?? 0);
    $price_input = trim((string) ($_POST['price'][$key] ?? ''));
    $unit = (string) ($_POST['unit'][$key] ?? '');

    if (strpos($price_input, '%') !== false) {
        $percent = (float) str_replace('%', '', $price_input);
        $line_total = $money2($current_running * ($percent / 100));
        $final_unit_price = round($line_total / ($qty ?: 1), 4);
    } else {
        $final_unit_price = (float) $price_input;
        $line_total = $money2($final_unit_price);
    }

    $subtotal += $line_total;
    $current_running += $line_total;

    $iid = Db::nextNumericId('invoice_items', 'id');
    Db::setRow('invoice_items', (string) $iid, [
        'id' => $iid,
        'invoice_id' => $invoice_id,
        'description' => (string) $desc,
        'quantity' => $qty,
        'unit' => $unit,
        'unit_price' => $final_unit_price,
        'total' => $line_total,
    ]);
}

$subtotal = $money2($subtotal);
$vat_amount = $vat_enabled ? $money2($subtotal * 0.07) : 0.0;
$wht_amount = $withholding_enabled ? $money2($subtotal * 0.03) : 0.0;
$total_amount = $money2($subtotal + $vat_amount - $wht_amount - $retention_amount);

$cur = Db::row('invoices', (string) $invoice_id) ?? [];
Db::setRow('invoices', (string) $invoice_id, array_merge($cur, [
    'invoice_number' => $invoice_number,
    'company_id' => $company_id,
    'customer_id' => $customer_id,
    'issue_date' => $issue_date,
    'subtotal' => $subtotal,
    'vat_amount' => $vat_amount,
    'withholding_tax' => $wht_amount,
    'retention_amount' => $retention_amount,
    'total_amount' => $total_amount,
    'rounding_enabled' => $rounding_enabled ? 1 : 0,
]));

$afterInv = Db::row('invoices', (string) $invoice_id) ?? [];
$afterLines = [];
foreach (Db::filter('invoice_items', static function (array $r) use ($invoice_id): bool {
    return isset($r['invoice_id']) && (int) $r['invoice_id'] === $invoice_id;
}) as $ln) {
    if (!is_array($ln)) {
        continue;
    }
    $afterLines[] = $ln;
    if (count($afterLines) >= 120) {
        break;
    }
}
tnc_audit_log('update', 'invoice', (string) $invoice_id, $invoice_number !== '' ? $invoice_number : ('#' . $invoice_id), [
    'source' => 'invoice-update.php',
    'action' => 'save_invoice',
    'before' => $beforeInv,
    'after' => $afterInv,
    'meta' => [
        'lines_before' => $beforeLines,
        'lines_after' => $afterLines,
    ],
]);

tnc_action_redirect(app_path('index.php') . '?invoice_updated=1');
