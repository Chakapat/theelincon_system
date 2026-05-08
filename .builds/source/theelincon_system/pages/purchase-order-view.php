<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

$id = (int) ($_GET['id'] ?? 0);

$po = Db::row('purchase_orders', (string) $id);
if (!$po) {
    die('ไม่พบข้อมูลใบสั่งซื้อ');
}

$sup = Db::row('suppliers', (string) ($po['supplier_id'] ?? ''));
$pr = Db::row('purchase_requests', (string) ($po['pr_id'] ?? ''));
$issuer = Db::row('users', (string) ($po['created_by'] ?? ''));

$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', false);
$com = $companies[0] ?? [];

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
$data['issuer_fname'] = $issuer['fname'] ?? '';
$data['issuer_lname'] = $issuer['lname'] ?? '';

$issuer_display = trim(($data['issuer_fname'] ?? '') . ' ' . ($data['issuer_lname'] ?? ''));
$issuer_display = $issuer_display !== '' ? htmlspecialchars($issuer_display, ENT_QUOTES, 'UTF-8') : 'ไม่ระบุ';

$items = Db::filter('purchase_order_items', static function (array $r) use ($id): bool {
    return isset($r['po_id']) && (int) $r['po_id'] === $id;
});
Db::sortRows($items, 'id', false);
function formatDateThai($date) { return date('d/m/Y', strtotime($date)); }

