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

$poFilterType = trim((string) ($_GET['po_filter'] ?? 'all'));
if (!in_array($poFilterType, ['all', 'day', 'month'], true)) {
    $poFilterType = 'all';
}
$poFilterDay = trim((string) ($_GET['po_date'] ?? ''));
$poFilterMonth = trim((string) ($_GET['po_month'] ?? ''));
if ($poFilterType === 'day' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $poFilterDay) !== 1) {
    $poFilterType = 'all';
    $poFilterDay = '';
}
if ($poFilterType === 'month' && preg_match('/^\d{4}-\d{2}$/', $poFilterMonth) !== 1) {
    $poFilterType = 'all';
    $poFilterMonth = '';
}
if ($poFilterType === 'day' && $poFilterDay === '') {
    $poFilterType = 'all';
}
if ($poFilterType === 'month' && $poFilterMonth === '') {
    $poFilterType = 'all';
}

/** วันที่ใช้เรียง/แสดง: issue_date ก่อน แล้ว fallback created_at → Y-m-d หรือว่าง */
$poListSortYmd = static function (array $row): string {
    $issue = trim((string) ($row['issue_date'] ?? ''));
    if ($issue !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) {
        return $issue;
    }
    if ($issue !== '') {
        $ts = strtotime($issue);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }
    $created = trim((string) ($row['created_at'] ?? ''));
    if ($created !== '') {
        $ts = strtotime($created);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }
    return '';
};

