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

// ---------- พิมพ์อัตโนมัติ ----------
$autoPrint = ($_GET['print'] ?? '') === '1';
?>
<!doctype html>
<html lang="th">
<head>
    <?php
    require_once dirname(__DIR__, 2) . '/includes/tnc_ops_head.php';
    tnc_ops_head([
        'title' => 'รายงานการใช้จ่ายตามไซต์ (Site Spending)',
        'site_spending' => true,
        'include_ops_ui' => false,
        'sarabun_weights' => '400;600;700;800',
    ]);
    ?>
</head>
<body class="tnc-app-body tnc-layout-list site-spending-print-page">
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
            <div class="site-print-header__main">
                <h1 class="site-print-title">รายงานการใช้จ่ายแยกตามไซต์</h1>
            </div>
            <div class="site-print-period">
                <span class="site-print-period__label">ช่วงข้อมูล</span>
                <span class="site-print-period__main"><?= h($periodLabelLong) ?></span>
                <span class="site-print-period__sub"><?= h($periodText) ?></span>
                <?php if ($hasCatFilter): ?>
                    <span class="site-print-filter">หมวด: <strong><?= h(implode(', ', $filterCatLabels)) ?></strong></span>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($sites === []): ?>
            <p class="site-print-empty">ไม่พบข้อมูล PO ที่ชำระครบและมีใบกำกับ ตามเงื่อนไขที่เลือก</p>
        <?php else: ?>
            <div class="site-print-table-wrap">
                <table class="site-print-table">
                    <colgroup>
                        <col class="col-label">
                        <col class="col-amt">
                    </colgroup>
                    <thead>
                    <tr class="site-print-thead-gap" aria-hidden="true">
                        <td colspan="2"></td>
                    </tr>
                    <tr>
                        <th scope="col" class="col-label">ไซต์ / หมวดค่าใช้จ่าย</th>
                        <th scope="col" class="col-amt">ยอดจ่ายแล้ว (บาท)</th>
                    </tr>
                    </thead>
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
                        <tbody class="site-print-group">
                        <tr class="site-print-row">
                            <td class="col-label"><?= h($s['label']) ?></td>
                            <td class="col-amt num"><?= number_format((float) ($s['paid_total'] ?? 0), 2) ?></td>
                        </tr>
                        <?php foreach ($printCats as $c): ?>
                            <tr class="cat-print-row">
                                <td class="col-label"><?= h($c['label']) ?></td>
                                <td class="col-amt num"><?= number_format((float) ($c['paid_total'] ?? 0), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    <?php endforeach; ?>
                </table>
                <div class="site-print-grand">
                    <span class="site-print-grand__label">รวมทั้งหมด (<?= $printSiteIdx ?> ไซต์)</span>
                    <span class="site-print-grand__num num"><?= number_format($grandPaid, 2) ?></span>
                </div>
            </div>
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
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
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
