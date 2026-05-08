<?php

declare(strict_types=1);


require_once __DIR__ . '/_page_root.php';
use Theelincon\Rtdb\Db;

session_start();
require_once THEELINCON_ROOT . '/config/connect_database.php';
require_once THEELINCON_ROOT . '/includes/cash_ledger_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$me = (int) $_SESSION['user_id'];
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
if (!$isAdmin) {
    header('Location: ' . app_path('index.php'));
    exit;
}
$cashHandlerUrl = app_path('actions/cash-ledger-handler.php');
$csrfQ = '&_csrf=' . rawurlencode(csrf_token());

$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$searchDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['entry_date'] ?? '')) ? (string) $_GET['entry_date'] : '';
$ymStart = $month . '-01';
$ymEnd = date('Y-m-t', strtotime($ymStart));
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $editRow = Db::row('cash_ledger', (string) $editId);
    if ($editRow && (int) ($editRow['created_by'] ?? 0) !== $me && !$isAdmin) {
        $editRow = null;
        $editId = 0;
    }
}

$sumIncome = 0.0;
$sumExpense = 0.0;
$net = 0.0;
$rows = [];
$rowCount = 0;

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
// ยอดยกมาต้นเดือน: คำนวณจากรายการก่อนหน้าเดือนที่เลือก
$openingBalance = 0.0;
foreach (Db::tableRows('cash_ledger') as $cPrev) {
    $edPrev = (string) ($cPrev['entry_date'] ?? '');
    if ($edPrev === '' || $edPrev >= $ymStart) {
        continue;
    }
    $amtPrev = (float) ($cPrev['amount'] ?? 0);
    $openingBalance += (($cPrev['entry_type'] ?? '') === 'income') ? $amtPrev : -$amtPrev;
}

