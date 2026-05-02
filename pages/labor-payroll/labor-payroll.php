<?php

declare(strict_types=1);


session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

use Theelincon\Rtdb\Db;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$handler = app_path('actions/labor-payroll-handler.php');

$ym = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$half = (int) ($_GET['half'] ?? 1) === 2 ? 2 : 1;

$ts = strtotime($ym . '-01');
if ($ts === false) {
    $ym = date('Y-m');
    $ts = strtotime($ym . '-01');
}
$dim = (int) date('t', $ts);
$startD = $half === 1 ? 1 : 16;
$endD = $half === 1 ? min(15, $dim) : $dim;
$daysList = range($startD, $endD);
$periodDaysMax = count($daysList);

/** snapshot ครั้งเดียว — ลดการสแกนซ้ำ / เรียกค้องานแบบ O(n²) */
$workersKeyed = Db::tableKeyed('labor_workers');
$workerByWid = [];
foreach ($workersKeyed as $pk => $w) {
    if (!is_array($w) || empty($w['is_active'])) {
        continue;
    }
    $wid = (int) ($w['id'] ?? $pk);
    if ($wid > 0) {
        $workerByWid[$wid] = $w;
    }
}

$monthByWid = [];
foreach (Db::tableRows('labor_worker_month_settings') as $ms) {
    if (!is_array($ms) || (string) ($ms['year_month'] ?? '') !== $ym) {
        continue;
    }
    $mwid = (int) ($ms['worker_id'] ?? 0);
    if ($mwid > 0) {
        $monthByWid[$mwid] = $ms;
    }
}

$sheetRows = [];
$sheetLinks = Db::filter('labor_month_sheet_workers', static fn ($r) => (string) ($r['year_month'] ?? '') === $ym);
/** labor_month_sheet_workers.sort_order ต่อคน — ใช้เรียงกลุ่มตอนดึงให้ตรงหน้าจัดการคนงาน */
$sheetSortByWorkerId = [];
foreach ($sheetLinks as $r) {
    $swid = (int) ($r['worker_id'] ?? 0);
    if ($swid > 0) {
        $sheetSortByWorkerId[$swid] = (int) ($r['sort_order'] ?? 0);
    }
}
usort(
    $sheetLinks,
    static function ($a, $b): int {
        $sa = (int) ($a['sort_order'] ?? 0);
        $sb = (int) ($b['sort_order'] ?? 0);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }
        $wa = (int) ($a['worker_id'] ?? 0);
        $wb = (int) ($b['worker_id'] ?? 0);

        return $wa <=> $wb;
    }
);
foreach ($sheetLinks as $s) {
    $wid = (int) ($s['worker_id'] ?? 0);
    if ($wid <= 0) {
        continue;
    }
    $w = $workerByWid[$wid] ?? null;
    if ($w === null) {
        continue;
    }
    $ms = $monthByWid[$wid] ?? null;
    $sheetRows[] = [
        'id' => $wid,
        'full_name' => (string) ($w['full_name'] ?? ''),
        'sort_order' => (int) ($s['sort_order'] ?? 0),
        'daily_wage' => $ms ? (float) ($ms['daily_wage'] ?? 0) : 0.0,
        'advance_draw' => $ms ? (float) ($ms['advance_draw'] ?? 0) : 0.0,
    ];
}

if (!empty($_GET['reset'])) {
    $sheetRows = [];
}

$attByWorker = [];
if (count($sheetRows) > 0) {
    $ids = [];
    foreach ($sheetRows as $sr) {
        $iw = (int) ($sr['id'] ?? 0);
        if ($iw > 0) {
            $ids[$iw] = true;
        }
    }
    if (count($ids) > 0) {
        $from = $ym . '-01';
        $to = $ym . '-' . str_pad((string) $dim, 2, '0', STR_PAD_LEFT);
        foreach (Db::tableRows('labor_attendance_days') as $row) {
            if (!is_array($row)) {
                continue;
            }
            $wid = (int) ($row['worker_id'] ?? 0);
            if ($wid <= 0 || !isset($ids[$wid])) {
                continue;
            }
            $wd = (string) ($row['work_date'] ?? '');
            if ($wd < $from || $wd > $to) {
                continue;
            }
            $d = (int) substr($wd, -2);
            if (!isset($attByWorker[$wid])) {
                $attByWorker[$wid] = [];
            }
            $attByWorker[$wid][$d] = [
                'p' => (int) ($row['is_present'] ?? 0) === 1,
                'ot' => (float) ($row['ot_hours'] ?? 0),
            ];
        }
    }
}

if (!empty($_GET['reset'])) {
    $attByWorker = [];
}

$allWorkers = array_values($workerByWid);
usort($allWorkers, static fn (array $a, array $b): int => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0)));
$workerGroups = Db::filter('labor_worker_groups', static fn ($g) => !empty($g['is_active']));
Db::sortRows($workerGroups, 'name');
$groupNameById = [];
foreach ($workerGroups as $g) {
    $gid = (int) ($g['id'] ?? 0);
    if ($gid > 0) {
        $groupNameById[$gid] = trim((string) ($g['name'] ?? ''));
    }
}
$workersByGroup = [];
foreach ($workerByWid as $w) {
    $gid = (int) ($w['group_id'] ?? 0);
    if ($gid <= 0) {
        continue;
    }
    if (!isset($workersByGroup[$gid])) {
        $workersByGroup[$gid] = [];
    }
    $workersByGroup[$gid][] = [
        'id' => (int) ($w['id'] ?? 0),
        'full_name' => trim((string) ($w['full_name'] ?? '')),
        'daily_wage' => (float) ($w['default_daily_wage'] ?? 0),
    ];
}
$missingSheetRank = 1_000_000;
foreach ($workersByGroup as &$wg) {
    usort(
        $wg,
        static function (array $a, array $b) use ($sheetSortByWorkerId, $missingSheetRank): int {
            $ida = (int) ($a['id'] ?? 0);
            $idb = (int) ($b['id'] ?? 0);
            $sa = $sheetSortByWorkerId[$ida] ?? ($missingSheetRank + $ida);
            $sb = $sheetSortByWorkerId[$idb] ?? ($missingSheetRank + $idb);
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            return $ida <=> $idb;
        }
    );
}
unset($wg);

