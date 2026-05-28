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

$supplierTaxByName = [];
foreach (Db::tableRows('suppliers') as $supplier) {
    $name = trim((string) ($supplier['name'] ?? ''));
    if ($name === '') {
        continue;
    }
    $supplierTaxByName[mb_strtolower($name)] = trim((string) ($supplier['tax_id'] ?? ''));
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

foreach (Db::tableRows('invoices') as $invoice) {
    $docDate = trim((string) ($invoice['issue_date'] ?? ''));
    if ($docDate === '' || $docDate < $fromDate || $docDate > $toDate) {
        continue;
    }
    if (!tnc_doc_is_active($invoice)) {
        continue;
    }

    $subtotal = round((float) ($invoice['subtotal'] ?? 0), 2);
    $vatAmount = round((float) ($invoice['vat_amount'] ?? 0), 2);
    $netAmount = round((float) ($invoice['total_amount'] ?? ($subtotal + $vatAmount)), 2);
    if (!tnc_vat_is_7_percent($subtotal, $vatAmount)) {
        continue;
    }

    $customerId = (int) ($invoice['customer_id'] ?? 0);
    $customer = $customerById[$customerId] ?? [];
    $salesRows[] = [
        'doc_date' => $docDate,
        'invoice_no' => trim((string) ($invoice['invoice_number'] ?? '')),
        'customer_name' => trim((string) ($customer['name'] ?? '')),
        'tax_id' => trim((string) ($customer['tax_id'] ?? '')),
        'base' => $subtotal,
        'vat' => $vatAmount,
        'net' => $netAmount,
    ];
    $sumSalesBase += $subtotal;
    $sumSalesVat += $vatAmount;
    $sumSalesNet += $netAmount;
}

foreach (Db::tableRows('purchase_bills') as $bill) {
    $docDate = trim((string) ($bill['bill_date'] ?? ''));
    if ($docDate === '' || $docDate < $fromDate || $docDate > $toDate) {
        continue;
    }
    if (!tnc_doc_is_active($bill)) {
        continue;
    }

    $subtotal = round((float) ($bill['subtotal_amount'] ?? 0), 2);
    $vatAmount = round((float) ($bill['vat_amount'] ?? 0), 2);
    $netAmount = round((float) ($bill['amount'] ?? ($subtotal + $vatAmount)), 2);

    $supplierName = trim((string) ($bill['supplier_name'] ?? $bill['vendor_name'] ?? ''));
    $supplierTax = $supplierTaxByName[mb_strtolower($supplierName)] ?? '';
    $billNo = trim((string) ($bill['bill_number'] ?? ''));
    $seenKey = mb_strtolower($billNo . '|' . $docDate . '|' . number_format($netAmount, 2, '.', '') . '|' . $supplierName);
    if (isset($purchaseSeen[$seenKey])) {
        continue;
    }
    $purchaseSeen[$seenKey] = true;
    $purchaseRows[] = [
        'doc_date' => $docDate,
        'bill_no' => $billNo,
        'supplier_name' => $supplierName,
        'tax_id' => $supplierTax,
        'base' => $subtotal,
        'vat' => $vatAmount,
        'net' => $netAmount,
    ];
    $sumPurchaseBase += $subtotal;
    $sumPurchaseVat += $vatAmount;
    $sumPurchaseNet += $netAmount;
}

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
    $supplierName = trim((string) ($bill['supplier_name'] ?? $bill['vendor_name'] ?? ''));
    $supplierTax = $supplierTaxByName[mb_strtolower($supplierName)] ?? '';
    $billNo = trim((string) ($bill['supplier_invoice_no'] ?? $bill['bill_number'] ?? ''));
    $seenKey = mb_strtolower($billNo . '|' . $docDate . '|' . number_format($netAmount, 2, '.', '') . '|' . $supplierName);
    if (isset($purchaseSeen[$seenKey])) {
        continue;
    }
    $purchaseSeen[$seenKey] = true;
    $purchaseRows[] = [
        'doc_date' => $docDate,
        'bill_no' => $billNo,
        'supplier_name' => $supplierName,
        'tax_id' => $supplierTax,
        'base' => $subtotal,
        'vat' => $vatAmount,
        'net' => $netAmount,
    ];
    $sumPurchaseBase += $subtotal;
    $sumPurchaseVat += $vatAmount;
    $sumPurchaseNet += $netAmount;
}

