<?php

declare(strict_types=1);


require_once __DIR__ . '/_page_root.php';
use Theelincon\Rtdb\Db;

session_start();
require_once THEELINCON_ROOT . '/config/connect_database.php';

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
Db::sortRows($prodRows, 'name', false);
$preId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
$preSiteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;
$sites = Db::tableRows('sites');
usort($sites, static function (array $a, array $b): int {
    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
$handler = app_path('actions/stock-handler.php');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Transaction | Stock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f8fafc; }
        .stock-form-card { border: 1px solid #e8edf5; border-radius: 1rem; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05); }
    </style>
</head>
<body>

<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>

<div class="container py-4 pb-5" style="max-width: 760px;">
    <h5 class="fw-bold mb-2"><i class="bi bi-plus-circle text-warning me-2"></i>Add Transaction</h5>
    <div class="text-muted small mb-4">บันทึกรายการเข้า-ออกหน้างานแบบรวดเร็ว รองรับมือถือ</div>

    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger rounded-3"><?php
            $map = [
                'insufficient' => 'จำนวนคงเหลือไม่พอสำหรับรายการนำออก',
                'qty' => 'กรุณากรอกจำนวนให้ถูกต้อง (มากกว่า 0)',
                'type' => 'กรุณาเลือกประเภทการทำรายการ',
                'product' => 'กรุณาเลือกอุปกรณ์',
                'site' => 'กรุณาเลือกไซต์งาน',
            ];
            echo htmlspecialchars($map[(string) $_GET['error']] ?? 'บันทึกไม่สำเร็จ', ENT_QUOTES, 'UTF-8');
        ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" action="<?= htmlspecialchars($handler) ?>?action=save_transaction" class="stock-form-card bg-white p-4">
        <?php csrf_field(); ?>
        <div class="mb-3">
            <label class="form-label fw-bold small">ไซต์งาน</label>
            <select name="site_id" class="form-select rounded-3" required>
                <option value="">— เลือกไซต์ —</option>
                <?php foreach ($sites as $s): ?>
                    <?php $sid = (int) ($s['id'] ?? 0); ?>
                    <option value="<?= $sid ?>" <?= $preSiteId === $sid ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label fw-bold small">วันที่</label>
                <input type="date" name="txn_date" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" class="form-control rounded-3" required>
            </div>
            <div class="col-12 col-md-8">
                <label class="form-label fw-bold small">ชื่อผู้นำเข้า / นำออก</label>
                <input type="text" name="person_name" class="form-control rounded-3" required maxlength="120" placeholder="เช่น เจมส์/KMIT">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small mt-3">ประเภทอุปกรณ์</label>
            <select name="product_id" class="form-select rounded-3" required>
                <option value="">— เลือก —</option>
                <?php foreach ($prodRows as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $preId === (int) $p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name'] . ' (' . $p['unit'] . ')', ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6">
                <label class="form-label fw-bold small">ประเภทรายการ</label>
            <select name="movement_type" class="form-select rounded-3" required id="kindSel">
                <option value="in">รับเข้า (+)</option>
                <option value="out">จ่ายออก (−)</option>
            </select>
        </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-bold small">จำนวน</label>
                <input type="number" name="qty" step="0.01" min="0.01" class="form-control rounded-3" required>
                <div class="form-text" id="qtyHint">เลือก "รับเข้า" ระบบจะบวกเข้าสต็อกให้อัตโนมัติ</div>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label fw-bold small">หมายเหตุ</label>
            <textarea name="note" class="form-control rounded-3" maxlength="500" rows="2"></textarea>
        </div>
        <div class="mb-4">
            <label class="form-label fw-bold small">รูปภาพ (ไม่บังคับ)</label>
            <input type="file" name="photo" class="form-control rounded-3" accept="image/*">
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-warning text-white fw-bold rounded-pill px-4">บันทึกการเคลื่อนไหว</button>
            <a href="<?= htmlspecialchars(app_path('pages/stock-list.php') . ($preSiteId > 0 ? '?site_id=' . $preSiteId : ''), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill">กลับ</a>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('kindSel').addEventListener('change', function () {
    var h = document.getElementById('qtyHint');
    if (this.value === 'out') h.textContent = 'เลือก "จ่ายออก" ระบบจะหักยอดคงเหลือให้อัตโนมัติ';
    else h.textContent = 'เลือก "รับเข้า" ระบบจะบวกเข้าสต็อกให้อัตโนมัติ';
});
</script>
</body>
</html>
