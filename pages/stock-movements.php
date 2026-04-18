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
$filterPid = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;

$products = Db::tableKeyed('stock_products');
$users = Db::tableKeyed('users');

$productLabel = '';
if ($filterPid > 0) {
    $pr = $products[(string) $filterPid] ?? null;
    if ($pr) {
        $productLabel = ($pr['code'] ?? '') . ' — ' . ($pr['name'] ?? '');
    } else {
        $filterPid = 0;
    }
}

$rows = [];
foreach (Db::tableRows('stock_movements') as $m) {
    $pid = (int) ($m['product_id'] ?? 0);
    if ($filterPid > 0 && $pid !== $filterPid) {
        continue;
    }
    $p = $products[(string) $pid] ?? null;
    if ($p === null) {
        continue;
    }
    $uid = (string) ($m['created_by'] ?? '');
    $u = $users[$uid] ?? null;
    $rows[] = array_merge($m, [
        'product_code' => (string) ($p['code'] ?? ''),
        'product_name' => (string) ($p['name'] ?? ''),
        'user_name' => trim(($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')),
    ]);
}

usort($rows, static function (array $a, array $b): int {
    return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
});
$limit = $filterPid > 0 ? 300 : 200;
$rows = array_slice($rows, 0, $limit);

$colspan = $filterPid > 0 ? 5 : 6;

$typeLabel = static function (string $t): string {
    $map = ['opening' => 'ยอดยกมา', 'in' => 'รับเข้า', 'out' => 'จ่ายออก', 'adjust' => 'ปรับยอด'];
    return $map[$t] ?? $t;
};
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประวัติ Stock | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }</style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container py-4 pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-clock-history text-warning me-2"></i>ประวัติการเคลื่อนไหวคลัง</h4>
            <?php if ($productLabel): ?>
                <p class="text-muted small mb-0">กรอง: <?= htmlspecialchars($productLabel) ?></p>
            <?php else: ?>
                <p class="text-muted small mb-0">รายการล่าสุดทั้งระบบ</p>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($filterPid): ?>
                <a href="<?= htmlspecialchars(app_path('pages/stock-movements.php')) ?>" class="btn btn-outline-secondary rounded-pill">ดูทั้งหมด</a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(app_path('pages/stock-list.php')) ?>" class="btn btn-outline-secondary rounded-pill">คลังสินค้า</a>
            <?php if ($canManage): ?>
                <a href="<?= htmlspecialchars(app_path('pages/stock-adjust.php')) ?>" class="btn btn-warning text-white fw-bold rounded-pill">รับเข้า/จ่ายออก</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_GET['success'])): ?>
        <div class="alert alert-success rounded-3">บันทึกการเคลื่อนไหวแล้ว</div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">วันเวลา</th>
                        <?php if (!$filterPid): ?><th>สินค้า</th><?php endif; ?>
                        <th>ประเภท</th>
                        <th class="text-end">จำนวน (+/−)</th>
                        <th>หมายเหตุ</th>
                        <th class="pe-4">ผู้บันทึก</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="<?= (int)$colspan ?>" class="text-center text-muted py-5">ยังไม่มีรายการ</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $m): ?>
                        <tr>
                            <td class="ps-4 small"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($m['created_at']))) ?></td>
                            <?php if (!$filterPid): ?>
                                <td>
                                    <a href="<?= htmlspecialchars(app_path('pages/stock-movements.php')) ?>?product_id=<?= (int)$m['product_id'] ?>">
                                        <?= htmlspecialchars($m['product_code']) ?>
                                    </a>
                                    <div class="small text-muted"><?= htmlspecialchars($m['product_name']) ?></div>
                                </td>
                            <?php endif; ?>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($typeLabel($m['movement_type'])) ?></span></td>
                            <td class="text-end fw-bold <?= (float)$m['qty'] < 0 ? 'text-danger' : 'text-success' ?>">
                                <?= rtrim(rtrim(number_format((float)$m['qty'], 3, '.', ','), '0'), '.') ?>
                            </td>
                            <td class="small"><?= htmlspecialchars((string)($m['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                            <td class="pe-4 small"><?= htmlspecialchars(trim($m['user_name'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
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
