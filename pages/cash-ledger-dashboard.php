<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/cash_ledger_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$ymStart = $month . '-01';
$ymEnd = date('Y-m-t', strtotime($ymStart));

$sumIncome = 0.0;
$sumExpense = 0.0;
$net = 0.0;
$rows = [];
$rowCount = 0;
$lineItemCount = 0;
$dashLinesByLedger = [];

$thaiMonths = [
    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน', 5 => 'พฤษภาคม', 6 => 'มิถุนายน',
    7 => 'กรกฎาคม', 8 => 'สิงหาคม', 9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
];
$ymParts = array_map('intval', explode('-', $month));
$periodLabelTh = ($thaiMonths[$ymParts[1]] ?? '') . ' พ.ศ. ' . ($ymParts[0] + 543);
$printedBy = trim((string) ($_SESSION['name'] ?? ''));
if ($printedBy === '') {
    $printedBy = 'ผู้ใช้งาน';
}

$users = Db::tableKeyed('users');
$storesKeyed = Db::tableKeyed('cash_ledger_stores');
$sitesKeyed = Db::tableKeyed('cash_ledger_sites');

foreach (Db::tableRows('cash_ledger') as $c) {
    $ed = (string) ($c['entry_date'] ?? '');
    if ($ed < $ymStart || $ed > $ymEnd) {
        continue;
    }
    $uid = (string) ($c['created_by'] ?? '');
    $u = $users[$uid] ?? null;
    $sid = (int) ($c['store_id'] ?? 0);
    $zid = (int) ($c['site_id'] ?? 0);
    $stName = $sid > 0 ? (string) ($storesKeyed[(string) $sid]['name'] ?? '') : '';
    $siName = $zid > 0 ? (string) ($sitesKeyed[(string) $zid]['name'] ?? '') : '';
    $rows[] = array_merge($c, [
        'author_name' => trim(($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')),
        'store_name' => $stName,
        'site_name' => $siName,
    ]);
}

usort($rows, static function (array $a, array $b): int {
    $cmp = strcmp((string) ($a['entry_date'] ?? ''), (string) ($b['entry_date'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }

    return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
});

$rowCount = count($rows);
$idsInMonth = [];
foreach ($rows as $r) {
    $idsInMonth[(int) ($r['id'] ?? 0)] = true;
}

foreach (Db::tableRows('cash_ledger_lines') as $ln) {
    $lid = (int) ($ln['ledger_id'] ?? 0);
    if ($lid <= 0 || empty($idsInMonth[$lid])) {
        continue;
    }
    if (!isset($dashLinesByLedger[$lid])) {
        $dashLinesByLedger[$lid] = [];
    }
    $dashLinesByLedger[$lid][] = $ln;
}
foreach ($dashLinesByLedger as $lid => &$list) {
    usort($list, static fn ($a, $b): int => (int) ($a['line_no'] ?? 0) <=> (int) ($b['line_no'] ?? 0));
}
unset($list);

foreach ($rows as $r) {
    if (($r['entry_type'] ?? '') === 'income') {
        $sumIncome += (float) ($r['amount'] ?? 0);
    } else {
        $sumExpense += (float) ($r['amount'] ?? 0);
    }
}
$net = $sumIncome - $sumExpense;

$ledgerDates = [];
foreach (Db::tableRows('cash_ledger') as $lg) {
    $ledgerDates[(int) ($lg['id'] ?? 0)] = (string) ($lg['entry_date'] ?? '');
}
foreach (Db::tableRows('cash_ledger_lines') as $ln) {
    $lgId = (int) ($ln['ledger_id'] ?? 0);
    $ed = $ledgerDates[$lgId] ?? '';
    if ($ed !== '' && $ed >= $ymStart && $ed <= $ymEnd) {
        ++$lineItemCount;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สรุปรายรับรายจ่ายภายใน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/cash-ledger-print.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }
        .card-dash { border-radius: 16px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,.06); }
        .card-stats { border-left: 5px solid #fd7e14; transition: transform 0.2s; }
        .card-stats:hover { transform: translateY(-3px); }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container pb-5">
    <div class="no-print d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-speedometer2 text-warning me-2"></i>สรุปรายรับ — รายจ่าย</h4>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-dark rounded-pill px-3" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>พิมพ์รายงาน
            </button>
            <a href="<?= htmlspecialchars(app_path('pages/cash-ledger.php') . '?month=' . urlencode($month)) ?>" class="btn text-white rounded-pill px-3" style="background-color:#fd7e14;">
                <i class="bi bi-pencil-square me-1"></i>บันทึกรายการ
            </a>
        </div>
    </div>

    <form method="get" class="no-print d-flex align-items-center gap-2 mb-4 flex-wrap">
        <label class="fw-bold small mb-0">เดือนที่ดู</label>
        <input type="month" name="month" class="form-control form-control-sm rounded-3" style="width: auto;" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary rounded-3">แสดง</button>
    </form>

    <div class="d-none d-print-block report-print-header text-center border-bottom border-2 border-dark pb-3 mb-3">
        <h1 class="h4 fw-bold mb-1">THEELIN CON CO.,LTD.</h1>
        <h2 class="h5 fw-bold mb-2">รายงานสรุปรายรับ — รายจ่ายภายใน</h2>
        <p class="mb-1 fw-semibold">งวดบัญชี: <?= htmlspecialchars($periodLabelTh, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>)</p>
        <p class="small mb-2">พิมพ์เมื่อ <?= date('d/m/Y H:i') ?> &nbsp;|&nbsp; ผู้พิมพ์: <?= htmlspecialchars($printedBy, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="row justify-content-center g-2 small">
            <div class="col-auto border rounded px-3 py-2 mx-1">
                <span class="text-muted">รายรับรวม</span>
                <span class="fw-bold text-success d-block">฿<?= number_format($sumIncome, 2) ?></span>
            </div>
            <div class="col-auto border rounded px-3 py-2 mx-1">
                <span class="text-muted">รายจ่ายรวม</span>
                <span class="fw-bold text-danger d-block">฿<?= number_format($sumExpense, 2) ?></span>
            </div>
            <div class="col-auto border rounded px-3 py-2 mx-1">
                <span class="text-muted">คงเหลือ</span>
                <span class="fw-bold d-block">฿<?= number_format($net, 2) ?></span>
            </div>
            <div class="col-auto border rounded px-3 py-2 mx-1">
                <span class="text-muted">จำนวนรายการสินค้า</span>
                <span class="fw-bold d-block"><?= number_format($lineItemCount) ?> รายการสินค้า</span>
            </div>
        </div>
    </div>

    <div class="no-print row g-4 mb-4">
        <div class="col-md-4">
            <div class="card card-stats border-0 shadow-sm p-3 rounded-4" style="border-left-color: #198754;">
                <h6 class="text-muted mb-1 small">รายรับ</h6>
                <h3 class="fw-bold text-success mb-0">฿<?= number_format($sumIncome, 2) ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stats border-0 shadow-sm p-3 rounded-4" style="border-left-color: #dc3545;">
                <h6 class="text-muted mb-1 small">รายจ่าย</h6>
                <h3 class="fw-bold text-danger mb-0">฿<?= number_format($sumExpense, 2) ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stats border-0 shadow-sm p-3 rounded-4" style="border-left-color: #0d6efd;">
                <h6 class="text-muted mb-1 small">คงเหลือ (รายรับ − รายจ่าย)</h6>
                <h3 class="fw-bold mb-0 <?= $net >= 0 ? 'text-dark' : 'text-danger' ?>">฿<?= number_format($net, 2) ?></h3>
            </div>
        </div>
    </div>

    <div class="card card-dash">
        <div class="card-header bg-white border-0 py-3 px-4">
            <h5 class="fw-bold mb-0">รายละเอียดทั้งหมดในเดือน <span class="text-secondary fw-semibold">(<?= number_format($lineItemCount) ?> รายการสินค้า · <?= number_format($rowCount) ?> รายการบันทึก)</span></h5>
        </div>
        <div class="card-body p-0">
            <div class="table-wrap-screen px-0">
                <table class="table table-hover align-middle mb-0 table-cash-report">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3 py-3 text-center" style="width:3rem;">#</th>
                            <th class="py-3">วันที่</th>
                            <th class="py-3">ประเภท</th>
                            <th class="py-3">หมวด</th>
                            <th class="py-3 text-end">ยอดสุทธิ</th>
                            <th class="py-3">VAT</th>
                            <th class="py-3">ร้าน / แหล่งซื้อ</th>
                            <th class="py-3">ไซต์งาน</th>
                            <th class="py-3">รายการสินค้า</th>
                            <th class="pe-3 py-3">ผู้บันทึก</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rowCount === 0): ?>
                            <tr><td colspan="10" class="text-center text-muted py-5">ยังไม่มีรายการในเดือนนี้</td></tr>
                        <?php else: ?>
                            <?php $n = 0; foreach ($rows as $row): $n++;
                                $lid = (int) $row['id'];
                                $subLines = $dashLinesByLedger[$lid] ?? [];
                                $sn = trim((string) ($row['store_name'] ?? ''));
                                $bf = trim((string) ($row['bought_from'] ?? ''));
                                $storeDisp = $sn !== '' ? $sn : ($bf !== '' ? $bf : '—');
                                $sin = trim((string) ($row['site_name'] ?? ''));
                                $us = trim((string) ($row['used_at_site'] ?? ''));
                                $siteDisp = $sin !== '' ? $sin : ($us !== '' ? $us : '—');
                                $memo = trim((string) ($row['description'] ?? ''));
                                $lineText = cash_ledger_format_lines_summary($subLines);
                                if ($memo !== '' && $lineText !== '—') {
                                    $lineText .= "\nหมายเหตุ: " . $memo;
                                } elseif ($memo !== '') {
                                    $lineText = $memo;
                                }
                                ?>
                                <tr>
                                    <td class="ps-3 text-center text-secondary small"><?= $n ?></td>
                                    <td class="text-secondary small text-nowrap"><?= date('d/m/Y', strtotime($row['entry_date'])) ?></td>
                                    <td>
                                        <?php if ($row['entry_type'] === 'income'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle d-inline d-print-none">รายรับ</span>
                                            <span class="d-none d-print-inline fw-semibold">รายรับ</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle d-inline d-print-none">รายจ่าย</span>
                                            <span class="d-none d-print-inline fw-semibold">รายจ่าย</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?php
                                        $cat = trim((string) ($row['category'] ?? ''));
                                        echo $cat !== '' ? htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') : '—';
                                    ?></td>
                                    <td class="small text-end fw-bold <?= $row['entry_type'] === 'income' ? 'text-success' : 'text-danger' ?> text-nowrap">
                                        <?= $row['entry_type'] === 'income' ? '+' : '−' ?><?= number_format((float) $row['amount'], 2) ?>
                                    </td>
                                    <td class="small"><?= htmlspecialchars(cash_ledger_vat_label($row), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="small text-break"><?= htmlspecialchars($storeDisp, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="small text-break"><?= htmlspecialchars($siteDisp, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="small text-break" style="white-space:pre-wrap;"><?= nl2br(htmlspecialchars($lineText, ENT_QUOTES, 'UTF-8')) ?></td>
                                    <td class="pe-3 small text-secondary"><?= htmlspecialchars(trim($row['author_name'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($rowCount > 0): ?>
            <div class="cash-report-final-summary px-3 py-3 border-top bg-light">
                <h6 class="fw-bold text-secondary small mb-2">ผลรวมสุดท้าย</h6>
                <table class="table table-sm table-bordered mb-0 bg-white align-middle">
                    <tbody>
                        <tr>
                            <td class="ps-3 py-2 text-end fw-bold" style="width:40%;">รวมรายรับทั้งเดือน</td>
                            <td class="py-2 text-end fw-bold text-success text-nowrap">฿<?= number_format($sumIncome, 2) ?></td>
                        </tr>
                        <tr>
                            <td class="ps-3 py-2 text-end fw-bold">รวมรายจ่ายทั้งเดือน</td>
                            <td class="py-2 text-end fw-bold text-danger text-nowrap">฿<?= number_format($sumExpense, 2) ?></td>
                        </tr>
                        <tr>
                            <td class="ps-3 py-2 text-end fw-bold">คงเหลือ (รายรับ − รายจ่าย)</td>
                            <td class="py-2 text-end fw-bold text-nowrap <?= $net >= 0 ? '' : 'text-danger' ?>">฿<?= number_format($net, 2) ?></td>
                        </tr>
                        <tr>
                            <td class="ps-3 py-2 small text-muted" colspan="2">สรุปจาก <?= number_format($lineItemCount) ?> รายการสินค้า (<?= number_format($rowCount) ?> รายการบันทึก)</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
