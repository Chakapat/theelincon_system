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

$uid = (int) $_SESSION['user_id'];
$user_data = Db::rowByIdField('users', $uid, 'userid');
$requester_name = $user_data ? ($user_data['fname'] ?? '') . ' ' . ($user_data['lname'] ?? '') : 'Unknown User';

$current_pr_number = Purchase::nextPRNumber();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>สร้างใบขอซื้อ (PR)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .btn-orange { background-color: #fd7e14; color: white; border: none; }
        .btn-orange:hover { background-color: #e86c00; color: white; }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4">
    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php
            $err = (string) $_GET['error'];
            if ($err === 'upload_type') {
                echo 'ชนิดไฟล์แนบไม่รองรับ กรุณาแนบ PDF หรือไฟล์รูปภาพ';
            } elseif ($err === 'upload_failed') {
                echo 'อัปโหลดไฟล์แนบไม่สำเร็จ กรุณาลองใหม่';
            } elseif ($err === 'invalid_hire') {
                echo 'กรุณากรอกข้อมูลจัดจ้างให้ครบ: ผู้รับจ้าง, มูลค่าสัญญา และจำนวนงวด';
            } else {
                echo 'เกิดข้อผิดพลาด กรุณาลองใหม่';
            }
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=save_pr" method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold"><i class="bi bi-cart-plus-fill text-warning me-2"></i> สร้างใบขอซื้อ (PR)</h3>
            <div>
                <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light rounded-pill px-4 me-2">ยกเลิก</a>
                <button type="submit" class="btn btn-orange rounded-pill px-4 shadow-sm fw-bold">บันทึกใบ PR</button>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card border-0 shadow-sm p-4 h-100">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">เลขที่ใบขอซื้อ</label>
                            <input type="text" name="pr_number" class="form-control bg-light fw-bold text-primary" value="<?= $current_pr_number ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold" id="request_date_label">วันที่ขอซื้อ</label>
                            <input type="text" name="created_at" id="created_at" class="form-control" value="<?= date('d/m/Y') ?>" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold">ประเภทคำขอ</label>
                            <select name="request_type" id="request_type" class="form-select" onchange="toggleRequestTypeFields()">
                                <option value="purchase" selected>จัดซื้อ (Purchase)</option>
                                <option value="hire">จัดจ้าง (Hire)</option>
                            </select>
                        </div>
                        <div class="col-md-12 d-none" id="hire_fields_wrap">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">ผู้รับจ้าง</label>
                                    <input type="text" name="contractor_name" id="contractor_name" class="form-control" maxlength="255">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">มูลค่าสัญญา (บาท)</label>
                                    <input type="number" name="contract_value" id="contract_value" class="form-control" step="0.01" min="0">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">จำนวนงวด</label>
                                    <input type="number" name="installment_total" id="installment_total" class="form-control" min="1" max="120" value="1">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold" id="details_label">รายละเอียด/วัตถุประสงค์</label>
                            <textarea name="details" id="details_textarea" class="form-control" rows="2" placeholder="ระบุรายละเอียดที่ต้องการจัดซื้อ"></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold">แนบใบเสนอราคา (ไม่บังคับ)</label>
                            <input
                                type="file"
                                name="quotation_file"
                                class="form-control"
                                accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.bmp,.tif,.tiff"
                            >
                            <div class="form-text">รองรับไฟล์ PDF และรูปภาพทั่วไป (JPG, PNG, WEBP, GIF, BMP, TIFF)</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm p-4 h-100 text-white" style="background-color: #fd7e14;">
                    <label class="opacity-75">ผู้ขอซื้อ</label>
                    <h4 class="fw-bold"><?= $requester_name ?></h4>
                    <input type="hidden" name="requested_by" value="<?= $uid ?>">
                    <hr>
                    <p class="mb-0"><i class="bi bi-clock"></i> สถานะ: <strong>รออนุมัติ (Pending)</strong></p>
                    <hr class="opacity-50">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="vat_enabled" id="vat_enabled" value="1" onchange="calculateTotal()">
                        <label class="form-check-label fw-semibold" for="vat_enabled">รวม VAT 7%</label>
                    </div>
                    <div class="mt-2 d-none" id="vat_mode_wrap">
                        <label class="form-label form-label-sm mb-1 opacity-75">รูปแบบ VAT</label>
                        <select class="form-select form-select-sm" name="vat_mode" id="vat_mode" onchange="calculateTotal()">
                            <option value="exclusive" selected>VAT แยก (บวกเพิ่ม 7%)</option>
                            <option value="inclusive">VAT รวมในราคา</option>
                        </select>
                    </div>
                    <p class="small opacity-75 mb-0 mt-1" id="vat_help_text">ยอดรายการ = ก่อน VAT · ระบบบวก VAT 7% เมื่อเปิด</p>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm p-4" id="item_table_card">
            <table class="table align-middle" id="prTable">
                <thead class="table-light">
                    <tr>
                        <th width="5%">#</th>
                        <th width="40%">รายการสินค้า</th>
                        <th width="10%">จำนวน</th>
                        <th width="10%">หน่วย</th>
                        <th width="15%">ราคา/หน่วย</th>
                        <th width="15%">รวม</th>
                        <th width="5%"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="row-number">1</td>
                        <td><input type="text" name="item_description[]" class="form-control" required placeholder="ระบุรายการสินค้า"></td>
                        <td><input type="number" name="item_qty[]" class="form-control qty" step="0.01" required oninput="calculateTotal()"></td>
                        <td><input type="text" name="item_unit[]" class="form-control" placeholder="หน่วย"></td>
                        <td><input type="number" name="item_price[]" class="form-control price" step="0.01" required oninput="calculateTotal()"></td>
                        <td><input type="text" class="form-control row-total bg-light" value="0.00" readonly></td>
                        <td></td> </tr>
                </tbody>
            </table>
            
            <div class="d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" onclick="addRow()">
                    <i class="bi bi-plus-circle-fill me-1"></i> เพิ่มรายการสินค้า
                </button>
                <div class="text-end">
                    <div class="small text-muted mb-1"><span id="subtotal_label">ยอดรายการ (ก่อน VAT):</span> <span id="subtotal_display">0.00</span> บาท</div>
                    <div class="small text-muted mb-1" id="vat_row" style="display:none;">VAT 7%: <span id="vat_display">0.00</span> บาท</div>
                    <h4 class="fw-bold text-dark mb-0">ยอดรวมสุทธิ: <span id="grand_total" class="text-primary">0.00</span> บาท</h4>
                    <input type="hidden" name="total_amount" id="total_amount_input" value="0">
                </div>
            </div>
        </div>
    </form>

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
        <td><input type="text" name="item_description[]" class="form-control" required placeholder="ระบุรายการสินค้า"></td>
        <td><input type="number" name="item_qty[]" class="form-control qty" step="0.01" required oninput="calculateTotal()"></td>
        <td><input type="text" name="item_unit[]" class="form-control" placeholder="หน่วย"></td>
        <td><input type="number" name="item_price[]" class="form-control price" step="0.01" required oninput="calculateTotal()"></td>
        <td><input type="text" class="form-control row-total bg-light" value="0.00" readonly></td>
        <td><button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="removeRow(this)"><i class="bi bi-trash-fill"></i></button></td>
    `;
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
            let qty = parseFloat(row.querySelector('.qty').value) || 0;
            let price = parseFloat(row.querySelector('.price').value) || 0;
            let total = qty * price;
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
    const subtotalLabel = document.getElementById('subtotal_label');
    if (subtotalLabel) {
        subtotalLabel.textContent = vatOn && vatMode === 'inclusive'
            ? 'ยอดก่อน VAT (คำนวณจากราคารวม):'
            : 'ยอดรายการ (ก่อน VAT):';
    }
    const vatRow = document.getElementById('vat_row');
    if (vatOn) {
        vatRow.style.display = 'block';
        document.getElementById('vat_display').innerText = vat.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        vatRow.style.display = 'none';
    }
    document.getElementById('grand_total').innerText = grand.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('total_amount_input').value = grand.toFixed(2);

    const vatModeWrap = document.getElementById('vat_mode_wrap');
    if (vatModeWrap) {
        vatModeWrap.classList.toggle('d-none', !vatOn);
    }
    const vatHelpText = document.getElementById('vat_help_text');
    if (vatHelpText) {
        if (!vatOn) {
            vatHelpText.textContent = 'ยอดรายการยังไม่รวม VAT';
        } else if (vatMode === 'inclusive') {
            vatHelpText.textContent = 'ราคาต่อหน่วยถือว่า "รวม VAT" แล้ว · ระบบถอด VAT 7% ออกให้';
        } else {
            vatHelpText.textContent = 'ยอดรายการ = ก่อน VAT · ระบบบวก VAT 7% เพิ่ม';
        }
    }
}

function toggleRequestTypeFields() {
    const requestTypeEl = document.getElementById('request_type');
    const hireWrap = document.getElementById('hire_fields_wrap');
    const contractorName = document.getElementById('contractor_name');
    const contractValue = document.getElementById('contract_value');
    const installmentTotal = document.getElementById('installment_total');
    const itemTableCard = document.getElementById('item_table_card');
    const detailsLabel = document.getElementById('details_label');
    const detailsTextarea = document.getElementById('details_textarea');
    const requestDateLabel = document.getElementById('request_date_label');
    if (!requestTypeEl || !hireWrap || !contractorName || !contractValue || !installmentTotal || !itemTableCard || !detailsLabel || !detailsTextarea || !requestDateLabel) {
        return;
    }
    const isHire = requestTypeEl.value === 'hire';
    hireWrap.classList.toggle('d-none', !isHire);
    itemTableCard.classList.toggle('d-none', isHire);
    contractorName.required = isHire;
    contractValue.required = isHire;
    installmentTotal.required = isHire;
    detailsLabel.textContent = isHire ? 'รายละเอียดการจ้าง' : 'รายละเอียด/วัตถุประสงค์';
    requestDateLabel.textContent = isHire ? 'วันที่จัดจ้าง' : 'วันที่ขอซื้อ';
    detailsTextarea.placeholder = isHire
        ? 'ระบุรายละเอียดการจ้าง เช่น งานที่จ้าง ขอบเขตงาน เงื่อนไขงวดงาน'
        : 'ระบุรายละเอียดที่ต้องการจัดซื้อ';

    const tableInputs = itemTableCard.querySelectorAll('input[name="item_description[]"], input[name="item_qty[]"], input[name="item_price[]"]');
    tableInputs.forEach((input) => {
        input.required = !isHire;
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

    const form = dateInput.closest('form');
    form?.addEventListener('submit', (event) => {
        const raw = (dateInput.value || '').trim();
        const m = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (!m) {
            event.preventDefault();
            alert('กรุณากรอกวันที่เป็นรูปแบบ วัน/เดือน/ปี เช่น 25/04/2026');
            dateInput.focus();
            return;
        }
        const dd = Number(m[1]);
        const mm = Number(m[2]);
        const yyyy = Number(m[3]);
        const d = new Date(yyyy, mm - 1, dd);
        if (d.getFullYear() !== yyyy || d.getMonth() !== (mm - 1) || d.getDate() !== dd) {
            event.preventDefault();
            alert('วันที่ไม่ถูกต้อง กรุณาตรวจสอบใหม่');
            dateInput.focus();
            return;
        }
        dateInput.value = `${String(yyyy)}-${String(mm).padStart(2, '0')}-${String(dd).padStart(2, '0')}`;
    });
})();

document.addEventListener('DOMContentLoaded', calculateTotal);
document.addEventListener('DOMContentLoaded', toggleRequestTypeFields);
</script>
</body>
</html>