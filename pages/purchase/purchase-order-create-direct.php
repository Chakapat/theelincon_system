<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

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

$po_number = Purchase::generatePONumber();
$supplierRows = Db::tableRows('suppliers');
Db::sortRows($supplierRows, 'name', false);

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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ออกใบสั่งซื้อโดยตรง</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/purchase-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
        .po-vat-panel { background: #fffbf5; border: 1px solid var(--tnc-orange-border); border-radius: 0.75rem; }
        .po-actions-bar { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eef2f7; }
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
<body class="purchase-module tnc-app-body">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container container-lg py-4 py-md-5 mb-5 po-create-wrap">
    <?php if ($errorCode !== ''): ?>
        <div class="alert alert-danger py-2 mb-3">
            <?php
            echo match ($errorCode) {
                'supplier', 'po_supplier' => 'กรุณาเลือกผู้ขายจากรายการที่ระบบแนะนำ',
                'no_items', 'invalid_items' => 'กรุณาเพิ่มรายการอย่างน้อย 1 รายการ และกรอกจำนวน/ราคาให้ถูกต้อง',
                'billing_required' => 'กรุณากรอกเลขที่บิล/ใบกำกับภาษี',
                'payment_slip_required' => 'กรุณาแนบสลิปหรือหลักฐานการจ่ายอย่างน้อย 1 ไฟล์',
                'need_site' => 'กรุณาเลือกไซต์งาน (โครงการ)',
                'need_cost_category' => 'กรุณาเลือกหมวดค่าใช้จ่ายของไซต์',
                'upload_failed', 'upload_type' => 'อัปโหลดสลิปไม่สำเร็จ — ใช้ไฟล์รูปหรือ PDF',
                default => 'บันทึกใบสั่งซื้อไม่สำเร็จ กรุณาตรวจสอบข้อมูลและลองใหม่',
            };
            ?>
        </div>
    <?php endif; ?>

    <?php if (count($sites) === 0): ?>
        <div class="alert alert-warning py-2 mb-3">ยังไม่มีไซต์งานในระบบ — กรุณา<a href="<?= htmlspecialchars(app_path('pages/organization/sites.php'), ENT_QUOTES, 'UTF-8') ?>">เพิ่มไซต์งาน</a>ก่อนออก PO</div>
    <?php endif; ?>

    <form action="<?= htmlspecialchars($handlerUrl, ENT_QUOTES, 'UTF-8') ?>" method="POST" enctype="multipart/form-data" data-tnc-fullnav="1">
        <?php csrf_field(); ?>

        <header class="po-create-hero p-4 p-md-4 mb-4">
            <div class="row align-items-center g-3">
                <div class="col-lg">
                    <p class="purchase-page-kicker mb-1">Purchase Module</p>
                    <h1 class="h3 mb-1 fw-bold">ออกใบสั่งซื้อโดยตรง</h1>
                </div>
                <div class="col-lg-auto d-flex flex-wrap gap-2 justify-content-lg-end">
                    <a href="<?= htmlspecialchars($poListUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light rounded-pill px-4 shadow-sm"><i class="bi bi-arrow-left me-1"></i>กลับรายการ PO</a>
                </div>
            </div>
        </header>

        <div class="card card-soft p-4 p-md-4 mb-4 po-meta-card">
            <div class="row g-3 g-md-4">
                <div class="col-md-6 col-lg-3">
                    <label class="po-field-label" for="po_number_display">เลขที่ใบสั่งซื้อ (อัตโนมัติ)</label>
                    <input type="text" id="po_number_display" class="form-control po-po-number bg-light text-tnc-orange fw-bold" value="<?= htmlspecialchars($po_number, ENT_QUOTES, 'UTF-8') ?>" readonly>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label class="po-field-label" for="issue_date">วันที่ออกใบสั่งซื้อ / วันที่ใบกำกับ <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-white text-tnc-orange"><i class="bi bi-calendar3"></i></span>
                        <input type="text" name="issue_date" id="issue_date" class="form-control" value="<?= htmlspecialchars($issueDateDisplay, ENT_QUOTES, 'UTF-8') ?>" required autocomplete="off" placeholder="วัน/เดือน/ปี">
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
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
                <div class="col-md-6 col-lg-3">
                    <label class="po-field-label" for="supplier_invoice_no">เลขที่บิล / ใบกำกับภาษี</label>
                    <input type="text" name="supplier_invoice_no" id="supplier_invoice_no" class="form-control" maxlength="120">
                </div>
            </div>
            <?php if (count($sites) > 0): ?>
            <div class="row g-3 g-md-4 mt-1 pt-3 border-top border-light">
                <div class="col-md-6">
                    <label class="po-field-label" for="site_id">ไซต์งาน / โครงการ <span class="text-danger">*</span></label>
                    <select name="site_id" id="site_id" class="form-select" required>
                        <option value="" disabled selected>— เลือกไซต์งาน —</option>
                        <?php foreach ($sites as $site): ?>
                            <?php $sid = (int) ($site['id'] ?? 0); ?>
                            <?php if ($sid <= 0) { continue; } ?>
                            <option value="<?= $sid ?>"><?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="po-field-label" for="cost_category_id">หมวดค่าใช้จ่าย <span class="text-danger">*</span></label>
                    <select name="cost_category_id" id="cost_category_id" class="form-select" required disabled>
                        <option value="" disabled selected>— เลือกไซต์ก่อน —</option>
                    </select>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="card card-soft p-4 p-md-4 mb-4">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="po-field-label" for="payment_slips">แนบสลิป / หลักฐานการจ่าย</label>
                    <input type="file" name="payment_slips[]" id="payment_slips" class="form-control" accept="image/*,.pdf" multiple>
                </div>
            </div>
            <input type="hidden" name="billed_total_amount" id="billed_total_amount" value="0">
            <input type="hidden" name="billed_vat_amount" id="billed_vat_amount" value="0">
            <input type="hidden" name="payment_method" value="transfer">
        </div>

        <div class="card card-soft p-4 p-md-4 mb-4">
            <label class="po-field-label" for="po_note">หมายเหตุใบสั่งซื้อ</label>
            <textarea name="po_note" id="po_note" class="form-control" rows="2" maxlength="500" placeholder="หมายเหตุ (ถ้ามี)"></textarea>
        </div>
        <div class="card card-soft p-4 p-md-4 mb-4">
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
                            <th style="width:7.5rem;">ยอดรวม</th>
                            <th style="width:2.75rem;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $index => $item): ?>
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
                    <div class="po-vat-panel p-3 mb-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="vat_enabled" id="vat_enabled" value="1" onchange="updatePoVatBasisUi(); calculateTotal()"<?= $poVatEnabled === 1 ? ' checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="vat_enabled">มี VAT 7%</label>
                        </div>
                        <input type="hidden" name="vat_mode" id="vat_mode" value="<?= htmlspecialchars($poVatMode, ENT_QUOTES, 'UTF-8') ?>">
                        <div id="vat_basis_wrap" class="pt-2 border-top border-secondary border-opacity-25">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_inclusive" value="inclusive" onchange="calculateTotal()">
                                <label class="form-check-label" for="vat_basis_inclusive">รวม VAT <span class="text-muted small">(รวมภาษีมูลค่าเพิ่มในราคารวม)</span></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_exclusive" value="exclusive" checked onchange="calculateTotal()">
                                <label class="form-check-label" for="vat_basis_exclusive">แยก VAT <span class="text-muted small">(บวกภาษีมูลค่าเพิ่มแยกจากราคารวม)</span></label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 order-1 order-lg-2">
                    <div class="summary-box po-summary-sticky">
                        <div class="summary-line small text-muted"><span class="summary-label" id="subtotal_label">ยอดรายการ</span><strong class="summary-value"><span id="subtotal_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-success" id="vat_row" style="display:none;"><span class="summary-label" id="vat_label">ภาษีมูลค่าเพิ่ม</span><strong class="summary-value"><span id="vat_display">0.00</span> บาท</strong></div>
                        <div class="summary-line summary-grand fw-bold"><span class="summary-label">ยอดสุทธิ</span><strong class="summary-value text-tnc-orange"><span id="grand_total">0.00</span> บาท</strong></div>
                    </div>
                    <input type="hidden" name="withholding_type" id="withholding_type" value="none">
                </div>
            </div>
        </div>

        <div class="po-submit-panel mb-2">
            <div class="po-submit-panel-inner">
                <div class="po-submit-panel-meta">
                    <p class="po-submit-amount-label mb-0">ยอดสุทธิที่จะบันทึก</p>
                    <p class="po-submit-amount mb-0">
                        <span id="submit_grand_total">0.00</span>
                        <span class="po-submit-currency">บาท</span>
                    </p>
                    <p class="po-submit-hint mb-0">ตรวจสอบรายการ ไซต์งาน และหลักฐานการจ่ายก่อนยืนยัน</p>
                </div>
                <div class="po-submit-panel-action">
                    <button type="submit" class="btn btn-orange btn-lg po-submit-btn rounded-pill w-100 w-lg-auto"<?= count($sites) === 0 ? ' disabled' : '' ?>>
                        <i class="bi bi-check2-circle me-2"></i>ยืนยันสร้างใบสั่งซื้อ
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="<?= htmlspecialchars(app_path('assets/js/purchase-vat-calc.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
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
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function (e) {
            const siteEl = document.getElementById('site_id');
            const catEl = document.getElementById('cost_category_id');
            if (siteEl && siteEl.required && !(parseInt(siteEl.value || '0', 10) > 0)) {
                e.preventDefault();
                alert('กรุณาเลือกไซต์งาน (โครงการ)');
                siteEl.focus();
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
        });
    }
})();

