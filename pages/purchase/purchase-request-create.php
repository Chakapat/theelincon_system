<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/line_notify_runtime.php';
require_once dirname(__DIR__, 2) . '/includes/hire_line_items.php';
require_once dirname(__DIR__, 2) . '/includes/hire_form_rows.php';
require_once dirname(__DIR__, 2) . '/includes/contractors.php';

$prOfferLineOnSave = line_effective_channel_access_token() !== '' && line_effective_target_group_id() !== '';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$editId = (int) ($_GET['id'] ?? 0);
if ($editId > 0) {
    if (!user_can('pr.update')) {
        header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?error=forbidden');
        exit();
    }
} elseif (!user_can('pr.create')) {
    header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?error=forbidden');
    exit();
}

$uid = (int) $_SESSION['user_id'];

$editPr = null;
$editItems = [];
if ($editId > 0) {
    $editPr = Db::rowByIdField('purchase_requests', $editId);
    if ($editPr === null) {
        header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?error=invalid_pr');
        exit();
    }
    $poForPr = Db::findFirst('purchase_orders', static function (array $r) use ($editId): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $editId;
    });
    if ($poForPr !== null) {
        header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?error=pr_has_po');
        exit();
    }
    $editItems = Db::filter('purchase_request_items', static function (array $r) use ($editId): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $editId;
    });
    Db::sortRows($editItems, 'id', false);
}
$isEdit = $editPr !== null;
$current_pr_number = $isEdit ? (string) ($editPr['pr_number'] ?? '') : Purchase::nextPRNumber();
$prFormAction = $isEdit ? 'update_pr' : 'save_pr';
$requestTypeVal = $isEdit ? trim((string) ($editPr['request_type'] ?? ($editPr['procurement_type'] ?? 'purchase'))) : 'purchase';
if (!in_array($requestTypeVal, ['purchase', 'hire'], true)) {
    $requestTypeVal = (stripos($requestTypeVal, 'hire') !== false || str_contains($requestTypeVal, 'จัดจ้าง')) ? 'hire' : 'purchase';
}
if (!$isEdit) {
    $createTypeHint = trim((string) ($_GET['type'] ?? ''));
    if ($createTypeHint === 'hire') {
        $requestTypeVal = 'hire';
    }
}
if ($requestTypeVal !== 'hire') {
    $requestTypeVal = 'purchase';
}
$createdAtDisplay = date('d/m/Y');
if ($isEdit) {
    $rawDate = trim((string) ($editPr['created_at'] ?? ''));
    if ($rawDate !== '') {
        $ts = strtotime($rawDate);
        if ($ts !== false) {
            $createdAtDisplay = date('d/m/Y', $ts);
        }
    }
}
$editSiteId = $isEdit ? (int) ($editPr['site_id'] ?? 0) : 0;
$editDetails = $isEdit ? trim((string) ($editPr['details'] ?? '')) : '';
if ($isEdit && $editDetails === '') {
    $editDetails = trim((string) ($editPr['hire_scope_details'] ?? ''));
}
$editVatOn = $isEdit && (int) ($editPr['vat_enabled'] ?? 0) === 1;
$editVatMode = $isEdit ? trim((string) ($editPr['vat_mode'] ?? 'exclusive')) : 'exclusive';
if (!in_array($editVatMode, ['exclusive', 'inclusive'], true)) {
    $editVatMode = 'exclusive';
}
$editRequestedBy = $isEdit ? (int) ($editPr['requested_by'] ?? $uid) : $uid;
$hireContractorEdit = $isEdit ? trim((string) ($editPr['contractor_name'] ?? ($editPr['hire_contractor_name'] ?? ''))) : '';
$hireContractorIdEdit = $isEdit ? (int) ($editPr['contractor_id'] ?? 0) : 0;
$hireContractorSearchEdit = '';
if ($hireContractorIdEdit > 0) {
    $hireContractorRowEdit = tnc_contractor_row_by_id($hireContractorIdEdit);
    if ($hireContractorRowEdit !== null) {
        $hireContractorSearchEdit = tnc_contractor_display_label($hireContractorRowEdit);
    }
} elseif ($hireContractorEdit !== '') {
    $hireContractorSearchEdit = $hireContractorEdit;
}
$contractorRows = Db::tableRows('contractors');
usort($contractorRows, static function (array $a, array $b): int {
    return strnatcasecmp(tnc_contractor_full_name_th($a), tnc_contractor_full_name_th($b));
});
$hireValueEdit = $isEdit ? (float) ($editPr['contract_value'] ?? ($editPr['hire_total_value'] ?? 0)) : 0.0;
$hireInstallEdit = $isEdit ? (int) ($editPr['installment_total'] ?? ($editPr['hire_installment_count'] ?? 1)) : 1;
if ($hireInstallEdit < 1) {
    $hireInstallEdit = 1;
}
$hireOverheadEdit = $isEdit ? (float) ($editPr['overhead_percent'] ?? 0) : 0.0;
$hirePreliminaryEdit = $isEdit ? (float) ($editPr['preliminary_percent'] ?? 0) : 0.0;

$sites = Db::tableRows('sites');
usort($sites, static function (array $a, array $b): int {
    $sort = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
    if ($sort !== 0) {
        return $sort;
    }

    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});

