<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$isAdmin = user_is_admin_role();
$csrfQ = '&_csrf=' . rawurlencode(csrf_token());

$suppliers = Db::tableKeyed('suppliers');
$users = Db::tableKeyed('users');
$po_rows = [];
$totalAmount = 0.0;
foreach (Db::tableRows('purchase_orders') as $po) {
    $s = $suppliers[(string) ($po['supplier_id'] ?? '')] ?? null;
    $u = $users[(string) ($po['created_by'] ?? '')] ?? null;
    $status = strtolower(trim((string) ($po['status'] ?? 'ordered')));
    if ($status === '') {
        $status = 'ordered';
    }
    $amt = (float) ($po['total_amount'] ?? 0);
    $totalAmount += $amt;
    $paymentStatus = strtolower(trim((string) ($po['payment_status'] ?? 'unpaid')));
    if (!in_array($paymentStatus, ['paid', 'unpaid'], true)) {
        $paymentStatus = 'unpaid';
    }
    $paymentSlipPath = trim((string) ($po['payment_slip_path'] ?? ''));
    $po_rows[] = array_merge($po, [
        'supplier_name' => $s['name'] ?? '',
        'created_by_name' => trim(($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')),
        'status_label' => strtoupper($status),
        'status' => $status,
        'payment_status' => $paymentStatus,
        'payment_slip_path' => $paymentSlipPath,
        'payment_slip_url' => $paymentSlipPath !== '' ? app_path($paymentSlipPath) : '',
        'total_amount' => $amt,
        'order_type' => trim((string) ($po['order_type'] ?? 'purchase')),
        'installment_no' => (int) ($po['installment_no'] ?? 0),
        'installment_total' => (int) ($po['installment_total'] ?? 0),
    ]);
}
usort($po_rows, static function (array $a, array $b): int {
    return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
});
$poCount = count($po_rows);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> <title>รายการใบสั่งซื้อ (PO List)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .main-card { border: none; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            สร้างใบสั่งซื้อ (PO) สำเร็จแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['updated'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            แก้ไขใบสั่งซื้อ (PO) เรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ลบใบสั่งซื้อเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php
            $errorCode = trim((string) ($_GET['error'] ?? ''));
            if ($errorCode === 'upload_type') {
                echo 'ไฟล์แนบต้องเป็นรูปภาพเท่านั้น (JPG, JPEG, PNG, WEBP, GIF)';
            } elseif ($errorCode === 'upload_failed') {
                echo 'อัปโหลดรูปหลักฐานไม่สำเร็จ กรุณาลองใหม่';
            } elseif ($errorCode === 'payment_slip_required') {
                echo 'ต้องแนบรูปหลักฐานก่อนเปลี่ยนสถานะเป็น จ่ายแล้ว';
            } elseif ($errorCode === 'invalid') {
                echo 'ไม่พบใบสั่งซื้อ หรือข้อมูลไม่ถูกต้อง';
            } else {
                echo 'เกิดข้อผิดพลาดในการจัดการใบสั่งซื้อ กรุณาลองใหม่';
            }
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['payment_saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            บันทึกสถานะการจ่ายเงินเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold"><i class="bi bi-file-earmark-check-fill text-primary"></i> รายการใบสั่งซื้อ (PO)</h2>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-create.php')) ?>" class="btn btn-primary rounded-pill px-4">
                <i class="bi bi-plus-lg"></i> สร้าง PO โดยตรง
            </a>
            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php')) ?>" class="btn btn-outline-primary rounded-pill px-4">
                <i class="bi bi-link-45deg"></i> สร้างจาก PR
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card p-3 border-0 shadow-sm h-100">
                <div class="text-muted small">จำนวนใบสั่งซื้อทั้งหมด</div>
                <div class="fs-4 fw-bold"><?= number_format($poCount) ?> รายการ</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card p-3 border-0 shadow-sm h-100">
                <div class="text-muted small">ยอดรวมทั้งหมด</div>
                <div class="fs-4 fw-bold text-primary"><?= number_format($totalAmount, 2) ?> บาท</div>
            </div>
        </div>
    </div>

    <div class="card main-card p-4">
        <div class="d-flex justify-content-end mb-3">
            <div style="max-width: 420px; width: 100%;">
                <input id="poSearch" type="search" class="form-control" placeholder="ค้นหา">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="poTable">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่ PO</th>
                        <th>วันที่ออก</th>
                        <th>ผู้ขาย / ผู้รับจ้าง</th>
                        <th class="text-center">ประเภท</th>
                        <th>ผู้ออกใบ</th>
                        <th class="text-end">ยอดเงินรวม</th>
                        <th class="text-center">สถานะ</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody id="poTableBody">
                    <?php if (count($po_rows) === 0): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">ยังไม่มีการออกใบสั่งซื้อ</td></tr>
                    <?php else: ?>
                        <?php foreach ($po_rows as $row): ?>
                    <tr>
                        <td class="fw-bold text-primary"><?= htmlspecialchars((string) ($row['po_number'] ?? '')) ?></td>
                        <td>
                            <?php
                            $createdAt = trim((string) ($row['created_at'] ?? ''));
                            echo $createdAt !== '' ? htmlspecialchars(date('d/m/Y', strtotime($createdAt)), ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>';
                            ?>
                        </td>
                        <td>
                            <?php
                            $orderTypeCell = in_array((string) ($row['order_type'] ?? ''), ['purchase', 'hire'], true) ? (string) $row['order_type'] : 'purchase';
                            $supplierDisplay = $orderTypeCell === 'hire'
                                ? trim((string) ($row['contractor_name'] ?? ''))
                                : trim((string) ($row['supplier_name'] ?? ''));
                            echo htmlspecialchars($supplierDisplay !== '' ? $supplierDisplay : '-', ENT_QUOTES, 'UTF-8');
                            ?>
                        </td>
                        <td class="text-center small">
                            <?php $orderType = $orderTypeCell; ?>
                            <?php if ($orderType === 'hire'): ?>
                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">จัดจ้าง</span>
                                <?php if ((int) ($row['installment_no'] ?? 0) > 0 && (int) ($row['installment_total'] ?? 0) > 0): ?>
                                    <div class="small text-muted mt-1">งวด <?= (int) $row['installment_no'] ?>/<?= (int) $row['installment_total'] ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-light text-secondary border">จัดซื้อ</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?php $cb = trim((string)($row['created_by_name'] ?? '')); echo $cb !== '' ? htmlspecialchars($cb) : '<span class="text-muted">—</span>'; ?></td>
                        <td class="text-end">
                            <div class="fw-bold text-primary"><?= number_format((float)$row['total_amount'], 2) ?></div>
                            <?php if ((int)($row['vat_enabled'] ?? 0) === 1): ?>
                                <span class="badge bg-success rounded-pill mt-1" style="font-size:0.7rem;">รวม VAT 7%</span>
                            <?php else: ?>
                                <span class="badge bg-light text-secondary border mt-1" style="font-size:0.7rem;">ไม่มี VAT</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if (($row['status'] ?? '') === 'cancelled'): ?>
                                <span class="badge bg-danger rounded-pill">CANCELLED</span>
                            <?php elseif (($row['status'] ?? '') !== 'ordered'): ?>
                                <span class="badge bg-secondary rounded-pill"><?= htmlspecialchars((string) ($row['status_label'] ?? 'UNKNOWN')) ?></span>
                            <?php endif; ?>
                            <div class="mt-1">
                                <?php if (($row['payment_status'] ?? 'unpaid') === 'paid'): ?>
                                    <button
                                        type="button"
                                        class="btn btn-success btn-sm rounded-pill py-0 px-3 mt-1 js-show-slip"
                                        data-po-number="<?= htmlspecialchars((string) ($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-slip-url="<?= htmlspecialchars((string) ($row['payment_slip_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                    >จ่ายแล้ว</button>
                                <?php else: ?>
                                    <button
                                        type="button"
                                        class="btn btn-warning btn-sm rounded-pill py-0 px-3 mt-1 js-mark-paid"
                                        data-po-id="<?= (int) ($row['id'] ?? 0) ?>"
                                        data-po-number="<?= htmlspecialchars((string) ($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                    >ยังไม่จ่าย</button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-center">
                            <div class="btn-group shadow-sm">
                                <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-view.php')) ?>?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary" title="ดูรายละเอียด">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-edit.php')) ?>?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-warning" title="แก้ไขใบสั่งซื้อ">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                                <?php if ($isAdmin): ?>
                                    <a href="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=delete&type=purchase_order&id=<?= (int) $row['id'] ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-danger" title="ลบใบสั่งซื้อ" onclick="return confirm('ยืนยันการลบใบสั่งซื้อ <?= htmlspecialchars((string) ($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?> ?');">
                                        <i class="bi bi-trash3-fill"></i>
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

<div class="modal fade" id="markPaidModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=update_po_payment_status" method="POST" enctype="multipart/form-data" id="markPaidForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="return_to" value="list">
                <input type="hidden" name="po_id" id="markPaidPoId" value="">
                <input type="hidden" name="payment_status" value="paid">
                <div class="modal-header">
                    <h5 class="modal-title">แนบหลักฐานการจ่ายเงิน</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2 small text-muted">PO: <span id="markPaidPoNumber">-</span></div>
                    <label class="form-label fw-semibold">ไฟล์รูปหลักฐาน <span class="text-danger">*</span></label>
                    <input type="file" name="payment_slip" id="markPaidFile" class="form-control" accept="image/*" required>
                    <div class="form-text">เมื่อแนบไฟล์แล้ว ระบบจะเปลี่ยนสถานะเป็น "จ่ายแล้ว" อัตโนมัติ</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="showSlipModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">หลักฐานการจ่ายเงิน: <span id="showSlipPoNumber">-</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <img id="showSlipImage" src="" alt="Payment slip" class="img-fluid rounded border" style="max-height:70vh; object-fit:contain;">
                <div id="showSlipNoImage" class="text-muted py-4 d-none">ไม่พบไฟล์หลักฐานการจ่ายเงิน</div>
            </div>
            <div class="modal-footer">
                <a id="showSlipOpenLink" href="#" target="_blank" rel="noopener" class="btn btn-outline-primary d-none">เปิดไฟล์เต็ม</a>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    const input = document.getElementById('poSearch');
    const tbody = document.getElementById('poTableBody');
    if (!input || !tbody) return;

    const noDataRow = () => {
        const tr = document.createElement('tr');
        tr.id = 'poNoResult';
            tr.innerHTML = "<td colspan=\"8\" class=\"text-center py-4 text-muted\">ไม่พบรายการที่ค้นหา</td>";
        return tr;
    };

    input.addEventListener('input', function () {
        const q = (input.value || '').trim().toLowerCase();
        const rows = Array.from(tbody.querySelectorAll('tr'));
        let visible = 0;
        rows.forEach((row) => {
            if (row.id === 'poNoResult') return;
            const txt = (row.textContent || '').toLowerCase();
            const show = q === '' || txt.includes(q);
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        const oldNoResult = document.getElementById('poNoResult');
        if (oldNoResult) oldNoResult.remove();
        if (q !== '' && visible === 0) {
            tbody.appendChild(noDataRow());
        }
    });
})();

(function () {
    const markPaidModalEl = document.getElementById('markPaidModal');
    const showSlipModalEl = document.getElementById('showSlipModal');
    if (!markPaidModalEl || !showSlipModalEl) return;

    const markPaidModal = new bootstrap.Modal(markPaidModalEl);
    const showSlipModal = new bootstrap.Modal(showSlipModalEl);
    const poIdInput = document.getElementById('markPaidPoId');
    const poNumberLabel = document.getElementById('markPaidPoNumber');
    const markPaidFile = document.getElementById('markPaidFile');
    const showSlipPoNumber = document.getElementById('showSlipPoNumber');
    const showSlipImage = document.getElementById('showSlipImage');
    const showSlipNoImage = document.getElementById('showSlipNoImage');
    const showSlipOpenLink = document.getElementById('showSlipOpenLink');

    document.querySelectorAll('.js-mark-paid').forEach((btn) => {
        btn.addEventListener('click', () => {
            poIdInput.value = btn.getAttribute('data-po-id') || '';
            poNumberLabel.textContent = btn.getAttribute('data-po-number') || '-';
            if (markPaidFile) {
                markPaidFile.value = '';
            }
            markPaidModal.show();
        });
    });

    document.querySelectorAll('.js-show-slip').forEach((btn) => {
        btn.addEventListener('click', () => {
            const poNumber = btn.getAttribute('data-po-number') || '-';
            const slipUrl = btn.getAttribute('data-slip-url') || '';
            showSlipPoNumber.textContent = poNumber;
            if (slipUrl !== '') {
                showSlipImage.src = slipUrl;
                showSlipImage.classList.remove('d-none');
                showSlipNoImage.classList.add('d-none');
                showSlipOpenLink.href = slipUrl;
                showSlipOpenLink.classList.remove('d-none');
            } else {
                showSlipImage.src = '';
                showSlipImage.classList.add('d-none');
                showSlipNoImage.classList.remove('d-none');
                showSlipOpenLink.href = '#';
                showSlipOpenLink.classList.add('d-none');
            }
            showSlipModal.show();
        });
    });
})();
</script>

</body>
</html>