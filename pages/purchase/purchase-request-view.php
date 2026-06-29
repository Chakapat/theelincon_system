<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$pr_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

require_once dirname(__DIR__, 2) . '/includes/purchase_print/pr_document.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_flash.php';
use Theelincon\Rtdb\Purchase;
$prCtx = tnc_purchase_pr_print_prepare($pr_id);
if ($prCtx === null) {
    echo "<script>alert('ไม่พบข้อมูลใบขอซื้อ'); window.location.href='" . htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES) . "';</script>";
    exit();
}
extract($prCtx, EXTR_OVERWRITE);

$prCanSendLineAdmin = user_can('pr.send_line') && in_array($prApprovalStatus, ['pending', 'rejected'], true);
$prCanWebDecide = user_can('pr.approve') && $prApprovalStatus === 'pending';
$prCanEdit = line_pr_user_can_edit($pr);
$prHandlerUrl = app_path('actions/action-handler.php');

$prToolbarPoNumber = '';
if (!empty($existing_po) && is_array($existing_po)) {
    $prToolbarPoNumber = trim((string) ($existing_po['po_number'] ?? ''));
    if ($prToolbarPoNumber === '' && (int) ($existing_po['id'] ?? 0) > 0) {
        $prToolbarPoNumber = 'PO-' . (int) $existing_po['id'];
    }
}
$prToolbarDisplayId = $prToolbarPoNumber !== '' ? $prToolbarPoNumber : $prDocTitle;
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($prDocTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/purchase-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <?php require_once dirname(__DIR__, 2) . '/includes/document_color_css.php'; tnc_doc_color_render_head_assets(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/tnc-app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        :root {
            --dark: #333;
        }
        body {
            font-family: 'Sarabun', 'Leelawadee UI', 'Segoe UI', Tahoma, sans-serif;
            background: var(--tnc-surface, #f6f7f9);
            color: var(--dark);
            margin: 0;
            padding: 0;
            font-weight: 500;
            min-height: 100vh;
        }

        .pr-view-shell {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #fff;
            border-bottom: 1px solid var(--brand-border-soft, var(--doc-pr-border, #bbf7d0));
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
        }

        .pr-view-shell-inner {
            max-width: calc(210mm + 1.5rem);
            margin: 0 auto;
            padding: 0.85rem 0.75rem;
        }

        .pr-view-kicker {
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--brand-color);
            margin-bottom: 0.2rem;
        }

        .pr-view-toolbar-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            min-width: 0;
        }

        .pr-view-toolbar-id {
            font-size: 1.15rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
            line-height: 1.2;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .pr-view-toolbar-sep {
            color: #94a3b8;
            font-weight: 600;
            line-height: 1;
            flex-shrink: 0;
        }

        .pr-view-toolbar-row .btn,
        .pr-view-toolbar-row .badge {
            flex-shrink: 0;
            white-space: nowrap;
        }

        .pr-view-title {
            font-size: 1.35rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }

        .pr-view-canvas {
            max-width: 210mm;
            margin-left: auto;
            margin-right: auto;
            padding: 0.75rem 0.75rem 2.5rem;
        }

        .pr-side-accent { border-left-color: var(--brand-color) !important; }

        .invoice-box {
            width: 210mm;
            max-width: 100%;
            min-height: 297mm;
            height: auto;
            margin: 0 auto 1.5rem;
            background: #fff;
            padding: 10mm 15mm;
            position: relative;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            overflow: visible;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
        }

        .invoice-box.pr-purchase-requisition-doc {
            --pr-doc-a4-height: 297mm;
            --pr-doc-pad-block: 10mm;
        }

        .pr-doc-main {
            box-sizing: border-box;
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            min-height: calc(var(--pr-doc-a4-height) - (var(--pr-doc-pad-block) * 2));
            width: 100%;
        }

        .pr-doc-content {
            flex: 1 1 auto;
            min-height: 0;
        }

        .company-logo { max-height: 84px; width: auto; max-width: 220px; object-fit: contain; }

        .invoice-box .pr-total-sheet {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
        }

        .table-custom { margin-top: 12px; margin-bottom: 0; }

        .invoice-box.pr-purchase-requisition-doc .invoice-title {
            font-size: 28px;
            font-weight: 800;
            color: var(--brand-color);
            line-height: 1.1;
        }

        .invoice-box.pr-purchase-requisition-doc .pr-company-name {
            font-size: 1.25rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.25;
        }

        .invoice-box.pr-purchase-requisition-doc .pr-company-detail {
            font-size: 0.92rem;
            line-height: 1.5;
            color: #475569 !important;
            margin-top: 0.35rem;
        }

        .invoice-box.pr-purchase-requisition-doc .table-custom thead th,
        .invoice-box.pr-purchase-requisition-doc .pr-items-table thead th {
            background: #fafafa;
            border-bottom: 2px solid var(--brand-color);
            font-size: 11px;
            padding: 7px 8px;
        }

        .invoice-box.pr-purchase-requisition-doc .table-custom td,
        .invoice-box.pr-purchase-requisition-doc .pr-items-table tbody td {
            padding: 7px 8px;
            font-size: 11px;
            border-bottom: 1px solid #f2f2f2;
        }

        .invoice-box .pr-total-sheet .summary-item {
            display: flex;
            flex-wrap: nowrap;
            align-items: baseline;
            justify-content: flex-start;
            gap: 0.75rem;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            padding: 2px 0;
            font-size: 14px;
        }

        .invoice-box .pr-total-sheet .summary-item > span:last-child {
            margin-left: auto;
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .invoice-box.pr-purchase-requisition-doc .grand-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--brand-color);
            color: #fff;
            padding: 12px;
            border-radius: 5px;
            margin-top: 8px;
        }

        .invoice-box.pr-purchase-requisition-doc .footer-sticky {
            flex: 0 0 auto;
            margin-top: auto;
            position: relative;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .invoice-box.pr-purchase-requisition-doc .signature-grid {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .signature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; text-align: center; margin-top: 22px; }
        .sig-space { height: 72px; }
        .sig-box { border-top: 1px solid #333; padding-top: 10px; font-size: 13px; font-weight: 600; }

        .pr-cancelled-watermark {
            position: absolute;
            left: 50%;
            top: 48%;
            transform: translate(-50%, -50%) rotate(-32deg);
            font-size: clamp(1.75rem, 6.5vw, 2.85rem);
            font-weight: 800;
            color: rgba(220, 38, 38, 0.42);
            white-space: nowrap;
            pointer-events: none;
            z-index: 50;
            letter-spacing: 0.12em;
            user-select: none;
        }

        @media print {
            .invoice-box.pr-purchase-requisition-doc,
            .invoice-box.po-purchase-order-doc {
                width: 210mm !important;
                max-width: 210mm !important;
                min-height: 297mm !important;
                margin: 0 auto !important;
                padding: 10mm 15mm !important;
                border: none !important;
                border-top: none !important;
                border-top-width: 0 !important;
                outline: none !important;
                box-shadow: none !important;
            }
            .invoice-box.pr-purchase-requisition-doc {
                display: flex !important;
                flex-direction: column !important;
            }
            .invoice-box.pr-purchase-requisition-doc .pr-doc-main {
                min-height: calc(297mm - 20mm) !important;
                display: flex !important;
                flex-direction: column !important;
            }
            .invoice-box.pr-purchase-requisition-doc .footer-sticky {
                margin-top: auto !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }

        @media (max-width: 575.98px) {
            body { background: #fff; }
            .invoice-box {
                width: 100%;
                height: auto;
                min-height: 0;
                padding: 1rem;
                box-shadow: none;
                overflow: visible;
                display: block;
            }
            .pr-doc-main { min-height: 0; display: block; }
            .pr-doc-content { flex: none; }
            .invoice-box.pr-purchase-requisition-doc .footer-sticky { margin-top: 1.25rem; }
            .signature-grid { grid-template-columns: 1fr; gap: 18px; }
        }

        .invoice-box.pr-purchase-requisition-doc .pr-notes-panel {
            border: 1px solid #e2e8f0;
            border-left: 3px solid var(--brand-color);
            background: #f8fafc;
            border-radius: 0.35rem;
            padding: 0.45rem 0.55rem 0.5rem;
            max-width: 100%;
            box-sizing: border-box;
        }

        .invoice-box.pr-purchase-requisition-doc .pr-note-heading {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--brand-color-deep);
            margin-bottom: 0.35rem;
        }

        .invoice-box.pr-purchase-requisition-doc .pr-note-body {
            font-size: 0.72rem;
            line-height: 1.45;
            color: #334155;
            white-space: pre-line;
        }

        .invoice-box.pr-purchase-requisition-doc .pr-footer-row {
            display: flex;
            flex-wrap: nowrap;
            align-items: flex-start;
            margin-left: 0;
            margin-right: 0;
            --bs-gutter-x: 0.75rem;
        }

        .invoice-box.pr-purchase-requisition-doc .pr-footer-row.pr-footer-row--has-notes {
            column-gap: 0.75rem;
            row-gap: 1.15rem;
        }

        .invoice-box.pr-purchase-requisition-doc .pr-footer-row.pr-footer-row--has-notes .pr-footer-notes-col {
            flex: 0 0 auto;
            width: 58.33333333%;
            max-width: 58.33333333%;
            padding-right: 0.75rem;
        }

        .invoice-box.pr-purchase-requisition-doc .pr-footer-row.pr-footer-row--has-notes .pr-footer-totals-col {
            flex: 0 0 auto;
            width: 41.66666667%;
            max-width: 41.66666667%;
            margin-left: auto;
        }

        .invoice-box.pr-purchase-requisition-doc .pr-footer-notes-col:not(:has(.pr-notes-panel)) {
            display: none;
        }

        .invoice-box.pr-purchase-requisition-doc .pr-footer-row:not(.pr-footer-row--has-notes) .pr-footer-totals-col {
            margin-left: auto;
            flex: 0 0 auto;
            width: min(100%, 15.5rem);
            max-width: 15.5rem;
        }

        .invoice-box.pr-purchase-requisition-doc .pr-vat-line {
            color: var(--brand-color-deep) !important;
        }

        .invoice-box.pr-purchase-requisition-doc .doc-site-block.doc-site-block--pr-triple {
            display: flex !important;
            flex-direction: row;
            align-items: baseline;
            width: 100%;
            column-gap: 0.75rem;
        }

        .invoice-box.pr-purchase-requisition-doc .doc-site-block--pr-triple .doc-site-seg {
            flex: 1 1 0;
            min-width: 0;
            display: block;
        }

        .invoice-box.pr-purchase-requisition-doc .doc-site-block--pr-triple .doc-site-seg--place {
            text-align: left;
        }

        .invoice-box.pr-purchase-requisition-doc .doc-site-block--pr-triple .doc-site-seg--cat {
            text-align: center;
        }

        .invoice-box.pr-purchase-requisition-doc .doc-site-block--pr-triple .doc-site-seg--requester {
            text-align: right;
        }
    </style>
</head>
<body class="purchase-module tnc-doc-pr-view tnc-app-body tnc-purchase-boot-lock" data-tnc-boot-title="กำลังโหลดใบขอซื้อ…" data-tnc-boot-sub="กรุณารอสักครู่ ระบบจะพร้อมให้ดำเนินการต่อเมื่อโหลดเสร็จ">

<div class="no-print tnc-app-chrome">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container-fluid px-3 d-lg-none no-print">
    <?php include dirname(__DIR__, 2) . '/components/purchase-subnav.php'; ?>
</div>
</div>

<header class="pr-view-shell no-print">
    <div class="pr-view-shell-inner">
        <div class="pr-view-toolbar-row js-tnc-doc-toolbar mb-2">
                <span class="badge rounded-pill px-3 py-2 <?= htmlspecialchars($prApprovalBadgeClass, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($prApprovalLabel, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php if ($prCanSendLineAdmin || $prCanWebDecide): ?>
                    <?php if ($prCanSendLineAdmin): ?>
                        <button type="button" class="btn js-tnc-doc-action btn-outline-success btn-sm rounded-pill px-3" id="btnPrSendLine" title="ส่งขออนุมัติไปกลุ่ม LINE">
                            <i class="bi bi-line me-1"></i>ส่ง LINE
                        </button>
                    <?php endif; ?>
                    <?php if ($prCanWebDecide): ?>
                        <button type="button" class="btn js-tnc-doc-action btn-success btn-sm rounded-pill px-3" id="btnPrWebApprove" title="อนุมัติบนเว็บ">
                            <i class="bi bi-check-circle me-1"></i>อนุมัติ
                        </button>
                        <button type="button" class="btn js-tnc-doc-action btn-outline-danger btn-sm rounded-pill px-3" id="btnPrWebReject" title="ไม่อนุมัติบนเว็บ">
                            <i class="bi bi-x-circle me-1"></i>ไม่อนุมัติ
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($existing_po): ?>
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-view.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $existing_po['id'] ?>" class="btn js-tnc-doc-action btn-orange btn-sm rounded-pill px-3" title="คีย์ลัด: Ctrl+Shift+G">
                        <i class="bi bi-eye me-1"></i>ดูใบสั่งซื้อ
                    </a>
                <?php elseif (!empty($prIsApprovedForPo) && user_can('po.create')): ?>
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-create.php'), ENT_QUOTES, 'UTF-8') ?>?pr_id=<?= (int) $pr['id'] ?>" class="btn js-tnc-doc-action btn-orange btn-sm rounded-pill px-3" title="คีย์ลัด: Ctrl+Shift+G">
                        <i class="bi bi-file-earmark-plus me-1"></i>สร้างใบสั่งซื้อ
                    </a>
                <?php elseif (!empty($prIsApprovedForPo)): ?>
                    <span class="btn btn-secondary btn-sm rounded-pill px-3 disabled" tabindex="-1" title="ไม่มีสิทธิ์สร้าง PO">
                        <i class="bi bi-lock me-1"></i>ไม่มีสิทธิ์สร้าง PO
                    </span>
                <?php else: ?>
                    <span class="btn btn-secondary btn-sm rounded-pill px-3 disabled" tabindex="-1" title="รออนุมัติก่อนออก PO">
                        <i class="bi bi-lock me-1"></i>รออนุมัติ
                    </span>
                <?php endif; ?>
                <?php if ($prCanEdit): ?>
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-create.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $pr['id'] ?>" class="btn js-tnc-doc-action btn-outline-warning btn-sm rounded-pill px-3" data-dock-primary="edit" title="แก้ไขใบขอซื้อ">
                        <i class="bi bi-pencil-square me-1"></i>แก้ไข PR
                    </a>
                <?php endif; ?>
                <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn js-tnc-doc-action btn-outline-secondary btn-sm rounded-pill px-3" data-dock-primary="back">
                    <i class="bi bi-arrow-left me-1"></i>รายการ PR
                </a>
                <button type="button" onclick="window.print()" class="btn js-tnc-doc-action btn-outline-secondary btn-sm rounded-pill px-3" data-dock-primary="print">
                    <i class="bi bi-printer me-1"></i>พิมพ์
                </button>
        </div>
        <?php
        $prViewFlash = tnc_purchase_pr_view_flash($_GET);
        if ($prViewFlash !== null): ?>
        <div class="mb-3">
            <?php tnc_purchase_render_flash($prViewFlash, true); ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($isPoCancelled)): ?>
            <div class="alert alert-danger py-2 px-3 mb-3 border-0 shadow-sm">
                <i class="bi bi-x-octagon me-1"></i>ใบสั่งซื้อ (PO) ที่เชื่อมกับ PR นี้ถูกยกเลิกแล้ว (CANCELLED)
            </div>
        <?php endif; ?>
    </div>
</header>

<div class="pr-view-canvas">
<?php tnc_purchase_pr_print_render($prCtx); ?>
</div>

<form method="post" action="<?= htmlspecialchars($prHandlerUrl, ENT_QUOTES, 'UTF-8') ?>" id="prSendLineForm" class="d-none">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="send_pr_line_approval">
    <input type="hidden" name="pr_id" value="<?= (int) ($pr['id'] ?? $pr_id) ?>">
</form>
<form method="post" action="<?= htmlspecialchars($prHandlerUrl, ENT_QUOTES, 'UTF-8') ?>" id="prWebApproveForm" class="d-none">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="pr_web_decision">
    <input type="hidden" name="pr_id" value="<?= (int) ($pr['id'] ?? $pr_id) ?>">
    <input type="hidden" name="decision" value="approve">
</form>
<form method="post" action="<?= htmlspecialchars($prHandlerUrl, ENT_QUOTES, 'UTF-8') ?>" id="prWebRejectForm" class="d-none">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="pr_web_decision">
    <input type="hidden" name="pr_id" value="<?= (int) ($pr['id'] ?? $pr_id) ?>">
    <input type="hidden" name="decision" value="reject">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    function submitIfConfirm(form, msg) {
        if (!form || !window.confirm(msg)) {
            return;
        }
        if (window.TncPurchaseLoading && typeof window.TncPurchaseLoading.submitWithOverlay === 'function') {
            window.TncPurchaseLoading.submitWithOverlay(form);
            return;
        }
        form.submit();
    }

    if (window.TncPurchaseLoading) {
        window.TncPurchaseLoading.markBootTableReady();
        window.TncPurchaseLoading.markBootSyncReady();
    }
    document.getElementById('btnPrSendLine')?.addEventListener('click', function () {
        submitIfConfirm(
            document.getElementById('prSendLineForm'),
            'ต้องการส่งใบขอซื้อไปยัง LINE หรือไม่?'
        );
    });
    document.getElementById('btnPrWebApprove')?.addEventListener('click', function () {
        submitIfConfirm(
            document.getElementById('prWebApproveForm'),
            'ยืนยันอนุมัติ PR นี้บนเว็บ (ADMIN)?'
        );
    });
    document.getElementById('btnPrWebReject')?.addEventListener('click', function () {
        submitIfConfirm(
            document.getElementById('prWebRejectForm'),
            'ยืนยันไม่อนุมัติ PR นี้?'
        );
    });

    var u = <?= json_encode($poShortcutUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    if (!u || typeof u !== 'string') return;
    document.addEventListener('keydown', function (e) {
        if (!e.ctrlKey || !e.shiftKey || e.altKey || e.metaKey) return;
        if (e.key !== 'G' && e.key !== 'g') return;
        var el = e.target;
        if (!el || !el.closest) return;
        if (el.closest('input, textarea, select, [contenteditable="true"]')) return;
        e.preventDefault();
        window.location.href = u;
    });
})();
</script>
<?php
$tncPrintOnlyCss = app_path('assets/css/print-document-only.css');
tnc_doc_color_render_print_style_tag();
?>
<link rel="stylesheet" href="<?= htmlspecialchars($tncPrintOnlyCss, ENT_QUOTES, 'UTF-8') ?>" media="print">
<style media="print">
    @page {
        size: A4 portrait;
        margin: 0;
    }
</style>
</body>
</html>
