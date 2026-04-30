<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/connect_database.php';

use Theelincon\Rtdb\Portal;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$can_edit_invoice = user_can_edit_invoice();

if (isset($_GET['ajax_search'])) {
    $needle = (string) ($_GET['search'] ?? '');
    $rows = Portal::invoiceSearchRows($needle, 60);

    if (count($rows) > 0) {
        foreach ($rows as $row): ?>
            <tr>
                <td class="text-secondary small"><?= date('d/m/Y', strtotime($row['issue_date'])); ?></td>
                <td><?php
                    $hasTaxInv = !empty($row['has_tax_invoice']);
                    $invBadgeClass = $hasTaxInv
                        ? 'badge rounded-pill inv-badge-tax-issued px-3'
                        : 'badge rounded-pill inv-badge-tax-pending px-3';
                    $invBadgeTitle = $hasTaxInv ? 'ออกใบกำกับภาษีแล้ว' : 'ยังไม่ออกใบกำกับภาษี';
                    ?>
                    <span class="<?= htmlspecialchars($invBadgeClass, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($invBadgeTitle, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($row['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                </td>
                <td class="fw-semibold">
                    <div class="d-flex align-items-center gap-2">
                        <?php
                        $custLogo = isset($row['customer_logo']) ? trim((string) $row['customer_logo']) : '';
                        if ($custLogo !== ''):
                        ?>
                            <img src="<?= htmlspecialchars(upload_logo_url($custLogo)) ?>" alt="" class="cust-logo-thumb rounded border bg-light flex-shrink-0">
                        <?php else: ?>
                            <div class="cust-logo-thumb rounded border bg-light flex-shrink-0 d-flex align-items-center justify-content-center text-muted"><i class="bi bi-person"></i></div>
                        <?php endif; ?>
                        <span class="text-break"><?= htmlspecialchars($row['customer_name'] ?? ''); ?></span>
                    </div>
                </td>
                <td class="fw-bold text-dark">
                    ฿<?= number_format($row['net_pay'], 2); ?>
                </td>
                <td class="small text-secondary">
                    <?php
                    $cn = trim((string)($row['creator_name'] ?? ''));
                    echo $cn !== '' ? htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>';
                    ?>
                </td>
                <td class="text-end pe-4">
                    <div class="btn-group shadow-sm rounded-3">
                        <a href="<?= htmlspecialchars(app_path('pages/invoice-view.php')) ?>?id=<?= $row['id']; ?>" class="btn btn-sm btn-white border text-warning" title="ดูใบแจ้งหนี้"><i class="bi bi-eye-fill"></i></a>
                        
                        <a href="<?= htmlspecialchars(app_path('pages/tax-invoice-receipt.php')) ?>?id=<?= $row['id']; ?>" class="btn btn-sm btn-white border text-success" title="ใบกำกับภาษี/ใบเสร็จ"><i class="bi bi-file-earmark-check-fill"></i></a>
                        
                        <?php if ($can_edit_invoice): ?>
                            <a href="<?= htmlspecialchars(app_path('pages/invoice-edit.php')) ?>?id=<?= $row['id']; ?>" class="btn btn-sm btn-white border text-secondary" title="แก้ไข"><i class="bi bi-pencil-square"></i></a>
                        <?php endif; ?>
                        <?php if ($is_admin): ?>
                            <button onclick="deleteItem(<?= $row['id']; ?>, 'invoice')" class="btn btn-sm btn-white border text-danger" title="ลบ"><i class="bi bi-trash3-fill"></i></button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach;
    } else {
        echo "<tr><td colspan='6' class='text-center py-5 text-muted'>ไม่พบข้อมูลใบแจ้งหนี้ที่ค้นหา</td></tr>";
    }
    exit;
}

