<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/line_pr_approval.php';
require_once dirname(__DIR__, 2) . '/includes/banks.php';
require_once dirname(__DIR__, 2) . '/includes/suppliers.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_purchase_head.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

if (!user_can('po.create')) {
    header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?error=forbidden');
    exit();
}

$pr_id = (int) ($_GET['pr_id'] ?? 0);
if ($pr_id <= 0) {
    header('Location: ' . app_path('pages/purchase/purchase-request-list.php'));
    exit();
}

$pr = Db::rowByIdField('purchase_requests', $pr_id);
if ($pr === null) {
    header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?error=not_found');
    exit();
}
if (!line_pr_is_approved_for_po($pr)) {
    $st = line_pr_normalize_status($pr);
    $err = $st === 'rejected' ? 'pr_rejected' : 'pr_not_approved';
    header('Location: ' . app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&error=' . $err);
    exit();
}

require_once dirname(__DIR__, 2) . '/includes/pr_po_split.php';
if (!tnc_pr_has_remaining_for_po($pr_id)) {
    header('Location: ' . app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&error=pr_fully_ordered');
    exit();
}

$linked_pos = tnc_pr_collect_active_purchase_orders($pr_id);
$linked_po_count = count($linked_pos);

$pr_number_display = trim((string) ($pr['pr_number'] ?? ('PR-' . $pr_id)));
$pr_vat_enabled = (int) ($pr['vat_enabled'] ?? 0) === 1 ? 1 : 0;
$pr_vat_mode = trim((string) ($pr['vat_mode'] ?? 'exclusive'));
if (!in_array($pr_vat_mode, ['exclusive', 'inclusive'], true)) {
    $pr_vat_mode = 'exclusive';
}

$pr_prefill_items_display = [];
foreach (tnc_pr_remaining_items_for_po($pr_id) as $prItemRow) {
    if (trim((string) ($prItemRow['description'] ?? '')) !== '') {
        $pr_prefill_items_display[] = $prItemRow;
    }
}

try {
    $po_number = Purchase::poNumberFromPrSplit($pr, $pr_id);
} catch (InvalidArgumentException) {
    header('Location: ' . app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&error=invalid_pr_number');
    exit();
}
$po_split_label = tnc_pr_po_split_sequence_label($linked_po_count);
$supplier_rows = Db::tableRows('suppliers');
Db::sortRows($supplier_rows, 'name', false);

/** @var array<string, array{name: string, bank: string, account_name: string, account_number: string, bank_logo: string, note_text: string}> $supplier_payment_map */
$supplier_payment_map = [];
foreach ($supplier_rows as $supplierRow) {
    $sid = (int) ($supplierRow['id'] ?? 0);
    if ($sid <= 0 || !tnc_supplier_has_payment_info($supplierRow)) {
        continue;
    }
    $bankName = trim((string) ($supplierRow['bank_name'] ?? ''));
    $supplier_payment_map[(string) $sid] = [
        'name' => trim((string) ($supplierRow['name'] ?? '')),
        'bank' => $bankName,
        'account_name' => trim((string) ($supplierRow['bank_account_name'] ?? '')),
        'account_number' => trim((string) ($supplierRow['bank_account_number'] ?? '')),
        'bank_logo' => $bankName !== '' ? tnc_bank_logo_url($bankName) : '',
        'note_text' => tnc_supplier_payment_note_text($supplierRow),
    ];
}

$errorCode = trim((string) ($_GET['error'] ?? ''));

/**
 * @param array<string, mixed> $item
 */
function tnc_po_create_pr_discount_label(array $item): string
{
    $discDisplay = trim((string) ($item['discount_input'] ?? ''));
    if ($discDisplay !== '') {
        return $discDisplay;
    }
    $discType = (string) ($item['discount_type'] ?? 'amount');
    $discValue = (float) ($item['discount_value'] ?? 0);
    if ($discValue > 0) {
        return $discType === 'percent'
            ? (rtrim(rtrim(number_format($discValue, 4, '.', ''), '0'), '.') . '%')
            : number_format($discValue, 2, '.', '');
    }
    $discAmount = (float) ($item['discount_amount'] ?? 0);
    if ($discAmount > 0) {
        return number_format($discAmount, 2, '.', '');
    }

    return '';
}

/**
 * @param array<string, mixed> $item
 */
function tnc_po_create_pr_line_total(array $item): float
{
    $qty = (float) ($item['quantity'] ?? 0);
    $price = (float) ($item['unit_price'] ?? 0);
    $base = round($qty * $price, 2);
    $discRaw = tnc_po_create_pr_discount_label($item);
    $discount = 0.0;
    if ($discRaw !== '' && $base > 0) {
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*%$/', $discRaw, $pctMatch) === 1) {
            $pct = (float) $pctMatch[1];
            if ($pct < 0) {
                $pct = 0.0;
            } elseif ($pct > 100) {
                $pct = 100.0;
            }
            $discount = round($base * $pct / 100, 2);
        } else {
            $discount = min($base, round((float) str_replace([',', ' '], '', $discRaw), 2));
        }
    } elseif ((float) ($item['discount_amount'] ?? 0) > 0) {
        $discount = min($base, round((float) $item['discount_amount'], 2));
    }
    $computed = round($base - $discount, 2);
    $storedTotal = (float) ($item['total'] ?? 0);
    if ($storedTotal > 0 && abs($storedTotal - $computed) <= 0.015) {
        return $storedTotal;
    }

    return $computed;
}

/**
 * @param array<string, mixed> $pr
 */
function tnc_po_create_issue_date_from_pr(array $pr): string
{
    $raw = trim((string) ($pr['created_at'] ?? ''));
    if ($raw === '') {
        return date('Y-m-d');
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $ymd) === 1) {
        return $ymd[1] . '-' . $ymd[2] . '-' . $ymd[3];
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $raw, $dmy) === 1) {
        return sprintf('%04d-%02d-%02d', (int) $dmy[3], (int) $dmy[2], (int) $dmy[1]);
    }
    $ts = strtotime($raw);

    return $ts !== false ? date('Y-m-d', $ts) : date('Y-m-d');
}

function tnc_po_create_format_ymd_as_dmy(string $ymd): string
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($ymd), $m) === 1) {
        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    return trim($ymd);
}

$issueDateDefault = tnc_po_create_issue_date_from_pr($pr);
$issueDateDisplay = tnc_po_create_format_ymd_as_dmy($issueDateDefault);
$prIssueDateHint = $issueDateDisplay;
$pr_view_url = app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id;
$pr_edit_url = app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id;

$pr_grand = (float) ($pr['total_amount'] ?? 0);
$pr_vat_amt = (float) ($pr['vat_amount'] ?? 0);
if (isset($pr['subtotal_amount']) && $pr['subtotal_amount'] !== null && $pr['subtotal_amount'] !== '') {
    $pr_sub_amt = (float) $pr['subtotal_amount'];
} else {
    $pr_sub_amt = round($pr_grand - $pr_vat_amt, 2);
}
if (!function_exists('tnc_purchase_vat_print_summary')) {
    require_once dirname(__DIR__, 2) . '/includes/purchase_print/vat_print_summary.php';
}
$prVatPrintCreate = tnc_purchase_vat_print_summary($pr_vat_enabled === 1, $pr_vat_mode, $pr_sub_amt, $pr_vat_amt, $pr_grand);

