<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_is_finance_role()) {
    $access_denied_title = 'ใบเสร็จรับเงิน';
    $access_denied_text = 'เข้าใช้งานได้เฉพาะผู้ใช้ที่มีสิทธิ์ CEO / ADMIN / ACCOUNTING';
    require dirname(__DIR__, 2) . '/includes/page_access_denied_swal.php';
    exit;
}

$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', false);

$handler = app_path('actions/money-receipt-handler.php');
$listUrl = app_path('pages/tools/money-receipt-list.php');
$today = date('Y-m-d');
$issuerName = trim((string) ($_SESSION['name'] ?? ''));
if ($issuerName === '') {
    $issuerName = 'ผู้ใช้งานระบบ';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ออกใบเสร็จรับเงิน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f8f9fa; }
        .btn-orange { background-color: #fd7e14; color: #fff; border-radius: 10px; }
        .btn-orange:hover { background-color: #e8590c; color: #fff; }
        #transferSlipWrap.d-none { display: none !important; }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="fw-bold mb-0"><i class="bi bi-receipt-cutoff me-2 text-warning"></i>ออกใบเสร็จรับเงิน</h2>
            <p class="text-muted small mb-0">กรอกข้อมูลแล้วบันทึก — ระบบจะเปิดหน้าพิมพ์อัตโนมัติ</p>
        </div>
        <a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill"><i class="bi bi-list-ul me-1"></i>รายการที่บันทึกแล้ว</a>
    </div>

    <?php if (count($companies) === 0): ?>
        <div class="alert alert-warning">ยังไม่มีข้อมูลบริษัท — กรุณาเพิ่มที่เมนูจัดการบริษัทก่อน</div>
    <?php else: ?>
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-4">
            <form id="mrCreateForm" method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create">

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">บริษัท (หัวกระดาษ)</label>
                        <select name="company_id" class="form-select" required>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?= (int) ($c['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($c['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">DATE</label>
                        <input type="date" name="doc_date" class="form-control" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">ผู้ออกเอกสาร</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($issuerName, ENT_QUOTES, 'UTF-8') ?>" readonly>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="fw-semibold mb-0">รายการ</label>
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill" id="mrAddRow"><i class="bi bi-plus-lg"></i> เพิ่มแถว</button>
                </div>
                <div class="table-responsive mb-3">
                    <table class="table table-bordered align-middle" id="mrItemsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:48px;">#</th>
                                <th>รายละเอียด</th>
                                <th style="width:120px;">ยอดหัก</th>
                                <th style="width:120px;">ยอดรับ</th>
                                <th style="width:52px;"></th>
                            </tr>
                        </thead>
                        <tbody id="mrItemsBody">
                            <tr class="mr-item-row">
                                <td class="text-muted small text-center idx">1</td>
                                <td><input type="text" name="item_detail[]" class="form-control form-control-sm" placeholder="รายละเอียด"></td>
                                <td><input type="text" name="item_deduct[]" class="form-control form-control-sm text-end" placeholder="0.00" inputmode="decimal"></td>
                                <td><input type="text" name="item_receive[]" class="form-control form-control-sm text-end" placeholder="0.00" inputmode="decimal"></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger border-0 mr-del-row" title="ลบแถว"><i class="bi bi-x-lg"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="border rounded-3 p-3 mb-3 bg-light">
                    <div class="fw-semibold mb-2">วิธีชำระเงิน</div>
                    <div class="d-flex flex-wrap gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="pay_cash" id="payCash" value="1" checked>
                            <label class="form-check-label" for="payCash">เงินสด</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="pay_transfer" id="payTransfer" value="1">
                            <label class="form-check-label" for="payTransfer">เงินโอน</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="pay_check" id="payCheck" value="1">
                            <label class="form-check-label" for="payCheck">เช็คธนาคาร</label>
                        </div>
                    </div>
                    <div id="transferSlipWrap" class="mt-3 d-none">
                        <label class="form-label small fw-semibold text-danger">แนบสลิปการโอน <span class="fw-normal">(เมื่อเลือกเงินโอน)</span></label>
                        <input type="file" name="transfer_slip" id="transferSlipInput" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp,image/gif">
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-orange px-4 fw-semibold"><i class="bi bi-save me-1"></i>บันทึกและเปิดหน้าพิมพ์</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
    var payTransfer = document.getElementById('payTransfer');
    var slipWrap = document.getElementById('transferSlipWrap');
    var slipInput = document.getElementById('transferSlipInput');

    function syncSlip() {
        if (!payTransfer || !slipWrap) return;
        if (payTransfer.checked) {
            slipWrap.classList.remove('d-none');
            if (slipInput) slipInput.required = true;
        } else {
            slipWrap.classList.add('d-none');
            if (slipInput) { slipInput.required = false; slipInput.value = ''; }
        }
    }
    payTransfer && payTransfer.addEventListener('change', syncSlip);
    syncSlip();

    var body = document.getElementById('mrItemsBody');
    var addBtn = document.getElementById('mrAddRow');

    function renumber() {
        if (!body) return;
        body.querySelectorAll('.mr-item-row').forEach(function (tr, i) {
            var cell = tr.querySelector('.idx');
            if (cell) cell.textContent = String(i + 1);
        });
    }

    function bindDel(btn) {
        btn.addEventListener('click', function () {
            var tr = btn.closest('tr');
            if (!tr || !body) return;
            if (body.querySelectorAll('.mr-item-row').length <= 1) return;
            tr.remove();
            renumber();
        });
    }

    body && body.querySelectorAll('.mr-del-row').forEach(bindDel);

    addBtn && addBtn.addEventListener('click', function () {
        if (!body) return;
        var first = body.querySelector('.mr-item-row');
        if (!first) return;
        var clone = first.cloneNode(true);
        clone.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
        body.appendChild(clone);
        var del = clone.querySelector('.mr-del-row');
        if (del) bindDel(del);
        renumber();
    });

    var params = new URLSearchParams(window.location.search);
    if (params.get('error') === 'payment_slip_required') {
        Swal.fire({ icon: 'warning', title: 'ต้องแนบสลิปการโอน', confirmButtonColor: '#fd7e14' });
    } else if (params.get('error') === 'invalid') {
        Swal.fire({ icon: 'warning', title: 'กรุณากรอกข้อมูลให้ครบ', text: 'ต้องมีอย่างน้อย 1 รายการ และเลือกวิธีชำระเงิน', confirmButtonColor: '#fd7e14' });
    } else if (params.get('error') === 'upload_failed') {
        Swal.fire({ icon: 'error', title: 'อัปโหลดไฟล์ไม่สำเร็จ', confirmButtonColor: '#fd7e14' });
    } else if (params.get('error') === 'upload_type') {
        Swal.fire({ icon: 'error', title: 'ชนิดไฟล์ไม่รองรับ', text: 'ใช้ได้เฉพาะ JPG PNG WEBP GIF', confirmButtonColor: '#fd7e14' });
    } else if (params.get('error') === 'csrf') {
        Swal.fire({ icon: 'error', title: 'เซสชันหมดอายุ', text: 'ลองโหลดหน้าใหม่', confirmButtonColor: '#fd7e14' });
    }
})();
</script>
</body>
</html>