require_once dirname(__DIR__, 2) . '/includes/site_cost_categories.php';
$siteCategoryMap = tnc_site_categories_map_by_site(); // [siteId => [{id,name}], 0 = หมวดกลาง]
$editCostCategoryId = $isEdit ? (int) ($editPr['cost_category_id'] ?? 0) : 0;
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title><?= $isEdit ? 'แก้ไขใบขอซื้อ (PR)' : 'สร้างใบขอซื้อ (PR)' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/purchase-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/hire-line-table.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/pr-hire-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        :root {
            --pr-brand: var(--tnc-orange, #ea580c);
            --pr-brand-hover: var(--tnc-orange-dark, #c2410c);
            --pr-surface: var(--tnc-surface, #f6f7f9);
            --pr-card-shadow: 0 0.28rem 0.9rem rgba(0, 0, 0, 0.045);
            --pr-border: #e2e8f0;
            --space-xs: 0.25rem;
            --space-sm: 0.5rem;
            --space-md: 1rem;
            --space-lg: 1.5rem;
            --space-xl: 2rem;
            --space-2xl: 2.5rem;
        }
        body {
            background: var(--pr-surface);
            font-family: 'Sarabun', sans-serif;
            color: #0f172a;
        }
        .pr-page { max-width: min(1200px, 100%); }
        .pr-page.pr-page--wide {
            width: 100%;
            max-width: none;
        }
        .pr-form-stack {
            display: flex;
            flex-direction: column;
            gap: var(--space-lg);
        }
        .pr-page-header {
            margin-bottom: var(--space-lg);
            padding-bottom: var(--space-md);
            border-bottom: 1px solid var(--pr-border);
        }
        @media (min-width: 1200px) {
            .pr-page.pr-page--wide .pr-table-card {
                padding: var(--space-lg) var(--space-md);
            }
        }
        @media (min-width: 1600px) {
            .pr-page.pr-page--wide .pr-table-card {
                padding: var(--space-xl) var(--space-lg);
            }
        }
        .pr-page-title {
            font-size: clamp(1.35rem, 2.5vw, 1.5rem);
            font-weight: 700;
            letter-spacing: -0.02em;
            color: #0f172a;
            line-height: 1.25;
        }
        .pr-page-title__icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.35rem;
            height: 2.35rem;
            border-radius: 0.625rem;
            background: rgba(253, 126, 20, 0.12);
            color: var(--pr-brand);
            font-size: 1.05rem;
            vertical-align: -0.12em;
        }
        .pr-card {
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 0.875rem;
            box-shadow: var(--pr-card-shadow);
            padding: var(--space-lg);
        }
        .pr-section-head {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin: 0 0 var(--space-md);
        }
        .pr-field-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            letter-spacing: 0.01em;
            margin-bottom: 0.35rem;
        }
        .pr-meta-grid .form-control,
        .pr-meta-grid .form-select {
            border-radius: 0.5rem;
            border-color: var(--pr-border);
        }
        .pr-meta-grid .form-control:focus,
        .pr-meta-grid .form-select:focus {
            border-color: var(--pr-brand);
            box-shadow: 0 0 0 0.2rem rgba(253, 126, 20, 0.15);
        }
        .pr-meta-grid .form-control[readonly] {
            background: #f8fafc;
            font-weight: 600;
            color: var(--pr-brand);
        }
        .pr-meta-grid {
            --bs-gutter-y: 0.75rem;
            --bs-gutter-x: 0.75rem;
        }
        .pr-meta-grid .form-text {
            font-size: 0.72rem;
            margin-top: 0.25rem;
            line-height: 1.35;
        }
        .pr-meta-grid textarea.form-control {
            min-height: 3.25rem;
        }
        @media (max-width: 575.98px) {
            .pr-page-header .d-flex.gap-2 {
                width: 100%;
            }
            .pr-page-header .btn-pr-primary,
            .pr-page-header .btn-pr-cancel {
                flex: 1 1 calc(50% - 0.25rem);
                justify-content: center;
            }
        }
        .pr-table-card .table {
            margin-bottom: 0;
        }
        #prTable {
            --bs-table-bg: transparent;
        }
        #prTable thead th {
            background: transparent;
            border-bottom: 2px solid var(--pr-border);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            padding: 0.75rem 0.5rem;
            white-space: nowrap;
        }
        #prTable tbody td {
            padding: 0.5rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }
        #prTable tbody tr:last-child td {
            border-bottom: none;
        }
        #prTable .form-control {
            border: 1px solid var(--pr-border);
            border-radius: 0.375rem;
            font-size: 0.9rem;
            padding: 0.45rem 0.55rem;
        }
        #prTable .form-control:focus {
            border-color: var(--pr-brand);
            box-shadow: 0 0 0 0.15rem rgba(253, 126, 20, 0.12);
        }
        #prTable .row-total {
            background: #f8fafc !important;
            font-weight: 600;
            color: #0f172a;
        }
        #prTable .row-number {
            color: #94a3b8;
            font-weight: 600;
            font-size: 0.85rem;
            text-align: center;
        }
        .btn-pr-primary {
            background-color: var(--pr-brand);
            color: #fff;
            border: none;
            font-weight: 600;
            padding: 0.55rem 1.5rem;
            border-radius: 999px;
            box-shadow: 0 4px 14px rgba(253, 126, 20, 0.35);
        }
        .btn-pr-primary:hover:not(:disabled) {
            background-color: var(--pr-brand-hover);
            color: #fff;
        }
        .btn-pr-primary:disabled {
            opacity: 0.55;
        }
        .btn-pr-cancel {
            background: #fff;
            color: #475569;
            border: 1px solid var(--pr-border);
            font-weight: 500;
            padding: 0.55rem 1.5rem;
            border-radius: 999px;
        }
        .btn-pr-cancel:hover {
            background: #f8fafc;
            color: #334155;
            border-color: #cbd5e1;
        }
        .btn-pr-add-row {
            border-color: var(--pr-border);
            color: #475569;
            font-weight: 500;
        }
        .btn-pr-add-row:hover {
            background: #fff7ed;
            border-color: var(--pr-brand);
            color: var(--pr-brand);
        }
        /* VAT selection — compact card, toggle + dropdown inline when space allows */
        .pr-vat-inline-box {
            justify-self: start;
            align-self: start;
            width: fit-content;
            max-width: min(100%, 38rem);
            padding: 0.5rem 0.75rem;
            background: #fff;
            border: 1px solid var(--pr-border);
            border-radius: 0.65rem;
            box-sizing: border-box;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
        }
        .pr-vat-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.45rem 1rem;
        }
        .pr-vat-switch-wrap .form-check {
            margin-bottom: 0;
            padding-left: 0;
        }
        .pr-vat-switch-wrap .form-check.form-switch {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 0.45rem;
            min-height: 1.85rem;
        }
        .pr-vat-switch-wrap .form-check-input {
            margin-left: 0;
            flex-shrink: 0;
            cursor: pointer;
        }
        .pr-vat-switch-wrap .form-check-label {
            margin-bottom: 0;
            text-transform: none;
            letter-spacing: normal;
            font-size: 0.9rem;
            font-weight: 600;
            color: #334155;
            line-height: 1.25;
            cursor: pointer;
        }
        .pr-vat-dropdown-wrap {
            position: relative;
            flex: 1 1 11.5rem;
            min-width: min(100%, 11rem);
            max-width: 20rem;
        }
        .pr-vat-dropdown-wrap .form-select {
            width: 100%;
            border-radius: 0.5rem;
            border-color: var(--pr-border);
            font-size: 0.8125rem;
        }
        #vat_mode_wrap:not(.pr-vat-select-hidden) {
            position: relative;
            width: 100%;
        }
        #vat_mode_wrap.pr-vat-select-hidden {
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            height: 0;
            overflow: hidden;
            opacity: 0;
            pointer-events: none;
        }
        #pr_summary_footer .pr-summary-footer-inner {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 0.85rem 1.25rem;
            align-items: start;
        }
        @media (max-width: 575.98px) {
            #pr_summary_footer .pr-summary-footer-inner {
                grid-template-columns: 1fr;
            }
            .pr-vat-toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            .pr-vat-dropdown-wrap {
                max-width: none;
            }
        }
        #pr_summary_footer .pr-summary-totals-stack {
            padding: 0.65rem 1rem 1rem 1.05rem;
            background: #fff;
            border: 1px solid var(--pr-border);
            border-radius: 0.65rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
            align-self: start;
            justify-self: end;
            width: max-content;
            max-width: 100%;
            min-width: min(100%, 14.5rem);
        }
        @media (max-width: 575.98px) {
            #pr_summary_footer .pr-summary-totals-stack {
                justify-self: stretch;
                width: 100%;
            }
        }
        .pr-summary-totals-inner {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 0.15rem;
        }
        .pr-sum-row {
            display: grid;
            grid-template-columns: auto minmax(5.85rem, max-content);
            column-gap: 0.85rem;
            align-items: baseline;
            justify-content: end;
            justify-items: end;
            font-size: 0.875rem;
            line-height: 1.35;
        }
        .pr-sum-row .pr-sum-lbl {
            color: #64748b;
            text-align: right;
            white-space: nowrap;
            justify-self: end;
        }
        .pr-sum-row .pr-sum-val {
            text-align: right;
            font-variant-numeric: tabular-nums;
            color: #475569;
            justify-self: end;
        }
        .pr-sum-row .pr-sum-val .fw-semibold {
            color: #0f172a;
        }
        #pr_summary_footer .pr-grand-total-line {
            display: grid;
            grid-template-columns: auto minmax(5.85rem, max-content);
            column-gap: 0.85rem;
            align-items: baseline;
            justify-content: end;
            justify-items: end;
            margin-top: 0.45rem;
            padding-top: 0.55rem;
            padding-bottom: 0.1rem;
            margin-bottom: 0;
            border-top: 1px dashed rgba(148, 163, 184, 0.55);
            white-space: nowrap;
        }
        #pr_summary_footer .pr-grand-total-line .pr-grand-lbl {
            font-size: 0.92rem;
            font-weight: 700;
            color: #0f172a;
            text-align: right;
            justify-self: end;
        }
        #pr_summary_footer .pr-grand-total-line .pr-grand-amount-wrap {
            text-align: right;
            justify-self: end;
            font-variant-numeric: tabular-nums;
            line-height: 1;
        }
        #pr_summary_footer .pr-grand-total-line #grand_total {
            font-size: 1.7rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            color: var(--pr-brand) !important;
            vertical-align: baseline;
        }
        #pr_summary_footer .pr-grand-total-line .pr-grand-suffix {
            font-size: 0.9rem;
            font-weight: 600;
            color: #475569;
            margin-left: 0.2rem;
            vertical-align: baseline;
        }
        /* การ์ดข้อมูลหัวฟอร์ม PR */
        .pr-card-meta-compact {
            padding: var(--space-md) var(--space-lg);
        }
    </style>
