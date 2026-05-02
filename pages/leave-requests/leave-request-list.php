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
$isAdmin = isset($_SESSION['role']) && (string) $_SESSION['role'] === 'admin';
$showAll = $isAdmin && isset($_GET['scope']) && (string) $_GET['scope'] === 'all';
$csrfQ = '&_csrf=' . rawurlencode(csrf_token());
$users = Db::tableKeyed('users');
$allRows = $showAll
    ? Db::tableRows('leave_requests')
    : Db::filter('leave_requests', static function (array $row) use ($me): bool {
        return isset($row['requested_by']) && (int) $row['requested_by'] === $me;
    });
Db::sortRows($allRows, 'created_at', true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $showAll ? 'รายการใบลาทั้งหมด' : 'รายการใบลาของฉัน' ?></title>
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
    <?php if (!empty($_GET['success']) && $_GET['success'] === '1'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            สร้างคำขอใบลาเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['sent']) && $_GET['sent'] === '1'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ส่งใบลาไป LINE เรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['line_error']) && $_GET['line_error'] === '1'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            บันทึกใบลาแล้ว แต่ส่งไป LINE ไม่สำเร็จ — เปิดรายละเอียดใบลานั้นเพื่อดูข้อความเตือน หรือแจ้งผู้ดูแลระบบ
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['deleted']) && $_GET['deleted'] === '1'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ลบใบลาเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="bi bi-calendar-check text-warning me-2"></i><?= $showAll ? 'ใบลาทั้งหมดในระบบ' : 'ใบลาของฉัน' ?></h3>
        <div class="d-flex align-items-center gap-2">
            <?php if ($isAdmin): ?>
                <?php if ($showAll): ?>
                    <a href="<?= htmlspecialchars(app_path('pages/leave-requests/leave-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark rounded-pill px-3">
                        <i class="bi bi-person me-1"></i> เฉพาะของฉัน
                    </a>
                <?php else: ?>
                    <a href="<?= htmlspecialchars(app_path('pages/leave-requests/leave-request-list.php') . '?scope=all', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark rounded-pill px-3">
                        <i class="bi bi-collection me-1"></i> โชว์ใบลาทั้งหมด
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(app_path('pages/leave-requests/leave-request-create.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange rounded-pill px-4 shadow-sm">
                <i class="bi bi-plus-lg"></i> สร้างคำขออนุญาติลา
            </a>
        </div>
    </div>

    <div class="card table-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่คำขอ</th>
                        <?php if ($showAll): ?>
                            <th>ผู้ขอ</th>
                        <?php endif; ?>
                        <th>ประเภทการลา</th>
                        <th>ช่วงวันลา</th>
                        <th class="text-center">จำนวนวัน</th>
                        <th class="text-center">สถานะ</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($allRows) > 0): ?>
                    <?php foreach ($allRows as $row): ?>
                        <?php
                        $status = (string) ($row['status'] ?? 'draft');
                        $badgeClass = 'bg-secondary';
                        if ($status === 'pending') {
                            $badgeClass = 'bg-warning text-dark';
                        } elseif ($status === 'approved') {
                            $badgeClass = 'bg-success';
                        } elseif ($status === 'rejected') {
                            $badgeClass = 'bg-danger';
                        }
                        ?>
                        <tr>
                            <td class="fw-bold text-primary"><?= htmlspecialchars((string) ($row['leave_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <?php if ($showAll): ?>
                                <td>
                                    <?php
                                    $uid = (string) ($row['requested_by'] ?? '');
                                    $u = $users[$uid] ?? [];
                                    $name = trim((string) ($u['fname'] ?? '') . ' ' . (string) ($u['lname'] ?? ''));
                                    echo htmlspecialchars($name !== '' ? $name : '-', ENT_QUOTES, 'UTF-8');
                                    ?>
                                </td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars((string) ($row['leave_type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?= htmlspecialchars((string) ($row['start_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                -
                                <?= htmlspecialchars((string) ($row['end_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-center fw-semibold"><?= number_format((float) ($row['days_count'] ?? 0), 2) ?></td>
                            <td class="text-center"><span class="badge <?= $badgeClass ?> rounded-pill"><?= strtoupper($status) ?></span></td>
                            <td class="text-center">
                                <a href="<?= htmlspecialchars(app_path('pages/leave-requests/leave-request-view.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) ($row['id'] ?? 0) ?>" class="btn btn-sm btn-outline-primary" title="ดูรายละเอียด">
                                    <i class="bi bi-eye-fill"></i>
                                </a>
                                <?php if ($isAdmin): ?>
                                    <a href="<?= htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8') ?>?action=delete_leave_request&id=<?= (int) ($row['id'] ?? 0) ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-danger" title="ลบใบลา" onclick="return confirm('ยืนยันการลบใบลา <?= htmlspecialchars((string) ($row['leave_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?> ?');">
                                        <i class="bi bi-trash-fill"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="<?= $showAll ? '7' : '6' ?>" class="text-center py-4 text-muted">ยังไม่มีข้อมูลใบลา</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