$prSiteId = (int) ($pr['site_id'] ?? 0);
$prSiteName = trim((string) ($pr['site_name'] ?? ''));
if ($prSiteName === '' && $prSiteId > 0) {
    $prSiteRow = Db::row('sites', (string) $prSiteId);
    if (is_array($prSiteRow)) {
        $prSiteName = trim((string) ($prSiteRow['name'] ?? ''));
    }
}
$prCostCategoryId = (int) ($pr['cost_category_id'] ?? 0);
require_once dirname(__DIR__, 2) . '/includes/site_category_document_name.php';
$prCostCategoryName = tnc_site_category_document_name(
    $prCostCategoryId,
    trim((string) ($pr['cost_category_name'] ?? ''))
);

$po_submit_disabled = $pr_prefill_items_display === [];
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <?php tnc_purchase_head([
        'title' => 'สร้างใบสั่งซื้อ (PO)',
        'flatpickr' => true,
        'sarabun_weights' => '400;600;700',
    ]); ?>
    <style>
        .po-create-wrap { max-width: 1100px; }
        .card-soft {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: var(--tnc-radius-lg);
            box-shadow: var(--tnc-shadow-sm);
            background: #fff;
        }
        .po-section-head {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 1.1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #eef2f7;
        }
        .section-title { font-size: 1.05rem; font-weight: 800; color: var(--tnc-ink); margin: 0; letter-spacing: -0.02em; }
        .section-sub { font-size: 0.8rem; color: var(--tnc-muted); margin: 0.2rem 0 0; line-height: 1.4; }
        .po-field-label { font-size: 0.78rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.35rem; }
        .po-field-label--thai { text-transform: none; letter-spacing: 0; font-size: 0.84rem; color: #334155; }
        .form-control, .form-select, .input-group-text { border-radius: 0.5rem; }
        .po-meta-card .form-control:focus { box-shadow: 0 0 0 0.2rem rgba(253, 126, 20, 0.12); }
        .po-meta-card .input-group > .input-group-text { border-color: var(--tnc-border); background: #fff; }
        .po-meta-card .input-group > .form-control { border-color: var(--tnc-border); }
        .po-meta-card .input-group:focus-within > .input-group-text,
        .po-meta-card .input-group:focus-within > .form-control { border-color: var(--tnc-orange-border); }
        .po-meta-card .input-group:focus-within > .input-group-text { color: var(--tnc-orange); }
        .po-meta-section {
            padding-top: 1.15rem;
            margin-top: 1.15rem;
            border-top: 1px solid #eef2f7;
        }
        .po-meta-section__head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.45rem 0.65rem;
            margin-bottom: 0.85rem;
        }
        .po-meta-section__title {
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #64748b;
            margin: 0;
        }
        .po-meta-section__title i { color: var(--tnc-orange); margin-right: 0.15rem; }
        .po-meta-badge {
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 0.18rem 0.5rem;
            border-radius: 999px;
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }
        .po-pr-context {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 0.75rem;
        }
        .po-pr-context__item {
            display: flex;
            align-items: flex-start;
            gap: 0.7rem;
            min-height: 3.25rem;
            padding: 0.8rem 0.95rem;
            border-radius: 0.65rem;
            border: 1px solid #e8ecf1;
            background: linear-gradient(180deg, #fafbfc 0%, #f1f5f9 100%);
        }
        .po-pr-context__icon {
            width: 2.1rem;
            height: 2.1rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            border: 1px solid #e2e8f0;
            color: var(--tnc-orange);
            flex-shrink: 0;
            font-size: 1rem;
        }
        .po-pr-context__body { min-width: 0; flex: 1; }
        .po-pr-context__label {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 0.12rem;
        }
        .po-pr-context__value {
            font-size: 0.92rem;
            font-weight: 600;
            color: #0f172a;
            line-height: 1.4;
            word-break: break-word;
        }
        .po-pr-context__value.is-empty { color: #94a3b8; font-weight: 500; }
        .po-pr-context__link {
            display: inline-block;
            margin-top: 0.25rem;
            font-size: 0.78rem;
            font-weight: 600;
        }
        .po-meta-optional {
            background: #fafbfc;
            border: 1px dashed #dbe3ec;
            border-radius: 0.75rem;
            padding: 1rem 1.1rem;
        }
        .po-meta-optional .row { --bs-gutter-y: 0.85rem; }
        .po-field-hint {
            display: flex;
            align-items: flex-start;
            gap: 0.35rem;
            font-size: 0.78rem;
            color: #94a3b8;
            margin-top: 0.35rem;
            line-height: 1.35;
        }
        .po-field-hint i { flex-shrink: 0; margin-top: 0.1rem; }
        @media (min-width: 992px) {
            .po-meta-primary .col-lg-6:first-child { padding-right: 0.75rem; }
            .po-meta-primary .col-lg-6:last-child { padding-left: 0.75rem; }
        }
        .po-po-number { font-size: 1.05rem; letter-spacing: 0.02em; }
        .po-qt-toggle {
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 0.85rem 1rem;
            background: #fafbfc;
            transition: background 0.15s ease, border-color 0.15s ease;
        }
        .po-qt-toggle:hover { border-color: #cbd5e1; background: #f8fafc; }
        .po-qt-toggle .form-check-input { width: 2.5rem; height: 1.25rem; cursor: pointer; }
        .po-qt-toggle .form-check-label { cursor: pointer; padding-top: 0.1rem; }
        #quotation_panel { border-color: #e2e8f0 !important; background: #f8fafc !important; border-radius: 0.75rem !important; }
        .po-table-wrap { border: 1px solid #e8ecf1; border-radius: 0.75rem; overflow: hidden; background: #fff; }
        .po-table-wrap .table { margin-bottom: 0; }
        .po-table-wrap thead th {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
            font-weight: 700;
            background: #f1f5f9 !important;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.65rem 0.5rem;
            white-space: nowrap;
        }
        .po-table-wrap tbody td { padding: 0.5rem 0.45rem; vertical-align: middle; }
        .po-table-wrap .form-control-sm { min-height: calc(1.5em + 0.6rem + 2px); }
        .po-actions-bar { margin-top: 0.85rem; padding-top: 0.85rem; border-top: 1px solid #eef2f7; }
        .po-wht-box {
            border: 1px solid #fee2e2;
            border-radius: 0.75rem;
            padding: 0.85rem 1rem;
            background: linear-gradient(180deg, #fffefe 0%, #fff7f7 100%);
        }
        .po-wht-box .form-check-input { cursor: pointer; }
        .summary-box {
            background: linear-gradient(180deg, #fffbf5 0%, var(--tnc-orange-soft) 100%);
            border: 1px solid var(--tnc-orange-border);
            border-radius: 0.85rem;
            padding: 1.1rem 1.15rem;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
        }
        @media (min-width: 992px) {
            .po-summary-sticky { position: sticky; top: 5.5rem; }
        }
        .summary-line {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 12px;
            width: 100%;
            margin-bottom: 10px;
        }
        .summary-line:last-child { margin-bottom: 0; }
        .summary-label {
            color: #475569;
            font-weight: 600;
            font-size: 0.9rem;
            min-width: 0;
            line-height: 1.35;
        }
        #vat_row .summary-label { color: #198754; }
        .summary-value {
            font-weight: 700;
            white-space: nowrap;
            text-align: right;
            justify-self: end;
            font-variant-numeric: tabular-nums;
        }
        .summary-grand { padding-top: 0.35rem; margin-top: 0.25rem; border-top: 2px dashed rgba(253, 126, 20, 0.25); }
        .summary-grand .summary-label { font-size: 1rem; color: var(--tnc-ink); }
        .summary-grand .summary-value { font-size: 1.25rem; color: var(--tnc-orange) !important; }
        .po-vat-panel { background: #fffbf5; border: 1px solid var(--tnc-orange-border); border-radius: 0.75rem; }
        .po-doc-flow {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.65rem 1rem;
            margin-top: 0.35rem;
        }
        .po-doc-badge {
            font-size: 0.65rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 0.2rem 0.45rem;
            border-radius: 0.35rem;
            line-height: 1.2;
        }
        .po-doc-badge-pr { background: rgba(255, 255, 255, 0.22); color: #fff; }
        .po-doc-badge-po { background: #fff; color: var(--tnc-orange); }
        .po-doc-num {
            font-size: clamp(1.1rem, 2.8vw, 1.45rem);
            font-weight: 800;
            letter-spacing: 0.02em;
            font-variant-numeric: tabular-nums;
        }
        .po-doc-arrow { font-size: 1.35rem; opacity: 0.85; }
        .po-readonly-hint {
            border: 1px solid var(--tnc-orange-border);
            border-radius: 0.85rem;
            background: linear-gradient(180deg, #fffbf5 0%, var(--tnc-orange-soft) 100%);
            padding: 1rem 1.15rem;
        }
        .po-readonly-hint .hint-title { font-weight: 800; color: #0f172a; font-size: 0.95rem; margin-bottom: 0.35rem; }
        .po-readonly-hint ul { margin: 0.5rem 0 0; padding-left: 1.15rem; color: #475569; font-size: 0.88rem; }
        .po-readonly-hint li { margin-bottom: 0.25rem; }
        .po-items-readonly .table { --bs-table-bg: #fff; }
        .po-items-readonly tbody td {
            font-size: 0.92rem;
            vertical-align: middle;
        }
        .po-items-readonly .cell-num { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .po-items-readonly .cell-desc { font-weight: 600; color: #0f172a; }
        .po-lock-banner {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.65rem 1rem;
            background: #f1f5f9;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.85rem;
            color: #475569;
        }
        .po-lock-banner i { color: #64748b; }
    </style>
    <?php
    $poLineMobileCss = dirname(__DIR__, 2) . '/assets/css/po-line-table-mobile.css';
    $poLineMobileVer = @filemtime($poLineMobileCss);
    if (!is_int($poLineMobileVer) || $poLineMobileVer <= 0) {
        $poLineMobileVer = time();
    }
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/po-line-table-mobile.css') . '?v=' . $poLineMobileVer, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="purchase-module tnc-app-body tnc-layout-form">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container container-lg py-4 py-md-5 mb-5 po-create-wrap">
    <?php if ($errorCode !== ''): ?>
        <div class="alert alert-danger py-2 mb-3">
            <?php
            echo match ($errorCode) {
                'supplier' => 'กรุณาเลือกผู้ขายจากรายการที่ระบบแนะนำ',
                'no_items', 'invalid_items' => 'กรุณาเพิ่มรายการอย่างน้อย 1 รายการ และกรอกจำนวน/ราคาให้ถูกต้อง',
                'cash_paid_by_required' => 'กรุณากรอก «จ่ายโดย» เมื่อเลือกชำระด้วยเงินสด',
                'payment_slip_required' => 'กรุณาแนบสลิปหรือหลักฐานการจ่ายอย่างน้อย 1 ไฟล์',
                'upload_failed', 'upload_type' => 'อัปโหลดสลิปไม่สำเร็จ — ใช้ไฟล์รูปหรือ PDF',
                'billing_required' => 'กรุณากรอกวันที่ออกใบสั่งซื้อให้ถูกต้อง',
                'site_budget_exceeded' => 'งบไซต์ไม่พอ — ไม่สามารถออก PO ได้ (เกินวงเงินรวมของไซต์)',
                'site_budget_cat_exceeded' => 'งบหมวดไม่พอ — ไม่สามารถออก PO ได้ (เกินวงเงินหมวดที่กำหนด)',
                'invalid_pr_number' => 'เลข PR ไม่ถูกต้อง — ไม่สามารถออก PO ที่เลขท้ายตรงกันได้',
                'po_number_conflict' => 'เลข PO ที่ตรงกับ PR ถูกใช้ไปแล้ว — ติดต่อผู้ดูแลระบบ',
                'qty_exceeds_pr' => 'จำนวนรายการเกินยอดที่เหลือในใบขอซื้อ — ลดจำนวนหรือลบแถวที่เกิน',
                default => 'บันทึกใบสั่งซื้อไม่สำเร็จ กรุณาตรวจสอบข้อมูลและลองใหม่',
            };
            ?>
        </div>
    <?php endif; ?>
    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=create_po_from_pr" method="POST" enctype="multipart/form-data" data-tnc-fullnav="1">
        <?php csrf_field(); ?>
        <input type="hidden" name="pr_id" value="<?= $pr_id ?>">
        <input type="hidden" name="site_id" value="<?= $prSiteId ?>">
        <input type="hidden" name="site_name" value="<?= htmlspecialchars($prSiteName, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="cost_category_id" value="<?= $prCostCategoryId ?>">
        <input type="hidden" name="cost_category_name" value="<?= htmlspecialchars($prCostCategoryName, ENT_QUOTES, 'UTF-8') ?>">

        <header class="po-create-hero p-4 p-md-4 mb-4">
            <div class="row align-items-center g-3">
                <div class="col-lg">
                    <div class="po-doc-flow">
                        <span class="po-doc-num"><?= htmlspecialchars($pr_number_display, ENT_QUOTES, 'UTF-8') ?></span>
                        <i class="bi bi-arrow-right po-doc-arrow" aria-hidden="true"></i>
                        <span class="po-doc-num"><?= htmlspecialchars($po_number, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($linked_po_count > 0): ?>
                        <span class="po-doc-badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><?= htmlspecialchars($po_split_label, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($linked_po_count > 0): ?>
                    <p class="small text-muted mb-0 mt-2">
                        มี PO จาก PR นี้แล้ว <?= number_format($linked_po_count) ?> ใบ —
                        <?php foreach ($linked_pos as $i => $lpo): ?>
                            <?php if ($i > 0): ?><span class="text-muted">·</span><?php endif; ?>
                            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-view.php') . '?id=' . (int) ($lpo['id'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" class="link-secondary fw-semibold"><?= htmlspecialchars(trim((string) ($lpo['po_number'] ?? ('PO-' . (int) ($lpo['id'] ?? 0)))), ENT_QUOTES, 'UTF-8') ?></a>
                        <?php endforeach; ?>
                        — กรอกเฉพาะรายการ/จำนวนที่เหลือ
                    </p>
                    <?php endif; ?>
                </div>
                <div class="col-lg-auto d-flex flex-wrap gap-2 justify-content-lg-end">
                    <a href="<?= htmlspecialchars($pr_view_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light rounded-pill px-4 shadow-sm"><i class="bi bi-arrow-left me-1"></i>กลับใบขอซื้อ</a>
                </div>
            </div>
        </header>
        
        <div class="card card-soft p-4 p-md-4 mb-4 po-meta-card">
            <div class="po-section-head mb-0 pb-3 border-bottom-0">
                <div class="po-section-icon"><i class="bi bi-card-checklist"></i></div>
                <div>
                    <h2 class="section-title">ข้อมูลใบสั่งซื้อ</h2>
                    <p class="section-sub">กรอกวันที่และผู้ขาย — แก้ไขรายการ/VAT ด้านล่างได้ก่อนยืนยัน PO</p>
                </div>
            </div>

            <div class="row g-3 g-md-4 po-meta-primary align-items-end">
                <div class="col-md-6 col-lg-4">
                    <label class="po-field-label po-field-label--thai" for="issue_date">วันที่ออกใบสั่งซื้อ / วันที่ใบกำกับ <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text text-tnc-orange" title="ปฏิทิน"><i class="bi bi-calendar3"></i></span>
                        <input
                            type="text"
                            class="form-control"
                            name="issue_date"
                            id="issue_date"
                            value="<?= htmlspecialchars($issueDateDisplay, ENT_QUOTES, 'UTF-8') ?>"
                            required
                            autocomplete="off"
                            placeholder="วัน/เดือน/ปี"
                        >
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="po-field-label po-field-label--thai" for="supplier_search">ผู้ขาย / แหล่งซื้อ</label>
                    <div class="input-group">
                        <span class="input-group-text text-secondary"><i class="bi bi-shop"></i></span>
                        <input type="text" id="supplier_search" class="form-control" list="supplier_list" autocomplete="off" placeholder="พิมพ์ชื่อผู้ขายเพื่อเลือก (ไม่บังคับ)">
                    </div>
                    <datalist id="supplier_list">
                        <?php foreach ($supplier_rows as $s): ?>
                            <option
                                value="<?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-id="<?= (int) ($s['id'] ?? 0) ?>"
                            ></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="supplier_id" id="supplier_id" value="">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="po-field-label po-field-label--thai" for="supplier_invoice_no">เลขที่บิล / ใบกำกับภาษี</label>
                    <div class="input-group">
                        <span class="input-group-text text-secondary"><i class="bi bi-hash"></i></span>
                        <input type="text" name="supplier_invoice_no" id="supplier_invoice_no" class="form-control" maxlength="120">
                    </div>
                </div>
            </div>

            <div class="po-meta-section">
                <div class="po-pr-context">
                    <div class="po-pr-context__item">
                        <span class="po-pr-context__icon" aria-hidden="true"><i class="bi bi-geo-alt"></i></span>
                        <div class="po-pr-context__body">
                            <div class="po-pr-context__label">สถานที่</div>
                            <div class="po-pr-context__value<?= $prSiteName === '' ? ' is-empty' : '' ?>"><?= htmlspecialchars($prSiteName !== '' ? $prSiteName : 'ยังไม่ระบุใน PR', ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if ($prSiteName === '' && $prSiteId <= 0): ?>
                            <a class="po-pr-context__link text-tnc-orange" href="<?= htmlspecialchars($pr_edit_url, ENT_QUOTES, 'UTF-8') ?>">แก้ไข PR เพื่อระบุสถานที่</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="po-pr-context__item">
                        <span class="po-pr-context__icon" aria-hidden="true"><i class="bi bi-tags"></i></span>
                        <div class="po-pr-context__body">
                            <div class="po-pr-context__label">หมวดหมู่</div>
                            <div class="po-pr-context__value<?= $prCostCategoryName === '' ? ' is-empty' : '' ?>"><?= htmlspecialchars($prCostCategoryName !== '' ? $prCostCategoryName : 'ยังไม่ระบุใน PR', ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if ($prCostCategoryName === '' && $prCostCategoryId <= 0): ?>
                            <a class="po-pr-context__link text-tnc-orange" href="<?= htmlspecialchars($pr_edit_url, ENT_QUOTES, 'UTF-8') ?>">แก้ไข PR เพื่อระบุหมวดหมู่</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-soft p-4 p-md-4 mb-4">
            <div class="po-section-head mb-3 pb-0 border-bottom-0">
                <div>
                    <h2 class="section-title mb-0">การชำระเงิน</h2>
                    <p class="section-sub mb-0">เลือกช่องทางชำระ — แนบสลิปแล้ว PO จะถูกบันทึกเป็น «จ่ายแล้ว»</p>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-12">
                    <label class="po-field-label po-field-label--thai d-block mb-2">ช่องทางชำระ</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="payment_method" id="payMethodTransfer" value="transfer" checked>
                        <label class="form-check-label" for="payMethodTransfer">โอนเงิน / ช่องทางอื่น <span class="text-muted small">(แนบหลักฐาน)</span></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="payment_method" id="payMethodCash" value="cash">
                        <label class="form-check-label" for="payMethodCash">เงินสด</label>
                    </div>
                </div>
                <div class="col-md-6 d-none" id="poCreateCashWrap">
                    <label class="po-field-label po-field-label--thai" for="payment_cash_paid_by">จ่ายโดย <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="payment_cash_paid_by" id="payment_cash_paid_by" maxlength="255" placeholder="เช่น ชื่อผู้รับเงิน / แผนก" autocomplete="off">
                    <div class="form-text">บังคับเมื่อเลือกเงินสด — เก็บในฐานข้อมูลพร้อม PO</div>
                </div>
                <div class="col-md-6" id="poCreateSlipWrap">
                    <label class="po-field-label po-field-label--thai" for="payment_slips">แนบสลิป / หลักฐานการจ่าย</label>
                    <input type="file" name="payment_slips[]" id="payment_slips" class="form-control" accept="image/*,.pdf" multiple>
                    <div class="form-text" id="poCreateSlipHint">เลือกได้หลายไฟล์ (รูปหรือ PDF) — ถ้าแนบแล้ว PO จะถูกบันทึกเป็น «จ่ายแล้ว»</div>
                </div>
            </div>
            <input type="hidden" name="billed_total_amount" id="billed_total_amount" value="<?= htmlspecialchars(number_format((float) $prVatPrintCreate['net_amount'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="billed_vat_amount" id="billed_vat_amount" value="<?= htmlspecialchars(number_format((float) $prVatPrintCreate['vat_amount'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="card card-soft p-4 p-md-4 mb-4">
            <label class="po-field-label po-field-label--thai" for="po_note">หมายเหตุใบสั่งซื้อ</label>
            <div class="form-text text-muted mb-2">เลือกผู้ขายแล้ว ระบบจะถามว่าต้องการใส่ข้อมูลบัญชีโอนลงหมายเหตุหรือไม่ (ถ้ามีในระบบ)</div>
            <textarea name="po_note" id="po_note" class="form-control" rows="2" maxlength="500" placeholder="หมายเหตุ (ถ้ามี)"></textarea>
        </div>

        <div class="card card-soft p-0 mb-4 overflow-hidden">
            <div class="p-3 p-md-4 pb-0">
                <div class="po-section-head mb-3">
                    <div>
                        <h2 class="section-title mb-0">รายการสินค้า</h2>
                        <p class="section-sub mb-0">ดึงจาก PR เป็นค่าเริ่มต้น — แก้ไขรายการ ราคา ส่วนลด และ VAT ได้ก่อนยืนยัน PO</p>
                    </div>
                </div>
                <div class="table-responsive po-table-wrap po-line-table-mobile">
                    <table class="table align-middle mb-0 po-line-table" id="poTable">
                        <thead>
                            <tr>
                                <th style="width:3rem;" class="text-center">#</th>
                                <th>รายการ</th>
                                <th style="width:6.5rem;" class="text-end">จำนวน</th>
                                <th style="width:6.5rem;" class="text-center">หน่วย</th>
                                <th style="width:7.5rem;" class="text-end">ราคา/หน่วย</th>
                                <th style="width:6.5rem;" class="text-end">ส่วนลด</th>
                                <th style="width:3.5rem;" class="text-center" title="คิด VAT">VAT</th>
                                <th style="width:7.5rem;" class="text-end">ยอดรวม</th>
                                <th style="width:2.75rem;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($pr_prefill_items_display === []): ?>
                            <tr class="po-line-empty">
                                <td colspan="9" class="text-center text-muted py-4">ไม่มีรายการในใบขอซื้อ — กด «เพิ่มรายการ» ด้านล่าง หรือ <a href="<?= htmlspecialchars($pr_edit_url, ENT_QUOTES, 'UTF-8') ?>">แก้ไข PR</a></td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($pr_prefill_items_display as $idx => $prItem): ?>
                            <?php
                            $prefillQty = (float) ($prItem['quantity'] ?? 0);
                            $prefillPrice = (float) ($prItem['unit_price'] ?? 0);
                            $maxQtyRemaining = (float) ($prItem['_pr_qty_remaining'] ?? $prefillQty);
                            $discDisplay = tnc_po_create_pr_discount_label($prItem);
                            $prefillLineTotal = tnc_po_create_pr_line_total($prItem);
                            $unitCell = trim((string) ($prItem['unit'] ?? ''));
                            $vatApplyChecked = (int) ($prItem['vat_exempt'] ?? 0) !== 1;
                            ?>
                            <tr>
                                <td class="po-cell-idx row-number text-secondary small fw-semibold">
                                    <div class="po-mobile-item-head">
                                        <span class="po-mobile-item-label">รายการที่ <span class="po-mobile-item-no"><?= $idx + 1 ?></span></span>
                                        <?php if ($idx > 0): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm border-0 po-row-delete-btn po-row-delete-mobile" title="ลบแถว" aria-label="ลบแถว"><i class="bi bi-trash-fill"></i></button>
                                        <?php endif; ?>
                                    </div>
                                    <span class="d-none d-lg-inline po-mobile-item-no"><?= $idx + 1 ?></span>
                                </td>
                                <td class="po-cell-desc" data-label="รายการ"><input type="text" name="item_description[]" class="form-control form-control-sm" required value="<?= htmlspecialchars((string) ($prItem['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td class="po-cell-qty" data-label="จำนวน"><input type="number" name="item_qty[]" class="form-control form-control-sm qty text-end" step="any" min="0" max="<?= htmlspecialchars((string) $maxQtyRemaining, ENT_QUOTES, 'UTF-8') ?>" data-max-qty="<?= htmlspecialchars((string) $maxQtyRemaining, ENT_QUOTES, 'UTF-8') ?>" required value="<?= htmlspecialchars((string) $prefillQty, ENT_QUOTES, 'UTF-8') ?>" oninput="calculateTotal()" title="คงเหลือใน PR: <?= htmlspecialchars((string) $maxQtyRemaining, ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td class="po-cell-unit text-center" data-label="หน่วย"><input type="text" name="item_unit[]" class="form-control form-control-sm" value="<?= htmlspecialchars($unitCell, ENT_QUOTES, 'UTF-8') ?>"></td>
                                <td class="po-cell-price" data-label="ราคา/หน่วย"><input type="number" name="item_price[]" class="form-control form-control-sm price text-end" step="any" min="0" required value="<?= $prefillPrice > 0 ? htmlspecialchars((string) $prefillPrice, ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="0" oninput="calculateTotal()"></td>
                                <td class="po-cell-disc" data-label="ส่วนลด"><input type="text" name="item_discount[]" class="form-control form-control-sm po-discount text-end" maxlength="20" value="<?= htmlspecialchars($discDisplay, ENT_QUOTES, 'UTF-8') ?>" oninput="calculateTotal()"></td>
                                <td class="po-cell-vat-exempt text-center" data-label="คิด VAT">
                                    <input type="hidden" class="line-vat-exempt-val" name="item_vat_exempt[]" value="<?= $vatApplyChecked ? '0' : '1' ?>">
                                    <input type="checkbox" class="form-check-input line-vat-apply m-0" value="1" title="คิด VAT รายการนี้" aria-label="คิด VAT" onchange="tncPurchaseSyncVatApplyHidden(this); calculateTotal();"<?= $vatApplyChecked ? ' checked' : '' ?>>
                                </td>
                                <td class="po-cell-total" data-label="ยอดรวม"><input type="text" class="form-control form-control-sm row-total text-end bg-light fw-semibold" value="<?= number_format($prefillLineTotal, 2, '.', '') ?>" readonly tabindex="-1"></td>
                                <td class="po-cell-action po-cell-action-desktop"><?php if ($idx > 0): ?><button type="button" class="btn btn-outline-danger btn-sm border-0 po-row-delete-btn" title="ลบแถว" aria-label="ลบแถว"><i class="bi bi-trash-fill"></i></button><?php endif; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="po-actions-bar px-0 pb-3">
                    <button type="button" class="btn btn-orange btn-sm rounded-pill px-3 shadow-sm" onclick="addRow()">
                        <i class="bi bi-plus-lg me-1"></i>เพิ่มรายการ
                    </button>
                </div>
            </div>

            <div class="row g-4 mt-0 px-3 px-md-4 pb-4">
                <div class="col-lg-7 order-2 order-lg-1">
                    <div class="po-vat-panel p-3 mb-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="vat_enabled" id="vat_enabled" value="1" onchange="updatePoVatBasisUi(); calculateTotal()"<?= $pr_vat_enabled === 1 ? ' checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="vat_enabled">มี VAT 7%</label>
                        </div>
                        <input type="hidden" name="vat_mode" id="vat_mode" value="<?= htmlspecialchars($pr_vat_mode, ENT_QUOTES, 'UTF-8') ?>">
                        <div id="vat_basis_wrap" class="pt-2 border-top border-secondary border-opacity-25">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_inclusive" value="inclusive" onchange="calculateTotal()"<?= $pr_vat_mode === 'inclusive' ? ' checked' : '' ?>>
                                <label class="form-check-label" for="vat_basis_inclusive">รวม VAT <span class="text-muted small">(รวมภาษีมูลค่าเพิ่มในราคารวม)</span></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_exclusive" value="exclusive" onchange="calculateTotal()"<?= $pr_vat_mode !== 'inclusive' ? ' checked' : '' ?>>
                                <label class="form-check-label" for="vat_basis_exclusive">แยก VAT <span class="text-muted small">(บวกภาษีมูลค่าเพิ่มแยกจากราคารวม)</span></label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 order-1 order-lg-2">
                    <div class="summary-box po-summary-sticky">
                        <div class="summary-line small text-muted"><span class="summary-label" id="subtotal_label">ยอดรายการ</span><strong class="summary-value text-end"><span id="subtotal_display"><?= number_format((float) $prVatPrintCreate['line_amount'], 2) ?></span> บาท</strong></div>
                        <div class="summary-line small text-muted d-none" id="vat_exempt_row"><span class="summary-label">ไม่คิด VAT</span><strong class="summary-value text-end"><span id="vat_exempt_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small" id="vat_row" style="<?= $pr_vat_enabled ? 'display:grid' : 'display:none' ?>;"><span class="summary-label" id="vat_label"><?= $pr_vat_enabled ? htmlspecialchars((string) $prVatPrintCreate['vat_label'], ENT_QUOTES, 'UTF-8') : 'ภาษีมูลค่าเพิ่ม' ?></span><strong class="summary-value"><span id="vat_display"><?= number_format((float) $prVatPrintCreate['vat_amount'], 2) ?></span> บาท</strong></div>
                        <div class="summary-line summary-grand fw-bold"><span class="summary-label">ยอดสุทธิ</span><span class="summary-value text-tnc-orange"><span id="grand_total"><?= number_format((float) $prVatPrintCreate['net_amount'], 2) ?></span> บาท</span></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="po-submit-panel mb-2 tnc-mobile-sticky-cta d-lg-none">
            <div class="tnc-mobile-sticky-inner">
                <div class="tnc-mobile-sticky-meta">
                    <div class="tnc-mobile-sticky-label">ยอดสุทธิ</div>
                    <div class="tnc-mobile-sticky-total" id="grand_total_sticky"><?= number_format((float) $prVatPrintCreate['net_amount'], 2) ?></div>
                </div>
                <div class="tnc-mobile-sticky-actions">
                    <button type="submit" class="btn btn-orange btn-lg po-submit-btn rounded-pill"<?= $po_submit_disabled ? ' disabled' : '' ?>>
                        <i class="bi bi-check2-circle me-1"></i>สร้าง PO
                    </button>
                </div>
            </div>
        </div>

        <div class="card card-soft po-submit-panel mb-2 d-none d-lg-block">
            <div class="d-flex flex-wrap align-items-center justify-content-end gap-3">
                <button type="submit" class="btn btn-orange btn-lg po-submit-btn rounded-pill"<?= $po_submit_disabled ? ' disabled' : '' ?>>
                    <i class="bi bi-check2-circle me-2"></i>ยืนยันสร้างใบสั่งซื้อ
                </button>
            </div>
        </div>
    </form>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?= htmlspecialchars(tnc_asset_href('assets/js/purchase-vat-calc.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function () {
    const issueDateEl = document.getElementById('issue_date');
    if (issueDateEl && typeof flatpickr === 'function') {
        flatpickr(issueDateEl, {
            dateFormat: 'd/m/Y',
            defaultDate: issueDateEl.value || 'today',
            allowInput: true,
        });
    }

    function normalizeIssueDateForSubmit() {
        if (!issueDateEl) {
            return true;
        }
        const raw = (issueDateEl.value || '').trim();
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
        issueDateEl.value = yyyy + '-' + String(mm).padStart(2, '0') + '-' + String(dd).padStart(2, '0');
        return true;
    }

    const payMethodTransfer = document.getElementById('payMethodTransfer');
    const payMethodCash = document.getElementById('payMethodCash');
    const poCreateCashWrap = document.getElementById('poCreateCashWrap');
    const paymentCashPaidBy = document.getElementById('payment_cash_paid_by');
    const poCreateSlipHint = document.getElementById('poCreateSlipHint');

    function syncPoCreatePaymentUi() {
        const isCash = !!(payMethodCash && payMethodCash.checked);
        if (poCreateCashWrap) {
            poCreateCashWrap.classList.toggle('d-none', !isCash);
        }
        if (paymentCashPaidBy) {
            paymentCashPaidBy.required = isCash;
            if (!isCash) {
                paymentCashPaidBy.value = '';
            }
        }
        if (poCreateSlipHint) {
            poCreateSlipHint.textContent = isCash
                ? 'ไม่บังคับเมื่อเลือกเงินสด — แนบได้ถ้ามีใบเสร็จหรือหลักฐานเพิ่มเติม'
                : 'เลือกได้หลายไฟล์ (รูปหรือ PDF) — ถ้าแนบแล้ว PO จะถูกบันทึกเป็น «จ่ายแล้ว»';
        }
    }
    payMethodTransfer?.addEventListener('change', syncPoCreatePaymentUi);
    payMethodCash?.addEventListener('change', syncPoCreatePaymentUi);
    syncPoCreatePaymentUi();

    const poCreateForm = issueDateEl ? issueDateEl.closest('form') : document.querySelector('form[data-tnc-fullnav="1"]');
    if (poCreateForm) {
        poCreateForm.addEventListener('submit', function (e) {
            if (!normalizeIssueDateForSubmit()) {
                e.preventDefault();
                alert('กรุณากรอกวันที่ออกใบสั่งซื้อเป็น วัน/เดือน/ปี เช่น 31/05/2026');
                issueDateEl && issueDateEl.focus();
                return;
            }
            if (payMethodCash && payMethodCash.checked) {
                const paidBy = (paymentCashPaidBy?.value || '').trim();
                if (!paidBy) {
                    e.preventDefault();
                    alert('กรุณากรอก «จ่ายโดย» เมื่อเลือกชำระด้วยเงินสด');
                    paymentCashPaidBy?.focus();
                }
            }
        });
    }
})();

function addRow() {
    const tbody = document.getElementById('poTable')?.getElementsByTagName('tbody')[0];
    if (!tbody) {
        return;
    }
    const emptyRow = tbody.querySelector('.po-line-empty');
    if (emptyRow) {
        emptyRow.remove();
    }
    const newRow = tbody.insertRow();
    const rowCount = tbody.rows.length;
    newRow.innerHTML = '<td class="po-cell-idx row-number text-secondary small fw-semibold">' +
        '<div class="po-mobile-item-head">' +
        '<span class="po-mobile-item-label">รายการที่ <span class="po-mobile-item-no">' + rowCount + '</span></span>' +
        '<button type="button" class="btn btn-outline-danger btn-sm border-0 po-row-delete-btn po-row-delete-mobile" title="ลบแถว" aria-label="ลบแถว"><i class="bi bi-trash-fill"></i></button>' +
        '</div>' +
        '<span class="d-none d-lg-inline po-mobile-item-no">' + rowCount + '</span>' +
        '</td>' +
        '<td class="po-cell-desc" data-label="รายการ"><input type="text" name="item_description[]" class="form-control form-control-sm" required></td>' +
        '<td class="po-cell-qty" data-label="จำนวน"><input type="number" name="item_qty[]" class="form-control form-control-sm qty text-end" step="any" min="0" required oninput="calculateTotal()"></td>' +
        '<td class="po-cell-unit text-center" data-label="หน่วย"><input type="text" name="item_unit[]" class="form-control form-control-sm"></td>' +
        '<td class="po-cell-price" data-label="ราคา/หน่วย"><input type="number" name="item_price[]" class="form-control form-control-sm price text-end" step="any" min="0" required placeholder="0" oninput="calculateTotal()"></td>' +
        '<td class="po-cell-disc" data-label="ส่วนลด"><input type="text" name="item_discount[]" class="form-control form-control-sm po-discount text-end" maxlength="20" oninput="calculateTotal()"></td>' +
        '<td class="po-cell-vat-exempt text-center" data-label="คิด VAT">' +
        '<input type="hidden" class="line-vat-exempt-val" name="item_vat_exempt[]" value="0">' +
        '<input type="checkbox" class="form-check-input line-vat-apply m-0" value="1" checked title="คิด VAT รายการนี้" aria-label="คิด VAT" onchange="tncPurchaseSyncVatApplyHidden(this); calculateTotal();">' +
        '</td>' +
        '<td class="po-cell-total" data-label="ยอดรวม"><input type="text" class="form-control form-control-sm row-total text-end bg-light fw-semibold" value="0.00" readonly tabindex="-1"></td>' +
        '<td class="po-cell-action po-cell-action-desktop"><button type="button" class="btn btn-outline-danger btn-sm border-0 po-row-delete-btn" title="ลบแถว" aria-label="ลบแถว"><i class="bi bi-trash-fill"></i></button></td>';
    calculateTotal();
    const submitBtn = document.querySelector('.po-submit-btn');
    if (submitBtn) {
        submitBtn.disabled = false;
    }
}
function removeRow(btn) {
    const row = btn.closest('tr');
    if (!row || !row.parentNode) {
        return;
    }
    row.remove();
    updateRowNumbers();
    calculateTotal();
}
function updateRowNumbers() {
    document.querySelectorAll('#poTable tbody tr:not(.po-line-empty)').forEach(function (row, index) {
        row.querySelectorAll('.po-mobile-item-no').forEach(function (el) {
            el.innerText = index + 1;
        });
    });
}
function poLineAmountAfterDiscount(qty, price, discRaw) {
    const q = parseFloat(String(qty || '').replace(/,/g, '')) || 0;
    const p = parseFloat(String(price || '').replace(/,/g, '')) || 0;
    const base = Math.round(q * p * 100) / 100;
    const dRaw = String(discRaw || '').trim();
    let discount = 0;
    if (dRaw !== '' && base > 0) {
        const pctMatch = dRaw.match(/^([0-9]+(?:\.[0-9]+)?)\s*%$/);
        if (pctMatch) {
            let pct = parseFloat(pctMatch[1]) || 0;
            if (pct < 0) pct = 0;
            if (pct > 100) pct = 100;
            discount = Math.round(base * pct / 100 * 100) / 100;
        } else {
            discount = Math.min(base, Math.round((parseFloat(dRaw.replace(/,/g, '')) || 0) * 100) / 100);
        }
    }
    return Math.round((base - discount) * 100) / 100;
}
function updatePoVatBasisUi() {
    const vatBasisWrap = document.getElementById('vat_basis_wrap');
    const vatEnabled = document.getElementById('vat_enabled');
    if (!vatBasisWrap || !vatEnabled) {
        return;
    }
    const on = vatEnabled.checked;
    vatBasisWrap.classList.toggle('opacity-50', !on);
    vatBasisWrap.style.pointerEvents = on ? '' : 'none';
    vatBasisWrap.setAttribute('aria-disabled', on ? 'false' : 'true');
    vatBasisWrap.querySelectorAll('input[name="vat_basis"]').forEach(function (el) {
        el.disabled = !on;
    });
}
function calculateTotal() {
    const vatModeInput = document.getElementById('vat_mode');
    const vatEnabledEl = document.getElementById('vat_enabled');
    const vatOn = !!(vatEnabledEl && vatEnabledEl.checked);
    let vatMode = 'exclusive';
    if (vatOn) {
        const selectedBasis = document.querySelector('input[name="vat_basis"]:checked');
        vatMode = selectedBasis ? selectedBasis.value : 'exclusive';
    }
    if (vatModeInput) {
        vatModeInput.value = vatMode;
    }
    let taxableSum = 0;
    let exemptSum = 0;
    const tbody = document.getElementById('poTable')?.getElementsByTagName('tbody')[0];
    const rows = tbody ? tbody.rows : [];
    for (const row of rows) {
        if (row.classList.contains('po-line-empty')) {
            continue;
        }
        const qtyEl = row.querySelector('.qty');
        const priceEl = row.querySelector('.price');
        const discEl = row.querySelector('.po-discount');
        const total = poLineAmountAfterDiscount(
            qtyEl ? qtyEl.value : 0,
            priceEl ? priceEl.value : 0,
            discEl ? discEl.value : ''
        );
        const totalCell = row.querySelector('.row-total');
        if (totalCell) {
            totalCell.value = total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        const applyEl = row.querySelector('.line-vat-apply');
        if (applyEl && typeof tncPurchaseSyncVatApplyHidden === 'function') {
            tncPurchaseSyncVatApplyHidden(applyEl);
        }
        const isExempt = (typeof tncPurchaseLineIsVatExempt === 'function')
            ? tncPurchaseLineIsVatExempt(row)
            : (applyEl ? !applyEl.checked : (row.querySelector('.line-vat-exempt-val')?.value === '1'));
        if (isExempt) {
            exemptSum += total;
        } else {
            taxableSum += total;
        }
    }
    taxableSum = Math.round(taxableSum * 100) / 100;
    exemptSum = Math.round(exemptSum * 100) / 100;
    const splitFn = typeof tncPurchaseVatFromLineSums === 'function'
        ? tncPurchaseVatFromLineSums
        : function (t, e, v, m) { return tncPurchaseVatFromLineSum(t + e, v, m); };
    const split = splitFn(taxableSum, exemptSum, vatOn, vatMode);
    const subtotal = split.subtotal;
    const vat = split.vat;
    const gross = split.gross;
    const subtotalLabelEl = document.getElementById('subtotal_label');
    if (subtotalLabelEl) {
        subtotalLabelEl.textContent = vatOn && exemptSum > 0 ? 'ยอดรายการ (คิด VAT)' : 'ยอดรายการ';
    }
    const subtotalDisplay = document.getElementById('subtotal_display');
    if (subtotalDisplay) {
        subtotalDisplay.innerText = subtotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    const vatExemptRow = document.getElementById('vat_exempt_row');
    const vatExemptDisplay = document.getElementById('vat_exempt_display');
    if (vatExemptRow && vatExemptDisplay) {
        if (vatOn && exemptSum > 0) {
            vatExemptRow.classList.remove('d-none');
            vatExemptDisplay.textContent = exemptSum.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } else {
            vatExemptRow.classList.add('d-none');
        }
    }
    const vatLabelEl = document.getElementById('vat_label');
    if (vatLabelEl) {
        vatLabelEl.textContent = vatOn ? (vatMode === 'inclusive' ? 'รวม VAT' : 'แยก VAT') : 'ภาษีมูลค่าเพิ่ม';
    }
    const vatRow = document.getElementById('vat_row');
    const vatDisplay = document.getElementById('vat_display');
    if (vatOn) {
        if (vatRow) vatRow.style.display = 'grid';
        if (vatDisplay) {
            vatDisplay.innerText = vat.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    } else if (vatRow) {
        vatRow.style.display = 'none';
    }
    const grandTotalEl = document.getElementById('grand_total');
    if (grandTotalEl) {
        grandTotalEl.innerText = gross.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    const billedTotalEl = document.getElementById('billed_total_amount');
    const billedVatEl = document.getElementById('billed_vat_amount');
    if (billedTotalEl) billedTotalEl.value = gross.toFixed(2);
    if (billedVatEl) billedVatEl.value = vat.toFixed(2);
    updatePoVatBasisUi();
}
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.line-vat-apply').forEach(function (cb) {
        if (typeof tncPurchaseSyncVatApplyHidden === 'function') {
            tncPurchaseSyncVatApplyHidden(cb);
        }
    });
    updatePoVatBasisUi();
    calculateTotal();
    const poTable = document.getElementById('poTable');
    if (poTable) {
        poTable.addEventListener('click', function (e) {
            const btn = e.target.closest('.po-row-delete-btn');
            if (!btn) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            removeRow(btn);
        });
    }
});

const poCreateErrorCode = <?= json_encode($errorCode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
(function () {
    if (!poCreateErrorCode) return;
    const messages = {
        no_items: 'กรุณาเพิ่มรายการอย่างน้อย 1 รายการ และกรอกจำนวน/ราคาให้ถูกต้อง',
        invalid_items: 'กรุณาเพิ่มรายการอย่างน้อย 1 รายการ และกรอกจำนวน/ราคาให้ถูกต้อง',
        contract: 'ไม่พบข้อมูลสัญญาจ้างที่อ้างอิง กรุณาตรวจสอบใหม่',
        supplier: 'กรุณาเลือกผู้ขายจากรายการที่ระบบแนะนำ',
        cash_paid_by_required: 'กรุณากรอก «จ่ายโดย» เมื่อเลือกชำระด้วยเงินสด',
        payment_slip_required: 'กรุณาแนบสลิปหรือหลักฐานการจ่ายอย่างน้อย 1 ไฟล์',
        upload_failed: 'อัปโหลดสลิปไม่สำเร็จ กรุณาลองใหม่',
        upload_type: 'อัปโหลดสลิปไม่สำเร็จ — ใช้ไฟล์รูปหรือ PDF',
        billing_required: 'กรุณากรอกวันที่ออกใบสั่งซื้อให้ถูกต้อง',
        quotation_required: 'เมื่อระบุว่ามีใบเสนอราคา กรุณากรอกเลขที่ QT หรือแนบไฟล์อย่างน้อยหนึ่งอย่าง',
        quotation_upload_failed: 'อัปโหลดไฟล์ใบเสนอราคาไม่สำเร็จ กรุณาลองใหม่',
        quotation_upload_type: 'ไฟล์ใบเสนอราคาต้องเป็น PDF หรือรูปภาพ (JPG, PNG, WEBP, GIF ฯลฯ)'
    };
    const text = messages[poCreateErrorCode] || 'บันทึกใบ PO ไม่สำเร็จ กรุณาลองใหม่';
    Swal.fire({
        icon: 'error',
        title: 'บันทึกไม่สำเร็จ',
        text: text,
        confirmButtonText: 'ตกลง'
    });
})();

const SUPPLIER_PAYMENT_MAP = <?= json_encode($supplier_payment_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

(function () {
    const searchInput = document.getElementById('supplier_search');
    const supplierIdInput = document.getElementById('supplier_id');
    const poNoteEl = document.getElementById('po_note');
    const datalist = document.getElementById('supplier_list');
    if (!searchInput || !supplierIdInput || !datalist) {
        return;
    }

    let lastPromptedSupplierId = '';
    let autoInsertedNote = '';

    function syncSupplierId() {
        const typed = (searchInput.value || '').trim();
        if (typed === '') {
            supplierIdInput.value = '';
            return '';
        }
        const options = datalist.querySelectorAll('option');
        let matchedId = '';
        options.forEach((opt) => {
            const optValue = (opt.value || '').trim();
            if (matchedId === '' && optValue.toLowerCase() === typed.toLowerCase()) {
                matchedId = (opt.getAttribute('data-id') || '').trim();
            }
        });
        supplierIdInput.value = matchedId;
        return matchedId;
    }

    function escHtml(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function buildPaymentInfoHtml(info) {
        const rows = [];
        if (info.bank) {
            const logo = info.bank_logo
                ? '<img src="' + escHtml(info.bank_logo) + '" alt="" style="width:22px;height:22px;object-fit:contain;border-radius:4px;vertical-align:middle;margin-right:6px;">'
                : '';
            rows.push('<div class="mb-1"><span class="text-muted small">ธนาคาร</span><div class="fw-semibold">' + logo + escHtml(info.bank) + '</div></div>');
        }
        if (info.account_name) {
            rows.push('<div class="mb-1"><span class="text-muted small">ชื่อบัญชี</span><div class="fw-semibold">' + escHtml(info.account_name) + '</div></div>');
        }
        if (info.account_number) {
            rows.push('<div class="mb-0"><span class="text-muted small">เลขที่บัญชี</span><div class="fw-semibold font-monospace">' + escHtml(info.account_number) + '</div></div>');
        }
        return '<div class="text-start small">' +
            '<div class="mb-2 fw-bold text-dark">' + escHtml(info.name) + '</div>' +
            '<div class="p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0;">' + rows.join('') + '</div>' +
            '</div>';
    }

    function stripAutoInsertedNote(note) {
        let text = String(note || '');
        if (autoInsertedNote && text.indexOf(autoInsertedNote) >= 0) {
            text = text.replace(autoInsertedNote, '').replace(/^\s*\n+/, '').replace(/\n+\s*$/, '');
        }
        return text.trim();
    }

    function applyPaymentNote(noteText) {
        if (!poNoteEl || !noteText) return;
        const base = stripAutoInsertedNote(poNoteEl.value);
        autoInsertedNote = noteText;
        poNoteEl.value = base === '' ? noteText : (base + '\n\n' + noteText);
        if (poNoteEl.value.length > 500) {
            poNoteEl.value = poNoteEl.value.slice(0, 500);
        }
    }

    function maybePromptSupplierPayment(supplierId) {
        if (!supplierId || supplierId === lastPromptedSupplierId) {
            return;
        }
        const info = SUPPLIER_PAYMENT_MAP[supplierId];
        if (!info || !info.note_text) {
            lastPromptedSupplierId = supplierId;
            return;
        }
        lastPromptedSupplierId = supplierId;
        if (typeof Swal === 'undefined') {
            return;
        }
        Swal.fire({
            icon: 'question',
            title: 'ใส่ข้อมูลบัญชีในหมายเหตุ PO?',
            html: '<p class="small text-muted mb-3">ผู้ขายรายนี้มีข้อมูลบัญชีรับโอน — ต้องการใส่ลงหมายเหตุใบสั่งซื้อหรือไม่</p>' + buildPaymentInfoHtml(info),
            showCancelButton: true,
            confirmButtonText: 'ใส่ในหมายเหตุ',
            cancelButtonText: 'ไม่ใส่',
            confirmButtonColor: '#ea580c',
            reverseButtons: true,
            focusCancel: true,
            width: '28rem',
        }).then(function (result) {
            if (result.isConfirmed) {
                applyPaymentNote(info.note_text);
            }
        });
    }

    function onSupplierChange() {
        const matchedId = syncSupplierId();
        if (!matchedId) {
            lastPromptedSupplierId = '';
            return;
        }
        maybePromptSupplierPayment(matchedId);
    }

    searchInput.addEventListener('input', syncSupplierId);
    searchInput.addEventListener('change', onSupplierChange);

    const form = searchInput.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            syncSupplierId();
        });
    }
})();

</script>

<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
