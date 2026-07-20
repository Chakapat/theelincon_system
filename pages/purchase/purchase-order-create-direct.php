<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/banks.php';
require_once dirname(__DIR__, 2) . '/includes/suppliers.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_purchase_head.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_po_adjustments_ui.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

if (!user_can('po.create')) {
    header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?error=forbidden');
    exit();
}

$poListUrl = app_path('pages/purchase/purchase-order-list.php');
$prListUrl = app_path('pages/purchase/purchase-request-list.php');
$handlerUrl = app_path('actions/action-handler.php') . '?action=create_po_direct';
$errorCode = trim((string) ($_GET['error'] ?? ''));

$po_number = Purchase::generateDirectPONumber();
$supplierRows = Db::tableRows('suppliers');
Db::sortRows($supplierRows, 'name', false);

/** @var array<string, array{name: string, tax_id: string, address: string}> $supplierInfoMap */
$supplierInfoMap = [];
/** @var array<string, array{name: string, bank: string, account_name: string, account_number: string, bank_logo: string, note_text: string}> $supplierPaymentMap */
$supplierPaymentMap = [];
foreach ($supplierRows as $supplierRow) {
    $sid = (int) ($supplierRow['id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }
    $supplierInfoMap[(string) $sid] = [
        'name' => trim((string) ($supplierRow['name'] ?? '')),
        'tax_id' => trim((string) ($supplierRow['tax_id'] ?? '')),
        'address' => trim((string) ($supplierRow['address'] ?? '')),
    ];
    if (!tnc_supplier_has_payment_info($supplierRow)) {
        continue;
    }
    $bankName = trim((string) ($supplierRow['bank_name'] ?? ''));
    $supplierPaymentMap[(string) $sid] = [
        'name' => trim((string) ($supplierRow['name'] ?? '')),
        'bank' => $bankName,
        'account_name' => trim((string) ($supplierRow['bank_account_name'] ?? '')),
        'account_number' => trim((string) ($supplierRow['bank_account_number'] ?? '')),
        'bank_logo' => $bankName !== '' ? tnc_bank_logo_url($bankName) : '',
        'note_text' => tnc_supplier_payment_note_text($supplierRow),
    ];
}

$sites = Db::tableRows('sites');
usort($sites, static function (array $a, array $b): int {
    $sort = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
    if ($sort !== 0) {
        return $sort;
    }

    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
require_once dirname(__DIR__, 2) . '/includes/site_cost_categories.php';
$siteCategoryMap = tnc_site_categories_map_by_site();

$prefillSiteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;
$siteLockedFromHub = false;
$lockedSiteHubUrl = '';
$isEmbed = (string) ($_GET['embed'] ?? '') === '1';
if ($prefillSiteId > 0) {
    foreach ($sites as $siteRowCheck) {
        if ((int) ($siteRowCheck['id'] ?? 0) === $prefillSiteId) {
            $siteLockedFromHub = true;
            $lockedSiteHubUrl = app_path('pages/sites/site-hub.php?site_id=' . $prefillSiteId);
            break;
        }
    }
    if (!$siteLockedFromHub) {
        $prefillSiteId = 0;
    }
}
if ($isEmbed && !$siteLockedFromHub) {
    $isEmbed = false;
}
$embedCssVer = @filemtime(dirname(__DIR__, 2) . '/assets/css/tnc-embed-page.css');
if (!is_int($embedCssVer) || $embedCssVer <= 0) {
    $embedCssVer = time();
}
$issueDateDisplay = date('d/m/Y');
$poVatEnabled = 0;
$poVatMode = 'exclusive';
$items = [[
    'description' => '',
    'quantity' => 0,
    'unit' => '',
    'unit_price' => 0,
    'discount_input' => '',
]];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php tnc_purchase_head([
        'title' => 'ออกใบสั่งซื้อโดยตรง',
        'flatpickr' => true,
        'sarabun_weights' => '400;600;700',
    ]); ?>
    <style>
        .po-create-wrap { max-width: 1100px; }
        .card-soft { border: 1px solid rgba(226, 232, 240, 0.95); border-radius: var(--tnc-radius-lg); box-shadow: var(--tnc-shadow-sm); background: #fff; }
        .po-section-head { display: flex; align-items: flex-start; gap: 0.75rem; margin-bottom: 1.1rem; padding-bottom: 0.75rem; border-bottom: 1px solid #eef2f7; }
        .section-title { font-size: 1.05rem; font-weight: 800; color: var(--tnc-ink); margin: 0; }
        .section-sub { font-size: 0.8rem; color: var(--tnc-muted); margin: 0.2rem 0 0; }
        .po-field-label { font-size: 0.78rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.35rem; }
        .po-po-number { font-size: 1.05rem; letter-spacing: 0.02em; }
        .po-table-wrap { border: 1px solid #e8ecf1; border-radius: 0.75rem; overflow: hidden; background: #fff; }
        .po-table-wrap thead th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; font-weight: 700; background: #f1f5f9 !important; }
        .summary-box { background: linear-gradient(180deg, #fffbf5 0%, var(--tnc-orange-soft) 100%); border: 1px solid var(--tnc-orange-border); border-radius: 0.85rem; padding: 1.1rem 1.15rem; }
        @media (min-width: 992px) { .po-summary-sticky { position: sticky; top: 5.5rem; } }
        .summary-line { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; margin-bottom: 10px; }
        .summary-grand { padding-top: 0.35rem; margin-top: 0.25rem; border-top: 2px dashed rgba(253, 126, 20, 0.25); }
        .po-vat-panel { background: #fffbf5; border: 1px solid var(--tnc-orange-border); border-radius: 0.75rem; padding: 0.85rem; }
        .po-vat-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.45rem 0.85rem;
        }
        .po-vat-switch-wrap .form-check { margin-bottom: 0; }
        .po-vat-dropdown-wrap {
            position: relative;
            flex: 1 1 11rem;
            min-width: min(100%, 10.5rem);
            max-width: 20rem;
        }
        #vat_mode_wrap.po-vat-select-hidden {
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
            .po-vat-toolbar { flex-direction: column; align-items: stretch; }
            .po-vat-dropdown-wrap { max-width: none; }
        }
        .po-actions-bar { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eef2f7; }
        .site-field-locked .form-select:disabled {
            background-color: #f8fafc;
            color: #334155;
            cursor: not-allowed;
            opacity: 1;
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

<div class="container container-lg py-4 py-md-5 mb-5 po-create-wrap">
    <?php if (!$isEmbed): ?>
    <?php include dirname(__DIR__, 2) . '/components/purchase-subnav.php'; ?>
    <?php endif; ?>
    <?php if ($errorCode !== ''): ?>
        <div class="alert alert-danger py-2 mb-3">
            <?php
            echo match ($errorCode) {
                'supplier', 'po_supplier' => 'กรุณาเลือกผู้ขายจากรายการที่ระบบแนะนำ',
                'no_items', 'invalid_items' => 'กรุณาเพิ่มรายการอย่างน้อย 1 รายการ และกรอกจำนวน/ราคาให้ถูกต้อง',
                'billing_required' => 'กรุณากรอกเลขที่บิล/ใบกำกับภาษี',
                'payment_slip_required' => 'กรุณาแนบสลิปหรือหลักฐานการจ่ายอย่างน้อย 1 ไฟล์',
                'cash_paid_by_required' => 'กรุณากรอก «จ่ายโดย» เมื่อเลือกชำระด้วยเงินสด',
                'need_site' => 'กรุณาเลือกไซต์งาน (โครงการ)',
                'need_cost_category' => 'กรุณาเลือกหมวดค่าใช้จ่ายของไซต์',
                'site_budget_exceeded' => 'งบไซต์ไม่พอ — ไม่สามารถออก PO ได้ (เกินวงเงินรวมของไซต์)',
                'site_budget_cat_exceeded' => 'งบหมวดไม่พอ — ไม่สามารถออก PO ได้ (เกินวงเงินหมวดที่กำหนด)',
                'upload_failed', 'upload_type' => 'อัปโหลดสลิปไม่สำเร็จ — ใช้ไฟล์รูปหรือ PDF',
                'quotation_upload_failed' => 'อัปโหลดไฟล์ใบเสนอราคาไม่สำเร็จ กรุณาลองใหม่',
                'quotation_upload_type' => 'ไฟล์ใบเสนอราคาต้องเป็น PDF หรือรูปภาพ (JPG, PNG, WEBP, GIF ฯลฯ)',
                default => 'บันทึกใบสั่งซื้อไม่สำเร็จ กรุณาตรวจสอบข้อมูลและลองใหม่',
            };
            ?>
        </div>
    <?php endif; ?>

    <?php if (count($sites) === 0): ?>
        <div class="alert alert-warning py-2 mb-3">ยังไม่มีไซต์งานในระบบ — กรุณา<a href="<?= htmlspecialchars(app_path('pages/sites/site-picker.php'), ENT_QUOTES, 'UTF-8') ?>">เพิ่มไซต์งาน</a>ก่อนออก PO</div>
    <?php endif; ?>

    <?php
    $poDirectDraftKey = 'u' . (int) ($_SESSION['user_id'] ?? 0) . ':po:direct' . ($siteLockedFromHub ? (':site' . (int) $prefillSiteId) : '');
    ?>
    <form action="<?= htmlspecialchars($handlerUrl, ENT_QUOTES, 'UTF-8') ?>" method="POST" enctype="multipart/form-data" data-tnc-fullnav="1" data-tnc-draft="1" data-tnc-draft-key="<?= htmlspecialchars($poDirectDraftKey, ENT_QUOTES, 'UTF-8') ?>" data-tnc-draft-table="#poTable"<?= $isEmbed ? ' target="_top"' : '' ?>>
        <?php csrf_field(); ?>
        <?php if ($isEmbed && $siteLockedFromHub): ?>
            <input type="hidden" name="embed" value="1">
            <input type="hidden" name="return_to" value="site_hub">
            <input type="hidden" name="return_site_id" value="<?= (int) $prefillSiteId ?>">
        <?php endif; ?>

        <header class="po-create-hero p-4 p-md-4 mb-4">
            <div class="row align-items-center g-3">
                <div class="col-lg">
                    <p class="purchase-page-kicker mb-1">Purchase Module</p>
                    <h1 class="h3 mb-1 fw-bold">ออกใบสั่งซื้อโดยตรง</h1>
                </div>
                <?php if (!$isEmbed): ?>
                <div class="col-lg-auto d-flex flex-wrap gap-2 justify-content-lg-end">
                    <a href="<?= htmlspecialchars($poListUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light rounded-pill px-4 shadow-sm"><i class="bi bi-arrow-left me-1"></i>กลับรายการ PO</a>
                </div>
                <?php endif; ?>
            </div>
        </header>

        <div class="card card-soft p-4 p-md-4 mb-4 po-meta-card">
            <div class="row g-3 g-md-4">
                <div class="col-md-6">
                    <label class="po-field-label" for="po_number_display">เลขที่ใบสั่งซื้อ (อัตโนมัติ)</label>
                    <input type="text" id="po_number_display" class="form-control po-po-number bg-light text-tnc-orange fw-bold" value="<?= htmlspecialchars($po_number, ENT_QUOTES, 'UTF-8') ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="po-field-label" for="issue_date">วันที่ออกใบสั่งซื้อ / วันที่ใบกำกับ <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-white text-tnc-orange"><i class="bi bi-calendar3"></i></span>
                        <input type="text" name="issue_date" id="issue_date" class="form-control" value="<?= htmlspecialchars($issueDateDisplay, ENT_QUOTES, 'UTF-8') ?>" required autocomplete="off" placeholder="วัน/เดือน/ปี">
                    </div>
                </div>
            </div>
            <div class="row g-3 g-md-4 mt-0">
                <div class="col-md-4">
                    <label class="po-field-label" for="supplier_search">ผู้ขาย / แหล่งซื้อ <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-white text-secondary"><i class="bi bi-shop"></i></span>
                        <input type="text" id="supplier_search" class="form-control" list="supplier_list" required autocomplete="off">
                    </div>
                    <datalist id="supplier_list">
                        <?php foreach ($supplierRows as $s): ?>
                            <option value="<?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-id="<?= (int) ($s['id'] ?? 0) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="supplier_id" id="supplier_id" value="">
                </div>
                <div class="col-md-3">
                    <label class="po-field-label" for="supplier_tax_display">เลขที่ภาษี</label>
                    <input type="text" id="supplier_tax_display" class="form-control bg-light" value="" readonly placeholder="" autocomplete="off">
                </div>
                <div class="col-md-5">
                    <label class="po-field-label" for="supplier_address_display">ที่อยู่</label>
                    <input type="text" id="supplier_address_display" class="form-control bg-light" value="" readonly placeholder="" autocomplete="off">
                </div>
            </div>
            <div class="row g-3 g-md-4 mt-0">
                <div class="col-md-4">
                    <label class="po-field-label" for="supplier_invoice_no">เลขที่บิล / ใบกำกับภาษี</label>
                    <input type="text" name="supplier_invoice_no" id="supplier_invoice_no" class="form-control" maxlength="120">
                </div>
                <?php if (count($sites) > 0): ?>
                <div class="col-md-4<?= $siteLockedFromHub ? ' site-field-locked' : '' ?>">
                    <label class="po-field-label" for="site_id">ไซต์งาน / โครงการ <span class="text-danger">*</span></label>
                    <select id="site_id" class="form-select"<?= $siteLockedFromHub ? ' disabled' : ' name="site_id" required' ?>>
                        <option value="" disabled<?= $prefillSiteId <= 0 ? ' selected' : '' ?>>— เลือกไซต์งาน —</option>
                        <?php foreach ($sites as $site): ?>
                            <?php $sid = (int) ($site['id'] ?? 0); ?>
                            <?php if ($sid <= 0) { continue; } ?>
                            <option value="<?= $sid ?>"<?= $prefillSiteId === $sid ? ' selected' : '' ?>><?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($siteLockedFromHub): ?>
                        <input type="hidden" name="site_id" value="<?= $prefillSiteId ?>">
                        <div class="form-text"><i class="bi bi-lock-fill me-1"></i>ล็อกจาก Site Hub<?php if (!$isEmbed): ?> — <a href="<?= htmlspecialchars($lockedSiteHubUrl, ENT_QUOTES, 'UTF-8') ?>">กลับเมนูไซต์</a><?php endif; ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="po-field-label" for="cost_category_id">หมวดค่าใช้จ่าย <span class="text-danger">*</span></label>
                    <select name="cost_category_id" id="cost_category_id" class="form-select" required disabled>
                        <option value="" disabled selected>— เลือกไซต์ก่อน —</option>
                    </select>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card card-soft p-4 p-md-4 mb-4">
            <div class="row g-3">
                <div class="col-12">
                    <label class="po-field-label d-block mb-2">ช่องทางชำระ</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="payment_method" id="payMethodTransfer" value="transfer" checked>
                        <label class="form-check-label" for="payMethodTransfer">เงินโอน</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="payment_method" id="payMethodCash" value="cash">
                        <label class="form-check-label" for="payMethodCash">เงินสด</label>
                    </div>
                </div>
                <div class="col-md-6 d-none" id="poCreateCashWrap">
                    <label class="po-field-label" for="payment_cash_paid_by">จ่ายโดย <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="payment_cash_paid_by" id="payment_cash_paid_by" maxlength="255" placeholder="เช่น ชื่อผู้จ่าย / แผนก" autocomplete="off">
                </div>
                <div class="col-md-6" id="poCreateSlipWrap">
                    <label class="po-field-label" for="payment_slips">แนบสลิป / หลักฐานการจ่าย</label>
                    <input type="file" name="payment_slips[]" id="payment_slips" class="form-control" accept="image/*,.pdf" multiple>
                </div>
            </div>
            <input type="hidden" name="billed_total_amount" id="billed_total_amount" value="0">
            <input type="hidden" name="billed_vat_amount" id="billed_vat_amount" value="0">
        </div>

        <div class="card card-soft p-4 p-md-4 mb-4">
            <label class="po-field-label" for="po_note">หมายเหตุใบสั่งซื้อ</label>
            <textarea name="po_note" id="po_note" class="form-control" rows="2" maxlength="500" placeholder="หมายเหตุ (ถ้ามี)"></textarea>
            <div class="mt-3 pt-3 border-top">
                <label class="po-field-label" for="quotation_file">แนบใบเสนอราคา <span class="text-muted fw-normal">(ไม่บังคับ)</span></label>
                <input type="file" name="quotation_file" id="quotation_file" class="form-control" accept=".pdf,image/*,.jpg,.jpeg,.png,.webp,.gif,.bmp,.tif,.tiff">
                <div class="form-text">รองรับ PDF หรือรูปภาพ — เปิดดูได้จากหน้ารายละเอียด PO</div>
            </div>
        </div>
        <div class="card card-soft p-4 p-md-4 mb-4">
            <div class="po-section-head">
                <div>
                    <h2 class="section-title mb-0">รายการสินค้า</h2>
                </div>
            </div>
            <div class="table-responsive po-table-wrap po-line-table-mobile">
                <table class="table align-middle table-hover mb-0 po-line-table" id="poTable">
                    <thead>
                        <tr>
                            <th style="width:3rem;">#</th>
                            <th>รายการ</th>
                            <th style="width:6.5rem;">จำนวน</th>
                            <th style="width:6.5rem;">หน่วย</th>
                            <th style="width:7.5rem;">ราคา/หน่วย</th>
                            <th style="width:6.5rem;">ส่วนลด</th>
                            <th style="width:3.5rem;" class="text-center" title="คิด VAT">VAT</th>
                            <th style="width:7.5rem;">ยอดรวม</th>
                            <th style="width:2.75rem;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $index => $item): ?>
                            <?php $vatApplyChecked = (int) ($item['vat_exempt'] ?? 0) !== 1; ?>
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
                                <td class="po-cell-desc" data-label="รายการ"><input type="text" name="item_description[]" class="form-control form-control-sm" required value="<?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td class="po-cell-qty" data-label="จำนวน"><input type="number" name="item_qty[]" class="form-control form-control-sm qty" step="0.001" min="0" required value="<?= htmlspecialchars((string) ($item['quantity'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" oninput="calculateTotal()"></td>
                                <td class="po-cell-unit" data-label="หน่วย"><input type="text" name="item_unit[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td class="po-cell-price" data-label="ราคา/หน่วย"><input type="number" name="item_price[]" class="form-control form-control-sm price" step="0.001" required value="<?= htmlspecialchars((string) ($item['unit_price'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" oninput="calculateTotal()"></td>
                                <td class="po-cell-disc" data-label="ส่วนลด"><input type="text" name="item_discount[]" class="form-control form-control-sm po-discount" maxlength="20" value="<?= htmlspecialchars((string) ($item['discount_input'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" oninput="calculateTotal()"></td>
                                <td class="po-cell-vat-exempt text-center" data-label="คิด VAT">
                                    <input type="hidden" class="line-vat-exempt-val" name="item_vat_exempt[]" value="<?= $vatApplyChecked ? '0' : '1' ?>">
                                    <input type="checkbox" class="form-check-input line-vat-apply m-0" value="1" title="คิด VAT รายการนี้" aria-label="คิด VAT" onchange="if(typeof tncPurchaseSyncVatApplyHidden==='function'){tncPurchaseSyncVatApplyHidden(this);} calculateTotal();"<?= $vatApplyChecked ? ' checked' : '' ?>>
                                </td>
                                <td class="po-cell-total" data-label="ยอดรวม"><input type="text" class="form-control form-control-sm row-total bg-light text-end fw-semibold" value="0.00" readonly tabindex="-1"></td>
                                <td class="po-cell-action po-cell-action-desktop"><?php if ($index > 0): ?><button type="button" class="btn btn-outline-danger btn-sm border-0 po-row-delete-btn" title="ลบแถว" aria-label="ลบแถว"><i class="bi bi-trash-fill"></i></button><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="po-actions-bar">
                <button type="button" class="btn btn-orange btn-sm rounded-pill px-3 shadow-sm" onclick="addRow()"><i class="bi bi-plus-lg me-1"></i>เพิ่มรายการ</button>
            </div>
            <div class="row g-4 mt-1">
                <div class="col-lg-7 order-2 order-lg-1">
                    <div class="po-vat-panel mb-3">
                        <label class="po-field-label d-block mb-2">ภาษีมูลค่าเพิ่ม</label>
                        <div class="po-vat-toolbar">
                            <div class="po-vat-switch-wrap">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" name="vat_enabled" id="vat_enabled" value="1" onchange="updatePoVatBasisUi(); calculateTotal()"<?= $poVatEnabled === 1 ? ' checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="vat_enabled">มี VAT 7%</label>
                                </div>
                                <div class="form-check form-switch mb-0 mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" name="round_to_baht" id="round_to_baht" value="1" onchange="calculateTotal()">
                                    <label class="form-check-label fw-semibold" for="round_to_baht">ปัดเต็มบาท</label>
                                </div>
                            </div>
                            <div class="po-vat-dropdown-wrap">
                                <div id="vat_mode_wrap" class="<?= $poVatEnabled === 1 ? '' : 'po-vat-select-hidden' ?>">
                                    <select class="form-select form-select-sm" name="vat_mode" id="vat_mode" onchange="calculateTotal()" aria-label="วิธีคิด VAT"<?= $poVatEnabled === 1 ? '' : ' disabled' ?>>
                                        <option value="exclusive"<?= $poVatMode === 'exclusive' ? ' selected' : '' ?>>แยกภาษีมูลค่าเพิ่ม (บวก 7% จากฐาน)</option>
                                        <option value="inclusive"<?= $poVatMode === 'inclusive' ? ' selected' : '' ?>>รวมภาษีมูลค่าเพิ่ม (ราคารวมภาษีแล้ว)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php tnc_po_render_adjustments_panel(tnc_po_adjustments_editor_seed(null)); ?>
                </div>
                <div class="col-lg-5 order-1 order-lg-2">
                    <div class="summary-box po-summary-sticky">
                        <div class="summary-box__title">สรุปยอด</div>
                        <div class="summary-line small text-muted"><span class="summary-label" id="subtotal_label">ยอดรายการ</span><strong class="summary-value"><span id="subtotal_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-muted d-none" id="vat_exempt_row"><span class="summary-label">ไม่คิด VAT</span><strong class="summary-value"><span id="vat_exempt_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-success" id="vat_row" style="display:none;"><span class="summary-label" id="vat_label">ภาษีมูลค่าเพิ่ม</span><strong class="summary-value"><span id="vat_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-muted" id="gross_row"><span class="summary-label" id="gross_label">ยอดรวมภาษี</span><strong class="summary-value"><span id="gross_display">0.00</span> บาท</strong></div>
                        <?php tnc_po_render_adjustments_summary_slot(); ?>
                        <div class="summary-line summary-grand fw-bold"><span class="summary-label">ยอดสุทธิ</span><strong class="summary-value text-tnc-orange"><span id="grand_total">0.00</span> บาท</strong></div>
                    </div>
                    <input type="hidden" name="withholding_type" id="withholding_type" value="none">
                </div>
            </div>
        </div>

        <div class="po-submit-panel mb-2 tnc-mobile-sticky-cta d-lg-none">
            <div class="tnc-mobile-sticky-inner">
                <div class="tnc-mobile-sticky-meta">
                    <div class="tnc-mobile-sticky-label">ยอดสุทธิ</div>
                    <div class="tnc-mobile-sticky-total" id="grand_total_sticky">0.00</div>
                </div>
                <div class="tnc-mobile-sticky-actions">
                    <button type="submit" class="btn btn-orange btn-lg po-submit-btn rounded-pill"<?= count($sites) === 0 ? ' disabled' : '' ?>>
                        <i class="bi bi-check2-circle me-1"></i>สร้าง PO
                    </button>
                </div>
            </div>
        </div>

        <div class="card card-soft po-submit-panel mb-2 d-none d-lg-block">
            <div class="po-submit-panel-inner">
                <div class="po-submit-panel-meta">
                    <div>
                    </div>
                </div>
                <div class="po-submit-panel-action">
                    <button type="submit" class="btn btn-orange btn-lg po-submit-btn rounded-pill"<?= count($sites) === 0 ? ' disabled' : '' ?>>
                        <i class="bi bi-check2-circle me-2"></i>สร้างใบสั่งซื้อ
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="<?= htmlspecialchars(app_path('assets/js/site-category-select.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(tnc_asset_href('assets/js/purchase-vat-calc.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(tnc_asset_href('assets/js/po-adjustments.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(tnc_asset_href('assets/js/tnc-form-draft.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function () {
    const issueDateEl = document.getElementById('issue_date');
    if (typeof flatpickr === 'function') {
        if (issueDateEl) {
            flatpickr(issueDateEl, { dateFormat: 'd/m/Y', defaultDate: issueDateEl.value || 'today', allowInput: true });
        }
    }
    function normalizeYmdInput(el) {
        if (!el) return true;
        const raw = (el.value || '').trim();
        if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return true;
        const m = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (!m) return false;
        el.value = m[3] + '-' + String(m[2]).padStart(2, '0') + '-' + String(m[1]).padStart(2, '0');
        return true;
    }
    const payMethodTransfer = document.getElementById('payMethodTransfer');
    const payMethodCash = document.getElementById('payMethodCash');
    const poCreateCashWrap = document.getElementById('poCreateCashWrap');
    const paymentCashPaidBy = document.getElementById('payment_cash_paid_by');
    const poCreateSlipHint = document.getElementById('poCreateSlipHint');

    function syncPoCreatePaymentUi() {
        const isCash = !!(payMethodCash && payMethodCash.checked);
        if (poCreateCashWrap) {
            poCreateCashWrap.classList.toggle('d-none', !isCash);
        }
        if (paymentCashPaidBy) {
            paymentCashPaidBy.required = isCash;
            if (!isCash) {
                paymentCashPaidBy.value = '';
            }
        }
        if (poCreateSlipHint) {
            poCreateSlipHint.textContent = isCash
                ? 'ไม่บังคับเมื่อเลือกเงินสด — แนบได้ถ้ามีใบเสร็จหรือหลักฐานเพิ่มเติม'
                : 'เลือกได้หลายไฟล์ (รูปหรือ PDF) — ถ้าแนบแล้ว PO จะถูกบันทึกเป็น «จ่ายแล้ว»';
        }
    }
    payMethodTransfer?.addEventListener('change', syncPoCreatePaymentUi);
    payMethodCash?.addEventListener('change', syncPoCreatePaymentUi);
    syncPoCreatePaymentUi();

    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function (e) {
            const siteEl = document.getElementById('site_id');
            const catEl = document.getElementById('cost_category_id');
            const lockedSiteId = <?= $siteLockedFromHub ? (int) $prefillSiteId : 0 ?>;
            function resolveSiteId() {
                if (lockedSiteId > 0) {
                    return lockedSiteId;
                }
                return siteEl ? (parseInt(siteEl.value || '0', 10) || 0) : 0;
            }
            const siteVal = resolveSiteId();
            if (siteEl && !siteEl.disabled && siteEl.required && siteVal <= 0) {
                e.preventDefault();
                alert('กรุณาเลือกไซต์งาน (โครงการ)');
                siteEl.focus();
                return;
            }
            if (siteVal <= 0) {
                e.preventDefault();
                alert('กรุณาเลือกไซต์งาน (โครงการ)');
                if (siteEl) siteEl.focus();
                return;
            }
            if (catEl && catEl.required && !(parseInt(catEl.value || '0', 10) > 0)) {
                e.preventDefault();
                alert('กรุณาเลือกหมวดค่าใช้จ่าย');
                catEl.focus();
                return;
            }
            if (!normalizeYmdInput(issueDateEl)) {
                e.preventDefault();
                alert('กรุณากรอกวันที่เป็น วัน/เดือน/ปี');
                issueDateEl && issueDateEl.focus();
                return;
            }
            if (payMethodCash && payMethodCash.checked) {
                const paidBy = (paymentCashPaidBy?.value || '').trim();
                if (!paidBy) {
                    e.preventDefault();
                    alert('กรุณากรอก «จ่ายโดย» เมื่อเลือกชำระด้วยเงินสด');
                    paymentCashPaidBy?.focus();
                    return;
                }
            }
        });
    }
})();

(function () {
    var catMap = <?= json_encode($siteCategoryMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    var lockedSiteId = <?= $siteLockedFromHub ? (int) $prefillSiteId : 0 ?>;
    var siteEl = document.getElementById('site_id');
    var catEl = document.getElementById('cost_category_id');
    if (!catEl) return;

    function resolveSiteId() {
        if (lockedSiteId > 0) {
            return lockedSiteId;
        }
        return siteEl ? parseInt(siteEl.value || '0', 10) || 0 : 0;
    }

    function populateCategories() {
        var siteId = resolveSiteId();
        var prev = parseInt(catEl.value || '0', 10) || 0;
        if (typeof window.tncPopulateSiteCategorySelect === 'function') {
            window.tncPopulateSiteCategorySelect(catEl, catMap, siteId, prev);
        }
    }

    if (siteEl) {
        siteEl.addEventListener('change', populateCategories);
        if (lockedSiteId > 0) {
            siteEl.value = String(lockedSiteId);
        }
    }
    populateCategories();
})();

const SUPPLIER_INFO_MAP = <?= json_encode($supplierInfoMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const SUPPLIER_PAYMENT_MAP = <?= json_encode($supplierPaymentMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

(function () {
    const searchInput = document.getElementById('supplier_search');
    const supplierIdInput = document.getElementById('supplier_id');
    const poNoteEl = document.getElementById('po_note');
    const datalist = document.getElementById('supplier_list');
    const taxDisplay = document.getElementById('supplier_tax_display');
    const addressDisplay = document.getElementById('supplier_address_display');
    if (!searchInput || !supplierIdInput || !datalist) {
        return;
    }

    let lastPromptedSupplierId = '';
    let autoInsertedNote = '';

    function updateSupplierInfo(supplierId) {
        const info = supplierId ? (SUPPLIER_INFO_MAP[supplierId] || null) : null;
        if (taxDisplay) {
            taxDisplay.value = info && info.tax_id ? info.tax_id : '';
        }
        if (addressDisplay) {
            const addr = info && info.address ? String(info.address).replace(/\s+/g, ' ').trim() : '';
            addressDisplay.value = addr;
        }
    }

    function syncSupplierId() {
        const typed = (searchInput.value || '').trim();
        if (typed === '') {
            supplierIdInput.value = '';
            updateSupplierInfo('');
            return '';
        }
        let matchedId = '';
        datalist.querySelectorAll('option').forEach(function (opt) {
            const optValue = (opt.value || '').trim();
            if (matchedId === '' && optValue.toLowerCase() === typed.toLowerCase()) {
                matchedId = (opt.getAttribute('data-id') || '').trim();
            }
        });
        supplierIdInput.value = matchedId;
        updateSupplierInfo(matchedId);
        return matchedId;
    }

    function escHtml(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function buildPaymentInfoHtml(info) {
        const rows = [];
        if (info.bank) {
            const logo = info.bank_logo
                ? '<img src="' + escHtml(info.bank_logo) + '" alt="" style="width:22px;height:22px;object-fit:contain;border-radius:4px;vertical-align:middle;margin-right:6px;">'
                : '';
            rows.push('<div class="mb-1"><span class="text-muted small">ธนาคาร</span><div class="fw-semibold">' + logo + escHtml(info.bank) + '</div></div>');
        }
        if (info.account_name) {
            rows.push('<div class="mb-1"><span class="text-muted small">ชื่อบัญชี</span><div class="fw-semibold">' + escHtml(info.account_name) + '</div></div>');
        }
        if (info.account_number) {
            rows.push('<div class="mb-0"><span class="text-muted small">เลขที่บัญชี</span><div class="fw-semibold font-monospace">' + escHtml(info.account_number) + '</div></div>');
        }
        return '<div class="text-start small">' +
            '<div class="mb-2 fw-bold text-dark">' + escHtml(info.name) + '</div>' +
            '<div class="p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0;">' + rows.join('') + '</div>' +
            '</div>';
    }

    function stripAutoInsertedNote(note) {
        let text = String(note || '');
        if (autoInsertedNote && text.indexOf(autoInsertedNote) >= 0) {
            text = text.replace(autoInsertedNote, '').replace(/^\s*\n+/, '').replace(/\n+\s*$/, '');
        }
        return text.trim();
    }

    function applyPaymentNote(noteText) {
        if (!poNoteEl || !noteText) return;
        const base = stripAutoInsertedNote(poNoteEl.value);
        autoInsertedNote = noteText;
        poNoteEl.value = base === '' ? noteText : (base + '\n\n' + noteText);
        if (poNoteEl.value.length > 500) {
            poNoteEl.value = poNoteEl.value.slice(0, 500);
        }
    }

    function maybePromptSupplierPayment(supplierId) {
        if (!supplierId || supplierId === lastPromptedSupplierId) {
            return;
        }
        const info = SUPPLIER_PAYMENT_MAP[supplierId];
        if (!info || !info.note_text) {
            lastPromptedSupplierId = supplierId;
            return;
        }
        lastPromptedSupplierId = supplierId;
        if (typeof Swal === 'undefined') {
            applyPaymentNote(info.note_text);
            return;
        }
        Swal.fire({
            icon: 'question',
            title: 'ใส่ข้อมูลบัญชีในหมายเหตุ PO?',
            html: '<p class="small text-muted mb-3">ผู้ขายรายนี้มีข้อมูลบัญชีรับโอน — ต้องการใส่ลงหมายเหตุใบสั่งซื้อหรือไม่</p>' + buildPaymentInfoHtml(info),
            showCancelButton: true,
            confirmButtonText: 'ใส่ในหมายเหตุ',
            cancelButtonText: 'ไม่ใส่',
            confirmButtonColor: '#ea580c',
            reverseButtons: true,
            focusCancel: true,
            width: '28rem',
        }).then(function (result) {
            if (result.isConfirmed) {
                applyPaymentNote(info.note_text);
            }
        });
    }

    function onSupplierChange() {
        const matchedId = syncSupplierId();
        if (!matchedId) {
            lastPromptedSupplierId = '';
            return;
        }
        maybePromptSupplierPayment(matchedId);
    }

    searchInput.addEventListener('input', syncSupplierId);
    searchInput.addEventListener('change', onSupplierChange);

    const form = searchInput.closest('form');
    if (form) {
        form.addEventListener('submit', syncSupplierId);
    }
})();

function addRow() {
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
        '<td class="po-cell-desc" data-label="รายการ"><input type="text" name="item_description[]" class="form-control form-control-sm" required></td>' +
        '<td class="po-cell-qty" data-label="จำนวน"><input type="number" name="item_qty[]" class="form-control form-control-sm qty" step="0.001" min="0" required oninput="calculateTotal()"></td>' +
        '<td class="po-cell-unit" data-label="หน่วย"><input type="text" name="item_unit[]" class="form-control form-control-sm"></td>' +
        '<td class="po-cell-price" data-label="ราคา/หน่วย"><input type="number" name="item_price[]" class="form-control form-control-sm price" step="0.001" required oninput="calculateTotal()"></td>' +
        '<td class="po-cell-disc" data-label="ส่วนลด"><input type="text" name="item_discount[]" class="form-control form-control-sm po-discount" maxlength="20" oninput="calculateTotal()"></td>' +
        '<td class="po-cell-vat-exempt text-center" data-label="คิด VAT">' +
        '<input type="hidden" class="line-vat-exempt-val" name="item_vat_exempt[]" value="0">' +
        '<input type="checkbox" class="form-check-input line-vat-apply m-0" value="1" checked title="คิด VAT รายการนี้" aria-label="คิด VAT" onchange="if(typeof tncPurchaseSyncVatApplyHidden===\'function\'){tncPurchaseSyncVatApplyHidden(this);} calculateTotal();">' +
        '</td>' +
        '<td class="po-cell-total" data-label="ยอดรวม"><input type="text" class="form-control form-control-sm row-total bg-light text-end fw-semibold" value="0.00" readonly tabindex="-1"></td>' +
        '<td class="po-cell-action po-cell-action-desktop"><button type="button" class="btn btn-outline-danger btn-sm border-0 po-row-delete-btn" title="ลบแถว" aria-label="ลบแถว"><i class="bi bi-trash-fill"></i></button></td>';
}
function removeRow(btn) {
    const row = btn.closest('tr');
    if (!row) {
        return;
    }
    row.remove();
    updateRowNumbers();
    calculateTotal();
}
function updateRowNumbers() {
    document.querySelectorAll('#poTable tbody tr').forEach(function (row, i) {
        row.querySelectorAll('.po-mobile-item-no').forEach(function (el) {
            el.innerText = i + 1;
        });
    });
}
function poLineAmountAfterDiscount(qty, price, discRaw) {
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
            if (pct < 0) pct = 0; if (pct > 100) pct = 100;
            discount = money2(base * pct / 100);
        } else {
            discount = Math.min(base, money2(parseFloat(dRaw.replace(/,/g, '')) || 0));
        }
    }
    return money2(base - discount);
}
function updatePoVatBasisUi() {
    const vatModeWrap = document.getElementById('vat_mode_wrap');
    const vatEnabled = document.getElementById('vat_enabled');
    const vatModeSelect = document.getElementById('vat_mode');
    const vatModeHint = document.getElementById('vat_mode_hint');
    if (!vatEnabled) return;
    const on = vatEnabled.checked;
    if (vatModeWrap) {
        vatModeWrap.classList.toggle('po-vat-select-hidden', !on);
    }
    if (vatModeSelect) {
        vatModeSelect.disabled = !on;
        vatModeSelect.setAttribute('aria-hidden', on ? 'false' : 'true');
    }
}
function calculateTotal() {
    const vatModeInput = document.getElementById('vat_mode');
    const vatEnabledEl = document.getElementById('vat_enabled');
    const vatOn = !!(vatEnabledEl && vatEnabledEl.checked);
    let vatMode = 'exclusive';
    if (vatOn && vatModeInput) {
        vatMode = vatModeInput.value === 'inclusive' ? 'inclusive' : 'exclusive';
    }
    let taxableSum = 0;
    let exemptSum = 0;
    const rows = document.getElementById('poTable').getElementsByTagName('tbody')[0].rows;
    const bucketFn = typeof tncPurchaseSumLineVatBuckets === 'function' ? tncPurchaseSumLineVatBuckets : null;
    if (bucketFn) {
        for (const row of rows) {
            const qtyEl = row.querySelector('.qty');
            const priceEl = row.querySelector('.price');
            const totalCell = row.querySelector('.row-total');
            if (!qtyEl || !priceEl || !totalCell) {
                continue;
            }
            const total = poLineAmountAfterDiscount(qtyEl.value, priceEl.value, row.querySelector('.po-discount')?.value || '');
            totalCell.value = total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        const buckets = bucketFn(rows, function (row) {
            const qtyEl = row.querySelector('.qty');
            const priceEl = row.querySelector('.price');
            if (!qtyEl || !priceEl) {
                return 0;
            }
            return poLineAmountAfterDiscount(qtyEl.value, priceEl.value, row.querySelector('.po-discount')?.value || '');
        });
        taxableSum = buckets.taxableSum;
        exemptSum = buckets.exemptSum;
    } else {
        for (const row of rows) {
            const qtyEl = row.querySelector('.qty');
            const priceEl = row.querySelector('.price');
            const totalCell = row.querySelector('.row-total');
            if (!qtyEl || !priceEl || !totalCell) {
                continue;
            }
            const applyEl = row.querySelector('.line-vat-apply');
            if (applyEl && typeof tncPurchaseSyncVatApplyHidden === 'function') {
                tncPurchaseSyncVatApplyHidden(applyEl);
            }
            const total = poLineAmountAfterDiscount(qtyEl.value, priceEl.value, row.querySelector('.po-discount')?.value || '');
            totalCell.value = total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const hidden = row.querySelector('.line-vat-exempt-val');
            const isExempt = applyEl ? !applyEl.checked : (hidden && hidden.value === '1');
            if (isExempt) {
                exemptSum += total;
            } else {
                taxableSum += total;
            }
        }
        taxableSum = (typeof tncPurchaseMoney2 === 'function' ? tncPurchaseMoney2(taxableSum) : Math.round(taxableSum * 100 + 1e-8) / 100);
        exemptSum = (typeof tncPurchaseMoney2 === 'function' ? tncPurchaseMoney2(exemptSum) : Math.round(exemptSum * 100 + 1e-8) / 100);
    }
    const splitFn = typeof tncPurchaseVatFromLineSums === 'function'
        ? tncPurchaseVatFromLineSums
        : function (t, e, v, m) { return tncPurchaseVatFromLineSum(t + e, v, m); };
    const split = splitFn(taxableSum, exemptSum, vatOn, vatMode);
    const subtotal = split.subtotal;
    const vat = split.vat;
    const gross = split.gross;
    const adjResult = (typeof tncPurchaseApplyAdjustmentsToTotals === 'function')
        ? tncPurchaseApplyAdjustmentsToTotals(gross, subtotal)
        : { net: gross, items: [] };
    const netTotal = adjResult.net;
    if (typeof tncPurchaseRenderAdjustmentsSummary === 'function') {
        tncPurchaseRenderAdjustmentsSummary(adjResult.items || []);
    }
    const lineSum = split.lineSum != null ? split.lineSum : (taxableSum + exemptSum);
    const subtotalLabelEl = document.getElementById('subtotal_label');
    if (subtotalLabelEl) {
        subtotalLabelEl.textContent = vatOn && exemptSum > 0 ? 'ยอดรายการ (คิด VAT)' : 'ยอดรายการ';
    }
    const subtotalDisplayEl = document.getElementById('subtotal_display');
    if (subtotalDisplayEl) {
        subtotalDisplayEl.innerText = subtotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    const vatExemptRow = document.getElementById('vat_exempt_row');
    const vatExemptDisplay = document.getElementById('vat_exempt_display');
    if (vatExemptRow && vatExemptDisplay) {
        if (vatOn && exemptSum > 0) {
            vatExemptRow.classList.remove('d-none');
            vatExemptDisplay.textContent = exemptSum.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
    const vatRow = document.getElementById('vat_row');
    if (vatOn) { vatRow.style.display = 'grid'; document.getElementById('vat_display').innerText = vat.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    else { vatRow.style.display = 'none'; }
    const grossDisplayEl = document.getElementById('gross_display');
    if (grossDisplayEl) {
        grossDisplayEl.innerText = gross.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    document.getElementById('grand_total').innerText = netTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const grossFormatted = netTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const submitGrandEl = document.getElementById('submit_grand_total');
    if (submitGrandEl) submitGrandEl.innerText = grossFormatted;
    const stickyGrandEl = document.getElementById('grand_total_sticky');
    if (stickyGrandEl) stickyGrandEl.innerText = grossFormatted;
    const billedTotalEl = document.getElementById('billed_total_amount');
    const billedVatEl = document.getElementById('billed_vat_amount');
    if (billedTotalEl) billedTotalEl.value = netTotal.toFixed(2);
    if (billedVatEl) billedVatEl.value = vat.toFixed(2);
    updatePoVatBasisUi();
}
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.line-vat-apply').forEach(function (cb) {
        if (typeof tncPurchaseSyncVatApplyHidden === 'function') {
            tncPurchaseSyncVatApplyHidden(cb);
        }
    });
    updatePoVatBasisUi();
    calculateTotal();
    const poTable = document.getElementById('poTable');
    if (poTable) {
        poTable.addEventListener('click', function (e) {
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
</script>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
