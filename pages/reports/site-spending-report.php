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

/** หาวันที่ Y-m-d จาก field ที่เป็นไปได้ */
function tnc_site_doc_date(array $row, array $fields): string
{
    foreach ($fields as $f) {
        $v = trim((string) ($row[$f] ?? ''));
        if ($v === '') {
            continue;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
            return $v;
        }
        $ts = strtotime($v);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }

    return '';
}

/** @return float ยอดที่จ่ายแล้วของ PO — ใช้ยอดจากรายการ PO ปัจจุบัน */
function tnc_site_spending_paid_amount(array $po, array $items = []): float
{
    $amounts = tnc_purchase_report_amounts_from_po($po, $items);
    $net = round((float) ($amounts['net'] ?? 0), 2);
    if ($net > 0) {
        return $net;
    }

    $orderTotal = round((float) ($po['total_amount'] ?? 0), 2);
    $billed = round((float) ($po['billed_total_amount'] ?? 0), 2);
    $paid = $billed > 0 ? $billed : $orderTotal;

    return $paid > 0 ? $paid : 0.0;
}

/** วันที่ใช้จัดเข้าเดือนรายงาน — ใช้วันที่ออก PO ก่อน (รองรับ PO ย้อนหลัง) แล้ว fallback วันที่จ่าย */
function tnc_site_spending_paid_date(array $po): string
{
    $issueDate = tnc_site_doc_date($po, ['issue_date']);
    if ($issueDate !== '') {
        return $issueDate;
    }

    $paidAt = tnc_site_doc_date($po, ['payment_marked_paid_at']);
    if ($paidAt !== '') {
        return $paidAt;
    }

    return tnc_site_doc_date($po, ['created_at']);
}

/** PO สมบูรณ์ = ชำระแล้ว + มีเลขที่ใบกำกับ (ตามเกณฑ์หน้ารายการ PO) */
function tnc_site_spending_po_is_complete(array $po): bool
{
    return tnc_purchase_po_is_complete_for_report($po);
}

/**
 * ยอดรายการ = PO สมบูรณ์ที่จ่ายแล้วเท่านั้น — คงไซต์/หมวดที่มียอดจ่ายแล้ว > 0
 * ยอดรวมไซต์ = ผลรวมหมวดที่แสดง (ให้ตรงกับรายการย่อย)
 *
 * @param array<string, array<string, mixed>> $sites
 * @return array<string, array<string, mixed>>
 */
function tnc_site_spending_keep_paid_only(array $sites): array
{
    $out = [];
    foreach ($sites as $key => $site) {
        $cats = [];
        $sumPaid = 0.0;
        foreach ($site['categories'] as $ck => $cv) {
            $paid = round((float) ($cv['paid_total'] ?? 0), 2);
            if ($paid <= 0.0) {
                continue;
            }
            $cv['paid_total'] = $paid;
            $cv['label'] = $ck === 'none'
                ? 'ไม่ระบุหมวด'
                : (string) ($cv['label'] ?? $ck);
            $cats[$ck] = $cv;
            $sumPaid += $paid;
        }
        if ($sumPaid <= 0.0) {
            continue;
        }
        uasort($cats, static function (array $a, array $b): int {
            return ($b['paid_total'] <=> $a['paid_total']) ?: strcmp($a['label'], $b['label']);
        });
        $site['categories'] = $cats;
        $site['paid_total'] = round($sumPaid, 2);
        $out[$key] = $site;
    }
    uasort($out, static function (array $a, array $b): int {
        return ($b['paid_total'] <=> $a['paid_total']) ?: strcmp($a['label'], $b['label']);
    });

    return $out;
}

/**
 * กรองไซต์ตามหมวดที่เลือก — คงเฉพาะไซต์ที่มีหมวดนั้น และคำนวณยอดจ่ายแล้วจากหมวดที่เลือกเท่านั้น
 *
 * @param array<string, array<string, mixed>> $sites
 * @param list<string> $filterCatKeys
 * @return array<string, array<string, mixed>>
 */
function tnc_site_spending_apply_cat_filter(array $sites, array $filterCatKeys): array
{
    if ($filterCatKeys === []) {
        return $sites;
    }
    $allowed = array_fill_keys($filterCatKeys, true);
    $filtered = [];
    foreach ($sites as $key => $site) {
        $cats = [];
        $paidTotal = 0.0;
        foreach ($site['categories'] as $ck => $cv) {
            if ($ck === 'none' || !isset($allowed[$ck])) {
                continue;
            }
            $paid = round((float) ($cv['paid_total'] ?? 0), 2);
            if ($paid <= 0.0) {
                continue;
            }
            $cv['paid_total'] = $paid;
            $cats[$ck] = $cv;
            $paidTotal += $paid;
        }
        if ($cats === []) {
            continue;
        }
        $site['categories'] = $cats;
        $site['paid_total'] = round($paidTotal, 2);
        $filtered[$key] = $site;
    }
    uasort($filtered, static function (array $a, array $b): int {
        return ($b['paid_total'] <=> $a['paid_total']) ?: strcmp($a['label'], $b['label']);
    });

    return $filtered;
}

