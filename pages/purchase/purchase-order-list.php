<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_po_payment_slips.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_table_skeleton.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$poCanDelete = user_can('po.delete');
$csrfQ = '&_csrf=' . rawurlencode(csrf_token());

$suppliers = Db::tableKeyed('suppliers');
$users = Db::tableKeyed('users');

$siteNameById = [];
foreach (Db::tableRows('sites') as $site) {
    $sid = (int) ($site['id'] ?? 0);
    if ($sid > 0) {
        $siteNameById[$sid] = trim((string) ($site['name'] ?? ''));
    }
}
$prById = [];
foreach (Db::tableRows('purchase_requests') as $pr) {
    $pid = (int) ($pr['id'] ?? 0);
    if ($pid > 0) {
        $prById[$pid] = $pr;
    }
}
$resolvePoSiteName = static function (array $po) use ($siteNameById, $prById): string {
    $siteId = (int) ($po['site_id'] ?? 0);
    $siteName = trim((string) ($po['site_name'] ?? ''));
    if ($siteId <= 0) {
        $prId = (int) ($po['pr_id'] ?? 0);
        if ($prId > 0 && isset($prById[$prId])) {
            $pr = $prById[$prId];
            $siteId = (int) ($pr['site_id'] ?? 0);
            if ($siteName === '') {
                $siteName = trim((string) ($pr['site_name'] ?? ''));
            }
        }
    }
    if ($siteId > 0 && isset($siteNameById[$siteId]) && $siteNameById[$siteId] !== '') {
        $siteName = $siteNameById[$siteId];
    }

    return $siteName;
};

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
        'incomplete_ignored' => (int) ($po['incomplete_ignored'] ?? 0) === 1,
        'site_display' => $resolvePoSiteName($po),
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

