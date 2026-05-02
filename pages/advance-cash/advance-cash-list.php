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
$isFinanceRole = user_is_finance_role();
if (!$isFinanceRole) {
    header('Location: ' . app_path('index.php'));
    exit;
}
$showAll = isset($_GET['scope']) && (string) $_GET['scope'] === 'all';
$csrfQ = '&_csrf=' . rawurlencode(csrf_token());
$openAdvanceId = isset($_GET['open_id']) ? (int) $_GET['open_id'] : 0;
$openAdvanceReceipt = !empty($_GET['open_receipt']);

$users = Db::tableKeyed('users');
$rows = $showAll
    ? Db::tableRows('advance_cash_requests')
    : Db::filter('advance_cash_requests', static function (array $r) use ($me): bool {
        return (int) ($r['requested_by'] ?? 0) === $me;
    });
Db::sortRows($rows, 'created_at', true);

$fetchDetailUrl = app_path('pages/advance-cash/advance-cash-detail-fetch.php');
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
        #advanceDetailModal .modal-body { max-height: min(85vh, 800px); overflow-y: auto; }
    </style>
</head>
<body class="page-advance-list">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['success'])): ?><div class="alert alert-success">บันทึกคำขอเรียบร้อยแล้ว</div><?php endif; ?>
    <?php if (!empty($_GET['line_error'])): ?><div class="alert alert-warning">บันทึกคำขอแล้ว แต่ส่งแจ้งเตือน LINE ไม่สำเร็จ</div><?php endif; ?>
    <?php if (!empty($_GET['approved'])): ?><div class="alert alert-success">อนุมัติคำขอเรียบร้อยแล้ว</div><?php endif; ?>
    <?php if (!empty($_GET['rejected'])): ?><div class="alert alert-warning">ปฏิเสธคำขอเรียบร้อยแล้ว</div><?php endif; ?>
    <?php if (!empty($_GET['deleted'])): ?><div class="alert alert-success">ลบคำขอเรียบร้อยแล้ว</div><?php endif; ?>
    <?php if (!empty($_GET['receipt_saved'])): ?><div class="alert alert-success">บันทึกใบเสร็จรับเงินเรียบร้อยแล้ว</div><?php endif; ?>
    <?php if (!empty($_GET['error']) && (string) $_GET['error'] === 'receipt_requires_approved'): ?>
        <div class="alert alert-warning">ออกใบเสร็จได้เฉพาะรายการที่อนุมัติแล้ว</div>
    <?php endif; ?>
    <?php if (!empty($_GET['error']) && (string) $_GET['error'] === 'receipt_not_issued'): ?>
        <div class="alert alert-warning">ยังไม่มีใบเสร็จที่ออกสำหรับรายการนี้</div>
    <?php endif; ?>
    <?php if (empty($_GET['open_receipt'])): ?>
    <?php if (!empty($_GET['error']) && (string) $_GET['error'] === 'invalid_input'): ?>
        <div class="alert alert-danger">กรุณากรอกข้อมูลใบเสร็จให้ครบ</div>
    <?php endif; ?>
    <?php if (!empty($_GET['error']) && (string) $_GET['error'] === 'slip_required'): ?>
        <div class="alert alert-warning">กรณีโอนเงิน ต้องแนบสลิปโอนเงิน</div>
    <?php endif; ?>
    <?php if (!empty($_GET['error']) && (string) $_GET['error'] === 'upload_type'): ?>
        <div class="alert alert-warning">ไฟล์สลิปไม่ถูกต้อง (รองรับ jpg/png/webp/gif/pdf)</div>
    <?php endif; ?>
    <?php if (!empty($_GET['error']) && (string) $_GET['error'] === 'upload_failed'): ?>
        <div class="alert alert-danger">อัปโหลดสลิปไม่สำเร็จ</div>
    <?php endif; ?>
    <?php endif; ?>

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
                        $rowId = (int) ($row['id'] ?? 0);
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
                                <button type="button" class="btn btn-sm btn-outline-primary btn-advance-detail" data-advance-id="<?= $rowId ?>" title="ดูรายละเอียด">
                                    <i class="bi bi-eye-fill"></i>
                                </button>
                                <?php if ($isFinanceRole): ?>
                                    <a href="<?= htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8') ?>?action=delete_advance_cash_request&id=<?= $rowId ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('ยืนยันการลบคำขอนี้ ?');">
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

