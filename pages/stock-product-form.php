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

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$row = null;
if ($id > 0) {
    $row = Db::row('stock_products', (string) $id);
    if (!$row) {
        header('Location: ' . app_path('pages/stock-list.php?error=notfound'));
        exit();
    }
}

$handler = app_path('actions/stock-handler.php');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $row ? 'แก้ไขสินค้า' : 'เพิ่มสินค้า' ?> | Stock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }</style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container py-4" style="max-width: 640px;">
    <h5 class="fw-bold mb-4"><i class="bi bi-box-seam text-warning me-2"></i><?= $row ? 'แก้ไขสินค้า' : 'เพิ่มสินค้าใหม่' ?></h5>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger rounded-3"><?= $_GET['error'] === 'duplicate' ? 'รหัสสินค้าซ้ำในระบบ' : 'กรุณากรอกรหัสและชื่อสินค้า' ?></div>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars($handler) ?>?action=save_product" class="card border-0 shadow-sm rounded-4 p-4">
        <?php if ($row): ?>
            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
        <?php endif; ?>
        <div class="mb-3">
            <label class="form-label fw-bold small">รหัสสินค้า / SKU</label>
            <input type="text" name="code" class="form-control rounded-3 border-0 bg-light" required maxlength="64"
                   value="<?= htmlspecialchars($row['code'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="mb-3">
            <label class="form-label fw-bold small">ชื่อสินค้า</label>
            <input type="text" name="name" class="form-control rounded-3 border-0 bg-light" required maxlength="255"
                   value="<?= htmlspecialchars($row['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-bold small">หน่วยนับ</label>
                <input type="text" name="unit" class="form-control rounded-3 border-0 bg-light" maxlength="32"
                       value="<?= htmlspecialchars($row['unit'] ?? 'ชิ้น', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold small">แจ้งเตือนเมื่อคงเหลือ ≤</label>
                <input type="text" name="reorder_level" class="form-control rounded-3 border-0 bg-light" placeholder="0 = ไม่แจ้ง"
                       value="<?= htmlspecialchars(isset($row['reorder_level']) ? (string)$row['reorder_level'] : '0', ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>
        <?php if (!$row): ?>
        <div class="mb-4">
            <label class="form-label fw-bold small">ยอดยกมาเริ่มต้น <span class="text-muted fw-normal">(ไม่บังคับ)</span></label>
            <input type="text" name="opening_qty" class="form-control rounded-3 border-0 bg-light" placeholder="เช่น 100">
            <div class="form-text">บันทึกเป็นรายการรับเข้าประเภท &ldquo;ยอดยกมา&rdquo; ครั้งเดียวตอนเพิ่มสินค้า</div>
        </div>
        <?php endif; ?>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-warning text-white fw-bold rounded-pill px-4">บันทึก</button>
            <a href="<?= htmlspecialchars(app_path('pages/stock-list.php')) ?>" class="btn btn-outline-secondary rounded-pill">ยกเลิก</a>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
