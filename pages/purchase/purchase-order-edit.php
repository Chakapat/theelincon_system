<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$poId = (int) ($_GET['id'] ?? 0);
$po = Db::rowByIdField('purchase_orders', $poId);
if ($po === null) {
    header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?error=not_found');
    exit();
}

$orderType = trim((string) ($po['order_type'] ?? 'purchase'));
if (!in_array($orderType, ['purchase', 'hire'], true)) {
    $orderType = 'purchase';
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
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขใบสั่งซื้อ (PO)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .page-title { letter-spacing: 0.1px; }
        .card-soft { border: 1px solid #eef1f5; border-radius: 14px; box-shadow: 0 8px 20px rgba(16, 24, 40, 0.05); }
        .section-title { font-size: 1.03rem; font-weight: 700; color: #0d6efd; margin-bottom: 12px; }
        .table thead th { white-space: nowrap; }
        .summary-box { background: #f8fbff; border: 1px solid #dbeafe; border-radius: 12px; padding: 14px; }
        .summary-line { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; gap: 12px; }
        .summary-line:last-child { margin-bottom: 0; }
        .summary-label { color: #374151; font-weight: 600; }
        .summary-value { font-weight: 700; white-space: nowrap; }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=update_po_direct&id=<?= (int) $poId ?>" method="POST">
        <?php csrf_field(); ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold mb-0 page-title"><i class="bi bi-pencil-square text-primary me-2"></i> แก้ไขใบสั่งซื้อ (PO)</h3>
            <div>
                <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-list.php')) ?>" class="btn btn-light rounded-pill px-4 me-2">ยกเลิก</a>
                <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm fw-bold">บันทึกการแก้ไข</button>
            </div>
        </div>

        <div class="row g-3 mb-4 card-soft p-3 mx-0 bg-white">
            <div class="col-md-4">
                <label class="form-label fw-bold">เลขที่ใบสั่งซื้อ</label>
                <input type="text" class="form-control bg-light fw-bold text-primary" value="<?= htmlspecialchars((string) ($po['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">วันที่ออกใบสั่งซื้อ *</label>
                <input
                    type="date"
                    class="form-control"
                    name="issue_date"
                    value="<?= htmlspecialchars((string) ($po['issue_date'] ?? $po['created_at'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?>"
                    required
                >
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">ผู้ขาย (Supplier)</label>
                <input type="text" id="supplier_search" class="form-control" list="supplier_list" value="<?= htmlspecialchars($supplierName, ENT_QUOTES, 'UTF-8') ?>" placeholder="พิมพ์ชื่อผู้ขายเพื่อค้นหา (ถ้ามี)">
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
        </div>

        <div class="card card-soft p-4 mb-4">
            <h5 class="section-title"><i class="bi bi-file-earmark-text me-1"></i> ข้อมูลใบเสนอราคา (QT)</h5>
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label fw-bold">เลขที่ใบเสนอราคา (QT No.)</label>
                    <input type="text" name="quotation_number" class="form-control" maxlength="120" value="<?= htmlspecialchars((string) ($po['quotation_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-12">
                    <label class="form-label fw-bold">หมายเหตุ (สำหรับแสดงในใบ PO)</label>
                    <textarea name="quotation_note" class="form-control" rows="2" maxlength="500"><?= htmlspecialchars((string) ($po['quotation_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>
        </div>

        <div class="card card-soft p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="section-title mb-0"><i class="bi bi-list-check text-primary me-1"></i> รายการสินค้า/บริการ</h5>
                <div></div>
            </div>

            <div class="table-responsive">
                <table class="table align-middle" id="poTable">
                    <thead class="table-light">
                        <tr>
                            <th width="5%">#</th>
                            <th width="40%">รายการ</th>
                            <th width="12%">จำนวน</th>
                            <th width="13%">หน่วย</th>
                            <th width="15%">ราคา/หน่วย</th>
                            <th width="15%">ยอดรวม</th>
                            <th width="5%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $index => $item): ?>
                            <tr>
                                <td class="row-number"><?= $index + 1 ?></td>
                                <td><input type="text" name="item_description[]" class="form-control" required value="<?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td><input type="number" name="item_qty[]" class="form-control qty" step="0.01" min="0" required value="<?= htmlspecialchars((string) ($item['quantity'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" oninput="calculateTotal()"></td>
                                <td><input type="text" name="item_unit[]" class="form-control" value="<?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td><input type="number" name="item_price[]" class="form-control price" step="0.01" min="0" required value="<?= htmlspecialchars((string) ($item['unit_price'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" oninput="calculateTotal()"></td>
                                <td><input type="text" class="form-control row-total bg-light" value="0.00" readonly></td>
                                <td>
                                    <?php if ($index > 0): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="removeRow(this)"><i class="bi bi-trash-fill"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" onclick="addRow()">
                    <i class="bi bi-plus-circle-fill me-1"></i> เพิ่มรายการ
                </button>
                <div></div>
            </div>

            <div class="row mt-3 g-3">
                <div class="col-md-6">
                    <h6 class="fw-bold mb-3">การตั้งค่าภาษีและเงินหัก</h6>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="vat_enabled" id="vat_enabled" value="1" onchange="calculateTotal()"<?= (int) ($po['vat_enabled'] ?? 0) === 1 ? ' checked' : '' ?>>
                        <label class="form-check-label fw-bold text-primary" for="vat_enabled">บวกภาษีมูลค่าเพิ่ม VAT 7% (+)</label>
                    </div>
                    <div class="mb-3<?= (int) ($po['vat_enabled'] ?? 0) === 1 ? '' : ' d-none' ?>" id="vat_mode_wrap">
                        <label class="form-label fw-bold mb-1">รูปแบบ VAT</label>
                        <?php $vatMode = trim((string) ($po['vat_mode'] ?? 'exclusive')); ?>
                        <select class="form-select form-select-sm" name="vat_mode" id="vat_mode" onchange="calculateTotal()">
                            <option value="exclusive"<?= $vatMode === 'exclusive' ? ' selected' : '' ?>>VAT แยก (บวกเพิ่ม 7%)</option>
                            <option value="inclusive"<?= $vatMode === 'inclusive' ? ' selected' : '' ?>>VAT รวมในราคา (ถอด VAT ออก)</option>
                        </select>
                    </div>
                    <p class="small text-muted mb-3" id="vat_help_text">เมื่อเปิด VAT ระบบจะบวก VAT 7% เพิ่มจากยอดก่อน VAT</p>
                    <?php $withholdingType = trim((string) ($po['withholding_type'] ?? 'none')); if ($withholdingType === 'wht5') { $withholdingType = 'wht3'; } ?>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="wht_enabled" onchange="calculateTotal()"<?= $withholdingType === 'wht3' ? ' checked' : '' ?>>
                        <label class="form-check-label fw-bold text-danger" for="wht_enabled">หัก ณ ที่จ่าย 3% (-) <span class="text-muted fw-normal">(คิดจากยอดก่อน VAT)</span></label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="summary-box">
                        <div class="summary-line small text-muted"><span class="summary-label" id="subtotal_label">ยอดรายการ (ก่อน VAT)</span><strong class="summary-value"><span id="subtotal_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-success" id="vat_row" style="display:none;"><span class="summary-label">VAT</span><strong class="summary-value"><span id="vat_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-danger" id="wht_row" style="display:none;"><span class="summary-label">หัก ณ ที่จ่าย</span><strong class="summary-value">- <span id="wht_display">0.00</span> บาท</strong></div>
                        <hr class="my-2">
                        <div class="summary-line fs-5 fw-bold"><span>ยอดรวมสุทธิ</span><span class="text-primary"><span id="grand_total">0.00</span> บาท</span></div>
                    </div>
                    <input type="hidden" name="total_amount" id="total_amount_input" value="0">
                    <input type="hidden" name="withholding_type" id="withholding_type" value="none">
                    <input type="hidden" name="retention_type" value="none">
                    <input type="hidden" name="retention_value" value="0">
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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

function addRow() {
    const table = document.getElementById('poTable').getElementsByTagName('tbody')[0];
    const newRow = table.insertRow();
    const rowCount = table.rows.length;

    newRow.innerHTML = `
        <td class="row-number">${rowCount}</td>
        <td><input type="text" name="item_description[]" class="form-control" required placeholder="ระบุรายการสินค้า"></td>
        <td><input type="number" name="item_qty[]" class="form-control qty" step="0.01" min="0" required oninput="calculateTotal()"></td>
        <td><input type="text" name="item_unit[]" class="form-control" placeholder="หน่วย"></td>
        <td><input type="number" name="item_price[]" class="form-control price" step="0.01" min="0" required oninput="calculateTotal()"></td>
        <td><input type="text" class="form-control row-total bg-light" value="0.00" readonly></td>
        <td><button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="removeRow(this)"><i class="bi bi-trash-fill"></i></button></td>
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

function calculateTotal() {
    let lineAmount = 0;
    const rows = document.getElementById('poTable').getElementsByTagName('tbody')[0].rows;
    const vatOn = document.getElementById('vat_enabled').checked;
    const vatMode = document.getElementById('vat_mode')?.value || 'exclusive';
    const whtOn = document.getElementById('wht_enabled').checked;

    for (const row of rows) {
        const qty = parseFloat(row.querySelector('.qty').value) || 0;
        const price = parseFloat(row.querySelector('.price').value) || 0;
        const total = qty * price;
        row.querySelector('.row-total').value = total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        lineAmount += total;
    }

    lineAmount = Math.round(lineAmount * 100) / 100;
    let subtotal = lineAmount;
    let vat = 0;
    let gross = lineAmount;
    if (vatOn) {
        if (vatMode === 'inclusive') {
            vat = Math.round((lineAmount * 7 / 107) * 100) / 100;
            subtotal = Math.round((lineAmount - vat) * 100) / 100;
            gross = lineAmount;
        } else {
            vat = Math.round(subtotal * 0.07 * 100) / 100;
            gross = Math.round((subtotal + vat) * 100) / 100;
        }
    }
    const whtType = whtOn ? 'wht3' : 'none';
    const whtRate = whtOn ? 0.03 : 0;
    const wht = Math.round(subtotal * whtRate * 100) / 100;
    const grand = Math.round((gross - wht) * 100) / 100;
    const withholdingTypeInput = document.getElementById('withholding_type');
    if (withholdingTypeInput) {
        withholdingTypeInput.value = whtType;
    }
    const vatModeWrap = document.getElementById('vat_mode_wrap');
    if (vatModeWrap) {
        vatModeWrap.classList.toggle('d-none', !vatOn);
    }
    const subtotalLabel = document.getElementById('subtotal_label');
    if (subtotalLabel) {
        subtotalLabel.textContent = vatOn && vatMode === 'inclusive'
            ? 'ยอดก่อน VAT (ถอดจากยอดรวม)'
            : 'ยอดรายการ (ก่อน VAT)';
    }
    const vatHelpText = document.getElementById('vat_help_text');
    if (vatHelpText) {
        if (!vatOn) {
            vatHelpText.textContent = 'ยังไม่รวม VAT';
        } else if (vatMode === 'inclusive') {
            vatHelpText.textContent = 'ยอดรายการถือว่ารวม VAT แล้ว ระบบถอด VAT 7% ออก';
        } else {
            vatHelpText.textContent = 'ยอดรายการเป็นก่อน VAT ระบบบวก VAT 7% เพิ่ม';
        }
    }

    document.getElementById('subtotal_display').innerText = subtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const vatRow = document.getElementById('vat_row');
    if (vatOn) {
        vatRow.style.display = 'block';
        document.getElementById('vat_display').innerText = vat.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        vatRow.style.display = 'none';
    }
    const whtRow = document.getElementById('wht_row');
    if (wht > 0) {
        whtRow.style.display = 'block';
        document.getElementById('wht_display').innerText = wht.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        whtRow.style.display = 'none';
    }
    document.getElementById('grand_total').innerText = grand.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('total_amount_input').value = grand.toFixed(2);
}

document.addEventListener('DOMContentLoaded', calculateTotal);
</script>
</body>
</html>
