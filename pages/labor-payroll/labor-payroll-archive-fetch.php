<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

use Theelincon\Rtdb\Db;

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$head = Db::row('labor_payroll_archive', (string) $id)
    ?? Db::rowByIdField('labor_payroll_archive', $id);
if (!$head) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$aid = (int) ($head['id'] ?? $id);
$lines = [];
foreach (Db::tableRows('labor_payroll_archive_lines') as $ln) {
    if (!is_array($ln)) {
        continue;
    }
    $rawArch = $ln['archive_id'] ?? null;
    if ($rawArch === null || $rawArch === '') {
        continue;
    }
    if ((int) $rawArch !== $aid) {
        continue;
    }
    $lines[] = $ln;
}
usort(
    $lines,
    static fn ($a, $b): int => ((int) ($a['line_no'] ?? $a['id'] ?? 0)) <=> ((int) ($b['line_no'] ?? $b['id'] ?? 0))
);

$h = (int) ($head['period_half'] ?? 1);
$de = (int) ($head['day_end'] ?? 31);
$halfRange = $h === 1 ? 'วันที่ 1–15' : ('วันที่ 16–' . $de);
$dn = trim((string) ($head['doc_number'] ?? ''));
$docDisplay = $dn !== '' ? $dn : ('#' . $aid);
$periodNote = trim((string) ($head['period_note'] ?? ''));
$groupNote = trim((string) ($head['worker_group_note'] ?? ''));