$halfLabel = $half === 1 ? 'วันที่ 1–15' : ('วันที่ 16–' . $dim);
$periodNoteRow = Db::row('labor_payroll_period_notes', Db::compositeKey([$ym, (string) $half]));
$periodNote = is_array($periodNoteRow) ? trim((string) ($periodNoteRow['note'] ?? '')) : '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>คำนวณค่าแรงคนงาน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --lp-bg: #eef2f7;
            --lp-card: #ffffff;
            --lp-meta-bg: #f0f4f8;
            --lp-meta-border: #d8e0ea;
            --lp-day-alt: #f7f9fc;
            --lp-weekend: #fff0f3;
            --lp-weekend-border: #f5d0d8;
            --lp-hover: #e8f2ff;
            --lp-accent: #1e5a8e;
            --lp-net-bg: #f0fdf4;
            --lp-focus: rgba(30, 90, 142, 0.35);
        }
        body { font-family: 'Sarabun', sans-serif; background: var(--lp-bg); font-size: 15px; }
        .punch-card {
            border-radius: 16px;
            border: 1px solid var(--lp-meta-border);
            box-shadow: 0 8px 28px rgba(15, 23, 42, 0.06);
            background: var(--lp-card);
        }
        .table-labor-wrap {
            border-radius: 12px;
            overflow: auto;
            max-height: min(72vh, 920px);
            border: 1px solid var(--lp-meta-border);
            background: var(--lp-card);
        }
        .table-labor {
            font-size: 0.9375rem;
            border-collapse: separate;
            border-spacing: 0;
        }
        .table-labor thead th {
            position: sticky;
            top: 0;
            z-index: 4;
            vertical-align: middle;
            padding: 0.45rem 0.35rem;
            border-bottom: 2px solid #94a3b8 !important;
            background: #e2e8f0 !important;
            font-weight: 700;
            color: #1e293b;
            box-shadow: 0 1px 0 #cbd5e1;
        }
        .table-labor thead .sticky-worker { position: sticky; left: 0; z-index: 6; box-shadow: 4px 0 12px rgba(15,23,42,0.08); }
        .table-labor thead .sticky-sum { position: sticky; right: 0; z-index: 6; box-shadow: -4px 0 12px rgba(15,23,42,0.08); }
        .th-sub { display: block; font-weight: 500; font-size: 0.72rem; color: #64748b; margin-top: 0.15rem; line-height: 1.2; }
        .col-meta-head { background: #dce7f3 !important; border-right: 1px solid var(--lp-meta-border) !important; }
        .table-labor tbody td {
            vertical-align: middle;
            padding: 0.35rem 0.3rem;
            border-color: #e2e8f0 !important;
        }
        .table-labor tbody .col-meta {
            background: var(--lp-meta-bg);
            border-right: 1px solid var(--lp-meta-border) !important;
        }
        .table-labor tbody tr.labor-row:nth-child(even) { background: #fafbfe; }
        .table-labor tbody tr.labor-row:nth-child(even) .col-meta { background: #e8eef5; }
        .table-labor tbody tr.labor-row:hover { background: var(--lp-hover) !important; }
        .table-labor tbody tr.labor-row:hover .col-meta { background: #ddeaf9 !important; }
        .table-labor tbody tr.labor-row:hover .sticky-worker { background: var(--lp-hover) !important; }
        .table-labor tbody tr.labor-row:hover .sticky-sum,
        .table-labor tbody tr.labor-row:hover .col-net { background: #dcf5e6 !important; }
        .sticky-worker {
            position: sticky;
            left: 0;
            z-index: 3;
            background: var(--lp-meta-bg);
            box-shadow: 4px 0 10px rgba(15,23,42,0.06);
            min-width: 11rem;
        }
        .table-labor tbody tr.labor-row:nth-child(even) .sticky-worker { background: #e8eef5; }
        .sticky-sum, .col-net {
            position: sticky;
            right: 0;
            z-index: 3;
            background: var(--lp-net-bg);
            box-shadow: -4px 0 10px rgba(15,23,42,0.06);
        }
        .table-labor tbody tr.labor-row:nth-child(even) .sticky-sum,
        .table-labor tbody tr.labor-row:nth-child(even) .col-net { background: #e6f9ec; }
        .col-net-pad { padding-right: 0.5rem !important; }
        .table-labor .form-control-sm {
            font-size: 0.9rem;
            padding: 0.32rem 0.45rem;
            border-radius: 8px;
        }
        .daily-input { max-width: 6.25rem; margin: 0 auto; }
        .inp-days-present { max-width: 5rem; margin: 0 auto; }
        .inp-ot-total { max-width: 5.5rem; margin: 0 auto; }
        .advance-input { max-width: 6.75rem; margin: 0 auto; }
        .worker-search { font-weight: 600; color: #0f172a; }
        .td-gross {
            font-size: 0.88rem;
            color: #334155;
            white-space: nowrap;
            padding-left: 0.35rem !important;
            padding-right: 0.35rem !important;
        }
        .td-net { font-variant-numeric: tabular-nums; color: #166534; }
        .table-labor tfoot th {
            background: linear-gradient(180deg, #fef9e8 0%, #fdecbd 100%) !important;
            border-top: 2px solid #eab308 !important;
            font-weight: 700;
            color: #713f12;
            padding: 0.55rem 0.45rem !important;
        }
        .hint-advance { font-size: 0.75rem; color: #6c757d; }
        .group-load-wrap {
            background: var(--lp-card);
            border: 1px solid var(--lp-meta-border);
            border-radius: 12px;
            padding: 8px 12px;
            box-shadow: 0 2px 8px rgba(15,23,42,0.04);
        }
        .group-load-select { min-width: 210px; }
        .quick-actions-col { display: flex; flex-direction: column; gap: 6px; }
        .period-pill .nav-link { font-weight: 600; }
        .period-pill .nav-link.active { background: var(--lp-accent) !important; }
        @media (max-width: 991px) {
            .table-labor-wrap { max-height: none; }
        }
        /* แป้นตัวเลข popup — วันมา / OT รวม */
        .lp-numpad-overlay {
            position: fixed;
            inset: 0;
            z-index: 1080;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(4px);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }
        .lp-numpad-overlay.is-open {
            opacity: 1;
            visibility: visible;
        }
        .lp-numpad-dialog {
            width: 100%;
            max-width: 340px;
            max-height: min(92vh, 640px);
            display: flex;
            flex-direction: column;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 24px 48px rgba(15, 23, 42, 0.18);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        .lp-numpad-head {
            padding: 1rem 1.1rem 0.65rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .lp-numpad-title {
            font-weight: 700;
            font-size: 1.05rem;
            color: #0f172a;
            margin: 0;
        }
        .lp-numpad-preview {
            margin-top: 0.5rem;
            font-size: 1.75rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            color: var(--lp-accent);
            text-align: center;
            padding: 0.35rem 0;
        }
        .lp-numpad-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 0.65rem 0.85rem 0.35rem;
            -webkit-overflow-scrolling: touch;
        }
        .lp-numpad-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }
        .lp-numpad-num {
            border: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 12px;
            padding: 0.55rem 0.25rem;
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            transition: background 0.12s ease, border-color 0.12s ease;
        }
        .lp-numpad-num:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        .lp-numpad-num:active {
            background: #e2e8f0;
        }
        .lp-numpad-frac-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            padding: 0.65rem 1rem 0.5rem;
            border-top: 1px solid #f1f5f9;
        }
        .lp-numpad-frac {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            border: 1px solid #e2e8f0;
            background: #fff;
            font-size: 0.8rem;
            font-weight: 700;
            color: #334155;
        }
        .lp-numpad-frac:hover { background: #f8fafc; }
        .lp-numpad-clear {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            border: 1px solid #fecaca;
            background: #fff;
            font-size: 0.8rem;
            font-weight: 700;
            color: #dc2626;
        }
        .lp-numpad-clear:hover { background: #fef2f2; }
        .lp-numpad-foot {
            display: flex;
            justify-content: flex-end;
            padding: 0.65rem 1rem 1rem;
            gap: 8px;
        }
        .lp-numpad-foot .btn-close-pad {
            border-radius: 12px;
            padding: 0.45rem 1.25rem;
            font-weight: 600;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #334155;
        }
        .lp-numpad-foot .btn-close-pad:hover { background: #f1f5f9; }
        .inp-days-present,
        .inp-ot-total {
            cursor: pointer;
        }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container-fluid px-2 px-md-3 pb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2 mt-2">
        <div>
            <h5 class="fw-bold mb-0"><i class="bi bi-person-workspace text-primary me-2"></i>บัตรค่าแรงคนงาน</h5>
            <div class="text-muted small">บันทึกวันทำงาน/OT รายรอบ</div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="group-load-wrap d-flex flex-wrap align-items-center gap-2">
                <select class="form-select form-select-sm group-load-select" id="bulkGroupId">
                    <option value="">เลือกกลุ่มคนงาน</option>
                    <?php foreach ($workerGroups as $g): ?>
                        <?php $gid = (int) ($g['id'] ?? 0); ?>
                        <option value="<?= $gid ?>"><?= htmlspecialchars((string) ($g['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm btn-success rounded-3 px-3" id="btnLoadGroupWorkers"><i class="bi bi-download me-1"></i>ดึงข้อมูลคนงาน</button>
            </div>
            <div class="quick-actions-col">
                <a href="<?= htmlspecialchars(app_path('pages/labor-payroll/labor-worker-manage.php') . '?month=' . urlencode($ym) . '&half=' . (int) $half, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-dark rounded-3 px-3"><i class="bi bi-plus-lg me-1"></i>เพิ่มคนงาน</a>
                <a href="<?= htmlspecialchars(app_path('pages/labor-payroll/labor-payroll-history.php') . '?month=' . urlencode($ym) . '&half=' . (int) $half, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-primary rounded-3 px-3"><i class="bi bi-clock-history me-1"></i>ประวัติรายการ</a>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <?php
        $laborPeriodYm = $ym;
        $laborPeriodHalf = $half;
        $laborPeriodAction = app_path('pages/labor-payroll/labor-payroll.php');
        $laborPeriodPreserve = [];
        $laborPeriodInputId = 'laborPayrollMonth';
        include dirname(__DIR__, 2) . '/components/labor-period-selector.php';
        ?>
        <ul class="nav nav-pills gap-2 period-pill mb-0">
            <li class="nav-item">
                <a class="nav-link <?= $half === 1 ? 'active' : '' ?> rounded-pill py-2 px-4" href="<?= htmlspecialchars(app_path('pages/labor-payroll/labor-payroll.php') . '?month=' . urlencode($ym) . '&half=1') ?>">งวด 1–15</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $half === 2 ? 'active' : '' ?> rounded-pill py-2 px-4" href="<?= htmlspecialchars(app_path('pages/labor-payroll/labor-payroll.php') . '?month=' . urlencode($ym) . '&half=2') ?>">งวด 16–<?= (int) $dim ?></a>
            </li>
        </ul>
    </div>

    <div class="punch-card p-2 p-md-3 mb-3">
        <?php if (!empty($_GET['draft_saved'])): ?>
            <div class="alert alert-success py-2 rounded-3 small mb-3">บันทึกร่างแล้ว — วันมา / OT และหมายเหตุถูกเก็บไว้ สามารถกลับมาแก้ไขได้</div>
        <?php endif; ?>
        <?php if (!empty($_GET['save_err'])): ?>
            <div class="alert alert-danger py-2 rounded-3 small mb-3">บันทึกไม่สำเร็จ กรุณาลองใหม่</div>
        <?php endif; ?>
        <?php if (!empty($_GET['close_err']) && ($_GET['close_err'] ?? '') === 'empty'): ?>
            <div class="alert alert-warning py-2 rounded-3 small mb-3">ไม่มีรายชื่อคนงานในตาราง — เพิ่มหรือดึงกลุ่มก่อนบันทึก</div>
        <?php endif; ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="fw-semibold small"><i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($halfLabel, ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <form method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>" id="payrollForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="year_month" value="<?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="half" value="<?= (int) $half ?>">
            <input type="hidden" name="group_context_id" id="groupContextId" value="">
            <input type="hidden" name="group_context_name" id="groupContextName" value="">

            <div class="mb-3">
                <label class="form-label small fw-semibold mb-1" for="payrollPeriodNote">หมายเหตุ <span class="text-muted fw-normal">(ไม่บังคับ)</span></label>
                <textarea class="form-control" id="payrollPeriodNote" name="payroll_period_note" rows="2" maxlength="2000" placeholder="เช่น โครงการ / เงื่อนไขพิเศษของรอบนี้"><?= htmlspecialchars($periodNote, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div class="table-labor-wrap shadow-sm">
                <table class="table table-bordered table-labor mb-0 text-center" id="laborTable">
                    <colgroup>
                        <col style="width:12rem;">
                        <col style="width:5.75rem;">
                        <col style="width:4.5rem;">
                        <col style="width:4.75rem;">
                        <col style="width:6.25rem;">
                        <col style="width:4.5rem;"><col style="width:4.75rem;"><col style="width:2.75rem;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="text-start sticky-worker ps-3 col-meta-head">คนงาน</th>
                            <th class="col-meta-head">ค่าแรง<span class="th-sub">บาท / วัน</span></th>
                            <th class="col-meta-head">วันมา<span class="th-sub">รวมในรอบนี้</span></th>
                            <th class="col-meta-head">OT รวม<span class="th-sub">ชม.</span></th>
                            <th class="col-meta-head">เบิกล่วง<span class="th-sub"><?= $half === 2 ? 'หักรอบบัตรนี้' : 'ใช้รอบ 16–สิ้นเดือน' ?></span></th>
                            <th><span class="d-block">ก่อนหัก</span><span class="th-sub">บาท</span></th>
                            <th class="sticky-sum"><span class="d-block">จ่ายจริง</span><span class="th-sub">บาท</span></th>
                            <th class="bg-secondary-subtle text-secondary" style="width:2.75rem;" title="ลบแถว"><i class="bi bi-trash3"></i></th>
                        </tr>
                    </thead>
                    <tbody id="laborBody">
                        <?php if (count($sheetRows) === 0): ?>
                        <tr id="laborEmptyHint">
                            <td colspan="8" class="text-center text-muted py-4">
                                ยังไม่มีรายชื่อในเดือนนี้ — กดปุ่ม <strong>จัดการกลุ่ม/คนงาน</strong> เพื่อเพิ่มข้อมูลก่อน แล้วค่อยดึงกลุ่มเข้าบัตร
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php
                        $rowIdx = 0;
                        foreach ($sheetRows as $sr):
                            $wid = (int) $sr['id'];
                            $wname = (string) $sr['full_name'];
                            $dw = (float) $sr['daily_wage'];
                            $adv = (float) $sr['advance_draw'];
                            $daysPresentAgg = 0;
                            $otTotalAgg = 0.0;
                            for ($d = $startD; $d <= $endD; $d++) {
                                $cell = $attByWorker[$wid][$d] ?? null;
                                if (is_array($cell) && !empty($cell['p'])) {
                                    $daysPresentAgg++;
                                    $otTotalAgg += (float) ($cell['ot'] ?? 0);
                                }
                            }
                            $otTotalAgg = round($otTotalAgg, 2);
                            $otDisp = $otTotalAgg > 0 ? rtrim(rtrim(number_format($otTotalAgg, 2, '.', ''), '0'), '.') : '';
                        ?>
                        <tr class="labor-row" data-row="<?= (int) $rowIdx ?>">
                            <td class="text-start sticky-worker ps-3 col-meta">
                                <input type="hidden" class="inp-wid" name="workers[<?= (int) $rowIdx ?>][id]" value="<?= $wid ?>">
                                <input type="text" class="form-control form-control-sm worker-search" name="workers[<?= (int) $rowIdx ?>][new_name]" value="<?= htmlspecialchars($wname, ENT_QUOTES, 'UTF-8') ?>" readonly autocomplete="off">
                            </td>
                            <td class="col-meta">
                                <input type="number" class="form-control form-control-sm inp-daily daily-input text-end" name="workers[<?= (int) $rowIdx ?>][daily_wage]" step="0.01" min="0" value="<?= htmlspecialchars((string) $dw, ENT_QUOTES, 'UTF-8') ?>">
                            </td>
                            <td class="col-meta">
                                <input type="number" class="form-control form-control-sm inp-days-present text-end" name="workers[<?= (int) $rowIdx ?>][days_present]" min="0" max="<?= (int) $periodDaysMax ?>" step="1" value="<?= $daysPresentAgg > 0 ? (int) $daysPresentAgg : '' ?>" inputmode="none" readonly title="แตะเพื่อเปิดแป้นตัวเลข — วันมาในรอบนี้ (สูงสุด <?= (int) $periodDaysMax ?> วัน)">
                            </td>
                            <td class="col-meta">
                                <input type="number" class="form-control form-control-sm inp-ot-total text-end" name="workers[<?= (int) $rowIdx ?>][ot_total]" step="0.25" min="0" value="<?= $otDisp !== '' ? htmlspecialchars($otDisp, ENT_QUOTES, 'UTF-8') : '' ?>" inputmode="none" readonly title="แตะเพื่อเปิดแป้นตัวเลข — OT รวม (ชม.)">
                            </td>
                            <td class="col-meta">
                                <input type="number" class="form-control form-control-sm inp-advance advance-input text-end" name="workers[<?= (int) $rowIdx ?>][advance]" step="0.01" min="0" value="<?= htmlspecialchars((string) $adv, ENT_QUOTES, 'UTF-8') ?>" title="เบิกล่วงหน้า (หักเฉพาะรอบบัตร 16–สิ้นเดือน)">
                            </td>
                            <td class="td-gross text-end">0.00</td>
                            <td class="td-net text-end fw-bold sticky-sum col-net col-net-pad">0.00</td>
                            <td>
                                <button type="submit" form="formRemove<?= (int) $rowIdx ?>" class="btn btn-sm btn-outline-danger border-0" title="เอาออกจากบัตรเดือนนี้"><i class="bi bi-x-lg"></i></button>
                            </td>
                        </tr>
                        <?php
                            $rowIdx++;
                        endforeach;
                        ?>
                    </tbody>
                    <tfoot class="table-warning">
                        <tr>
                            <th colspan="5" class="text-end">สรุปยอดจ่ายจริงทั้งหมด (รอบ <?= htmlspecialchars($halfLabel, ENT_QUOTES, 'UTF-8') ?>)</th>
                            <th class="text-end" id="sumGrossFoot">—</th>
                            <th class="text-end fw-bold sticky-sum" id="sumNetFoot">0.00</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-primary rounded-pill" id="btnAddRow"><i class="bi bi-plus-lg me-1"></i>เพิ่มคนงาน</button>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="submit" name="action" value="save_draft" class="btn btn-sm btn-outline-primary rounded-pill px-3" id="btnSaveDraft" title="เก็บวันมา/OT/หมายเหตุในระบบ ยังไม่ปิดรอบ"><i class="bi bi-floppy me-1"></i>บันทึกร่าง</button>
                    <button type="submit" name="action" value="save" class="btn btn-sm btn-success rounded-pill px-3" id="btnSavePayroll" title="ปิดรอบ เก็บประวัติ แล้วเคลียร์บัตร"><i class="bi bi-check2-circle me-1"></i>บันทึกและปิดรอบ</button>
                </div>
            </div>
        </form>

        <?php for ($i = 0; $i < $rowIdx; $i++): ?>
        <form id="formRemove<?= $i ?>" method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>" class="d-none">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="remove_row">
            <input type="hidden" name="year_month" value="<?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="half" value="<?= (int) $half ?>">
            <input type="hidden" name="worker_id" value="<?= (int) ($sheetRows[$i]['id'] ?? 0) ?>">
        </form>
        <?php endfor; ?>
    </div>

    <div id="laborNumPadOverlay" class="lp-numpad-overlay" aria-hidden="true">
        <div class="lp-numpad-dialog" role="dialog" aria-modal="true" aria-labelledby="laborNumPadTitle">
            <div class="lp-numpad-head">
                <h2 class="lp-numpad-title" id="laborNumPadTitle">—</h2>
                <div class="lp-numpad-preview" id="laborNumPadPreview">0</div>
            </div>
            <div class="lp-numpad-scroll">
                <div class="lp-numpad-grid" id="laborNumPadGrid"></div>
            </div>
            <div class="lp-numpad-frac-row d-none" id="laborNumPadFracRow">
                <button type="button" class="lp-numpad-frac" data-add="0.25">+1/4</button>
                <button type="button" class="lp-numpad-frac" data-add="0.5">+1/2</button>
                <button type="button" class="lp-numpad-frac" data-add="0.75">+3/4</button>
                <button type="button" class="lp-numpad-clear" id="laborNumPadClear">ล้าง</button>
            </div>
            <div class="lp-numpad-foot">
                <button type="button" class="btn-close-pad" id="laborNumPadDone">ปิด</button>
            </div>
        </div>
    </div>

    <datalist id="workerList">
        <?php foreach ($allWorkers as $w): ?>
            <?php
                $gid = (int) ($w['group_id'] ?? 0);
                $gname = $groupNameById[$gid] ?? '';
                $label = trim((string) ($w['full_name'] ?? ''));
                if ($gname !== '') {
                    $label .= ' (' . $gname . ')';
                }
            ?>
            <option data-id="<?= (int) $w['id'] ?>" value="<?= htmlspecialchars((string) ($w['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <template id="tplRow">
        <tr class="labor-row" data-row="__IDX__">
            <td class="text-start sticky-worker ps-3 col-meta">
                <input type="hidden" name="workers[__IDX__][id]" value="0" class="inp-wid">
                <input type="text" class="form-control form-control-sm worker-search" name="workers[__IDX__][new_name]" value="" list="workerList" placeholder="พิมพ์ชื่อหรือเลือกจากรายการ" autocomplete="off">
            </td>
            <td class="col-meta"><input type="number" class="form-control form-control-sm inp-daily daily-input text-end" name="workers[__IDX__][daily_wage]" step="0.01" min="0" value=""></td>
            <td class="col-meta"><input type="number" class="form-control form-control-sm inp-days-present text-end" name="workers[__IDX__][days_present]" min="0" max="<?= (int) $periodDaysMax ?>" step="1" value="" inputmode="none" readonly title="แตะเพื่อเปิดแป้นตัวเลข — วันมา"></td>
            <td class="col-meta"><input type="number" class="form-control form-control-sm inp-ot-total text-end" name="workers[__IDX__][ot_total]" step="0.25" min="0" value="" inputmode="none" readonly title="แตะเพื่อเปิดแป้นตัวเลข — OT รวม"></td>
            <td class="col-meta"><input type="number" class="form-control form-control-sm inp-advance advance-input text-end" name="workers[__IDX__][advance]" step="0.01" min="0" value="" title="เบิกล่วงหน้า (หักเฉพาะรอบบัตร 16–สิ้นเดือน)"></td>
            <td class="td-gross text-end">0.00</td>
            <td class="td-net text-end fw-bold sticky-sum col-net col-net-pad">0.00</td>
            <td><button type="button" class="btn btn-sm btn-outline-danger border-0 btn-del-row" title="ลบแถวนี้"><i class="bi bi-x-lg"></i></button></td>
        </tr>
    </template>

    <script>
    (function () {
        const half = <?= (int) $half ?>;
        const periodDaysMax = <?= (int) $periodDaysMax ?>;
        const halfLabelJs = <?= json_encode($halfLabel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        /** ชั่วโมง OT รวม — ปุ่มจำนวนเต็ม 0..N (N ตามความยาวรอบ) */
        const otIntPadMax = Math.min(160, Math.max(48, periodDaysMax * 6));
        const fmt = (n) => (Math.round(n * 100) / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const workersByGroup = <?= json_encode($workersByGroup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const bulkGroupSel = document.getElementById('bulkGroupId');

        const padOverlay = document.getElementById('laborNumPadOverlay');
        const padTitleEl = document.getElementById('laborNumPadTitle');
        const padPreviewEl = document.getElementById('laborNumPadPreview');
        const padGridEl = document.getElementById('laborNumPadGrid');
        const padFracRow = document.getElementById('laborNumPadFracRow');
        let padTargetInput = null;
        let padMode = 'days';
        let padDraft = 0;

        function roundOtQuarter(x) {
            return Math.round(x * 4) / 4;
        }

        function formatOtPadPreview(v) {
            const q = roundOtQuarter(v);
            if (q <= 0) return '0';
            const s = (Math.round(q * 100) / 100).toFixed(2);
            return s.replace(/\.?0+$/, '');
        }

        function formatOtInputValue(v) {
            const q = roundOtQuarter(v);
            if (q <= 0) return '';
            const s = (Math.round(q * 100) / 100).toFixed(2);
            return s.replace(/\.?0+$/, '');
        }

        function padUpdatePreview() {
            if (!padPreviewEl) return;
            if (padMode === 'days') {
                padPreviewEl.textContent = String(Math.min(periodDaysMax, Math.max(0, Math.floor(padDraft))));
            } else {
                padPreviewEl.textContent = formatOtPadPreview(padDraft);
            }
        }

        function padBuildGrid() {
            if (!padGridEl) return;
            padGridEl.innerHTML = '';
            const frag = document.createDocumentFragment();
            const maxN = padMode === 'days' ? periodDaysMax : otIntPadMax;
            for (let i = 0; i <= maxN; i++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'lp-numpad-num';
                btn.textContent = String(i);
                btn.dataset.val = String(i);
                btn.addEventListener('click', (ev) => {
                    ev.stopPropagation();
                    if (padMode === 'days') {
                        padDraft = i;
                    } else {
                        padDraft = roundOtQuarter(i);
                    }
                    padApplyAndClose();
                });
                frag.appendChild(btn);
            }
            padGridEl.appendChild(frag);
        }

        function padOpen(inputEl, mode) {
            if (!padOverlay || !inputEl) return;
            padTargetInput = inputEl;
            padMode = mode;
            if (mode === 'days') {
                const n = parseDaysPresent(inputEl);
                padDraft = n;
                if (padTitleEl) padTitleEl.textContent = 'วันมา — ' + halfLabelJs;
                padFracRow?.classList.remove('d-none');
                padFracRow?.querySelectorAll('.lp-numpad-frac').forEach((b) => b.classList.add('d-none'));
            } else {
                const raw = parseFloat(String(inputEl.value).replace(/,/g, ''));
                padDraft = isFinite(raw) ? roundOtQuarter(raw) : 0;
                if (padTitleEl) padTitleEl.textContent = 'OT รวม (ชม.) — ' + halfLabelJs;
                padFracRow?.classList.remove('d-none');
                padFracRow?.querySelectorAll('.lp-numpad-frac').forEach((b) => b.classList.remove('d-none'));
            }
            padBuildGrid();
            padUpdatePreview();
            padOverlay.classList.add('is-open');
            padOverlay.setAttribute('aria-hidden', 'false');
        }

        function padApplyAndClose() {
            if (!padOverlay) return;
            if (padTargetInput) {
                if (padMode === 'days') {
                    const v = Math.min(periodDaysMax, Math.max(0, Math.floor(padDraft)));
                    padTargetInput.value = v > 0 ? String(v) : '';
                } else {
                    padTargetInput.value = formatOtInputValue(padDraft);
                }
                padTargetInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
            padTargetInput = null;
            padOverlay.classList.remove('is-open');
            padOverlay.setAttribute('aria-hidden', 'true');
        }

        padOverlay?.querySelector('.lp-numpad-dialog')?.addEventListener('click', (e) => e.stopPropagation());
        padOverlay?.addEventListener('click', () => padApplyAndClose());
        document.getElementById('laborNumPadDone')?.addEventListener('click', (e) => {
            e.stopPropagation();
            padApplyAndClose();
        });
        document.getElementById('laborNumPadClear')?.addEventListener('click', (e) => {
            e.stopPropagation();
            padDraft = 0;
            padUpdatePreview();
        });
        padFracRow?.querySelectorAll('.lp-numpad-frac').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (padMode !== 'ot') return;
                const add = parseFloat(btn.getAttribute('data-add') || '0');
                if (!isFinite(add)) return;
                padDraft = roundOtQuarter(padDraft + add);
                padDraft = Math.min(999.75, Math.max(0, padDraft));
                padUpdatePreview();
            });
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && padOverlay?.classList.contains('is-open')) {
                padApplyAndClose();
            }
        });

        document.getElementById('laborBody')?.addEventListener('click', (e) => {
            const daysInp = e.target.closest('.inp-days-present');
            if (daysInp) {
                e.preventDefault();
                padOpen(daysInp, 'days');
                return;
            }
            const otInp = e.target.closest('.inp-ot-total');
            if (otInp) {
                e.preventDefault();
                padOpen(otInp, 'ot');
            }
        });

        function syncGroupContext() {
            const idEl = document.getElementById('groupContextId');
            const nameEl = document.getElementById('groupContextName');
            if (!idEl || !nameEl || !bulkGroupSel) return;
            const opt = bulkGroupSel.options[bulkGroupSel.selectedIndex] || null;
            idEl.value = bulkGroupSel.value || '';
            nameEl.value = opt && opt.value ? (opt.text || '') : '';
        }

        function parseNum(el) {
            if (!el) return 0;
            const v = parseFloat(String(el.value).replace(/,/g, ''));
            return isFinite(v) ? v : 0;
        }

        function parseDaysPresent(el) {
            if (!el || String(el.value).trim() === '') return 0;
            const n = parseInt(String(el.value).replace(/,/g, ''), 10);
            if (!isFinite(n) || n < 0) return 0;
            return Math.min(periodDaysMax, n);
        }

        function recalcRow(tr) {
            const daily = parseNum(tr.querySelector('.inp-daily'));
            const advance = parseNum(tr.querySelector('.inp-advance'));
            const days = parseDaysPresent(tr.querySelector('.inp-days-present'));
            const otRaw = parseNum(tr.querySelector('.inp-ot-total'));
            const otSum = days === 0 ? 0 : otRaw;
            const otRate = (daily / 8) * 1.5;
            const gross = days * daily + otSum * otRate;
            let net = gross;
            if (half === 2) {
                net = gross - advance;
            }
            const tdGross = tr.querySelector('.td-gross');
            const tdNet = tr.querySelector('.td-net');
            if (tdGross) tdGross.textContent = fmt(gross);
            if (tdNet) tdNet.textContent = fmt(net);
            return { gross, net };
        }

        function recalcAll() {
            let sumGross = 0, sumNet = 0;
            document.querySelectorAll('#laborBody .labor-row').forEach((tr) => {
                const r = recalcRow(tr);
                sumGross += r.gross;
                sumNet += r.net;
            });
            const fg = document.getElementById('sumGrossFoot');
            const fn = document.getElementById('sumNetFoot');
            if (fg) fg.textContent = fmt(sumGross);
            if (fn) fn.textContent = fmt(sumNet);
        }

        document.getElementById('laborBody')?.addEventListener('input', (e) => {
            if (e.target.closest('.labor-row')) recalcAll();
        });

        let idx = <?= (int) $rowIdx ?>;
        document.getElementById('btnAddRow')?.addEventListener('click', () => {
            document.getElementById('laborEmptyHint')?.remove();
            const tpl = document.getElementById('tplRow');
            if (!tpl) return;
            const html = tpl.innerHTML.replace(/__IDX__/g, String(idx++));
            const wrap = document.createElement('tbody');
            wrap.innerHTML = html.trim();
            const tr = wrap.firstElementChild;
            document.getElementById('laborBody').appendChild(tr);
            recalcAll();
        });

        function appendWorkerRow(workerId, workerName, dailyWage) {
            document.getElementById('laborEmptyHint')?.remove();
            const tpl = document.getElementById('tplRow');
            if (!tpl) return;
            const html = tpl.innerHTML.replace(/__IDX__/g, String(idx++));
            const wrap = document.createElement('tbody');
            wrap.innerHTML = html.trim();
            const tr = wrap.firstElementChild;
            if (!tr) return;
            const widInp = tr.querySelector('.inp-wid');
            const nameInp = tr.querySelector('.worker-search');
            const dailyInp = tr.querySelector('.inp-daily');
            if (widInp) widInp.value = String(workerId > 0 ? workerId : 0);
            if (nameInp) {
                nameInp.value = workerName || '';
                if (workerId > 0) {
                    nameInp.readOnly = true;
                }
            }
            if (dailyInp) {
                dailyInp.value = String(dailyWage > 0 ? dailyWage : 0);
            }
            document.getElementById('laborBody').appendChild(tr);
        }

        document.getElementById('btnLoadGroupWorkers')?.addEventListener('click', async () => {
            const sel = document.getElementById('bulkGroupId');
            if (!sel || !sel.value) {
                window.alert('กรุณาเลือกกลุ่มก่อน');
                return;
            }
            const gid = String(sel.value);
            const list = Array.isArray(workersByGroup[gid]) ? workersByGroup[gid] : [];
            if (list.length === 0) {
                window.alert('ไม่พบคนงานในกลุ่มนี้');
                return;
            }

            const body = document.getElementById('laborBody');
            const hadRows = body && body.querySelectorAll('.labor-row').length > 0;
            if (hadRows) {
                const ok = await Swal.fire({
                    icon: 'warning',
                    title: 'ล้างตารางแล้วโหลดกลุ่มใหม่?',
                    html: 'รายชื่อในบัตรจะถูกล้างทั้งหมด (ข้อมูลที่ยังไม่กดบันทึกจะหาย) แล้วโหลดเฉพาะกลุ่มที่เลือก',
                    showCancelButton: true,
                    confirmButtonText: 'ดึงกลุ่มนี้',
                    cancelButtonText: 'ยกเลิก',
                    confirmButtonColor: '#198754'
                });
                if (!ok.isConfirmed) {
                    return;
                }
            }

            body?.querySelectorAll('.labor-row').forEach((tr) => tr.remove());
            idx = 0;

            list.forEach((w) => {
                const wid = parseInt(String(w.id || 0), 10);
                appendWorkerRow(wid, String(w.full_name || ''), parseFloat(String(w.daily_wage || 0)));
            });

            syncGroupContext();
            recalcAll();
        });
        bulkGroupSel?.addEventListener('change', syncGroupContext);

        document.getElementById('laborBody')?.addEventListener('click', (e) => {
            const btn = e.target.closest('.btn-del-row');
            if (!btn) return;
            const tr = btn.closest('tr');
            if (tr) tr.remove();
            recalcAll();
        });

        document.getElementById('laborBody')?.addEventListener('input', (e) => {
            const inp = e.target.closest('.worker-search');
            if (!inp || inp.readOnly) return;
            const tr = inp.closest('tr');
            if (!tr) return;
            const list = document.getElementById('workerList');
            if (!list) return;
            const val = inp.value.trim();
            let foundId = '0';
            list.querySelectorAll('option').forEach((op) => {
                if (op.value === val) {
                    const id = op.getAttribute('data-id');
                    if (id) foundId = id;
                }
            });
            const hid = tr.querySelector('.inp-wid');
            if (hid) hid.value = foundId;
        });

        recalcAll();

        const payrollForm = document.getElementById('payrollForm');
        let isSubmitting = false;
        payrollForm?.addEventListener('submit', async function (ev) {
            syncGroupContext();
            const submitter = ev.submitter;
            const act = submitter && submitter.getAttribute('name') === 'action' ? submitter.value : 'save_draft';
            if (act !== 'save') {
                return;
            }
            /* รอบสองหลังยืนยัน — ต้องไม่ preventDefault เพื่อให้ POST ไปพร้อม action=save */
            if (isSubmitting) {
                return;
            }
            ev.preventDefault();
            const result = await Swal.fire({
                icon: 'question',
                title: 'ยืนยันปิดรอบ?',
                html: 'ระบบจะเก็บประวัติและล้างบัตรรอบนี้ — หากต้องการเก็บวันมา/OT/หมายเหตุโดยยังไม่ปิดรอบ ให้ใช้ปุ่ม <strong>บันทึกร่าง</strong>',
                showCancelButton: true,
                confirmButtonText: 'ยืนยันปิดรอบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#198754'
            });
            if (!result.isConfirmed) {
                return;
            }
            isSubmitting = true;
            const btnClose = document.getElementById('btnSavePayroll');
            /* form.submit() ไม่ส่งค่า submit button — POST จะไม่มี action=save จึงบันทึกไม่ได้ */
            if (typeof payrollForm.requestSubmit === 'function' && btnClose) {
                payrollForm.requestSubmit(btnClose);
            } else if (btnClose) {
                btnClose.click();
            } else {
                payrollForm.submit();
            }
        });
        syncGroupContext();
    })();
    </script>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
