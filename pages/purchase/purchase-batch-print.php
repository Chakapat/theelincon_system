<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$kind = strtolower(trim((string) ($_GET['kind'] ?? '')));
if (!in_array($kind, ['pr', 'po'], true)) {
    $kind = '';
}

$rawIds = trim((string) ($_GET['ids'] ?? ''));
$idList = [];
if ($rawIds !== '') {
    foreach (preg_split('/[\s,;]+/', $rawIds, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
        $n = (int) $p;
        if ($n > 0) {
            $idList[$n] = true;
        }
    }
}
$ids = array_keys($idList);
$ids = array_slice($ids, 0, 25);

$backPr = app_path('pages/purchase/purchase-request-list.php');
$backPo = app_path('pages/purchase/purchase-order-list.php');
$backUrl = $kind === 'po' ? $backPo : $backPr;

require_once dirname(__DIR__, 2) . '/includes/purchase_print/pr_document.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_print/po_document.php';

$poPrintModeBatch = ($kind === 'po') ? tnc_purchase_po_resolve_print_mode() : 'both';

$documents = [];
foreach ($ids as $docId) {
    if ($kind === 'pr') {
        $ctx = tnc_purchase_pr_print_prepare($docId);
        if ($ctx !== null) {
            $documents[] = ['kind' => 'pr', 'id' => $docId, 'ctx' => $ctx];
        }
    } elseif ($kind === 'po') {
        $ctx = tnc_purchase_po_print_prepare($docId);
        if ($ctx !== null) {
            $documents[] = ['kind' => 'po', 'id' => $docId, 'ctx' => $ctx];
        }
    }
}

$pageTitle = $kind === 'po' ? 'พิมพ์ใบสั่งซื้อ (หลายใบ)' : ($kind === 'pr' ? 'พิมพ์ใบขอซื้อ (หลายใบ)' : 'พิมพ์เอกสาร');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/purchase-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <?php require_once dirname(__DIR__, 2) . '/includes/document_color_css.php'; tnc_doc_color_render_head_assets(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/tnc-app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        <?php if ($kind === 'po'): ?>
        :root { --dark: #333; }
        .po-side-accent { border-left-color: var(--brand-color) !important; }
        .po-vat-line { color: var(--brand-color-deep) !important; }
        .invoice-box .po-total-sheet {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            background: var(--doc-po-soft, #fff9f0);
            border: 1px solid var(--doc-po-border, #fed7aa);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
        }
        .po-payment-slip-print-wrap {
            max-width: 210mm;
            margin: 1rem auto 2rem;
        }
        .po-payment-slip-sheet {
            background: #fff;
            padding: 12px 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            border: 1px solid #e2e8f0;
        }
        .po-slip-print-caption {
            font-weight: 600;
            color: var(--brand-color);
            letter-spacing: 0.03em;
        }
        .po-slip-paper-header {
            font-size: 0.88rem;
            line-height: 1.4;
            color: #334155;
            border-bottom: 2px solid var(--brand-color);
            padding-bottom: 0.45rem;
            margin-bottom: 0.65rem;
        }
        .po-slip-paper-header-kicker {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #94a3b8;
            margin-bottom: 0.15rem;
        }
        .po-slip-po-line {
            font-size: 0.82rem;
            color: #475569;
        }
        .po-slip-po-number {
            font-size: 1.08rem;
            font-weight: 800;
            color: var(--brand-color-deep);
            margin-left: 0.25rem;
        }
        .po-slip-img-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            max-height: 72vh;
            overflow: auto;
        }
        .po-payment-slip-img {
            max-width: 100%;
            max-height: 72vh;
            height: auto;
            width: auto;
            object-fit: contain;
        }
        .po-quotation-pdf-iframe {
            width: 100%;
            min-height: 40vh;
            max-height: 72vh;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: #f8fafc;
        }
        <?php else: ?>
        :root { --dark: #333; }
        <?php endif; ?>
        body { font-family: 'Sarabun', 'Leelawadee UI', 'Segoe UI', Tahoma, sans-serif; background: #e8ecf1; color: var(--dark); margin: 0; padding: 0; font-weight: 500; }
        .tnc-batch-toolbar {
            position: sticky; top: 0; z-index: 100;
            background: linear-gradient(180deg, #fff 0%, #f1f5f9 100%);
            border-bottom: 1px solid #cbd5e1;
            padding: 0.75rem 1rem;
        }
        .tnc-batch-print-wrap {
            page-break-after: always;
            break-after: page;
            margin: 1rem auto;
            max-width: 220mm;
        }
        .tnc-batch-print-wrap:last-child {
            page-break-after: auto;
            break-after: auto;
            margin-bottom: 2rem;
        }
        .invoice-box {
            width: 210mm;
            max-width: 100%;
            min-height: 297mm;
            height: auto;
            margin: 0 auto;
            background: #fff;
            padding: 10mm 15mm;
            position: relative;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            overflow: visible;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
        }
        .invoice-box.po-purchase-order-doc,
        .invoice-box.pr-purchase-requisition-doc {
            --po-doc-a4-height: 297mm;
            --po-doc-pad-block: 10mm;
        }
        .company-logo { max-height: 84px; width: auto; max-width: 220px; object-fit: contain; }
        .po-purchase-order-doc .invoice-title { font-size: 28px; font-weight: 800; color: var(--brand-color); line-height: 1.1; }
        .pr-purchase-requisition-doc .invoice-title { font-size: 28px; font-weight: 800; color: var(--doc-pr-primary, #28a745); line-height: 1.1; }
        .table-custom { margin-top: 12px; margin-bottom: 0; }
        .invoice-box.po-purchase-order-doc .table-custom thead th { background: #fafafa; border-bottom: 2px solid var(--brand-color); font-size: 13px; padding: 10px; }
        .pr-purchase-requisition-doc .table-custom thead th { background: #fafafa; border-bottom: 2px solid var(--doc-pr-primary, #28a745); font-size: 13px; padding: 10px; }
        .table-custom td { padding: 10px; font-size: 13px; border-bottom: 1px solid #f2f2f2; }
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
            display: flex; justify-content: space-between; align-items: center;
            background: var(--brand-color); color: white; padding: 12px; border-radius: 5px; margin-top: 8px;
        }
        .pr-purchase-requisition-doc .grand-total-row {
            display: flex; justify-content: space-between; align-items: center;
            background: var(--doc-pr-primary, #28a745); color: #fff; padding: 12px; border-radius: 5px; margin-top: 8px;
        }
        .signature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; text-align: center; margin-top: 22px; page-break-inside: avoid; break-inside: avoid; }
        .sig-space { height: 72px; }
        .sig-box { border-top: 1px solid #333; padding-top: 10px; font-size: 13px; font-weight: 600; }
        .pr-doc-main,
        .po-doc-main {
            box-sizing: border-box;
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            min-height: calc(var(--po-doc-a4-height, 297mm) - (var(--po-doc-pad-block, 10mm) * 2));
            width: 100%;
        }
        .pr-doc-content,
        .po-doc-content {
            flex: 1 1 auto;
            min-height: 0;
        }
        .invoice-box.po-purchase-order-doc .footer-sticky,
        .invoice-box.pr-purchase-requisition-doc .footer-sticky {
            flex: 0 0 auto;
            margin-top: auto;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .po-cancelled-watermark,
        .pr-cancelled-watermark {
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%) rotate(-32deg);
            font-size: clamp(2.5rem, 12vw, 4.5rem);
            font-weight: 800;
            color: rgba(220, 38, 38, 0.38);
            white-space: nowrap;
            pointer-events: none;
            z-index: 60;
            letter-spacing: 0.18em;
            user-select: none;
            text-transform: uppercase;
        }
        @media (max-width: 575.98px) {
            .invoice-box { width: 100%; min-height: 0; height: auto; padding: 1rem; box-shadow: none; overflow: visible; display: block; }
            .signature-grid { grid-template-columns: 1fr; gap: 18px; }
        }
        @media print {
            .invoice-box.po-purchase-order-doc,
            .invoice-box.pr-purchase-requisition-doc {
                width: 210mm !important;
                max-width: 210mm !important;
                min-height: 297mm !important;
                margin: 0 auto !important;
                height: auto !important;
                display: flex !important;
                flex-direction: column !important;
                padding: 10mm 15mm !important;
                border: none !important;
                border-top: none !important;
                border-top-width: 0 !important;
                outline: none !important;
                box-shadow: none !important;
            }
            .invoice-box.po-purchase-order-doc .po-doc-main,
            .invoice-box.pr-purchase-requisition-doc .pr-doc-main {
                flex: 1 1 auto !important;
                display: flex !important;
                flex-direction: column !important;
                min-height: calc(297mm - 20mm) !important;
            }
            .invoice-box.po-purchase-order-doc .po-doc-content,
            .invoice-box.pr-purchase-requisition-doc .pr-doc-content {
                flex: 1 1 auto !important;
            }
            .invoice-box.po-purchase-order-doc .footer-sticky,
            .invoice-box.pr-purchase-requisition-doc .footer-sticky {
                flex: 0 0 auto !important;
                margin-top: auto !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .invoice-box.po-purchase-order-doc .signature-grid,
            .invoice-box.pr-purchase-requisition-doc .signature-grid,
            .invoice-box.po-purchase-order-doc .po-total-sheet,
            .invoice-box.pr-purchase-requisition-doc .pr-total-sheet {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
        }
    </style>
</head>
<body class="tnc-batch-print-body purchase-module<?= $kind === 'po' ? ' tnc-doc-po-view' : ($kind === 'pr' ? ' tnc-doc-pr-view' : '') ?>">

<div class="tnc-batch-toolbar no-print d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm rounded-pill">
            <i class="bi bi-arrow-left me-1"></i>กลับรายการ
        </a>
        <span class="small text-muted"><?= count($documents) ?> ใบ</span>
        <?php if ($kind === 'po'): ?>
            <span class="small text-secondary">
                <?php if ($poPrintModeBatch === 'po'): ?>
                    <i class="bi bi-file-earmark-text me-1"></i>โหมด: เฉพาะใบสั่งซื้อ
                <?php elseif ($poPrintModeBatch === 'slip'): ?>
                    <i class="bi bi-receipt me-1"></i>โหมด: เฉพาะสลิป (ถ้ามีในแต่ละใบ)
                <?php elseif ($poPrintModeBatch === 'all'): ?>
                    <i class="bi bi-collection me-1"></i>โหมด: PR + ใบสั่งซื้อ + สลิป/แนบ (ตามที่มีในแต่ละใบ)
                <?php else: ?>
                    <i class="bi bi-files me-1"></i>โหมด: ใบสั่งซื้อ + สลิป/แนบ (ถ้ามี)
                <?php endif; ?>
            </span>
        <?php endif; ?>
    </div>
    <button type="button" class="btn btn-success btn-sm rounded-pill px-3" onclick="typeof tncPrintPoWhenReady==='function'?tncPrintPoWhenReady():window.print()">
        <i class="bi bi-printer me-1"></i>พิมพ์ทั้งหมด
    </button>
</div>

<?php if ($kind === ''): ?>
    <div class="container py-5">
        <div class="alert alert-warning">ระบุประเภทเอกสารไม่ถูกต้อง (ใช้ <code>?kind=pr</code> หรือ <code>?kind=po</code>)</div>
        <a href="<?= htmlspecialchars($backPr, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange">รายการ PR</a>
        <a href="<?= htmlspecialchars($backPo, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">รายการ PO</a>
    </div>
<?php elseif (count($ids) === 0): ?>
    <div class="container py-5">
        <div class="alert alert-info mb-0">ยังไม่ได้เลือกเลขที่เอกสาร — กลับไปติ๊กที่รายการแล้วกด «พิมพ์ที่เลือก»</div>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange mt-3">กลับ</a>
    </div>
<?php elseif (count($documents) === 0): ?>
    <div class="container py-5">
        <div class="alert alert-danger mb-0">ไม่พบเอกสารตามรหัสที่ส่งมา หรือถูกลบแล้ว</div>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange mt-3">กลับ</a>
    </div>
<?php else: ?>
    <?php foreach ($documents as $block): ?>
        <div class="tnc-batch-print-wrap">
            <?php if ($block['kind'] === 'pr'): ?>
                <?php tnc_purchase_pr_print_render($block['ctx']); ?>
            <?php else: ?>
                <?php if ($poPrintModeBatch === 'all'): ?>
                    <?php
                    $prIdBatch = (int) ($block['ctx']['po']['pr_id'] ?? 0);
                    $prCtxBatch = $prIdBatch > 0 ? tnc_purchase_pr_print_prepare($prIdBatch) : null;
                    ?>
                    <?php if ($prCtxBatch !== null): ?>
                    <div class="pr-bundle-inline po-print-bundle-pr">
                        <?php tnc_purchase_pr_print_render($prCtxBatch); ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (in_array($poPrintModeBatch, ['po', 'both', 'all'], true)): ?>
                    <?php tnc_purchase_po_print_render($block['ctx']); ?>
                <?php endif; ?>
                <?php if (in_array($poPrintModeBatch, ['slip', 'both', 'all'], true)): ?>
                    <?php tnc_purchase_po_payment_slip_print_render($block['ctx']['po'], in_array($poPrintModeBatch, ['po', 'both', 'all'], true)); ?>
                <?php endif; ?>
                <?php if (in_array($poPrintModeBatch, ['both', 'all'], true)): ?>
                    <?php tnc_purchase_po_quotation_attachment_print_render($block['ctx']['po'], true); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script src="<?= htmlspecialchars(app_path('assets/js/tnc-po-print.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
