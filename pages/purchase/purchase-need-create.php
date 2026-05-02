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
$userRow = Db::row('users', (string) $uid) ?? [];
$requester_name = trim((string) ($userRow['fname'] ?? '') . ' ' . (string) ($userRow['lname'] ?? ''));
if ($requester_name === '') {
    $requester_name = (string) ($_SESSION['name'] ?? 'Unknown User');
}

$current_need_number = Purchase::nextNeedNumber();
$today = date('Y-m-d');

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สร้างใบต้องการซื้อ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .btn-blue { background-color: #0d6efd; color: white; border: none; }
        .btn-blue:hover { background-color: #0b5ed7; color: white; }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'need_no_items'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            กรุณาระบุอย่างน้อย 1 รายการที่มีรายละเอียดและจำนวนถูกต้อง
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'need_site'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            กรุณาเลือกไซต์งาน
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (count($sites) === 0): ?>
        <div class="alert alert-warning">ยังไม่มีข้อมูลไซต์งานในระบบ — ผู้ดูแลต้องเพิ่มที่เมนู «ไซต์งาน» ก่อนจึงจะสร้างใบต้องการซื้อได้</div>
    <?php endif; ?>

    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=save_purchase_need" method="POST">
        <?php csrf_field(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <h3 class="fw-bold mb-0"><i class="bi bi-card-checklist text-primary me-2"></i>สร้างใบต้องการซื้อ</h3>
            <div class="d-flex gap-2">
                <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-need-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light rounded-pill px-4">ยกเลิก</a>
                <button type="submit" class="btn btn-blue rounded-pill px-4 shadow-sm fw-bold" <?= count($sites) === 0 ? 'disabled' : '' ?>>บันทึกและส่งขออนุมัติ LINE</button>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm p-4 h-100">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">เลขที่เอกสาร</label>
                            <input type="text" name="need_number" class="form-control bg-light fw-bold text-primary" value="<?= htmlspecialchars($current_need_number, ENT_QUOTES, 'UTF-8') ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">วันที่เอกสาร</label>
                            <input type="date" name="created_at" class="form-control bg-light" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" readonly required>
                            <div class="form-text">ใช้วันที่ปัจจุบันตามระบบ</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">ไซต์งาน <span class="text-danger">*</span></label>
                            <select name="site_id" class="form-select" required>
                                <option value="" disabled selected>— เลือกไซต์งาน —</option>
                                <?php foreach ($sites as $site): ?>
                                    <?php $siteId = (int) ($site['id'] ?? 0); ?>
                                    <?php if ($siteId <= 0) { continue; } ?>
                                    <option value="<?= $siteId ?>">
                                        <?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">หมายเหตุ</label>
                            <textarea name="remarks" class="form-control" rows="2" maxlength="2000" placeholder="ถ้ามี (ไม่บังคับ)"></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 mt-3 mt-lg-0">
                <div class="card border-0 shadow-sm p-4 text-white h-100" style="background-color: #0d6efd;">
                    <label class="opacity-75 small">ผู้ขอ</label>
                    <h4 class="fw-bold"><?= htmlspecialchars($requester_name, ENT_QUOTES, 'UTF-8') ?></h4>
                    <p class="small opacity-75 mb-0 mt-2">หลังบันทึก ระบบจะแจ้งไป LINE เพื่อกดอนุมัติ / ไม่อนุมัติ</p>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm p-4">
            <h6 class="fw-bold mb-3">รายการที่ต้องการซื้อ</h6>
            <div class="table-responsive">
                <table class="table align-middle" id="needTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:3rem;">#</th>
                            <th>รายละเอียด <span class="text-danger">*</span></th>
                            <th style="width:8rem;">จำนวน <span class="text-danger">*</span></th>
                            <th style="width:8rem;">หน่วย</th>
                            <th style="width:3rem;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="need-row-number">1</td>
                            <td><input type="text" name="need_item_description[]" class="form-control" required maxlength="500" placeholder="ระบุรายการ"></td>
                            <td><input type="number" name="need_item_qty[]" class="form-control" step="0.01" min="0.01" required></td>
                            <td><input type="text" name="need_item_unit[]" class="form-control" maxlength="40" placeholder="เช่น ชิ้น, กล่อง"></td>
                            <td><button type="button" class="btn btn-outline-danger btn-sm border-0 need-remove-btn" disabled title="ต้องมีอย่างน้อย 1 แถว"><i class="bi bi-trash-fill"></i></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 mt-2" id="needAddRowBtn">
                <i class="bi bi-plus-circle-fill me-1"></i>เพิ่มแถว
            </button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function refreshNeedRemoveButtons() {
    var table = document.getElementById('needTable').getElementsByTagName('tbody')[0];
    var rows = table.rows.length;
    table.querySelectorAll('.need-remove-btn').forEach(function (btn) {
        btn.disabled = rows <= 1;
    });
}

function addNeedRow() {
    var table = document.getElementById('needTable').getElementsByTagName('tbody')[0];
    var newRow = table.insertRow();
    var rowCount = table.rows.length;
    newRow.innerHTML =
        '<td class="need-row-number">' + rowCount + '</td>' +
        '<td><input type="text" name="need_item_description[]" class="form-control" required maxlength="500" placeholder="ระบุรายการ"></td>' +
        '<td><input type="number" name="need_item_qty[]" class="form-control" step="0.01" min="0.01" required></td>' +
        '<td><input type="text" name="need_item_unit[]" class="form-control" maxlength="40" placeholder="หน่วย"></td>' +
        '<td><button type="button" class="btn btn-outline-danger btn-sm border-0 need-remove-btn" onclick="removeNeedRow(this)"><i class="bi bi-trash-fill"></i></button></td>';
    refreshNeedRemoveButtons();
}

function removeNeedRow(btn) {
    var table = document.getElementById('needTable').getElementsByTagName('tbody')[0];
    if (table.rows.length <= 1) return;
    btn.closest('tr').remove();
    updateNeedRowNumbers();
    refreshNeedRemoveButtons();
}

function updateNeedRowNumbers() {
    document.querySelectorAll('.need-row-number').forEach(function (td, index) {
        td.innerText = String(index + 1);
    });
}

document.getElementById('needAddRowBtn').addEventListener('click', addNeedRow);
document.querySelector('.need-remove-btn').addEventListener('click', function () {
    removeNeedRow(this);
});
refreshNeedRemoveButtons();
</script>
</body>
</html>
