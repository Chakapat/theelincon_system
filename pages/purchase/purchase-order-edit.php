<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_purchase_head.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

if (!user_can('po.update')) {
    header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?error=forbidden');
    exit();
}

$poId = (int) ($_GET['id'] ?? 0);
$po = Db::rowByIdField('purchase_orders', $poId);
if ($po === null) {
    header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?error=not_found');
    exit();
}

$orderType = trim((string) ($po['order_type'] ?? 'purchase'));
if (trim((string) ($po['order_type'] ?? 'purchase')) !== 'purchase') {
    header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?error=invalid');
    exit();
}
$orderType = 'purchase';
if (Purchase::poPaidLocksMutation($po)) {
    header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?error=po_paid');
    exit();
}

$supplierRows = Db::tableRows('suppliers');
Db::sortRows($supplierRows, 'name', false);
$supplierById = Db::tableKeyed('suppliers'); 
$supplierId = (int) ($po['supplier_id'] ?? 0);
$supplierName = trim((string) (($supplierById[(string) $supplierId]['name'] ?? '')));

$items = Db::filter('purchase_order_items', static function (array $row) use ($poId): bool {
    $pid = isset($row['po_id']) ? (int) $row['po_id'] : 0;
    $purchaseOrderId = isset($row['purchase_order_id']) ? (int) $row['purchase_order_id'] : 0;
    return $pid === $poId || $purchaseOrderId === $poId;
});
Db::sortRows($items, 'id', false);

if (count($items) === 0) {
    $items = [[
        'description' => '',
        'quantity' => 0,
        'unit' => '',
        'unit_price' => 0,
    ]];
}

$poPrId = (int) ($po['pr_id'] ?? 0);
$linkedPr = $poPrId > 0 ? Db::rowByIdField('purchase_requests', $poPrId) : null;
$vatLockedFromPr = $linkedPr !== null;
$poVatModeStored = trim((string) ($po['vat_mode'] ?? 'exclusive'));
if ($vatLockedFromPr) {
    $poVatModeStored = trim((string) ($linkedPr['vat_mode'] ?? 'exclusive'));
    if (!in_array($poVatModeStored, ['exclusive', 'inclusive'], true)) {
        $poVatModeStored = 'exclusive';
    }
} elseif (!in_array($poVatModeStored, ['exclusive', 'inclusive'], true)) {
    $poVatModeStored = 'exclusive';
}
$poVatEnabledStored = $vatLockedFromPr
    ? ((int) ($linkedPr['vat_enabled'] ?? 0) === 1 ? 1 : 0)
    : ((int) ($po['vat_enabled'] ?? 0) === 1 ? 1 : 0);
$poNoteVal = trim((string) ($po['po_note'] ?? ''));
$quotationNoteVal = trim((string) ($po['quotation_note'] ?? ''));
$linkedPrNumber = $vatLockedFromPr ? trim((string) ($linkedPr['pr_number'] ?? ('PR-' . $poPrId))) : '';

$issueDateVal = trim((string) ($po['issue_date'] ?? ''));
if ($issueDateVal === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDateVal)) {
    $fallbackDate = trim((string) ($po['created_at'] ?? ''));
    $issueDateVal = ($fallbackDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $fallbackDate) === 1)
        ? substr($fallbackDate, 0, 10)
        : date('Y-m-d');
}

$supplierInvoiceNoVal = trim((string) ($po['supplier_invoice_no'] ?? ''));
$supplierInvoiceDateVal = trim((string) ($po['supplier_invoice_date'] ?? ''));
if ($supplierInvoiceDateVal === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $supplierInvoiceDateVal)) {
    $supplierInvoiceDateVal = $issueDateVal;
}
$billedTotalStored = (float) ($po['billed_total_amount'] ?? 0);
if ($billedTotalStored <= 0) {
    $billedTotalStored = (float) ($po['total_amount'] ?? 0);
}
$billedVatStored = (float) ($po['billed_vat_amount'] ?? 0);
if ($billedVatStored < 0) {
    $billedVatStored = (float) ($po['vat_amount'] ?? 0);
}

