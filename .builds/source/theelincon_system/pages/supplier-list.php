<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

$suppliers = Db::tableRows('suppliers');
Db::sortRows($suppliers, 'name', false);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการผู้ขาย (Suppliers)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .main-card { border: none; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container mt-5">
    <?php if(isset($_GET['success'])): ?>
        <div class="alert alert-success">บันทึกข้อมูลเรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if(isset($_GET['error']) && $_GET['error'] == 'in_use'): ?>
        <div class="alert alert-danger">ไม่สามารถลบได้: ผู้ขายรายนี้ถูกใช้ในใบสั่งซื้อ (PO) แล้ว</div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-success"><i class="bi bi-truck"></i> ระบบจัดการผู้ขาย</h2>
        <a href="supplier-form.php" class="btn btn-success px-4 shadow-sm"><i class="bi bi-plus-lg me-2"></i> เพิ่มผู้ขายใหม่</a>
    </div>

    <div class="card main-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ชื่อบริษัท/ร้านค้า</th>
                        <th>ผู้ติดต่อ</th>
                        <th>เบอร์โทรศัพท์</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($suppliers) === 0): ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted">ยังไม่มีข้อมูลผู้ขายในระบบ</td></tr>
                    <?php else: ?>
                    <?php foreach ($suppliers as $row): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['contact_person']) ?: '-' ?></td>
                        <td><?= $row['phone'] ?></td>
                        <td class="text-center">
                            <a href="supplier-form.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                            <?php if($is_admin): ?>
                            <button onclick="deleteSup(<?= $row['id'] ?>)" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
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
<script>
const actionHandlerUrl = <?= json_encode(app_path('actions/action-handler.php'), JSON_UNESCAPED_SLASHES) ?>;
function deleteSup(id) {
    if(confirm('คุณแน่ใจว่าต้องการลบรายชื่อผู้ขายนี้?')) {
        window.location.href = actionHandlerUrl + '?action=delete_supplier&id=' + id;
    }
}
</script>
</body>
</html>