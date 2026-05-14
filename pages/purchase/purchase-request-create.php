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

$editId = (int) ($_GET['id'] ?? 0);
$editPr = null;
$editItems = [];
if ($editId > 0) {
    $editPr = Db::rowByIdField('purchase_requests', $editId);
    if ($editPr === null) {
        header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?error=invalid_pr');
        exit();
    }
    $poForPr = Db::findFirst('purchase_orders', static function (array $r) use ($editId): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $editId;
    });
    if ($poForPr !== null) {
        header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?error=pr_has_po');
        exit();
    }
    $editItems = Db::filter('purchase_request_items', static function (array $r) use ($editId): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $editId;
    });
    Db::sortRows($editItems, 'id', false);
}
$isEdit = $editPr !== null;
$current_pr_number = $isEdit ? (string) ($editPr['pr_number'] ?? '') : Purchase::nextPRNumber();
$prFormAction = $isEdit ? 'update_pr' : 'save_pr';
$requestTypeVal = $isEdit ? trim((string) ($editPr['request_type'] ?? ($editPr['procurement_type'] ?? 'purchase'))) : 'purchase';
if ($requestTypeVal !== 'hire') {
    $requestTypeVal = 'purchase';
}
$createdAtDisplay = date('d/m/Y');
if ($isEdit) {
    $rawDate = trim((string) ($editPr['created_at'] ?? ''));
    if ($rawDate !== '') {
        $ts = strtotime($rawDate);
        if ($ts !== false) {
            $createdAtDisplay = date('d/m/Y', $ts);
        }
    }
}
$editSiteId = $isEdit ? (int) ($editPr['site_id'] ?? 0) : 0;
$editDetails = $isEdit ? trim((string) ($editPr['details'] ?? '')) : '';
if ($isEdit && $editDetails === '') {
    $editDetails = trim((string) ($editPr['hire_scope_details'] ?? ''));
}
$editVatOn = $isEdit && (int) ($editPr['vat_enabled'] ?? 0) === 1;
$editVatMode = $isEdit ? trim((string) ($editPr['vat_mode'] ?? 'exclusive')) : 'exclusive';
if (!in_array($editVatMode, ['exclusive', 'inclusive'], true)) {
    $editVatMode = 'exclusive';
}
$editRequestedBy = $isEdit ? (int) ($editPr['requested_by'] ?? $uid) : $uid;
$quotationPathExisting = $isEdit ? trim((string) ($editPr['quotation_attachment_path'] ?? '')) : '';
$quotationNameExisting = $isEdit ? trim((string) ($editPr['quotation_attachment_name'] ?? '')) : '';
$hireContractorEdit = $isEdit ? trim((string) ($editPr['contractor_name'] ?? ($editPr['hire_contractor_name'] ?? ''))) : '';
$hireValueEdit = $isEdit ? (float) ($editPr['contract_value'] ?? ($editPr['hire_total_value'] ?? 0)) : 0.0;
$hireInstallEdit = $isEdit ? (int) ($editPr['installment_total'] ?? ($editPr['hire_installment_count'] ?? 1)) : 1;
if ($hireInstallEdit < 1) {
    $hireInstallEdit = 1;
}

