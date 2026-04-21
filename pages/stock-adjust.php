<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$pos = $_SESSION['role'] ?? 'user';
$canManage = ($pos === 'admin' || $pos === 'Accounting');
if (!$canManage) {
    header('Location: ' . app_path('pages/stock-list.php'));
    exit();
}

$prodRows = Db::filter('stock_products', static fn (array $r): bool => !empty($r['is_active']));
Db::sortRows($prodRows, 'code', false);
$preId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
$handler = app_path('actions/stock-handler.php');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รับเข้า / จ่ายออก | Stock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }</style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container py-4" style="max-width: 640px;">
    <h5 class="fw-bold mb-4"><i class="bi bi-arrow-left-right text-warning me-2"></i>รับเข้า / จ่ายออก / ปรับยอด</h5>

    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger rounded-3"><?php
            if ($_GET['error'] === 'insufficient') {
                echo 'จ่ายออกเกินจำนวนคงเหลือ';
            } elseif ($_GET['error'] === 'invalid') {
                echo 'กรุณาเลือกสินค้าและกรอกจำนวน';
            } else {
                echo 'บันทึกไม่สำเร็จ';
            }
        ?></div>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars($handler) ?>?action=add_movement" class="card border-0 shadow-sm rounded-4 p-4">
        <?php csrf_field(); ?>
        <div class="mb-3">
            <label class="form-label fw-bold small">สินค้า</label>
            <select name="product_id" class="form-select rounded-3 border-0 bg-light" required>
                <option value="">— เลือก —</option>
                <?php foreach ($prodRows as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $preId === (int) $p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['code'] . ' — ' . $p['name'] . ' (' . $p['unit'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-bold small">ประเภท</label>
            <select name="kind" class="form-select rounded-3 border-0 bg-light" required id="kindSel">
                <option value="in">รับเข้า (+)</option>
                <option value="out">จ่ายออก (−)</option>
                <option value="adjust">ปรับยอด (+/− ใส่เครื่องหมายเอง)</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-bold small">จำนวน</label>
            <input type="text" name="qty" class="form-control rounded-3 border-0 bg-light" required placeholder="เช่น 10 หรือ -2 สำหรับปรับลด">
            <div class="form-text" id="qtyHint">รับเข้า/จ่ายออก: ใส่จำนวนบวกเท่านั้น</div>
        </div>
        <div class="mb-4">
            <label class="form-label fw-bold small">หมายเหตุ</label>
            <input type="text" name="note" class="form-control rounded-3 border-0 bg-light" maxlength="500" placeholder="เช่น รับของจาก PO, เบิกใช้งานโครงการ">
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-warning text-white fw-bold rounded-pill px-4">บันทึกการเคลื่อนไหว</button>
            <a href="<?= htmlspecialchars(app_path('pages/stock-list.php')) ?>" class="btn btn-outline-secondary rounded-pill">กลับ</a>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('kindSel').addEventListener('change', function () {
    var h = document.getElementById('qtyHint');
    if (this.value === 'adjust') h.textContent = 'ปรับยอด: ใส่ +10 เพื่อเพิ่ม หรือ -5 เพื่อลด';
    else h.textContent = 'รับเข้า/จ่ายออก: ใส่จำนวนบวกเท่านั้น';
});
</script>
</body>
</html>
