<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$canApprovePr = in_array((string) $user_role, ['admin', 'Accounting'], true);
$csrfQ = '&_csrf=' . rawurlencode(csrf_token());

$users = Db::tableKeyed('users');
$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', false);
$companyName = trim((string) ((array_values($companies)[0]['name'] ?? '')));
$pr_rows = Db::tableRows('purchase_requests');
foreach ($pr_rows as &$row) {
    $rb = $users[(string) ($row['requested_by'] ?? '')] ?? null;
    $cb = $users[(string) ($row['created_by'] ?? '')] ?? null;
    $row['fname'] = $rb['fname'] ?? '';
    $row['lname'] = $rb['lname'] ?? '';
    $row['creator_fname'] = $cb['fname'] ?? '';
    $row['creator_lname'] = $cb['lname'] ?? '';
}
unset($row);
Db::sortRows($pr_rows, 'created_at', true);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการใบขอซื้อ (PR)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .table-card { border: none; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
        .btn-orange { background-color: #fd7e14; color: white; border: none; }
        .btn-orange:hover { background-color: #e86c00; color: white; }
        .badge { font-weight: 500; }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['line_error'])): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            ส่งแจ้งเตือน LINE ไม่สำเร็จ (กรุณาตรวจสอบการตั้งค่า LINE API)
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ลบใบขอซื้อเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['approved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            อนุมัติใบ PR เรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['rejected'])): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            ปฏิเสธใบ PR เรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php
            $err = $_GET['error'];
            if ($err === 'invalid_pr') {
                echo 'ไม่พบรหัสใบขอซื้อที่ถูกต้อง';
            } elseif ($err === 'delete_pr_failed') {
                echo 'ไม่สามารถลบใบขอซื้อได้ กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ';
            } else {
                echo 'เกิดข้อผิดพลาด กรุณาลองใหม่';
            }
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold">
            <i class="bi bi-cart-check-fill text-warning me-2"></i> รายการใบขอซื้อ (PR List)
        </h3>
        <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-create.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange rounded-pill px-4 shadow-sm">
            <i class="bi bi-plus-lg"></i> สร้างใบ PR ใหม่
        </a>
    </div>

    <div class="card table-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="prTable">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่ PR</th>
                        <th>วันที่ขอซื้อ/จัดจ้าง</th>
                        <th>ผู้ขอซื้อ/ผู้จัดจ้าง</th>
                        <th class="text-center">ประเภท</th>
                        <th class="text-end">ยอดรวมสุทธิ</th>
                        <th class="text-center">สถานะ</th>
                        <th>ผู้ออก/บันทึก</th>
                        <th class="text-center">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($pr_rows) > 0): ?>
                        <?php foreach ($pr_rows as $row): ?>
                        <tr>
                            <td class="fw-bold text-primary"><?= $row['pr_number'] ?></td>
                            <td><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>
                            <td><?= htmlspecialchars($companyName !== '' ? $companyName : '-', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center">
                                <?php $reqType = (string) ($row['request_type'] ?? 'purchase'); ?>
                                <?php if ($reqType === 'hire'): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle rounded-pill" style="font-size:0.75rem;">จัดจ้าง</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-secondary border rounded-pill" style="font-size:0.75rem;">จัดซื้อ</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="fw-bold"><?= number_format((float)$row['total_amount'], 2) ?></div>
                            </td>
                            <td class="text-center">
                                <?php if($row['status'] == 'pending'): ?>
                                    <span class="badge bg-warning text-dark px-3 rounded-pill">PENDING</span>
                                <?php elseif($row['status'] == 'approved'): ?>
                                    <span class="badge bg-success px-3 rounded-pill">APPROVED</span>
                                <?php else: ?>
                                    <span class="badge bg-danger px-3 rounded-pill">REJECTED</span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?php
                                $cr = trim(($row['creator_fname'] ?? '') . ' ' . ($row['creator_lname'] ?? ''));
                                echo $cr !== '' ? htmlspecialchars($cr) : '<span class="text-muted">—</span>';
                            ?></td>
                            <td class="text-center">
                                <div class="btn-group shadow-sm rounded">
                                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-view.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= $row['id'] ?>" class="btn btn-sm btn-white text-primary border" title="ดูรายละเอียด">
                                        <i class="bi bi-eye-fill"></i>
                                    </a>

                                    <?php if($row['status'] == 'pending'): ?>
                                        <?php if ($canApprovePr): ?>
                                            <a href="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=approve_pr&id=<?= (int) $row['id'] ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" 
                                               class="btn btn-sm btn-white text-success border" 
                                               onclick="return confirm('ยืนยันการอนุมัติใบขอซื้อ <?= htmlspecialchars((string) ($row['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>?')">
                                                <i class="bi bi-check-circle-fill"></i>
                                            </a>
                                            <a href="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=reject_pr&id=<?= (int) $row['id'] ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" 
                                               class="btn btn-sm btn-white text-danger border" 
                                               onclick="return confirm('ยืนยันการปฏิเสธใบขอซื้อ <?= htmlspecialchars((string) ($row['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>?')">
                                                <i class="bi bi-x-circle-fill"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($user_role === 'admin'): ?>
                                        <a href="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=delete_pr&id=<?= $row['id'] ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" 
                                           class="btn btn-sm btn-white text-secondary border" 
                                           onclick="return confirm('ยืนยันการลบข้อมูลถาวร?')">
                                            <i class="bi bi-trash3-fill text-danger"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">ไม่พบข้อมูลใบขอซื้อ</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>