<div class="modal fade" id="advanceDetailModal" tabindex="-1" aria-labelledby="advanceDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="advanceDetailModalLabel">รายละเอียดคำขอเบิกเงินล่วงหน้า</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <div id="advanceDetailLoading" class="text-center text-muted py-5 d-none">กำลังโหลด…</div>
                <div id="advanceDetailError" class="alert alert-danger d-none"></div>
                <div id="advanceDetailInner"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const fetchBase = <?= json_encode($fetchDetailUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;
    const modalEl = document.getElementById('advanceDetailModal');
    const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
    const titleEl = document.getElementById('advanceDetailModalLabel');
    const loadingEl = document.getElementById('advanceDetailLoading');
    const errEl = document.getElementById('advanceDetailError');
    const innerEl = document.getElementById('advanceDetailInner');

    const receiptErrMessages = {
        invalid_input: 'กรุณากรอกข้อมูลใบเสร็จให้ครบ',
        slip_required: 'กรณีโอนเงิน ต้องแนบสลิปโอนเงิน',
        upload_type: 'ไฟล์สลิปไม่ถูกต้อง (รองรับ jpg/png/webp/gif/pdf)',
        upload_failed: 'อัปโหลดสลิปไม่สำเร็จ',
    };

    let currentAdvanceId = null;

    function setLoading(on) {
        if (loadingEl) loadingEl.classList.toggle('d-none', !on);
    }
    function showError(msg) {
        if (!errEl) return;
        errEl.textContent = msg;
        errEl.classList.remove('d-none');
    }
    function clearError() {
        if (!errEl) return;
        errEl.classList.add('d-none');
        errEl.textContent = '';
    }

    function syncAdvanceReceiptSlip() {
        const methodEl = document.getElementById('advanceReceiptPaymentMethod');
        const slipWrap = document.getElementById('advanceReceiptSlipWrap');
        if (!methodEl || !slipWrap) return;
        slipWrap.classList.toggle('d-none', methodEl.value !== 'transfer');
    }

    function stripModalQueryParams() {
        try {
            const u = new URL(window.location.href);
            u.searchParams.delete('open_id');
            u.searchParams.delete('open_receipt');
            u.searchParams.delete('error');
            window.history.replaceState({}, '', u.pathname + u.search + u.hash);
        } catch (e) { /* ignore */ }
    }

    async function loadDetail(id) {
        if (!innerEl || !modal) return;
        currentAdvanceId = parseInt(String(id), 10) || 0;
        clearError();
        innerEl.innerHTML = '';
        setLoading(true);
        let data;
        try {
            const res = await fetch(fetchBase + '?id=' + encodeURIComponent(String(id)), { credentials: 'same-origin' });
            data = await res.json();
        } catch (e) {
            setLoading(false);
            showError('โหลดข้อมูลไม่สำเร็จ');
            return;
        }
        setLoading(false);
        if (!data || !data.ok) {
            showError(data && data.error === 'not_found' ? 'ไม่พบรายการ' : 'โหลดข้อมูลไม่สำเร็จ');
            return;
        }
        if (titleEl) titleEl.textContent = 'รายละเอียด — ' + (data.requestNumber || '');
        innerEl.innerHTML = data.detailHtml || '';
    }

    async function loadReceiptForm(id, flashErr) {
        if (!innerEl || !modal) return;
        currentAdvanceId = parseInt(String(id), 10) || 0;
        clearError();
        innerEl.innerHTML = '';
        setLoading(true);
        let data;
        try {
            const res = await fetch(fetchBase + '?id=' + encodeURIComponent(String(id)) + '&part=receipt', { credentials: 'same-origin' });
            data = await res.json();
        } catch (e) {
            setLoading(false);
            showError('โหลดฟอร์มไม่สำเร็จ');
            return;
        }
        setLoading(false);
        if (!data || !data.ok) {
            if (data && data.error === 'not_approved') {
                showError('ออกใบเสร็จได้เฉพาะรายการที่อนุมัติแล้ว');
            } else {
                showError('โหลดฟอร์มไม่สำเร็จ');
            }
            return;
        }
        if (titleEl) titleEl.textContent = 'ออกใบเสร็จรับเงิน — ' + (data.requestNumber || '');
        innerEl.innerHTML = data.receiptHtml || '';
        if (flashErr && receiptErrMessages[flashErr]) {
            innerEl.insertAdjacentHTML('afterbegin', '<div class="alert alert-warning py-2 small mb-3">' + receiptErrMessages[flashErr] + '</div>');
        }
        syncAdvanceReceiptSlip();
    }

    if (modalEl) {
        modalEl.addEventListener('change', function (e) {
            if (e.target && e.target.id === 'advanceReceiptPaymentMethod') {
                syncAdvanceReceiptSlip();
            }
        });
        modalEl.addEventListener('click', function (e) {
            const openBtn = e.target.closest('.btn-advance-open-receipt');
            if (openBtn) {
                e.preventDefault();
                const rid = openBtn.getAttribute('data-advance-id');
                if (rid) loadReceiptForm(rid, '');
                return;
            }
            if (e.target.closest('.btn-advance-back-detail')) {
                e.preventDefault();
                if (currentAdvanceId) loadDetail(currentAdvanceId);
            }
        });
    }

    document.querySelectorAll('.btn-advance-detail').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-advance-id');
            if (!id || !modal) return;
            modal.show();
            loadDetail(id);
        });
    });

    const openId = <?= (int) $openAdvanceId ?>;
    const openReceipt = <?= $openAdvanceReceipt ? 'true' : 'false' ?>;
    const flashReceiptErr = <?= json_encode(isset($_GET['error']) ? (string) $_GET['error'] : '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

    if (openId > 0 && modal) {
        modal.show();
        if (openReceipt) {
            loadReceiptForm(openId, flashReceiptErr);
        } else {
            loadDetail(openId);
        }
        stripModalQueryParams();
    }
})();
</script>
</body>
</html>
