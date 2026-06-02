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
$can_edit_invoice = user_can('invoice.edit');
$can_delete_invoice = user_can('invoice.delete');
$is_finance_hub = user_is_finance_role();

if (isset($_GET['ajax_search'])) {
    $needle = (string) ($_GET['search'] ?? '');
    $rows = Portal::invoiceSearchRows($needle, 60);

    if (count($rows) > 0) {
        foreach ($rows as $row): ?>
            <?php
            $issueRaw = trim((string) ($row['issue_date'] ?? ''));
            $issueTs = $issueRaw !== '' ? strtotime($issueRaw) : false;
            $dateDisplay = $issueTs !== false ? date('d/m/Y', $issueTs) : '—';
            $createdOrder = sprintf('%010d', (int) ($row['id'] ?? 0));
            ?>
            <tr>
                <td data-order="<?= htmlspecialchars($createdOrder, ENT_QUOTES, 'UTF-8') ?>"><?php
                    $hasTaxInv = !empty($row['has_tax_invoice']);
                    $invBadgeClass = $hasTaxInv
                        ? 'badge rounded-pill inv-badge-tax-issued px-3'
                        : 'badge rounded-pill inv-badge-tax-pending px-3';
                    $invBadgeTitle = $hasTaxInv ? 'ออกใบกำกับภาษีแล้ว' : 'ยังไม่ออกใบกำกับภาษี';
                    ?>
                    <?php
                    $invNoDisplay = (string) ($row['invoice_number'] ?? '');
                    ?>
                    <div><span class="<?= htmlspecialchars($invBadgeClass, ENT_QUOTES, 'UTF-8') ?> index-inv-no-copy" role="button" tabindex="0" data-invoice-copy="<?= htmlspecialchars($invNoDisplay, ENT_QUOTES, 'UTF-8') ?>" title="คลิกเพื่อคัดลอกเลขที่"><?= htmlspecialchars($invNoDisplay, ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="small text-muted mt-1"><?= htmlspecialchars($dateDisplay, ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td class="fw-semibold">
                    <div class="d-flex align-items-center gap-2">
                        <?php
                        $custLogo = isset($row['customer_logo']) ? trim((string) $row['customer_logo']) : '';
                        if ($custLogo !== ''):
                        ?>
                            <img src="<?= htmlspecialchars(upload_logo_url($custLogo)) ?>" alt="" class="cust-logo-thumb rounded border bg-light flex-shrink-0">
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
                        <?php if ($can_edit_invoice): ?>
                            <a href="<?= htmlspecialchars(app_path('pages/invoices/invoice.php'), ENT_QUOTES, 'UTF-8') ?>?action=edit&amp;id=<?= (int) $row['id']; ?>" class="btn btn-invoice-action btn-invoice-action-edit" title="แก้ไข"><i class="bi bi-pencil-square"></i></a>
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --tnc-orange: #ea580c;
            --tnc-orange-dark: #c2410c;
            --tnc-surface: #f6f7f9;
            --tnc-sidebar-bg: #eceef2;
            --tnc-radius: 0.875rem;
            --tnc-sidebar-item-hover: rgba(15, 23, 42, 0.055);
            --tnc-sidebar-item-active-bg: rgba(253, 126, 20, 0.09);
            --tnc-sidebar-active-bar: var(--tnc-orange);
        }
        body { font-family: 'Sarabun', sans-serif; background-color: var(--tnc-surface); }
        .bg-orange-gradient { background: linear-gradient(135deg, #ea580c 0%, #f97316 100%); }
        .btn-orange { background-color: var(--tnc-orange); color: white; border: none; }
        .btn-orange:hover { background-color: var(--tnc-orange-dark); color: white; }
        .nav-link { font-weight: 500; transition: 0.3s; }
        .nav-link:hover { opacity: 0.8; transform: translateY(-1px); }
        .card-stats {
            border-left: 4px solid #dee2e6;
            transition: transform 0.2s, box-shadow 0.2s;
            border-radius: var(--tnc-radius) !important;
        }
        .card-stats:hover { transform: translateY(-3px); box-shadow: 0 0.5rem 1.25rem rgba(0, 0, 0, 0.08) !important; }
        .card-stats .index-stat-value {
            font-size: clamp(1.75rem, 4vw, 2.35rem);
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1.15;
        }
        .card-stats .index-stat-label {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6c757d;
        }
        .btn-white:hover { background-color: #f8f9fa; }
        .cust-logo-thumb { width: 40px; height: 40px; object-fit: contain; }
        .home-menu-hub .home-hub-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border: 1px solid rgba(0, 0, 0, 0.06) !important;
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
            position: relative;
            font-weight: 500;
            border-radius: 0.5rem;
            padding: 0.78rem 1rem 0.78rem 1.05rem;
            color: #334155;
            background: transparent;
            border: none;
            text-decoration: none;
            transition: background-color 0.16s ease, color 0.16s ease;
        }
        .home-menu-hub .home-hub-link::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%) scaleY(0);
            width: 3px;
            height: 0;
            border-radius: 0 3px 3px 0;
            background: var(--tnc-sidebar-active-bar);
            opacity: 0;
            transition: transform 0.18s ease, height 0.18s ease, opacity 0.18s ease;
        }
        .home-menu-hub .home-hub-link:hover {
            background: var(--tnc-sidebar-item-hover);
            color: #0f172a;
        }
        .home-menu-hub .home-hub-link.active {
            background: var(--tnc-sidebar-item-active-bg);
            color: #9a3412;
            font-weight: 600;
        }
        .home-menu-hub .home-hub-link.active::before {
            transform: translateY(-50%) scaleY(1);
            height: 62%;
            min-height: 1.35rem;
            opacity: 1;
        }
        .home-menu-hub .home-hub-link.active i { color: var(--tnc-orange) !important; }
        .home-menu-hub .home-hub-link i { opacity: 0.88; }
        .home-menu-hub-single .home-hub-section + .home-hub-section {
            margin-top: 0.65rem;
            padding-top: 0.65rem;
            border-top: none;
        }
        .home-menu-hub-single .home-hub-toggle {
            position: relative;
            width: 100%;
            border: 0;
            background: transparent;
            text-align: left;
            padding: 0.95rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font: inherit;
            color: inherit;
            cursor: pointer;
            border-radius: 0.5rem;
            transition: background-color 0.16s ease;
        }
        .home-menu-hub-single .home-hub-toggle:hover,
        .home-menu-hub-single .home-hub-toggle:focus-visible {
            background: var(--tnc-sidebar-item-hover);
            outline: none;
        }
        .home-menu-hub-single .home-hub-toggle.has-active-child {
            background: var(--tnc-sidebar-item-active-bg);
        }
        .home-menu-hub-single .home-hub-toggle.has-active-child::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 52%;
            min-height: 1.2rem;
            border-radius: 0 3px 3px 0;
            background: var(--tnc-sidebar-active-bar);
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
            padding: 0.35rem 0.75rem 1rem 0.75rem;
        }
        .home-menu-hub-single .home-hub-panel-inner {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
            padding-left: 0.15rem;
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
                background: rgba(0, 0, 0, 0.2);
                border-radius: 999px;
            }
        }
        .home-menu-hub.index-sidebar .index-sidebar-scroll {
            padding-bottom: 0.5rem;
        }
        .index-sidebar-card {
            border: 1px solid rgba(0, 0, 0, 0.06) !important;
            box-shadow: 0 0.22rem 0.8rem rgba(0, 0, 0, 0.045) !important;
            background: rgba(255, 255, 255, 0.68) !important;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border-radius: var(--tnc-radius) !important;
        }
        .home-menu-hub.index-sidebar .home-menu-hub-single {
            border-radius: 0 0 1rem 1rem !important;
            border: 0 !important;
            box-shadow: none !important;
        }
        .home-menu-hub.index-sidebar .home-hub-toggle {
            padding: 0.88rem 1rem;
            font-size: 0.95rem;
        }
        .home-menu-hub.index-sidebar .home-hub-ico {
            width: 2.15rem;
            height: 2.15rem;
            font-size: 1.05rem;
            background: rgba(255, 255, 255, 0.72) !important;
        }
        .home-menu-hub.index-sidebar .home-hub-link {
            padding: 0.72rem 1rem 0.72rem 1.05rem;
            font-size: 0.92rem;
            line-height: 1.4;
        }
        .home-menu-hub.index-sidebar .home-hub-toggle > .fw-semibold,
        .home-menu-hub.index-sidebar .home-hub-link {
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .home-menu-hub.index-sidebar .home-hub-panel {
            padding: 0.25rem 0.65rem 0.85rem;
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
                padding: 0.78rem 0.85rem;
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
                padding: 0.65rem 0.85rem 0.65rem 0.95rem;
                font-size: 0.86rem;
            }
            .home-menu-hub.index-sidebar .home-hub-panel {
                padding: 0.2rem 0.5rem 0.65rem;
            }
            .home-menu-hub.index-sidebar .home-hub-chevron {
                font-size: 0.9rem;
            }
        }
        /* เลขที่ใบแจ้งหนี้: สถานะใบกำกับภาษี */
        .inv-badge-tax-pending {
            background-color: rgba(255, 193, 7, 0.22);
            color: #856404;
            border: 1px solid rgba(255, 193, 7, 0.55);
            font-weight: 600;
        }
        .inv-badge-tax-issued {
            background-color: rgba(25, 135, 84, 0.14);
            color: #0f5132;
            border: 1px solid rgba(25, 135, 84, 0.4);
            font-weight: 600;
        }
        .index-inv-legend {
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            gap: 0.75rem;
            padding: 0.55rem 1rem;
            font-size: 0.8125rem;
            color: #64748b;
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
            border-top: 1px solid #e8edf3;
            overflow-x: auto;
        }
        @media (min-width: 768px) {
            .index-inv-legend {
                padding-left: 1.5rem;
                padding-right: 1.5rem;
            }
        }
        .index-inv-legend__item {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            white-space: nowrap;
        }
        .index-inv-legend__swatch {
            display: inline-block;
            width: 0.72rem;
            height: 0.72rem;
            border-radius: 999px;
            flex-shrink: 0;
        }
        .index-inv-legend__swatch--pending {
            background-color: rgba(255, 193, 7, 0.55);
            border: 1px solid rgba(255, 193, 7, 0.75);
        }
        .index-inv-legend__swatch--issued {
            background-color: rgba(25, 135, 84, 0.45);
            border: 1px solid rgba(25, 135, 84, 0.55);
        }
        .index-inv-legend__sep {
            color: #cbd5e1;
            user-select: none;
        }
        .index-inv-no-copy {
            cursor: pointer;
            user-select: none;
            transition: filter 0.15s ease, transform 0.12s ease;
        }
        .index-inv-no-copy:hover {
            filter: brightness(0.92);
        }
        .index-inv-no-copy:active {
            transform: scale(0.98);
        }
        .index-inv-no-copy:focus-visible {
            outline: 2px solid rgba(253, 126, 20, 0.65);
            outline-offset: 2px;
        }
        /* Invoice table — roomier rows, single search only (no DataTables filter UI) */
        .index-table-card > .card-header {
            border-bottom: 1px solid #e8edf3 !important;
        }
        #invoice_table.table-invoice-index thead,
        #invoice_table.table-invoice-index.dataTable thead {
            border-top: none !important;
        }
        #invoice_table.table-invoice-index thead th,
        #invoice_table.table-invoice-index.dataTable thead th {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6c757d;
            padding: 1rem 1.25rem;
            border-top: none !important;
            border-bottom: 1px solid #e8edf3 !important;
        }
        #invoice_table.table-invoice-index tbody td {
            padding: 1.1rem 1.25rem;
            vertical-align: middle;
        }
        #invoice_table.table-invoice-index tbody tr:last-child td {
            border-bottom: 0;
        }
        #invoice_table.table-invoice-index tbody tr {
            transition: background-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }
        #invoice_table.table-invoice-index tbody tr:hover {
            background: #fff9f2;
            box-shadow: inset 0 0 0 1px rgba(253, 126, 20, 0.1), 0 0.2rem 0.6rem rgba(0, 0, 0, 0.05);
            transform: translateY(-1px);
        }
        .index-table-card {
            border-radius: var(--tnc-radius) !important;
            border: 1px solid rgba(0, 0, 0, 0.06) !important;
            box-shadow: 0 0.28rem 0.9rem rgba(0, 0, 0, 0.045) !important;
            background: rgba(255, 255, 255, 0.68) !important;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        .index-dashboard-block .card-stats {
            background: rgba(255, 255, 255, 0.68) !important;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.46);
        }
        .index-stat-label {
            line-height: 1.35;
            white-space: normal;
            overflow-wrap: anywhere;
        }
        .index-table-toolbar .form-control.index-search-input {
            border-radius: var(--tnc-radius);
            padding: 0.65rem 1rem 0.65rem 2.75rem;
            border: 1px solid rgba(0, 0, 0, 0.08);
            background: #fff;
        }
        .index-table-toolbar .form-control.index-search-input:focus {
            border-color: rgba(253, 126, 20, 0.45);
            box-shadow: 0 0 0 0.2rem rgba(253, 126, 20, 0.15);
        }
        .index-cta-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            padding: 0.62rem 1.05rem;
            border-radius: 999px;
            font-weight: 700;
            letter-spacing: 0.01em;
            border: 1px solid transparent;
            text-decoration: none;
            transition: transform 0.16s ease, box-shadow 0.16s ease, filter 0.16s ease, background-color 0.16s ease, border-color 0.16s ease;
        }
        .index-cta-btn .index-cta-icon {
            width: 1.55rem;
            height: 1.55rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 0.84rem;
        }
        .index-cta-btn:hover {
            transform: translateY(-1px);
            text-decoration: none;
        }
        .index-cta-primary {
            color: #fff;
            background: linear-gradient(135deg, #ea580c 0%, #c2410c 100%);
            box-shadow: 0 0.4rem 0.95rem rgba(253, 126, 20, 0.34);
        }
        .index-cta-primary .index-cta-icon {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.32);
        }
        .index-cta-primary:hover {
            color: #fff;
            filter: brightness(1.04);
            box-shadow: 0 0.58rem 1.15rem rgba(253, 126, 20, 0.4);
        }
        .index-cta-secondary {
            color: #14532d;
            background: linear-gradient(135deg, rgba(25, 135, 84, 0.16) 0%, rgba(25, 135, 84, 0.07) 100%);
            border-color: rgba(25, 135, 84, 0.35);
            box-shadow: 0 0.3rem 0.85rem rgba(25, 135, 84, 0.14);
        }
        .index-cta-secondary .index-cta-icon {
            background: rgba(25, 135, 84, 0.18);
            border: 1px solid rgba(25, 135, 84, 0.26);
            color: #146c43;
        }
        .index-cta-secondary:hover {
            color: #0f5132;
            background: linear-gradient(135deg, rgba(25, 135, 84, 0.21) 0%, rgba(25, 135, 84, 0.1) 100%);
            border-color: rgba(25, 135, 84, 0.45);
            box-shadow: 0 0.45rem 1rem rgba(25, 135, 84, 0.18);
        }
        a.btn-invoice-action { text-decoration: none; }
        .btn-invoice-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.35rem;
            min-height: 2.35rem;
            padding: 0.4rem 0.55rem;
            border-radius: 0.5rem;
            border: 1px solid transparent;
            font-size: 1rem;
            line-height: 1;
            transition: background 0.15s ease, color 0.15s ease, transform 0.1s ease;
        }
        .btn-invoice-action:hover { transform: translateY(-1px); }
        .btn-invoice-action-view {
            background: rgba(253, 126, 20, 0.14);
            color: #c2410c;
            border-color: rgba(253, 126, 20, 0.24);
        }
        .btn-invoice-action-view:hover { background: rgba(253, 126, 20, 0.24); color: #9a3412; }
        .btn-invoice-action-view:focus-visible {
            outline: 2px solid rgba(253, 126, 20, 0.55);
            outline-offset: 2px;
        }
        .btn-invoice-action-tax {
            background: rgba(25, 135, 84, 0.14);
            color: #146c43;
            border-color: rgba(25, 135, 84, 0.22);
        }
        .btn-invoice-action-tax:hover { background: rgba(25, 135, 84, 0.22); color: #0f5132; }
        .btn-invoice-action-edit {
            background: rgba(108, 117, 125, 0.16);
            color: #495057;
            border-color: rgba(108, 117, 125, 0.22);
        }
        .btn-invoice-action-edit:hover { background: rgba(108, 117, 125, 0.24); color: #343a40; }
        .btn-invoice-action-delete {
            background: rgba(220, 53, 69, 0.12);
            color: #b02a37;
            border-color: rgba(220, 53, 69, 0.25);
        }
        .btn-invoice-action-delete:hover { background: rgba(220, 53, 69, 0.2); color: #842029; }
        .index-dashboard-block { padding-top: 0.25rem; }
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            padding-left: 1rem;
            padding-right: 1rem;
            padding-bottom: 0.75rem;
        }
        /* Invoice: กว้างกว่า A4 เล็กน้อย — ให้เห็นแผ่น 210mm เต็มความกว้างโดยไม่เลื่อนซ้าย-ขวา (เลื่อนขึ้นลงใน iframe) */
        #tncInvoiceModal .tnc-invoice-modal-dialog {
            width: min(calc(210mm + 3rem), calc(100vw - 1rem));
            max-width: min(calc(210mm + 3rem), calc(100vw - 1rem));
            margin: 0.5rem auto;
        }
        #tncInvoiceModal .modal-content {
            border: none;
            border-radius: 0.65rem;
            box-shadow: 0 0.35rem 2rem rgba(15, 23, 42, 0.12);
            overflow: hidden;
            background: var(--tnc-surface, #f6f7f9);
        }
        #tncInvoiceModal .modal-header {
            flex-shrink: 0;
            border-bottom: 1px solid var(--tnc-border, #e2e8f0);
            background: #fff;
            padding: 0.65rem 0.85rem;
        }
        #tncInvoiceModal .modal-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
        }
        #tncInvoiceModal .modal-body {
            padding: 0;
            height: min(calc(297mm + 1.5rem), calc(100vh - 4.25rem));
            max-height: calc(100vh - 4.25rem);
            overflow: auto;
            background: var(--tnc-surface, #f6f7f9);
            scrollbar-gutter: stable;
        }
        #tncInvoiceModal #tncInvoiceModalFrame {
            width: 100%;
            height: 100%;
            min-height: 240px;
            display: block;
        }

        /* Skeleton loading rows */
        .index-skeleton-wrap {
            padding: 0.65rem 0.95rem;
        }
        .index-skeleton-row {
            display: grid;
            grid-template-columns: 120px 1.5fr 1.5fr 130px 160px;
            gap: 0.8rem;
            align-items: center;
            padding: 0.82rem 0.5rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        .index-skeleton-line {
            height: 0.78rem;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(226, 232, 240, 0.8) 25%, rgba(241, 245, 249, 0.96) 50%, rgba(226, 232, 240, 0.8) 75%);
            background-size: 200% 100%;
            animation: indexSkeletonWave 1.15s linear infinite;
        }
        .index-skeleton-line.sm { width: 62%; }
        .index-skeleton-line.md { width: 82%; }
        .index-skeleton-line.lg { width: 94%; }
        .index-skeleton-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.38rem;
        }
        .index-skeleton-dot {
            width: 2rem;
            height: 2rem;
            border-radius: 0.55rem;
            background: linear-gradient(90deg, rgba(226, 232, 240, 0.88) 25%, rgba(241, 245, 249, 1) 50%, rgba(226, 232, 240, 0.88) 75%);
            background-size: 200% 100%;
            animation: indexSkeletonWave 1.15s linear infinite;
        }
        @keyframes indexSkeletonWave {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* ---------- Index mobile optimization ---------- */
        @media (max-width: 991.98px) {
            .index-sidebar-wrap {
                margin-top: 0.25rem;
            }
            .index-sidebar-card {
                border-radius: 0.75rem !important;
            }
            .home-menu-hub.index-sidebar .home-hub-toggle {
                padding: 0.92rem 0.95rem;
            }
            .home-menu-hub.index-sidebar .home-hub-section + .home-hub-section {
                margin-top: 0.5rem;
                padding-top: 0.5rem;
            }
            body.index-menu-in-navbar .index-sidebar-wrap {
                display: none;
            }
            #tnc-mobile-index-menu-slot .index-sidebar-card {
                border-radius: 0.65rem !important;
                box-shadow: 0 0.2rem 0.8rem rgba(0, 0, 0, 0.12) !important;
            }
            #tnc-mobile-index-menu-slot .index-sidebar-scroll {
                max-height: 58vh;
                overflow-y: auto;
            }
        }

        @media (max-width: 767.98px) {
            .index-dashboard-block .card-stats {
                padding: 1rem !important;
            }
            .card-stats:hover {
                transform: none !important;
                box-shadow: 0 0.35rem 0.95rem rgba(0, 0, 0, 0.07) !important;
            }
            .index-dashboard-block .card-stats .index-stat-label {
                font-size: 0.74rem;
            }
            .index-dashboard-block .card-stats .index-stat-value {
                font-size: clamp(1.35rem, 7vw, 1.9rem);
            }
            .index-table-card .card-header {
                padding-top: 0.9rem !important;
                padding-bottom: 0.9rem !important;
            }
            .index-table-card .card-header {
                position: sticky;
                top: 0.35rem;
                z-index: 20;
                background: rgba(255, 255, 255, 0.92) !important;
                backdrop-filter: blur(7px);
                -webkit-backdrop-filter: blur(7px);
                box-shadow: 0 0.22rem 0.65rem rgba(0, 0, 0, 0.06);
            }
            .index-table-toolbar {
                width: 100%;
            }
            .index-table-toolbar .form-control.index-search-input {
                min-height: 2.7rem;
            }
            .index-cta-btn {
                width: 100%;
            }
            .btn-invoice-action {
                min-width: 2.2rem;
                min-height: 2.2rem;
            }
            .btn-invoice-action:hover {
                transform: none;
            }

            /* Turn invoice table into readable card rows on phones */
            #invoice_table.table-invoice-index thead {
                display: none;
            }
            #invoice_table.table-invoice-index tbody tr {
                display: block;
                margin: 0.65rem 0.75rem;
                border: 1px solid rgba(0, 0, 0, 0.08);
                border-radius: 0.75rem;
                box-shadow: 0 0.15rem 0.75rem rgba(0, 0, 0, 0.05);
                background: #fff;
            }
            #invoice_table.table-invoice-index tbody td {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 0.9rem;
                border: 0;
                border-bottom: 1px dashed rgba(0, 0, 0, 0.08);
                padding: 0.62rem 0.85rem;
                text-align: right;
            }
            #invoice_table.table-invoice-index tbody td::before {
                color: #6c757d;
                font-size: 0.73rem;
                font-weight: 700;
                letter-spacing: 0.03em;
                text-transform: uppercase;
                text-align: left;
                flex: 0 0 5.8rem;
            }
            #invoice_table.table-invoice-index tbody td:nth-child(1)::before { content: "เลขที่"; }
            #invoice_table.table-invoice-index tbody td:nth-child(2)::before { content: "ลูกค้า"; }
            #invoice_table.table-invoice-index tbody td:nth-child(3)::before { content: "ยอดสุทธิ"; }
            #invoice_table.table-invoice-index tbody td:nth-child(4)::before { content: "จัดการ"; }
            #invoice_table.table-invoice-index tbody td:last-child {
                border-bottom: 0;
            }
            #invoice_table.table-invoice-index tbody td:nth-child(4) .d-inline-flex {
                margin-left: auto;
            }

            /* Keep loading/error row readable */
            #invoice_table.table-invoice-index tbody tr td[colspan] {
                display: block;
                text-align: center;
                border-bottom: 0;
                padding: 1.1rem 0.75rem;
            }
            .index-skeleton-wrap {
                padding: 0.45rem 0.45rem 0.75rem;
            }
            .index-skeleton-row {
                grid-template-columns: 1fr;
                gap: 0.45rem;
                border: 1px solid rgba(0, 0, 0, 0.08);
                border-radius: 0.7rem;
                margin-bottom: 0.55rem;
                padding: 0.7rem;
            }
            .index-skeleton-actions {
                justify-content: flex-start;
            }
            .index-skeleton-dot {
                width: 1.9rem;
                height: 1.9rem;
            }

            #tncInvoiceModal .tnc-invoice-modal-dialog {
                width: calc(100vw - 0.5rem);
                max-width: calc(100vw - 0.5rem);
                margin: 0.25rem auto;
            }
            #tncInvoiceModal .modal-body {
                height: calc(100vh - 5.1rem);
                max-height: calc(100vh - 5.1rem);
            }
        }

        @media (max-width: 575.98px) {
            .index-page-wrap {
                padding-left: 0.55rem !important;
                padding-right: 0.55rem !important;
            }
            #invoice_table.table-invoice-index tbody tr {
                margin-left: 0.5rem;
                margin-right: 0.5rem;
            }
            #invoice_table.table-invoice-index tbody td::before {
                flex-basis: 5.3rem;
            }
            .home-menu-hub.index-sidebar .home-hub-toggle {
                padding: 0.82rem 0.85rem;
            }
            .home-menu-hub.index-sidebar .home-hub-link {
                padding: 0.68rem 0.85rem 0.68rem 0.95rem;
                font-size: 0.88rem;
            }
            /* Mobile: spacing between groups, no outlined chips */
            .home-menu-hub.index-sidebar .home-hub-section {
                margin-bottom: 0.35rem;
            }
            .home-menu-hub.index-sidebar .home-hub-section + .home-hub-section {
                margin-top: 0.4rem;
                padding-top: 0.4rem;
            }
            .home-menu-hub.index-sidebar .home-hub-toggle {
                border: none;
                border-radius: 0.55rem;
                background: transparent;
                box-shadow: none;
                gap: 0.56rem;
            }
            .home-menu-hub.index-sidebar .home-hub-toggle:active {
                background: var(--tnc-sidebar-item-hover);
            }
            .home-menu-hub.index-sidebar .home-hub-toggle .home-hub-chevron {
                width: auto;
                height: auto;
                border-radius: 0;
                display: inline;
                background: transparent;
                color: inherit;
                opacity: 0.65;
            }
            .home-menu-hub.index-sidebar .home-hub-panel {
                margin-top: 0.15rem;
                padding: 0.15rem 0.45rem 0.5rem;
            }
            .home-menu-hub.index-sidebar .home-hub-link {
                border-radius: 0.5rem;
                background: transparent;
            }
            .home-menu-hub.index-sidebar .home-hub-link:active {
                background: var(--tnc-sidebar-item-hover);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .card-stats,
            .home-menu-hub .home-hub-card,
            .index-cta-btn,
            .btn-invoice-action,
            #invoice_table.table-invoice-index tbody tr {
                transition: none !important;
                animation: none !important;
            }
            .home-menu-hub .home-hub-link,
            .home-menu-hub .home-hub-link::before,
            .home-menu-hub-single .home-hub-toggle {
                transition: none !important;
            }
        }
    </style>
