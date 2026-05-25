<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/line_notify_runtime.php';

$prOfferLineOnSave = line_effective_channel_access_token() !== '' && line_effective_target_group_id() !== '';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$uid = (int) $_SESSION['user_id'];

$editId = (int) ($_GET['id'] ?? 0);
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
$hireValueEdit = $isEdit ? (float) ($editPr['contract_value'] ?? ($editPr['hire_total_value'] ?? 0)) : 0.0;
$hireInstallEdit = $isEdit ? (int) ($editPr['installment_total'] ?? ($editPr['hire_installment_count'] ?? 1)) : 1;
if ($hireInstallEdit < 1) {
    $hireInstallEdit = 1;
}

$sites = Db::tableRows('sites');
usort($sites, static function (array $a, array $b): int {
    $sort = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
    if ($sort !== 0) {
        return $sort;
    }

    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title><?= $isEdit ? 'แก้ไขใบขอซื้อ (PR)' : 'สร้างใบขอซื้อ (PR)' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --pr-brand: #fd7e14;
            --pr-brand-hover: #e86c00;
            --pr-surface: #f4f6f9;
            --pr-card-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
            --pr-border: #e8ecf1;
        }
        body {
            background: var(--pr-surface);
            font-family: 'Sarabun', sans-serif;
            color: #1e293b;
        }
        .pr-page { max-width: 1200px; }
        .pr-page-title {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: #0f172a;
        }
        .pr-card {
            background: #fff;
            border: 1px solid var(--pr-border);
            border-radius: 1rem;
            box-shadow: var(--pr-card-shadow);
            padding: 1.5rem 1.75rem;
        }
        .pr-card + .pr-card { margin-top: 1.25rem; }
        .pr-field-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 0.4rem;
        }
        .pr-doc-row .form-control {
            border-radius: 0.5rem;
            border-color: var(--pr-border);
            padding: 0.65rem 0.85rem;
        }
        .pr-doc-row .form-control:focus {
            border-color: var(--pr-brand);
            box-shadow: 0 0 0 0.2rem rgba(253, 126, 20, 0.15);
        }
        .pr-doc-row .form-control[readonly] {
            background: #f8fafc;
            font-weight: 600;
            color: var(--pr-brand);
        }
        .pr-sidebar-card {
            background: #fff;
            border: none;
            border-left: 4px solid var(--pr-brand);
            border-radius: 1rem;
            box-shadow: var(--pr-card-shadow);
            padding: 1.5rem 1.75rem;
            height: 100%;
        }
        .pr-sidebar-card .form-label,
        .pr-sidebar-card label {
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .pr-sidebar-card hr {
            border-color: var(--pr-border);
            margin: 1rem 0;
        }
        .pr-sidebar-card .form-check-label {
            color: #334155;
            text-transform: none;
            letter-spacing: normal;
            font-size: 0.95rem;
        }
        .pr-sidebar-card .form-select {
            border-radius: 0.5rem;
            border-color: var(--pr-border);
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
        /* การ์ดข้อมูลหัวฟอร์ม PR — กะทัดรัด */
        .pr-card-meta-compact {
            padding: 1rem 1.2rem;
            border-radius: 0.85rem;
        }
        .pr-card-meta-compact .row {
            --bs-gutter-y: 0.65rem;
            --bs-gutter-x: 0.75rem;
        }
        .pr-card-meta-compact .pr-field-label {
            font-size: 0.72rem;
            margin-bottom: 0.28rem;
        }
        .pr-card-meta-compact .form-select,
        .pr-card-meta-compact .form-control {
            font-size: 0.875rem;
            padding: 0.4rem 0.65rem;
        }
        .pr-card-meta-compact .form-text {
            font-size: 0.72rem;
            margin-top: 0.25rem;
            line-height: 1.35;
        }
        .pr-card-meta-compact textarea.form-control {
            min-height: 3.25rem;
        }
        .pr-card-meta-compact .form-check {
            margin-bottom: 0.25rem;
        }
        .pr-card-meta-compact #hire_fields_wrap .row {
            --bs-gutter-y: 0.5rem;
        }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container pr-page mt-4 mb-5 py-2">
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
            } elseif ($err === 'no_items') {
                echo 'กรุณาระบุอย่างน้อย 1 รายการสินค้าที่มีรายละเอียดและจำนวนมากกว่า 0 (ราคาต่อหน่วยใส่ 0 ได้หากยังไม่ทราบราคา — กรอกราคาจริงตอนสร้าง PO)';
            } elseif ($err === 'invalid_hire' || $err === 'hire_invalid') {
                echo 'กรุณากรอกข้อมูลจัดจ้างให้ครบ: ผู้รับจ้าง, มูลค่าสัญญา และจำนวนงวด';
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
            <input type="hidden" name="request_type" value="<?= htmlspecialchars($requestTypeVal, ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <input type="hidden" name="send_line_after_save" id="send_line_after_save" value="0">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 pb-1">
            <h1 class="pr-page-title mb-0"><i class="bi bi-cart-plus-fill text-warning me-2"></i><?= $isEdit ? 'แก้ไขใบขอซื้อ (PR)' : 'สร้างใบขอซื้อ (PR)' ?></h1>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-pr-primary" id="btnPrSaveOpenModal" <?= count($sites) === 0 ? 'disabled' : '' ?>><i class="bi bi-save me-1"></i>บันทึกใบขอซื้อ</button>
                <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-danger rounded-pill fw-semibold px-4"><i class="bi bi-x-circle me-1"></i>ยกเลิก</a>
            </div>
        </div>

        <div class="pr-card pr-doc-row mb-4">
            <div class="row g-4">
                <div class="col-md-6">
                    <label class="pr-field-label">เลขที่ใบขอซื้อ</label>
                    <input type="text" name="pr_number" class="form-control" value="<?= $current_pr_number ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="pr-field-label" id="request_date_label">วันที่ขอซื้อ</label>
                    <input type="text" name="created_at" id="created_at" class="form-control" value="<?= htmlspecialchars($createdAtDisplay, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12">
                <div class="pr-card pr-card-meta-compact h-100">
                    <div class="row g-2 g-md-3">
                        <div class="col-md-6">
                            <label class="pr-field-label">ประเภทคำขอ</label>
                            <select name="request_type" id="request_type" class="form-select form-select-sm" onchange="toggleRequestTypeFields()"<?= $isEdit ? ' disabled' : '' ?>>
                                <option value="purchase"<?= $requestTypeVal === 'purchase' ? ' selected' : '' ?>>จัดซื้อ (Purchase)</option>
                                <option value="hire"<?= $requestTypeVal === 'hire' ? ' selected' : '' ?>>จัดจ้าง (Hire)</option>
                            </select>
                            <?php if ($isEdit): ?>
                                <div class="form-text">ไม่สามารถเปลี่ยนประเภทหลังสร้างแล้ว — หากต้องการประเภทอื่นให้สร้างใบ PR ใหม่</div>
                            <?php endif; ?>
                        </div>
                        <?php if (count($sites) > 0): ?>
                        <div class="col-md-6">
                            <label class="pr-field-label">ไซต์งาน <span class="text-danger">*</span></label>
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
                        <div class="col-12 d-none" id="hire_fields_wrap">
                            <div class="row g-2 g-md-3">
                                <div class="col-md-4">
                                    <label class="pr-field-label">ผู้รับจ้าง</label>
                                    <input type="text" name="contractor_name" id="contractor_name" class="form-control form-control-sm" maxlength="255" value="<?= htmlspecialchars($hireContractorEdit, ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="pr-field-label">มูลค่าสัญญา (บาท)</label>
                                    <input type="number" name="contract_value" id="contract_value" class="form-control form-control-sm" step="any" min="0" value="<?= $hireValueEdit > 0 ? htmlspecialchars((string) $hireValueEdit, ENT_QUOTES, 'UTF-8') : '' ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="pr-field-label">จำนวนงวด</label>
                                    <input type="number" name="installment_total" id="installment_total" class="form-control form-control-sm" min="1" max="120" value="<?= (int) $hireInstallEdit ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="pr-field-label" id="details_label">รายละเอียด/วัตถุประสงค์</label>
                            <textarea name="details" id="details_textarea" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($editDetails, ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="pr-card pr-table-card" id="item_table_card">
            <div id="pr_lines_wrap">
                <h2 class="h6 fw-bold text-secondary mb-3 text-uppercase" style="letter-spacing:0.05em;">รายการสินค้า</h2>
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
                                <td><input type="number" name="item_price[]" class="form-control price text-end" step="any" min="0" oninput="calculateTotal()" value="<?= htmlspecialchars((string) ($it['unit_price'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"></td>
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
                        <td><input type="number" name="item_price[]" class="form-control price text-end" step="any" min="0" oninput="calculateTotal()"></td>
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
                            <div class="pr-sum-row">
                                <span class="pr-sum-lbl"><span id="subtotal_label">ยอดรายการ:</span></span>
                                <span class="pr-sum-val"><span id="subtotal_display" class="fw-semibold text-dark">0.00</span> บาท</span>
                            </div>
                            <div id="vat_row" class="pr-sum-row<?= $editVatOn ? '' : ' d-none' ?>">
                                <span class="pr-sum-lbl">ภาษีมูลค่าเพิ่ม:</span>
                                <span class="pr-sum-val"><span id="vat_display">0.00</span> บาท</span>
                            </div>
                            <div class="pr-grand-total-line mb-0">
                                <span class="pr-grand-lbl">ยอดรวมสุทธิ:</span>
                                <span class="pr-grand-amount-wrap"><span id="grand_total">0.00</span><span class="pr-grand-suffix"> บาท</span></span>
                            </div>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="total_amount" id="total_amount_input" value="0">
            </div>
        </div>

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
<script>
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
        <td><input type="number" name="item_price[]" class="form-control price text-end" step="any" min="0" oninput="calculateTotal()"></td>
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
    if (dRaw !== '') {
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

// ฟังก์ชันคำนวณเงินรวม (รองรับ VAT แยก/รวมในราคา)
function calculateTotal() {
    let lineAmount = 0;
    const rows = document.getElementById('prTable').getElementsByTagName('tbody')[0].rows;
    const vatOn = document.getElementById('vat_enabled').checked;
    const vatMode = document.getElementById('vat_mode')?.value || 'exclusive';
    const requestType = (document.getElementById('request_type')?.value || 'purchase');

    if (requestType === 'hire') {
        const contractValue = parseFloat(document.getElementById('contract_value')?.value || '0') || 0;
        lineAmount = Math.max(0, contractValue);
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
            row.querySelector('.row-total').value = total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            lineAmount += total;
        }
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

    document.getElementById('subtotal_display').innerText = subtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const vatRow = document.getElementById('vat_row');
    if (vatOn) {
        if (vatRow) {
            vatRow.classList.remove('d-none');
        }
        document.getElementById('vat_display').innerText = vat.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        if (vatRow) {
            vatRow.classList.add('d-none');
        }
    }
    document.getElementById('grand_total').innerText = grand.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('total_amount_input').value = grand.toFixed(2);

    const vatModeWrap = document.getElementById('vat_mode_wrap');
    if (vatModeWrap) {
        vatModeWrap.classList.toggle('pr-vat-select-hidden', !vatOn);
    }
    const vatModeEl = document.getElementById('vat_mode');
    if (vatModeEl) {
        vatModeEl.tabIndex = vatOn ? 0 : -1;
    }
    const vatHelpText = document.getElementById('vat_help_text');
}

function toggleRequestTypeFields() {
    const requestTypeEl = document.getElementById('request_type');
    const hireWrap = document.getElementById('hire_fields_wrap');
    const contractorName = document.getElementById('contractor_name');
    const contractValue = document.getElementById('contract_value');
    const installmentTotal = document.getElementById('installment_total');
    const itemTableCard = document.getElementById('item_table_card');
    const prLinesWrap = document.getElementById('pr_lines_wrap');
    const detailsLabel = document.getElementById('details_label');
    const detailsTextarea = document.getElementById('details_textarea');
    const requestDateLabel = document.getElementById('request_date_label');
    if (!requestTypeEl || !hireWrap || !contractorName || !contractValue || !installmentTotal || !itemTableCard || !detailsLabel || !detailsTextarea || !requestDateLabel) {
        return;
    }
    const isHire = requestTypeEl.value === 'hire';
    hireWrap.classList.toggle('d-none', !isHire);
    if (prLinesWrap) {
        prLinesWrap.classList.toggle('d-none', isHire);
    }
    contractorName.required = isHire;
    contractValue.required = isHire;
    installmentTotal.required = isHire;
    detailsLabel.textContent = isHire ? 'รายละเอียดการจ้าง' : 'หมายเหตุ';
    requestDateLabel.textContent = isHire ? 'วันที่จัดจ้าง' : 'วันที่ขอซื้อ';

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

}

document.getElementById('request_type')?.addEventListener('change', function () {
    toggleRequestTypeFields();
    calculateTotal();
});

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

    function normalizePrCreatedDate() {
        if (!dateInput) return true;
        const raw = (dateInput.value || '').trim();
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
        dateInput.value = `${String(yyyy)}-${String(mm).padStart(2, '0')}-${String(dd).padStart(2, '0')}`;
        return true;
    }

    const form = dateInput ? dateInput.closest('form') : null;
    const saveModalEl = document.getElementById('prSaveConfirmModal');
    const saveModal = saveModalEl && typeof bootstrap !== 'undefined' ? new bootstrap.Modal(saveModalEl) : null;
    const sendLineHidden = document.getElementById('send_line_after_save');
    const lineCheck = document.getElementById('prSendLineOnSaveCheck');

    document.getElementById('btnPrSaveOpenModal')?.addEventListener('click', function () {
        if (lineCheck) {
            lineCheck.checked = false;
        }
        saveModal?.show();
    });

    document.getElementById('btnPrSaveConfirm')?.addEventListener('click', function () {
        if (!form || !normalizePrCreatedDate()) {
            return;
        }
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
            saveModal?.hide();
            return;
        }
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

document.addEventListener('DOMContentLoaded', calculateTotal);
document.addEventListener('DOMContentLoaded', toggleRequestTypeFields);

</script>
</body>
</html>