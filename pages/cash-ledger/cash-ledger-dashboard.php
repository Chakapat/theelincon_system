<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/cash_ledger_helpers.php';

if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Asia/Bangkok');
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$me = (int) $_SESSION['user_id'];
$isAdmin = user_can('page.cash') && user_is_admin_only_role();
if (!$isAdmin) {
    $access_denied_title = 'สดย่อย (Petty Cash)';
    $access_denied_text = 'เข้าใช้งานได้เฉพาะผู้ใช้ที่มีสิทธิ์ ADMIN เท่านั้น';
    require dirname(__DIR__, 2) . '/includes/page_access_denied_swal.php';
    exit;
}
$cashHandlerUrl = app_path('actions/cash-ledger-handler.php');
$dashboardUrl = app_path('pages/cash-ledger/cash-ledger-dashboard.php');
$csrfQ = '&_csrf=' . rawurlencode(csrf_token());

$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$searchDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['entry_date'] ?? '')) ? (string) $_GET['entry_date'] : '';
$ymStart = $month . '-01';
$ymEnd = date('Y-m-t', strtotime($ymStart));
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $editRow = Db::rowByIdField('cash_ledger', $editId);
    if ($editRow && (int) ($editRow['created_by'] ?? 0) !== $me && !$isAdmin) {
        $editRow = null;
        $editId = 0;
    }
}

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
$printedAtThai = '';
try {
    $printedAtThai = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('d/m/Y H:i');
} catch (Throwable $e) {
    $printedAtThai = date('d/m/Y H:i');
}

$users = Db::tableKeyed('users');

$allLedgerChrono = cash_ledger_chronological_rows();
$latestBalanceAllTime = 0.0;
$openingBalance = 0.0;
foreach ($allLedgerChrono as $cAll) {
    $delta = cash_ledger_row_amount_delta($cAll);
    $edAll = (string) ($cAll['entry_date'] ?? '');
    if ($edAll !== '' && $edAll < $ymStart) {
        $openingBalance += $delta;
    }
    $latestBalanceAllTime += $delta;
}

$rows = [];
foreach ($allLedgerChrono as $c) {
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
    $runningBalance += cash_ledger_row_amount_delta($rAsc);
    $balanceById[$rowId] = $runningBalance;
}

foreach ($rows as &$rowRef) {
    $rowId = (int) ($rowRef['id'] ?? 0);
    $rowRef['running_balance'] = $balanceById[$rowId] ?? $openingBalance;
}
unset($rowRef);

foreach ($rowsAsc as &$rowAscRef) {
    $rowId = (int) ($rowAscRef['id'] ?? 0);
    $rowAscRef['running_balance'] = $balanceById[$rowId] ?? $openingBalance;
}
unset($rowAscRef);

