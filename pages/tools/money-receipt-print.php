<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/money_receipt_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/money_receipt_sheet.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_is_finance_role()) {
    $access_denied_title = 'ใบเสร็จรับเงิน';
    $access_denied_text = 'เข้าใช้งานได้เฉพาะผู้ใช้ที่มีสิทธิ์ CEO / ADMIN / ACCOUNTING';
    require dirname(__DIR__, 2) . '/includes/page_access_denied_swal.php';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . app_path('pages/tools/money-receipt-issue.php'));
    exit;
}

$pk = Db::pkForLogicalId('money_receipts', $id);
$receipt = Db::row('money_receipts', $pk);
if ($receipt === null || (int) ($receipt['id'] ?? 0) !== $id) {
    header('Location: ' . app_path('pages/tools/money-receipt-list.php') . '?error=invalid');
    exit;
}

$companyId = (int) ($receipt['company_id'] ?? 0);
$company = $companyId > 0 ? Db::rowByIdField('company', $companyId) : null;
if ($company === null) {
    $company = ['name' => '—', 'tax_id' => '', 'address' => '', 'phone' => '', 'email' => '', 'logo' => ''];
}

$listUrl = app_path('pages/tools/money-receipt-list.php');
$issueUrl = app_path('pages/tools/money-receipt-issue.php');
$titleNo = trim((string) ($receipt['receipt_no'] ?? ''));
if ($titleNo === '') {
    $titleNo = '#' . (int) ($receipt['id'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>พิมพ์ใบเสร็จรับเงิน <?= htmlspecialchars($titleNo, ENT_QUOTES, 'UTF-8') ?> | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4; margin: 10mm; }
        html, body { margin: 0; padding: 0; min-height: 100%; }
        * { box-sizing: border-box; }
        body, .invoice-box, .invoice-box * {
            font-family: 'Sarabun', 'Leelawadee UI', 'Segoe UI', 'Tahoma', sans-serif;
            text-rendering: optimizeLegibility;
            color: #1f2937;
        }
        body {
            background: #e9ecef;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .mr-toolbar { background: #212529; color: #fff; }
        .mr-toolbar .btn-light { font-weight: 600; }
        .mr-print-wrap { width: 210mm; max-width: 100%; margin: 0 auto; padding: 1rem 0 2rem; }
        .invoice-box.mr-sheet {
            background: #fff;
            padding: 15mm;
            box-shadow: 0 6px 28px rgba(0,0,0,.1);
            min-height: 277mm;
            width: 210mm;
            max-width: 100%;
            border: 1px solid #e5e7eb;
        }
        .mr-page-flex { min-height: calc(277mm - 30mm); display: flex; flex-direction: column; }

        .mr-header {
            display: grid;
            grid-template-columns: 54mm 1fr;
            gap: 12px;
            align-items: start;
            border-bottom: 1px solid #d1d5db;
            padding-bottom: 8px;
        }
        .mr-header-left { min-height: 38mm; }
        .company-logo {
            display: block;
            max-height: 38mm;
            max-width: 54mm;
            width: 100%;
            object-fit: contain;
        }
        .mr-logo-placeholder {
            width: 54mm;
            height: 38mm;
            border: 1px dashed #cbd5e1;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10pt;
            font-weight: 600;
        }
        .mr-header-right { min-width: 0; text-align: right; }
        .mr-title-wrap { margin-bottom: 4px; }
        .invoice-title {
            font-size: 20pt;
            letter-spacing: 0.02em;
            font-weight: 800;
            color: #111827;
        }
        .co-name {
            font-size: 13.5pt;
            font-weight: 700;
            color: #111827;
            margin-bottom: 2px;
        }
        .mr-company-meta {
            font-size: 10.5pt;
            line-height: 1.45;
            color: #4b5563;
            overflow-wrap: anywhere;
        }
        .mr-company-meta .meta-label {
            display: inline-block;
            min-width: 48px;
            color: #6b7280;
            font-weight: 600;
        }
        .mr-doc-meta-box {
            margin-top: 6px;
            margin-left: auto;
            width: 75mm;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            overflow: hidden;
            background: #fafafa;
        }
        .mr-doc-meta-row {
            display: grid;
            grid-template-columns: 28mm 1fr;
            border-top: 1px solid #e5e7eb;
            font-size: 10.8pt;
        }
        .mr-doc-meta-row:first-child { border-top: 0; }
        .mr-doc-meta-row .meta-head {
            padding: 5px 8px;
            font-weight: 600;
            color: #6b7280;
            border-right: 1px solid #e5e7eb;
        }
        .mr-doc-meta-row .meta-value {
            padding: 5px 8px;
            font-weight: 700;
            color: #111827;
            text-align: right;
        }

        .table-custom {
            table-layout: fixed;
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        .table-custom thead th,
        .table-custom td {
            font-size: 11pt;
            font-weight: 500;
            border: 1px solid #ddd !important;
            padding: 8px 10px !important;
            vertical-align: middle;
        }
        .table-custom thead th {
            background: #f5f5f5 !important;
            color: #374151;
            font-weight: 700;
        }
        .table-custom tfoot td { vertical-align: middle; }
        .mr-grand-total-row td {
            font-weight: 800 !important;
            background: #f3f4f6 !important;
            border-top: 2px solid #9ca3af !important;
            border-bottom: 2px solid #9ca3af !important;
        }

        .payment-info-box {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 8px 10px;
            background: #fff;
            font-size: 10.8pt;
        }
        .mr-pay-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 18px;
        }
        .mr-pay-item {
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }
        .mr-pay-check {
            width: 14px;
            height: 14px;
            border: 1px solid #6b7280;
            border-radius: 2px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 700;
            color: #111827;
        }
        .mr-pay-check.is-on {
            border-color: #111827;
            background: #f9fafb;
        }

        .slip-thumb { max-height: 220px; max-width: 100%; }

        .mr-stamp-space {
            min-height: 26mm;
            margin-top: 8px;
            border: 1px dashed #d1d5db;
            border-radius: 6px;
            background: #fcfcfc;
        }
        .sig-row { padding-bottom: 2mm; }
        .sig-box {
            border-top: 1px dotted #6b7280;
            min-height: 18mm;
        }
        .sig-box .small, .payment-info-box, .small, small, .text-muted {
            font-size: 10.8pt;
        }

        @media (max-width: 768px) {
            .mr-header { grid-template-columns: 1fr; }
            .mr-header-right { text-align: left; }
            .mr-doc-meta-box { margin-left: 0; width: 100%; }
            .mr-doc-meta-row .meta-value { text-align: left; }
        }
        @media (max-width: 575.98px) {
            body { background: #fff; }
            .mr-print-wrap { width: 100%; padding: 0.5rem 0.75rem 1.25rem; }
            .invoice-box.mr-sheet {
                width: 100%;
                min-height: auto;
                padding: 1rem;
                box-shadow: none;
            }
        }
        @media print {
            @page { size: A4; margin: 10mm; }
            html, body {
                width: 100% !important;
                min-height: auto !important;
                background: #fff !important;
            }
            .mr-toolbar { display: none !important; }
            .mr-print-wrap {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .invoice-box.mr-sheet {
                width: 100% !important;
                min-height: auto !important;
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
            }
            .mr-stamp-space {
                background: transparent !important;
            }
            .table-custom thead th { background: #f5f5f5 !important; }
            .mr-grand-total-row td { background: #f3f4f6 !important; }
        }
    </style>
</head>
<body>

<div class="mr-toolbar py-2 px-3 mb-0 d-flex flex-wrap gap-2 align-items-center justify-content-between">
    <span class="small"><i class="bi bi-receipt me-1"></i>ใบเสร็จรับเงิน <?= htmlspecialchars($titleNo, ENT_QUOTES, 'UTF-8') ?></span>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>พิมพ์</button>
        <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>">รายการทั้งหมด</a>
        <a class="btn btn-outline-warning btn-sm" href="<?= htmlspecialchars($issueUrl, ENT_QUOTES, 'UTF-8') ?>">ออกใบใหม่</a>
    </div>
</div>

<div class="mr-print-wrap">
    <?php money_receipt_render_sheet($receipt, $company); ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php if (isset($_GET['saved']) && (string) $_GET['saved'] === '1'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Swal !== 'undefined') {
        Swal.fire({ icon: 'success', title: 'บันทึกใบเสร็จแล้ว', toast: true, position: 'top-end', showConfirmButton: false, timer: 2600, timerProgressBar: true });
    }
});
</script>
<?php endif; ?>
</body>
</html>
