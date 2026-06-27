<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/connect_database.php';
require_once __DIR__ . '/includes/tnc_hub_nav.php';
require_once __DIR__ . '/includes/invoice_cancel_helpers.php';

use Theelincon\Rtdb\Portal;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$is_admin = user_is_admin_role();
$is_admin_only = user_is_admin_only_role();
$can_edit_invoice = user_can('invoice.edit');
$can_delete_invoice = user_can('invoice.delete');
$can_cancel_invoice = user_can('invoice.cancel');
$is_finance_hub = user_is_finance_role();

if (isset($_GET['ajax_search'])) {
    $needle = (string) ($_GET['search'] ?? '');
    $rows = Portal::invoiceSearchRows($needle, 60);

    if (count($rows) > 0) {
        foreach ($rows as $row): ?>
            <?php
            $isInvCancelled = tnc_invoice_is_cancelled($row);
            $issueRaw = trim((string) ($row['issue_date'] ?? ''));
            $issueTs = $issueRaw !== '' ? strtotime($issueRaw) : false;
            $dateDisplay = $issueTs !== false ? date('d/m/Y', $issueTs) : '—';
            $createdOrder = sprintf('%010d', (int) ($row['id'] ?? 0));
            ?>
            <tr<?= $isInvCancelled ? ' class="inv-row-cancelled"' : '' ?>>
                <td data-order="<?= htmlspecialchars($createdOrder, ENT_QUOTES, 'UTF-8') ?>"><?php
                    if ($isInvCancelled) {
                        $invBadgeClass = 'badge rounded-pill inv-badge-cancelled px-3';
                        $invBadgeTitle = 'ยกเลิกแล้ว';
                    } else {
                        $hasTaxInv = !empty($row['has_tax_invoice']);
                        $invBadgeClass = $hasTaxInv
                            ? 'badge rounded-pill inv-badge-tax-issued px-3'
                            : 'badge rounded-pill inv-badge-tax-pending px-3';
                        $invBadgeTitle = $hasTaxInv ? 'ออกใบกำกับภาษีแล้ว' : 'ยังไม่ออกใบกำกับภาษี';
                    }
                    ?>
                    <?php
                    $invNoDisplay = (string) ($row['invoice_number'] ?? '');
                    ?>
                    <div><span class="<?= htmlspecialchars($invBadgeClass, ENT_QUOTES, 'UTF-8') ?> index-inv-no-copy" role="button" tabindex="0" data-invoice-copy="<?= htmlspecialchars($invNoDisplay, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($invBadgeTitle, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($invNoDisplay, ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="small text-muted mt-1"><?= htmlspecialchars($dateDisplay, ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td class="fw-semibold">
                    <div class="d-flex align-items-center gap-2">
                        <?php
                        $custLogo = isset($row['customer_logo']) ? trim((string) $row['customer_logo']) : '';
                        if ($custLogo !== ''):
                        ?>
                            <img src="<?= htmlspecialchars(upload_logo_url($custLogo)) ?>" alt="" class="cust-logo-thumb rounded border bg-light flex-shrink-0 object-fit-contain">
                        <?php endif; ?>
                        <span class="text-break"><?= htmlspecialchars($row['customer_name'] ?? ''); ?></span>
                    </div>
                </td>
                <td class="fw-bold text-dark">
                    ฿<?= number_format($row['net_pay'], 2); ?>
                </td>
                <td class="text-end pe-4">
                    <div class="d-inline-flex flex-wrap align-items-center justify-content-end gap-2">
                        <button type="button" class="btn btn-invoice-action btn-invoice-action-view" data-tnc-invoice="view" data-invoice-id="<?= (int) $row['id']; ?>" title="ดูใบแจ้งหนี้"><i class="bi bi-eye-fill"></i></button>
                        <a href="<?= htmlspecialchars(app_path('pages/invoices/tax-invoice-receipt.php')) ?>?id=<?= $row['id']; ?>" class="btn btn-invoice-action btn-invoice-action-tax" title="ใบกำกับภาษี/ใบเสร็จ"><i class="bi bi-file-earmark-check-fill"></i></a>
                        <?php if ($can_edit_invoice && !$isInvCancelled): ?>
                            <a href="<?= htmlspecialchars(app_path('pages/invoices/invoice.php'), ENT_QUOTES, 'UTF-8') ?>?action=edit&amp;id=<?= (int) $row['id']; ?>" class="btn btn-invoice-action btn-invoice-action-edit" title="แก้ไข"><i class="bi bi-pencil-square"></i></a>
                        <?php endif; ?>
                        <?php if ($can_cancel_invoice && !$isInvCancelled): ?>
                            <button type="button" class="btn btn-invoice-action btn-invoice-action-cancel" data-tnc-cancel-invoice data-invoice-id="<?= (int) $row['id'] ?>" title="ยกเลิกใบแจ้งหนี้"><i class="bi bi-x-circle"></i></button>
                        <?php endif; ?>
                        <?php if ($can_delete_invoice): ?>
                            <button type="button" onclick="deleteItem(<?= $row['id']; ?>, 'invoice')" class="btn btn-invoice-action btn-invoice-action-delete" title="ลบ"><i class="bi bi-trash3-fill"></i></button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach;
    } else {
        echo "<tr><td colspan='4' class='text-center py-5 text-muted'>ไม่พบข้อมูลใบแจ้งหนี้ที่ค้นหา</td></tr>";
    }
    exit;
}

