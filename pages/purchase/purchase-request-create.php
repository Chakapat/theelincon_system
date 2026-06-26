<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/line_pr_approval.php';
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
    if (!line_pr_user_can_edit($editPr, false)) {
        header('Location: ' . app_path('pages/purchase/purchase-request-view.php') . '?id=' . $editId . '&error=pr_approved_locked');
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
$editSiteId = $isEdit ? (int) ($editPr['site_id'] ?? 0) : (int) ($_GET['site_id'] ?? 0);
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

$siteLockedFromHub = false;
$lockedSiteHubUrl = '';
$hubSiteIdParam = !$isEdit ? (int) ($_GET['site_id'] ?? 0) : 0;
if ($hubSiteIdParam > 0 && !$isEdit) {
    foreach ($sites as $siteRowCheck) {
        if ((int) ($siteRowCheck['id'] ?? 0) === $hubSiteIdParam) {
            $siteLockedFromHub = true;
            $editSiteId = $hubSiteIdParam;
            $lockedSiteHubUrl = app_path('pages/sites/site-hub.php?site_id=' . $hubSiteIdParam);
            break;
        }
    }
}
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
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/purchase-ui.css') . '?v=' . (@filemtime(dirname(__DIR__, 2) . '/assets/css/purchase-ui.css') ?: time()), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/hire-line-table.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/pr-hire-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        .pr-create-wrap { max-width: 1100px; }
        .card-soft { border: 1px solid rgba(226, 232, 240, 0.95); border-radius: var(--tnc-radius-lg); box-shadow: var(--tnc-shadow-sm); background: #fff; }
        .po-field-label { font-size: 0.78rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.35rem; }
        .po-section-head { display: flex; align-items: flex-start; gap: 0.75rem; margin-bottom: 1rem; padding-bottom: 0.65rem; border-bottom: 1px solid #eef2f7; }
        .section-title { font-size: 1.05rem; font-weight: 800; color: var(--tnc-ink); margin: 0; }
        .po-table-wrap { border: 1px solid #e8ecf1; border-radius: 0.75rem; overflow: hidden; background: #fff; }
        .po-table-wrap thead th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; font-weight: 700; background: #f1f5f9 !important; }
        .po-actions-bar { margin-top: 0.85rem; padding-top: 0.85rem; border-top: 1px solid #eef2f7; }
        .summary-box { background: linear-gradient(180deg, #fffbf5 0%, var(--tnc-orange-soft) 100%); border: 1px solid var(--tnc-orange-border); border-radius: 0.85rem; padding: 1rem 1.05rem; }
        @media (min-width: 992px) { .pr-summary-sticky { position: sticky; top: 5.5rem; } }
        .summary-line { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; margin-bottom: 8px; }
        .summary-grand { padding-top: 0.35rem; margin-top: 0.2rem; border-top: 2px dashed rgba(253, 126, 20, 0.25); }
        .po-vat-panel { background: #fffbf5; border: 1px solid var(--tnc-orange-border); border-radius: 0.75rem; padding: 0.85rem; }
        #prTable .row-total { background: #f8fafc !important; font-weight: 600; }
        #prTable .row-number { color: #94a3b8; font-weight: 600; font-size: 0.82rem; text-align: center; }
        .btn-pr-add-row {
            border-color: #e2e8f0;
            color: #475569;
            font-weight: 500;
        }
        .btn-pr-add-row:hover {
            background: #fff7ed;
            border-color: var(--tnc-orange);
            color: var(--tnc-orange);
        }
        .site-field-locked .form-select:disabled {
            background-color: #f8fafc;
            color: #334155;
            cursor: not-allowed;
            opacity: 1;
        }
        .pr-vat-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.45rem 0.85rem;
        }
        .pr-vat-switch-wrap .form-check { margin-bottom: 0; }
        .pr-vat-dropdown-wrap {
            position: relative;
            flex: 1 1 11rem;
            min-width: min(100%, 10.5rem);
            max-width: 18rem;
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
        @media (max-width: 575.98px) {
            .pr-vat-toolbar { flex-direction: column; align-items: stretch; }
            .pr-vat-dropdown-wrap { max-width: none; }
        }
        .pr-create-wrap .po-submit-panel--end {
            display: flex !important;
            justify-content: flex-end !important;
            align-items: center;
            width: 100%;
        }
        .pr-create-wrap .po-submit-panel--end .po-submit-btn {
            margin-left: auto;
        }
        @media (max-width: 991.98px) {
            .pr-create-wrap .po-submit-panel--end {
                justify-content: center !important;
            }
            .pr-create-wrap .po-submit-panel--end .po-submit-btn {
                margin-left: 0;
                width: 100%;
            }
        }
    </style>
    <?php
    $poLineMobileCss = dirname(__DIR__, 2) . '/assets/css/po-line-table-mobile.css';
    $poLineMobileVer = @filemtime($poLineMobileCss);
    if (!is_int($poLineMobileVer) || $poLineMobileVer <= 0) {
        $poLineMobileVer = time();
    }
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/po-line-table-mobile.css') . '?v=' . $poLineMobileVer, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="purchase-module tnc-app-body tnc-layout-form">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container container-lg py-4 py-md-5 mb-5 pr-create-wrap" id="pr_page_root">
    <?php include dirname(__DIR__, 2) . '/components/purchase-subnav.php'; ?>
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
                echo 'กรุณาระบุอย่างน้อย 1 รายการที่มีรายละเอียดและจำนวนมากกว่า 0 — ราคา/หน่วยไม่บังคับ (กรอกทีหลังตอนออก PO ได้)';
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
        <header class="po-create-hero p-4 p-md-4 mb-4">
            <div class="row align-items-center g-3">
                <div class="col-lg">
                    <p class="purchase-page-kicker mb-1">Purchase Module</p>
                    <h1 class="h3 mb-0 fw-bold" id="pr_page_title">
                        <span id="pr_page_title_text"><?= $requestTypeVal === 'hire'
                            ? ($isEdit ? 'แก้ไขใบขอจัดจ้าง (PR)' : 'สร้างใบขอจัดจ้าง (PR)')
                            : ($isEdit ? 'แก้ไขใบขอซื้อ (PR)' : 'สร้างใบขอซื้อ (PR)') ?></span>
                    </h1>
                </div>
                <div class="col-lg-auto d-flex flex-wrap gap-2 justify-content-lg-end">
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light rounded-pill px-4 shadow-sm"><i class="bi bi-arrow-left me-1"></i>กลับรายการ PR</a>
                </div>
            </div>
        </header>

        <div class="card card-soft p-4 p-md-4 mb-4">
            <div class="row g-3 g-md-4">
                <div class="col-md-4">
                    <label class="po-field-label" for="pr_number_field">เลขที่ใบขอซื้อ</label>
                    <input type="text" name="pr_number" id="pr_number_field" class="form-control bg-light text-tnc-orange fw-bold" value="<?= $current_pr_number ?>" readonly>
                </div>
                <div class="col-md-4">
                    <label class="po-field-label" for="created_at" id="request_date_label">วันที่ขอซื้อ</label>
                    <input type="text" name="created_at" id="created_at" class="form-control" value="<?= htmlspecialchars($createdAtDisplay, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="po-field-label" for="request_type">ประเภทคำขอ</label>
                    <select name="request_type" id="request_type" class="form-select">
                        <option value="purchase"<?= $requestTypeVal === 'purchase' ? ' selected' : '' ?>>จัดซื้อ (Purchase)</option>
                        <option value="hire"<?= $requestTypeVal === 'hire' ? ' selected' : '' ?>>จัดจ้าง (Hire)</option>
                    </select>
                    <?php if ($isEdit): ?>
                        <div class="form-text">เปลี่ยนประเภทได้ก่อนออก PO</div>
                    <?php endif; ?>
                </div>
                <?php if (count($sites) > 0): ?>
                <div class="col-md-6<?= $siteLockedFromHub ? ' site-field-locked' : '' ?>">
                    <label class="po-field-label" for="site_id">ไซต์งาน <span class="text-danger">*</span></label>
                    <select id="site_id" class="form-select"<?= $siteLockedFromHub ? ' disabled' : ' name="site_id" required' ?>>
                        <option value="" disabled<?= $editSiteId <= 0 ? ' selected' : '' ?>>— เลือกไซต์งาน —</option>
                        <?php foreach ($sites as $site): ?>
                            <?php $sid = (int) ($site['id'] ?? 0); ?>
                            <?php if ($sid <= 0) { continue; } ?>
                            <option value="<?= $sid ?>"<?= $sid === $editSiteId ? ' selected' : '' ?>><?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($siteLockedFromHub): ?>
                        <input type="hidden" name="site_id" value="<?= $editSiteId ?>">
                        <div class="form-text"><i class="bi bi-lock-fill me-1"></i>ล็อกจาก Site Hub — <a href="<?= htmlspecialchars($lockedSiteHubUrl, ENT_QUOTES, 'UTF-8') ?>">กลับเมนูไซต์</a></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="col-md-6">
                    <label class="po-field-label" for="cost_category_id">หมวดค่าใช้จ่าย <span class="text-danger">*</span> <span class="text-muted small fw-normal">(หัวข้อย่อยของไซต์)</span></label>
                    <select name="cost_category_id" id="cost_category_id" class="form-select"<?= count($sites) > 0 ? ' required' : '' ?>>
                        <option value="" disabled<?= $editCostCategoryId <= 0 ? ' selected' : '' ?>>— เลือกหมวด —</option>
                    </select>
                    <div class="form-text">เลือกไซต์ก่อน — เพิ่มหมวดได้ที่ <a href="<?= htmlspecialchars(app_path('pages/sites/site-picker.php'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Site Picker / Site Hub</a></div>
                </div>
                <div class="col-md-6 d-none" id="hire_field_contractor">
                    <label class="po-field-label" for="contractor_search">ผู้รับจ้าง <span class="text-danger">*</span></label>
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
                    <label class="po-field-label" for="installment_total">จำนวนงวดชำระ <span class="text-danger">*</span></label>
                    <input type="number" name="installment_total" id="installment_total" class="form-control text-end" min="1" max="120" value="<?= (int) $hireInstallEdit ?>">
                </div>
                <input type="hidden" name="contract_value" id="contract_value" value="<?= $hireValueEdit > 0 ? htmlspecialchars((string) $hireValueEdit, ENT_QUOTES, 'UTF-8') : '0' ?>">
                <div class="col-12">
                    <label class="po-field-label" id="details_label" for="details_textarea"><?= $requestTypeVal === 'hire' ? 'เงื่อนไขการชำระเงิน / ขอบเขตการทำงาน' : 'รายละเอียด/วัตถุประสงค์' ?><?= $requestTypeVal === 'hire' ? ' <span class="text-danger">*</span>' : '' ?></label>
                    <textarea name="details" id="details_textarea" class="form-control" rows="<?= $requestTypeVal === 'hire' ? 3 : 2 ?>" placeholder="<?= $requestTypeVal === 'hire' ? 'ระบุเงื่อนไขการชำระเงิน และขอบเขตการทำงาน' : '' ?>"<?= $requestTypeVal === 'hire' ? ' required' : '' ?>><?= htmlspecialchars($editDetails, ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>
        </div>

        <div class="card card-soft p-4 p-md-4 mb-4" id="item_table_card">
            <div id="pr_lines_wrap">
                <div class="po-section-head">
                    <h2 class="section-title mb-0">รายการสินค้า</h2>
                </div>
                <div class="table-responsive po-table-wrap po-line-table-mobile">
            <table class="table align-middle table-hover mb-0 po-line-table" id="prTable">
                <thead>
                    <tr>
                        <th style="width:3rem;" class="text-center">#</th>
                        <th>รายการสินค้า</th>
                        <th style="width:7rem;" class="text-end">จำนวน</th>
                        <th style="width:6rem;" class="text-end">หน่วย</th>
                        <th style="width:8rem;" class="text-end">ราคา/หน่วย</th>
                        <th style="width:7rem;" class="text-end">ส่วนลด</th>
                        <th style="width:7rem;" class="text-end">ยอดรวม</th>
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
                                <td class="po-cell-idx row-number text-secondary small fw-semibold">
                                    <div class="po-mobile-item-head">
                                        <span class="po-mobile-item-label">รายการที่ <span class="po-mobile-item-no"><?= $rn ?></span></span>
                                        <?php if ($rn > 1): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm border-0 po-row-delete-btn po-row-delete-mobile" title="ลบแถว" aria-label="ลบแถว"><i class="bi bi-trash-fill"></i></button>
                                        <?php endif; ?>
                                    </div>
                                    <span class="d-none d-lg-inline po-mobile-item-no"><?= $rn ?></span>
                                </td>
                                <td class="po-cell-desc" data-label="รายการ"><input type="text" name="item_description[]" class="form-control form-control-sm" required value="<?= htmlspecialchars((string) ($it['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td class="po-cell-qty" data-label="จำนวน"><input type="number" name="item_qty[]" class="form-control form-control-sm qty text-end" step="any" min="0" required oninput="calculateTotal()" value="<?= htmlspecialchars((string) ($it['quantity'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td class="po-cell-unit" data-label="หน่วย"><input type="text" name="item_unit[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($it['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td class="po-cell-price" data-label="ราคา/หน่วย"><input type="number" name="item_price[]" class="form-control form-control-sm price text-end" step="any" min="0" placeholder="—" oninput="calculateTotal()" value="<?= (float) ($it['unit_price'] ?? 0) > 0 ? htmlspecialchars((string) ($it['unit_price'] ?? ''), ENT_QUOTES, 'UTF-8') : '' ?>"></td>
                                <td class="po-cell-disc" data-label="ส่วนลด"><input type="text" name="item_discount[]" class="form-control form-control-sm line-discount text-end" maxlength="20" oninput="calculateTotal()" value="<?= htmlspecialchars($discEdit, ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td class="po-cell-total" data-label="รวม"><input type="text" class="form-control form-control-sm row-total text-end bg-light fw-semibold" value="<?= number_format((float) ($it['total'] ?? 0), 2, '.', '') ?>" readonly tabindex="-1"></td>
                                <td class="po-cell-action po-cell-action-desktop"><?php if ($rn > 1): ?><button type="button" class="btn btn-outline-danger btn-sm border-0 po-row-delete-btn" title="ลบแถว" aria-label="ลบแถว"><i class="bi bi-trash-fill"></i></button><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td class="po-cell-idx row-number text-secondary small fw-semibold">
                            <div class="po-mobile-item-head">
                                <span class="po-mobile-item-label">รายการที่ <span class="po-mobile-item-no">1</span></span>
                            </div>
                            <span class="d-none d-lg-inline po-mobile-item-no">1</span>
                        </td>
                        <td class="po-cell-desc" data-label="รายการ"><input type="text" name="item_description[]" class="form-control form-control-sm" required></td>
                        <td class="po-cell-qty" data-label="จำนวน"><input type="number" name="item_qty[]" class="form-control form-control-sm qty text-end" step="any" min="0" required oninput="calculateTotal()"></td>
                        <td class="po-cell-unit" data-label="หน่วย"><input type="text" name="item_unit[]" class="form-control form-control-sm text-end"></td>
                        <td class="po-cell-price" data-label="ราคา/หน่วย"><input type="number" name="item_price[]" class="form-control form-control-sm price text-end" step="any" min="0" placeholder="—" oninput="calculateTotal()"></td>
                        <td class="po-cell-disc" data-label="ส่วนลด"><input type="text" name="item_discount[]" class="form-control form-control-sm line-discount text-end" maxlength="20" oninput="calculateTotal()"></td>
                        <td class="po-cell-total" data-label="รวม"><input type="text" class="form-control form-control-sm row-total text-end bg-light fw-semibold" value="0.00" readonly tabindex="-1"></td>
                        <td class="po-cell-action po-cell-action-desktop"></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
                </div>
                <div class="po-actions-bar">
                    <button type="button" class="btn btn-orange btn-sm rounded-pill px-3 shadow-sm" onclick="addRow()">
                        <i class="bi bi-plus-lg me-1"></i>เพิ่มรายการ
                    </button>
                </div>
            </div>

            <div id="hire_lines_wrap" class="d-none hire-lines-section" data-tnc-hire-root>
                <div class="po-section-head">
                    <h2 class="section-title mb-0">รายการงานจัดจ้าง</h2>
                </div>
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
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" id="addHirePrGroupBtn" data-tnc-hire-add="group">
                        <i class="bi bi-folder-plus me-1"></i>หัวข้อหลัก
                    </button>
                    <button type="button" class="btn btn-orange btn-sm rounded-pill px-3 shadow-sm" id="addHirePrRowBtn" data-tnc-hire-add="item">
                        <i class="bi bi-plus-lg me-1"></i>รายการย่อย
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

            <div class="row g-4 mt-1" id="pr_summary_footer">
                <div class="col-lg-7 order-2 order-lg-1">
                    <div class="po-vat-panel">
                        <div class="pr-vat-toolbar">
                            <div class="pr-vat-switch-wrap">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="vat_enabled" id="vat_enabled" value="1" onchange="calculateTotal()"<?= $editVatOn ? ' checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="vat_enabled">มี VAT 7%</label>
                                </div>
                            </div>
                            <div class="pr-vat-dropdown-wrap">
                                <div id="vat_mode_wrap" class="<?= $editVatOn ? '' : 'pr-vat-select-hidden' ?>">
                                    <select class="form-select form-select-sm" name="vat_mode" id="vat_mode" onchange="calculateTotal()">
                                        <option value="exclusive"<?= $editVatMode === 'exclusive' ? ' selected' : '' ?>>แยก VAT (บวก 7% จากฐาน)</option>
                                        <option value="inclusive"<?= $editVatMode === 'inclusive' ? ' selected' : '' ?>>รวม VAT (ราคารวมภาษีแล้ว)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 order-1 order-lg-2">
                    <div class="summary-box pr-summary-sticky">
                        <div id="hire_sum_row_direct" class="summary-line small text-muted d-none"><span>ยอดรายการ</span><strong class="summary-value"><span id="hire_direct_display">0.00</span> บาท</strong></div>
                        <div id="hire_sum_row_overhead" class="summary-line small text-secondary d-none"><span>Overhead (<span id="overhead_pct_label">0</span>%)</span><strong class="summary-value">+ <span id="overhead_amount_display">0.00</span> บาท</strong></div>
                        <div id="hire_sum_row_preliminary" class="summary-line small text-secondary d-none"><span>Preliminary (<span id="preliminary_pct_label">0</span>%)</span><strong class="summary-value">+ <span id="preliminary_amount_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-muted"><span class="summary-label" id="subtotal_label">ยอดรายการ</span><strong class="summary-value"><span id="subtotal_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-success<?= $editVatOn ? '' : ' d-none' ?>" id="vat_row"><span class="summary-label" id="vat_label">ภาษีมูลค่าเพิ่ม</span><strong class="summary-value"><span id="vat_prefix"></span><span id="vat_display">0.00</span> บาท</strong></div>
                        <div class="summary-line summary-grand fw-bold"><span class="summary-label" id="grand_total_label">ยอดสุทธิ</span><strong class="summary-value text-tnc-orange"><span id="grand_total">0.00</span> บาท</strong></div>
                    </div>
                </div>
                <input type="hidden" name="total_amount" id="total_amount_input" value="0">
            </div>
        </div>

        <div class="po-submit-panel po-submit-panel--end mb-2 tnc-mobile-sticky-cta d-lg-none">
            <div class="tnc-mobile-sticky-inner">
                <div class="tnc-mobile-sticky-meta">
                    <div class="tnc-mobile-sticky-label">ยอดสุทธิ</div>
                    <div class="tnc-mobile-sticky-total" id="grand_total_sticky">0.00</div>
                </div>
                <div class="tnc-mobile-sticky-actions">
                    <button type="button" class="btn btn-orange btn-lg po-submit-btn rounded-pill" id="btnPrSaveOpenModalMobile"<?= count($sites) === 0 ? ' disabled' : '' ?> onclick="document.getElementById('btnPrSaveOpenModal')?.click()">
                        <i class="bi bi-check2-circle me-1"></i>บันทึก
                    </button>
                </div>
            </div>
        </div>

        <div class="po-submit-panel po-submit-panel--end mb-2 d-none d-lg-flex w-100 justify-content-center justify-content-lg-end">
            <button type="button" class="btn btn-orange btn-lg po-submit-btn rounded-pill w-100 w-lg-auto" id="btnPrSaveOpenModal"<?= count($sites) === 0 ? ' disabled' : '' ?>>
                <i class="bi bi-check2-circle me-2"></i><?= $requestTypeVal === 'hire' ? 'บันทึกใบขอจัดจ้าง' : 'บันทึกใบขอซื้อ' ?>
            </button>
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
                    <button type="button" class="btn btn-pr-primary rounded-pill px-4 fw-semibold" id="btnPrSaveConfirm" data-tnc-loading-text="กำลังบันทึก…">
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
<script src="<?= htmlspecialchars(app_path('assets/js/purchase-vat-calc.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
let hirePrTableApi = null;

function buildPrPurchaseRowHtml(rowCount, withDelete) {
    var deleteMobile = withDelete
        ? '<button type="button" class="btn btn-outline-danger btn-sm border-0 po-row-delete-btn po-row-delete-mobile" title="ลบแถว" aria-label="ลบแถว"><i class="bi bi-trash-fill"></i></button>'
        : '';
    var deleteDesktop = withDelete
        ? '<button type="button" class="btn btn-outline-danger btn-sm border-0 po-row-delete-btn" title="ลบแถว" aria-label="ลบแถว"><i class="bi bi-trash-fill"></i></button>'
        : '';
    return '<td class="po-cell-idx row-number text-secondary small fw-semibold">' +
        '<div class="po-mobile-item-head">' +
        '<span class="po-mobile-item-label">รายการที่ <span class="po-mobile-item-no">' + rowCount + '</span></span>' +
        deleteMobile +
        '</div>' +
        '<span class="d-none d-lg-inline po-mobile-item-no">' + rowCount + '</span>' +
        '</td>' +
        '<td class="po-cell-desc" data-label="รายการ"><input type="text" name="item_description[]" class="form-control form-control-sm" required></td>' +
        '<td class="po-cell-qty" data-label="จำนวน"><input type="number" name="item_qty[]" class="form-control form-control-sm qty text-end" step="any" min="0" required oninput="calculateTotal()"></td>' +
        '<td class="po-cell-unit" data-label="หน่วย"><input type="text" name="item_unit[]" class="form-control form-control-sm text-end"></td>' +
        '<td class="po-cell-price" data-label="ราคา/หน่วย"><input type="number" name="item_price[]" class="form-control form-control-sm price text-end" step="any" min="0" placeholder="—" oninput="calculateTotal()"></td>' +
        '<td class="po-cell-disc" data-label="ส่วนลด"><input type="text" name="item_discount[]" class="form-control form-control-sm line-discount text-end" maxlength="20" oninput="calculateTotal()"></td>' +
        '<td class="po-cell-total" data-label="รวม"><input type="text" class="form-control form-control-sm row-total text-end bg-light fw-semibold" value="0.00" readonly tabindex="-1"></td>' +
        '<td class="po-cell-action po-cell-action-desktop">' + deleteDesktop + '</td>';
}

// ฟังก์ชันเพิ่มแถวใหม่
function addRow() {
    const table = document.getElementById('prTable').getElementsByTagName('tbody')[0];
    const newRow = table.insertRow();
    const rowCount = table.rows.length;
    newRow.innerHTML = buildPrPurchaseRowHtml(rowCount, true);
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
    const row = btn.closest('tr');
    if (!row || !row.parentNode) {
        return;
    }
    row.remove();
    updateRowNumbers();
    calculateTotal();
}

// ฟังก์ชันอัปเดตเลขลำดับข้อ (#)
function updateRowNumbers() {
    document.querySelectorAll('#prTable tbody tr').forEach(function (row, index) {
        row.querySelectorAll('.po-mobile-item-no').forEach(function (el) {
            el.innerText = index + 1;
        });
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
            const split = tncPurchaseVatFromLineSum(excludedVat, true, 'exclusive');
            vat = split.vat;
            grand = split.gross;
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
            subtotalLabel.textContent = 'ยอดก่อน VAT';
        }
        const subtotalDisplay = document.getElementById('subtotal_display');
        if (subtotalDisplay) {
            subtotalDisplay.textContent = fmtNum(excludedVat);
        }
        const vatLabel = document.getElementById('vat_label');
        if (vatLabel) {
            vatLabel.textContent = 'VAT 7%';
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
            grandLabel.textContent = 'ยอดสุทธิ';
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
        const split = tncPurchaseVatFromLineSum(lineAmount, vatOn, vatMode);
        const subtotal = split.subtotal;
        const vat = split.vat;
        const grand = split.gross;

        document.getElementById('subtotal_label').textContent = 'ยอดรายการ';
        document.getElementById('subtotal_display').textContent = fmtNum(subtotal);
        const vatLabelEl = document.getElementById('vat_label');
        if (vatLabelEl) {
            vatLabelEl.textContent = vatOn ? (vatMode === 'inclusive' ? 'รวม VAT' : 'แยก VAT') : 'แยก VAT';
        }
        document.getElementById('vat_prefix').textContent = '';
        document.getElementById('vat_display').textContent = fmtNum(vat);
        document.getElementById('grand_total_label').textContent = 'ยอดสุทธิ';
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

    const submitGrandEl = document.getElementById('pr_submit_grand_total');
    const grandTotalEl = document.getElementById('grand_total');
    if (submitGrandEl && grandTotalEl) {
        submitGrandEl.textContent = grandTotalEl.textContent;
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
            ? '<i class="bi bi-check2-circle me-2"></i>บันทึกใบขอจัดจ้าง'
            : '<i class="bi bi-check2-circle me-2"></i>บันทึกใบขอซื้อ';
    }
    const saveBtnMobile = document.getElementById('btnPrSaveOpenModalMobile');
    if (saveBtnMobile && saveBtn) {
        saveBtnMobile.disabled = saveBtn.disabled;
    }
    const submitHint = document.getElementById('pr_submit_hint');
    if (submitHint) {
        submitHint.textContent = isHire
            ? 'ตรวจสอบรายการงานและยอดสัญญาก่อนบันทึก'
            : 'ตรวจสอบรายการและจำนวนก่อนบันทึก (ราคาใส่ทีหลังได้)';
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
    itemTableCard.querySelectorAll('input[name="item_description[]"], input[name="item_qty[]"]').forEach((input) => {
        input.required = !isHire;
        input.disabled = isHire;
    });
    itemTableCard.querySelectorAll('input[name="item_price[]"]').forEach((input) => {
        input.required = false;
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
        if (window.TncPurchaseLoading && typeof window.TncPurchaseLoading.setSubmitButtonLoading === 'function') {
            window.TncPurchaseLoading.setSubmitButtonLoading(form, this);
        }
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
    const prTable = document.getElementById('prTable');
    if (prTable) {
        prTable.addEventListener('click', function (e) {
            const btn = e.target.closest('.po-row-delete-btn');
            if (!btn) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            removeRow(btn);
        });
    }
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