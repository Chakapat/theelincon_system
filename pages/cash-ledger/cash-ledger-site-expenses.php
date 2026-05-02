<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/cash_ledger_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}
if (!isset($_SESSION['user_id']) || !user_is_admin_role()) {
    header('Location: ' . app_path('index.php'));
    exit;
}

cash_ledger_auto_archive_monthly_if_due();

$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$ymStart = $month . '-01';
$ymEnd = date('Y-m-t', strtotime($ymStart));
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

$sitesKeyed = Db::tableKeyed('sites');
$siteExpenseSummary = [];

foreach (Db::tableRows('sites') as $siteRow) {
    $siteId = (int) ($siteRow['id'] ?? 0);
    if ($siteId <= 0) {
        continue;
    }
    $siteName = trim((string) ($siteRow['name'] ?? ''));
    if ($siteName === '') {
        $siteName = 'ไซต์งาน #' . $siteId;
    }
    $siteExpenseSummary['id:' . $siteId] = [
        'site_label' => $siteName,
        'expense_total' => 0.0,
        'expense_count' => 0,
    ];
}

foreach (Db::tableRows('cash_ledger') as $row) {
    $entryDate = (string) ($row['entry_date'] ?? '');
    if ($entryDate < $ymStart || $entryDate > $ymEnd) {
        continue;
    }
    if ((string) ($row['entry_type'] ?? '') !== 'expense') {
        continue;
    }
    $amount = (float) ($row['amount'] ?? 0);
    if ($amount <= 0) {
        continue;
    }

    $siteId = (int) ($row['site_id'] ?? 0);
    $siteName = '';
    if ($siteId > 0) {
        $siteName = trim((string) ($sitesKeyed[(string) $siteId]['name'] ?? ''));
    }
    $siteText = trim((string) ($row['used_at_site'] ?? ''));

    if ($siteId > 0) {
        $key = 'id:' . $siteId;
        if (!isset($siteExpenseSummary[$key])) {
            $siteExpenseSummary[$key] = [
                'site_label' => $siteName !== '' ? $siteName : ('ไซต์งาน #' . $siteId),
                'expense_total' => 0.0,
                'expense_count' => 0,
            ];
        }
    } else {
        $label = $siteText !== '' ? $siteText : 'ไม่ระบุไซต์';
        $key = 'text:' . mb_strtolower($label);
        if (!isset($siteExpenseSummary[$key])) {
            $siteExpenseSummary[$key] = [
                'site_label' => $label,
                'expense_total' => 0.0,
                'expense_count' => 0,
            ];
        }
    }

    $siteExpenseSummary[$key]['expense_total'] += $amount;
    $siteExpenseSummary[$key]['expense_count']++;
}

$siteRows = array_values($siteExpenseSummary);
usort($siteRows, static function (array $a, array $b): int {
    $cmp = ((float) ($b['expense_total'] ?? 0)) <=> ((float) ($a['expense_total'] ?? 0));
    if ($cmp !== 0) {
        return $cmp;
    }
    return strcmp((string) ($a['site_label'] ?? ''), (string) ($b['site_label'] ?? ''));
});

$grandExpense = 0.0;
foreach ($siteRows as $siteRow) {
    $grandExpense += (float) ($siteRow['expense_total'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ค่าใช้จ่ายแต่ละไซต์ | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }
        .card-dash { border-radius: 16px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,.06); }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .card-dash { box-shadow: none; border: 1px solid #dee2e6; }
        }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container pb-5">
    <div class="no-print d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-geo-alt text-warning me-2"></i>ค่าใช้จ่ายแต่ละไซต์</h4>
            <div class="text-muted small">สรุปจากรายการรายจ่ายของเดือนที่เลือก</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-dark rounded-pill" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>พิมพ์รายงาน
            </button>
            <a href="<?= htmlspecialchars(app_path('pages/cash-ledger/cash-ledger.php') . '?month=' . urlencode($month), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-primary rounded-pill">
                <i class="bi bi-arrow-left me-1"></i>กลับหน้า Dashboard
            </a>
        </div>
    </div>

    <form method="get" class="no-print d-flex align-items-center gap-2 mb-4 flex-wrap">
        <label class="fw-bold small mb-0">เดือนที่ดู</label>
        <input type="month" name="month" class="form-control form-control-sm rounded-3" style="width: auto;" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary rounded-3">แสดง</button>
    </form>

    <div class="d-none d-print-block text-center border-bottom border-2 border-dark pb-3 mb-3">
        <h1 class="h4 fw-bold mb-1">THEELIN CON CO.,LTD.</h1>
        <h2 class="h5 fw-bold mb-2">รายงานค่าใช้จ่ายแต่ละไซต์</h2>
        <p class="mb-1 fw-semibold">งวดบัญชี: <?= htmlspecialchars($periodLabelTh, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>)</p>
        <p class="small mb-0">พิมพ์เมื่อ <?= date('d/m/Y H:i') ?> &nbsp;|&nbsp; ผู้พิมพ์: <?= htmlspecialchars($printedBy, ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="row g-3 mb-4 no-print">
        <div class="col-md-6">
            <div class="card card-dash p-3">
                <div class="text-muted small">จำนวนไซต์/กลุ่มที่พบในรายจ่าย</div>
                <div class="fw-bold fs-4"><?= number_format(count($siteRows)) ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card card-dash p-3">
                <div class="text-muted small">รวมค่าใช้จ่ายทั้งหมด</div>
                <div class="fw-bold fs-4 text-danger">฿<?= number_format($grandExpense, 2) ?></div>
            </div>
        </div>
    </div>

    <div class="card card-dash">
        <div class="card-header bg-white border-0 py-3 px-4">
            <h5 class="fw-bold mb-0">รายละเอียดค่าใช้จ่ายรายไซต์</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4" style="width:4rem;">#</th>
                            <th>ไซต์งาน</th>
                            <th class="text-center">จำนวนรายการรายจ่าย</th>
                            <th class="text-end pe-4">รวมค่าใช้จ่าย (บาท)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($siteRows) === 0): ?>
                        <tr>
                            <td colspan="4" class="text-center py-5 text-muted">ไม่พบข้อมูลรายจ่ายในเดือนนี้</td>
                        </tr>
                    <?php else: ?>
                        <?php $n = 0; foreach ($siteRows as $siteRow): $n++; ?>
                            <tr>
                                <td class="ps-4 text-secondary small"><?= $n ?></td>
                                <td class="fw-semibold"><?= htmlspecialchars((string) ($siteRow['site_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-center"><?= number_format((int) ($siteRow['expense_count'] ?? 0)) ?></td>
                                <td class="text-end pe-4 fw-bold text-danger">฿<?= number_format((float) ($siteRow['expense_total'] ?? 0), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

