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
    $sites[$key]['pr_total'] += (float) ($pr['total_amount'] ?? 0);
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

    $paymentStatus = strtolower(trim((string) ($po['payment_status'] ?? 'unpaid')));
    if ($paymentStatus === 'paid') {
        $billed = (float) ($po['billed_total_amount'] ?? 0);
        $sites[$key]['paid_total'] += $billed > 0 ? $billed : $orderTotal;
    }
}

// outstanding + เรียงตามยอดจ่ายแล้วมากสุด
foreach ($sites as &$s) {
    $s['outstanding'] = round($s['po_total'] - $s['paid_total'], 2);
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

// ---------- Export CSV ----------
if (($_GET['export'] ?? '') === 'csv') {
    $filename = 'site-spending-' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    echo tnc_site_csv_row(['รายงานสรุปการใช้จ่ายแยกตามไซต์']);
    echo tnc_site_csv_row(['ช่วงข้อมูล', $periodText]);
    echo tnc_site_csv_row([]);
    echo tnc_site_csv_row(['ไซต์/สถานที่', 'จำนวน PR', 'ยอดขอซื้อ (PR)', 'จำนวน PO', 'ยอดสั่งซื้อ (PO)', 'จ่ายแล้ว', 'ค้างจ่าย']);
    foreach ($sites as $s) {
        echo tnc_site_csv_row([
            $s['label'],
            $s['pr_count'],
            number_format($s['pr_total'], 2, '.', ''),
            $s['po_count'],
            number_format($s['po_total'], 2, '.', ''),
            number_format($s['paid_total'], 2, '.', ''),
            number_format($s['outstanding'], 2, '.', ''),
        ]);
    }
    echo tnc_site_csv_row([
        'รวมทั้งหมด',
        $grandPrCount,
        number_format($grandPrTotal, 2, '.', ''),
        $grandPoCount,
        number_format($grandPoTotal, 2, '.', ''),
        number_format($grandPaid, 2, '.', ''),
        number_format($grandOutstanding, 2, '.', ''),
    ]);
    exit;
}
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
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container pb-5">
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
                </div>
            </form>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="stat-pill stat-paid h-100">
                <div class="label"><i class="bi bi-cash-coin me-1"></i>จ่ายแล้วทุกไซต์</div>
                <div class="value"><?= number_format($grandPaid, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill stat-order h-100">
                <div class="label"><i class="bi bi-bag-check me-1"></i>ยอดสั่งซื้อ (PO)</div>
                <div class="value"><?= number_format($grandPoTotal, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill stat-out h-100">
                <div class="label"><i class="bi bi-hourglass-split me-1"></i>ค้างจ่าย</div>
                <div class="value"><?= number_format($grandOutstanding, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill stat-pr h-100">
                <div class="label"><i class="bi bi-journal-text me-1"></i>ยอดขอซื้อ (PR)</div>
                <div class="value"><?= number_format($grandPrTotal, 2) ?></div>
            </div>
        </div>
    </div>

    <div class="card card-soft mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
                <span class="badge bg-light text-dark border">ช่วงข้อมูล: <?= h($periodText) ?></span>
                <span class="badge bg-secondary-subtle text-secondary-emphasis">จำนวนไซต์: <?= count($sites) ?></span>
                <span class="text-muted small">* "จ่ายแล้ว" คือ PO ที่ทำเครื่องหมายจ่ายเงินแล้ว (ไม่รวมใบยกเลิก) · "ค้างจ่าย" = ยอดสั่งซื้อ − จ่ายแล้ว</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>ไซต์/สถานที่</th>
                        <th class="text-end">จำนวน PR</th>
                        <th class="text-end">ยอดขอซื้อ (PR)</th>
                        <th class="text-end">จำนวน PO</th>
                        <th class="text-end">ยอดสั่งซื้อ (PO)</th>
                        <th class="text-end">จ่ายแล้ว</th>
                        <th class="text-end">ค้างจ่าย</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($sites === []): ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">ไม่พบข้อมูล PR/PO ตามเงื่อนไข</td></tr>
                    <?php else: ?>
                        <?php foreach ($sites as $s): ?>
                            <tr>
                                <td class="fw-semibold"><?= h($s['label']) ?></td>
                                <td class="text-end"><?= number_format($s['pr_count']) ?></td>
                                <td class="text-end"><?= number_format($s['pr_total'], 2) ?></td>
                                <td class="text-end"><?= number_format($s['po_count']) ?></td>
                                <td class="text-end"><?= number_format($s['po_total'], 2) ?></td>
                                <td class="text-end fw-bold text-success"><?= number_format($s['paid_total'], 2) ?></td>
                                <td class="text-end <?= $s['outstanding'] > 0 ? 'text-warning-emphasis fw-semibold' : 'text-muted' ?>"><?= number_format($s['outstanding'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($sites !== []): ?>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td>รวมทั้งหมด</td>
                            <td class="text-end"><?= number_format($grandPrCount) ?></td>
                            <td class="text-end"><?= number_format($grandPrTotal, 2) ?></td>
                            <td class="text-end"><?= number_format($grandPoCount) ?></td>
                            <td class="text-end"><?= number_format($grandPoTotal, 2) ?></td>
                            <td class="text-end text-success"><?= number_format($grandPaid, 2) ?></td>
                            <td class="text-end"><?= number_format($grandOutstanding, 2) ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
