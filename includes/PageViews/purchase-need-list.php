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

$user_role = $_SESSION['role'] ?? 'user';
$csrfQ = '&_csrf=' . rawurlencode(csrf_token());

$users = Db::tableKeyed('users');
$need_rows = Db::tableRows('purchase_needs');
foreach ($need_rows as &$needRow) {
    $rb = $users[(string) ($needRow['requested_by'] ?? '')] ?? null;
    $needRow['fname'] = $rb['fname'] ?? '';
    $needRow['lname'] = $rb['lname'] ?? '';
}
unset($needRow);
Db::sortRows($need_rows, 'created_at', true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการใบต้องการซื้อ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .table-card { border: none; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['need_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            บันทึกใบต้องการซื้อเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['line_need_error'])): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            ส่งแจ้งเตือน LINE ไม่สำเร็จ
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['line_need_sent'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ส่งแจ้งเตือนขออนุมัติผ่าน LINE เรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['need_deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ลบใบต้องการซื้อเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['approved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            อนุมัติใบต้องการซื้อเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['rejected'])): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            ไม่อนุมัติใบต้องการซื้อเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'invalid_need'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            ไม่พบรหัสใบต้องการซื้อที่ถูกต้อง
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'invalid_need_status'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            ส่งแจ้งเตือนได้เฉพาะใบต้องการซื้อที่สถานะ PENDING เท่านั้น
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="bi bi-card-checklist text-primary me-2"></i>รายการใบต้องการซื้อ</h3>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars(app_path('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
                <i class="bi bi-arrow-left"></i> กลับหน้าเมนูหลัก
            </a>
            <a href="purchase-need-create.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
                <i class="bi bi-plus-lg"></i> สร้างใบต้องการซื้อ
            </a>
        </div>
    </div>

    <div class="card table-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่เอกสาร</th>
                        <th>วันที่</th>
                        <th>ผู้ขอ</th>
                        <th>รายละเอียด</th>
                        <th class="text-center">สถานะ</th>
                        <th class="text-center">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($need_rows) === 0): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">ยังไม่มีข้อมูลใบต้องการซื้อ</td></tr>
                    <?php else: ?>
                        <?php foreach ($need_rows as $row): ?>
                        <tr>
                            <td class="fw-bold text-primary"><?= htmlspecialchars((string) ($row['need_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(date('d/m/Y', strtotime((string) ($row['created_at'] ?? date('Y-m-d')))), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="small">
                                <div><?= htmlspecialchars((string) ($row['details'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php if (trim((string) ($row['site_name'] ?? '')) !== ''): ?>
                                    <div class="text-muted mt-1">
                                        <i class="bi bi-geo-alt-fill"></i>
                                        <?= htmlspecialchars((string) ($row['site_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if (($row['status'] ?? '') === 'approved'): ?>
                                    <span class="badge bg-success px-3 rounded-pill">APPROVED</span>
                                <?php elseif (($row['status'] ?? '') === 'rejected'): ?>
                                    <span class="badge bg-danger px-3 rounded-pill">REJECTED</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark px-3 rounded-pill">PENDING</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group shadow-sm rounded">
                                    <a href="purchase-need-view.php?id=<?= (int) ($row['id'] ?? 0) ?>" class="btn btn-sm btn-white text-primary border" title="ดูรายละเอียด">
                                        <i class="bi bi-eye-fill"></i>
                                    </a>
                                    <?php if (($row['status'] ?? '') === 'pending'): ?>
                                        <?php $lineSentOnce = trim((string) ($row['line_sent_at'] ?? '')) !== ''; ?>
                                        <a href="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=send_purchase_need_request&id=<?= (int) ($row['id'] ?? 0) ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-white text-warning border" title="<?= $lineSentOnce ? 'ส่งแจ้งเตือนซ้ำผ่าน LINE' : 'ส่งแจ้งเตือนผ่าน LINE เพื่อขออนุมัติ' ?>" onclick="return confirm('<?= $lineSentOnce ? 'ยืนยันการส่งแจ้งเตือนซ้ำไป LINE ?' : 'ยืนยันการส่งแจ้งเตือนไป LINE เพื่อขออนุมัติ ?' ?>')">
                                            <i class="bi bi-bell-fill"></i><span class="d-none d-lg-inline ms-1"><?= $lineSentOnce ? 'ส่งซ้ำ' : 'แจ้งเตือน' ?></span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($user_role === 'admin'): ?>
                                        <a href="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=delete_purchase_need&id=<?= (int) ($row['id'] ?? 0) ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-white text-secondary border" onclick="return confirm('ยืนยันการลบข้อมูลถาวร?')">
                                            <i class="bi bi-trash3-fill text-danger"></i>
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
</body>
</html>