</head>
<body class="tnc-app-body tnc-index-page">

<?php include __DIR__ . '/components/navbar.php'; ?>

<div class="container pb-5 pt-1 index-page-wrap px-3 px-md-4">
    <div class="row g-4 index-layout-row">
    <aside class="col-lg-4 col-xl-3 index-sidebar-wrap order-1 order-lg-1" aria-label="เมนูนำทางระบบ">
        <div class="index-sidebar-sticky" id="indexSidebarMenuMount">
            <div class="card index-sidebar-card rounded-4 overflow-hidden mb-0" id="indexSidebarMenuCard">
                <section class="home-menu-hub index-sidebar mb-0" aria-label="เมนูระบบ">
                    <div class="index-sidebar-scroll">
                    <div class="card home-menu-hub-single home-hub-card border-0 shadow-none rounded-0 overflow-hidden">
            <div class="home-hub-section">
                <button type="button" class="home-hub-toggle<?= $index_hub_start_all_collapsed ? ' collapsed' : '' ?>" data-bs-toggle="collapse" data-bs-target="#hub-collapse-master" aria-expanded="<?= $index_hub_start_all_collapsed ? 'false' : 'true' ?>" aria-controls="hub-collapse-master" id="hub-toggle-master">
                    <span class="home-hub-ico home-hub-ico--master flex-shrink-0"><i class="bi bi-folder2" aria-hidden="true"></i></span>
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
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/contractors/contractor-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-person-badge me-2 text-secondary"></i>ผู้รับจ้าง (Contractors)</a>
                    </div>
                </div>
            </div>
            <div class="home-hub-section">
                <button type="button" class="home-hub-toggle collapsed" data-bs-toggle="collapse" data-bs-target="#hub-collapse-purchase" aria-expanded="false" aria-controls="hub-collapse-purchase" id="hub-toggle-purchase">
                    <span class="home-hub-ico home-hub-ico--purchase flex-shrink-0"><i class="bi bi-cart3" aria-hidden="true"></i></span>
                    <span class="fw-semibold text-dark">จัดซื้อ / จัดจ้าง (Purchase / Hire)</span>
                    <i class="bi bi-chevron-down home-hub-chevron" aria-hidden="true"></i>
                </button>
                <div id="hub-collapse-purchase" class="collapse home-hub-panel" aria-labelledby="hub-toggle-purchase">
                    <div class="home-hub-panel-inner pb-1">
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-cart-plus me-2 text-secondary"></i>ใบขอซื้อ (Purchase Request)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-bag-check me-2 text-secondary"></i>ใบสั่งซื้อ (Purchase Order)</a>
                    </div>
                </div>
            </div>
            <div class="home-hub-section">
                <button type="button" class="home-hub-toggle collapsed" data-bs-toggle="collapse" data-bs-target="#hub-collapse-docs" aria-expanded="false" aria-controls="hub-collapse-docs" id="hub-toggle-docs">
                    <span class="home-hub-ico home-hub-ico--docs flex-shrink-0"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span>
                    <span class="fw-semibold text-dark">ระบบเอกสาร (Documents)</span>
                    <i class="bi bi-chevron-down home-hub-chevron" aria-hidden="true"></i>
                </button>
                <div id="hub-collapse-docs" class="collapse home-hub-panel" aria-labelledby="hub-toggle-docs">
                    <div class="home-hub-panel-inner pb-1">
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/hire-contracts/hire-contract-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-file-earmark-ruled me-2 text-secondary"></i>สัญญาจ้าง (Hire Contract)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/stock/stock-list.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-box-seam me-2 text-secondary"></i>คลังสินค้า (Stock)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/daily-site-reports/daily-site-report-calendar.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-calendar3 me-2 text-secondary"></i>สมุดรายวันหน้างาน (DSR)</a>
                    </div>
                </div>
            </div>
            <div class="home-hub-section">
                <button type="button" class="home-hub-toggle collapsed" data-bs-toggle="collapse" data-bs-target="#hub-collapse-cash" aria-expanded="false" aria-controls="hub-collapse-cash" id="hub-toggle-cash">
                    <span class="home-hub-ico home-hub-ico--cash flex-shrink-0"><i class="bi bi-cash-stack" aria-hidden="true"></i></span>
                    <span class="fw-semibold text-dark">ระบบการเงิน (Cash)</span>
                    <i class="bi bi-chevron-down home-hub-chevron" aria-hidden="true"></i>
                </button>
                <div id="hub-collapse-cash" class="collapse home-hub-panel" aria-labelledby="hub-toggle-cash">
                    <div class="home-hub-panel-inner pb-1">
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/reports/vat-report.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-file-earmark-bar-graph me-2 text-secondary"></i>รายงานภาษีซื้อ/ขาย (VAT Report)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/reports/site-spending-report.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-geo-alt me-2 text-secondary"></i>รายงานใช้จ่ายตามไซต์ (Site Spending)</a>
                        <a class="home-hub-link d-flex align-items-center" href="<?= htmlspecialchars(app_path('pages/cash-ledger/cash-ledger.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-speedometer2 me-2 text-secondary"></i>สดย่อย (Petty Cash)</a>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    <main class="col-lg-8 col-xl-9 index-main-col order-2 order-lg-2 min-w-0" id="main-content">
    <div class="index-dashboard-block">
    <div class="card index-table-card border-0 shadow-sm overflow-hidden mb-0 bg-white">
        <div class="card-header bg-white border-bottom py-3 px-3 px-md-4">
            <div class="row align-items-center g-3">
                <div class="col-12 col-lg-4">
                    <h1 class="index-invoice-head-title">รายการใบแจ้งหนี้</h1>
                </div>
                <div class="col-12 col-lg-8">
                    <div class="d-flex flex-column flex-sm-row flex-wrap gap-2 align-items-stretch justify-content-lg-end">
                        <div class="position-relative flex-grow-1 index-table-toolbar" style="min-width: 220px; max-width: 100%;">
                            <label class="visually-hidden" for="search_invoice">ค้นหาใบแจ้งหนี้</label>
                            <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted" aria-hidden="true"></i>
                            <input type="search" id="search_invoice" autocomplete="off" class="form-control index-search-input">
                        </div>
                        <a href="<?= htmlspecialchars(app_path('pages/invoices/invoice.php')) ?>?action=create" class="index-cta-btn index-cta-primary flex-shrink-0 text-center text-nowrap">
                            <span class="index-cta-icon"><i class="bi bi-plus-lg" aria-hidden="true"></i></span>
                            <span>สร้างใบแจ้งหนี้ใหม่</span>
                        </a>
                        <a href="<?= htmlspecialchars(app_path('pages/invoices/tax-invoice-list.php')) ?>" class="index-cta-btn index-cta-secondary flex-shrink-0 text-center text-nowrap">
                            <span class="index-cta-icon"><i class="bi bi-file-earmark-break" aria-hidden="true"></i></span>
                            <span>รายการใบกำกับภาษี</span>
                        </a>
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

        <div class="index-inv-legend px-3 px-md-4" aria-label="ความหมายสีเลขที่ใบแจ้งหนี้">
            <span class="index-inv-legend__item"><span class="index-inv-legend__swatch index-inv-legend__swatch--pending" aria-hidden="true"></span>สีเหลือง = ยังไม่ออกใบกำกับภาษี</span>
            <span class="index-inv-legend__sep d-none d-sm-inline" aria-hidden="true">·</span>
            <span class="index-inv-legend__item"><span class="index-inv-legend__swatch index-inv-legend__swatch--issued" aria-hidden="true"></span>สีเขียว = ออกใบกำกับภาษีแล้ว</span>
        </div>
    </div>
    </div>

    <div class="modal fade" id="tncInvoiceModal" tabindex="-1" aria-labelledby="tncInvoiceModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered tnc-invoice-modal-dialog">
            <div class="modal-content">
                <div class="modal-header py-2 px-3 align-items-center flex-wrap gap-2 no-print">
                    <h6 class="modal-title fw-semibold mb-0 me-auto" id="tncInvoiceModalTitle">ใบแจ้งหนี้</h6>
                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                        <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-semibold text-nowrap" id="tncInvoiceModalPrint" title="พิมพ์ต้นฉบับและสำเนา">
                            <i class="bi bi-printer me-1"></i>พิมพ์
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                    </div>
                </div>
                <div class="modal-body p-0">
                    <iframe id="tncInvoiceModalFrame" class="border-0" title="Invoice"></iframe>
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

