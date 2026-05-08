<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

use Theelincon\Rtdb\Db;

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isFinanceRole = user_is_finance_role();
if (!$isFinanceRole) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$row = Db::rowByIdField('advance_cash_requests', $id);
if ($row === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$part = (string) ($_GET['part'] ?? '');
if ($part === 'receipt') {
    if ((string) ($row['status'] ?? '') !== 'approved') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'not_approved'], JSON_UNESCAPED_UNICODE);
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
    $handler = htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8');
    $rid = (int) ($row['id'] ?? 0);
    $reqNo = (string) ($row['request_number'] ?? '');

    ob_start();
    ?>
<div class="advance-receipt-modal-inner">
    <div class="mb-3">
        <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill btn-advance-back-detail">
            <i class="bi bi-arrow-left me-1"></i>กลับรายละเอียด
        </button>
    </div>
    <div class="row g-3 mb-3 small">
        <div class="col-md-4">
            <div class="text-muted small">เลขที่คำขอ</div>
            <div class="fw-semibold text-primary"><?= htmlspecialchars($reqNo, ENT_QUOTES, 'UTF-8') ?></div>
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

    <form action="<?= $handler ?>?action=save_advance_cash_receipt&id=<?= $rid ?>" method="post" enctype="multipart/form-data" class="row g-3">
        <?php csrf_field(); ?>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">วันที่ออกใบเสร็จ</label>
            <input type="date" name="receipt_date" class="form-control form-control-sm" required value="<?= htmlspecialchars((string) ($row['receipt_date'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-md-8">
            <label class="form-label fw-semibold small">ชื่อผู้รับเงิน</label>
            <input type="text" class="form-control form-control-sm bg-light" readonly value="<?= htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold small">วิธีรับเงิน</label>
            <select name="receipt_payment_method" id="advanceReceiptPaymentMethod" class="form-select form-select-sm" required>
                <option value="">เลือกวิธีรับเงิน</option>
                <option value="cash" <?= $paymentMethod === 'cash' ? 'selected' : '' ?>>เงินสด</option>
                <option value="transfer" <?= $paymentMethod === 'transfer' ? 'selected' : '' ?>>เงินโอน</option>
            </select>
        </div>
        <div class="col-12 d-none" id="advanceReceiptSlipWrap">
            <label class="form-label fw-semibold small">แนบสลิปโอนเงิน</label>
            <input type="file" name="transfer_slip" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,image/*,application/pdf">
            <div class="form-text small">บังคับแนบเมื่อเลือกวิธีรับเงินเป็น "เงินโอน"</div>
            <?php if ($slipUrl !== ''): ?>
            <div class="mt-2">
                <a href="<?= htmlspecialchars($slipUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-paperclip me-1"></i>ดูสลิปแนบล่าสุด
                </a>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-12 d-flex gap-2 flex-wrap mt-2">
            <button type="submit" class="btn btn-success btn-sm px-3">
                <i class="bi bi-check-circle me-1"></i>บันทึกใบเสร็จรับเงิน
            </button>
            <button type="button" class="btn btn-light border btn-sm px-3 btn-advance-back-detail">ยกเลิก</button>
        </div>
    </form>

    <hr class="my-3">
    <div class="border rounded p-3 bg-light small">
        <h6 class="fw-bold mb-2 small">ตัวอย่างส่วนลงนามในใบเสร็จรับเงิน</h6>
        <div class="row g-2">
            <div class="col-md-6">
                ผู้รับเงิน: <span class="fw-semibold"><?= htmlspecialchars((string) ($row['receipt_receiver_name'] ?? $requesterName), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="col-md-6 text-md-end">
                วันที่รับเงิน: <span class="fw-semibold"><?= htmlspecialchars((string) ($row['receipt_date'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="col-12 pt-3 text-muted">
                ลายเซ็นผู้รับเงิน ...........................................................
            </div>
        </div>
    </div>
</div>
    <?php
    $receiptHtml = ob_get_clean();
    echo json_encode(
        [
            'ok' => true,
            'requestNumber' => $reqNo,
            'receiptHtml' => $receiptHtml,
        ],
        JSON_UNESCAPED_UNICODE
    );
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
if ($status === 'pending') {
    $badgeClass = 'bg-warning text-dark';
} elseif ($status === 'approved') {
    $badgeClass = 'bg-success';
} elseif ($status === 'rejected') {
    $badgeClass = 'bg-danger';
}

$csrfQ = '&_csrf=' . rawurlencode(csrf_token());
$actionBase = htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8');
$rid = (int) ($row['id'] ?? 0);

ob_start();
?>
<div class="advance-detail-modal-inner">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="text-muted small">เลขที่คำขอ</div>
            <div class="fw-bold fs-5 text-primary"><?= htmlspecialchars((string) ($row['request_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <span class="badge <?= $badgeClass ?> rounded-pill px-3 py-2"><?= strtoupper(htmlspecialchars($status, ENT_QUOTES, 'UTF-8')) ?></span>
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
            <div class="p-3 bg-light rounded small"><?= nl2br(htmlspecialchars((string) ($row['purpose'] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div>
        </div>
        <?php if (trim((string) ($row['decision_note'] ?? '')) !== ''): ?>
        <div class="col-12">
            <div class="text-muted small">หมายเหตุการอนุมัติ/ปฏิเสธ</div>
            <div class="p-3 bg-light rounded small"><?= nl2br(htmlspecialchars((string) ($row['decision_note'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
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

    <div class="mt-4 d-flex gap-2 flex-wrap">
        <?php if ($status === 'pending'): ?>
        <a href="<?= $actionBase ?>?action=approve_advance_cash_request&id=<?= $rid ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-success btn-sm px-3" onclick="return confirm('ยืนยันอนุมัติคำขอนี้ ?')">
            <i class="bi bi-check-circle me-1"></i> อนุมัติ
        </a>
        <a href="<?= $actionBase ?>?action=reject_advance_cash_request&id=<?= $rid ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-danger btn-sm px-3" onclick="return confirm('ยืนยันปฏิเสธคำขอนี้ ?')">
            <i class="bi bi-x-circle me-1"></i> ปฏิเสธ
        </a>
        <?php endif; ?>
        <?php if ($status === 'approved'): ?>
        <button type="button" class="btn btn-primary btn-sm px-3 btn-advance-open-receipt" data-advance-id="<?= $rid ?>">
            <i class="bi bi-receipt me-1"></i> ออกใบเสร็จรับเงิน
        </button>
        <?php endif; ?>
        <?php if ((string) ($row['receipt_status'] ?? 'none') === 'issued'): ?>
        <a href="<?= htmlspecialchars(app_path('pages/advance-cash/advance-cash-receipt-print.php') . '?id=' . $rid, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark btn-sm px-3" target="_blank" rel="noopener">
            <i class="bi bi-printer me-1"></i> พิมพ์ใบเสร็จรับเงิน
        </a>
        <?php endif; ?>
    </div>
</div>
<?php
$detailHtml = ob_get_clean();

echo json_encode(
    [
        'ok' => true,
        'requestNumber' => (string) ($row['request_number'] ?? ''),
        'detailHtml' => $detailHtml,
    ],
    JSON_UNESCAPED_UNICODE
);
