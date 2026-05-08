<?php

declare(strict_types=1);


require_once __DIR__ . '/_page_root.php';
use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once THEELINCON_ROOT . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$uid = (string) $_SESSION['user_id'];
$user_data = Db::row('users', $uid);
$requester_name = $user_data ? ($user_data['fname'] ?? '') . ' ' . ($user_data['lname'] ?? '') : 'Unknown User';
$current_need_number = Purchase::nextNeedNumber();
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

<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>

<div class="container mt-4">
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'need_no_items'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            กรุณาระบุอย่างน้อย 1 รายการที่ต้องการซื้อ
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=save_purchase_need" method="POST">
        <?php csrf_field(); ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold"><i class="bi bi-card-checklist text-primary me-2"></i> สร้างใบต้องการซื้อ</h3>
            <div>
                <a href="purchase-need-list.php" class="btn btn-light rounded-pill px-4 me-2">ยกเลิก</a>
                <button type="submit" class="btn btn-blue rounded-pill px-4 shadow-sm fw-bold">บันทึกใบต้องการซื้อ</button>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card border-0 shadow-sm p-4 h-100">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">เลขที่เอกสาร</label>
                            <input type="text" name="need_number" class="form-control bg-light fw-bold text-primary" value="<?= htmlspecialchars($current_need_number, ENT_QUOTES, 'UTF-8') ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">วันที่ต้องการซื้อ</label>
                            <input type="date" name="created_at" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">ไซต์งาน</label>
                            <select name="site_id" class="form-select">
                                <option value="">-- เลือกไซต์งาน (ถ้ามี) --</option>
                                <?php foreach ($sites as $site): ?>
                                    <?php $siteId = (int) ($site['id'] ?? 0); ?>
                                    <?php if ($siteId <= 0) { continue; } ?>
                                    <option value="<?= $siteId ?>">
                                        <?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm p-4 text-white" style="background-color: #0d6efd;">
                    <label class="opacity-75">ผู้ขอ</label>
                    <h4 class="fw-bold"><?= htmlspecialchars($requester_name, ENT_QUOTES, 'UTF-8') ?></h4>
                    <input type="hidden" name="requested_by" value="<?= htmlspecialchars($uid, ENT_QUOTES, 'UTF-8') ?>">
                    <hr>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm p-4">
            <table class="table align-middle" id="needTable">
                <thead class="table-light">
                    <tr>
                        <th width="5%">#</th>
                        <th width="55%">รายการสินค้า</th>
                        <th width="15%">จำนวน</th>
                        <th width="20%">หน่วย</th>
                        <th width="5%"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="need-row-number">1</td>
                        <td><input type="text" name="need_item_description[]" class="form-control" required placeholder="ระบุรายการสินค้า"></td>
                        <td><input type="number" name="need_item_qty[]" class="form-control" step="0.01" min="0.01" required></td>
                        <td><input type="text" name="need_item_unit[]" class="form-control" placeholder="หน่วย"></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" onclick="addNeedRow()">
                <i class="bi bi-plus-circle-fill me-1"></i> เพิ่มรายการ
            </button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function addNeedRow() {
    const table = document.getElementById('needTable').getElementsByTagName('tbody')[0];
    const newRow = table.insertRow();
    const rowCount = table.rows.length;
    newRow.innerHTML = `
        <td class="need-row-number">${rowCount}</td>
        <td><input type="text" name="need_item_description[]" class="form-control" required placeholder="ระบุรายการสินค้า"></td>
        <td><input type="number" name="need_item_qty[]" class="form-control" step="0.01" min="0.01" required></td>
        <td><input type="text" name="need_item_unit[]" class="form-control" placeholder="หน่วย"></td>
        <td><button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="removeNeedRow(this)"><i class="bi bi-trash-fill"></i></button></td>
    `;
}

function removeNeedRow(btn) {
    const row = btn.parentNode.parentNode;
    row.parentNode.removeChild(row);
    updateNeedRowNumbers();
}

function updateNeedRowNumbers() {
    const rows = document.querySelectorAll('.need-row-number');
    rows.forEach((td, index) => {
        td.innerText = index + 1;
    });
}
</script>
</body>
</html>
