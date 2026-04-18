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
$showInactive = !empty($_GET['inactive']);

$balByPid = [];
foreach (Db::tableRows('stock_movements') as $m) {
    $pid = (int) ($m['product_id'] ?? 0);
    $balByPid[$pid] = ($balByPid[$pid] ?? 0) + (float) ($m['qty'] ?? 0);
}

$rows = [];
foreach (Db::tableRows('stock_products') as $p) {
    $active = !empty($p['is_active']);
    if (!$showInactive && !$active) {
        continue;
    }
    $pid = (int) ($p['id'] ?? 0);
    $rows[] = array_merge($p, ['qty_on_hand' => $balByPid[$pid] ?? 0.0]);
}

usort($rows, static function (array $a, array $b) use ($showInactive): int {
    if ($showInactive) {
        $ia = !empty($a['is_active']) ? 1 : 0;
        $ib = !empty($b['is_active']) ? 1 : 0;
        if ($ia !== $ib) {
            return $ib <=> $ia;
        }
    }

    return strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
});

$handler = app_path('actions/stock-handler.php');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>คลังสินค้า (Stock) | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }
        .card-main { border: none; border-radius: 16px; box-shadow: 0 6px 24px rgba(0,0,0,.06); }
        .stock-low { background: #fff3cd; }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container py-4 pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-box-seam text-warning me-2"></i>คลังสินค้า</h4>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars(app_path('pages/stock-movements.php')) ?>" class="btn btn-outline-secondary rounded-pill">
                <i class="bi bi-clock-history me-1"></i>ประวัติการเคลื่อนไหว
            </a>
            <?php if ($canManage): ?>
                <a href="<?= htmlspecialchars(app_path('pages/stock-product-form.php')) ?>" class="btn btn-warning text-white fw-bold rounded-pill">
                    <i class="bi bi-plus-lg me-1"></i>เพิ่มสินค้า
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success rounded-3"><?= $_GET['success'] === 'updated' ? 'อัปเดตสินค้าแล้ว' : 'บันทึกสินค้าใหม่แล้ว' ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['deactivated'])): ?>
        <div class="alert alert-secondary rounded-3">ปิดการใช้งานสินค้าแล้ว</div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger rounded-3"><?php
            $em = ['forbidden' => 'ไม่มีสิทธิ์', 'notfound' => 'ไม่พบรายการ'];
            echo htmlspecialchars($em[$_GET['error']] ?? 'เกิดข้อผิดพลาด', ENT_QUOTES, 'UTF-8');
        ?></div>
    <?php endif; ?>

    <div class="mb-3 d-flex flex-wrap align-items-center gap-3">
        <a href="<?= htmlspecialchars(app_path('pages/stock-list.php')) ?>" class="btn btn-sm <?= !$showInactive ? 'btn-warning text-white' : 'btn-outline-secondary' ?> rounded-pill">เฉพาะที่ใช้งาน</a>
        <a href="<?= htmlspecialchars(app_path('pages/stock-list.php')) ?>?inactive=1" class="btn btn-sm <?= $showInactive ? 'btn-warning text-white' : 'btn-outline-secondary' ?> rounded-pill">รวมที่ปิดใช้งาน</a>
    </div>

    <div class="card card-main">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">รหัส</th>
                        <th>ชื่อสินค้า</th>
                        <th class="text-center">หน่วย</th>
                        <th class="text-end">คงเหลือ</th>
                        <th class="text-end">แจ้งเตือนเมื่อต่ำกว่า</th>
                        <th class="text-end pe-4">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีสินค้าในระบบ</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r):
                            $qh = (float) $r['qty_on_hand'];
                            $rl = (float) $r['reorder_level'];
                            $low = $rl > 0 && $qh <= $rl;
                        ?>
                        <tr class="<?= $low ? 'stock-low' : '' ?>">
                            <td class="ps-4 fw-bold text-warning-emphasis"><?= htmlspecialchars($r['code']) ?></td>
                            <td>
                                <?= htmlspecialchars($r['name']) ?>
                                <?php if (empty($r['is_active'])): ?>
                                    <span class="badge bg-secondary ms-1">ปิดใช้งาน</span>
                                <?php endif; ?>
                                <?php if ($low): ?>
                                    <span class="badge bg-warning text-dark ms-1">ใกล้หมด</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= htmlspecialchars($r['unit']) ?></td>
                            <td class="text-end fw-bold"><?= rtrim(rtrim(number_format($qh, 3, '.', ','), '0'), '.') ?></td>
                            <td class="text-end text-muted"><?= rtrim(rtrim(number_format($rl, 2, '.', ','), '0'), '.') ?></td>
                            <td class="text-end pe-4">
                                <a href="<?= htmlspecialchars(app_path('pages/stock-movements.php')) ?>?product_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-light border rounded-3" title="ประวัติ"><i class="bi bi-list-ul"></i></a>
                                <?php if ($canManage && !empty($r['is_active'])): ?>
                                    <a href="<?= htmlspecialchars(app_path('pages/stock-adjust.php')) ?>?product_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-light border rounded-3 text-warning" title="รับเข้า/จ่ายออก"><i class="bi bi-arrow-left-right"></i></a>
                                    <a href="<?= htmlspecialchars(app_path('pages/stock-product-form.php')) ?>?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-light border rounded-3" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                                    <a href="<?= htmlspecialchars($handler) ?>?action=deactivate&id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-light border rounded-3 text-danger" title="ปิดใช้งาน" onclick="return confirm('ปิดใช้งานสินค้านี้?');"><i class="bi bi-archive"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
