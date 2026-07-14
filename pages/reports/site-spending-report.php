<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_print/vat_print_summary.php';
require_once dirname(__DIR__, 2) . '/includes/site_cost_categories.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** คีย์หมวดหลักสำหรับ pivot — รวมชื่อซ้ำข้ามไซต์เป็นคอลัมน์เดียว */
function tnc_site_spending_parent_cat_key(string $label): string
{
    $label = trim($label);
    if ($label === '') {
        $label = 'ไม่ระบุหมวด';
    }

    return 'name:' . mb_strtolower($label, 'UTF-8');
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
            $subs = [];
            foreach (($cv['subs'] ?? []) as $sk => $sv) {
                $subPaid = round((float) ($sv['paid_total'] ?? 0), 2);
                if ($subPaid <= 0.0) {
                    continue;
                }
                $sv['paid_total'] = $subPaid;
                $sv['label'] = (string) ($sv['label'] ?? $sk);
                $subs[$sk] = $sv;
            }
            if ($subs !== []) {
                uasort($subs, static function (array $a, array $b): int {
                    return ($b['paid_total'] <=> $a['paid_total'])
                        ?: strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
                });
            }
            $cv['subs'] = $subs;
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
            if (!isset($allowed[$ck])) {
                continue;
            }
            $paid = round((float) ($cv['paid_total'] ?? 0), 2);
            if ($paid <= 0.0) {
                continue;
            }
            $cv['paid_total'] = $paid;
            $subs = [];
            foreach (($cv['subs'] ?? []) as $sk => $sv) {
                $subPaid = round((float) ($sv['paid_total'] ?? 0), 2);
                if ($subPaid <= 0.0) {
                    continue;
                }
                $sv['paid_total'] = $subPaid;
                $subs[$sk] = $sv;
            }
            if ($subs !== []) {
                uasort($subs, static function (array $a, array $b): int {
                    return ($b['paid_total'] <=> $a['paid_total'])
                        ?: strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
                });
            }
            $cv['subs'] = $subs;
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
            'categories' => [], // [catKey => [label, pr_total, po_total, paid_total, subs]]
        ];
    }
};

/** คืนค่า [parentKey, parentLabel, subKey, subLabel] */
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

    $parentLabel = tnc_site_category_document_parent_name($cid, $cname);
    if ($parentLabel === '') {
        $parentLabel = 'ไม่ระบุหมวด';
    }
    $parentKey = tnc_site_spending_parent_cat_key($parentLabel);

    $subLabel = tnc_site_category_document_name($cid, $cname);
    if ($subLabel === '') {
        $subLabel = $parentLabel;
    }
    $subKey = tnc_site_spending_parent_cat_key($parentLabel . ' › ' . $subLabel);

    return [$parentKey, $parentLabel, $subKey, $subLabel];
};

$ensureCat = static function (array &$site, string $catKey, string $catLabel): void {
    if (!isset($site['categories'][$catKey])) {
        $site['categories'][$catKey] = [
            'label' => $catLabel,
            'pr_total' => 0.0,
            'po_total' => 0.0,
            'paid_total' => 0.0,
            'subs' => [],
        ];
    }
};

