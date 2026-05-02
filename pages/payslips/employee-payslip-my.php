<?php
declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$me = (int) ($_SESSION['user_id'] ?? 0);
$rows = Db::filter('employee_payslip_requests', static function (array $r) use ($me): bool {
    return (int) ($r['employee_user_id'] ?? 0) === $me;
});
Db::sortRows($rows, 'id', true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สลิปเงินเดือนของฉัน</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container py-4">
    <h4 class="fw-bold mb-3">สลิปเงินเดือนของฉัน</h4>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light"><tr><th>#</th><th>งวด</th><th>วันที่จ่าย</th><th class="text-end">ยอดสุทธิ</th><th>สถานะ</th><th class="text-end">เอกสาร</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $status=(string)($r['status']??'draft'); ?>
                <tr>
                    <td><?= (int) ($r['id'] ?? 0) ?></td>
                    <td><?= htmlspecialchars((string) ($r['period'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($r['pay_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-end"><?= number_format((float) ($r['net_total'] ?? 0), 2) ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td class="text-end">
                        <?php if ($status === 'approved'): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_path('pages/payslips/employee-payslip-view.php') . '?request_id=' . (int) ($r['id'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" target="_blank">เปิดสลิป</a>
                        <?php else: ?>
                            <span class="text-muted small">รออนุมัติ</span>
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
