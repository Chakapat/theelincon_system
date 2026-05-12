<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$pr_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$pr = Db::rowByIdField('purchase_requests', $pr_id);
if (!$pr) {
    echo "<script>alert('ไม่พบข้อมูลใบขอซื้อ'); window.location.href='" . htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES) . "';</script>";
    exit();
}

$users = Db::tableKeyed('users');
$rb = $users[(string) ($pr['requested_by'] ?? '')] ?? null;
$cb = $users[(string) ($pr['created_by'] ?? '')] ?? null;
$pr['fname'] = $rb['fname'] ?? '';
$pr['lname'] = $rb['lname'] ?? '';
$pr['creator_fname'] = $cb['fname'] ?? '';
$pr['creator_lname'] = $cb['lname'] ?? '';

$item_rows = Db::filter('purchase_request_items', static function (array $r) use ($pr_id): bool {
    return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
});
Db::sortRows($item_rows, 'id', false);

$existing_po = Db::findFirst('purchase_orders', static function (array $r) use ($pr_id): bool {
    return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
});
$requestType = trim((string) ($pr['request_type'] ?? ($pr['procurement_type'] ?? 'purchase')));
if (!in_array($requestType, ['purchase', 'hire'], true)) {
    $requestType = 'purchase';
}
$contractorName = trim((string) ($pr['contractor_name'] ?? ($pr['hire_contractor_name'] ?? '')));
$contractValue = (float) ($pr['contract_value'] ?? ($pr['hire_total_value'] ?? 0));
$installmentTotal = (int) ($pr['installment_total'] ?? ($pr['hire_installment_count'] ?? 1));
if ($installmentTotal < 1) {
    $installmentTotal = 1;
}
$hireScope = trim((string) ($pr['hire_scope_details'] ?? ''));

$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', false);
$com = array_values($companies)[0] ?? [];

$requesterDisplay = trim((string) ($pr['fname'] ?? '') . ' ' . (string) ($pr['lname'] ?? ''));
$creatorDisplay = trim((string) ($pr['creator_fname'] ?? '') . ' ' . (string) ($pr['creator_lname'] ?? ''));

$pv = (float) ($pr['vat_amount'] ?? 0);
$pg = (float) $pr['total_amount'];
if (isset($pr['subtotal_amount']) && $pr['subtotal_amount'] !== null && $pr['subtotal_amount'] !== '') {
    $ps = (float) $pr['subtotal_amount'];
} else {
    $ps = round($pg - $pv, 2);
}
$vatOn = (int) ($pr['vat_enabled'] ?? 0) === 1;

$siteDisplay = trim((string) ($pr['site_name'] ?? ''));
$siteIdPr = (int) ($pr['site_id'] ?? 0);
if ($siteDisplay === '' && $siteIdPr > 0) {
    $siteRowPr = Db::row('sites', (string) $siteIdPr);
    if (is_array($siteRowPr)) {
        $siteDisplay = trim((string) ($siteRowPr['name'] ?? ''));
    }
}

$createdRaw = trim((string) ($pr['created_at'] ?? ''));

function pr_format_date_thai(mixed $date): string
{
    $s = trim((string) $date);
    if ($s === '') {
        return '-';
    }
    $ts = strtotime($s);
    if ($ts === false) {
        return '-';
    }

    return date('d/m/Y', $ts);
}

$quotationAttach = trim((string) ($pr['quotation_attachment_path'] ?? ''));
$quotationName = trim((string) ($pr['quotation_attachment_name'] ?? ''));
$detailsText = trim((string) ($pr['details'] ?? ''));

$hireTableNote = $requestType === 'hire' && count($item_rows) === 0 && $hireScope !== '';