let tncInvoiceModalInstance = null;
function tncOpenInvoiceViewModal(id) {
    const frame = document.getElementById('tncInvoiceModalFrame');
    const titleEl = document.getElementById('tncInvoiceModalTitle');
    const modalEl = document.getElementById('tncInvoiceModal');
    if (!frame || !modalEl || !window.bootstrap || !window.bootstrap.Modal) {
        return;
    }
    const u = invoicePhpUrl + '?action=view&id=' + encodeURIComponent(String(id)) + '&embed=1';
    frame.src = u;
    if (titleEl) {
        titleEl.textContent = 'ดูใบแจ้งหนี้';
    }
    if (!tncInvoiceModalInstance) {
        tncInvoiceModalInstance = new bootstrap.Modal(modalEl);
    }
    document.body.classList.add('tnc-invoice-modal-open');
    tncInvoiceModalInstance.show();
}

document.getElementById('tncInvoiceModal')?.addEventListener('hidden.bs.modal', function () {
    document.body.classList.remove('tnc-invoice-modal-open');
    const frame = document.getElementById('tncInvoiceModalFrame');
    if (frame) {
        frame.src = 'about:blank';
    }
});

function tncPrintInvoiceFromModal() {
    const frame = document.getElementById('tncInvoiceModalFrame');
    if (!frame) {
        return;
    }
    const src = frame.src || '';
    if (!src || src === 'about:blank') {
        return;
    }
    try {
        const u = new URL(src, window.location.href);
        u.searchParams.set('autoprint', '1');
        const printWin = window.open(u.toString(), '_blank', 'noopener,noreferrer');
        if (printWin) {
            return;
        }
    } catch (e) {}
    try {
        if (frame.contentWindow) {
            frame.contentWindow.focus();
            frame.contentWindow.print();
        }
    } catch (e2) {}
}