usort($rows, static function (array $a, array $b): int {
    $cmp = strcmp((string) ($b['entry_date'] ?? ''), (string) ($a['entry_date'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }

    return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
});

$rowCount = count($rows);
$perPageParam = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
$perPage = in_array($perPageParam, [10, 25, 50], true) ? $perPageParam : 10;
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

$sumIncome = 0.0;
$sumExpense = 0.0;
foreach ($rows as $r) {
    if (($r['entry_type'] ?? '') === 'income') {
        $sumIncome += (float) ($r['amount'] ?? 0);
    } else {
        $sumExpense += (float) ($r['amount'] ?? 0);
    }
}
$net = $sumIncome - $sumExpense;
$periodEndBalance = $openingBalance + $net;
$formExpanded = true;
$ledgerAmountValue = '';
if ($editRow) {
    $ledgerAmountValue = rtrim(rtrim(number_format((float) ($editRow['amount'] ?? 0), 2, '.', ''), '0'), '.');
}
$periodFilterLabel = $searchDate !== ''
    ? 'วันที่ ' . date('d/m/Y', strtotime($searchDate))
    : $periodLabelTh;

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php
    require_once dirname(__DIR__, 2) . '/includes/tnc_ops_head.php';
    tnc_ops_head([
        'title' => 'สรุปรายรับรายจ่ายภายใน | THEELIN CON',
        'sweetalert' => true,
        'cash_ledger_ui' => true,
        'cash_print' => true,
        'include_ops_ui' => false,
    ]);
    ?>
</head>
<body class="tnc-app-body tnc-layout-list cash-ledger-print-page">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container pb-5 cash-ledger-shell">
    <div class="tnc-page-head no-print mb-3 flex-wrap gap-3">
        <div>
            <p class="tnc-page-kicker">สดย่อย</p>
            <h1 class="tnc-list-title ledger-hero-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-speedometer2"></i></span>รายการบันทึกสดย่อย</h1>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn ledger-cta-btn ledger-cta-secondary px-3" data-bs-toggle="modal" data-bs-target="#ledgerFilterModal">
                <i class="bi bi-funnel me-1"></i>กรองรายการ<?php if ($searchDate !== ''): ?><span class="badge rounded-pill text-bg-warning ms-1">1</span><?php endif; ?>
            </button>
            <button type="button" class="btn ledger-cta-btn ledger-cta-secondary px-3" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>พิมพ์รายงาน
            </button>
            <button type="button" class="btn ledger-cta-btn ledger-cta-primary px-3" data-bs-toggle="collapse" data-bs-target="#ledgerFormCollapse" aria-expanded="<?= $formExpanded ? 'true' : 'false' ?>" aria-controls="ledgerFormCollapse" id="toggleLedgerFormBtn">
                <i class="bi bi-cash-stack me-1"></i><?= $editRow ? 'แก้ไขรายการ' : 'เพิ่มรายการ' ?> <i class="bi <?= $formExpanded ? 'bi-chevron-up' : 'bi-chevron-down' ?> ms-1" id="toggleLedgerFormIcon"></i>
            </button>
        </div>
    </div>

    <div class="ledger-period-bar no-print">
        <span class="ledger-period-bar__label">งวดที่ดู</span>
        <span class="ledger-period-bar__chip"><?= htmlspecialchars($periodFilterLabel, ENT_QUOTES, 'UTF-8') ?></span>
        <form method="get" action="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="d-flex flex-wrap align-items-center gap-2 ms-lg-auto">
            <label class="small fw-bold text-secondary mb-0" for="ledger_inline_month">เปลี่ยนเดือน</label>
            <input type="month" name="month" id="ledger_inline_month" class="form-control form-control-sm rounded-3" style="width: auto;" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($searchDate !== ''): ?>
            <input type="hidden" name="entry_date" value="<?= htmlspecialchars($searchDate, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill">แสดง</button>
        </form>
    </div>

    <div class="no-print card card-dash mb-4" id="ledger-form-card">
        <div class="collapse<?= $formExpanded ? ' show' : '' ?>" id="ledgerFormCollapse">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
                <h5 class="fw-bold mb-0"><?= $editRow ? 'แก้ไขรายการ' : 'เพิ่มรายการ' ?></h5>
            </div>
            <form method="post" action="<?= htmlspecialchars($cashHandlerUrl, ENT_QUOTES, 'UTF-8') ?>?action=save&redirect_to=dashboard" class="row g-3" id="ledgerForm" data-tnc-fullnav="1" data-tnc-ledger-form="1">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="redirect_to" value="dashboard">
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($editRow): ?>
                    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                <?php endif; ?>

                <div class="col-md-5 col-lg-5">
                    <label class="form-label fw-bold small">รายละเอียดการจ่าย/รับ</label>
                    <input type="text" name="description" class="form-control rounded-3" maxlength="1000" required value="<?= htmlspecialchars($editRow['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-2 col-lg-2">
                    <label class="form-label fw-bold small">ประเภท</label>
                    <select name="entry_type" id="entry_type" class="form-select rounded-3" required>
                        <option value="income" <?= ($editRow['entry_type'] ?? '') === 'income' ? 'selected' : '' ?>>รายรับ</option>
                        <option value="expense" <?= ($editRow['entry_type'] ?? '') === 'expense' ? 'selected' : '' ?>>รายจ่าย</option>
                    </select>
                </div>
                <div class="col-md-2 col-lg-2">
                    <label class="form-label fw-bold small">วันที่</label>
                    <input type="date" name="entry_date" class="form-control rounded-3" required value="<?= htmlspecialchars($editRow['entry_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-3 col-lg-3">
                    <label class="form-label fw-bold small">จำนวนเงิน (บาท)</label>
                    <input type="number" name="amount" class="form-control rounded-3" required step="0.01" min="0.01" placeholder="0.00" value="<?= htmlspecialchars($ledgerAmountValue, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="col-12 ledger-form-actions">
                    <?php if ($editRow): ?>
                        <a href="<?= htmlspecialchars($dashboardUrl . '?month=' . urlencode($month), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill">ยกเลิก</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-orange rounded-pill px-4 fw-bold ledger-form-submit">
                        <i class="bi bi-check-lg me-1"></i><?= $editRow ? 'บันทึกการแก้ไข' : 'บันทึกรายการ' ?>
                    </button>
                </div>
            </form>
        </div>
        </div>
    </div>

    <div class="modal fade ledger-filter-modal no-print" id="ledgerFilterModal" tabindex="-1" aria-labelledby="ledgerFilterModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="ledgerFilterModalLabel"><i class="bi bi-funnel text-tnc-orange me-2"></i>กรองรายการ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <form method="get" action="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-body pt-2">
                        <div class="mb-3">
                            <label class="form-label fw-bold small mb-1" for="filter_month">เดือนที่ดู</label>
                            <input type="month" name="month" id="filter_month" class="form-control rounded-3 ledger-filter-input" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="mb-1">
                            <label class="form-label fw-bold small mb-1" for="filter_entry_date">ค้นหาวันที่</label>
                            <input type="date" name="entry_date" id="filter_entry_date" class="form-control rounded-3 ledger-filter-input" value="<?= htmlspecialchars($searchDate, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="form-text">เว้นว่างเพื่อดูทั้งเดือน</div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 flex-column flex-sm-row gap-2">
                        <?php if ($searchDate !== ''): ?>
                            <a href="<?= htmlspecialchars($dashboardUrl . '?month=' . urlencode($month), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-danger rounded-pill w-100 w-sm-auto order-sm-1">ล้างวันที่</a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-light rounded-pill w-100 w-sm-auto" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-orange rounded-pill w-100 w-sm-auto ledger-filter-submit">แสดงผล</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="d-none d-print-block report-print-header text-center border-bottom border-2 border-dark pb-3 mb-3">
        <h1 class="h4 fw-bold mb-1">THEELIN CON CO.,LTD.</h1>
        <h2 class="h5 fw-bold mb-2">รายงานสรุปรายรับรายจ่ายภายใน</h2>
        <p class="mb-1 fw-semibold">งวดบัญชี: <?= htmlspecialchars($periodFilterLabel, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>)</p>
        <p class="small mb-2">พิมพ์เมื่อ <?= htmlspecialchars($printedAtThai, ENT_QUOTES, 'UTF-8') ?> &nbsp;|&nbsp; ผู้พิมพ์: <?= htmlspecialchars($printedBy, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="row justify-content-center g-2 small">
            <div class="col-auto border rounded px-3 py-2 mx-1">
                <span class="fw-semibold d-block">ยอดยกมา</span>
                <span class="fw-bold d-block ledger-money">฿<?= number_format($openingBalance, 2) ?></span>
            </div>
            <div class="col-auto border rounded px-3 py-2 mx-1">
                <span class="fw-semibold d-block">รายรับในงวด</span>
                <span class="fw-bold text-success d-block ledger-money">฿<?= number_format($sumIncome, 2) ?></span>
            </div>
            <div class="col-auto border rounded px-3 py-2 mx-1">
                <span class="fw-semibold d-block">รายจ่ายในงวด</span>
                <span class="fw-bold text-danger d-block ledger-money">฿<?= number_format($sumExpense, 2) ?></span>
            </div>
            <div class="col-auto border rounded px-3 py-2 mx-1">
                <span class="fw-semibold d-block">คงเหลือปลายงวด</span>
                <span class="fw-bold d-block ledger-money <?= $periodEndBalance < 0 ? 'text-danger' : '' ?>">฿<?= number_format($periodEndBalance, 2) ?></span>
            </div>
            <div class="col-auto border rounded px-3 py-2 mx-1">
                <span class="fw-semibold d-block">จำนวนรายการ</span>
                <span class="fw-bold d-block"><?= number_format($rowCount) ?> รายการ</span>
            </div>
        </div>
        <p class="small text-muted mt-2 mb-0">คงเหลือล่าสุดในระบบ (สะสม): ฿<?= number_format($latestBalanceAllTime, 2) ?></p>
    </div>

    <div class="no-print row g-3 mb-4">
        <div class="col-md-4 col-6">
            <div class="ledger-kpi ledger-kpi--income h-100">
                <div class="ledger-kpi__label">ยอดยกมา</div>
                <div class="ledger-kpi__value">฿<?= number_format($openingBalance, 2) ?></div>
            </div>
        </div>
        <div class="col-md-4 col-6">
            <div class="ledger-kpi ledger-kpi--income h-100">
                <div class="ledger-kpi__label">รายรับในงวด</div>
                <div class="ledger-kpi__value text-success">฿<?= number_format($sumIncome, 2) ?></div>
            </div>
        </div>
        <div class="col-md-4 col-6">
            <div class="ledger-kpi ledger-kpi--expense h-100">
                <div class="ledger-kpi__label">รายจ่ายในงวด</div>
                <div class="ledger-kpi__value text-danger">฿<?= number_format($sumExpense, 2) ?></div>
            </div>
        </div>
        <div class="col-md-6 col-6">
            <div class="ledger-kpi ledger-kpi--balance h-100">
                <div class="ledger-kpi__label">คงเหลือปลายงวด</div>
                <div class="ledger-kpi__value <?= $periodEndBalance < 0 ? 'text-danger' : '' ?>">฿<?= number_format($periodEndBalance, 2) ?></div>
            </div>
        </div>
        <div class="col-md-6 col-6">
            <div class="ledger-kpi h-100">
                <div class="ledger-kpi__label">คงเหลือล่าสุดในระบบ</div>
                <div class="ledger-kpi__value <?= $latestBalanceAllTime < 0 ? 'text-danger' : '' ?>">฿<?= number_format($latestBalanceAllTime, 2) ?></div>
            </div>
        </div>
    </div>

    <div class="card card-dash">
        <div class="ledger-table-head no-print">
            <div>
                <h2 class="h6 fw-bold mb-0">รายการในงวด</h2>
                <p class="ledger-table-head__meta mb-0">แสดง <?= number_format($showFrom) ?>–<?= number_format($showTo) ?> จาก <?= number_format($rowCount) ?> รายการ</p>
            </div>
            <form method="get" action="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="d-flex align-items-center gap-2">
                <input type="hidden" name="month" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($searchDate !== ''): ?>
                <input type="hidden" name="entry_date" value="<?= htmlspecialchars($searchDate, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                <label class="small fw-bold text-secondary mb-0" for="ledger_per_page">แสดง</label>
                <select name="per_page" id="ledger_per_page" class="form-select form-select-sm rounded-3" style="width: auto;" onchange="this.form.submit()">
                    <?php foreach ([10, 25, 50] as $perPageOpt): ?>
                    <option value="<?= $perPageOpt ?>"<?= $perPage === $perPageOpt ? ' selected' : '' ?>><?= $perPageOpt ?> รายการ</option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-wrap-screen px-0">
                <table class="table table-hover align-middle mb-0 table-cash-report">
                    <colgroup>
                        <col class="ledger-col-date">
                        <col class="ledger-col-desc">
                        <col class="ledger-col-in">
                        <col class="ledger-col-out">
                        <col class="ledger-col-balance">
                        <col class="ledger-col-action no-print">
                    </colgroup>
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
                            <tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีรายการในงวดนี้</td></tr>
                        <?php else: ?>
                            <tr class="ledger-opening-row">
                                <td class="ledger-cell-date text-secondary small text-nowrap ps-3">—</td>
                                <td class="ledger-cell-desc col-desc fw-semibold">ยอดยกมา</td>
                                <td class="ledger-cell-in ledger-cell-empty ledger-money small text-end"></td>
                                <td class="ledger-cell-out ledger-cell-empty ledger-money small text-end"></td>
                                <td class="ledger-cell-balance ledger-money small text-end fw-bold text-nowrap <?= $openingBalance < 0 ? 'text-danger' : 'text-dark' ?>">
                                    <?= number_format($openingBalance, 2) ?>
                                </td>
                                <td class="ledger-cell-actions pe-3 text-center no-print"></td>
                            </tr>
                            <?php foreach ($pagedRows as $row):
                                $lid = (int) $row['id'];
                                $canManage = $isAdmin || (int) ($row['created_by'] ?? 0) === $me;
                                $memo = trim((string) ($row['description'] ?? ''));
                                $editQuery = ['month' => $month, 'page' => $page, 'per_page' => $perPage, 'edit' => $lid];
                                if ($searchDate !== '') {
                                    $editQuery['entry_date'] = $searchDate;
                                }
                                $editUrl = $dashboardUrl . '?' . http_build_query($editQuery);
                                ?>
                                <tr class="ledger-entry-row ledger-screen-row">
                                    <td class="ledger-cell-date text-secondary small text-nowrap ps-3"><?= date('d/m/Y', strtotime($row['entry_date'])) ?></td>
                                    <td class="ledger-cell-desc col-desc"><?= nl2br(htmlspecialchars($memo !== '' ? $memo : '—', ENT_QUOTES, 'UTF-8')) ?></td>
                                    <td class="ledger-cell-in ledger-money small text-end fw-bold text-success text-nowrap<?= $row['entry_type'] === 'income' ? '' : ' ledger-cell-empty' ?>"><?= $row['entry_type'] === 'income' ? number_format((float) $row['amount'], 2) : '' ?></td>
                                    <td class="ledger-cell-out ledger-money small text-end fw-bold text-danger text-nowrap<?= $row['entry_type'] === 'expense' ? '' : ' ledger-cell-empty' ?>"><?= $row['entry_type'] === 'expense' ? number_format((float) $row['amount'], 2) : '' ?></td>
                                    <td class="ledger-cell-balance ledger-money small text-end fw-semibold text-nowrap <?= ((float) ($row['running_balance'] ?? 0)) < 0 ? 'text-danger' : 'text-dark' ?>">
                                        <?= number_format((float) ($row['running_balance'] ?? 0), 2) ?>
                                    </td>
                                    <td class="ledger-cell-actions pe-3 text-center no-print">
                                        <?php if ($canManage): ?>
                                            <a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-warning" title="แก้ไข" aria-label="แก้ไขรายการ <?= htmlspecialchars($memo !== '' ? $memo : (string) $lid, ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                            </a>
                                            <a href="<?= htmlspecialchars($cashHandlerUrl . '?action=delete&redirect_to=dashboard&id=' . $lid . '&month=' . urlencode($month) . $csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-danger tnc-delete-post" title="ลบ (ต้องใส่รหัสผ่าน)" aria-label="ลบรายการ <?= htmlspecialchars($memo !== '' ? $memo : (string) $lid, ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-trash-fill" aria-hidden="true"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($rowsAsc as $row):
                                $memo = trim((string) ($row['description'] ?? ''));
                                ?>
                                <tr class="ledger-entry-row ledger-print-chrono-row d-none d-print-table-row">
                                    <td class="ledger-cell-date text-secondary small text-nowrap ps-3"><?= date('d/m/Y', strtotime($row['entry_date'])) ?></td>
                                    <td class="ledger-cell-desc col-desc"><?= nl2br(htmlspecialchars($memo !== '' ? $memo : '—', ENT_QUOTES, 'UTF-8')) ?></td>
                                    <td class="ledger-cell-in ledger-money small text-end fw-bold text-success text-nowrap<?= $row['entry_type'] === 'income' ? '' : ' ledger-cell-empty' ?>"><?= $row['entry_type'] === 'income' ? number_format((float) $row['amount'], 2) : '' ?></td>
                                    <td class="ledger-cell-out ledger-money small text-end fw-bold text-danger text-nowrap<?= $row['entry_type'] === 'expense' ? '' : ' ledger-cell-empty' ?>"><?= $row['entry_type'] === 'expense' ? number_format((float) $row['amount'], 2) : '' ?></td>
                                    <td class="ledger-cell-balance ledger-money small text-end fw-semibold text-nowrap <?= ((float) ($row['running_balance'] ?? 0)) < 0 ? 'text-danger' : 'text-dark' ?>">
                                        <?= number_format((float) ($row['running_balance'] ?? 0), 2) ?>
                                    </td>
                                    <td class="ledger-cell-actions pe-3 text-center no-print"></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($rowCount > 0): ?>
            <div class="cash-report-final-summary d-none d-print-block px-3 py-3">
                <h6 class="fw-bold mb-2">สรุปงวด <?= htmlspecialchars($periodFilterLabel, ENT_QUOTES, 'UTF-8') ?></h6>
                <table class="table table-sm table-bordered mb-3">
                    <tbody>
                        <tr>
                            <td>ยอดยกมา</td>
                            <td class="text-end fw-bold ledger-money">฿<?= number_format($openingBalance, 2) ?></td>
                        </tr>
                        <tr>
                            <td>รายรับในงวด</td>
                            <td class="text-end fw-bold text-success ledger-money">฿<?= number_format($sumIncome, 2) ?></td>
                        </tr>
                        <tr>
                            <td>รายจ่ายในงวด</td>
                            <td class="text-end fw-bold text-danger ledger-money">฿<?= number_format($sumExpense, 2) ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">คงเหลือปลายงวด</td>
                            <td class="text-end fw-bold ledger-money <?= $periodEndBalance < 0 ? 'text-danger' : '' ?>">฿<?= number_format($periodEndBalance, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
                <div class="ledger-print-sign row g-3 small">
                    <div class="col-4 text-center">
                        <div class="ledger-print-sign__line"></div>
                        <div>ผู้จัดทำรายงาน</div>
                    </div>
                    <div class="col-4 text-center">
                        <div class="ledger-print-sign__line"></div>
                        <div>ผู้ตรวจสอบ</div>
                    </div>
                    <div class="col-4 text-center">
                        <div class="ledger-print-sign__line"></div>
                        <div>ผู้อนุมัติ</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($totalPages > 1): ?>
                <div class="no-print d-flex justify-content-between align-items-center px-3 py-3 border-top bg-white">
                    <div class="small text-muted">หน้า <?= number_format($page) ?> / <?= number_format($totalPages) ?></div>
                    <div class="d-flex gap-2">
                        <?php
                        $prevPage = $page - 1;
                        $nextPage = $page + 1;
                        $pageQuery = ['month' => $month, 'per_page' => $perPage];
                        if ($searchDate !== '') {
                            $pageQuery['entry_date'] = $searchDate;
                        }
                        $prevUrl = $dashboardUrl . '?' . http_build_query(array_merge($pageQuery, ['page' => $prevPage]));
                        $nextUrl = $dashboardUrl . '?' . http_build_query(array_merge($pageQuery, ['page' => $nextPage]));
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
                            <a href="<?= htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-orange rounded-pill">
                                ดูถัดไป<i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm btn-orange rounded-pill" disabled>
                                ดูถัดไป<i class="bi bi-arrow-right ms-1"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<script>
const params = new URLSearchParams(window.location.search);
if (params.get('saved') === '1') {
    Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', confirmButtonColor: '#ea580c' });
}
if (params.get('deleted') === '1') {
    Swal.fire({ icon: 'success', title: 'ลบแล้ว', confirmButtonColor: '#ea580c' });
}
if (params.get('err')) {
    const map = {
        amount: 'จำนวนเงินต้องมากกว่า 0',
        need_lines: 'กรุณากรอกรายละเอียดและจำนวนเงิน',
        line_total: 'จำนวนเงินรวมต้องมากกว่า 0',
        save_failed: 'บันทึกไม่สำเร็จ ลองใหม่อีกครั้ง',
        invalid_type: 'ประเภทไม่ถูกต้อง',
        date: 'วันที่ไม่ถูกต้อง',
        notfound: 'ไม่พบรายการที่ต้องการ',
        forbidden: 'คุณไม่มีสิทธิ์จัดการรายการนี้',
        csrf: 'เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง',
    };
    Swal.fire({ icon: 'error', title: 'ไม่สามารถดำเนินการได้', text: map[params.get('err')] || params.get('err'), confirmButtonColor: '#ea580c' });
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
<?php
$cashLedgerFormJsPath = dirname(__DIR__, 2) . '/assets/js/cash-ledger-form.js';
$cashLedgerFormJsVer = @filemtime($cashLedgerFormJsPath);
if (!is_int($cashLedgerFormJsVer) || $cashLedgerFormJsVer <= 0) {
    $cashLedgerFormJsVer = time();
}
?>
<script src="<?= htmlspecialchars(app_path('assets/js/cash-ledger-form.js') . '?v=' . $cashLedgerFormJsVer, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
