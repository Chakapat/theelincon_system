<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$uid = (string) $_SESSION['user_id'];
$user_data = Db::row('users', $uid);
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
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .btn-orange { background-color: #fd7e14; color: white; border: none; }
        .btn-orange:hover { background-color: #e86c00; color: white; }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container mt-4">
    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=save_pr" method="POST">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold"><i class="bi bi-cart-plus-fill text-warning me-2"></i> สร้างใบขอซื้อ (PR)</h3>
            <div>
                <a href="purchase-request-list.php" class="btn btn-light rounded-pill px-4 me-2">ยกเลิก</a>
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
                            <label class="form-label fw-bold">วันที่ขอซื้อ</label>
                            <input type="date" name="created_at" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold">รายละเอียด/วัตถุประสงค์</label>
                            <textarea name="details" class="form-control" rows="2"></textarea>
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
                    <p class="small opacity-75 mb-0 mt-1">ยอดรายการ = ก่อน VAT · ระบบบวก VAT 7% เมื่อเปิด</p>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm p-4">
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
                    <div class="small text-muted mb-1">ยอดรายการ (ก่อน VAT): <span id="subtotal_display">0.00</span> บาท</div>
                    <div class="small text-muted mb-1" id="vat_row" style="display:none;">VAT 7%: <span id="vat_display">0.00</span> บาท</div>
                    <h4 class="fw-bold text-dark mb-0">ยอดรวมสุทธิ: <span id="grand_total" class="text-primary">0.00</span> บาท</h4>
                    <input type="hidden" name="total_amount" id="total_amount_input" value="0">
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

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

// ฟังก์ชันคำนวณเงินรวม (รายการ = ก่อน VAT · สุทธิ = + VAT 7% เมื่อเปิด)
function calculateTotal() {
    let subtotal = 0;
    const rows = document.getElementById('prTable').getElementsByTagName('tbody')[0].rows;
    const vatOn = document.getElementById('vat_enabled').checked;

    for (let row of rows) {
        let qty = parseFloat(row.querySelector('.qty').value) || 0;
        let price = parseFloat(row.querySelector('.price').value) || 0;
        let total = qty * price;
        row.querySelector('.row-total').value = total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        subtotal += total;
    }

    subtotal = Math.round(subtotal * 100) / 100;
    const vat = vatOn ? Math.round(subtotal * 0.07 * 100) / 100 : 0;
    const grand = Math.round((subtotal + vat) * 100) / 100;

    document.getElementById('subtotal_display').innerText = subtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const vatRow = document.getElementById('vat_row');
    if (vatOn) {
        vatRow.style.display = 'block';
        document.getElementById('vat_display').innerText = vat.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        vatRow.style.display = 'none';
    }
    document.getElementById('grand_total').innerText = grand.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('total_amount_input').value = grand.toFixed(2);
}

document.addEventListener('DOMContentLoaded', calculateTotal);
</script>
</body>
</html>