<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$id = (int) ($_GET['id'] ?? 0);

$quote = Db::rowByIdField('quotations', $id);
if (!$quote) {
    die("<div class='container mt-5 alert alert-danger text-center'>ไม่พบข้อมูลใบเสนอราคาในระบบ</div>");
}

$creator = Db::rowByIdField('users', (int) ($quote['created_by'] ?? 0), 'userid') ?? [];
$quote['creator_display'] = trim(($creator['fname'] ?? '') . ' ' . ($creator['lname'] ?? ''));

$items_list = Db::filter('quotation_items', static function (array $r) use ($id): bool {
    return isset($r['quotation_id']) && (int) $r['quotation_id'] === $id;
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขใบเสนอราคา - <?= htmlspecialchars($quote['quote_number']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .card { border: none; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .border-orange { border-left: 5px solid #FF6600 !important; }
        .btn-orange { background: linear-gradient(135deg, #FF9966 0%, #FF6600 100%); color: white; border: none; border-radius: 10px; font-weight: 600; padding: 10px 25px; transition: 0.3s; }
        .btn-orange:hover { opacity: 0.9; color: white; transform: translateY(-2px); }
        .readonly-grand-total {
            font-size: 2.2rem; font-weight: bold; color: #FF6600;
            border: none; background-color: transparent !important;
            text-align: right; width: 100%; padding: 10px; outline: none;
        }
        .total-box { background: #fff; border-radius: 15px; padding: 25px; border: 1px solid #eee; }
        .remove-row { color: #dc3545; cursor: pointer; font-size: 1.3rem; transition: 0.2s; }
        .remove-row:hover { transform: scale(1.2); }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="bi bi-pencil-square" style="color:#FF6600;"></i> แก้ไขใบเสนอราคา</h3>
    </div>

    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=edit_quotation" method="POST" id="quoteForm">
        <?php csrf_field(); ?>
        <input type="hidden" name="quotation_id" value="<?= $id ?>">

        <div class="row g-4">
            <div class="col-md-6">
                <div class="card h-100 border-orange p-4">
                    <label class="form-label fw-bold">ผู้ออกใบเสนอราคา (บริษัท)</label>
                    <select name="company_id" class="form-select mb-3 shadow-sm" required>
                        <?php foreach ($companies as $com): ?>
                            <option value="<?= $com['id'] ?>" <?= ($com['id'] == $quote['company_id']) ? 'selected' : '' ?>><?= htmlspecialchars((string) $com['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="form-label fw-bold text-muted small">เลขที่ใบเสนอราคา</label>
                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($quote['quote_number']) ?>" readonly>
                    <p class="small text-muted mt-2 mb-0">ผู้ออกใบ (ตามระบบ): <?php
                        $cd = trim((string)($quote['creator_display'] ?? ''));
                        echo $cd !== '' ? htmlspecialchars($cd) : 'ไม่ระบุ';
                    ?></p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100 border-orange p-4">
                    <label class="form-label fw-bold">ลูกค้า</label>
                    <select name="customer_id" class="form-select mb-3 shadow-sm" required>
                        <?php foreach ($customers as $cus): ?>
                            <option value="<?= $cus['id'] ?>" <?= ($cus['id'] == $quote['customer_id']) ? 'selected' : '' ?>><?= htmlspecialchars((string) $cus['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small fw-bold">วันที่</label>
                            <input type="date" name="issue_date" class="form-control shadow-sm" value="<?= $quote['date'] ?>" required>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-4 border-orange">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <span class="fw-bold" style="color: #FF6600;"><i class="bi bi-list-task me-2"></i>รายการสินค้า / บริการ</span>
                <button type="button" class="btn btn-success btn-sm rounded-pill px-3" onclick="addRow()"><i class="bi bi-plus"></i> เพิ่มรายการ</button>
            </div>
            <div class="table-responsive">
                <table class="table align-middle" id="items_table">
                    <thead>
                        <tr class="small text-muted">
                            <th width="40%" class="ps-4">รายละเอียด</th>
                            <th width="12%" class="text-center">จำนวน</th>
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
                            <td><input type="number" name="price[]" class="form-control price text-end" value="<?= ((float) ($item['unit_price'] ?? 0) != 0.0) ? htmlspecialchars((string) $item['unit_price']) : '' ?>" step="0.01"></td>
                            <td><input type="number" name="total[]" class="form-control total text-end fw-bold bg-light" value="<?= htmlspecialchars((string) $item['total']) ?>" readonly></td>
                            <td class="text-center"><i class="bi bi-trash-fill remove-row remove"></i></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row mt-4 g-4">
            <div class="col-md-6">
                <div class="card p-4 border-orange h-100 shadow-sm">
                    <h6 class="fw-bold mb-3">การตั้งค่าภาษี</h6>
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" name="vat_enabled" class="form-check-input" id="vatCheck" <?= $quote['vat_amount'] > 0 ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold text-primary" for="vatCheck">ภาษีมูลค่าเพิ่ม VAT 7% (+)</label>
                    </div>
                    
                </div>
            </div>

            <div class="col-md-6">
                <div class="total-box shadow-sm border-0">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">รวมเงิน (Subtotal):</span>
                        <span id="subtotal_text" class="fw-bold text-dark">0.00</span>
                        <input type="hidden" name="subtotal" id="subtotal_input" value="<?= $quote['subtotal'] ?>">
                    </div>
                    <div class="d-flex justify-content-between mb-2 text-primary">
                        <span>ภาษีมูลค่าเพิ่ม VAT 7% (+):</span>
                        <span id="vat_text" class="fw-bold">0.00</span>
                    </div>
                    <hr class="my-3" style="border-top: 2px solid #FF6600;">
                    
                    <div class="total-container text-end">
                        <label class="form-label fw-bold text-dark small mb-0">ยอดรวมสุทธิ (Grand Total)</label>
                        <div id="grand_total_display" class="readonly-grand-total">0.00</div>
                        <input type="hidden" name="grand_total" id="grand_total_input" value="<?= $quote['grand_total'] ?>">
                    </div>
                </div>
                
                <button type="button" onclick="confirmUpdate()" class="btn btn-orange w-100 py-3 shadow mt-3">
                    <i class="bi bi-save2 me-2"></i> บันทึกการแก้ไขใบเสนอราคา
                </button>
            </div>
        </div>
    </form>
</div>

<script>
const oldData = {
    issue_date: "<?= $quote['date'] ?>",
    total: "<?= $quote['grand_total'] ?>"
};

function calculate(){
    let subtotal = 0;
    document.querySelectorAll("#items_table tbody tr").forEach(row => {
        let qty = parseFloat(row.querySelector(".qty").value) || 0;
        let price = parseFloat(row.querySelector(".price").value) || 0;
        let total = qty * price;
        
        row.querySelector(".total").value = total.toFixed(2);
        subtotal += total;
    });

    const opt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
    
    // VAT 7%
    let vat = document.getElementById("vatCheck").checked ? subtotal * 0.07 : 0;
    let grand = subtotal + vat;

    // แสดงผล UI
    document.getElementById("subtotal_text").innerText = subtotal.toLocaleString('th-TH', opt);
    document.getElementById("vat_text").innerText = "+ " + vat.toLocaleString('th-TH', opt);
    document.getElementById("grand_total_display").innerText = grand.toLocaleString('th-TH', opt);
    
    // เก็บค่าตัวเลขใส่ Hidden Input
    document.getElementById("subtotal_input").value = subtotal.toFixed(2);
    document.getElementById("grand_total_input").value = grand.toFixed(2);
}

function addRow(){
    const table = document.querySelector("#items_table tbody");
    const trs = table.querySelectorAll("tr");
    const lastRow = trs[trs.length - 1];
    
    if (lastRow) {
        const newRow = lastRow.cloneNode(true);
        newRow.querySelectorAll("input").forEach(i => {
            if (i.classList.contains('qty')) i.value = "1";
            else if (i.classList.contains('total')) i.value = "0.00";
            else if (i.classList.contains('price')) i.value = "";
            else i.value = "";
        });
        table.appendChild(newRow);
        calculate();
    }
}

document.addEventListener("click", e => {
    if(e.target.closest(".remove")){
        const rows = document.querySelectorAll("#items_table tbody tr");
        if(rows.length > 1) {
            e.target.closest("tr").remove();
            calculate();
        } else {
            Swal.fire('คำเตือน', 'ต้องมีอย่างน้อย 1 รายการ', 'warning');
        }
    }
});

document.getElementById("quoteForm").addEventListener("input", calculate);

async function confirmUpdate() {
    const newTotal = parseFloat(document.getElementById('grand_total_input').value).toFixed(2);
    const newDate = document.querySelector('input[name="issue_date"]').value;

    let diffHtml = `
        <div class="text-start">
            <p>ยืนยันการบันทึกการเปลี่ยนแปลงใบเสนอราคา?</p>
            <table class="table table-sm border small">
                <tr class="table-light"><th>รายการ</th><th>เดิม</th><th>ใหม่</th></tr>
                <tr><td>วันที่</td><td>${oldData.issue_date}</td><td class="${oldData.issue_date != newDate ? 'text-danger fw-bold' : ''}">${newDate}</td></tr>
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
        document.getElementById('quoteForm').submit();
    }
}

window.onload = calculate;
</script>
</body>
</html>