<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/hire_form_rows.php';
require_once dirname(__DIR__, 2) . '/includes/line_pr_approval.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

if (!user_can('po.create')) {
    header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?error=forbidden');
    exit();
}

$pr_id = isset($_GET['pr_id']) ? (int) $_GET['pr_id'] : 0;

$pr = Db::findFirst('purchase_requests', static function (array $r) use ($pr_id): bool {
    return isset($r['id']) && (int) $r['id'] === $pr_id;
});
if (!$pr) {
    echo "<script>alert('ไม่พบข้อมูลใบขอซื้อ'); window.location.href='" . htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES) . "';</script>";
    exit();
}
if (!line_pr_is_approved_for_po($pr)) {
    $st = line_pr_normalize_status($pr);
    $err = $st === 'rejected' ? 'pr_rejected' : 'pr_not_approved';
    header('Location: ' . app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&error=' . $err);
    exit();
}

$requestType = trim((string) ($pr['request_type'] ?? 'purchase'));
if (!in_array($requestType, ['purchase', 'hire'], true)) {
    $requestType = 'purchase';
}
$contractorName = trim((string) ($pr['contractor_name'] ?? ($pr['hire_contractor_name'] ?? '')));
$installmentTotal = (int) ($pr['installment_total'] ?? ($pr['hire_installment_count'] ?? 1));
if ($installmentTotal < 1) {
    $installmentTotal = 1;
}

$dup = Db::findFirst('purchase_orders', static function (array $r) use ($pr_id): bool {
    return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
});
if ($requestType !== 'hire' && $dup !== null) {
    $msg = 'ใบขอซื้อนี้ออกใบสั่งซื้อ (PO) เลขที่ ' . ($dup['po_number'] ?? '') . ' แล้ว ไม่สามารถออกซ้ำได้';
    $view = htmlspecialchars(app_path('pages/purchase/purchase-order-view.php?id=' . (int) ($dup['id'] ?? 0)), ENT_QUOTES);
    echo "<script>alert(" . json_encode($msg, JSON_UNESCAPED_UNICODE) . "); window.location.href='" . $view . "';</script>";
    exit();
}

$issuedInstallments = [];
$paidAmountSoFar = 0.0;
$hireContract = null;
$hirePaymentRows = [];
$hasHireContractPo = false;
$hireContractPoRow = null;
$hirePoMode = 'contract';
$pr_hire_items = [];
if ($requestType === 'hire') {
    // สร้างสัญญาจ้างอัตโนมัติถ้ายังไม่มี (รองรับ PR เก่าที่อนุมัติก่อนระบบนี้)
    if (method_exists(Purchase::class, 'createHireContractIfNeededForPr')) {
        Purchase::createHireContractIfNeededForPr($pr_id);
    }
    $hireContract = Db::findFirst('hire_contracts', static function (array $r) use ($pr_id): bool {
        return (int) ($r['pr_id'] ?? 0) === $pr_id;
    });
    $hcIdForPayments = is_array($hireContract) ? (int) ($hireContract['id'] ?? 0) : 0;
    $hasHireContractPo = Purchase::hasHireContractPo($pr_id, $hcIdForPayments);
    $hireContractPoRow = Purchase::hireContractPoFor($pr_id, $hcIdForPayments);
    $modeReq = strtolower(trim((string) ($_GET['mode'] ?? '')));
    if ($modeReq === 'payment' || $modeReq === 'contract' || $modeReq === 'advance') {
        $hirePoMode = $modeReq;
    } else {
        $hirePoMode = $hasHireContractPo ? 'payment' : 'contract';
    }
    if ($hirePoMode === 'contract' && $hasHireContractPo) {
        header('Location: ' . app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&mode=payment');
        exit();
    }
    if (($hirePoMode === 'payment' || $hirePoMode === 'advance') && !$hasHireContractPo) {
        header('Location: ' . app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&mode=contract');
        exit();
    }
    foreach (Db::tableRows('purchase_orders') as $row) {
        if ((int) ($row['pr_id'] ?? 0) !== $pr_id) {
            continue;
        }
        if (trim((string) ($row['order_type'] ?? 'purchase')) !== 'hire') {
            continue;
        }
        // ข้าม PO ที่ถูกยกเลิก เพื่อให้ออกงวดเดิมซ้ำได้และนับยอดถูกต้อง
        if (strtolower(trim((string) ($row['status'] ?? ''))) === 'cancelled') {
            continue;
        }
        if (Purchase::isHireContractPo($row)) {
            continue;
        }
        if (Purchase::isHireAdvancePo($row)) {
            continue;
        }
        $paidAmountSoFar += (float) (($row['subtotal_amount'] ?? '') !== '' ? $row['subtotal_amount'] : ($row['payable_amount'] ?? 0));
        $no = (int) ($row['installment_no'] ?? 0);
        if ($no > 0) {
            $issuedInstallments[$no] = true;
        }
    }
    $hirePaymentRows = Purchase::filterActiveHireContractPaymentPos(
        Db::filter('hire_contract_payments', static function (array $r) use ($pr_id, $hcIdForPayments): bool {
            if ((int) ($r['pr_id'] ?? 0) !== $pr_id) {
                return false;
            }
            if ($hcIdForPayments > 0 && (int) ($r['hire_contract_id'] ?? 0) !== $hcIdForPayments) {
                return false;
            }

            return true;
        }),
        $hcIdForPayments > 0 ? $hcIdForPayments : null,
        $pr_id
    );
    Db::sortRows($hirePaymentRows, 'installment_no', false);
    $pr_hire_items = Db::filter('purchase_request_items', static function (array $r) use ($pr_id): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
    });
    Db::sortRows($pr_hire_items, 'id', false);
}
$poNoteDefaultHire = '';
if ($requestType === 'hire' && in_array($hirePoMode, ['payment', 'advance'], true) && is_array($hireContract)) {
    require_once dirname(__DIR__, 2) . '/includes/contractors.php';
    $hcContractorIdNote = (int) ($hireContract['contractor_id'] ?? 0);
    if ($hcContractorIdNote > 0) {
        $poNoteDefaultHire = tnc_contractor_payment_note_text($hcContractorIdNote);
    }
}
$hireContractAmount = 0.0;
$hireContractRemaining = 0.0;
$hireCommittedPayable = 0.0;
$hireCommittedAdvance = 0.0;
$hireOpenPayments = false;
if ($requestType === 'hire' && is_array($hireContract)) {
    $hcIdCalc = (int) ($hireContract['id'] ?? 0);
    $hireContractAmount = round((float) ($hireContract['contract_amount'] ?? 0), 2);
    $hireCommittedPayable = Purchase::hireContractCommittedPayable($hcIdCalc);
    $hireCommittedAdvance = Purchase::hireContractCommittedAdvance($hcIdCalc);
    $hireContractRemaining = Purchase::hireContractRemainingPayable($hireContract, $hcIdCalc);
    $hcInstallmentTotalOpen = (int) ($hireContract['installment_total'] ?? $installmentTotal);
    if ($hcInstallmentTotalOpen < 0) {
        $hcInstallmentTotalOpen = 0;
    }
    $hireOpenPayments = Purchase::hireInstallmentsUnspecified($hcInstallmentTotalOpen);
}
$hirePoSiteDisplay = '—';
$hirePoSiteId = 0;
$hirePoCostCategoryId = 0;
$hirePoCostCategoryName = '';
if ($requestType === 'hire' && in_array($hirePoMode, ['payment', 'advance'], true)) {
    $catSource = is_array($hireContractPoRow) ? $hireContractPoRow : $pr;
    $hirePoSiteId = (int) ($catSource['site_id'] ?? 0);
    if ($hirePoSiteId <= 0) {
        $hirePoSiteId = (int) ($pr['site_id'] ?? 0);
    }
    $hirePoSiteName = trim((string) ($catSource['site_name'] ?? ''));
    if ($hirePoSiteName === '') {
        $hirePoSiteName = trim((string) ($pr['site_name'] ?? ''));
    }
    if ($hirePoSiteName === '' && $hirePoSiteId > 0) {
        $siteRowHirePo = Db::row('sites', (string) $hirePoSiteId);
        if (is_array($siteRowHirePo)) {
            $hirePoSiteName = trim((string) ($siteRowHirePo['name'] ?? ''));
        }
    }
    $hirePoSiteDisplay = $hirePoSiteName !== '' ? $hirePoSiteName : ($hirePoSiteId > 0 ? 'ไซต์ #' . $hirePoSiteId : '—');
    $hirePoCostCategoryId = (int) ($catSource['cost_category_id'] ?? 0);
    if ($hirePoCostCategoryId <= 0) {
        $hirePoCostCategoryId = (int) ($pr['cost_category_id'] ?? 0);
    }
    $hirePoCostCategoryName = trim((string) ($catSource['cost_category_name'] ?? ''));
    if ($hirePoCostCategoryName === '') {
        $hirePoCostCategoryName = trim((string) ($pr['cost_category_name'] ?? ''));
    }
    require_once dirname(__DIR__, 2) . '/includes/site_category_document_name.php';
    $hirePoCostCategoryName = tnc_site_category_document_name($hirePoCostCategoryId, $hirePoCostCategoryName);
}
$remainingInstallments = $requestType === 'hire' ? max(0, $installmentTotal - count($issuedInstallments)) : 0;
$poPaymentFlatItems = [[
    'description' => '',
    'quantity' => 1,
    'unit' => '',
    'unit_price' => 0,
]];

$supplier_rows = Db::tableRows('suppliers');
Db::sortRows($supplier_rows, 'name', false);

$po_number = ($requestType === 'hire' && $hirePoMode === 'contract')
    ? Purchase::generateWorkOrderNumber()
    : Purchase::generatePONumber();
$errorCode = trim((string) ($_GET['error'] ?? ''));
$prUpdated = !empty($_GET['pr_updated']);

$pr_items_for_edit = [];
$pr_has_unknown_line_price = false;
$pr_needs_price_fix = false;
if ($requestType === 'purchase') {
    $pr_items_for_edit = Db::filter('purchase_request_items', static function (array $r) use ($pr_id): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
    });
    Db::sortRows($pr_items_for_edit, 'id', false);
    $pr_grand_check = (float) ($pr['total_amount'] ?? 0);
    if (abs($pr_grand_check) < 0.0005) {
        $pr_needs_price_fix = true;
    }
    foreach ($pr_items_for_edit as $pi) {
        if ((float) ($pi['quantity'] ?? 0) > 0 && (float) ($pi['unit_price'] ?? 0) <= 0) {
            $pr_has_unknown_line_price = true;
            $pr_needs_price_fix = true;
            break;
        }
    }
}

$tnc_po_submit_disabled = ($requestType === 'hire' && $hirePoMode === 'payment' && $remainingInstallments === 0)
    || ($requestType === 'hire' && $hirePoMode === 'payment' && $hireContractRemaining <= 0.0005)
    || ($requestType === 'purchase' && $pr_has_unknown_line_price);
$tnc_po_submit_label = $requestType === 'hire'
    ? ($hirePoMode === 'contract'
        ? 'ยืนยันออก Work Order'
        : ($hirePoMode === 'advance'
            ? 'ยืนยันออก PO เบิกล่วงหน้า'
            : ($remainingInstallments === 0 ? 'ออกครบทุกงวดแล้ว' : 'ยืนยันสร้างใบสั่งจ่ายงวดนี้')))
    : ($pr_has_unknown_line_price ? 'ไม่สามารถออกใบสั่งซื้อได้' : 'สร้างใบสั่งซื้อ');
$hireRemainingOver = $requestType === 'hire' && $hireContractRemaining < -0.0005;
$hireRemainingCss = $hireRemainingOver
    ? 'text-danger fw-bold'
    : ($hireContractRemaining <= 0.0005 ? 'text-success fw-bold' : 'text-tnc-orange fw-bold');

$pr_details_hidden = trim((string) ($pr['details'] ?? ''));
$pr_site_id_hidden = (int) ($pr['site_id'] ?? 0);
$pr_requested_by_hidden = (int) ($pr['requested_by'] ?? 0);
$pr_created_ymd = '';
$rawPrCreated = trim((string) ($pr['created_at'] ?? ''));
if ($rawPrCreated !== '') {
    $tsPrCreated = strtotime($rawPrCreated);
    if ($tsPrCreated !== false) {
        $pr_created_ymd = date('Y-m-d', $tsPrCreated);
    }
}
if ($pr_created_ymd === '') {
    $pr_created_ymd = date('Y-m-d');
}
$pr_fix_vat_on = (int) ($pr['vat_enabled'] ?? 0) === 1;
$pr_fix_vat_mode = trim((string) ($pr['vat_mode'] ?? 'exclusive'));
if (!in_array($pr_fix_vat_mode, ['exclusive', 'inclusive'], true)) {
    $pr_fix_vat_mode = 'exclusive';
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $requestType === 'hire' ? ($hirePoMode === 'contract' ? 'ออก Work Order (WO)' : 'ใบสั่งจ่าย PO') : 'สร้างใบสั่งซื้อจาก PR' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/purchase-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/hire-line-table.css'), ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($requestType === 'hire'): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/pr-hire-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/po-hire-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <?php if (in_array($hirePoMode, ['payment', 'advance'], true)): ?>
    <?php
    $poLineMobileCss = dirname(__DIR__, 2) . '/assets/css/po-line-table-mobile.css';
    $poLineMobileVer = @filemtime($poLineMobileCss);
    if (!is_int($poLineMobileVer) || $poLineMobileVer <= 0) {
        $poLineMobileVer = time();
    }
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/po-line-table-mobile.css') . '?v=' . $poLineMobileVer, ENT_QUOTES, 'UTF-8') ?>">
    <style>
        .po-from-pr-hire-po-table .po-table-wrap { border: 1px solid #e8ecf1; border-radius: 0.75rem; overflow: hidden; background: #fff; }
        .po-from-pr-hire-po-table .po-table-wrap thead th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; font-weight: 700; background: #f1f5f9 !important; }
        .po-from-pr-hire-po-table .summary-box { background: linear-gradient(180deg, #fffbf5 0%, var(--tnc-orange-soft) 100%); border: 1px solid var(--tnc-orange-border); border-radius: 0.85rem; padding: 1.1rem 1.15rem; }
        .po-from-pr-hire-po-table .summary-line { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; margin-bottom: 10px; }
        .po-from-pr-hire-po-table .summary-grand { padding-top: 0.35rem; margin-top: 0.25rem; border-top: 2px dashed rgba(253, 126, 20, 0.25); }
        .po-from-pr-hire-po-table .po-vat-panel { background: #fffbf5; border: 1px solid var(--tnc-orange-border); border-radius: 0.75rem; }
        .po-from-pr-hire-po-table .po-actions-bar { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eef2f7; }
        .po-from-pr-shell.po-from-pr-hire-pay { max-width: 1100px; }
    </style>
    <?php endif; ?>
    <?php endif; ?>
    <style>
        .po-from-pr-shell { max-width: 720px; }
        .po-field-label { font-size: 0.8rem; font-weight: 600; color: var(--tnc-muted); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.35rem; }
        .po-panel {
            border: 1px solid var(--tnc-border); border-radius: 0.875rem; background: #f8fafc;
            padding: 1rem 1.15rem;
        }
        .po-panel-muted { background: #fff; border-color: #e9ecef; }
        .section-card { border: 1px solid #e9ecef; border-radius: 12px; background: #fff; }
        .section-title { font-size: 1rem; font-weight: 700; color: var(--tnc-orange); margin-bottom: 12px; }
        .form-control:focus, .form-select:focus { border-color: var(--tnc-orange-border); box-shadow: 0 0 0 0.2rem rgba(253, 126, 20, 0.12); }
    </style>
</head>
<body<?= $requestType === 'hire' ? ' class="po-hire-mode purchase-module tnc-app-body tnc-layout-form"' : ' class="purchase-module tnc-app-body tnc-layout-form"' ?>>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
    <div class="<?= $requestType === 'hire' ? 'container-fluid px-3 px-lg-4' : 'container' ?> py-4 py-md-5">
        <div class="row justify-content-center">
            <div class="<?= $requestType === 'hire' ? 'col-12 po-hire-layout-inner' : 'col-xl-8' ?>">
                <div class="po-from-pr-shell mx-auto<?= ($requestType === 'hire' && in_array($hirePoMode, ['payment', 'advance'], true)) ? ' po-from-pr-hire-pay' : '' ?>">
                <div class="card po-from-pr-card">
                    <div class="po-from-pr-head">
                        <div class="<?= ($requestType === 'purchase' && $pr_needs_price_fix && count($pr_items_for_edit) > 0) ? 'd-flex flex-wrap justify-content-between align-items-start gap-2 gap-md-3' : '' ?>">
                            <div class="min-w-0 flex-grow-1">
                                <h1 class="d-flex align-items-center gap-2 mb-0">
                                    <i class="bi bi-file-earmark-plus-fill opacity-90"></i>
                                    <?= $requestType === 'hire'
                                        ? ($hirePoMode === 'contract' ? 'ออก Work Order (WO)' : ($hirePoMode === 'advance' ? 'ออก PO เบิกล่วงหน้า' : 'สร้างใบสั่งจ่าย'))
                                        : 'สร้างใบสั่งซื้อ' ?>
                                </h1>
                                <div class="sub"><?= $requestType === 'hire'
                                    ? ($hirePoMode === 'contract'
                                        ? 'PR = ขอสร้างสัญญา → PO นี้เป็นเอกสารสัญญาจ้าง (ยังไม่ใช่การสั่งจ่ายเงิน)'
                                        : 'ออก PO สั่งจ่าย (หลังออก WO สัญญาแล้ว)')
                                    : 'ออกใบสั่งซื้อ (PO) -> จากใบขอซื้อ (PR)' ?></div>
                            </div>
                            <?php if ($requestType === 'purchase' && $pr_needs_price_fix && count($pr_items_for_edit) > 0): ?>
                            <button type="button" class="btn btn-warning text-dark fw-semibold rounded-pill px-3 py-2 flex-shrink-0 align-self-start" data-bs-toggle="modal" data-bs-target="#prFixFromPoModal" id="prFixOpenBtn" title="แก้รายการสินค้าและ VAT ในใบขอซื้อ">
                                <i class="bi bi-pencil-square me-1"></i>แก้ใบขอซื้อ
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="p-4 p-md-4">
                    <?php if ($requestType === 'hire'): ?>
                        <div class="alert alert-info py-2 mb-3">
                            <i class="bi bi-diagram-3-fill me-1"></i>
                            <strong>ขั้นตอน:</strong> PR (ขอสร้างสัญญา) →
                            <strong>Work Order (WO)</strong> (ส่งให้ผู้รับจ้าง) →
                            <strong>PO สั่งจ่าย</strong> (งวด/ครั้ง)
                            <?php if ($hirePoMode === 'contract'): ?>
                                — ตอนนี้อยู่ขั้น <strong>ออก WO</strong>
                            <?php else: ?>
                                — ตอนนี้อยู่ขั้น <strong>ออก PO สั่งจ่าย</strong>
                                <?php if (is_array($hireContractPoRow)): ?>
                                    · WO:
                                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-view.php') . '?id=' . (int) ($hireContractPoRow['id'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" class="alert-link"><?= htmlspecialchars((string) ($hireContractPoRow['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($requestType === 'hire' && $hasHireContractPo && $hirePoMode !== 'contract'): ?>
                        <ul class="nav nav-pills gap-2 mb-3">
                            <li class="nav-item">
                                <a class="nav-link<?= $hirePoMode === 'payment' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&mode=payment', ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-cash-coin me-1"></i>สั่งจ่ายตามงวด/ครั้ง</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link<?= $hirePoMode === 'advance' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&mode=advance', ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-wallet2 me-1"></i>เบิกล่วงหน้า</a>
                            </li>
                        </ul>
                    <?php endif; ?>
                    <?php if ($errorCode === 'contract_po_required'): ?>
                        <div class="alert alert-warning py-2">กรุณาออก <strong>Work Order (WO)</strong> ก่อน จึงจะออก PO สั่งจ่ายได้</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'contract_po_exists'): ?>
                        <div class="alert alert-warning py-2">ออก WO แล้ว — ใช้โหมด <strong>สั่งจ่าย PO</strong> แทน</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'invalid_installment'): ?>
                        <div class="alert alert-warning py-2">งวดที่เลือกไม่ถูกต้อง</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'duplicate_installment'): ?>
                        <div class="alert alert-warning py-2">งวดนี้ถูกออกเอกสารแล้ว กรุณาเลือกงวดอื่น</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'invalid_installment_amount'): ?>
                        <div class="alert alert-warning py-2">มูลค่างวดต้องมากกว่า 0</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'invalid_installment_description'): ?>
                        <div class="alert alert-warning py-2">กรุณากรอกรายละเอียดการสั่งจ่ายงวดนี้</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'invalid_hire_rows'): ?>
                        <div class="alert alert-warning py-2">กรุณากรอกรายการสั่งจ่ายอย่างน้อย 1 รายการให้ถูกต้อง</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'contract'): ?>
                        <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle-fill me-1"></i>ยังไม่มีสัญญาจ้างผูกกับใบขอจัดจ้างนี้ — ระบบจะพยายามสร้างให้อัตโนมัติ หากยังพบปัญหา กรุณาเปิดหน้า «สัญญาจ้าง» เพื่อสร้าง/ตรวจสอบสัญญาก่อนออกใบสั่งจ่าย</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'contract_fully_paid'): ?>
                        <div class="alert alert-danger py-2"><i class="bi bi-x-circle-fill me-1"></i>มูลค่าสัญญาจ้างออก PO ครบแล้ว (คงเหลือ 0 บาท) — ไม่สามารถออกใบสั่งจ่ายเพิ่มได้ หากออก PO ผิดโดยไม่ได้จ่ายเงินจริง ให้ยกเลิก PO นั้นก่อน</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'contract_exceeds_remaining' || $errorCode === 'contract_exceeds_confirm'): ?>
                        <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle-fill me-1"></i>กรุณายืนยันการออกใบสั่งจ่ายเมื่อยอดเกินมูลค่าสัญญา</div>
                    <?php endif; ?>
                    <?php if ($requestType === 'purchase' && $prUpdated): ?>
                        <div class="alert alert-success py-2 border-0" data-tnc-audio="update"><i class="bi bi-check-circle-fill me-1"></i>อัปเดตใบขอซื้อ (PR) แล้ว — ตรวจสอบยอดด้านล่างแล้วดำเนินการสร้าง PO ต่อได้</div>
                    <?php endif; ?>
                    <?php if ($requestType === 'hire' && $remainingInstallments === 0 && $hireContractRemaining > 0.0005): ?>
                        <div class="alert alert-info py-2">ออกใบสั่งจ่ายครบทุกงวดแล้ว แต่ยังมียอดคงเหลือในสัญญา — ตรวจสอบยอดแต่ละงวด</div>
                    <?php endif; ?>
                    <?php if ($requestType === 'hire' && $hireRemainingOver): ?>
                        <div class="alert alert-danger py-2"><i class="bi bi-exclamation-octagon-fill me-1"></i>จ่ายเกินมูลค่าสัญญาแล้ว <strong><?= number_format(abs($hireContractRemaining), 2) ?> บาท</strong> (คงเหลือ <?= number_format($hireContractRemaining, 2) ?> บาท)</div>
                    <?php elseif ($requestType === 'hire' && $hirePoMode === 'payment' && $hireContractRemaining <= 0.0005 && $hireContractRemaining >= -0.0005): ?>
                        <div class="alert alert-success py-2"><i class="bi bi-check-circle-fill me-1"></i>มูลค่าสัญญาจ้างออก PO สั่งจ่ายครบแล้ว (คงเหลือ 0 บาท)</div>
                    <?php endif; ?>
                    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=create_po_from_pr" method="POST" data-tnc-fullnav="1"<?= $requestType === 'hire' ? ' data-hire-remaining="' . htmlspecialchars(number_format($hireContractRemaining, 2, '.', ''), ENT_QUOTES, 'UTF-8') . '"' . ($hirePoMode === 'advance' ? ' data-hire-advance="1"' : '') : '' ?>>
                        <input type="hidden" name="confirm_over_contract" id="confirm_over_contract" value="">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="pr_id" value="<?= $pr['id'] ?>">
                        <?php if ($requestType === 'hire' && $hireContract !== null): ?>
                        <input type="hidden" name="hire_contract_id" value="<?= (int) ($hireContract['id'] ?? 0) ?>">
                        <input type="hidden" name="hire_po_kind" value="<?= htmlspecialchars($hirePoMode, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="po-field-label">อ้างอิงใบขอซื้อ (PR)</div>
                                <input type="text" class="form-control form-control-lg bg-light border-0" value="<?= htmlspecialchars((string) ($pr['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <div class="po-field-label"><?= ($requestType === 'hire' && $hirePoMode === 'contract') ? 'เลขที่ WO (Work Order)' : 'เลขที่ PO (อัตโนมัติ)' ?></div>
                                <input type="text" name="po_number" class="form-control form-control-lg bg-light border-0" value="<?= htmlspecialchars((string) $po_number, ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </div>
                        </div>
                        <?php if ($requestType === 'hire'): ?>
                        <div class="hire-meta-panel mb-4">
                            <div class="hire-meta-panel__head"><i class="bi bi-briefcase-fill"></i> ข้อมูลจัดจ้าง</div>
                            <div class="hire-meta-kv hire-meta-kv--readonly">
                                <span class="hire-meta-chip"><strong>ผู้รับจ้าง:</strong> <?= htmlspecialchars($contractorName !== '' ? $contractorName : '-', ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="hire-meta-chip sep">|</span>
                                <span class="hire-meta-chip"><strong>จำนวนงวดจ่าย:</strong> <?= number_format($installmentTotal) ?> งวด</span>
                                <?php if ($hirePoMode === 'payment'): ?>
                                <span class="hire-meta-chip sep">|</span>
                                <span class="hire-meta-chip"><strong>งวดถัดไป:</strong> <?php for ($i = 1; $i <= $installmentTotal; $i++) { if (!isset($issuedInstallments[$i])) { echo $i . ' / ' . $installmentTotal; break; } } ?></span>
                                <?php else: ?>
                                <span class="hire-meta-chip sep">|</span>
                                <span class="hire-meta-chip"><strong>มูลค่าสัญญา:</strong> <?= number_format($hireContractAmount, 2) ?> บาท</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (in_array($hirePoMode, ['payment', 'advance'], true)): ?>
                        <div class="row g-3 mb-4 pt-1 border-top border-light">
                            <div class="col-md-6">
                                <label class="po-field-label">โครงการ / ไซต์</label>
                                <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($hirePoSiteDisplay, ENT_QUOTES, 'UTF-8') ?>" readonly>
                                <div class="form-text">ดึงจาก Work Order</div>
                            </div>
                            <div class="col-md-6">
                                <label class="po-field-label">หมวดค่าใช้จ่าย</label>
                                <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($hirePoCostCategoryName !== '' ? $hirePoCostCategoryName : '—', ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </div>
                            <input type="hidden" name="site_id" value="<?= (int) $hirePoSiteId ?>">
                            <input type="hidden" name="cost_category_id" value="<?= (int) $hirePoCostCategoryId ?>">
                            <input type="hidden" name="cost_category_name" value="<?= htmlspecialchars($hirePoCostCategoryName, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($requestType === 'hire'): ?>
                        <div class="po-hire-block">
                            <h3 class="po-hire-block__title"><i class="bi bi-journal-text"></i>เงื่อนไข / ขอบเขตงาน (จาก PR)</h3>
                            <div class="po-hire-block__body"><?= htmlspecialchars((string) ($pr['details'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>

                        <?php if ($hirePoMode === 'payment'): ?>
                        <div class="po-hire-block">
                            <h3 class="po-hire-block__title"><i class="bi bi-file-earmark-ruled"></i>สถานะสัญญาจ้าง (PO สั่งจ่าย)</h3>
                            <?php
                                $hcRow = is_array($hireContract) ? $hireContract : [];
                                $paidInstallmentsDisplay = count($issuedInstallments);
                                $paidAmountDisplay = $hireCommittedPayable;
                            ?>
                            <div class="po-hire-stats">
                                <div class="po-hire-stat">
                                    <span class="po-hire-stat__label">มูลค่าสัญญา</span>
                                    <span class="po-hire-stat__value"><?= number_format($hireContractAmount, 2) ?> บาท</span>
                                </div>
                                <div class="po-hire-stat">
                                    <span class="po-hire-stat__label">สั่งจ่ายแล้ว</span>
                                    <span class="po-hire-stat__value"><?= number_format($paidAmountDisplay, 2) ?> บาท</span>
                                </div>
                                <div class="po-hire-stat">
                                    <span class="po-hire-stat__label">คงเหลือ</span>
                                    <span class="po-hire-stat__value <?= $hireRemainingCss ?>" id="hire_remaining_display"><?= number_format($hireContractRemaining, 2) ?> บาท</span>
                                </div>
                                <div class="po-hire-stat">
                                    <span class="po-hire-stat__label"><?= $hireOpenPayments ? 'ครั้งที่สั่งจ่าย' : 'งวดที่สั่งจ่ายแล้ว' ?></span>
                                    <span class="po-hire-stat__value"><?php if ($hireOpenPayments): ?><?= number_format($paidInstallmentsDisplay) ?><?php else: ?><?= number_format($paidInstallmentsDisplay) ?> / <?= number_format($installmentTotal) ?><?php endif; ?></span>
                                </div>
                            </div>
                            <div class="po-hire-ledger-wrap">
                                <table class="table align-middle po-hire-ledger-table mb-0" id="tncHirePaidInstallmentsTable">
                                    <thead>
                                        <tr>
                                            <th>PO No.</th>
                                            <th>งวด</th>
                                            <th class="text-end">มูลค่างวด</th>
                                            <th>วันที่บันทึก</th>
                                            <th>สถานะ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($hirePaymentRows) === 0): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted">ยังไม่มี PO งวด</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($hirePaymentRows as $payment): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars((string) ($payment['po_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td>งวด <?= (int) ($payment['installment_no'] ?? 0) ?>/<?= (int) ($payment['installment_total'] ?? $installmentTotal) ?></td>
                                                    <td class="text-end"><?= number_format((float) ($payment['amount'] ?? 0), 2) ?> บาท</td>
                                                    <td><?= htmlspecialchars((string) ($payment['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><span class="badge bg-success">ออก PO แล้ว</span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>

                        <div class="mb-4<?= $requestType === 'hire' ? ' d-none' : '' ?>">
                            <div class="po-field-label">ผู้ขาย/แหล่งซื้อ</div>
                            <input type="text" id="supplier_search" class="form-control form-control-lg" list="supplier_list" autocomplete="off">
                            <datalist id="supplier_list">
                                <?php foreach ($supplier_rows as $s): ?>
                                    <option
                                        value="<?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-id="<?= (int) ($s['id'] ?? 0) ?>"
                                    ></option>
                                <?php endforeach; ?>
                            </datalist>
                            <input type="hidden" name="supplier_id" id="supplier_id" value="">
                        </div>

                        <div class="mb-4<?= $requestType === 'hire' ? ' d-none' : '' ?>">
                            <label class="po-field-label" for="po_note">หมายเหตุใบสั่งซื้อ</label>
                            <textarea name="po_note" id="po_note" class="form-control" rows="2" maxlength="500"></textarea>
                        </div>

                        <div class="mb-4<?= $requestType === 'hire' ? ' d-none' : '' ?>">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" value="1" id="has_qt" name="has_qt">
                                <label class="form-check-label fw-semibold" for="has_qt">มีข้อมูลใบเสนอราคา</label>
                            </div>
                            <div class="rounded-3 border bg-white p-3 p-md-4 mt-2 d-none" id="qt_panel">
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold text-secondary mb-1" for="qt_quotation_number">เลขที่ใบเสนอราคา</label>
                                    <input type="text" name="quotation_number" id="qt_quotation_number" class="form-control" maxlength="120" disabled>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold text-secondary mb-1" for="qt_quotation_date">วันที่ใบเสนอราคา</label>
                                    <input type="date" name="quotation_date" id="qt_quotation_date" class="form-control" value="" disabled>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label small fw-semibold text-secondary mb-1" for="qt_quotation_note">หมายเหตุ</label>
                                    <textarea name="quotation_note" id="qt_quotation_note" class="form-control" rows="2" maxlength="500" disabled></textarea>
                                </div>
                            </div>
                        </div>

                        <?php if ($requestType === 'hire'): ?>
                        <?php if ($hirePoMode === 'payment'): ?>
                        <input type="hidden" name="installment_no" value="<?php for ($i = 1; $i <= $installmentTotal; $i++) { if (!isset($issuedInstallments[$i])) { echo $i; break; } } ?>">
                        <?php else: ?>
                        <input type="hidden" name="installment_no" value="0">
                        <?php endif; ?>
                        <input type="hidden" name="installment_amount" id="installment_amount" value="0">
                        <input type="hidden" name="installment_description" id="installment_description" value="">

                        <div class="section-card p-3 mb-3<?= in_array($hirePoMode, ['payment', 'advance'], true) ? ' po-from-pr-hire-po-table' : '' ?> hire-lines-section" data-tnc-hire-root>
                            <div class="section-title"><i class="bi bi-table me-1"></i><?php
                                if ($hirePoMode === 'contract') {
                                    echo 'ตารางรายละเอียดสัญญา (จาก PR)';
                                } elseif ($hirePoMode === 'advance') {
                                    echo 'ตารางรายละเอียดเบิกล่วงหน้า';
                                } else {
                                    echo 'ตารางรายละเอียดสั่งจ่าย';
                                }
                            ?></div>
                            <?php if (in_array($hirePoMode, ['payment', 'advance'], true)): ?>
                            <div class="table-responsive po-table-wrap po-line-table-mobile">
                                <table class="table align-middle table-hover mb-0 po-line-table" id="poTable">
                                    <thead>
                                        <tr>
                                            <th style="width:3rem;">#</th>
                                            <th>รายการ</th>
                                            <th style="width:6.5rem;">จำนวน</th>
                                            <th style="width:6.5rem;">หน่วย</th>
                                            <th style="width:7.5rem;">ราคา/หน่วย</th>
                                            <th style="width:7.5rem;">ยอดรวม</th>
                                            <th style="width:2.75rem;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($poPaymentFlatItems as $index => $item): ?>
                                            <tr>
                                                <td class="po-cell-idx row-number text-secondary small fw-semibold">
                                                    <div class="po-mobile-item-head">
                                                        <span class="po-mobile-item-label">รายการที่ <span class="po-mobile-item-no"><?= $index + 1 ?></span></span>
                                                        <?php if ($index > 0): ?>
                                                        <button type="button" class="btn btn-outline-danger btn-sm border-0 po-row-delete-btn po-row-delete-mobile" title="ลบแถว" aria-label="ลบแถว"><i class="bi bi-trash-fill"></i></button>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="d-none d-lg-inline po-mobile-item-no"><?= $index + 1 ?></span>
                                                </td>
                                                <td class="po-cell-desc" data-label="รายการ"><input type="text" name="item_description[]" class="form-control form-control-sm po-line-desc" required value="<?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="ระบุรายการ"></td>
                                                <td class="po-cell-qty" data-label="จำนวน"><input type="number" name="item_qty[]" class="form-control form-control-sm qty" step="0.001" min="0" required value="<?= htmlspecialchars((string) ($item['quantity'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" oninput="calculatePoPaymentTotal()"></td>
                                                <td class="po-cell-unit" data-label="หน่วย"><input type="text" name="item_unit[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="ชิ้น"></td>
                                                <td class="po-cell-price" data-label="ราคา/หน่วย"><input type="number" name="item_price[]" class="form-control form-control-sm price" step="0.001" required value="<?= htmlspecialchars((string) ($item['unit_price'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" oninput="calculatePoPaymentTotal()"></td>
                                                <td class="po-cell-total" data-label="ยอดรวม"><input type="text" class="form-control form-control-sm row-total bg-light text-end fw-semibold" value="0.00" readonly tabindex="-1"></td>
                                                <td class="po-cell-action po-cell-action-desktop"><?php if ($index > 0): ?><button type="button" class="btn btn-outline-danger btn-sm border-0 po-row-delete-btn" title="ลบแถว" aria-label="ลบแถว"><i class="bi bi-trash-fill"></i></button><?php endif; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="po-actions-bar">
                                <button type="button" class="btn btn-orange btn-sm rounded-pill px-3 shadow-sm" onclick="addPoPaymentRow()"><i class="bi bi-plus-lg me-1"></i>เพิ่มรายการ</button>
                            </div>
                            <div class="row g-4 mt-1">
                                <div class="col-lg-7 order-2 order-lg-1">
                                    <div class="po-vat-panel p-3">
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" role="switch" name="vat_enabled" id="vat_enabled" value="1" onchange="updatePoPaymentVatUi(); calculatePoPaymentTotal()">
                                            <label class="form-check-label fw-semibold" for="vat_enabled">มี VAT 7%</label>
                                        </div>
                                        <input type="hidden" name="vat_mode" id="vat_mode" value="exclusive">
                                        <div id="vat_basis_wrap" class="pt-2 border-top border-secondary border-opacity-25">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_inclusive" value="inclusive" onchange="calculatePoPaymentTotal()">
                                                <label class="form-check-label" for="vat_basis_inclusive">รวม VAT <span class="text-muted small">(รวมภาษีมูลค่าเพิ่มในราคารวม)</span></label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_exclusive" value="exclusive" checked onchange="calculatePoPaymentTotal()">
                                                <label class="form-check-label" for="vat_basis_exclusive">แยก VAT <span class="text-muted small">(บวกภาษีมูลค่าเพิ่มแยกจากราคารวม)</span></label>
                                            </div>
                                        </div>
                                        <div class="pt-3 mt-2 border-top border-secondary border-opacity-25">
                                            <label class="form-label text-danger fw-bold mb-1 small" for="retention_value">หักประกันผลงาน (บาท)</label>
                                            <input type="text" name="retention_value" id="retention_value" class="form-control form-control-sm" value="0" placeholder="0" oninput="calculatePoPaymentTotal()">
                                        </div>
                                        <input type="hidden" name="withholding_type" value="none">
                                    </div>
                                </div>
                                <div class="col-lg-5 order-1 order-lg-2">
                                    <div class="summary-box">
                                        <div class="summary-line small text-muted"><span>ยอดรายการ</span><strong><span id="po_pay_subtotal_display">0.00</span> บาท</strong></div>
                                        <div class="summary-line small text-success" id="po_pay_vat_row" style="display:none;"><span>ภาษีมูลค่าเพิ่ม</span><strong><span id="po_pay_vat_display">0.00</span> บาท</strong></div>
                                        <div class="summary-line small text-muted border-bottom pb-2 mb-1"><span>ยอดรวม VAT</span><strong><span id="po_pay_total_after_vat_display">0.00</span> บาท</strong></div>
                                        <div class="summary-line small text-danger" id="po_pay_retention_summary_row" style="display:none;"><span>หักประกันผลงาน</span><strong>- <span id="po_pay_retention_display">0.00</span> บาท</strong></div>
                                        <div class="summary-line summary-grand fw-bold"><span>ยอดสุทธิ</span><strong class="text-tnc-orange"><span id="po_pay_grand_total">0.00</span> บาท</strong></div>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="hire-table-panel">
                                    <div class="table-responsive hire-table-scroll">
                                        <table class="table align-middle mb-0 table-hire-lines" id="hireInstallmentTable">
                                            <thead>
                                                <tr>
                                                    <th class="hire-col-no text-center">#</th>
                                                    <th class="hire-col-desc">รายการ</th>
                                                    <th class="hire-col-qty text-end">จำนวน</th>
                                                    <th class="hire-col-unit text-end">หน่วย</th>
                                                    <th class="hire-col-money text-end">ค่าวัสดุ</th>
                                                    <th class="hire-col-money text-end">ค่าแรง</th>
                                                    <th class="hire-col-money text-end">ราคา/หน่วย</th>
                                                    <th class="hire-col-money text-end">ราคารวม</th>
                                                    <th class="hire-col-action text-center">ลบ</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if ($hirePoMode === 'contract' && count($pr_hire_items) > 0): ?>
                                                    <?php tnc_hire_form_rows_from_items('hire', $pr_hire_items, 'po'); ?>
                                                <?php else: ?>
                                                    <?php tnc_hire_form_default_rows('hire', 'po'); ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    </div>
                                    <div class="hire-lines-toolbar">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="addHireGroupBtn" data-tnc-hire-add="group"><i class="bi bi-folder-plus me-1"></i>เพิ่มหัวข้อหลัก</button>
                                        <button type="button" class="btn btn-sm btn-outline-orange" id="addHireRowBtn" data-tnc-hire-add="item"><i class="bi bi-plus-circle me-1"></i>เพิ่มรายการย่อย</button>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($hirePoMode === 'contract'): ?>
                        <div class="section-card p-3 mb-4">
                            <div class="section-title"><i class="bi bi-calculator me-1"></i>สรุปยอด</div>
                            <div class="po-hire-summary-grid">
                                <div class="po-hire-summary-settings">
                                    <h6 class="fw-bold mb-3 small text-uppercase text-secondary" style="letter-spacing:0.05em;">ภาษีและเงินหัก</h6>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="vat_enabled" id="vat_enabled"<?= ($hirePoMode === 'contract' && (int) ($pr['vat_enabled'] ?? 0) === 1) ? ' checked' : '' ?>>
                                        <label class="form-check-label fw-semibold" for="vat_enabled">บวก VAT 7% (+)</label>
                                    </div>
                                    <input type="hidden" name="retention_value" id="retention_value" value="0">
                                    <input type="hidden" name="withholding_type" id="withholding_type" value="none">
                                    <input type="hidden" name="retention_type" id="retention_type" value="fixed">
                                </div>
                                <div class="po-hire-totals-card">
                                    <div class="po-hire-sum-row"><span>ยอดรวม (Subtotal)</span><span id="subtotal_text">0.00</span></div>
                                    <div class="po-hire-sum-row text-tnc-orange"><span>VAT (+)</span><span id="vat_text">0.00</span></div>
                                    <div class="po-hire-sum-row border-bottom pb-2 mb-1"><span class="text-muted fw-semibold">ยอดรวม VAT</span><span id="total_after_vat_text">0.00</span></div>
                                    <div id="retention_summary_row" class="po-hire-sum-row text-danger" style="display:none;"><span>หักประกันผลงาน (-)</span><span id="retention_display">0.00</span></div>
                                    <div class="po-hire-grand-row">
                                        <span class="label">ยอดสุทธิ</span>
                                        <span class="amount" id="grand_total">0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="section-card p-3 mb-3">
                            <label class="po-field-label" for="po_note_hire">หมายเหตุใบสั่งซื้อ</label>
                            <textarea name="po_note" id="po_note_hire" class="form-control" rows="<?= ($poNoteDefaultHire !== '' && in_array($hirePoMode, ['payment', 'advance'], true)) ? min(5, max(2, substr_count($poNoteDefaultHire, "\n") + 1)) : 3 ?>" maxlength="500" placeholder="<?= ($poNoteDefaultHire === '' || !in_array($hirePoMode, ['payment', 'advance'], true)) ? ($hirePoMode === 'contract' ? 'หมายเหตุสัญญาจ้าง (แสดงตอนพิมพ์)' : 'หมายเหตุใบสั่งจ่าย (แสดงตอนพิมพ์)') : '' ?>"><?php if (in_array($hirePoMode, ['payment', 'advance'], true)): ?><?= htmlspecialchars($poNoteDefaultHire, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></textarea>
                            <?php if (in_array($hirePoMode, ['payment', 'advance'], true) && $poNoteDefaultHire !== ''): ?>
                                <div class="form-text">ดึงช่องทางชำระจากข้อมูลผู้รับจ้าง — แก้ไขได้ก่อนบันทึก</div>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="withholding_type" value="none">
                        <?php endif; ?>

                        <?php
                        $pr_vat_on = (int) ($pr['vat_enabled'] ?? 0) === 1;
                        $pr_vat = (float) ($pr['vat_amount'] ?? 0);
                        $pr_grand = (float) $pr['total_amount'];
                        if (isset($pr['subtotal_amount']) && $pr['subtotal_amount'] !== null && $pr['subtotal_amount'] !== '') {
                            $pr_sub = (float) $pr['subtotal_amount'];
                        } else {
                            $pr_sub = round($pr_grand - $pr_vat, 2);
                        }
                        $pr_vat_mode_display = trim((string) ($pr['vat_mode'] ?? 'exclusive'));
                        if (!in_array($pr_vat_mode_display, ['exclusive', 'inclusive'], true)) {
                            $pr_vat_mode_display = 'exclusive';
                        }
                        if (!function_exists('tnc_purchase_vat_print_summary')) {
                            require_once dirname(__DIR__, 2) . '/includes/purchase_print/vat_print_summary.php';
                        }
                        $prVatPrintFromPo = tnc_purchase_vat_print_summary($pr_vat_on, $pr_vat_mode_display, $pr_sub, $pr_vat, $pr_grand);
                        ?>
                        <?php if ($requestType !== 'hire'): ?>
                        <div class="po-panel mb-4">
                            <div class="small fw-semibold text-secondary text-uppercase mb-2" style="letter-spacing:0.06em;">สรุปยอดจากใบขอซื้อ</div>
                            <div class="d-flex justify-content-between align-items-center py-1"><span class="text-secondary">ยอดรายการ</span><strong><?= number_format((float) $prVatPrintFromPo['line_amount'], 2) ?> บาท</strong></div>
                            <?php if ($pr_vat_on && (float) $prVatPrintFromPo['vat_amount'] > 0): ?>
                            <div class="d-flex justify-content-between align-items-center py-1 text-success"><span><?= htmlspecialchars((string) $prVatPrintFromPo['vat_label'], ENT_QUOTES, 'UTF-8') ?></span><strong><?= number_format((float) $prVatPrintFromPo['vat_amount'], 2) ?> บาท</strong></div>
                            <?php else: ?>
                            <div class="text-muted small py-1">ไม่รวม VAT</div>
                            <?php endif; ?>
                            <hr class="my-2 border-secondary-subtle">
                            <div class="d-flex justify-content-between align-items-center"><span class="fw-bold">ยอดสุทธิ</span><strong class="fs-5 text-tnc-orange"><?= number_format((float) $prVatPrintFromPo['net_amount'], 2) ?> บาท</strong></div>
                        </div>
                        <?php if ($pr_needs_price_fix && count($pr_items_for_edit) > 0): ?>
                        <div class="alert alert-warning border-0 py-2 px-3 small mb-4 mb-md-0">
                            <div class="fw-semibold mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i>ใบ PR นี้มีรายการยังไม่มีราคา หรือยอดรวมสุทธิเป็น 0 — กรุณากดปุ่ม <strong>แก้ใบขอซื้อ</strong> มุมขวาบนหัวการ์ดเพื่อแก้รายการสินค้า + VAT ให้ครบก่อนสร้าง PO</div>
                        </div>
                        <?php elseif ($pr_needs_price_fix && count($pr_items_for_edit) === 0): ?>
                        <div class="alert alert-danger border-0 py-2 px-3 small mb-4 mb-md-0">
                            ไม่พบรายการสินค้าใน PR — กรุณา<a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-create.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= $pr_id ?>" class="alert-link">แก้ไขใบขอซื้อเต็มแบบฟอร์ม</a>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>

                        <div class="tnc-mobile-sticky-cta d-lg-none">
                            <div class="tnc-mobile-sticky-inner">
                                <?php if ($requestType === 'hire' && in_array($hirePoMode, ['payment', 'advance'], true)): ?>
                                <div class="tnc-mobile-sticky-meta">
                                    <div class="tnc-mobile-sticky-label">ยอดสุทธิ</div>
                                    <div class="tnc-mobile-sticky-total" id="grand_total_sticky">0.00</div>
                                </div>
                                <?php elseif ($requestType === 'hire' && $hirePoMode === 'contract'): ?>
                                <div class="tnc-mobile-sticky-meta">
                                    <div class="tnc-mobile-sticky-label">ยอดสุทธิ</div>
                                    <div class="tnc-mobile-sticky-total" id="grand_total_sticky">0.00</div>
                                </div>
                                <?php elseif ($requestType !== 'hire'): ?>
                                <div class="tnc-mobile-sticky-meta">
                                    <div class="tnc-mobile-sticky-label">ยอดสุทธิ</div>
                                    <div class="tnc-mobile-sticky-total"><?= number_format((float) $prVatPrintFromPo['net_amount'], 2) ?></div>
                                </div>
                                <?php endif; ?>
                                <div class="tnc-mobile-sticky-actions">
                                    <button type="submit" class="btn btn-orange rounded-pill fw-semibold po-submit-btn-mobile"<?= $tnc_po_submit_disabled ? ' disabled' : '' ?>><?= htmlspecialchars($tnc_po_submit_label, ENT_QUOTES, 'UTF-8') ?></button>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid gap-2 mt-1 d-none d-lg-grid">
                            <button type="submit" class="btn btn-orange btn-lg rounded-pill shadow-sm fw-semibold py-3 po-submit-btn-desktop"<?= $tnc_po_submit_disabled ? ' disabled' : '' ?>><?= htmlspecialchars($tnc_po_submit_label, ENT_QUOTES, 'UTF-8') ?></button>
                            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-view.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= $pr_id ?>" class="btn btn-outline-danger btn-lg rounded-pill fw-semibold py-2">ยกเลิก</a>
                        </div>
                    </form>
                    <?php if ($requestType === 'purchase' && count($pr_items_for_edit) > 0 && $pr_needs_price_fix): ?>
                    <div class="modal fade" id="prFixFromPoModal" tabindex="-1" aria-labelledby="prFixFromPoModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered modal-fullscreen-md-down">
                            <div class="modal-content">
                                <div class="modal-header border-bottom">
                                    <h2 class="modal-title fs-5 fw-bold" id="prFixFromPoModalLabel"><i class="bi bi-pencil-square text-warning me-2"></i>แก้ใบขอซื้อ — <?= htmlspecialchars((string) ($pr['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                                </div>
                                <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=update_pr" method="POST" id="prFixFromPoForm" data-tnc-fullnav="1">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="pr_id" value="<?= (int) ($pr['id'] ?? 0) ?>">
                                    <input type="hidden" name="after_pr_update" value="po_from_pr">
                                    <input type="hidden" name="site_id" value="<?= (int) $pr_site_id_hidden ?>">
                                    <input type="hidden" name="created_at" value="<?= htmlspecialchars($pr_created_ymd, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="requested_by" value="<?= (int) $pr_requested_by_hidden ?>">
                                    <div class="modal-body">
                                        <textarea name="details" class="d-none" tabindex="-1" aria-hidden="true"><?= htmlspecialchars($pr_details_hidden, ENT_QUOTES, 'UTF-8') ?></textarea>
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="vat_enabled" id="pr_fix_vat_enabled" value="1"<?= $pr_fix_vat_on ? ' checked' : '' ?>>
                                                    <label class="form-check-label fw-semibold" for="pr_fix_vat_enabled">รวมภาษีมูลค่าเพิ่ม (VAT 7%)</label>
                                                </div>
                                                <div class="mt-2<?= $pr_fix_vat_on ? '' : ' d-none' ?>" id="pr_fix_vat_mode_wrap">
                                                    <label class="form-label small text-secondary mb-1" for="pr_fix_vat_mode">รูปแบบภาษีมูลค่าเพิ่ม</label>
                                                    <select class="form-select form-select-sm" name="vat_mode" id="pr_fix_vat_mode">
                                                        <option value="exclusive"<?= $pr_fix_vat_mode === 'exclusive' ? ' selected' : '' ?>>แยก VAT</option>
                                                        <option value="inclusive"<?= $pr_fix_vat_mode === 'inclusive' ? ' selected' : '' ?>>รวม VAT</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6 text-md-end small text-muted align-self-end">
                                                <div><span id="pr_fix_subtotal_label">ยอดรายการ:</span> <span id="pr_fix_subtotal_display" class="fw-semibold text-dark">0.00</span> บาท</div>
                                                <div id="pr_fix_vat_row" class="mb-1<?= $pr_fix_vat_on ? '' : ' d-none' ?>"><span id="pr_fix_vat_label">ภาษีมูลค่าเพิ่ม:</span> <span id="pr_fix_vat_display" class="fw-semibold text-success">0.00</span> บาท</div>
                                                <div class="fs-6 fw-bold text-tnc-orange mt-1">ยอดรวมสุทธิ: <span id="pr_fix_grand_total">0.00</span> บาท</div>
                                                <input type="hidden" name="total_amount" id="pr_fix_total_amount_input" value="0">
                                            </div>
                                        </div>
                                        <div class="table-responsive border rounded-3">
                                            <table class="table table-sm align-middle mb-0" id="pr_fix_prTable">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th style="width:2.5rem;">#</th>
                                                        <th>รายการสินค้า</th>
                                                        <th style="width:6.5rem;">จำนวน</th>
                                                        <th style="width:5.5rem;">หน่วย</th>
                                                        <th style="width:7rem;">ราคา/หน่วย</th>
                                                        <th style="width:6.5rem;">ส่วนลด</th>
                                                        <th style="width:7rem;">รวม</th>
                                                        <th style="width:2.5rem;"></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php $prFixRn = 0; ?>
                                                    <?php foreach ($pr_items_for_edit as $it): ?>
                                                        <?php
                                                        $prFixRn++;
                                                        $discEdit = trim((string) ($it['discount_input'] ?? ''));
                                                        if ($discEdit === '') {
                                                            $dt = (string) ($it['discount_type'] ?? 'amount');
                                                            $dv = (float) ($it['discount_value'] ?? 0);
                                                            if ($dv > 0) {
                                                                $discEdit = $dt === 'percent'
                                                                    ? (rtrim(rtrim(number_format($dv, 4, '.', ''), '0'), '.') . '%')
                                                                    : (string) $dv;
                                                            }
                                                        }
                                                        ?>
                                                        <tr>
                                                            <td class="pr-fix-row-number"><?= $prFixRn ?></td>
                                                            <td><input type="text" name="item_description[]" class="form-control form-control-sm" required value="<?= htmlspecialchars((string) ($it['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                                            <td><input type="number" name="item_qty[]" class="form-control form-control-sm pr-fix-qty" step="0.001" min="0" required value="<?= htmlspecialchars((string) ($it['quantity'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"></td>
                                                            <td><input type="text" name="item_unit[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($it['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                                            <td><input type="number" name="item_price[]" class="form-control form-control-sm pr-fix-price" step="0.01" value="<?= htmlspecialchars((string) ($it['unit_price'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" placeholder="0 = ยังไม่ทราบราคา"></td>
                                                            <td><input type="text" name="item_discount[]" class="form-control form-control-sm pr-fix-discount" maxlength="20" value="<?= htmlspecialchars($discEdit, ENT_QUOTES, 'UTF-8') ?>"></td>
                                                            <td><input type="text" class="form-control form-control-sm pr-fix-row-total bg-light" value="<?= number_format((float) ($it['total'] ?? 0), 2, '.', '') ?>" readonly></td>
                                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger border-0 pr-fix-remove-row" title="ลบแถว"><i class="bi bi-trash-fill"></i></button></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-orange mt-2 rounded-pill" id="pr_fix_add_row"><i class="bi bi-plus-circle me-1"></i>เพิ่มรายการสินค้า</button>
                                    </div>
                                    <div class="modal-footer border-top bg-light">
                                        <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">ปิด</button>
                                        <button type="submit" class="btn btn-warning text-dark fw-semibold rounded-pill px-4"><i class="bi bi-save me-1"></i>บันทึกลงใบขอซื้อ</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </div>
<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($requestType === 'hire' && $hirePoMode === 'contract'): ?>
<script src="<?= htmlspecialchars(app_path('assets/js/hire-line-table.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
<script src="<?= htmlspecialchars(app_path('assets/js/purchase-vat-calc.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function ($) {
    if (typeof window.TncLiveDT === 'undefined' || !$ || !$.fn.DataTable) return;
    var $t = $('#tncHirePaidInstallmentsTable');
    if (!$t.length) return;
    if ($t.find('tbody tr').length === 1 && $t.find('tbody td[colspan]').length) return;
    TncLiveDT.init('#tncHirePaidInstallmentsTable', { order: [[2, 'desc']], pageLength: 10 });
})(jQuery);
</script>
<script>
(function () {
    const searchInput = document.getElementById('supplier_search');
    const supplierIdInput = document.getElementById('supplier_id');
    const datalist = document.getElementById('supplier_list');
    if (!searchInput || !supplierIdInput || !datalist) {
        return;
    }

    function syncSupplierId() {
        const typed = (searchInput.value || '').trim();
        if (typed === '') {
            supplierIdInput.value = '';
            return;
        }
        const options = datalist.querySelectorAll('option');
        let matchedId = '';
        options.forEach((opt) => {
            const optValue = (opt.value || '').trim();
            if (matchedId === '' && optValue.toLowerCase() === typed.toLowerCase()) {
                matchedId = (opt.getAttribute('data-id') || '').trim();
            }
        });
        supplierIdInput.value = matchedId;
    }

    searchInput.addEventListener('input', syncSupplierId);
    searchInput.addEventListener('change', syncSupplierId);

    const form = searchInput.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            syncSupplierId();
        });
    }
})();

(function () {
    const cb = document.getElementById('has_qt');
    const panel = document.getElementById('qt_panel');
    const fields = ['qt_quotation_number', 'qt_quotation_date', 'qt_quotation_note'].map(function (id) { return document.getElementById(id); }).filter(Boolean);
    if (!cb || !panel) return;

    function setQtEnabled(on) {
        fields.forEach(function (el) {
            el.disabled = !on;
            if (!on) {
                if (el.type === 'checkbox') return;
                el.value = '';
            }
        });
    }

    function toggleQtPanel() {
        const on = cb.checked;
        panel.classList.toggle('d-none', !on);
        setQtEnabled(on);
    }

    cb.addEventListener('change', toggleQtPanel);
    setQtEnabled(false);
    panel.classList.add('d-none');
})();

<?php if ($requestType === 'hire' && in_array($hirePoMode, ['payment', 'advance'], true)): ?>
<script>
function addPoPaymentRow() {
    const table = document.getElementById('poTable').getElementsByTagName('tbody')[0];
    const newRow = table.insertRow();
    const rowCount = table.rows.length;
    newRow.innerHTML = '<td class="po-cell-idx row-number text-secondary small fw-semibold">' +
        '<div class="po-mobile-item-head">' +
        '<span class="po-mobile-item-label">รายการที่ <span class="po-mobile-item-no">' + rowCount + '</span></span>' +
        '<button type="button" class="btn btn-outline-danger btn-sm border-0 po-row-delete-btn po-row-delete-mobile" title="ลบแถว" aria-label="ลบแถว"><i class="bi bi-trash-fill"></i></button>' +
        '</div>' +
        '<span class="d-none d-lg-inline po-mobile-item-no">' + rowCount + '</span>' +
        '</td>' +
        '<td class="po-cell-desc" data-label="รายการ"><input type="text" name="item_description[]" class="form-control form-control-sm po-line-desc" required placeholder="ระบุรายการ"></td>' +
        '<td class="po-cell-qty" data-label="จำนวน"><input type="number" name="item_qty[]" class="form-control form-control-sm qty" step="0.001" min="0" required oninput="calculatePoPaymentTotal()"></td>' +
        '<td class="po-cell-unit" data-label="หน่วย"><input type="text" name="item_unit[]" class="form-control form-control-sm" placeholder="ชิ้น"></td>' +
        '<td class="po-cell-price" data-label="ราคา/หน่วย"><input type="number" name="item_price[]" class="form-control form-control-sm price" step="0.001" required oninput="calculatePoPaymentTotal()"></td>' +
        '<td class="po-cell-total" data-label="ยอดรวม"><input type="text" class="form-control form-control-sm row-total bg-light text-end fw-semibold" value="0.00" readonly tabindex="-1"></td>' +
        '<td class="po-cell-action po-cell-action-desktop"><button type="button" class="btn btn-outline-danger btn-sm border-0 po-row-delete-btn" title="ลบแถว" aria-label="ลบแถว"><i class="bi bi-trash-fill"></i></button></td>';
    calculatePoPaymentTotal();
}
function removePoPaymentRow(btn) {
    const row = btn.closest('tr');
    if (!row) return;
    row.remove();
    document.querySelectorAll('#poTable .po-mobile-item-no, #poTable .row-number .d-none.d-lg-inline').forEach(function (el, i) {
        if (el.classList.contains('po-mobile-item-no') || el.classList.contains('d-lg-inline')) {
            el.innerText = i + 1;
        }
    });
    document.querySelectorAll('#poTable tbody tr').forEach(function (rowEl, i) {
        rowEl.querySelectorAll('.po-mobile-item-no').forEach(function (el) { el.innerText = i + 1; });
    });
    calculatePoPaymentTotal();
}
function poPaymentLineAmount(qty, price) {
    const q = parseFloat(String(qty || '').replace(/,/g, '')) || 0;
    const p = parseFloat(String(price || '').replace(/,/g, '')) || 0;
    return Math.round(q * p * 100) / 100;
}
function updatePoPaymentVatUi() {
    const vatBasisWrap = document.getElementById('vat_basis_wrap');
    const vatEnabled = document.getElementById('vat_enabled');
    if (!vatBasisWrap || !vatEnabled) return;
    const on = vatEnabled.checked;
    vatBasisWrap.classList.toggle('opacity-50', !on);
    vatBasisWrap.style.pointerEvents = on ? '' : 'none';
    vatBasisWrap.setAttribute('aria-disabled', on ? 'false' : 'true');
    vatBasisWrap.querySelectorAll('input[name="vat_basis"]').forEach(function (el) {
        el.disabled = !on;
    });
}
function calculatePoPaymentTotal() {
    const installmentAmountInput = document.getElementById('installment_amount');
    const installmentDescriptionEl = document.getElementById('installment_description');
    const vatModeInput = document.getElementById('vat_mode');
    const vatEnabledEl = document.getElementById('vat_enabled');
    const retentionValueEl = document.getElementById('retention_value');
    const poTable = document.getElementById('poTable');
    if (!poTable || !poTable.tBodies[0]) {
        return;
    }
    const vatOn = !!(vatEnabledEl && vatEnabledEl.checked);
    let vatMode = 'exclusive';
    if (vatOn) {
        const selectedBasis = document.querySelector('input[name="vat_basis"]:checked');
        vatMode = selectedBasis ? selectedBasis.value : 'exclusive';
    }
    if (vatModeInput) vatModeInput.value = vatMode;

    let lineAmount = 0;
    let firstDescription = '';
    const rows = poTable.tBodies[0].rows;
    for (const row of rows) {
        const descEl = row.querySelector('.po-line-desc');
        if (firstDescription === '' && (descEl?.value || '').trim() !== '') {
            firstDescription = (descEl?.value || '').trim();
        }
        const total = poPaymentLineAmount(row.querySelector('.qty')?.value, row.querySelector('.price')?.value);
        const totalEl = row.querySelector('.row-total');
        if (totalEl) {
            totalEl.value = total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        lineAmount += total;
    }
    lineAmount = Math.round(lineAmount * 100) / 100;
    if (installmentAmountInput) {
        installmentAmountInput.value = lineAmount > 0 ? String(lineAmount) : '';
    }
    if (installmentDescriptionEl) {
        installmentDescriptionEl.value = firstDescription !== '' ? firstDescription : 'สั่งจ่ายตามตารางรายการ';
    }

    const split = typeof tncPurchaseVatFromLineSum === 'function'
        ? tncPurchaseVatFromLineSum(lineAmount, vatOn, vatMode)
        : { subtotal: lineAmount, vat: 0, gross: lineAmount };
    const subtotal = split.subtotal;
    const vat = split.vat;
    const gross = split.gross;

    let retentionRaw = (retentionValueEl?.value || '').toString().trim().replace('%', '');
    let retention = Math.max(0, Math.round((parseFloat(retentionRaw) || 0) * 100) / 100);
    const net = Math.round((gross - retention) * 100) / 100;
    const fmt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };

    const subtotalDisplay = document.getElementById('po_pay_subtotal_display');
    const vatDisplay = document.getElementById('po_pay_vat_display');
    const vatRow = document.getElementById('po_pay_vat_row');
    const totalAfterVatDisplay = document.getElementById('po_pay_total_after_vat_display');
    const retentionDisplay = document.getElementById('po_pay_retention_display');
    const retentionSummaryRow = document.getElementById('po_pay_retention_summary_row');
    const grandTotal = document.getElementById('po_pay_grand_total');
    const hireRemainingDisplay = document.getElementById('hire_remaining_display');
    const poForm = document.querySelector('form[data-hire-remaining]');

    if (subtotalDisplay) subtotalDisplay.textContent = subtotal.toLocaleString(undefined, fmt);
    if (vatOn && vatRow && vatDisplay) {
        vatRow.style.display = 'grid';
        vatDisplay.textContent = vat.toLocaleString(undefined, fmt);
    } else if (vatRow) {
        vatRow.style.display = 'none';
    }
    if (totalAfterVatDisplay) totalAfterVatDisplay.textContent = gross.toLocaleString(undefined, fmt);
    if (retentionDisplay) retentionDisplay.textContent = retention.toLocaleString(undefined, fmt);
    if (retentionSummaryRow) retentionSummaryRow.style.display = retention > 0 ? 'grid' : 'none';
    if (grandTotal) grandTotal.textContent = net.toLocaleString(undefined, fmt);

    if (hireRemainingDisplay && poForm) {
        const remaining = parseFloat(poForm.getAttribute('data-hire-remaining') || '0') || 0;
        const projected = Math.round((remaining - net) * 100) / 100;
        hireRemainingDisplay.textContent = projected.toLocaleString(undefined, fmt) + ' บาท';
        hireRemainingDisplay.classList.remove('text-danger', 'text-success', 'text-tnc-orange', 'fw-bold');
        if (projected < -0.0005) {
            hireRemainingDisplay.classList.add('text-danger', 'fw-bold');
        } else if (projected <= 0.0005) {
            hireRemainingDisplay.classList.add('text-success', 'fw-bold');
        } else {
            hireRemainingDisplay.classList.add('text-tnc-orange', 'fw-bold');
        }
    }
    window.__hirePoNet = net;
    updatePoPaymentVatUi();
}
document.addEventListener('DOMContentLoaded', function () {
    updatePoPaymentVatUi();
    calculatePoPaymentTotal();
    const poTable = document.getElementById('poTable');
    if (poTable) {
        poTable.addEventListener('input', function (e) {
            if (e.target.closest('.qty, .price, .po-line-desc')) {
                calculatePoPaymentTotal();
            }
        });
        poTable.addEventListener('click', function (e) {
            const btn = e.target.closest('.po-row-delete-btn');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            removePoPaymentRow(btn);
        });
    }
    const poForm = document.querySelector('form[data-hire-remaining]');
    const confirmOverInput = document.getElementById('confirm_over_contract');
    poForm?.addEventListener('submit', function (event) {
        calculatePoPaymentTotal();
        const installmentDescriptionEl = document.getElementById('installment_description');
        if (installmentDescriptionEl && installmentDescriptionEl.value.trim() === '') {
            installmentDescriptionEl.value = 'สั่งจ่ายตามตารางรายการ';
        }
        if (poForm.getAttribute('data-hire-advance') === '1') {
            return;
        }
        const net = window.__hirePoNet || 0;
        const remaining = parseFloat(poForm.getAttribute('data-hire-remaining') || '0') || 0;
        const alreadyConfirmed = confirmOverInput && confirmOverInput.value === '1';
        if (net > remaining + 0.0005 && !alreadyConfirmed) {
            event.preventDefault();
            const msg = <?= json_encode($hirePoMode === 'advance'
                ? 'จำนวนเงินที่ต้องการเบิก เกินมูลค่าคงเหลือในสัญญาแล้ว ท่านต้องการออก PO หรือไม่'
                : 'จำนวนเงินที่ต้องการจ่าย เกินมูลค่าสัญญานี้แล้ว ท่านต้องการออกใบสั่งจ่ายหรือไม่', JSON_UNESCAPED_UNICODE) ?>;
            if (confirm(msg)) {
                if (confirmOverInput) confirmOverInput.value = '1';
                poForm.requestSubmit();
            }
        }
    });
});
</script>
<?php elseif ($requestType === 'hire' && $hirePoMode === 'contract'): ?>
<script>
(function () {
    const installmentAmountInput = document.getElementById('installment_amount');
    const subtotalTextEl = document.getElementById('subtotal_text');
    const vatTextEl = document.getElementById('vat_text');
    const totalAfterVatTextEl = document.getElementById('total_after_vat_text');
    const retentionDisplayEl = document.getElementById('retention_display');
    const grandTotalEl = document.getElementById('grand_total');
    const retentionSummaryRowEl = document.getElementById('retention_summary_row');
    const withholdingTypeEl = document.getElementById('withholding_type');
    const retentionTypeEl = document.getElementById('retention_type');
    const retentionValueEl = document.getElementById('retention_value');
    const installmentDescriptionEl = document.getElementById('installment_description');
    const vatEnabledEl = document.getElementById('vat_enabled');
    const table = document.getElementById('hireInstallmentTable');
    const addGroupBtn = document.getElementById('addHireGroupBtn');
    const addRowBtn = document.getElementById('addHireRowBtn');
    if (!installmentAmountInput || !subtotalTextEl || !table) {
        return;
    }

    const applySubtotal = (subtotal) => {
        let firstDescription = '';
        table.querySelectorAll('tbody tr').forEach((row) => {
            const descEl = row.querySelector('.hire-desc') || row.querySelector('.hire-desc-group');
            if (firstDescription === '' && (descEl?.value || '').trim() !== '') {
                firstDescription = (descEl?.value || '').trim();
            }
        });
        if (installmentDescriptionEl) {
            installmentDescriptionEl.value = firstDescription !== '' ? firstDescription : 'สั่งจ่ายตามตารางรายการ';
        }
        subtotal = Math.round(subtotal * 100) / 100;
        installmentAmountInput.value = subtotal > 0 ? String(subtotal) : '';

        const vat = vatEnabledEl?.checked ? Math.round(subtotal * 0.07 * 100) / 100 : 0;
        if (withholdingTypeEl) {
            withholdingTypeEl.value = 'none';
        }
        const retentionType = (retentionTypeEl?.value || 'fixed');
        let retentionValueRaw = (retentionValueEl?.value || '').toString().trim().replace('%', '');
        let retentionValue = parseFloat(retentionValueRaw) || 0;
        if (retentionValue < 0) retentionValue = 0;
        let retention = 0;
        if (retentionType === 'percent') {
            if (retentionValue > 100) retentionValue = 100;
            retention = Math.round(subtotal * (retentionValue / 100) * 100) / 100;
        } else if (retentionType === 'fixed') {
            retention = Math.round(retentionValue * 100) / 100;
        }
        const totalAfterVat = Math.round((subtotal + vat) * 100) / 100;
        const net = Math.round((totalAfterVat - retention) * 100) / 100;
        const fmt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
        subtotalTextEl.textContent = subtotal.toLocaleString(undefined, fmt);
        if (vatTextEl) vatTextEl.textContent = '+ ' + vat.toLocaleString(undefined, fmt);
        if (totalAfterVatTextEl) totalAfterVatTextEl.textContent = totalAfterVat.toLocaleString(undefined, fmt);
        if (retentionDisplayEl) retentionDisplayEl.textContent = '- ' + retention.toLocaleString(undefined, fmt);
        if (grandTotalEl) grandTotalEl.textContent = net.toLocaleString(undefined, fmt);
        if (retentionSummaryRowEl) retentionSummaryRowEl.style.display = retention > 0 ? 'flex' : 'none';
        return net;
    };

    const form = document.querySelector('form[action*="create_po_from_pr"]');
    const confirmOverInput = document.getElementById('confirm_over_contract');
    const hireRemainingDisplay = document.getElementById('hire_remaining_display');

    const updateHireRemainingPreview = (net) => {
        if (!hireRemainingDisplay || !form?.hasAttribute('data-hire-remaining')) {
            return;
        }
        const remaining = parseFloat(form.getAttribute('data-hire-remaining') || '0') || 0;
        const projected = Math.round((remaining - net) * 100) / 100;
        const fmt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
        hireRemainingDisplay.textContent = projected.toLocaleString(undefined, fmt) + ' บาท';
        hireRemainingDisplay.classList.remove('text-danger', 'text-success', 'text-tnc-orange', 'fw-bold');
        if (projected < -0.0005) {
            hireRemainingDisplay.classList.add('text-danger', 'fw-bold');
        } else if (projected <= 0.0005) {
            hireRemainingDisplay.classList.add('text-success', 'fw-bold');
        } else {
            hireRemainingDisplay.classList.add('text-tnc-orange', 'fw-bold');
        }
    };

    let lastNet = 0;
    const hireLineApi = window.TncHireLineTable ? window.TncHireLineTable.bindTable(table, {
        fieldPrefix: 'hire',
        addGroupButton: addGroupBtn,
        addItemButton: addRowBtn,
        onSubtotal: (subtotal) => {
            lastNet = applySubtotal(subtotal);
            updateHireRemainingPreview(lastNet);
        },
    }) : null;

    const recalcWithNet = () => {
        if (hireLineApi) {
            lastNet = applySubtotal(hireLineApi.recalc());
            updateHireRemainingPreview(lastNet);
        }
        return lastNet;
    };

    withholdingTypeEl?.addEventListener('change', recalcWithNet);
    retentionTypeEl?.addEventListener('change', recalcWithNet);
    retentionValueEl?.addEventListener('input', recalcWithNet);
    vatEnabledEl?.addEventListener('change', recalcWithNet);
    form?.addEventListener('submit', (event) => {
        recalcWithNet();
        if (installmentDescriptionEl && installmentDescriptionEl.value.trim() === '') {
            installmentDescriptionEl.value = 'สั่งจ่ายตามตารางรายการ';
        }
        if (withholdingTypeEl) withholdingTypeEl.value = 'none';
        if (retentionTypeEl) retentionTypeEl.value = 'fixed';
        const remaining = parseFloat(form.getAttribute('data-hire-remaining') || '0') || 0;
        const alreadyConfirmed = confirmOverInput && confirmOverInput.value === '1';
        if (form.hasAttribute('data-hire-remaining') && lastNet > remaining + 0.0005 && !alreadyConfirmed) {
            event.preventDefault();
            if (confirm('จำนวนเงินที่ต้องการจ่าย เกิน มูลค่าสัญญานี้แล้ว ท่านต้องการออกใบสั่งจ่ายหรือไม่')) {
                if (confirmOverInput) {
                    confirmOverInput.value = '1';
                }
                form.requestSubmit();
            }
        }
    });

    recalcWithNet();
})();
</script>
<?php endif; ?>
<script>
(function () {
    const table = document.getElementById('pr_fix_prTable');
    if (!table) {
        return;
    }
    const tbody = table.querySelector('tbody');
    const vatOnEl = document.getElementById('pr_fix_vat_enabled');
    const vatModeEl = document.getElementById('pr_fix_vat_mode');
    const vatModeWrap = document.getElementById('pr_fix_vat_mode_wrap');
    const subtotalDisplay = document.getElementById('pr_fix_subtotal_display');
    const subtotalLabel = document.getElementById('pr_fix_subtotal_label');
    const vatLabel = document.getElementById('pr_fix_vat_label');
    const vatRow = document.getElementById('pr_fix_vat_row');
    const vatDisplay = document.getElementById('pr_fix_vat_display');
    const grandTotalEl = document.getElementById('pr_fix_grand_total');
    const totalAmountInput = document.getElementById('pr_fix_total_amount_input');
    const addRowBtn = document.getElementById('pr_fix_add_row');

    function prFixLineAmountAfterDiscount(qty, price, discRaw) {
        const q = parseFloat(String(qty || '').replace(/,/g, '')) || 0;
        const p = parseFloat(String(price || '').replace(/,/g, '')) || 0;
        const base = Math.round(q * p * 100) / 100;
        const dRaw = String(discRaw || '').trim();
        let discount = 0;
        if (dRaw !== '' && base > 0) {
            const pctMatch = dRaw.match(/^([0-9]+(?:\.[0-9]+)?)\s*%$/);
            if (pctMatch) {
                let pct = parseFloat(pctMatch[1]) || 0;
                if (pct < 0) pct = 0;
                if (pct > 100) pct = 100;
                discount = Math.round(base * pct / 100 * 100) / 100;
            } else {
                discount = Math.round((parseFloat(dRaw.replace(/,/g, '')) || 0) * 100) / 100;
                if (discount < 0) discount = 0;
                if (discount > base) discount = base;
            }
        }
        return Math.round((base - discount) * 100) / 100;
    }

    function prFixUpdateRowNumbers() {
        table.querySelectorAll('.pr-fix-row-number').forEach(function (td, index) {
            td.textContent = String(index + 1);
        });
    }

    function prFixCalculateTotal() {
        if (!tbody) return;
        const vatOn = !!(vatOnEl && vatOnEl.checked);
        const vatMode = (vatModeEl && vatModeEl.value) || 'exclusive';
        let lineAmount = 0;
        for (let i = 0; i < tbody.rows.length; i++) {
            const row = tbody.rows[i];
            const qtyEl = row.querySelector('.pr-fix-qty');
            const priceEl = row.querySelector('.pr-fix-price');
            const discEl = row.querySelector('.pr-fix-discount');
            const totalEl = row.querySelector('.pr-fix-row-total');
            const total = prFixLineAmountAfterDiscount(
                qtyEl ? qtyEl.value : 0,
                priceEl ? priceEl.value : 0,
                discEl ? discEl.value : ''
            );
            if (totalEl) {
                totalEl.value = total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            lineAmount += total;
        }
        lineAmount = Math.round(lineAmount * 100) / 100;
        const split = tncPurchaseVatFromLineSum(lineAmount, vatOn, vatMode);
        const subtotal = split.subtotal;
        const vat = split.vat;
        const grand = split.gross;
        if (subtotalLabel) {
            subtotalLabel.textContent = 'ยอดรายการ:';
        }
        if (vatLabel) {
            if (!vatOn) {
                vatLabel.textContent = 'แยก VAT:';
            } else if (vatMode === 'inclusive') {
                vatLabel.textContent = 'รวม VAT:';
            } else {
                vatLabel.textContent = 'แยก VAT:';
            }
        }
        if (subtotalDisplay) {
            subtotalDisplay.textContent = subtotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        if (vatRow) {
            vatRow.classList.toggle('d-none', !vatOn);
        }
        if (vatDisplay) {
            vatDisplay.textContent = vat.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        if (grandTotalEl) {
            grandTotalEl.textContent = grand.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        if (totalAmountInput) {
            totalAmountInput.value = grand.toFixed(2);
        }
        if (vatModeWrap) {
            vatModeWrap.classList.toggle('d-none', !vatOn);
        }
    }

    function prFixUpdateRemoveButtons() {
        const rows = tbody.querySelectorAll('tr');
        const one = rows.length <= 1;
        rows.forEach(function (row) {
            const btn = row.querySelector('.pr-fix-remove-row');
            if (btn) btn.disabled = one;
        });
    }

    function prFixBindRow(row) {
        row.querySelectorAll('.pr-fix-qty, .pr-fix-price, .pr-fix-discount').forEach(function (el) {
            el.addEventListener('input', prFixCalculateTotal);
        });
        const removeBtn = row.querySelector('.pr-fix-remove-row');
        removeBtn?.addEventListener('click', function () {
            if (tbody.querySelectorAll('tr').length <= 1) return;
            row.remove();
            prFixUpdateRowNumbers();
            prFixUpdateRemoveButtons();
            prFixCalculateTotal();
        });
    }

    tbody.querySelectorAll('tr').forEach(prFixBindRow);
    prFixUpdateRemoveButtons();
    vatOnEl?.addEventListener('change', prFixCalculateTotal);
    vatModeEl?.addEventListener('change', prFixCalculateTotal);

    addRowBtn?.addEventListener('click', function () {
        if (!tbody) return;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="pr-fix-row-number">0</td>
            <td><input type="text" name="item_description[]" class="form-control form-control-sm" required placeholder="รายการสินค้า"></td>
            <td><input type="number" name="item_qty[]" class="form-control form-control-sm pr-fix-qty" step="0.001" min="0" required value="1"></td>
            <td><input type="text" name="item_unit[]" class="form-control form-control-sm"></td>
            <td><input type="number" name="item_price[]" class="form-control form-control-sm pr-fix-price" step="0.01" value="0" placeholder="0 = ยังไม่ทราบราคา"></td>
            <td><input type="text" name="item_discount[]" class="form-control form-control-sm pr-fix-discount" maxlength="20"></td>
            <td><input type="text" class="form-control form-control-sm pr-fix-row-total bg-light" value="0.00" readonly></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger border-0 pr-fix-remove-row" title="ลบแถว"><i class="bi bi-trash-fill"></i></button></td>
        `;
        tbody.appendChild(tr);
        prFixBindRow(tr);
        prFixUpdateRowNumbers();
        prFixUpdateRemoveButtons();
        prFixCalculateTotal();
    });

    function prFixInitTotals() {
        prFixCalculateTotal();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', prFixInitTotals);
    } else {
        prFixInitTotals();
    }
})();
</script>
</body>
</html>