<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$suppliers = Db::tableKeyed('suppliers');
$users = Db::tableKeyed('users');
$po_rows = [];
foreach (Db::tableRows('purchase_orders') as $po) {
    $s = $suppliers[(string) ($po['supplier_id'] ?? '')] ?? null;
    $u = $users[(string) ($po['created_by'] ?? '')] ?? null;
    $po_rows[] = array_merge($po, [
        'supplier_name' => $s['name'] ?? '',
        'created_by_name' => trim(($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')),
    ]);
}
Db::sortRows($po_rows, 'created_at', true);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> <title>รายการใบสั่งซื้อ (PO List)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .main-card { border: none; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container mt-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold"><i class="bi bi-file-earmark-check-fill text-primary"></i> รายการใบสั่งซื้อ (PO)</h2>
    </div>

    <div class="card main-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่ PO</th>
                        <th>วันที่ออก</th>
                        <th>ผู้ขาย (Supplier)</th>
                        <th>ผู้ออกใบ</th>
                        <th class="text-end">ยอดเงินรวม</th>
                        <th class="text-center">สถานะ</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($po_rows) === 0): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">ยังไม่มีการออกใบสั่งซื้อ</td></tr>
                    <?php else: ?>
                        <?php foreach ($po_rows as $row): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($row['po_number']) ?></td>
                        <td><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>
                        <td><?= htmlspecialchars((string)($row['supplier_name'] ?? '')) ?></td>
                        <td class="small"><?php $cb = trim((string)($row['created_by_name'] ?? '')); echo $cb !== '' ? htmlspecialchars($cb) : '<span class="text-muted">—</span>'; ?></td>
                        <td class="text-end">
                            <div class="fw-bold text-primary"><?= number_format((float)$row['total_amount'], 2) ?></div>
                            <?php if ((int)($row['vat_enabled'] ?? 0) === 1): ?>
                                <span class="badge bg-success rounded-pill mt-1" style="font-size:0.7rem;">รวม VAT 7%</span>
                            <?php else: ?>
                                <span class="badge bg-light text-secondary border mt-1" style="font-size:0.7rem;">ไม่มี VAT</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-info rounded-pill">ORDERED</span>
                        </td>
                        <td class="text-center">
                            <a href="purchase-order-view.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i> ดูรายละเอียด
                            </a>
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