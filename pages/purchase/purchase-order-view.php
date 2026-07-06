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
require_once dirname(__DIR__, 2) . '/includes/purchase_flash.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_purchase_head.php';
use Theelincon\Rtdb\Purchase;
$poCtx = tnc_purchase_po_print_prepare($id);
if ($poCtx === null) {
    die('ไม่พบข้อมูลใบสั่งซื้อ');
}
extract($poCtx, EXTR_OVERWRITE);
$paymentStatusPo = strtolower(trim((string) ($po['payment_status'] ?? 'unpaid')));
$isPoPaid = ($paymentStatusPo === 'paid');
$poPaidLocksActions = Purchase::poPaidLocksMutation($po);
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
$printIncludeSlip = in_array($poPrintMode, ['slip', 'both', 'all'], true) && $hasPaymentSlipPrint;
$printIncludeQuotation = in_array($poPrintMode, ['both', 'all'], true) && $hasQuotationAttachPrint;

$poEmbed = isset($_GET['embed']) && (string) $_GET['embed'] === '1';
$poAutoprint = isset($_GET['autoprint']) && (string) $_GET['autoprint'] === '1';
if ($poEmbed || $poAutoprint) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
if ($poEmbed) {
    $printIncludePr = false;
    $printIncludePo = true;
    $printIncludeSlip = false;
    $printIncludeQuotation = false;
}

