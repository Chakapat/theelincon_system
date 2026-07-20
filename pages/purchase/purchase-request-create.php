<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/line_pr_approval.php';
require_once dirname(__DIR__, 2) . '/includes/line_notify_runtime.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_purchase_head.php';

$prOfferLineOnSave = line_effective_channel_access_token() !== '' && line_effective_target_group_id() !== '';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$editId = (int) ($_GET['id'] ?? 0);
if ($editId > 0) {
    if (!user_can('pr.update') && !user_can('pr.create')) {
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
$poForPr = null;
if ($editId > 0) {
    $editPr = Db::rowByIdField('purchase_requests', $editId);
    if ($editPr === null) {
        header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?error=invalid_pr');
        exit();
    }
    $poForPr = Db::findFirst('purchase_orders', static function (array $r) use ($editId): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $editId;
    });
    if (line_pr_is_cancelled($editPr)) {
        header('Location: ' . app_path('pages/purchase/purchase-request-view.php') . '?id=' . $editId . '&error=pr_cancelled');
        exit();
    }
    if (!line_pr_user_can_edit($editPr)) {
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
$requestTypeVal = 'purchase';
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
$editQuotationPath = $isEdit ? trim((string) ($editPr['quotation_attachment_path'] ?? '')) : '';
$editQuotationName = $isEdit ? trim((string) ($editPr['quotation_attachment_name'] ?? '')) : '';
$editVatOn = $isEdit && (int) ($editPr['vat_enabled'] ?? 0) === 1;
$editRoundToBaht = $isEdit && (int) ($editPr['round_to_baht'] ?? 0) === 1;
$editVatMode = $isEdit ? trim((string) ($editPr['vat_mode'] ?? 'exclusive')) : 'exclusive';
if (!in_array($editVatMode, ['exclusive', 'inclusive'], true)) {
    $editVatMode = 'exclusive';
}
$editRequestedBy = $isEdit ? (int) ($editPr['requested_by'] ?? $uid) : $uid;
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
$editPrIsApproved = $isEdit && line_pr_is_approved_for_po($editPr);
$editPrHasLinkedPo = $isEdit && is_array($poForPr) && (int) ($poForPr['id'] ?? 0) > 0;
$editLinkedPoViewUrl = $editPrHasLinkedPo
    ? app_path('pages/purchase/purchase-order-view.php') . '?id=' . (int) $poForPr['id']
    : '';

$siteLockedFromHub = false;
$lockedSiteHubUrl = '';
$hubSiteIdParam = (int) ($_GET['site_id'] ?? 0);
$isEmbed = (string) ($_GET['embed'] ?? '') === '1';
$embedReturnSiteId = 0;
if (!$isEdit && $hubSiteIdParam > 0) {
    foreach ($sites as $siteRowCheck) {
        if ((int) ($siteRowCheck['id'] ?? 0) === $hubSiteIdParam) {
            $siteLockedFromHub = true;
            $editSiteId = $hubSiteIdParam;
            $lockedSiteHubUrl = app_path('pages/sites/site-hub.php?site_id=' . $hubSiteIdParam);
            $embedReturnSiteId = $hubSiteIdParam;
            break;
        }
    }
    if ($isEmbed && !$siteLockedFromHub) {
        $isEmbed = false;
    }
} elseif ($isEdit) {
    $embedReturnSiteId = $hubSiteIdParam > 0 ? $hubSiteIdParam : (int) $editSiteId;
    if ($isEmbed && $embedReturnSiteId <= 0) {
        $isEmbed = false;
    }
    if ($hubSiteIdParam > 0) {
        $lockedSiteHubUrl = app_path('pages/sites/site-hub.php?site_id=' . $hubSiteIdParam);
    }
}
$embedCssVer = @filemtime(dirname(__DIR__, 2) . '/assets/css/tnc-embed-page.css');
if (!is_int($embedCssVer) || $embedCssVer <= 0) {
    $embedCssVer = time();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <?php tnc_purchase_head([
        'title' => $isEdit ? 'แก้ไขใบขอซื้อ (PR)' : 'สร้างใบขอซื้อ (PR)',
        'flatpickr' => true,
    ]); ?>
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
            align-items: flex-start;
            gap: 0.45rem 0.85rem;
        }
        .pr-vat-switch-wrap {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }
        .pr-vat-switch-wrap .form-check { margin-bottom: 0; }
        .pr-vat-dropdown-wrap {
            position: relative;
            flex: 1 1 11rem;
            min-width: min(100%, 10.5rem);
            max-width: 20rem;
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
            justify-content: flex-end;
            align-items: center;
            width: 100%;
        }
        .pr-create-wrap .po-submit-panel--end .po-submit-btn {
            margin-left: auto;
        }
        @media (max-width: 991.98px) {
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
    <?php if ($isEmbed): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/tnc-embed-page.css') . '?v=' . $embedCssVer, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
</head>
<body class="purchase-module tnc-app-body tnc-layout-form<?= $isEmbed ? ' tnc-embed-page' : '' ?>">

<?php if (!$isEmbed): ?>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<?php endif; ?>

<div class="container container-lg py-4 py-md-5 mb-5 pr-create-wrap" id="pr_page_root">
    <?php if (!$isEmbed): ?>
    <?php include dirname(__DIR__, 2) . '/components/purchase-subnav.php'; ?>
    <?php endif; ?>
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
    <?php if ($editPrHasLinkedPo): ?>
        <div class="alert alert-warning border-0 shadow-sm">
            <i class="bi bi-arrow-repeat me-1"></i>PR นี้มี PO แล้ว — บันทึก PR แล้วระบบจะ<strong>อัปเดต PO ที่เชื่อม</strong>ให้อัตโนมัติ (หมวดหมู่, ไซต์, รายการในตาราง, ยอดรวม)
            <?php if ($editLinkedPoViewUrl !== ''): ?>
                · <a href="<?= htmlspecialchars($editLinkedPoViewUrl, ENT_QUOTES, 'UTF-8') ?>" class="alert-link" target="_blank" rel="noopener">ดู PO ที่เชื่อม</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($editPrIsApproved): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <i class="bi bi-check-circle me-1"></i>PR นี้อนุมัติแล้ว — แก้ไขและบันทึกได้ สถานะอนุมัติจะยังคงอยู่ (ยังออก PO ได้ตามเดิม)
        </div>
    <?php endif; ?>
    <?php
    $prDraftUserId = (int) ($_SESSION['user_id'] ?? 0);
    $prDraftKey = $isEdit
        ? ('u' . $prDraftUserId . ':pr:edit:' . (int) $editId)
        : ('u' . $prDraftUserId . ':pr:create' . ($siteLockedFromHub ? (':site' . (int) $editSiteId) : ''));
    ?>
    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=<?= htmlspecialchars($prFormAction, ENT_QUOTES, 'UTF-8') ?>" method="POST" enctype="multipart/form-data" data-tnc-fullnav="1" data-tnc-draft="1" data-tnc-draft-key="<?= htmlspecialchars($prDraftKey, ENT_QUOTES, 'UTF-8') ?>" data-tnc-draft-table="#prTable"<?= $isEmbed ? ' target="_top"' : '' ?>>
        <?php csrf_field(); ?>
        <input type="hidden" name="requested_by" value="<?= (int) $editRequestedBy ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="pr_id" value="<?= (int) $editId ?>">
        <?php endif; ?>
        <?php if ($isEmbed && $embedReturnSiteId > 0): ?>
            <input type="hidden" name="embed" value="1">
            <input type="hidden" name="return_to" value="site_hub">
            <input type="hidden" name="return_site_id" value="<?= (int) $embedReturnSiteId ?>">
        <?php endif; ?>
        <input type="hidden" name="send_line_after_save" id="send_line_after_save" value="0">
        <header class="po-create-hero p-4 p-md-4 mb-4">
            <div class="row align-items-center g-3">
                <div class="col-lg">
                    <p class="purchase-page-kicker mb-1">Purchase Module</p>
                    <h1 class="h3 mb-0 fw-bold" id="pr_page_title">
                        <span id="pr_page_title_text"><?= $isEdit ? 'แก้ไขใบขอซื้อ (PR)' : 'สร้างใบขอซื้อ (PR)' ?></span>
                    </h1>
                </div>
                <?php if (!$isEmbed): ?>
                <div class="col-lg-auto d-flex flex-wrap gap-2 justify-content-lg-end">
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light rounded-pill px-4 shadow-sm"><i class="bi bi-arrow-left me-1"></i>กลับรายการ PR</a>
                </div>
                <?php endif; ?>
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
                <input type="hidden" name="request_type" id="request_type" value="purchase">
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
                        <div class="form-text"><i class="bi bi-lock-fill me-1"></i>ล็อกจาก Site Hub<?php if (!$isEmbed): ?> — <a href="<?= htmlspecialchars($lockedSiteHubUrl, ENT_QUOTES, 'UTF-8') ?>">กลับเมนูไซต์</a><?php endif; ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="col-md-6">
                    <label class="po-field-label" for="cost_category_id">หมวดค่าใช้จ่าย <span class="text-danger">*</span></label>
                    <select name="cost_category_id" id="cost_category_id" class="form-select"<?= count($sites) > 0 ? ' required' : '' ?>>
                        <option value="" disabled<?= $editCostCategoryId <= 0 ? ' selected' : '' ?>>— เลือกหมวด —</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="po-field-label" id="details_label" for="details_textarea">รายละเอียด/วัตถุประสงค์</label>
                    <textarea name="details" id="details_textarea" class="form-control" rows="2" placeholder=""><?= htmlspecialchars($editDetails, ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="po-field-label" for="quotation_file">แนบใบเสนอราคา <span class="text-muted fw-normal">(ไม่บังคับ)</span></label>
                    <?php if ($editQuotationPath !== ''): ?>
                        <div class="small mb-2">
                            ไฟล์ปัจจุบัน:
                            <a href="<?= htmlspecialchars(app_path($editQuotationPath), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                                <?= htmlspecialchars($editQuotationName !== '' ? $editQuotationName : 'เปิดไฟล์', ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <span class="text-muted">— เลือกไฟล์ใหม่ด้านล่างหากต้องการแทนที่</span>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="quotation_file" id="quotation_file" class="form-control" accept=".pdf,image/*,.jpg,.jpeg,.png,.webp,.gif,.bmp,.tif,.tiff">
                    <div class="form-text">รองรับ PDF หรือรูปภาพ — เปิดดูได้จากหน้ารายละเอียด PR</div>
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
                        <th style="width:3.5rem;" class="text-center" title="คิด VAT">VAT</th>
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
                            $vatApplyChecked = (int) ($it['vat_exempt'] ?? 0) !== 1;
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
                                <td class="po-cell-vat-exempt text-center" data-label="คิด VAT">
                                    <input type="hidden" class="line-vat-exempt-val" name="item_vat_exempt[]" value="<?= $vatApplyChecked ? '0' : '1' ?>">
                                    <input type="checkbox" class="form-check-input line-vat-apply m-0" value="1" title="คิด VAT รายการนี้" aria-label="คิด VAT" onchange="tncPurchaseSyncVatApplyHidden(this); calculateTotal();"<?= $vatApplyChecked ? ' checked' : '' ?>>
                                </td>
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
                        <td class="po-cell-vat-exempt text-center" data-label="คิด VAT">
                            <input type="hidden" class="line-vat-exempt-val" name="item_vat_exempt[]" value="0">
                            <input type="checkbox" class="form-check-input line-vat-apply m-0" value="1" checked title="คิด VAT รายการนี้" aria-label="คิด VAT" onchange="tncPurchaseSyncVatApplyHidden(this); calculateTotal();">
                        </td>
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


            <div class="row g-4 mt-1" id="pr_summary_footer">
                <div class="col-lg-7 order-2 order-lg-1">
                    <div class="po-vat-panel mb-3">
                        <label class="po-field-label d-block mb-2">ภาษีมูลค่าเพิ่ม</label>
                        <div class="pr-vat-toolbar">
                            <div class="pr-vat-switch-wrap">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" name="vat_enabled" id="vat_enabled" value="1" onchange="calculateTotal()"<?= $editVatOn ? ' checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="vat_enabled">มี VAT 7%</label>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" name="round_to_baht" id="round_to_baht" value="1" onchange="calculateTotal()"<?= $editRoundToBaht ? ' checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="round_to_baht">ปัดเต็มบาท</label>
                                </div>
                            </div>
                            <div class="pr-vat-dropdown-wrap">
                                <div id="vat_mode_wrap" class="<?= $editVatOn ? '' : 'pr-vat-select-hidden' ?>">
                                    <select class="form-select form-select-sm" name="vat_mode" id="vat_mode" onchange="calculateTotal()" aria-label="วิธีคิด VAT"<?= $editVatOn ? '' : ' disabled' ?>>
                                        <option value="exclusive"<?= $editVatMode === 'exclusive' ? ' selected' : '' ?>>แยกภาษีมูลค่าเพิ่ม (บวก 7% จากฐาน)</option>
                                        <option value="inclusive"<?= $editVatMode === 'inclusive' ? ' selected' : '' ?>>รวมภาษีมูลค่าเพิ่ม (ราคารวมภาษีแล้ว)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 order-1 order-lg-2">
                    <div class="summary-box pr-summary-sticky">
                        <div class="summary-line small text-muted"><span class="summary-label" id="subtotal_label">ยอดรายการ</span><strong class="summary-value"><span id="subtotal_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-muted d-none" id="vat_exempt_row"><span class="summary-label">ไม่คิด VAT</span><strong class="summary-value"><span id="vat_exempt_display">0.00</span> บาท</strong></div>
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
                <i class="bi bi-check2-circle me-2"></i>บันทึกใบขอซื้อ
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
                <div class="modal-footer border-0 pt-0 flex-nowrap gap-2 justify-content-end">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3 px-sm-4" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="button" class="btn btn-orange rounded-pill px-3 px-sm-4 fw-semibold" id="btnPrSaveConfirm" data-tnc-loading-text="กำลังบันทึก…">
                        <i class="bi bi-check2-circle me-1"></i><?= $isEdit ? 'ยืนยันบันทึก' : 'ยืนยันสร้าง' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="<?= htmlspecialchars(app_path('assets/js/site-category-select.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(tnc_asset_href('assets/js/purchase-vat-calc.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(tnc_asset_href('assets/js/tnc-form-draft.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
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
        '<td class="po-cell-vat-exempt text-center" data-label="คิด VAT">' +
        '<input type="hidden" class="line-vat-exempt-val" name="item_vat_exempt[]" value="0">' +
        '<input type="checkbox" class="form-check-input line-vat-apply m-0" value="1" checked title="คิด VAT รายการนี้" aria-label="คิด VAT" onchange="tncPurchaseSyncVatApplyHidden(this); calculateTotal();">' +
        '</td>' +
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
    const money2 = (typeof tncPurchaseMoney2 === 'function')
        ? tncPurchaseMoney2
        : function (n) {
            n = Number(n);
            if (!Number.isFinite(n)) return 0;
            const sign = n < 0 ? -1 : 1;
            return sign * Math.round(Math.abs(n) * 100 + 1e-8) / 100;
        };
    const q = parseFloat(String(qty || '').replace(/,/g, '')) || 0;
    const p = parseFloat(String(price || '').replace(/,/g, '')) || 0;
    const base = money2(q * p);
    const dRaw = String(discRaw || '').trim();
    let discount = 0;
    if (dRaw !== '' && base > 0) {
        const pctMatch = dRaw.match(/^([0-9]+(?:\.[0-9]+)?)\s*%$/);
        if (pctMatch) {
            let pct = parseFloat(pctMatch[1]) || 0;
            if (pct < 0) pct = 0;
            if (pct > 100) pct = 100;
            discount = money2(base * pct / 100);
        } else {
            discount = money2(parseFloat(dRaw.replace(/,/g, '')) || 0);
            if (discount < 0) discount = 0;
            if (discount > base) discount = base;
        }
    }
    return money2(base - discount);
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

function calculateTotal() {
    const fmt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
    const fmtNum = (n) => n.toLocaleString(undefined, fmt);
    let taxableSum = 0;
    let exemptSum = 0;
    const prTableBody = document.getElementById('prTable')?.getElementsByTagName('tbody')[0];
    const rows = prTableBody ? prTableBody.rows : [];
    const vatOn = !!document.getElementById('vat_enabled')?.checked;
    const vatMode = document.getElementById('vat_mode')?.value || 'exclusive';

    for (const row of rows) {
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
        const applyEl = row.querySelector('.line-vat-apply');
        if (applyEl && typeof tncPurchaseSyncVatApplyHidden === 'function') {
            tncPurchaseSyncVatApplyHidden(applyEl);
        }
        const isExempt = (typeof tncPurchaseLineIsVatExempt === 'function')
            ? tncPurchaseLineIsVatExempt(row)
            : (applyEl ? !applyEl.checked : (row.querySelector('.line-vat-exempt-val')?.value === '1'));
        if (isExempt) {
            exemptSum += total;
        } else {
            taxableSum += total;
        }
    }

    taxableSum = (typeof tncPurchaseMoney2 === 'function' ? tncPurchaseMoney2(taxableSum) : Math.round(taxableSum * 100 + 1e-8) / 100);
    exemptSum = (typeof tncPurchaseMoney2 === 'function' ? tncPurchaseMoney2(exemptSum) : Math.round(exemptSum * 100 + 1e-8) / 100);
    const splitFn = typeof tncPurchaseVatFromLineSums === 'function'
        ? tncPurchaseVatFromLineSums
        : function (t, e, v, m) { return tncPurchaseVatFromLineSum(t + e, v, m); };
    const split = splitFn(taxableSum, exemptSum, vatOn, vatMode);
    const subtotal = split.subtotal;
    const vat = split.vat;
    const grand = split.gross;

    document.getElementById('subtotal_label').textContent = vatOn && exemptSum > 0 ? 'ยอดรายการ (คิด VAT)' : 'ยอดรายการ';
    document.getElementById('subtotal_display').textContent = fmtNum(subtotal);
    const vatExemptRow = document.getElementById('vat_exempt_row');
    const vatExemptDisplay = document.getElementById('vat_exempt_display');
    if (vatExemptRow && vatExemptDisplay) {
        if (vatOn && exemptSum > 0) {
            vatExemptRow.classList.remove('d-none');
            vatExemptDisplay.textContent = fmtNum(exemptSum);
        } else {
            vatExemptRow.classList.add('d-none');
        }
    }
    const vatLabelEl = document.getElementById('vat_label');
    if (vatLabelEl) {
        vatLabelEl.textContent = vatOn
            ? (typeof tncPurchaseVatModeLabel === 'function' ? tncPurchaseVatModeLabel(vatMode) : (vatMode === 'inclusive' ? 'รวมภาษีมูลค่าเพิ่ม' : 'แยกภาษีมูลค่าเพิ่ม'))
            : 'ภาษีมูลค่าเพิ่ม';
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

    const vatModeWrap = document.getElementById('vat_mode_wrap');
    if (vatModeWrap) {
        vatModeWrap.classList.toggle('pr-vat-select-hidden', !vatOn);
    }
    const vatModeEl = document.getElementById('vat_mode');
    if (vatModeEl) {
        vatModeEl.disabled = !vatOn;
        vatModeEl.tabIndex = vatOn ? 0 : -1;
        vatModeEl.setAttribute('aria-hidden', vatOn ? 'false' : 'true');
    }

    const submitGrandEl = document.getElementById('pr_submit_grand_total');
    const grandTotalEl = document.getElementById('grand_total');
    if (submitGrandEl && grandTotalEl) {
        submitGrandEl.textContent = grandTotalEl.textContent;
    }
}

function toggleRequestTypeFields() {
    const isEditMode = <?= $isEdit ? 'true' : 'false' ?>;
    const pageTitleText = document.getElementById('pr_page_title_text');
    const saveBtn = document.getElementById('btnPrSaveOpenModal');
    if (pageTitleText) {
        pageTitleText.textContent = isEditMode ? 'แก้ไขใบขอซื้อ (PR)' : 'สร้างใบขอซื้อ (PR)';
    }
    if (saveBtn) {
        saveBtn.innerHTML = '<i class="bi bi-check2-circle me-2"></i>บันทึกใบขอซื้อ';
    }
    const saveBtnMobile = document.getElementById('btnPrSaveOpenModalMobile');
    if (saveBtnMobile && saveBtn) {
        saveBtnMobile.disabled = saveBtn.disabled;
    }
    const submitHint = document.getElementById('pr_submit_hint');
    if (submitHint) {
        submitHint.textContent = 'ตรวจสอบรายการและจำนวนก่อนบันทึก (ราคาใส่ทีหลังได้)';
    }
    calculateTotal();
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
    document.querySelectorAll('.line-vat-apply').forEach(function (cb) {
        if (typeof tncPurchaseSyncVatApplyHidden === 'function') {
            tncPurchaseSyncVatApplyHidden(cb);
        }
    });
    document.getElementById('request_type')?.addEventListener('change', toggleRequestTypeFields);
    toggleRequestTypeFields();
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
        if (typeof window.tncPopulateSiteCategorySelect === 'function') {
            window.tncPopulateSiteCategorySelect(catEl, catMap, siteId, prev);
        }
        selectedCatId = 0;
    }

    if (siteEl) {
        siteEl.addEventListener('change', populateCategories);
    }
    document.addEventListener('DOMContentLoaded', populateCategories);
    populateCategories();
})();


</script>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>