/** เมนูหมวดซ้ายหน้าแรก: true = ปิดทุกหมวดตอนโหลด (ค่าเริ่มต้น), false = เปิดหมวด «ข้อมูลหลัก» ไว้ */
$index_hub_start_all_collapsed = true;
$index_display_name = trim((string) ($_SESSION['name'] ?? ''));
if ($index_display_name === '') {
    $index_display_name = 'ผู้ใช้งาน';
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TNC | OFFICE SYSTEM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/tnc-app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/index-page.css') . '?v=' . rawurlencode((string) @filemtime(__DIR__ . '/assets/css/index-page.css')), ENT_QUOTES, 'UTF-8') ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="tnc-app-body tnc-index-page tnc-has-hub-fab tnc-index-desktop-sidebar">

<?php include __DIR__ . '/components/navbar.php'; ?>

<div class="container pb-5 pt-1 index-page-wrap px-3 px-md-4">
    <div class="row g-4 index-layout-row">
    <aside class="col-lg-4 col-xl-3 index-sidebar-wrap order-1 order-lg-1" aria-label="เมนูนำทางระบบ">
        <div class="index-sidebar-sticky" id="indexSidebarMenuMount">
            <div class="card index-sidebar-card rounded-4 overflow-hidden mb-0" id="indexSidebarMenuCard">
                <section class="home-menu-hub index-sidebar mb-0" aria-label="เมนูระบบ">
                    <div class="index-sidebar-scroll">
                    <div class="card home-menu-hub-single home-hub-card border-0 shadow-none rounded-0 overflow-hidden">
                        <?php tnc_hub_nav_render_sidebar(['start_collapsed' => $index_hub_start_all_collapsed]); ?>
                    </div>
                    </div>
                </section>
            </div>
        </div>
    </aside>

    <main class="col-lg-8 col-xl-9 index-main-col order-2 order-lg-2 min-w-0" id="main-content">
    <div class="index-dashboard-block">
    <div class="card index-table-card border-0 shadow-sm overflow-hidden mb-0">
        <div class="card-header border-bottom py-3 px-3 px-md-4">
            <div class="row align-items-center g-3">
                <div class="col-12 col-lg-4">
                    <p class="index-invoice-head-kicker mb-1">Invoice Hub</p>
                    <h1 class="index-invoice-head-title mb-0">รายการใบแจ้งหนี้</h1>
                </div>
                <div class="col-12 col-lg-8">
                    <div class="d-flex flex-column flex-sm-row flex-wrap gap-2 align-items-stretch justify-content-lg-end">
                        <div class="position-relative flex-grow-1 index-table-toolbar index-table-toolbar-field">
                            <label class="visually-hidden" for="search_invoice">ค้นหาใบแจ้งหนี้</label>
                            <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted" aria-hidden="true"></i>
                            <input type="search" id="search_invoice" autocomplete="off" class="form-control index-search-input ps-5" placeholder="ค้นหาเลขที่หรือชื่อลูกค้า…">
                        </div>
                        <?php if (user_can('page.invoice.create') && user_can('invoice.edit')): ?><a href="<?= htmlspecialchars(app_path('pages/invoices/invoice.php')) ?>?action=create" class="index-cta-btn index-cta-primary flex-shrink-0 text-center text-nowrap">
                            <span class="index-cta-icon"><i class="bi bi-plus-lg" aria-hidden="true"></i></span>
                            <span>สร้างใบแจ้งหนี้ใหม่</span>
                        </a><?php endif; ?>
                        <?php if (user_can('page.invoice.tax_list')): ?><a href="<?= htmlspecialchars(app_path('pages/invoices/tax-invoice-list.php')) ?>" class="index-cta-btn index-cta-secondary flex-shrink-0 text-center text-nowrap">
                            <span class="index-cta-icon"><i class="bi bi-file-earmark-break" aria-hidden="true"></i></span>
                            <span>รายการใบกำกับภาษี</span>
                        </a><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table id="invoice_table" class="table table-invoice-index table-hover align-middle mb-0" aria-busy="false">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">เลขที่ใบแจ้งหนี้</th>
                        <th>รายชื่อบริษัทแจ้งหนี้</th>
                        <th>ยอดสุทธิ</th>
                        <th class="text-end pe-4">จัดการ</th>
                    </tr>
                </thead>
                <tbody id="invoice_table_body">
                    <tr>
                        <td colspan="4" class="text-center py-5 text-muted">
                            <span class="spinner-border spinner-border-sm text-warning me-2" role="status" aria-hidden="true"></span>
                            <span class="align-middle">กำลังโหลดรายการใบแจ้งหนี้…</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="index-inv-legend d-flex flex-nowrap align-items-center gap-2 gap-md-3 overflow-x-auto small text-secondary border-top py-2 px-3 px-md-4" aria-label="ความหมายสีเลขที่ใบแจ้งหนี้">
            <span class="index-inv-legend__item"><span class="index-inv-legend__swatch index-inv-legend__swatch--pending" aria-hidden="true"></span>สีเหลือง = ยังไม่ออกใบกำกับภาษี</span>
            <span class="index-inv-legend__sep d-none d-sm-inline" aria-hidden="true">·</span>
            <span class="index-inv-legend__item"><span class="index-inv-legend__swatch index-inv-legend__swatch--issued" aria-hidden="true"></span>สีเขียว = ออกใบกำกับภาษีแล้ว</span>
            <span class="index-inv-legend__sep d-none d-sm-inline" aria-hidden="true">·</span>
            <span class="index-inv-legend__item"><span class="index-inv-legend__swatch index-inv-legend__swatch--cancelled" aria-hidden="true"></span>สีแดง = ยกเลิกแล้ว</span>
        </div>
    </div>
    </div>

    <div id="tncInvoicePopoverBackdrop" class="tnc-invoice-popover-backdrop d-none" aria-hidden="true"></div>
    <div id="tncInvoicePopover" class="popover tnc-invoice-popover fade d-none" role="dialog" aria-labelledby="tncInvoicePopoverTitle" aria-modal="true">
        <div class="popover-arrow"></div>
        <div class="popover-header no-print">
            <span class="me-auto" id="tncInvoicePopoverTitle">ใบแจ้งหนี้</span>
            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-semibold text-nowrap" id="tncInvoicePopoverPrint" title="พิมพ์ต้นฉบับและสำเนา">
                <i class="bi bi-printer me-1"></i>พิมพ์
            </button>
            <button type="button" class="btn-close ms-1" id="tncInvoicePopoverClose" aria-label="ปิด"></button>
        </div>
        <div class="popover-body p-0">
            <iframe id="tncInvoicePopoverFrame" title="Invoice"></iframe>
        </div>
    </div>
    </main>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?= htmlspecialchars(app_path('assets/js/tnc-invoice-cancel.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php include __DIR__ . '/includes/datatables_bundle.php'; ?>
<script>
const actionHandlerUrl = <?= json_encode(app_path('actions/action-handler.php'), JSON_UNESCAPED_SLASHES) ?>;
window.tncActionHandlerUrl = actionHandlerUrl;
const invoicePhpUrl = <?= json_encode(app_path('pages/invoices/invoice.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
window.tncCsrfToken = csrfToken;
const indexUserIsAdminOnly = <?= $is_admin_only ? 'true' : 'false' ?>;
document.querySelector('.js-hub-member-manage')?.addEventListener('click', function (e) {
    if (indexUserIsAdminOnly) {
        return;
    }
    e.preventDefault();
    Swal.fire({
        icon: 'warning',
        title: 'ไม่มีสิทธิ์เข้าใช้งาน',
        text: 'เมนูจัดการสมาชิกใช้ได้เฉพาะผู้ใช้ที่มีบทบาท ADMIN เท่านั้น',
        confirmButtonText: 'ตกลง',
        confirmButtonColor: '#ea580c'
    });
});

const loadingRowHtml = '<tr><td colspan="4">' +
    '<div class="index-skeleton-wrap" aria-label="Loading invoice rows">' +
    '<div class="index-skeleton-row"><span class="index-skeleton-line sm"></span><span class="index-skeleton-line md"></span><span class="index-skeleton-line lg"></span><span class="index-skeleton-line sm"></span><span class="index-skeleton-actions"><span class="index-skeleton-dot"></span><span class="index-skeleton-dot"></span><span class="index-skeleton-dot"></span></span></div>' +
    '<div class="index-skeleton-row"><span class="index-skeleton-line sm"></span><span class="index-skeleton-line md"></span><span class="index-skeleton-line lg"></span><span class="index-skeleton-line sm"></span><span class="index-skeleton-actions"><span class="index-skeleton-dot"></span><span class="index-skeleton-dot"></span><span class="index-skeleton-dot"></span></span></div>' +
    '<div class="index-skeleton-row"><span class="index-skeleton-line sm"></span><span class="index-skeleton-line md"></span><span class="index-skeleton-line lg"></span><span class="index-skeleton-line sm"></span><span class="index-skeleton-actions"><span class="index-skeleton-dot"></span><span class="index-skeleton-dot"></span><span class="index-skeleton-dot"></span></span></div>' +
    '</div></td></tr>';
const errorRowHtml = '<tr><td colspan="4" class="text-center py-5 text-danger">' +
    'โหลดข้อมูลไม่สำเร็จ — ลองโหลดหน้าใหม่หรือตรวจสอบการเชื่อมต่ออินเตอร์เน็ต</td></tr>';

function refreshInvoiceDataTable() {
    if (typeof jQuery === 'undefined' || !jQuery.fn.DataTable || typeof window.TncLiveDT === 'undefined') {
        return;
    }
    var $t = jQuery('#invoice_table');
    if (!$t.length) {
        return;
    }
    if (jQuery.fn.DataTable.isDataTable($t)) {
        $t.DataTable().destroy();
    }
    var $rows = $t.find('tbody tr');
    if ($rows.length === 1 && $rows.find('td[colspan]').length) {
        return;
    }
    TncLiveDT.init('#invoice_table', {
        pageLength: 5,
        order: [[0, 'desc']],
        dom: 'rtp',
        info: false,
        columnDefs: [{ orderable: false, targets: [3] }]
    });
}

function loadTable(query = '') {
    const tableBody = document.getElementById('invoice_table_body');
    const table = document.getElementById('invoice_table');
    const indexUrl = <?= json_encode(app_path('index.php'), JSON_UNESCAPED_SLASHES) ?>;
    if (tableBody) {
        tableBody.innerHTML = loadingRowHtml;
    }
    if (table) {
        table.setAttribute('aria-busy', 'true');
    }
    refreshInvoiceDataTable();
    const normalized = (query || '').trim();
    if (normalized.length === 1) {
        if (tableBody) {
            tableBody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">พิมพ์เพิ่มอีกอย่างน้อย 1 ตัวอักษรเพื่อค้นหา</td></tr>';
        }
        if (table) {
            table.setAttribute('aria-busy', 'false');
        }
        refreshInvoiceDataTable();
        return;
    }
    fetch(`${indexUrl}?ajax_search=1&search=${encodeURIComponent(normalized)}`, { credentials: 'same-origin' })
        .then(function (res) {
            if (!res.ok) {
                throw new Error('bad_status');
            }
            return res.text();
        })
        .then(function (data) {
            if (tableBody) {
                tableBody.innerHTML = data;
            }
        })
        .catch(function () {
            if (tableBody) {
                tableBody.innerHTML = errorRowHtml;
            }
        })
        .finally(function () {
            if (table) {
                table.setAttribute('aria-busy', 'false');
            }
            refreshInvoiceDataTable();
        });
}

let searchTimeout;
var searchInput = document.getElementById('search_invoice');
if (searchInput) {
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function () {
            loadTable(searchInput.value);
        }, 300);
    });
}

function tncCloseInvoicePopover() {
    const pop = document.getElementById('tncInvoicePopover');
    const backdrop = document.getElementById('tncInvoicePopoverBackdrop');
    const frame = document.getElementById('tncInvoicePopoverFrame');
    if (pop) {
        pop.classList.remove('show');
        window.setTimeout(function () {
            if (!pop.classList.contains('show')) {
                pop.classList.add('d-none');
            }
        }, 200);
    }
    if (backdrop) {
        backdrop.classList.remove('show');
        window.setTimeout(function () {
            if (!backdrop.classList.contains('show')) {
                backdrop.classList.add('d-none');
            }
        }, 200);
    }
    document.body.classList.remove('tnc-invoice-popover-open');
    if (frame) {
        frame.src = 'about:blank';
    }
}

function tncOpenInvoiceViewModal(id) {
    const frame = document.getElementById('tncInvoicePopoverFrame');
    const titleEl = document.getElementById('tncInvoicePopoverTitle');
    const pop = document.getElementById('tncInvoicePopover');
    const backdrop = document.getElementById('tncInvoicePopoverBackdrop');
    if (!frame || !pop || !backdrop) {
        return;
    }
    const u = invoicePhpUrl + '?action=view&id=' + encodeURIComponent(String(id)) + '&embed=1&_=' + Date.now();
    frame.src = u;
    if (titleEl) {
        titleEl.textContent = 'ดูใบแจ้งหนี้';
    }
    backdrop.classList.remove('d-none');
    pop.classList.remove('d-none');
    window.requestAnimationFrame(function () {
        backdrop.classList.add('show');
        pop.classList.add('show');
    });
    document.body.classList.add('tnc-invoice-popover-open');
}

function tncPrintInvoiceFromPopover() {
    const frame = document.getElementById('tncInvoicePopoverFrame');
    if (!frame || !frame.contentWindow) {
        return;
    }
    try {
        const src = frame.src || '';
        if (!src || src === 'about:blank') {
            return;
        }
        frame.contentWindow.focus();
        frame.contentWindow.print();
    } catch (e) {}
}

document.getElementById('tncInvoicePopoverClose')?.addEventListener('click', tncCloseInvoicePopover);
document.getElementById('tncInvoicePopoverBackdrop')?.addEventListener('click', tncCloseInvoicePopover);
document.getElementById('tncInvoicePopoverPrint')?.addEventListener('click', tncPrintInvoiceFromPopover);

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        const pop = document.getElementById('tncInvoicePopover');
        if (pop && pop.classList.contains('show')) {
            tncCloseInvoicePopover();
        }
        return;
    }
    if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'p') {
        return;
    }
    const pop = document.getElementById('tncInvoicePopover');
    if (!pop || !pop.classList.contains('show')) {
        return;
    }
    e.preventDefault();
    tncPrintInvoiceFromPopover();
});

