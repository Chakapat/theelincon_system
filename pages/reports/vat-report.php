<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_print/vat_print_summary.php';
require_once dirname(__DIR__, 2) . '/includes/site_category_document_name.php';

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

/** คีย์หมวดย่อยสำหรับกรองภาษีซื้อ */
function tnc_vat_purchase_sub_key(string $parentLabel, string $subLabel): string
{
    $parentLabel = trim($parentLabel);
    $subLabel = trim($subLabel);
    if ($parentLabel === '' && $subLabel === '') {
        return 'name:' . mb_strtolower('ไม่ระบุหมวด', 'UTF-8');
    }
    if ($subLabel === '') {
        $subLabel = $parentLabel !== '' ? $parentLabel : 'ไม่ระบุหมวด';
    }
    if ($parentLabel === '') {
        $parentLabel = $subLabel;
    }
    $combo = $parentLabel === $subLabel
        ? $subLabel
        : ($parentLabel . ' › ' . $subLabel);

    return 'name:' . mb_strtolower($combo, 'UTF-8');
}

/** ข้อความแสดงหมวดในตาราง/CSV */
function tnc_vat_purchase_category_display(string $parentLabel, string $subLabel): string
{
    $parentLabel = trim($parentLabel);
    $subLabel = trim($subLabel);
    if ($parentLabel === '' && $subLabel === '') {
        return 'ไม่ระบุหมวด';
    }
    if ($subLabel === '') {
        return $parentLabel !== '' ? $parentLabel : 'ไม่ระบุหมวด';
    }
    if ($parentLabel === '' || $parentLabel === $subLabel) {
        return $subLabel;
    }

    return $parentLabel . ' › ' . $subLabel;
}

/**
 * @param list<array<string, mixed>> $purchaseRows
 * @param list<string> $filterSubKeys
 * @return list<array<string, mixed>>
 */
