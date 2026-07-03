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
    <div class="vat-print-table-wrap tnc-mobile-table-wrap">
        <table class="table table-bordered table-sm vat-print-table tnc-mobile-table mb-0">
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
                        <td data-label="<?= $isSales ? 'วันที่เอกสาร' : 'วันที่บิล' ?>"><?= h((string) ($row['doc_date'] ?? '')) ?></td>
                        <td data-label="<?= $isSales ? 'เลขที่ใบกำกับภาษี' : 'เลขที่บิล/ใบกำกับภาษี' ?>" class="tnc-mobile-primary<?= !$isSales && !empty($row['is_duplicate_bill']) ? ' vat-duplicate-doc' : '' ?>"><?= $withDocLinks ? tnc_vat_render_doc_no($docNo, (string) ($row['link_url'] ?? '')) : h($docNo) ?></td>
                        <td data-label="<?= $isSales ? 'ชื่อลูกค้า' : 'ชื่อซัพพลายเออร์' ?>"><?= h($nameCol) ?></td>
                        <td class="text-end tnc-mobile-amount" data-label="มูลค่าสินค้า/บริการ"><?= number_format((float) ($row['base'] ?? 0), 2) ?></td>
                        <td class="text-end" data-label="จำนวน VAT (7%)"><?= number_format((float) ($row['vat'] ?? 0), 2) ?></td>
                        <td class="text-end tnc-mobile-amount" data-label="ยอดรวมสุทธิ"><?= number_format((float) ($row['net'] ?? 0), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            <tr class="vat-print-total-row">
                <td colspan="3" class="vat-total-heading text-end">รวมเงินสุทธิ</td>
                <td class="text-end" data-label="มูลค่าสินค้า/บริการ"><?= number_format($sumBase, 2) ?></td>
                <td class="text-end" data-label="จำนวน VAT (7%)"><?= number_format($sumVat, 2) ?></td>
                <td class="text-end tnc-mobile-amount" data-label="ยอดรวมสุทธิ"><?= number_format($sumNet, 2) ?></td>
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
    <?php
    require_once dirname(__DIR__, 2) . '/includes/tnc_ops_head.php';
    tnc_ops_head([
        'title' => $printTitle . ' | ' . $periodText,
        'minimal' => true,
        'vat_print' => true,
        'include_ops_ui' => false,
        'sarabun_weights' => '400;500;600;700;800',
    ]);
    ?>
</head>
<body class="tnc-app-body tnc-layout-list">
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
    <?php
    require_once dirname(__DIR__, 2) . '/includes/tnc_ops_head.php';
    tnc_ops_head([
        'title' => 'รายงานภาษีซื้อ-ภาษีขาย (VAT Report)',
        'vat_report' => true,
        'include_ops_ui' => false,
        'sarabun_weights' => '400;600;700;800',
    ]);
    ?>
</head>
<body class="tnc-app-body tnc-layout-list vat-report-page">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container pb-5 pt-4">
    <div class="tnc-page-head mb-3">
        <div>
            <p class="tnc-page-kicker">Reports · Accounting</p>
            <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-receipt"></i></span>รายงานภาษีซื้อ / ภาษีขาย (VAT)</h1>
        </div>
    </div>

    <div class="card card-soft mb-3 vat-filter-card">
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
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-semibold">วันที่เริ่มต้น <span class="text-muted fw-normal">(แทนเดือน/ปี)</span></label>
                    <input type="date" name="start_date" class="form-control" value="<?= h($startDate) ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-semibold">วันที่สิ้นสุด</label>
                    <input type="date" name="end_date" class="form-control" value="<?= h($endDate) ?>">
                </div>
                <div class="col-12 col-md-2">
                    <button type="submit" class="btn btn-orange w-100"><i class="bi bi-search me-1"></i>ค้นหารายงาน</button>
                </div>
                <div class="col-12 vat-filter-actions">
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
                    <a href="<?= h(app_path('pages/reports/vat-report.php') . '?' . http_build_query($exportQuery)) ?>" class="btn btn-outline-success rounded-pill px-3 fw-semibold btn-export">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
                    </a>
                </div>
            </form>
            <div class="vat-summary-grid mt-3 pt-3 border-top">
                <span class="report-badge report-badge--period">ช่วงข้อมูล: <?= h($periodText) ?></span>
                <span class="report-badge report-badge--sales">ภาษีขายรวม<span class="d-md-none"><br></span><span class="d-none d-md-inline">: </span><?= number_format($sumSalesVat, 2) ?></span>
                <span class="report-badge report-badge--purchase">ภาษีซื้อรวม<span class="d-md-none"><br></span><span class="d-none d-md-inline">: </span><?= number_format($sumPurchaseVat, 2) ?></span>
                <span class="report-badge <?= $vatDiff >= 0 ? 'report-badge--diff' : 'report-badge--refund' ?>"><?= h($vatSummaryLabel) ?><span class="d-md-none"><br></span><span class="d-none d-md-inline">: </span><?= number_format(abs($vatDiff), 2) ?></span>
                <?php if ($duplicatePurchaseBills !== []): ?>
                    <span class="report-badge bg-warning-subtle text-warning-emphasis border-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i>เลขบิลซ้ำ <?= count($duplicatePurchaseBills) ?> รายการ</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($duplicatePurchaseBills !== []): ?>
        <?= tnc_vat_render_duplicate_bill_alert($duplicatePurchaseBills) ?>
    <?php endif; ?>

    <div class="card card-soft mb-3 vat-table-card">
        <div class="card-body">
            <ul class="nav nav-tabs mb-3" id="vatTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="vat-tab-sales" data-bs-toggle="tab" data-bs-target="#tab-sales" type="button" role="tab" aria-controls="tab-sales" aria-selected="true"><span class="d-md-none">ขาย (<?= count($salesRows) ?>)</span><span class="d-none d-md-inline">ตารางภาษีขาย</span></button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="vat-tab-purchase" data-bs-toggle="tab" data-bs-target="#tab-purchase" type="button" role="tab" aria-controls="tab-purchase" aria-selected="false"><span class="d-md-none">ซื้อ (<?= count($purchaseRows) ?>)</span><span class="d-none d-md-inline">ตารางภาษีซื้อ</span></button>
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
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
</body>
</html>
