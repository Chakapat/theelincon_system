<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$id = (int) ($_GET['id'] ?? 0);

require_once dirname(__DIR__, 2) . '/includes/purchase_print/po_document.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_po_payment_slips.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_print/pr_document.php';
$poCtx = tnc_purchase_po_print_prepare($id);
if ($poCtx === null) {
    die('ไม่พบข้อมูลใบสั่งซื้อ');
}
extract($poCtx, EXTR_OVERWRITE);
$paymentStatusPo = strtolower(trim((string) ($po['payment_status'] ?? 'unpaid')));
$isPoPaid = ($paymentStatusPo === 'paid');
$billingStatusPo = strtolower(trim((string) ($po['billing_status'] ?? 'pending')));
if (!in_array($billingStatusPo, ['pending', 'billed'], true)) {
    $billingStatusPo = 'pending';
}
$paymentSlipItemsForPrint = $isPoPaid ? tnc_po_payment_slip_items($po) : [];
$hasPaymentSlipPrint = $paymentSlipItemsForPrint !== [];
$quotRelPrint = trim((string) ($po['quotation_attachment_path'] ?? ''));
$quotExtPrint = $quotRelPrint !== '' ? strtolower(pathinfo($quotRelPrint, PATHINFO_EXTENSION)) : '';
$quotPrintableExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'pdf'];
$hasQuotationAttachPrint = $quotRelPrint !== '' && in_array($quotExtPrint, $quotPrintableExts, true);
$hasFollowPagesPrint = $hasPaymentSlipPrint || $hasQuotationAttachPrint;

$poPrIdForPrint = (int) ($po['pr_id'] ?? 0);
$prCtxForPo = $poPrIdForPrint > 0 ? tnc_purchase_pr_print_prepare($poPrIdForPrint) : null;
$hasPrForPrint = $prCtxForPo !== null;

$poPrintMode = tnc_purchase_po_resolve_print_mode();
if ($poPrintMode === 'all' && !$hasPrForPrint) {
    $poPrintMode = 'both';
}
$hasPrintChoiceModal = $hasFollowPagesPrint || $hasPrForPrint;

$printIncludePr = ($poPrintMode === 'all' && $hasPrForPrint);
$printIncludePo = in_array($poPrintMode, ['po', 'both', 'all'], true);
$printIncludeSlip = in_array($poPrintMode, ['slip', 'both', 'all'], true);
$printIncludeQuotation = in_array($poPrintMode, ['both', 'all'], true);