document.getElementById('tncInvoiceModalPrint')?.addEventListener('click', tncPrintInvoiceFromModal);

document.addEventListener('keydown', function (e) {
    if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'p') {
        return;
    }
    const modalEl = document.getElementById('tncInvoiceModal');
    if (!modalEl || !modalEl.classList.contains('show')) {
        return;
    }
    e.preventDefault();
    tncPrintInvoiceFromModal();
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

function indexMoveMenuToNavbarOnMobile() {
    var slot = document.getElementById('tnc-mobile-index-menu-slot');
    var mount = document.getElementById('indexSidebarMenuMount');
    var card = document.getElementById('indexSidebarMenuCard');
    if (!slot || !mount || !card) {
        return;
    }
    var isMobile = window.matchMedia('(max-width: 991.98px)').matches;
    var shouldInNavbar = isMobile;
    if (shouldInNavbar && card.parentElement !== slot) {
        slot.appendChild(card);
        document.body.classList.add('index-menu-in-navbar');
    } else if (!shouldInNavbar && card.parentElement !== mount) {
        mount.appendChild(card);
        document.body.classList.remove('index-menu-in-navbar');
    } else if (!shouldInNavbar) {
        document.body.classList.remove('index-menu-in-navbar');
    }
}

window.onload = () => {
    indexMoveMenuToNavbarOnMobile();
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
    }
};
window.addEventListener('resize', function () {
    indexMoveMenuToNavbarOnMobile();
});
</script>
<link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/print-document-only.css'), ENT_QUOTES, 'UTF-8') ?>" media="print">
</body>
</html>