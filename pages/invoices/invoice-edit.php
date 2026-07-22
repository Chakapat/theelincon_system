<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/invoice_cancel_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_invoice_head.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_po_adjustments_ui.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

if (!user_can('invoice.edit')) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์แก้ไขใบแจ้งหนี้');
}

$id = (int) ($_GET['id'] ?? 0);

$invoice = Db::rowByIdField('invoices', $id);
if (!$invoice) {
    die("<div class='container mt-5 alert alert-danger text-center'>ไม่พบข้อมูลใบแจ้งหนี้ในระบบ</div>");
}
if (tnc_invoice_is_cancelled($invoice)) {
    die("<div class='container mt-5 alert alert-warning text-center'>ใบแจ้งหนี้นี้ถูกยกเลิกแล้ว — ไม่สามารถแก้ไขได้<br><a href=\"" . htmlspecialchars(app_path('pages/invoices/invoice-view.php') . '?id=' . $id, ENT_QUOTES, 'UTF-8') . "\" class=\"alert-link\">กลับไปดูเอกสาร</a></div>");
}

$creator = Db::rowByIdField('users', (int) ($invoice['created_by'] ?? 0), 'userid') ?? [];
$invoice['creator_display'] = trim(($creator['fname'] ?? '') . ' ' . ($creator['lname'] ?? ''));

$items_list = Db::filter('invoice_items', static function (array $r) use ($id): bool {
    return isset($r['invoice_id']) && (int) $r['invoice_id'] === $id;
});
Db::sortRows($items_list, 'id', false);
if (count($items_list) === 0) {
    $items_list[] = ['description' => '', 'quantity' => 1, 'unit' => '', 'unit_price' => 0, 'total' => 0];
}

$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', false);
$customers = Db::tableRows('customers');
Db::sortRows($customers, 'name', false);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <?php tnc_invoice_head([
        'title' => 'แก้ไข Invoice - ' . (string) ($invoice['invoice_number'] ?? ''),
        'sweetalert' => true,
    ]); ?>