(function () {
    var catMap = <?= json_encode($siteCategoryMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    var siteEl = document.getElementById('site_id');
    var catEl = document.getElementById('cost_category_id');
    if (!catEl) return;

    function populateCategories() {
        var siteId = siteEl ? parseInt(siteEl.value || '0', 10) || 0 : 0;
        var prev = parseInt(catEl.value || '0', 10) || 0;
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
    }

    if (siteEl) {
        siteEl.addEventListener('change', populateCategories);
    }
    populateCategories();
})();

(function () {
    const searchInput = document.getElementById('supplier_search');
    const supplierIdInput = document.getElementById('supplier_id');
    const datalist = document.getElementById('supplier_list');
    if (!searchInput || !supplierIdInput || !datalist) return;
    function syncSupplierId() {
        const typed = (searchInput.value || '').trim();
        supplierIdInput.value = '';
        if (typed === '') return;
        datalist.querySelectorAll('option').forEach(function (opt) {
            if (supplierIdInput.value === '' && (opt.value || '').trim().toLowerCase() === typed.toLowerCase()) {
                supplierIdInput.value = (opt.getAttribute('data-id') || '').trim();
            }
        });
    }
    searchInput.addEventListener('input', syncSupplierId);
    searchInput.addEventListener('change', syncSupplierId);
    const form = searchInput.closest('form');
    if (form) form.addEventListener('submit', syncSupplierId);
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
    const q = parseFloat(String(qty || '').replace(/,/g, '')) || 0;
    const p = parseFloat(String(price || '').replace(/,/g, '')) || 0;
    const base = Math.round(q * p * 100) / 100;
    const dRaw = String(discRaw || '').trim();
    let discount = 0;
    if (dRaw !== '' && base > 0) {
        const pctMatch = dRaw.match(/^([0-9]+(?:\.[0-9]+)?)\s*%$/);
        if (pctMatch) {
            let pct = parseFloat(pctMatch[1]) || 0;
            if (pct < 0) pct = 0; if (pct > 100) pct = 100;
            discount = Math.round(base * pct / 100 * 100) / 100;
        } else {
            discount = Math.min(base, Math.round((parseFloat(dRaw.replace(/,/g, '')) || 0) * 100) / 100);
        }
    }
    return Math.round((base - discount) * 100) / 100;
}
function updatePoVatBasisUi() {
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
function calculateTotal() {
    const vatModeInput = document.getElementById('vat_mode');
    const vatEnabledEl = document.getElementById('vat_enabled');
    const vatOn = !!(vatEnabledEl && vatEnabledEl.checked);
    let vatMode = 'exclusive';
    if (vatOn) {
        const selectedBasis = document.querySelector('input[name="vat_basis"]:checked');
        vatMode = selectedBasis ? selectedBasis.value : 'exclusive';
    }
    if (vatModeInput) vatModeInput.value = vatMode;
    let lineAmount = 0;
    const rows = document.getElementById('poTable').getElementsByTagName('tbody')[0].rows;
    for (const row of rows) {
        const total = poLineAmountAfterDiscount(row.querySelector('.qty').value, row.querySelector('.price').value, row.querySelector('.po-discount')?.value || '');
        row.querySelector('.row-total').value = total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        lineAmount += total;
    }
    lineAmount = Math.round(lineAmount * 100) / 100;
    const split = tncPurchaseVatFromLineSum(lineAmount, vatOn, vatMode);
    const subtotal = split.subtotal;
    const vat = split.vat;
    const gross = split.gross;
    document.getElementById('subtotal_display').innerText = subtotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const vatLabelEl = document.getElementById('vat_label');
    if (vatLabelEl) {
        vatLabelEl.textContent = vatOn ? (vatMode === 'inclusive' ? 'รวม VAT' : 'แยก VAT') : 'แยก VAT';
    }
    const vatRow = document.getElementById('vat_row');
    if (vatOn) { vatRow.style.display = 'grid'; document.getElementById('vat_display').innerText = vat.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    else { vatRow.style.display = 'none'; }
    document.getElementById('grand_total').innerText = gross.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const submitGrandEl = document.getElementById('submit_grand_total');
    if (submitGrandEl) submitGrandEl.innerText = gross.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const billedTotalEl = document.getElementById('billed_total_amount');
    const billedVatEl = document.getElementById('billed_vat_amount');
    if (billedTotalEl) billedTotalEl.value = gross.toFixed(2);
    if (billedVatEl) billedVatEl.value = vat.toFixed(2);
    updatePoVatBasisUi();
}
document.addEventListener('DOMContentLoaded', function () {
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
</body>
</html>
