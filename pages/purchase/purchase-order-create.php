<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/line_pr_approval.php';
require_once dirname(__DIR__, 2) . '/includes/banks.php';
require_once dirname(__DIR__, 2) . '/includes/suppliers.php';

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

$existingPoFromPr = Db::findFirst('purchase_orders', static function (array $r) use ($pr_id): bool {
    return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
});
if ($existingPoFromPr !== null) {
    header('Location: ' . app_path('pages/purchase/purchase-order-view.php') . '?id=' . (int) ($existingPoFromPr['id'] ?? 0));
    exit();
}

$pr_number_display = trim((string) ($pr['pr_number'] ?? ('PR-' . $pr_id)));
$pr_vat_enabled = (int) ($pr['vat_enabled'] ?? 0) === 1 ? 1 : 0;
$pr_vat_mode = trim((string) ($pr['vat_mode'] ?? 'exclusive'));
if (!in_array($pr_vat_mode, ['exclusive', 'inclusive'], true)) {
    $pr_vat_mode = 'exclusive';
}

$pr_prefill_items = Db::filter('purchase_request_items', static function (array $r) use ($pr_id): bool {
    return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
});
Db::sortRows($pr_prefill_items, 'id', false);
$pr_prefill_items_display = [];
foreach ($pr_prefill_items as $prItemRow) {
    if (trim((string) ($prItemRow['description'] ?? '')) !== '') {
        $pr_prefill_items_display[] = $prItemRow;
    }
}

$po_number = Purchase::generatePONumber();
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
$prCostCategoryName = trim((string) ($pr['cost_category_name'] ?? ''));
if ($prCostCategoryName === '' && $prCostCategoryId > 0) {
    require_once dirname(__DIR__, 2) . '/includes/site_cost_categories.php';
    $prCostCategoryName = tnc_site_category_name($prCostCategoryId);
}

