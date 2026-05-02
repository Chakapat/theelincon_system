<?php
declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$me = (int) $_SESSION['user_id'];
$isFinanceRole = isset($_SESSION['role']) && in_array((string) $_SESSION['role'], ['admin', 'Accounting'], true);
if (!$isFinanceRole) {
    header('Location: ' . app_path('index.php'));
    exit;
}
$showAll = isset($_GET['scope']) && (string) $_GET['scope'] === 'all';
$csrfQ = '&_csrf=' . rawurlencode(csrf_token());

$users = Db::tableKeyed('users');
$rows = $showAll
    ? Db::tableRows('advance_cash_requests')
    : Db::filter('advance_cash_requests', static function (array $r) use ($me): bool {
        return (int) ($r['requested_by'] ?? 0) === $me;
    });
Db::sortRows($rows, 'created_at', true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $showAll ? 'คำขอเบิกเงินล่วงหน้าทั้งหมด' : 'คำขอเบิกเงินล่วงหน้าของฉัน' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .table-card { border: none; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
        .btn-orange { background-color: #fd7e14; color: #fff; border: none; }
        .btn-orange:hover { background-color: #e86c00; color: #fff; }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['success'])): ?><div class="alert alert-success">บันทึกคำขอเรียบร้อยแล้ว</div><?php endif; ?>
    <?php if (!empty($_GET['line_error'])): ?><div class="alert alert-warning">บันทึกคำขอแล้ว แต่ส่งแจ้งเตือน LINE ไม่สำเร็จ</div><?php endif; ?>
    <?php if (!empty($_GET['approved'])): ?><div class="alert alert-success">อนุมัติคำขอเรียบร้อยแล้ว</div><?php endif; ?>
    <?php if (!empty($_GET['rejected'])): ?><div class="alert alert-warning">ปฏิเสธคำขอเรียบร้อยแล้ว</div><?php endif; ?>
    <?php if (!empty($_GET['deleted'])): ?><div class="alert alert-success">ลบคำขอเรียบร้อยแล้ว</div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="bi bi-wallet2 text-warning me-2"></i><?= $showAll ? 'คำขอเบิกเงินล่วงหน้าทั้งหมด' : 'คำขอเบิกเงินล่วงหน้าของฉัน' ?></h3>
        <div class="d-flex align-items-center gap-2">
            <?php if ($isFinanceRole): ?>
                <?php if ($showAll): ?>
                    <a href="<?= htmlspecialchars(app_path('pages/advance-cash/advance-cash-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark rounded-pill px-3">เฉพาะของฉัน</a>
                <?php else: ?>
                    <a href="<?= htmlspecialchars(app_path('pages/advance-cash/advance-cash-list.php') . '?scope=all', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark rounded-pill px-3">ทั้งหมด</a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(app_path('pages/advance-cash/advance-cash-create.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange rounded-pill px-4 shadow-sm">
                <i class="bi bi-plus-lg"></i> สร้างคำขอเบิกเงิน
            </a>
        </div>
    </div>

    <div class="card table-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                <tr>
                    <th>เลขที่คำขอ</th>
                    <?php if ($showAll): ?><th>ผู้ขอ</th><?php endif; ?>
                    <th>วันที่ขอ</th>
                    <th class="text-end">จำนวนเงิน</th>
                    <th class="text-center">สถานะ</th>
                    <th class="text-center">ใบเสร็จรับเงิน</th>
                    <th class="text-center">จัดการ</th>
                </tr>
                </thead>
                <tbody>
                <?php if (count($rows) === 0): ?>
                    <tr><td colspan="<?= $showAll ? '7' : '6' ?>" class="text-center text-muted py-4">ยังไม่มีคำขอเบิกเงิน</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $status = (string) ($row['status'] ?? 'pending');
                        $badgeClass = 'bg-secondary';
                        if ($status === 'pending') { $badgeClass = 'bg-warning text-dark'; }
                        elseif ($status === 'approved') { $badgeClass = 'bg-success'; }
                        elseif ($status === 'rejected') { $badgeClass = 'bg-danger'; }
                        $receiptStatus = (string) ($row['receipt_status'] ?? 'none');
                        $receiptText = $receiptStatus === 'issued' ? 'ออกแล้ว' : 'ยังไม่ออก';
                        $receiptClass = $receiptStatus === 'issued' ? 'bg-success' : 'bg-secondary';
                        ?>
                        <tr>
                            <td class="fw-bold text-primary"><?= htmlspecialchars((string) ($row['request_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <?php if ($showAll): ?>
                                <td>
                                    <?php
                                    $u = $users[(string) ((int) ($row['requested_by'] ?? 0))] ?? [];
                                    $name = trim((string) ($u['fname'] ?? '') . ' ' . (string) ($u['lname'] ?? ''));
                                    echo htmlspecialchars($name !== '' ? $name : '-', ENT_QUOTES, 'UTF-8');
                                    ?>
                                </td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars((string) ($row['request_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-end fw-semibold">฿<?= number_format((float) ($row['amount'] ?? 0), 2) ?></td>
                            <td class="text-center"><span class="badge <?= $badgeClass ?> rounded-pill"><?= strtoupper($status) ?></span></td>
                            <td class="text-center"><span class="badge <?= $receiptClass ?> rounded-pill"><?= htmlspecialchars($receiptText, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td class="text-center">
                                <a href="<?= htmlspecialchars(app_path('pages/advance-cash/advance-cash-view.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) ($row['id'] ?? 0) ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye-fill"></i>
                                </a>
                                <?php if ($isFinanceRole): ?>
                                    <a href="<?= htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8') ?>?action=delete_advance_cash_request&id=<?= (int) ($row['id'] ?? 0) ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('ยืนยันการลบคำขอนี้ ?');">
                                        <i class="bi bi-trash-fill"></i>
                                    </a>
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
