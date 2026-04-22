<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$print_modes = [
    ['key' => 'original', 'text' => 'ต้นฉบับ / ORIGINAL'],
    ['key' => 'copy', 'text' => 'สำเนา / COPY'],
];

$inv = Db::row('invoices', (string) $id);
if (!$inv) {
    die('ไม่พบข้อมูล');
}

$cust = Db::row('customers', (string) ($inv['customer_id'] ?? ''));
$com = Db::row('company', (string) ($inv['company_id'] ?? ''));
$creator = Db::row('users', (string) ($inv['created_by'] ?? ''));
$tax = Db::findFirst('tax_invoices', static function (array $r) use ($id): bool {
    return isset($r['invoice_id']) && (int) $r['invoice_id'] === $id;
});

$data = $inv;
$data['customer_name'] = $cust['name'] ?? '';
$data['customer_address'] = $cust['address'] ?? '';
$data['customer_tax'] = $cust['tax_id'] ?? '';
$data['customer_phone'] = $cust['phone'] ?? '';
$data['tax_invoice_number'] = $tax['tax_invoice_number'] ?? '';
foreach (['name', 'logo', 'address', 'phone', 'tax_id', 'bank_name', 'bank_account_name', 'bank_account_number'] as $ck) {
    $data[$ck] = $com[$ck] ?? '';
}

$issuer_display = trim(($creator['fname'] ?? '') . ' ' . ($creator['lname'] ?? ''));
$issuer_display = $issuer_display !== '' ? htmlspecialchars($issuer_display, ENT_QUOTES, 'UTF-8') : 'ไม่ระบุ';

$display_number = (string) ($data['invoice_number'] ?? '');
$items = Db::filter('invoice_items', static function (array $r) use ($id): bool {
    return isset($r['invoice_id']) && (int) $r['invoice_id'] === $id;
});
Db::sortRows($items, 'id', false);
function formatDateThai($date) { return date('d/m/Y', strtotime($date)); }
function formatQty($qty) {
    $n = (float) $qty;
    if (abs($n) <= 0.000001) {
        return '';
    }
    $s = number_format($n, 2, '.', ',');
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' ? '0' : $s;
}