$po_submit_disabled = $pr_prefill_items_display === [];
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สร้างใบสั่งซื้อ (PO)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/purchase-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
<body class="purchase-module tnc-app-body">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container container-lg py-4 py-md-5 mb-5 po-create-wrap">
    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=create_po_from_pr" method="POST" enctype="multipart/form-data" data-tnc-fullnav="1">
        <?php csrf_field(); ?>
        <input type="hidden" name="pr_id" value="<?= $pr_id ?>">
        <input type="hidden" name="vat_enabled" id="vat_enabled" value="<?= $pr_vat_enabled ?>">
        <input type="hidden" name="vat_mode" id="vat_mode" value="<?= htmlspecialchars($pr_vat_mode, ENT_QUOTES, 'UTF-8') ?>">
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
                    </div>
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
                    <p class="section-sub">กรอกวันที่และผู้ขาย — สถานที่และหมวดหมู่ดึงจาก PR อัตโนมัติ</p>
                </div>
            </div>

            <div class="row g-3 g-md-4 po-meta-primary align-items-end">
                <div class="col-lg-6">
                    <label class="po-field-label po-field-label--thai" for="issue_date">วันที่ออกใบสั่งซื้อ <span class="text-danger">*</span></label>
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
                <div class="col-lg-6">
                    <label class="po-field-label po-field-label--thai" for="supplier_search">ผู้ขาย / แหล่งซื้อ <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text text-secondary"><i class="bi bi-shop"></i></span>
                        <input type="text" id="supplier_search" class="form-control" list="supplier_list" required autocomplete="off" placeholder="พิมพ์ชื่อผู้ขายเพื่อเลือก">
                    </div>
                    <datalist id="supplier_list">
                        <?php foreach ($supplier_rows as $s): ?>
                            <option
                                value="<?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-id="<?= (int) ($s['id'] ?? 0) ?>"
                            ></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="supplier_id" id="supplier_id">
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

            <div class="po-meta-section">
                <div class="po-meta-optional">
                    <div class="row g-3 align-items-start">
                        <div class="col-md-6">
                            <label class="po-field-label po-field-label--thai" for="supplier_invoice_no">เลขที่บิล / ใบกำกับภาษี</label>
                            <div class="input-group">
                                <span class="input-group-text text-secondary"><i class="bi bi-hash"></i></span>
                                <input type="text" name="supplier_invoice_no" id="supplier_invoice_no" class="form-control" maxlength="120">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="po-field-label po-field-label--thai" for="payment_slips">แนบสลิป / หลักฐานการจ่าย</label>
                            <input type="file" name="payment_slips[]" id="payment_slips" class="form-control" accept="image/*,.pdf" multiple>
                        </div>
                    </div>
                </div>
            </div>

            <input type="hidden" name="billed_total_amount" id="billed_total_amount" value="<?= htmlspecialchars(number_format((float) $prVatPrintCreate['net_amount'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="billed_vat_amount" id="billed_vat_amount" value="<?= htmlspecialchars(number_format((float) $prVatPrintCreate['vat_amount'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="payment_method" value="transfer">
        </div>

        <div class="card card-soft p-0 mb-4 overflow-hidden po-items-readonly">
            <div class="p-3 p-md-4">
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
                                <th style="width:7.5rem;" class="text-end">ยอดรวม</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($pr_prefill_items_display === []): ?>
                            <tr class="po-line-empty">
                                <td colspan="7" class="text-center text-muted py-4">ไม่มีรายการในใบขอซื้อ — <a href="<?= htmlspecialchars($pr_edit_url, ENT_QUOTES, 'UTF-8') ?>">เพิ่มรายการที่ PR</a></td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($pr_prefill_items_display as $idx => $prItem): ?>
                            <?php
                            $prefillQty = (float) ($prItem['quantity'] ?? 0);
                            $prefillPrice = (float) ($prItem['unit_price'] ?? 0);
                            $discDisplay = tnc_po_create_pr_discount_label($prItem);
                            $prefillLineTotal = tnc_po_create_pr_line_total($prItem);
                            $unitCell = trim((string) ($prItem['unit'] ?? ''));
                            ?>
                            <tr data-pr-total="<?= htmlspecialchars(number_format($prefillLineTotal, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                                <td class="po-cell-idx text-center text-secondary small fw-semibold">
                                    <div class="po-mobile-item-head">
                                        <span class="po-mobile-item-label">รายการที่ <span class="po-mobile-item-no"><?= $idx + 1 ?></span></span>
                                    </div>
                                    <span class="d-none d-lg-inline po-mobile-item-no"><?= $idx + 1 ?></span>
                                </td>
                                <td class="po-cell-desc cell-desc" data-label="รายการ"><?= htmlspecialchars((string) ($prItem['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="po-cell-qty cell-num" data-label="จำนวน"><?= number_format($prefillQty, 2) ?></td>
                                <td class="po-cell-unit text-center text-muted" data-label="หน่วย"><?= $unitCell !== '' ? htmlspecialchars($unitCell, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                <td class="po-cell-price cell-num" data-label="ราคา/หน่วย"><?= number_format($prefillPrice, 2) ?></td>
                                <td class="po-cell-disc cell-num<?= $discDisplay !== '' ? ' fw-semibold' : ' text-muted' ?>" data-label="ส่วนลด"><?= $discDisplay !== '' ? htmlspecialchars($discDisplay, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                <td class="po-cell-total cell-num fw-bold" data-label="ยอดรวม"><?= number_format($prefillLineTotal, 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="row g-4 mt-0 px-3 px-md-4 pb-4">  
                <div class="col-lg-7 order-2 order-lg-1">
                    <div class="po-vat-panel p-3 mb-3">
                        <?php if ($pr_vat_enabled): ?>
                        <div class="small mb-2">
                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                <?= $pr_vat_mode === 'inclusive' ? 'รวม VAT' : 'แยก VAT' ?>
                            </span>
                        </div>
                        <?php else: ?>
                        <div class="small text-muted mb-2">ไม่มีภาษีมูลค่าเพิ่มในใบขอซื้อนี้</div>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id, ENT_QUOTES, 'UTF-8') ?>" class="small text-tnc-orange text-decoration-none"><i class="bi bi-pencil-square me-1"></i>ต้องการแก้ไขภาษีมูลค่าเพิ่ม?</a>
                    </div>
                </div>
                <div class="col-lg-5 order-1 order-lg-2">
                    <div class="summary-box po-summary-sticky">
                        <div class="summary-line small text-muted"><span class="summary-label" id="subtotal_label">ยอดรายการ</span><strong class="summary-value text-end"><span id="subtotal_display"><?= number_format((float) $prVatPrintCreate['line_amount'], 2) ?></span> บาท</strong></div>
                        <div class="summary-line small" id="vat_row" style="<?= $pr_vat_enabled ? 'display:grid' : 'display:none' ?>;"><span class="summary-label" id="vat_label"><?= $pr_vat_enabled ? htmlspecialchars((string) $prVatPrintCreate['vat_label'], ENT_QUOTES, 'UTF-8') : 'ภาษีมูลค่าเพิ่ม' ?></span><strong class="summary-value"><span id="vat_display"><?= number_format((float) $prVatPrintCreate['vat_amount'], 2) ?></span> บาท</strong></div>
                        <div class="summary-line summary-grand fw-bold"><span class="summary-label">ยอดสุทธิ</span><span class="summary-value text-tnc-orange"><span id="grand_total"><?= number_format((float) $prVatPrintCreate['net_amount'], 2) ?></span> บาท</span></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-soft p-4 p-md-4 mb-2">
            <label class="po-field-label" for="po_note">หมายเหตุใบสั่งซื้อ</label>
            <textarea name="po_note" id="po_note" class="form-control" rows="3" maxlength="500" placeholder="หมายเหตุใบสั่งซื้อ"></textarea>
        </div>

        <div class="card card-soft po-submit-panel mb-2">
            <div class="d-flex flex-wrap align-items-center justify-content-end gap-3">
                <button type="submit" class="btn btn-orange btn-lg po-submit-btn rounded-pill"<?= $po_submit_disabled ? ' disabled' : '' ?>>
                    <i class="bi bi-check2-circle me-2"></i>ยืนยันสร้างใบสั่งซื้อ
                </button>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
    const issueDateEl = document.getElementById('issue_date');
    if (!issueDateEl || typeof flatpickr !== 'function') {
        return;
    }
    flatpickr(issueDateEl, {
        dateFormat: 'd/m/Y',
        defaultDate: issueDateEl.value || 'today',
        allowInput: true,
    });

    function normalizeIssueDateForSubmit() {
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

    const poCreateForm = issueDateEl.closest('form');
    if (poCreateForm) {
        poCreateForm.addEventListener('submit', function (e) {
            if (!normalizeIssueDateForSubmit()) {
                e.preventDefault();
                alert('กรุณากรอกวันที่ออกใบสั่งซื้อเป็น วัน/เดือน/ปี เช่น 31/05/2026');
                issueDateEl.focus();
            }
        });
    }
})();
const poCreateErrorCode = <?= json_encode($errorCode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
(function () {
    if (!poCreateErrorCode) return;
    const messages = {
        no_items: 'กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ และกรอกจำนวน/ราคาให้ถูกต้อง',
        invalid_items: 'กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ และกรอกจำนวน/ราคาให้ถูกต้อง',
        contract: 'ไม่พบข้อมูลสัญญาจ้างที่อ้างอิง กรุณาตรวจสอบใหม่',
        supplier: 'กรุณาเลือกผู้ขายจากรายการที่ระบบแนะนำ',
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

</body>
</html>
