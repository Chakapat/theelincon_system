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
$prCtx = tnc_purchase_pr_print_prepare($pr_id);
if ($prCtx === null) {
    echo "<script>alert('ไม่พบข้อมูลใบขอซื้อ'); window.location.href='" . htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES) . "';</script>";
    exit();
}
extract($prCtx, EXTR_OVERWRITE);
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
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/document-print.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        :root { --brand-color: #28a745; --dark: #333; }
        body { font-family: 'Sarabun', 'Leelawadee UI', 'Segoe UI', Tahoma, sans-serif; background: #f4f4f4; color: var(--dark); margin: 0; padding: 0; font-weight: 500; }

        .invoice-box {
            width: 210mm;
            max-width: 100%;
            height: 297mm;
            margin: 0 auto 1.5rem;
            background: #fff;
            padding: 10mm 15mm;
            position: relative;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border-top: 8px solid var(--brand-color);
            overflow: hidden;
        }

        .pr-cancelled-watermark {
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%) rotate(-32deg);
            font-size: clamp(2.5rem, 12vw, 4.5rem);
            font-weight: 800;
            color: rgba(220, 38, 38, 0.38);
            letter-spacing: 0.18em;
            white-space: nowrap;
            pointer-events: none;
            z-index: 60;
            user-select: none;
            text-transform: uppercase;
        }

        .company-logo { max-height: 84px; width: auto; max-width: 220px; object-fit: contain; }
        .invoice-title { font-size: 28px; font-weight: 800; color: var(--brand-color); line-height: 1.1; }

        /* ตารางรายการ — ระยะห่างและบรรทัดอ่านง่าย */
        .pr-purchase-requisition-doc .table-custom {
            margin-top: 10px;
            margin-bottom: 0;
        }
        .pr-purchase-requisition-doc .table-custom thead th {
            background: #fafafa;
            border-bottom: 2px solid var(--brand-color);
            font-size: 12px;
            font-weight: 700;
            line-height: 1.45;
            padding: 9px 11px;
            vertical-align: middle;
        }
        .pr-purchase-requisition-doc .table-custom tbody td {
            padding: 9px 11px;
            font-size: 12px;
            line-height: 1.45;
            border-bottom: 1px solid #f2f2f2;
            vertical-align: middle;
        }
        .pr-purchase-requisition-doc .table-custom tbody td.fw-bold {
            line-height: 1.4;
        }

        /* ยอดรวม + ลายเซ็น ติดขอบล่างกระดาษ A4 */
        .pr-purchase-requisition-doc .footer-sticky {
            position: absolute;
            bottom: 12mm;
            left: 15mm;
            right: 15mm;
        }
        .pr-purchase-requisition-doc .pr-footer-panel {
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .pr-purchase-requisition-doc .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 0.75rem;
            padding: 3px 0;
            font-size: 13px;
            line-height: 1.45;
        }
        .pr-purchase-requisition-doc .summary-box {
            background: #f8fbff;
            border: 1px solid #c7dbfa;
            border-radius: 0.5rem;
            padding: 0.65rem 0.9rem;
        }
        .pr-purchase-requisition-doc .grand-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--brand-color);
            color: #fff;
            padding: 10px 12px;
            border-radius: 5px;
            margin-top: 6px;
            line-height: 1.35;
        }
        .pr-purchase-requisition-doc .signature-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            text-align: center;
            margin-top: 16px;
        }
        .pr-purchase-requisition-doc .sig-space { height: 56px; }
        .pr-purchase-requisition-doc .sig-box {
            border-top: 1px solid #333;
            padding-top: 8px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.4;
        }

        .pr-view-toolbar {
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        }
        .pr-view-toolbar .btn { font-weight: 600; }
        .pr-view-toolbar-inner { max-width: 210mm; margin: 0 auto; }
        .pr-purchase-requisition-doc .pr-doc-main {
            padding-bottom: 50mm;
            box-sizing: border-box;
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
            }
            .pr-purchase-requisition-doc .pr-doc-main { padding-bottom: 0; }
            .pr-purchase-requisition-doc .footer-sticky {
                position: static;
                bottom: auto;
                left: auto;
                right: auto;
                margin-top: 1.25rem;
            }
            .pr-purchase-requisition-doc .signature-grid { grid-template-columns: 1fr; gap: 18px; }
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 0;
            }

            html {
                font-size: 15px;
            }

            body {
                background: none !important;
                margin: 0;
                font-weight: 500;
            }

            body,
            .invoice-box,
            .invoice-box * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .no-print,
            nav,
            .navbar {
                display: none !important;
            }

            .invoice-box.pr-purchase-requisition-doc {
                width: 210mm;
                max-width: 210mm;
                height: 297mm;
                min-height: 297mm;
                max-height: 297mm;
                margin: 0;
                padding: 8mm 12mm;
                box-shadow: none;
                border-top: 6px solid var(--brand-color);
                overflow: hidden;
                page-break-after: avoid;
                break-after: avoid;
            }

            .pr-purchase-requisition-doc .pr-doc-main {
                padding-bottom: 46mm;
            }

            .pr-purchase-requisition-doc .company-logo {
                max-height: 20mm;
            }

            .pr-purchase-requisition-doc .invoice-title {
                font-size: 24px;
                line-height: 1.15;
            }

            .pr-purchase-requisition-doc .table-custom {
                margin-top: 8px;
            }

            .pr-purchase-requisition-doc .table-custom thead th {
                font-size: 10.5px;
                font-weight: 700;
                line-height: 1.45;
                padding: 7px 10px;
                background: #fafafa !important;
                border-bottom: 2px solid #28a745 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .pr-purchase-requisition-doc .table-custom tbody td {
                font-size: 10.5px;
                line-height: 1.45;
                padding: 7px 10px;
                vertical-align: middle;
            }

            .pr-purchase-requisition-doc .table-custom tbody tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .pr-purchase-requisition-doc .footer-sticky {
                position: absolute;
                bottom: 8mm;
                left: 12mm;
                right: 12mm;
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .pr-purchase-requisition-doc .pr-footer-panel {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .pr-purchase-requisition-doc .summary-box {
                padding: 0.5rem 0.75rem;
                background: #f8fbff !important;
                border-color: #c7dbfa !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .pr-purchase-requisition-doc .summary-item {
                font-size: 12px;
                line-height: 1.45;
                padding: 2px 0;
            }

            .pr-purchase-requisition-doc .grand-total-row {
                padding: 8px 10px;
                margin-top: 5px;
                background: #28a745 !important;
                color: #fff !important;
                page-break-inside: avoid;
                break-inside: avoid;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .pr-purchase-requisition-doc .grand-total-row span {
                color: #fff !important;
            }

            .pr-purchase-requisition-doc .grand-total-row .fw-bold {
                font-size: 12px !important;
            }

            .pr-purchase-requisition-doc .grand-total-row span:last-child {
                font-size: 16px !important;
                font-weight: 800 !important;
            }

            .pr-purchase-requisition-doc .signature-grid {
                margin-top: 10px;
                gap: 28px;
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .pr-purchase-requisition-doc .sig-space {
                height: 40px;
            }

            .pr-purchase-requisition-doc .sig-box {
                font-size: 11px;
                line-height: 1.4;
                padding-top: 6px;
            }

            /* หัวเอกสาร / บล็อกข้อมูล — กระชับลงเล็กน้อย */
            .pr-purchase-requisition-doc .row.mb-2.mt-3,
            .pr-purchase-requisition-doc .row.mb-2 {
                margin-bottom: 0.4rem !important;
            }

            .pr-purchase-requisition-doc .mb-3 {
                margin-bottom: 0.5rem !important;
            }

            .pr-cancelled-watermark {
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
                color: rgba(185, 28, 28, 0.48) !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="no-print pr-view-toolbar py-3 mb-3 shadow-sm">
    <div class="px-3 pr-view-toolbar-inner">
        <?php if (!empty($_GET['error']) && $_GET['error'] === 'po_exists'): ?>
            <div class="alert alert-warning py-2 px-3 mb-3 border-0 shadow-sm">ใบขอซื้อนี้มีใบสั่งซื้อแล้ว ไม่สามารถออกซ้ำได้</div>
        <?php endif; ?>
        <?php if (!empty($isPoCancelled)): ?>
            <div class="alert alert-danger py-2 px-3 mb-3 border-0 shadow-sm">
                <i class="bi bi-x-octagon me-1"></i>ใบสั่งซื้อ (PO) ที่เชื่อมกับ PR นี้ถูกยกเลิกแล้ว (CANCELLED)
            </div>
        <?php endif; ?>
        <div class="d-flex flex-wrap align-items-stretch justify-content-between gap-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-3">
                    <i class="bi bi-arrow-left me-1"></i>รายการ PR
                </a>
            </div>
            <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 flex-grow-1 flex-lg-grow-0">
                <button type="button" onclick="window.print()" class="btn btn-success rounded-pill px-4 shadow-sm">
                    <i class="bi bi-printer me-1"></i>พิมพ์
                </button>
                <?php if ($requestType !== 'hire' && $existing_po): ?>
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-view.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $existing_po['id'] ?>" class="btn btn-outline-primary rounded-pill px-3" title="คีย์ลัด: Ctrl+Shift+G">
                        <i class="bi bi-eye me-1"></i>ดู PO
                    </a>
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light border rounded-pill px-3">รายการ PO</a>
                <?php elseif ($requestType !== 'hire'): ?>
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-from-pr.php'), ENT_QUOTES, 'UTF-8') ?>?pr_id=<?= (int) $pr['id'] ?>" class="btn btn-primary rounded-pill px-4 shadow-sm" title="คีย์ลัด: Ctrl+Shift+G">
                        <i class="bi bi-file-earmark-plus me-1"></i>สร้างใบสั่งซื้อ (PO)
                    </a>
                <?php else: ?>
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-from-pr.php'), ENT_QUOTES, 'UTF-8') ?>?pr_id=<?= (int) $pr['id'] ?>" class="btn btn-primary rounded-pill px-3 shadow-sm" title="คีย์ลัด: Ctrl+Shift+G">
                        <i class="bi bi-file-earmark-plus me-1"></i>ออก PO / สั่งจ่าย
                    </a>
                    <a href="<?= htmlspecialchars(app_path('pages/hire-contracts/hire-contract-view.php'), ENT_QUOTES, 'UTF-8') ?>?pr_id=<?= (int) $pr['id'] ?>" class="btn btn-outline-secondary rounded-pill px-3">
                        <i class="bi bi-file-earmark-ruled me-1"></i>สัญญาจ้าง
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php tnc_purchase_pr_print_render($prCtx); ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
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
</body>
</html>