</head>
<body class="tnc-app-body tnc-layout-form">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="bi bi-pencil-square" style="color:#FF6600;"></i> แก้ไขใบแจ้งหนี้</h3>
    </div>

    <form action="<?= htmlspecialchars(app_path('actions/invoice-update.php')) ?>" method="POST" id="invoiceForm">
        <?php csrf_field(); ?>
        <input type="hidden" name="invoice_id" value="<?= $id ?>">

        <div class="row g-4">
            <div class="col-md-6">
                <div class="card h-100 border-orange p-4">
                    <label class="form-label fw-bold">ผู้ออกบิล (บริษัท)</label>
                    <select name="company_id" class="form-select mb-3 shadow-sm" required>
                        <?php foreach ($companies as $com): ?>
                            <option value="<?= $com['id'] ?>" <?= ($com['id'] == $invoice['company_id']) ? 'selected' : '' ?>><?= htmlspecialchars((string) $com['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="form-label fw-bold text-muted small">เลขที่ใบแจ้งหนี้</label>
                    <input type="text" name="invoice_number" class="form-control" value="<?= htmlspecialchars((string) ($invoice['invoice_number'] ?? '')) ?>" required>
                    <p class="small text-muted mt-2 mb-0">ผู้ออกใบ (ตามระบบ): <?php
                        $cd = trim((string)($invoice['creator_display'] ?? ''));
                        echo $cd !== '' ? htmlspecialchars($cd) : 'ไม่ระบุ';
                    ?></p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100 border-orange p-4">
                    <label class="form-label fw-bold">บริษัทผู้รับเอกสาร</label>
                    <select name="customer_id" class="form-select mb-3 shadow-sm" required>
                        <?php foreach ($customers as $cus): ?>
                            <option value="<?= $cus['id'] ?>" <?= ($cus['id'] == $invoice['customer_id']) ? 'selected' : '' ?>><?= htmlspecialchars((string) $cus['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small fw-bold">วันที่ออกใบแจ้งหนี้</label>
                            <input type="date" name="issue_date" class="form-control" value="<?= $invoice['issue_date'] ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-4 border-orange">
            <div class="table-responsive">
                <table class="table align-middle" id="items_table">
                    <thead>
                        <tr class="small text-muted">
                            <th width="40%" class="ps-4">รายละเอียด</th>
                            <th width="10%" class="text-center">จำนวน</th>
                            <th width="10%" class="text-center">หน่วย</th>
                            <th width="15%" class="text-end">ราคา/หน่วย</th>
                            <th width="15%" class="text-end pe-4">รวมเงิน</th>
                            <th width="5%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items_list as $item): ?>
                        <tr>
                            <td class="ps-4"><input type="text" name="description[]" class="form-control" value="<?= htmlspecialchars((string) $item['description']) ?>" required></td>
                            <td><input type="number" name="quantity[]" class="form-control qty text-center" value="<?= htmlspecialchars((string) $item['quantity']) ?>" step="0.01"></td>
                            <td><input type="text" name="unit[]" class="form-control text-center" value="<?= htmlspecialchars((string) $item['unit']) ?>"></td>
                            <td><input type="text" name="price[]" class="form-control price text-end" value="<?= ((float) ($item['unit_price'] ?? 0) != 0.0) ? htmlspecialchars((string) $item['unit_price']) : '' ?>" placeholder="เช่น 1500 หรือ 10%" inputmode="decimal" autocomplete="off"></td>
                            <td><input type="number" name="total[]" class="form-control total text-end fw-bold bg-light" value="<?= htmlspecialchars((string) $item['total']) ?>" readonly></td>
                            <td class="text-center"><i class="bi bi-trash-fill remove-row remove"></i></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="invoice-add-row-bar">
                <button type="button" class="btn-add-invoice-row" data-invoice-add-row title="เพิ่มรายการ (Ctrl+Enter)">
                    <i class="bi bi-plus-circle"></i>
                    <span>เพิ่มรายการ</span>
                </button>
            </div>
        </div>

        <div class="row mt-4 g-4">
            <div class="col-md-6">
                <div class="card p-4 border-orange h-100 shadow-sm">
                    <h6 class="fw-bold mb-3">การตั้งค่าภาษีและเงินหัก</h6>
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" name="vat_enabled" class="form-check-input" id="vatCheck" <?= $invoice['vat_amount'] > 0 ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold text-primary" for="vatCheck">บวกภาษีมูลค่าเพิ่ม</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" name="withholding_enabled" class="form-check-input" id="whtCheck" <?= $invoice['withholding_tax'] > 0 ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold text-danger" for="whtCheck">หัก ณ ที่จ่าย 3% <span class="text-muted small fw-normal"></span></label>
                    </div>
                    <?php tnc_po_render_adjustments_panel(tnc_po_adjustments_editor_seed($invoice), ['hint' => 'ไม่บังคับ · หักหรือบวกหลัง VAT · แสดงบนใบแจ้งหนี้']); ?>
                    <?php $roundingOn = !isset($invoice['rounding_enabled']) || (int) $invoice['rounding_enabled'] === 1; ?>
                    <div class="form-check form-switch mt-3">
                        <input type="checkbox" name="rounding_enabled" class="form-check-input" id="roundingCheck" <?= $roundingOn ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold text-secondary" for="roundingCheck">ปัดเศษทศนิยม (หลักตัวที่ 3 ตั้งแต่ 5 ขึ้นไป)</label>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="total-box shadow-sm border-0">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">ยอดรวม (Subtotal)</span>
                    <span id="subtotal_text" class="fw-bold text-dark">0.00</span>
                </div>
                <div class="d-flex justify-content-between mb-2 text-primary">
                    <span>ภาษีมูลค่าเพิ่ม</span>
                    <span id="vat_text" class="fw-bold">0.00</span>
                </div>
                <div class="d-flex justify-content-between mb-2 border-bottom pb-2 mb-2">
                    <span class="fw-bold text-muted">ยอดรวมภาษีมูลค่าเพิ่ม</span>
                    <span id="total_after_vat_text" class="fw-bold text-dark">0.00</span>
                </div>

                <div class="d-flex justify-content-between mb-2 text-danger">
                    <span>หัก ณ ที่จ่าย 3%</span>
                    <span id="wht_text" class="fw-bold">0.00</span>
                </div>
                <div class="d-flex justify-content-between mb-2" style="padding-left: 10px; border-left: 3px solid #dc3545;">
                    <span class="small text-muted fw-bold">ยอดรวมหลังหัก ณ ที่จ่าย</span>
                    <span id="after_wht_text" class="fw-bold text-dark">0.00</span>
                </div>
                <?php tnc_po_render_adjustments_summary_slot(); ?>
                <hr class="my-3" style="border-top: 2px solid #FF6600;">
                
                <div class="total-container">
                    <label class="form-label fw-bold text-dark small mb-0">ยอดสุทธิ</label>
                    <div id="grand_total_display" class="readonly-grand-total" style="font-size: 2.2rem; font-weight: bold; color: #FF6600; text-align: right;">0.00</div>
                    <input type="hidden" name="total_amount" id="grand_total" value="<?= $invoice['total_amount'] ?>">
                </div>
            </div>
                    <button type="button" onclick="confirmUpdate()" class="btn btn-orange w-100 py-3 shadow mt-3 d-none d-lg-block">
                        <i class="bi bi-save2 me-2"></i> อัปเดตข้อมูล
                    </button>
                </div>
            </div>
        </div>

        <div class="tnc-mobile-sticky-cta d-lg-none">
            <div class="tnc-mobile-sticky-inner">
                <div class="tnc-mobile-sticky-meta">
                    <div class="tnc-mobile-sticky-label">ยอดสุทธิ</div>
                    <div class="tnc-mobile-sticky-total" id="grand_total_sticky">0.00</div>
                </div>
                <div class="tnc-mobile-sticky-actions">
                    <button type="button" onclick="confirmUpdate()" class="btn btn-orange rounded-pill fw-bold">
                        <i class="bi bi-save2 me-1"></i>บันทึก
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
const oldData = {
    issue_date: "<?= $invoice['issue_date'] ?>",
    total: "<?= $invoice['total_amount'] ?>"
};

function calculate(){
    const roundingEnabled = document.getElementById("roundingCheck")?.checked ?? true;
    const money2 = (v) => {
        const n = Number(v) || 0;
        if (roundingEnabled) {
            return Math.round((n + Number.EPSILON) * 100) / 100;
        }
        return n >= 0 ? Math.floor(n * 100) / 100 : Math.ceil(n * 100) / 100;
    };

    let subtotal = 0;
    let running = 0;
    document.querySelectorAll("#items_table tbody tr").forEach(row => {
        let qty = parseFloat(row.querySelector(".qty").value) || 0;
        let pIn = row.querySelector(".price").value.trim();
        let rowTotal = pIn.includes('%')
            ? money2(running * ((parseFloat(pIn) || 0) / 100))
            : money2(qty * (parseFloat(pIn) || 0));

        row.querySelector(".total").value = rowTotal.toFixed(2);
        subtotal += rowTotal;
        running += rowTotal;
    });
    subtotal = money2(subtotal);

    // ตั้งค่าการแสดงผล Comma และทศนิยม 2 ตำแหน่ง
    const opt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
    
    let vat = document.getElementById("vatCheck").checked ? money2(subtotal * 0.07) : 0;
    let totalAfterVat = money2(subtotal + vat);
    let wht = document.getElementById("whtCheck").checked ? money2(subtotal * 0.03) : 0;
    let afterWht = money2(totalAfterVat - wht);

    let adjDelta = 0;
    let adjItems = [];
    if (typeof tncPurchaseApplyAdjustmentsToTotals === 'function') {
        const adj = tncPurchaseApplyAdjustmentsToTotals(totalAfterVat, subtotal);
        adjDelta = Number(adj.delta) || 0;
        adjItems = adj.items || [];
    }
    let grand = money2(afterWht + adjDelta);
    if (grand < 0) grand = 0;
    if (typeof tncPurchaseRenderAdjustmentsSummary === 'function') {
        tncPurchaseRenderAdjustmentsSummary(adjItems);
    }

    document.getElementById("subtotal_text").innerText = subtotal.toLocaleString('th-TH', opt);
    document.getElementById("vat_text").innerText = "+ " + vat.toLocaleString('th-TH', opt);
    document.getElementById("total_after_vat_text").innerText = totalAfterVat.toLocaleString('th-TH', opt);
    document.getElementById("wht_text").innerText = "- " + wht.toLocaleString('th-TH', opt);
    document.getElementById("after_wht_text").innerText = afterWht.toLocaleString('th-TH', opt);
    
    // แสดงยอดสุทธิตัวใหญ่แบบมี Comma
    document.getElementById("grand_total_display").innerText = grand.toLocaleString('th-TH', opt);
    
    // เก็บค่าตัวเลขดิบไว้ใน hidden input (เพื่อส่งไป save)
    document.getElementById("grand_total").value = grand.toFixed(2);
    const stickyTotal = document.getElementById("grand_total_sticky");
    if (stickyTotal) stickyTotal.innerText = grand.toLocaleString('th-TH', opt);
}

// ฟังก์ชันอื่นๆ เหมือนเดิม (addRow, confirmUpdate, etc.)
function addRow(){
    const table = document.querySelector("#items_table tbody");
    const firstRow = table?.querySelector("tr");
    if (!firstRow) return;
    const newRow = firstRow.cloneNode(true);
    newRow.querySelectorAll("input").forEach(i => {
        if (i.classList.contains('qty')) i.value = "1";
        else if (i.classList.contains('total')) i.value = "0.00";
        else if (i.classList.contains('price')) i.value = "";
        else i.value = "";
    });
    table.appendChild(newRow);
    calculate();
    const desc = newRow.querySelector('input[name="description[]"]');
    if (desc) {
        desc.focus({ preventScroll: false });
        newRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

function isLastInvoiceItemRow(row) {
    const tbody = document.querySelector("#items_table tbody");
    return tbody && row === tbody.rows[tbody.rows.length - 1];
}

document.addEventListener("click", e => {
    if (e.target.closest("[data-invoice-add-row]")) {
        e.preventDefault();
        addRow();
        return;
    }
    if(e.target.closest(".remove")){
        const rows = document.querySelectorAll("#items_table tbody tr");
        if(rows.length > 1) {
            e.target.closest("tr").remove();
            calculate();
        }
    }
});

document.addEventListener("keydown", e => {
    if (!(e.ctrlKey || e.metaKey) || e.key !== "Enter") return;
    const row = e.target.closest("#items_table tbody tr");
    if (!row || !isLastInvoiceItemRow(row)) return;
    if (!e.target.matches(".price, .total, .qty, input[name='description[]']")) return;
    e.preventDefault();
    addRow();
});

document.getElementById("invoiceForm").addEventListener("input", e => {
    calculate();
});

async function confirmUpdate() {
    const newTotal = parseFloat(document.getElementById('grand_total').value).toFixed(2);
    const newDate = document.querySelector('input[name="issue_date"]').value;

    let diffHtml = `
        <div class="text-start">
            <p>ต้องการบันทึกการเปลี่ยนแปลงใช่หรือไม่?</p>
            <table class="table table-sm border small">
                <tr class="table-light"><th>รายการ</th><th>เดิม</th><th>ใหม่</th></tr>
                <tr><td>วันที่ออก</td><td>${oldData.issue_date}</td><td class="${oldData.issue_date != newDate ? 'text-danger fw-bold' : ''}">${newDate}</td></tr>
                <tr><td>ยอดเงินสุทธิ</td><td>${parseFloat(oldData.total).toLocaleString()}</td><td class="${oldData.total != newTotal ? 'text-danger fw-bold' : ''}">${parseFloat(newTotal).toLocaleString()}</td></tr>
            </table>
        </div>
    `;

    const result = await Swal.fire({
        title: 'ยืนยันการแก้ไข',
        html: diffHtml,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#FF6600',
        confirmButtonText: 'บันทึกข้อมูล',
        cancelButtonText: 'กลับไปตรวจสอบ'
    });

    if (result.isConfirmed) {
        document.getElementById('invoiceForm').submit();
    }
}

window.onload = calculate;
</script>
<script src="<?= htmlspecialchars(app_path('assets/js/purchase-vat-calc.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(app_path('assets/js/po-adjustments.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>