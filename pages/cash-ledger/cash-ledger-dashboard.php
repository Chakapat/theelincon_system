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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สรุปรายรับรายจ่ายภายใน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/cash-ledger-print.css') . '?v=' . (@filemtime(dirname(__DIR__, 2) . '/assets/css/cash-ledger-print.css') ?: time()), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }
        .cash-ledger-shell { max-width: 1360px; }
        .card-dash { border-radius: 14px; border: 1px solid #e2e8f0; background: #fff; }
        .ledger-kpi {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            padding: 0.85rem 1rem;
        }
        .ledger-kpi--income { background: #f0fdf4; border-color: #bbf7d0; }
        .ledger-kpi--expense { background: #fef2f2; border-color: #fecaca; }
        .ledger-kpi--balance { background: #fff7ed; border-color: #fed7aa; }
        .ledger-kpi__label { font-size: 0.75rem; font-weight: 700; color: #64748b; margin-bottom: 0.15rem; }
        .ledger-kpi__value {
            font-size: 1.15rem;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            color: #0f172a;
            line-height: 1.2;
        }
        .ledger-period-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.65rem 1rem;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            border: 1px solid #fed7aa;
            border-radius: 12px;
            background: #fff7ed;
        }
        .ledger-period-bar__label { font-size: 0.8125rem; font-weight: 700; color: #9a3412; }
        .ledger-period-bar__chip {
            font-size: 0.9375rem;
            font-weight: 800;
            color: #0f172a;
        }
        .ledger-table-head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem 1rem;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .ledger-table-head__meta { font-size: 0.8125rem; color: #64748b; }
        .table-cash-report thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            box-shadow: 0 1px 0 #fdba74;
        }
        .table-cash-report .ledger-money {
            font-variant-numeric: tabular-nums;
        }
        .ledger-opening-row td {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }
        .ledger-opening-row .ledger-cell-desc {
            font-weight: 700;
            color: #334155;
        }
        .ledger-hero-title { letter-spacing: 0.01em; }
        .ledger-subtitle { color: #6c757d; font-size: 0.93rem; margin-bottom: 0; }
        .ledger-cta-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            border-radius: 999px;
            font-weight: 700;
            letter-spacing: .01em;
            border: 1px solid transparent;
            transition: transform .15s ease, box-shadow .15s ease, filter .15s ease;
        }
        .ledger-cta-btn:hover { transform: translateY(-1px); text-decoration: none; }
        .ledger-cta-primary {
            color: #fff;
            background: linear-gradient(135deg, #ea580c 0%, #c2410c 100%);
            box-shadow: 0 .38rem .9rem rgba(253,126,20,.33);
        }
        .ledger-cta-primary:hover { color:#fff; filter: brightness(1.03); }
        .ledger-cta-secondary {
            color: #1f2937;
            background: #fff;
            border-color: rgba(0,0,0,.16);
            box-shadow: 0 .22rem .62rem rgba(0,0,0,.08);
        }
        .ledger-cta-secondary:hover { color:#111827; border-color: rgba(0,0,0,.22); }
        .ledger-filter-input { width: 100%; }
        #ledgerFilterModal .modal-content { border: 0; box-shadow: 0 1rem 2.5rem rgba(15, 23, 42, 0.14); }
        #ledgerForm .ledger-form-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 0.5rem;
        }
        .table-cash-report { table-layout: fixed; width: 100%; }
        .table-cash-report th,
        .table-cash-report td { padding-left: .45rem; padding-right: .45rem; }
        .table-cash-report .col-date { width: 104px; }
        .table-cash-report .col-desc { width: 18%; }
        .table-cash-report .col-in,
        .table-cash-report .col-out,
        .table-cash-report .col-balance { width: 126px; }
        .table-cash-report .col-action { width: 76px; }
        .table-cash-report tbody tr {
            transition: background-color .16s ease, box-shadow .16s ease;
        }
        .table-cash-report tbody tr:hover {
            background: #fff9f2;
            box-shadow: inset 0 0 0 1px rgba(253,126,20,.12);
        }
        .table-cash-report tbody td.no-print .btn {
            min-width: 2.05rem;
            border-radius: .55rem;
        }

        /* Mobile: card list instead of stacked label/value rows (screen only — ไม่กระทบ print) */
        @media screen and (max-width: 767.98px) {
            .container.pb-5 {
                padding-left: 0.75rem;
                padding-right: 0.75rem;
            }
            .tnc-page-head.no-print {
                flex-direction: column;
                align-items: stretch !important;
            }
            .tnc-page-head.no-print .d-flex.flex-wrap.gap-2 {
                display: grid !important;
                grid-template-columns: 1fr 1fr;
                gap: 0.5rem !important;
            }
            .ledger-cta-btn { width: 100%; min-height: 2.75rem; }
            .ledger-hero-title { font-size: 1.05rem; line-height: 1.35; }
            #ledger-form-card .card-body {
                padding: 1rem !important;
            }
            #ledgerForm .col-md-2,
            #ledgerForm .col-md-3,
            #ledgerForm .col-md-4,
            #ledgerForm .col-md-5,
            #ledgerForm .col-lg-2,
            #ledgerForm .col-lg-3,
            #ledgerForm .col-lg-5 {
                width: 100%;
            }
            #ledgerForm .ledger-form-actions {
                display: flex !important;
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }
            #ledgerForm .ledger-form-submit,
            #ledgerForm .ledger-form-actions .btn {
                width: 100%;
                min-height: 2.75rem;
            }
            .tnc-page-head.no-print .d-flex.flex-wrap.gap-2 {
                display: grid !important;
                grid-template-columns: 1fr 1fr;
                gap: 0.5rem !important;
            }
            .tnc-page-head.no-print .ledger-filter-open-btn {
                grid-column: 1 / -1;
            }
            .no-print.row.g-4.mb-4 {
                --bs-gutter-x: 0.65rem;
            }
            .no-print.row.g-4.mb-4 > .col-md-4 {
                flex: 0 0 auto;
                width: 50%;
            }
            .no-print.row.g-4.mb-4 > .col-md-4:last-child,
            .no-print.row.g-4.mb-4 > .col-md-6 {
                width: 100%;
            }
            .no-print.row.g-4.mb-4 > [class*="col-"] .ledger-kpi {
                padding: 0.75rem !important;
            }
            .card-dash > .card-header {
                padding: 0.85rem 1rem !important;
            }
            .table-wrap-screen {
                padding: 0.35rem 0.55rem 0.75rem !important;
            }
            .table-cash-report thead {
                display: none;
            }
            .table-cash-report tbody tr.ledger-entry-row {
                display: grid;
                grid-template-columns: 1fr auto;
                grid-template-areas:
                    "date amount"
                    "desc desc"
                    "balance actions";
                gap: 0.15rem 0.65rem;
                margin: 0.55rem 0;
                padding: 0.75rem 0.85rem;
                border: 1px solid rgba(0, 0, 0, 0.09);
                border-radius: 0.85rem;
                background: #fff;
                box-shadow: 0 0.12rem 0.55rem rgba(0, 0, 0, 0.05);
            }
            .table-cash-report tbody tr.ledger-entry-row:hover {
                background: #fffbf7;
                box-shadow: 0 0.18rem 0.65rem rgba(234, 88, 12, 0.12);
            }
            .table-cash-report tbody td {
                display: block;
                border: 0;
                padding: 0 !important;
                text-align: left !important;
                min-width: 0;
            }
            .table-cash-report tbody td::before {
                content: none;
                display: none;
            }
            .table-cash-report .ledger-cell-date {
                grid-area: date;
                align-self: center;
                font-size: 0.82rem;
                font-weight: 700;
                color: #64748b;
                padding-left: 0 !important;
            }
            .table-cash-report .ledger-cell-desc {
                grid-area: desc;
                grid-column: 1 / -1;
                width: 100%;
                font-size: 1.05rem;
                font-weight: 600;
                color: #1e293b;
                line-height: 1.55;
                white-space: normal !important;
                word-break: normal !important;
                overflow-wrap: break-word !important;
                padding: 0.85rem 0.95rem !important;
                margin: 0.4rem 0 0.15rem;
                min-height: 3.75rem;
                background: #f8fafc;
                border: 1px solid rgba(148, 163, 184, 0.32);
                border-radius: 0.7rem;
                box-sizing: border-box;
            }
            .table-cash-report .ledger-cell-in.ledger-cell-empty,
            .table-cash-report .ledger-cell-out.ledger-cell-empty {
                display: none;
            }
            .table-cash-report .ledger-cell-in:not(.ledger-cell-empty),
            .table-cash-report .ledger-cell-out:not(.ledger-cell-empty) {
                grid-area: amount;
                justify-self: end;
                align-self: start;
                font-size: 1.02rem;
                font-weight: 800;
                padding: 0.2rem 0.55rem !important;
                border-radius: 999px;
                line-height: 1.2;
            }
            .table-cash-report .ledger-cell-in:not(.ledger-cell-empty) {
                color: #15803d;
                background: rgba(25, 135, 84, 0.12);
            }
            .table-cash-report .ledger-cell-out:not(.ledger-cell-empty) {
                color: #b42318;
                background: rgba(220, 53, 69, 0.1);
            }
            .table-cash-report .ledger-cell-balance {
                grid-area: balance;
                align-self: center;
                margin-top: 0.45rem;
                padding-top: 0.55rem !important;
                border-top: 1px dashed rgba(0, 0, 0, 0.08);
                font-size: 0.92rem;
            }
            .table-cash-report .ledger-cell-balance::before {
                content: "คงเหลือ ";
                display: inline;
                font-size: 0.74rem;
                font-weight: 700;
                color: #94a3b8;
                letter-spacing: 0.02em;
            }
            .table-cash-report .ledger-cell-actions {
                grid-area: actions;
                justify-self: end;
                align-self: center;
                margin-top: 0.45rem;
                padding-top: 0.55rem !important;
                border-top: 1px dashed rgba(0, 0, 0, 0.08);
                text-align: right !important;
            }
            .table-cash-report .ledger-cell-actions .btn {
                min-width: 2.5rem;
                min-height: 2.5rem;
                border-radius: 0.65rem;
            }
            .table-cash-report tbody td[colspan] {
                display: block;
                grid-column: 1 / -1;
                text-align: center !important;
                padding: 1.25rem 0.5rem !important;
            }
            .no-print.d-flex.justify-content-between.align-items-center.px-3.py-3.border-top.bg-white {
                flex-direction: column;
                align-items: stretch !important;
                gap: 0.65rem;
            }
            .no-print.d-flex.justify-content-between.align-items-center.px-3.py-3.border-top.bg-white .d-flex.gap-2 {
                display: grid !important;
                grid-template-columns: 1fr 1fr;
                gap: 0.45rem !important;
            }
            .no-print.d-flex.justify-content-between.align-items-center.px-3.py-3.border-top.bg-white .d-flex.gap-2 .btn {
                width: 100%;
                min-height: 2.65rem;
            }
            .cash-report-final-summary table td {
                font-size: 0.88rem;
            }
        }

    </style>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