$ensureSub = static function (array &$site, string $catKey, string $subKey, string $subLabel): void {
    if (!isset($site['categories'][$catKey]['subs'][$subKey])) {
        $site['categories'][$catKey]['subs'][$subKey] = [
            'label' => $subLabel,
            'paid_total' => 0.0,
            'po_total' => 0.0,
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

    [$catKey, $catLabel, $subKey, $subLabel] = $resolveCategory($po);
    $ensureCat($sites[$key], $catKey, $catLabel);
    $ensureSub($sites[$key], $catKey, $subKey, $subLabel);
    $sites[$key]['categories'][$catKey]['po_total'] += $orderTotal;
    $sites[$key]['categories'][$catKey]['paid_total'] += $paidAmount;
    $sites[$key]['categories'][$catKey]['subs'][$subKey]['po_total'] += $orderTotal;
    $sites[$key]['categories'][$catKey]['subs'][$subKey]['paid_total'] += $paidAmount;
}

// outstanding + เรียงตามยอดจ่ายแล้วมากสุด
foreach ($sites as &$s) {
    $s['outstanding'] = round($s['po_total'] - $s['paid_total'], 2);
    if (!empty($s['categories'])) {
        foreach ($s['categories'] as &$catRef) {
            if (!empty($catRef['subs']) && is_array($catRef['subs'])) {
                uasort($catRef['subs'], static function (array $a, array $b): int {
                    return ($b['paid_total'] <=> $a['paid_total'])
                        ?: strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
                });
            }
        }
        unset($catRef);
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

// ---------- ตัวเลือกหมวดหลัก (รวมชื่อซ้ำ) + กรอง ----------
$categoryCatalog = [];
foreach ($sites as $s) {
    foreach ($s['categories'] as $ck => $cv) {
        $paid = round((float) ($cv['paid_total'] ?? 0), 2);
        if ($paid <= 0.0) {
            continue;
        }
        $label = trim((string) ($cv['label'] ?? ''));
        if ($label === '') {
            $label = 'ไม่ระบุหมวด';
        }
        // รวมด้วยชื่ออีกครั้ง (กันกรณี key ต่างแต่ชื่อเหมือน)
        $mergeKey = tnc_site_spending_parent_cat_key($label);
        if (!isset($categoryCatalog[$mergeKey])) {
            $categoryCatalog[$mergeKey] = [
                'label' => $label,
                'paid_total' => 0.0,
            ];
        }
        $categoryCatalog[$mergeKey]['paid_total'] += $paid;
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
        if ($rawKey !== '' && isset($validKeys[$rawKey])) {
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

/** @var list<array{key:string,label:string}> คอลัมน์หมวดหลักของตาราง pivot */
$pivotColumns = [];
$pivotSeen = [];
foreach ($sites as $s) {
    foreach ($s['categories'] as $ck => $cv) {
        $paid = round((float) ($cv['paid_total'] ?? 0), 2);
        if ($paid <= 0.0) {
            continue;
        }
        $label = trim((string) ($cv['label'] ?? ''));
        if ($label === '') {
            $label = 'ไม่ระบุหมวด';
        }
        $mergeKey = tnc_site_spending_parent_cat_key($label);
        if (isset($pivotSeen[$mergeKey])) {
            continue;
        }
        $pivotSeen[$mergeKey] = true;
        $pivotColumns[] = [
            'key' => $mergeKey,
            'label' => $label,
            'paid_total' => (float) ($categoryCatalog[$mergeKey]['paid_total'] ?? $paid),
        ];
    }
}
usort($pivotColumns, static function (array $a, array $b): int {
    return ($b['paid_total'] <=> $a['paid_total']) ?: strcmp($a['label'], $b['label']);
});

$catFilterButtonText = 'เลือกหมวดหลัก';
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
/** @var array<string, float> รวมต่อคอลัมน์หมวด */
$pivotColTotals = [];
foreach ($pivotColumns as $col) {
    $pivotColTotals[$col['key']] = 0.0;
}
foreach ($sites as $s) {
    $grandPrCount += $s['pr_count'];
    $grandPrTotal += $s['pr_total'];
    $grandPoCount += $s['po_count'];
    $grandPoTotal += $s['po_total'];
    $grandPaid += $s['paid_total'];
    $grandOutstanding += $s['outstanding'];
    foreach ($s['categories'] as $ck => $cv) {
        $label = trim((string) ($cv['label'] ?? ''));
        if ($label === '') {
            $label = 'ไม่ระบุหมวด';
        }
        $mergeKey = tnc_site_spending_parent_cat_key($label);
        if (!isset($pivotColTotals[$mergeKey])) {
            continue;
        }
        $pivotColTotals[$mergeKey] += round((float) ($cv['paid_total'] ?? 0), 2);
    }
}
foreach ($pivotColTotals as $k => $v) {
    $pivotColTotals[$k] = round($v, 2);
}

$pivotColCount = count($pivotColumns);
$pivotTableColspan = $pivotColCount + 2; // ไซต์ + หมวด… + รวม

/** ดึงยอดหมวดจากไซต์ตามคีย์ชื่อหมวดหลัก */
$siteCatAmount = static function (array $site, string $catKey): float {
    $sum = 0.0;
    foreach ($site['categories'] as $ck => $cv) {
        $label = trim((string) ($cv['label'] ?? ''));
        if ($label === '') {
            $label = 'ไม่ระบุหมวด';
        }
        if (tnc_site_spending_parent_cat_key($label) === $catKey) {
            $sum += round((float) ($cv['paid_total'] ?? 0), 2);
        }
    }

    return round($sum, 2);
};

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
            <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-geo-alt"></i></span>Site Spending Summary</h1>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php
            require_once dirname(__DIR__, 2) . '/includes/tnc_ui.php';
            echo tnc_ui_back_previous_button(['no_print' => true]);
            ?>
        </div>
    </div>

    <div class="site-print-sheet" id="sitePrintSheet" aria-hidden="true">
        <?php if ($sites === [] || $pivotColumns === []): ?>
            <section class="site-print-summary-page is-last">
                <header class="site-print-header">
                    <div class="site-print-header__main">
                        <h1 class="site-print-title">รายงานค่าใช้จ่าย (เฉพาะค่าวัสดุ)</h1>
                    </div>
                    <div class="site-print-period">
                        <span class="site-print-period__label">ช่วงข้อมูล</span>
                        <span class="site-print-period__main"><?= h($periodLabelLong) ?></span>
                        <span class="site-print-period__sub"><?= h($periodText) ?></span>
                    </div>
                </header>
                <p class="site-print-empty">ไม่พบข้อมูล PO ที่ชำระครบและมีใบกำกับ ตามเงื่อนไขที่เลือก</p>
            </section>
        <?php else: ?>
            <?php
            $printSiteKeys = array_keys($sites);
            $printSiteLast = $printSiteKeys !== [] ? (string) end($printSiteKeys) : '';
            ?>
            <!-- หน้า 1: ตารางสรุปตามรูป (ไซต์ × หมวดหลัก) -->
            <section class="site-print-summary-page">
                <header class="site-print-header">
                    <div class="site-print-header__main">
                        <h1 class="site-print-title">รายงานค่าใช้จ่าย (เฉพาะค่าวัสดุ)</h1>
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

                <div class="site-print-table-wrap">
                    <table class="site-print-table site-print-pivot">
                        <thead>
                        <tr class="site-print-thead-gap" aria-hidden="true">
                            <td colspan="<?= (int) $pivotTableColspan ?>"></td>
                        </tr>
                        <tr>
                            <th scope="col" class="col-site">สถานที่</th>
                            <?php foreach ($pivotColumns as $col): ?>
                                <th scope="col" class="col-cat"><?= h($col['label']) ?></th>
                            <?php endforeach; ?>
                            <th scope="col" class="col-total">รวม</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sites as $s): ?>
                            <?php $rowTotal = round((float) ($s['paid_total'] ?? 0), 2); ?>
                            <tr class="site-print-row">
                                <th scope="row" class="col-site"><?= h($s['label']) ?></th>
                                <?php foreach ($pivotColumns as $col): ?>
                                    <?php $amt = $siteCatAmount($s, $col['key']); ?>
                                    <td class="col-cat num"><?= $amt > 0 ? number_format($amt, 2) : '—' ?></td>
                                <?php endforeach; ?>
                                <td class="col-total num"><?= number_format($rowTotal, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr class="site-print-grand-row">
                            <th scope="row" class="col-site">ยอดสรุป</th>
                            <?php foreach ($pivotColumns as $col): ?>
                                <td class="col-cat num"><?= number_format((float) ($pivotColTotals[$col['key']] ?? 0), 2) ?></td>
                            <?php endforeach; ?>
                            <td class="col-total num"><?= number_format($grandPaid, 2) ?></td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <!-- หน้าถัดไป: รายละเอียดหมวดย่อย 1 ไซต์ = 1 แผ่น -->
            <?php foreach ($sites as $printSiteKey => $s): ?>
                <?php
                $sitePaidTotal = round((float) ($s['paid_total'] ?? 0), 2);
                $isLastSitePage = (string) $printSiteKey === $printSiteLast;
                ?>
                <section class="site-print-site-page<?= $isLastSitePage ? ' is-last' : '' ?>">
                    <header class="site-print-header">
                        <div class="site-print-header__main">
                            <p class="site-print-kicker">รายละเอียดการใช้จ่าย · หมวดย่อย</p>
                            <h1 class="site-print-title"><?= h((string) ($s['label'] ?? 'ไม่ระบุไซต์')) ?></h1>
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

                    <div class="site-print-table-wrap">
                        <table class="site-print-table site-print-site-detail">
                            <thead>
                            <tr>
                                <th scope="col" class="col-cat">หมวดหลัก / หมวดย่อย</th>
                                <th scope="col" class="col-amt">ยอดจ่ายแล้ว</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($s['categories'])): ?>
                                <tr>
                                    <td colspan="2" class="site-print-empty-cell">ไม่มีหมวดในช่วงนี้</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($s['categories'] as $cat): ?>
                                    <?php
                                    $parentPaid = round((float) ($cat['paid_total'] ?? 0), 2);
                                    $subs = is_array($cat['subs'] ?? null) ? $cat['subs'] : [];
                                    $showSubs = false;
                                    if ($subs !== []) {
                                        $onlySub = count($subs) === 1 ? reset($subs) : null;
                                        $onlySubLabel = is_array($onlySub) ? trim((string) ($onlySub['label'] ?? '')) : '';
                                        $parentLabel = trim((string) ($cat['label'] ?? ''));
                                        $showSubs = count($subs) > 1
                                            || ($onlySubLabel !== '' && $onlySubLabel !== $parentLabel);
                                    }
                                    ?>
                                    <tr class="site-print-parent-row">
                                        <th scope="row" class="col-cat"><?= h((string) ($cat['label'] ?? '')) ?></th>
                                        <td class="col-amt num"><?= number_format($parentPaid, 2) ?></td>
                                    </tr>
                                    <?php if ($showSubs): ?>
                                        <?php foreach ($subs as $sub): ?>
                                            <tr class="site-print-sub-row">
                                                <td class="col-cat col-cat--sub"><?= h((string) ($sub['label'] ?? '')) ?></td>
                                                <td class="col-amt num"><?= number_format(round((float) ($sub['paid_total'] ?? 0), 2), 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                            <tfoot>
                            <tr class="site-print-grand-row">
                                <th scope="row" class="col-cat">รวมทั้งไซต์</th>
                                <td class="col-amt num"><?= number_format($sitePaidTotal, 2) ?></td>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>
            <?php endforeach; ?>
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
                            <label class="form-label small fw-semibold mb-1">หมวดหลัก</label>
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
            <div class="table-responsive site-spend-table-wrap">
                <table id="spendTable" class="table table-hover align-middle mb-0 spend-pivot-table w-100">
                    <thead>
                    <tr>
                        <th scope="col" class="col-site">สถานที่</th>
                        <?php foreach ($pivotColumns as $col): ?>
                            <th scope="col" class="col-cat text-end"><?= h($col['label']) ?></th>
                        <?php endforeach; ?>
                        <th scope="col" class="col-total text-end">รวม</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($sites === [] || $pivotColumns === []): ?>
                        <tr>
                            <td colspan="<?= max(2, (int) $pivotTableColspan) ?>" class="text-center text-muted py-4">
                                <?= $hasCatFilter ? 'ไม่พบไซต์ที่มีหมวดที่เลือกในช่วงนี้' : 'ไม่พบ PO ที่ชำระครบและมีใบกำกับในช่วงนี้' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sites as $s): ?>
                            <tr class="site-row">
                                <th scope="row" class="col-site">
                                    <span class="site-name"><?= h($s['label']) ?></span>
                                </th>
                                <?php foreach ($pivotColumns as $col): ?>
                                    <?php $amt = $siteCatAmount($s, $col['key']); ?>
                                    <td class="col-cat text-end num<?= $amt <= 0 ? ' is-empty' : '' ?>">
                                        <?= $amt > 0 ? number_format($amt, 2) : '—' ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="col-total text-end num fw-bold"><?= number_format((float) $s['paid_total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($sites !== [] && $pivotColumns !== []): ?>
                    <tfoot>
                        <tr class="grand-row">
                            <th scope="row" class="col-site">รวมทั้งหมด (<?= number_format(count($sites)) ?> ไซต์)</th>
                            <?php foreach ($pivotColumns as $col): ?>
                                <td class="col-cat text-end num"><?= number_format((float) ($pivotColTotals[$col['key']] ?? 0), 2) ?></td>
                            <?php endforeach; ?>
                            <td class="col-total text-end num"><?= number_format($grandPaid, 2) ?></td>
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
            labelEl.textContent = 'เลือกหมวดหลัก';
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
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
