<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Invoice;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_invoice_head.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_po_adjustments_ui.php';

if(!isset($_SESSION['user_id'])){
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$show_success = false;
$new_inv_number = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_invoice'])) {
    if (!csrf_verify_request()) {
        die('Invalid security token. Please reload the page.');
    }
    $company_id  = $_POST['company_id'];
    $customer_id = $_POST['customer_id'];
    $issue_date  = $_POST['issue_date']; 
    $rounding_enabled = isset($_POST['rounding_enabled']);

    $money2 = static function (float $value) use ($rounding_enabled): float {
        if ($rounding_enabled) {
            return round($value, 2, PHP_ROUND_HALF_UP);
        }
        return $value >= 0 ? floor($value * 100) / 100 : ceil($value * 100) / 100;
    };
    
    // --- 1. คำนวณยอดเงินรวม ---
    $subtotal = 0;
    $running_total = 0;
    if(isset($_POST['price'])){
        foreach ($_POST['price'] as $key => $price_val) {
            $qty = floatval($_POST['quantity'][$key]);
            if (strpos($price_val, '%') !== false) {
                $percent = floatval(str_replace('%', '', $price_val));
                $item_total = $money2($running_total * ($percent / 100));
            } else {
                $unit_price = floatval($price_val);
                $item_total = $money2($qty * $unit_price);
            }
            $subtotal += $item_total;
            $running_total += $item_total; 
        }
    }

    $vat_amount = isset($_POST['vat_enabled']) ? $money2($subtotal * 0.07) : 0; 
    $wht_amount = isset($_POST['withholding_enabled']) ? $money2($subtotal * 0.03) : 0;
    $gross_after_vat = $money2($subtotal + $vat_amount);
    $adjustmentLines = tnc_po_adjustment_lines_from_post();
    $parsedAdjustments = tnc_po_parse_adjustment_lines($adjustmentLines, (float) $subtotal, (float) $gross_after_vat);
    $adjustmentDelta = tnc_po_adjustment_delta($parsedAdjustments);
    $adjustmentFields = tnc_invoice_adjustment_save_fields($parsedAdjustments);
    $retention_amount = (float) ($adjustmentFields['retention_amount'] ?? 0);
    $total_amount = $money2($gross_after_vat - $wht_amount + $adjustmentDelta);
    if ($total_amount < 0) {
        $total_amount = 0.0;
    }

    $new_inv_number = Invoice::nextInvoiceNumber($issue_date);

    $created_by = (int) $_SESSION['user_id'];
    $invoice_id = Db::nextNumericId('invoices', 'id');

    $inv_row = array_merge([
        'id' => $invoice_id,
        'invoice_number' => $new_inv_number,
        'company_id' => (int) $company_id,
        'customer_id' => (int) $customer_id,
        'issue_date' => $issue_date,
        'subtotal' => $subtotal,
        'vat_amount' => $vat_amount,
        'withholding_tax' => $wht_amount,
        'retention_amount' => $retention_amount,
        'total_amount' => $total_amount,
        'rounding_enabled' => $rounding_enabled ? 1 : 0,
        'status' => 'pending',
        'created_by' => $created_by,
    ], $adjustmentFields);
    Db::setRow('invoices', (string) $invoice_id, $inv_row);

    $current_running = 0.0;
    foreach ($_POST['description'] as $key => $desc) {
        $qty = floatval($_POST['quantity'][$key]);
        $price_input = trim($_POST['price'][$key]);
        $unit = $_POST['unit'][$key];

        if (strpos($price_input, '%') !== false) {
            $percent = floatval(str_replace('%', '', $price_input));
            $row_total = $money2($current_running * ($percent / 100));
            $final_unit_price = $qty > 0 ? round($row_total / $qty, 4) : 0.0;
        } else {
            $final_unit_price = floatval($price_input);
            $row_total = $money2($qty * $final_unit_price);
        }

        $iid = Db::nextNumericId('invoice_items', 'id');
        Db::setRow('invoice_items', (string) $iid, [
            'id' => $iid,
            'invoice_id' => $invoice_id,
            'description' => $desc,
            'quantity' => $qty,
            'unit' => $unit,
            'unit_price' => $final_unit_price,
            'total' => $row_total,
        ]);
        $current_running += $row_total;
    }

    $afterInvCreate = Db::row('invoices', (string) $invoice_id) ?? [];
    $afterLinesCreate = [];
    foreach (Db::filter('invoice_items', static function (array $r) use ($invoice_id): bool {
        return isset($r['invoice_id']) && (int) $r['invoice_id'] === $invoice_id;
    }) as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $afterLinesCreate[] = $ln;
        if (count($afterLinesCreate) >= 120) {
            break;
        }
    }
    tnc_audit_log('create', 'invoice', (string) $invoice_id, $new_inv_number !== '' ? $new_inv_number : ('#' . $invoice_id), [
        'source' => 'invoice-create.php',
        'action' => 'save_invoice',
        'after' => $afterInvCreate,
        'meta' => ['lines' => $afterLinesCreate],
    ]);

    $show_success = true;
}

