<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$id = (int) ($_GET['id'] ?? 0);

$po = Db::rowByIdField('purchase_orders', $id);
if (!$po) {
    die('ไม่พบข้อมูลใบสั่งซื้อ');
}

$sup = Db::rowByIdField('suppliers', (int) ($po['supplier_id'] ?? 0));
$prId = (int) ($po['pr_id'] ?? 0);
$pr = $prId > 0 ? Db::rowByIdField('purchase_requests', $prId) : null;

$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', false);
$companyRows = array_values($companies);
$com = $companyRows[0] ?? [];

$data = $po;
foreach (['name', 'logo', 'address', 'phone', 'tax_id'] as $ck) {
    $data[$ck] = $com[$ck] ?? '';
}
$data['s_name'] = $sup['name'] ?? '';
$data['s_address'] = $sup['address'] ?? '';
$data['s_tax'] = $sup['tax_id'] ?? '';
$data['s_phone'] = $sup['phone'] ?? '';
$data['contact_person'] = $sup['contact_person'] ?? '';
$data['pr_number'] = $pr['pr_number'] ?? '';
$orderType = trim((string) ($data['order_type'] ?? 'purchase'));
if (!in_array($orderType, ['purchase', 'hire'], true)) {
    $orderType = 'purchase';
}
$contractorName = trim((string) ($data['contractor_name'] ?? ''));
$installmentNo = (int) ($data['installment_no'] ?? 0);
$installmentTotal = (int) ($data['installment_total'] ?? 0);
$referencePrNumber = trim((string) ($data['reference_pr_number'] ?? ($data['pr_number'] ?? '')));
$withholdingType = trim((string) ($data['withholding_type'] ?? 'none'));
if ($withholdingType === 'wht5') {
    $withholdingType = 'wht3';
}
if (!in_array($withholdingType, ['none', 'wht3'], true)) {
    $withholdingType = 'none';
}
$withholdingAmount = (float) ($data['withholding_amount'] ?? 0);
$retentionType = trim((string) ($data['retention_type'] ?? 'none'));
if (!in_array($retentionType, ['none', 'percent', 'fixed'], true)) {
    $retentionType = 'none';
}
$retentionAmount = (float) ($data['retention_amount'] ?? 0);
$poNotePo = trim((string) ($data['po_note'] ?? ''));
$poNoteQt = trim((string) ($data['quotation_note'] ?? ''));