$installmentNo = (int) ($po['installment_no'] ?? 0);
$installmentTotal = max(1, (int) ($po['installment_total'] ?? 1));
$retentionValueEdit = (float) ($po['retention_amount'] ?? 0);
$errorCode = trim((string) ($_GET['error'] ?? ''));

$oldPayable = (float) ($po['payable_amount'] ?? 0);
if ($oldPayable <= 0) {
    $oldPayable = (float) ($po['total_amount'] ?? 0);
}

$sites = [];
$siteCategoryMap = [];
$poSiteId = 0;
$poCostCategoryId = 0;
$siteCategoriesForPo = [];
if (true) {
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
    $poSiteId = (int) ($po['site_id'] ?? 0);
    $poCostCategoryId = (int) ($po['cost_category_id'] ?? 0);
    if ($poSiteId <= 0 && $linkedPr !== null) {
        $poSiteId = (int) ($linkedPr['site_id'] ?? 0);
    }
    if ($poCostCategoryId <= 0 && $linkedPr !== null) {
        $poCostCategoryId = (int) ($linkedPr['cost_category_id'] ?? 0);
    }
    if ($poSiteId > 0) {
        $siteCategoriesForPo = tnc_site_category_build_select_options($poSiteId);
    }
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php tnc_purchase_head([
        'title' => 'แก้ไขใบสั่งซื้อ (PO)',
        'sarabun_weights' => '400;600;700',
    ]); ?>
    <style>
        .po-create-wrap { max-width: 1100px; }
        .card-soft {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: var(--tnc-radius-lg);
            box-shadow: var(--tnc-shadow-sm);
            background: #fff;
        }
        .po-section-head {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 1.1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #eef2f7;
        }
        .section-title { font-size: 1.05rem; font-weight: 800; color: var(--tnc-ink); margin: 0; letter-spacing: -0.02em; }
        .section-sub { font-size: 0.8rem; color: var(--tnc-muted); margin: 0.2rem 0 0; line-height: 1.4; }
        .po-field-label { font-size: 0.78rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.35rem; }
        .form-control, .form-select, .input-group-text { border-radius: 0.5rem; }
        .po-meta-card .form-control:focus { box-shadow: 0 0 0 0.2rem rgba(253, 126, 20, 0.12); }
        .po-po-number { font-size: 1.05rem; letter-spacing: 0.02em; }
        .po-table-wrap { border: 1px solid #e8ecf1; border-radius: 0.75rem; overflow: hidden; background: #fff; }
        .po-table-wrap .table { margin-bottom: 0; }
        .po-table-wrap thead th {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
            font-weight: 700;
            background: #f1f5f9 !important;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.65rem 0.5rem;
            white-space: nowrap;
        }
        .po-table-wrap tbody td { padding: 0.5rem 0.45rem; vertical-align: middle; }
        .po-table-wrap .form-control-sm { min-height: calc(1.5em + 0.6rem + 2px); }
        .summary-box {
            background: linear-gradient(180deg, #fffbf5 0%, var(--tnc-orange-soft) 100%);
            border: 1px solid var(--tnc-orange-border);
            border-radius: 0.85rem;
            padding: 1.1rem 1.15rem;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
        }
        @media (min-width: 992px) {
            .po-summary-sticky { position: sticky; top: 5.5rem; }
        }
        .summary-line {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            column-gap: 1rem;
            align-items: center;
            width: 100%;
            margin-bottom: 10px;
        }
        .summary-line:last-child { margin-bottom: 0; }
        .summary-label { justify-self: start; text-align: left; color: #475569; font-weight: 600; font-size: 0.9rem; }
        .summary-value { justify-self: end; font-weight: 700; white-space: nowrap; text-align: right; font-variant-numeric: tabular-nums; }
        .summary-grand { padding-top: 0.35rem; margin-top: 0.25rem; border-top: 2px dashed rgba(253, 126, 20, 0.25); }
        .summary-grand .summary-label { font-size: 1rem; color: var(--tnc-ink); }
        .summary-grand .summary-value { font-size: 1.25rem; color: var(--tnc-orange) !important; }
        .po-vat-panel { background: #fffbf5; border: 1px solid var(--tnc-orange-border); border-radius: 0.75rem; }
        .po-actions-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eef2f7; }
    </style>
</head>
<body class="purchase-module tnc-app-body tnc-layout-form">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container container-lg py-4 py-md-5 mb-5 po-create-wrap">
    <?php include dirname(__DIR__, 2) . '/components/purchase-subnav.php'; ?>
    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=update_po_direct&id=<?= (int) $poId ?>" method="POST" data-tnc-fullnav="1">
        <input type="hidden" name="confirm_over_contract" id="confirm_over_contract" value="">
        <?php csrf_field(); ?>

        <?php if ($errorCode === 'no_items'): ?>
            <div class="alert alert-warning py-2 mb-3">กรุณาระบุรายการอย่างน้อย 1 รายการ</div>
        <?php endif; ?>
        <?php if ($errorCode === 'need_site'): ?>
            <div class="alert alert-warning py-2 mb-3">กรุณาเลือกไซต์งาน (โครงการ)</div>
        <?php endif; ?>
        <?php if ($errorCode === 'need_cost_category'): ?>
            <div class="alert alert-warning py-2 mb-3">กรุณาเลือกหมวดค่าใช้จ่ายของไซต์</div>
        <?php endif; ?>
        <?php if ($errorCode === 'site_budget_exceeded'): ?>
            <div class="alert alert-danger py-2 mb-3">งบไซต์ไม่พอ — ไม่สามารถบันทึก PO ได้ (เกินวงเงินรวมของไซต์)</div>
        <?php endif; ?>
        <?php if ($errorCode === 'site_budget_cat_exceeded'): ?>
            <div class="alert alert-danger py-2 mb-3">งบหมวดไม่พอ — ไม่สามารถบันทึก PO ได้ (เกินวงเงินหมวดที่กำหนด)</div>
        <?php endif; ?>

        <header class="po-create-hero p-4 p-md-4 mb-4">
            <div class="row align-items-center g-3">
                <div class="col-lg">
                    <h1 class="mb-2 mt-1"><i class="bi bi-pencil-square me-2 opacity-90"></i>แก้ไขใบสั่งซื้อ</h1>
                </div>
                <div class="col-lg-auto d-none d-lg-flex flex-wrap gap-2 justify-content-lg-end">
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-list.php')) ?>" class="btn btn-light rounded-pill px-4 shadow-sm"><i class="bi bi-arrow-left me-1"></i>กลับหน้ารายการใบสั่งซื้อ</a>
                    <button type="submit" class="btn btn-orange rounded-pill px-4 shadow"><i class="bi bi-check2-circle me-1"></i>บันทึกการแก้ไข</button>
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-view.php')) ?>?id=<?= (int) $poId ?>" class="btn btn-outline-light rounded-pill px-3"><i class="bi bi-eye me-1"></i>ดูใบสั่งซื้อ</a>
                </div>
            </div>
        </header>

        <div class="card card-soft p-4 p-md-4 mb-4 po-meta-card">
            <div class="po-section-head">
                <div class="po-section-icon" aria-hidden="true"><i class="bi bi-info-lg"></i></div>
                <div>
                    <h2 class="section-title">ข้อมูลเอกสาร</h2>
                    <p class="section-sub">เลขที่ PO อ่านอย่างเดียว · แก้วันที่ออกใบ / ผู้ขาย / ไซต์ / หมวดค่าใช้จ่ายได้ตามจริง</p>
                </div>
            </div>
            <div class="row g-3 g-md-4">
                <div class="col-md-4">
                    <label class="po-field-label" for="po_number_display">เลขที่ใบสั่งซื้อ</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-tnc-orange border-end-0"><i class="bi bi-hash"></i></span>
                        <input type="text" id="po_number_display" class="form-control po-po-number bg-light text-tnc-orange fw-bold border-start-0" value="<?= htmlspecialchars((string) ($po['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="po-field-label" for="issue_date">วันที่ออกใบสั่งซื้อ <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-white text-tnc-orange" title="ปฏิทิน"><i class="bi bi-calendar3"></i></span>
                        <input
                            type="date"
                            class="form-control"
                            name="issue_date"
                            id="issue_date"
                            value="<?= htmlspecialchars($issueDateVal, ENT_QUOTES, 'UTF-8') ?>"
                            required
                            lang="th"
                            autocomplete="off"
                        >
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="po-field-label" for="supplier_search">ผู้ขาย</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white text-secondary"><i class="bi bi-shop"></i></span>
                        <input type="text" id="supplier_search" class="form-control" list="supplier_list" value="<?= htmlspecialchars($supplierName, ENT_QUOTES, 'UTF-8') ?>" placeholder="พิมพ์ชื่อแล้วเลือกจากรายการ">
                    </div>
                    <datalist id="supplier_list">
                        <?php foreach ($supplierRows as $s): ?>
                            <option
                                value="<?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-id="<?= (int) ($s['id'] ?? 0) ?>"
                            ></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="supplier_id" id="supplier_id" value="<?= (int) $supplierId ?>">
                </div>
                <div class="col-md-4">
                    <label class="po-field-label" for="supplier_invoice_no">เลขที่บิล / ใบกำกับภาษี</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white text-secondary"><i class="bi bi-receipt"></i></span>
                        <input
                            type="text"
                            name="supplier_invoice_no"
                            id="supplier_invoice_no"
                            class="form-control"
                            maxlength="120"
                            value="<?= htmlspecialchars($supplierInvoiceNoVal, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="ระบุเมื่อมีบิลจากผู้ขาย"
                        >
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="po-field-label" for="supplier_invoice_date">วันที่บิล</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white text-secondary"><i class="bi bi-calendar3"></i></span>
                        <input
                            type="date"
                            name="supplier_invoice_date"
                            id="supplier_invoice_date"
                            class="form-control"
                            value="<?= htmlspecialchars($supplierInvoiceDateVal, ENT_QUOTES, 'UTF-8') ?>"
                            lang="th"
                            autocomplete="off"
                        >
                    </div>
                </div>
                                <?php if (count($sites) > 0): ?>
                <div class="col-md-6">
                    <label class="po-field-label" for="site_id">ไซต์งาน / โครงการ <span class="text-danger">*</span></label>
                    <select name="site_id" id="site_id" class="form-select" required>
                        <option value="" disabled<?= $poSiteId <= 0 ? ' selected' : '' ?>>— เลือกไซต์งาน —</option>
                        <?php foreach ($sites as $site): ?>
                            <?php $sid = (int) ($site['id'] ?? 0); ?>
                            <?php if ($sid <= 0) { continue; } ?>
                            <option value="<?= $sid ?>"<?= $sid === $poSiteId ? ' selected' : '' ?>><?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="po-field-label" for="cost_category_id">หมวดค่าใช้จ่าย <span class="text-danger">*</span> <span class="text-muted small fw-normal">(เลือกหมวดย่อย)</span></label>
                    <select name="cost_category_id" id="cost_category_id" class="form-select" required<?= $poSiteId <= 0 ? ' disabled' : '' ?>>
                        <?php if ($poSiteId <= 0): ?>
                            <option value="" disabled selected>— เลือกไซต์ก่อน —</option>
                        <?php else: ?>
                            <option value="" disabled<?= $poCostCategoryId <= 0 ? ' selected' : '' ?>>— เลือกหมวด —</option>
                            <?php tnc_site_category_render_select_options($siteCategoriesForPo, $poCostCategoryId); ?>
                        <?php endif; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card card-soft p-4 p-md-4 mb-4">
            <div class="po-section-head">
                <div class="po-section-icon" aria-hidden="true"><i class="bi bi-list-check"></i></div>
                <div class="flex-grow-1">
                    <h2 class="section-title">รายการสินค้า / บริการ</h2>
                </div>
            </div>

            <div class="table-responsive po-table-wrap">
                <table class="table align-middle table-hover" id="poTable">
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
                            <?php
                            $discEdit = trim((string) ($item['discount_input'] ?? ''));
                            if ($discEdit === '') {
                                $dt = (string) ($item['discount_type'] ?? 'amount');
                                $dv = (float) ($item['discount_value'] ?? 0);
                                if ($dv > 0) {
                                    $discEdit = $dt === 'percent'
                                        ? (rtrim(rtrim(number_format($dv, 4, '.', ''), '0'), '.') . '%')
                                        : (string) $dv;
                                }
                            }
                            $vatApplyChecked = (int) ($item['vat_exempt'] ?? 0) !== 1;
                            ?>
                            <tr>
                                <td class="row-number text-secondary small fw-semibold"><?= $index + 1 ?></td>
                                <td><input type="text" name="item_description[]" class="form-control form-control-sm" required value="<?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="ระบุรายการ"></td>
                                <td><input type="number" name="item_qty[]" class="form-control form-control-sm qty" step="0.001" min="0" required value="<?= htmlspecialchars((string) ($item['quantity'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" oninput="calculateTotal()"></td>
                                <td><input type="text" name="item_unit[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="ชิ้น"></td>
                                <td><input type="number" name="item_price[]" class="form-control form-control-sm price" step="0.001" required value="<?= htmlspecialchars((string) ($item['unit_price'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" oninput="calculateTotal()"></td>
                                <td><input type="text" name="item_discount[]" class="form-control form-control-sm po-discount" maxlength="20" value="<?= htmlspecialchars($discEdit, ENT_QUOTES, 'UTF-8') ?>" placeholder="บาท/10%" oninput="calculateTotal()"></td>
                                <td class="text-center">
                                    <input type="hidden" class="line-vat-exempt-val" name="item_vat_exempt[]" value="<?= $vatApplyChecked ? '0' : '1' ?>">
                                    <input type="checkbox" class="form-check-input line-vat-apply m-0" value="1" title="คิด VAT รายการนี้" aria-label="คิด VAT" onchange="tncPurchaseSyncVatApplyHidden(this); calculateTotal();"<?= $vatApplyChecked ? ' checked' : '' ?>>
                                </td>
                                <td><input type="text" class="form-control form-control-sm row-total bg-light text-end fw-semibold" value="0.00" readonly tabindex="-1"></td>
                                <td>
                                    <?php if ($index > 0): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm border-0 rounded-3" onclick="removeRow(this)" title="ลบแถว"><i class="bi bi-trash-fill"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="po-actions-bar">
                <button type="button" class="btn btn-orange btn-sm rounded-pill px-3 shadow-sm" onclick="addRow()">
                    <i class="bi bi-plus-lg me-1"></i>เพิ่มรายการ
                </button>
            </div>

            <div class="row g-4 mt-1">
                <div class="col-lg-7 order-2 order-lg-1">
                    <div class="po-vat-panel p-3 mb-3">
                        <div class="small fw-bold text-secondary text-uppercase mb-2" style="letter-spacing:0.05em;">ภาษีมูลค่าเพิ่ม<?= $vatLockedFromPr ? ' (จากใบขอซื้อ)' : '' ?></div>
                        <?php if ($vatLockedFromPr): ?>
                        <?php if ($poVatEnabledStored): ?>
                        <div class="small mb-2">
                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                <?= $poVatModeStored === 'inclusive' ? 'รวม VAT' : 'แยก VAT' ?>
                            </span>
                        </div>
                        <?php else: ?>
                        <div class="small text-muted mb-2">ไม่มี VAT ในใบขอซื้อ <?= htmlspecialchars($linkedPrNumber, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $poPrId, ENT_QUOTES, 'UTF-8') ?>" class="small text-tnc-orange text-decoration-none"><i class="bi bi-pencil-square me-1"></i>แก้ไข VAT ที่ใบขอซื้อ (PR)</a>
                        <input type="hidden" name="vat_enabled" id="vat_enabled" value="<?= $poVatEnabledStored ?>">
                        <input type="hidden" name="vat_mode" id="vat_mode" value="<?= htmlspecialchars($poVatModeStored, ENT_QUOTES, 'UTF-8') ?>">
                        <?php else: ?>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="vat_enabled" id="vat_enabled" value="1" onchange="calculateTotal()"<?= $poVatEnabledStored === 1 ? ' checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="vat_enabled">มี VAT</label>
                        </div>
                        <input type="hidden" name="vat_mode" id="vat_mode" value="<?= htmlspecialchars($poVatModeStored, ENT_QUOTES, 'UTF-8') ?>">
                        <div id="vat_basis_wrap" class="pt-2 border-top border-secondary border-opacity-25">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_inclusive" value="inclusive"<?= $poVatModeStored === 'exclusive' ? '' : ' checked' ?> onchange="calculateTotal()">
                                <label class="form-check-label" for="vat_basis_inclusive">รวม VAT <span class="text-muted small">(ราคารวมภาษีแล้ว)</span></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_exclusive" value="exclusive"<?= $poVatModeStored === 'exclusive' ? ' checked' : '' ?> onchange="calculateTotal()">
                                <label class="form-check-label" for="vat_basis_exclusive">แยก VAT <span class="text-muted small">(บวก 7% จากฐาน)</span></label>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-5 order-1 order-lg-2">
                    <div class="summary-box po-summary-sticky">
                        <label class="small fw-bold text-secondary text-uppercase mb-2" style="letter-spacing:0.05em;"></label>
                        <div class="summary-line small text-muted"><span class="summary-label" id="subtotal_label">ยอดรายการ</span><strong class="summary-value text-end"><span id="subtotal_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-muted d-none" id="vat_exempt_row"><span class="summary-label">ไม่คิด VAT</span><strong class="summary-value text-end"><span id="vat_exempt_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-success" id="vat_row" style="display:none;"><span class="summary-label" id="vat_label">ภาษีมูลค่าเพิ่ม</span><strong class="summary-value text-end"><span id="vat_display">0.00</span> บาท</strong></div>
                        <div class="summary-line summary-grand fw-bold"><span class="summary-label">ยอดสุทธิ</span><strong class="summary-value text-end text-tnc-orange"><span id="grand_total">0.00</span> บาท</strong></div>
                    </div>
                    <input type="hidden" name="total_amount" id="total_amount_input" value="0">
                    <input type="hidden" name="billed_total_amount" id="billed_total_amount" value="<?= htmlspecialchars(number_format($billedTotalStored, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="billed_vat_amount" id="billed_vat_amount" value="<?= htmlspecialchars(number_format($billedVatStored, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="withholding_type" id="withholding_type" value="none">
                    <input type="hidden" name="retention_type" value="none">
                    <input type="hidden" name="retention_value" value="0">
                </div>
            </div>
        </div>

        <div class="card card-soft p-4 p-md-4 mb-4">
            <div class="po-section-head border-0 pb-0 mb-3">
                <div class="po-section-icon" aria-hidden="true"><i class="bi bi-chat-left-text"></i></div>
                <div>
                    <h2 class="section-title">หมายเหตุ</h2>
                    <p class="section-sub">แสดงบนใบ PO เมื่อพิมพ์ (ถ้ามี)</p>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="po-field-label" for="po_note">หมายเหตุ PO</label>
                    <textarea name="po_note" id="po_note" class="form-control" rows="3" maxlength="500" placeholder="หมายเหตุใบสั่งซื้อ"><?= htmlspecialchars($poNoteVal, ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="po-field-label" for="quotation_note">หมายเหตุ / เงื่อนไข (QT)</label>
                    <textarea name="quotation_note" id="quotation_note" class="form-control" rows="3" maxlength="500" placeholder="เงื่อนไขจากใบเสนอราคา (ถ้ามี)"><?= htmlspecialchars($quotationNoteVal, ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                            </div>
        </div>

        <div class="tnc-mobile-sticky-cta d-lg-none">
            <div class="tnc-mobile-sticky-inner">
                <div class="tnc-mobile-sticky-meta">
                    <div class="tnc-mobile-sticky-label">ยอดสุทธิ</div>
                    <div class="tnc-mobile-sticky-total" id="grand_total_mobile_sticky"><?= number_format((float) ($po['total_amount'] ?? 0), 2) ?></div>
                </div>
                <div class="tnc-mobile-sticky-actions">
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-list.php')) ?>" class="btn btn-outline-secondary rounded-pill btn-sm">ยกเลิก</a>
                    <button type="submit" class="btn btn-orange rounded-pill fw-bold"><i class="bi bi-check2-circle me-1"></i>บันทึก</button>
                </div>
            </div>
        </div>
    </form>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<script src="<?= htmlspecialchars(app_path('assets/js/site-category-select.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(tnc_asset_href('assets/js/purchase-vat-calc.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
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

<?php if (count($sites) > 0): ?>
(function () {
    var catMap = <?= json_encode($siteCategoryMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    var siteEl = document.getElementById('site_id');
    var catEl = document.getElementById('cost_category_id');
    var initialCatId = <?= (int) $poCostCategoryId ?>;
    if (!catEl) return;

    function populateCategories() {
        var siteId = siteEl ? parseInt(siteEl.value || '0', 10) || 0 : 0;
        var prev = parseInt(catEl.value || '0', 10) || initialCatId;
        initialCatId = 0;
        if (typeof window.tncPopulateSiteCategorySelect === 'function') {
            window.tncPopulateSiteCategorySelect(catEl, catMap, siteId, prev);
        }
    }

    if (siteEl) {
        siteEl.addEventListener('change', populateCategories);
    }
    populateCategories();

    var form = siteEl ? siteEl.closest('form') : null;
    if (form) {
        form.addEventListener('submit', function (e) {
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
            }
        });
    }
})();
<?php endif; ?>


function addRow() {
    const table = document.getElementById('poTable').getElementsByTagName('tbody')[0];
    const newRow = table.insertRow();
    const rowCount = table.rows.length;

    newRow.innerHTML = `
        <td class="row-number text-secondary small fw-semibold">${rowCount}</td>
        <td><input type="text" name="item_description[]" class="form-control form-control-sm" required placeholder="ระบุรายการ"></td>
        <td><input type="number" name="item_qty[]" class="form-control form-control-sm qty" step="0.001" min="0" required oninput="calculateTotal()"></td>
        <td><input type="text" name="item_unit[]" class="form-control form-control-sm" placeholder="ชิ้น"></td>
        <td><input type="number" name="item_price[]" class="form-control form-control-sm price" step="0.001" required oninput="calculateTotal()"></td>
        <td><input type="text" name="item_discount[]" class="form-control form-control-sm po-discount" maxlength="20" placeholder="บาท/10%" oninput="calculateTotal()"></td>
        <td class="text-center">
            <input type="hidden" class="line-vat-exempt-val" name="item_vat_exempt[]" value="0">
            <input type="checkbox" class="form-check-input line-vat-apply m-0" value="1" checked title="คิด VAT รายการนี้" aria-label="คิด VAT" onchange="tncPurchaseSyncVatApplyHidden(this); calculateTotal();">
        </td>
        <td><input type="text" class="form-control form-control-sm row-total bg-light text-end fw-semibold" value="0.00" readonly tabindex="-1"></td>
        <td><button type="button" class="btn btn-outline-danger btn-sm border-0 rounded-3" onclick="removeRow(this)" title="ลบแถว"><i class="bi bi-trash-fill"></i></button></td>
    `;
}

function removeRow(btn) {
    const row = btn.parentNode.parentNode;
    row.parentNode.removeChild(row);
    updateRowNumbers();
    calculateTotal();
}

function updateRowNumbers() {
    const rows = document.querySelectorAll('.row-number');
    rows.forEach((td, index) => {
        td.innerText = index + 1;
    });
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

function calculateTotal() {
    const FIXED_VAT_RATE = 7;
    const vatModeInput = document.getElementById('vat_mode');
    const vatEnabledEl = document.getElementById('vat_enabled');
    const vatBasisWrap = document.getElementById('vat_basis_wrap');
    const vatOn = !!(vatEnabledEl && (vatEnabledEl.type === 'hidden' ? String(vatEnabledEl.value) === '1' : vatEnabledEl.checked));
    let vatMode = (vatModeInput && vatModeInput.value) ? vatModeInput.value : 'exclusive';
    if (vatOn && vatBasisWrap) {
        const selectedBasis = document.querySelector('input[name="vat_basis"]:checked');
        vatMode = selectedBasis ? selectedBasis.value : vatMode;
    }
    if (!['inclusive', 'exclusive'].includes(vatMode)) vatMode = 'exclusive';
    if (vatModeInput && vatBasisWrap) vatModeInput.value = vatMode;

    let taxableSum = 0;
    let exemptSum = 0;
    const rows = document.getElementById('poTable').getElementsByTagName('tbody')[0].rows;

    for (const row of rows) {
        const qty = row.querySelector('.qty').value;
        const price = row.querySelector('.price').value;
        const discEl = row.querySelector('.po-discount');
        const total = poLineAmountAfterDiscount(qty, price, discEl ? discEl.value : '');
        row.querySelector('.row-total').value = total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
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

    taxableSum = Math.round(taxableSum * 100) / 100;
    exemptSum = Math.round(exemptSum * 100) / 100;
    const splitFn = typeof tncPurchaseVatFromLineSums === 'function'
        ? tncPurchaseVatFromLineSums
        : function (t, e, v, m) { return tncPurchaseVatFromLineSum(t + e, v, m); };
    const split = splitFn(taxableSum, exemptSum, vatOn, vatMode);
    const subtotal = split.subtotal;
    const vat = split.vat;
    const gross = split.gross;
    const grand = gross;
    const withholdingTypeInput = document.getElementById('withholding_type');
    if (withholdingTypeInput) {
        withholdingTypeInput.value = 'none';
    }
    if (typeof updatePoVatBasisUi === 'function') {
        updatePoVatBasisUi();
    }
    const subtotalLabel = document.getElementById('subtotal_label');
    const vatLabel = document.getElementById('vat_label');
    const lineDisplay = subtotal;
    if (subtotalLabel) {
        subtotalLabel.textContent = vatOn && exemptSum > 0 ? 'ยอดรายการ (คิด VAT)' : 'ยอดรายการ';
    }
    const vatExemptRow = document.getElementById('vat_exempt_row');
    const vatExemptDisplay = document.getElementById('vat_exempt_display');
    if (vatExemptRow && vatExemptDisplay) {
        if (vatOn && exemptSum > 0) {
            vatExemptRow.classList.remove('d-none');
            vatExemptDisplay.textContent = exemptSum.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        } else {
            vatExemptRow.classList.add('d-none');
        }
    }
    if (vatLabel) {
        if (!vatOn) {
            vatLabel.textContent = 'แยก VAT';
        } else if (vatMode === 'inclusive') {
            vatLabel.textContent = 'รวม VAT';
        } else {
            vatLabel.textContent = 'แยก VAT';
        }
    }

    document.getElementById('subtotal_display').innerText = lineDisplay.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const vatRow = document.getElementById('vat_row');
    if (vatOn) {
        vatRow.style.display = 'block';
        document.getElementById('vat_display').innerText = vat.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        vatRow.style.display = 'none';
    }
    document.getElementById('grand_total').innerText = grand.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('total_amount_input').value = grand.toFixed(2);
    const billedTotalEl = document.getElementById('billed_total_amount');
    const billedVatEl = document.getElementById('billed_vat_amount');
    if (billedTotalEl) {
        billedTotalEl.value = grand.toFixed(2);
    }
    if (billedVatEl) {
        billedVatEl.value = vat.toFixed(2);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.line-vat-apply').forEach(function (cb) {
        if (typeof tncPurchaseSyncVatApplyHidden === 'function') {
            tncPurchaseSyncVatApplyHidden(cb);
        }
    });
    calculateTotal();
});
</script>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
