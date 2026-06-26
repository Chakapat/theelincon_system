<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/line_pr_approval.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_table_skeleton.php';
require_once dirname(__DIR__, 2) . '/includes/purchase_flash.php';
require_once dirname(__DIR__, 2) . '/includes/site_budget.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$csrfQ = '&_csrf=' . rawurlencode(csrf_token());
$siteFilter = tnc_site_list_filter_from_request();
$filterSiteId = (int) ($siteFilter['site_id'] ?? 0);
$filterSiteName = (string) ($siteFilter['site_name'] ?? '');
$siteFilterQuery = (string) ($siteFilter['query'] ?? '');
$siteHubUrl = (string) ($siteFilter['hub_url'] ?? '');

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

if ($filterSiteId > 0) {
    $pr_rows = array_values(array_filter($pr_rows, static function (array $row) use ($filterSiteId): bool {
        return (int) ($row['site_id'] ?? 0) === $filterSiteId;
    }));
}

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
    <?php require_once dirname(__DIR__, 2) . '/includes/document_color_css.php'; tnc_doc_color_render_head_assets(); ?>
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
        .pr-po-legend {
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            gap: 0.75rem;
            padding: 0.55rem 0 0.85rem;
            margin-bottom: 0.15rem;
            font-size: 0.8125rem;
            color: #64748b;
            overflow-x: auto;
        }
        .pr-po-legend__item {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            white-space: nowrap;
        }
        .pr-po-legend__sep {
            color: #cbd5e1;
            user-select: none;
        }
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
<body class="purchase-module tnc-app-body tnc-layout-list tnc-purchase-boot-lock" data-tnc-boot-title="กำลังโหลดรายการ PR…" data-tnc-boot-sub="กรุณารอสักครู่ ระบบจะพร้อมให้จัดการใบขอซื้อเมื่อโหลดเสร็จ" data-tnc-boot-sync-url="<?= htmlspecialchars(app_path('actions/live-datasets.php?dataset=mirror_table&table=purchase_requests'), ENT_QUOTES, 'UTF-8') ?>">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <div class="no-print">
    <?php tnc_purchase_render_flash(tnc_purchase_pr_list_flash($_GET)); ?>
    </div>

    <div class="purchase-page-head mb-4">
        <div>
            <p class="purchase-page-kicker">Purchase Module</p>
            <h1 class="purchase-list-title mb-0">
                <span class="po-list-title__icon me-2 text-tnc-orange" aria-hidden="true"><i class="bi bi-cart-check-fill"></i></span>
                รายการใบขอซื้อทั้งหมด (PR)
            </h1>
            <?php if ($filterSiteId > 0 && $filterSiteName !== ''): ?>
                <p class="text-muted small mb-0 mt-2">
                    ไซต์: <span class="fw-semibold"><?= htmlspecialchars($filterSiteName, ENT_QUOTES, 'UTF-8') ?></span>
                    · <a href="<?= htmlspecialchars($siteHubUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-tnc-orange">กลับ Site Hub</a>
                    · <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="text-secondary">ดูทุกไซต์</a>
                </p>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2 no-print align-items-center">
            <button type="button" class="btn btn-outline-dark rounded-pill px-3 shadow-sm d-none" id="prBatchPrintBtn" title="เปิดหน้าพิมพ์หลายใบตามที่ติ๊ก" aria-hidden="true">
                <i class="bi bi-printer me-1"></i>พิมพ์ที่เลือก
            </button>
            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-create.php') . ($filterSiteId > 0 ? ('?site_id=' . $filterSiteId) : ''), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange rounded-pill px-4 shadow-sm">
                <i class="bi bi-plus-lg"></i> สร้างใบขอซื้อใหม่
            </a>
            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-list.php') . $siteFilterQuery, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-orange rounded-pill px-3 shadow-sm">
                <i class="bi bi-arrow-right-circle me-1"></i>ไปหน้ารายการใบสั่งซื้อ
            </a>
        </div>
    </div>

    <?php include dirname(__DIR__, 2) . '/components/purchase-subnav.php'; ?>

    <div class="pr-print-head pr-list-print-head d-none d-print-block">
        <div class="fw-bold fs-5"><?= htmlspecialchars($companyName !== '' ? $companyName : 'THEELIN CON', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="small" style="color:#475569;">รายการใบขอซื้อ (PR) · พิมพ์เมื่อ <?= date('d/m/Y H:i') ?></div>
    </div>

    <div class="card table-card p-4">
        <div class="pr-po-legend no-print px-1" aria-label="ความหมายสีสถานะ PO">
            <span class="pr-po-legend__item"><span class="pr-po-status-dot pr-po-status-dot--no-po" aria-hidden="true"></span>สีเหลือง = ยังไม่ออกใบสั่งซื้อ</span>
            <span class="pr-po-legend__sep d-none d-sm-inline" aria-hidden="true">·</span>
            <span class="pr-po-legend__item"><span class="pr-po-status-dot pr-po-status-dot--has-po" aria-hidden="true"></span>สีเขียว = ออกใบสั่งซื้อแล้ว</span>
        </div>
        <div class="table-responsive tnc-mobile-table-wrap">
            <table class="table table-hover align-middle pr-list-print-table tnc-mobile-table" id="prTable"<?= count($pr_rows) > 0 ? ' aria-busy="true"' : '' ?>>
                <thead class="table-light">
                    <tr>
                        <th class="text-center no-print" style="width:2.5rem;" title="เลือกเพื่อพิมพ์หลายใบ">
                            <input type="checkbox" class="form-check-input m-0" id="prSelectAllPrint" aria-label="เลือกทั้งหมดในหน้านี้">
                        </th>
                        <th>เลขที่ PR</th>
                        <th>ไซต์งาน</th>
                        <th class="text-center">ประเภท</th>
                        <th class="text-center">อนุมัติ</th>
                        <th class="text-end">ยอดรวมสุทธิ</th>
                        <th class="text-center no-print">การจัดการ</th>
                    </tr>
                </thead>
                <tbody id="prTableBody"<?= count($pr_rows) > 0 ? ' class="tnc-table-is-loading"' : '' ?>>
                    <?php if (count($pr_rows) > 0): ?>
                        <?= tnc_purchase_table_skeleton_tr(7, 'pr') ?>
                        <?php foreach ($pr_rows as $row): ?>
                        <?php
                            $rowPrId = (int) ($row['id'] ?? 0);
                            $prHasPo = $rowPrId > 0 && !empty($pr_ids_with_po[$rowPrId]);
                            $prPoCancelled = $rowPrId > 0 && !empty($pr_ids_po_cancelled[$rowPrId]);
                            $createdRaw = trim((string) ($row['created_at'] ?? ''));
                            $createdTs = $createdRaw !== '' ? strtotime($createdRaw) : false;
                            $prDateDisplay = $createdTs !== false ? date('d/m/Y', $createdTs) : '—';
                            $prDateOrder = $createdTs !== false ? date('Y-m-d', $createdTs) : '0000-00-00';
                        ?>
                        <tr>
                            <td class="text-center align-middle no-print">
                                <input type="checkbox" class="form-check-input m-0 js-pr-print-cb" value="<?= $rowPrId ?>" aria-label="เลือกพิมพ์ <?= htmlspecialchars((string) ($row['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </td>
                            <td data-order="<?= htmlspecialchars($prDateOrder, ENT_QUOTES, 'UTF-8') ?>" data-label="เลขที่ PR" class="tnc-mobile-primary">
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
                                <div class="small text-muted"><?= htmlspecialchars($prDateDisplay, ENT_QUOTES, 'UTF-8') ?></div>
                            </td>
                            <td class="small" data-label="ไซต์งาน"><?php
                                $sn = trim((string) ($row['site_name'] ?? ''));
                                echo $sn !== '' ? htmlspecialchars($sn, ENT_QUOTES, 'UTF-8') : '—';
                            ?></td>
                            <td class="text-center" data-label="ประเภท">
                                <?php $reqType = (string) ($row['request_type'] ?? 'purchase'); ?>
                                <?php if ($reqType === 'hire'): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle rounded-pill" style="font-size:0.75rem;">จัดจ้าง</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-secondary border rounded-pill" style="font-size:0.75rem;">จัดซื้อ</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center" data-label="อนุมัติ">
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
                            <td class="text-end tnc-mobile-amount" data-label="ยอดรวมสุทธิ" data-order="<?= htmlspecialchars((string) $totalAmt, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="fw-bold"><?php
                                    if ($totalIsZero) {
                                        echo '<span class="text-warning">รอราคา</span>';
                                    } else {
                                        echo number_format($totalAmt, 2);
                                    }
                                ?></div>
                            </td>
                            <td class="text-center no-print tnc-mobile-actions" data-label="จัดการ">
                                <div class="btn-group shadow-sm rounded">
                                    <?php
                                    $prCanEdit = line_pr_user_can_edit($row);
                                    $prEditLockTitle = 'ไม่มีสิทธิ์แก้ไข PR';
                                    ?>
                                    <?php if ($prCanEdit): ?>
                                        <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-create.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-white text-warning border" title="แก้ไขใบขอซื้อ">
                                            <i class="bi bi-pencil-fill"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="btn btn-sm btn-white text-secondary border disabled opacity-75" style="cursor: not-allowed;" title="<?= htmlspecialchars($prEditLockTitle, ENT_QUOTES, 'UTF-8') ?>">
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
                            <td colspan="7" class="text-center py-4 text-muted"><?= $filterSiteId > 0 ? 'ไม่พบ PR ของไซต์นี้' : 'ไม่พบข้อมูลใบขอซื้อ' ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="<?= htmlspecialchars(app_path('assets/js/tnc-table-skeleton.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var batchBase = <?= json_encode(app_path('pages/purchase/purchase-batch-print.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var batchPrintBtn = document.getElementById('prBatchPrintBtn');
    var selectAllPrint = document.getElementById('prSelectAllPrint');

    function syncPrBatchPrintBtn() {
        if (!batchPrintBtn) return;
        var hasChecked = document.querySelectorAll('.js-pr-print-cb:checked').length > 0;
        batchPrintBtn.classList.toggle('d-none', !hasChecked);
        batchPrintBtn.setAttribute('aria-hidden', hasChecked ? 'false' : 'true');
    }

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
        syncPrBatchPrintBtn();
    });
    document.getElementById('prTable')?.addEventListener('change', function (e) {
        if (!e.target.classList.contains('js-pr-print-cb')) return;
        if (selectAllPrint) {
            var boxes = document.querySelectorAll('#prTable tbody .js-pr-print-cb');
            var checked = document.querySelectorAll('#prTable tbody .js-pr-print-cb:checked');
            selectAllPrint.checked = boxes.length > 0 && checked.length === boxes.length;
            selectAllPrint.indeterminate = checked.length > 0 && checked.length < boxes.length;
        }
        syncPrBatchPrintBtn();
    });
    syncPrBatchPrintBtn();
})();
</script>
<script>
(function ($) {
    function initPrDataTable() {
        if ($('#prTable tbody tr td[colspan]').length === 0 && $('#prTable tbody tr').length) {
            $('#prTable').DataTable({
                order: [[1, 'desc']],
                pageLength: 10,
                info: false,
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
                columnDefs: [{ targets: [0, 6], orderable: false, searchable: false }],
                initComplete: function () {
                    if (window.TncPurchaseLoading) {
                        window.TncPurchaseLoading.markBootTableReady();
                    }
                }
            });
        } else if (window.TncPurchaseLoading) {
            window.TncPurchaseLoading.markBootTableReady();
        }
    }

    if (window.TncTableSkeleton && document.getElementById('prTableBody')?.classList.contains('tnc-table-is-loading')) {
        window.TncTableSkeleton.bootListPage({
            bodyId: 'prTableBody',
            tableId: 'prTable',
            onReady: initPrDataTable
        });
    } else {
        initPrDataTable();
    }

    var u = <?= json_encode(app_path('actions/live-datasets.php?dataset=mirror_table&table=purchase_requests'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var c = '';
    setInterval(function () {
        if (document.hidden) return;
        fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.ok) return;
            if (c === '') { c = d.checksum; return; }
            if (d.checksum !== c) {
                if (typeof window.tncPurchaseReloadWithWait === 'function') {
                    window.tncPurchaseReloadWithWait('กำลังอัปเดตรายการ PR…', 'พบข้อมูลเปลี่ยนแปลง กำลังโหลดหน้าใหม่…');
                } else {
                    window.location.reload();
                }
            }
        }).catch(function () {});
    }, 6000);
})(jQuery);
</script>

</body>
</html>