document.getElementById('invoice_table_body')?.addEventListener('click', function (ev) {
    const copyEl = ev.target.closest('.index-inv-no-copy');
    if (copyEl) {
        ev.preventDefault();
        const text = copyEl.getAttribute('data-invoice-copy') || '';
        if (!text) {
            return;
        }
        const notifyOk = function () {
            if (typeof Swal === 'undefined') {
                return;
            }
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'คัดลอกเลขที่แล้ว',
                showConfirmButton: false,
                timer: 1600,
                timerProgressBar: true
            });
        };
        const notifyFail = function () {
            if (typeof Swal === 'undefined') {
                return;
            }
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'error',
                title: 'คัดลอกไม่สำเร็จ',
                showConfirmButton: false,
                timer: 2200
            });
        };
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(text).then(notifyOk).catch(notifyFail);
        } else {
            try {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                notifyOk();
            } catch (e) {
                notifyFail();
            }
        }
        return;
    }
    const btn = ev.target.closest('[data-tnc-invoice="view"]');
    if (!btn) {
        return;
    }
    const iid = btn.getAttribute('data-invoice-id');
    if (iid) {
        tncOpenInvoiceViewModal(iid);
    }
});

document.getElementById('invoice_table_body')?.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' && ev.key !== ' ') {
        return;
    }
    const copyEl = ev.target.closest('.index-inv-no-copy');
    if (!copyEl) {
        return;
    }
    ev.preventDefault();
    copyEl.click();
});

