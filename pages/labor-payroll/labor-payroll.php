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

$sheetRows = [];
$sheetLinks = Db::filter('labor_month_sheet_workers', static fn ($r) => (string) ($r['year_month'] ?? '') === $ym);
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
    $w = Db::rowByIdField('labor_workers', $wid);
    if (!$w || empty($w['is_active'])) {
        continue;
    }
    $ms = Db::row('labor_worker_month_settings', Db::compositeKey([(string) $wid, $ym]));
    $sheetRows[] = [
        'id' => $wid,
        'full_name' => (string) ($w['full_name'] ?? ''),
        'sort_order' => (int) ($s['sort_order'] ?? 0),
        'daily_wage' => $ms ? (float) ($ms['daily_wage'] ?? 0) : 0.0,
        'advance_draw' => $ms ? (float) ($ms['advance_draw'] ?? 0) : 0.0,
    ];
}
usort(
    $sheetRows,
    static function ($a, $b): int {
        $sa = (int) ($a['sort_order'] ?? 0);
        $sb = (int) ($b['sort_order'] ?? 0);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }

        return strcmp((string) ($a['full_name'] ?? ''), (string) ($b['full_name'] ?? ''));
    }
);

if (!empty($_GET['reset'])) {
    $sheetRows = [];
}

$attByWorker = [];
if (count($sheetRows) > 0) {
    $ids = array_map(static fn ($r) => (int) $r['id'], $sheetRows);
    $ids = array_values(array_filter($ids, static fn ($x) => $x > 0));
    if (count($ids) > 0) {
        $from = $ym . '-01';
        $to = $ym . '-' . str_pad((string) $dim, 2, '0', STR_PAD_LEFT);
        foreach (Db::tableRows('labor_attendance_days') as $row) {
            $wid = (int) ($row['worker_id'] ?? 0);
            if (!in_array($wid, $ids, true)) {
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

$allWorkers = Db::filter('labor_workers', static fn ($w) => !empty($w['is_active']));
Db::sortRows($allWorkers, 'full_name');
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
foreach ($allWorkers as $w) {
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

$halfLabel = $half === 1 ? 'วันที่ 1–15' : ('วันที่ 16–' . $dim);
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
        body { font-family: 'Sarabun', sans-serif; background: #f6f8fb; }
        .punch-card { border-radius: 12px; border: 1px solid #e3e8ef; box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05); background: #fff; }
        .day-head { min-width: 46px; font-size: 0.7rem; vertical-align: bottom; }
        .day-cell { min-width: 46px; user-select: none; }
        .ot-input { width: 100%; max-width: 46px; font-size: 0.72rem; padding: 1px 3px; margin: 0 auto; display: block; }
        .daily-input { width: 74px; margin: 0 auto; }
        .advance-input { width: 84px; margin: 0 auto; }
        .chk-tiny { transform: scale(1.05); }
        .sticky-worker { position: sticky; left: 0; z-index: 2; background: #fff; box-shadow: 4px 0 8px rgba(15,23,42,0.04); }
        .sticky-sum { position: sticky; right: 0; z-index: 2; background: #fffdf8; box-shadow: -4px 0 8px rgba(15,23,42,0.04); }
        .table-labor th, .table-labor td { vertical-align: middle; }
        .hint-advance { font-size: 0.75rem; color: #6c757d; }
        .group-load-wrap {
            background: #f8fafc;
            border: 1px solid #e7edf4;
            border-radius: 10px;
            padding: 6px 8px;
        }
        .group-load-select { min-width: 210px; }
        .quick-actions-col {
            display: flex;
            flex-direction: column;
            gap: 6px;
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
                <span class="small text-muted">ดึงคนงานจากกลุ่ม</span>
                <select class="form-select form-select-sm group-load-select" id="bulkGroupId">
                    <option value="">เลือกกลุ่มคนงาน</option>
                    <?php foreach ($workerGroups as $g): ?>
                        <?php $gid = (int) ($g['id'] ?? 0); ?>
                        <option value="<?= $gid ?>"><?= htmlspecialchars((string) ($g['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm btn-success rounded-3 px-3" id="btnLoadGroupWorkers"><i class="bi bi-download me-1"></i>ดึงคนงาน</button>
            </div>
            <div class="quick-actions-col">
                <a href="<?= htmlspecialchars(app_path('pages/labor-payroll/labor-worker-manage.php') . '?month=' . urlencode($ym) . '&half=' . (int) $half, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-dark rounded-3 px-3"><i class="bi bi-plus-lg me-1"></i>เพิ่มคนงาน</a>
                <a href="<?= htmlspecialchars(app_path('pages/labor-payroll/labor-payroll-history.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-primary rounded-3 px-3"><i class="bi bi-clock-history me-1"></i>ประวัติรายการ</a>
            </div>
        </div>
    </div>

    <ul class="nav nav-pills mb-2 gap-2">
        <li class="nav-item">
            <a class="nav-link <?= $half === 1 ? 'active' : '' ?> rounded-pill py-1 px-3" href="<?= htmlspecialchars(app_path('pages/labor-payroll/labor-payroll.php') . '?month=' . urlencode($ym) . '&half=1') ?>">งวด 1–15</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $half === 2 ? 'active' : '' ?> rounded-pill py-1 px-3" href="<?= htmlspecialchars(app_path('pages/labor-payroll/labor-payroll.php') . '?month=' . urlencode($ym) . '&half=2') ?>">งวด 16–<?= (int) $dim ?></a>
        </li>
    </ul>

    <div class="punch-card p-2 p-md-3 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="fw-semibold small"><i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($halfLabel, ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <form method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>" id="payrollForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="year_month" value="<?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="half" value="<?= (int) $half ?>">
            <input type="hidden" name="group_context_id" id="groupContextId" value="">
            <input type="hidden" name="group_context_name" id="groupContextName" value="">

            <div class="table-responsive border rounded-3 bg-white">
                <table class="table table-sm table-bordered table-labor mb-0 text-center small" id="laborTable">
                    <thead class="table-light">
                        <tr>
                            <th class="text-start sticky-worker ps-2" style="min-width: 170px;">คนงาน</th>
                            <th class="small">ค่าแรง<br>/วัน</th>
                            <th class="small">เบิกล่วงหน้า<br><span class="fw-normal">(ทั้งเดือน)</span></th>
                            <?php foreach ($daysList as $d): ?>
                                <th class="day-head">วัน<br><?= (int) $d ?></th>
                            <?php endforeach; ?>
                            <th class="small">วันมา</th>
                            <th class="small">OT รวม</th>
                            <th class="small">ยอดรวม</th>
                            <th class="small sticky-sum">จ่ายจริง</th>
                            <th class="small" style="width: 56px;"></th>
                        </tr>
                    </thead>
                    <tbody id="laborBody">
                        <?php if (count($sheetRows) === 0): ?>
                        <tr id="laborEmptyHint">
                            <td colspan="<?= 8 + count($daysList) ?>" class="text-center text-muted py-4">
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
                        ?>
                        <tr class="labor-row" data-row="<?= (int) $rowIdx ?>">
                            <td class="text-start sticky-worker ps-2">
                                <input type="hidden" class="inp-wid" name="workers[<?= (int) $rowIdx ?>][id]" value="<?= $wid ?>">
                                <input type="text" class="form-control form-control-sm worker-search" name="workers[<?= (int) $rowIdx ?>][new_name]" value="<?= htmlspecialchars($wname, ENT_QUOTES, 'UTF-8') ?>" readonly autocomplete="off">
                            </td>
                            <td>
                                <input type="number" class="form-control form-control-sm inp-daily daily-input text-end" name="workers[<?= (int) $rowIdx ?>][daily_wage]" step="0.01" min="0" value="<?= htmlspecialchars((string) $dw, ENT_QUOTES, 'UTF-8') ?>">
                            </td>
                            <td>
                                <input type="number" class="form-control form-control-sm inp-advance advance-input text-end" name="workers[<?= (int) $rowIdx ?>][advance]" step="0.01" min="0" value="<?= htmlspecialchars((string) $adv, ENT_QUOTES, 'UTF-8') ?>">
                            </td>
                            <?php foreach ($daysList as $d):
                                $cell = $attByWorker[$wid][$d] ?? ['p' => false, 'ot' => 0.0];
                                $ck = !empty($cell['p']);
                                $otv = (float) ($cell['ot'] ?? 0);
                            ?>
                                <td class="day-cell px-1">
                                    <div class="mb-1">
                                        <input class="form-check-input chk-tiny chk-pres" type="checkbox" name="workers[<?= (int) $rowIdx ?>][days][<?= (int) $d ?>][p]" value="1" <?= $ck ? 'checked' : '' ?>>
                                    </div>
                                    <input type="number" class="form-control form-control-sm ot-input inp-ot" name="workers[<?= (int) $rowIdx ?>][days][<?= (int) $d ?>][ot]" step="0.25" min="0" value="<?= $otv > 0 ? htmlspecialchars(rtrim(rtrim(number_format($otv, 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="OT">
                                </td>
                            <?php endforeach; ?>
                            <td class="small td-days">0</td>
                            <td class="small td-ot">0</td>
                            <td class="small td-gross text-end">0.00</td>
                            <td class="small td-net text-end fw-bold sticky-sum">0.00</td>
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
                            <th colspan="<?= 3 + count($daysList) ?>" class="text-end">สรุปยอดจ่ายจริงทั้งหมด (รอบ <?= htmlspecialchars($halfLabel, ENT_QUOTES, 'UTF-8') ?>)</th>
                            <th colspan="2"></th>
                            <th class="text-end" id="sumGrossFoot">—</th>
                            <th class="text-end fw-bold sticky-sum" id="sumNetFoot">0.00</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-primary rounded-pill" id="btnAddRow"><i class="bi bi-plus-lg me-1"></i>เพิ่มคนงาน</button>
                <div class="d-flex align-items-center gap-2">
                    <button type="submit" class="btn btn-sm btn-success rounded-pill px-3" id="btnSavePayroll"><i class="bi bi-save me-1"></i>บันทึกและเริ่มใหม่</button>
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
            <td class="text-start sticky-worker ps-2">
                <input type="hidden" name="workers[__IDX__][id]" value="0" class="inp-wid">
                <input type="text" class="form-control form-control-sm worker-search" name="workers[__IDX__][new_name]" value="" list="workerList" placeholder="พิมพ์ค้นหาหรือชื่อใหม่" autocomplete="off">
            </td>
            <td><input type="number" class="form-control form-control-sm inp-daily daily-input text-end" name="workers[__IDX__][daily_wage]" step="0.01" min="0" value=""></td>
            <td><input type="number" class="form-control form-control-sm inp-advance advance-input text-end" name="workers[__IDX__][advance]" step="0.01" min="0" value=""></td>
            <?php foreach ($daysList as $d): ?>
            <td class="day-cell px-1">
                <div class="mb-1">
                    <input class="form-check-input chk-tiny chk-pres" type="checkbox" name="workers[__IDX__][days][<?= (int) $d ?>][p]" value="1">
                </div>
                <input type="number" class="form-control form-control-sm ot-input inp-ot" name="workers[__IDX__][days][<?= (int) $d ?>][ot]" step="0.25" min="0" value="" placeholder="OT">
            </td>
            <?php endforeach; ?>
            <td class="small td-days">0</td>
            <td class="small td-ot">0</td>
            <td class="small td-gross text-end">0.00</td>
            <td class="small td-net text-end fw-bold sticky-sum">0.00</td>
            <td><button type="button" class="btn btn-sm btn-outline-danger border-0 btn-del-row" title="ลบแถวนี้"><i class="bi bi-x-lg"></i></button></td>
        </tr>
    </template>

    <script>
    (function () {
        const half = <?= (int) $half ?>;
        const fmt = (n) => (Math.round(n * 100) / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const workersByGroup = <?= json_encode($workersByGroup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const bulkGroupSel = document.getElementById('bulkGroupId');

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

        function recalcRow(tr) {
            const daily = parseNum(tr.querySelector('.inp-daily'));
            const advance = parseNum(tr.querySelector('.inp-advance'));
            let days = 0, otSum = 0;
            tr.querySelectorAll('.day-cell').forEach((cell) => {
                const ck = cell.querySelector('.chk-pres');
                const ot = parseNum(cell.querySelector('.inp-ot'));
                if (ck && ck.checked) days += 1;
                otSum += ot;
            });
            const otRate = (daily / 8) * 1.5;
            const gross = days * daily + otSum * otRate;
            let net = gross;
            if (half === 2) {
                net = gross - advance;
            }
            const tdDays = tr.querySelector('.td-days');
            const tdOt = tr.querySelector('.td-ot');
            const tdGross = tr.querySelector('.td-gross');
            const tdNet = tr.querySelector('.td-net');
            if (tdDays) tdDays.textContent = String(days);
            if (tdOt) tdOt.textContent = (Math.round(otSum * 100) / 100).toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
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
        document.getElementById('laborBody')?.addEventListener('change', (e) => {
            if (e.target.classList.contains('chk-pres')) recalcAll();
        });
        const laborBodyEl = document.getElementById('laborBody');
        let dragChecking = false;
        let dragTargetValue = false;
        let dragStartCheckbox = null;
        let dragHasMoved = false;
        let suppressNextClick = false;

        laborBodyEl?.addEventListener('mousedown', (e) => {
            const ck = e.target.closest('.chk-pres');
            if (!ck) return;
            dragChecking = true;
            dragStartCheckbox = ck;
            dragTargetValue = !ck.checked;
            dragHasMoved = false;
        });
        laborBodyEl?.addEventListener('mouseover', (e) => {
            if (!dragChecking) return;
            const ck = e.target.closest('.chk-pres');
            if (!ck || ck === dragStartCheckbox) return;
            if (dragStartCheckbox && !dragHasMoved) {
                dragStartCheckbox.checked = dragTargetValue;
                dragHasMoved = true;
            }
            ck.checked = dragTargetValue;
        });
        laborBodyEl?.addEventListener('click', (e) => {
            const ck = e.target.closest('.chk-pres');
            if (!ck) return;
            if (suppressNextClick) {
                e.preventDefault();
                e.stopPropagation();
                suppressNextClick = false;
                return;
            }
            recalcAll();
        });
        document.addEventListener('mouseup', () => {
            if (!dragChecking) return;
            dragChecking = false;
            if (dragHasMoved) {
                suppressNextClick = true;
                recalcAll();
            }
            dragStartCheckbox = null;
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

        document.getElementById('btnLoadGroupWorkers')?.addEventListener('click', () => {
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

            const existing = new Set();
            document.querySelectorAll('#laborBody .labor-row .inp-wid').forEach((el) => {
                const id = parseInt(el.value || '0', 10);
                if (id > 0) existing.add(id);
            });

            let added = 0;
            list.forEach((w) => {
                const wid = parseInt(String(w.id || 0), 10);
                if (wid > 0 && existing.has(wid)) {
                    return;
                }
                appendWorkerRow(wid, String(w.full_name || ''), parseFloat(String(w.daily_wage || 0)));
                if (wid > 0) {
                    existing.add(wid);
                }
                added += 1;
            });

            recalcAll();
            if (added === 0) {
                window.alert('คนงานในกลุ่มนี้ถูกเพิ่มไว้แล้ว');
            }
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
            if (isSubmitting) {
                return;
            }
            ev.preventDefault();
            syncGroupContext();
            const result = await Swal.fire({
                icon: 'question',
                title: 'ยืนยันบันทึกรายการทั้งหมด',
                text: 'ระบบจะเก็บประวัติ แล้วเคลียร์ตารางรอบนี้ทั้งหมดเพื่อเริ่มกรอกใหม่',
                showCancelButton: true,
                confirmButtonText: 'ยืนยันบันทึก',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#198754'
            });
            if (!result.isConfirmed) {
                return;
            }
            isSubmitting = true;
            payrollForm.submit();
        });
        syncGroupContext();
    })();
    </script>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
