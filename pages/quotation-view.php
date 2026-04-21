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
$print_type = isset($_GET['type']) ? (string) $_GET['type'] : 'original';
$type_text = ($print_type === 'copy') ? 'สำเนา / COPY' : 'ต้นฉบับ / ORIGINAL';

$q = Db::row('quotations', (string) $id);
if (!$q) {
    die('ไม่พบข้อมูลใบเสนอราคา');
}

$cus = Db::row('customers', (string) ($q['customer_id'] ?? ''));
$com = Db::row('company', (string) ($q['company_id'] ?? ''));
$issuer = Db::row('users', (string) ($q['created_by'] ?? ''));

$data = $q;
$data['q_main_id'] = $q['id'] ?? $id;
$data['customer_name'] = $cus['name'] ?? '';
$data['customer_address'] = $cus['address'] ?? '';
$data['customer_tax'] = $cus['tax_id'] ?? '';
$data['customer_phone'] = $cus['phone'] ?? '';
$data['com_name'] = $com['name'] ?? '';
$data['com_address'] = $com['address'] ?? '';
$data['com_phone'] = $com['phone'] ?? '';
$data['com_tax_id'] = $com['tax_id'] ?? '';
$data['logo'] = $com['logo'] ?? '';
$data['bank_name'] = $com['bank_name'] ?? '';
$data['bank_account_name'] = $com['bank_account_name'] ?? '';
$data['bank_account_number'] = $com['bank_account_number'] ?? '';
$data['issuer_fname'] = $issuer['fname'] ?? '';
$data['issuer_lname'] = $issuer['lname'] ?? '';

$issuer_display = trim(($data['issuer_fname'] ?? '') . ' ' . ($data['issuer_lname'] ?? ''));
$issuer_display = $issuer_display !== '' ? htmlspecialchars($issuer_display, ENT_QUOTES, 'UTF-8') : 'ไม่ระบุ';

$target_id = (int) ($data['q_main_id'] ?? $id);
$display_number = (string) ($data['quote_number'] ?? '');
$items = Db::filter('quotation_items', static function (array $r) use ($target_id): bool {
    return isset($r['quotation_id']) && (int) $r['quotation_id'] === $target_id;
});
Db::sortRows($items, 'id', false);

function formatDateThai($date) { 
    return $date ? date('d/m/Y', strtotime($date)) : '-'; 
}