usort($salesRows, static function (array $a, array $b): int {
    $cmp = strcmp($a['doc_date'], $b['doc_date']);
    if ($cmp !== 0) {
        return $cmp;
    }
    return strcmp($a['invoice_no'], $b['invoice_no']);
});
usort($purchaseRows, static function (array $a, array $b): int {
    $cmp = strcmp($a['doc_date'], $b['doc_date']);
    if ($cmp !== 0) {
        return $cmp;
    }
    return strcmp($a['bill_no'], $b['bill_no']);
});

$vatDiff = round($sumSalesVat - $sumPurchaseVat, 2);
$vatSummaryLabel = $vatDiff >= 0 ? 'ต้องชำระภาษีเพิ่ม' : 'ขอคืนภาษี';
$periodText = $fromDate . ' ถึง ' . $toDate;

$exportExcel = isset($_GET['export']) && $_GET['export'] === 'excel';
if ($exportExcel) {
    $filename = 'vat_report_' . $fromDate . '_to_' . $toDate . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    ?>
    <table border="1">
        <tr><th colspan="8">รายงานภาษีขาย (VAT Output Tax)</th></tr>
        <tr><th colspan="8">ช่วงวันที่ <?= h($periodText) ?></th></tr>
        <tr>
            <th>ลำดับ</th><th>วันที่เอกสาร</th><th>เลขที่ Invoice</th><th>ชื่อลูกค้า</th><th>เลขประจำตัวผู้เสียภาษี</th><th>มูลค่าสินค้า/บริการ</th><th>จำนวน VAT (7%)</th><th>ยอดรวมสุทธิ</th>
        </tr>
        <?php $i = 0; foreach ($salesRows as $row): $i++; ?>
            <tr>
                <td><?= $i ?></td><td><?= h($row['doc_date']) ?></td><td><?= h($row['invoice_no']) ?></td><td><?= h($row['customer_name']) ?></td><td><?= h($row['tax_id']) ?></td>
                <td><?= number_format((float) $row['base'], 2, '.', '') ?></td><td><?= number_format((float) $row['vat'], 2, '.', '') ?></td><td><?= number_format((float) $row['net'], 2, '.', '') ?></td>
            </tr>
        <?php endforeach; ?>
        <tr><th colspan="5">รวมเงินสุทธิ</th><th><?= number_format($sumSalesBase, 2, '.', '') ?></th><th><?= number_format($sumSalesVat, 2, '.', '') ?></th><th><?= number_format($sumSalesNet, 2, '.', '') ?></th></tr>
    </table>
    <br>
    <table border="1">
        <tr><th colspan="8">รายงานภาษีซื้อ (VAT Input Tax)</th></tr>
        <tr><th colspan="8">ช่วงวันที่ <?= h($periodText) ?></th></tr>
        <tr>
            <th>ลำดับ</th><th>วันที่บิล</th><th>เลขที่บิล/ใบกำกับภาษี</th><th>ชื่อซัพพลายเออร์</th><th>เลขประจำตัวผู้เสียภาษี</th><th>มูลค่าสินค้า/บริการ</th><th>จำนวน VAT (7%)</th><th>ยอดรวมสุทธิ</th>
        </tr>
        <?php $i = 0; foreach ($purchaseRows as $row): $i++; ?>
            <tr>
                <td><?= $i ?></td><td><?= h($row['doc_date']) ?></td><td><?= h($row['bill_no']) ?></td><td><?= h($row['supplier_name']) ?></td><td><?= h($row['tax_id']) ?></td>
                <td><?= number_format((float) $row['base'], 2, '.', '') ?></td><td><?= number_format((float) $row['vat'], 2, '.', '') ?></td><td><?= number_format((float) $row['net'], 2, '.', '') ?></td>
            </tr>
        <?php endforeach; ?>
        <tr><th colspan="5">รวมเงินสุทธิ</th><th><?= number_format($sumPurchaseBase, 2, '.', '') ?></th><th><?= number_format($sumPurchaseVat, 2, '.', '') ?></th><th><?= number_format($sumPurchaseNet, 2, '.', '') ?></th></tr>
    </table>
    <br>
    <table border="1">
        <tr><th>สรุปภาพรวม</th><th>จำนวนเงิน</th></tr>
        <tr><td>ภาษีขายรวม</td><td><?= number_format($sumSalesVat, 2, '.', '') ?></td></tr>
        <tr><td>ภาษีซื้อรวม</td><td><?= number_format($sumPurchaseVat, 2, '.', '') ?></td></tr>
        <tr><td><?= h($vatSummaryLabel) ?> (ภาษีขาย - ภาษีซื้อ)</td><td><?= number_format(abs($vatDiff), 2, '.', '') ?></td></tr>
    </table>
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
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f7f8fa; }
        .card-soft { border: 0; border-radius: 14px; box-shadow: 0 5px 18px rgba(15, 23, 42, 0.08); }
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
                <div class="col-12 d-flex justify-content-end">
                    <?php
                    $exportQuery = $_GET;
                    $exportQuery['export'] = 'excel';
                    ?>
                    <a href="<?= h(app_path('pages/reports/vat-report.php') . '?' . http_build_query($exportQuery)) ?>" class="btn btn-success">
                        <i class="bi bi-file-earmark-excel me-1"></i>Export เป็น Excel
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
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>ลำดับ</th><th>วันที่เอกสาร</th><th>เลขที่ Invoice</th><th>ชื่อลูกค้า</th><th>เลขประจำตัวผู้เสียภาษี</th><th class="text-end">มูลค่าสินค้า/บริการ</th><th class="text-end">จำนวน VAT (7%)</th><th class="text-end">ยอดรวมสุทธิ</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($salesRows === []): ?>
                                <tr><td colspan="8" class="text-center text-muted py-3">ไม่พบข้อมูลภาษีขายตามเงื่อนไข</td></tr>
                            <?php else: ?>
                                <?php $i = 0; foreach ($salesRows as $row): $i++; ?>
                                    <tr>
                                        <td><?= $i ?></td><td><?= h($row['doc_date']) ?></td><td><?= h($row['invoice_no']) ?></td><td><?= h($row['customer_name']) ?></td><td><?= h($row['tax_id']) ?></td>
                                        <td class="text-end"><?= number_format((float) $row['base'], 2) ?></td><td class="text-end"><?= number_format((float) $row['vat'], 2) ?></td><td class="text-end"><?= number_format((float) $row['net'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                            <tfoot>
                            <tr class="table-warning">
                                <th colspan="5" class="text-end">รวมเงินสุทธิ</th>
                                <th class="text-end"><?= number_format($sumSalesBase, 2) ?></th>
                                <th class="text-end"><?= number_format($sumSalesVat, 2) ?></th>
                                <th class="text-end"><?= number_format($sumSalesNet, 2) ?></th>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-purchase">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>ลำดับ</th><th>วันที่บิล</th><th>เลขที่บิล/ใบกำกับภาษี</th><th>ชื่อซัพพลายเออร์</th><th>เลขประจำตัวผู้เสียภาษี</th><th class="text-end">มูลค่าสินค้า/บริการ</th><th class="text-end">จำนวน VAT (7%)</th><th class="text-end">ยอดรวมสุทธิ</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($purchaseRows === []): ?>
                                <tr><td colspan="8" class="text-center text-muted py-3">ไม่พบข้อมูลภาษีซื้อตามเงื่อนไข</td></tr>
                            <?php else: ?>
                                <?php $i = 0; foreach ($purchaseRows as $row): $i++; ?>
                                    <tr>
                                        <td><?= $i ?></td><td><?= h($row['doc_date']) ?></td><td><?= h($row['bill_no']) ?></td><td><?= h($row['supplier_name']) ?></td><td><?= h($row['tax_id']) ?></td>
                                        <td class="text-end"><?= number_format((float) $row['base'], 2) ?></td><td class="text-end"><?= number_format((float) $row['vat'], 2) ?></td><td class="text-end"><?= number_format((float) $row['net'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                            <tfoot>
                            <tr class="table-success">
                                <th colspan="5" class="text-end">รวมเงินสุทธิ</th>
                                <th class="text-end"><?= number_format($sumPurchaseBase, 2) ?></th>
                                <th class="text-end"><?= number_format($sumPurchaseVat, 2) ?></th>
                                <th class="text-end"><?= number_format($sumPurchaseNet, 2) ?></th>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
