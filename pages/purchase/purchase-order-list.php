<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_po_payment_slips.php';

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
    $slipItems = tnc_po_payment_slip_items($po);
    $billingStatus = strtolower(trim((string) ($po['billing_status'] ?? 'pending')));
    if (!in_array($billingStatus, ['pending', 'billed'], true)) {
        $billingStatus = 'pending';
    }
    $paymentSlipPath = $slipItems !== [] ? (string) ($slipItems[0]['path'] ?? '') : trim((string) ($po['payment_slip_path'] ?? ''));
    $merged = array_merge($po, [
        'supplier_name' => $s['name'] ?? '',
        'created_by_name' => trim(($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')),
        'status_label' => strtoupper($status),
        'status' => $status,
        'payment_status' => $paymentStatus,
        'payment_slip_path' => $paymentSlipPath,
        'payment_slip_url' => $slipItems !== [] ? (string) ($slipItems[0]['url'] ?? '') : ($paymentSlipPath !== '' ? app_path($paymentSlipPath) : ''),
        'payment_slip_items' => $slipItems,
        'payment_slip_count' => count($slipItems),
        'billing_status' => $billingStatus,
        'supplier_invoice_no' => trim((string) ($po['supplier_invoice_no'] ?? '')),
        'supplier_invoice_date' => trim((string) ($po['supplier_invoice_date'] ?? '')),
        'billed_total_amount' => (float) ($po['billed_total_amount'] ?? $amt),
        'billed_vat_amount' => (float) ($po['billed_vat_amount'] ?? ($po['vat_amount'] ?? 0)),
        'payment_method' => strtolower(trim((string) ($po['payment_method'] ?? 'transfer'))) === 'cash' ? 'cash' : 'transfer',
        'payment_cash_paid_by' => trim((string) ($po['payment_cash_paid_by'] ?? '')),
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
        #poTable th { white-space: nowrap; font-size: .84rem; }
        #poTable td { vertical-align: middle; }
        #poTable .badge { font-size: .72rem; font-weight: 600; letter-spacing: .01em; }
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
                echo 'ไฟล์แนบต้องเป็นรูปภาพหรือ PDF (JPG, PNG, WEBP, GIF, PDF)';
            } elseif ($errorCode === 'upload_failed') {
                echo 'อัปโหลดรูปหลักฐานไม่สำเร็จ กรุณาลองใหม่';
            } elseif ($errorCode === 'payment_slip_required') {
                echo 'ต้องแนบรูปหลักฐานก่อนเปลี่ยนสถานะเป็น จ่ายแล้ว';
            } elseif ($errorCode === 'cash_paid_by_required') {
                echo 'กรุณาระบุ «จ่ายโดย» เมื่อเลือกชำระด้วยเงินสด';
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
            } elseif ($errorCode === 'billing_required') {
                echo 'กรุณากรอกเลขที่บิลซื้อและวันที่บนบิลให้ครบถ้วน';
            } elseif ($errorCode === 'billing_amount_invalid') {
                echo 'ยอดเงินรวมและยอด VAT ต้องไม่เป็นค่าว่างหรือติดลบ';
            } else {
                echo 'เกิดข้อผิดพลาดในการจัดการใบสั่งซื้อ กรุณาลองใหม่';
            }
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['payment_slips_updated'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            อัปเดตไฟล์หลักฐานการจ่ายเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['payment_saved'])): ?>
        <?php
        $autoBill = !empty($_GET['auto_bill']);
        $printPoIdSaved = (int) ($_GET['print_po_id'] ?? 0);
        $billMonthQ = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['bill_month'] ?? '')) ? (string) $_GET['bill_month'] : date('Y-m');
        $billIdQ = (int) ($_GET['bill_id'] ?? 0);
        $billListUrl = htmlspecialchars(app_path('pages/purchase/purchase-bill.php') . '?month=' . rawurlencode($billMonthQ), ENT_QUOTES, 'UTF-8');
        $billEditUrl = $billIdQ > 0
            ? htmlspecialchars(app_path('pages/purchase/purchase-bill.php') . '?month=' . rawurlencode($billMonthQ) . '&edit=' . $billIdQ, ENT_QUOTES, 'UTF-8')
            : $billListUrl;
        $poAutoprintBase = $printPoIdSaved > 0
            ? htmlspecialchars(app_path('pages/purchase/purchase-order-view.php') . '?id=' . $printPoIdSaved, ENT_QUOTES, 'UTF-8')
            : '';
        $poAutoprintPoUrl = $poAutoprintBase !== '' ? $poAutoprintBase . '&print_mode=po&autoprint=1' : '';
        $poAutoprintSlipUrl = $poAutoprintBase !== '' ? $poAutoprintBase . '&print_mode=slip&autoprint=1' : '';
        $poAutoprintBothUrl = $poAutoprintBase !== '' ? $poAutoprintBase . '&print_mode=both&autoprint=1' : '';
        $poAutoprintAllUrl = $poAutoprintBase !== '' ? $poAutoprintBase . '&print_mode=all&autoprint=1' : '';
        ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            บันทึกสถานะการจ่ายเงินเรียบร้อยแล้ว
            <?php if ($printPoIdSaved > 0 && $poAutoprintPoUrl !== ''): ?>
                <div class="mt-2 small">
                    <span class="text-muted">พิมพ์อัตโนมัติ:</span>
                    <a href="<?= $poAutoprintPoUrl ?>" target="_blank" rel="noopener" class="alert-link fw-semibold">1. เฉพาะใบสั่งซื้อ</a>
                    <span class="text-muted">·</span>
                    <a href="<?= $poAutoprintSlipUrl ?>" target="_blank" rel="noopener" class="alert-link fw-semibold">2. เฉพาะสลิป</a>
                    <span class="text-muted">·</span>
                    <a href="<?= $poAutoprintBothUrl ?>" target="_blank" rel="noopener" class="alert-link fw-semibold">3. ใบสั่งซื้อ + สลิป</a>
                    <span class="text-muted">·</span>
                    <a href="<?= $poAutoprintAllUrl ?>" target="_blank" rel="noopener" class="alert-link fw-semibold">4. PR + PO + สลิป/แนบ</a>
                </div>
            <?php endif; ?>
            <?php if ($autoBill): ?>
                <div class="mt-2 small">
                    ระบบบันทึกบิล<strong>บันทึกบิลซื้อตามโครงการ</strong>จาก PO นี้แล้ว
                    — <a href="<?= $billEditUrl ?>" class="alert-link fw-semibold">เปิดบิลที่สร้างอัตโนมัติ</a>
                    หรือ <a href="<?= $billListUrl ?>" class="alert-link">ไปหน้ารายการบิลเดือน <?= htmlspecialchars($billMonthQ, ENT_QUOTES, 'UTF-8') ?></a>
                </div>
            <?php endif; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['billing_saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            บันทึกเลขที่บิลซื้อเรียบร้อยแล้ว และสร้างข้อมูลในตาราง bills แล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h2 class="fw-bold mb-0"><i class="bi bi-file-earmark-check-fill text-primary"></i>รายการใบสั่งซื้อ (Purchase orders List)</h2>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="dropdown">
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li>
                        <a class="dropdown-item" href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php')) ?>">
                            <i class="bi bi-link-45deg me-1"></i> เลือกใบขอซื้อเพื่อสร้าง PO
                        </a>
                    </li>
                </ul>
            </div>
            <button type="button" class="btn btn-outline-dark rounded-pill px-3 shadow-sm no-print" id="poBatchPrintBtn" title="เปิดหน้าพิมพ์หลายใบตามที่ติ๊ก">
                <i class="bi bi-printer me-1"></i>พิมพ์ที่เลือก
            </button>
            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm">
                <i class="bi bi-arrow-left-circle me-1"></i>รายการใบขอซื้อ
            </a>
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
                                <option value="day" <?= $poFilterType === 'day' ? 'selected' : '' ?>>เลือกวันที่</option>
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
            <table class="table table-sm table-hover align-middle" id="poTable">
                <thead class="table-light">
                    <tr>
                        <th class="text-center no-print" style="width:2.5rem;" title="เลือกเพื่อพิมพ์หลายใบ">
                            <input type="checkbox" class="form-check-input m-0" id="poSelectAllPrint" aria-label="เลือกทั้งหมดในหน้านี้">
                        </th>
                        <th>เลขที่ PO</th>
                        <th>วันที่ออก</th>
                        <th>ผู้ขาย / ผู้รับจ้าง</th>
                        <th class="text-end">ยอดเงินรวม</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody id="poTableBody">
                    <?php if (count($po_rows_display) === 0): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted"><?= count($po_rows) === 0 ? 'ยังไม่มีการออกใบสั่งซื้อ' : 'ไม่มีรายการตามเงื่อนไขที่เลือก — ลองเปลี่ยนตัวกรองวันที่' ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($po_rows_display as $row): ?>
                    <?php
                    $poCancelled = ($row['status'] ?? '') === 'cancelled';
                    $hasBillRef = trim((string) ($row['supplier_invoice_no'] ?? '')) !== '';
                    $hasSlip = (int) ($row['payment_slip_count'] ?? 0) > 0;
                    $isDocComplete = $hasSlip && $hasBillRef;
                    ?>
                    <tr<?= $poCancelled ? ' class="po-row-cancelled"' : '' ?>>
                        <td class="text-center align-middle no-print">
                            <input type="checkbox" class="form-check-input m-0 js-po-print-cb" value="<?= (int) ($row['id'] ?? 0) ?>" aria-label="เลือกพิมพ์ <?= htmlspecialchars((string) ($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </td>
                        <td>
                            <div class="fw-bold <?= $poCancelled ? 'text-danger' : ($isDocComplete ? 'text-info' : 'text-warning') ?>">
                                <?php
                                $poNoDisp = htmlspecialchars((string) ($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $poViewHref = htmlspecialchars(app_path('pages/purchase/purchase-order-view.php'), ENT_QUOTES, 'UTF-8') . '?id=' . (int) ($row['id'] ?? 0);
                                $poLinkClass = $poCancelled ? 'text-danger' : ($isDocComplete ? 'text-info' : 'text-warning');
                                echo '<a href="' . $poViewHref . '" class="' . $poLinkClass . ' text-decoration-none" title="ดูรายละเอียด">' . $poNoDisp . '</a>';
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
                        <td class="text-end">
                            <div class="fw-bold <?= $poCancelled ? 'text-danger' : 'text-primary' ?>"><?= number_format((float)$row['total_amount'], 2) ?></div>
                        </td>
                        <td class="text-center">
                            <?php
                            $rowPaid = (($row['payment_status'] ?? 'unpaid') === 'paid');
                            $poCanEditCancel = ($row['status'] ?? '') !== 'cancelled' && !$rowPaid;
                            $poCanAdminDelete = $isAdmin && !$rowPaid;
                            ?>
                            <?php if (($row['status'] ?? '') !== 'cancelled'): ?>
                            <div class="dropdown">
                                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    จัดการ
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                    <?php if (($row['payment_status'] ?? 'unpaid') === 'paid'): ?>
                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item js-show-slip"
                                                data-po-id="<?= (int) ($row['id'] ?? 0) ?>"
                                                data-po-number="<?= htmlspecialchars((string) ($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-slip-url="<?= htmlspecialchars((string) ($row['payment_slip_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-slip-path="<?= htmlspecialchars((string) ($row['payment_slip_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-payment-method="<?= htmlspecialchars((string) ($row['payment_method'] ?? 'transfer'), ENT_QUOTES, 'UTF-8') ?>"
                                                data-cash-paid-by="<?= htmlspecialchars((string) ($row['payment_cash_paid_by'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            ><i class="bi bi-image me-2"></i>ดูสลิปจ่ายเงิน</button>
                                        </li>
                                    <?php else: ?>
                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item js-mark-paid"
                                                data-po-id="<?= (int) ($row['id'] ?? 0) ?>"
                                                data-po-number="<?= htmlspecialchars((string) ($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            ><i class="bi bi-cash-coin me-2"></i>แนปสลิป</button>
                                        </li>
                                    <?php endif; ?>

                                    <?php if (($row['billing_status'] ?? 'pending') !== 'billed'): ?>
                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item js-receive-bill"
                                                data-po-id="<?= (int) ($row['id'] ?? 0) ?>"
                                                data-po-number="<?= htmlspecialchars((string) ($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-po-total="<?= htmlspecialchars(number_format((float) ($row['total_amount'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-po-vat="<?= htmlspecialchars(number_format((float) ($row['vat_amount'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                                            ><i class="bi bi-receipt me-2"></i>เพิ่มเลขบิลซื้อ</button>
                                        </li>
                                    <?php endif; ?>

                                    <?php if ($poCanEditCancel || $poCanAdminDelete): ?><li><hr class="dropdown-divider"></li><?php endif; ?>
                                    <?php if ($poCanEditCancel): ?>
                                        <li><a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-edit.php')) ?>?id=<?= (int)$row['id'] ?>" class="dropdown-item"><i class="bi bi-pencil-square me-2"></i>แก้ไขใบสั่งซื้อ</a></li>
                                        <li>
                                            <form method="post" action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=cancel_purchase_order" class="d-inline" data-tnc-fullnav="1" onsubmit="return confirm('ยืนยันยกเลิกใบสั่งซื้อนี้? สถานะจะเปลี่ยนเป็น ยกเลิก และแสดงประทับบนใบพิมพ์');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="po_id" value="<?= (int) ($row['id'] ?? 0) ?>">
                                                <button type="submit" class="dropdown-item text-danger"><i class="bi bi-x-circle me-2"></i>ยกเลิกใบสั่งซื้อ</button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($poCanAdminDelete): ?>
                                        <li><a href="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=delete&type=purchase_order&id=<?= (int) $row['id'] ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="dropdown-item text-danger tnc-delete-post"><i class="bi bi-trash3-fill me-2"></i>ลบใบสั่งซื้อ</a></li>
                                    <?php endif; ?>
                                </ul>
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

<div class="modal fade" id="receiveBillModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=receive_po_bill" method="POST" id="receiveBillForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="return_to" value="list">
                <input type="hidden" name="po_id" id="receiveBillPoId" value="">
                <div class="modal-header">
                    <h5 class="modal-title">บันทึกเลขที่บิลซื้อ (Receive Bill)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="small text-muted mb-2">PO: <span id="receiveBillPoNumber">-</span></div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">เลขที่ใบกำกับภาษี/บิลซื้อ <span class="text-danger">*</span></label>
                        <input type="text" name="supplier_invoice_no" id="receiveBillInvoiceNo" class="form-control" maxlength="120" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">วันที่บนใบกำกับภาษี/บิลซื้อ <span class="text-danger">*</span></label>
                        <input type="date" name="supplier_invoice_date" id="receiveBillInvoiceDate" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">ยอดเงินรวม (บาท)</label>
                        <input type="number" name="billed_total_amount" id="receiveBillTotalAmount" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-semibold">ยอด VAT 7% (บาท)</label>
                        <input type="number" name="billed_vat_amount" id="receiveBillVatAmount" class="form-control" step="0.01" min="0" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึกบิลซื้อ</button>
                </div>
            </form>
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
                    <div class="mb-3">
                        <label class="form-label fw-semibold d-block">ช่องทางชำระ</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="payment_method" id="payMethodTransfer" value="transfer" checked>
                            <label class="form-check-label" for="payMethodTransfer">โอนเงิน / ช่องทางอื่น <span class="text-muted small">(แนบหลักฐาน)</span></label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="payment_method" id="payMethodCash" value="cash">
                            <label class="form-check-label" for="payMethodCash">เงินสด</label>
                        </div>
                    </div>
                    <div class="mb-3 d-none" id="markPaidCashWrap">
                        <label class="form-label fw-semibold" for="markPaidCashBy">จ่ายโดย <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="payment_cash_paid_by" id="markPaidCashBy" maxlength="255" placeholder="เช่น ชื่อผู้รับเงิน / แผนก">
                        <div class="form-text">บังคับเมื่อเลือกเงินสด — เก็บในฐานข้อมูลพร้อม PO</div>
                    </div>
                    <label class="form-label fw-semibold" id="markPaidFileLabel">ไฟล์หลักฐาน <span class="text-danger" id="markPaidFileReq">*</span></label>
                    <input type="file" name="payment_slips[]" id="markPaidFile" class="form-control" accept="image/*,.pdf" multiple required>
                    <div class="form-text">เลือกได้หลายไฟล์ (รูปหรือ PDF) — เมื่อบันทึกแล้ว ระบบจะเปลี่ยนสถานะเป็น «จ่ายแล้ว» และสร้างบันทึกบิลโครงการอัตโนมัติ (ถ้าเป็น PO จัดซื้อ)</div>
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
                <div id="showSlipPayMeta" class="text-start small text-muted mb-2 d-none"></div>
                <img id="showSlipImage" src="" alt="Payment slip" class="img-fluid rounded border" style="max-height:55vh; object-fit:contain;">
                <div id="showSlipPdfHint" class="text-muted py-4 d-none">
                    <i class="bi bi-file-earmark-pdf fs-1 text-danger d-block mb-2"></i>
                    ไฟล์นี้เป็น PDF — ใช้ปุ่ม «เปิดไฟล์เต็ม» เพื่อดู หรืออัปโหลดไฟล์ใหม่ด้านล่าง
                </div>
                <div id="showSlipNoImage" class="text-muted py-4 d-none">ไม่พบไฟล์หลักฐานการจ่ายเงิน</div>
                <form
                    action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=replace_po_payment_slip"
                    method="POST"
                    enctype="multipart/form-data"
                    id="replaceSlipForm"
                    class="border-top pt-3 mt-3 text-start"
                    onsubmit="return confirm('ยืนยันเปลี่ยนไฟล์หลักฐานการจ่าย? ไฟล์เดิมจะถูกแทนที่');"
                >
                    <?php csrf_field(); ?>
                    <input type="hidden" name="po_id" id="replaceSlipPoId" value="">
                    <input type="hidden" name="slip_path" id="replaceSlipPath" value="">
                    <label class="form-label fw-semibold mb-1" for="replaceSlipFile">
                        <i class="bi bi-arrow-repeat me-1"></i>เปลี่ยนรูป / ไฟล์หลักฐาน
                    </label>
                    <input type="file" name="payment_slip" id="replaceSlipFile" class="form-control form-control-sm mb-2" accept="image/*,.pdf" required>
                    <div class="form-text mb-2">เลือกรูปหรือ PDF ใหม่เพื่อแทนที่ไฟล์ปัจจุบัน</div>
                    <button type="submit" class="btn btn-warning btn-sm rounded-pill px-3">
                        <i class="bi bi-upload me-1"></i>บันทึกรูปใหม่
                    </button>
                </form>
            </div>
            <div class="modal-footer">
                <a id="showSlipOpenLink" href="#" target="_blank" rel="noopener" class="btn btn-outline-primary d-none">เปิดไฟล์เต็ม</a>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="poBatchPrintChoiceModal" tabindex="-1" aria-labelledby="poBatchPrintChoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="poBatchPrintChoiceModalLabel">พิมพ์ใบสั่งซื้อที่เลือก</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body pt-2">
                <p class="small text-muted mb-3">เลือกว่าจะพิมพ์เฉพาะใบสั่งซื้อ เฉพาะสลิป รวม PO+สลิป/แนบ หรือชุดครบ PR+PO+สลิป/แนบ (สลิปมีเฉพาะรายการที่จ่ายแล้วและแนบไฟล์ — โหมด 4 จะข้าม PR เมื่อ PO ไม่มี PR)</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-secondary rounded-pill py-2 text-start" id="poBatchPrintPoOnlyBtn">
                        <span class="fw-semibold d-block">1. เฉพาะใบสั่งซื้อ</span>
                        <span class="small text-muted">ไม่รวมสลิปและไฟล์แนบ</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary rounded-pill py-2 text-start" id="poBatchPrintSlipOnlyBtn">
                        <span class="fw-semibold d-block">2. เฉพาะสลิป</span>
                        <span class="small text-muted">หลักฐานการจ่ายเท่านั้น (แต่ละใบที่มีไฟล์)</span>
                    </button>
                    <button type="button" class="btn btn-success rounded-pill py-2 text-start" id="poBatchPrintWithAttachBtn">
                        <span class="fw-semibold d-block">3. ใบสั่งซื้อ + สลิป</span>
                        <span class="small" style="opacity:0.95">รวมใบ PO สลิป และใบเสนอราคาแนบเมื่อมี</span>
                    </button>
                    <button type="button" class="btn btn-outline-primary rounded-pill py-2 text-start" id="poBatchPrintAllBtn">
                        <span class="fw-semibold d-block">4. พิมพ์ทุกอย่าง (PR + PO + สลิป/แนบ)</span>
                        <span class="small text-muted">แต่ละใบ: ใบขอซื้อ (ถ้ามี) แล้วตามด้วย PO สลิป และแนบ QT ตามที่มี</span>
                    </button>
                </div>
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
            order: [[2, 'desc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'ทั้งหมด']],
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
            columnDefs: [{ targets: [0, 5], orderable: false, searchable: false }]
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
    var batchBase = <?= json_encode(app_path('pages/purchase/purchase-batch-print.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var pendingBatchIds = '';
    var batchModalEl = document.getElementById('poBatchPrintChoiceModal');
    var batchModal = batchModalEl && typeof bootstrap !== 'undefined' ? new bootstrap.Modal(batchModalEl) : null;

    function openBatchPrint(mode) {
        var m = mode === 'po' || mode === 'slip' || mode === 'both' || mode === 'all' ? mode : 'both';
        window.open(batchBase + '?kind=po&ids=' + encodeURIComponent(pendingBatchIds) + '&print_mode=' + encodeURIComponent(m), '_blank', 'noopener');
        pendingBatchIds = '';
        if (batchModal) {
            batchModal.hide();
        }
    }

    document.getElementById('poBatchPrintBtn')?.addEventListener('click', function () {
        var ids = [];
        document.querySelectorAll('.js-po-print-cb:checked').forEach(function (cb) {
            var v = parseInt(cb.value, 10);
            if (v > 0) ids.push(v);
        });
        if (ids.length === 0) {
            alert('กรุณาติ๊กเลือกใบสั่งซื้อ (PO) อย่างน้อย 1 ใบ');
            return;
        }
        pendingBatchIds = ids.join(',');
        if (batchModal) {
            batchModal.show();
        } else {
            openBatchPrint('both');
        }
    });
    document.getElementById('poBatchPrintPoOnlyBtn')?.addEventListener('click', function () {
        openBatchPrint('po');
    });
    document.getElementById('poBatchPrintSlipOnlyBtn')?.addEventListener('click', function () {
        openBatchPrint('slip');
    });
    document.getElementById('poBatchPrintWithAttachBtn')?.addEventListener('click', function () {
        openBatchPrint('both');
    });
    document.getElementById('poBatchPrintAllBtn')?.addEventListener('click', function () {
        openBatchPrint('all');
    });
    document.getElementById('poSelectAllPrint')?.addEventListener('change', function () {
        var on = this.checked;
        document.querySelectorAll('#poTable tbody .js-po-print-cb').forEach(function (cb) {
            cb.checked = on;
        });
    });
})();

(function () {
    const receiveBillModalEl = document.getElementById('receiveBillModal');
    if (receiveBillModalEl) {
        const receiveBillModal = new bootstrap.Modal(receiveBillModalEl);
        const poIdInput = document.getElementById('receiveBillPoId');
        const poNoEl = document.getElementById('receiveBillPoNumber');
        const totalEl = document.getElementById('receiveBillTotalAmount');
        const vatEl = document.getElementById('receiveBillVatAmount');
        const invNoEl = document.getElementById('receiveBillInvoiceNo');
        const invDateEl = document.getElementById('receiveBillInvoiceDate');
        const formEl = document.getElementById('receiveBillForm');

        document.querySelectorAll('.js-receive-bill').forEach((btn) => {
            btn.addEventListener('click', () => {
                poIdInput.value = btn.getAttribute('data-po-id') || '';
                poNoEl.textContent = btn.getAttribute('data-po-number') || '-';
                totalEl.value = btn.getAttribute('data-po-total') || '0.00';
                vatEl.value = btn.getAttribute('data-po-vat') || '0.00';
                invNoEl.value = '';
                invDateEl.value = '';
                receiveBillModal.show();
            });
        });

        formEl?.addEventListener('submit', function (e) {
            const totalVal = parseFloat(String(totalEl.value || ''));
            const vatVal = parseFloat(String(vatEl.value || ''));
            if (!Number.isFinite(totalVal) || !Number.isFinite(vatVal) || totalVal < 0 || vatVal < 0) {
                e.preventDefault();
                alert('ยอดเงินรวมและยอด VAT ต้องไม่เป็นค่าว่างหรือติดลบ');
            }
        });
    }
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
    const markPaidFileReq = document.getElementById('markPaidFileReq');
    const showSlipPoNumber = document.getElementById('showSlipPoNumber');
    const showSlipImage = document.getElementById('showSlipImage');
    const showSlipNoImage = document.getElementById('showSlipNoImage');
    const showSlipOpenLink = document.getElementById('showSlipOpenLink');
    const showSlipPayMeta = document.getElementById('showSlipPayMeta');
    const showSlipPdfHint = document.getElementById('showSlipPdfHint');
    const replaceSlipPoId = document.getElementById('replaceSlipPoId');
    const replaceSlipPath = document.getElementById('replaceSlipPath');
    const replaceSlipFile = document.getElementById('replaceSlipFile');
    const payMethodTransfer = document.getElementById('payMethodTransfer');
    const payMethodCash = document.getElementById('payMethodCash');
    const markPaidCashWrap = document.getElementById('markPaidCashWrap');
    const markPaidCashBy = document.getElementById('markPaidCashBy');
    const markPaidForm = document.getElementById('markPaidForm');

    function tncEscHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function syncMarkPaidCashUi() {
        if (!markPaidCashWrap || !markPaidCashBy || !payMethodCash) return;
        const isCash = payMethodCash.checked;
        markPaidCashWrap.classList.toggle('d-none', !isCash);
        markPaidCashBy.required = isCash;
        if (markPaidFile) {
            markPaidFile.required = !isCash;
        }
        if (markPaidFileReq) {
            markPaidFileReq.classList.toggle('d-none', isCash);
        }
        if (!isCash) {
            markPaidCashBy.value = '';
        }
    }
    payMethodTransfer?.addEventListener('change', syncMarkPaidCashUi);
    payMethodCash?.addEventListener('change', syncMarkPaidCashUi);
    syncMarkPaidCashUi();

    markPaidForm?.addEventListener('submit', (e) => {
        if (payMethodCash && payMethodCash.checked) {
            const v = (markPaidCashBy?.value || '').trim();
            if (!v) {
                e.preventDefault();
                alert('กรุณากรอก «จ่ายโดย» เมื่อเลือกชำระด้วยเงินสด');
                markPaidCashBy?.focus();
                return;
            }
        } else if (markPaidFile && (!markPaidFile.files || markPaidFile.files.length === 0)) {
            e.preventDefault();
            alert('กรุณาแนบไฟล์หลักฐานอย่างน้อย 1 ไฟล์');
        }
    });

    document.querySelectorAll('.js-mark-paid').forEach((btn) => {
        btn.addEventListener('click', () => {
            poIdInput.value = btn.getAttribute('data-po-id') || '';
            poNumberLabel.textContent = btn.getAttribute('data-po-number') || '-';
            if (markPaidFile) markPaidFile.value = '';
            if (payMethodTransfer) payMethodTransfer.checked = true;
            if (payMethodCash) payMethodCash.checked = false;
            if (markPaidCashBy) markPaidCashBy.value = '';
            syncMarkPaidCashUi();
            markPaidModal.show();
        });
    });

    document.querySelectorAll('.js-show-slip').forEach((btn) => {
        btn.addEventListener('click', () => {
            const poId = btn.getAttribute('data-po-id') || '';
            const poNumber = btn.getAttribute('data-po-number') || '-';
            const slipUrl = btn.getAttribute('data-slip-url') || '';
            const slipPath = btn.getAttribute('data-slip-path') || '';
            const pm = (btn.getAttribute('data-payment-method') || 'transfer').toLowerCase();
            const paidBy = btn.getAttribute('data-cash-paid-by') || '';
            const isPdf = /\.pdf(\?|$)/i.test(slipUrl);
            showSlipPoNumber.textContent = poNumber;
            if (replaceSlipPoId) replaceSlipPoId.value = poId;
            if (replaceSlipPath) replaceSlipPath.value = slipPath;
            if (replaceSlipFile) replaceSlipFile.value = '';
            if (showSlipPayMeta) {
                if (pm === 'cash') {
                    showSlipPayMeta.classList.remove('d-none');
                    const extra = paidBy.trim() !== '' ? (' · <strong>จ่ายโดย:</strong> ' + tncEscHtml(paidBy)) : '';
                    showSlipPayMeta.innerHTML = '<strong>ชำระ:</strong> เงินสด' + extra;
                } else {
                    showSlipPayMeta.classList.remove('d-none');
                    showSlipPayMeta.innerHTML = '<strong>ชำระ:</strong> โอน/ช่องทางอื่น';
                }
            }
            if (slipUrl !== '') {
                showSlipNoImage.classList.add('d-none');
                showSlipOpenLink.href = slipUrl;
                showSlipOpenLink.classList.remove('d-none');
                if (isPdf) {
                    showSlipImage.src = '';
                    showSlipImage.classList.add('d-none');
                    showSlipPdfHint?.classList.remove('d-none');
                } else {
                    showSlipImage.src = slipUrl;
                    showSlipImage.classList.remove('d-none');
                    showSlipPdfHint?.classList.add('d-none');
                }
            } else {
                showSlipImage.src = '';
                showSlipImage.classList.add('d-none');
                showSlipPdfHint?.classList.add('d-none');
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