$poShortcutUrl = app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . (int) $pr['id'];
if (is_array($existing_po) && (int) ($existing_po['id'] ?? 0) > 0) {
    $poShortcutUrl = app_path('pages/purchase/purchase-order-view.php') . '?id=' . (int) $existing_po['id'];
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ใบขอซื้อ (PR) — <?= htmlspecialchars((string) ($pr['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></title>
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
                        <i class="bi bi-file-earmark-plus me-1"></i>ออก PO จาก PR
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

<div class="invoice-box">
    <div class="pr-doc-main">
    <div class="row align-items-start mb-2">
        <div class="col-6">
            <?php if (!empty($com['logo'])): ?>
                <img src="<?= htmlspecialchars(upload_logo_url((string) $com['logo']), ENT_QUOTES, 'UTF-8') ?>" class="company-logo" alt="Logo">
            <?php endif; ?>
            <div class="fw-bold mt-2" style="font-size: 16px;"><?= htmlspecialchars((string) ($com['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="small text-muted" style="font-size: 11px; line-height: 1.4;">
                <?= htmlspecialchars((string) ($com['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?><br>
                โทร: <?= htmlspecialchars((string) ($com['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?> | Tax ID: <?= htmlspecialchars((string) ($com['tax_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
        <div class="col-6 text-end">
            <div class="invoice-title"><?= $requestType === 'hire' ? 'REQUISITION (HIRE)' : 'PURCHASE REQUISITION' ?></div>
            <div class="fw-bold text-muted small"><?= $requestType === 'hire' ? 'ใบขอจ้าง / จัดจ้าง' : 'ใบขอซื้อ (PR)' ?></div>
            <div class="fw-bold text-dark mt-2" style="font-size: 18px;">เลขที่: <?= htmlspecialchars((string) ($pr['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($quotationAttach !== ''): ?>
                <?php
                $attachLabel = $quotationName !== '' ? $quotationName : 'เปิดไฟล์แนบ';
                ?>
                <div class="small text-muted mt-2">แนบใบเสนอราคา:
                    <a href="<?= htmlspecialchars(app_path($quotationAttach), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="no-print"><?= htmlspecialchars($attachLabel, ENT_QUOTES, 'UTF-8') ?></a>
                    <span class="d-none d-print-inline"><?= htmlspecialchars($attachLabel, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row mb-2 mt-3">
        <div class="col-7 border-start border-4 border-success ps-3">
            <div style="font-size: 10px; color: var(--brand-color); font-weight: bold; text-transform: uppercase;">ผู้ขอซื้อ / ผู้รับผิดชอบ</div>
            <div class="fw-bold" style="font-size: 15px;"><?= htmlspecialchars($requesterDisplay !== '' ? $requesterDisplay : '-', ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($creatorDisplay !== '' && $creatorDisplay !== $requesterDisplay): ?>
                <div class="small text-muted">ผู้บันทึกในระบบ: <?= htmlspecialchars($creatorDisplay, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
        <div class="col-5 text-end">
            <div style="font-size: 10px; color: var(--brand-color); font-weight: bold; text-transform: uppercase;">วันที่เอกสาร</div>
            <div class="fw-bold" style="font-size: 15px;"><?= htmlspecialchars(pr_format_date_thai($createdRaw !== '' ? $createdRaw : date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($requestType === 'hire'): ?>
                <div class="small text-muted mt-1">ประเภท: จัดจ้าง</div>
            <?php endif; ?>
            <?php if ($siteDisplay !== ''): ?>
                <div class="small text-muted mt-1">สถานที่: <?= htmlspecialchars($siteDisplay, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($requestType === 'hire'): ?>
    <div class="row mb-3 small">
        <div class="col-12 border rounded-2 p-2 bg-light">
            <strong>ผู้รับจ้าง:</strong> <?= htmlspecialchars($contractorName !== '' ? $contractorName : '-', ENT_QUOTES, 'UTF-8') ?>
            &nbsp;|&nbsp; <strong>มูลค่าสัญญา:</strong> <?= number_format($contractValue, 2) ?> บาท
            &nbsp;|&nbsp; <strong>จำนวนงวด:</strong> <?= number_format($installmentTotal) ?> งวด
        </div>
    </div>
    <?php endif; ?>

    <?php if ($detailsText !== ''): ?>
    <div class="mb-3 p-2 border rounded-2" style="font-size: 12px;">
        <div class="fw-bold text-secondary text-uppercase mb-1" style="font-size: 10px;">รายละเอียด / วัตถุประสงค์</div>
        <div style="white-space: pre-line;"><?= htmlspecialchars($detailsText, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php endif; ?>

    <table class="table table-custom">
        <thead>
            <tr class="text-center">
                <th style="width:5%;" class="text-center">#</th>
                <th style="width:38%;" class="text-start">รายละเอียดสินค้า / บริการ</th>
                <th style="width:12%;">จำนวน</th>
                <th style="width:10%;">หน่วย</th>
                <th style="width:13%;" class="text-end">ราคา/หน่วย</th>
                <th style="width:12%;" class="text-end">ยอดรวม</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($hireTableNote): ?>
            <tr>
                <td class="text-center text-muted">1</td>
                <td class="text-start fw-semibold" style="white-space: pre-line;"><?= htmlspecialchars($hireScope, ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-center">1</td>
                <td class="text-center text-muted">—</td>
                <td class="text-end"><?= number_format($contractValue, 2) ?></td>
                <td class="text-end fw-bold"><?= number_format($contractValue, 2) ?></td>
            </tr>
            <?php elseif (count($item_rows) === 0): ?>
            <tr>
                <td colspan="6" class="text-center text-muted py-4">ไม่มีรายการบรรทัด (งานจัดจ้างอาจสรุปเป็นยอดเดียว)</td>
            </tr>
            <?php else: ?>
                <?php $i = 1; foreach ($item_rows as $item): ?>
                <tr>
                    <td class="text-center text-muted"><?= $i++ ?></td>
                    <td class="fw-bold text-dark text-start"><?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-center"><?= number_format((float) ($item['quantity'] ?? 0), 2) ?></td>
                    <td class="text-center"><?php
                        $unitCell = trim((string) ($item['unit'] ?? ''));
                        echo $unitCell !== '' ? htmlspecialchars($unitCell, ENT_QUOTES, 'UTF-8') : '—';
                    ?></td>
                    <td class="text-end"><?= number_format((float) ($item['unit_price'] ?? 0), 2) ?></td>
                    <td class="text-end fw-bold"><?= number_format((float) ($item['total'] ?? 0), 2) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="footer-sticky">
        <div class="row align-items-end mb-3">
            <div class="col-7 small text-muted">
                <?php if ($requestType === 'hire' && $hireScope !== '' && !$hireTableNote): ?>
                    <div style="font-size: 11px; font-weight: 700; color: #111; margin-bottom: 4px;">ขอบเขตงาน</div>
                    <div style="font-size: 12px; line-height: 1.45; color: #444; white-space: pre-line;"><?= htmlspecialchars($hireScope, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
            <div class="col-5">
                <div class="summary-box" style="background: #f8fbff; border: 1px solid #c7dbfa; border-radius: 0.5rem; padding: 0.75rem 1rem;">
                    <div class="summary-item">
                        <span>ยอดรายการ (ก่อน VAT)</span>
                        <span><?= number_format($ps, 2) ?></span>
                    </div>
                    <?php if ($vatOn && $pv > 0): ?>
                    <div class="summary-item text-success">
                        <span>VAT 7%</span>
                        <span><?= number_format($pv, 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="grand-total-row">
                        <span class="fw-bold" style="font-size: 14px;">ยอดรวมสุทธิ</span>
                        <span style="font-size: 18px; font-weight: 800;">฿ <?= number_format($pg, 2) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="signature-grid">
            <div>
                <div class="sig-space"></div>
                <div class="sig-box">ผู้ขอซื้อ / ผู้รับผิดชอบ<br><small>(Requester)</small></div>
            </div>
            <div>
                <div class="sig-space"></div>
                <div class="sig-box">ผู้มีอำนาจลงนาม<br><small>(Authorized)</small></div>
            </div>
        </div>
    </div>
</div>

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