$company_data = Db::tableRows('company');
Db::sortRows($company_data, 'id', false);
$customer_data = Db::tableRows('customers');
Db::sortRows($customer_data, 'name', false);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <?php tnc_invoice_head([
        'title' => 'สร้าง Invoice ใหม่',
        'sweetalert' => true,
        'sarabun_weights' => '400;500;600;700',
    ]); ?>
</head>
<body class="tnc-app-body tnc-layout-form">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5 invoice-shell">
    <div class="tnc-page-head mb-4">
        <div>
            <p class="tnc-page-kicker">Invoices</p>
            <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-receipt"></i></span>สร้างใบแจ้งหนี้</h1>
        </div>
        <?php
        require_once dirname(__DIR__, 2) . '/includes/tnc_ui.php';
        echo tnc_ui_back_previous_button([
            'fallback' => app_path('pages/invoices/invoice.php'),
            'class' => 'btn-back-home',
        ]);
        ?>
    </div>

    <form action="" method="POST" id="invoiceCreateForm">
        <?php csrf_field(); ?>
        <div class="card mb-4 invoice-card">
            <div class="card-body">
                <label class="form-label fw-bold"><i class="bi bi-calendar-event me-2"></i>วันที่ออกใบแจ้งหนี้</label>
                <input type="date" name="issue_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="card h-100 invoice-card">
                    <div class="card-header py-3 bg-white">ข้อมูลบริษัท</div>
                    <div class="card-body p-4">
                        <select id="company_select" name="company_id" class="form-select mb-3 shadow-sm" required>
                            <option value="">-- เลือกบริษัท --</option>
                            <?php foreach($company_data as $com): ?><option value="<?= $com['id']; ?>"><?= $com['name']; ?></option><?php endforeach; ?>
                        </select>
                        <input type="text" id="com_tax" class="form-control mb-2" placeholder="เลขผู้เสียภาษี" readonly>
                        <textarea id="com_address" class="form-control mb-2" rows="2" placeholder="ที่อยู่บริษัท" readonly></textarea>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100 invoice-card">
                    <div class="card-header py-3 bg-white">ข้อมูลลูกค้า</div>
                    <div class="card-body p-4">
                        <select id="customer_select" name="customer_id" class="form-select mb-3 shadow-sm" required>
                            <option value="">-- เลือกลูกค้า --</option>
                            <?php foreach($customer_data as $cus): ?><option value="<?= $cus['id']; ?>"><?= $cus['name']; ?></option><?php endforeach; ?>
                        </select>
                        <input type="text" id="cus_tax" class="form-control mb-2" placeholder="เลขผู้เสียภาษีลูกค้า" readonly>
                        <textarea id="cus_address" class="form-control mb-2" rows="2" placeholder="ที่อยู่ลูกค้า" readonly></textarea>
                        <div class="row g-2">
                            <div class="col-6"><input type="text" id="cus_email" class="form-control" placeholder="อีเมลลูกค้า" readonly></div>
                            <div class="col-6"><input type="text" id="cus_phone" class="form-control" placeholder="เบอร์โทรลูกค้า" readonly></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-4 overflow-hidden invoice-card">
            <div class="card-body p-0 invoice-table-wrap">
                <table class="table table-hover mb-0" id="items_table">
                    <thead class="table-light">
                        <tr class="small text-muted">
                            <th width="40%" class="text-center">รายการ</th>
                            <th width="12%" class="text-center">จำนวน</th>
                            <th width="12%" class="text-center">หน่วย</th>
                            <th width="15%" class="text-center">ราคา/หน่วย</th>
                            <th width="15%" class="text-center">รวมเงิน</th>
                            <th width="6%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><input type="text" name="description[]" class="form-control" required></td>
                            <td><input type="number" name="quantity[]" class="form-control qty text-center" value="1" step="0.01"></td>
                            <td><input type="text" name="unit[]" class="form-control text-center"></td>
                            <td><input type="text" name="price[]" class="form-control price text-end" value="" inputmode="decimal" autocomplete="off"></td>
                            <td><input type="number" name="total[]" class="form-control total text-end fw-bold" value="0.00" readonly></td>
                            <td class="text-center"><i class="bi bi-trash-fill text-danger remove" style="cursor:pointer;"></i></td>
                        </tr>
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
                <div class="card p-4 h-100 invoice-card">
                    <h5 class="fw-bold mb-3 section-title">การตั้งค่าภาษีและเงินหัก</h5>
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" name="vat_enabled" class="form-check-input" id="vatCheck">
                        <label class="form-check-label fw-bold text-primary">บวกภาษีมูลค่าเพิ่ม VAT 7% (+)</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" name="withholding_enabled" class="form-check-input" id="whtCheck">
                        <label class="form-check-label fw-bold text-danger">หัก ณ ที่จ่าย 3% (-) <span class="text-muted small fw-normal">(คิดจากยอดก่อน VAT)</span></label>
                    </div>
                    <?php tnc_po_render_adjustments_panel(tnc_po_adjustments_editor_seed(null), ['hint' => 'ไม่บังคับ · หักหรือบวกหลัง VAT · แสดงบนใบแจ้งหนี้']); ?>
                    <div class="form-check form-switch mt-3">
                        <input type="checkbox" name="rounding_enabled" class="form-check-input" id="roundingCheck" checked>
                        <label class="form-check-label fw-bold text-secondary" for="roundingCheck">ปัดเศษทศนิยม (หลักตัวที่ 3 ตั้งแต่ 5 ขึ้นไป)</label>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="total-box shadow-sm border-0">
                    <div class="d-flex justify-content-between mb-2"><span>ยอดรวม (Subtotal):</span> <span id="subtotal_text" class="fw-bold">0.00</span></div>
                    <div class="d-flex justify-content-between mb-2 text-primary"><span>VAT 7% (+):</span> <span id="vat_text" class="fw-bold">0.00</span></div>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2 mb-2"><span class="text-muted fw-bold">ยอดรวม VAT:</span> <span id="total_after_vat_text" class="fw-bold">0.00</span></div>
                    <div class="d-flex justify-content-between mb-2 text-danger"><span>หัก ณ ที่จ่าย 3% (-) <small class="text-muted fw-normal">(คิดจากยอดก่อน VAT)</small></span> <span id="wht_text" class="fw-bold">0.00</span></div>
                    <div class="d-flex justify-content-between mb-2 small"><span class="text-muted">ยอดรวมหลังหัก ณ ที่จ่าย:</span> <span id="after_wht_text" class="fw-bold text-dark">0.00</span></div>
                    <?php tnc_po_render_adjustments_summary_slot(); ?>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center grand-total-wrap">
                        <span class="h5 fw-bold mb-0">ยอดสุทธิ:</span>
                        <span class="grand-total-text" id="grand_total">0.00</span>
                    </div>
                </div>
                <button type="submit" name="save_invoice" class="btn btn-primary-save w-100 mt-4 d-none d-md-flex">
                    <i class="bi bi-save-fill"></i>บันทึกและออกใบแจ้งหนี้
                </button>
            </div>
        </div>

        <div class="sticky-save-bar d-md-none">
            <div class="sticky-save-inner">
                <div>
                    <div class="sticky-save-label">ยอดสุทธิ</div>
                    <div class="sticky-save-total" id="grand_total_sticky">0.00</div>
                </div>
                <button type="submit" name="save_invoice" class="btn btn-primary-save">
                    <i class="bi bi-save-fill"></i>บันทึก
                </button>
            </div>
        </div>
    </form>