// ---- PO ไม่สมบูรณ์: ยังไม่ชำระ หรือ ยังไม่มีเลขที่ใบกำกับ (ไม่นับใบที่ยกเลิก) ----
$poMissingReasons = static function (array $r): array {
    if (($r['status'] ?? '') === 'cancelled') {
        return [];
    }
    $out = [];
    if (($r['payment_status'] ?? 'unpaid') !== 'paid') {
        $out[] = 'ขาดการชำระ';
    }
    if (trim((string) ($r['supplier_invoice_no'] ?? '')) === '') {
        $out[] = 'ขาดเลขที่ใบกำกับ';
    }
    return $out;
};
$poIncompleteItemLabel = static function (array $ip): string {
    $id = (int) ($ip['id'] ?? 0);
    $no = trim((string) ($ip['po_number'] ?? ''));
    $noRaw = $no !== '' ? $no : ('#' . $id);
    $ymd = trim((string) ($ip['issue_date_ymd'] ?? ''));
    $datePart = $ymd !== '' ? date('d/m/Y', strtotime($ymd)) : '—';
    $amountPart = number_format((float) ($ip['total_amount'] ?? 0), 2);

    return $noRaw . ' · ' . $datePart . ' · ' . $amountPart;
};
$incompletePoList = [];
$ignoredPoList = [];
foreach ($po_rows as $r) {
    $reasons = $poMissingReasons($r);
    if ($reasons === []) {
        continue;
    }
    $entry = [
        'id' => (int) ($r['id'] ?? 0),
        'po_number' => (string) ($r['po_number'] ?? ''),
        'issue_date_ymd' => (string) ($r['_list_sort_ymd'] ?? ''),
        'total_amount' => (float) ($r['total_amount'] ?? 0),
        'vat_amount' => (float) ($r['vat_amount'] ?? 0),
        'supplier' => trim((string) (($r['order_type'] ?? '') === 'hire' ? ($r['contractor_name'] ?? '') : ($r['supplier_name'] ?? ''))),
        'reasons' => $reasons,
        'need_payment' => in_array('ขาดการชำระ', $reasons, true),
        'need_invoice' => in_array('ขาดเลขที่ใบกำกับ', $reasons, true),
    ];
    if (!empty($r['incomplete_ignored'])) {
        $ignoredPoList[] = $entry;
    } else {
        $incompletePoList[] = $entry;
    }
}
$incompleteCountAll = count($incompletePoList);
$ignoredCountAll = count($ignoredPoList);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> <title>รายการใบสั่งซื้อ (PO List)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/tnc-app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/purchase-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .po-list-title { font-size: clamp(1.35rem, 2.5vw, 1.65rem); letter-spacing: -0.02em; }
        .po-list-title__icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.35rem;
            height: 2.35rem;
            border-radius: 0.625rem;
            background: rgba(253, 126, 20, 0.12);
            color: var(--tnc-orange);
            font-size: 1.1rem;
            vertical-align: -0.15em;
        }
        .main-card {
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: var(--tnc-radius);
            box-shadow: 0 0.28rem 0.9rem rgba(0, 0, 0, 0.045);
            background: #fff;
        }
        #poTable thead th {
            white-space: nowrap;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--tnc-muted);
            padding: 0.85rem 0.75rem;
            border-bottom-width: 1px;
        }
        #poTable tbody td { vertical-align: middle; padding: 0.85rem 0.75rem; }
        #poTable tbody tr { transition: background-color 0.16s ease; }
        #poTable tbody tr:hover { background: #fff9f2; }
        #poTable .badge { font-size: .72rem; font-weight: 600; letter-spacing: .01em; }
        #poTable .po-amount { font-variant-numeric: tabular-nums; color: var(--tnc-ink); }
        #poTable .po-site-col { max-width: 14rem; }
        #poTable .po-site-name {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .po-incomplete-box {
            cursor: pointer;
            border: 1px solid rgba(245, 158, 11, 0.35);
            background: linear-gradient(180deg, rgba(255, 247, 237, 0.95) 0%, #fff 100%);
            border-radius: var(--tnc-radius);
            transition: transform 0.16s ease, box-shadow 0.2s ease, border-color 0.16s ease;
        }
        .po-incomplete-box:hover {
            transform: translateY(-1px);
            box-shadow: 0 0.5rem 1.25rem rgba(245, 158, 11, 0.14);
            border-color: rgba(245, 158, 11, 0.5);
        }
        .po-incomplete-box:focus-visible {
            outline: 2px solid rgba(253, 126, 20, 0.55);
            outline-offset: 2px;
        }
        .po-incomplete-icon { width: 42px; height: 42px; border-radius: 50%; background: #fff3cd; color: #d97706; font-size: 1.3rem; }
        #incompletePoModal .ipo-item { border: 1px solid var(--tnc-border); border-radius: 0.65rem; }
        #incompletePoModal .ipo-item + .ipo-item { margin-top: .6rem; }
        .po-empty-state { padding: 2.5rem 1rem; }
        .po-empty-state i { font-size: 2rem; color: var(--tnc-muted); opacity: 0.65; }
        .dropdown-menu .dropdown-item { font-size: 0.92rem; }
        .dropdown-menu .dropdown-item:focus-visible { outline: 2px solid rgba(253, 126, 20, 0.45); outline-offset: -2px; }
        @media (prefers-reduced-motion: reduce) {
            .po-incomplete-box:hover { transform: none; }
            #poTable tbody tr { transition: none; }
        }
    </style>
</head>
<body class="purchase-module tnc-app-body tnc-po-boot-lock" data-tnc-boot-title="กำลังโหลดรายการ PO…" data-tnc-boot-sub="กรุณารอสักครู่ ระบบจะพร้อมให้แนบสลิปและบันทึกเลขบิลเมื่อโหลดเสร็จ">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['success'])): ?>
        <?php $createdPoNo = trim((string) ($_GET['po_number'] ?? '')); ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" data-tnc-audio="create">
            สร้างใบสั่งซื้อ (PO) สำเร็จแล้ว<?php if ($createdPoNo !== ''): ?> — เลขที่ <strong><?= htmlspecialchars($createdPoNo, ENT_QUOTES, 'UTF-8') ?></strong><?php endif; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['updated'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" data-tnc-audio="update">
            แก้ไขใบสั่งซื้อ (PO) เรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" data-tnc-audio="delete">
            ลบใบสั่งซื้อเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['cancelled'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" data-tnc-audio="delete">
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
        <div class="alert alert-success alert-dismissible fade show" role="alert" data-tnc-audio="update">
            อัปเดตไฟล์หลักฐานการจ่ายเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['payment_reverted'])): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            ไม่เหลือหลักฐานการจ่าย (โอนเงิน) ระบบจึงคืนสถานะใบสั่งซื้อนี้เป็น <strong>ยังไม่จ่าย</strong> โดยอัตโนมัติ
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['payment_saved'])): ?>
        <?php
        $printPoIdSaved = (int) ($_GET['print_po_id'] ?? 0);
        $poAutoprintBase = $printPoIdSaved > 0
            ? htmlspecialchars(app_path('pages/purchase/purchase-order-view.php') . '?id=' . $printPoIdSaved, ENT_QUOTES, 'UTF-8')
            : '';
        $poAutoprintPoUrl = $poAutoprintBase !== '' ? $poAutoprintBase . '&print_mode=po&autoprint=1' : '';
        $poAutoprintSlipUrl = $poAutoprintBase !== '' ? $poAutoprintBase . '&print_mode=slip&autoprint=1' : '';
        $poAutoprintBothUrl = $poAutoprintBase !== '' ? $poAutoprintBase . '&print_mode=both&autoprint=1' : '';
        $poAutoprintAllUrl = $poAutoprintBase !== '' ? $poAutoprintBase . '&print_mode=all&autoprint=1' : '';
        ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" data-tnc-audio="complete">
            บันทึกสถานะการจ่ายเงินเรียบร้อยแล้ว
            <?php if ($printPoIdSaved > 0 && $poAutoprintPoUrl !== ''): ?>
                <div class="mt-2 small">
                    <span class="text-muted">พิมพ์อัตโนมัติ:</span>
                    <a href="<?= $poAutoprintPoUrl ?>" class="alert-link fw-semibold">1. เฉพาะใบสั่งซื้อ</a>
                    <span class="text-muted">·</span>
                    <a href="<?= $poAutoprintSlipUrl ?>" class="alert-link fw-semibold">2. เฉพาะสลิป</a>
                    <span class="text-muted">·</span>
                    <a href="<?= $poAutoprintBothUrl ?>" class="alert-link fw-semibold">3. ใบสั่งซื้อ + สลิป</a>
                    <span class="text-muted">·</span>
                    <a href="<?= $poAutoprintAllUrl ?>" class="alert-link fw-semibold">4. PR + PO + สลิป/แนบ</a>
                </div>
            <?php endif; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['billing_saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" data-tnc-audio="complete">
            บันทึกเลขที่บิลซื้อเรียบร้อยแล้ว และสร้างข้อมูลในตาราง bills แล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['po_ignored'])): ?>
        <div class="alert alert-secondary alert-dismissible fade show" role="alert">
            ปัดทิ้งใบสั่งซื้อเรียบร้อยแล้ว — จะไม่ถูกนับในกล่อง «ใบสั่งซื้อที่ไม่สมบูรณ์» อีก (คืนค่าได้จากในกล่อง)
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['po_unignored'])): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            คืนค่าใบสั่งซื้อกลับมานับใหม่เรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="purchase-page-head mb-4">
        <div>
            <p class="purchase-page-kicker">Purchase Module</p>
            <h1 class="purchase-list-title po-list-title mb-0">
                <span class="po-list-title__icon me-2" aria-hidden="true"><i class="bi bi-file-earmark-check-fill"></i></span>
                รายการใบสั่งซื้อ (PO)
            </h1>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-outline-dark rounded-pill px-3 shadow-sm no-print" id="poBatchPrintBtn" title="เปิดหน้าพิมพ์หลายใบตามที่ติ๊ก">
                <i class="bi bi-printer me-1"></i>พิมพ์ที่เลือก
            </button>
            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm">
                <i class="bi bi-arrow-left-circle me-1"></i>รายการใบขอซื้อ
            </a>
        </div>
    </div>

    <?php if ($incompleteCountAll > 0): ?>
        <div class="po-incomplete-box mb-4" role="button" tabindex="0"
             data-bs-toggle="modal" data-bs-target="#incompletePoModal"
             title="กดเพื่อดูรายการใบสั่งซื้อที่ยังไม่สมบูรณ์">
            <div class="d-flex align-items-center gap-3 py-3 px-3">
                <span class="po-incomplete-icon d-inline-flex align-items-center justify-content-center flex-shrink-0">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </span>
                <div class="flex-grow-1">
                    <div class="fw-bold text-warning-emphasis">ใบสั่งซื้อที่ไม่สมบูรณ์ <span class="js-incomplete-count"><?= number_format($incompleteCountAll) ?></span> รายการ</div>
                </div>
                <span class="badge rounded-pill bg-warning text-dark fs-6 px-3 py-2 flex-shrink-0 js-incomplete-count"><?= number_format($incompleteCountAll) ?></span>
                <i class="bi bi-chevron-right fs-5 text-secondary flex-shrink-0"></i>
            </div>
        </div>
    <?php elseif ($ignoredCountAll > 0): ?>
        <div class="text-end mb-3">
            <button type="button" class="btn btn-sm btn-link text-muted text-decoration-none" data-bs-toggle="modal" data-bs-target="#incompletePoModal">
                <i class="bi bi-eye-slash me-1"></i>มีใบสั่งซื้อที่ปัดทิ้งไว้ <?= number_format($ignoredCountAll) ?> ใบ — ดู/คืนค่า
            </button>
        </div>
    <?php endif; ?>

    <div class="card main-card p-4">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle" id="poTable"<?= count($po_rows) > 0 ? ' aria-busy="true"' : '' ?>>
                <thead class="table-light">
                    <tr>
                        <th class="text-center no-print" style="width:2.5rem;" title="เลือกเพื่อพิมพ์หลายใบ">
                            <input type="checkbox" class="form-check-input m-0" id="poSelectAllPrint" aria-label="เลือกทั้งหมดในหน้านี้">
                        </th>
                        <th>เลขที่ PO</th>
                        <th>ไซต์งาน</th>
                        <th>ผู้ขาย / ผู้รับจ้าง</th>
                        <th class="text-end">ยอดเงินรวม</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody id="poTableBody"<?= count($po_rows) > 0 ? ' class="tnc-table-is-loading"' : '' ?>>
                    <?php if (count($po_rows) === 0): ?>
                        <tr><td colspan="6" class="po-empty-state text-center text-muted">
                            <i class="bi bi-inbox d-block mb-2" aria-hidden="true"></i>
                            <div class="fw-semibold text-dark">ยังไม่มีใบสั่งซื้อ</div>
                            <div class="small mt-1">สร้าง PO จาก<a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="text-tnc-orange">รายการใบขอซื้อ</a></div>
                        </td></tr>
                    <?php else: ?>
                        <?= tnc_purchase_table_skeleton_tr(6, 'po') ?>
                        <?php foreach ($po_rows as $row): ?>
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
                        <?php
                        $ymd = trim((string) ($row['_list_sort_ymd'] ?? ''));
                        $dateOrderAttr = $ymd !== '' ? $ymd : '0000-00-00';
                        ?>
                        <td data-order="<?= htmlspecialchars($dateOrderAttr, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="fw-bold <?= $poCancelled ? 'text-danger' : ($isDocComplete ? 'text-info' : 'text-warning') ?>">
                                <?php
                                $poNoDisp = htmlspecialchars((string) ($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $poViewHref = htmlspecialchars(app_path('pages/purchase/purchase-order-view.php'), ENT_QUOTES, 'UTF-8') . '?id=' . (int) ($row['id'] ?? 0);
                                $poLinkClass = $poCancelled ? 'text-danger' : ($isDocComplete ? 'text-info' : 'text-warning');
                                echo '<a href="' . $poViewHref . '" class="' . $poLinkClass . ' text-decoration-none" title="ดูรายละเอียด">' . $poNoDisp . '</a>';
                                ?>
                            </div>
                            <div class="small text-muted"><?= $ymd !== '' ? htmlspecialchars(date('d/m/Y', strtotime($ymd)), ENT_QUOTES, 'UTF-8') : '—' ?></div>
                        </td>
                        <td class="po-site-col small">
                            <?php
                            $siteDisp = trim((string) ($row['site_display'] ?? ''));
                            if ($siteDisp !== ''): ?>
                                <span class="po-site-name" title="<?= htmlspecialchars($siteDisp, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($siteDisp, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
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
                            <div class="fw-bold po-amount <?= $poCancelled ? 'text-danger' : '' ?>"><?= number_format((float)$row['total_amount'], 2) ?></div>
                        </td>
                        <td class="text-center">
                            <?php
                            $rowPaid = (($row['payment_status'] ?? 'unpaid') === 'paid');
                            $poCanEditCancel = ($row['status'] ?? '') !== 'cancelled' && !$rowPaid;
                            $poCanAdminDelete = $poCanDelete && !$rowPaid;
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
                                                data-po-issue-date="<?= htmlspecialchars((string) ($row['_list_sort_ymd'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
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

<div class="modal fade" id="incompletePoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>ใบสั่งซื้อที่ไม่สมบูรณ์
                    <span class="badge rounded-pill bg-warning text-dark ms-1 js-incomplete-count"><?= number_format($incompleteCountAll) ?></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">รายการด้านล่างยังขาดข้อมูล — กดปุ่มเพื่อแนบสลิปหรือกรอกเลขที่ใบกำกับ เมื่อครบทั้งสองอย่างใบสั่งซื้อจะถือว่าสมบูรณ์</p>
                <div id="incompleteActiveList">
                    <div class="text-center text-muted py-4 <?= $incompleteCountAll === 0 ? '' : 'd-none' ?>" id="incompleteEmptyMsg">ไม่มีใบสั่งซื้อที่ไม่สมบูรณ์</div>
                    <?php foreach ($incompletePoList as $ip): ?>
                        <?php
                        $ipId = (int) $ip['id'];
                        $ipLabelHtml = htmlspecialchars($poIncompleteItemLabel($ip), ENT_QUOTES, 'UTF-8');
                        $ipSupplier = htmlspecialchars($ip['supplier'] !== '' ? $ip['supplier'] : '—', ENT_QUOTES, 'UTF-8');
                        $ipViewHref = htmlspecialchars(app_path('pages/purchase/purchase-order-view.php'), ENT_QUOTES, 'UTF-8') . '?id=' . $ipId;
                        ?>
                        <div class="ipo-item p-3 d-flex flex-wrap align-items-center gap-2" data-ipo-id="<?= $ipId ?>">
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-bold">
                                    <a href="<?= $ipViewHref ?>" class="text-decoration-none text-warning-emphasis"><?= $ipLabelHtml ?></a>
                                </div>
                                <div class="small text-muted text-truncate"><?= $ipSupplier ?></div>
                                <div class="mt-1 d-flex flex-wrap gap-1">
                                    <?php foreach ($ip['reasons'] as $rsn): ?>
                                        <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle fw-semibold"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($rsn, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 flex-shrink-0">
                                <?php if ($ip['need_payment']): ?>
                                    <button type="button" class="btn btn-sm btn-warning rounded-pill px-3 js-fix-paid" data-po-id="<?= $ipId ?>" data-po-number="<?= htmlspecialchars((string) ($ip['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="bi bi-cash-coin me-1"></i>แนบสลิป
                                    </button>
                                <?php endif; ?>
                                <?php if ($ip['need_invoice']): ?>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-orange rounded-pill px-3 js-fix-bill"
                                        data-po-id="<?= $ipId ?>"
                                        data-po-number="<?= htmlspecialchars((string) ($ip['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-po-total="<?= htmlspecialchars(number_format((float) ($ip['total_amount'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-po-vat="<?= htmlspecialchars(number_format((float) ($ip['vat_amount'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-po-issue-date="<?= htmlspecialchars((string) ($ip['issue_date_ymd'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                        <i class="bi bi-receipt me-1"></i>กรอกเลขที่ใบกำกับ
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 js-po-ignore" data-po-id="<?= $ipId ?>" title="ไม่สนใจใบนี้ (ปัดทิ้ง)">
                                    <i class="bi bi-eye-slash me-1"></i>ปัดทิ้ง
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($ignoredCountAll > 0): ?>
                    <div class="mt-4 pt-3 border-top" id="ignoredSection">
                        <button type="button" class="btn btn-sm btn-link text-decoration-none px-0" data-bs-toggle="collapse" data-bs-target="#ignoredPoCollapse">
                            <i class="bi bi-eye-slash me-1"></i>ใบสั่งซื้อที่ปัดทิ้งไว้ (<span class="js-ignored-count"><?= number_format($ignoredCountAll) ?></span>) <i class="bi bi-caret-down-fill small"></i>
                        </button>
                        <div class="collapse mt-2" id="ignoredPoCollapse">
                            <?php foreach ($ignoredPoList as $ip): ?>
                                <?php
                                $ipId = (int) $ip['id'];
                                $ipLabelHtml = htmlspecialchars($poIncompleteItemLabel($ip), ENT_QUOTES, 'UTF-8');
                                $ipViewHref = htmlspecialchars(app_path('pages/purchase/purchase-order-view.php'), ENT_QUOTES, 'UTF-8') . '?id=' . $ipId;
                                ?>
                                <div class="ipo-item p-2 px-3 d-flex flex-wrap align-items-center gap-2 bg-light" data-ipo-id="<?= $ipId ?>">
                                    <div class="flex-grow-1 min-w-0">
                                        <a href="<?= $ipViewHref ?>" class="text-decoration-none text-muted fw-semibold"><?= $ipLabelHtml ?></a>
                                        <span class="small text-muted ms-2"><?= htmlspecialchars(implode(' , ', $ip['reasons']), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-orange rounded-pill px-3 js-po-unignore" data-po-id="<?= $ipId ?>" title="นำกลับมานับใหม่">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>คืนค่า
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">ปิด</button>
            </div>
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
                        <input type="text" name="supplier_invoice_date" id="receiveBillInvoiceDate" class="form-control" placeholder="วัน/เดือน/ปี เช่น 29/05/2026" autocomplete="off" required>
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
                    <button type="submit" class="btn btn-orange">บันทึกบิลซื้อ</button>
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
                    <button type="submit" class="btn btn-orange">บันทึก</button>
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
                <img id="showSlipImage" src="" alt="หลักฐานการจ่ายเงิน" class="img-fluid rounded border d-none" style="max-height:55vh; object-fit:contain;">
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
                <a id="showSlipOpenLink" href="#" target="_blank" rel="noopener" class="btn btn-outline-orange d-none">เปิดไฟล์เต็ม</a>
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
                    <button type="button" class="btn btn-outline-orange rounded-pill py-2 text-start" id="poBatchPrintAllBtn">
                        <span class="fw-semibold d-block">4. พิมพ์ทุกอย่าง (PR + PO + สลิป/แนบ)</span>
                        <span class="small text-muted">แต่ละใบ: ใบขอซื้อ (ถ้ามี) แล้วตามด้วย PO สลิป และแนบ QT ตามที่มี</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="<?= htmlspecialchars(app_path('assets/js/tnc-table-skeleton.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
window.__tncPoBoot = window.__tncPoBoot || { table: false, sync: false };
window.tncPoLiveDatasetsUrl = <?= json_encode(app_path('actions/live-datasets.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

window.tncPoShowWait = function (title, sub) {
    if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.showWithMessage === 'function') {
        window.TncLoadingOverlay.showWithMessage(title, sub);
    } else if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.show === 'function') {
        window.TncLoadingOverlay.show();
    }
};

window.tncPoHideWait = function () {
    if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.hide === 'function') {
        window.TncLoadingOverlay.hide();
    }
};

window.tncPoTryPageReady = function () {
    var boot = window.__tncPoBoot;
    if (!boot || !boot.table || !boot.sync) {
        return;
    }
    if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.pageReady === 'function') {
        window.TncLoadingOverlay.pageReady();
    }
};

window.tncPoFetchActionRow = function (poId) {
    var url = window.tncPoLiveDatasetsUrl + '?dataset=po_action_row&po_id=' + encodeURIComponent(String(poId || ''));
    return fetch(url, { credentials: 'same-origin' })
        .then(function (r) {
            if (!r.ok) {
                throw new Error('fetch_failed');
            }
            return r.json();
        })
        .then(function (d) {
            if (!d || !d.ok || !d.row) {
                throw new Error('bad_payload');
            }
            return d.row;
        });
};

window.tncPoReloadWithWait = function (title, sub) {
    window.__tncPoReloading = true;
    window.tncPoShowWait(title || 'กำลังอัปเดตข้อมูล PO…', sub || 'พบข้อมูลเปลี่ยนแปลง กำลังโหลดหน้าใหม่…');
    window.location.reload();
};
</script>
<script>
(function ($) {
    var poBoot = window.__tncPoBoot;

    function initPoDataTable() {
        var hasDataRows = $('#poTable tbody tr').length && $('#poTable tbody tr td[colspan]').length === 0;
        if (hasDataRows) {
            $('#poTable').DataTable({
                order: [[1, 'desc']],
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'ทั้งหมด']],
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
                columnDefs: [{ targets: [0, 5], orderable: false, searchable: false }],
                initComplete: function () {
                    poBoot.table = true;
                    window.tncPoTryPageReady();
                }
            });
        } else {
            poBoot.table = true;
        }
    }

    if (window.TncTableSkeleton && document.getElementById('poTableBody')?.classList.contains('tnc-table-is-loading')) {
        window.TncTableSkeleton.bootListPage({
            bodyId: 'poTableBody',
            tableId: 'poTable',
            onReady: initPoDataTable
        });
    } else {
        initPoDataTable();
    }

    var u = window.tncPoLiveDatasetsUrl + '?dataset=mirror_table&table=purchase_orders';
    var c = '';
    fetch(u, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d && d.ok) {
                c = d.checksum || '';
            }
        })
        .catch(function () {})
        .finally(function () {
            poBoot.sync = true;
            window.tncPoTryPageReady();
        });

    setInterval(function () {
        if (document.hidden) return;
        if (window.__poBlockReload) return;
        fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.ok) return;
            if (c === '') { c = d.checksum; return; }
            if (d.checksum !== c) {
                window.tncPoReloadWithWait();
            }
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
        window.location.href = batchBase + '?kind=po&ids=' + encodeURIComponent(pendingBatchIds) + '&print_mode=' + encodeURIComponent(m);
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
    document.querySelector('.po-incomplete-box')?.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        e.preventDefault();
        document.getElementById('incompletePoModal') && bootstrap.Modal.getOrCreateInstance(document.getElementById('incompletePoModal')).show();
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

        function ymdToDmy(ymd) {
            const m = String(ymd || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
            return m ? (m[3] + '/' + m[2] + '/' + m[1]) : '';
        }

        function normalizeInvoiceDateForSubmit() {
            const raw = (invDateEl.value || '').trim();
            if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
                return true;
            }
            const m = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
            if (!m) {
                return false;
            }
            const dd = Number(m[1]);
            const mm = Number(m[2]);
            const yyyy = Number(m[3]);
            const d = new Date(yyyy, mm - 1, dd);
            if (d.getFullYear() !== yyyy || d.getMonth() !== (mm - 1) || d.getDate() !== dd) {
                return false;
            }
            invDateEl.value = yyyy + '-' + String(mm).padStart(2, '0') + '-' + String(dd).padStart(2, '0');
            return true;
        }

        let receiveBillDateFp = null;
        if (invDateEl && typeof flatpickr === 'function') {
            receiveBillDateFp = flatpickr(invDateEl, {
                dateFormat: 'd/m/Y',
                allowInput: true,
            });
        }

        function openReceiveBillModal(data) {
            const poId = data.poId || '';
            window.tncPoShowWait(
                'กำลังโหลดข้อมูล PO…',
                'กรุณารอสักครู่ ระบบกำลังดึงข้อมูลล่าสุดก่อนบันทึกเลขบิล'
            );
            window.tncPoFetchActionRow(poId)
                .then(function (row) {
                    if (row.status === 'cancelled') {
                        alert('ใบสั่งซื้อนี้ถูกยกเลิกแล้ว');
                        window.tncPoReloadWithWait();
                        return;
                    }
                    if (row.billing_status === 'billed') {
                        alert('ใบสั่งซื้อนี้บันทึกเลขบิลแล้ว');
                        window.tncPoReloadWithWait();
                        return;
                    }
                    poIdInput.value = String(row.id || poId);
                    poNoEl.textContent = row.po_number || data.poNumber || '-';
                    totalEl.value = Number(row.billed_total_amount ?? row.total_amount ?? 0).toFixed(2);
                    vatEl.value = Number(row.billed_vat_amount ?? 0).toFixed(2);
                    invNoEl.value = row.supplier_invoice_no || '';
                    const issueDmy = ymdToDmy(row.issue_date || data.issueDate || '');
                    if (receiveBillDateFp) {
                        if (issueDmy) {
                            receiveBillDateFp.setDate(issueDmy, true, 'd/m/Y');
                        } else {
                            receiveBillDateFp.clear();
                        }
                    } else {
                        invDateEl.value = issueDmy;
                    }
                    receiveBillModal.show();
                })
                .catch(function () {
                    alert('โหลดข้อมูลไม่สำเร็จ กรุณาลองใหม่');
                })
                .finally(function () {
                    if (!window.__tncPoReloading) {
                        window.tncPoHideWait();
                    }
                });
        }

        window.tncOpenReceiveBillModal = openReceiveBillModal;

        document.querySelectorAll('.js-receive-bill').forEach((btn) => {
            btn.addEventListener('click', () => {
                openReceiveBillModal({
                    poId: btn.getAttribute('data-po-id') || '',
                    poNumber: btn.getAttribute('data-po-number') || '-',
                    total: btn.getAttribute('data-po-total') || '0.00',
                    vat: btn.getAttribute('data-po-vat') || '0.00',
                    issueDate: btn.getAttribute('data-po-issue-date') || '',
                });
            });
        });

        formEl?.addEventListener('submit', function (e) {
            const totalVal = parseFloat(String(totalEl.value || ''));
            const vatVal = parseFloat(String(vatEl.value || ''));
            if (!Number.isFinite(totalVal) || !Number.isFinite(vatVal) || totalVal < 0 || vatVal < 0) {
                e.preventDefault();
                alert('ยอดเงินรวมและยอด VAT ต้องไม่เป็นค่าว่างหรือติดลบ');
                return;
            }
            if (!normalizeInvoiceDateForSubmit()) {
                e.preventDefault();
                alert('กรุณากรอกวันที่บนใบกำกับภาษี/บิลซื้อเป็น วัน/เดือน/ปี เช่น 29/05/2026');
                invDateEl.focus();
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

    function openMarkPaidModal(data) {
        const poId = data.poId || '';
        window.tncPoShowWait(
            'กำลังโหลดข้อมูล PO…',
            'กรุณารอสักครู่ ระบบกำลังดึงข้อมูลล่าสุดก่อนแนบหลักฐาน'
        );
        window.tncPoFetchActionRow(poId)
            .then(function (row) {
                if (row.status === 'cancelled') {
                    alert('ใบสั่งซื้อนี้ถูกยกเลิกแล้ว');
                    window.tncPoReloadWithWait();
                    return;
                }
                if (row.payment_status === 'paid') {
                    alert('ใบสั่งซื้อนี้จ่ายแล้ว');
                    window.tncPoReloadWithWait();
                    return;
                }
                poIdInput.value = String(row.id || poId);
                poNumberLabel.textContent = row.po_number || data.poNumber || '-';
                if (markPaidFile) markPaidFile.value = '';
                if (payMethodTransfer) payMethodTransfer.checked = true;
                if (payMethodCash) payMethodCash.checked = false;
                if (markPaidCashBy) markPaidCashBy.value = '';
                syncMarkPaidCashUi();
                markPaidModal.show();
            })
            .catch(function () {
                alert('โหลดข้อมูลไม่สำเร็จ กรุณาลองใหม่');
            })
            .finally(function () {
                if (!window.__tncPoReloading) {
                    window.tncPoHideWait();
                }
            });
    }

    window.tncOpenMarkPaidModal = openMarkPaidModal;

    document.querySelectorAll('.js-mark-paid').forEach((btn) => {
        btn.addEventListener('click', () => {
            openMarkPaidModal({
                poId: btn.getAttribute('data-po-id') || '',
                poNumber: btn.getAttribute('data-po-number') || '-',
            });
        });
    });

    document.querySelectorAll('.js-show-slip').forEach((btn) => {
        btn.addEventListener('click', () => {
            const poId = btn.getAttribute('data-po-id') || '';
            window.tncPoShowWait(
                'กำลังโหลดหลักฐาน…',
                'กรุณารอสักครู่ ระบบกำลังดึงข้อมูลล่าสุด'
            );
            window.tncPoFetchActionRow(poId)
                .then(function (row) {
                    const poNumber = row.po_number || btn.getAttribute('data-po-number') || '-';
                    const slip = Array.isArray(row.slip_items) && row.slip_items.length ? row.slip_items[0] : null;
                    const slipUrl = slip ? (slip.url || '') : '';
                    const slipPath = slip ? (slip.path || '') : '';
                    const pm = (row.payment_method || 'transfer').toLowerCase();
                    const paidBy = row.payment_cash_paid_by || '';
                    const isPdf = slip ? !!slip.is_pdf : /\.pdf(\?|$)/i.test(slipUrl);
                    showSlipPoNumber.textContent = poNumber;
                    if (replaceSlipPoId) replaceSlipPoId.value = String(row.id || poId);
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
                })
                .catch(function () {
                    alert('โหลดข้อมูลไม่สำเร็จ กรุณาลองใหม่');
                })
                .finally(function () {
                    if (!window.__tncPoReloading) {
                        window.tncPoHideWait();
                    }
                });
        });
    });
})();

(function () {
    const incompleteModalEl = document.getElementById('incompletePoModal');
    if (!incompleteModalEl || typeof bootstrap === 'undefined') return;
    const incompleteModal = bootstrap.Modal.getOrCreateInstance(incompleteModalEl);
    let pendingIncompleteAction = null;

    function queueAfterIncomplete(action) {
        pendingIncompleteAction = action;
        incompleteModal.hide();
    }

    const actionUrl = <?= json_encode(app_path('actions/action-handler.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;

    function refreshCounts() {
        const active = incompleteModalEl.querySelectorAll('#incompleteActiveList .ipo-item').length;
        document.querySelectorAll('.js-incomplete-count').forEach(function (el) { el.textContent = active; });
        const emptyMsg = document.getElementById('incompleteEmptyMsg');
        if (emptyMsg) { emptyMsg.classList.toggle('d-none', active > 0); }
        const box = document.querySelector('.po-incomplete-box');
        if (box && active === 0) { box.classList.add('d-none'); }

        const ignored = incompleteModalEl.querySelectorAll('#ignoredPoCollapse .ipo-item').length;
        document.querySelectorAll('.js-ignored-count').forEach(function (el) { el.textContent = ignored; });
        const ignoredSection = document.getElementById('ignoredSection');
        if (ignoredSection && ignored === 0) { ignoredSection.classList.add('d-none'); }
    }

    function postIgnore(action, poId, btn) {
        if (!poId) return;
        const fd = new FormData();
        fd.append('_csrf', csrfToken);
        fd.append('po_id', poId);
        fd.append('ajax', '1');
        btn.disabled = true;
        const item = btn.closest('.ipo-item');
        if (item) { item.style.opacity = '0.45'; }
        fetch(actionUrl + '?action=' + action, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { throw new Error('failed'); }
                if (item) { item.remove(); }
                refreshCounts();
            })
            .catch(function () {
                btn.disabled = false;
                if (item) { item.style.opacity = ''; }
                alert('ทำรายการไม่สำเร็จ กรุณาลองใหม่');
            });
    }

    incompleteModalEl.addEventListener('click', function (e) {
        const ig = e.target.closest('.js-po-ignore');
        if (ig) { e.preventDefault(); postIgnore('ignore_incomplete_po', ig.getAttribute('data-po-id'), ig); return; }
        const un = e.target.closest('.js-po-unignore');
        if (un) { e.preventDefault(); postIgnore('unignore_incomplete_po', un.getAttribute('data-po-id'), un); return; }
    });

    incompleteModalEl.addEventListener('shown.bs.modal', function () { window.__poBlockReload = true; });
    incompleteModalEl.addEventListener('hidden.bs.modal', function () {
        window.__poBlockReload = false;
        if (!pendingIncompleteAction) {
            return;
        }
        const action = pendingIncompleteAction;
        pendingIncompleteAction = null;
        if (action.type === 'receive-bill' && typeof window.tncOpenReceiveBillModal === 'function') {
            window.tncOpenReceiveBillModal(action.data);
        } else if (action.type === 'mark-paid' && typeof window.tncOpenMarkPaidModal === 'function') {
            window.tncOpenMarkPaidModal(action.data);
        }
    });

    incompleteModalEl.querySelectorAll('.js-fix-paid').forEach(function (btn) {
        btn.addEventListener('click', function () {
            queueAfterIncomplete({
                type: 'mark-paid',
                data: {
                    poId: btn.getAttribute('data-po-id') || '',
                    poNumber: btn.getAttribute('data-po-number') || '-',
                },
            });
        });
    });
    incompleteModalEl.querySelectorAll('.js-fix-bill').forEach(function (btn) {
        btn.addEventListener('click', function () {
            queueAfterIncomplete({
                type: 'receive-bill',
                data: {
                    poId: btn.getAttribute('data-po-id') || '',
                    poNumber: btn.getAttribute('data-po-number') || '-',
                    total: btn.getAttribute('data-po-total') || '0.00',
                    vat: btn.getAttribute('data-po-vat') || '0.00',
                    issueDate: btn.getAttribute('data-po-issue-date') || '',
                },
            });
        });
    });
})();
</script>

</body>
</html>