foreach (Db::tableRows('cash_ledger') as $c) {
    $ed = (string) ($c['entry_date'] ?? '');
    if ($ed < $ymStart || $ed > $ymEnd) {
        continue;
    }
    if ($searchDate !== '' && $ed !== $searchDate) {
        continue;
    }
    $uid = (string) ($c['created_by'] ?? '');
    $u = $users[$uid] ?? null;
    $rows[] = array_merge($c, [
        'author_name' => trim(($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')),
    ]);
}

// คำนวณยอดคงเหลือรายบรรทัดโดยเรียงเวลาเก่า -> ใหม่ก่อน
$rowsAsc = $rows;
usort($rowsAsc, static function (array $a, array $b): int {
    $cmp = strcmp((string) ($a['entry_date'] ?? ''), (string) ($b['entry_date'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }

    return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
});

$runningBalance = $openingBalance;
$balanceById = [];
foreach ($rowsAsc as $rAsc) {
    $rowId = (int) ($rAsc['id'] ?? 0);
    $amt = (float) ($rAsc['amount'] ?? 0);
    $runningBalance += (($rAsc['entry_type'] ?? '') === 'income') ? $amt : -$amt;
    $balanceById[$rowId] = $runningBalance;
}

foreach ($rows as &$rowRef) {
    $rowId = (int) ($rowRef['id'] ?? 0);
    $rowRef['running_balance'] = $balanceById[$rowId] ?? $openingBalance;
}
unset($rowRef);

// แสดงผลล่าสุดขึ้นก่อนเหมือนเดิม
usort($rows, static function (array $a, array $b): int {
    $cmp = strcmp((string) ($b['entry_date'] ?? ''), (string) ($a['entry_date'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }

    return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
});

$rowCount = count($rows);
$perPage = 10;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
$totalPages = max(1, (int) ceil($rowCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$pagedRows = array_slice($rows, $offset, $perPage);
$showFrom = $rowCount === 0 ? 0 : ($offset + 1);
$showTo = $rowCount === 0 ? 0 : min($offset + count($pagedRows), $rowCount);
foreach ($rows as $r) {
    if (($r['entry_type'] ?? '') === 'income') {
        $sumIncome += (float) ($r['amount'] ?? 0);
    } else {
        $sumExpense += (float) ($r['amount'] ?? 0);
    }
}
$net = $sumIncome - $sumExpense;

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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/cash-ledger-print.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }
        .card-dash { border-radius: 16px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,.06); }
        .card-stats { border-left: 5px solid #fd7e14; transition: transform 0.2s; }
        .card-stats:hover { transform: translateY(-3px); }
        .table-cash-report { table-layout: fixed; width: 100%; }
        .table-cash-report th,
        .table-cash-report td { padding-left: .45rem; padding-right: .45rem; }
        .table-cash-report .col-date { width: 104px; }
        .table-cash-report .col-desc { width: 18%; }
        .table-cash-report .col-in,
        .table-cash-report .col-out,
        .table-cash-report .col-balance { width: 126px; }
        .table-cash-report .col-action { width: 76px; }
    </style>
</head>
<body>

<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>

<div class="container pb-5">
    <div class="no-print d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-speedometer2 text-warning me-2"></i>สรุปรายรับ — รายจ่าย</h4>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-dark rounded-pill px-3" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>พิมพ์รายงาน
            </button>
            <button type="button" class="btn btn-outline-primary rounded-pill px-3" data-bs-toggle="collapse" data-bs-target="#ledgerFormCollapse" aria-expanded="<?= $editRow ? 'true' : 'false' ?>" aria-controls="ledgerFormCollapse" id="toggleLedgerFormBtn">
                <i class="bi bi-cash-stack me-1"></i><?= $editRow ? 'แก้ไขรายการ' : 'เพิ่มรายการ' ?> <i class="bi <?= $editRow ? 'bi-chevron-up' : 'bi-chevron-down' ?> ms-1" id="toggleLedgerFormIcon"></i>
            </button>
        </div>
    </div>

    <div class="no-print card card-dash mb-4" id="ledger-form-card">
        <div class="collapse<?= $editRow ? ' show' : '' ?>" id="ledgerFormCollapse">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
                <h5 class="fw-bold mb-0"><?= $editRow ? 'แก้ไขรายการ' : 'เพิ่มรายการ' ?></h5>
            </div>
            <form method="post" action="<?= htmlspecialchars($cashHandlerUrl, ENT_QUOTES, 'UTF-8') ?>?action=save&redirect_to=dashboard" class="row g-3" id="ledgerForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($editRow): ?>
                    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                <?php endif; ?>

                <div class="col-md-2">
                    <label class="form-label fw-bold small">ประเภท</label>
                    <select name="entry_type" id="entry_type" class="form-select rounded-3" required>
                        <option value="income" <?= ($editRow['entry_type'] ?? '') === 'income' ? 'selected' : '' ?>>รายรับ</option>
                        <option value="expense" <?= ($editRow['entry_type'] ?? '') === 'expense' ? 'selected' : '' ?>>รายจ่าย</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small">วันที่</label>
                    <input type="date" name="entry_date" class="form-control rounded-3" required value="<?= htmlspecialchars($editRow['entry_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-bold small">รายละเอียดการจ่าย/รับ</label>
                    <input type="text" name="description" class="form-control rounded-3" maxlength="1000" required value="<?= htmlspecialchars($editRow['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small">จำนวนเงิน (บาท)</label>
                    <input type="number" name="amount" class="form-control rounded-3" required step="0.01" min="0.01" value="<?= htmlspecialchars(number_format((float) ($editRow['amount'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="col-12 d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn rounded-pill px-4 text-white" style="background-color:#fd7e14;">
                        <i class="bi bi-check-lg me-1"></i><?= $editRow ? 'บันทึกการแก้ไข' : 'บันทึกรายการ' ?>
                    </button>
                    <?php if ($editRow): ?>
                        <a href="<?= htmlspecialchars(app_path('pages/cash-ledger-dashboard.php') . '?month=' . urlencode($month), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill">ยกเลิก</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        </div>
    </div>

    <form method="get" class="no-print d-flex align-items-center gap-2 mb-4 flex-wrap">
        <label class="fw-bold small mb-0">เดือนที่ดู</label>
        <input type="month" name="month" class="form-control form-control-sm rounded-3" style="width: auto;" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>">
        <label class="fw-bold small mb-0">ค้นหาวันที่</label>
        <input type="date" name="entry_date" class="form-control form-control-sm rounded-3" style="width: auto;" value="<?= htmlspecialchars($searchDate, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary rounded-3">แสดง</button>
        <?php if ($searchDate !== ''): ?>
            <a href="<?= htmlspecialchars(app_path('pages/cash-ledger-dashboard.php') . '?month=' . urlencode($month), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-danger rounded-3">ล้างวันที่</a>
        <?php endif; ?>
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
                <span class="text-muted">จำนวนรายการบันทึก</span>
                <span class="fw-bold d-block"><?= number_format($rowCount) ?> รายการ</span>
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
            <h5 class="fw-bold mb-0">รายละเอียดทั้งหมดในเดือน <span class="text-secondary fw-semibold">(<?= number_format($rowCount) ?> รายการบันทึก)</span></h5>
            <?php if ($rowCount > 0): ?>
                <div class="text-muted small mt-1">แสดง <?= number_format($showFrom) ?>-<?= number_format($showTo) ?> จาก <?= number_format($rowCount) ?> รายการ</div>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-wrap-screen px-0">
                <table class="table table-hover align-middle mb-0 table-cash-report">
                    <thead class="table-light">
                        <tr>
                            <th class="py-3 ps-3 col-date">วันที่</th>
                            <th class="py-3 col-desc">รายละเอียด</th>
                            <th class="py-3 text-end col-in">รับ</th>
                            <th class="py-3 text-end col-out">จ่าย</th>
                            <th class="py-3 text-end col-balance">คงเหลือ</th>
                            <th class="pe-3 py-3 text-center no-print col-action">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rowCount === 0): ?>
                            <tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีรายการในเดือนนี้</td></tr>
                        <?php else: ?>
                            <?php $n = 0; foreach ($rows as $row): $n++;
                                $isInCurrentPage = $n > $offset && $n <= ($offset + $perPage);
                                $lid = (int) $row['id'];
                                $canManage = $isAdmin || (int) ($row['created_by'] ?? 0) === $me;
                                $memo = trim((string) ($row['description'] ?? ''));
                                ?>
                                <tr<?= $isInCurrentPage ? '' : ' class="d-none d-print-table-row"' ?>>
                                    <td class="text-secondary small text-nowrap ps-3"><?= date('d/m/Y', strtotime($row['entry_date'])) ?></td>
                                    <td class="small text-break col-desc" style="white-space:pre-wrap;"><?= nl2br(htmlspecialchars($memo !== '' ? $memo : '—', ENT_QUOTES, 'UTF-8')) ?></td>
                                    <td class="small text-end fw-bold text-success text-nowrap">
                                        <?= $row['entry_type'] === 'income' ? number_format((float) $row['amount'], 2) : '' ?>
                                    </td>
                                    <td class="small text-end fw-bold text-danger text-nowrap">
                                        <?= $row['entry_type'] === 'expense' ? number_format((float) $row['amount'], 2) : '' ?>
                                    </td>
                                    <td class="small text-end fw-semibold text-nowrap <?= ((float) ($row['running_balance'] ?? 0)) < 0 ? 'text-danger' : 'text-dark' ?>">
                                        <?= number_format((float) ($row['running_balance'] ?? 0), 2) ?>
                                    </td>
                                    <td class="pe-3 text-center no-print">
                                        <?php if ($canManage): ?>
                                            <a href="<?= htmlspecialchars(app_path('pages/cash-ledger-dashboard.php') . '?' . http_build_query(['month' => $month, 'page' => $page, 'edit' => $lid]), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-warning" title="แก้ไข">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                            <a href="<?= htmlspecialchars($cashHandlerUrl . '?action=delete&redirect_to=dashboard&id=' . $lid . '&month=' . urlencode($month) . $csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-danger" title="ลบ" onclick="return confirm('ยืนยันการลบรายการนี้ ?');">
                                                <i class="bi bi-trash-fill"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="no-print d-flex justify-content-between align-items-center px-3 py-3 border-top bg-white">
                    <div class="small text-muted">หน้า <?= number_format($page) ?> / <?= number_format($totalPages) ?></div>
                    <div class="d-flex gap-2">
                        <?php
                        $prevPage = $page - 1;
                        $nextPage = $page + 1;
                        $prevUrl = app_path('pages/cash-ledger-dashboard.php') . '?' . http_build_query(['month' => $month, 'entry_date' => $searchDate, 'page' => $prevPage]);
                        $nextUrl = app_path('pages/cash-ledger-dashboard.php') . '?' . http_build_query(['month' => $month, 'entry_date' => $searchDate, 'page' => $nextPage]);
                        ?>
                        <?php if ($page > 1): ?>
                            <a href="<?= htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-secondary rounded-pill">
                                <i class="bi bi-arrow-left me-1"></i>ดูก่อนหน้า
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" disabled>
                                <i class="bi bi-arrow-left me-1"></i>ดูก่อนหน้า
                            </button>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary rounded-pill">
                                ดูถัดไป<i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm btn-primary rounded-pill" disabled>
                                ดูถัดไป<i class="bi bi-arrow-right ms-1"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
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
                            <td class="ps-3 py-2 small text-muted" colspan="2">สรุปจาก <?= number_format($rowCount) ?> รายการบันทึก</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const params = new URLSearchParams(window.location.search);
if (params.get('saved') === '1') {
    Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', confirmButtonColor: '#fd7e14' });
}
if (params.get('deleted') === '1') {
    Swal.fire({ icon: 'success', title: 'ลบแล้ว', confirmButtonColor: '#fd7e14' });
}
if (params.get('err')) {
    const map = {
        amount: 'จำนวนเงินต้องมากกว่า 0',
        save_failed: 'บันทึกไม่สำเร็จ ลองใหม่อีกครั้ง',
        invalid_type: 'ประเภทไม่ถูกต้อง',
        date: 'วันที่ไม่ถูกต้อง',
        notfound: 'ไม่พบรายการที่ต้องการ',
        forbidden: 'คุณไม่มีสิทธิ์จัดการรายการนี้',
        csrf: 'เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง',
    };
    Swal.fire({ icon: 'error', title: 'ไม่สามารถดำเนินการได้', text: map[params.get('err')] || params.get('err'), confirmButtonColor: '#fd7e14' });
}

const ledgerFormCollapse = document.getElementById('ledgerFormCollapse');
const toggleLedgerFormBtn = document.getElementById('toggleLedgerFormBtn');
const toggleLedgerFormIcon = document.getElementById('toggleLedgerFormIcon');
if (ledgerFormCollapse && toggleLedgerFormBtn && toggleLedgerFormIcon) {
    const updateLedgerFormToggle = () => {
        const isOpen = ledgerFormCollapse.classList.contains('show');
        toggleLedgerFormBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        toggleLedgerFormIcon.className = isOpen ? 'bi bi-chevron-up ms-1' : 'bi bi-chevron-down ms-1';
    };
    ledgerFormCollapse.addEventListener('shown.bs.collapse', updateLedgerFormToggle);
    ledgerFormCollapse.addEventListener('hidden.bs.collapse', updateLedgerFormToggle);
    updateLedgerFormToggle();
}
</script>
</body>
</html>
