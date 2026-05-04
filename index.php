<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/connect_database.php';

use Theelincon\Rtdb\Portal;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$is_admin = user_is_admin_role();
$is_admin_only = user_is_admin_only_role();
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
                        <button type="button" class="btn btn-sm btn-white border text-warning" data-tnc-invoice="view" data-invoice-id="<?= (int) $row['id']; ?>" title="ดูใบแจ้งหนี้"><i class="bi bi-eye-fill"></i></button>
                        
                        <a href="<?= htmlspecialchars(app_path('pages/invoices/tax-invoice-receipt.php')) ?>?id=<?= $row['id']; ?>" class="btn btn-sm btn-white border text-success" title="ใบกำกับภาษี/ใบเสร็จ"><i class="bi bi-file-earmark-check-fill"></i></a>
                        
                        <?php if ($can_edit_invoice): ?>
                            <button type="button" class="btn btn-sm btn-white border text-secondary" data-tnc-invoice="edit" data-invoice-id="<?= (int) $row['id']; ?>" title="แก้ไข"><i class="bi bi-pencil-square"></i></button>
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

/** เมนูหมวดซ้ายหน้าแรก: true = ปิดทุกหมวดตอนโหลดเสมอ, false = เปิดหมวด «ข้อมูลหลัก» ไว้ */
$index_hub_start_all_collapsed = true;
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
        .home-menu-hub-single .home-hub-section + .home-hub-section {
            border-top: 1px solid rgba(0, 0, 0, 0.06);
        }
        .home-menu-hub-single .home-hub-toggle {
            width: 100%;
            border: 0;
            background: transparent;
            text-align: left;
            padding: 0.85rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font: inherit;
            color: inherit;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .home-menu-hub-single .home-hub-toggle:hover,
        .home-menu-hub-single .home-hub-toggle:focus-visible {
            background: rgba(253, 126, 20, 0.06);
            outline: none;
        }
        .home-menu-hub-single .home-hub-toggle .home-hub-chevron {
            margin-left: auto;
            font-size: 1.1rem;
            opacity: 0.65;
            transition: transform 0.2s ease;
        }
        .home-menu-hub-single .home-hub-toggle:not(.collapsed) .home-hub-chevron {
            transform: rotate(180deg);
        }
        .home-menu-hub-single .home-hub-panel {
            padding: 0 1rem 1rem 1rem;
        }
        .home-menu-hub-single .home-hub-panel-inner {
            display: grid;
            gap: 0.5rem;
            padding-left: 0.25rem;
        }
        /* หน้าแรก: จำกัดความกว้างให้อยู่กึ่งกลาง ไม่ยืดเต็มจอใหญ่ */
        .index-page-wrap {
            max-width: 1540px;
        }
        /* หน้าแรก: เมนูซ้าย (จอใหญ่) · มือถือแสดงเนื้อหาหลักก่อน */
        .index-layout-row { align-items: flex-start; }
        .index-sidebar-wrap {
            position: relative;
        }
        .index-main-col {
            min-width: 0;
        }
        @media (min-width: 992px) {
            .index-sidebar-wrap {
                flex: 0 0 320px;
                max-width: 320px;
            }
            .index-main-col {
                flex: 1 1 auto;
                max-width: calc(100% - 320px);
            }
            .index-sidebar-sticky {
                position: sticky;
                top: 1rem;
                max-height: calc(100vh - 1.25rem);
            }
            .index-sidebar-scroll {
                max-height: calc(100vh - 1.25rem);
                overflow-y: auto;
                overflow-x: hidden;
                padding-right: 0.2rem;
                scrollbar-width: thin;
                scrollbar-color: rgba(253, 126, 20, 0.45) transparent;
            }
            .index-sidebar-scroll::-webkit-scrollbar { width: 6px; }
            .index-sidebar-scroll::-webkit-scrollbar-thumb {
                background: rgba(253, 126, 20, 0.35);
                border-radius: 999px;
            }
        }
        .home-menu-hub.index-sidebar .index-sidebar-scroll {
            padding-bottom: 0.5rem;
        }
        .index-sidebar-card {
            border: 1px solid rgba(253, 126, 20, 0.18) !important;
            box-shadow: 0 0.35rem 1.1rem rgba(0, 0, 0, 0.06) !important;
            border-left: 4px solid #fd7e14 !important;
            background: #fff;
        }
        .home-menu-hub.index-sidebar .home-menu-hub-single {
            border-radius: 0 0 1rem 1rem !important;
            border: 0 !important;
            box-shadow: none !important;
        }
        .home-menu-hub.index-sidebar .home-hub-toggle {
            padding: 0.7rem 0.9rem;
            font-size: 0.95rem;
        }
        .home-menu-hub.index-sidebar .home-hub-ico {
            width: 2.15rem;
            height: 2.15rem;
            font-size: 1.05rem;
        }
        .home-menu-hub.index-sidebar .home-hub-link {
            padding: 0.45rem 0.7rem;
            font-size: 0.92rem;
        }
        .home-menu-hub.index-sidebar .home-hub-panel {
            padding: 0 0.85rem 0.75rem;
        }
        @media (min-width: 1200px) {
            .index-sidebar-wrap {
                flex-basis: 340px;
                max-width: 340px;
            }
            .index-main-col {
                max-width: calc(100% - 340px);
            }
            .home-menu-hub.index-sidebar .home-hub-toggle {
                padding: 0.58rem 0.5rem;
                font-size: 0.88rem;
                gap: 0.45rem;
            }
            .home-menu-hub.index-sidebar .home-hub-toggle > .fw-semibold {
                min-width: 0;
                line-height: 1.3;
            }
            .home-menu-hub.index-sidebar .home-hub-ico {
                width: 1.85rem;
                height: 1.85rem;
                font-size: 0.92rem;
            }
            .home-menu-hub.index-sidebar .home-hub-link {
                padding: 0.38rem 0.55rem;
                font-size: 0.86rem;
            }
            .home-menu-hub.index-sidebar .home-hub-panel {
                padding: 0 0.55rem 0.6rem;
            }
            .home-menu-hub.index-sidebar .home-hub-chevron {
                font-size: 0.9rem;
            }
        }
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
    <div class="row g-4 index-layout-row">
    <aside class="col-lg-4 col-xl-3 index-sidebar-wrap order-2 order-lg-1" aria-label="เมนูนำทางระบบ">
        <div class="index-sidebar-sticky">
            <div class="card index-sidebar-card rounded-4 overflow-hidden mb-0">
                <section class="home-menu-hub index-sidebar mb-0" aria-label="เมนูระบบ">
                    <div class="index-sidebar-scroll">
                    <div class="card home-menu-hub-single home-hub-card border-0 shadow-none rounded-0 overflow-hidden">
            <div class="home-hub-section">
                <button type="button" class="home-hub-toggle<?= $index_hub_start_all_collapsed ? ' collapsed' : '' ?>" data-bs-toggle="collapse" data-bs-target="#hub-collapse-master" aria-expanded="<?= $index_hub_start_all_collapsed ? 'false' : 'true' ?>" aria-controls="hub-collapse-master" id="hub-toggle-master">
                    <span class="home-hub-ico bg-warning-subtle text-warning shadow-sm flex-shrink-0"><i class="bi bi-folder2" aria-hidden="true"></i></span>
                    <span class="fw-semibold text-dark">ข้อมูลหลัก (Information Data)</span>
                    <i class="bi bi-chevron-down home-hub-chevron" aria-hidden="true"></i>
                </button>
                <div id="hub-collapse-master" class="collapse<?= $index_hub_start_all_collapsed ? '' : ' show' ?> home-hub-panel" aria-labelledby="hub-toggle-master">
                    <div class="home-hub-panel-inner pb-1">
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/organization/customer-manage.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-people me-2 text-secondary"></i>ลูกค้า (Customer)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/organization/company-manage.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-building me-2 text-secondary"></i>บริษัท (Company)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/organization/sites.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-geo-alt me-2 text-secondary"></i>ไซต์งาน (Sites)</a>
                        <a class="home-hub-link d-flex align-items-center js-hub-member-manage<?= $is_admin_only ? '' : ' text-muted' ?>" href="<?= htmlspecialchars(app_path('pages/organization/member-manage.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-person-gear me-2 text-secondary"></i>จัดการสมาชิก (Members)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/suppliers/supplier-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-truck me-2 text-secondary"></i>ผู้ขาย (Suppliers)</a>
                    </div>
                </div>
            </div>
            <div class="home-hub-section">
                <button type="button" class="home-hub-toggle collapsed" data-bs-toggle="collapse" data-bs-target="#hub-collapse-purchase" aria-expanded="false" aria-controls="hub-collapse-purchase" id="hub-toggle-purchase">
                    <span class="home-hub-ico bg-primary-subtle text-primary shadow-sm flex-shrink-0"><i class="bi bi-cart3" aria-hidden="true"></i></span>
                    <span class="fw-semibold text-dark">จัดซื้อ / จัดจ้าง (Purchase / Hire)</span>
                    <i class="bi bi-chevron-down home-hub-chevron" aria-hidden="true"></i>
                </button>
                <div id="hub-collapse-purchase" class="collapse home-hub-panel" aria-labelledby="hub-toggle-purchase">
                    <div class="home-hub-panel-inner pb-1">
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-cart-plus me-2 text-secondary"></i>ใบขอซื้อ (Purchase Request)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-bag-check me-2 text-secondary"></i>ใบสั่งซื้อ (Purchase Order)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/quotations/quotation-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-ui-checks me-2 text-secondary"></i>ใบเสนอราคา (Quotation)</a>
                    </div>
                </div>
            </div>
            <div class="home-hub-section">
                <button type="button" class="home-hub-toggle collapsed" data-bs-toggle="collapse" data-bs-target="#hub-collapse-docs" aria-expanded="false" aria-controls="hub-collapse-docs" id="hub-toggle-docs">
                    <span class="home-hub-ico bg-info-subtle text-info shadow-sm flex-shrink-0"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span>
                    <span class="fw-semibold text-dark">ระบบเอกสาร (Documents)</span>
                    <i class="bi bi-chevron-down home-hub-chevron" aria-hidden="true"></i>
                </button>
                <div id="hub-collapse-docs" class="collapse home-hub-panel" aria-labelledby="hub-toggle-docs">
                    <div class="home-hub-panel-inner pb-1">
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/hire-contracts/hire-contract-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-file-earmark-ruled me-2 text-secondary"></i>สัญญาจ้าง (Hire Contract)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/labor-payroll/labor-payroll.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-calculator me-2 text-secondary"></i>คำนวณค่าแรง (Wage)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/stock/stock-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-box-seam me-2 text-secondary"></i>คลังสินค้า (Stock)</a>
                        <?php if ($is_admin): ?>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/tools/employment-certificate.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-file-earmark-medical me-2 text-secondary"></i>หนังสือรับรองการทำงาน (Employment Certificate)</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="home-hub-section">
                <button type="button" class="home-hub-toggle collapsed" data-bs-toggle="collapse" data-bs-target="#hub-collapse-cash" aria-expanded="false" aria-controls="hub-collapse-cash" id="hub-toggle-cash">
                    <span class="home-hub-ico bg-success-subtle text-success shadow-sm flex-shrink-0"><i class="bi bi-cash-stack" aria-hidden="true"></i></span>
                    <span class="fw-semibold text-dark">ระบบการเงิน (Cash)</span>
                    <i class="bi bi-chevron-down home-hub-chevron" aria-hidden="true"></i>
                </button>
                <div id="hub-collapse-cash" class="collapse home-hub-panel" aria-labelledby="hub-toggle-cash">
                    <div class="home-hub-panel-inner pb-1">
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/purchase/purchase-bill.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-receipt-cutoff me-2 text-secondary"></i>บันทึกบิลซื้อ (Purchase Bill)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/purchase/purchase-need-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-card-checklist me-2 text-secondary"></i>ใบต้องการซื้อ (Purchase Need)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/cash-ledger/cash-ledger.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-speedometer2 me-2 text-secondary"></i>สดย่อย (Petty Cash)</a>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    <main class="col-lg-8 col-xl-9 index-main-col order-1 order-lg-2 min-w-0" id="main-content">
    <div class="index-dashboard-block">
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

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-0">
        <div class="card-header bg-white border-0 py-4 px-3 px-md-4">
            <div class="row align-items-stretch align-items-md-center g-3">
                <div class="col-12 col-md-4 col-lg-5">
                    <h1 class="h5 fw-bold mb-0 text-dark">รายการใบแจ้งหนี้</h1>
                    <p class="small text-muted mb-0 mt-1 d-md-none">ค้นหาเลขที่หรือชื่อลูกค้า</p>
                </div>
                <div class="col-12 col-md-8 col-lg-7">
                    <div class="d-flex flex-column flex-sm-row gap-2 align-items-stretch justify-content-md-end">
                        <div class="position-relative flex-grow-1" style="max-width: 100%;">
                            <label class="visually-hidden" for="search_invoice">ค้นหาใบแจ้งหนี้</label>
                            <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted" aria-hidden="true"></i>
                            <input type="search" id="search_invoice" autocomplete="off" class="form-control ps-5 rounded-pill border-light-subtle bg-light" placeholder="เลขที่ใบแจ้งหนี้หรือชื่อลูกค้า">
                        </div>
                        <a href="<?= htmlspecialchars(app_path('pages/invoices/invoice.php')) ?>?action=create" class="btn btn-orange rounded-pill px-4 shadow-sm flex-shrink-0 text-center">
                            <i class="bi bi-plus-lg me-1"></i>สร้างบิลใหม่
                        </a>
                        <a href="<?= htmlspecialchars(app_path('pages/invoices/tax-invoice-list.php')) ?>" class="btn btn-outline-success rounded-pill px-4 shadow-sm flex-shrink-0 text-center">
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

    <div class="modal fade" id="tncInvoiceModal" tabindex="-1" aria-labelledby="tncInvoiceModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content bg-light" style="min-height: 100vh;">
                <div class="modal-header py-2 bg-dark text-white align-items-center">
                    <h6 class="modal-title fw-semibold mb-0" id="tncInvoiceModalTitle">ใบแจ้งหนี้</h6>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-warning btn-sm fw-bold" id="tncInvoiceModalPrint">พิมพ์</button>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="ปิด"></button>
                    </div>
                </div>
                <div class="modal-body p-0 flex-grow-1" style="min-height: calc(100vh - 52px);">
                    <iframe id="tncInvoiceModalFrame" class="w-100 border-0 d-block" style="min-height: calc(100vh - 52px); height: calc(100vh - 52px);" title="Invoice"></iframe>
                </div>
            </div>
        </div>
    </div>
    </main>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/includes/datatables_bundle.php'; ?>
<script>
const actionHandlerUrl = <?= json_encode(app_path('actions/action-handler.php'), JSON_UNESCAPED_SLASHES) ?>;
const invoicePhpUrl = <?= json_encode(app_path('pages/invoices/invoice.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
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
        confirmButtonColor: '#fd7e14'
    });
});

