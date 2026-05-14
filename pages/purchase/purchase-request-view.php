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

        .company-logo { max-height: 84px; width: auto; max-width: 220px; object-fit: contain; }
        .invoice-title { font-size: 28px; font-weight: 800; color: var(--brand-color); line-height: 1.1; }
        .table-custom { margin-top: 12px; margin-bottom: 0; }
        .table-custom thead th { background: #fafafa; border-bottom: 2px solid var(--brand-color); font-size: 13px; padding: 10px; }
        .table-custom td { padding: 10px; font-size: 13px; border-bottom: 1px solid #f2f2f2; }

        /* ยอดรวม + ลายเซ็น ติดขอบล่างกระดาษ A4 (เหมือน PO) */
        .footer-sticky { position: absolute; bottom: 12mm; left: 15mm; right: 15mm; }
        .summary-item { display: flex; justify-content: space-between; padding: 2px 0; font-size: 14px; }
        .grand-total-row {
            display: flex; justify-content: space-between; align-items: center;
            background: var(--brand-color); color: white; padding: 12px; border-radius: 5px; margin-top: 8px;
        }
        .signature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; text-align: center; margin-top: 22px; }
        .sig-space { height: 72px; }
        .sig-box { border-top: 1px solid #333; padding-top: 10px; font-size: 13px; font-weight: 600; }

        .pr-view-toolbar {
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        }
        .pr-view-toolbar .btn { font-weight: 600; }
        .pr-view-toolbar-inner { max-width: 210mm; margin: 0 auto; }
        .pr-doc-main { padding-bottom: 52mm; box-sizing: border-box; }

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
            .pr-doc-main { padding-bottom: 0; }
            .footer-sticky { position: static; bottom: auto; left: auto; right: auto; margin-top: 1.25rem; }
            .signature-grid { grid-template-columns: 1fr; gap: 18px; }
        }

        @media print {
            @page { size: A4; margin: 0; }
            body { background: none; }
            .no-print { display: none !important; }
            nav, .navbar { display: none !important; }
            .invoice-box { margin: 0; box-shadow: none; border-top: 8px solid var(--brand-color); height: 297mm; }
            .footer-sticky { position: absolute; bottom: 12mm; left: 15mm; right: 15mm; }
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