/** วันที่ PO สำหรับฟอร์มบันทึกบิลซื้อ — ใช้วันที่ออกใบสั่งซื้อตอนสร้าง */
$poIssueDateForBill = tnc_po_issue_date_ymd($po);
$poIssueDateForBillDisplay = tnc_po_ymd_to_dmy($poIssueDateForBill);
$supplierInvoiceNoView = trim((string) ($po['supplier_invoice_no'] ?? ''));
$supplierInvoiceDateView = trim((string) ($po['supplier_invoice_date'] ?? ''));
$supplierInvoiceDateYmd = tnc_po_parse_date_ymd($supplierInvoiceDateView);
$supplierInvoiceDateViewDisplay = $supplierInvoiceDateYmd !== '' ? tnc_po_ymd_to_dmy($supplierInvoiceDateYmd) : '';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <?php tnc_purchase_head([
        'title' => $poDocTitle,
        'document_color' => true,
        'flatpickr' => true,
        'sarabun_weights' => '400;500;600;700',
    ]); ?>
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

        .po-view-shell {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #fff;
            border-bottom: 1px solid var(--brand-border-soft, var(--doc-po-border, #fed7aa));
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
        }

        .po-view-shell-inner {
            max-width: 100%;
            margin: 0 auto;
            padding: 0.85rem 1rem;
        }

        .po-view-toolbar-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: nowrap;
            min-width: 0;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            padding-bottom: 2px;
        }

        .po-view-toolbar-main {
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            gap: 0.5rem;
            flex: 0 0 auto;
            min-width: 0;
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

        .po-view-toolbar-sep--actions {
            margin-left: 0.25rem;
            opacity: 0.65;
        }

        .po-view-toolbar-actions {
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            gap: 0.5rem;
            flex: 0 0 auto;
            margin-left: auto;
            padding-left: 0.5rem;
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
            --brand-color: var(--doc-pr-primary, #28a745);
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

        .po-view-chip--bill {
            background: #fffbeb;
            color: #92400e;
            border-color: #fcd34d;
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
            background: var(--doc-po-soft, var(--brand-color-soft, #fff9f0));
            border: 1px solid var(--doc-po-border, var(--brand-border-soft, #fed7aa));
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
        }

        .company-logo { max-height: 84px; width: auto; max-width: 220px; object-fit: contain; }
        .po-purchase-order-doc .invoice-title { font-size: 28px; font-weight: 800; color: var(--doc-po-primary, var(--brand-color, #ea580c)); line-height: 1.1; }
        .table-custom { margin-top: 12px; margin-bottom: 0; }
        .po-purchase-order-doc .po-company-name {
            font-size: 1.125rem;
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
            color: var(--doc-po-deep, var(--brand-color-deep, #9a3412));
            margin-bottom: 0.35rem;
        }

        .invoice-box.po-purchase-order-doc .doc-site-block.doc-site-block--po-split {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: baseline;
            column-gap: 1rem;
            width: 100%;
        }

        .invoice-box.po-purchase-order-doc .doc-site-block--po-split .doc-site-main {
            justify-self: start;
            min-width: 0;
            text-align: left;
        }

        .invoice-box.po-purchase-order-doc .doc-site-block--po-split .doc-site-category {
            justify-self: end;
            text-align: right;
            white-space: nowrap;
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


        .invoice-box.po-purchase-order-doc .po-footer-row {
            display: flex;
            flex-wrap: nowrap;
            align-items: flex-start;
            margin-left: 0;
            margin-right: 0;
            --bs-gutter-x: 0.75rem;
        }

        .invoice-box.po-purchase-order-doc .po-footer-row:has(.po-notes-panel) {
            column-gap: 0.75rem;
            row-gap: 1.15rem;
        }

        .invoice-box.po-purchase-order-doc .po-footer-row:has(.po-notes-panel) .po-footer-notes-col {
            flex: 0 0 auto;
            width: 58.33333333%;
            max-width: 58.33333333%;
            padding-right: 0.75rem;
        }

        .invoice-box.po-purchase-order-doc .po-footer-row:has(.po-notes-panel) .po-footer-totals-col {
            flex: 0 0 auto;
            width: 41.66666667%;
            max-width: 41.66666667%;
            margin-left: auto;
        }

        .invoice-box.po-purchase-order-doc .po-footer-notes-col:not(:has(.po-notes-panel)) {
            display: none;
        }

        .invoice-box.po-purchase-order-doc .po-footer-row:not(:has(.po-notes-panel)) .po-footer-totals-col {
            margin-left: auto;
            flex: 0 0 auto;
            width: min(100%, 15.5rem);
            max-width: 15.5rem;
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
            background: var(--doc-po-primary, var(--brand-color, #ea580c));
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
            .po-view-canvas {
                display: block !important;
                position: static !important;
                left: auto !important;
                top: auto !important;
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
            }

            .tnc-po-print-page {
                display: block !important;
                position: relative !important;
                float: none !important;
                clear: both !important;
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                page-break-inside: avoid !important;
                break-inside: avoid-page !important;
            }

            .tnc-po-print-page + .tnc-po-print-page {
                page-break-before: always !important;
                break-before: page !important;
            }

            .tnc-po-print-page--slip .po-payment-slip-print-wrap {
                page-break-before: auto !important;
                break-before: auto !important;
            }

            .invoice-box.po-purchase-order-doc,
            .invoice-box.pr-purchase-requisition-doc {
                width: 210mm !important;
                max-width: 210mm !important;
                min-height: 297mm !important;
                margin: 0 auto !important;
                border: none !important;
                border-top: none !important;
                border-top-width: 0 !important;
                outline: none !important;
                box-shadow: none !important;
                padding: 10mm 15mm !important;
            }
            .invoice-box.po-purchase-order-doc {
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

            .po-payment-slip-print-wrap {
                display: block !important;
                position: relative !important;
                float: none !important;
                clear: both !important;
                width: 100% !important;
                max-width: none !important;
                margin: 0 auto !important;
                overflow: hidden !important;
            }

            .po-payment-slip-print-wrap.po-slip-a4-page {
                height: 277mm !important;
                min-height: 277mm !important;
                max-height: 277mm !important;
            }

            .po-slip-a4-page .po-payment-slip-sheet--full {
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                height: 277mm !important;
                min-height: 277mm !important;
                max-height: 277mm !important;
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

        <?php if ($poEmbed || $poAutoprint): ?>
        body.po-doc-embed {
            overflow-x: hidden;
            max-width: 100%;
            background: #f6f7f9;
            margin: 0;
        }
        body.po-doc-autoprint {
            background: #fff;
        }
        body.po-doc-embed .po-view-canvas {
            max-width: 210mm;
            margin: 0 auto;
            padding: 0.5rem;
        }
        <?php endif; ?>

        
            </style>
</head>
<body class="purchase-module tnc-doc-po-view<?= ($poEmbed || $poAutoprint) ? ' po-doc-embed' . ($poAutoprint ? ' po-doc-autoprint' : '') : ' tnc-app-body tnc-po-boot-lock' ?>"<?= ($poEmbed || $poAutoprint) ? '' : ' data-tnc-boot-title="กำลังโหลดใบสั่งซื้อ…" data-tnc-boot-sub="กรุณารอสักครู่ ระบบจะพร้อมให้บันทึกเลขบิลและดำเนินการต่อเมื่อโหลดเสร็จ"' ?>>

<?php if (!$poEmbed && !$poAutoprint): ?>
<div class="no-print tnc-app-chrome">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container-fluid px-3 d-lg-none no-print">
    <?php include dirname(__DIR__, 2) . '/components/purchase-subnav.php'; ?>
</div>
</div>
<?php endif; ?>

<?php
$pmToolbar = strtolower(trim((string) ($po['payment_method'] ?? 'transfer'))) === 'cash' ? 'cash' : 'transfer';
$cashByToolbar = trim((string) ($po['payment_cash_paid_by'] ?? ''));
$slipItemsToolbar = $isPoPaid ? tnc_po_payment_slip_items($po) : [];
$slipRelToolbar = $slipItemsToolbar !== [] ? (string) ($slipItemsToolbar[0]['path'] ?? '') : '';
$poListHref = htmlspecialchars(app_path('pages/purchase/purchase-order-list.php'), ENT_QUOTES, 'UTF-8');
$poViewFullHref = htmlspecialchars(app_path('pages/purchase/purchase-order-view.php') . '?id=' . (int) $id, ENT_QUOTES, 'UTF-8');
$poViewFlash = tnc_purchase_po_view_flash($_GET);
$hasAlerts = $poViewFlash !== null
    || ($hasFollowPagesPrint && $poPrintMode === 'po')
    || ($poPrintMode === 'slip')
    || ($poPrintMode === 'all');
?>
<?php if (!$poEmbed && !$poAutoprint): ?>
<header class="po-view-shell no-print">
    <div class="po-view-shell-inner">
        <div class="po-view-toolbar-row js-tnc-doc-toolbar mb-2">
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
                <?php if ($supplierInvoiceNoView !== ''): ?>
                    <span class="po-view-chip po-view-chip--bill" title="<?= htmlspecialchars(
                        $supplierInvoiceDateViewDisplay !== ''
                            ? ('เลขที่บิล / ใบกำกับภาษี · วันที่บิล ' . $supplierInvoiceDateViewDisplay)
                            : 'เลขที่บิล / ใบกำกับภาษี',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>">
                        <i class="bi bi-journal-text" aria-hidden="true"></i>เลขที่บิล <?= htmlspecialchars($supplierInvoiceNoView, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
                <?php if (user_can('po.update') && !$poPaidLocksActions): ?>
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-edit.php') . '?id=' . (int) $id, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange btn-sm rounded-pill px-3 shadow-sm js-tnc-doc-action" data-dock-primary="edit">
                        <i class="bi bi-pencil-square me-1"></i>แก้ไข
                    </a>
                <?php endif; ?>
                <?php if (user_can('po.cancel') && !$isPoCancelled && !$poPaidLocksActions): ?>
                    <form method="post" action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=cancel_purchase_order" class="d-inline js-tnc-doc-action" data-tnc-fullnav="1" onsubmit="return confirm('ยืนยันยกเลิกใบสั่งซื้อนี้? สถานะจะเปลี่ยนเป็น ยกเลิก และจะแสดงประทับบนใบพิมพ์');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="po_id" value="<?= (int) $id ?>">
                        <input type="hidden" name="return_to" value="view">
                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3"><i class="bi bi-x-circle me-1"></i>ยกเลิก PO</button>
                    </form>
                <?php endif; ?>
                <?php if (user_can('po.update') && !$isPoCancelled && $billingStatusPo === 'pending' && !false): ?>
                    <button type="button" class="btn btn-outline-orange btn-sm rounded-pill px-3 js-tnc-doc-action" id="btnOpenReceiveBill">
                        <i class="bi bi-receipt me-1"></i>บันทึกเลขที่บิลซื้อ
                    </button>
                <?php endif; ?>
                <?php if ($isPoPaid && user_can('po.update') && !$poPaidLocksActions && !false): ?>
                    <button
                        type="button"
                        class="btn btn-outline-orange btn-sm rounded-pill px-3 js-manage-slips js-tnc-doc-action"
                        data-po-id="<?= (int) $id ?>"
                        data-return-to="view"
                    >
                        <i class="bi bi-images me-1"></i>จัดการสลิป<?= count($slipItemsToolbar) > 1 ? ' (' . count($slipItemsToolbar) . ')' : '' ?>
                    </button>
                <?php endif; ?>
            </div>
            <span class="po-view-toolbar-sep po-view-toolbar-sep--actions" aria-hidden="true">|</span>
            <div class="po-view-toolbar-actions">
                                <a href="<?= $poListHref ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3 js-tnc-doc-action" data-dock-primary="back">
                    <i class="bi bi-arrow-left me-1"></i>รายการ PO
                </a>
                                <?php if ($hasPrintChoiceModal): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 js-tnc-doc-action" data-dock-primary="print" data-bs-toggle="modal" data-bs-target="#poPrintChoiceModal">
                        <i class="bi bi-printer me-1"></i>พิมพ์
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 js-tnc-doc-action" data-dock-primary="print" onclick="tncPrintPoWhenReady()">
                        <i class="bi bi-printer me-1"></i>พิมพ์
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($hasAlerts): ?>
            <div class="po-view-alerts">
                <?php if ($poViewFlash !== null): ?>
                    <?php tnc_purchase_render_flash($poViewFlash, false); ?>
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
<?php endif; ?>

<?php if (!$poEmbed && !$poAutoprint): ?>
<div class="modal fade no-print" id="receiveBillModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-md-down">
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
<?php endif; ?>



<div class="po-view-canvas">
<?php if ($printIncludePr && $prCtxForPo !== null): ?>
<div class="tnc-po-print-page tnc-po-print-page--pr">
<div class="pr-bundle-inline po-print-bundle-pr">
<?php tnc_purchase_pr_print_render($prCtxForPo); ?>
</div>
</div>
<?php endif; ?>
<?php if ($printIncludePo): ?>
<div class="tnc-po-print-page tnc-po-print-page--po">
<?php tnc_purchase_po_print_render($poCtx); ?>
</div>
<?php endif; ?>
<?php if ($printIncludeSlip): ?>
<div class="tnc-po-print-page tnc-po-print-page--slip">
<?php tnc_purchase_po_payment_slip_print_render($poCtx['po'], false); ?>
</div>
<?php endif; ?>
<?php if ($printIncludeQuotation): ?>
<div class="tnc-po-print-page tnc-po-print-page--quotation">
<?php tnc_purchase_po_quotation_attachment_print_render($poCtx['po'], false); ?>
</div>
<?php endif; ?>
<?php if ($poPrintMode === 'slip' && !$hasPaymentSlipPrint): ?>
<div class="container-xl py-5 no-print">
    <div class="alert alert-warning border-0 shadow-sm mb-0 rounded-3">ไม่มีไฟล์หลักฐานการจ่ายเงิน (สลิป) สำหรับใบสั่งซื้อนี้</div>
</div>
<?php endif; ?>
</div>

<script src="<?= htmlspecialchars(app_path('assets/js/tnc-po-print.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php if (!$poEmbed && !$poAutoprint): ?>
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<?php if ($isPoPaid && user_can('po.update') && !$poPaidLocksActions && !false): ?>
<script>
window.tncPoLiveDatasetsUrl = <?= json_encode(app_path('actions/live-datasets.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
window.tncPoFetchActionRow = function (poId) {
    var url = window.tncPoLiveDatasetsUrl + '?dataset=po_action_row&po_id=' + encodeURIComponent(String(poId || ''));
    return fetch(url, { credentials: 'same-origin' })
        .then(function (r) {
            if (!r.ok) throw new Error('fetch_failed');
            return r.json();
        })
        .then(function (d) {
            if (!d || !d.ok || !d.row) throw new Error('bad_payload');
            return d.row;
        });
};
</script>
<?php
$poSlipDefaultReturnTo = 'view';
include dirname(__DIR__, 2) . '/includes/purchase/po_payment_slips_modal.php';
?>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<?php endif; ?>
<?php if ($hasPrintChoiceModal && !$poEmbed && !$poAutoprint): ?>
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
<?php if (!$poEmbed && !$poAutoprint): ?>
<script>
window.__tncPoBoot = window.__tncPoBoot || { table: true, sync: false };
window.tncPoLiveDatasetsUrl = <?= json_encode(app_path('actions/live-datasets.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
window.tncPoViewPoId = <?= (int) $id ?>;

window.tncPoShowWait = function (title, sub) {
    if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.showWithMessage === 'function') {
        window.TncLoadingOverlay.showWithMessage(title, sub);
    } else if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.show === 'function') {
        window.TncLoadingOverlay.show();
    }
};
window.tncPoHideWait = function () {
    if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.hide === 'function') {
        window.TncLoadingOverlay.hide();
    }
};
window.tncPoTryPageReady = function () {
    var boot = window.__tncPoBoot;
    if (!boot || !boot.table || !boot.sync) return;
    if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.pageReady === 'function') {
        window.TncLoadingOverlay.pageReady();
    }
};
window.tncPoFetchActionRow = function (poId) {
    var url = window.tncPoLiveDatasetsUrl + '?dataset=po_action_row&po_id=' + encodeURIComponent(String(poId || ''));
    return fetch(url, { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('fetch_failed'); return r.json(); })
        .then(function (d) { if (!d || !d.ok || !d.row) throw new Error('bad_payload'); return d.row; });
};
window.tncPoReloadWithWait = function (title, sub) {
    window.__tncPoReloading = true;
    window.tncPoShowWait(title || 'กำลังอัปเดตข้อมูล PO…', sub || 'กำลังโหลดหน้าใหม่…');
    window.location.reload();
};

fetch(window.tncPoLiveDatasetsUrl + '?dataset=po_action_row&po_id=' + encodeURIComponent(String(window.tncPoViewPoId)), { credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .catch(function () { return null; })
    .finally(function () {
        window.__tncPoBoot.sync = true;
        window.tncPoTryPageReady();
    });
</script>
<script>
(function () {
    const btn = document.getElementById('btnOpenReceiveBill');
    const modalEl = document.getElementById('receiveBillModal');
    const totalEl = document.getElementById('receiveBillTotalAmount');
    const vatEl = document.getElementById('receiveBillVatAmount');
    const invNoEl = document.querySelector('#receiveBillForm [name="supplier_invoice_no"]');
    const invDateEl = document.getElementById('receiveBillInvoiceDate');
    if (!btn || !modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        return;
    }
    const modal = new bootstrap.Modal(modalEl);
    function ymdToDmy(ymd) {
        const m = String(ymd || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
        return m ? (m[3] + '/' + m[2] + '/' + m[1]) : '';
    }
    btn.addEventListener('click', function () {
        window.tncPoShowWait(
            'กำลังโหลดข้อมูล PO…',
            'กรุณารอสักครู่ ระบบกำลังดึงข้อมูลล่าสุดก่อนบันทึกเลขบิล'
        );
        window.tncPoFetchActionRow(window.tncPoViewPoId)
            .then(function (row) {
                if (row.status === 'cancelled') {
                    alert('ใบสั่งซื้อนี้ถูกยกเลิกแล้ว');
                    window.tncPoReloadWithWait();
                    return;
                }
                if (row.billing_status === 'billed') {
                    alert('ใบสั่งซื้อนี้บันทึกเลขบิลแล้ว');
                    window.tncPoReloadWithWait();
                    return;
                }
                if (totalEl) totalEl.value = Number(row.billed_total_amount ?? row.total_amount ?? 0).toFixed(2);
                if (vatEl) vatEl.value = Number(row.billed_vat_amount ?? 0).toFixed(2);
                if (invNoEl) invNoEl.value = row.supplier_invoice_no || '';
                if (invDateEl) {
                    const issueDmy = ymdToDmy(row.issue_date || '');
                    if (invDateEl._flatpickr) {
                        if (issueDmy) invDateEl._flatpickr.setDate(issueDmy, true, 'd/m/Y');
                        else invDateEl._flatpickr.clear();
                    } else {
                        invDateEl.value = issueDmy;
                    }
                }
                modal.show();
            })
            .catch(function () {
                alert('โหลดข้อมูลไม่สำเร็จ กรุณาลองใหม่');
            })
            .finally(function () {
                if (!window.__tncPoReloading) window.tncPoHideWait();
            });
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
<?php endif; ?>
<?php
$tncPrintOnlyCssPath = dirname(__DIR__, 2) . '/assets/css/print-document-only.css';
$tncPrintOnlyCssVer = is_file($tncPrintOnlyCssPath) ? (string) filemtime($tncPrintOnlyCssPath) : (string) time();
$tncPrintOnlyCss = app_path('assets/css/print-document-only.css') . '?v=' . rawurlencode($tncPrintOnlyCssVer);
tnc_doc_color_render_print_style_tag();
?>
<link rel="stylesheet" href="<?= htmlspecialchars($tncPrintOnlyCss, ENT_QUOTES, 'UTF-8') ?>" media="print">
<style media="print">
    @page {
        size: A4 portrait;
        margin: 0;
    }
    </style>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>