$prId = (int) ($po['pr_id'] ?? 0);
$poNumber = trim((string) ($po['po_number'] ?? ''));
$items = Db::filter('purchase_order_items', static function (array $r) use ($id, $poNumber): bool {
    $poId = isset($r['po_id']) ? (int) $r['po_id'] : 0;
    $purchaseOrderId = isset($r['purchase_order_id']) ? (int) $r['purchase_order_id'] : 0;
    $poNumberRef = trim((string) ($r['po_number'] ?? ''));
    return $poId === $id
        || $purchaseOrderId === $id
        || ($poNumberRef !== '' && $poNumberRef === $poNumber);
});
if (count($items) === 0 && $prId > 0) {
    // รองรับข้อมูลเก่าบางชุดที่เก็บ item ใต้ purchase_order_items โดยอ้าง pr_id
    $items = Db::filter('purchase_order_items', static function (array $r) use ($prId): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $prId;
    });
}
if (count($items) === 0 && $prId > 0) {
    // รองรับข้อมูลเก่าที่บางครั้งยังเก็บ item ไว้ใต้ PR
    $items = Db::filter('purchase_request_items', static function (array $r) use ($prId): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $prId;
    });
}
Db::sortRows($items, 'id', false);
function formatDateThai($date) {
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

$po_vat_enabled = (int)($data['vat_enabled'] ?? 0);
$po_vat_amount = (float)($data['vat_amount'] ?? 0);
$po_grand_total = (float)$data['total_amount'];
// วันที่ออกบิลของ PO: ใช้ issue_date ก่อน แล้ว fallback ไป created_at หรือวันที่ PR
$issueDate = (string) ($data['issue_date'] ?? '');
if (trim($issueDate) === '') {
    $issueDate = (string) ($data['created_at'] ?? '');
}
if (trim($issueDate) === '' && isset($pr['created_at'])) {
    $issueDate = (string) $pr['created_at'];
}
if (isset($data['subtotal_amount']) && $data['subtotal_amount'] !== null && $data['subtotal_amount'] !== '') {
    $po_subtotal = (float)$data['subtotal_amount'];
} else {
    $po_subtotal = round($po_grand_total - $po_vat_amount, 2);
}
$po_gross_amount = (float) (($data['gross_amount'] ?? '') !== '' ? $data['gross_amount'] : ($po_subtotal + $po_vat_amount));
$poStatus = strtolower(trim((string) ($data['status'] ?? 'ordered')));
$isPoCancelled = ($poStatus === 'cancelled');
$paymentStatusPo = strtolower(trim((string) ($po['payment_status'] ?? 'unpaid')));
$isPoPaid = ($paymentStatusPo === 'paid');
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order - <?= htmlspecialchars((string) ($data['po_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/document-print.css')) ?>">
    
    <style>
        :root { --brand-color: #28a745; --dark: #333; }
        body { font-family: 'Sarabun', 'Leelawadee UI', 'Segoe UI', Tahoma, sans-serif; background: #f4f4f4; color: var(--dark); margin: 0; padding: 0; font-weight: 500; }
        
        .invoice-box {
            width: 210mm;
            max-width: 100%;
            height: 297mm;
            margin: 0 auto;
            background: #fff;
            padding: 10mm 15mm;
            position: relative;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border-top: 8px solid var(--brand-color);
            overflow: hidden;
        }

        .company-logo { max-height: 84px; width: auto; max-width: 220px; object-fit: contain; }
        .invoice-title { font-size: 32px; font-weight: 800; color: var(--brand-color); line-height: 1; }
        .table-custom { margin-top: 15px; margin-bottom: 0; }
        .table-custom thead th { background: #fafafa; border-bottom: 2px solid var(--brand-color); font-size: 13px; padding: 10px; }
        .table-custom td { padding: 10px; font-size: 13px; border-bottom: 1px solid #f2f2f2; }

        .footer-sticky { position: absolute; bottom: 12mm; left: 15mm; right: 15mm; }
        .summary-item { display: flex; justify-content: space-between; padding: 2px 0; font-size: 14px; }
        
        .grand-total-row { 
            display: flex; justify-content: space-between; align-items: center; 
            background: var(--brand-color); color: white; padding: 12px; border-radius: 5px; margin-top: 8px; 
        }
        
        .signature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; text-align: center; margin-top: 30px; }
        .sig-space { height: 75px; }
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

        @media (max-width: 575.98px) {
            body { background: #fff; }
            .invoice-box {
                width: 100%;
                height: auto;
                padding: 1rem;
                box-shadow: none;
                overflow: visible;
            }
            .footer-sticky { position: static; bottom: auto; left: auto; right: auto; margin-top: 1rem; }
            .signature-grid { grid-template-columns: 1fr; gap: 18px; }
        }

        @media print {
            @page { size: A4; margin: 0; }
            body { background: none; }
            .no-print, nav.navbar { display: none !important; }
            .invoice-box { margin: 0; box-shadow: none; border-top: 8px solid var(--brand-color); }
            .po-cancelled-watermark {
                color: rgba(185, 28, 28, 0.5);
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

<div class="no-print">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
</div>

<div class="controls-wrapper no-print py-3 mb-3 shadow-sm border-bottom" style="background: linear-gradient(180deg, #fff 0%, #f8fafc 100%); border-color: #e2e8f0 !important;">
    <?php if (!empty($_GET['cancelled'])): ?>
        <div class="alert alert-success py-2 px-3 mb-3 mx-auto" style="max-width: 520px;">ยกเลิกใบสั่งซื้อเรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'po_paid'): ?>
        <div class="alert alert-warning py-2 px-3 mb-3 mx-auto" style="max-width: 520px;">ใบสั่งซื้อนี้สถานะการจ่ายเป็น «จ่ายแล้ว» ไม่สามารถยกเลิกได้</div>
    <?php endif; ?>
    <div class="container d-flex flex-wrap align-items-center justify-content-center gap-2">
    <button type="button" onclick="window.print()" class="btn btn-success rounded-pill px-4 shadow-sm fw-semibold">
        <i class="bi bi-printer me-1"></i>พิมพ์ใบสั่งซื้อ
    </button>
    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-3">รายการ PO</a>
    <?php if (!$isPoCancelled && !$isPoPaid): ?>
        <form method="post" action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=cancel_purchase_order" class="d-inline ms-2" data-tnc-fullnav="1" onsubmit="return confirm('ยืนยันยกเลิกใบสั่งซื้อนี้? สถานะจะเปลี่ยนเป็น ยกเลิก และจะแสดงประทับบนใบพิมพ์');">
            <?php csrf_field(); ?>
            <input type="hidden" name="po_id" value="<?= (int) $id ?>">
            <input type="hidden" name="return_to" value="view">
            <button type="submit" class="btn btn-outline-danger rounded-pill px-3"><i class="bi bi-x-circle me-1"></i>ยกเลิกใบ PO</button>
        </form>
    <?php endif; ?>
    </div>
</div>

<div class="invoice-box">
    <?php if ($isPoCancelled): ?>
    <div class="po-cancelled-watermark" aria-hidden="true">ยกเบิกใบสั่งซื้อ</div>
    <?php endif; ?>
    <div class="row align-items-start mb-2">
        <div class="col-6">
            <?php if(!empty($data['logo'])): ?>
                <img src="<?= htmlspecialchars(upload_logo_url($data['logo'])) ?>" class="company-logo" alt="Logo">
            <?php endif; ?>
            <div class="fw-bold mt-2" style="font-size: 16px;"><?= $data['name']; ?></div>
            <div class="small text-muted" style="font-size: 11px; line-height: 1.4;">
                <?= $data['address']; ?><br>
                โทร: <?= $data['phone']; ?> | Tax ID: <?= $data['tax_id']; ?>
            </div>
        </div>
        <div class="col-6 text-end">
            <div class="invoice-title"><?= $orderType === 'hire' ? 'PAYMENT ORDER' : 'PURCHASE ORDER' ?></div>
            <div class="fw-bold text-muted small"><?= $orderType === 'hire' ? 'ใบสั่งจ่าย / ใบสั่งจ้าง' : 'ใบสั่งซื้อสินค้า' ?></div>
            <div class="fw-bold text-dark mt-2" style="font-size: 18px;"><?= $orderType === 'hire' ? 'เลขที่ใบสั่งจ่าย' : 'เลขที่ใบสั่งซื้อ' ?>: <?= htmlspecialchars((string) ($data['po_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php $quotationNo = trim((string) ($data['quotation_number'] ?? '')); ?>
            <?php $quotationAttach = trim((string) ($data['quotation_attachment_path'] ?? '')); ?>
            <?php if ($quotationNo !== ''): ?>
                <div class="small text-muted">อ้างอิงใบเสนอราคา: <?= htmlspecialchars($quotationNo, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($quotationAttach !== ''): ?>
                <?php
                $attachLabel = trim((string) ($data['quotation_attachment_name'] ?? ''));
                if ($attachLabel === '') {
                    $attachLabel = 'เปิดไฟล์';
                }
                ?>
                <div class="small text-muted">ไฟล์ใบเสนอราคา:
                    <a href="<?= htmlspecialchars(app_path($quotationAttach), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($attachLabel, ENT_QUOTES, 'UTF-8') ?></a>
                </div>
            <?php endif; ?>
            <?php if ($referencePrNumber !== ''): ?>
                <div class="small text-muted"><?= $orderType === 'hire' ? 'อ้างอิงสัญญา' : 'อ้างอิง PR' ?>: <?= htmlspecialchars($referencePrNumber, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($orderType === 'hire' && $installmentNo > 0 && $installmentTotal > 0): ?>
                <div class="small text-muted">งวดที่ <?= number_format($installmentNo) ?> / <?= number_format($installmentTotal) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row mb-2 mt-4">
        <div class="col-7 border-start border-4 border-success ps-3">
            <div style="font-size: 10px; color: var(--brand-color); font-weight: bold; text-transform: uppercase;"><?= $orderType === 'hire' ? 'ผู้รับจ้าง' : 'Vendor / ผู้ขาย' ?></div>
            <div class="fw-bold" style="font-size: 15px;"><?= htmlspecialchars($orderType === 'hire' && $contractorName !== '' ? $contractorName : (string) ($data['s_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="small text-muted" style="font-size: 12px;">
                <?= htmlspecialchars((string) ($data['s_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><br>
                <strong>Tax ID:</strong> <?= htmlspecialchars((string) ($data['s_tax'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> | <strong>โทร:</strong> <?= htmlspecialchars((string) ($data['s_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
        <div class="col-5 text-end">
            <div style="font-size: 10px; color: var(--brand-color); font-weight: bold; text-transform: uppercase;">วันที่ออกบิล</div>
            <div class="fw-bold" style="font-size: 15px;"><?= formatDateThai($issueDate); ?></div>
        </div>
    </div>

    <table class="table table-custom">
        <thead>
            <tr class="text-center">
                <th width="55%" class="text-start">รายละเอียดสินค้า/บริการ</th>
                <th width="15%">จำนวน</th>
                <th width="15%" class="text-end">ราคา/หน่วย</th>
                <th width="15%" class="text-end">ยอดรวม</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($items) === 0): ?>
            <tr>
                <td colspan="4" class="text-center text-muted py-4">ไม่พบรายการสินค้าในใบสั่งซื้อนี้</td>
            </tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td class="fw-bold text-dark text-start"><?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-center"><?= number_format((float) ($item['quantity'] ?? 0), 0); ?> <?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-end"><?= number_format((float) ($item['unit_price'] ?? 0), 2); ?></td>
                    <td class="text-end fw-bold"><?= number_format((float) ($item['total'] ?? 0), 2); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="footer-sticky">
        <div class="row align-items-end mb-4">
            <div class="col-7 small text-muted italic">
                <?php if ($poNotePo !== ''): ?>
                    <div style="font-size: 11px; font-weight: 700; color: #111; margin-bottom: 4px;">หมายเหตุ PO</div>
                    <div style="font-size: 12px; line-height: 1.45; color: #444; white-space: pre-line; margin-bottom: <?= $poNoteQt !== '' ? '12px' : '0' ?>;">
                        <?= htmlspecialchars($poNotePo, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <?php if ($poNoteQt !== ''): ?>
                    <div style="font-size: 11px; font-weight: 700; color: #111; margin-bottom: 4px;"><?= $poNotePo !== '' ? 'หมายเหตุ / เงื่อนไข (QT)' : 'หมายเหตุ' ?></div>
                    <div style="font-size: 12px; line-height: 1.45; color: #444; white-space: pre-line;">
                        <?= htmlspecialchars($poNoteQt, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-5">
                <div class="summary-box">
                    <div class="summary-item">
                        <span>ยอดรวม</span>
                        <span><?= number_format($po_subtotal, 2); ?></span>
                    </div>
                    <?php if ($po_vat_enabled && $po_vat_amount > 0): ?>
                    <div class="summary-item text-success">
                        <span>VAT 7%</span>
                        <span><?= number_format($po_vat_amount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($withholdingType !== 'none' && $withholdingAmount > 0): ?>
                    <div class="summary-item text-danger">
                        <span>หัก ณ ที่จ่าย 3%</span>
                        <span>-<?= number_format($withholdingAmount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($retentionType !== 'none' && $retentionAmount > 0): ?>
                    <div class="summary-item text-danger">
                        <span>หักประกันผลงาน<?= $retentionType === 'percent' ? ' (%)' : '' ?></span>
                        <span>-<?= number_format($retentionAmount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (($withholdingType !== 'none' && $withholdingAmount > 0) || ($retentionType !== 'none' && $retentionAmount > 0)): ?>
                    <div class="summary-item">
                        <span>ยอดก่อนหัก</span>
                        <span><?= number_format($po_gross_amount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="grand-total-row">
                        <span class="fw-bold" style="font-size: 14px;">ยอดสุทธิทั้งสิ้น</span>
                        <span style="font-size: 20px; font-weight: 800;">฿ <?= number_format($po_grand_total, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="signature-grid">
            <div>
                <div class="sig-space"></div>
                <div class="sig-box">ผู้สั่งซื้อ / สั่งจ้าง<br><small>(Authorized Signature)</small></div>
            </div>
            <div>
                <div class="sig-space"></div>
                <div class="sig-box">ผู้อนุมัติสั่งซื้อ / สั่งจ่าย<br><small>(Approver Signature)</small></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>