function deleteItem(id, type) {
    Swal.fire({
        title: 'ยืนยันการลบ?',
        html: 'ข้อมูลจะถูกลบถาวร กรุณาใส่<strong>รหัสผ่านของคุณ</strong>',
        icon: 'warning',
        input: 'password',
        inputPlaceholder: 'รหัสผ่าน',
        showCancelButton: true,
        confirmButtonColor: '#ea580c',
        cancelButtonColor: '#adb5bd',
        confirmButtonText: 'ยืนยัน ลบข้อมูล',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true,
        focusCancel: true,
        didOpen: function () {
            if (typeof window.tncSwalAttachPasswordReveal === 'function') {
                window.tncSwalAttachPasswordReveal();
            }
        },
        preConfirm: function (pw) {
            if (!pw || !String(pw).trim()) {
                Swal.showValidationMessage('กรุณากรอกรหัสผ่าน');
                return false;
            }
            return pw;
        }
    }).then(function (result) {
        if (!result.isConfirmed || !result.value) return;
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = actionHandlerUrl;
        form.style.display = 'none';
        [['action', 'delete'], ['type', type], ['id', String(id)], ['_csrf', csrfToken], ['confirm_password', result.value]].forEach(function (pair) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = pair[0];
            inp.value = pair[1];
            form.appendChild(inp);
        });
        document.body.appendChild(form);
        form.submit();
    });
}