// ---------- ช่วงวันที่ (เดือน/ปี) ----------
$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
if ($month < 1 || $month > 12) {
    $month = (int) date('n');
}
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$fromDate = sprintf('%04d-%02d-01', $year, $month);
$toDate = date('Y-m-t', strtotime($fromDate));
$periodText = 'เดือน ' . sprintf('%02d/%04d', $month, $year);
$thaiMonthNames = [
    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
    5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
    9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
];
$periodLabelLong = ($thaiMonthNames[$month] ?? sprintf('%02d', $month)) . ' ' . $year;

// ---------- เตรียม map ไซต์ / PR ----------
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

/**
 * คืนค่า [siteId, siteLabel] โดยลำดับ: site_id ของเอกสาร -> site ของ PR -> ไม่ระบุ
 */
$resolveSite = static function (array $row) use ($siteNameById, $prById): array {
    $siteId = (int) ($row['site_id'] ?? 0);
    $siteName = trim((string) ($row['site_name'] ?? ''));
    if ($siteId <= 0) {
        $prId = (int) ($row['pr_id'] ?? 0);
        if ($prId > 0 && isset($prById[$prId])) {
            $siteId = (int) ($prById[$prId]['site_id'] ?? 0);
            if ($siteName === '') {
                $siteName = trim((string) ($prById[$prId]['site_name'] ?? ''));
            }
        }
    }
    if ($siteId > 0 && isset($siteNameById[$siteId]) && $siteNameById[$siteId] !== '') {
        $siteName = $siteNameById[$siteId];
    }
    if ($siteName === '') {
        $siteName = 'ไม่ระบุไซต์';
    }
    $key = $siteId > 0 ? ('id:' . $siteId) : ('name:' . mb_strtolower($siteName));

    return [$key, $siteName];
};

// ---------- รวมยอดต่อไซต์ ----------
$sites = [];
$ensureSite = static function (string $key, string $label) use (&$sites): void {
    if (!isset($sites[$key])) {
        $sites[$key] = [
            'label' => $label,
            'pr_count' => 0,
            'pr_total' => 0.0,
            'po_count' => 0,
            'po_total' => 0.0,
            'paid_total' => 0.0,
            'categories' => [], // [catKey => [label, pr_total, po_total, paid_total]]
        ];
    }
};

/** คืนค่า [catKey, catLabel] — ดึงจาก PO ก่อน แล้ว fallback จาก PR ที่เชื่อม */
$resolveCategory = static function (array $row) use ($prById): array {
    $cid = (int) ($row['cost_category_id'] ?? 0);
    $cname = trim((string) ($row['cost_category_name'] ?? ''));
    if ($cid <= 0) {
        $prId = (int) ($row['pr_id'] ?? 0);
        if ($prId > 0 && isset($prById[$prId])) {
            $pr = $prById[$prId];
            $cid = (int) ($pr['cost_category_id'] ?? 0);
            if ($cname === '') {
                $cname = trim((string) ($pr['cost_category_name'] ?? ''));
            }
        }
    }
    if ($cid > 0) {
        return ['cid:' . $cid, $cname !== '' ? $cname : ('หมวด #' . $cid)];
    }

    return ['none', 'ไม่ระบุหมวด'];
};

$ensureCat = static function (array &$site, string $catKey, string $catLabel): void {
    if (!isset($site['categories'][$catKey])) {
        $site['categories'][$catKey] = [
            'label' => $catLabel,
            'pr_total' => 0.0,
            'po_total' => 0.0,
            'paid_total' => 0.0,
        ];
    }
};

// PO สมบูรณ์ + จ่ายแล้วเท่านั้น — กรองตามวันที่ออก PO (issue_date)
$poItemsByPoId = tnc_purchase_po_items_group_by_po_id();
foreach (Db::tableRows('purchase_orders') as $po) {
    if (!tnc_site_spending_po_is_complete($po)) {
        continue;
    }
    $poId = (int) ($po['id'] ?? 0);
    $poItems = $poItemsByPoId[$poId] ?? [];
    $paidAmount = tnc_site_spending_paid_amount($po, $poItems);
    if ($paidAmount <= 0.0) {
        continue;
    }
    $paidDate = tnc_site_spending_paid_date($po);
    if ($paidDate === '' || $paidDate < $fromDate || $paidDate > $toDate) {
        continue;
    }
    [$key, $label] = $resolveSite($po);
    $ensureSite($key, $label);

    $orderTotal = round((float) (tnc_purchase_report_amounts_from_po($po, $poItems)['net'] ?? 0), 2);
    if ($orderTotal <= 0.0) {
        $orderTotal = $paidAmount;
    }
    $sites[$key]['po_count']++;
    $sites[$key]['po_total'] += $orderTotal;
    $sites[$key]['paid_total'] += $paidAmount;

    [$catKey, $catLabel] = $resolveCategory($po);
    $ensureCat($sites[$key], $catKey, $catLabel);
    $sites[$key]['categories'][$catKey]['po_total'] += $orderTotal;
    $sites[$key]['categories'][$catKey]['paid_total'] += $paidAmount;
}

