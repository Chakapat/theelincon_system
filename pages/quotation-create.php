<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Invoice;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if(!isset($_SESSION['user_id'])){
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$show_success = false;
$new_qt_number = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_quotation'])) {
    $company_id  = $_POST['company_id'];
    $customer_id = $_POST['customer_id'];
    $issue_date  = $_POST['issue_date']; 
    
    // --- 1. คำนวณยอดเงินรวม ---
    $subtotal = 0;
    $running_total = 0;
    if(isset($_POST['price'])){
        foreach ($_POST['price'] as $key => $price_val) {
            $qty = floatval($_POST['quantity'][$key]);
            if (strpos($price_val, '%') !== false) {
                $percent = floatval(str_replace('%', '', $price_val));
                $item_total = $running_total * ($percent / 100);
            } else {
                $item_total = $qty * floatval($price_val);
            }
            $subtotal += $item_total;
            $running_total += $item_total; 
        }
    }

    $vat_amount = isset($_POST['vat_enabled']) ? ($subtotal * 0.07) : 0; 
    // ตัด WHT ออก ยอดรวมจึงเหลือแค่ subtotal + vat
    $total_amount = ($subtotal + $vat_amount);

    $new_qt_number = Invoice::nextQuotationNumber($issue_date);

    $created_by = (int) $_SESSION['user_id'];
    $quotation_id = Db::nextNumericId('quotations', 'id');
    Db::setRow('quotations', (string) $quotation_id, [
        'id' => $quotation_id,
        'quote_number' => $new_qt_number,
        'company_id' => (int) $company_id,
        'customer_id' => (int) $customer_id,
        'date' => $issue_date,
        'subtotal' => $subtotal,
        'vat_amount' => $vat_amount,
        'withholding_tax' => 0,
        'grand_total' => $total_amount,
        'status' => 'pending',
        'created_by' => $created_by,
    ]);

    $current_running = 0.0;
    foreach ($_POST['description'] as $key => $desc) {
        $qty = floatval($_POST['quantity'][$key]);
        $price_input = trim($_POST['price'][$key]);
        $unit = $_POST['unit'][$key];

        if (strpos($price_input, '%') !== false) {
            $percent = floatval(str_replace('%', '', $price_input));
            $row_total = round($current_running * ($percent / 100), 2);
            $final_unit_price = round($row_total / ($qty ?: 1), 4);
        } else {
            $final_unit_price = floatval($price_input);
            $row_total = round($qty * $final_unit_price, 2);
        }

        $iid = Db::nextNumericId('quotation_items', 'id');
        Db::setRow('quotation_items', (string) $iid, [
            'id' => $iid,
            'quotation_id' => $quotation_id,
            'description' => $desc,
            'quantity' => $qty,
            'unit' => $unit,
            'unit_price' => $final_unit_price,
            'total' => $row_total,
        ]);
        $current_running += $row_total;
    }
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สร้างใบเสนอราคาใหม่ | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .border-orange { border-left: 5px solid #fd7e14 !important; }
        .preview-logo { max-height: 76px; max-width: 140px; object-fit: contain; }
        .btn-orange { background: #fd7e14; color: white; border: none; padding: 12px; font-weight: 600; border-radius: 10px; }
        .btn-orange:hover { background: #e66d0a; color: white; }
        .total-box { background: #fff; border-radius: 15px; padding: 25px; border: 1px solid #eee; }
        .grand-total-text { font-size: 2rem; font-weight: bold; color: #fd7e14; }
        .text-orange { color: #fd7e14; }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?> 

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-dark">สร้างใบเสนอราคา (Quotation)</h3>
    </div>

    <form action="" method="POST">
        <div class="card mb-4 border-orange shadow-sm border-0">
            <div class="card-body">
                <label class="form-label fw-bold"><i class="bi bi-calendar-event me-2"></i>วันที่ในใบเสนอราคา</label>
                <input type="date" name="issue_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="card h-100 border-orange border-0 shadow-sm">
                    <div class="card-header py-3 fw-bold bg-white text-orange">ข้อมูลผู้เสนอราคา (บริษัท)</div>
                    <div class="card-body">
                        <select id="company_select" name="company_id" class="form-select mb-3 shadow-sm" required>
                            <option value="">-- เลือกบริษัท --</option>
                            <?php foreach ($company_data as $com): ?>
                                <option value="<?= (int) $com['id'] ?>"><?= htmlspecialchars((string) ($com['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="row g-3 align-items-start">
                            <div class="col-auto" id="com_logo_wrap" style="display:none;">
                                <img id="com_logo_img" src="" alt="โลโก้บริษัท" class="preview-logo rounded border bg-white p-1">
                            </div>
                            <div class="col">
                                <label class="form-label small text-muted mb-0">เลขผู้เสียภาษี</label>
                                <input type="text" id="com_tax" class="form-control mb-2 bg-light border-0" placeholder="—" readonly>
                                <label class="form-label small text-muted mb-0">ที่อยู่</label>
                                <textarea id="com_address" class="form-control mb-2 bg-light border-0" rows="3" placeholder="—" readonly></textarea>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted mb-0">โทรศัพท์</label>
                                        <input type="text" id="com_phone" class="form-control bg-light border-0" placeholder="—" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted mb-0">อีเมล</label>
                                        <input type="text" id="com_email" class="form-control bg-light border-0" placeholder="—" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 p-3 rounded-3 bg-light border small">
                            <div class="fw-bold text-secondary mb-2"><i class="bi bi-bank me-1"></i>บัญชีรับชำระ (จากข้อมูลบริษัท)</div>
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label small text-muted mb-0">ธนาคาร</label>
                                    <input type="text" id="com_bank_name" class="form-control form-control-sm bg-white border-0" readonly placeholder="—">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-0">ชื่อบัญชี</label>
                                    <input type="text" id="com_bank_acc_name" class="form-control form-control-sm bg-white border-0" readonly placeholder="—">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-0">เลขที่บัญชี</label>
                                    <input type="text" id="com_bank_acc_no" class="form-control form-control-sm bg-white border-0 font-monospace" readonly placeholder="—">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100 border-orange border-0 shadow-sm">
                    <div class="card-header py-3 fw-bold bg-white text-orange">ข้อมูลลูกค้า</div>
                    <div class="card-body">
                        <select id="customer_select" name="customer_id" class="form-select mb-3 shadow-sm" required>
                            <option value="">-- เลือกลูกค้า --</option>
                            <?php foreach ($customer_data as $cus): ?>
                                <option value="<?= (int) $cus['id'] ?>"><?= htmlspecialchars((string) ($cus['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="cus_type_row" class="mb-2" style="display:none;">
                            <span class="badge bg-secondary-subtle text-secondary border">ประเภท: <span id="cus_type_label"></span></span>
                        </div>
                        <div class="row g-3 align-items-start">
                            <div class="col-auto" id="cus_logo_wrap" style="display:none;">
                                <img id="cus_logo_img" src="" alt="โลโก้ลูกค้า" class="preview-logo rounded border bg-white p-1">
                            </div>
                            <div class="col">
                                <label class="form-label small text-muted mb-0">เลขผู้เสียภาษี</label>
                                <input type="text" id="cus_tax" class="form-control mb-2 bg-light border-0" placeholder="—" readonly>
                                <label class="form-label small text-muted mb-0">ที่อยู่</label>
                                <textarea id="cus_address" class="form-control mb-2 bg-light border-0" rows="3" placeholder="—" readonly></textarea>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted mb-0">โทรศัพท์</label>
                                        <input type="text" id="cus_phone" class="form-control bg-light border-0" placeholder="—" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted mb-0">อีเมล</label>
                                        <input type="text" id="cus_email" class="form-control bg-light border-0" placeholder="—" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-4 overflow-hidden border-orange border-0 shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center py-3 bg-white">
                <span class="fw-bold">รายละเอียดรายการเสนอราคา</span>
                <button type="button" class="btn btn-success btn-sm rounded-pill px-3" onclick="addRow()">+ เพิ่มแถว</button>
            </div>
            <div class="card-body p-0">
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
                            <td><input type="text" name="price[]" class="form-control price text-end" value="0.00"></td>
                            <td><input type="number" name="total[]" class="form-control total text-end fw-bold" value="0.00" readonly></td>
                            <td class="text-center"><i class="bi bi-trash-fill text-danger remove" style="cursor:pointer;"></i></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row mt-4 g-4">
            <div class="col-md-6">
                <div class="card p-4 h-100 border-orange border-0 shadow-sm">
                    <h5 class="fw-bold mb-3">การตั้งค่าภาษี</h5>
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" name="vat_enabled" class="form-check-input" id="vatCheck" checked>
                        <label class="form-check-label fw-bold text-primary">บวกภาษีมูลค่าเพิ่ม VAT 7% (+)</label>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="total-box shadow-sm border-0">
                    <div class="d-flex justify-content-between mb-2"><span>ยอดรวม (Subtotal):</span> <span id="subtotal_text" class="fw-bold">0.00</span></div>
                    <div class="d-flex justify-content-between mb-2 text-primary"><span>VAT 7% (+):</span> <span id="vat_text" class="fw-bold">0.00</span></div>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="h5 fw-bold mb-0">ยอดสุทธิ (Grand Total):</span>
                        <span class="grand-total-text" id="grand_total">0.00</span>
                    </div>
                </div>
                <button type="submit" name="save_quotation" class="btn btn-orange w-100 mt-4 shadow py-3">
                    <i class="bi bi-save-fill me-2"></i>บันทึกใบเสนอราคา
                </button>
            </div>
        </div>
    </form>
</div>

<script>
const companyData = <?= json_encode($company_data, JSON_UNESCAPED_UNICODE); ?>;
const customerData = <?= json_encode($customer_data, JSON_UNESCAPED_UNICODE); ?>;
const logoBase = <?= json_encode(upload_logos_base_url(), JSON_UNESCAPED_SLASHES); ?>;

function setVal(id, v) {
    const el = document.getElementById(id);
    if (el) el.value = v != null && v !== '' ? String(v) : '';
}

function applyCompany(com) {
    if (!com || String(com.id) === '') {
        setVal('com_tax', '');
        setVal('com_address', '');
        setVal('com_phone', '');
        setVal('com_email', '');
        setVal('com_bank_name', '');
        setVal('com_bank_acc_name', '');
        setVal('com_bank_acc_no', '');
        const lw = document.getElementById('com_logo_wrap');
        if (lw) lw.style.display = 'none';
        return;
    }
    setVal('com_tax', com.tax_id);
    setVal('com_address', com.address);
    setVal('com_phone', com.phone);
    setVal('com_email', com.email);
    setVal('com_bank_name', com.bank_name);
    setVal('com_bank_acc_name', com.bank_account_name);
    setVal('com_bank_acc_no', com.bank_account_number);
    const logo = (com.logo || '').trim();
    const wrap = document.getElementById('com_logo_wrap');
    const img = document.getElementById('com_logo_img');
    if (wrap && img) {
        if (logo) {
            img.src = logoBase + encodeURIComponent(logo.replace(/^.*[\\/]/, ''));
            wrap.style.display = '';
        } else {
            wrap.style.display = 'none';
            img.removeAttribute('src');
        }
    }
}

const customerTypeLabel = { company: 'บริษัท', individual: 'บุคคลธรรมดา' };

function applyCustomer(cus) {
    const typeRow = document.getElementById('cus_type_row');
    const typeLabel = document.getElementById('cus_type_label');
    if (!cus || String(cus.id) === '') {
        setVal('cus_tax', '');
        setVal('cus_address', '');
        setVal('cus_phone', '');
        setVal('cus_email', '');
        if (typeRow) typeRow.style.display = 'none';
        const lw = document.getElementById('cus_logo_wrap');
        if (lw) lw.style.display = 'none';
        return;
    }
    setVal('cus_tax', cus.tax_id);
    setVal('cus_address', cus.address);
    setVal('cus_phone', cus.phone);
    setVal('cus_email', cus.email);
    const ct = (cus.customer_type || 'company').toLowerCase();
    if (typeRow && typeLabel) {
        typeLabel.textContent = customerTypeLabel[ct] || cus.customer_type || '—';
        typeRow.style.display = '';
    }
    const logo = (cus.logo || '').trim();
    const wrap = document.getElementById('cus_logo_wrap');
    const img = document.getElementById('cus_logo_img');
    if (wrap && img) {
        if (logo) {
            img.src = logoBase + encodeURIComponent(logo.replace(/^.*[\\/]/, ''));
            wrap.style.display = '';
        } else {
            wrap.style.display = 'none';
            img.removeAttribute('src');
        }
    }
}

document.getElementById('company_select').addEventListener('change', function() {
    const com = companyData.find(i => String(i.id) === String(this.value));
    applyCompany(com || null);
});

document.getElementById('customer_select').addEventListener('change', function() {
    const cus = customerData.find(i => String(i.id) === String(this.value));
    applyCustomer(cus || null);
});

function addRow(){
    const tbody = document.querySelector("#items_table tbody");
    const row = tbody.rows[0].cloneNode(true);
    row.querySelectorAll("input").forEach(i => {
        i.value = i.classList.contains('qty') ? "1" : (i.classList.contains('total') || i.classList.contains('price') ? "0.00" : "");
    });
    tbody.appendChild(row);
}

document.addEventListener("click", e => { 
    if(e.target.closest(".remove") && document.querySelectorAll("#items_table tbody tr").length > 1) { 
        e.target.closest("tr").remove(); calculate(); 
    } 
});

document.addEventListener("input", calculate);

function calculate(){
    let subtotal = 0, running = 0;
    document.querySelectorAll("#items_table tbody tr").forEach(row => {
        let qty = parseFloat(row.querySelector(".qty").value) || 0;
        let p_in = row.querySelector(".price").value.trim();
        let row_t = p_in.includes('%') ? (running * (parseFloat(p_in) / 100)) : (qty * (parseFloat(p_in) || 0));
        row.querySelector(".total").value = row_t.toFixed(2);
        subtotal += row_t; running += row_t;
    });
    
    let vat = document.getElementById("vatCheck").checked ? subtotal * 0.07 : 0;
    // ตัดการคำนวณ WHT ออก
    let grand = (subtotal + vat);

    const fmt = { minimumFractionDigits: 2 };
    document.getElementById("subtotal_text").innerText = subtotal.toLocaleString(undefined, fmt);
    document.getElementById("vat_text").innerText = "+ " + vat.toLocaleString(undefined, fmt);
    document.getElementById("grand_total").innerText = grand.toLocaleString(undefined, fmt);
}

<?php if($show_success): ?>
    Swal.fire({ 
        icon: 'success', 
        title: 'สำเร็จ!', 
        text: 'บันทึกใบเสนอราคาเลขที่ <?= $new_qt_number ?> เรียบร้อยแล้ว' 
    }).then(() => { window.location.href = <?= json_encode(app_path('index.php'), JSON_UNESCAPED_SLASHES) ?>; });
<?php endif; ?>


</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>