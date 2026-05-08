<?php

declare(strict_types=1);


require_once __DIR__ . '/_page_root.php';
session_start();
require_once THEELINCON_ROOT . '/config/connect_database.php';

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
    $w = Db::row('labor_workers', (string) $wid);
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

$allWorkers = Db::filter('labor_workers', static fn ($w) => !empty($w['is_active']));
Db::sortRows($allWorkers, 'full_name');

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
        .punch-card { border-radius: 14px; border: 1px solid #e3e8ef; box-shadow: 0 6px 24px rgba(15, 23, 42, 0.06); background: #fff; }
        .day-head { min-width: 52px; font-size: 0.72rem; vertical-align: bottom; }
        .day-cell { min-width: 52px; }
        .ot-input { width: 100%; max-width: 52px; font-size: 0.75rem; padding: 2px 4px; margin: 0 auto; display: block; }
        .chk-tiny { transform: scale(1.05); }
        .sticky-worker { position: sticky; left: 0; z-index: 2; background: #fff; box-shadow: 4px 0 8px rgba(15,23,42,0.04); }
        .sticky-sum { position: sticky; right: 0; z-index: 2; background: #fffdf8; box-shadow: -4px 0 8px rgba(15,23,42,0.04); }
        .table-labor th, .table-labor td { vertical-align: middle; }
        .hint-advance { font-size: 0.75rem; color: #6c757d; }
    </style>
</head>
<body>

<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>

<div class="container-fluid px-2 px-md-3 pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3 mt-2">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-person-workspace text-primary me-2"></i>ระบบคำนวณค่าแรงคนงาน</h4>
            <div class="text-muted small">บันทึกการมาทำงานรายวัน</div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <form method="get" class="d-flex flex-wrap align-items-center gap-2">
                <label class="small text-muted mb-0">เดือน</label>
                <input type="month" name="month" class="form-control form-control-sm" style="width: auto;" value="<?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="half" value="<?= (int) $half ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary rounded-3">แสดง</button>
            </form>
            <a href="<?= htmlspecialchars(app_path('pages/labor-payroll-history.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-primary rounded-3"><i class="bi bi-archive me-1"></i>ประวัติตัดยอด</a>
        </div>
    </div>

    <?php if (!empty($_GET['saved'])): ?>
        <div class="alert alert-success py-2 rounded-3 small">บันทึกข้อมูลแล้ว</div>
    <?php endif; ?>
    <?php if (!empty($_GET['closed'])): ?>
        <div class="alert alert-info py-2 rounded-3 small"><strong>ตัดยอดแล้ว</strong> — เก็บประวัติไว้ในเมนูประวัติตัดยอด และเคลียร์การเช็คชื่อ/OT ของบัตรรอบนี้แล้ว (ค่าแรงต่อวันและเบิกล่วงหน้ายังอยู่)</div>
    <?php endif; ?>
    <?php if (!empty($_GET['close_err']) && $_GET['close_err'] === 'empty'): ?>
        <div class="alert alert-warning py-2 rounded-3 small">ต้องมีอย่างน้อย 1 แถวคนงานจึงจะตัดยอดได้</div>
    <?php endif; ?>
    <?php if (!empty($_GET['save_err'])): ?>
        <div class="alert alert-danger py-2 rounded-3 small">บันทึกไม่สำเร็จ — ลองใหม่อีกครั้ง</div>
    <?php endif; ?>

    <ul class="nav nav-pills mb-3 gap-2">
        <li class="nav-item">
            <a class="nav-link <?= $half === 1 ? 'active' : '' ?> rounded-pill" href="<?= htmlspecialchars(app_path('pages/labor-payroll.php') . '?month=' . urlencode($ym) . '&half=1') ?>">บัตรตอก: วันที่ 1–15</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $half === 2 ? 'active' : '' ?> rounded-pill" href="<?= htmlspecialchars(app_path('pages/labor-payroll.php') . '?month=' . urlencode($ym) . '&half=2') ?>">บัตรตอก: วันที่ 16–<?= (int) $dim ?></a>
        </li>
    </ul>

    <div class="punch-card p-2 p-md-3 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="fw-bold"><i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($halfLabel, ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <form method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>" id="payrollForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="year_month" value="<?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="half" value="<?= (int) $half ?>">

            <div class="table-responsive border rounded-3 bg-white">
                <table class="table table-sm table-bordered table-labor mb-0 text-center" id="laborTable">
                    <thead class="table-light">
                        <tr>
                            <th class="text-start sticky-worker ps-3" style="min-width: 200px;">คนงาน</th>
                            <th class="small">ค่าแรง<br>/วัน</th>
                            <th class="small">เบิกล่วงหน้า<br><span class="fw-normal">(ทั้งเดือน)</span></th>
                            <?php foreach ($daysList as $d): ?>
                                <th class="day-head">วัน<br><?= (int) $d ?></th>
                            <?php endforeach; ?>
                            <th class="small">วันมา<br>(รอบนี้)</th>
                            <th class="small">OT รวม<br>(รอบนี้)</th>
                            <th class="small">ยอดรวม<br>(รอบนี้)</th>
                            <th class="small sticky-sum">จ่ายจริง<br>(รอบนี้)</th>
                            <th class="small" style="width: 56px;"></th>
                        </tr>
                    </thead>
                    <tbody id="laborBody">
                        <?php if (count($sheetRows) === 0): ?>
                        <tr id="laborEmptyHint">
                            <td colspan="<?= 8 + count($daysList) ?>" class="text-center text-muted py-4">
                                ยังไม่มีรายชื่อในเดือนนี้ — กด <strong>เพิ่มคนงาน</strong> แล้วเลือกจากรายการหรือพิมพ์ชื่อใหม่ จากนั้นกดบันทึก
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
                                <input type="number" class="form-control form-control-sm inp-daily text-end" name="workers[<?= (int) $rowIdx ?>][daily_wage]" step="0.01" min="0" value="<?= htmlspecialchars((string) $dw, ENT_QUOTES, 'UTF-8') ?>">
                            </td>
                            <td>
                                <input type="number" class="form-control form-control-sm inp-advance text-end" name="workers[<?= (int) $rowIdx ?>][advance]" step="0.01" min="0" value="<?= htmlspecialchars((string) $adv, ENT_QUOTES, 'UTF-8') ?>">
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

            <div class="card border-0 bg-light rounded-3 p-3 mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="close_and_archive" value="1" id="closeAndArchive">
                    <label class="form-check-label small" for="closeAndArchive">
                        <strong>ตัดยอดและเก็บประวัติ</strong>
                    </label>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                <button type="button" class="btn btn-primary rounded-pill" id="btnAddRow"><i class="bi bi-plus-lg me-1"></i>เพิ่มคนงาน (แถวใหม่)</button>
                <button type="submit" class="btn btn-success rounded-pill px-4" id="btnSavePayroll"><i class="bi bi-save me-1"></i>บันทึกบัตรรอบนี้</button>
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
            <option data-id="<?= (int) $w['id'] ?>" value="<?= htmlspecialchars($w['full_name'], ENT_QUOTES, 'UTF-8') ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <template id="tplRow">
        <tr class="labor-row" data-row="__IDX__">
            <td class="text-start sticky-worker ps-2">
                <input type="hidden" name="workers[__IDX__][id]" value="0" class="inp-wid">
                <input type="text" class="form-control form-control-sm worker-search" name="workers[__IDX__][new_name]" value="" list="workerList" placeholder="พิมพ์ค้นหาหรือชื่อใหม่" autocomplete="off">
            </td>
            <td><input type="number" class="form-control form-control-sm inp-daily text-end" name="workers[__IDX__][daily_wage]" step="0.01" min="0" value=""></td>
            <td><input type="number" class="form-control form-control-sm inp-advance text-end" name="workers[__IDX__][advance]" step="0.01" min="0" value=""></td>
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

        document.getElementById('payrollForm')?.addEventListener('submit', function (ev) {
            const ck = document.getElementById('closeAndArchive');
            if (ck && ck.checked) {
                const ok = window.confirm('ยืนยันตัดยอดรอบนี้?\n\nระบบจะเก็บประวัติยอดรวมและรายคน แล้วล้างการเช็คชื่อ/OT ในช่วงวันที่ของบัตรนี้ (ค่าแรง/วันและเบิกล่วงหน้าไม่ถูกลบ)');
                if (!ok) {
                    ev.preventDefault();
                }
            }
        });
    })();
    </script>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
