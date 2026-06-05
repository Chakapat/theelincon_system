<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_flash.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_table_skeleton.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$csrfQ = '&_csrf=' . rawurlencode(csrf_token());
$poListUrl = app_path('pages/purchase/purchase-order-list.php');
$woCreateUrl = app_path('pages/purchase/purchase-order-hire-contract-create.php');
$batchPrintBase = app_path('pages/purchase/purchase-batch-print.php');

$siteNameById = [];
foreach (Db::tableRows('sites') as $site) {
    $sid = (int) ($site['id'] ?? 0);
    if ($sid > 0) {
        $siteNameById[$sid] = trim((string) ($site['name'] ?? ''));
    }
}

$paymentCountByContractPo = [];
foreach (Db::tableRows('purchase_orders') as $poPay) {
    if (!Purchase::isHirePayablePo($poPay)) {
        continue;
    }
    if (strtolower(trim((string) ($poPay['status'] ?? ''))) === 'cancelled') {
        continue;
    }
    $refId = (int) ($poPay['reference_contract_po_id'] ?? 0);
    if ($refId <= 0) {
        continue;
    }
    $paymentCountByContractPo[$refId] = ($paymentCountByContractPo[$refId] ?? 0) + 1;
}

$woListSortYmd = static function (array $row): string {
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

$wo_rows = [];
foreach (Db::tableRows('purchase_orders') as $po) {
    if (!Purchase::isWorkOrder($po)) {
        continue;
    }
    $woId = (int) ($po['id'] ?? 0);
    $hcId = (int) ($po['hire_contract_id'] ?? 0);
    $siteId = (int) ($po['site_id'] ?? 0);
    $siteName = trim((string) ($po['site_name'] ?? ''));
    if ($siteId > 0 && isset($siteNameById[$siteId]) && $siteNameById[$siteId] !== '') {
        $siteName = $siteNameById[$siteId];
    }
    $status = strtolower(trim((string) ($po['status'] ?? 'ordered')));
    if ($status === '') {
        $status = 'ordered';
    }
    $merged = array_merge($po, [
        'status' => $status,
        'site_display' => $siteName,
        'payment_po_count' => (int) ($paymentCountByContractPo[$woId] ?? 0),
        'payment_from_hc_url' => $hcId > 0
            ? app_path('pages/purchase/purchase-order-from-hire-contract.php') . '?hire_contract_id=' . $hcId
            : '',
        'advance_from_hc_url' => $hcId > 0
            ? app_path('pages/purchase/purchase-order-from-hire-contract.php') . '?hire_contract_id=' . $hcId . '&mode=advance'
            : '',
    ]);
    $merged['_list_sort_ymd'] = $woListSortYmd($merged);
    $wo_rows[] = $merged;
}

usort($wo_rows, static function (array $a, array $b): int {
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
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการ Work Order (WO)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/tnc-app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/purchase-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        .wo-list-title { font-size: clamp(1.35rem, 2.5vw, 1.65rem); letter-spacing: -0.02em; }
        .wo-list-title__icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.35rem;
            height: 2.35rem;
            border-radius: 0.625rem;
            background: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
            font-size: 1.1rem;
            vertical-align: -0.15em;
        }
        .main-card {
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: var(--tnc-radius);
            box-shadow: 0 0.28rem 0.9rem rgba(0, 0, 0, 0.045);
            background: #fff;
        }
        #woTable thead th {
            white-space: nowrap;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--tnc-muted);
            padding: 0.85rem 0.75rem;
            border-bottom-width: 1px;
        }
        #woTable tbody td { vertical-align: middle; padding: 0.85rem 0.75rem; }
        #woTable tbody tr { transition: background-color 0.16s ease; }
        #woTable tbody tr:hover { background: #f0f6ff; }
        #woTable .wo-amount { font-variant-numeric: tabular-nums; }
        #woTable .wo-site-col { max-width: 14rem; }
        #woTable .wo-site-name {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .wo-empty-state { padding: 2.5rem 1rem; }
        .wo-empty-state i { font-size: 2rem; color: var(--tnc-muted); opacity: 0.65; }
    </style>