$po_vat_enabled = (int)($data['vat_enabled'] ?? 0);
$po_vat_amount = (float)($data['vat_amount'] ?? 0);
$po_grand_total = (float)$data['total_amount'];
if (isset($data['subtotal_amount']) && $data['subtotal_amount'] !== null && $data['subtotal_amount'] !== '') {
    $po_subtotal = (float)$data['subtotal_amount'];
} else {
    $po_subtotal = round($po_grand_total - $po_vat_amount, 2);
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Purchase Order - <?= $data['po_number']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/document-print.css')) ?>">
    
    <style>
        :root { --brand-color: #28a745; --dark: #333; }
        body { font-family: 'Sarabun', 'Leelawadee UI', 'Segoe UI', Tahoma, sans-serif; background: #f4f4f4; color: var(--dark); margin: 0; padding: 0; font-weight: 500; }
        
        .invoice-box { 
            width: 210mm; height: 297mm; margin: 0 auto; background: #fff; padding: 10mm 15mm; 
            position: relative; box-shadow: 0 5px 20px rgba(0,0,0,0.05); border-top: 8px solid var(--brand-color); overflow: hidden;
        }

        .doc-label-container { text-align: right; margin-bottom: 5px; }
        .doc-type-text {
            border: 2px solid var(--brand-color); color: var(--brand-color);
            padding: 2px 20px; font-weight: 800; font-size: 14px; border-radius: 5px;
            display: inline-block; background-color: #f6fff8;
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

        @media print {
            @page { size: A4; margin: 0; }
            body { background: none; }
            .no-print { display: none; }
            .invoice-box { margin: 0; box-shadow: none; border-top: 8px solid var(--brand-color); }
        }
    </style>
</head>
<body>

<div class="controls-wrapper no-print p-3 text-center bg-dark shadow-sm mb-4">
    <button onclick="window.print()" class="btn btn-success btn-sm fw-bold" style="padding: 5px 40px;">
        <i class="bi bi-printer"></i> พิมพ์ใบสั่งซื้อ
    </button>
    <a href="purchase-order-list.php" class="btn btn-outline-light btn-sm ms-2">กลับรายการ PO</a>
</div>

<div class="invoice-box">
    <div class="doc-label-container">
        <div class="doc-type-text">สั่งซื้อ / PURCHASE ORDER</div>
    </div>

    <div class="row align-items-start mb-2">
        <div class="col-6">
            <div class="invoice-title">PURCHASE ORDER</div>
            <div class="fw-bold text-muted small">ใบสั่งซื้อสินค้า</div>
            <div class="fw-bold text-dark mt-2" style="font-size: 18px;">เลขที่ใบสั่งซื้อ: <?= $data['po_number']; ?></div>
            <div class="small text-muted">อ้างอิงใบขอซื้อ: <?= $data['pr_number']; ?></div>
            <div class="small text-muted mt-1">ผู้ออกใบสั่งซื้อ: <?= $issuer_display; ?></div>
            <?php if ($po_vat_enabled): ?>
            <div class="mt-2"><span class="badge bg-success">รวม VAT 7%</span></div>
            <?php else: ?>
            <div class="mt-2"><span class="badge bg-secondary">ไม่รวม VAT</span></div>
            <?php endif; ?>
        </div>
        <div class="col-6 text-end">
            <?php if(!empty($data['logo'])): ?>
                <img src="<?= htmlspecialchars(upload_logo_url($data['logo'])) ?>" class="company-logo" alt="Logo">
            <?php endif; ?>
            <div class="fw-bold mt-2" style="font-size: 16px;"><?= $data['name']; ?></div>
            <div class="small text-muted" style="font-size: 11px; line-height: 1.4;">
                <?= $data['address']; ?><br>
                โทร: <?= $data['phone']; ?> | Tax ID: <?= $data['tax_id']; ?>
            </div>
        </div>
    </div>

    <div class="row mb-2 mt-4">
        <div class="col-7 border-start border-4 border-success ps-3">
            <div style="font-size: 10px; color: var(--brand-color); font-weight: bold; text-transform: uppercase;">Vendor / ผู้ขาย</div>
            <div class="fw-bold" style="font-size: 15px;"><?= $data['s_name']; ?></div>
            <div class="small text-muted" style="font-size: 12px;">
                <?= $data['s_address']; ?><br>
                <strong>Tax ID:</strong> <?= $data['s_tax']; ?> | <strong>โทร:</strong> <?= $data['s_phone']; ?>
            </div>
        </div>
        <div class="col-5 text-end">
            <div style="font-size: 10px; color: var(--brand-color); font-weight: bold; text-transform: uppercase;">Date / วันที่</div>
            <div class="fw-bold" style="font-size: 15px;"><?= formatDateThai($data['created_at']); ?></div>
        </div>
    </div>

    <table class="table table-custom">
        <thead>
            <tr class="text-center">
                <th width="55%" class="text-start">รายละเอียดสินค้า/บริการ</th>
                <th width="15%">จำนวน</th>
                <th width="15%" class="text-end">ราคา/หน่วย</th>
                <th width="15%" class="text-end">รวมเงิน</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
                <td class="fw-bold text-dark text-start"><?= htmlspecialchars($item['description']); ?></td>
                <td class="text-center"><?= number_format($item['quantity'], 0); ?> <?= $item['unit']; ?></td>
                <td class="text-end"><?= number_format($item['unit_price'], 2); ?></td>
                <td class="text-end fw-bold"><?= number_format($item['total'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer-sticky">
        <div class="row align-items-end mb-4">
            <div class="col-7 small text-muted italic">
                <div style="font-size: 10px; font-weight: bold; color: #666; margin-bottom: 5px;">หมายเหตุ / Remarks:</div>
                - โปรดระบุเลขที่ใบสั่งซื้อนี้ในใบแจ้งหนี้/ใบกำกับภาษีทุกครั้ง<br>
                - การส่งมอบสินค้าต้องเป็นไปตามเงื่อนไขที่ตกลงกันไว้
            </div>

            <div class="col-5">
                <div class="summary-box">
                    <div class="summary-item">
                        <span>ยอดรายการ (ก่อน VAT)</span>
                        <span><?= number_format($po_subtotal, 2); ?></span>
                    </div>
                    <?php if ($po_vat_enabled && $po_vat_amount > 0): ?>
                    <div class="summary-item text-success">
                        <span>VAT 7%</span>
                        <span><?= number_format($po_vat_amount, 2); ?></span>
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
                <div class="sig-box">ผู้อนุมัติสั่งซื้อ<br><small>(Approver Signature)</small></div>
            </div>
        </div>
    </div>
</div>

</body>
</html>