$part = (string) ($_GET['part'] ?? '');
if ($part === 'edit') {
    $linesEdit = $lines;
    if (count($linesEdit) === 0) {
        $linesEdit = [['worker_id' => 0, 'worker_name' => '', 'days_present' => 0, 'ot_hours' => 0, 'daily_wage' => 0, 'advance_draw' => 0]];
    }
    $navYm = preg_match('/^\d{4}-\d{2}$/', (string) ($head['period_ym'] ?? '')) ? (string) $head['period_ym'] : date('Y-m');
    $periodHalfEdit = (int) ($head['period_half'] ?? 1) === 2 ? 2 : 1;
    $tsEdit = strtotime($navYm . '-01');
    $dimEdit = $tsEdit ? (int) date('t', $tsEdit) : 31;
    $handler = app_path('actions/labor-payroll-archive-handler.php');
    $caTs = strtotime((string) ($head['closed_at'] ?? ''));
    $closedDisplay = $caTs ? date('d/m/Y', $caTs) : '—';
    $nextLineIdx = count($linesEdit);

    ob_start();
    ?>
    <div id="archiveEditFormWrap" class="archive-edit-modal-inner" data-next-line-idx="<?= (int) $nextLineIdx ?>">
        <form method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>" id="formArchiveSaveModal">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="archive_id" value="<?= $aid ?>">

            <div class="card border mb-3 bg-light bg-opacity-50">
                <div class="card-body small py-2 px-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <span class="text-muted d-block mb-1">เลขที่เอกสาร</span>
                            <strong class="font-monospace"><?= htmlspecialchars($docDisplay, ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1" for="archiveEditPeriodYm">เดือน (ปี-เดือน)</label>
                            <input type="month" class="form-control form-control-sm" name="period_ym" id="archiveEditPeriodYm" required
                                   value="<?= htmlspecialchars($navYm, ENT_QUOTES, 'UTF-8') ?>"
                                   min="2000-01" max="2099-12">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1" for="archiveEditPeriodHalf">งวดบัตรตอก</label>
                            <select class="form-select form-select-sm" name="period_half" id="archiveEditPeriodHalf">
                                <option value="1" <?= $periodHalfEdit === 1 ? 'selected' : '' ?>>งวด 1–15</option>
                                <option value="2" <?= $periodHalfEdit === 2 ? 'selected' : '' ?>>งวด 16–<?= (int) $dimEdit ?> (สิ้นเดือน)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <span class="text-muted d-block mb-1">วันที่ตัดยอด (เดิม)</span>
                            <strong><?= htmlspecialchars($closedDisplay, ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                    </div>
                    <p class="text-muted small mb-0 mt-2">ยอดรวม / จ่ายจริง จะคำนวณใหม่จากแต่ละแถว — หักเบิกล่วงหน้าเฉพาะเมื่อเลือก <strong>งวด 16–สิ้นเดือน</strong></p>
                </div>
            </div>

            <div class="table-responsive border rounded">
                <table class="table table-sm align-middle mb-0" id="archiveEditLineTable">
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
                    <tbody id="archiveEditLineBody">
                        <?php foreach ($linesEdit as $idx => $ln): ?>
                        <tr class="line-row">
                            <td>
                                <input type="hidden" name="lines[<?= (int) $idx ?>][worker_id]" value="<?= (int) ($ln['worker_id'] ?? 0) ?>">
                                <input type="text" class="form-control form-control-sm" name="lines[<?= (int) $idx ?>][worker_name]" maxlength="200" value="<?= htmlspecialchars((string) ($ln['worker_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </td>
                            <td><input type="number" class="form-control form-control-sm inp-days" name="lines[<?= (int) $idx ?>][days_present]" min="0" step="1" value="<?= (int) ($ln['days_present'] ?? 0) ?>"></td>
                            <td><input type="number" class="form-control form-control-sm inp-ot" name="lines[<?= (int) $idx ?>][ot_hours]" min="0" step="0.25" value="<?= htmlspecialchars(rtrim(rtrim(number_format((float) ($ln['ot_hours'] ?? 0), 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input type="number" class="form-control form-control-sm inp-daily" name="lines[<?= (int) $idx ?>][daily_wage]" min="0" step="0.01" value="<?= htmlspecialchars((string) ($ln['daily_wage'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input type="number" class="form-control form-control-sm inp-adv" name="lines[<?= (int) $idx ?>][advance_draw]" min="0" step="0.01" value="<?= htmlspecialchars((string) ($ln['advance_draw'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td class="text-end small td-gross">—</td>
                            <td class="text-end small td-net fw-semibold">—</td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger border-0 btn-del-line" title="ลบแถว"><i class="bi bi-x-lg"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                <button type="button" class="btn btn-outline-primary btn-sm rounded-pill" id="archiveEditBtnAddLine"><i class="bi bi-plus-lg me-1"></i>เพิ่มแถว</button>
                <button type="submit" class="btn btn-success btn-sm rounded-pill px-3"><i class="bi bi-save me-1"></i>บันทึกการแก้ไข</button>
            </div>
        </form>

        <hr class="my-3">
        <form method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>" id="formArchiveDelModal" class="mb-0" onsubmit="return confirm('ลบเอกสารนี้และรายละเอียดทั้งหมดถาวร — ยืนยัน?');">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="archive_id" value="<?= $aid ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill"><i class="bi bi-trash3 me-1"></i>ลบเอกสารนี้ทั้งฉบับ</button>
        </form>
    </div>
    <?php
    $editFormHtml = ob_get_clean();
    echo json_encode(
        [
            'ok' => true,
            'docDisplay' => $docDisplay,
            'editFormHtml' => $editFormHtml,
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

ob_start();
?>
<table class="table table-sm table-bordered mb-0 align-middle table-archive-lines-modal">
    <thead class="table-light">
        <tr>
            <th class="col-name">ชื่อคนงาน</th>
            <th class="col-n text-end">ค่าแรง/วัน</th>
            <th class="col-c text-center">วันมา</th>
            <th class="col-c text-center">OT</th>
            <th class="col-n text-end">เบิกล่วงหน้า</th>
            <th class="col-n text-end">ยอดรวม</th>
            <th class="col-n text-end">ยอดสุทธิ</th>
        </tr>
    </thead>
    <tbody>
        <?php if (count($lines) === 0): ?>
        <tr>
            <td colspan="7" class="text-center text-muted py-4">ไม่มีรายการบรรทัด</td>
        </tr>
        <?php else: ?>
        <?php foreach ($lines as $ln): ?>
        <tr>
            <td class="col-name"><?= htmlspecialchars((string) ($ln['worker_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="col-n text-end"><?= number_format(round((float) ($ln['daily_wage'] ?? 0)), 0, '.', ',') ?></td>
            <td class="col-c text-center"><?= (int) ($ln['days_present'] ?? 0) ?></td>
            <td class="col-c text-center"><?= number_format(round((float) ($ln['ot_hours'] ?? 0)), 0, '.', ',') ?></td>
            <td class="col-n text-end"><?= number_format(round((float) ($ln['advance_draw'] ?? 0)), 0, '.', ',') ?></td>
            <td class="col-n text-end"><?= number_format(round((float) ($ln['gross_amount'] ?? 0)), 0, '.', ',') ?></td>
            <td class="col-n text-end fw-semibold"><?= number_format(round((float) ($ln['net_amount'] ?? 0)), 0, '.', ',') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
<?php
$modalTable = ob_get_clean();

ob_start();
?>
<div class="print-doc-header">
    <div class="co-name">THEELIN CON CO.,LTD.</div>
    <div class="doc-title">เอกสารสรุปค่าแรงคนงาน</div>
    <?php if ($groupNote !== ''): ?>
    <div class="doc-group-head"><?= htmlspecialchars($groupNote, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <div class="doc-sub">เลขที่เอกสาร <?= htmlspecialchars($docDisplay, ENT_QUOTES, 'UTF-8') ?></div>
</div>
<div class="archive-summary-block-print card border mb-3">
    <div class="card-body small py-2 px-3">
        <div class="row g-2">
            <div class="col-6"><span class="text-muted">เดือน</span><br><strong><?= htmlspecialchars((string) $head['period_ym'], ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div class="col-6"><span class="text-muted">ช่วง</span><br><strong><?= htmlspecialchars($halfRange, ENT_QUOTES, 'UTF-8') ?></strong></div>
            <?php if ($periodNote !== ''): ?>
            <div class="col-12"><span class="text-muted">หมายเหตุ</span><br><strong><?= htmlspecialchars($periodNote, ENT_QUOTES, 'UTF-8') ?></strong></div>
            <?php endif; ?>
            <div class="col-12 archive-print-net-highlight mt-1">
                <span class="text-muted d-block mb-1">ยอดสุทธิ</span>
                <div class="archive-print-net-box fw-bold">฿<?= number_format(round((float) $head['total_net']), 0, '.', ',') ?></div>
            </div>
        </div>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-sm table-bordered mb-0 align-middle table-print table-archive-lines">
        <thead class="table-light">
            <tr>
                <th class="col-name">ชื่อคนงาน</th>
                <th class="col-n text-end">ค่าแรง/วัน</th>
                <th class="col-c text-center">วันมา</th>
                <th class="col-c text-center">OT</th>
                <th class="col-n text-end">เบิกล่วงหน้า</th>
                <th class="col-n text-end">ยอดรวม</th>
                <th class="col-n text-end">ยอดสุทธิ</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($lines) === 0): ?>
            <tr>
                <td colspan="7" class="text-center text-muted py-4">ไม่มีรายการบรรทัด</td>
            </tr>
            <?php else: ?>
            <?php foreach ($lines as $ln): ?>
            <tr>
                <td class="col-name"><?= htmlspecialchars((string) ($ln['worker_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td class="col-n text-end"><?= number_format(round((float) ($ln['daily_wage'] ?? 0)), 0, '.', ',') ?></td>
                <td class="col-c text-center"><?= (int) ($ln['days_present'] ?? 0) ?></td>
                <td class="col-c text-center"><?= number_format(round((float) ($ln['ot_hours'] ?? 0)), 0, '.', ',') ?></td>
                <td class="col-n text-end"><?= number_format(round((float) ($ln['advance_draw'] ?? 0)), 0, '.', ',') ?></td>
                <td class="col-n text-end"><?= number_format(round((float) ($ln['gross_amount'] ?? 0)), 0, '.', ',') ?></td>
                <td class="col-n text-end fw-semibold"><?= number_format(round((float) ($ln['net_amount'] ?? 0)), 0, '.', ',') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
$printBlock = ob_get_clean();

echo json_encode(
    [
        'ok' => true,
        'docDisplay' => $docDisplay,
        'modalTable' => $modalTable,
        'printBlock' => $printBlock,
    ],
    JSON_UNESCAPED_UNICODE
);
