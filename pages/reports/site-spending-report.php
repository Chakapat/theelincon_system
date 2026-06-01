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

function tnc_site_csv_cell(string $value): string
{
    $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
    if (preg_match('/[",;]/', $value) === 1) {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    return $value;
}

/** @param list<string|float|int> $cells */
function tnc_site_csv_row(array $cells): string
{
    $out = [];
    foreach ($cells as $cell) {
        $out[] = tnc_site_csv_cell((string) $cell);
    }

    return implode(',', $out) . "\r\n";
}

// ---------- ช่วงวันที่ ----------
$startDate = trim((string) ($_GET['start_date'] ?? ''));
$endDate = trim((string) ($_GET['end_date'] ?? ''));
$useRange = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) === 1;

$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
if ($month < 1 || $month > 12) {
    $month = (int) date('n');
}
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

if ($useRange) {
    $fromDate = $startDate;
    $toDate = $endDate;
    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }
    $periodText = $fromDate . ' ถึง ' . $toDate;
} else {
    $fromDate = sprintf('%04d-%02d-01', $year, $month);
    $toDate = date('Y-m-t', strtotime($fromDate));
    $periodText = 'เดือน ' . sprintf('%02d/%04d', $month, $year);
}

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

/** คืนค่า [catKey, catLabel] จากเอกสาร (cost_category) — ไม่มี = "ไม่ระบุหมวด" */
$resolveCategory = static function (array $row): array {
    $cid = (int) ($row['cost_category_id'] ?? 0);
    $cname = trim((string) ($row['cost_category_name'] ?? ''));
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

// PR (ใบขอซื้อ) — ยอดที่ขอซื้อ
foreach ($prById as $pr) {
    $docDate = tnc_site_doc_date($pr, ['issue_date', 'pr_date', 'request_date', 'created_at']);
    if ($docDate === '' || $docDate < $fromDate || $docDate > $toDate) {
        continue;
    }
    [$key, $label] = $resolveSite($pr);
    $ensureSite($key, $label);
    $sites[$key]['pr_count']++;
    $prAmount = (float) ($pr['total_amount'] ?? 0);
    $sites[$key]['pr_total'] += $prAmount;
    [$catKey, $catLabel] = $resolveCategory($pr);
    $ensureCat($sites[$key], $catKey, $catLabel);
    $sites[$key]['categories'][$catKey]['pr_total'] += $prAmount;
}

// PO (ใบสั่งซื้อ) — ยอดสั่งซื้อ + ยอดที่จ่ายแล้ว (ไม่รวมใบยกเลิก)
foreach (Db::tableRows('purchase_orders') as $po) {
    $status = strtolower(trim((string) ($po['status'] ?? 'ordered')));
    if ($status === 'cancelled') {
        continue;
    }
    $docDate = tnc_site_doc_date($po, ['issue_date', 'created_at']);
    if ($docDate === '' || $docDate < $fromDate || $docDate > $toDate) {
        continue;
    }
    [$key, $label] = $resolveSite($po);
    $ensureSite($key, $label);

    $orderTotal = (float) ($po['total_amount'] ?? 0);
    $sites[$key]['po_count']++;
    $sites[$key]['po_total'] += $orderTotal;

    [$catKey, $catLabel] = $resolveCategory($po);
    $ensureCat($sites[$key], $catKey, $catLabel);
    $sites[$key]['categories'][$catKey]['po_total'] += $orderTotal;

    $paymentStatus = strtolower(trim((string) ($po['payment_status'] ?? 'unpaid')));
    if ($paymentStatus === 'paid') {
        $billed = (float) ($po['billed_total_amount'] ?? 0);
        $paidAmount = $billed > 0 ? $billed : $orderTotal;
        $sites[$key]['paid_total'] += $paidAmount;
        $sites[$key]['categories'][$catKey]['paid_total'] += $paidAmount;
    }
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

// ---------- ตารางไขว้ (Matrix): ไซต์ (แถว) × หมวด (คอลัมน์) ----------
$matrixMetric = (string) ($_GET['mx'] ?? 'po');
if (!in_array($matrixMetric, ['po', 'paid'], true)) {
    $matrixMetric = 'po';
}
$metricField = ['po' => 'po_total', 'paid' => 'paid_total'][$matrixMetric];
$metricLabel = ['po' => 'ยอดสั่งซื้อ (PO)', 'paid' => 'จ่ายแล้ว'][$matrixMetric];

// คอลัมน์ = สหภาพของหมวดจริงทุกไซต์ (ตัด "ไม่ระบุหมวด")
$matrixCols = []; // catKey => ['label'=>, 'total'=>]
foreach ($sites as $s) {
    foreach ($s['categories'] as $ck => $cv) {
        if ($ck === 'none') {
            continue;
        }
        if (!isset($matrixCols[$ck])) {
            $matrixCols[$ck] = ['label' => (string) $cv['label'], 'total' => 0.0];
        }
        $matrixCols[$ck]['total'] += (float) ($cv[$metricField] ?? 0);
    }
}
uasort($matrixCols, static function (array $a, array $b): int {
    return ($b['total'] <=> $a['total']) ?: strcmp($a['label'], $b['label']);
});

// แถว = ไซต์ที่มีหมวดจริงอย่างน้อยหนึ่งหมวด (รวมยอด > 0)
$matrixRows = [];
foreach ($sites as $s) {
    $cells = [];
    $rowTotal = 0.0;
    foreach ($matrixCols as $ck => $col) {
        $val = (float) ($s['categories'][$ck][$metricField] ?? 0);
        $cells[$ck] = $val;
        $rowTotal += $val;
    }
    if ($rowTotal != 0.0) {
        $matrixRows[] = ['label' => $s['label'], 'cells' => $cells, 'total' => $rowTotal];
    }
}
usort($matrixRows, static function (array $a, array $b): int {
    return ($b['total'] <=> $a['total']) ?: strcmp($a['label'], $b['label']);
});
$matrixColTotals = [];
$matrixGrand = 0.0;
foreach ($matrixCols as $ck => $col) {
    $sum = 0.0;
    foreach ($matrixRows as $r) {
        $sum += (float) ($r['cells'][$ck] ?? 0);
    }
    $matrixColTotals[$ck] = $sum;
    $matrixGrand += $sum;
}

// ---------- Export CSV ----------
if (($_GET['export'] ?? '') === 'csv') {
    $filename = 'site-spending-' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    echo tnc_site_csv_row(['รายงานสรุปการใช้จ่ายแยกตามไซต์']);
    echo tnc_site_csv_row(['ช่วงข้อมูล', $periodText]);
    echo tnc_site_csv_row([]);
    echo tnc_site_csv_row(['ไซต์/สถานที่', 'หมวดค่าใช้จ่าย', 'ยอดสั่งซื้อ (PO)', 'จ่ายแล้ว', 'ค้างจ่าย']);
    foreach ($sites as $s) {
        echo tnc_site_csv_row([
            $s['label'],
            '(รวมทั้งไซต์)',
            number_format($s['po_total'], 2, '.', ''),
            number_format($s['paid_total'], 2, '.', ''),
            number_format($s['outstanding'], 2, '.', ''),
        ]);
        foreach ($s['categories'] as $ck => $c) {
            if ($ck === 'none') {
                continue; // ไม่แสดง "ไม่ระบุหมวด"
            }
            echo tnc_site_csv_row([
                '',
                '— ' . $c['label'],
                number_format($c['po_total'], 2, '.', ''),
                number_format($c['paid_total'], 2, '.', ''),
                number_format(round($c['po_total'] - $c['paid_total'], 2), 2, '.', ''),
            ]);
        }
    }
    echo tnc_site_csv_row([
        'รวมทั้งหมด',
        '',
        number_format($grandPrTotal, 2, '.', ''),
        $grandPoCount,
        number_format($grandPoTotal, 2, '.', ''),
        number_format($grandPaid, 2, '.', ''),
        number_format($grandOutstanding, 2, '.', ''),
    ]);
    exit;
}

// ---------- Export CSV: ตารางไขว้ (Matrix) ----------
if (($_GET['export'] ?? '') === 'matrix') {
    $filename = 'site-spending-matrix-' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    echo tnc_site_csv_row(['ตารางค่าใช้จ่าย: ไซต์ × หมวด (' . $metricLabel . ')']);
    echo tnc_site_csv_row(['ช่วงข้อมูล', $periodText]);
    echo tnc_site_csv_row([]);
    $header = ['ไซต์/สถานที่'];
    foreach ($matrixCols as $col) {
        $header[] = $col['label'];
    }
    $header[] = 'ยอดรวม';
    echo tnc_site_csv_row($header);
    foreach ($matrixRows as $r) {
        $line = [$r['label']];
        foreach ($matrixCols as $ck => $col) {
            $line[] = number_format((float) ($r['cells'][$ck] ?? 0), 2, '.', '');
        }
        $line[] = number_format($r['total'], 2, '.', '');
        echo tnc_site_csv_row($line);
    }
    $footer = ['รวมทั้งหมด'];
    foreach ($matrixCols as $ck => $col) {
        $footer[] = number_format((float) ($matrixColTotals[$ck] ?? 0), 2, '.', '');
    }
    $footer[] = number_format($matrixGrand, 2, '.', '');
    echo tnc_site_csv_row($footer);
    exit;
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
        .report-stat {
            background: #fff;
            border: 1px solid var(--tnc-orange-border, #fdba74);
            border-radius: 0.875rem;
            padding: 0.95rem 1.1rem;
            height: 100%;
        }
        .report-stat__label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--tnc-muted, #64748b);
            margin-bottom: 0.25rem;
        }
        .report-stat__value {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--tnc-ink, #0f172a);
            font-variant-numeric: tabular-nums;
            line-height: 1.2;
        }
        .report-stat--success .report-stat__value { color: #1e7e34; }
        .report-stat--accent .report-stat__value { color: var(--tnc-orange, #ea580c); }
        .report-stat--warn .report-stat__value { color: #b45309; }
        .report-note { font-size: 0.8125rem; color: var(--tnc-muted, #64748b); }
        /* ---- ตารางสรุป ---- */
        #spendTable { border-collapse: separate; border-spacing: 0; }
        #spendTable thead th {
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
            color: #334155;
            font-weight: 700;
            font-size: 0.8125rem;
            border-bottom: 2px solid var(--tnc-orange-border, #fdba74);
            padding: 0.7rem 0.9rem;
            white-space: nowrap;
        }
        #spendTable td { padding: 0.65rem 0.9rem; vertical-align: middle; }
        #spendTable .num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .site-row { border-top: 1px solid #eef2f7; }
        .site-row:hover { background: var(--tnc-orange-soft, #ffedd5); }
        .site-row .site-sub { font-size: 0.75rem; line-height: 1.1; margin-top: 1px; }
        .btn-cat-toggle {
            min-width: 44px; min-height: 44px; width: 44px; height: 44px; flex: 0 0 44px;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 0.5rem; border: 1px solid var(--tnc-border-soft, #e2e8f0);
            background: #fff; color: var(--tnc-muted, #64748b);
        }
        .btn-cat-toggle:hover { background: var(--tnc-orange-soft, #ffedd5); color: var(--tnc-orange-dark, #9a3412); }
        .cat-toggle-spacer { display: inline-block; width: 44px; flex: 0 0 44px; }
        .btn-cat-toggle .bi-caret-right-fill { transition: transform 0.15s ease; font-size: 0.7rem; }
        [aria-expanded="true"] .bi-caret-right-fill { transform: rotate(90deg); }
        .cat-breakdown { background: #fcfdfe; margin: 0; }
        .cat-breakdown td { border: 0; border-bottom: 1px dashed #eef2f7; font-size: 0.8125rem; padding: 0.5rem 0.9rem; }
        .cat-breakdown tr:last-child td { border-bottom: 0; }
        .cat-breakdown .cat-name { color: #475569; padding-left: 3.75rem; }
        .cat-breakdown .cat-name .bi { color: var(--tnc-orange, #ea580c); font-size: 0.75rem; }
        .cat-detail-row > td { background: rgba(255, 237, 213, 0.35); }
        .grand-row td { background: #f8fafc; border-top: 2px solid var(--tnc-orange-border, #fdba74); }
        /* ---- ตารางไขว้ (Matrix) ---- */
        .matrix-wrap { overflow-x: auto; }
        #matrixTable { border-collapse: separate; border-spacing: 0; min-width: 640px; }
        #matrixTable thead th {
            background: linear-gradient(180deg, #fff7ed 0%, #ffedd5 100%);
            color: var(--tnc-orange-dark, #9a3412);
            font-weight: 700;
            font-size: 0.8125rem;
            padding: 0.6rem 0.8rem;
            white-space: nowrap;
            position: sticky; top: 0;
            border-bottom: 2px solid var(--tnc-orange-border, #fdba74);
        }
        #matrixTable th, #matrixTable td { border-bottom: 1px solid #eef2f7; padding: 0.55rem 0.8rem; }
        #matrixTable tbody tr:nth-child(even) td { background: #f8fafc; }
        #matrixTable tbody tr:hover td { background: var(--tnc-orange-soft, #ffedd5); }
        #matrixTable .num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        #matrixTable .mx-site-col {
            position: sticky; left: 0; z-index: 2;
            background: #fff; min-width: 200px; box-shadow: 1px 0 0 #e5e7eb;
        }
        #matrixTable thead .mx-site-col {
            background: linear-gradient(180deg, #fff7ed 0%, #ffedd5 100%);
            z-index: 3;
        }
        #matrixTable tbody tr:nth-child(even) .mx-site-col { background: #f8fafc; }
        #matrixTable tbody tr:hover .mx-site-col { background: var(--tnc-orange-soft, #ffedd5); }
        #matrixTable .mx-total-col { background: #fff7ed; font-weight: 700; }
        #matrixTable .grand-row td { background: #eef2f7 !important; border-top: 2px solid var(--tnc-orange-border, #fdba74); }
        #matrixTable .grand-row .mx-site-col { background: #eef2f7 !important; }
        @media (min-width: 1200px) { .container { max-width: 1140px; } }
        @media (max-width: 575.98px) {
            .card-soft .card-body { padding: 1rem; }
            .report-actions .btn { width: 100%; justify-content: center; }
        }
        @media (prefers-reduced-motion: reduce) {
            .btn-cat-toggle .bi-caret-right-fill { transition: none; }
        }
        .print-header { text-align: center; margin-bottom: 14px; }
        .print-header .print-company { font-size: 1.15rem; font-weight: 700; }
        .print-header .print-title { font-size: 1rem; font-weight: 600; margin-top: 2px; }
        .print-header .print-meta { font-size: .82rem; color: #444; margin-top: 4px; display: flex; gap: 18px; justify-content: center; flex-wrap: wrap; }

        /* ---- รายงานพิมพ์: จัดกลุ่มตามไซต์ ---- */
        .print-only {
            position: absolute;
            left: -9999px;
            top: 0;
            width: 210mm;
            visibility: hidden;
            pointer-events: none;
        }
        body.tnc-print-mode .print-only {
            position: static;
            left: auto;
            width: auto;
            visibility: visible;
            pointer-events: auto;
        }
        .print-site-report {
            font-size: 0.92rem;
            color: #0f172a;
        }
        .print-site-block {
            margin-bottom: 1.15rem;
            break-inside: avoid-page;
            page-break-inside: avoid;
        }
        .print-site-block + .print-site-block {
            padding-top: 0.35rem;
        }
        .print-site-head {
            display: flex;
            align-items: baseline;
            flex-wrap: wrap;
            gap: 0.35rem 0.65rem;
            margin-bottom: 0.45rem;
            padding: 0.45rem 0.65rem;
            background: linear-gradient(90deg, #fff7ed 0%, #fff 72%);
            border-left: 4px solid var(--tnc-orange, #ea580c);
            border-radius: 0 0.35rem 0.35rem 0;
            break-after: avoid;
            page-break-after: avoid;
        }
        .print-site-num {
            flex: 0 0 auto;
            min-width: 1.65rem;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            color: var(--tnc-orange-dark, #9a3412);
            line-height: 1.2;
        }
        .print-site-title {
            flex: 1 1 auto;
            margin: 0;
            font-size: 0.98rem;
            font-weight: 800;
            line-height: 1.25;
            color: #0f172a;
        }
        .print-site-meta {
            flex: 0 0 auto;
            font-size: 0.72rem;
            font-weight: 600;
            color: #64748b;
            white-space: nowrap;
        }
        .print-site-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .print-site-table thead th {
            font-size: 0.72rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 0.35rem 0.5rem;
            border-bottom: 1px solid #cbd5e1;
            background: #f8fafc;
        }
        .print-site-table thead th:first-child { text-align: left; width: 42%; }
        .print-site-table thead th:nth-child(n+2) { text-align: right; width: 19%; }
        .print-site-table tbody td {
            padding: 0.32rem 0.5rem;
            border-bottom: 1px dotted #e2e8f0;
            vertical-align: top;
            font-size: 0.86rem;
        }
        .print-site-table tbody tr:last-child td { border-bottom: none; }
        .print-site-table .cat-label {
            color: #334155;
            padding-left: 0.85rem;
            position: relative;
        }
        .print-site-table .cat-label::before {
            content: '›';
            position: absolute;
            left: 0;
            color: var(--tnc-orange, #ea580c);
            font-weight: 700;
        }
        .print-site-table .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .print-site-table tfoot td {
            padding: 0.45rem 0.5rem;
            font-weight: 700;
            font-size: 0.88rem;
            border-top: 2px solid #94a3b8;
            background: #f1f5f9;
        }
        .print-site-table tfoot .num { text-align: right; font-variant-numeric: tabular-nums; }
        .print-site-table tfoot .num--paid { color: #166534; }
        .print-site-table tfoot .num--out { color: #92400e; }
        .print-grand-wrap {
            margin-top: 1rem;
            padding-top: 0.65rem;
            border-top: 3px double #334155;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .print-grand-table tfoot td {
            font-size: 0.95rem;
            font-weight: 800;
            border-top: none;
        }
        .print-empty {
            color: #64748b;
            font-size: 0.9rem;
            padding: 0.5rem 0;
        }

        /* legacy — ไม่ใช้แล้ว */
        .print-amt-head,
        .print-site-items,
        .print-site-total,
        .print-grand-total { display: none !important; }

        /* ---- Breakpoints: หน้าจอ ---- */
        @media (max-width: 575.98px) {
            .container.pb-5 { padding-bottom: 2rem !important; }
            .card-soft .card-body { padding: 1rem; }
            .stat-pill { padding: .7rem .85rem; }
            .stat-pill .value { font-size: 1.05rem; }
            #spendTable thead th,
            #spendTable td { font-size: .76rem; padding: .45rem .55rem; }
            .cat-breakdown .cat-name { padding-left: 1.75rem; }
            .cat-breakdown td { font-size: .78rem; }
            .btn-export-modern,
            .btn-print-modern { width: 100%; }
            #matrixCard .matrix-tools { width: 100%; }
            #matrixCard .matrix-tools .btn-group { width: 100%; }
            #matrixCard .matrix-tools .btn-group .btn { flex: 1 1 auto; }
        }

        @media (min-width: 576px) and (max-width: 767.98px) {
            #spendTable thead th,
            #spendTable td { font-size: .8rem; }
        }

        @media (max-width: 767.98px) {
            .btn-export-modern,
            .btn-print-modern { width: 100%; }
            #matrixCard .matrix-tools { width: 100%; }
            #matrixCard .matrix-tools .btn-group { width: 100%; }
            #matrixCard .matrix-tools .btn-group .btn { flex: 1 1 auto; }
        }

        @media print {
            @page { size: A4 landscape; margin: 10mm 12mm; }
            body {
                background: #fff !important;
                font-family: 'Sarabun', 'Leelawadee UI', sans-serif !important;
                font-size: 11pt;
                color: #000 !important;
            }
            .no-print,
            nav,
            .navbar,
            #summaryCard,
            #matrixCard,
            .card-soft.no-print { display: none !important; }
            .print-only {
                position: static !important;
                left: auto !important;
                width: auto !important;
                visibility: visible !important;
                pointer-events: auto !important;
            }
            .container {
                max-width: 100% !important;
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .print-header {
                margin-bottom: 12px;
                padding-bottom: 8px;
                border-bottom: 1px solid #cbd5e1;
            }
            .print-header .print-company { font-size: 14pt; }
            .print-header .print-title { font-size: 12pt; }
            .print-header .print-meta { color: #334155 !important; font-size: 9pt; }
            .print-site-block {
                margin-bottom: 10px;
                break-inside: avoid-page;
                page-break-inside: avoid;
            }
            .print-site-head {
                background: #f8fafc !important;
                border-left-color: #000 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .print-site-num { color: #000 !important; }
            .print-site-table thead th {
                background: #eee !important;
                color: #000 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .print-site-table tbody td { font-size: 10pt; }
            .print-site-table .cat-label::before { color: #000 !important; }
            .print-site-table tfoot td {
                background: #e5e7eb !important;
                border-top-color: #000 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .print-site-table tfoot .num--paid { color: #000 !important; }
            .print-site-table tfoot .num--out { color: #000 !important; }
            .print-grand-wrap {
                border-top-color: #000 !important;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .print-grand-table td.num--paid { color: #000 !important; }
        }
    </style>
</head>
<body class="tnc-app-body">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container pb-5 pt-4">
    <div class="tnc-page-head mb-3 no-print">
        <div>
            <p class="tnc-page-kicker">Reports · Site spending</p>
            <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-geo-alt"></i></span>รายงานการใช้จ่ายแยกตามไซต์</h1>
        </div>
    </div>

    <div class="print-only" id="printSiteReportWrap">
    <div class="print-header" id="printReportHeader">
        <div class="print-company"><?= h($companyName) ?></div>
        <div class="print-title">รายงานการใช้จ่ายแยกตามไซต์ (Site Spending)</div>
        <div class="print-meta">
            <span>ช่วงข้อมูล: <?= h($periodText) ?></span>
            <span>จำนวนไซต์: <?= count($sites) ?></span>
            <span>พิมพ์เมื่อ: <?= h($printedAt) ?></span>
        </div>
    </div>

    <div class="print-site-report" id="printSiteReport">
        <?php if ($sites === []): ?>
            <p class="print-empty">ไม่พบข้อมูล PR/PO ตามเงื่อนไข</p>
        <?php else: ?>
            <?php $printSiteIdx = 0; ?>
            <?php foreach ($sites as $s): ?>
                <?php
                $printCats = [];
                foreach ($s['categories'] as $ck => $cv) {
                    if ($ck === 'none') {
                        continue;
                    }
                    if ((float) ($cv['po_total'] ?? 0) != 0.0 || (float) ($cv['paid_total'] ?? 0) != 0.0) {
                        $printCats[] = $cv;
                    }
                }
                if ((float) ($s['po_total'] ?? 0) == 0.0 && (float) ($s['paid_total'] ?? 0) == 0.0 && $printCats === []) {
                    continue;
                }
                $printSiteIdx++;
                $siteOut = round((float) ($s['outstanding'] ?? 0), 2);
                ?>
                <section class="print-site-block">
                    <div class="print-site-head">
                        <span class="print-site-num"><?= sprintf('%02d', $printSiteIdx) ?></span>
                        <h2 class="print-site-title"><?= h($s['label']) ?></h2>
                        <?php if ($printCats !== []): ?>
                            <span class="print-site-meta"><?= count($printCats) ?> หมวด</span>
                        <?php endif; ?>
                    </div>
                    <table class="print-site-table">
                        <thead>
                        <tr>
                            <th>หมวดค่าใช้จ่าย</th>
                            <th>ยอด PO</th>
                            <th>จ่ายแล้ว</th>
                            <th>ค้างจ่าย</th>
                        </tr>
                        </thead>
                        <?php if ($printCats !== []): ?>
                        <tbody>
                            <?php foreach ($printCats as $c): ?>
                                <?php $cOut = round((float) ($c['po_total'] ?? 0) - (float) ($c['paid_total'] ?? 0), 2); ?>
                                <tr>
                                    <td class="cat-label"><?= h($c['label']) ?></td>
                                    <td class="num"><?= number_format((float) ($c['po_total'] ?? 0), 2) ?></td>
                                    <td class="num"><?= number_format((float) ($c['paid_total'] ?? 0), 2) ?></td>
                                    <td class="num"><?= number_format($cOut, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php endif; ?>
                        <tfoot>
                        <tr>
                            <td>รวมไซต์นี้</td>
                            <td class="num"><?= number_format((float) ($s['po_total'] ?? 0), 2) ?></td>
                            <td class="num num--paid"><?= number_format((float) ($s['paid_total'] ?? 0), 2) ?></td>
                            <td class="num num--out"><?= number_format($siteOut, 2) ?></td>
                        </tr>
                        </tfoot>
                    </table>
                </section>
            <?php endforeach; ?>
            <div class="print-grand-wrap">
                <table class="print-site-table print-grand-table">
                    <tfoot>
                    <tr>
                        <td>รวมทั้งหมด (<?= $printSiteIdx ?> ไซต์)</td>
                        <td class="num"><?= number_format($grandPoTotal, 2) ?></td>
                        <td class="num num--paid"><?= number_format($grandPaid, 2) ?></td>
                        <td class="num num--out"><?= number_format($grandOutstanding, 2) ?></td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
    </div>

    <div class="card card-soft mb-3 no-print">
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
                    <?php
                    $exportQuery = $_GET;
                    $exportQuery['export'] = 'csv';
                    ?>
                    <a href="<?= h(app_path('pages/reports/site-spending-report.php') . '?' . http_build_query($exportQuery)) ?>" class="btn btn-outline-success rounded-pill px-3 fw-semibold">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
                    </a>
                    <button type="button" id="btnPrintReport" class="btn btn-outline-secondary rounded-pill px-3 fw-semibold" onclick="tncSiteSpendingPrint(event)">
                        <i class="bi bi-printer me-1"></i>พิมพ์เอกสาร
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-2 mb-3 no-print">
        <div class="col-12 col-md-4">
            <div class="report-stat report-stat--success h-100">
                <div class="report-stat__label"><i class="bi bi-cash-coin me-1"></i>จ่ายแล้วทุกไซต์</div>
                <div class="report-stat__value"><?= number_format($grandPaid, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="report-stat report-stat--accent h-100">
                <div class="report-stat__label"><i class="bi bi-bag-check me-1"></i>ยอดสั่งซื้อ (PO)</div>
                <div class="report-stat__value"><?= number_format($grandPoTotal, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="report-stat report-stat--warn h-100">
                <div class="report-stat__label"><i class="bi bi-hourglass-split me-1"></i>ค้างจ่าย</div>
                <div class="report-stat__value"><?= number_format($grandOutstanding, 2) ?></div>
            </div>
        </div>
    </div>

    <div class="card card-soft mb-3 no-print" id="summaryCard">
        <div class="card-body">
            <div class="report-summary-row mb-3">
                <span class="report-badge">ช่วงข้อมูล: <?= h($periodText) ?></span>
                <span class="report-badge">จำนวนไซต์: <?= count($sites) ?></span>
            </div>
            <p class="report-note mb-3">จ่ายแล้ว = PO ที่ทำเครื่องหมายจ่ายเงินแล้ว (ไม่รวมใบยกเลิก) · ค้างจ่าย = ยอดสั่งซื้อ − จ่ายแล้ว</p>
            <div class="table-responsive">
                <table id="spendTable" class="table align-middle mb-0">
                    <thead>
                    <tr>
                        <th>ไซต์ / หมวดค่าใช้จ่าย</th>
                        <th class="text-end">ยอดสั่งซื้อ (PO)</th>
                        <th class="text-end">จ่ายแล้ว</th>
                        <th class="text-end">ค้างจ่าย</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($sites === []): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">ไม่พบข้อมูล PR/PO ตามเงื่อนไข</td></tr>
                    <?php else: ?>
                        <?php $siteIdx = 0; ?>
                        <?php foreach ($sites as $s): ?>
                            <?php
                            $siteIdx++;
                            // แสดงเฉพาะหมวดจริง — ตัด "ไม่ระบุหมวด" (key 'none') ออก
                            $realCats = [];
                            foreach ($s['categories'] as $ck => $cv) {
                                if ($ck !== 'none') {
                                    $realCats[] = $cv;
                                }
                            }
                            $hasCats = count($realCats) > 0;
                            ?>
                            <tr class="site-row">
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($hasCats): ?>
                                            <button type="button" class="btn btn-cat-toggle" data-bs-toggle="collapse" data-bs-target="#cat-<?= $siteIdx ?>" aria-expanded="false" aria-label="ดูหมวดย่อยของ <?= h($s['label']) ?>">
                                                <i class="bi bi-caret-right-fill"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="cat-toggle-spacer"></span>
                                        <?php endif; ?>
                                        <div>
                                            <span class="fw-semibold"><?= h($s['label']) ?></span>
                                            <?php if ($hasCats): ?><span class="badge rounded-pill text-bg-light border ms-1"><?= count($realCats) ?> หมวด</span><?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end num"><?= number_format($s['po_total'], 2) ?></td>
                                <td class="text-end num fw-bold text-success"><?= number_format($s['paid_total'], 2) ?></td>
                                <td class="text-end num <?= $s['outstanding'] > 0 ? 'text-warning-emphasis fw-semibold' : 'text-muted' ?>"><?= number_format($s['outstanding'], 2) ?></td>
                            </tr>
                            <?php if ($hasCats): ?>
                            <tr class="cat-detail-row">
                                <td colspan="4" class="p-0 border-0">
                                    <div class="collapse" id="cat-<?= $siteIdx ?>">
                                        <table class="table table-sm mb-0 cat-breakdown">
                                            <tbody>
                                            <?php foreach ($realCats as $c): ?>
                                                <?php $cOut = round($c['po_total'] - $c['paid_total'], 2); ?>
                                                <tr>
                                                    <td class="cat-name"><i class="bi bi-tag-fill me-1"></i><?= h($c['label']) ?></td>
                                                    <td class="text-end num"><?= number_format($c['po_total'], 2) ?></td>
                                                    <td class="text-end num text-success"><?= number_format($c['paid_total'], 2) ?></td>
                                                    <td class="text-end num <?= $cOut > 0 ? 'text-warning-emphasis' : 'text-muted' ?>"><?= number_format($cOut, 2) ?></td>
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
                        <tr class="grand-row fw-bold">
                            <td>รวมทั้งหมด</td>
                            <td class="text-end num"><?= number_format($grandPoTotal, 2) ?></td>
                            <td class="text-end num text-success"><?= number_format($grandPaid, 2) ?></td>
                            <td class="text-end num"><?= number_format($grandOutstanding, 2) ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <?php
    $selfUrl = app_path('pages/reports/site-spending-report.php');
    $mkUrl = static function (array $override) use ($selfUrl): string {
        return $selfUrl . '?' . http_build_query(array_merge($_GET, $override));
    };
    ?>
    <div class="card card-soft mb-3 no-print" id="matrixCard">
        <div class="card-body">
            <div class="print-header d-none">
                <div class="print-company"><?= h($companyName) ?></div>
                <div class="print-title">ตารางค่าใช้จ่าย: ไซต์ × หมวด (<?= h($metricLabel) ?>)</div>
                <div class="print-meta">
                    <span>ช่วงข้อมูล: <?= h($periodText) ?></span>
                    <span>พิมพ์เมื่อ: <?= h($printedAt) ?></span>
                </div>
            </div>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h5 class="fw-bold mb-0"><i class="bi bi-grid-3x3-gap me-2 text-tnc-orange"></i>ตารางค่าใช้จ่าย: ไซต์ × หมวด</h5>
                <div class="d-flex flex-wrap gap-2 align-items-center matrix-tools">
                    <div class="btn-group btn-group-sm" role="group" aria-label="เลือกค่าที่แสดง">
                        <?php foreach (['po' => 'ยอดสั่งซื้อ', 'paid' => 'จ่ายแล้ว'] as $mk => $mlabel): ?>
                            <a href="<?= h($mkUrl(['mx' => $mk])) ?>#matrixCard" class="btn <?= $matrixMetric === $mk ? 'btn-orange' : 'btn-outline-orange' ?>"><?= h($mlabel) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <a href="<?= h($mkUrl(['export' => 'matrix'])) ?>" class="btn btn-sm btn-export-modern"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Export</a>
                    <button type="button" id="btnPrintMatrix" class="btn btn-sm btn-print-modern" onclick="tncSiteSpendingPrint(event)"><i class="bi bi-printer me-1"></i>พิมพ์</button>
                </div>
            </div>
            <p class="text-muted small mb-2">แสดงค่า: <strong><?= h($metricLabel) ?></strong> ต่อไซต์ แยกตามหมวดค่าใช้จ่าย (ไม่รวมรายการที่ไม่ระบุหมวด)</p>
            <div class="table-responsive matrix-wrap">
                <?php if ($matrixCols === [] || $matrixRows === []): ?>
                    <div class="text-center text-muted py-4">ยังไม่มีข้อมูลค่าใช้จ่ายที่ระบุหมวดในช่วงนี้</div>
                <?php else: ?>
                <table id="matrixTable" class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="mx-site-col">ไซต์ / สถานที่</th>
                            <?php foreach ($matrixCols as $col): ?>
                                <th class="text-end"><?= h($col['label']) ?></th>
                            <?php endforeach; ?>
                            <th class="text-end mx-total-col">ยอดรวม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matrixRows as $r): ?>
                            <tr>
                                <td class="mx-site-col fw-semibold"><?= h($r['label']) ?></td>
                                <?php foreach ($matrixCols as $ck => $col): ?>
                                    <?php $val = (float) ($r['cells'][$ck] ?? 0); ?>
                                    <td class="text-end num <?= $val == 0.0 ? 'text-muted' : '' ?>"><?= $val == 0.0 ? '–' : number_format($val, 2) ?></td>
                                <?php endforeach; ?>
                                <td class="text-end num fw-bold mx-total-col"><?= number_format($r['total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="grand-row fw-bold">
                            <td class="mx-site-col">รวมทั้งหมด</td>
                            <?php foreach ($matrixCols as $ck => $col): ?>
                                <td class="text-end num"><?= number_format((float) ($matrixColTotals[$ck] ?? 0), 2) ?></td>
                            <?php endforeach; ?>
                            <td class="text-end num mx-total-col"><?= number_format($matrixGrand, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function tncSiteSpendingPrint(e) {
    if (e && typeof e.preventDefault === 'function') {
        e.preventDefault();
        e.stopPropagation();
    }
    document.body.classList.add('tnc-print-mode');
    var done = false;
    function cleanup() {
        if (done) return;
        done = true;
        document.body.classList.remove('tnc-print-mode');
    }
    window.addEventListener('afterprint', cleanup, { once: true });
    setTimeout(function () {
        window.print();
        setTimeout(cleanup, 1500);
    }, 50);
}

(function () {
    <?php if ($autoPrint): ?>
    window.addEventListener('load', function () { setTimeout(function () { tncSiteSpendingPrint(); }, 350); });
    <?php endif; ?>
})();
</script>
</body>
</html>
