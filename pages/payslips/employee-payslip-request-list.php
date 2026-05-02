<?php
declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}
$role = (string) ($_SESSION['role'] ?? 'user');
if (!in_array($role, ['admin', 'Accounting'], true)) {
    header('Location: ' . app_path('index.php'));
    exit();
}

$rows = Db::tableRows('employee_payslip_requests');
Db::sortRows($rows, 'id', true);
$users = Db::tableKeyed('users');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการขอใบสลิปเงินเดือน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">รายการขอใบสลิปเงินเดือน</h4>
        <a class="btn btn-outline-secondary rounded-pill" href="<?= htmlspecialchars(app_path('pages/payslips/employee-payslip.php'), ENT_QUOTES, 'UTF-8') ?>">กลับหน้าขอสลิป</a>
    </div>
    <?php if (isset($_GET['created'])): ?><div class="alert alert-success">เพิ่มคำขอสำเร็จ และพร้อมพิมพ์ได้ทันที</div><?php endif; ?>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>#</th><th>พนักงาน</th><th>งวด</th><th>วันที่จ่าย</th><th class="text-end">ยอดสุทธิ</th><th>สถานะ</th><th class="text-end">จัดการ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php
                $uid = (string) ((int) ($r['employee_user_id'] ?? 0));
                $u = $users[$uid] ?? [];
                $name = trim((string) ($u['fname'] ?? '') . ' ' . (string) ($u['lname'] ?? ''));
                $code = strtoupper(trim((string) ($u['user_code'] ?? '')));
                $status = (string) ($r['status'] ?? 'draft');
                ?>
                <tr>
                    <td><?= (int) ($r['id'] ?? 0) ?></td>
                    <td><?= htmlspecialchars(($code !== '' ? $code . ' | ' : '') . ($name !== '' ? $name : '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($r['period'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($r['pay_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-end"><?= number_format((float) ($r['net_total'] ?? 0), 2) ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td class="text-end">
                        <?php if ($status === 'approved'): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_path('pages/payslips/employee-payslip-view.php') . '?request_id=' . (int) ($r['id'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" target="_blank">เปิด PDF</a>
                        <?php else: ?>
                            <span class="text-muted small">ยังไม่พร้อมพิมพ์</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
