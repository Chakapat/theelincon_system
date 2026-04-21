<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$csrfQ = '&_csrf=' . rawurlencode(csrf_token());

$suppliers = Db::tableKeyed('suppliers');
$users = Db::tableKeyed('users');
$po_rows = [];
$totalAmount = 0.0;
$orderedCount = 0;
foreach (Db::tableRows('purchase_orders') as $po) {
    $s = $suppliers[(string) ($po['supplier_id'] ?? '')] ?? null;
    $u = $users[(string) ($po['created_by'] ?? '')] ?? null;
    $status = strtolower(trim((string) ($po['status'] ?? 'ordered')));
    if ($status === '') {
        $status = 'ordered';
    }
    $amt = (float) ($po['total_amount'] ?? 0);
    $totalAmount += $amt;
    if ($status === 'ordered') {
        $orderedCount++;
    }
    $po_rows[] = array_merge($po, [
        'supplier_name' => $s['name'] ?? '',
        'created_by_name' => trim(($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')),
        'status_label' => strtoupper($status),
        'status' => $status,
        'total_amount' => $amt,
    ]);
}
Db::sortRows($po_rows, 'created_at', true);
$poCount = count($po_rows);
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

<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            สร้างใบสั่งซื้อ (PO) สำเร็จแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ลบใบสั่งซื้อเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            เกิดข้อผิดพลาดในการจัดการใบสั่งซื้อ กรุณาลองใหม่
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold"><i class="bi bi-file-earmark-check-fill text-primary"></i> รายการใบสั่งซื้อ (PO)</h2>
        <a href="<?= htmlspecialchars(app_path('pages/purchase-request-list.php')) ?>" class="btn btn-outline-primary rounded-pill px-4">
            <i class="bi bi-plus-lg"></i> สร้างจาก PR
        </a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card p-3 border-0 shadow-sm h-100">
                <div class="text-muted small">จำนวนใบสั่งซื้อทั้งหมด</div>
                <div class="fs-4 fw-bold"><?= number_format($poCount) ?> รายการ</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 border-0 shadow-sm h-100">
                <div class="text-muted small">สถานะ ORDERED</div>
                <div class="fs-4 fw-bold text-info"><?= number_format($orderedCount) ?> รายการ</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 border-0 shadow-sm h-100">
                <div class="text-muted small">ยอดรวมทั้งหมด</div>
                <div class="fs-4 fw-bold text-primary"><?= number_format($totalAmount, 2) ?> บาท</div>
            </div>
        </div>
    </div>

    <div class="card main-card p-4">
        <div class="d-flex justify-content-end mb-3">
            <div style="max-width: 420px; width: 100%;">
                <input id="poSearch" type="search" class="form-control" placeholder="ค้นหาเลขที่ PO, ผู้ขาย, หรือผู้ออกใบ...">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="poTable">
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
                <tbody id="poTableBody">
                    <?php if (count($po_rows) === 0): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">ยังไม่มีการออกใบสั่งซื้อ</td></tr>
                    <?php else: ?>
                        <?php foreach ($po_rows as $row): ?>
                    <tr>
                        <td class="fw-bold text-primary"><?= htmlspecialchars((string) ($row['po_number'] ?? '')) ?></td>
                        <td>
                            <?php
                            $createdAt = trim((string) ($row['created_at'] ?? ''));
                            echo $createdAt !== '' ? htmlspecialchars(date('d/m/Y', strtotime($createdAt)), ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>';
                            ?>
                        </td>
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
                            <?php if (($row['status'] ?? '') === 'ordered'): ?>
                                <span class="badge bg-info rounded-pill">ORDERED</span>
                            <?php elseif (($row['status'] ?? '') === 'cancelled'): ?>
                                <span class="badge bg-danger rounded-pill">CANCELLED</span>
                            <?php else: ?>
                                <span class="badge bg-secondary rounded-pill"><?= htmlspecialchars((string) ($row['status_label'] ?? 'UNKNOWN')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="btn-group shadow-sm">
                                <a href="<?= htmlspecialchars(app_path('pages/purchase-order-view.php')) ?>?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary" title="ดูรายละเอียด">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if ($isAdmin): ?>
                                    <a href="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=delete&type=purchase_order&id=<?= (int) $row['id'] ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-danger" title="ลบใบสั่งซื้อ" onclick="return confirm('ยืนยันการลบใบสั่งซื้อ <?= htmlspecialchars((string) ($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?> ?');">
                                        <i class="bi bi-trash3-fill"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
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
<script>
(function() {
    const input = document.getElementById('poSearch');
    const tbody = document.getElementById('poTableBody');
    if (!input || !tbody) return;

    const noDataRow = () => {
        const tr = document.createElement('tr');
        tr.id = 'poNoResult';
        tr.innerHTML = "<td colspan=\"7\" class=\"text-center py-4 text-muted\">ไม่พบรายการที่ค้นหา</td>";
        return tr;
    };

    input.addEventListener('input', function () {
        const q = (input.value || '').trim().toLowerCase();
        const rows = Array.from(tbody.querySelectorAll('tr'));
        let visible = 0;
        rows.forEach((row) => {
            if (row.id === 'poNoResult') return;
            const txt = (row.textContent || '').toLowerCase();
            const show = q === '' || txt.includes(q);
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        const oldNoResult = document.getElementById('poNoResult');
        if (oldNoResult) oldNoResult.remove();
        if (q !== '' && visible === 0) {
            tbody.appendChild(noDataRow());
        }
    });
})();
</script>

</body>
</html>