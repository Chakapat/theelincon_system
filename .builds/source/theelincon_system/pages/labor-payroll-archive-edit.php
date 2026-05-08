<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/connect_database.php';

use Theelincon\Rtdb\Db;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$handler = app_path('actions/labor-payroll-archive-handler.php');
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$head = null;
$lines = [];

if ($id > 0) {
    $head = Db::row('labor_payroll_archive', (string) $id);
    if ($head) {
        $aid = (int) ($head['id'] ?? 0);
        foreach (Db::filter('labor_payroll_archive_lines', static fn ($r) => (int) ($r['archive_id'] ?? 0) === $aid) as $ln) {
            $lines[] = $ln;
        }
        usort(
            $lines,
            static fn ($a, $b) => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0))
        );
        if (count($lines) === 0) {
            $lines = [['worker_id' => 0, 'worker_name' => '', 'days_present' => 0, 'ot_hours' => 0, 'daily_wage' => 0, 'advance_draw' => 0]];
        }
    }
}

$halfLabel = '';
$docDisplay = '';
if ($head) {
    $h = (int) ($head['period_half'] ?? 1);
    $de = (int) ($head['day_end'] ?? 31);
    $halfLabel = $h === 1 ? 'วันที่ 1–15' : ('วันที่ 16–' . $de);
    $dn = trim((string) ($head['doc_number'] ?? ''));
    $docDisplay = $dn !== '' ? $dn : ('#' . (int) ($head['id'] ?? 0));
}
$periodHalf = (int) ($head['period_half'] ?? 1) === 2 ? 2 : 1;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขประวัติตัดยอด | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f6f8fb; }
        .card-e { border-radius: 14px; border: 1px solid #e3e8ef; box-shadow: 0 4px 18px rgba(15, 23, 42, 0.06); }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 mt-2">
        <a href="<?= htmlspecialchars(app_path('pages/labor-payroll-history.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-secondary rounded-pill"><i class="bi bi-arrow-left me-1"></i>กลับประวัติ</a>
        <?php if ($head): ?>
        <a href="<?= htmlspecialchars(app_path('pages/labor-payroll-archive-view.php') . '?id=' . $id, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-primary rounded-pill"><i class="bi bi-eye me-1"></i>ดูรายละเอียด</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_GET['save_err'])): ?>
        <div class="alert alert-danger rounded-3 py-2">บันทึกไม่สำเร็จ — ต้องมีอย่างน้อย 1 แถวที่มีชื่อคนงาน</div>
    <?php endif; ?>

    <?php if (!$head): ?>
        <div class="alert alert-danger rounded-3">ไม่พบรายการ</div>
    <?php else: ?>
        <h4 class="fw-bold mb-3"><i class="bi bi-pencil-square me-2 text-warning"></i>แก้ไขประวัติตัดยอด</h4>

        <div class="card card-e mb-3">
            <div class="card-body small">
                <div class="row g-2">
                    <div class="col-md-3"><span class="text-muted">เลขที่เอกสาร</span><br><strong class="font-monospace"><?= htmlspecialchars($docDisplay, ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <div class="col-md-3"><span class="text-muted">เดือน</span><br><strong><?= htmlspecialchars((string) $head['period_ym'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <div class="col-md-3"><span class="text-muted">บัตรตอก</span><br><strong><?= htmlspecialchars($halfLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <div class="col-md-3"><span class="text-muted">วันที่ตัดยอด (เดิม)</span><br><strong><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $head['closed_at'])), ENT_QUOTES, 'UTF-8') ?></strong></div>
                </div>
                <p class="text-muted small mb-0 mt-2">ยอดรวม / จ่ายจริง จะคำนวณใหม่จากแต่ละแถวตามสูตรเดิม (หักเบิกล่วงหน้าเฉพาะรอบบัตร 16–สิ้นเดือน)</p>
            </div>
        </div>

        <form method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>" id="formArchiveSave">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="archive_id" value="<?= (int) $head['id'] ?>">

            <div class="table-responsive card card-e">
                <table class="table table-sm align-middle mb-0" id="lineTable">
                    <thead class="table-light">
                        <tr>
                            <th>ชื่อคนงาน</th>
                            <th style="width:5rem;">วันมา</th>
                            <th style="width:5.5rem;">OT</th>
                            <th style="width:6rem;">ค่าแรง/วัน</th>
                            <th style="width:6rem;">เบิกล่วงหน้า</th>
                            <th class="text-end" style="width:6.5rem;">ยอดรวม</th>
                            <th class="text-end" style="width:6.5rem;">จ่ายจริง</th>
                            <th style="width:3rem;"></th>
                        </tr>
                    </thead>
                    <tbody id="lineBody">
                        <?php foreach ($lines as $idx => $ln): ?>
                        <tr class="line-row">
                            <td>
                                <input type="hidden" name="lines[<?= (int) $idx ?>][worker_id]" value="<?= (int) ($ln['worker_id'] ?? 0) ?>">
                                <input type="text" class="form-control form-control-sm" name="lines[<?= (int) $idx ?>][worker_name]" maxlength="200" value="<?= htmlspecialchars((string) $ln['worker_name'], ENT_QUOTES, 'UTF-8') ?>">
                            </td>
                            <td><input type="number" class="form-control form-control-sm inp-days" name="lines[<?= (int) $idx ?>][days_present]" min="0" step="1" value="<?= (int) $ln['days_present'] ?>"></td>
                            <td><input type="number" class="form-control form-control-sm inp-ot" name="lines[<?= (int) $idx ?>][ot_hours]" min="0" step="0.25" value="<?= htmlspecialchars(rtrim(rtrim(number_format((float) $ln['ot_hours'], 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input type="number" class="form-control form-control-sm inp-daily" name="lines[<?= (int) $idx ?>][daily_wage]" min="0" step="0.01" value="<?= htmlspecialchars((string) $ln['daily_wage'], ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input type="number" class="form-control form-control-sm inp-adv" name="lines[<?= (int) $idx ?>][advance_draw]" min="0" step="0.01" value="<?= htmlspecialchars((string) $ln['advance_draw'], ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td class="text-end small td-gross">—</td>
                            <td class="text-end small td-net fw-semibold">—</td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger border-0 btn-del-line" title="ลบแถว"><i class="bi bi-x-lg"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                <button type="button" class="btn btn-outline-primary rounded-pill" id="btnAddLine"><i class="bi bi-plus-lg me-1"></i>เพิ่มแถว</button>
                <button type="submit" class="btn btn-success rounded-pill px-4"><i class="bi bi-save me-1"></i>บันทึกการแก้ไข</button>
            </div>
        </form>

        <hr class="my-4">
        <form method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>" id="formArchiveDel" onsubmit="return confirm('ลบเอกสารนี้และรายละเอียดทั้งหมดถาวร — ยืนยัน?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="archive_id" value="<?= (int) $head['id'] ?>">
            <button type="submit" class="btn btn-outline-danger rounded-pill"><i class="bi bi-trash3 me-1"></i>ลบเอกสารนี้ทั้งฉบับ</button>
        </form>

        <template id="tplLine">
            <tr class="line-row">
                <td>
                    <input type="hidden" name="lines[__I__][worker_id]" value="0">
                    <input type="text" class="form-control form-control-sm" name="lines[__I__][worker_name]" maxlength="200" placeholder="ชื่อคนงาน">
                </td>
                <td><input type="number" class="form-control form-control-sm inp-days" name="lines[__I__][days_present]" min="0" step="1" value="0"></td>
                <td><input type="number" class="form-control form-control-sm inp-ot" name="lines[__I__][ot_hours]" min="0" step="0.25" value=""></td>
                <td><input type="number" class="form-control form-control-sm inp-daily" name="lines[__I__][daily_wage]" min="0" step="0.01" value=""></td>
                <td><input type="number" class="form-control form-control-sm inp-adv" name="lines[__I__][advance_draw]" min="0" step="0.01" value=""></td>
                <td class="text-end small td-gross">—</td>
                <td class="text-end small td-net fw-semibold">—</td>
                <td><button type="button" class="btn btn-sm btn-outline-danger border-0 btn-del-line" title="ลบแถว"><i class="bi bi-x-lg"></i></button></td>
            </tr>
        </template>

        <script>
        (function () {
            const periodHalf = <?= (int) $periodHalf ?>;
            let idx = <?= count($lines) ?>;

            function parseNum(el) {
                if (!el) return 0;
                const v = parseFloat(String(el.value).replace(/,/g, ''));
                return isFinite(v) ? v : 0;
            }
            function fmt(n) {
                return (Math.round(n * 100) / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            function recalcRow(tr) {
                const daily = parseNum(tr.querySelector('.inp-daily'));
                const adv = parseNum(tr.querySelector('.inp-adv'));
                const days = parseInt(String(tr.querySelector('.inp-days')?.value || '0'), 10) || 0;
                const ot = parseNum(tr.querySelector('.inp-ot'));
                const otRate = (daily / 8) * 1.5;
                const gross = days * daily + ot * otRate;
                let net = gross;
                if (periodHalf === 2) net = gross - adv;
                const g = tr.querySelector('.td-gross');
                const n = tr.querySelector('.td-net');
                if (g) g.textContent = fmt(gross);
                if (n) n.textContent = fmt(net);
            }
            function recalcAll() {
                document.querySelectorAll('#lineBody .line-row').forEach(recalcRow);
            }
            document.getElementById('lineBody')?.addEventListener('input', (e) => {
                if (e.target.closest('.line-row')) recalcAll();
            });
            document.getElementById('btnAddLine')?.addEventListener('click', () => {
                const tpl = document.getElementById('tplLine');
                if (!tpl) return;
                const html = tpl.innerHTML.replace(/__I__/g, String(idx++));
                const wrap = document.createElement('tbody');
                wrap.innerHTML = html.trim();
                document.getElementById('lineBody').appendChild(wrap.firstElementChild);
                recalcAll();
            });
            document.getElementById('lineBody')?.addEventListener('click', (e) => {
                const b = e.target.closest('.btn-del-line');
                if (!b) return;
                const tr = b.closest('tr');
                if (tr) tr.remove();
                recalcAll();
            });
            recalcAll();
        })();
        </script>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
