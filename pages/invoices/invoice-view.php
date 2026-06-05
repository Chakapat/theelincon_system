<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/banks.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$embed = isset($_GET['embed']) && (string) $_GET['embed'] === '1';
$autoprint = isset($_GET['autoprint']) && (string) $_GET['autoprint'] === '1';
if ($embed || $autoprint) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
if ($autoprint) {
    $embed = true;
}
$print_modes = [
    ['key' => 'original', 'text' => 'ต้นฉบับ / ORIGINAL'],
    ['key' => 'copy', 'text' => 'สำเนา / COPY'],
];

$inv = Db::rowByIdField('invoices', $id);
if (!$inv) {
    die('ไม่พบข้อมูล');
}

$cust = Db::row('customers', (string) ($inv['customer_id'] ?? ''));
$com = Db::row('company', (string) ($inv['company_id'] ?? ''));
$creator = Db::rowByIdField('users', (int) ($inv['created_by'] ?? 0), 'userid') ?? [];
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

function formatMoneyOrBlank($amount): string
{
    $n = (float) $amount;
    if (abs($n) <= 0.000001) {
        return '';
    }
    return number_format($n, 2);
}

// --- ลำดับแสดง: ยอดรวม → VAT → ยอดรวม VAT → หัก ณ ที่จ่าย → หลังหัก ณ ที่จ่าย → retention → สุทธิ ---
$subtotal = (float) $data['subtotal'];
$vat = (float) $data['vat_amount'];
$wht = (float) $data['withholding_tax'];
$retention = (float) $data['retention_amount'];
$total_after_vat = $subtotal + $vat;
$after_wht = $total_after_vat - $wht;
$final_grand_total = $after_wht - $retention;

/** แสดงที่อยู่เป็นบรรทัดเดียว (ยุบ newline ในข้อมูล) */
$company_address_one_line = preg_replace('/\s+/u', ' ', trim(str_replace(["\r\n", "\r", "\n"], ' ', (string) ($data['address'] ?? ''))));
$customer_address_one_line = preg_replace('/\s+/u', ' ', trim(str_replace(["\r\n", "\r", "\n"], ' ', (string) ($data['customer_address'] ?? ''))));
$customer_tax_trim = trim((string) ($data['customer_tax'] ?? ''));
$company_phone_trim = trim((string) ($data['phone'] ?? ''));
$company_tax_trim = trim((string) ($data['tax_id'] ?? ''));
$company_contact_bits = array_filter([
    $company_phone_trim !== '' ? 'โทร: ' . $company_phone_trim : '',
], static fn (string $s): bool => $s !== '');
$company_detail_line = $company_address_one_line;
if ($company_detail_line !== '' && count($company_contact_bits) > 0) {
    $company_detail_line .= ' | ' . implode(' | ', $company_contact_bits);
} elseif ($company_detail_line === '' && count($company_contact_bits) > 0) {
    $company_detail_line = implode(' | ', $company_contact_bits);
}