$stats = Portal::invoiceSummary();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TNC | OFFICE SYSTEM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Sarabun', sans-serif; background-color: #fffaf5; }
        .bg-orange-gradient { background: linear-gradient(135deg, #fd7e14 0%, #ff922b 100%); }
        .btn-orange { background-color: #fd7e14; color: white; border: none; }
        .btn-orange:hover { background-color: #e8590c; color: white; }
        .nav-link { font-weight: 500; transition: 0.3s; }
        .nav-link:hover { opacity: 0.8; transform: translateY(-1px); }
        .card-stats { border-left: 5px solid #fd7e14; transition: transform 0.2s; }
        .card-stats:hover { transform: translateY(-5px); }
        .btn-white:hover { background-color: #f8f9fa; }
        .cust-logo-thumb { width: 40px; height: 40px; object-fit: contain; }
        .home-menu-hub .home-hub-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border: 1px solid rgba(253, 126, 20, 0.12) !important;
        }
        .home-menu-hub .home-hub-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 0.65rem 1.25rem rgba(0, 0, 0, 0.07) !important;
        }
        .home-menu-hub .home-hub-head {
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }
        .home-menu-hub .home-hub-ico {
            width: 2.5rem;
            height: 2.5rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.65rem;
            font-size: 1.25rem;
        }
        .home-menu-hub .home-hub-link {
            font-weight: 500;
            border-radius: 0.55rem;
            padding: 0.55rem 0.85rem;
            color: #343a40;
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.06);
            text-decoration: none;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }
        .home-menu-hub .home-hub-link:hover {
            background: #fffaf5;
            border-color: rgba(253, 126, 20, 0.35);
            color: #c2410c;
        }
        .home-menu-hub .home-hub-link i { opacity: 0.85; }
        /* หน้าแรก: บนมือถือให้ส่วนใบแจ้งหนี้อยู่ก่อนเมนูระบบ */
        .index-page-wrap { display: flex; flex-direction: column; }
        /* เลขที่ใบแจ้งหนี้: สถานะใบกำกับภาษี */
        .inv-badge-tax-pending {
            background-color: rgba(220, 53, 69, 0.14);
            color: #b02a37;
            border: 1px solid rgba(220, 53, 69, 0.4);
            font-weight: 600;
        }
        .inv-badge-tax-issued {
            background-color: rgba(25, 135, 84, 0.14);
            color: #0f5132;
            border: 1px solid rgba(25, 135, 84, 0.4);
            font-weight: 600;
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/components/navbar.php'; ?>

<div class="container pb-5 index-page-wrap">
    <div class="index-dashboard-block order-1 order-lg-2">
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card card-stats border-0 shadow-sm p-3 rounded-4">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 bg-warning-subtle text-warning p-3 rounded-4">
                        <i class="bi bi-file-earmark-text fs-3"></i>
                    </div>
                    <div class="ms-3">
                        <h6 class="text-muted mb-0">จำนวนใบแจ้งหนี้ทั้งหมด</h6>
                        <h3 class="fw-bold mb-0"><?= number_format($stats['total_count']) ?></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card card-stats border-0 shadow-sm p-3 rounded-4" style="border-left-color: #198754;">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 bg-success-subtle text-success p-3 rounded-4">
                        <i class="bi bi-wallet2 fs-3"></i>
                    </div>
                    <div class="ms-3 min-w-0">
                        <h6 class="text-muted mb-0 text-truncate" title="ผลรวมยอดสุทธิจากใบแจ้งหนี้ทั้งหมดในระบบ">ยอดสุทธิรวม <span class="fw-normal small d-none d-sm-inline">(ใบแจ้งหนี้ในระบบ)</span></h6>
                        <h3 class="fw-bold mb-0 text-success">฿ <?= number_format($stats['final_net_sum'] ?? 0, 2) ?></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-white border-0 py-4 px-3 px-md-4">
            <div class="row align-items-stretch align-items-md-center g-3">
                <div class="col-12 col-md-4 col-lg-5">
                    <h5 class="fw-bold mb-0 text-dark">รายการใบแจ้งหนี้</h5>
                    <p class="small text-muted mb-0 mt-1">เลขที่: <span class="text-danger fw-semibold">แดง</span> = ยังไม่ออกใบกำกับ · <span class="text-success fw-semibold">เขียว</span> = ออกแล้ว</p>
                    <p class="small text-muted mb-0 mt-1 d-md-none">ค้นหาเลขที่หรือชื่อลูกค้า</p>
                </div>
                <div class="col-12 col-md-8 col-lg-7">
                    <div class="d-flex flex-column flex-sm-row gap-2 align-items-stretch justify-content-md-end">
                        <div class="position-relative flex-grow-1" style="max-width: 100%;">
                            <label class="visually-hidden" for="search_invoice">ค้นหาใบแจ้งหนี้</label>
                            <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted" aria-hidden="true"></i>
                            <input type="search" id="search_invoice" autocomplete="off" class="form-control ps-5 rounded-pill border-light-subtle bg-light" placeholder="เลขที่ใบแจ้งหนี้หรือชื่อลูกค้า">
                        </div>
                        <a href="<?= htmlspecialchars(app_path('pages/invoice-create.php')) ?>" class="btn btn-orange rounded-pill px-4 shadow-sm flex-shrink-0 text-center">
                            <i class="bi bi-plus-lg me-1"></i>สร้างบิลใหม่
                        </a>
                        <a href="<?= htmlspecialchars(app_path('pages/tax-invoice-list.php')) ?>" class="btn btn-outline-success rounded-pill px-4 shadow-sm flex-shrink-0 text-center">
                            <i class="bi bi-file-earmark-break me-1"></i>ใบกำกับภาษี
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table id="invoice_table" class="table table-hover align-middle mb-0" aria-busy="false">
                <thead class="table-light border-bottom">
                    <tr>
                        <th class="ps-4 py-3">วันที่</th>
                        <th class="py-3">เลขที่ใบแจ้งหนี้</th>
                        <th class="py-3">ลูกค้า</th>
                        <th class="py-3">ยอดเงิน</th>
                        <th class="py-3">ผู้ออกใบ</th>
                        <th class="text-end pe-4 py-3">จัดการ</th>
                    </tr>
                </thead>
                <tbody id="invoice_table_body">
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <span class="spinner-border spinner-border-sm text-warning me-2" role="status" aria-hidden="true"></span>
                            <span class="align-middle">กำลังโหลดรายการใบแจ้งหนี้…</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    </div>

    <section class="home-menu-hub mb-4 order-2 order-lg-1" aria-label="เมนูระบบ">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
            <div>
                <h4 class="fw-bold text-dark mb-1">เมนูระบบ</h4>
            </div>
        </div>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
            <div class="col">
                <div class="card home-hub-card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                    <div class="home-hub-head px-3 py-3 d-flex align-items-center gap-3 bg-warning-subtle">
                        <span class="home-hub-ico bg-white text-warning shadow-sm"><i class="bi bi-folder2" aria-hidden="true"></i></span>
                        <span class="fw-semibold text-dark">ข้อมูลหลัก</span>
                    </div>
                    <div class="card-body p-3 d-grid gap-2">
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/customer-manage.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-people me-2 text-secondary"></i>ลูกค้า</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/company-manage.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-building me-2 text-secondary"></i>บริษัท</a>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card home-hub-card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                    <div class="home-hub-head px-3 py-3 d-flex align-items-center gap-3" style="background: rgba(13, 110, 253, 0.09);">
                        <span class="home-hub-ico bg-white text-primary shadow-sm"><i class="bi bi-cart3" aria-hidden="true"></i></span>
                        <span class="fw-semibold text-dark">ซื้อ / คลัง</span>
                    </div>
                    <div class="card-body p-3 d-grid gap-2">
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/supplier-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-truck me-2 text-secondary"></i>ผู้ขาย</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-cart-plus me-2 text-secondary"></i>ใบขอซื้อ (PR)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/purchase-order-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-bag-check me-2 text-secondary"></i>ใบสั่งซื้อ (PO)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/stock-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-box-seam me-2 text-secondary"></i>คลังสินค้า</a>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card home-hub-card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                    <div class="home-hub-head px-3 py-3 d-flex align-items-center gap-3 bg-success-subtle">
                        <span class="home-hub-ico bg-white text-success shadow-sm"><i class="bi bi-cash-stack" aria-hidden="true"></i></span>
                        <span class="fw-semibold text-dark">รับ — จ่าย</span>
                    </div>
                    <div class="card-body p-3 d-grid gap-2">
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/cash-ledger-dashboard.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-speedometer2 me-2 text-secondary"></i>บันทึกรายการ</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/cash-ledger-master-stores.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-shop me-2 text-secondary"></i>เพิ่มร้านค้า</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/cash-ledger-master-sites.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-geo-alt me-2 text-secondary"></i>เพิ่มไซต์งาน</a>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card home-hub-card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                    <div class="home-hub-head px-3 py-3 d-flex align-items-center gap-3" style="background: rgba(111, 66, 193, 0.1);">
                        <span class="home-hub-ico bg-white shadow-sm" style="color: #6f42c1;"><i class="bi bi-person-workspace" aria-hidden="true"></i></span>
                        <span class="fw-semibold text-dark">ค่าแรง</span>
                    </div>
                    <div class="card-body p-3 d-grid gap-2">
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/labor-payroll.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-calculator me-2 text-secondary"></i>บัตรค่าแรงคนงาน</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/labor-payroll-history.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-archive me-2 text-secondary"></i>ประวัติรายการ</a>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card home-hub-card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                    <div class="home-hub-head px-3 py-3 d-flex align-items-center gap-3 bg-secondary-subtle">
                        <span class="home-hub-ico bg-white text-secondary shadow-sm"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span>
                        <span class="fw-semibold text-dark">เสนอราคา</span>
                    </div>
                    <div class="card-body p-3 d-grid gap-2">
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/quotation-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-ui-checks me-2 text-secondary"></i>ใบเสนอราคา</a>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card home-hub-card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                    <div class="home-hub-head px-3 py-3 d-flex align-items-center gap-3" style="background: rgba(92, 77, 51, 0.1);">
                        <span class="home-hub-ico bg-white shadow-sm" style="color: #5c4d33;"><i class="bi bi-tools" aria-hidden="true"></i></span>
                        <span class="fw-semibold text-dark">เครื่องมือทั่วไป</span>
                    </div>
                    <div class="card-body p-3 d-grid gap-2">
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/cement-volume-calculator.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-boxes me-2 text-secondary"></i>คำนวณปริมาตรปูน (คิว)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/daily-site-report-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-journal-text me-2 text-secondary"></i>สมุดรายวันหน้างาน (DSR)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/leave-request-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-calendar-check me-2 text-secondary"></i>ใบลา</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const actionHandlerUrl = <?= json_encode(app_path('actions/action-handler.php'), JSON_UNESCAPED_SLASHES) ?>;
const csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

const loadingRowHtml = '<tr><td colspan="6" class="text-center py-5 text-muted">' +
    '<span class="spinner-border spinner-border-sm text-warning me-2" role="status" aria-hidden="true"></span>' +
    '<span class="align-middle">กำลังโหลดรายการใบแจ้งหนี้…</span></td></tr>';
const errorRowHtml = '<tr><td colspan="6" class="text-center py-5 text-danger">' +
    'โหลดข้อมูลไม่สำเร็จ — ลองโหลดหน้าใหม่หรือตรวจสอบการเชื่อมต่อ</td></tr>';

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
    const normalized = (query || '').trim();
    if (normalized.length === 1) {
        if (tableBody) {
            tableBody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">พิมพ์เพิ่มอีกอย่างน้อย 1 ตัวอักษรเพื่อค้นหา</td></tr>';
        }
        if (table) {
            table.setAttribute('aria-busy', 'false');
        }
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

function deleteItem(id, type) {
    Swal.fire({
        title: 'ยืนยันการลบ?',
        text: "ข้อมูลจะถูกลบถาวร ไม่สามารถย้อนกลับได้",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#fd7e14',
        cancelButtonColor: '#adb5bd',
        confirmButtonText: 'ยืนยัน ลบข้อมูล',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `${actionHandlerUrl}?action=delete&type=${type}&id=${id}&_csrf=${encodeURIComponent(csrfToken)}`;
        }
    });
}

window.onload = () => {
    var si = document.getElementById('search_invoice');
    loadTable(si ? si.value : '');
    const params = new URLSearchParams(window.location.search);
    if (params.get('invoice_updated') === '1') {
        Swal.fire({
            icon: 'success',
            title: 'อัปเดตสำเร็จ',
            text: 'บันทึกการแก้ไขใบแจ้งหนี้เรียบร้อยแล้ว',
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#fd7e14'
        }).then(() => {
            const u = new URL(window.location.href);
            u.searchParams.delete('invoice_updated');
            const q = u.searchParams.toString();
            history.replaceState({}, '', u.pathname + (q ? '?' + q : '') + u.hash);
        });
    }
};
</script>
</body>
</html>