</div>

<script>
const companyData = <?= json_encode($company_data); ?>;
const customerData = <?= json_encode($customer_data); ?>;

// --- ดึงข้อมูลบริษัท ---
document.getElementById('company_select').addEventListener('change', function() {
    const com = companyData.find(i => i.id == this.value);
    document.getElementById('com_tax').value = com ? (com.tax_id || '') : '';
    document.getElementById('com_address').value = com ? (com.address || '') : '';
});

// --- ดึงข้อมูลลูกค้า ---
document.getElementById('customer_select').addEventListener('change', function() {
    const cus = customerData.find(i => i.id == this.value);
    document.getElementById('cus_tax').value = cus ? (cus.tax_id || '') : '';
    document.getElementById('cus_address').value = cus ? (cus.address || '') : '';
    document.getElementById('cus_email').value = cus ? (cus.email || '') : '';
    document.getElementById('cus_phone').value = cus ? (cus.phone || '') : '';
});

function addRow(){
    const tbody = document.querySelector("#items_table tbody");
    if (!tbody || !tbody.rows.length) return;
    const row = tbody.rows[0].cloneNode(true);
    row.querySelectorAll("input").forEach(i => {
        i.value = i.classList.contains('qty') ? "1"
            : i.classList.contains('total') ? "0.00"
            : i.classList.contains('price') ? ""
            : "";
    });
    tbody.appendChild(row);
    calculate();
    const desc = row.querySelector('input[name="description[]"]');
    if (desc) {
        desc.focus({ preventScroll: false });
        row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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
    if(e.target.closest(".remove") && document.querySelectorAll("#items_table tbody tr").length > 1) { 
        e.target.closest("tr").remove(); calculate(); 
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

document.addEventListener("input", calculate);

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
        let p_in = row.querySelector(".price").value.trim();
        let row_t = p_in.includes('%')
            ? money2(running * ((parseFloat(p_in) || 0) / 100))
            : money2(qty * (parseFloat(p_in) || 0));
        row.querySelector(".total").value = row_t.toFixed(2);
        subtotal += row_t;
        running += row_t;
    });
    subtotal = money2(subtotal);
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

    const fmt = { minimumFractionDigits: 2 };
    document.getElementById("subtotal_text").innerText = subtotal.toLocaleString(undefined, fmt);
    document.getElementById("vat_text").innerText = "+ " + vat.toLocaleString(undefined, fmt);
    document.getElementById("total_after_vat_text").innerText = totalAfterVat.toLocaleString(undefined, fmt);
    document.getElementById("wht_text").innerText = "- " + wht.toLocaleString(undefined, fmt);
    document.getElementById("after_wht_text").innerText = afterWht.toLocaleString(undefined, fmt);
    document.getElementById("grand_total").innerText = grand.toLocaleString(undefined, fmt);
    const stickyTotal = document.getElementById("grand_total_sticky");
    if (stickyTotal) stickyTotal.innerText = grand.toLocaleString(undefined, fmt);
}

document.addEventListener('DOMContentLoaded', calculate);

<?php if($show_success): ?>
    Swal.fire({ icon: 'success', title: 'สำเร็จ!', text: 'บันทึกเลขที่ <?= $new_inv_number ?> เรียบร้อยแล้ว' }).then(() => { window.location.href = <?= json_encode(app_path('index.php'), JSON_UNESCAPED_SLASHES) ?>; });
<?php endif; ?>
</script>
<script src="<?= htmlspecialchars(app_path('assets/js/purchase-vat-calc.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(app_path('assets/js/po-adjustments.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>