/** ชื่อแท็บ / ชื่อไฟล์เริ่มต้นตอนพิมพ์หรือบันทึก PDF (Ctrl+P) */
$invDocTitle = trim((string) ($data['invoice_number'] ?? ''));
if ($invDocTitle === '') {
    $invDocTitle = 'INV-' . $id;
}
$invDocDateSubtitle = $invDocTitle . ' · ' . formatDateThai($data['issue_date']);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($invDocTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php if (!$embed && !$autoprint): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/tnc-app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/doc-view-shell.css'), ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/document-print.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/invoice-sales-print.css'), ENT_QUOTES, 'UTF-8') ?>">
    
    <style>
        :root { --orange: #FF6600; --dark: #333; }
        
        .invoice-box.inv-sales-doc {
            width: 210mm;
            max-width: 100%;
            min-height: 297mm;
            height: auto;
            margin: 0 auto;
            background: #fff;
            padding: 10mm 15mm;
            position: relative;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border-top: none;
            overflow: visible;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            --inv-doc-a4-height: 297mm;
            --inv-doc-pad-block: 10mm;
        }
        .invoice-box.inv-sales-doc > .inv-doc-main {
            flex: 1 1 auto;
        }
        .inv-doc-main {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            min-height: calc(var(--inv-doc-a4-height) - (var(--inv-doc-pad-block) * 2));
            width: 100%;
            box-sizing: border-box;
        }
        .invoice-box.inv-sales-doc .inv-doc-main {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            min-height: calc(var(--inv-doc-a4-height) - (var(--inv-doc-pad-block) * 2));
        }
        .inv-doc-content {
            flex: 1 1 auto;
            min-height: 0;
        }
        .invoice-box.inv-sales-doc .inv-doc-content {
            flex: 1 1 auto;
            min-height: 0;
        }
        .invoice-sheet { margin-bottom: 12px; }
        .invoice-sheet:last-child { margin-bottom: 0; }

        .doc-label-container { text-align: right; margin-bottom: 5px; }
        .doc-type-text {
            border: 2px solid var(--orange); color: var(--orange);
            padding: 2px 15px; font-weight: 800; font-size: 14px; border-radius: 5px;
            display: inline-block; background-color: #fff9f5;
        }
        .doc-site-block {
            border-left: 3px solid var(--orange);
            background: #fff9f5;
            padding: 0.35rem 0.65rem;
            margin-bottom: 0.35rem;
            font-size: 13px;
        }
        .doc-site-label { font-weight: 700; margin-right: 0.35rem; }

        .company-logo { max-height: 84px; width: auto; max-width: 220px; object-fit: contain; }
        .tax-id-keep { white-space: nowrap; }
        .invoice-title { font-size: 32px; font-weight: 800; color: var(--orange); line-height: 1; }
        .table-custom { margin-top: 10px; margin-bottom: 0; }
        .table-custom thead th { background: #fafafa; border-bottom: 2px solid var(--orange); font-size: 13px; padding: 8px 10px; }
        .table-custom td { padding: 8px 10px; font-size: 13px; border-bottom: 1px solid #f2f2f2; }
        
        .footer-sticky {
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
            margin-top: auto;
            padding-top: 8mm;
            position: relative;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .invoice-box.inv-sales-doc .footer-sticky {
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
            margin-top: auto !important;
            padding-top: 8mm;
        }
        .invoice-box.inv-sales-doc .footer-sticky.doc-footer {
            margin-top: auto !important;
            padding-top: 8mm;
            border-top: none;
        }
        .inv-doc-footer-totals {
            flex: 0 0 auto;
            margin-bottom: 0 !important;
        }
        .inv-doc-sign-block {
            flex: 0 0 auto;
            margin-top: 22mm;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .payment-info-box { border: 1px solid #eee; border-radius: 8px; padding: 10px; background: #fafafa; font-size: 11.5px; line-height: 1.4; }
        .inv-bank-display { display: inline-flex; align-items: center; gap: 4px; vertical-align: middle; }
        .inv-bank-logo { width: 24px; height: 24px; object-fit: contain; vertical-align: middle; border-radius: 3px; }
        
        .summary-item { display: flex; justify-content: space-between; padding: 2px 0; font-size: 13px; }
        .summary-divider { border-top: 1px dashed #ddd; margin: 4px 0; }
        .grand-total-row { 
            display: flex; justify-content: space-between; align-items: center; 
            background: var(--orange); color: #111; padding: 10px 12px; border-radius: 5px; margin-top: 8px; 
        }
        
        .signature-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            text-align: center;
            margin-top: 0;
            padding-top: 0;
        }
        .sig-space { height: 80px; }
        .sig-box { border-top: 1px solid #333; padding-top: 15px; font-size: 13px; font-weight: 600; }

        <?php if ($autoprint): ?>
        body.invoice-autoprint {
            margin: 0;
            padding: 0;
            background: #fff;
        }
        <?php elseif ($embed): ?>
        body.invoice-embed { overflow-x: hidden; max-width: 100%; background: #f6f7f9; }
        <?php endif; ?>

        @media print {
            .invoice-box.inv-sales-doc,
            #tnc-invoice-print-root .invoice-box.inv-sales-doc {
                border: none !important;
                border-top: none !important;
                border-top-width: 0 !important;
                min-height: 297mm !important;
                display: flex !important;
                flex-direction: column !important;
            }
            .invoice-box.inv-sales-doc > .inv-doc-main,
            #tnc-invoice-print-root .invoice-box.inv-sales-doc > .inv-doc-main {
                flex: 1 1 auto !important;
                min-height: calc(297mm - 20mm) !important;
                display: flex !important;
                flex-direction: column !important;
            }
            .invoice-box.inv-sales-doc .inv-doc-content,
            #tnc-invoice-print-root .invoice-box.inv-sales-doc .inv-doc-content {
                flex: 1 1 auto !important;
                min-height: 0 !important;
            }
            .invoice-box.inv-sales-doc .footer-sticky,
            .invoice-box.inv-sales-doc .footer-sticky.doc-footer {
                flex: 0 0 auto !important;
                display: flex !important;
                flex-direction: column !important;
                margin-top: auto !important;
                padding-top: 8mm !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .invoice-box.inv-sales-doc .inv-doc-sign-block {
                margin-top: 22mm !important;
            }
            .inv-summary-vat-label::after,
            .inv-summary-wht-label::after {
                content: none !important;
            }
        }

        @media (max-width: 575.98px) {
            body { background: #fff; }
            .invoice-box.inv-sales-doc {
                width: 100%;
                height: auto;
                min-height: 0;
                padding: 1rem;
                box-shadow: none;
                overflow: visible;
                display: block;
            }
            .inv-doc-main { min-height: 0; display: block; }
            .inv-doc-content { flex: none; }
            .footer-sticky { margin-top: 1.25rem; padding-top: 0; display: block; }
            .inv-doc-sign-block { margin-top: 2rem; }
            .signature-grid { grid-template-columns: 1fr; gap: 18px; }
        }
    </style>
</head>
<body class="invoice-print-page<?= $autoprint ? ' invoice-autoprint' : ($embed ? ' invoice-embed' : ' tnc-app-body') ?>">

<?php if (!$embed && !$autoprint): ?>
<div class="tnc-inv-chrome no-print">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
</div>
<header class="tnc-inv-chrome doc-view-shell no-print">
    <div class="doc-view-shell-inner">
        <div class="doc-view-toolbar-row">
            <div class="doc-view-toolbar-main">
                <span class="doc-view-toolbar-id"><?= htmlspecialchars($invDocTitle, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="doc-view-toolbar-sep" aria-hidden="true">—</span>
                <span class="doc-view-toolbar-meta">ต้นฉบับ + สำเนา (2 แผ่น)</span>
            </div>
            <div class="doc-view-toolbar-actions">
                <a href="<?= htmlspecialchars(app_path('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="bi bi-arrow-left me-1"></i>หน้าหลัก
                </a>
                <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="bi bi-printer me-1"></i>พิมพ์
                </button>
            </div>
        </div>
    </div>
</header>
<?php endif; ?>

<div id="tnc-invoice-print-root" class="doc-view-canvas">
<?php foreach ($print_modes as $pm): ?>
<div class="invoice-sheet">
<div class="invoice-box inv-sales-doc">
    <div class="inv-doc-main">
    <div class="inv-doc-content">
    <div class="doc-label-container">
        <div class="doc-type-text"><?= htmlspecialchars((string) ($pm['text'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="row align-items-start mb-2">
        <div class="col-6 inv-company-col">
            <?php if(!empty($data['logo'])): ?>
                <img src="<?= htmlspecialchars(upload_logo_url($data['logo'])) ?>" class="company-logo" alt="Logo">
            <?php endif; ?>
            <div class="inv-company-name"><?= h((string) ($data['name'] ?? '')); ?></div>
            <div class="inv-company-detail text-muted">
                <?php if ($company_detail_line !== ''): ?>
                    <?= htmlspecialchars($company_detail_line, ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
                <?php if ($company_tax_trim !== ''): ?>
                    <?= ($company_detail_line !== '' ? ' | ' : '') ?><span class="tax-id-keep">เลขประจำตัวผู้เสียภาษี: <?= htmlspecialchars($company_tax_trim, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-6 text-end">
            <div class="invoice-title">INVOICE</div>
            <div class="fw-bold text-muted small"><?= htmlspecialchars($invDocDateSubtitle, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </div>

    <div class="row mb-2 doc-site-row">
        <div class="col-12">
            <div class="doc-site-block">
                <span class="doc-site-value"><?= h((string) ($data['customer_name'] ?? '')); ?></span>
            </div>
            <?php if ($customer_address_one_line !== '' || $customer_tax_trim !== ''): ?>
            <div class="doc-site-block mt-2">
                <span class="doc-site-value">
                    <?php if ($customer_address_one_line !== ''): ?><?= htmlspecialchars($customer_address_one_line, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                    <?php if ($customer_address_one_line !== '' && $customer_tax_trim !== ''): ?> | <?php endif; ?>
                    <?php if ($customer_tax_trim !== ''): ?><span class="tax-id-keep"><?= htmlspecialchars($customer_tax_trim, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <table class="table table-custom">
            <thead>
                <tr>
                    <th width="42%">รายละเอียด</th>
                    <th class="text-center">จำนวน</th>
                    <th class="text-center">หน่วย</th>
                    <th class="text-end">ราคา/หน่วย</th>
                    <th class="text-end">ยอดรวม</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td class="fw-bold text-dark"><?= htmlspecialchars($item['description']); ?></td>
                    
                    <td class="text-center"><?= formatQty($item['quantity']); ?></td>
                    <td class="text-center"><?= htmlspecialchars($item['unit']); ?></td>
                    
                    <td class="text-end"><?= formatMoneyOrBlank($item['unit_price']); ?></td>
                    <td class="text-end fw-bold"><?= formatMoneyOrBlank($item['total']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    </div>

    <div class="footer-sticky doc-footer">
        <div class="row align-items-end inv-doc-footer-totals">
            <div class="col-7">
                <div class="payment-info-box">
                    <div style="font-size: 9px; color: var(--orange); font-weight: bold; margin-bottom: 3px; border-bottom: 1px solid #ddd;">PAYMENT INFO</div>
                    <?php include dirname(__DIR__, 2) . '/includes/invoice_payment_info_bank.php'; ?>
                </div>
            </div>

            <div class="col-5">
                <div class="summary-box">
                    <div class="summary-item"><span>ยอดรวม (Subtotal)</span><span><?= formatMoneyOrBlank($subtotal); ?></span></div>
                    <div class="summary-item inv-summary-vat <?= $vat > 0 ? 'text-primary' : 'text-muted' ?>">
                        <span class="inv-summary-vat-label">ภาษีมูลค่าเพิ่ม</span>
                        <span><?= formatMoneyOrBlank($vat); ?></span>
                    </div>
                    <div class="summary-item fw-bold border-bottom pb-1 mb-1" style="font-size: 12px; color: #666;">
                        <span>ยอดรวมภาษีมูลค่าเพิ่ม</span>
                        <span><?= formatMoneyOrBlank($total_after_vat); ?></span>
                    </div>

                    <div class="summary-divider"></div>

                    <div class="summary-item inv-summary-wht <?= $wht > 0 ? 'text-danger' : 'text-muted' ?>">
                        <span class="inv-summary-wht-label">หัก ณ ที่จ่าย 3%</span>
                        <span><?= formatMoneyOrBlank($wht); ?></span>
                    </div>
                    <div class="summary-item fw-bold border-bottom pb-1 mb-1" style="font-size: 12px; color: #444;">
                        <span>ยอดรวมหลังหัก ณ ที่จ่าย</span>
                        <span><?= formatMoneyOrBlank($after_wht); ?></span>
                    </div>

                    <?php if($retention > 0): ?>
                        <div class="summary-item text-danger"><span>หักประกันผลงาน (Retention) </span><span><?= formatMoneyOrBlank($retention); ?></span></div>
                    <?php endif; ?>

                    <div class="grand-total-row">
                        <span class="fw-bold" style="font-size: 13px;">ยอดสุทธิ</span>
                        <span style="font-size: 18px; font-weight: 800;">฿ <?= formatMoneyOrBlank($final_grand_total); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="inv-doc-sign-block">
        <div class="signature-grid">
            <div><div class="sig-space"></div><div class="sig-box">ผู้รับวางบิล / วันที่</div></div>
            <div><div class="sig-space"></div><div class="sig-box">ผู้วางบิล / วันที่</div></div>
        </div>
        </div>
    </div>
    </div>
</div>
</div>
<?php endforeach; ?>
</div>

<?php if ($autoprint): ?>
<script>
(function () {
    function runPrint() {
        window.focus();
        window.print();
    }
    function schedulePrint() {
        var delay = 600;
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () {
                setTimeout(runPrint, delay);
            }).catch(function () {
                setTimeout(runPrint, delay + 200);
            });
            return;
        }
        setTimeout(runPrint, delay + 200);
    }
    if (document.readyState === 'complete') {
        schedulePrint();
    } else {
        window.addEventListener('load', schedulePrint, { once: true });
    }
    window.addEventListener('afterprint', function () {
        if (window.opener) {
            window.close();
        }
    });
})();
</script>
<?php endif; ?>

<?php
$tncPrintOnlyCss = app_path('assets/css/print-document-only.css');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($tncPrintOnlyCss, ENT_QUOTES, 'UTF-8') ?>" media="print">
<style media="print">
    @page {
        size: A4 portrait;
        margin: 0;
    }

    body.invoice-print-page > #tnc-invoice-print-root,
    body.invoice-autoprint > #tnc-invoice-print-root,
    body.invoice-embed > #tnc-invoice-print-root {
        display: block !important;
        visibility: visible !important;
        position: static !important;
        width: 100% !important;
        height: auto !important;
        overflow: visible !important;
        opacity: 1 !important;
        clip: auto !important;
        clip-path: none !important;
    }

    body.invoice-print-page .invoice-box.inv-sales-doc,
    body.invoice-autoprint .invoice-box.inv-sales-doc,
    body.invoice-embed .invoice-box.inv-sales-doc {
        border: none !important;
        border-top: none !important;
        border-top-width: 0 !important;
        display: flex !important;
        flex-direction: column !important;
        min-height: 297mm !important;
        box-shadow: none !important;
    }

    body.invoice-print-page .invoice-box.inv-sales-doc > .inv-doc-main,
    body.invoice-autoprint .invoice-box.inv-sales-doc > .inv-doc-main,
    body.invoice-embed .invoice-box.inv-sales-doc > .inv-doc-main {
        flex: 1 1 auto !important;
        display: flex !important;
        flex-direction: column !important;
        min-height: calc(297mm - 20mm) !important;
    }

    body.invoice-print-page .invoice-box.inv-sales-doc .inv-doc-content,
    body.invoice-autoprint .invoice-box.inv-sales-doc .inv-doc-content,
    body.invoice-embed .invoice-box.inv-sales-doc .inv-doc-content {
        flex: 1 1 auto !important;
        min-height: 0 !important;
    }

    body.invoice-print-page .invoice-box.inv-sales-doc .footer-sticky,
    body.invoice-autoprint .invoice-box.inv-sales-doc .footer-sticky,
    body.invoice-embed .invoice-box.inv-sales-doc .footer-sticky,
    body.invoice-print-page .invoice-box.inv-sales-doc .footer-sticky.doc-footer,
    body.invoice-autoprint .invoice-box.inv-sales-doc .footer-sticky.doc-footer,
    body.invoice-embed .invoice-box.inv-sales-doc .footer-sticky.doc-footer {
        flex: 0 0 auto !important;
        display: flex !important;
        flex-direction: column !important;
        margin-top: auto !important;
        padding-top: 8mm !important;
    }

    body.invoice-print-page .invoice-box.inv-sales-doc .inv-doc-sign-block,
    body.invoice-autoprint .invoice-box.inv-sales-doc .inv-doc-sign-block,
    body.invoice-embed .invoice-box.inv-sales-doc .inv-doc-sign-block {
        margin-top: 22mm !important;
    }
</style>
</body>
</html>