</head>
<body class="purchase-module tnc-app-body tnc-purchase-boot-lock" data-tnc-boot-title="กำลังโหลดรายการ WO…" data-tnc-boot-sub="กรุณารอสักครู่ ระบบจะพร้อมให้จัดการ Work Order เมื่อโหลดเสร็จ">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <?php
    $woListFlash = tnc_purchase_wo_list_flash($_GET);
    if ($woListFlash !== null && ($woListFlash['message'] ?? '') === 'ยังไม่มี Work Order (WO)') {
        $woListFlash['html'] = ' — <a href="' . htmlspecialchars($woCreateUrl, ENT_QUOTES, 'UTF-8') . '" class="alert-link">ออก Work Order</a>';
    }
    tnc_purchase_render_flash($woListFlash);
    ?>

    <div class="purchase-page-head mb-4">
        <div>
            <p class="purchase-page-kicker">Purchase Module · สัญญาจ้าง</p>
            <h1 class="purchase-list-title wo-list-title mb-0">
                <span class="wo-list-title__icon me-2" aria-hidden="true"><i class="bi bi-file-earmark-ruled-fill"></i></span>
                รายการ Work Order (WO)
            </h1>
            <p class="text-muted small mb-0 mt-1">ใบสั่งงานสัญญาจ้าง — ส่งให้ผู้รับจ้างก่อน จากนั้นออก PO สั่งจ่ายรายงวด/ครั้งใน<a href="<?= htmlspecialchars($poListUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none">รายการ PO</a></p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if (user_can('po.create')): ?>
            <a href="<?= htmlspecialchars($woCreateUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange rounded-pill px-3 shadow-sm">
                <i class="bi bi-plus-lg me-1"></i>ออก Work Order
            </a>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-dark rounded-pill px-3 shadow-sm no-print" id="woBatchPrintBtn" title="พิมพ์ WO ที่เลือก">
                <i class="bi bi-printer me-1"></i>พิมพ์ที่เลือก
            </button>
            <a href="<?= htmlspecialchars($poListUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm">
                <i class="bi bi-bag-check me-1"></i>รายการ PO สั่งจ่าย
            </a>
        </div>
    </div>

    <div class="card main-card p-4">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle" id="woTable"<?= count($wo_rows) > 0 ? ' aria-busy="true"' : '' ?>>
                <thead class="table-light">
                    <tr>
                        <th class="text-center no-print" style="width:2.5rem;" title="เลือกเพื่อพิมพ์หลายใบ">
                            <input type="checkbox" class="form-check-input m-0" id="woSelectAllPrint" aria-label="เลือกทั้งหมด">
                        </th>
                        <th>เลขที่ WO</th>
                        <th>ชื่อโครงการ</th>
                        <th>ผู้รับจ้าง</th>
                        <th class="text-center">PO สั่งจ่าย</th>
                        <th class="text-end">มูลค่าสัญญา</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody id="woTableBody"<?= count($wo_rows) > 0 ? ' class="tnc-table-is-loading"' : '' ?>>
                    <?php if (count($wo_rows) === 0): ?>
                        <tr><td colspan="7" class="wo-empty-state text-center text-muted">
                            <i class="bi bi-inbox d-block mb-2" aria-hidden="true"></i>
                            <div class="fw-semibold text-dark">ยังไม่มี Work Order</div>
                            <?php if (user_can('po.create')): ?>
                            <div class="small mt-1"><a href="<?= htmlspecialchars($woCreateUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-tnc-orange">ออก Work Order ใหม่</a></div>
                            <?php endif; ?>
                        </td></tr>
                    <?php else: ?>
                        <?= tnc_purchase_table_skeleton_tr(7, 'po') ?>
                        <?php foreach ($wo_rows as $row): ?>
                            <?php
                            $woCancelled = ($row['status'] ?? '') === 'cancelled';
                            $woId = (int) ($row['id'] ?? 0);
                            $ymd = trim((string) ($row['_list_sort_ymd'] ?? ''));
                            $viewUrl = app_path('pages/purchase/purchase-order-view.php') . '?id=' . $woId;
                            $printUrl = $viewUrl . '&print_mode=po&autoprint=1';
                            ?>
                            <tr<?= $woCancelled ? ' class="table-secondary"' : '' ?>>
                                <td class="text-center align-middle no-print">
                                    <input type="checkbox" class="form-check-input m-0 js-wo-print-cb" value="<?= $woId ?>" aria-label="เลือกพิมพ์ <?= htmlspecialchars((string) ($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                </td>
                                <td data-order="<?= htmlspecialchars($ymd !== '' ? $ymd : '0000-00-00', ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="fw-bold <?= $woCancelled ? 'text-danger text-decoration-line-through' : 'text-primary' ?>">
                                        <a href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>" class="<?= $woCancelled ? 'text-danger' : 'text-primary' ?> text-decoration-none"><?= htmlspecialchars((string) ($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></a>
                                    </div>
                                    <div class="small text-muted"><?= $ymd !== '' ? htmlspecialchars(date('d/m/Y', strtotime($ymd)), ENT_QUOTES, 'UTF-8') : '—' ?></div>
                                    <?php if ($woCancelled): ?>
                                        <span class="badge rounded-pill text-bg-danger mt-1">ยกเลิก</span>
                                    <?php endif; ?>
                                </td>
                                <td class="wo-site-col small">
                                    <?php
                                    $siteDisp = trim((string) ($row['site_display'] ?? ''));
                                    if ($siteDisp !== ''): ?>
                                        <span class="wo-site-name" title="<?= htmlspecialchars($siteDisp, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($siteDisp, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(trim((string) ($row['contractor_name'] ?? '')) !== '' ? trim((string) ($row['contractor_name'] ?? '')) : '—', ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-center">
                                    <?php $payCount = (int) ($row['payment_po_count'] ?? 0); ?>
                                    <?php if ($payCount > 0): ?>
                                        <span class="badge rounded-pill text-bg-success"><?= number_format($payCount) ?> ใบ</span>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="fw-bold wo-amount <?= $woCancelled ? 'text-danger' : '' ?>"><?= number_format((float) ($row['total_amount'] ?? 0), 2) ?></div>
                                </td>
                                <td class="text-center">
                                    <?php if (!$woCancelled): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">จัดการ</button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                            <li><a href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>" class="dropdown-item"><i class="bi bi-eye me-2"></i>ดูรายละเอียด</a></li>
                                            <li><a href="<?= htmlspecialchars($printUrl, ENT_QUOTES, 'UTF-8') ?>" class="dropdown-item" target="_blank" rel="noopener"><i class="bi bi-printer me-2"></i>พิมพ์ WO</a></li>
                                            <?php if (user_can('po.create') && ($row['payment_from_hc_url'] ?? '') !== ''): ?>
                                            <li><a href="<?= htmlspecialchars((string) $row['payment_from_hc_url'], ENT_QUOTES, 'UTF-8') ?>" class="dropdown-item text-orange"><i class="bi bi-cash-coin me-2"></i>ออก PO สั่งจ่าย</a></li>
                                            <li><a href="<?= htmlspecialchars((string) ($row['advance_from_hc_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="dropdown-item text-warning"><i class="bi bi-wallet2 me-2"></i>เบิกล่วงหน้า</a></li>
                                            <?php endif; ?>
                                            <?php if (user_can('po.cancel')): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="post" action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=cancel_purchase_order" class="d-inline" data-tnc-fullnav="1" onsubmit="return confirm('ยืนยันยกเลิก Work Order นี้?');">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="po_id" value="<?= $woId ?>">
                                                    <input type="hidden" name="return_to" value="wo_list">
                                                    <button type="submit" class="dropdown-item text-danger"><i class="bi bi-x-circle me-2"></i>ยกเลิก WO</button>
                                                </form>
                                            </li>
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

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<script src="<?= htmlspecialchars(app_path('assets/js/tnc-table-skeleton.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function () {
    var batchPrintBase = <?= json_encode($batchPrintBase . '?kind=po&back=wo&ids=', JSON_UNESCAPED_UNICODE) ?>;

    function initWoDataTable() {
        if (typeof jQuery === 'undefined' || !jQuery.fn.DataTable) {
            if (window.TncPurchaseLoading) {
                window.TncPurchaseLoading.markBootTableReady();
                window.TncPurchaseLoading.markBootSyncReady();
            }
            return;
        }
        if (jQuery('#woTable tbody tr td[colspan]').length) {
            if (window.TncPurchaseLoading) {
                window.TncPurchaseLoading.markBootTableReady();
                window.TncPurchaseLoading.markBootSyncReady();
            }
            return;
        }
        jQuery('#woTable').DataTable({
            order: [[1, 'desc']],
            pageLength: 25,
            language: { url: '//cdn.datatables.net/plug-ins/1.13.8/i18n/th.json' },
            columnDefs: [{ orderable: false, targets: [0, 6] }],
            initComplete: function () {
                if (window.TncPurchaseLoading) {
                    window.TncPurchaseLoading.markBootTableReady();
                    window.TncPurchaseLoading.markBootSyncReady();
                }
            }
        });
    }

    if (window.TncTableSkeleton && document.getElementById('woTableBody')?.classList.contains('tnc-table-is-loading')) {
        window.TncTableSkeleton.bootListPage({
            bodyId: 'woTableBody',
            tableId: 'woTable',
            onReady: initWoDataTable
        });
    } else {
        initWoDataTable();
    }

    var selectAll = document.getElementById('woSelectAllPrint');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.js-wo-print-cb').forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
        });
    }

    var batchBtn = document.getElementById('woBatchPrintBtn');
    if (batchBtn) {
        batchBtn.addEventListener('click', function () {
            var ids = [];
            document.querySelectorAll('.js-wo-print-cb:checked').forEach(function (cb) {
                ids.push(cb.value);
            });
            if (ids.length === 0) {
                alert('กรุณาเลือก WO อย่างน้อย 1 รายการ');
                return;
            }
            window.open(batchPrintBase + ids.join(','), '_blank', 'noopener');
        });
    }
})();
</script>
</body>
</html>
