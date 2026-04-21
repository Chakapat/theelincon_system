<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$csrfQ = '&_csrf=' . rawurlencode(csrf_token());

$pr_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$pr = Db::row('purchase_requests', (string) $pr_id);
if (!$pr) {
    echo "<script>alert('ไม่พบข้อมูลใบขอซื้อ'); window.location.href='" . htmlspecialchars(app_path('pages/purchase-request-list.php'), ENT_QUOTES) . "';</script>";
    exit();
}

$users = Db::tableKeyed('users');
$rb = $users[(string) ($pr['requested_by'] ?? '')] ?? null;
$cb = $users[(string) ($pr['created_by'] ?? '')] ?? null;
$pr['fname'] = $rb['fname'] ?? '';
$pr['lname'] = $rb['lname'] ?? '';
$pr['creator_fname'] = $cb['fname'] ?? '';
$pr['creator_lname'] = $cb['lname'] ?? '';

$item_rows = Db::filter('purchase_request_items', static function (array $r) use ($pr_id): bool {
    return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
});
Db::sortRows($item_rows, 'id', false);

$existing_po = Db::findFirst('purchase_orders', static function (array $r) use ($pr_id): bool {
    return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
});
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดใบขอซื้อ: <?= $pr['pr_number'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .card { border: none; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); }
        .btn-back { color: #6c757d; text-decoration: none; transition: 0.3s; }
        .btn-back:hover { color: #000; }
        .badge-pending { background-color: #ffc107; color: #000; }
        .badge-approved { background-color: #198754; color: #fff; }
        .badge-rejected { background-color: #dc3545; color: #fff; }
        .table thead { background-color: #f1f3f5; }
        .main-container { max-width: 900px; margin-top: 3rem; margin-bottom: 5rem; }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?> 

<div class="container main-container">

    <?php if (!empty($_GET['error']) && $_GET['error'] === 'po_exists'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            ใบขอซื้อนี้มีใบสั่งซื้อแล้ว ไม่สามารถออกซ้ำได้
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <div class="mb-3">
        <a href="javascript:history.back()" class="btn-back fw-bold">
            <i class="bi bi-arrow-left-circle-fill me-1"></i> ย้อนกลับไปหน้ารายการ
        </a>
    </div>

    <div class="card p-4 p-md-5">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <span class="text-muted small">เลขที่ใบขอซื้อ</span>
                <h3 class="fw-bold text-primary mb-1"><?= $pr['pr_number'] ?></h3>
                <p class="mb-0"><strong>ผู้ขอซื้อ:</strong> <?= htmlspecialchars(trim(($pr['fname'] ?? '') . ' ' . ($pr['lname'] ?? ''))) ?></p>
                <?php
                $crn = trim(($pr['creator_fname'] ?? '') . ' ' . ($pr['creator_lname'] ?? ''));
                if ($crn !== ''):
                ?>
                <p class="mb-0 small text-muted"><strong>ผู้ออก/บันทึกในระบบ:</strong> <?= htmlspecialchars($crn) ?></p>
                <?php endif; ?>
            </div>
            <div class="text-end">
                <span class="badge badge-<?= strtolower($pr['status']) ?> px-4 py-2 rounded-pill fs-6">
                    <?= strtoupper($pr['status']) ?>
                </span>
                <p class="small text-muted mt-2">วันที่ขอซื้อ: <?= date('d/m/Y', strtotime($pr['created_at'])) ?></p>
                <?php if ((int)($pr['vat_enabled'] ?? 0) === 1): ?>
                <span class="badge bg-success mt-1">รวม VAT 7%</span>
                <?php else: ?>
                <span class="badge bg-secondary mt-1">ไม่รวม VAT</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-light p-3 rounded mb-4">
            <h6 class="fw-bold mb-1"><i class="bi bi-info-circle me-1"></i> รายละเอียด/วัตถุประสงค์:</h6>
            <p class="mb-0 text-secondary"><?= nl2br(htmlspecialchars($pr['details'])) ?: 'ไม่ได้ระบุรายละเอียด' ?></p>
        </div>

        <hr>

        <div class="table-responsive mb-4">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th width="8%" class="text-center">#</th>
                        <th>รายการสินค้า</th>
                        <th width="15%" class="text-center">จำนวน</th>
                        <th width="15%" class="text-center">ราคา/หน่วย</th>
                        <th width="18%" class="text-end">รวมเป็นเงิน</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    if (count($item_rows) > 0):
                        foreach ($item_rows as $item):
                    ?>
                    <tr>
                        <td class="text-center text-muted"><?= $i++ ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($item['description']) ?></td>
                        <td class="text-center"><?= number_format($item['quantity'], 2) ?> <small class="text-muted"><?= $item['unit'] ?></small></td>
                        <td class="text-center"><?= number_format($item['unit_price'], 2) ?></td>
                        <td class="text-end fw-bold"><?= number_format($item['total'], 2) ?></td>
                    </tr>
                    <?php
                        endforeach;
                    else:
                    ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted">ไม่พบรายการสินค้าในใบขอซื้อนี้</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light">
                    <?php
                    $pv = (float)($pr['vat_amount'] ?? 0);
                    $pg = (float)$pr['total_amount'];
                    if (isset($pr['subtotal_amount']) && $pr['subtotal_amount'] !== null && $pr['subtotal_amount'] !== '') {
                        $ps = (float)$pr['subtotal_amount'];
                    } else {
                        $ps = round($pg - $pv, 2);
                    }
                    ?>
                    <tr>
                        <th colspan="4" class="text-end py-2">ยอดรายการ (ก่อน VAT)</th>
                        <th class="text-end py-2"><?= number_format($ps, 2) ?></th>
                    </tr>
                    <?php if ((int)($pr['vat_enabled'] ?? 0) === 1): ?>
                    <tr>
                        <th colspan="4" class="text-end py-2 text-success">VAT 7%</th>
                        <th class="text-end py-2 text-success"><?= number_format($pv, 2) ?></th>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th colspan="4" class="text-end py-3">ยอดรวมสุทธิ (Total)</th>
                        <th class="text-end text-primary py-3 fs-5"><?= number_format($pg, 2) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="row g-3 justify-content-center mt-2">
            <?php 
            // 1. ปุ่มอนุมัติ/ปฏิเสธ: แสดงเฉพาะสถานะ pending และผู้ใช้ต้องเป็น admin
            if($pr['status'] == 'pending' && isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): 
            ?>
                <div class="col-md-5">
                    <a href="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=approve_pr&id=<?= $pr['id'] ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-success w-100 py-2 fw-bold" onclick="return confirm('ยืนยันการอนุมัติ?')">
                        <i class="bi bi-check-circle-fill me-2"></i> อนุมัติ (Approve)
                    </a>
                </div>
                <div class="col-md-5">
                    <a href="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=reject_pr&id=<?= $pr['id'] ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-danger w-100 py-2 fw-bold" onclick="return confirm('ยืนยันการปฏิเสธ?')">
                        <i class="bi bi-x-circle-fill me-2"></i> ปฏิเสธ (Reject)
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($pr['status'] == 'approved' && $existing_po): ?>
                <div class="col-md-8">
                    <div class="alert alert-secondary border mb-0">
                        <p class="mb-2 fw-semibold"><i class="bi bi-lock-fill me-1"></i> ใบขอซื้อนี้ออกใบสั่งซื้อ (PO) แล้ว ไม่สามารถออกซ้ำได้</p>
                        <p class="small text-muted mb-2">เลขที่ PO: <strong><?= htmlspecialchars($existing_po['po_number']) ?></strong></p>
                        <a href="purchase-order-view.php?id=<?= (int)$existing_po['id'] ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-eye-fill me-1"></i> ดูใบสั่งซื้อ
                        </a>
                        <a href="purchase-order-list.php" class="btn btn-light btn-sm border ms-1">ไปรายการ PO</a>
                    </div>
                </div>
            <?php elseif ($pr['status'] == 'approved'): ?>
                <div class="col-md-6">
                    <a href="purchase-order-from-pr.php?pr_id=<?= $pr['id'] ?>" class="btn btn-primary w-100 py-3 fw-bold shadow">
                        <i class="bi bi-file-earmark-plus-fill me-2"></i> ออกใบสั่งซื้อ (Create Purchase Order)
                    </a>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>