/** วันที่ PO สำหรับค่าเริ่มต้นในฟอร์มบันทึกบิลซื้อ (issue_date → created_at) */
$poIssueDateForBill = '';
$issueRawForBill = trim((string) ($po['issue_date'] ?? ''));
if ($issueRawForBill !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueRawForBill)) {
    $poIssueDateForBill = $issueRawForBill;
} elseif ($issueRawForBill !== '') {
    $issueTsForBill = strtotime($issueRawForBill);
    if ($issueTsForBill !== false) {
        $poIssueDateForBill = date('Y-m-d', $issueTsForBill);
    }
}
if ($poIssueDateForBill === '') {
    $createdRawForBill = trim((string) ($po['created_at'] ?? ''));
    if ($createdRawForBill !== '') {
        $createdTsForBill = strtotime($createdRawForBill);
        if ($createdTsForBill !== false) {
            $poIssueDateForBill = date('Y-m-d', $createdTsForBill);
        }
    }
}
$poIssueDateForBillDisplay = '';
if ($poIssueDateForBill !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $poIssueDateForBill, $billDateM) === 1) {
    $poIssueDateForBillDisplay = $billDateM[3] . '/' . $billDateM[2] . '/' . $billDateM[1];
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($poDocTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/purchase-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/document-print.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/tnc-app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        :root {
            --brand-color: #ea580c;
            --brand-color-deep: #c2410c;
            --brand-color-soft: #fff3e6;
            --brand-border-soft: #fed7aa;
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

        .po-view-shell {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #fff;
            border-bottom: 1px solid var(--tnc-orange-border, #fdba74);
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
        }

        .po-view-shell-inner {
            max-width: calc(210mm + 1.5rem);
            margin: 0 auto;
            padding: 0.85rem 0.75rem;
        }

        .po-view-toolbar-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 0;
        }

        .po-view-toolbar-main {
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            gap: 0.5rem;
            min-width: 0;
            flex: 1 1 auto;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            padding-bottom: 1px;
        }

        .po-view-toolbar-id {
            font-size: 1.15rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
            line-height: 1.2;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .po-view-toolbar-sep {
            color: #94a3b8;
            font-weight: 600;
            line-height: 1;
            flex-shrink: 0;
        }

        .po-view-toolbar-actions {
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            justify-content: flex-end;
            gap: 0.5rem;
            flex: 0 0 auto;
            flex-shrink: 0;
            padding-left: 0.25rem;
            background: #fff;
        }

        .po-view-toolbar-row .btn,
        .po-view-toolbar-row .badge,
        .po-view-toolbar-row .po-view-chip {
            flex-shrink: 0;
            white-space: nowrap;
        }

        .po-view-toolbar-actions .btn {
            font-weight: 600;
        }

        .po-view-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .po-view-alerts {
            margin-top: 0.75rem;
        }

        .po-view-alerts .alert {
            margin-bottom: 0.5rem;
            padding: 0.45rem 0.85rem;
            font-size: 0.875rem;
            border-radius: 0.5rem;
        }

        .po-view-alerts .alert:last-child {
            margin-bottom: 0;
        }

        .po-view-canvas {
            max-width: 210mm;
            margin-left: auto;
            margin-right: auto;
            padding: 0.75rem 0.75rem 2.5rem;
        }

        /* PR ฝังในหน้า PO — คงโทนเขียวของ PR (ไม่ใช้ส้มของ PO) */
        .pr-bundle-inline {
            --brand-color: #28a745;
            --brand-color-deep: #1e7e34;
        }

        .po-view-chip--link {
            background: #f0fdf4;
            color: #047857 !important;
            border-color: #86efac !important;
        }

        .po-view-chip--link:hover {
            background: #dcfce7;
            border-color: #4ade80 !important;
        }

        #poPrintChoiceModal .modal-content {
            border-radius: 1rem;
        }

        #poPrintChoiceModal .modal-header {
            padding-bottom: 0.25rem;
        }

        #poPrintChoiceModal .js-po-print-choice {
            border-radius: 0.75rem !important;
            padding-top: 0.65rem !important;
            padding-bottom: 0.65rem !important;
        }

        .po-side-accent {
            border-left-color: var(--brand-color) !important;
        }
        .po-vat-line {
            color: var(--brand-color-deep) !important;
        }
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

        .invoice-box.po-purchase-order-doc {
            --po-doc-a4-height: 297mm;
            --po-doc-pad-block: 10mm;
        }

        .po-doc-main {
            box-sizing: border-box;
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            min-height: calc(var(--po-doc-a4-height) - (var(--po-doc-pad-block) * 2));
            width: 100%;
        }

        .po-doc-content {
            flex: 1 1 auto;
            min-height: 0;
        }

        .invoice-box.po-purchase-order-doc .footer-sticky {
            flex: 0 0 auto;
            margin-top: auto;
            position: relative;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .invoice-box.po-purchase-order-doc .signature-grid {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .invoice-box .po-total-sheet {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            background: #fff9f0;
            border: 1px solid #fed7aa;
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
        }

        .company-logo { max-height: 84px; width: auto; max-width: 220px; object-fit: contain; }
        .po-purchase-order-doc .invoice-title { font-size: 28px; font-weight: 800; color: var(--brand-color); line-height: 1.1; }
        .table-custom { margin-top: 12px; margin-bottom: 0; }
        .po-purchase-order-doc .po-company-name {
            font-size: 1.25rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.25;
        }
        .po-purchase-order-doc .po-company-detail {
            font-size: 0.92rem;
            line-height: 1.5;
            color: #475569 !important;
            margin-top: 0.35rem;
        }
        .po-purchase-order-doc .po-note-heading {
            font-size: 0.8rem;
            font-weight: 700;
            color: #9a3412;
            margin-bottom: 0.35rem;
        }
        .invoice-box.po-purchase-order-doc .table-custom thead th,
        .invoice-box.po-purchase-order-doc .po-items-table thead th {
            background: #fafafa;
            border-bottom: 2px solid var(--brand-color);
            font-size: 11px;
            padding: 7px 8px;
        }
        .invoice-box.po-purchase-order-doc .table-custom td,
        .invoice-box.po-purchase-order-doc .po-items-table tbody td {
            padding: 7px 8px;
            font-size: 11px;
            border-bottom: 1px solid #f2f2f2;
        }


        .invoice-box .po-total-sheet .summary-item {
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
        .invoice-box .po-total-sheet .summary-item > span:last-child {
            margin-left: auto;
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .invoice-box.po-purchase-order-doc .grand-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--brand-color);
            color: #fff;
            padding: 12px;
            border-radius: 5px;
            margin-top: 8px;
        }

        .signature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; text-align: center; margin-top: 22px; }
        .sig-space { height: 72px; }
        .sig-box { border-top: 1px solid #333; padding-top: 10px; font-size: 13px; font-weight: 600; }

        .po-cancelled-watermark {
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
            text-transform: none;
            user-select: none;
        }

        .po-payment-slip-print-wrap {
            max-width: 210mm;
            margin: 1rem auto 2rem;
        }

        @media (max-width: 575.98px) {
            .po-slip-a4-page {
                width: 100%;
            }
            .po-slip-a4-page .po-payment-slip-sheet--full {
                height: auto;
                min-height: 0;
                max-height: none;
                padding: 0.5rem 0;
            }
            .po-slip-a4-page .po-slip-img-wrap {
                width: 100%;
                height: auto;
                max-height: min(277mm, 85vh);
            }
            .po-slip-a4-page .po-payment-slip-img {
                max-height: min(277mm, 85vh);
            }
        }

        @media print {
            .invoice-box.po-purchase-order-doc,
            .invoice-box.pr-purchase-requisition-doc {
                border-top: none !important;
            }
            .invoice-box.po-purchase-order-doc {
                min-height: calc(297mm - 20mm);
                display: flex !important;
                flex-direction: column !important;
            }
            .invoice-box.po-purchase-order-doc .po-doc-main {
                min-height: calc(297mm - 20mm) !important;
                display: flex !important;
                flex-direction: column !important;
            }
            .invoice-box.po-purchase-order-doc .footer-sticky {
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
            .po-doc-main { min-height: 0; display: block; }
            .po-doc-content { flex: none; }
            .invoice-box.po-purchase-order-doc .footer-sticky { margin-top: 1.25rem; }
            .signature-grid { grid-template-columns: 1fr; gap: 18px; }
        }
    </style>
</head>
<body class="purchase-module tnc-app-body tnc-po-boot-lock" data-tnc-boot-title="กำลังโหลดใบสั่งซื้อ…" data-tnc-boot-sub="กรุณารอสักครู่ ระบบจะพร้อมให้บันทึกเลขบิลและดำเนินการต่อเมื่อโหลดเสร็จ">

<div class="no-print tnc-app-chrome">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
</div>

<?php
$pmToolbar = strtolower(trim((string) ($po['payment_method'] ?? 'transfer'))) === 'cash' ? 'cash' : 'transfer';
$cashByToolbar = trim((string) ($po['payment_cash_paid_by'] ?? ''));
$slipItemsToolbar = $isPoPaid ? tnc_po_payment_slip_items($po) : [];
$slipRelToolbar = $slipItemsToolbar !== [] ? (string) ($slipItemsToolbar[0]['path'] ?? '') : '';
$poListHref = htmlspecialchars(app_path('pages/purchase/purchase-order-list.php'), ENT_QUOTES, 'UTF-8');
$poViewFullHref = htmlspecialchars(app_path('pages/purchase/purchase-order-view.php') . '?id=' . (int) $id, ENT_QUOTES, 'UTF-8');
$hasAlerts = !empty($_GET['cancelled'])
    || (!empty($_GET['error']) && $_GET['error'] === 'po_paid')
    || (!empty($_GET['error']) && in_array((string) $_GET['error'], ['billing_required', 'billing_amount_invalid'], true))
    || !empty($_GET['billing_saved'])
    || ($hasFollowPagesPrint && $poPrintMode === 'po')
    || ($poPrintMode === 'slip')
    || ($poPrintMode === 'all');
?>
<header class="po-view-shell no-print">
    <div class="po-view-shell-inner">
        <div class="po-view-toolbar-row mb-2">
            <div class="po-view-toolbar-main">
                <span class="po-view-toolbar-id"><?= htmlspecialchars($poDocTitle, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="po-view-toolbar-sep" aria-hidden="true">—</span>
                <?php if ($isPoCancelled): ?>
                    <span class="badge rounded-pill px-3 py-2 text-bg-danger">ยกเลิกแล้ว</span>
                <?php elseif ($isPoPaid): ?>
                    <span class="po-view-chip"><?= $pmToolbar === 'cash' ? 'เงินสด' : 'โอน / ช่องทางอื่น' ?></span>
                    <?php if ($pmToolbar === 'cash' && $cashByToolbar !== ''): ?>
                        <span class="po-view-chip">จ่ายโดย <?= htmlspecialchars($cashByToolbar, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <?php if ($slipItemsToolbar !== []): ?>
                        <span class="po-view-chip">
                            <i class="bi bi-receipt"></i>หลักฐาน <?= count($slipItemsToolbar) ?> ไฟล์
                        </span>
                        <?php foreach ($slipItemsToolbar as $slipTb): ?>
                        <a href="<?= htmlspecialchars((string) ($slipTb['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="po-view-chip po-view-chip--link text-decoration-none" title="<?= htmlspecialchars((string) ($slipTb['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            เปิด<?= ($slipTb['is_pdf'] ?? false) ? ' PDF' : '' ?>
                        </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (user_can('po.update') && !$isPoCancelled && !$isPoPaid): ?>
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-edit.php') . '?id=' . (int) $id, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange btn-sm rounded-pill px-3 shadow-sm">
                        <i class="bi bi-pencil-square me-1"></i>แก้ไข
                    </a>
                <?php endif; ?>
                <?php if (user_can('po.cancel') && !$isPoCancelled && !$isPoPaid): ?>
                    <form method="post" action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=cancel_purchase_order" class="d-inline" data-tnc-fullnav="1" onsubmit="return confirm('ยืนยันยกเลิกใบสั่งซื้อนี้? สถานะจะเปลี่ยนเป็น ยกเลิก และจะแสดงประทับบนใบพิมพ์');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="po_id" value="<?= (int) $id ?>">
                        <input type="hidden" name="return_to" value="view">
                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3"><i class="bi bi-x-circle me-1"></i>ยกเลิก PO</button>
                    </form>
                <?php endif; ?>
                <?php if (user_can('po.update') && !$isPoCancelled && $billingStatusPo === 'pending'): ?>
                    <button type="button" class="btn btn-outline-orange btn-sm rounded-pill px-3" id="btnOpenReceiveBill">
                        <i class="bi bi-receipt me-1"></i>บันทึกเลขที่บิลซื้อ
                    </button>
                <?php endif; ?>
            </div>
            <div class="po-view-toolbar-actions">
                <a href="<?= $poListHref ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="bi bi-arrow-left me-1"></i>รายการ PO
                </a>
                <?php if ($hasPrintChoiceModal): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#poPrintChoiceModal">
                        <i class="bi bi-printer me-1"></i>พิมพ์
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" onclick="tncPrintPoWhenReady()">
                        <i class="bi bi-printer me-1"></i>พิมพ์
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($hasAlerts): ?>
            <div class="po-view-alerts">
                <?php if (!empty($_GET['cancelled'])): ?>
                    <div class="alert alert-success mb-0" data-tnc-audio="delete">ยกเลิกใบสั่งซื้อเรียบร้อยแล้ว</div>
                <?php endif; ?>
                <?php if (!empty($_GET['error']) && $_GET['error'] === 'po_paid'): ?>
                    <div class="alert alert-warning mb-0">ใบสั่งซื้อนี้สถานะการจ่ายเป็น «จ่ายแล้ว» ไม่สามารถยกเลิกได้</div>
                <?php endif; ?>
                <?php if (!empty($_GET['billing_saved'])): ?>
                    <div class="alert alert-success mb-0" data-tnc-audio="complete">บันทึกเลขที่บิลซื้อเรียบร้อยแล้ว และสร้างข้อมูลในตาราง bills แล้ว</div>
                <?php endif; ?>
                <?php if (!empty($_GET['error']) && $_GET['error'] === 'billing_required'): ?>
                    <div class="alert alert-warning mb-0">กรุณากรอกเลขที่บิลซื้อและวันที่บนบิลให้ครบถ้วน</div>
                <?php endif; ?>
                <?php if (!empty($_GET['error']) && $_GET['error'] === 'billing_amount_invalid'): ?>
                    <div class="alert alert-warning mb-0">ยอดเงินรวมและยอด VAT ต้องไม่เป็นค่าว่างหรือติดลบ</div>
                <?php endif; ?>
                <?php if ($hasFollowPagesPrint && $poPrintMode === 'po'): ?>
                    <div class="alert alert-light border mb-0 small">
                        กำลังแสดง<strong>เฉพาะใบสั่งซื้อ</strong>
                        — <a class="alert-link" href="<?= $poViewFullHref ?>">เปิดแบบครบ (PO + สลิป/แนบ)</a>
                    </div>
                <?php endif; ?>
                <?php if ($poPrintMode === 'slip'): ?>
                    <div class="alert alert-light border mb-0 small">
                        กำลังแสดง<strong>เฉพาะสลิป</strong>
                        — <a class="alert-link" href="<?= $poViewFullHref ?>">เปิดแบบครบ</a>
                    </div>
                <?php endif; ?>
                <?php if ($poPrintMode === 'all'): ?>
                    <div class="alert alert-light border mb-0 small">
                        กำลังแสดง<strong>ชุดครบ: PR + ใบสั่งซื้อ + สลิป/แนบ</strong> (ตามที่มีในระบบ)
                        — <a class="alert-link" href="<?= $poViewFullHref ?>">เปิดหน้าเริ่มต้น</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</header>

<div class="modal fade no-print" id="receiveBillModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=receive_po_bill" method="POST" id="receiveBillForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="return_to" value="view">
                <input type="hidden" name="po_id" value="<?= (int) $id ?>">
                <div class="modal-header">
                    <h5 class="modal-title">บันทึกเลขที่บิลซื้อ (Receive Bill)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="small text-muted mb-2">PO: <?= htmlspecialchars((string) ($po['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">เลขที่ใบกำกับภาษี/บิลซื้อ <span class="text-danger">*</span></label>
                        <input type="text" name="supplier_invoice_no" class="form-control" maxlength="120" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">วันที่บนใบกำกับภาษี/บิลซื้อ <span class="text-danger">*</span></label>
                        <input type="text" name="supplier_invoice_date" id="receiveBillInvoiceDate" class="form-control" value="<?= htmlspecialchars($poIssueDateForBillDisplay, ENT_QUOTES, 'UTF-8') ?>" placeholder="วัน/เดือน/ปี เช่น 29/05/2026" autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">ยอดเงินรวม (บาท)</label>
                        <input type="number" name="billed_total_amount" class="form-control" step="0.01" min="0" value="<?= htmlspecialchars(number_format((float) ($po['total_amount'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-semibold">ยอด VAT 7% (บาท)</label>
                        <input type="number" name="billed_vat_amount" class="form-control" step="0.01" min="0" value="<?= htmlspecialchars(number_format((float) ($po['vat_amount'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-orange">บันทึกบิลซื้อ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($hasPrintChoiceModal): ?>
<div class="modal fade no-print" id="poPrintChoiceModal" tabindex="-1" aria-labelledby="poPrintChoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" id="poPrintChoiceModalLabel">เลือกรูปแบบการพิมพ์</h5>
                    <p class="small text-muted mb-0 mt-1">เปิดหน้าพิมพ์พร้อมกล่องพิมพ์อัตโนมัติ</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body pt-2 pb-3">
                <div class="row g-2">
                    <div class="col-12 col-md-6 col-xl-3">
                        <button type="button" class="btn btn-outline-secondary w-100 h-100 py-3 text-start js-po-print-choice border-2 rounded-3" data-print-mode="po">
                            <i class="bi bi-file-earmark-text d-block mb-2 fs-4 text-secondary"></i>
                            <span class="fw-bold d-block">1. เฉพาะใบสั่งซื้อ</span>
                            <span class="small text-muted">ไม่รวมสลิปและแนบ QT</span>
                        </button>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <button type="button" class="btn btn-outline-secondary w-100 h-100 py-3 text-start js-po-print-choice border-2 rounded-3" data-print-mode="slip"<?= $hasPaymentSlipPrint ? '' : ' disabled title="ไม่มีไฟล์หลักฐานการจ่ายเงิน"' ?>>
                            <i class="bi bi-receipt d-block mb-2 fs-4 text-secondary"></i>
                            <span class="fw-bold d-block">2. เฉพาะสลิป</span>
                            <span class="small text-muted">หลักฐานการจ่ายอย่างเดียว</span>
                        </button>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <button type="button" class="btn btn-success w-100 h-100 py-3 text-start js-po-print-choice border-0 rounded-3 shadow-sm" data-print-mode="both">
                            <i class="bi bi-files d-block mb-2 fs-4"></i>
                            <span class="fw-bold d-block">3. ใบสั่งซื้อ + สลิป</span>
                            <span class="small" style="opacity:0.95">รวมแนบใบเสนอราคา (ถ้ามี)</span>
                        </button>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <button type="button" class="btn btn-outline-orange w-100 h-100 py-3 text-start js-po-print-choice border-2 rounded-3" data-print-mode="all"<?= $hasPrForPrint ? '' : ' disabled title="ไม่มีใบขอซื้อ (PR) อ้างอิงจาก PO นี้"' ?>>
                            <i class="bi bi-collection d-block mb-2 fs-4"></i>
                            <span class="fw-bold d-block">4. พิมพ์ทุกอย่าง</span>
                            <span class="small text-muted">PR + ใบสั่งซื้อ + สลิป + แนบ QT (ตามที่มี)</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="po-view-canvas">
<?php if ($printIncludePr && $prCtxForPo !== null): ?>
<div class="pr-bundle-inline po-print-bundle-pr">
<?php tnc_purchase_pr_print_render($prCtxForPo); ?>
</div>
<?php endif; ?>
<?php if ($printIncludePo): ?>
<?php tnc_purchase_po_print_render($poCtx); ?>
<?php endif; ?>
<?php if ($printIncludeSlip): ?>
<?php tnc_purchase_po_payment_slip_print_render($poCtx['po'], $printIncludePo || ($printIncludePr && $prCtxForPo !== null)); ?>
<?php endif; ?>
<?php if ($printIncludeQuotation): ?>
<?php tnc_purchase_po_quotation_attachment_print_render($poCtx['po'], true); ?>
<?php endif; ?>
<?php if ($poPrintMode === 'slip' && !$hasPaymentSlipPrint): ?>
<div class="container-xl py-5 no-print">
    <div class="alert alert-warning border-0 shadow-sm mb-0 rounded-3">ไม่มีไฟล์หลักฐานการจ่ายเงิน (สลิป) สำหรับใบสั่งซื้อนี้</div>
</div>
<?php endif; ?>
</div>

<script src="<?= htmlspecialchars(app_path('assets/js/tnc-po-print.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<?php if ($hasPrintChoiceModal): ?>
<script>
(function () {
    var base = <?= json_encode(app_path('pages/purchase/purchase-order-view.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var id = <?= (int) $id ?>;
    document.querySelectorAll('#poPrintChoiceModal .js-po-print-choice').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.disabled) {
                return;
            }
            var mode = btn.getAttribute('data-print-mode') || 'both';
            if (mode !== 'po' && mode !== 'slip' && mode !== 'both' && mode !== 'all') {
                mode = 'both';
            }
            var u = base + '?id=' + id + '&print_mode=' + encodeURIComponent(mode) + '&autoprint=1';
            window.location.href = u;
            var el = document.getElementById('poPrintChoiceModal');
            if (el && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var m = bootstrap.Modal.getInstance(el);
                if (m) {
                    m.hide();
                }
            }
        });
    });
})();
</script>
<?php endif; ?>
<script>
(function () {
    const btn = document.getElementById('btnOpenReceiveBill');
    const modalEl = document.getElementById('receiveBillModal');
    if (!btn || !modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        return;
    }
    const modal = new bootstrap.Modal(modalEl);
    btn.addEventListener('click', function () {
        modal.show();
    });
})();

(function () {
    const invDateEl = document.getElementById('receiveBillInvoiceDate');
    const formEl = document.getElementById('receiveBillForm');
    if (!invDateEl || typeof flatpickr !== 'function') {
        return;
    }

    flatpickr(invDateEl, {
        dateFormat: 'd/m/Y',
        defaultDate: invDateEl.value || undefined,
        allowInput: true,
    });

    function normalizeInvoiceDateForSubmit() {
        const raw = (invDateEl.value || '').trim();
        if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
            return true;
        }
        const m = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (!m) {
            return false;
        }
        const dd = Number(m[1]);
        const mm = Number(m[2]);
        const yyyy = Number(m[3]);
        const d = new Date(yyyy, mm - 1, dd);
        if (d.getFullYear() !== yyyy || d.getMonth() !== (mm - 1) || d.getDate() !== dd) {
            return false;
        }
        invDateEl.value = yyyy + '-' + String(mm).padStart(2, '0') + '-' + String(dd).padStart(2, '0');
        return true;
    }

    formEl?.addEventListener('submit', function (e) {
        if (!normalizeInvoiceDateForSubmit()) {
            e.preventDefault();
            alert('กรุณากรอกวันที่บนใบกำกับภาษี/บิลซื้อเป็น วัน/เดือน/ปี เช่น 29/05/2026');
            invDateEl.focus();
        }
    });
})();
</script>
<script>
(function () {
    function releasePoBootLock() {
        if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.pageReady === 'function') {
            window.TncLoadingOverlay.pageReady();
        }
    }
    window.addEventListener('load', function () {
        window.requestAnimationFrame(releasePoBootLock);
    }, { once: true });
})();
</script>
<?php
$tncPrintOnlyCss = app_path('assets/css/print-document-only.css');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($tncPrintOnlyCss, ENT_QUOTES, 'UTF-8') ?>" media="print">
</body>
</html>