// outstanding + เรียงตามยอดจ่ายแล้วมากสุด
foreach ($sites as &$s) {
    $s['outstanding'] = round($s['po_total'] - $s['paid_total'], 2);
    if (!empty($s['categories'])) {
        uasort($s['categories'], static function (array $a, array $b): int {
            return ($b['paid_total'] <=> $a['paid_total'])
                ?: ($b['po_total'] <=> $a['po_total'])
                ?: strcmp($a['label'], $b['label']);
        });
    }
}
unset($s);
uasort($sites, static function (array $a, array $b): int {
    return ($b['paid_total'] <=> $a['paid_total']) ?: strcmp($a['label'], $b['label']);
});

$sites = tnc_site_spending_keep_paid_only($sites);

// ---------- ตัวเลือกหมวด + กรองตามหมวด ----------
$categoryCatalog = [];
foreach ($sites as $s) {
    foreach ($s['categories'] as $ck => $cv) {
        if ($ck === 'none') {
            continue;
        }
        $paid = round((float) ($cv['paid_total'] ?? 0), 2);
        if ($paid <= 0.0) {
            continue;
        }
        if (!isset($categoryCatalog[$ck])) {
            $categoryCatalog[$ck] = [
                'label' => (string) ($cv['label'] ?? $ck),
                'paid_total' => 0.0,
            ];
        }
        $categoryCatalog[$ck]['paid_total'] += $paid;
    }
}
$filterCategoryOptions = $categoryCatalog;
uasort($filterCategoryOptions, static function (array $a, array $b): int {
    return ($b['paid_total'] <=> $a['paid_total']) ?: strcmp($a['label'], $b['label']);
});

$filterCatKeys = [];
if (isset($_GET['cat'])) {
    $rawCats = $_GET['cat'];
    if (!is_array($rawCats)) {
        $rawCats = [$rawCats];
    }
    $validKeys = array_fill_keys(array_keys($filterCategoryOptions), true);
    foreach ($rawCats as $rawKey) {
        $rawKey = trim((string) $rawKey);
        if ($rawKey !== '' && $rawKey !== 'none' && isset($validKeys[$rawKey])) {
            $filterCatKeys[] = $rawKey;
        }
    }
    $filterCatKeys = array_values(array_unique($filterCatKeys));
}

$filterCatLabels = [];
foreach ($filterCatKeys as $fk) {
    if (isset($categoryCatalog[$fk]['label'])) {
        $filterCatLabels[] = (string) $categoryCatalog[$fk]['label'];
    }
}
$hasCatFilter = $filterCatKeys !== [];
if ($hasCatFilter) {
    $sites = tnc_site_spending_apply_cat_filter($sites, $filterCatKeys);
}

$catFilterButtonText = 'เลือกหมวดค่าใช้จ่าย';
if ($hasCatFilter) {
    if (count($filterCatLabels) <= 2) {
        $catFilterButtonText = implode(', ', $filterCatLabels);
    } else {
        $catFilterButtonText = count($filterCatLabels) . ' หมวดที่เลือก';
    }
}

$grandPrCount = 0;
$grandPrTotal = 0.0;
$grandPoCount = 0;
$grandPoTotal = 0.0;
$grandPaid = 0.0;
$grandOutstanding = 0.0;
foreach ($sites as $s) {
    $grandPrCount += $s['pr_count'];
    $grandPrTotal += $s['pr_total'];
    $grandPoCount += $s['po_count'];
    $grandPoTotal += $s['po_total'];
    $grandPaid += $s['paid_total'];
    $grandOutstanding += $s['outstanding'];
}

