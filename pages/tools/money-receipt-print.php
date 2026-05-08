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
        @page { size: A4; margin: 0; }
        html, body { margin: 0; padding: 0; min-height: 100%; }
        * { box-sizing: border-box; }
        body, .invoice-box, .invoice-box * {
            font-family: 'Sarabun', 'Leelawadee UI', 'Segoe UI', 'Tahoma', sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            text-rendering: optimizeLegibility;
            color: #111;
        }
        body {
            background: #e9ecef;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .mr-toolbar { background: #212529; color: #fff; }
        .mr-toolbar .btn-light { font-weight: 600; }
        .mr-print-wrap { width: 210mm; margin: 0 auto; padding: 1rem 0 2rem; }
        .invoice-box.mr-sheet {
            background: #fff;
            padding: 18mm 18mm 22mm;
            box-shadow: 0 6px 28px rgba(0,0,0,.1);
            border-top: 4px solid #fd7e14;
            min-height: 297mm;
            width: 210mm;
        }
        .mr-page-flex { min-height: calc(297mm - 40mm); display: flex; flex-direction: column; }
        .mr-header-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .mr-header-left {
            width: 48mm;
            min-height: 36mm;
            text-align: left;
            overflow: hidden;
            padding: 0;
        }
        .mr-header-right {
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: break-word;
            padding: 0;
        }
        .mr-header-table .company-logo {
            display: block;
            max-height: 36mm;
            max-width: 48mm;
            width: 100%;
            object-fit: contain;
        }
        .mr-header .co-name { font-size: 13.5pt; }
        .invoice-title { font-size: 17pt; letter-spacing: 0.06em; font-weight: 700; }
        .table-custom tfoot td { vertical-align: middle; }
        .table-custom { table-layout: fixed; width: 100%; }
        .table-custom thead th,
        .table-custom td { font-size: 11.5pt; font-weight: 500; }
        .table-custom .fw-bold { font-weight: 700 !important; }
        .payment-info-box, .sig-box, .small, small, .text-muted { font-size: 11pt; font-weight: 500; }
        .company-logo { max-height: 36mm; max-width: 100%; width: auto; object-fit: contain; }
        .sig-row { padding-bottom: 2mm; }
        @media (max-width: 768px) {
            .mr-header-table,
            .mr-header-table tr,
            .mr-header-table td { display: block; width: 100%; }
            .mr-header-left, .mr-header-right { width: 100%; text-align: left !important; }
        }
        @media print {
            html, body { width: 210mm !important; min-height: 297mm !important; background: #fff !important; }
            .mr-toolbar { display: none !important; }
            .mr-print-wrap { width: 210mm !important; margin: 0 !important; padding: 0 !important; }
            .invoice-box.mr-sheet { width: 210mm !important; box-shadow: none; border-top: none; min-height: 297mm; }
            .mr-page-flex { min-height: calc(297mm - 40mm); }
            .mr-header-table .company-logo { max-height: 36mm !important; max-width: 100% !important; }
            .mr-header-table { display: table !important; width: 100% !important; }
            .mr-header-table tr { display: table-row !important; }
            .mr-header-table td { display: table-cell !important; vertical-align: top !important; }
            .mr-header-left { width: 48mm !important; }
            .mr-header-right { text-align: right !important; }
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
