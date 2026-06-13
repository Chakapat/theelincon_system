<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_print/vat_print_summary.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function tnc_vat_render_doc_no(string $label, string $url = ''): string
{
    if ($label === '') {
        return '—';
    }
    if ($url === '') {
        return h($label);
    }

    return '<a href="' . h($url) . '" class="vat-doc-link">' . h($label) . '</a>';
}

function tnc_vat_csv_cell(string $value): string
{
    $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
    if (preg_match('/[",;]/', $value) === 1) {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    return $value;
}

/** @param list<string|float|int> $cells */
function tnc_vat_csv_row(array $cells): string
{
    $out = [];
    foreach ($cells as $cell) {
        $out[] = tnc_vat_csv_cell((string) $cell);
    }

    return implode(',', $out) . "\r\n";
}

function tnc_doc_is_active(array $row): bool
{
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    if ($status === '') {
        return true;
    }
    return !in_array($status, ['cancelled', 'canceled', 'void', 'deleted'], true);
}

function tnc_vat_is_7_percent(float $base, float $vat, float $declaredRate = 0.0): bool
{
    if ($vat <= 0.0 || $base <= 0.0) {
        return false;
    }
    if ($declaredRate > 0.0) {
        return abs($declaredRate - 7.0) <= 0.05;
    }
    $effective = ($vat / $base) * 100;
    return abs($effective - 7.0) <= 0.15;
}

function tnc_vat_is_cash_bill_purchase(string $billNo, string $supplierName): bool
{
    $cashLabel = mb_strtolower(trim('บิลเงินสด'));
    if ($cashLabel === '') {
        return false;
    }
    $billNorm = mb_strtolower(trim($billNo));
    $supplierNorm = mb_strtolower(trim($supplierName));

    return $billNorm === $cashLabel || $supplierNorm === $cashLabel;
}

/**
 * หาเลขที่บิล/ใบกำกับภาษี (ภาษีซื้อ) ที่ซ้ำกันในรายงาน
 *
 * @param list<array<string, mixed>> $purchaseRows
 * @return list<array{bill_no: string, count: int, indexes: list<int>}>
 */
function tnc_vat_find_duplicate_purchase_bills(array $purchaseRows): array
{
    /** @var array<string, list<int>> $grouped */
    $grouped = [];
    foreach ($purchaseRows as $idx => $row) {
        $billNo = trim((string) ($row['bill_no'] ?? ''));
        if ($billNo === '') {
            continue;
        }
        if (tnc_vat_is_cash_bill_purchase($billNo, (string) ($row['supplier_name'] ?? ''))) {
            continue;
        }
        $key = mb_strtolower($billNo);
        if (!isset($grouped[$key])) {
            $grouped[$key] = [];
        }
        $grouped[$key][] = (int) $idx;
    }

    $dupes = [];
    foreach ($grouped as $indexes) {
        if (count($indexes) < 2) {
            continue;
        }
        $first = $purchaseRows[$indexes[0]] ?? [];
        $dupes[] = [
            'bill_no' => trim((string) ($first['bill_no'] ?? '')),
            'count' => count($indexes),
            'indexes' => $indexes,
        ];
    }

    usort($dupes, static function (array $a, array $b): int {
        return strcmp($a['bill_no'], $b['bill_no']);
    });

    return $dupes;
}

/**
 * @param list<array<string, mixed>> $purchaseRows
 * @param list<array{bill_no: string, count: int, indexes: list<int>}> $duplicateBills
 */
function tnc_vat_mark_duplicate_purchase_bills(array &$purchaseRows, array $duplicateBills): void
{
    $duplicateIndexes = [];
    foreach ($duplicateBills as $group) {
        foreach ($group['indexes'] as $idx) {
            $duplicateIndexes[(int) $idx] = true;
        }
    }
    foreach ($purchaseRows as $idx => &$row) {
        $row['is_duplicate_bill'] = isset($duplicateIndexes[$idx]);
    }
    unset($row);
}

/**
 * @param list<array{bill_no: string, count: int, indexes: list<int>}> $duplicateBills
 */
function tnc_vat_render_duplicate_bill_alert(array $duplicateBills): string
{
    if ($duplicateBills === []) {
        return '';
    }

    ob_start();
    ?>
    <div class="alert alert-warning border-warning py-3 mb-3 vat-duplicate-bill-alert" role="alert">
        <div class="d-flex gap-2 align-items-start">
            <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
            <div>
                <div class="fw-bold mb-1">พบเลขที่บิล / ใบกำกับภาษีซ้ำกัน <?= count($duplicateBills) ?> รายการ</div>
                <p class="small mb-2 text-secondary">กรุณาตรวจสอบและแก้ไขข้อมูล PO หรือบิลที่ซ้ำก่อนยื่น VAT</p>
                <ul class="small mb-0 ps-3">
                    <?php foreach ($duplicateBills as $group): ?>
                        <li>
                            <strong><?= h($group['bill_no']) ?></strong>
                            — ปรากฏ <?= (int) $group['count'] ?> ครั้ง
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * @param list<array<string, mixed>> $rows
 */
function tnc_vat_render_table_html(
    bool $isSales,
    array $rows,
    float $sumBase,
    float $sumVat,
    float $sumNet,
    bool $withDocLinks = false
): string {
    ob_start();
    ?>
    <div class="vat-print-table-wrap">
        <table class="table table-bordered table-sm vat-print-table mb-0">
            <colgroup>
                <col class="col-date">
                <col class="col-doc">
                <col class="col-name">
                <col class="col-amt">
                <col class="col-amt">
                <col class="col-amt">
            </colgroup>
            <thead>
            <tr>
                <?php if ($isSales): ?>
                    <th>วันที่เอกสาร</th>
                    <th>เลขที่ใบกำกับภาษี</th>
                    <th>ชื่อลูกค้า</th>
                <?php else: ?>
                    <th>วันที่บิล</th>
                    <th>เลขที่บิล/ใบกำกับภาษี</th>
                    <th>ชื่อซัพพลายเออร์</th>
                <?php endif; ?>
                <th class="text-end">มูลค่าสินค้า/บริการ</th>
                <th class="text-end">จำนวน VAT (7%)</th>
                <th class="text-end">ยอดรวมสุทธิ</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">ไม่พบข้อมูลตามเงื่อนไข</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $docNo = (string) ($isSales ? ($row['invoice_no'] ?? '') : ($row['bill_no'] ?? ''));
                    $nameCol = (string) ($isSales ? ($row['customer_name'] ?? '') : ($row['supplier_name'] ?? ''));
                    ?>
                    <tr<?= !$isSales && !empty($row['is_duplicate_bill']) ? ' class="vat-row-duplicate"' : '' ?>>
                        <td><?= h((string) ($row['doc_date'] ?? '')) ?></td>
                        <td><?= $withDocLinks ? tnc_vat_render_doc_no($docNo, (string) ($row['link_url'] ?? '')) : h($docNo) ?></td>
                        <td><?= h($nameCol) ?></td>
                        <td class="text-end"><?= number_format((float) ($row['base'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float) ($row['vat'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float) ($row['net'] ?? 0), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            <tr class="vat-print-total-row">
                <th colspan="3" class="text-end">รวมเงินสุทธิ</th>
                <th class="text-end"><?= number_format($sumBase, 2) ?></th>
                <th class="text-end"><?= number_format($sumVat, 2) ?></th>
                <th class="text-end"><?= number_format($sumNet, 2) ?></th>
            </tr>
            </tbody>
        </table>
    </div>
    <?php
    return (string) ob_get_clean();
}

$month = isset($_GET['month']) ? max(1, min(12, (int) $_GET['month'])) : (int) date('n');
$year = isset($_GET['year']) ? max(2000, min(2100, (int) $_GET['year'])) : (int) date('Y');
$startDate = trim((string) ($_GET['start_date'] ?? ''));
$endDate = trim((string) ($_GET['end_date'] ?? ''));
$dateRangeValid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) === 1
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) === 1
    && $startDate <= $endDate;

if ($dateRangeValid) {
    $fromDate = $startDate;
    $toDate = $endDate;
} else {
    $fromDate = sprintf('%04d-%02d-01', $year, $month);
    $toDate = date('Y-m-t', strtotime($fromDate));
}

$customerById = [];
foreach (Db::tableRows('customers') as $customer) {
    $cid = (int) ($customer['id'] ?? 0);
    if ($cid <= 0) {
        continue;
    }
    $customerById[$cid] = $customer;
}

$invoiceById = [];
foreach (Db::tableRows('invoices') as $invoice) {
    $iid = (int) ($invoice['id'] ?? 0);
    if ($iid <= 0) {
        continue;
    }
    $invoiceById[$iid] = $invoice;
}

$salesRows = [];
$purchaseRows = [];
$purchaseSeen = [];
$sumSalesBase = 0.0;
$sumSalesVat = 0.0;
$sumSalesNet = 0.0;
$sumPurchaseBase = 0.0;
$sumPurchaseVat = 0.0;
$sumPurchaseNet = 0.0;

foreach (Db::tableRows('tax_invoices') as $taxInvoice) {
    $taxInvoiceNo = trim((string) ($taxInvoice['tax_invoice_number'] ?? ''));
    if ($taxInvoiceNo === '') {
        continue;
    }

    $docDate = trim((string) ($taxInvoice['tax_date'] ?? ''));
    if ($docDate === '' || $docDate < $fromDate || $docDate > $toDate) {
        continue;
    }

    $invoiceId = (int) ($taxInvoice['invoice_id'] ?? 0);
    $invoice = $invoiceId > 0 ? ($invoiceById[$invoiceId] ?? null) : null;
    if ($invoice !== null && !tnc_doc_is_active($invoice)) {
        continue;
    }

    $subtotal = round((float) ($taxInvoice['subtotal'] ?? 0), 2);
    $vatAmount = round((float) ($taxInvoice['vat_amount'] ?? 0), 2);
    // ยอดรวมในรายงาน VAT = มูลค่าสินค้า/บริการ + VAT (ไม่หักภาษี ณ ที่จ่าย/เงินประกัน ซึ่งเป็นคนละส่วนกับ VAT)
    $netAmount = round($subtotal + $vatAmount, 2);
    if (!tnc_vat_is_7_percent($subtotal, $vatAmount)) {
        continue;
    }

    $customerId = (int) ($taxInvoice['customer_id'] ?? ($invoice['customer_id'] ?? 0));
    $customer = $customerById[$customerId] ?? [];
    $salesRows[] = [
        'doc_date' => $docDate,
        'invoice_no' => $taxInvoiceNo,
        'link_url' => $invoiceId > 0
            ? app_path('pages/invoices/tax-invoice-receipt.php') . '?id=' . $invoiceId
            : '',
        'customer_name' => trim((string) ($customer['name'] ?? '')),
        'base' => $subtotal,
        'vat' => $vatAmount,
        'net' => $netAmount,
    ];
    $sumSalesBase += $subtotal;
    $sumSalesVat += $vatAmount;
    $sumSalesNet += $netAmount;
}

// ภาษีซื้อจาก PO สมบูรณ์ — ดึงยอดจากรายการ PO ปัจจุบัน (ไม่ใช้ snapshot ใน bills)
$poItemsByPoId = tnc_purchase_po_items_group_by_po_id();
foreach (Db::tableRows('purchase_orders') as $po) {
    if (!tnc_purchase_po_is_complete_for_report($po)) {
        continue;
    }
    $docDate = trim((string) ($po['supplier_invoice_date'] ?? ''));
    if ($docDate === '' || $docDate < $fromDate || $docDate > $toDate) {
        continue;
    }
    if ((int) ($po['vat_enabled'] ?? 0) !== 1) {
        continue;
    }
    $poId = (int) ($po['id'] ?? 0);
    $poItems = $poItemsByPoId[$poId] ?? [];
    $amounts = tnc_purchase_report_amounts_from_po($po, $poItems);
    $subtotal = round((float) ($amounts['subtotal'] ?? 0), 2);
    $vatAmount = round((float) ($amounts['vat'] ?? 0), 2);
    if ($subtotal < 0) {
        $subtotal = 0.0;
    }
    if (!tnc_vat_is_7_percent($subtotal, $vatAmount)) {
        continue;
    }
    $netAmount = round($subtotal + $vatAmount, 2);
    $supplierName = tnc_purchase_report_supplier_name($po);
    $billNo = trim((string) ($po['supplier_invoice_no'] ?? ''));
    if (tnc_vat_is_cash_bill_purchase($billNo, $supplierName)) {
        continue;
    }
    $seenKey = 'po:' . $poId;
    if (isset($purchaseSeen[$seenKey])) {
        continue;
    }
    $purchaseSeen[$seenKey] = true;
    $linkUrl = $poId > 0
        ? app_path('pages/purchase/purchase-order-view.php') . '?id=' . $poId
        : '';
    $purchaseRows[] = [
        'doc_date' => $docDate,
        'bill_no' => $billNo,
        'link_url' => $linkUrl,
        'supplier_name' => $supplierName,
        'base' => $subtotal,
        'vat' => $vatAmount,
        'net' => $netAmount,
    ];
    $sumPurchaseBase += $subtotal;
    $sumPurchaseVat += $vatAmount;
    $sumPurchaseNet += $netAmount;
}

// ภาษีซื้ออื่น (ไม่ผูก PO) — ใช้ตาราง bills เป็นแหล่งข้อมูล
foreach (Db::tableRows('bills') as $bill) {
    $billPoId = (int) ($bill['po_id'] ?? 0);
    if ($billPoId > 0 && trim((string) ($bill['source'] ?? '')) === 'po_receive_bill') {
        continue;
    }
    $docDate = trim((string) ($bill['supplier_invoice_date'] ?? $bill['bill_date'] ?? ''));
    if ($docDate === '' || $docDate < $fromDate || $docDate > $toDate) {
        continue;
    }
    if (!tnc_doc_is_active($bill)) {
        continue;
    }
    $netAmount = round((float) ($bill['total_amount'] ?? $bill['amount'] ?? 0), 2);
    $vatAmount = round((float) ($bill['vat_amount'] ?? 0), 2);
    $subtotal = round((float) ($bill['subtotal_amount'] ?? ($netAmount - $vatAmount)), 2);
    if ($subtotal < 0) {
        $subtotal = 0.0;
    }
    if (!tnc_vat_is_7_percent($subtotal, $vatAmount)) {
        continue;
    }
    // ยอดรวมในรายงาน VAT = มูลค่าสินค้า/บริการ + VAT (ไม่หักภาษี ณ ที่จ่าย/เงินประกัน ซึ่งเป็นคนละส่วนกับ VAT)
    $netAmount = round($subtotal + $vatAmount, 2);
    $supplierName = trim((string) ($bill['supplier_name'] ?? $bill['vendor_name'] ?? ''));
    $billNo = trim((string) ($bill['supplier_invoice_no'] ?? $bill['bill_number'] ?? ''));
    if (tnc_vat_is_cash_bill_purchase($billNo, $supplierName)) {
        continue;
    }
    $seenKey = mb_strtolower($billNo . '|' . $docDate . '|' . number_format($netAmount, 2, '.', '') . '|' . $supplierName);
    if (isset($purchaseSeen[$seenKey])) {
        continue;
    }
    $purchaseSeen[$seenKey] = true;
    $poId = (int) ($bill['po_id'] ?? 0);
    $linkUrl = $poId > 0
        ? app_path('pages/purchase/purchase-order-view.php') . '?id=' . $poId
        : '';
    $purchaseRows[] = [
        'doc_date' => $docDate,
        'bill_no' => $billNo,
        'link_url' => $linkUrl,
        'supplier_name' => $supplierName,
        'base' => $subtotal,
        'vat' => $vatAmount,
        'net' => $netAmount,
    ];
    $sumPurchaseBase += $subtotal;
    $sumPurchaseVat += $vatAmount;
    $sumPurchaseNet += $netAmount;
}

usort($salesRows, static function (array $a, array $b): int {
    $cmp = strcmp($b['doc_date'], $a['doc_date']);
    if ($cmp !== 0) {
        return $cmp;
    }
    return strcmp($b['invoice_no'], $a['invoice_no']);
});
usort($purchaseRows, static function (array $a, array $b): int {
    $cmp = strcmp($b['doc_date'], $a['doc_date']);
    if ($cmp !== 0) {
        return $cmp;
    }
    return strcmp($b['bill_no'], $a['bill_no']);
});

$duplicatePurchaseBills = tnc_vat_find_duplicate_purchase_bills($purchaseRows);
tnc_vat_mark_duplicate_purchase_bills($purchaseRows, $duplicatePurchaseBills);

$vatDiff = round($sumSalesVat - $sumPurchaseVat, 2);
$vatSummaryLabel = $vatDiff >= 0 ? 'ต้องชำระภาษีเพิ่ม' : 'ขอคืนภาษี';
$periodText = $fromDate . ' ถึง ' . $toDate;

$reportBaseQuery = array_filter([
    'month' => $month,
    'year' => $year,
    'start_date' => $startDate,
    'end_date' => $endDate,
], static fn ($v): bool => $v !== null && $v !== '');
$reportBackUrl = app_path('pages/reports/vat-report.php') . '?' . http_build_query($reportBaseQuery);
$printSalesUrl = app_path('pages/reports/vat-report.php') . '?' . http_build_query(array_merge($reportBaseQuery, ['print' => 'sales']));
$printPurchaseUrl = app_path('pages/reports/vat-report.php') . '?' . http_build_query(array_merge($reportBaseQuery, ['print' => 'purchase']));

$companyName = 'THEELIN CON';
foreach (Db::tableRows('company') as $companyRow) {
    $name = trim((string) ($companyRow['name'] ?? ''));
    if ($name !== '') {
        $companyName = $name;
        break;
    }
}

$exportRequested = isset($_GET['export']) && in_array((string) $_GET['export'], ['excel', 'csv'], true);
if ($exportRequested) {
    $filename = 'vat_report_' . $fromDate . '_to_' . $toDate . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";

    echo tnc_vat_csv_row(['รายงานภาษีขาย (VAT Output Tax)']);
    echo tnc_vat_csv_row(['ช่วงวันที่', $periodText]);
    echo tnc_vat_csv_row([]);
    echo tnc_vat_csv_row(['วันที่เอกสาร', 'เลขที่ใบกำกับภาษี', 'ชื่อลูกค้า', 'มูลค่าสินค้า/บริการ', 'จำนวน VAT (7%)', 'ยอดรวมสุทธิ']);
    foreach ($salesRows as $row) {
        echo tnc_vat_csv_row([
            (string) ($row['doc_date'] ?? ''),
            (string) ($row['invoice_no'] ?? ''),
            (string) ($row['customer_name'] ?? ''),
            number_format((float) ($row['base'] ?? 0), 2, '.', ''),
            number_format((float) ($row['vat'] ?? 0), 2, '.', ''),
            number_format((float) ($row['net'] ?? 0), 2, '.', ''),
        ]);
    }
    echo tnc_vat_csv_row(['รวมเงินสุทธิ', '', '', number_format($sumSalesBase, 2, '.', ''), number_format($sumSalesVat, 2, '.', ''), number_format($sumSalesNet, 2, '.', '')]);

    echo tnc_vat_csv_row([]);
    echo tnc_vat_csv_row([]);
    echo tnc_vat_csv_row(['รายงานภาษีซื้อ (VAT Input Tax)']);
    echo tnc_vat_csv_row(['ช่วงวันที่', $periodText]);
    echo tnc_vat_csv_row([]);
    echo tnc_vat_csv_row(['วันที่บิล', 'เลขที่บิล/ใบกำกับภาษี', 'ชื่อซัพพลายเออร์', 'มูลค่าสินค้า/บริการ', 'จำนวน VAT (7%)', 'ยอดรวมสุทธิ']);
    foreach ($purchaseRows as $row) {
        echo tnc_vat_csv_row([
            (string) ($row['doc_date'] ?? ''),
            (string) ($row['bill_no'] ?? ''),
            (string) ($row['supplier_name'] ?? ''),
            number_format((float) ($row['base'] ?? 0), 2, '.', ''),
            number_format((float) ($row['vat'] ?? 0), 2, '.', ''),
            number_format((float) ($row['net'] ?? 0), 2, '.', ''),
        ]);
    }
    echo tnc_vat_csv_row(['รวมเงินสุทธิ', '', '', number_format($sumPurchaseBase, 2, '.', ''), number_format($sumPurchaseVat, 2, '.', ''), number_format($sumPurchaseNet, 2, '.', '')]);

    echo tnc_vat_csv_row([]);
    echo tnc_vat_csv_row(['สรุปภาพรวม', 'จำนวนเงิน']);
    echo tnc_vat_csv_row(['ภาษีขายรวม', number_format($sumSalesVat, 2, '.', '')]);
    echo tnc_vat_csv_row(['ภาษีซื้อรวม', number_format($sumPurchaseVat, 2, '.', '')]);
    echo tnc_vat_csv_row([$vatSummaryLabel . ' (ภาษีขาย - ภาษีซื้อ)', number_format(abs($vatDiff), 2, '.', '')]);
    exit;
}

$printType = strtolower(trim((string) ($_GET['print'] ?? '')));
if ($printType === 'sales' || $printType === 'purchase') {
    $isSalesPrint = $printType === 'sales';
    $printTitle = $isSalesPrint ? 'รายงานภาษีขาย (VAT Output Tax)' : 'รายงานภาษีซื้อ (VAT Input Tax)';
    $printRows = $isSalesPrint ? $salesRows : $purchaseRows;
    $sumBase = $isSalesPrint ? $sumSalesBase : $sumPurchaseBase;
    $sumVat = $isSalesPrint ? $sumSalesVat : $sumPurchaseVat;
    $sumNet = $isSalesPrint ? $sumSalesNet : $sumPurchaseNet;
    $printedAt = date('d/m/Y H:i');
    ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($printTitle) ?> | <?= h($periodText) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --vat-a4-w: 210mm;
            --vat-a4-h: 297mm;
            --vat-print-pad-x: 10mm;
            --vat-print-pad-y: 12mm;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            font-family: 'Sarabun', 'Leelawadee UI', sans-serif;
            background: #e9ecef;
            color: #1f2937;
            margin: 0;
            line-height: 1.45;
        }
        .vat-print-toolbar {
            background: #212529;
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .vat-print-sheet {
            background: #fff;
            width: 100%;
            max-width: var(--vat-a4-w);
            min-height: var(--vat-a4-h);
            margin: 0.75rem auto 2rem;
            padding: 1rem;
            box-shadow: 0 6px 24px rgba(15, 23, 42, .12);
            border: 1px solid #e5e7eb;
        }
        .vat-print-header {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .vat-print-title { font-size: 1.25rem; font-weight: 800; margin: 0; line-height: 1.25; }
        .vat-print-meta { font-size: 0.82rem; color: #4b5563; }
        .vat-print-meta > div + div { margin-top: 0.15rem; }
        .vat-print-table-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .vat-print-table {
            width: 100%;
            min-width: 520px;
            font-size: 0.75rem;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .vat-print-table th,
        .vat-print-table td {
            border: 1px solid #d1d5db;
            padding: 0.35rem 0.45rem;
            vertical-align: top;
            word-break: break-word;
        }
        .vat-print-table thead th {
            background: #f3f4f6 !important;
            font-weight: 700;
            font-size: 0.76rem;
        }
        .vat-print-table tr.vat-print-total-row th,
        .vat-print-table tr.vat-print-total-row td {
            background: #e5e7eb !important;
            font-weight: 700;
        }
        .vat-print-table col.col-date { width: 12%; }
        .vat-print-table col.col-doc { width: 15%; }
        .vat-print-table col.col-name { width: 28%; }
        .vat-print-table col.col-amt { width: 15%; }
        .vat-print-summary {
            margin-top: 0.75rem;
            font-size: 0.85rem;
            color: #374151;
        }

        @media (min-width: 576px) {
            .vat-print-sheet { padding: 1.25rem 1.5rem; margin: 1rem auto 2rem; }
            .vat-print-title { font-size: 1.4rem; }
        }
        @media (min-width: 768px) {
            .vat-print-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: flex-start;
            }
            .vat-print-meta { text-align: right; max-width: 48%; }
            .vat-print-sheet {
                padding: var(--vat-print-pad-y) var(--vat-print-pad-x);
                min-height: var(--vat-a4-h);
            }
            .vat-print-table { font-size: 9pt; min-width: 100%; }
        }
        @media (min-width: 992px) {
            .vat-print-title { font-size: 16pt; }
            .vat-print-table { font-size: 9.5pt; }
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 12mm 10mm 14mm 10mm;
            }
            .no-print { display: none !important; }
            html, body {
                width: 100%;
                height: auto;
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .vat-print-sheet {
                width: 100% !important;
                max-width: none !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: 0 !important;
            }
            .vat-print-header {
                border-bottom-color: #9ca3af;
                margin-bottom: 4mm;
                padding-bottom: 3mm;
            }
            .vat-print-title { font-size: 14pt; }
            .vat-print-meta { font-size: 9pt; }
            .vat-print-table-wrap { overflow: visible !important; }
            .vat-print-table {
                min-width: 0 !important;
                font-size: 7.5pt;
                page-break-inside: auto;
            }
            .vat-print-table thead { display: table-header-group; }
            .vat-print-table tr { page-break-inside: avoid; break-inside: avoid; }
            .vat-print-table tr.vat-print-total-row { page-break-inside: avoid; break-inside: avoid; }
            .vat-print-table th,
            .vat-print-table td {
                padding: 1.5mm 2mm;
                border-color: #6b7280 !important;
            }
            .vat-print-table thead th {
                background: #f3f4f6 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .vat-print-table tr.vat-print-total-row th,
            .vat-print-table tr.vat-print-total-row td {
                background: #e5e7eb !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .vat-print-summary {
                margin-top: 3mm;
                font-size: 9pt;
            }
            a { color: inherit !important; text-decoration: none !important; }
        }
    </style>
</head>
<body class="tnc-app-body">
<div class="vat-print-toolbar py-2 px-3 mb-0 d-flex flex-wrap gap-2 align-items-center justify-content-between no-print">
    <span class="small"><i class="bi bi-printer me-1"></i><?= h($printTitle) ?></span>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>พิมพ์</button>
        <a href="<?= h($reportBackUrl) ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>กลับรายงาน</a>
    </div>
</div>
<div class="vat-print-sheet">
    <div class="vat-print-header">
        <div>
            <p class="mb-1 fw-semibold text-secondary"><?= h($companyName) ?></p>
            <h1 class="vat-print-title"><?= h($printTitle) ?></h1>
        </div>
        <div class="vat-print-meta">
            <div>ช่วงข้อมูล: <strong><?= h($periodText) ?></strong></div>
            <div>พิมพ์เมื่อ: <?= h($printedAt) ?></div>
            <div>จำนวนรายการ: <?= count($printRows) ?> รายการ</div>
        </div>
    </div>
    <?= tnc_vat_render_table_html($isSalesPrint, $printRows, $sumBase, $sumVat, $sumNet, false) ?>
</div>
</body>
</html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานภาษีซื้อ-ภาษีขาย (VAT Report)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .card-soft {
            border: 1px solid var(--tnc-orange-border, #fdba74);
            border-radius: 0.875rem;
            background: #fff;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
        }
        .report-summary-row { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .report-badge {
            font-size: 0.8125rem;
            font-weight: 600;
            padding: 0.35rem 0.75rem;
            border-radius: 50rem;
            border: 1px solid var(--tnc-border-soft, #e2e8f0);
            background: #fff;
            color: var(--tnc-body-ink, #1f2937);
        }
        .report-badge--sales { background: var(--tnc-orange-soft, #ffedd5); color: var(--tnc-orange-dark, #9a3412); border-color: var(--tnc-orange-border, #fdba74); }
        .report-badge--purchase { background: #e6f4ea; color: #1e7e34; border-color: #b7dfc4; }
        .report-badge--diff { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
        .report-badge--refund { background: #e0f2fe; color: #0369a1; border-color: #7dd3fc; }
        .vat-print-table-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .vat-print-table {
            width: 100%;
            min-width: 520px;
            font-size: 0.8125rem;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .vat-print-table th,
        .vat-print-table td {
            border: 1px solid #d1d5db;
            padding: 0.35rem 0.45rem;
            vertical-align: top;
            word-break: break-word;
        }
        .vat-print-table thead th {
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 2px solid var(--tnc-orange-border, #fdba74);
            font-weight: 700;
            font-size: 0.8125rem;
        }
        .vat-print-table tr.vat-print-total-row th,
        .vat-print-table tr.vat-print-total-row td {
            background: #e5e7eb !important;
            font-weight: 700;
        }
        .vat-print-table col.col-date { width: 12%; }
        .vat-print-table col.col-doc { width: 15%; }
        .vat-print-table col.col-name { width: 28%; }
        .vat-print-table col.col-amt { width: 15%; }
        .vat-print-table td.text-end,
        .vat-print-table th.text-end { font-variant-numeric: tabular-nums; }
        .vat-doc-link { font-weight: 600; text-decoration: none; color: var(--tnc-orange, #ea580c); }
        .vat-doc-link:hover { text-decoration: underline; color: var(--tnc-orange-dark, #9a3412); }
        .vat-row-duplicate td { background: #fff7ed !important; }
        .vat-row-duplicate td:nth-child(2) { box-shadow: inset 3px 0 0 #ea580c; font-weight: 700; color: #c2410c; }
        .vat-duplicate-bill-alert ul { margin-bottom: 0; }
        .vat-tab-meta { font-size: 0.82rem; color: #64748b; margin-bottom: 0.75rem; }
        @media (max-width: 575.98px) {
            .card-soft .card-body { padding: 1rem; }
            .report-actions .btn { width: 100%; justify-content: center; }
            .nav-tabs .nav-link { font-size: 0.85rem; padding: 0.45rem 0.65rem; }
        }
        @media (max-width: 767.98px) {
            .vat-print-table-wrap { margin: 0 -0.25rem; }
            .vat-print-table { font-size: 0.8125rem; }
        }
        @media (min-width: 1200px) {
            .container { max-width: 1140px; }
        }
    </style>
</head>
<body class="tnc-app-body">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container pb-5 pt-4">
    <div class="tnc-page-head mb-3">
        <div>
            <p class="tnc-page-kicker">Reports · Accounting</p>
            <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-receipt"></i></span>รายงานภาษีซื้อ / ภาษีขาย (VAT)</h1>
        </div>
    </div>

    <div class="card card-soft mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold">เดือน</label>
                    <select name="month" class="form-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= sprintf('%02d', $m) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold">ปี</label>
                    <input type="number" name="year" min="2000" max="2100" class="form-control" value="<?= $year ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold">วันที่เริ่มต้น (เลือกแทนเดือน/ปี)</label>
                    <input type="date" name="start_date" class="form-control" value="<?= h($startDate) ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold">วันที่สิ้นสุด</label>
                    <input type="date" name="end_date" class="form-control" value="<?= h($endDate) ?>">
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-orange w-100"><i class="bi bi-search me-1"></i>ค้นหารายงาน</button>
                </div>
                <div class="col-12 d-flex flex-wrap justify-content-end gap-2 report-actions">
                    <a href="<?= h($printSalesUrl) ?>" class="btn btn-outline-orange rounded-pill px-3">
                        <i class="bi bi-printer me-1"></i>พิมพ์ภาษีขาย
                    </a>
                    <a href="<?= h($printPurchaseUrl) ?>" class="btn btn-outline-secondary rounded-pill px-3">
                        <i class="bi bi-printer me-1"></i>พิมพ์ภาษีซื้อ
                    </a>
                    <?php
                    $exportQuery = $_GET;
                    $exportQuery['export'] = 'csv';
                    ?>
                    <a href="<?= h(app_path('pages/reports/vat-report.php') . '?' . http_build_query($exportQuery)) ?>" class="btn btn-outline-success rounded-pill px-3 fw-semibold">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
                    </a>
                </div>
            </form>
            <div class="report-summary-row mt-3 pt-3 border-top">
                <span class="report-badge">ช่วงข้อมูล: <?= h($periodText) ?></span>
                <span class="report-badge report-badge--sales">ภาษีขายรวม: <?= number_format($sumSalesVat, 2) ?></span>
                <span class="report-badge report-badge--purchase">ภาษีซื้อรวม: <?= number_format($sumPurchaseVat, 2) ?></span>
                <span class="report-badge <?= $vatDiff >= 0 ? 'report-badge--diff' : 'report-badge--refund' ?>"><?= h($vatSummaryLabel) ?>: <?= number_format(abs($vatDiff), 2) ?></span>
                <?php if ($duplicatePurchaseBills !== []): ?>
                    <span class="report-badge bg-warning-subtle text-warning-emphasis border-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i>เลขบิลซ้ำ <?= count($duplicatePurchaseBills) ?> รายการ</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($duplicatePurchaseBills !== []): ?>
        <?= tnc_vat_render_duplicate_bill_alert($duplicatePurchaseBills) ?>
    <?php endif; ?>

    <div class="card card-soft mb-3">
        <div class="card-body">
            <ul class="nav nav-tabs mb-3" id="vatTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="vat-tab-sales" data-bs-toggle="tab" data-bs-target="#tab-sales" type="button" role="tab" aria-controls="tab-sales" aria-selected="true">ตารางภาษีขาย</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="vat-tab-purchase" data-bs-toggle="tab" data-bs-target="#tab-purchase" type="button" role="tab" aria-controls="tab-purchase" aria-selected="false">ตารางภาษีซื้อ</button>
                </li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="tab-sales" role="tabpanel" aria-labelledby="vat-tab-sales" tabindex="0">
                    <div class="vat-tab-meta">จำนวนรายการ: <?= count($salesRows) ?> รายการ · ช่วงข้อมูล: <?= h($periodText) ?></div>
                    <?= tnc_vat_render_table_html(true, $salesRows, $sumSalesBase, $sumSalesVat, $sumSalesNet, true) ?>
                </div>
                <div class="tab-pane fade" id="tab-purchase" role="tabpanel" aria-labelledby="vat-tab-purchase" tabindex="0">
                    <div class="vat-tab-meta">
                        จำนวนรายการ: <?= count($purchaseRows) ?> รายการ · ช่วงข้อมูล: <?= h($periodText) ?>
                        <?php if ($duplicatePurchaseBills !== []): ?>
                            · <span class="text-warning-emphasis fw-semibold">มีเลขบิลซ้ำ <?= count($duplicatePurchaseBills) ?> รายการ</span>
                        <?php endif; ?>
                    </div>
                    <?= tnc_vat_render_table_html(false, $purchaseRows, $sumPurchaseBase, $sumPurchaseVat, $sumPurchaseNet, true) ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