$subtotal = (float)$data['subtotal'];
$vat = (float)$data['vat_amount'];
$final_grand_total = (float)$data['grand_total'];
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Quotation - <?= $display_number; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/document-print.css')) ?>">
    
    <style>
        :root { --orange: #FF6600; --dark: #333; --green-success: #28a745; }
        body { font-family: 'Sarabun', 'Leelawadee UI', 'Segoe UI', Tahoma, sans-serif; background: #f4f4f4; color: var(--dark); margin: 0; padding: 0; font-weight: 500; }
        
        /* ตั้งค่าหน้ากระดาษ A4 */
        .invoice-box { 
            width: 210mm; 
            height: 297mm; /* บังคับความสูงคงที่ */
            margin: 20px auto; 
            background: #fff; 
            padding: 15mm; 
            position: relative; /* สำคัญสำหรับการยึด Footer */
            box-shadow: 0 5px 20px rgba(0,0,0,0.05); 
            border-top: 8px solid var(--orange);
            display: flex;
            flex-direction: column;
        }

        /* ส่วนหัวเอกสาร */
        .doc-label-container { text-align: right; margin-bottom: 5px; }
        .doc-type-text {
            border: 2px solid var(--orange); color: var(--orange);
            padding: 2px 15px; font-weight: 800; font-size: 14px; border-radius: 5px;
            display: inline-block; background-color: #fff9f5;
        }

        .company-logo { max-height: 84px; width: auto; max-width: 220px; object-fit: contain; }
        .invoice-title { font-size: 32px; font-weight: 800; color: var(--orange); line-height: 1; }
        
        /* ส่วนตาราง */
        .table-custom { margin-top: 20px; }
        .table-custom thead th { background: #fafafa; border-bottom: 2px solid var(--orange); font-size: 14px; padding: 10px; }
        .table-custom td { padding: 10px; font-size: 14px; border-bottom: 1px solid #f2f2f2; }
        
        /* --- จุดที่แก้ไข: Footer ยึดล่างกระดาษ --- */
        .footer-sticky-bottom { 
            position: absolute; 
            bottom: 15mm; /* ระยะห่างจากขอบล่าง */
            left: 15mm; 
            right: 15mm; 
        }

        .payment-info-box { border: 1px solid #eee; border-radius: 8px; padding: 12px; background: #fafafa; font-size: 12.5px; line-height: 1.5; }
        .summary-item { display: flex; justify-content: space-between; padding: 3px 0; font-size: 14px; }
        .summary-divider { border-top: 1px dashed #ddd; margin: 6px 0; }
        
        .grand-total-row { 
            display: flex; justify-content: space-between; align-items: center; 
            background: var(--orange); color: white; padding: 12px; border-radius: 5px; margin-top: 10px; 
        }
        
        .signature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 50px; text-align: center; margin-top: 35px; }
        .sig-space { height: 70px; border-bottom: 1px solid #ccc; margin-bottom: 10px; }
        .sig-box { font-size: 14px; font-weight: 600; }

        @media print {
            @page { size: A4; margin: 0; }
            body { background: none; }
            .no-print { display: none; }
            .invoice-box { margin: 0; box-shadow: none; border-top: 8px solid var(--orange); }
        }
    </style>
</head>
<body>

<div class="controls-wrapper no-print p-3 text-center bg-dark mb-4">
    <div class="btn-group me-3">
        <a href="?id=<?= $id ?>&type=original" class="btn btn-sm <?= ($print_type == 'original') ? 'btn-warning' : 'btn-outline-light' ?>">ต้นฉบับ</a>
        <a href="?id=<?= $id ?>&type=copy" class="btn btn-sm <?= ($print_type == 'copy') ? 'btn-warning' : 'btn-outline-light' ?>">สำเนา</a>
    </div>
    <button onclick="window.print()" class="btn btn-warning btn-sm fw-bold">พิมพ์<?= ($print_type == 'copy') ? 'สำเนา' : 'ต้นฉบับ' ?></button>
    <a href="quotation-list.php" class="btn btn-outline-danger btn-sm ms-2">กลับหน้าหลัก</a>
</div>

<div class="invoice-box">
    <div class="doc-label-container">
        <div class="doc-type-text"><?= $type_text ?></div>
    </div>

    <div class="row align-items-start mb-2">
        <div class="col-6">
            <div class="invoice-title">QUOTATION</div>
            <div class="fw-bold text-muted">ใบเสนอราคา</div>
            <div class="fw-bold text-dark mt-2">เลขที่: <?= $display_number; ?></div>
            <div class="small text-muted mt-1">ผู้ออกใบ: <?= $issuer_display; ?></div>
        </div>
        <div class="col-6 text-end">
            <?php if(!empty($data['logo'])): ?>
                <img src="<?= htmlspecialchars(upload_logo_url($data['logo'])) ?>" class="company-logo" alt="Logo">
            <?php endif; ?>
            <div class="fw-bold" style="font-size: 16px;"><?= $data['com_name']; ?></div>
            <div class="small text-muted" style="font-size: 11px; line-height: 1.3;">
                <?= $data['com_address']; ?><br>
                โทร: <?= $data['com_phone']; ?> | Tax ID: <?= $data['com_tax_id']; ?>
            </div>
        </div>
    </div>

    <div class="row mb-3 mt-4">
        <div class="col-7">
            <div style="font-size: 11px; color: var(--orange); font-weight: bold; border-bottom: 1px solid #eee; margin-bottom: 5px;">CUSTOMER / ลูกค้า</div>
            <div class="fw-bold" style="font-size: 15px;"><?= $data['customer_name']; ?></div>
            <div class="small text-muted">
                <?= $data['customer_address']; ?><br>
                <strong>Tax ID:</strong> <?= $data['customer_tax']; ?>
            </div>
        </div>
        <div class="col-5 text-end">
            <div style="font-size: 11px; color: var(--orange); font-weight: bold; border-bottom: 1px solid #eee; margin-bottom: 5px;">DATE / วันที่</div>
            <div class="fw-bold" style="font-size: 15px;"><?= formatDateThai($data['date']); ?></div>
        </div>
    </div>

    <table class="table table-custom">
        <thead>
            <tr>
                <th width="50%">รายละเอียด</th>
                <th class="text-center">จำนวน</th>
                <th class="text-end">ราคา/หน่วย</th>
                <th class="text-end">รวมเงิน</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
                <td class="fw-bold text-dark"><?= htmlspecialchars($item['description']); ?></td>
                <td class="text-center"><?= number_format($item['quantity'], 0); ?> <?= $item['unit']; ?></td>
                <td class="text-end"><?= number_format($item['unit_price'], 2); ?></td>
                <td class="text-end fw-bold"><?= number_format($item['total'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer-sticky-bottom">
        <div class="row align-items-end mb-4">
            <div class="col-7">
                <div class="payment-info-box">
                    <div style="font-size: 10px; color: var(--orange); font-weight: bold; margin-bottom: 5px; border-bottom: 1px solid #ddd;">เงื่อนไขการชำระเงิน</div>
                    <strong>ธนาคาร:</strong> <?= $data['bank_name']; ?><br>
                    <strong>ชื่อบัญชี:</strong> <?= $data['bank_account_name']; ?><br>
                    <strong>เลขที่บัญชี:</strong> <span style="font-family: monospace; font-weight: bold; font-size: 14px;"><?= $data['bank_account_number']; ?></span>
                </div>
            </div>

            <div class="col-5">
                <div class="summary-box">
                    <div class="summary-item"><span>รวมเงิน (Subtotal)</span><span><?= number_format($subtotal, 2); ?></span></div>
                    <?php if($vat > 0): ?>
                        <div class="summary-item" style="color: var(--green-success); font-weight: 600;">
                            <span>ภาษี 7% (VAT)</span><span><?= number_format($vat, 2); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="summary-divider"></div>
                    <div class="grand-total-row">
                        <span class="fw-bold" style="font-size: 14px;">ยอดรวมทั้งสิ้น</span>
                        <span style="font-size: 18px; font-weight: 800;">฿ <?= number_format($final_grand_total, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="signature-grid">
            <div><div class="sig-space"></div><div class="sig-box">ผู้รับข้อเสนอ (Customer)</div></div>
            <div><div class="sig-space"></div><div class="sig-box">ผู้ออกใบเสนอราคา (Authorized)</div></div>
        </div>

        <div class="text-center mt-3 small text-muted" style="font-size: 10px;">
            ใบเสนอราคานี้มีผล 30 วันนับจากวันที่ระบุ / ขอบคุณที่ไว้วางใจให้เราบริการ
        </div>
    </div>
</div>

</body>
</html>