function indexMarkSidebarActive() {
    var path = (window.location.pathname || '').replace(/\/$/, '') || '/';
    document.querySelectorAll('.home-menu-hub .home-hub-link').forEach(function (el) {
        try {
            var u = new URL(el.getAttribute('href') || '', window.location.origin);
            var p = (u.pathname || '').replace(/\/$/, '') || '/';
            if (p === path) {
                el.classList.add('active');
                var panel = el.closest('.collapse');
                var toggle = panel && panel.previousElementSibling;
                if (panel && toggle && toggle.classList.contains('home-hub-toggle')) {
                    panel.classList.add('show');
                    toggle.classList.remove('collapsed');
                    toggle.setAttribute('aria-expanded', 'true');
                    toggle.classList.add('has-active-child');
                }
            }
        } catch (e) {}
    });
}

window.onload = () => {
    indexMarkSidebarActive();
    var si = document.getElementById('search_invoice');
    loadTable(si ? si.value : '');
    const params = new URLSearchParams(window.location.search);
    if (params.get('invoice_updated') === '1') {
        Swal.fire({
            icon: 'success',
            title: 'อัปเดตสำเร็จ',
            text: 'บันทึกการแก้ไขใบแจ้งหนี้เรียบร้อยแล้ว',
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#ea580c'
        }).then(() => {
            const u = new URL(window.location.href);
            u.searchParams.delete('invoice_updated');
            const q = u.searchParams.toString();
            history.replaceState({}, '', u.pathname + (q ? '?' + q : '') + u.hash);
        });
    } else if (params.get('cancelled') === '1') {
        Swal.fire({ icon: 'success', title: 'ยกเลิกสำเร็จ', text: 'ยกเลิกใบแจ้งหนี้เรียบร้อยแล้ว', confirmButtonColor: '#ea580c' }).then(clearParam('cancelled'));
    } else if (params.get('error') === 'need_cancel_reason') {
        Swal.fire({ icon: 'warning', title: 'กรุณาระบุเหตุผล', text: 'ต้องกรอกเหตุผลการยกเลิกก่อนยืนยัน', confirmButtonColor: '#ea580c' }).then(clearParam('error'));
    } else if (params.get('error') === 'already_cancelled') {
        Swal.fire({ icon: 'info', title: 'ยกเลิกแล้ว', text: 'เอกสารนี้ถูกยกเลิกไปแล้ว', confirmButtonColor: '#ea580c' }).then(clearParam('error'));
    }
    function clearParam(key) {
        return function () {
            const u = new URL(window.location.href);
            u.searchParams.delete(key);
            const q = u.searchParams.toString();
            history.replaceState({}, '', u.pathname + (q ? '?' + q : '') + u.hash);
        };
    }
};
</script>
<link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/print-document-only.css'), ENT_QUOTES, 'UTF-8') ?>" media="print">
</body>
</html>