$sites = Db::tableRows('sites');
usort($sites, static function (array $a, array $b): int {
    $sort = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
    if ($sort !== 0) {
        return $sort;
    }

    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title><?= $isEdit ? 'แก้ไขใบขอซื้อ (PR)' : 'สร้างใบขอซื้อ (PR)' ?></title>
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

<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php
            $err = (string) $_GET['error'];
            if ($err === 'upload_type') {
                echo 'ชนิดไฟล์แนบไม่รองรับ กรุณาแนบ PDF หรือไฟล์รูปภาพ';
            } elseif ($err === 'upload_failed') {
                echo 'อัปโหลดไฟล์แนบไม่สำเร็จ กรุณาลองใหม่';
            } elseif ($err === 'need_site') {
                echo 'กรุณาเลือกไซต์งาน';
            } elseif ($err === 'no_items') {
                echo 'กรุณาระบุอย่างน้อย 1 รายการสินค้าที่มีจำนวนและราคาถูกต้อง';
            } elseif ($err === 'invalid_hire' || $err === 'hire_invalid') {
                echo 'กรุณากรอกข้อมูลจัดจ้างให้ครบ: ผู้รับจ้าง, มูลค่าสัญญา และจำนวนงวด';
            } else {
                echo 'เกิดข้อผิดพลาด กรุณาลองใหม่';
            }
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (count($sites) === 0): ?>
        <div class="alert alert-warning">ยังไม่มีข้อมูลไซต์งานในระบบ — ผู้ดูแลต้องเพิ่มที่เมนู «ไซต์งาน» ก่อนจึงจะสร้างใบขอซื้อได้</div>
    <?php endif; ?>
    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=<?= htmlspecialchars($prFormAction, ENT_QUOTES, 'UTF-8') ?>" method="POST" enctype="multipart/form-data" data-tnc-fullnav="1">
        <?php csrf_field(); ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="pr_id" value="<?= (int) $editId ?>">
            <input type="hidden" name="request_type" value="<?= htmlspecialchars($requestTypeVal, ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold"><i class="bi bi-cart-plus-fill text-warning me-2"></i> <?= $isEdit ? 'แก้ไขใบขอซื้อ (PR)' : 'สร้างใบขอซื้อ (PR)' ?></h3>
            <div>
                <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light rounded-pill px-4 me-2">ยกเลิก</a>
                <button type="submit" class="btn btn-orange rounded-pill px-4 shadow-sm fw-bold" <?= count($sites) === 0 ? 'disabled' : '' ?>><?= $isEdit ? 'บันทึกการแก้ไข' : 'บันทึกใบ PR' ?></button>
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
                            <input type="text" name="created_at" id="created_at" class="form-control" value="<?= htmlspecialchars($createdAtDisplay, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold">ประเภทคำขอ</label>
                            <select name="request_type" id="request_type" class="form-select" onchange="toggleRequestTypeFields()"<?= $isEdit ? ' disabled' : '' ?>>
                                <option value="purchase"<?= $requestTypeVal === 'purchase' ? ' selected' : '' ?>>จัดซื้อ (Purchase)</option>
                                <option value="hire"<?= $requestTypeVal === 'hire' ? ' selected' : '' ?>>จัดจ้าง (Hire)</option>
                            </select>
                            <?php if ($isEdit): ?>
                                <div class="form-text">ไม่สามารถเปลี่ยนประเภทหลังสร้างแล้ว — หากต้องการประเภทอื่นให้สร้างใบ PR ใหม่</div>
                            <?php endif; ?>
                        </div>
                        <?php if (count($sites) > 0): ?>
                        <div class="col-md-12">
                            <label class="form-label fw-bold">ไซต์งาน <span class="text-danger">*</span></label>
                            <select name="site_id" id="site_id" class="form-select" required>
                                <option value="" disabled<?= $editSiteId <= 0 ? ' selected' : '' ?>>— เลือกไซต์งาน —</option>
                                <?php foreach ($sites as $site): ?>
                                    <?php $sid = (int) ($site['id'] ?? 0); ?>
                                    <?php if ($sid <= 0) { continue; } ?>
                                    <option value="<?= $sid ?>"<?= $sid === $editSiteId ? ' selected' : '' ?>><?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-12 d-none" id="hire_fields_wrap">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">ผู้รับจ้าง</label>
                                    <input type="text" name="contractor_name" id="contractor_name" class="form-control" maxlength="255" value="<?= htmlspecialchars($hireContractorEdit, ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">มูลค่าสัญญา (บาท)</label>
                                    <input type="number" name="contract_value" id="contract_value" class="form-control" step="0.01" min="0" value="<?= $hireValueEdit > 0 ? htmlspecialchars((string) $hireValueEdit, ENT_QUOTES, 'UTF-8') : '' ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">จำนวนงวด</label>
                                    <input type="number" name="installment_total" id="installment_total" class="form-control" min="1" max="120" value="<?= (int) $hireInstallEdit ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold" id="details_label">รายละเอียด/วัตถุประสงค์</label>
                            <textarea name="details" id="details_textarea" class="form-control" rows="2" placeholder="ระบุรายละเอียดที่ต้องการจัดซื้อ"><?= htmlspecialchars($editDetails, ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="col-md-12">
                            <?php if ($isEdit && $quotationPathExisting !== ''): ?>
                                <div class="small text-muted mb-2">ไฟล์แนบปัจจุบัน: <?= htmlspecialchars($quotationNameExisting !== '' ? $quotationNameExisting : basename($quotationPathExisting), ENT_QUOTES, 'UTF-8') ?> — เลือกไฟล์ใหม่ด้านล่างเพื่อแทนที่</div>
                            <?php endif; ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" value="1" id="quotation_attach" name="quotation_attach"<?= ($isEdit && $quotationPathExisting !== '') ? ' checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="quotation_attach">แนบใบเสนอราคา (ไม่บังคับ)</label>
                            </div>
                            <div id="quotation_upload_wrap" class="d-none">
                                <input
                                    type="file"
                                    name="quotation_file"
                                    id="quotation_file"
                                    class="form-control"
                                    accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.bmp,.tif,.tiff"
                                    disabled
                                >
                                <div class="form-text">รองรับไฟล์ PDF และรูปภาพทั่วไป (JPG, PNG, WEBP, GIF, BMP, TIFF)</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm p-4 h-100 text-white" style="background-color: #fd7e14;">
                    <label class="opacity-75">ผู้ขอซื้อ</label>
                    <h4 class="fw-bold"><?= $requester_name ?></h4>
                    <input type="hidden" name="requested_by" value="<?= (int) $editRequestedBy ?>">
                    <hr>
                    <p class="mb-0 small opacity-90"><i class="bi bi-check2-circle me-1"></i>หลังบันทึกสามารถ<strong>พิมพ์ใบ PR</strong> หรือ<strong>ออก PO จากใบ PR</strong> ได้ทันที</p>
                    <hr class="opacity-50">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="vat_enabled" id="vat_enabled" value="1" onchange="calculateTotal()"<?= $editVatOn ? ' checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="vat_enabled">รวม VAT 7%</label>
                    </div>
                    <div class="mt-2 d-none" id="vat_mode_wrap">
                        <label class="form-label form-label-sm mb-1 opacity-75">รูปแบบ VAT</label>
                        <select class="form-select form-select-sm" name="vat_mode" id="vat_mode" onchange="calculateTotal()">
                            <option value="exclusive"<?= $editVatMode === 'exclusive' ? ' selected' : '' ?>>VAT แยก (บวกเพิ่ม 7%)</option>
                            <option value="inclusive"<?= $editVatMode === 'inclusive' ? ' selected' : '' ?>>VAT รวมในราคา</option>
                        </select>
                    </div>
                    <p class="small opacity-75 mb-0 mt-1" id="vat_help_text">ยอดรายการ = ก่อน VAT · ระบบบวก VAT 7% เมื่อเปิด</p>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm p-4" id="item_table_card">
            <div class="table-responsive">
            <table class="table align-middle" id="prTable">
                <thead class="table-light">
                    <tr>
                        <th style="width:3rem;">#</th>
                        <th>รายการสินค้า</th>
                        <th style="width:7rem;">จำนวน</th>
                        <th style="width:6rem;">หน่วย</th>
                        <th style="width:7rem;">ราคา/หน่วย</th>
                        <th style="width:7rem;">ส่วนลด</th>
                        <th style="width:7rem;">รวม</th>
                        <th style="width:3rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($isEdit && $requestTypeVal === 'purchase' && count($editItems) > 0): ?>
                        <?php $rn = 0; ?>
                        <?php foreach ($editItems as $it): ?>
                            <?php
                            $rn++;
                            $discEdit = trim((string) ($it['discount_input'] ?? ''));
                            if ($discEdit === '') {
                                $dt = (string) ($it['discount_type'] ?? 'amount');
                                $dv = (float) ($it['discount_value'] ?? 0);
                                if ($dv > 0) {
                                    $discEdit = $dt === 'percent'
                                        ? (rtrim(rtrim(number_format($dv, 4, '.', ''), '0'), '.') . '%')
                                        : (string) $dv;
                                }
                            }
                            ?>
                            <tr>
                                <td class="row-number"><?= $rn ?></td>
                                <td><input type="text" name="item_description[]" class="form-control" required placeholder="ระบุรายการสินค้า" value="<?= htmlspecialchars((string) ($it['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td><input type="number" name="item_qty[]" class="form-control qty" step="0.001" min="0" required oninput="calculateTotal()" value="<?= htmlspecialchars((string) ($it['quantity'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td><input type="text" name="item_unit[]" class="form-control" placeholder="หน่วย" value="<?= htmlspecialchars((string) ($it['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td><input type="number" name="item_price[]" class="form-control price" step="0.01" required oninput="calculateTotal()" value="<?= htmlspecialchars((string) ($it['unit_price'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td><input type="text" name="item_discount[]" class="form-control line-discount" maxlength="20" placeholder="ไม่บังคับ — เช่น 10% หรือ 100" oninput="calculateTotal()" value="<?= htmlspecialchars($discEdit, ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td><input type="text" class="form-control row-total bg-light" value="<?= number_format((float) ($it['total'] ?? 0), 2, '.', '') ?>" readonly></td>
                                <td><button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="removeRow(this)"><i class="bi bi-trash-fill"></i></button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td class="row-number">1</td>
                        <td><input type="text" name="item_description[]" class="form-control" required placeholder="ระบุรายการสินค้า"></td>
                        <td><input type="number" name="item_qty[]" class="form-control qty" step="0.001" min="0" required oninput="calculateTotal()"></td>
                        <td><input type="text" name="item_unit[]" class="form-control" placeholder="หน่วย"></td>
                        <td><input type="number" name="item_price[]" class="form-control price" step="0.01" required oninput="calculateTotal()"></td>
                        <td><input type="text" name="item_discount[]" class="form-control line-discount" maxlength="20" placeholder="ไม่บังคับ — เช่น 10% หรือ 100" oninput="calculateTotal()"></td>
                        <td><input type="text" class="form-control row-total bg-light" value="0.00" readonly></td>
                        <td></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
            
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
        <td><input type="number" name="item_qty[]" class="form-control qty" step="0.001" min="0" required oninput="calculateTotal()"></td>
        <td><input type="text" name="item_unit[]" class="form-control" placeholder="หน่วย"></td>
        <td><input type="number" name="item_price[]" class="form-control price" step="0.01" required oninput="calculateTotal()"></td>
        <td><input type="text" name="item_discount[]" class="form-control line-discount" maxlength="20" placeholder="ไม่บังคับ — เช่น 10% หรือ 100" oninput="calculateTotal()"></td>
        <td><input type="text" class="form-control row-total bg-light" value="0.00" readonly></td>
        <td><button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="removeRow(this)"><i class="bi bi-trash-fill"></i></button></td>
    `;
}

function prLineAmountAfterDiscount(qty, price, discRaw) {
    const q = parseFloat(String(qty || '').replace(/,/g, '')) || 0;
    const p = parseFloat(String(price || '').replace(/,/g, '')) || 0;
    const base = Math.round(q * p * 100) / 100;
    const dRaw = String(discRaw || '').trim();
    let discount = 0;
    if (dRaw !== '') {
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
            const qtyEl = row.querySelector('.qty');
            const priceEl = row.querySelector('.price');
            const discEl = row.querySelector('.line-discount');
            const total = prLineAmountAfterDiscount(
                qtyEl ? qtyEl.value : 0,
                priceEl ? priceEl.value : 0,
                discEl ? discEl.value : ''
            );
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
    itemTableCard.querySelectorAll('input[name="item_discount[]"]').forEach((input) => {
        input.required = false;
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

(function () {
    const cb = document.getElementById('quotation_attach');
    const wrap = document.getElementById('quotation_upload_wrap');
    const file = document.getElementById('quotation_file');
    function syncQuotationUpload() {
        const on = cb && cb.checked;
        if (wrap) {
            wrap.classList.toggle('d-none', !on);
        }
        if (file) {
            file.disabled = !on;
            if (!on) {
                file.value = '';
            }
        }
    }
    cb?.addEventListener('change', syncQuotationUpload);
    document.addEventListener('DOMContentLoaded', syncQuotationUpload);
})();
</script>
</body>
</html>