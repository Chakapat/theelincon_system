<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

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
    $netAmount = round((float) ($taxInvoice['grand_total'] ?? ($subtotal + $vatAmount)), 2);
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

// ภาษีซื้อ: ใช้ตาราง bills เป็นแหล่งข้อมูลเดียว (สร้างตอนรับบิลซื้อจาก PO)
foreach (Db::tableRows('bills') as $bill) {
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
    $supplierName = trim((string) ($bill['supplier_name'] ?? $bill['vendor_name'] ?? ''));
    $billNo = trim((string) ($bill['supplier_invoice_no'] ?? $bill['bill_number'] ?? ''));
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
<body>
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
            <?php if ($isSalesPrint): ?>
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
        <?php if ($printRows === []): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">ไม่พบข้อมูลตามเงื่อนไข</td></tr>
        <?php else: ?>
            <?php foreach ($printRows as $row): ?>
                <tr>
                    <td><?= h((string) ($row['doc_date'] ?? '')) ?></td>
                    <td><?= h((string) ($isSalesPrint ? ($row['invoice_no'] ?? '') : ($row['bill_no'] ?? ''))) ?></td>
                    <td><?= h((string) ($isSalesPrint ? ($row['customer_name'] ?? '') : ($row['supplier_name'] ?? ''))) ?></td>
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f7f8fa; }
        .card-soft { border: 0; border-radius: 14px; box-shadow: 0 5px 18px rgba(15, 23, 42, 0.08); }
        .table thead th { white-space: nowrap; font-size: .82rem; }
        .btn-export-modern {
            border: 0;
            border-radius: 999px;
            padding: .55rem 1rem;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%);
            box-shadow: 0 8px 20px rgba(34, 197, 94, .28);
        }
        .btn-export-modern:hover { color: #fff; filter: brightness(1.03); }
        .btn-print-modern {
            border-radius: 999px;
            padding: .55rem 1rem;
            font-weight: 600;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #0d6efd !important;
            border-color: #0d6efd !important;
            color: #fff !important;
        }
        .dataTables_wrapper .dataTables_paginate {
            float: none !important;
            text-align: center !important;
            padding-top: .5rem;
        }
        .dataTables_wrapper .dataTables_info {
            float: none !important;
            text-align: center;
            padding-top: .5rem;
        }
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            display: none;
        }
        .vat-table-wrap .table {
            margin-bottom: 0;
        }
        .vat-doc-link {
            font-weight: 600;
            text-decoration: none;
        }
        .vat-doc-link:hover {
            text-decoration: underline;
        }
        @media (max-width: 575.98px) {
            .card-soft .card-body { padding: 1rem; }
            h4.fw-bold { font-size: 1.1rem; }
            .btn-export-modern,
            .btn-print-modern { width: 100%; justify-content: center; }
            .nav-tabs .nav-link { font-size: 0.85rem; padding: 0.45rem 0.65rem; }
        }
        @media (max-width: 767.98px) {
            .vat-table-wrap { margin: 0 -0.25rem; }
            .table { font-size: 0.8rem; }
        }
        @media (min-width: 1200px) {
            .container { max-width: 1140px; }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container pb-5">
    <div class="card card-soft mb-3">
        <div class="card-body">
            <h4 class="fw-bold mb-3"><i class="bi bi-receipt me-2 text-primary"></i>รายงานภาษีซื้อ / ภาษีขาย (VAT)</h4>
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
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>ค้นหารายงาน</button>
                </div>
                <div class="col-12 d-flex flex-wrap justify-content-end gap-2">
                    <a href="<?= h($printSalesUrl) ?>" class="btn btn-outline-primary btn-print-modern">
                        <i class="bi bi-printer me-1"></i>พิมพ์ภาษีขาย
                    </a>
                    <a href="<?= h($printPurchaseUrl) ?>" class="btn btn-outline-success btn-print-modern">
                        <i class="bi bi-printer me-1"></i>พิมพ์ภาษีซื้อ
                    </a>
                    <?php
                    $exportQuery = $_GET;
                    $exportQuery['export'] = 'csv';
                    ?>
                    <a href="<?= h(app_path('pages/reports/vat-report.php') . '?' . http_build_query($exportQuery)) ?>" class="btn btn-export-modern">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export (Excel / Google Sheets)
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card card-soft mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <span class="badge bg-light text-dark border">ช่วงข้อมูล: <?= h($periodText) ?></span>
                <span class="badge bg-primary-subtle text-primary-emphasis">ภาษีขายรวม: <?= number_format($sumSalesVat, 2) ?></span>
                <span class="badge bg-success-subtle text-success-emphasis">ภาษีซื้อรวม: <?= number_format($sumPurchaseVat, 2) ?></span>
                <span class="badge <?= $vatDiff >= 0 ? 'bg-warning text-dark' : 'bg-info text-dark' ?>"><?= h($vatSummaryLabel) ?>: <?= number_format(abs($vatDiff), 2) ?></span>
            </div>
        </div>
    </div>

    <div class="card card-soft mb-3">
        <div class="card-body">
            <ul class="nav nav-tabs mb-3" id="vatTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-sales" type="button">ตารางภาษีขาย</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-purchase" type="button">ตารางภาษีซื้อ</button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="tab-sales">
                    <div class="table-responsive vat-table-wrap">
                        <table class="table table-sm table-bordered align-middle" id="vatSalesTable">
                            <thead class="table-light">
                            <tr>
                                <th>วันที่เอกสาร</th><th>เลขที่ใบกำกับภาษี</th><th>ชื่อลูกค้า</th><th class="text-end">มูลค่าสินค้า/บริการ</th><th class="text-end">จำนวน VAT (7%)</th><th class="text-end">ยอดรวมสุทธิ</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($salesRows === []): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">ไม่พบข้อมูลภาษีขายตามเงื่อนไข</td></tr>
                            <?php else: ?>
                                <?php foreach ($salesRows as $row): ?>
                                    <tr>
                                        <td><?= h($row['doc_date']) ?></td><td><?= tnc_vat_render_doc_no((string) ($row['invoice_no'] ?? ''), (string) ($row['link_url'] ?? '')) ?></td><td><?= h($row['customer_name']) ?></td>
                                        <td class="text-end"><?= number_format((float) $row['base'], 2) ?></td><td class="text-end"><?= number_format((float) $row['vat'], 2) ?></td><td class="text-end"><?= number_format((float) $row['net'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-purchase">
                    <div class="table-responsive vat-table-wrap">
                        <table class="table table-sm table-bordered align-middle" id="vatPurchaseTable">
                            <thead class="table-light">
                            <tr>
                                <th>วันที่บิล</th><th>เลขที่บิล/ใบกำกับภาษี</th><th>ชื่อซัพพลายเออร์</th><th class="text-end">มูลค่าสินค้า/บริการ</th><th class="text-end">จำนวน VAT (7%)</th><th class="text-end">ยอดรวมสุทธิ</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($purchaseRows === []): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">ไม่พบข้อมูลภาษีซื้อตามเงื่อนไข</td></tr>
                            <?php else: ?>
                                <?php foreach ($purchaseRows as $row): ?>
                                    <tr>
                                        <td><?= h($row['doc_date']) ?></td><td><?= tnc_vat_render_doc_no((string) ($row['bill_no'] ?? ''), (string) ($row['link_url'] ?? '')) ?></td><td><?= h($row['supplier_name']) ?></td>
                                        <td class="text-end"><?= number_format((float) $row['base'], 2) ?></td><td class="text-end"><?= number_format((float) $row['vat'], 2) ?></td><td class="text-end"><?= number_format((float) $row['net'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(function () {
        const dtOptions = {
            pageLength: 10,
            pagingType: 'simple_numbers',
            lengthChange: false,
            info: false,
            searching: false,
            ordering: false,
            autoWidth: false,
            dom: 't<"mt-2 d-flex justify-content-center"p>',
            language: {
                paginate: { previous: 'ก่อนหน้า', next: 'ถัดไป' },
                zeroRecords: 'ไม่พบข้อมูล',
                emptyTable: 'ไม่มีข้อมูล',
                search: 'ค้นหา:'
            }
        };
        const salesTable = $('#vatSalesTable').DataTable(dtOptions);
        const purchaseTable = $('#vatPurchaseTable').DataTable(dtOptions);
        $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function () {
            salesTable.columns.adjust();
            purchaseTable.columns.adjust();
        });
    });
</script>
</body>
</html>
