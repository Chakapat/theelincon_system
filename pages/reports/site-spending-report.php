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
        .stat-pill {
            border-radius: 12px;
            padding: .85rem 1.1rem;
            color: #fff;
        }
        .stat-pill .label { font-size: .8rem; opacity: .9; }
        .stat-pill .value { font-size: 1.25rem; font-weight: 700; }
        .stat-paid { background: linear-gradient(135deg, #16a34a, #22c55e); }
        .stat-order { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .stat-out { background: linear-gradient(135deg, #d97706, #f59e0b); }
        .stat-pr { background: linear-gradient(135deg, #7c3aed, #a855f7); }
        @media (min-width: 1200px) { .container { max-width: 1140px; } }
        /* ---- ตารางสรุป ---- */
        #spendTable { border-collapse: separate; border-spacing: 0; }
        #spendTable thead th {
            background: #f1f5f9;
            color: #334155;
            font-weight: 700;
            font-size: .8rem;
            text-transform: none;
            border-bottom: 2px solid #e2e8f0;
            padding: .7rem .9rem;
            white-space: nowrap;
        }
        #spendTable td { padding: .65rem .9rem; vertical-align: middle; }
        #spendTable .num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .site-row { border-top: 1px solid #eef2f7; }
        .site-row:hover { background: #fff7ed; }
        .site-row .site-sub { font-size: .72rem; line-height: 1.1; margin-top: 1px; }
        .btn-cat-toggle {
            width: 24px; height: 24px; flex: 0 0 24px;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 6px; border: 1px solid #e2e8f0; background: #fff; color: #64748b;
        }
        .btn-cat-toggle:hover { background: #f1f5f9; color: #0f172a; }
        .cat-toggle-spacer { display: inline-block; width: 24px; flex: 0 0 24px; }
        .btn-cat-toggle .bi-caret-right-fill { transition: transform .15s ease; font-size: .7rem; }
        [aria-expanded="true"] .bi-caret-right-fill { transform: rotate(90deg); }
        .cat-breakdown { background: #fcfdfe; margin: 0; }
        .cat-breakdown td { border: 0; border-bottom: 1px dashed #eef2f7; font-size: .83rem; padding: .5rem .9rem; }
        .cat-breakdown tr:last-child td { border-bottom: 0; }
        .cat-breakdown .cat-name { color: #475569; padding-left: 3.2rem; }
        .cat-breakdown .cat-name .bi { color: #fd7e14; font-size: .75rem; }
        .cat-detail-row > td { border-left: 3px solid #fed7aa; }
        .grand-row td { background: #f8fafc; border-top: 2px solid #e2e8f0; }
        /* ---- ตารางไขว้ (Matrix) ---- */
        .matrix-wrap { overflow-x: auto; }
        #matrixTable { border-collapse: separate; border-spacing: 0; min-width: 640px; }
        #matrixTable thead th {
            background: #1e7a46;
            color: #fff;
            font-weight: 700;
            font-size: .82rem;
            padding: .6rem .8rem;
            white-space: nowrap;
            position: sticky; top: 0;
        }
        #matrixTable th, #matrixTable td { border-bottom: 1px solid #eef2f7; padding: .55rem .8rem; }
        #matrixTable tbody tr:nth-child(even) td { background: #f8fafc; }
        #matrixTable tbody tr:hover td { background: #fff7ed; }
        #matrixTable .num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        #matrixTable .mx-site-col {
            position: sticky; left: 0; z-index: 2;
            background: #fff; min-width: 200px; box-shadow: 1px 0 0 #e5e7eb;
        }
        #matrixTable thead .mx-site-col { background: #1e7a46; z-index: 3; }
        #matrixTable tbody tr:nth-child(even) .mx-site-col { background: #f8fafc; }
        #matrixTable tbody tr:hover .mx-site-col { background: #fff7ed; }
        #matrixTable .mx-total-col { background: #f1f8f4; font-weight: 700; }
        #matrixTable .grand-row td { background: #eef2f7 !important; border-top: 2px solid #cbd5e1; }
        #matrixTable .grand-row .mx-site-col { background: #eef2f7 !important; }
        .btn-print-modern {
            border: 0;
            border-radius: 999px;
            padding: .55rem 1rem;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, #334155 0%, #475569 100%);
            box-shadow: 0 8px 20px rgba(51, 65, 85, .25);
        }
        .btn-print-modern:hover { color: #fff; filter: brightness(1.05); }
        .print-header { text-align: center; margin-bottom: 14px; }
        .print-header .print-company { font-size: 1.15rem; font-weight: 700; }
        .print-header .print-title { font-size: 1rem; font-weight: 600; margin-top: 2px; }
        .print-header .print-meta { font-size: .82rem; color: #444; margin-top: 4px; display: flex; gap: 18px; justify-content: center; flex-wrap: wrap; }

        @media print {
            @page { size: A4 portrait; margin: 12mm 10mm; }
            body { background: #fff !important; font-size: 12px; }
            /* ซ่อนส่วนที่ไม่ใช่เอกสาร */
            .navbar, nav, .card-soft > .card-body > form, .row.g-2.mb-3,
            #btnPrintReport, .btn-export-modern, .cat-detail-row .btn,
            tbody tr td .badge, .text-muted.small { display: none !important; }
            .container { max-width: 100% !important; width: 100% !important; padding: 0 !important; margin: 0 !important; }
            .card, .card-soft { box-shadow: none !important; border: 0 !important; }
            .card-body { padding: 0 !important; }
            .print-header { display: block !important; }
            /* กางรายละเอียดหมวดย่อยทั้งหมดตอนพิมพ์ */
            .collapse { display: block !important; height: auto !important; visibility: visible !important; }
            .cat-detail-row > td { padding: 0 !important; }
            .cat-breakdown { background: #fff !important; }
            table { width: 100% !important; border-collapse: collapse !important; }
            #spendTable, #spendTable th, #spendTable td, .cat-breakdown td,
            #matrixTable, #matrixTable th, #matrixTable td {
                border: 1px solid #999 !important;
                color: #000 !important;
                font-size: 11px !important;
                padding: 4px 6px !important;
            }
            #spendTable thead th, #matrixTable thead th { background: #e9edf2 !important; color: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            #spendTable .grand-row td, #matrixTable .grand-row td, tfoot tr { background: #f0f0f0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-weight: 700; }
            .cat-breakdown .cat-name { padding-left: 22px !important; }
            .cat-detail-row > td { border-left: 1px solid #999 !important; }
            .text-success, .text-warning-emphasis { color: #000 !important; }
            .site-row .site-sub { color: #333 !important; }
            /* ปุ่ม/เครื่องมือของ matrix ไม่พิมพ์ */
            .matrix-tools, #matrixCard h5 { display: none !important; }
            #matrixTable .mx-site-col, #matrixTable .mx-total-col { position: static !important; box-shadow: none !important; background: transparent !important; }
            /* แยกพิมพ์เฉพาะตารางที่เลือก */
            body.print-summary #matrixCard { display: none !important; }
            body.print-matrix #summaryCard, body.print-matrix #summaryPrintHeader { display: none !important; }
            #matrixCard { page-break-before: auto; }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container pb-5">
    <div class="print-header d-none" id="summaryPrintHeader">
        <div class="print-company"><?= h($companyName) ?></div>
        <div class="print-title">รายงานสรุปการใช้จ่ายแยกตามไซต์ (Site Spending)</div>
        <div class="print-meta">
            <span>ช่วงข้อมูล: <?= h($periodText) ?></span>
            <span>จำนวนไซต์: <?= count($sites) ?></span>
            <span>พิมพ์เมื่อ: <?= h($printedAt) ?></span>
        </div>
    </div>
    <div class="card card-soft mb-3">
        <div class="card-body">
            <h4 class="fw-bold mb-3"><i class="bi bi-geo-alt me-2 text-primary"></i>รายงานการใช้จ่ายแยกตามไซต์ (Site Spending)</h4>
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
                    <?php
                    $exportQuery = $_GET;
                    $exportQuery['export'] = 'csv';
                    ?>
                    <a href="<?= h(app_path('pages/reports/site-spending-report.php') . '?' . http_build_query($exportQuery)) ?>" class="btn btn-export-modern">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export (Excel / Google Sheets)
                    </a>
                    <button type="button" id="btnPrintReport" class="btn btn-print-modern">
                        <i class="bi bi-printer me-1"></i>พิมพ์เอกสาร
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-12 col-md-4">
            <div class="stat-pill stat-paid h-100">
                <div class="label"><i class="bi bi-cash-coin me-1"></i>จ่ายแล้วทุกไซต์</div>
                <div class="value"><?= number_format($grandPaid, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="stat-pill stat-order h-100">
                <div class="label"><i class="bi bi-bag-check me-1"></i>ยอดสั่งซื้อ (PO)</div>
                <div class="value"><?= number_format($grandPoTotal, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="stat-pill stat-out h-100">
                <div class="label"><i class="bi bi-hourglass-split me-1"></i>ค้างจ่าย</div>
                <div class="value"><?= number_format($grandOutstanding, 2) ?></div>
            </div>
        </div>
    </div>

    <div class="card card-soft mb-3" id="summaryCard">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
                <span class="badge bg-light text-dark border">ช่วงข้อมูล: <?= h($periodText) ?></span>
                <span class="badge bg-secondary-subtle text-secondary-emphasis">จำนวนไซต์: <?= count($sites) ?></span>
                <span class="text-muted small">* "จ่ายแล้ว" คือ PO ที่ทำเครื่องหมายจ่ายเงินแล้ว (ไม่รวมใบยกเลิก) · "ค้างจ่าย" = ยอดสั่งซื้อ − จ่ายแล้ว</span>
            </div>
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
                                            <button type="button" class="btn btn-sm btn-cat-toggle p-0" data-bs-toggle="collapse" data-bs-target="#cat-<?= $siteIdx ?>" aria-expanded="false" title="ดูหมวดย่อย">
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
    <div class="card card-soft mb-3" id="matrixCard">
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
                <h5 class="fw-bold mb-0"><i class="bi bi-grid-3x3-gap me-2 text-primary"></i>ตารางค่าใช้จ่าย: ไซต์ × หมวด</h5>
                <div class="d-flex flex-wrap gap-2 align-items-center matrix-tools">
                    <div class="btn-group btn-group-sm" role="group" aria-label="เลือกค่าที่แสดง">
                        <?php foreach (['po' => 'ยอดสั่งซื้อ', 'paid' => 'จ่ายแล้ว'] as $mk => $mlabel): ?>
                            <a href="<?= h($mkUrl(['mx' => $mk])) ?>#matrixCard" class="btn <?= $matrixMetric === $mk ? 'btn-primary' : 'btn-outline-primary' ?>"><?= h($mlabel) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <a href="<?= h($mkUrl(['export' => 'matrix'])) ?>" class="btn btn-sm btn-export-modern"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Export</a>
                    <button type="button" id="btnPrintMatrix" class="btn btn-sm btn-print-modern"><i class="bi bi-printer me-1"></i>พิมพ์</button>
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
(function () {
    function expandAllForPrint() {
        // กางหมวดย่อยทุกไซต์ให้แสดงครบก่อนพิมพ์
        document.querySelectorAll('.cat-detail-row .collapse').forEach(function (el) { el.classList.add('show'); });
        document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
    }
    function printWith(mode) {
        expandAllForPrint();
        if (mode) { document.body.classList.add(mode); }
        window.print();
    }
    window.addEventListener('afterprint', function () {
        document.body.classList.remove('print-summary', 'print-matrix');
    });
    var btn = document.getElementById('btnPrintReport');
    if (btn) { btn.addEventListener('click', function () { printWith('print-summary'); }); }
    var btnMx = document.getElementById('btnPrintMatrix');
    if (btnMx) { btnMx.addEventListener('click', function () { printWith('print-matrix'); }); }
    // เผื่อเรียกผ่านลิงก์ ?print=1 (พิมพ์ในแท็บเดิม ไม่เปิดแท็บใหม่)
    <?php if ($autoPrint): ?>
    window.addEventListener('load', function () { setTimeout(function () { printWith('print-summary'); }, 350); });
    <?php endif; ?>
})();
</script>
</body>
</html>
