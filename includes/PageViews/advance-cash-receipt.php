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
$isFinanceRole = isset($_SESSION['role']) && in_array((string) $_SESSION['role'], ['admin', 'Accounting'], true);
if (!$isFinanceRole) {
    header('Location: ' . app_path('pages/advance-cash-list.php'));
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$row = Db::row('advance_cash_requests', (string) $id);
if ($row === null) {
    header('Location: ' . app_path('pages/advance-cash-list.php') . '?error=not_found');
    exit;
}
if ((string) ($row['status'] ?? '') !== 'approved') {
    header('Location: ' . app_path('pages/advance-cash-view.php') . '?id=' . $id . '&error=receipt_requires_approved');
    exit;
}

$users = Db::tableKeyed('users');
$requester = $users[(string) ((int) ($row['requested_by'] ?? 0))] ?? [];
$requesterName = trim((string) ($requester['fname'] ?? '') . ' ' . (string) ($requester['lname'] ?? ''));
if ($requesterName === '') {
    $requesterName = '-';
}
$paymentMethod = (string) ($row['receipt_payment_method'] ?? '');
$slipUrl = trim((string) ($row['receipt_transfer_slip_url'] ?? ''));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ออกใบเสร็จรับเงินเบิกล่วงหน้า</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .card-main { border: none; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>
<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'invalid_input'): ?>
        <div class="alert alert-danger">กรุณากรอกข้อมูลใบเสร็จให้ครบ</div>
    <?php endif; ?>
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'slip_required'): ?>
        <div class="alert alert-warning">กรณีโอนเงิน ต้องแนบสลิปโอนเงิน</div>
    <?php endif; ?>
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'upload_type'): ?>
        <div class="alert alert-warning">ไฟล์สลิปไม่ถูกต้อง (รองรับ jpg/png/webp/gif/pdf)</div>
    <?php endif; ?>
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'upload_failed'): ?>
        <div class="alert alert-danger">อัปโหลดสลิปไม่สำเร็จ</div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="fw-bold"><i class="bi bi-receipt text-warning me-2"></i>ออกใบเสร็จรับเงิน</h3>
        <a href="<?= htmlspecialchars(app_path('pages/advance-cash-view.php') . '?id=' . $id, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-4">กลับรายละเอียด</a>
    </div>

    <div class="card card-main p-4">
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="text-muted small">เลขที่คำขอ</div>
                <div class="fw-semibold text-primary"><?= htmlspecialchars((string) ($row['request_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">ผู้รับเงิน</div>
                <div class="fw-semibold"><?= htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">จำนวนเงิน</div>
                <div class="fw-bold text-success">฿<?= number_format((float) ($row['amount'] ?? 0), 2) ?></div>
            </div>
        </div>

        <form action="<?= htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8') ?>?action=save_advance_cash_receipt&id=<?= (int) $id ?>" method="post" enctype="multipart/form-data" class="row g-3">
            <?php csrf_field(); ?>
            <div class="col-md-4">
                <label class="form-label fw-semibold">วันที่ออกใบเสร็จ</label>
                <input type="date" name="receipt_date" class="form-control" required value="<?= htmlspecialchars((string) ($row['receipt_date'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-md-8">
                <label class="form-label fw-semibold">ชื่อผู้รับเงิน</label>
                <input type="text" class="form-control bg-light" readonly value="<?= htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">วิธีรับเงิน</label>
                <select name="receipt_payment_method" id="receiptPaymentMethod" class="form-select" required>
                    <option value="">เลือกวิธีรับเงิน</option>
                    <option value="cash" <?= $paymentMethod === 'cash' ? 'selected' : '' ?>>เงินสด</option>
                    <option value="transfer" <?= $paymentMethod === 'transfer' ? 'selected' : '' ?>>เงินโอน</option>
                </select>
            </div>
            <div class="col-12" id="transferSlipWrap" style="display:none;">
                <label class="form-label fw-semibold">แนบสลิปโอนเงิน</label>
                <input type="file" name="transfer_slip" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,image/*,application/pdf">
                <div class="form-text">บังคับแนบเมื่อเลือกวิธีรับเงินเป็น "เงินโอน"</div>
                <?php if ($slipUrl !== ''): ?>
                    <div class="mt-2">
                        <a href="<?= htmlspecialchars($slipUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-paperclip me-1"></i>ดูสลิปแนบล่าสุด
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-12 d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-success px-4">
                    <i class="bi bi-check-circle me-1"></i>บันทึกใบเสร็จรับเงิน
                </button>
                <a href="<?= htmlspecialchars(app_path('pages/advance-cash-view.php') . '?id=' . $id, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light border px-4">ยกเลิก</a>
            </div>
        </form>

        <hr class="my-4">
        <div class="border rounded p-3 bg-light">
            <h6 class="fw-bold mb-3">ตัวอย่างส่วนลงนามในใบเสร็จรับเงิน</h6>
            <div class="row g-3">
                <div class="col-md-6">
                    ผู้รับเงิน: <span class="fw-semibold"><?= htmlspecialchars((string) ($row['receipt_receiver_name'] ?? $requesterName), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="col-md-6 text-md-end">
                    วันที่รับเงิน: <span class="fw-semibold"><?= htmlspecialchars((string) ($row['receipt_date'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="col-12 pt-4">
                    ลายเซ็นผู้รับเงิน ...........................................................
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const methodEl = document.getElementById('receiptPaymentMethod');
    const slipWrap = document.getElementById('transferSlipWrap');
    function sync() {
        const isTransfer = methodEl && methodEl.value === 'transfer';
        slipWrap.style.display = isTransfer ? '' : 'none';
    }
    if (methodEl) {
        methodEl.addEventListener('change', sync);
    }
    sync();
})();
</script>
</body>
</html>