const loadingRowHtml = '<tr><td colspan="6" class="text-center py-5 text-muted">' +
    '<span class="spinner-border spinner-border-sm text-warning me-2" role="status" aria-hidden="true"></span>' +
    '<span class="align-middle">กำลังโหลดรายการใบแจ้งหนี้…</span></td></tr>';
const errorRowHtml = '<tr><td colspan="6" class="text-center py-5 text-danger">' +
    'โหลดข้อมูลไม่สำเร็จ — ลองโหลดหน้าใหม่หรือตรวจสอบการเชื่อมต่อ</td></tr>';

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
        order: [],
        columnDefs: [{ orderable: false, targets: [0, 5] }]
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
            tableBody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">พิมพ์เพิ่มอีกอย่างน้อย 1 ตัวอักษรเพื่อค้นหา</td></tr>';
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

let tncInvoiceModalInstance = null;
function tncOpenInvoiceModal(action, id) {
    const frame = document.getElementById('tncInvoiceModalFrame');
    const titleEl = document.getElementById('tncInvoiceModalTitle');
    const modalEl = document.getElementById('tncInvoiceModal');
    if (!frame || !modalEl || !window.bootstrap || !window.bootstrap.Modal) {
        return;
    }
    const u = invoicePhpUrl + '?action=' + encodeURIComponent(action) + '&id=' + encodeURIComponent(String(id)) + '&embed=1';
    frame.src = u;
    if (titleEl) {
        titleEl.textContent = action === 'edit' ? 'แก้ไขใบแจ้งหนี้' : 'ดูใบแจ้งหนี้';
    }
    if (!tncInvoiceModalInstance) {
        tncInvoiceModalInstance = new bootstrap.Modal(modalEl);
    }
    tncInvoiceModalInstance.show();
}

document.getElementById('tncInvoiceModalPrint')?.addEventListener('click', function () {
    const frame = document.getElementById('tncInvoiceModalFrame');
    try {
        if (frame && frame.contentWindow) {
            frame.contentWindow.focus();
            frame.contentWindow.print();
        }
    } catch (e) {}
});

document.getElementById('tncInvoiceModal')?.addEventListener('hidden.bs.modal', function () {
    const frame = document.getElementById('tncInvoiceModalFrame');
    if (frame) {
        frame.src = 'about:blank';
    }
});

document.getElementById('invoice_table_body')?.addEventListener('click', function (ev) {
    const btn = ev.target.closest('[data-tnc-invoice]');
    if (!btn) {
        return;
    }
    const act = btn.getAttribute('data-tnc-invoice');
    const iid = btn.getAttribute('data-invoice-id');
    if (act && iid) {
        tncOpenInvoiceModal(act, iid);
    }
});

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