$po_rows = [];
foreach (Db::tableRows('purchase_orders') as $po) {
    $s = $suppliers[(string) ($po['supplier_id'] ?? '')] ?? null;
    $u = $users[(string) ($po['created_by'] ?? '')] ?? null;
    $status = strtolower(trim((string) ($po['status'] ?? 'ordered')));
    if ($status === '') {
        $status = 'ordered';
    }
    $amt = (float) ($po['total_amount'] ?? 0);
    $paymentStatus = strtolower(trim((string) ($po['payment_status'] ?? 'unpaid')));
    if (!in_array($paymentStatus, ['paid', 'unpaid'], true)) {
        $paymentStatus = 'unpaid';
    }
    $paymentSlipPath = trim((string) ($po['payment_slip_path'] ?? ''));
    $merged = array_merge($po, [
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
    $merged['_list_sort_ymd'] = $poListSortYmd($merged);
    $po_rows[] = $merged;
}
usort($po_rows, static function (array $a, array $b): int {
    $da = (string) ($a['_list_sort_ymd'] ?? '');
    $db = (string) ($b['_list_sort_ymd'] ?? '');
    $daKey = $da !== '' ? $da : '0000-00-00';
    $dbKey = $db !== '' ? $db : '0000-00-00';
    $cmp = strcmp($dbKey, $daKey);
    if ($cmp !== 0) {
        return $cmp;
    }
    return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
});

$po_rows_display = $po_rows;
if ($poFilterType === 'day' && $poFilterDay !== '') {
    $po_rows_display = array_values(array_filter(
        $po_rows,
        static function (array $r) use ($poFilterDay): bool {
            return (string) ($r['_list_sort_ymd'] ?? '') === $poFilterDay;
        }
    ));
} elseif ($poFilterType === 'month' && $poFilterMonth !== '') {
    $po_rows_display = array_values(array_filter(
        $po_rows,
        static function (array $r) use ($poFilterMonth): bool {
            $ymd = (string) ($r['_list_sort_ymd'] ?? '');

            return strlen($ymd) >= 7 && strncmp($ymd, $poFilterMonth, 7) === 0;
        }
    ));
}

$poCount = count($po_rows_display);
$totalAmount = 0.0;
foreach ($po_rows_display as $sumRow) {
    if (($sumRow['status'] ?? '') === 'cancelled') {
        continue;
    }
    if (($sumRow['payment_status'] ?? 'unpaid') !== 'paid') {
        continue;
    }
    $totalAmount += (float) ($sumRow['total_amount'] ?? 0);
}
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
        <?php $createdPoNo = trim((string) ($_GET['po_number'] ?? '')); ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            สร้างใบสั่งซื้อ (PO) สำเร็จแล้ว<?php if ($createdPoNo !== ''): ?> — เลขที่ <strong><?= htmlspecialchars($createdPoNo, ENT_QUOTES, 'UTF-8') ?></strong><?php endif; ?>
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
    <?php if (!empty($_GET['cancelled'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ยกเลิกใบสั่งซื้อ (PO) เรียบร้อยแล้ว
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
            } elseif ($errorCode === 'not_found') {
                echo 'ไม่พบใบสั่งซื้อ';
            } elseif ($errorCode === 'po_cancelled') {
                echo 'ใบสั่งซื้อนี้ถูกยกเลิกแล้ว ไม่สามารถดำเนินการนี้ได้';
            } elseif ($errorCode === 'already_cancelled') {
                echo 'ใบสั่งซื้อนี้ยกเลิกไปแล้ว';
            } elseif ($errorCode === 'po_paid') {
                echo 'ใบสั่งซื้อนี้สถานะการจ่ายเป็น «จ่ายแล้ว» ไม่สามารถแก้ไข ยกเลิก หรือลบได้';
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

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h2 class="fw-bold mb-0"><i class="bi bi-file-earmark-check-fill text-primary"></i>รายการใบสั่งซื้อ (Purchase orders List)</h2>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm">
                <i class="bi bi-arrow-left-circle me-1"></i>รายการใบขอซื้อ (PR)
            </a>
            <div class="dropdown">
                <button class="btn btn-primary rounded-pill px-4 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-plus-lg"></i> สร้างเอกสาร
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li>
                        <a class="dropdown-item" href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-create.php')) ?>">
                            <i class="bi bi-file-earmark-plus me-1"></i> สร้าง PO โดยตรง
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php')) ?>">
                            <i class="bi bi-link-45deg me-1"></i> สร้างจาก PR
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
            <div class="row g-3 align-items-end flex-lg-nowrap">
                <div class="col-12 col-lg min-w-0">
                    <form method="get" action="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="row g-2 gx-2 align-items-end" id="poFilterForm">
                        <div class="col-auto">
                            <label class="form-label small text-muted mb-1 text-nowrap">ค้นหาตามช่วงวันที่</label>
                            <select name="po_filter" id="poFilterType" class="form-select form-select-sm" style="min-width: 7.5rem;" aria-label="ประเภทตัวกรอง">
                                <option value="all" <?= $poFilterType === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
                                <option value="day" <?= $poFilterType === 'day' ? 'selected' : '' ?>>รายวัน</option>
                                <option value="month" <?= $poFilterType === 'month' ? 'selected' : '' ?>>รายเดือน</option>
                            </select>
                        </div>
                        <div class="col-auto po-filter-day-wrap" style="<?= $poFilterType === 'day' ? '' : 'display:none;' ?>">
                            <label class="form-label small text-muted mb-1 text-nowrap">เลือกวัน</label>
                            <input type="date" name="po_date" class="form-control form-control-sm" value="<?= htmlspecialchars($poFilterDay, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-auto po-filter-month-wrap" style="<?= $poFilterType === 'month' ? '' : 'display:none;' ?>">
                            <label class="form-label small text-muted mb-1 text-nowrap">เลือกเดือน</label>
                            <input type="month" name="po_month" class="form-control form-control-sm" value="<?= htmlspecialchars($poFilterMonth, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-auto d-flex flex-wrap gap-2 pt-2 pt-sm-0 align-items-end">
                            <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3"><i class="bi bi-search me-1"></i>ใช้ตัวกรอง</button>
                            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">ล้าง</a>
                        </div>
                    </form>
                    <?php if ($poFilterType === 'day' && $poFilterDay !== ''): ?>
                        <div class="small text-muted mt-2 mb-0">กำลังแสดงเฉพาะ PO วันที่ออก <?= htmlspecialchars(date('d/m/Y', strtotime($poFilterDay)), ENT_QUOTES, 'UTF-8') ?></div>
                    <?php elseif ($poFilterType === 'month' && $poFilterMonth !== ''): ?>
                        <?php
                        $mTs = strtotime($poFilterMonth . '-01');
                        $monthLabel = $mTs !== false ? date('m/Y', $mTs) : $poFilterMonth;
                        ?>
                        <div class="small text-muted mt-2 mb-0">กำลังแสดงเฉพาะ PO เดือน <?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-sm-6 col-lg-auto flex-shrink-0" style="min-width: 11rem;">
                    <div class="rounded-3 border bg-light px-3 py-2 h-100">
                        <div class="text-muted small text-nowrap text-truncate" title="จำนวนรายการที่แสดง">จำนวนที่แสดง<?= $poFilterType !== 'all' ? '*' : '' ?></div>
                        <div class="fs-5 fw-bold mb-0"><?= number_format($poCount) ?> <span class="fs-6 fw-normal text-secondary">รายการ</span></div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-lg-auto flex-shrink-0" style="min-width: 12rem;">
                    <div class="rounded-3 border bg-light px-3 py-2 h-100">
                        <div class="text-muted small text-nowrap text-truncate" title="ยอดรวมที่จ่ายแล้ว (เฉพาะจ่ายแล้ว ไม่ยกเลิก)">ยอดจ่ายแล้ว<?= $poFilterType !== 'all' ? '*' : '' ?></div>
                        <div class="fs-5 fw-bold text-primary mb-0"><?= number_format($totalAmount, 2) ?> <span class="fs-6 fw-normal text-secondary">บาท</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card main-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="poTable">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่ PO</th>
                        <th>วันที่ออก</th>
                        <th>ผู้ขาย / ผู้รับจ้าง</th>
                        <th class="text-center">ประเภท</th>
                        <th class="text-end">ยอดเงินรวม</th>
                        <th class="text-center">สถานะ</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody id="poTableBody">
                    <?php if (count($po_rows_display) === 0): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted"><?= count($po_rows) === 0 ? 'ยังไม่มีการออกใบสั่งซื้อ' : 'ไม่มีรายการตามเงื่อนไขที่เลือก — ลองเปลี่ยนตัวกรองวันที่' ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($po_rows_display as $row): ?>
                    <tr>
                        <td>
                            <div class="fw-bold text-primary">
                                <?php
                                $poNoDisp = htmlspecialchars((string) ($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $poViewHref = htmlspecialchars(app_path('pages/purchase/purchase-order-view.php'), ENT_QUOTES, 'UTF-8') . '?id=' . (int) ($row['id'] ?? 0);
                                echo '<a href="' . $poViewHref . '" class="text-primary text-decoration-none" title="ดูรายละเอียด">' . $poNoDisp . '</a>';
                                ?>
                            </div>
                            <div class="small text-muted"><?php $cb = trim((string)($row['created_by_name'] ?? '')); echo $cb !== '' ? htmlspecialchars($cb) : '—'; ?></div>
                        </td>
                        <?php
                        $ymd = trim((string) ($row['_list_sort_ymd'] ?? ''));
                        $dateOrderAttr = $ymd !== '' ? $ymd : '0000-00-00';
                        ?>
                        <td data-order="<?= htmlspecialchars($dateOrderAttr, ENT_QUOTES, 'UTF-8') ?>">
                            <?php
                            echo $ymd !== '' ? htmlspecialchars(date('d/m/Y', strtotime($ymd)), ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>';
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
                            <?php if (($row['status'] ?? '') !== 'cancelled'): ?>
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
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php
                            $rowPaid = (($row['payment_status'] ?? 'unpaid') === 'paid');
                            $poCanEditCancel = ($row['status'] ?? '') !== 'cancelled' && !$rowPaid;
                            $poCanAdminDelete = $isAdmin && !$rowPaid;
                            ?>
                            <?php if ($poCanEditCancel || $poCanAdminDelete): ?>
                            <div class="btn-group shadow-sm">
                                <?php if ($poCanEditCancel): ?>
                                <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-edit.php')) ?>?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-warning" title="แก้ไขใบสั่งซื้อ">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                                <form method="post" action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=cancel_purchase_order" class="d-inline" data-tnc-fullnav="1" onsubmit="return confirm('ยืนยันยกเลิกใบสั่งซื้อนี้? สถานะจะเปลี่ยนเป็น ยกเลิก และแสดงประทับบนใบพิมพ์');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="po_id" value="<?= (int) ($row['id'] ?? 0) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="ยกเลิกใบ PO"><i class="bi bi-x-circle"></i></button>
                                </form>
                                <?php endif; ?>
                                <?php if ($poCanAdminDelete): ?>
                                    <a href="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=delete&type=purchase_order&id=<?= (int) $row['id'] ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-danger tnc-delete-post" title="ลบใบสั่งซื้อ (ต้องใส่รหัสผ่าน)">
                                        <i class="bi bi-trash3-fill"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
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

<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function ($) {
    if ($('#poTable tbody tr').length && $('#poTable tbody tr td[colspan]').length === 0) {
        $('#poTable').DataTable({
            order: [[1, 'desc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'ทั้งหมด']],
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
            columnDefs: [{ targets: [6], orderable: false, searchable: false }]
        });
    }
    var u = <?= json_encode(app_path('actions/live-datasets.php?dataset=mirror_table&table=purchase_orders'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var c = '';
    setInterval(function () {
        if (document.hidden) return;
        fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.ok) return;
            if (c === '') { c = d.checksum; return; }
            if (d.checksum !== c) window.location.reload();
        }).catch(function () {});
    }, 6000);
})(jQuery);

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

(function () {
    const sel = document.getElementById('poFilterType');
    const dayWrap = document.querySelector('.po-filter-day-wrap');
    const monthWrap = document.querySelector('.po-filter-month-wrap');
    if (!sel || !dayWrap || !monthWrap) return;
    const sync = () => {
        const v = sel.value;
        dayWrap.style.display = v === 'day' ? '' : 'none';
        monthWrap.style.display = v === 'month' ? '' : 'none';
    };
    sel.addEventListener('change', sync);
})();
</script>

</body>
</html>