<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/line_pr_approval.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_purchase_head.php';

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
if ($requestType !== 'purchase') {
    header('Location: ' . app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&error=invalid_pr');
    exit();
}
$requestType = 'purchase';
$poPaymentFlatItems = [[
    'description' => '',
    'quantity' => 1,
    'unit' => '',
    'unit_price' => 0,
]];

$supplier_rows = Db::tableRows('suppliers');
Db::sortRows($supplier_rows, 'name', false);

try {
    $po_number = Purchase::poNumberFromPrSplit($pr, $pr_id);
} catch (InvalidArgumentException) {
    header('Location: ' . app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&error=invalid_pr_number');
    exit();
}
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

$tnc_po_submit_disabled = $pr_has_unknown_line_price;
$tnc_po_submit_label = $pr_has_unknown_line_price ? 'ไม่สามารถออกใบสั่งซื้อได้' : 'สร้างใบสั่งซื้อ';
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
    <?php tnc_purchase_head([
        'title' => 'สร้างใบสั่งซื้อจาก PR',
        'sarabun_weights' => '400;500;600;700',
    ]); ?>
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
<body class="purchase-module tnc-app-body tnc-layout-form">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
    <div class="container py-4 py-md-5">
        <div class="row justify-content-center">
            <div class="col-xl-8">
                <div class="po-from-pr-shell mx-auto">
                <div class="card po-from-pr-card">
                    <div class="po-from-pr-head">
                        <div class="<?= ($requestType === 'purchase' && $pr_needs_price_fix && count($pr_items_for_edit) > 0) ? 'd-flex flex-wrap justify-content-between align-items-start gap-2 gap-md-3' : '' ?>">
                            <div class="min-w-0 flex-grow-1">
                                <h1 class="d-flex align-items-center gap-2 mb-0">
                                    <i class="bi bi-file-earmark-plus-fill opacity-90"></i>
                                    สร้างใบสั่งซื้อ
                                </h1>
                                <div class="sub">ออกใบสั่งซื้อ (PO) -> จากใบขอซื้อ (PR)</div>
                            </div>
                            <?php if ($requestType === 'purchase' && $pr_needs_price_fix && count($pr_items_for_edit) > 0): ?>
                            <button type="button" class="btn btn-warning text-dark fw-semibold rounded-pill px-3 py-2 flex-shrink-0 align-self-start" data-bs-toggle="modal" data-bs-target="#prFixFromPoModal" id="prFixOpenBtn" title="แก้รายการสินค้าและ VAT ในใบขอซื้อ">
                                <i class="bi bi-pencil-square me-1"></i>แก้ใบขอซื้อ
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="p-4 p-md-4">
                    <?php if ($requestType === 'purchase' && $prUpdated): ?>
                        <div class="alert alert-success py-2 border-0" data-tnc-audio="update"><i class="bi bi-check-circle-fill me-1"></i>อัปเดตใบขอซื้อ (PR) แล้ว — ตรวจสอบยอดด้านล่างแล้วดำเนินการสร้าง PO ต่อได้</div>
                    <?php endif; ?>
                    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=create_po_from_pr" method="POST" enctype="multipart/form-data" data-tnc-fullnav="1">
                        <input type="hidden" name="confirm_over_contract" id="confirm_over_contract" value="">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="pr_id" value="<?= $pr['id'] ?>">

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="po-field-label">อ้างอิงใบขอซื้อ (PR)</div>
                                <input type="text" class="form-control form-control-lg bg-light border-0" value="<?= htmlspecialchars((string) ($pr['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <div class="po-field-label">เลขที่ PO (อัตโนมัติ)</div>
                                <input type="text" name="po_number" class="form-control form-control-lg bg-light border-0" value="<?= htmlspecialchars((string) $po_number, ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </div>
                        </div>
                        <div class="mb-4">
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

                        <div class="mb-4">
                            <label class="po-field-label" for="po_note">หมายเหตุใบสั่งซื้อ</label>
                            <textarea name="po_note" id="po_note" class="form-control" rows="2" maxlength="500"></textarea>
                        </div>

                        <?php
                        $prQuotationPathFromPr = trim((string) ($pr['quotation_attachment_path'] ?? ''));
                        $prQuotationNameFromPr = trim((string) ($pr['quotation_attachment_name'] ?? ''));
                        ?>
                        <div class="mb-4">
                            <label class="po-field-label" for="quotation_file">แนบใบเสนอราคา <span class="text-muted fw-normal">(ไม่บังคับ)</span></label>
                            <?php if ($prQuotationPathFromPr !== ''): ?>
                                <div class="small mb-2 text-secondary">
                                    PR นี้มีไฟล์:
                                    <a href="<?= htmlspecialchars(app_path($prQuotationPathFromPr), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($prQuotationNameFromPr !== '' ? $prQuotationNameFromPr : 'เปิดไฟล์', ENT_QUOTES, 'UTF-8') ?></a>
                                    — จะคัดลอกไปยัง PO หากไม่แนบใหม่
                                </div>
                            <?php endif; ?>
                            <input type="file" name="quotation_file" id="quotation_file" class="form-control" accept=".pdf,image/*,.jpg,.jpeg,.png,.webp,.gif,.bmp,.tif,.tiff">
                            <div class="form-text">รองรับ PDF หรือรูปภาพ</div>
                            <div class="form-check mt-3 mb-2">
                                <input class="form-check-input" type="checkbox" value="1" id="has_qt" name="has_qt">
                                <label class="form-check-label fw-semibold" for="has_qt">ระบุเลขที่ / วันที่ใบเสนอราคาเพิ่มเติม</label>
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

                        <input type="hidden" name="withholding_type" value="none">

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
                        <?php if (true): ?>
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
                                <script src="<?= htmlspecialchars(tnc_asset_href('assets/js/purchase-vat-calc.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
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
        lineAmount = (typeof tncPurchaseMoney2 === 'function' ? tncPurchaseMoney2(lineAmount) : Math.round(lineAmount * 100 + 1e-8) / 100);
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
<script>
(function () {
    const hasQt = document.getElementById('has_qt');
    const panel = document.getElementById('qt_panel');
    if (!hasQt || !panel) return;
    const fields = panel.querySelectorAll('input, textarea, select');
    function sync() {
        const on = !!hasQt.checked;
        panel.classList.toggle('d-none', !on);
        fields.forEach(function (el) {
            el.disabled = !on;
        });
    }
    hasQt.addEventListener('change', sync);
    sync();
})();
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>