function tnc_vat_apply_purchase_sub_filter(array $purchaseRows, array $filterSubKeys): array
{
    if ($filterSubKeys === []) {
        return $purchaseRows;
    }
    $allowed = array_fill_keys($filterSubKeys, true);
    $out = [];
    foreach ($purchaseRows as $row) {
        $key = (string) ($row['sub_key'] ?? '');
        if ($key === '' || !isset($allowed[$key])) {
            continue;
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $purchaseRows
 * @return array{0: float, 1: float, 2: float}
 */
function tnc_vat_sum_purchase_rows(array $purchaseRows): array
{
    $base = 0.0;
    $vat = 0.0;
    $net = 0.0;
    foreach ($purchaseRows as $row) {
        $base += (float) ($row['base'] ?? 0);
        $vat += (float) ($row['vat'] ?? 0);
        $net += (float) ($row['net'] ?? 0);
    }

    return [round($base, 2), round($vat, 2), round($net, 2)];
}

/** คืนค่าเลขผู้เสียภาษีของผู้ขายจาก PO/บิล + master suppliers */
function tnc_vat_resolve_supplier_tax_id(array $row, array $supplierById = []): string
{
    $supplierId = (int) ($row['supplier_id'] ?? 0);
    if ($supplierId > 0) {
        if (isset($supplierById[$supplierId]) && is_array($supplierById[$supplierId])) {
            $tax = trim((string) ($supplierById[$supplierId]['tax_id'] ?? ''));
            if ($tax !== '') {
                return $tax;
            }
        } else {
            $supplierRow = \Theelincon\Rtdb\Db::rowByIdField('suppliers', $supplierId);
            if (is_array($supplierRow)) {
                $tax = trim((string) ($supplierRow['tax_id'] ?? ''));
                if ($tax !== '') {
                    return $tax;
                }
            }
        }
    }

    foreach (['supplier_tax_id', 'vendor_tax_id', 'tax_id'] as $field) {
        $tax = trim((string) ($row[$field] ?? ''));
        if ($tax !== '') {
            return $tax;
        }
    }

    return '';
}

/** แสดงชื่อผู้ขาย · เลขผู้เสียภาษี (หรือข้อความเมื่อไม่พบเลข) */
function tnc_vat_purchase_seller_display(string $supplierName, string $taxId): string
{
    $supplierName = trim($supplierName);
    $taxId = trim($taxId);
    $taxPart = $taxId !== '' ? $taxId : 'ไม่พบเลขผู้เสียภาษี';
    if ($supplierName === '') {
        return '— · ' . $taxPart;
    }

    return $supplierName . ' · ' . $taxPart;
}

/**
 * หาเลขที่บิล/ใบกำกับภาษี (ภาษีซื้อ) ที่ซ้ำกันในรายงาน
 * ข้าม "บิลเงินสด" เพราะเป็นป้ายร่วม ไม่ใช่เลขบิลจริง
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
        // ไม่เตือนซ้ำสำหรับป้ายบิลเงินสด (หลายรายการใช้ชื่อเดียวกัน)
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
    bool $withDocLinks = false,
    bool $forPrint = false
): string {
    $showNet = $isSales;
    $baseLabel = $isSales
        ? ($forPrint ? 'ก่อน VAT' : 'มูลค่าสินค้า/บริการ')
        : 'มูลค่าสินค้า/บริการ';
    $vatLabel = $isSales
        ? ($forPrint ? 'VAT 7%' : 'จำนวน VAT (7%)')
        : 'จำนวนเงินภาษีมูลค่าเพิ่ม';
    $purchaseDocLabel = ($forPrint && !$isSales) ? 'เลขใบกำกับ' : 'เลขที่บิล/ใบกำกับภาษี';
    $purchaseNameLabel = 'ชื่อผู้ขายสินค้า/บริการ';
    $salesDocLabel = $forPrint ? 'เลขใบกำกับ' : 'เลขที่ใบกำกับภาษี';
    $salesNameLabel = $forPrint ? 'ลูกค้า' : 'ชื่อลูกค้า';
    $salesDateLabel = $forPrint ? 'วันที่' : 'วันที่เอกสาร';
    $metaColspan = $isSales ? 3 : 5;
    $emptyColspan = $isSales ? 6 : ($showNet ? 8 : 7);
    $formatDocDate = static function (string $raw) use ($forPrint): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '—';
        }
        if ($forPrint && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) === 1) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }

        return $raw;
    };
    ob_start();
    ?>
    <div class="vat-print-table-wrap<?= $forPrint ? '' : ' tnc-mobile-table-wrap' ?>">
        <table class="table table-bordered table-sm vat-print-table mb-0<?= $isSales ? ' vat-print-table--sales' : ' vat-print-table--purchase' ?><?= $forPrint ? ' vat-print-table--media' : ' tnc-mobile-table' ?>">
            <colgroup>
                <col class="col-date">
                <col class="col-doc">
                <col class="col-name">
                <?php if (!$isSales): ?>
                    <col class="col-site">
                    <col class="col-cat">
                <?php endif; ?>
                <col class="col-amt col-amt-base">
                <col class="col-amt col-amt-vat">
                <?php if ($showNet): ?>
                    <col class="col-amt col-amt-net">
                <?php endif; ?>
            </colgroup>
            <thead>
            <tr>
                <?php if ($isSales): ?>
                    <th scope="col" class="col-date"><?= h($salesDateLabel) ?></th>
                    <th scope="col" class="col-doc"><?= h($salesDocLabel) ?></th>
                    <th scope="col" class="col-name"><?= h($salesNameLabel) ?></th>
                <?php else: ?>
                    <th scope="col" class="col-date">วันที่บิล</th>
                    <th scope="col" class="col-doc"><?= h($purchaseDocLabel) ?></th>
                    <th scope="col" class="col-name"><?= h($purchaseNameLabel) ?></th>
                    <th scope="col" class="col-site">ไซต์</th>
                    <th scope="col" class="col-cat">หมวด</th>
                <?php endif; ?>
                <th scope="col" class="text-end col-amt"><?= h($baseLabel) ?></th>
                <th scope="col" class="text-end col-amt"><?= h($vatLabel) ?></th>
                <?php if ($showNet): ?>
                    <th scope="col" class="text-end col-amt">ยอดสุทธิ</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="<?= $emptyColspan ?>" class="text-center text-muted py-4">ไม่พบข้อมูลตามเงื่อนไข</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $rowIndex => $row): ?>
                    <?php
                    $docNo = (string) ($isSales ? ($row['invoice_no'] ?? '') : ($row['bill_no'] ?? ''));
                    if ($isSales) {
                        $nameCol = (string) ($row['customer_name'] ?? '');
                    } else {
                        $nameCol = tnc_vat_purchase_seller_display(
                            (string) ($row['supplier_name'] ?? ''),
                            (string) ($row['supplier_tax_id'] ?? '')
                        );
                    }
                    $siteCol = trim((string) ($row['site_name'] ?? ''));
                    $catCol = trim((string) ($row['category_name'] ?? ''));
                    $docLabel = $isSales ? $salesDocLabel : $purchaseDocLabel;
                    $nameLabel = $isSales ? $salesNameLabel : $purchaseNameLabel;
                    $dateLabel = $isSales ? $salesDateLabel : 'วันที่บิล';
                    $rowClasses = [];
                    if (!$isSales && !empty($row['is_duplicate_bill'])) {
                        $rowClasses[] = 'vat-row-duplicate';
                    }
                    if ($forPrint && ($rowIndex % 2) === 1) {
                        $rowClasses[] = 'vat-row-alt';
                    }
                    $rowClassAttr = $rowClasses !== [] ? ' class="' . h(implode(' ', $rowClasses)) . '"' : '';
                    ?>
                    <tr<?= $rowClassAttr ?>>
                        <td class="col-date" data-label="<?= h($dateLabel) ?>"><?= h($formatDocDate((string) ($row['doc_date'] ?? ''))) ?></td>
                        <td class="col-doc<?= !$forPrint ? ' tnc-mobile-primary' : '' ?><?= !$isSales && !empty($row['is_duplicate_bill']) ? ' vat-duplicate-doc' : '' ?>" data-label="<?= h($docLabel) ?>"><?= $withDocLinks ? tnc_vat_render_doc_no($docNo, (string) ($row['link_url'] ?? '')) : h($docNo !== '' ? $docNo : '—') ?></td>
                        <td class="col-name" data-label="<?= h($nameLabel) ?>"><?= h($nameCol !== '' ? $nameCol : '—') ?></td>
                        <?php if (!$isSales): ?>
                            <td class="col-site" data-label="ไซต์"><?= h($siteCol !== '' ? $siteCol : '—') ?></td>
                            <td class="col-cat" data-label="หมวด"><?= h($catCol !== '' ? $catCol : '—') ?></td>
                        <?php endif; ?>
                        <td class="text-end col-amt num" data-label="<?= h($baseLabel) ?>"><?= number_format((float) ($row['base'] ?? 0), 2) ?></td>
                        <td class="text-end col-amt num" data-label="<?= h($vatLabel) ?>"><?= number_format((float) ($row['vat'] ?? 0), 2) ?></td>
                        <?php if ($showNet): ?>
                            <td class="text-end col-amt num col-amt-net" data-label="ยอดสุทธิ"><?= number_format((float) ($row['net'] ?? 0), 2) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <tfoot>
            <tr class="vat-print-total-row">
                <th colspan="<?= $metaColspan ?>" scope="row" class="vat-total-heading text-end">รวม</th>
                <td class="text-end col-amt num" data-label="<?= h($baseLabel) ?>"><?= number_format($sumBase, 2) ?></td>
                <td class="text-end col-amt num" data-label="<?= h($vatLabel) ?>"><?= number_format($sumVat, 2) ?></td>
                <?php if ($showNet): ?>
                    <td class="text-end col-amt num col-amt-net" data-label="ยอดสุทธิ"><?= number_format($sumNet, 2) ?></td>
                <?php endif; ?>
            </tr>
            </tfoot>
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

$siteNameById = [];
foreach (Db::tableRows('sites') as $site) {
    $sid = (int) ($site['id'] ?? 0);
    if ($sid > 0) {
        $siteNameById[$sid] = trim((string) ($site['name'] ?? ''));
    }
}

$prById = [];
foreach (Db::tableRows('purchase_requests') as $pr) {
    $pid = (int) ($pr['id'] ?? 0);
    if ($pid > 0) {
        $prById[$pid] = $pr;
    }
}

$poById = [];
foreach (Db::tableRows('purchase_orders') as $poRow) {
    $pid = (int) ($poRow['id'] ?? 0);
    if ($pid > 0) {
        $poById[$pid] = $poRow;
    }
}

$supplierById = [];
foreach (Db::tableRows('suppliers') as $supplierRow) {
    $sid = (int) ($supplierRow['id'] ?? 0);
    if ($sid > 0) {
        $supplierById[$sid] = $supplierRow;
    }
}

/**
 * คืนค่า [site_name, parent_name, sub_name, sub_key, category_display]
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $pr
 * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
 */
$resolvePurchaseSiteCategory = static function (array $row, ?array $pr = null) use ($siteNameById, $prById): array {
    if ($pr === null) {
        $prId = (int) ($row['pr_id'] ?? 0);
        $pr = ($prId > 0 && isset($prById[$prId])) ? $prById[$prId] : null;
    }

    $siteName = tnc_purchase_po_resolve_site_name($row, is_array($pr) ? $pr : null, $siteNameById);

    $catId = (int) ($row['cost_category_id'] ?? 0);
    $catName = trim((string) ($row['cost_category_name'] ?? ''));
    if ($catId <= 0 && is_array($pr)) {
        $catId = (int) ($pr['cost_category_id'] ?? 0);
        if ($catName === '') {
            $catName = trim((string) ($pr['cost_category_name'] ?? ''));
        }
    }

    $parentName = tnc_site_category_document_parent_name($catId, $catName);
    $subName = tnc_site_category_document_name($catId, $catName);
    if ($parentName === '' && $subName === '') {
        $parentName = 'ไม่ระบุหมวด';
        $subName = 'ไม่ระบุหมวด';
    } elseif ($parentName === '') {
        $parentName = $subName;
    } elseif ($subName === '') {
        $subName = $parentName;
    }
    $subKey = tnc_vat_purchase_sub_key($parentName, $subName);
    $categoryDisplay = tnc_vat_purchase_category_display($parentName, $subName);

    return [$siteName, $parentName, $subName, $subKey, $categoryDisplay];
};

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

// ภาษีซื้อจาก PO สมบูรณ์ — รวมทั้งมี VAT และไม่มี VAT
$poItemsByPoId = tnc_purchase_po_items_group_by_po_id();
foreach ($poById as $po) {
    if (!tnc_purchase_po_is_complete_for_report($po)) {
        continue;
    }
    $docDate = trim((string) ($po['supplier_invoice_date'] ?? ''));
    if ($docDate === '' || $docDate < $fromDate || $docDate > $toDate) {
        continue;
    }
    $poId = (int) ($po['id'] ?? 0);
    $poItems = $poItemsByPoId[$poId] ?? [];
    $amounts = tnc_purchase_report_amounts_from_po($po, $poItems);
    $subtotal = round((float) ($amounts['subtotal'] ?? 0), 2);
    $vatAmount = round((float) ($amounts['vat'] ?? 0), 2);
    $netAmount = round((float) ($amounts['net'] ?? ($subtotal + $vatAmount)), 2);
    if ($subtotal < 0) {
        $subtotal = 0.0;
    }
    if ($vatAmount < 0) {
        $vatAmount = 0.0;
    }
    if ($netAmount <= 0 && $subtotal <= 0) {
        continue;
    }
    if ($netAmount <= 0) {
        $netAmount = round($subtotal + $vatAmount, 2);
    }
    $supplierName = tnc_purchase_report_supplier_name($po);
    $supplierTaxId = tnc_vat_resolve_supplier_tax_id($po, $supplierById);
    $billNo = trim((string) ($po['supplier_invoice_no'] ?? ''));
    $seenKey = 'po:' . $poId;
    if (isset($purchaseSeen[$seenKey])) {
        continue;
    }
    $purchaseSeen[$seenKey] = true;
    $linkUrl = $poId > 0
        ? app_path('pages/purchase/purchase-order-view.php') . '?id=' . $poId
        : '';
    $prId = (int) ($po['pr_id'] ?? 0);
    $pr = ($prId > 0 && isset($prById[$prId])) ? $prById[$prId] : null;
    [$siteName, $parentName, $subName, $subKey, $categoryName] = $resolvePurchaseSiteCategory($po, is_array($pr) ? $pr : null);
    $purchaseRows[] = [
        'doc_date' => $docDate,
        'bill_no' => $billNo,
        'link_url' => $linkUrl,
        'supplier_name' => $supplierName,
        'supplier_tax_id' => $supplierTaxId,
        'site_name' => $siteName,
        'parent_category_name' => $parentName,
        'sub_category_name' => $subName,
        'sub_key' => $subKey,
        'category_name' => $categoryName,
        'base' => $subtotal,
        'vat' => $vatAmount,
        'net' => $netAmount,
        'has_vat' => $vatAmount > 0.0,
    ];
}

// ภาษีซื้ออื่น (ไม่ผูก PO) — รวมทั้งมี VAT และไม่มี VAT
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
    if ($vatAmount < 0) {
        $vatAmount = 0.0;
    }
    if ($netAmount <= 0 && $subtotal <= 0) {
        continue;
    }
    // ยอดรวมในรายงาน = มูลค่าสินค้า/บริการ + VAT
    $netAmount = round($subtotal + $vatAmount, 2);
    $supplierName = trim((string) ($bill['supplier_name'] ?? $bill['vendor_name'] ?? ''));
    $supplierTaxId = tnc_vat_resolve_supplier_tax_id($bill, $supplierById);
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
    $siteSource = $bill;
    $pr = null;
    if ($poId > 0 && isset($poById[$poId])) {
        $siteSource = $poById[$poId];
        $prId = (int) ($siteSource['pr_id'] ?? 0);
        $pr = ($prId > 0 && isset($prById[$prId])) ? $prById[$prId] : null;
        if ($supplierName === '') {
            $supplierName = tnc_purchase_report_supplier_name($siteSource);
        }
        if ($supplierTaxId === '') {
            $supplierTaxId = tnc_vat_resolve_supplier_tax_id($siteSource, $supplierById);
        }
    } else {
        $prId = (int) ($bill['pr_id'] ?? 0);
        $pr = ($prId > 0 && isset($prById[$prId])) ? $prById[$prId] : null;
    }
    [$siteName, $parentName, $subName, $subKey, $categoryName] = $resolvePurchaseSiteCategory($siteSource, is_array($pr) ? $pr : null);
    $purchaseRows[] = [
        'doc_date' => $docDate,
        'bill_no' => $billNo,
        'link_url' => $linkUrl,
        'supplier_name' => $supplierName,
        'supplier_tax_id' => $supplierTaxId,
        'site_name' => $siteName,
        'parent_category_name' => $parentName,
        'sub_category_name' => $subName,
        'sub_key' => $subKey,
        'category_name' => $categoryName,
        'base' => $subtotal,
        'vat' => $vatAmount,
        'net' => $netAmount,
        'has_vat' => $vatAmount > 0.0,
    ];
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

/** @var array<string, array{key: string, parent: string, label: string, count: int}> */
$purchaseSubCatalog = [];
foreach ($purchaseRows as $row) {
    $sk = (string) ($row['sub_key'] ?? '');
    if ($sk === '') {
        continue;
    }
    if (!isset($purchaseSubCatalog[$sk])) {
        $parentLabel = trim((string) ($row['parent_category_name'] ?? ''));
        $subLabel = trim((string) ($row['sub_category_name'] ?? ''));
        if ($parentLabel === '') {
            $parentLabel = 'ไม่ระบุหมวด';
        }
        if ($subLabel === '') {
            $subLabel = $parentLabel;
        }
        $purchaseSubCatalog[$sk] = [
            'key' => $sk,
            'parent' => $parentLabel,
            'label' => $subLabel,
            'count' => 0,
        ];
    }
    $purchaseSubCatalog[$sk]['count']++;
}

/** @var array<string, list<array{key: string, parent: string, label: string, count: int}>> */
$purchaseSubOptionsByParent = [];
foreach ($purchaseSubCatalog as $opt) {
    $parentKey = (string) $opt['parent'];
    if (!isset($purchaseSubOptionsByParent[$parentKey])) {
        $purchaseSubOptionsByParent[$parentKey] = [];
    }
    $purchaseSubOptionsByParent[$parentKey][] = $opt;
}
foreach ($purchaseSubOptionsByParent as &$optList) {
    usort($optList, static function (array $a, array $b): int {
        return strcmp((string) $a['label'], (string) $b['label']);
    });
}
unset($optList);
uksort($purchaseSubOptionsByParent, static function (string $a, string $b): int {
    return strcmp($a, $b);
});

$filterSubKeys = [];
$rawSubs = $_GET['sub'] ?? [];
if (!is_array($rawSubs)) {
    $rawSubs = $rawSubs !== '' && $rawSubs !== null ? [(string) $rawSubs] : [];
}
$validSubKeys = array_fill_keys(array_keys($purchaseSubCatalog), true);
foreach ($rawSubs as $rawKey) {
    $rawKey = trim((string) $rawKey);
    if ($rawKey === '' || !isset($validSubKeys[$rawKey])) {
        continue;
    }
    $filterSubKeys[] = $rawKey;
}
$filterSubKeys = array_values(array_unique($filterSubKeys));
$hasSubFilter = $filterSubKeys !== [];
if ($hasSubFilter) {
    $purchaseRows = tnc_vat_apply_purchase_sub_filter($purchaseRows, $filterSubKeys);
}

[$sumPurchaseBase, $sumPurchaseVat, $sumPurchaseNet] = tnc_vat_sum_purchase_rows($purchaseRows);

$filterSubLabels = [];
foreach ($filterSubKeys as $fk) {
    if (!isset($purchaseSubCatalog[$fk])) {
        continue;
    }
    $opt = $purchaseSubCatalog[$fk];
    $parent = (string) $opt['parent'];
    $label = (string) $opt['label'];
    $filterSubLabels[] = ($parent !== '' && $parent !== $label)
        ? ($parent . ' › ' . $label)
        : $label;
}
$subFilterButtonText = 'เลือกหมวดย่อย';
if ($hasSubFilter) {
    if (count($filterSubLabels) <= 2) {
        $subFilterButtonText = implode(', ', $filterSubLabels);
    } else {
        $subFilterButtonText = count($filterSubLabels) . ' หมวดย่อยที่เลือก';
    }
}

$duplicatePurchaseBills = tnc_vat_find_duplicate_purchase_bills($purchaseRows);
tnc_vat_mark_duplicate_purchase_bills($purchaseRows, $duplicatePurchaseBills);

$vatDiff = round($sumSalesVat - $sumPurchaseVat, 2);
$vatSummaryLabel = $vatDiff >= 0 ? 'ต้องชำระภาษีเพิ่ม' : 'ขอคืนภาษี';
$periodText = $fromDate . ' ถึง ' . $toDate;

$companyName = 'THEELIN CON';
$companyTaxId = '';
foreach (Db::tableRows('company') as $companyRow) {
    $name = trim((string) ($companyRow['name'] ?? ''));
    if ($name !== '') {
        $companyName = $name;
        $companyTaxId = trim((string) ($companyRow['tax_id'] ?? ''));
        break;
    }
}
$companyTaxDisplay = $companyTaxId !== '' ? $companyTaxId : 'ไม่พบเลขผู้เสียภาษี';
$companyPurchasePrintTitle = $companyName . ' · เลขผู้เสียภาษี ' . $companyTaxDisplay;

$autoPrintType = strtolower(trim((string) ($_GET['print'] ?? '')));
if ($autoPrintType !== 'sales' && $autoPrintType !== 'purchase') {
    $autoPrintType = '';
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
    if ($hasSubFilter) {
        echo tnc_vat_csv_row(['กรองหมวดย่อย', implode('; ', $filterSubLabels)]);
    }
    echo tnc_vat_csv_row([]);
    echo tnc_vat_csv_row(['วันที่บิล', 'เลขที่บิล/ใบกำกับภาษี', 'ชื่อผู้ขายสินค้า/บริการ', 'เลขผู้เสียภาษี', 'ไซต์', 'หมวด', 'มูลค่าสินค้า/บริการ', 'จำนวนเงินภาษีมูลค่าเพิ่ม']);
    foreach ($purchaseRows as $row) {
        $sellerTax = trim((string) ($row['supplier_tax_id'] ?? ''));
        echo tnc_vat_csv_row([
            (string) ($row['doc_date'] ?? ''),
            (string) ($row['bill_no'] ?? ''),
            (string) ($row['supplier_name'] ?? ''),
            $sellerTax !== '' ? $sellerTax : 'ไม่พบเลขผู้เสียภาษี',
            (string) ($row['site_name'] ?? ''),
            (string) ($row['category_name'] ?? ''),
            number_format((float) ($row['base'] ?? 0), 2, '.', ''),
            number_format((float) ($row['vat'] ?? 0), 2, '.', ''),
        ]);
    }
    echo tnc_vat_csv_row(['รวม', '', '', '', '', '', number_format($sumPurchaseBase, 2, '.', ''), number_format($sumPurchaseVat, 2, '.', '')]);

    echo tnc_vat_csv_row([]);
    echo tnc_vat_csv_row(['สรุปภาพรวม', 'จำนวนเงิน']);
    echo tnc_vat_csv_row(['ภาษีขายรวม', number_format($sumSalesVat, 2, '.', '')]);
    echo tnc_vat_csv_row(['ภาษีซื้อรวม', number_format($sumPurchaseVat, 2, '.', '')]);
    echo tnc_vat_csv_row([$vatSummaryLabel . ' (ภาษีขาย - ภาษีซื้อ)', number_format(abs($vatDiff), 2, '.', '')]);
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
        'vat_print' => true,
        'include_ops_ui' => false,
        'sarabun_weights' => '400;600;700;800',
    ]);
    ?>
</head>
<body class="tnc-app-body tnc-layout-list vat-report-page">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container pb-5 pt-4">
    <div class="tnc-page-head mb-3 no-print">
        <div>
            <p class="tnc-page-kicker">Reports · Accounting</p>
            <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-receipt"></i></span>รายงานภาษีซื้อ / ภาษีขาย (VAT)</h1>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php
            require_once dirname(__DIR__, 2) . '/includes/tnc_ui.php';
            echo tnc_ui_back_previous_button(['no_print' => true]);
            ?>
        </div>
    </div>

    <div class="vat-print-sheet" id="vatPrintSheetSales" aria-hidden="true">
        <header class="vat-print-header">
            <div>
                <p class="vat-print-kicker">รายงานภาษีขาย</p>
                <h1 class="vat-print-title"><?= h($companyName) ?> - ภาษีขาย</h1>
            </div>
            <div class="vat-print-meta">
                <div>ช่วงข้อมูล: <strong><?= h($periodText) ?></strong></div>
                <div>จำนวนรายการ: <strong><?= number_format(count($salesRows)) ?></strong></div>
            </div>
        </header>
        <?= tnc_vat_render_table_html(true, $salesRows, $sumSalesBase, $sumSalesVat, $sumSalesNet, false, true) ?>
    </div>

    <div class="vat-print-sheet" id="vatPrintSheetPurchase" aria-hidden="true">
        <header class="vat-print-header">
            <div>
                <h1 class="vat-print-title"><?= h($companyPurchasePrintTitle) ?></h1>
            </div>
            <div class="vat-print-meta">
                <div>ช่วงข้อมูล: <strong><?= h($periodText) ?></strong></div>
            </div>
        </header>
        <?= tnc_vat_render_table_html(false, $purchaseRows, $sumPurchaseBase, $sumPurchaseVat, $sumPurchaseNet, false, true) ?>
    </div>

    <div class="card card-soft mb-3 vat-filter-card no-print">
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
                <div class="col-12">
                    <label class="form-label small fw-semibold">หมวดย่อย (ภาษีซื้อ)</label>
                    <?php if ($purchaseSubOptionsByParent === []): ?>
                        <div class="form-control bg-light text-muted">ไม่มีหมวดย่อยในช่วงนี้</div>
                    <?php else: ?>
                        <div class="dropdown cat-filter-dropdown vat-sub-filter-dropdown w-100">
                            <button type="button" class="btn dropdown-toggle cat-filter-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" id="vatSubFilterToggle">
                                <span class="cat-filter-toggle-text" id="vatSubFilterLabel"><?= h($subFilterButtonText) ?></span>
                            </button>
                            <div class="dropdown-menu cat-filter-menu w-100">
                                <div class="cat-filter-menu-inner">
                                    <?php foreach ($purchaseSubOptionsByParent as $parentLabel => $opts): ?>
                                        <div class="cat-filter-group-label"><?= h((string) $parentLabel) ?></div>
                                        <?php foreach ($opts as $opt): ?>
                                            <label class="dropdown-item cat-filter-option">
                                                <input
                                                    type="checkbox"
                                                    class="cat-filter-check vat-sub-filter-check"
                                                    name="sub[]"
                                                    value="<?= h((string) $opt['key']) ?>"
                                                    data-label="<?= h((string) $opt['label']) ?>"
                                                    <?= in_array((string) $opt['key'], $filterSubKeys, true) ? 'checked' : '' ?>
                                                >
                                                <span><?= h((string) $opt['label']) ?> <span class="text-muted">(<?= (int) $opt['count'] ?>)</span></span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </div>
                                <div class="cat-filter-menu-foot border-top px-3 py-2">
                                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="vatSubFilterClearBtn">ล้างการเลือก</button>
                                </div>
                            </div>
                        </div>
                        <div class="form-text">ไม่ติ๊ก = แสดงทั้งหมด · ติ๊กเฉพาะที่ต้องการโชว์</div>
                    <?php endif; ?>
                </div>
                <div class="col-12 vat-filter-actions">
                    <button type="button" class="btn btn-outline-orange rounded-pill px-3" onclick="tncVatReportPrint('sales', event)">
                        <i class="bi bi-printer me-1"></i>พิมพ์ภาษีขาย
                    </button>
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3" onclick="tncVatReportPrint('purchase', event)">
                        <i class="bi bi-printer me-1"></i>พิมพ์ภาษีซื้อ
                    </button>
                    <?php
                    $exportQuery = $_GET;
                    unset($exportQuery['print']);
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
        <div class="no-print">
            <?= tnc_vat_render_duplicate_bill_alert($duplicatePurchaseBills) ?>
        </div>
    <?php endif; ?>

    <div class="card card-soft mb-3 vat-table-card no-print">
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
                        · แสดงบิลทั้งหมด รวมบิลเงินสด และบิลไม่มี VAT
                        <?php if ($hasSubFilter): ?>
                            · กรองหมวดย่อย: <strong><?= h(implode(', ', $filterSubLabels)) ?></strong>
                        <?php endif; ?>
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
<script>
function tncVatReportPrint(type, e) {
    if (e && typeof e.preventDefault === 'function') {
        e.preventDefault();
    }
    var body = document.body;
    body.classList.remove('vat-printing-sales', 'vat-printing-purchase');
    body.classList.add(type === 'purchase' ? 'vat-printing-purchase' : 'vat-printing-sales');
    window.print();
}

(function () {
    var clearPrintMode = function () {
        document.body.classList.remove('vat-printing-sales', 'vat-printing-purchase');
    };
    window.addEventListener('afterprint', clearPrintMode);

    <?php if ($autoPrintType !== ''): ?>
    window.addEventListener('load', function () {
        setTimeout(function () {
            tncVatReportPrint(<?= json_encode($autoPrintType, JSON_UNESCAPED_UNICODE) ?>);
        }, 350);
    });
    <?php endif; ?>

    var labelEl = document.getElementById('vatSubFilterLabel');
    var checks = document.querySelectorAll('.vat-sub-filter-check');
    var clearBtn = document.getElementById('vatSubFilterClearBtn');
    if (!labelEl || checks.length === 0) {
        return;
    }
    function updateSubFilterLabel() {
        var selected = [];
        checks.forEach(function (cb) {
            if (cb.checked) {
                selected.push(cb.getAttribute('data-label') || cb.value);
            }
        });
        if (selected.length === 0) {
            labelEl.textContent = 'เลือกหมวดย่อย';
        } else if (selected.length <= 2) {
            labelEl.textContent = selected.join(', ');
        } else {
            labelEl.textContent = selected.length + ' หมวดย่อยที่เลือก';
        }
    }
    checks.forEach(function (cb) {
        cb.addEventListener('change', updateSubFilterLabel);
    });
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            checks.forEach(function (cb) { cb.checked = false; });
            updateSubFilterLabel();
        });
    }
})();
</script>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
