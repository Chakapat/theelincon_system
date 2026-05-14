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
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/document-print.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        <?php if ($kind === 'po'): ?>
        :root {
            --brand-color: #ea580c;
            --brand-color-deep: #c2410c;
            --brand-color-soft: #fff7ed;
            --brand-border-soft: #fdba74;
            --dark: #333;
        }
        .po-side-accent { border-left-color: var(--brand-color) !important; }
        .po-vat-line { color: var(--brand-color-deep) !important; }
        .invoice-box .po-total-sheet {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            background: #fff9f0;
            border: 1px solid #fed7aa;
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
        :root { --brand-color: #28a745; --dark: #333; }
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
            margin: 0 auto;
            background: #fff;
            padding: 10mm 15mm;
            position: relative;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            border-top: 8px solid var(--brand-color);
            overflow: hidden;
        }
        .company-logo { max-height: 84px; width: auto; max-width: 220px; object-fit: contain; }
        .invoice-title { font-size: 28px; font-weight: 800; color: var(--brand-color); line-height: 1.1; }
        .table-custom { margin-top: 12px; margin-bottom: 0; }
        .table-custom thead th { background: #fafafa; border-bottom: 2px solid var(--brand-color); font-size: 13px; padding: 10px; }
        .table-custom td { padding: 10px; font-size: 13px; border-bottom: 1px solid #f2f2f2; }
        .footer-sticky { position: absolute; bottom: 12mm; left: 15mm; right: 15mm; }
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
        .grand-total-row {
            display: flex; justify-content: space-between; align-items: center;
            background: var(--brand-color); color: white; padding: 12px; border-radius: 5px; margin-top: 8px;
        }
        .signature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; text-align: center; margin-top: 22px; }
        .sig-space { height: 72px; }
        .sig-box { border-top: 1px solid #333; padding-top: 10px; font-size: 13px; font-weight: 600; }
        .pr-doc-main,
        .po-doc-main {
            padding-bottom: 52mm;
            box-sizing: border-box;
        }
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
            user-select: none;
        }
        @media (max-width: 575.98px) {
            .invoice-box { width: 100%; min-height: 0; height: auto; padding: 1rem; box-shadow: none; overflow: visible; }
            .footer-sticky { position: static; margin-top: 1.25rem; }
            .signature-grid { grid-template-columns: 1fr; gap: 18px; }
            .pr-doc-main,
            .po-doc-main { padding-bottom: 0; }
        }
        @media print {
            @page { size: A4; margin: 0; }
            body { background: none !important; }
            .tnc-batch-toolbar { display: none !important; }
            .tnc-batch-print-wrap { margin: 0 !important; page-break-after: always; break-after: page; max-width: none; }
            .tnc-batch-print-wrap:last-child { page-break-after: auto; break-after: auto; }
            .invoice-box { box-shadow: none !important; margin: 0 !important; min-height: 297mm; height: 297mm; }
            <?php if ($kind === 'po'): ?>
            .invoice-box .po-vat-line {
                color: var(--brand-color-deep) !important;
            }
            .invoice-box .po-total-sheet {
                background: #fff9f0 !important;
                border-color: #fdba74 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .invoice-box .grand-total-row {
                background: var(--brand-color) !important;
                color: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .invoice-box .grand-total-row span {
                color: #fff !important;
            }
            .po-payment-slip-print-wrap {
                page-break-before: always;
                page-break-inside: avoid;
                break-inside: avoid;
                margin: 0;
                max-width: none;
                max-height: 297mm;
                overflow: hidden;
            }
            .po-payment-slip-print-wrap .no-print {
                display: none !important;
            }
            .po-payment-slip-sheet {
                box-shadow: none !important;
                border: none !important;
                background: #fff !important;
                padding: 6mm 8mm 8mm;
                border-radius: 0;
                max-height: 297mm;
                box-sizing: border-box;
                overflow: hidden;
            }
            .po-slip-paper-header {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .po-slip-img-wrap {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 100%;
                max-height: 215mm;
                overflow: hidden;
            }
            .po-payment-slip-img {
                max-width: 175mm !important;
                max-height: 215mm !important;
                width: auto !important;
                height: auto !important;
                object-fit: contain !important;
                margin-left: auto;
                margin-right: auto;
                display: block !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .po-quotation-pdf-iframe {
                min-height: 0;
                height: 215mm;
                max-height: 215mm;
                max-width: 175mm;
                margin: 0 auto;
                display: block;
                border: none !important;
                background: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            <?php endif; ?>
            .po-cancelled-watermark {
                color: rgba(185, 28, 28, 0.5);
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

<div class="tnc-batch-toolbar d-flex flex-wrap align-items-center justify-content-between gap-2">
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
        <a href="<?= htmlspecialchars($backPr, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">รายการ PR</a>
        <a href="<?= htmlspecialchars($backPo, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-primary">รายการ PO</a>
    </div>
<?php elseif (count($ids) === 0): ?>
    <div class="container py-5">
        <div class="alert alert-info mb-0">ยังไม่ได้เลือกเลขที่เอกสาร — กลับไปติ๊กที่รายการแล้วกด «พิมพ์ที่เลือก»</div>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary mt-3">กลับ</a>
    </div>
<?php elseif (count($documents) === 0): ?>
    <div class="container py-5">
        <div class="alert alert-danger mb-0">ไม่พบเอกสารตามรหัสที่ส่งมา หรือถูกลบแล้ว</div>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary mt-3">กลับ</a>
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
                    <?php tnc_purchase_po_payment_slip_print_render($block['ctx']['po']); ?>
                <?php endif; ?>
                <?php if (in_array($poPrintModeBatch, ['both', 'all'], true)): ?>
                    <?php tnc_purchase_po_quotation_attachment_print_render($block['ctx']['po']); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script src="<?= htmlspecialchars(app_path('assets/js/tnc-po-print.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