// --- ลำดับแสดง: ยอดรวม → VAT → ยอดรวม VAT → หัก ณ ที่จ่าย → หลังหัก ณ ที่จ่าย → retention → สุทธิ ---
$subtotal = (float) $data['subtotal'];
$vat = (float) $data['vat_amount'];
$wht = (float) $data['withholding_tax'];
$retention = (float) $data['retention_amount'];
$total_after_vat = $subtotal + $vat;
$after_wht = $total_after_vat - $wht;
$final_grand_total = $after_wht - $retention;
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Invoice - <?= htmlspecialchars($display_number); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/document-print.css')) ?>">
    
    <style>
        :root { --orange: #FF6600; --dark: #333; }
        body { font-family: 'Sarabun', 'Leelawadee UI', 'Segoe UI', Tahoma, sans-serif; background: #f4f4f4; color: var(--dark); margin: 0; padding: 0; font-weight: 500; }
        
        .invoice-box { 
            width: 210mm; height: 297mm; margin: 0 auto; background: #fff; padding: 10mm 15mm; 
            position: relative; box-shadow: 0 5px 20px rgba(0,0,0,0.05); border-top: 8px solid var(--orange); overflow: hidden;
        }
        .invoice-sheet { margin-bottom: 12px; }
        .invoice-sheet:last-child { margin-bottom: 0; }

        .doc-label-container { text-align: right; margin-bottom: 5px; }
        .doc-type-text {
            border: 2px solid var(--orange); color: var(--orange);
            padding: 2px 15px; font-weight: 800; font-size: 14px; border-radius: 5px;
            display: inline-block; background-color: #fff9f5;
        }

        .company-logo { max-height: 84px; width: auto; max-width: 220px; object-fit: contain; }
        .invoice-title { font-size: 32px; font-weight: 800; color: var(--orange); line-height: 1; }
        .table-custom { margin-top: 10px; margin-bottom: 0; }
        .table-custom thead th { background: #fafafa; border-bottom: 2px solid var(--orange); font-size: 13px; padding: 8px 10px; }
        .table-custom td { padding: 8px 10px; font-size: 13px; border-bottom: 1px solid #f2f2f2; }
        
        .footer-sticky { position: absolute; bottom: 12mm; left: 15mm; right: 15mm; }
        .payment-info-box { border: 1px solid #eee; border-radius: 8px; padding: 10px; background: #fafafa; font-size: 11.5px; line-height: 1.4; }
        
        .summary-item { display: flex; justify-content: space-between; padding: 2px 0; font-size: 13px; }
        .summary-divider { border-top: 1px dashed #ddd; margin: 4px 0; }
        .grand-total-row { 
            display: flex; justify-content: space-between; align-items: center; 
            background: var(--orange); color: white; padding: 10px 12px; border-radius: 5px; margin-top: 8px; 
        }
        
        .signature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; text-align: center; margin-top: 25px; }
        .sig-space { height: 80px; }
        .sig-box { border-top: 1px solid #333; padding-top: 15px; font-size: 13px; font-weight: 600; }

        @media print {
            @page { size: A4; margin: 0; }
            body { background: none; }
            .no-print { display: none; }
            .invoice-box { margin: 0; box-shadow: none; border-top: 8px solid var(--orange); }
            .invoice-sheet { margin: 0; page-break-after: always; break-after: page; }
            .invoice-sheet:last-child { page-break-after: auto; break-after: auto; }
        }
    </style>
</head>
<body>

<div class="controls-wrapper no-print p-3 text-center bg-dark shadow-sm mb-4">
    <button onclick="window.print()" class="btn btn-warning btn-sm fw-bold" style="padding: 5px 30px;">พิมพ์ ต้นฉบับ + สำเนา</button>
    <a href="<?= htmlspecialchars(app_path('index.php')) ?>" class="btn btn-outline-danger btn-sm ms-2">กลับหน้าหลัก</a>
</div>

<?php foreach ($print_modes as $pm): ?>
<div class="invoice-sheet">
<div class="invoice-box">
    <div class="doc-label-container">
        <div class="doc-type-text"><?= htmlspecialchars((string) ($pm['text'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="row align-items-start mb-2">
        <div class="col-6">
            <div class="invoice-title">INVOICE</div>
            <div class="fw-bold text-muted">ใบแจ้งหนี้</div>
            <div class="fw-bold text-dark" style="margin-top: 5px;">เลขที่: <?= htmlspecialchars($display_number); ?></div>
        </div>
        <div class="col-6 text-end">
            <?php if(!empty($data['logo'])): ?>
                <img src="<?= htmlspecialchars(upload_logo_url($data['logo'])) ?>" class="company-logo" alt="Logo">
            <?php endif; ?>
            <div class="fw-bold" style="font-size: 15px;"><?= $data['name']; ?></div>
            <div class="small text-muted" style="font-size: 10px; line-height: 1.2;">
                <?= $data['address']; ?><br>
                โทร: <?= $data['phone']; ?> | Tax ID: <?= $data['tax_id']; ?>
            </div>
        </div>
    </div>

    <div class="row mb-2 mt-3">
        <div class="col-7">
            <div style="font-size: 10px; color: var(--orange); font-weight: bold; border-bottom: 1px solid #eee; margin-bottom: 3px;">BILLED TO / ลูกค้า</div>
            <div class="fw-bold" style="font-size: 14px;"><?= $data['customer_name']; ?></div>
            <div class="small text-muted" style="font-size: 11px;">
                <?= $data['customer_address']; ?><br>
                <strong>Tax ID:</strong> <?= $data['customer_tax']; ?>
            </div>
        </div>
        <div class="col-5 text-end">
            <div style="font-size: 10px; color: var(--orange); font-weight: bold; border-bottom: 1px solid #eee; margin-bottom: 3px;">DATE / วันที่</div>
            <div class="fw-bold" style="font-size: 14px;"><?= formatDateThai($data['issue_date']); ?></div>
        </div>
    </div>

    <table class="table table-custom">
            <thead>
                <tr>
                    <th width="50%">รายละเอียด</th>
                    <th class="text-center">จำนวน</th>
                    <th class="text-end">ราคา</th>
                    <th class="text-end">รวมเงิน</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td class="fw-bold text-dark"><?= htmlspecialchars($item['description']); ?></td>
                    
                    <td class="text-center"><?= formatQty($item['quantity']); ?> <?= htmlspecialchars($item['unit']); ?></td>
                    
                    <td class="text-end"><?= number_format($item['unit_price'], 2); ?></td>
                    <td class="text-end fw-bold"><?= number_format($item['total'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <div class="footer-sticky">
        <div class="row align-items-end mb-3">
            <div class="col-7">
                <div class="payment-info-box">
                    <div style="font-size: 9px; color: var(--orange); font-weight: bold; margin-bottom: 3px; border-bottom: 1px solid #ddd;">PAYMENT INFO</div>
                    <strong>ธนาคาร:</strong> <?= $data['bank_name']; ?><br>
                    <strong>ชื่อบัญชี:</strong> <?= $data['bank_account_name']; ?><br>
                    <strong>เลขที่บัญชี:</strong> <span style="font-family: monospace; font-weight: bold; font-size: 13px;"><?= $data['bank_account_number']; ?></span>
                </div>
            </div>

            <div class="col-5">
                <div class="summary-box">
                    <div class="summary-item"><span>ยอดรวม (Subtotal)</span><span><?= number_format($subtotal, 2); ?></span></div>
                    <div class="summary-item <?= $vat > 0 ? 'text-primary' : 'text-muted' ?>">
                        <span>ภาษี 7% (VAT) (+)</span>
                        <span><?= number_format($vat, 2); ?></span>
                    </div>
                    <div class="summary-item fw-bold border-bottom pb-1 mb-1" style="font-size: 12px; color: #666;">
                        <span>ยอดรวม VAT</span>
                        <span><?= number_format($total_after_vat, 2); ?></span>
                    </div>

                    <div class="summary-divider"></div>

                    <div class="summary-item <?= $wht > 0 ? 'text-danger' : 'text-muted' ?>">
                        <span>หัก ณ ที่จ่าย 3% (Withholding tax)<small class="text-muted"></small></span>
                        <span><?= number_format($wht, 2); ?></span>
                    </div>
                    <div class="summary-item fw-bold border-bottom pb-1 mb-1" style="font-size: 12px; color: #444;">
                        <span>ยอดรวมหลังหัก ณ ที่จ่าย</span>
                        <span><?= number_format($after_wht, 2); ?></span>
                    </div>

                    <?php if($retention > 0): ?>
                        <div class="summary-item text-danger"><span>หักประกันผลงาน (Retention) </span><span><?= number_format($retention, 2); ?></span></div>
                    <?php endif; ?>

                    <div class="grand-total-row">
                        <span class="fw-bold" style="font-size: 13px;">ยอดสุทธิ</span>
                        <span style="font-size: 18px; font-weight: 800;">฿ <?= number_format($final_grand_total, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="signature-grid">
            <div><div class="sig-space"></div><div class="sig-box">ผู้รับวางบิล / วันที่</div></div>
            <div><div class="sig-space"></div><div class="sig-box">ผู้วางบิล / วันที่</div></div>
        </div>
        <div class="text-center mt-2 small text-muted" style="font-size: 9px;">ขอบคุณที่ใช้บริการ / Thank you for your business</div>
    </div>
</div>
</div>
<?php endforeach; ?>

</body>
</html>