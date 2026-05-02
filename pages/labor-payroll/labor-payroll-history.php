<?php



declare(strict_types=1);





session_start();

require_once dirname(__DIR__, 2) . '/config/connect_database.php';



use Theelincon\Rtdb\Db;



if (!isset($_SESSION['user_id'])) {

    header('Location: ' . app_path('sign-in.php'));

    exit;

}



$histYm = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : date('Y-m');

$histHalf = (int) ($_GET['half'] ?? 1) === 2 ? 2 : 1;

$openArchiveId = isset($_GET['open_id']) ? (int) $_GET['open_id'] : 0;

$editOpenId = isset($_GET['edit_open_id']) ? (int) $_GET['edit_open_id'] : 0;



$rows = Db::tableRows('labor_payroll_archive');

usort(

    $rows,

    static function ($a, $b): int {

        $ta = strtotime((string) ($a['closed_at'] ?? '')) ?: 0;

        $tb = strtotime((string) ($b['closed_at'] ?? '')) ?: 0;

        if ($ta !== $tb) {

            return $tb <=> $ta;

        }



        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));

    }

);



function labor_half_label(array $r): string

{

    $h = (int) ($r['period_half'] ?? 1);

    $de = (int) ($r['day_end'] ?? 31);



    return $h === 1 ? 'วันที่ 1–15' : ('วันที่ 16–' . $de);

}

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>ประวัติตัดยอดค่าแรงคนงาน | THEELIN CON</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>

        body { font-family: 'Sarabun', sans-serif; background: #f6f8fb; }

        .card-hist { border-radius: 14px; border: 1px solid #e3e8ef; box-shadow: 0 4px 18px rgba(15, 23, 42, 0.06); }

        .table-archive-lines-modal {

            table-layout: fixed;

            width: 100%;

            font-size: 0.88rem;

        }

        .table-archive-lines-modal th,

        .table-archive-lines-modal td {

            padding: 0.4rem 0.45rem !important;

            vertical-align: middle;

            line-height: 1.35;

        }

        .table-archive-lines-modal .col-name {

            width: 32%;

            word-break: break-word;

        }

        .table-archive-lines-modal .col-n {

            width: 11.5%;

            font-variant-numeric: tabular-nums;

            white-space: nowrap;

        }

        .table-archive-lines-modal .col-c {

            width: 7.5%;

            font-variant-numeric: tabular-nums;

        }

        #archiveDetailModal .modal-body { max-height: min(70vh, 640px); overflow: auto; }

        #archiveEditModal .modal-body { max-height: min(78vh, 720px); overflow: auto; }

        #archivePrintRoot {

            position: absolute;

            left: -9999px;

            top: 0;

            width: 100%;

            max-width: 100%;

            box-sizing: border-box;

            padding: 0;

        }

        .print-doc-header { display: none; }

        @page {

            size: A4 portrait;

            /* ขอบกว้างขึ้นเล็กน้อย กันตารางล้นขอบพิมพ์ */
            margin: 14mm 14mm 16mm 14mm;

        }

        @media print {

            html, body {

                background: #fff !important;

                font-size: 10pt;

                color: #000;

                -webkit-print-color-adjust: exact;

                print-color-adjust: exact;

            }

            .page-hist-print .navbar,

            .page-hist-print .no-print,

            .page-hist-print .container,

            .page-hist-print #archiveDetailModal,

            .page-hist-print #archiveEditModal {

                display: none !important;

            }

            /* Bootstrap modal backdrop ทับเอกสารตอน print preview — ต้องซ่อน */
            .modal-backdrop,

            .modal-backdrop.fade,

            .modal-backdrop.show {

                display: none !important;

                opacity: 0 !important;

                visibility: hidden !important;

                pointer-events: none !important;

            }

            #archivePrintRoot {

                display: block !important;

                position: static !important;

                left: auto !important;

                width: 100% !important;

                max-width: 100% !important;

                padding: 0 !important;

                margin: 0 !important;

                box-sizing: border-box !important;

                overflow: visible !important;

            }

            #archivePrintRoot *,

            #archivePrintRoot *::before,

            #archivePrintRoot *::after {

                box-sizing: border-box !important;

            }

            /* Bootstrap grid ดันขอบเกินหน้าเมื่อพิมพ์ */
            #archivePrintRoot .row {

                margin-left: 0 !important;

                margin-right: 0 !important;

                --bs-gutter-x: 0.5rem;

            }

            #archivePrintRoot .card {

                border-width: 1px !important;

                max-width: 100% !important;

            }

            #archivePrintRoot .card-body {

                padding: 0.35rem 0.5rem !important;

            }

            #archivePrintRoot .table-responsive {

                overflow: visible !important;

                max-width: 100% !important;

                width: 100% !important;

                display: block !important;

                -webkit-overflow-scrolling: auto !important;

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

            .print-doc-header .doc-group-head { font-size: 10pt; font-weight: 600; margin-top: 0.25rem; line-height: 1.3; color: #000; }

            .print-doc-header .doc-sub { font-size: 8pt; color: #333; margin-top: 0.15rem; line-height: 1.2; }

            .archive-summary-block-print {

                border: 1px solid #000 !important;

                border-radius: 0 !important;

                break-inside: avoid;

                page-break-inside: avoid;

            }

            .archive-summary-block-print .text-muted { color: #333 !important; font-size: 8pt; }

            .archive-summary-block-print strong { font-size: 9.5pt; }

            .archive-print-net-highlight .text-muted { font-size: 8.5pt !important; font-weight: 600; }

            .archive-print-net-box {

                display: inline-block;

                font-size: 12pt !important;

                letter-spacing: 0.02em;

                padding: 0.28rem 0.55rem;

                border: 2px solid #000 !important;

                background: #f0f4f8 !important;

                border-radius: 4px;

            }

            .table-print.table-archive-lines {

                table-layout: fixed;

                width: 100% !important;

                max-width: 100% !important;

                border-collapse: collapse;

                font-size: 8.5pt;

            }

            .table-print .col-name {

                width: 22%;

                word-break: break-word;

                overflow-wrap: break-word;

            }

            .table-print .col-n { width: 12%; }

            .table-print .col-c { width: 7%; }

            .table-print th,

            .table-print td {

                border: 1px solid #000 !important;

                padding: 0.2rem 0.25rem !important;

                vertical-align: middle;

                font-size: 8.5pt;

            }

            .table-print thead th {

                background: #e8e8e8 !important;

                font-weight: 700;

            }

            .table-print thead { display: table-header-group; }

            .table-print tr { break-inside: avoid; page-break-inside: avoid; }

        }

    </style>

</head>

<body class="page-hist-print">



<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>



<div class="container pb-5">

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4 mt-2">

        <div>

            <h4 class="fw-bold mb-1"><i class="bi bi-archive me-2 text-primary"></i>หน้าประวัติค่าแรงคนงาน</h4>

        </div>

        <div class="d-flex flex-column align-items-end gap-2">

            <a href="<?= htmlspecialchars(app_path('pages/labor-payroll/labor-payroll.php') . '?month=' . urlencode($histYm) . '&half=' . (int) $histHalf, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill no-print"><i class="bi bi-arrow-left me-1"></i>ย้อนกลับ</a>

        </div>

    </div>



    <?php if (count($rows) === 0): ?>

        <div class="card card-hist"><div class="card-body text-center text-muted py-5">ยังไม่มีรายการตัดยอด</div></div>

    <?php else: ?>

        <?php if (!empty($_GET['deleted'])): ?>

            <div class="alert alert-success py-2 rounded-3 small">ลบรายการแล้ว</div>

        <?php endif; ?>

        <?php if (!empty($_GET['saved'])): ?>

            <div class="alert alert-success py-2 rounded-3 small">บันทึกการแก้ไขแล้ว</div>

        <?php endif; ?>

        <?php if (!empty($_GET['save_err'])): ?>

            <div class="alert alert-danger py-2 rounded-3 small">บันทึกไม่สำเร็จ — ต้องมีอย่างน้อย 1 แถวที่มีชื่อคนงาน</div>

        <?php endif; ?>

        <div class="table-responsive card card-hist">

            <table class="table table-hover align-middle mb-0">

                <thead class="table-light">

                    <tr>

                        <th>เลขที่เอกสาร</th>

                        <th>เดือน</th>

                        <th>ช่วง</th>

                        <th>ชื่อกลุ่ม</th>

                        <th class="text-end">ยอดสุทธิ</th>

                        <th class="text-end" style="min-width: 200px;">จัดการ</th>

                    </tr>

                </thead>

                <tbody>

                    <?php

                    $archHandler = app_path('actions/labor-payroll-archive-handler.php');

                    foreach ($rows as $r):

                        $rid = (int) $r['id'];

                    ?>

                    <tr>

                        <td><span class="badge text-bg-light border font-monospace"><?= htmlspecialchars((string) ($r['doc_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></td>

                        <td class="fw-semibold"><?= htmlspecialchars((string) $r['period_ym'], ENT_QUOTES, 'UTF-8') ?></td>

                        <td><?= htmlspecialchars(labor_half_label($r), ENT_QUOTES, 'UTF-8') ?></td>

                        <td class="small"><?php
                            $gname = trim((string) ($r['worker_group_note'] ?? ''));
                            if ($gname !== '') {
                                echo htmlspecialchars($gname, ENT_QUOTES, 'UTF-8');
                            } else {
                                echo '<span class="text-muted">—</span>';
                            }
                        ?></td>

                        <td class="text-end fw-bold text-success"><?= number_format((float) $r['total_net'], 2) ?></td>

                        <td class="text-end">

                            <div class="btn-group btn-group-sm shadow-sm">

                                <button type="button" class="btn btn-outline-primary btn-archive-view" data-archive-id="<?= $rid ?>" title="ดู"><i class="bi bi-eye"></i></button>

                                <button type="button" class="btn btn-outline-warning btn-archive-edit" data-archive-id="<?= $rid ?>" title="แก้ไข"><i class="bi bi-pencil"></i></button>

                            </div>

                            <form method="post" action="<?= htmlspecialchars($archHandler, ENT_QUOTES, 'UTF-8') ?>" class="d-inline ms-1" onsubmit="return confirm('ลบรายการตัดยอดนี้ถาวร — ยืนยัน?');">

                                <?php csrf_field(); ?>

                                <input type="hidden" name="action" value="delete">

                                <input type="hidden" name="archive_id" value="<?= $rid ?>">

                                <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash3"></i></button>

                            </form>

                        </td>

                    </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</div>



<div id="archivePrintRoot" aria-hidden="true"></div>



<div class="modal fade" id="archiveDetailModal" tabindex="-1" aria-labelledby="archiveDetailModalLabel" aria-hidden="true">

    <div class="modal-dialog modal-xl modal-dialog-scrollable">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title" id="archiveDetailModalLabel">รายละเอียดบรรทัด</h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>

            </div>

            <div class="modal-body">

                <div id="archiveModalLoading" class="text-center text-muted py-5 d-none">กำลังโหลด…</div>

                <div id="archiveModalError" class="alert alert-danger d-none"></div>

                <div id="archiveModalTableWrap" class="table-responsive"></div>

            </div>

            <div class="modal-footer">

                <button type="button" class="btn btn-primary rounded-pill" id="btnArchivePrint" disabled><i class="bi bi-printer me-1"></i>พิมพ์เอกสาร (A4)</button>

                <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">ปิด</button>

            </div>

        </div>

    </div>

</div>



<div class="modal fade" id="archiveEditModal" tabindex="-1" aria-labelledby="archiveEditModalLabel" aria-hidden="true">

    <div class="modal-dialog modal-xl modal-dialog-scrollable">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title" id="archiveEditModalLabel">แก้ไขประวัติตัดยอด</h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>

            </div>

            <div class="modal-body">

                <div id="archiveEditLoading" class="text-center text-muted py-5 d-none">กำลังโหลด…</div>

                <div id="archiveEditError" class="alert alert-danger d-none"></div>

                <div id="archiveEditBodyInner"></div>

            </div>

            <div class="modal-footer">

                <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">ปิด</button>

            </div>

        </div>

    </div>

</div>



<template id="archiveEditTplLine">

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



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>

(function () {

    const fetchBase = <?= json_encode(app_path('pages/labor-payroll/labor-payroll-archive-fetch.php'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;

    const modalEl = document.getElementById('archiveDetailModal');

    const modal = modalEl ? new bootstrap.Modal(modalEl) : null;

    const titleEl = document.getElementById('archiveDetailModalLabel');

    const loadingEl = document.getElementById('archiveModalLoading');

    const errEl = document.getElementById('archiveModalError');

    const wrapEl = document.getElementById('archiveModalTableWrap');

    const printRoot = document.getElementById('archivePrintRoot');

    const btnPrint = document.getElementById('btnArchivePrint');

    let currentPrintHtml = '';

    const editModalEl = document.getElementById('archiveEditModal');

    const editModal = editModalEl ? new bootstrap.Modal(editModalEl) : null;

    const editTitleEl = document.getElementById('archiveEditModalLabel');

    const editLoadingEl = document.getElementById('archiveEditLoading');

    const editErrEl = document.getElementById('archiveEditError');

    const editInnerEl = document.getElementById('archiveEditBodyInner');

    function laborArchiveEditGetPeriodHalf(root) {

        const sel = root.querySelector('#archiveEditPeriodHalf');

        return sel && String(sel.value) === '2' ? 2 : 1;

    }

    function laborArchiveEditParseNum(el) {

        if (!el) return 0;

        const v = parseFloat(String(el.value).replace(/,/g, ''));

        return isFinite(v) ? v : 0;

    }

    function laborArchiveEditFmt(n) {

        return (Math.round(n * 100) / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    }

    function laborArchiveEditRecalcAll(root) {

        if (!root) return;

        const ph = laborArchiveEditGetPeriodHalf(root);

        root.querySelectorAll('#archiveEditLineBody .line-row').forEach(function (tr) {

            const daily = laborArchiveEditParseNum(tr.querySelector('.inp-daily'));

            const adv = laborArchiveEditParseNum(tr.querySelector('.inp-adv'));

            const daysEl = tr.querySelector('.inp-days');

            const days = parseInt(String(daysEl && daysEl.value || '0'), 10) || 0;

            const ot = laborArchiveEditParseNum(tr.querySelector('.inp-ot'));

            const otRate = (daily / 8) * 1.5;

            const gross = days * daily + ot * otRate;

            let net = gross;

            if (ph === 2) net = gross - adv;

            const g = tr.querySelector('.td-gross');

            const ne = tr.querySelector('.td-net');

            if (g) g.textContent = laborArchiveEditFmt(gross);

            if (ne) ne.textContent = laborArchiveEditFmt(net);

        });

    }

    if (editModalEl) {

        editModalEl.addEventListener('click', function (e) {

            if (e.target.closest('#archiveEditBtnAddLine')) {

                e.preventDefault();

                const wrap = editModalEl.querySelector('#archiveEditFormWrap');

                const lineBody = editModalEl.querySelector('#archiveEditLineBody');

                const tpl = document.getElementById('archiveEditTplLine');

                if (!wrap || !lineBody || !tpl) return;

                let idx = parseInt(wrap.getAttribute('data-next-line-idx') || '0', 10) || 0;

                const html = tpl.innerHTML.replace(/__I__/g, String(idx));

                const tb = document.createElement('tbody');

                tb.innerHTML = html.trim();

                lineBody.appendChild(tb.firstElementChild);

                wrap.setAttribute('data-next-line-idx', String(idx + 1));

                laborArchiveEditRecalcAll(editModalEl);

                return;

            }

            const delBtn = e.target.closest('.btn-del-line');

            if (delBtn) {

                const tr = delBtn.closest('tr');

                if (tr) tr.remove();

                laborArchiveEditRecalcAll(editModalEl);

            }

        });

        editModalEl.addEventListener('change', function (e) {

            if (e.target.id === 'archiveEditPeriodHalf') laborArchiveEditRecalcAll(editModalEl);

        });

        editModalEl.addEventListener('input', function (e) {

            if (e.target.closest('#archiveEditLineBody .line-row')) laborArchiveEditRecalcAll(editModalEl);

        });

    }

    function setEditLoading(on) {

        if (!editLoadingEl) return;

        editLoadingEl.classList.toggle('d-none', !on);

    }

    function showEditError(msg) {

        if (!editErrEl) return;

        editErrEl.textContent = msg;

        editErrEl.classList.remove('d-none');

    }

    function clearEditError() {

        if (!editErrEl) return;

        editErrEl.classList.add('d-none');

        editErrEl.textContent = '';

    }

    async function loadArchiveEdit(id) {

        if (!editInnerEl || !editModal) return;

        clearEditError();

        editInnerEl.innerHTML = '';

        setEditLoading(true);

        let data;

        try {

            const res = await fetch(fetchBase + '?id=' + encodeURIComponent(String(id)) + '&part=edit', { credentials: 'same-origin' });

            data = await res.json();

        } catch (err) {

            setEditLoading(false);

            showEditError('โหลดข้อมูลไม่สำเร็จ');

            return;

        }

        setEditLoading(false);

        if (!data || !data.ok) {

            showEditError(data && data.error === 'not_found' ? 'ไม่พบรายการ' : 'โหลดข้อมูลไม่สำเร็จ');

            return;

        }

        if (editTitleEl) editTitleEl.textContent = 'แก้ไข — เลขที่ ' + (data.docDisplay || '');

        editInnerEl.innerHTML = data.editFormHtml || '';

        laborArchiveEditRecalcAll(editModalEl);

    }



    function setLoading(on) {

        if (!loadingEl) return;

        loadingEl.classList.toggle('d-none', !on);

    }



    function showError(msg) {

        if (!errEl) return;

        errEl.textContent = msg;

        errEl.classList.remove('d-none');

    }



    function clearError() {

        if (!errEl) return;

        errEl.classList.add('d-none');

        errEl.textContent = '';

    }



    async function loadArchive(id) {

        if (!wrapEl || !modal) return;

        clearError();

        wrapEl.innerHTML = '';

        currentPrintHtml = '';

        if (btnPrint) btnPrint.disabled = true;

        setLoading(true);

        let data;

        try {

            const res = await fetch(fetchBase + '?id=' + encodeURIComponent(String(id)), { credentials: 'same-origin' });

            data = await res.json();

        } catch (e) {

            setLoading(false);

            showError('โหลดข้อมูลไม่สำเร็จ');

            return;

        }

        setLoading(false);

        if (!data || !data.ok) {

            showError(data && data.error === 'not_found' ? 'ไม่พบรายการ' : 'โหลดข้อมูลไม่สำเร็จ');

            return;

        }

        if (titleEl) titleEl.textContent = 'รายละเอียด — เลขที่ ' + (data.docDisplay || '');

        wrapEl.innerHTML = data.modalTable || '';

        currentPrintHtml = data.printBlock || '';

        if (btnPrint) btnPrint.disabled = !currentPrintHtml;

    }



    document.querySelectorAll('.btn-archive-view').forEach(function (btn) {

        btn.addEventListener('click', function () {

            const id = this.getAttribute('data-archive-id');

            if (!id || !modal) return;

            modal.show();

            loadArchive(id);

        });

    });



    document.querySelectorAll('.btn-archive-edit').forEach(function (btn) {

        btn.addEventListener('click', function () {

            const id = this.getAttribute('data-archive-id');

            if (!id || !editModal) return;

            editModal.show();

            loadArchiveEdit(id);

        });

    });



    function laborHistHideModalBackdropsForPrint() {
        document.querySelectorAll('.modal-backdrop').forEach(function (el) {
            if (!el.getAttribute('data-lp-print-toggled')) {
                el.setAttribute('data-lp-print-toggled', '1');
                el.setAttribute('data-lp-print-prev-display', el.style.display || '');
                el.style.setProperty('display', 'none', 'important');
            }
        });
    }

    function laborHistRestoreModalBackdropsAfterPrint() {
        document.querySelectorAll('.modal-backdrop[data-lp-print-toggled]').forEach(function (el) {
            const prev = el.getAttribute('data-lp-print-prev-display');
            el.style.removeProperty('display');
            if (prev !== null && prev !== '') {
                el.style.display = prev;
            }
            el.removeAttribute('data-lp-print-toggled');
            el.removeAttribute('data-lp-print-prev-display');
        });
    }

    window.addEventListener('beforeprint', laborHistHideModalBackdropsForPrint);
    window.addEventListener('afterprint', laborHistRestoreModalBackdropsAfterPrint);

    btnPrint?.addEventListener('click', function () {

        if (!printRoot || !currentPrintHtml) return;

        printRoot.innerHTML = currentPrintHtml;

        laborHistHideModalBackdropsForPrint();

        window.print();

    });



    const editOpenId = <?= (int) $editOpenId ?>;

    if (editOpenId > 0 && editModal) {

        editModal.show();

        loadArchiveEdit(editOpenId);

        try {

            const u = new URL(window.location.href);

            u.searchParams.delete('edit_open_id');

            window.history.replaceState({}, '', u.pathname + u.search + u.hash);

        } catch (e) { /* ignore */ }

    }



    const openId = <?= (int) $openArchiveId ?>;

    if (editOpenId <= 0 && openId > 0 && modal) {

        modal.show();

        loadArchive(openId);

        try {

            const u = new URL(window.location.href);

            u.searchParams.delete('open_id');

            window.history.replaceState({}, '', u.pathname + u.search + u.hash);

        } catch (e) { /* ignore */ }

    }

})();

</script>

</body>

</html>