</head>
<body class="purchase-module tnc-app-body">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container pr-page pr-page--wide mt-4 mb-5" id="pr_page_root">
    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php
            $err = (string) $_GET['error'];
            if ($err === 'upload_type') {
                echo 'ชนิดไฟล์แนบไม่รองรับ กรุณาแนบ PDF หรือไฟล์รูปภาพ';
            } elseif ($err === 'upload_failed') {
                echo 'อัปโหลดไฟล์แนบไม่สำเร็จ กรุณาลองใหม่';
            } elseif ($err === 'need_site') {
                echo 'กรุณาเลือกไซต์งาน';
            } elseif ($err === 'need_cost_category') {
                echo 'กรุณาเลือกหมวดค่าใช้จ่าย (หัวข้อย่อยของไซต์) — ไม่สามารถบันทึกแบบ «ไม่ระบุหมวด» ได้';
            } elseif ($err === 'no_items') {
                echo 'กรุณาระบุอย่างน้อย 1 รายการสินค้าที่มีรายละเอียดและจำนวนมากกว่า 0 (ราคาต่อหน่วยใส่ 0 ได้หากยังไม่ทราบราคา — กรอกราคาจริงตอนสร้าง PO)';
            } elseif ($err === 'invalid_hire' || $err === 'hire_invalid') {
                echo 'กรุณากรอกข้อมูลจัดจ้างให้ครบ: ผู้รับจ้าง, รายการงาน, Overhead/Preliminary (ถ้ามี) และจำนวนงวด';
            } elseif ($err === 'hire_contractor_required') {
                echo 'กรุณาเลือกผู้รับจ้างจากทะเบียนผู้รับจ้าง (หรือเพิ่มรายชื่อใหม่ก่อน)';
            } else {
                echo 'เกิดข้อผิดพลาด กรุณาลองใหม่';
            }
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (count($sites) === 0): ?>
        <div class="alert alert-warning">ยังไม่มีข้อมูลไซต์งานในระบบ — ผู้ดูแลต้องเพิ่มที่เมนู «ไซต์งาน» ก่อนจึงจะสร้างใบขอซื้อได้</div>
    <?php endif; ?>
    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=<?= htmlspecialchars($prFormAction, ENT_QUOTES, 'UTF-8') ?>" method="POST" enctype="multipart/form-data" data-tnc-fullnav="1">
        <?php csrf_field(); ?>
        <input type="hidden" name="requested_by" value="<?= (int) $editRequestedBy ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="pr_id" value="<?= (int) $editId ?>">
        <?php endif; ?>
        <input type="hidden" name="send_line_after_save" id="send_line_after_save" value="0">
        <div class="pr-page-header d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1 class="pr-page-title mb-1" id="pr_page_title">
                    <span class="pr-page-title__icon me-2" aria-hidden="true"><i class="bi bi-cart-plus"></i></span>
                    <span id="pr_page_title_text"><?= $requestTypeVal === 'hire'
                        ? ($isEdit ? 'แก้ไขใบขอจัดจ้าง (PR)' : 'สร้างใบขอจัดจ้าง (PR)')
                        : ($isEdit ? 'แก้ไขใบขอซื้อ (PR)' : 'สร้างใบขอซื้อ (PR)') ?></span>
                </h1>
                <p class="small text-muted mb-0">กรอกข้อมูลเอกสารและรายการ แล้วกดบันทึก</p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <button type="button" class="btn btn-pr-primary" id="btnPrSaveOpenModal" <?= count($sites) === 0 ? 'disabled' : '' ?>><i class="bi bi-save me-1"></i><?= $requestTypeVal === 'hire' ? 'บันทึกใบขอจัดจ้าง' : 'บันทึกใบขอซื้อ' ?></button>
                <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-pr-cancel"><i class="bi bi-x-circle me-1"></i>ยกเลิก</a>
            </div>
        </div>

        <div class="pr-form-stack">
        <div class="pr-card pr-card-meta-compact">
            <h2 class="pr-section-head">ข้อมูลเอกสาร</h2>
            <div class="row pr-meta-grid">
                <div class="col-md-6 col-lg-4">
                    <label class="pr-field-label" for="pr_number_field">เลขที่ใบขอซื้อ</label>
                    <input type="text" name="pr_number" id="pr_number_field" class="form-control form-control-sm" value="<?= $current_pr_number ?>" readonly>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="pr-field-label" for="created_at" id="request_date_label">วันที่ขอซื้อ</label>
                    <input type="text" name="created_at" id="created_at" class="form-control form-control-sm" value="<?= htmlspecialchars($createdAtDisplay, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="pr-field-label" for="request_type">ประเภทคำขอ</label>
                    <select name="request_type" id="request_type" class="form-select form-select-sm">
                        <option value="purchase"<?= $requestTypeVal === 'purchase' ? ' selected' : '' ?>>จัดซื้อ (Purchase)</option>
                        <option value="hire"<?= $requestTypeVal === 'hire' ? ' selected' : '' ?>>จัดจ้าง (Hire)</option>
                    </select>
                    <?php if ($isEdit): ?>
                        <div class="form-text">เปลี่ยนประเภทได้ก่อนออก PO</div>
                    <?php endif; ?>
                </div>
                <?php if (count($sites) > 0): ?>
                <div class="col-md-6 col-lg-6">
                    <label class="pr-field-label" for="site_id">ไซต์งาน <span class="text-danger">*</span></label>
                    <select name="site_id" id="site_id" class="form-select form-select-sm" required>
                        <option value="" disabled<?= $editSiteId <= 0 ? ' selected' : '' ?>>— เลือกไซต์งาน —</option>
                        <?php foreach ($sites as $site): ?>
                            <?php $sid = (int) ($site['id'] ?? 0); ?>
                            <?php if ($sid <= 0) { continue; } ?>
                            <option value="<?= $sid ?>"<?= $sid === $editSiteId ? ' selected' : '' ?>><?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-6 col-lg-6">
                    <label class="pr-field-label" for="cost_category_id">หมวดค่าใช้จ่าย <span class="text-danger">*</span> <span class="text-muted small fw-normal">(หัวข้อย่อยของไซต์)</span></label>
                    <select name="cost_category_id" id="cost_category_id" class="form-select form-select-sm"<?= count($sites) > 0 ? ' required' : '' ?>>
                        <option value="" disabled<?= $editCostCategoryId <= 0 ? ' selected' : '' ?>>— เลือกหมวด —</option>
                    </select>
                    <div class="form-text">เลือกไซต์ก่อน — เพิ่มหมวดได้ที่หน้า <a href="<?= htmlspecialchars(app_path('pages/organization/sites.php'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">«ไซต์งาน»</a></div>
                </div>
                <div class="col-md-6 d-none" id="hire_field_contractor">
                    <label class="pr-field-label" for="contractor_search">ผู้รับจ้าง <span class="text-danger">*</span></label>
                    <div class="input-group input-group-sm">
                        <input type="text" id="contractor_search" class="form-control" list="contractor_list" value="<?= htmlspecialchars($hireContractorSearchEdit, ENT_QUOTES, 'UTF-8') ?>" placeholder="พิมพ์ชื่อหรือเลขบัตร แล้วเลือกจากรายการ" autocomplete="off">
                        <a href="<?= htmlspecialchars(app_path('pages/contractors/contractor-form.php'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="เพิ่มผู้รับจ้าง"><i class="bi bi-person-plus"></i></a>
                    </div>
                    <datalist id="contractor_list">
                        <?php foreach ($contractorRows as $contractorRow): ?>
                            <option value="<?= htmlspecialchars(tnc_contractor_display_label($contractorRow), ENT_QUOTES, 'UTF-8') ?>" data-id="<?= (int) ($contractorRow['id'] ?? 0) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="contractor_id" id="contractor_id" value="<?= (int) $hireContractorIdEdit ?>">
                    <div class="form-text">เลือกจาก<a href="<?= htmlspecialchars(app_path('pages/contractors/contractor-list.php'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">ทะเบียนผู้รับจ้าง</a></div>
                </div>
                <div class="col-md-6 d-none" id="hire_field_installment">
                    <label class="pr-field-label" for="installment_total">จำนวนงวดชำระ <span class="text-danger">*</span></label>
                    <input type="number" name="installment_total" id="installment_total" class="form-control form-control-sm text-end" min="1" max="120" value="<?= (int) $hireInstallEdit ?>">
                </div>
                <input type="hidden" name="contract_value" id="contract_value" value="<?= $hireValueEdit > 0 ? htmlspecialchars((string) $hireValueEdit, ENT_QUOTES, 'UTF-8') : '0' ?>">
                <div class="col-12">
                    <label class="pr-field-label" id="details_label" for="details_textarea"><?= $requestTypeVal === 'hire' ? 'เงื่อนไขการชำระเงิน / ขอบเขตการทำงาน' : 'รายละเอียด/วัตถุประสงค์' ?><?= $requestTypeVal === 'hire' ? ' <span class="text-danger">*</span>' : '' ?></label>
                    <textarea name="details" id="details_textarea" class="form-control form-control-sm" rows="<?= $requestTypeVal === 'hire' ? 4 : 2 ?>" placeholder="<?= $requestTypeVal === 'hire' ? 'ระบุเงื่อนไขการชำระเงิน และขอบเขตการทำงาน' : '' ?>"<?= $requestTypeVal === 'hire' ? ' required' : '' ?>><?= htmlspecialchars($editDetails, ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>
        </div>

        <div class="pr-card pr-table-card" id="item_table_card">
            <div id="pr_lines_wrap">
                <h2 class="pr-section-head">รายการสินค้า</h2>
                <div class="table-responsive">
            <table class="table align-middle" id="prTable">
                <thead>
                    <tr>
                        <th style="width:3rem;" class="text-center">#</th>
                        <th>รายการสินค้า</th>
                        <th style="width:7rem;" class="text-end">จำนวน</th>
                        <th style="width:6rem;" class="text-end">หน่วย</th>
                        <th style="width:8rem;" class="text-end">ราคา/หน่วย</th>
                        <th style="width:7rem;" class="text-end">ส่วนลด</th>
                        <th style="width:7rem;" class="text-end">รวม</th>
                        <th style="width:3rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($isEdit && $requestTypeVal === 'purchase' && count($editItems) > 0): ?>
                        <?php $rn = 0; ?>
                        <?php foreach ($editItems as $it): ?>
                            <?php
                            $rn++;
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
                                <td class="row-number"><?= $rn ?></td>
                                <td><input type="text" name="item_description[]" class="form-control" required value="<?= htmlspecialchars((string) ($it['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td><input type="number" name="item_qty[]" class="form-control qty text-end" step="any" min="0" required oninput="calculateTotal()" value="<?= htmlspecialchars((string) ($it['quantity'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td><input type="text" name="item_unit[]" class="form-control" value="<?= htmlspecialchars((string) ($it['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td><input type="number" name="item_price[]" class="form-control price text-end" step="any" oninput="calculateTotal()" value="<?= htmlspecialchars((string) ($it['unit_price'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td><input type="text" name="item_discount[]" class="form-control line-discount text-end" maxlength="20" oninput="calculateTotal()" value="<?= htmlspecialchars($discEdit, ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td><input type="text" class="form-control row-total text-end bg-light" value="<?= number_format((float) ($it['total'] ?? 0), 2, '.', '') ?>" readonly></td>
                                <td><button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="removeRow(this)"><i class="bi bi-trash-fill"></i></button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td class="row-number">1</td>
                        <td><input type="text" name="item_description[]" class="form-control" required></td>
                        <td><input type="number" name="item_qty[]" class="form-control qty text-end" step="any" min="0" required oninput="calculateTotal()"></td>
                        <td><input type="text" name="item_unit[]" class="form-control text-end"></td>
                        <td><input type="number" name="item_price[]" class="form-control price text-end" step="any" oninput="calculateTotal()"></td>
                        <td><input type="text" name="item_discount[]" class="form-control line-discount text-end" maxlength="20" oninput="calculateTotal()"></td>
                        <td><input type="text" class="form-control row-total text-end bg-light" value="0.00" readonly></td>
                        <td></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
                </div>
                <div class="mt-3">
                    <button type="button" class="btn btn-pr-add-row btn-sm rounded-pill px-3" onclick="addRow()">
                        <i class="bi bi-plus-circle me-1"></i>เพิ่มรายการสินค้า
                    </button>
                </div>
            </div>

            <div id="hire_lines_wrap" class="d-none hire-lines-section" data-tnc-hire-root>
                <h2 class="pr-section-head">รายการงานจัดจ้าง</h2>
                <div class="hire-table-panel">
                    <div class="table-responsive hire-table-scroll">
                    <table class="table align-middle table-hire-lines" id="hirePrTable">
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
                                <th class="hire-col-action"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($isEdit && $requestTypeVal === 'hire'): ?>
                                <?php tnc_hire_form_rows_from_items('hire', $editItems, 'pr'); ?>
                            <?php else: ?>
                                <?php tnc_hire_form_default_rows('hire', 'pr'); ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <div class="hire-lines-toolbar">
                    <button type="button" class="btn btn-pr-add-row btn-sm rounded-pill px-3" id="addHirePrGroupBtn" data-tnc-hire-add="group">
                        <i class="bi bi-folder-plus me-1"></i>เพิ่มหัวข้อหลัก
                    </button>
                    <button type="button" class="btn btn-pr-add-row btn-sm rounded-pill px-3" id="addHirePrRowBtn" data-tnc-hire-add="item">
                        <i class="bi bi-plus-circle me-1"></i>เพิ่มรายการย่อย
                    </button>
                </div>
                <div class="hire-cost-adjust-panel d-none" id="hire_cost_adjust_wrap">
                    <div class="row g-2 g-md-3">
                        <div class="col-md-6">
                            <label class="form-label" for="overhead_percent">Overhead cost (%)</label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="overhead_percent" id="overhead_percent" class="form-control text-end" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string) $hireOverheadEdit, ENT_QUOTES, 'UTF-8') ?>" oninput="calculateTotal()">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="preliminary_percent">Preliminary cost (%)</label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="preliminary_percent" id="preliminary_percent" class="form-control text-end" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string) $hirePreliminaryEdit, ENT_QUOTES, 'UTF-8') ?>" oninput="calculateTotal()">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-top" id="pr_summary_footer">
                <div class="pr-summary-footer-inner">
                    <div class="pr-vat-inline-box">
                        <div class="pr-vat-toolbar">
                            <div class="pr-vat-switch-wrap">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="vat_enabled" id="vat_enabled" value="1" onchange="calculateTotal()"<?= $editVatOn ? ' checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="vat_enabled">เลือกรูปแบบภาษีมูลค่าเพิ่ม</label>
                                </div>
                            </div>
                            <div class="pr-vat-dropdown-wrap">
                                <div id="vat_mode_wrap" class="<?= $editVatOn ? '' : 'pr-vat-select-hidden' ?>">
                                    <select class="form-select form-select-sm" name="vat_mode" id="vat_mode" onchange="calculateTotal()">
                                        <option value="exclusive"<?= $editVatMode === 'exclusive' ? ' selected' : '' ?>>แยกภาษีมูลค่าเพิ่ม</option>
                                        <option value="inclusive"<?= $editVatMode === 'inclusive' ? ' selected' : '' ?>>รวมภาษีมูลค่าเพิ่มในราคาสินค้า</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="pr-summary-totals-stack">
                        <div class="pr-summary-totals-inner">
                            <div id="hire_sum_row_direct" class="pr-sum-row d-none">
                                <span class="pr-sum-lbl">ยอดรายการ:</span>
                                <span class="pr-sum-val"><span id="hire_direct_display" class="fw-semibold text-dark">0.00</span> บาท</span>
                            </div>
                            <div id="hire_sum_row_overhead" class="pr-sum-row d-none text-secondary">
                                <span class="pr-sum-lbl">Overhead cost (<span id="overhead_pct_label">0</span>%):</span>
                                <span class="pr-sum-val">+ <span id="overhead_amount_display">0.00</span> บาท</span>
                            </div>
                            <div id="hire_sum_row_preliminary" class="pr-sum-row d-none text-secondary">
                                <span class="pr-sum-lbl">Preliminary cost (<span id="preliminary_pct_label">0</span>%):</span>
                                <span class="pr-sum-val">+ <span id="preliminary_amount_display">0.00</span> บาท</span>
                            </div>
                            <div class="pr-sum-row">
                                <span class="pr-sum-lbl"><span id="subtotal_label">ยอดรายการ:</span></span>
                                <span class="pr-sum-val"><span id="subtotal_display" class="fw-semibold text-dark">0.00</span> บาท</span>
                            </div>
                            <div id="vat_row" class="pr-sum-row<?= $editVatOn ? '' : ' d-none' ?>">
                                <span class="pr-sum-lbl"><span id="vat_label">ภาษีมูลค่าเพิ่ม:</span></span>
                                <span class="pr-sum-val"><span id="vat_prefix">+ </span><span id="vat_display">0.00</span> บาท</span>
                            </div>
                            <div class="pr-grand-total-line mb-0">
                                <span class="pr-grand-lbl"><span id="grand_total_label">ยอดรวมสุทธิ:</span></span>
                                <span class="pr-grand-amount-wrap"><span id="grand_total">0.00</span><span class="pr-grand-suffix"> บาท</span></span>
                            </div>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="total_amount" id="total_amount_input" value="0">
            </div>
        </div>
        </div><!-- /.pr-form-stack -->

    </form>

    <div class="modal fade" id="prSaveConfirmModal" tabindex="-1" aria-labelledby="prSaveConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="prSaveConfirmModalLabel">
                        <i class="bi bi-cart-check text-warning me-2"></i><?= $isEdit ? 'ยืนยันบันทึกใบขอซื้อ' : 'ยืนยันสร้างใบขอซื้อ' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body pt-2">
                    <p class="text-muted small mb-3">
                        <?= $isEdit
                            ? 'ตรวจสอบข้อมูลให้ครบก่อนกดยืนยัน ระบบจะบันทึกใบขอซื้อ (PR) ตามที่กรอก'
                            : 'ตรวจสอบข้อมูลให้ครบก่อนกดยืนยัน ระบบจะสร้างใบขอซื้อ (PR) ใหม่' ?>
                    </p>
                    <?php if ($prOfferLineOnSave): ?>
                        <div class="form-check p-3 rounded-3 border bg-light">
                            <input class="form-check-input" type="checkbox" value="1" id="prSendLineOnSaveCheck">
                            <label class="form-check-label fw-semibold" for="prSendLineOnSaveCheck">
                                <i class="bi bi-line text-success me-1"></i>ส่งไปยัง LINE ด้วย
                            </label>
                            <div class="form-text ms-4">ติ๊กเพื่อส่งคำขออนุมัติไปกลุ่ม LINE — ไม่ติ๊กจะบันทึกอย่างเดียว</div>
                        </div>
                    <?php else: ?>
                        <p class="small text-warning mb-0"><i class="bi bi-info-circle me-1"></i>ยังไม่ได้ตั้งค่า LINE ครบ — บันทึกได้แต่ส่ง LINE ไม่ได้จนกว่าจะตั้งค่าในหน้า LINE แจ้งเตือน</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="button" class="btn btn-pr-primary rounded-pill px-4 fw-semibold" id="btnPrSaveConfirm">
                        <i class="bi bi-check2-circle me-1"></i><?= $isEdit ? 'ยืนยันบันทึกใบขอซื้อ' : 'ยืนยันสร้างใบขอซื้อ' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="<?= htmlspecialchars(app_path('assets/js/hire-line-table.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
let hirePrTableApi = null;
// ฟังก์ชันเพิ่มแถวใหม่
function addRow() {
    const table = document.getElementById('prTable').getElementsByTagName('tbody')[0];
    const newRow = table.insertRow();
    const rowCount = table.rows.length;

    newRow.innerHTML = `
        <td class="row-number">${rowCount}</td>
        <td><input type="text" name="item_description[]" class="form-control" required></td>
        <td><input type="number" name="item_qty[]" class="form-control qty text-end" step="any" min="0" required oninput="calculateTotal()"></td>
        <td><input type="text" name="item_unit[]" class="form-control text-end"></td>
        <td><input type="number" name="item_price[]" class="form-control price text-end" step="any" oninput="calculateTotal()"></td>
        <td><input type="text" name="item_discount[]" class="form-control line-discount text-end" maxlength="20" oninput="calculateTotal()"></td>
        <td><input type="text" class="form-control row-total text-end bg-light" value="0.00" readonly></td>
        <td><button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="removeRow(this)"><i class="bi bi-trash-fill"></i></button></td>
    `;
}   

function prLineAmountAfterDiscount(qty, price, discRaw) {
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

// ฟังก์ชันลบแถว
function removeRow(btn) {
    const row = btn.parentNode.parentNode;
    row.parentNode.removeChild(row);
    updateRowNumbers();
    calculateTotal();
}

// ฟังก์ชันอัปเดตเลขลำดับข้อ (#)
function updateRowNumbers() {
    const rows = document.querySelectorAll('.row-number');
    rows.forEach((td, index) => {
        td.innerText = index + 1;
    });
}

// รวมยอดจากตารางจัดจ้าง (อ่านจากช่องราคารวมที่คำนวณแล้ว)
function sumHireLineSubtotal() {
    const table = document.getElementById('hirePrTable');
    if (!table) {
        return 0;
    }
    let subtotal = 0;
    table.querySelectorAll('tbody tr').forEach(function (row) {
        if (row.classList.contains('hire-row-group')) {
            return;
        }
        if (row.querySelector('.hire-line-type')?.value === 'group') {
            return;
        }
        const totalEl = row.querySelector('.hire-line-total');
        subtotal += parseFloat(String(totalEl?.value || '').replace(/,/g, '')) || 0;
    });
    return Math.round(subtotal * 100) / 100;
}

// ฟังก์ชันคำนวณเงินรวม (รองรับ VAT แยก/รวมในราคา + Overhead/Preliminary สำหรับจัดจ้าง)
function calculateTotal(hireDirectSubtotal) {
    const fmt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
    const fmtNum = (n) => n.toLocaleString(undefined, fmt);
    let lineAmount = 0;
    const prTableBody = document.getElementById('prTable')?.getElementsByTagName('tbody')[0];
    const rows = prTableBody ? prTableBody.rows : [];
    const vatOn = !!document.getElementById('vat_enabled')?.checked;
    const vatMode = document.getElementById('vat_mode')?.value || 'exclusive';
    const requestType = getPrRequestType();
    const isHire = requestType === 'hire';

    if (isHire) {
        let lineAmount = 0;
        if (typeof hireDirectSubtotal === 'number' && !Number.isNaN(hireDirectSubtotal)) {
            lineAmount = hireDirectSubtotal;
        } else {
            lineAmount = sumHireLineSubtotal();
        }
        lineAmount = Math.round(lineAmount * 100) / 100;

        const overheadPct = Math.max(0, Math.min(100, parseFloat(document.getElementById('overhead_percent')?.value || '0') || 0));
        const preliminaryPct = Math.max(0, Math.min(100, parseFloat(document.getElementById('preliminary_percent')?.value || '0') || 0));
        const overheadAmt = Math.round(lineAmount * overheadPct / 100 * 100) / 100;
        const preliminaryAmt = Math.round(lineAmount * preliminaryPct / 100 * 100) / 100;
        const excludedVat = Math.round((lineAmount + overheadAmt + preliminaryAmt) * 100) / 100;
        let vat = 0;
        let grand = excludedVat;
        if (vatOn) {
            vat = Math.round(excludedVat * 0.07 * 100) / 100;
            grand = Math.round((excludedVat + vat) * 100) / 100;
        }

        const cvEl = document.getElementById('contract_value');
        if (cvEl) {
            cvEl.value = excludedVat.toFixed(2);
        }

        const hireDirectDisplay = document.getElementById('hire_direct_display');
        if (hireDirectDisplay) {
            hireDirectDisplay.textContent = fmtNum(lineAmount);
        }
        const ohPctLabel = document.getElementById('overhead_pct_label');
        if (ohPctLabel) {
            ohPctLabel.textContent = overheadPct.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        }
        const overheadAmountDisplay = document.getElementById('overhead_amount_display');
        if (overheadAmountDisplay) {
            overheadAmountDisplay.textContent = fmtNum(overheadAmt);
        }
        const prePctLabel = document.getElementById('preliminary_pct_label');
        if (prePctLabel) {
            prePctLabel.textContent = preliminaryPct.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        }
        const preliminaryAmountDisplay = document.getElementById('preliminary_amount_display');
        if (preliminaryAmountDisplay) {
            preliminaryAmountDisplay.textContent = fmtNum(preliminaryAmt);
        }
        const subtotalLabel = document.getElementById('subtotal_label');
        if (subtotalLabel) {
            subtotalLabel.textContent = 'ราคารวมทั้งหมด (Excluded VAT):';
        }
        const subtotalDisplay = document.getElementById('subtotal_display');
        if (subtotalDisplay) {
            subtotalDisplay.textContent = fmtNum(excludedVat);
        }
        const vatLabel = document.getElementById('vat_label');
        if (vatLabel) {
            vatLabel.textContent = 'VAT 7%:';
        }
        const vatPrefix = document.getElementById('vat_prefix');
        if (vatPrefix) {
            vatPrefix.textContent = vatOn ? '+ ' : '';
        }
        const vatDisplay = document.getElementById('vat_display');
        if (vatDisplay) {
            vatDisplay.textContent = fmtNum(vat);
        }
        const grandLabel = document.getElementById('grand_total_label');
        if (grandLabel) {
            grandLabel.textContent = 'Grand Total:';
        }
        const grandTotalEl = document.getElementById('grand_total');
        if (grandTotalEl) {
            grandTotalEl.textContent = fmtNum(grand);
        }
        const totalInput = document.getElementById('total_amount_input');
        if (totalInput) {
            totalInput.value = grand.toFixed(2);
        }

        const vatRow = document.getElementById('vat_row');
        if (vatOn) {
            vatRow?.classList.remove('d-none');
        } else {
            vatRow?.classList.add('d-none');
        }
    } else {
        for (let row of rows) {
            const qtyEl = row.querySelector('.qty');
            const priceEl = row.querySelector('.price');
            const discEl = row.querySelector('.line-discount');
            const total = prLineAmountAfterDiscount(
                qtyEl ? qtyEl.value : 0,
                priceEl ? priceEl.value : 0,
                discEl ? discEl.value : ''
            );
            const totalCell = row.querySelector('.row-total');
            if (totalCell) {
                totalCell.value = total.toLocaleString(undefined, fmt);
            }
            lineAmount += total;
        }

        lineAmount = Math.round(lineAmount * 100) / 100;
        let subtotal = lineAmount;
        let vat = 0;
        let grand = lineAmount;
        if (vatOn) {
            if (vatMode === 'inclusive') {
                vat = Math.round((lineAmount * 7 / 107) * 100) / 100;
                subtotal = Math.round((lineAmount - vat) * 100) / 100;
                grand = lineAmount;
            } else {
                subtotal = lineAmount;
                vat = Math.round(subtotal * 0.07 * 100) / 100;
                grand = Math.round((subtotal + vat) * 100) / 100;
            }
        }

        document.getElementById('subtotal_label').textContent = 'ยอดรายการ:';
        document.getElementById('subtotal_display').textContent = fmtNum(subtotal);
        document.getElementById('vat_label').textContent = 'ภาษีมูลค่าเพิ่ม:';
        document.getElementById('vat_prefix').textContent = '';
        document.getElementById('vat_display').textContent = fmtNum(vat);
        document.getElementById('grand_total_label').textContent = 'ยอดรวมสุทธิ:';
        document.getElementById('grand_total').textContent = fmtNum(grand);
        document.getElementById('total_amount_input').value = grand.toFixed(2);

        const vatRow = document.getElementById('vat_row');
        if (vatOn) {
            vatRow?.classList.remove('d-none');
        } else {
            vatRow?.classList.add('d-none');
        }
    }

    const vatModeWrap = document.getElementById('vat_mode_wrap');
    if (vatModeWrap) {
        vatModeWrap.classList.toggle('pr-vat-select-hidden', !vatOn || isHire);
    }
    const vatModeEl = document.getElementById('vat_mode');
    if (vatModeEl) {
        vatModeEl.tabIndex = vatOn && !isHire ? 0 : -1;
    }
}

function getPrRequestType() {
    const sel = document.getElementById('request_type');
    if (!sel) {
        return 'purchase';
    }
    return sel.value === 'hire' ? 'hire' : 'purchase';
}

function toggleRequestTypeFields() {
    const requestTypeEl = document.getElementById('request_type');
    const hireFieldContractor = document.getElementById('hire_field_contractor');
    const hireFieldInstallment = document.getElementById('hire_field_installment');
    const hireLinesWrap = document.getElementById('hire_lines_wrap');
    const hireCostAdjustWrap = document.getElementById('hire_cost_adjust_wrap');
    const contractorSearch = document.getElementById('contractor_search');
    const contractorIdInput = document.getElementById('contractor_id');
    const installmentTotal = document.getElementById('installment_total');
    const itemTableCard = document.getElementById('item_table_card');
    const prLinesWrap = document.getElementById('pr_lines_wrap');
    const detailsLabel = document.getElementById('details_label');
    const detailsTextarea = document.getElementById('details_textarea');
    const requestDateLabel = document.getElementById('request_date_label');
    if (!requestTypeEl) {
        return;
    }
    const isHire = getPrRequestType() === 'hire';
    const isEditMode = <?= $isEdit ? 'true' : 'false' ?>;

    const pageTitleText = document.getElementById('pr_page_title_text');
    const saveBtn = document.getElementById('btnPrSaveOpenModal');
    if (pageTitleText) {
        pageTitleText.textContent = isHire
            ? (isEditMode ? 'แก้ไขใบขอจัดจ้าง (PR)' : 'สร้างใบขอจัดจ้าง (PR)')
            : (isEditMode ? 'แก้ไขใบขอซื้อ (PR)' : 'สร้างใบขอซื้อ (PR)');
    }
    if (saveBtn) {
        saveBtn.innerHTML = isHire
            ? '<i class="bi bi-save me-1"></i>บันทึกใบขอจัดจ้าง'
            : '<i class="bi bi-save me-1"></i>บันทึกใบขอซื้อ';
    }

    if (hireFieldContractor) {
        hireFieldContractor.classList.toggle('d-none', !isHire);
    }
    if (hireFieldInstallment) {
        hireFieldInstallment.classList.toggle('d-none', !isHire);
    }
    if (hireLinesWrap) {
        hireLinesWrap.classList.toggle('d-none', !isHire);
    }
    if (hireCostAdjustWrap) {
        hireCostAdjustWrap.classList.toggle('d-none', !isHire);
    }
    ['hire_sum_row_direct', 'hire_sum_row_overhead', 'hire_sum_row_preliminary'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) {
            el.classList.toggle('d-none', !isHire);
        }
    });
    if (prLinesWrap) {
        prLinesWrap.classList.toggle('d-none', isHire);
    }
    if (contractorSearch) {
        contractorSearch.required = isHire;
    }
    if (installmentTotal) {
        installmentTotal.required = isHire;
    }
    if (detailsLabel) {
        if (isHire) {
            detailsLabel.innerHTML = 'เงื่อนไขการชำระเงิน / ขอบเขตการทำงาน <span class="text-danger">*</span>';
        } else {
            detailsLabel.textContent = 'รายละเอียด/วัตถุประสงค์';
        }
    }
    if (detailsTextarea) {
        detailsTextarea.required = isHire;
        detailsTextarea.rows = isHire ? 4 : 2;
        detailsTextarea.placeholder = isHire ? 'ระบุเงื่อนไขการชำระเงิน และขอบเขตการทำงาน' : '';
    }
    if (requestDateLabel) {
        requestDateLabel.textContent = isHire ? 'วันที่จัดจ้าง' : 'วันที่ขอซื้อ';
    }

    calculateTotal();

    if (!itemTableCard) {
        setHireLineInputsEnabled(isHire);
        if (isHire) {
            initHirePrTable();
        }
        return;
    }
    const tableInputs = itemTableCard.querySelectorAll('input[name="item_description[]"], input[name="item_qty[]"], input[name="item_price[]"]');
    tableInputs.forEach((input) => {
        input.required = !isHire;
        input.disabled = isHire;
    });
    itemTableCard.querySelectorAll('input[name="item_discount[]"]').forEach((input) => {
        input.required = false;
        input.disabled = isHire;
    });
    const optionalInputs = itemTableCard.querySelectorAll('input[name="item_unit[]"]');
    optionalInputs.forEach((input) => {
        input.disabled = isHire;
    });
    setHireLineInputsEnabled(isHire);
    if (isHire) {
        initHirePrTable();
    }
}

function setHireLineInputsEnabled(isHire) {
    const hireLinesWrap = document.getElementById('hire_lines_wrap');
    if (!hireLinesWrap) {
        return;
    }
    hireLinesWrap.querySelectorAll('.hire-desc, .hire-desc-group, .hire-qty').forEach((input) => {
        input.required = isHire;
        input.disabled = !isHire;
    });
    hireLinesWrap.querySelectorAll('.hire-unit, .hire-material, .hire-labor').forEach((input) => {
        input.disabled = !isHire;
    });
    hireLinesWrap.querySelectorAll('.hire-remove-row').forEach((btn) => {
        if (!isHire) {
            btn.disabled = true;
        }
    });
    hireLinesWrap.querySelectorAll('[data-tnc-hire-add]').forEach((btn) => {
        btn.disabled = !isHire;
    });
}

function initHirePrTable() {
    const hireTable = document.getElementById('hirePrTable');
    if (!hireTable || !window.TncHireLineTable) {
        return;
    }
    const addGroupBtn = document.getElementById('addHirePrGroupBtn');
    const addRowBtn = document.getElementById('addHirePrRowBtn');
    if (!hirePrTableApi) {
        hirePrTableApi = TncHireLineTable.bindTable(hireTable, {
            fieldPrefix: 'hire',
            addGroupButton: addGroupBtn,
            addItemButton: addRowBtn,
            onSubtotal: function (subtotal) {
                calculateTotal(subtotal);
            },
        });
        return;
    }
    hirePrTableApi.recalc();
}

(function () {
    const dateInput = document.getElementById('created_at');
    if (!dateInput) return;

    if (typeof flatpickr === 'function') {
        flatpickr(dateInput, {
            dateFormat: 'd/m/Y',
            defaultDate: dateInput.value || 'today',
            allowInput: true,
        });
    }

    // ตรวจรูปแบบวันที่ (ไม่แก้ค่าในช่อง) — ใช้ก่อนเปิด modal
    function validatePrCreatedDate() {
        if (!dateInput) return true;
        const raw = (dateInput.value || '').trim();
        if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
            return true;
        }
        const m = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (!m) {
            alert('กรุณากรอกวันที่เป็นรูปแบบ วัน/เดือน/ปี เช่น 25/04/2026');
            dateInput.focus();
            return false;
        }
        const dd = Number(m[1]);
        const mm = Number(m[2]);
        const yyyy = Number(m[3]);
        const d = new Date(yyyy, mm - 1, dd);
        if (d.getFullYear() !== yyyy || d.getMonth() !== (mm - 1) || d.getDate() !== dd) {
            alert('วันที่ไม่ถูกต้อง กรุณาตรวจสอบใหม่');
            dateInput.focus();
            return false;
        }
        return true;
    }

    // แปลงวันที่เป็น Y-m-d ก่อน submit จริง
    function normalizePrCreatedDate() {
        if (!dateInput) return;
        const raw = (dateInput.value || '').trim();
        const m = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (!m) return;
        const dd = Number(m[1]);
        const mm = Number(m[2]);
        const yyyy = Number(m[3]);
        dateInput.value = `${String(yyyy)}-${String(mm).padStart(2, '0')}-${String(dd).padStart(2, '0')}`;
    }

    const form = dateInput ? dateInput.closest('form') : null;
    const saveModalEl = document.getElementById('prSaveConfirmModal');
    const saveModal = saveModalEl && typeof bootstrap !== 'undefined' ? new bootstrap.Modal(saveModalEl) : null;
    const sendLineHidden = document.getElementById('send_line_after_save');
    const lineCheck = document.getElementById('prSendLineOnSaveCheck');

    document.getElementById('btnPrSaveOpenModal')?.addEventListener('click', function () {
        // ตรวจความถูกต้องของฟอร์ม "ก่อน" เปิด modal เพื่อให้ข้อความเตือนของเบราว์เซอร์
        // แสดงบนช่องที่ผิดได้จริง (ไม่ถูก modal บัง)
        if (!form || !validatePrCreatedDate()) {
            return;
        }
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
            return;
        }
        if (lineCheck) {
            lineCheck.checked = false;
        }
        saveModal?.show();
    });

    document.getElementById('btnPrSaveConfirm')?.addEventListener('click', function () {
        if (!form) {
            return;
        }
        normalizePrCreatedDate();
        if (sendLineHidden) {
            sendLineHidden.value = lineCheck && lineCheck.checked ? '1' : '0';
        }
        saveModal?.hide();
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    });
})();

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('request_type')?.addEventListener('change', toggleRequestTypeFields);
    toggleRequestTypeFields();
    initHirePrTable();
    calculateTotal();
});

