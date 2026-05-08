<?php
declare(strict_types=1);


require_once __DIR__ . '/_page_root.php';
use Theelincon\Rtdb\Db;

session_start();
require_once THEELINCON_ROOT . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$me = (int) $_SESSION['user_id'];
$isFinanceRole = isset($_SESSION['role']) && in_array((string) $_SESSION['role'], ['admin', 'Accounting'], true);
$isAdmin = isset($_SESSION['role']) && (string) $_SESSION['role'] === 'admin';
$row = Db::row('advance_cash_requests', (string) $id);

if (!$isFinanceRole || !$row) {
    header('Location: ' . app_path('pages/advance-cash-list.php'));
    exit;
}

$users = Db::tableKeyed('users');
$requester = $users[(string) ((int) ($row['requested_by'] ?? 0))] ?? [];
$requesterName = trim((string) ($requester['fname'] ?? '') . ' ' . (string) ($requester['lname'] ?? ''));
if ($requesterName === '') {
    $requesterName = '-';
}
$approver = $users[(string) ((int) ($row['approved_by'] ?? 0))] ?? [];
$approverName = trim((string) ($approver['fname'] ?? '') . ' ' . (string) ($approver['lname'] ?? ''));

$status = (string) ($row['status'] ?? 'pending');
$badgeClass = 'bg-secondary';
if ($status === 'pending') { $badgeClass = 'bg-warning text-dark'; }
elseif ($status === 'approved') { $badgeClass = 'bg-success'; }
elseif ($status === 'rejected') { $badgeClass = 'bg-danger'; }
$csrfQ = '&_csrf=' . rawurlencode(csrf_token());
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดคำขอเบิกเงินล่วงหน้า</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .detail-card { border: none; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['receipt_saved'])): ?>
        <div class="alert alert-success">บันทึกใบเสร็จรับเงินเรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'receipt_requires_approved'): ?>
        <div class="alert alert-warning">ออกใบเสร็จได้เฉพาะรายการที่อนุมัติแล้ว</div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="fw-bold"><i class="bi bi-file-earmark-text text-warning me-2"></i>รายละเอียดคำขอเบิกเงินล่วงหน้า</h3>
        <a href="<?= htmlspecialchars(app_path('pages/advance-cash-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-4">กลับรายการ</a>
    </div>

    <div class="card detail-card p-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="text-muted small">เลขที่คำขอ</div>
                <div class="fw-bold fs-5 text-primary"><?= htmlspecialchars((string) ($row['request_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <span class="badge <?= $badgeClass ?> rounded-pill px-3 py-2"><?= strtoupper($status) ?></span>
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <div class="text-muted small">ผู้ขอ</div>
                <div class="fw-semibold"><?= htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">วันที่ขอ</div>
                <div class="fw-semibold"><?= htmlspecialchars((string) ($row['request_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">จำนวนเงิน</div>
                <div class="fw-bold text-success fs-5">฿<?= number_format((float) ($row['amount'] ?? 0), 2) ?></div>
            </div>
            <div class="col-md-8">
                <div class="text-muted small">ผู้อนุมัติ/พิจารณา</div>
                <div class="fw-semibold"><?= htmlspecialchars($approverName !== '' ? $approverName : '-', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="col-12">
                <div class="text-muted small">วัตถุประสงค์การเบิก</div>
                <div class="p-3 bg-light rounded"><?= nl2br(htmlspecialchars((string) ($row['purpose'] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div>
            </div>
            <?php if (trim((string) ($row['decision_note'] ?? '')) !== ''): ?>
                <div class="col-12">
                    <div class="text-muted small">หมายเหตุการอนุมัติ/ปฏิเสธ</div>
                    <div class="p-3 bg-light rounded"><?= nl2br(htmlspecialchars((string) ($row['decision_note'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
                </div>
            <?php endif; ?>
            <div class="col-md-6">
                <div class="text-muted small">สถานะใบเสร็จรับเงิน</div>
                <div class="fw-semibold"><?= ((string) ($row['receipt_status'] ?? 'none') === 'issued') ? 'ออกใบเสร็จแล้ว' : 'ยังไม่ออกใบเสร็จ' ?></div>
            </div>
            <?php if ((string) ($row['receipt_status'] ?? 'none') === 'issued'): ?>
                <div class="col-md-6">
                    <div class="text-muted small">เลขที่ใบเสร็จ</div>
                    <div class="fw-semibold"><?= htmlspecialchars((string) ($row['receipt_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($isFinanceRole): ?>
            <div class="mt-4 d-flex gap-2 flex-wrap">
            <?php if ($status === 'pending'): ?>
                <a href="<?= htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8') ?>?action=approve_advance_cash_request&id=<?= (int) ($row['id'] ?? 0) ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-success px-4" onclick="return confirm('ยืนยันอนุมัติคำขอนี้ ?')">
                    <i class="bi bi-check-circle me-1"></i> อนุมัติ
                </a>
                <a href="<?= htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8') ?>?action=reject_advance_cash_request&id=<?= (int) ($row['id'] ?? 0) ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-danger px-4" onclick="return confirm('ยืนยันปฏิเสธคำขอนี้ ?')">
                    <i class="bi bi-x-circle me-1"></i> ปฏิเสธ
                </a>
            <?php endif; ?>
            <?php if ($status === 'approved'): ?>
                <a href="<?= htmlspecialchars(app_path('pages/advance-cash-receipt.php') . '?id=' . (int) ($row['id'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary px-4">
                    <i class="bi bi-receipt me-1"></i> ออกใบเสร็จรับเงิน
                </a>
            <?php endif; ?>
            <?php if ((string) ($row['receipt_status'] ?? 'none') === 'issued'): ?>
                <a href="<?= htmlspecialchars(app_path('pages/advance-cash-receipt-print.php') . '?id=' . (int) ($row['id'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark px-4" target="_blank" rel="noopener">
                    <i class="bi bi-printer me-1"></i> พิมพ์ใบเสร็จรับเงิน
                </a>
            <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