// ---------- ข้อมูลบริษัทสำหรับหัวกระดาษพิมพ์ ----------
$companyRows = Db::tableRows('company');
Db::sortRows($companyRows, 'id', false);
$company = $companyRows[0] ?? [];
$companyName = trim((string) ($company['name'] ?? '')) !== '' ? trim((string) $company['name']) : 'THEELIN CON';
$printedAt = date('d/m/Y H:i');
$autoPrint = ($_GET['print'] ?? '') === '1';
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานการใช้จ่ายตามไซต์ (Site Spending)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= h(app_path('assets/css/tnc-app.css')) ?>">
    <style>
        :root {
            --site-a4-landscape-w: 297mm;
            --site-print-pad: 12mm;
        }

        .card-soft {
            border: 1px solid var(--tnc-orange-border, #fdba74);
            border-radius: 0.875rem;
            background: #fff;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
        }

        .report-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.8125rem;
            font-weight: 600;
            padding: 0.35rem 0.75rem;
            border-radius: 50rem;
            border: 1px solid var(--tnc-border-soft, #e2e8f0);
            background: #fff;
            color: var(--tnc-body-ink, #1f2937);
        }
        .report-badge--filter {
            border-color: var(--tnc-orange-border, #fdba74);
            background: var(--tnc-orange-soft, #ffedd5);
            color: var(--tnc-orange-dark, #9a3412);
        }
        .report-badge--accent {
            border-color: var(--tnc-orange-border, #fdba74);
            background: #fff;
            color: var(--tnc-orange-dark, #9a3412);
            font-weight: 700;
        }
        .report-note {
            font-size: 0.8125rem;
            color: var(--tnc-muted, #64748b);
            line-height: 1.45;
        }

        .btn-orange {
            background: #ea580c;
            border-color: #ea580c;
            color: #fff;
            font-weight: 600;
        }
        .btn-orange:hover,
        .btn-orange:focus {
            background: #c2410c;
            border-color: #c2410c;
            color: #fff;
        }

        .report-filter-form { margin: 0; }
        .report-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            justify-content: space-between;
            gap: 0.85rem 1rem;
        }
        .report-toolbar__main {
            flex: 1 1 22rem;
            min-width: 0;
            margin: 0;
        }
        .report-toolbar__tools {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: 0.5rem;
            flex: 0 0 auto;
        }
        .report-toolbar__submit,
        .report-toolbar__tools .btn {
            min-height: calc(1.5em + 0.75rem + 2px);
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
        }
        .report-toolbar__submit {
            padding-left: 1.15rem;
            padding-right: 1.15rem;
        }

        .cat-filter-dropdown .cat-filter-toggle {
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
            background: #fff;
            color: var(--tnc-body-ink, #1f2937);
            font-size: 1rem;
            line-height: 1.5;
            padding: 0.375rem 2.1rem 0.375rem 0.75rem;
            min-height: calc(1.5em + 0.75rem + 2px);
            width: 100%;
            box-shadow: none !important;
            position: relative;
            text-align: left;
        }
        .cat-filter-dropdown .cat-filter-toggle::after {
            position: absolute;
            right: 0.85rem;
            top: 50%;
            margin: 0;
            transform: translateY(-50%);
        }
        .cat-filter-dropdown .cat-filter-toggle:hover,
        .cat-filter-dropdown.show .cat-filter-toggle {
            border-color: var(--tnc-orange-border, #fdba74);
        }
        .cat-filter-dropdown .cat-filter-toggle-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: block;
            width: 100%;
        }
        .cat-filter-menu {
            max-height: 280px;
            overflow: hidden;
            border-color: var(--tnc-orange-border, #fdba74);
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
        }
        .cat-filter-menu-inner {
            max-height: 220px;
            overflow-y: auto;
        }
        .cat-filter-option {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            margin: 0;
            padding: 0.45rem 0.85rem;
            font-size: 0.875rem;
            cursor: pointer;
        }
        .cat-filter-option:hover { background: var(--tnc-orange-soft, #ffedd5); }
        .cat-filter-option:has(input:checked) {
            background: #fff7ed;
            font-weight: 600;
            color: var(--tnc-orange-dark, #9a3412);
        }
        .cat-filter-option input { margin: 0; flex-shrink: 0; }
        .cat-filter-menu-foot { background: #f8fafc; }

        .site-report-context {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }

        .site-spend-table-wrap {
            width: 100%;
        }

        #spendTable {
            border-collapse: separate;
            border-spacing: 0;
            table-layout: fixed;
            width: 100% !important;
            min-width: 100%;
        }
        #spendTable col.spend-col-label { width: 68%; }
        #spendTable col.spend-col-amt { width: 32%; }
        #spendTable thead th {
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
            color: #334155;
            font-weight: 700;
            font-size: 0.8125rem;
            border-bottom: 2px solid var(--tnc-orange-border, #fdba74);
            padding: 0.7rem 0.9rem;
        }
        #spendTable thead th.col-amt { text-align: right; }
        @media (min-width: 992px) {
            #spendTable tbody tr.site-row,
            #spendTable tbody tr.cat-detail-row,
            #spendTable tfoot tr.grand-row {
                display: table-row;
                margin: 0;
                padding: 0;
                border: 0;
                border-radius: 0;
                box-shadow: none;
                background: transparent;
            }
            #spendTable tbody tr.site-row td,
            #spendTable tbody tr.cat-detail-row > td,
            #spendTable tfoot tr.grand-row td {
                display: table-cell;
                padding: 0.65rem 0.9rem;
                border-top: 1px solid #eef2f7;
            }
            #spendTable tbody tr.site-row td:first-child,
            #spendTable tfoot tr.grand-row td:first-child {
                width: auto;
                flex: none;
                min-width: 0;
            }
            #spendTable tbody tr.site-row td.col-amt,
            #spendTable tfoot tr.grand-row td.col-amt {
                text-align: right;
                font-size: inherit;
                flex: none;
            }
            #spendTable tfoot tr.grand-row td {
                background: #f8fafc;
            }
        }
        #spendTable td { padding: 0.65rem 0.9rem; vertical-align: middle; }
        #spendTable td.col-amt { text-align: right; }
        #spendTable .num {
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
            font-weight: 700;
        }
        .site-row { border-top: 1px solid #eef2f7; }
        .site-row:hover { background: var(--tnc-orange-soft, #ffedd5); }
        .site-row .site-name { font-weight: 700; color: var(--tnc-ink, #0f172a); }
        .btn-cat-toggle {
            min-width: 2.25rem;
            min-height: 2.25rem;
            width: 2.25rem;
            height: 2.25rem;
            flex: 0 0 2.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem;
            border: 1px solid var(--tnc-border-soft, #e2e8f0);
            background: #fff;
            color: var(--tnc-muted, #64748b);
        }
        .btn-cat-toggle:hover {
            background: var(--tnc-orange-soft, #ffedd5);
            color: var(--tnc-orange-dark, #9a3412);
        }
        .cat-toggle-spacer { display: inline-block; width: 2.25rem; flex: 0 0 2.25rem; }
        .btn-cat-toggle .bi-caret-right-fill {
            transition: transform 0.15s ease;
            font-size: 0.7rem;
        }
        [aria-expanded="true"] .bi-caret-right-fill { transform: rotate(90deg); }
        .cat-breakdown {
            background: #fcfdfe;
            margin: 0;
            table-layout: fixed;
            width: 100%;
        }
        .cat-breakdown col.spend-col-label { width: 68%; }
        .cat-breakdown col.spend-col-amt { width: 32%; }
        .cat-breakdown td {
            border: 0;
            border-bottom: 1px dashed #eef2f7;
            font-size: 0.8125rem;
            padding: 0.5rem 0.9rem;
        }
        .cat-breakdown td.col-amt { text-align: right; }
        .cat-breakdown tr:last-child td { border-bottom: 0; }
        .cat-breakdown .cat-name {
            color: #475569;
            padding-left: 3.25rem;
        }
        .cat-breakdown .cat-name .bi { color: var(--tnc-orange, #ea580c); font-size: 0.75rem; }
        .cat-detail-row > td { background: rgba(255, 237, 213, 0.28); }
        .grand-row td {
            background: #f8fafc;
            border-top: 2px solid var(--tnc-orange-border, #fdba74);
            font-weight: 800;
        }
        .grand-row .num { font-size: 1.05rem; color: var(--tnc-orange-dark, #9a3412); }

        /* ---- Print sheet (screen hidden, print visible) ---- */
        .site-print-sheet {
            display: none;
        }
        .site-print-header {
            display: block;
            text-align: center;
            margin-bottom: 1.25rem;
            padding-bottom: 0.85rem;
            border-bottom: 2px solid #0f172a;
        }
        .site-print-company {
            font-size: 0.82rem;
            font-weight: 700;
            color: #475569;
            margin: 0 0 0.35rem;
            letter-spacing: 0.02em;
        }
        .site-print-title {
            font-size: 1.15rem;
            font-weight: 800;
            margin: 0 0 0.5rem;
            line-height: 1.25;
            color: #0f172a;
        }
        .site-print-meta {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 0.35rem 0.85rem;
            font-size: 0.78rem;
            color: #475569;
            line-height: 1.5;
            margin: 0;
        }
        .site-print-meta strong { color: #0f172a; }
        .site-print-meta-dot { color: #94a3b8; user-select: none; }
        .site-print-body {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .site-print-block {
            border: 1px solid #94a3b8;
            border-radius: 0.35rem;
            overflow: hidden;
            background: #fff;
        }
        .site-print-block-head {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 0.65rem;
            padding: 0.55rem 1rem;
            background: #fff7ed;
            border-bottom: 1px solid #cbd5e1;
        }
        .site-print-block-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.75rem;
            height: 1.75rem;
            padding: 0 0.35rem;
            font-size: 0.72rem;
            font-weight: 800;
            color: #fff;
            background: #c2410c;
            border-radius: 0.25rem;
            line-height: 1;
        }
        .site-print-block-title {
            margin: 0;
            font-size: 0.92rem;
            font-weight: 800;
            line-height: 1.3;
            color: #0f172a;
        }
        .site-print-block-total {
            font-size: 0.92rem;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            color: #9a3412;
            white-space: nowrap;
        }
        .site-print-block-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 0.8125rem;
        }
        .site-print-block-table col.col-cat { width: 68%; }
        .site-print-block-table col.col-amt { width: 32%; }
        .site-print-block-table thead th {
            padding: 0.4rem 1rem;
            font-size: 0.72rem;
            font-weight: 700;
            color: #475569;
            text-align: left;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        .site-print-block-table thead th.col-amt { text-align: right; }
        .site-print-block-table tbody td {
            padding: 0.42rem 1rem;
            vertical-align: middle;
            border-bottom: 1px dotted #e2e8f0;
        }
        .site-print-block-table tbody tr:last-child td { border-bottom: 0; }
        .site-print-block-table .col-amt { text-align: right; }
        .site-print-block-table .num {
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .site-print-grand {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 0.25rem;
            padding: 0.65rem 0.85rem;
            border: 2px solid #0f172a;
            background: #f1f5f9;
            font-weight: 800;
            font-size: 0.95rem;
        }
        .site-print-grand .num {
            font-size: 1.05rem;
            font-variant-numeric: tabular-nums;
            color: #9a3412;
        }
        .site-print-footnote {
            margin-top: 0.85rem;
            font-size: 0.72rem;
            color: #64748b;
            line-height: 1.45;
            text-align: center;
        }
        .site-print-empty {
            padding: 1rem 0;
            color: #64748b;
            font-size: 0.875rem;
            text-align: center;
        }

        @media (max-width: 575.98px) {
            .card-soft .card-body { padding: 1rem; }
            .report-toolbar { flex-direction: column; align-items: stretch; }
            .report-toolbar__tools .btn { flex: 1 1 calc(50% - 0.25rem); justify-content: center; }
            .report-toolbar__submit { flex: 1 1 100% !important; }
            #spendTable thead th,
            #spendTable td { font-size: 0.76rem; padding: 0.45rem 0.55rem; }
            .cat-breakdown .cat-name { padding-left: 1.75rem; }
        }

        @media (prefers-reduced-motion: reduce) {
            .btn-cat-toggle .bi-caret-right-fill { transition: none; }
        }

        @media print {
            @page {
                size: A4 landscape;
                margin: 14mm 18mm;
            }
            html, body {
                width: 100%;
                height: auto;
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
                font-family: 'Sarabun', 'Leelawadee UI', sans-serif !important;
                font-size: 10pt;
                color: #000 !important;
            }
            .no-print,
            nav,
            .navbar { display: none !important; }
            .container {
                max-width: 100% !important;
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .site-print-sheet {
                display: block !important;
                box-sizing: border-box;
                padding: 0 5mm;
            }
            .site-print-header {
                display: block !important;
                text-align: center !important;
                margin-bottom: 7mm;
                padding-bottom: 4mm;
                border-bottom: 1pt solid #333;
            }
            .site-print-company {
                font-size: 9pt;
                color: #333 !important;
                margin-bottom: 1.5mm;
            }
            .site-print-title {
                font-size: 14pt;
                margin-bottom: 3mm;
            }
            .site-print-meta {
                display: flex !important;
                flex-wrap: wrap;
                justify-content: center !important;
                gap: 1mm 4mm;
                font-size: 8.5pt;
                color: #000 !important;
                text-align: center !important;
                max-width: none !important;
            }
            .site-print-body {
                gap: 5mm;
            }
            .site-print-block {
                border: 0.75pt solid #555;
                border-radius: 0;
                break-inside: avoid-page;
                page-break-inside: avoid;
            }
            .site-print-block-head {
                padding: 2.5mm 5mm;
                background: #eee !important;
                border-bottom: 0.75pt solid #555;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .site-print-block-num {
                background: #333 !important;
                color: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .site-print-block-title { font-size: 10pt; }
            .site-print-block-total { font-size: 10pt; color: #000 !important; }
            .site-print-block-table { font-size: 9pt; }
            .site-print-block-table thead th {
                padding: 2mm 5mm;
                background: #f5f5f5 !important;
                border-bottom: 0.75pt solid #555;
                font-size: 8pt;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .site-print-block-table tbody td {
                padding: 1.8mm 5mm;
                border-bottom: 0.5pt dotted #888;
            }
            .site-print-grand {
                margin-top: 3mm;
                padding: 3mm 5mm;
                border: 1pt solid #333;
                background: #e8e8e8 !important;
                font-size: 10pt;
                break-inside: avoid;
                page-break-inside: avoid;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .site-print-grand .num { font-size: 11pt; color: #000 !important; }
            .site-print-footnote {
                margin-top: 5mm;
                padding: 0 5mm;
                font-size: 7.5pt;
                color: #333 !important;
                text-align: center;
            }
        }
    </style>
</head>
<body class="tnc-app-body tnc-layout-list">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container pb-5 pt-4">
    <div class="tnc-page-head mb-3 no-print">
        <div>
            <p class="tnc-page-kicker">รายงาน · การใช้จ่ายตามไซต์</p>
            <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-geo-alt"></i></span>รายงานการใช้จ่ายแยกตามไซต์</h1>
        </div>
    </div>

    <div class="site-print-sheet" id="sitePrintSheet" aria-hidden="true">
        <header class="site-print-header">
            <p class="site-print-company"><?= h($companyName) ?></p>
            <h1 class="site-print-title">รายงานการใช้จ่ายแยกตามไซต์</h1>
            <p class="site-print-meta">
                <span>ช่วงข้อมูล: <strong><?= h($periodLabelLong) ?></strong> (<?= h($periodText) ?>)</span>
                <span class="site-print-meta-dot" aria-hidden="true">·</span>
                <span><?= count($sites) ?> ไซต์</span>
                <?php if ($hasCatFilter): ?>
                    <span class="site-print-meta-dot" aria-hidden="true">·</span>
                    <span>หมวด: <strong><?= h(implode(', ', $filterCatLabels)) ?></strong></span>
                <?php endif; ?>
                <span class="site-print-meta-dot" aria-hidden="true">·</span>
                <span>พิมพ์ <?= h($printedAt) ?></span>
            </p>
        </header>

        <?php if ($sites === []): ?>
            <p class="site-print-empty">ไม่พบข้อมูล PO ที่ชำระครบและมีใบกำกับ ตามเงื่อนไขที่เลือก</p>
        <?php else: ?>
            <div class="site-print-body">
                <?php $printSiteIdx = 0; ?>
                <?php foreach ($sites as $s): ?>
                    <?php
                    $printCats = [];
                    foreach ($s['categories'] as $cv) {
                        if (round((float) ($cv['paid_total'] ?? 0), 2) > 0.0) {
                            $printCats[] = $cv;
                        }
                    }
                    if ($printCats === []) {
                        continue;
                    }
                    $printSiteIdx++;
                    ?>
                    <section class="site-print-block">
                        <div class="site-print-block-head">
                            <span class="site-print-block-num"><?= sprintf('%02d', $printSiteIdx) ?></span>
                            <h2 class="site-print-block-title"><?= h($s['label']) ?></h2>
                            <span class="site-print-block-total num"><?= number_format((float) ($s['paid_total'] ?? 0), 2) ?></span>
                        </div>
                        <table class="site-print-block-table">
                            <colgroup>
                                <col class="col-cat">
                                <col class="col-amt">
                            </colgroup>
                            <thead>
                            <tr>
                                <th scope="col">หมวดค่าใช้จ่าย</th>
                                <th scope="col" class="col-amt">ยอดรายการ (บาท)</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($printCats as $c): ?>
                                <tr>
                                    <td><?= h($c['label']) ?></td>
                                    <td class="col-amt num"><?= number_format((float) ($c['paid_total'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                <?php endforeach; ?>
                <div class="site-print-grand">
                    <span>รวมทั้งหมด (<?= $printSiteIdx ?> ไซต์)</span>
                    <span class="num"><?= number_format($grandPaid, 2) ?> บาท</span>
                </div>
            </div>
            <p class="site-print-footnote">
                หมายเหตุ: รวมเฉพาะ PO ที่ชำระแล้ว มีเลขที่ใบกำกับ และวันที่ออก PO อยู่ในช่วงที่เลือก
            </p>
        <?php endif; ?>
    </div>

    <div class="card card-soft mb-3 no-print">
        <div class="card-body">
            <form method="get" class="report-filter-form">
                <div class="report-toolbar">
                    <div class="row g-2 align-items-end report-toolbar__main">
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label small fw-semibold mb-1">เดือน</label>
                            <select name="month" class="form-select">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= sprintf('%02d', $m) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label small fw-semibold mb-1">ปี</label>
                            <input type="number" name="year" min="2000" max="2100" class="form-control" value="<?= $year ?>">
                        </div>
                        <div class="col-12 col-md-6 col-lg">
                            <label class="form-label small fw-semibold mb-1">หมวดค่าใช้จ่าย</label>
                            <?php if ($filterCategoryOptions === []): ?>
                                <div class="form-control bg-light text-muted">ไม่มีหมวดในช่วงนี้</div>
                            <?php else: ?>
                                <div class="dropdown cat-filter-dropdown w-100">
                                    <button type="button" class="btn dropdown-toggle cat-filter-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" id="catFilterToggle">
                                        <span class="cat-filter-toggle-text" id="catFilterLabel"><?= h($catFilterButtonText) ?></span>
                                    </button>
                                    <div class="dropdown-menu cat-filter-menu w-100">
                                        <div class="cat-filter-menu-inner">
                                            <?php foreach ($filterCategoryOptions as $ck => $opt): ?>
                                                <label class="dropdown-item cat-filter-option">
                                                    <input type="checkbox" class="cat-filter-check" name="cat[]" value="<?= h($ck) ?>" data-label="<?= h($opt['label']) ?>" <?= in_array($ck, $filterCatKeys, true) ? 'checked' : '' ?>>
                                                    <span><?= h($opt['label']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="cat-filter-menu-foot border-top px-3 py-2">
                                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="catFilterClearBtn">ล้างการเลือก</button>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="report-toolbar__tools">
                        <button type="submit" class="btn btn-orange report-toolbar__submit">
                            <i class="bi bi-search me-1"></i>ค้นหา
                        </button>
                        <button type="button" id="btnPrintReport" class="btn btn-outline-secondary" onclick="tncSiteSpendingPrint(event)">
                            <i class="bi bi-printer me-1"></i>พิมพ์
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card card-soft mb-3 no-print" id="summaryCard">
        <div class="card-body">
            <div class="table-responsive site-spend-table-wrap tnc-mobile-table-wrap">
                <table id="spendTable" class="table table-hover align-middle mb-0 tnc-mobile-table w-100" style="width:100%">
                    <colgroup>
                        <col class="spend-col-label">
                        <col class="spend-col-amt">
                    </colgroup>
                    <thead>
                    <tr>
                        <th scope="col">ไซต์ / สถานที่</th>
                        <th scope="col" class="col-amt">ยอดจ่ายแล้ว (บาท)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($sites === []): ?>
                        <tr><td colspan="2" class="text-center text-muted py-4"><?= $hasCatFilter ? 'ไม่พบไซต์ที่มีหมวดที่เลือกในช่วงนี้' : 'ไม่พบ PO ที่ชำระครบและมีใบกำกับในช่วงนี้' ?></td></tr>
                    <?php else: ?>
                        <?php $siteIdx = 0; ?>
                        <?php foreach ($sites as $s): ?>
                            <?php
                            $siteIdx++;
                            $realCats = [];
                            foreach ($s['categories'] as $cv) {
                                if (round((float) ($cv['paid_total'] ?? 0), 2) > 0.0) {
                                    $realCats[] = $cv;
                                }
                            }
                            $hasCats = count($realCats) > 0;
                            $expandCats = $hasCatFilter && $hasCats;
                            ?>
                            <tr class="site-row">
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($hasCats): ?>
                                            <button type="button" class="btn btn-cat-toggle" data-bs-toggle="collapse" data-bs-target="#cat-<?= $siteIdx ?>" aria-expanded="<?= $expandCats ? 'true' : 'false' ?>" aria-label="ดูหมวดย่อยของ <?= h($s['label']) ?>">
                                                <i class="bi bi-caret-right-fill"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="cat-toggle-spacer"></span>
                                        <?php endif; ?>
                                        <div>
                                            <span class="site-name"><?= h($s['label']) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="col-amt num fw-bold"><?= number_format($s['paid_total'], 2) ?></td>
                            </tr>
                            <?php if ($hasCats): ?>
                            <tr class="cat-detail-row">
                                <td colspan="2" class="p-0 border-0">
                                    <div class="collapse<?= $expandCats ? ' show' : '' ?>" id="cat-<?= $siteIdx ?>">
                                        <table class="table table-sm mb-0 cat-breakdown">
                                            <colgroup>
                                                <col class="spend-col-label">
                                                <col class="spend-col-amt">
                                            </colgroup>
                                            <tbody>
                                            <?php foreach ($realCats as $c): ?>
                                                <tr>
                                                    <td class="cat-name"><i class="bi bi-tag-fill me-1"></i><?= h($c['label']) ?></td>
                                                    <td class="col-amt num"><?= number_format($c['paid_total'], 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($sites !== []): ?>
                    <tfoot>
                        <tr class="grand-row">
                            <td>รวมทั้งหมด (<?= number_format(count($sites)) ?> ไซต์)</td>
                            <td class="col-amt num"><?= number_format($grandPaid, 2) ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function tncSiteSpendingPrint(e) {
    if (e && typeof e.preventDefault === 'function') {
        e.preventDefault();
    }
    window.print();
}

(function () {
    <?php if ($autoPrint): ?>
    window.addEventListener('load', function () { setTimeout(function () { tncSiteSpendingPrint(); }, 350); });
    <?php endif; ?>

    var labelEl = document.getElementById('catFilterLabel');
    var checks = document.querySelectorAll('.cat-filter-check');
    var clearBtn = document.getElementById('catFilterClearBtn');
    if (!labelEl || checks.length === 0) {
        return;
    }
    function updateCatFilterLabel() {
        var selected = [];
        checks.forEach(function (cb) {
            if (cb.checked) {
                selected.push(cb.getAttribute('data-label') || cb.value);
            }
        });
        if (selected.length === 0) {
            labelEl.textContent = 'เลือกหมวดค่าใช้จ่าย';
        } else if (selected.length <= 2) {
            labelEl.textContent = selected.join(', ');
        } else {
            labelEl.textContent = selected.length + ' หมวดที่เลือก';
        }
    }
    checks.forEach(function (cb) {
        cb.addEventListener('change', updateCatFilterLabel);
    });
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            checks.forEach(function (cb) { cb.checked = false; });
            updateCatFilterLabel();
        });
    }
})();
</script>
</body>
</html>
