<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/line_pr_approval.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$csrfQ = '&_csrf=' . rawurlencode(csrf_token());

$users = Db::tableKeyed('users');
$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', false);
$companyName = trim((string) ((array_values($companies)[0]['name'] ?? '')));
$pr_rows = Db::tableRows('purchase_requests');
foreach ($pr_rows as &$row) {
    $cb = $users[(string) ($row['created_by'] ?? '')] ?? null;
    $row['creator_fname'] = $cb['fname'] ?? '';
    $row['creator_lname'] = $cb['lname'] ?? '';
}
unset($row);
Db::sortRows($pr_rows, 'created_at', true);

$pr_ids_with_po = [];
/** PR id => true when linked PO status is cancelled (lowercase in DB) */
$pr_ids_po_cancelled = [];
foreach (Db::tableRows('purchase_orders') as $poRow) {
    $pRid = (int) ($poRow['pr_id'] ?? 0);
    if ($pRid > 0) {
        $pr_ids_with_po[$pRid] = true;
        $poSt = strtolower(trim((string) ($poRow['status'] ?? 'ordered')));
        if ($poSt === 'cancelled') {
            $pr_ids_po_cancelled[$pRid] = true;
        }
    }
}

/** PR id => true ถ้ามีบรรทัดจัดซื้อที่มีจำนวนแต่ราคา/หน่วย = 0 (ยังไม่ทราบราคา) */
$pr_ids_unknown_unit_price = [];
foreach (Db::tableRows('purchase_request_items') as $pri) {
    $pid = (int) ($pri['pr_id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    $qty = (float) ($pri['quantity'] ?? 0);
    $unitPrice = (float) ($pri['unit_price'] ?? 0);
    if ($qty > 0 && $unitPrice <= 0) {
        $pr_ids_unknown_unit_price[$pid] = true;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการใบขอซื้อ (PR)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/tnc-app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/purchase-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/document-print.css')) ?>">
    <style>
        .table-card { border: none; border-radius: var(--tnc-radius-lg); box-shadow: var(--tnc-shadow-sm); }
        .badge { font-weight: 500; }
        .pr-print-head { border-bottom: 2px solid var(--tnc-orange); padding-bottom: 0.75rem; margin-bottom: 1rem; }
        .pr-po-status-dot {
            width: 0.55rem;
            height: 0.55rem;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
            box-shadow: 0 0 0 1px rgba(0,0,0,0.06);
        }
        .pr-po-status-dot--no-po { background-color: #ffc107; }
        .pr-po-status-dot--has-po { background-color: #198754; }
        .pr-po-status-dot--po-cancelled { background-color: #dc3545; }
        .pr-po-status-label {
            font-size: 0.7rem;
            font-weight: 600;
            line-height: 1.2;
        }
        .pr-po-status-label--cancelled { color: #dc3545; }
        @media print {
            @page { size: A4 portrait; margin: 12mm; }
            nav, .navbar, .no-print { display: none !important; }
            body { background: #fff !important; font-size: 11pt; }
            .table-card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
            .pr-list-print-table { font-size: 10pt; }
            .pr-list-print-table thead { display: table-header-group; }
            a[href]:after { content: none !important; }
        }
    </style>
</head>
<body class="purchase-module tnc-app-body">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <div class="no-print">
    <?php if (!empty($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" data-tnc-audio="create">
            บันทึกใบขอซื้อ (PR) เรียบร้อยแล้ว — ส่งขออนุมัติ LINE หรือให้ ADMIN อนุมัติได้จากหน้ารายละเอียด PR
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php
    $lineNotify = trim((string) ($_GET['line_notify'] ?? ''));
    if ($lineNotify === 'sent'): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            ส่งข้อความขออนุมัติไป LINE แล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif ($lineNotify === 'missing_token'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            บันทึก PR แล้ว แต่ยังไม่ได้ตั้ง Channel Access Token — ไปที่หน้าตั้งค่า LINE
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif ($lineNotify === 'missing_target'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            บันทึก PR แล้ว แต่ยังไม่ได้ตั้ง <strong>กลุ่ม LINE</strong> ในหน้าตั้งค่า LINE — ไปที่เมนูตั้งค่า LINE แล้วเลือก Group ID
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif ($lineNotify !== '' && $lineNotify !== 'sent'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            บันทึก PR แล้ว แต่ส่ง LINE ไม่สำเร็จ (<?= htmlspecialchars($lineNotify, ENT_QUOTES, 'UTF-8') ?>) — ตรวจสอบการตั้งค่า LINE
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['updated'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" data-tnc-audio="update">
            แก้ไขใบขอซื้อ (PR) เรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" data-tnc-audio="delete">
            ลบใบขอซื้อเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php
            $err = $_GET['error'];
            if ($err === 'invalid_pr') {
                echo 'ไม่พบรหัสใบขอซื้อที่ถูกต้อง';
            } elseif ($err === 'pr_has_po') {
                echo 'ใบขอซื้อนี้มีใบสั่งซื้อ (PO) แล้ว ไม่สามารถแก้ไขได้';
            } elseif ($err === 'pr_approved_locked') {
                echo 'ใบขอซื้ออนุมัติแล้ว ไม่สามารถแก้ไขได้';
            } elseif ($err === 'delete_pr_failed') {
                echo 'ไม่สามารถลบใบขอซื้อได้ กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ';
            } else {
                echo 'เกิดข้อผิดพลาด กรุณาลองใหม่';
            }
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    </div>

    <div class="purchase-page-head mb-4">
        <div>
            <p class="purchase-page-kicker">Purchase Module</p>
            <h1 class="purchase-list-title mb-0">
                <span class="po-list-title__icon me-2 text-tnc-orange" aria-hidden="true"><i class="bi bi-cart-check-fill"></i></span>
                รายการใบขอซื้อ (PR)
            </h1>
        </div>
        <div class="d-flex flex-wrap gap-2 no-print">
            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-create.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange rounded-pill px-4 shadow-sm">
                <i class="bi bi-plus-lg"></i> สร้างใบขอซื้อใหม่
            </a>
            <button type="button" class="btn btn-outline-dark rounded-pill px-3 shadow-sm" id="prBatchPrintBtn" title="เปิดหน้าพิมพ์หลายใบตามที่ติ๊ก">
                <i class="bi bi-printer me-1"></i>พิมพ์ที่เลือก
            </button>
            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-orange rounded-pill px-3 shadow-sm">
                <i class="bi bi-arrow-right-circle me-1"></i>ไปหน้ารายการใบสั่งซื้อ
            </a>
        </div>
    </div>

    <div class="pr-print-head pr-list-print-head d-none d-print-block">
        <div class="fw-bold fs-5"><?= htmlspecialchars($companyName !== '' ? $companyName : 'THEELIN CON', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="small" style="color:#475569;">รายการใบขอซื้อ (PR) · พิมพ์เมื่อ <?= date('d/m/Y H:i') ?></div>
    </div>

    <div class="card table-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle pr-list-print-table" id="prTable">
                <thead class="table-light">
                    <tr>
                        <th class="text-center no-print" style="width:2.5rem;" title="เลือกเพื่อพิมพ์หลายใบ">
                            <input type="checkbox" class="form-check-input m-0" id="prSelectAllPrint" aria-label="เลือกทั้งหมดในหน้านี้">
                        </th>
                        <th>เลขที่ PR</th>
                        <th>วันที่ขอซื้อ/จัดจ้าง</th>
                        <th>ไซต์งาน</th>
                        <th class="text-center">ประเภท</th>
                        <th class="text-center">อนุมัติ</th>
                        <th class="text-end">ยอดรวมสุทธิ</th>
                        <th class="text-center no-print">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($pr_rows) > 0): ?>
                        <?php foreach ($pr_rows as $row): ?>
                        <?php
                            $rowPrId = (int) ($row['id'] ?? 0);
                            $prHasPo = $rowPrId > 0 && !empty($pr_ids_with_po[$rowPrId]);
                            $prPoCancelled = $rowPrId > 0 && !empty($pr_ids_po_cancelled[$rowPrId]);
                        ?>
                        <tr>
                            <td class="text-center align-middle no-print">
                                <input type="checkbox" class="form-check-input m-0 js-pr-print-cb" value="<?= $rowPrId ?>" aria-label="เลือกพิมพ์ <?= htmlspecialchars((string) ($row['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </td>
                            <td>
                                <div class="fw-bold <?= $prPoCancelled ? 'text-danger' : 'text-tnc-orange' ?>">
                                    <span class="d-inline-flex align-items-center gap-2 flex-wrap">
                                        <?php
                                        $prNoDisp = htmlspecialchars((string) ($row['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $prViewHref = htmlspecialchars(app_path('pages/purchase/purchase-request-view.php'), ENT_QUOTES, 'UTF-8') . '?id=' . (int) $row['id'];
                                        $prLinkClass = $prPoCancelled ? 'text-danger' : 'text-tnc-orange';
                                        echo '<a href="' . $prViewHref . '" class="' . $prLinkClass . ' text-decoration-none" title="ดูรายละเอียด">' . $prNoDisp . '</a>';
                                        if ($prPoCancelled) {
                                            echo '<span class="pr-po-status-dot pr-po-status-dot--po-cancelled" role="img" aria-label="PO ยกเลิกแล้ว" title="PO ยกเลิกแล้ว"></span>';
                                        } elseif ($prHasPo) {
                                            echo '<span class="pr-po-status-dot pr-po-status-dot--has-po" role="img" aria-label="ออก PO แล้ว" title="ออก PO แล้ว"></span>';
                                        } else {
                                            echo '<span class="pr-po-status-dot pr-po-status-dot--no-po" role="img" aria-label="ยังไม่ออก PO" title="ยังไม่ออก PO"></span>';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="small text-muted"><?php
                                    $cr = trim(($row['creator_fname'] ?? '') . ' ' . ($row['creator_lname'] ?? ''));
                                    echo $cr !== '' ? htmlspecialchars($cr) : '—';
                                ?></div>
                            </td>
                            <td><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>
                            <td class="small"><?php
                                $sn = trim((string) ($row['site_name'] ?? ''));
                                echo $sn !== '' ? htmlspecialchars($sn, ENT_QUOTES, 'UTF-8') : '—';
                            ?></td>
                            <td class="text-center">
                                <?php $reqType = (string) ($row['request_type'] ?? 'purchase'); ?>
                                <?php if ($reqType === 'hire'): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle rounded-pill" style="font-size:0.75rem;">จัดจ้าง</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-secondary border rounded-pill" style="font-size:0.75rem;">จัดซื้อ</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php
                                $apSt = line_pr_normalize_status($row);
                                $apLbl = line_pr_status_label_th($apSt);
                                $apCls = line_pr_status_badge_class($apSt);
                                ?>
                                <span class="badge rounded-pill <?= htmlspecialchars($apCls, ENT_QUOTES, 'UTF-8') ?>" style="font-size:0.72rem;"><?= htmlspecialchars($apLbl, ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <?php
                                $totalAmt = (float) ($row['total_amount'] ?? 0);
                                $totalIsZero = abs($totalAmt) < 0.0005;
                                $hasUnknownLinePrice = $reqType === 'purchase' && !empty($pr_ids_unknown_unit_price[$rowPrId]);
                            ?>
                            <td class="text-end" data-order="<?= htmlspecialchars((string) $totalAmt, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="fw-bold"><?php
                                    if ($totalIsZero) {
                                        echo '<span class="text-warning">รอราคา</span>';
                                    } else {
                                        echo number_format($totalAmt, 2);
                                    }
                                ?></div>
                            </td>
                            <td class="text-center no-print">
                                <div class="btn-group shadow-sm rounded">
                                    <?php
                                    $prCanEdit = user_can('pr.update') && !$prHasPo && line_pr_normalize_status($row) !== 'approved';
                                    ?>
                                    <?php if ($prCanEdit): ?>
                                        <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-create.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-white text-warning border" title="แก้ไขใบขอซื้อ">
                                            <i class="bi bi-pencil-fill"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="btn btn-sm btn-white text-secondary border disabled opacity-75" style="cursor: not-allowed;" title="มีใบสั่งซื้อ (PO) แล้ว — แก้ไขไม่ได้">
                                            <i class="bi bi-pencil-fill"></i>
                                        </span>
                                    <?php endif; ?>

                                    <?php if (user_can('pr.delete')): ?>
                                        <a href="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=delete_pr&id=<?= $row['id'] ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" 
                                           class="btn btn-sm btn-white text-secondary border tnc-delete-post"
                                           title="ลบใบขอซื้อ (ต้องใส่รหัสผ่าน)">
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

<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var batchBase = <?= json_encode(app_path('pages/purchase/purchase-batch-print.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    document.getElementById('prBatchPrintBtn')?.addEventListener('click', function () {
        var ids = [];
        document.querySelectorAll('.js-pr-print-cb:checked').forEach(function (cb) {
            var v = parseInt(cb.value, 10);
            if (v > 0) ids.push(v);
        });
        if (ids.length === 0) {
            alert('กรุณาติ๊กเลือกใบขอซื้อ (PR) อย่างน้อย 1 ใบ');
            return;
        }
        window.location.href = batchBase + '?kind=pr&ids=' + encodeURIComponent(ids.join(','));
    });
    document.getElementById('prSelectAllPrint')?.addEventListener('change', function () {
        var on = this.checked;
        document.querySelectorAll('#prTable tbody .js-pr-print-cb').forEach(function (cb) {
            cb.checked = on;
        });
    });
})();
</script>
<script>
(function ($) {
    if ($('#prTable tbody tr td[colspan]').length === 0 && $('#prTable tbody tr').length) {
        $('#prTable').DataTable({
            order: [[1, 'desc']],
            pageLength: 10,
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
            columnDefs: [{ targets: [0, 6], orderable: false, searchable: false }]
        });
    }
    var u = <?= json_encode(app_path('actions/live-datasets.php?dataset=mirror_table&table=purchase_requests'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
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
</script>

</body>
</html>