// เติมตัวเลือก "หมวดค่าใช้จ่าย" ตามไซต์ที่เลือก (หมวดกลาง + หมวดเฉพาะไซต์)
(function () {
    var catMap = <?= json_encode($siteCategoryMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    var selectedCatId = <?= (int) $editCostCategoryId ?>;
    var siteEl = document.getElementById('site_id');
    var catEl = document.getElementById('cost_category_id');
    if (!catEl) return;

    function populateCategories() {
        var siteId = siteEl ? parseInt(siteEl.value || '0', 10) || 0 : 0;
        var prev = parseInt(catEl.value || '0', 10) || selectedCatId || 0;
        catEl.innerHTML = '';
        if (siteId <= 0) {
            catEl.disabled = true;
            catEl.innerHTML = '<option value="" disabled selected>— เลือกไซต์ก่อน —</option>';
            return;
        }
        catEl.disabled = false;
        var list = catMap[siteId] || catMap[0] || [];
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.disabled = true;
        placeholder.textContent = '— เลือกหมวด —';
        catEl.appendChild(placeholder);
        var hasPrev = false;
        list.forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name;
            if (c.id === prev) {
                opt.selected = true;
                hasPrev = true;
            }
            catEl.appendChild(opt);
        });
        if (!hasPrev) {
            placeholder.selected = true;
        }
        selectedCatId = 0;
    }

    if (siteEl) {
        siteEl.addEventListener('change', populateCategories);
    }
    document.addEventListener('DOMContentLoaded', populateCategories);
    populateCategories();
})();

(function () {
    const searchInput = document.getElementById('contractor_search');
    const contractorIdInput = document.getElementById('contractor_id');
    const datalist = document.getElementById('contractor_list');
    if (!searchInput || !contractorIdInput || !datalist) {
        return;
    }

    function syncContractorId() {
        const typed = (searchInput.value || '').trim();
        if (typed === '') {
            contractorIdInput.value = '';
            return;
        }
        let matchedId = '';
        datalist.querySelectorAll('option').forEach((opt) => {
            const optValue = (opt.value || '').trim();
            if (matchedId === '' && optValue.toLowerCase() === typed.toLowerCase()) {
                matchedId = (opt.getAttribute('data-id') || '').trim();
            }
        });
        contractorIdInput.value = matchedId;
    }

    searchInput.addEventListener('input', syncContractorId);
    searchInput.addEventListener('change', syncContractorId);

    const form = searchInput.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            syncContractorId();
        });
    }
})();

</script>
</body>
</html>