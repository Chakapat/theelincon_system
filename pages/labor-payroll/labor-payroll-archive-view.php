<?php

declare(strict_types=1);


session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

use Theelincon\Rtdb\Db;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$head = null;
$lines = [];

if ($id > 0) {
    $head = Db::row('labor_payroll_archive', (string) $id)
        ?? Db::rowByIdField('labor_payroll_archive', $id);
    if ($head) {
        $aid = (int) ($head['id'] ?? $id);
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
    }
}

$halfLabel = '';
$docDisplay = '';
$groupNote = '';
$navYm = date('Y-m');
$navHalf = 1;
if ($head) {
    $h = (int) ($head['period_half'] ?? 1);
    $de = (int) ($head['day_end'] ?? 31);
    $halfLabel = $h === 1 ? 'วันที่ 1–15' : ('วันที่ 16–' . $de);
    $dn = trim((string) ($head['doc_number'] ?? ''));
    $docDisplay = $dn !== '' ? $dn : ('#' . (int) ($head['id'] ?? 0));
    $groupNote = trim((string) ($head['worker_group_note'] ?? ''));
    $py = (string) ($head['period_ym'] ?? '');
    if (preg_match('/^\d{4}-\d{2}$/', $py)) {
        $navYm = $py;
    }
    $navHalf = $h === 2 ? 2 : 1;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $head ? htmlspecialchars('เลขที่ ' . $docDisplay . ' | ตัดยอดค่าแรง', ENT_QUOTES, 'UTF-8') : 'รายละเอียดตัดยอดค่าแรง' ?> | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f6f8fb; }
        .card-v { border-radius: 14px; border: 1px solid #e3e8ef; box-shadow: 0 4px 18px rgba(15, 23, 42, 0.06); }
        /* ตารางรายบรรทัด — ตัวเลขไม่มีทศนิยม, ความกว้างพออ่านสบาย */
        .archive-lines-wrap {
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }
        .table-archive-lines {
            table-layout: fixed;
            width: 100%;
            font-size: 0.9rem;
        }
        .table-archive-lines th,
        .table-archive-lines td {
            padding: 0.4rem 0.45rem !important;
            vertical-align: middle;
            line-height: 1.35;
        }
        .table-archive-lines thead th {
            font-weight: 600;
            white-space: normal;
            hyphens: auto;
        }
        .table-archive-lines .col-name {
            width: 32%;
            word-break: break-word;
            white-space: normal;
        }
        .table-archive-lines .col-n {
            width: 11.5%;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .table-archive-lines .col-c {
            width: 7.5%;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .print-doc-header { display: none; }
        .print-footer-note { display: none; font-size: 10pt; color: #555; margin-top: 1.25rem; border-top: 1px solid #ccc; padding-top: 0.5rem; }

        @page {
            size: A4 portrait;
            margin: 14mm 12mm 16mm 12mm;
        }

        @media print {
            html, body {
                background: #fff !important;
                font-size: 10pt;
                color: #000;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .page-archive-print .navbar,
            .page-archive-print .no-print {
                display: none !important;
            }
            .page-archive-print .container,
            .page-archive-print .container-fluid {
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .print-doc-header {
                display: block !important;
                text-align: center;
                margin-bottom: 0.45rem;
                padding-bottom: 0.35rem;
                border-bottom: 1px solid #000;
            }
            .print-doc-header .co-name { font-size: 11pt; font-weight: 700; letter-spacing: 0.02em; line-height: 1.2; }
            .print-doc-header .doc-title { font-size: 9.5pt; font-weight: 600; margin-top: 0.15rem; line-height: 1.2; }
            .print-doc-header .doc-sub { font-size: 8pt; color: #333; margin-top: 0.1rem; line-height: 1.2; }
            /* ห้ามบังคับทั้งการ์ดใหญ่หลีกเลี่ยง page-break — ตารางหลายแถวจะถูกตัดหายเมื่อพิมพ์ */
            .card-v {
                border: 1px solid #000 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                break-inside: auto;
                page-break-inside: auto;
            }
            .archive-summary-block.card-v {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .page-archive-print .table-responsive {
                overflow: visible !important;
            }
            .card-v .card-body { padding: 0.75rem !important; }
            .archive-summary-block {
                margin-bottom: 0.5rem !important;
            }
            .archive-summary-block .card-body {
                padding: 0.35rem 0.45rem !important;
            }
            .archive-summary-block h5 {
                display: none !important;
            }
            .archive-summary-block .row {
                --bs-gutter-x: 0.35rem;
                --bs-gutter-y: 0.15rem;
            }
            .archive-summary-block .row > [class*="col-"] {
                flex: 0 0 33.333% !important;
                max-width: 33.333% !important;
                padding-top: 0.1rem;
                padding-bottom: 0.1rem;
            }
            .archive-summary-block .text-muted {
                font-size: 7.5pt !important;
                line-height: 1.15;
            }
            .archive-summary-block strong {
                font-size: 9pt !important;
                line-height: 1.2;
            }
            .archive-summary-block .fs-5 {
                font-size: 10pt !important;
            }
            .archive-summary-block + h6,
            #printArea > h6 {
                font-size: 9pt !important;
                margin-bottom: 0.2rem !important;
                margin-top: 0.15rem !important;
            }
            .archive-summary-block + * {
                margin-top: 0.25rem !important;
            }
            .archive-lines-wrap {
                max-width: 100% !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
            .table-print.table-archive-lines {
                max-width: 100%;
                margin-left: auto;
                margin-right: auto;
                font-size: 9.5pt;
            }
            .table-print {
                width: 100%;
                border-collapse: collapse;
                font-size: 9.5pt;
            }
            .table-print th,
            .table-print td {
                border: 1px solid #000 !important;
                padding: 0.32rem 0.4rem !important;
                vertical-align: middle;
            }
            .table-print thead th {
                background: #e8e8e8 !important;
                font-weight: 700;
            }
            .table-print thead { display: table-header-group; }
            .table-print tr { break-inside: avoid; page-break-inside: avoid; }
            h5 .bi, h6 .bi { display: none; }
            .text-muted { color: #333 !important; }
            .text-success { color: #000 !important; }
            .print-footer-note { display: block !important; }
        }
    </style>
</head>
<body class="page-archive-print">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container pb-5">
    <div class="mb-3 mt-2 no-print d-flex flex-wrap align-items-center gap-2">
        <a href="<?= htmlspecialchars(app_path('pages/labor-payroll/labor-payroll-history.php') . '?month=' . urlencode($navYm) . '&half=' . (int) $navHalf, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-secondary rounded-pill"><i class="bi bi-arrow-left me-1"></i>กลับประวัติ</a>
        <?php if ($head): ?>
        <a href="<?= htmlspecialchars(app_path('pages/labor-payroll/labor-payroll.php') . '?month=' . urlencode($navYm) . '&half=' . (int) $navHalf, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-primary rounded-pill" title="แก้บัตรตอกเดือนเดียวกับเอกสารนี้"><i class="bi bi-calendar3 me-1"></i>บัตรค่าแรงเดือนนี้</a>
        <?php endif; ?>
        <?php if ($head): ?>
        <a href="<?= htmlspecialchars(app_path('pages/labor-payroll/labor-payroll-archive-edit.php') . '?id=' . (int) $id, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-warning rounded-pill"><i class="bi bi-pencil me-1"></i>แก้ไข</a>
        <button type="button" class="btn btn-sm btn-primary rounded-pill" id="btnPrint"><i class="bi bi-printer me-1"></i>พิมพ์เอกสาร (A4)</button>
        <?php endif; ?>
    </div>

    <?php if (!empty($_GET['saved'])): ?>
        <div class="alert alert-success py-2 rounded-3 small no-print">บันทึกการแก้ไขแล้ว</div>
    <?php endif; ?>

    <?php if (!$head): ?>
        <div class="alert alert-danger rounded-3">ไม่พบรายการ</div>
    <?php else: ?>
        <?php if (count($lines) === 0 && (int) ($head['worker_count'] ?? 0) > 0): ?>
            <div class="alert alert-warning rounded-3 py-2 small no-print">
                ระบบไม่พบรายการบรรทัดใน <code>labor_payroll_archive_lines</code> ที่ผูกกับเอกสารนี้ (หัวเอกสารระบุ <?= (int) $head['worker_count'] ?> คน) — หากเพิ่งพิมพ์แล้วไม่เห็นตาราง ให้ลองพิมพ์อีกครั้งหลังอัปเดตหน้านี้ หรือเปิด <strong>แก้ไข</strong> เพื่อตรวจข้อมูล
            </div>
        <?php endif; ?>
        <div id="printArea">
            <header class="print-doc-header">
                <div class="co-name">THEELIN CON CO.,LTD.</div>
                <div class="doc-title">เอกสารสรุปค่าแรงคนงาน</div>
                <div class="doc-sub">เลขที่เอกสาร <?= htmlspecialchars($docDisplay, ENT_QUOTES, 'UTF-8') ?> · รหัสระบบ #<?= (int) $head['id'] ?> · พิมพ์เมื่อ <?= htmlspecialchars(date('d/m/Y H:i'), ENT_QUOTES, 'UTF-8') ?></div>
            </header>

            <div class="card card-v mb-4 archive-summary-block">
                <div class="card-body">
                    <h5 class="fw-bold mb-3"><i class="bi bi-receipt me-2 text-primary"></i>สรุปรายการ <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace"><?= htmlspecialchars($docDisplay, ENT_QUOTES, 'UTF-8') ?></span></h5>
                    <div class="row g-2 g-md-3 small">
                        <div class="col-md-3"><span class="text-muted">เดือน</span><br><strong><?= htmlspecialchars((string) $head['period_ym'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                        <div class="col-md-3"><span class="text-muted">บัตรตอก</span><br><strong><?= htmlspecialchars($halfLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>
                        <div class="col-md-3"><span class="text-muted">จำนวนคนงาน</span><br><strong><?= (int) $head['worker_count'] ?></strong></div>
                        <div class="col-md-4">
                            <span class="text-muted"><span class="d-none d-print-inline">ยอดรวม</span><span class="d-inline d-print-none">ยอดรวม (ก่อนหักเบิกล่วงหน้าในรอบ)</span></span><br>
                            <strong>฿<?= number_format(round((float) $head['total_gross']), 0, '.', ',') ?></strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted"><span class="d-none d-print-inline">จ่ายจริงรวม</span><span class="d-inline d-print-none">ยอดจ่ายจริงรวม (รอบนี้)</span></span><br>
                            <strong class="text-success fs-5">฿<?= number_format(round((float) $head['total_net']), 0, '.', ',') ?></strong>
                        </div>
                        <?php if ($groupNote !== ''): ?>
                        <div class="col-12">
                            <span class="text-muted">หมายเหตุ</span><br>
                            <strong><?= htmlspecialchars($groupNote, ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="table-responsive card card-v archive-lines-wrap">
                <table class="table table-sm table-bordered mb-0 align-middle table-print table-archive-lines">
                    <thead class="table-light">
                        <tr>
                            <th class="col-name">ชื่อคนงาน</th>
                            <th class="col-n text-end">ค่าแรง/วัน</th>
                            <th class="col-c text-center">วันมา</th>
                            <th class="col-c text-center">OT</th>
                            <th class="col-n text-end">เบิกล่วงหน้า</th>
                            <th class="col-n text-end">ยอดรวม</th>
                            <th class="col-n text-end">จ่ายจริง</th>
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
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($head): ?>
<script>
document.getElementById('btnPrint')?.addEventListener('click', function () {
    window.print();
});
</script>
<?php endif; ?>
</body>
</html>
