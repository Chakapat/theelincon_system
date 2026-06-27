<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$hire_contract_id = isset($_GET['hire_contract_id']) ? (int) $_GET['hire_contract_id'] : 0;
$hc = $hire_contract_id > 0 ? Db::row('hire_contracts', (string) $hire_contract_id) : null;
if ($hc === null) {
    header('Location: ' . app_path('pages/purchase/purchase-order-hire-contract-create.php'));
    exit();
}
if ((int) ($hc['pr_id'] ?? 0) > 0) {
    $rid = (int) ($hc['pr_id'] ?? 0);
    header('Location: ' . app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $rid . '&mode=payment');
    exit();
}

$contractPoRow = Purchase::hireContractPoFor(0, $hire_contract_id);
if ($contractPoRow === null) {
    header('Location: ' . Purchase::workOrderListUrl() . '?error=no_wo&hire_contract_id=' . $hire_contract_id);
    exit();
}

Purchase::purgeStaleHireContractPayments($hire_contract_id);

$woSiteId = (int) ($contractPoRow['site_id'] ?? 0);
$woSiteName = trim((string) ($contractPoRow['site_name'] ?? ''));
$woCostCategoryId = (int) ($contractPoRow['cost_category_id'] ?? 0);
$woCostCategoryName = trim((string) ($contractPoRow['cost_category_name'] ?? ''));
if ($woSiteName === '' && $woSiteId > 0) {
    $woSiteRow = Db::row('sites', (string) $woSiteId);
    if (is_array($woSiteRow)) {
        $woSiteName = trim((string) ($woSiteRow['name'] ?? ''));
    }
}
require_once dirname(__DIR__, 2) . '/includes/site_category_document_name.php';
$woCostCategoryName = tnc_site_category_document_name($woCostCategoryId, $woCostCategoryName);
$woSiteDisplay = $woSiteName !== '' ? $woSiteName : ($woSiteId > 0 ? 'ไซต์ #' . $woSiteId : '—');
$siteCategoriesForWo = tnc_site_categories_for_site($woSiteId);
$requireCostCategory = count($siteCategoriesForWo) > 0;

$contractorName = trim((string) ($hc['contractor_name'] ?? ''));
$contractorId = (int) ($hc['contractor_id'] ?? 0);
require_once dirname(__DIR__, 2) . '/includes/contractors.php';
$contractorRow = null;
$contractorDisplay = $contractorName;
if ($contractorId > 0) {
    $contractorRow = Db::row('contractors', (string) $contractorId);
    if (is_array($contractorRow)) {
        $contractorDisplay = tnc_contractor_display_label($contractorRow);
        if ($contractorName === '') {
            $contractorName = tnc_contractor_full_name_th($contractorRow);
        }
    }
}
$poNoteDefault = is_array($contractorRow)
    ? tnc_contractor_payment_note_text($contractorRow)
    : '';
$installmentTotal = (int) ($hc['installment_total'] ?? 1);
if ($installmentTotal < 0) {
    $installmentTotal = 0;
}
$hireOpenPayments = Purchase::hireInstallmentsUnspecified($installmentTotal);

$issuedInstallments = [];
foreach (Purchase::activeHirePaymentPos($hire_contract_id) as $row) {
    $no = (int) ($row['installment_no'] ?? 0);
    if ($no > 0) {
        $issuedInstallments[$no] = true;
    }
}
$paymentCount = count($issuedInstallments);
$nextPaymentNo = Purchase::hireNextPaymentNo($hire_contract_id);
$nextInstallmentNo = $nextPaymentNo;
if (!$hireOpenPayments) {
    $nextInstallmentNo = 0;
    for ($i = 1; $i <= $installmentTotal; $i++) {
        if (!isset($issuedInstallments[$i])) {
            $nextInstallmentNo = $i;
            break;
        }
    }
}
$hirePaymentRows = Purchase::filterActiveHireContractPaymentPos(
    Db::filter('hire_contract_payments', static function (array $r) use ($hire_contract_id): bool {
        return (int) ($r['hire_contract_id'] ?? 0) === $hire_contract_id;
    }),
    $hire_contract_id
);
Db::sortRows($hirePaymentRows, 'installment_no', false);
$hireAdvancePos = Purchase::activeHireAdvancePos($hire_contract_id);
$advanceCount = count($hireAdvancePos);

$po_number = Purchase::generatePONumber();
$errorCode = trim((string) ($_GET['error'] ?? ''));
$poMode = strtolower(trim((string) ($_GET['mode'] ?? 'payment')));
if (!in_array($poMode, ['payment', 'advance'], true)) {
    $poMode = 'payment';
}
$isAdvanceMode = ($poMode === 'advance');

$hireContractAmount = round((float) ($hc['contract_amount'] ?? 0), 2);
$hireCommittedPayable = Purchase::hireContractCommittedPayable($hire_contract_id);
$hireCommittedAdvance = Purchase::hireContractCommittedAdvance($hire_contract_id);
$hireContractRemaining = Purchase::hireContractRemainingPayable($hc, $hire_contract_id);
if ($hireOpenPayments) {
    $remainingInstallments = $hireContractRemaining > 0.0005 ? 1 : 0;
} else {
    $remainingInstallments = max(0, $installmentTotal - count($issuedInstallments));
}
$submitDisabled = $isAdvanceMode ? false : ($hireContractRemaining <= 0.0005);
if ($isAdvanceMode) {
    $submitLabel = 'ยืนยันออก PO เบิกล่วงหน้า';
} else {
    $submitLabel = $submitDisabled
        ? ($hireOpenPayments ? 'มูลค่าสัญญาออกใบสั่งจ่ายครบแล้ว' : 'ออกครบทุกงวดแล้ว')
        : ($hireOpenPayments ? 'ยืนยันสร้างใบสั่งจ่ายครั้งนี้' : 'ยืนยันสร้างใบสั่งจ่ายงวดนี้');
}
$hireRemainingOver = $hireContractRemaining < -0.0005;
$hireRemainingCss = $hireRemainingOver
    ? 'text-danger fw-bold'
    : ($hireContractRemaining <= 0.0005 ? 'text-success fw-bold' : 'text-tnc-orange fw-bold');

$baseHcPoUrl = app_path('pages/purchase/purchase-order-from-hire-contract.php') . '?hire_contract_id=' . $hire_contract_id;
$paymentModeUrl = $baseHcPoUrl . '&mode=payment';
$advanceModeUrl = $baseHcPoUrl . '&mode=advance';
$listUrl = Purchase::workOrderListUrl();
$viewHcUrl = app_path('pages/purchase/purchase-order-view.php') . '?id=' . (int) ($contractPoRow['id'] ?? 0);
$installmentNoValue = $isAdvanceMode ? 0 : (int) ($hireOpenPayments ? $nextPaymentNo : $nextInstallmentNo);
$installmentBadge = $isAdvanceMode
    ? ''
    : ($hireOpenPayments
        ? 'ครั้งที่ ' . number_format($nextPaymentNo)
        : ($nextInstallmentNo > 0 ? 'งวด ' . number_format($nextInstallmentNo) . ' / ' . number_format($installmentTotal) : '—'));
$poFlatItems = [[
    'description' => '',
    'quantity' => 0,
    'unit' => '',
    'unit_price' => 0,
]];
$issueDateDisplay = date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isAdvanceMode ? 'ออกใบสั่งจ่ายเบิกล่วงหน้า' : 'ออกใบสั่งจ่ายตามงวด/ครั้ง' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/purchase-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .po-hire-form-wrap { max-width: 1100px; }
        .card-soft { border: 1px solid rgba(226, 232, 240, 0.95); border-radius: var(--tnc-radius-lg); box-shadow: var(--tnc-shadow-sm); background: #fff; }
        .po-field-label { font-size: 0.78rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.35rem; }
        .po-po-number { font-size: 1.05rem; letter-spacing: 0.02em; }
        .po-table-wrap { border: 1px solid #e8ecf1; border-radius: 0.75rem; overflow: hidden; background: #fff; }
        .po-table-wrap thead th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; font-weight: 700; background: #f1f5f9 !important; }
        .summary-box { background: linear-gradient(180deg, #fffbf5 0%, var(--tnc-orange-soft) 100%); border: 1px solid var(--tnc-orange-border); border-radius: 0.85rem; padding: 1.1rem 1.15rem; }
        .summary-line { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; margin-bottom: 10px; }
        .summary-grand { padding-top: 0.35rem; margin-top: 0.25rem; border-top: 2px dashed rgba(253, 126, 20, 0.25); }
        .po-vat-panel { background: #fffbf5; border: 1px solid var(--tnc-orange-border); border-radius: 0.75rem; }
        .po-actions-bar { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eef2f7; }
        .wo-ref-chip { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.75rem; border-radius: 999px; background: rgba(13, 110, 253, 0.08); color: #1d4ed8; font-size: 0.85rem; font-weight: 600; text-decoration: none; }
        .wo-ref-chip:hover { background: rgba(13, 110, 253, 0.14); color: #1e40af; }
        .wo-kpi-mini { border: 1px solid #e8ecf1; border-radius: 0.75rem; padding: 0.75rem 1rem; background: #fafbfc; height: 100%; }
        .wo-kpi-mini .lbl { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; font-weight: 700; }
        .wo-kpi-mini .val { font-size: 1.05rem; font-weight: 700; font-variant-numeric: tabular-nums; }
        .hc-pay-history { font-size: 0.875rem; }
        .hc-pay-history thead th { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; }
    </style>
</head>
<body class="purchase-module tnc-app-body tnc-layout-form">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container container-lg py-4 py-md-5 mb-5 po-hire-form-wrap">
    <ul class="nav nav-pills gap-2 mb-3 no-print">
        <li class="nav-item">
            <a class="nav-link<?= !$isAdvanceMode ? ' active' : '' ?>" href="<?= htmlspecialchars($paymentModeUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-cash-coin me-1"></i>สั่งจ่ายตามงวด/ครั้ง</a>
        </li>
        <li class="nav-item">
            <a class="nav-link<?= $isAdvanceMode ? ' active' : '' ?>" href="<?= htmlspecialchars($advanceModeUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-wallet2 me-1"></i>เบิกล่วงหน้า</a>
        </li>
    </ul>

    <?php if ($errorCode === 'contract_po_required'): ?>
        <div class="alert alert-warning py-2">ต้องมี WO สัญญาจ้างก่อน — <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-hire-contract-create.php'), ENT_QUOTES, 'UTF-8') ?>">ออก Work Order</a></div>
    <?php endif; ?>
    <?php if ($errorCode === 'contract'): ?>
        <div class="alert alert-warning py-2">ไม่พบสัญญาจ้าง</div>
    <?php endif; ?>
    <?php if ($errorCode === 'po_supplier'): ?>
        <div class="alert alert-warning py-2">กรุณาระบุผู้รับจ้างในสัญญา — ตรวจสอบข้อมูลสัญญาจ้าง</div>
    <?php endif; ?>
    <?php if ($errorCode === 'invalid_installment'): ?>
        <div class="alert alert-warning py-2">งวดที่เลือกไม่ถูกต้อง</div>
    <?php endif; ?>
    <?php if ($errorCode === 'duplicate_installment'): ?>
        <div class="alert alert-warning py-2">งวดนี้ถูกออกเอกสารแล้ว</div>
    <?php endif; ?>
    <?php if ($errorCode === 'no_items' || $errorCode === 'invalid_hire_rows'): ?>
        <div class="alert alert-warning py-2">กรุณากรอกรายการอย่างน้อย 1 รายการ และกรอกจำนวน/ราคาให้ถูกต้อง</div>
    <?php endif; ?>
    <?php if ($errorCode === 'invalid_installment_amount'): ?>
        <div class="alert alert-warning py-2">ยอดสุทธิหลังหักประกันต้องมากกว่า 0</div>
    <?php endif; ?>
    <?php if ($errorCode === 'contract_fully_paid'): ?>
        <div class="alert alert-danger py-2"><i class="bi bi-x-circle-fill me-1"></i>มูลค่าสัญญาจ้างออก PO ครบแล้ว (คงเหลือ 0 บาท) — ไม่สามารถออกใบสั่งจ่ายเพิ่มได้</div>
    <?php endif; ?>
    <?php if ($errorCode === 'contract_exceeds_remaining' || $errorCode === 'contract_exceeds_confirm'): ?>
        <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle-fill me-1"></i>กรุณายืนยันการออกใบสั่งจ่ายเมื่อยอดเกินมูลค่าสัญญา</div>
    <?php endif; ?>
    <?php if ($errorCode === 'need_cost_category'): ?>
        <div class="alert alert-warning py-2">กรุณาเลือกหมวดค่าใช้จ่าย</div>
    <?php endif; ?>
    <?php if ($remainingInstallments === 0 && $hireContractRemaining > 0.0005 && !$isAdvanceMode): ?>
        <div class="alert alert-info py-2">ออกใบสั่งจ่ายครบทุกงวดแล้ว แต่ยังมียอดคงเหลือในสัญญา — ตรวจสอบยอดแต่ละงวด</div>
    <?php endif; ?>
    <?php if ($hireRemainingOver): ?>
        <div class="alert alert-danger py-2"><i class="bi bi-exclamation-octagon-fill me-1"></i>จ่ายเกินมูลค่าสัญญาแล้ว <strong><?= number_format(abs($hireContractRemaining), 2) ?> บาท</strong></div>
    <?php elseif ($hireContractRemaining <= 0.0005 && $hireContractRemaining >= -0.0005 && !$isAdvanceMode): ?>
        <div class="alert alert-success py-2"><i class="bi bi-check-circle-fill me-1"></i>มูลค่าสัญญาจ้างออก PO ครบแล้ว (คงเหลือ 0 บาท)</div>
    <?php endif; ?>

    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=create_po_direct" method="POST" data-tnc-fullnav="1" data-hire-remaining="<?= htmlspecialchars(number_format($hireContractRemaining, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" data-hire-advance="<?= $isAdvanceMode ? '1' : '0' ?>">
        <input type="hidden" name="confirm_over_contract" id="confirm_over_contract" value="">
        <?php csrf_field(); ?>
        <input type="hidden" name="hire_contract_id" value="<?= $hire_contract_id ?>">
        <input type="hidden" name="hire_po_kind" value="<?= $isAdvanceMode ? 'advance' : 'payment' ?>">
        <input type="hidden" name="installment_no" value="<?= $installmentNoValue ?>">
        <input type="hidden" name="withholding_type" id="withholding_type" value="none">
        <input type="hidden" name="retention_type" id="retention_type" value="fixed">
        <input type="hidden" name="vat_mode" id="vat_mode" value="exclusive">
        <?php if ($woSiteId > 0): ?>
        <input type="hidden" name="site_id" value="<?= $woSiteId ?>">
        <?php endif; ?>

        <header class="po-create-hero p-4 p-md-4 mb-4">
            <div class="row align-items-center g-3">
                <div class="col-lg">
                    <p class="purchase-page-kicker mb-1">Purchase Module · Work Order</p>
                    <h1 class="h3 mb-2 fw-bold">
                        <i class="bi bi-<?= $isAdvanceMode ? 'wallet2 text-warning' : 'cash-coin text-tnc-orange' ?> me-2"></i>
                        <?= $isAdvanceMode ? 'ออก PO เบิกล่วงหน้า' : 'ออก PO สั่งจ่าย' ?>
                    </h1>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <a href="<?= htmlspecialchars($viewHcUrl, ENT_QUOTES, 'UTF-8') ?>" class="wo-ref-chip">
                            <i class="bi bi-file-earmark-ruled"></i>WO <?= htmlspecialchars((string) ($contractPoRow['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <?php if ($installmentBadge !== ''): ?>
                            <span class="badge rounded-pill text-bg-primary"><?= htmlspecialchars($installmentBadge, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-auto d-none d-lg-flex flex-wrap gap-2 justify-content-lg-end">
                    <button type="submit" class="btn btn-orange rounded-pill px-4 shadow po-submit-btn-desktop"<?= $submitDisabled ? ' disabled' : '' ?>><i class="bi bi-check2-circle me-1"></i><?= htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8') ?></button>
                    <a href="<?= htmlspecialchars($viewHcUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light rounded-pill px-4 shadow-sm"><i class="bi bi-arrow-left me-1"></i>กลับหน้ารายการสั่งจ้าง</a>
                </div>
            </div>
        </header>

        <div class="card card-soft p-4 p-md-4 mb-4">
            <div class="row g-3 g-md-4">
                <div class="col-md-4">
                    <label class="po-field-label">เลขที่ใบสั่งจ่าย(อัตโนมัติ)</label>
                    <input type="text" name="po_number" class="form-control po-po-number bg-light text-tnc-orange fw-bold" value="<?= htmlspecialchars($po_number, ENT_QUOTES, 'UTF-8') ?>" readonly>
                </div>
                <div class="col-md-4">
                    <label class="po-field-label" for="issue_date">วันที่ออกใบสั่งจ่าย <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-white text-tnc-orange"><i class="bi bi-calendar3"></i></span>
                        <input type="text" name="issue_date" id="issue_date" class="form-control" value="<?= htmlspecialchars($issueDateDisplay, ENT_QUOTES, 'UTF-8') ?>" required autocomplete="off" placeholder="วัน/เดือน/ปี">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="po-field-label">ผู้รับจ้าง</label>
                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($contractorDisplay !== '' ? $contractorDisplay : '—', ENT_QUOTES, 'UTF-8') ?>" readonly>
                </div>
            </div>
            <div class="row g-3 g-md-4 mt-1 pt-3 border-top border-light">
                <div class="col-md-6">
                    <label class="po-field-label">โครงการ / ไซต์</label>
                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($woSiteDisplay, ENT_QUOTES, 'UTF-8') ?>" readonly>
                </div>
                <?php if ($requireCostCategory): ?>
                <div class="col-md-6">
                    <label class="po-field-label" for="cost_category_id">หมวดค่าใช้จ่าย <span class="text-danger">*</span> <span class="text-muted small fw-normal">(เลือกหมวดย่อย)</span></label>
                    <select name="cost_category_id" id="cost_category_id" class="form-select" required>
                        <option value="" disabled<?= $woCostCategoryId <= 0 ? ' selected' : '' ?>>— โปรดเลือกหมวด —</option>
                        <?php tnc_site_category_render_select_options(tnc_site_category_build_select_options($woSiteId), $woCostCategoryId); ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <?php if (count($hirePaymentRows) > 0): ?>
            <div class="mt-3 pt-3 border-top border-light">
                <button class="btn btn-sm btn-link text-decoration-none px-0 text-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#hcPayHistory" aria-expanded="false">
                    <i class="bi bi-clock-history me-1"></i>ประวัติ PO สั่งจ่าย (<?= count($hirePaymentRows) ?> รายการ)
                </button>
                <div class="collapse mt-2" id="hcPayHistory">
                    <div class="table-responsive hc-pay-history">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>PO No.</th>
                                    <th>ประเภท</th>
                                    <th class="text-end">มูลค่า</th>
                                    <th>วันที่</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hirePaymentRows as $payment): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($payment['po_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars(Purchase::hireContractPaymentLabel($payment, $installmentTotal), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="text-end"><?= number_format((float) ($payment['amount'] ?? 0), 2) ?></td>
                                        <td class="text-muted"><?= htmlspecialchars((string) ($payment['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="card card-soft p-4 p-md-4 mb-4">
            <label class="po-field-label" for="po_note">หมายเหตุใบสั่งซื้อ</label>
            <textarea name="po_note" id="po_note" class="form-control" rows="<?= $poNoteDefault !== '' ? min(5, max(2, substr_count($poNoteDefault, "\n") + 1)) : 2 ?>" maxlength="500" placeholder="<?= $poNoteDefault === '' ? 'หมายเหตุ (แสดงตอนพิมพ์)' : '' ?>"><?= htmlspecialchars($poNoteDefault, ENT_QUOTES, 'UTF-8') ?></textarea>
            <?php if ($poNoteDefault !== ''): ?>
            <?php elseif ($contractorId <= 0): ?>
                <div class="form-text text-warning">ยังไม่ได้ผูกผู้รับจ้างในระบบ — กรอกช่องทางชำระเอง หรือ<a href="<?= htmlspecialchars(app_path('pages/contractors/contractor-list.php'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">เพิ่มผู้รับจ้าง</a></div>
            <?php endif; ?>
        </div>

        <div class="card card-soft p-4 p-md-4 mb-4">
            <div class="table-responsive po-table-wrap">
                <table class="table align-middle table-hover mb-0" id="poTable">
                    <thead>
                        <tr>
                            <th style="width:3rem;">#</th>
                            <th>รายการ</th>
                            <th style="width:6.5rem;">จำนวน</th>
                            <th style="width:6.5rem;">หน่วย</th>
                            <th style="width:7.5rem;">ราคา/หน่วย</th>
                            <th style="width:7.5rem;">ยอดรวม</th>
                            <th style="width:2.75rem;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($poFlatItems as $index => $item): ?>
                            <tr>
                                <td class="row-number text-secondary small fw-semibold"><?= $index + 1 ?></td>
                                <td><input type="text" name="item_description[]" class="form-control form-control-sm" required value="<?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="ระบุรายการ"></td>
                                <td><input type="number" name="item_qty[]" class="form-control form-control-sm qty" step="0.01" min="0" required value="<?= htmlspecialchars((string) ($item['quantity'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" oninput="calculatePoTotal()"></td>
                                <td><input type="text" name="item_unit[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="ชิ้น"></td>
                                <td><input type="number" name="item_price[]" class="form-control form-control-sm price" step="0.01" required value="<?= htmlspecialchars((string) ($item['unit_price'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" oninput="calculatePoTotal()"></td>
                                <td><input type="text" class="form-control form-control-sm row-total bg-light text-end fw-semibold" value="0.00" readonly tabindex="-1"></td>
                                <td><?php if ($index > 0): ?><button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="removePoRow(this)" title="ลบแถว"><i class="bi bi-trash-fill"></i></button><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="po-actions-bar">
                <button type="button" class="btn btn-orange btn-sm rounded-pill px-3 shadow-sm" onclick="addPoRow()"><i class="bi bi-plus-lg me-1"></i>เพิ่มรายการ</button>
            </div>
            <div class="row g-4 mt-1">
                <div class="col-lg-7 order-2 order-lg-1">
                    <div class="po-vat-panel p-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="vat_enabled" id="vat_enabled" value="1" onchange="updatePoVatBasisUi(); calculatePoTotal()">
                            <label class="form-check-label fw-semibold" for="vat_enabled">มี VAT 7%</label>
                        </div>
                        <div id="vat_basis_wrap" class="pt-2 border-top border-secondary border-opacity-25">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_inclusive" value="inclusive" onchange="calculatePoTotal()">
                                <label class="form-check-label" for="vat_basis_inclusive">รวม VAT <span class="text-muted small">(ราคารวมภาษีแล้ว)</span></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_exclusive" value="exclusive" checked onchange="calculatePoTotal()">
                                <label class="form-check-label" for="vat_basis_exclusive">แยก VAT <span class="text-muted small">(บวก 7% จากฐาน)</span></label>
                            </div>
                        </div>
                        <div class="pt-3 mt-2 border-top border-secondary border-opacity-25">
                            <label class="form-label text-danger fw-bold mb-1 small" for="retention_value">หักประกันผลงาน (บาท)</label>
                            <input type="text" name="retention_value" id="retention_value" class="form-control form-control-sm" value="0" placeholder="0" oninput="calculatePoTotal()">
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 order-1 order-lg-2">
                    <div class="summary-box">
                        <div class="summary-line small text-muted"><span>ยอดรายการ</span><strong><span id="subtotal_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-success" id="vat_row" style="display:none;"><span>ภาษีมูลค่าเพิ่ม</span><strong><span id="vat_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-muted border-bottom pb-2 mb-1"><span>ยอดรวม VAT</span><strong><span id="total_after_vat_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-danger" id="retention_summary_row" style="display:none;"><span>หักประกันผลงาน</span><strong>- <span id="retention_display">0.00</span> บาท</strong></div>
                        <div class="summary-line summary-grand fw-bold"><span>ยอดสุทธิ</span><strong class="text-tnc-orange"><span id="grand_total">0.00</span> บาท</strong></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tnc-mobile-sticky-cta d-lg-none">
            <div class="tnc-mobile-sticky-inner">
                <div class="tnc-mobile-sticky-meta">
                    <div class="tnc-mobile-sticky-label">ยอดสุทธิ</div>
                    <div class="tnc-mobile-sticky-total" id="grand_total_sticky">0.00</div>
                </div>
                <div class="tnc-mobile-sticky-actions">
                    <button type="submit" class="btn btn-orange rounded-pill fw-bold po-submit-btn-mobile"<?= $submitDisabled ? ' disabled' : '' ?>><i class="bi bi-check2-circle me-1"></i><?= htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8') ?></button>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="<?= htmlspecialchars(app_path('assets/js/purchase-vat-calc.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
function normalizeIssueDateInput(el) {
    if (!el) return true;
    const raw = (el.value || '').trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return true;
    const m = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!m) return false;
    el.value = m[3] + '-' + String(m[2]).padStart(2, '0') + '-' + String(m[1]).padStart(2, '0');
    return true;
}
function addPoRow() {
    const table = document.getElementById('poTable').getElementsByTagName('tbody')[0];
    const newRow = table.insertRow();
    const rowCount = table.rows.length;
    newRow.innerHTML = '<td class="row-number text-secondary small fw-semibold">' + rowCount + '</td>' +
        '<td><input type="text" name="item_description[]" class="form-control form-control-sm" required placeholder="ระบุรายการ"></td>' +
        '<td><input type="number" name="item_qty[]" class="form-control form-control-sm qty" step="0.01" min="0" required oninput="calculatePoTotal()"></td>' +
        '<td><input type="text" name="item_unit[]" class="form-control form-control-sm" placeholder="ชิ้น"></td>' +
        '<td><input type="number" name="item_price[]" class="form-control form-control-sm price" step="0.01" required oninput="calculatePoTotal()"></td>' +
        '<td><input type="text" class="form-control form-control-sm row-total bg-light text-end fw-semibold" value="0.00" readonly tabindex="-1"></td>' +
        '<td><button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="removePoRow(this)" title="ลบแถว"><i class="bi bi-trash-fill"></i></button></td>';
    calculatePoTotal();
}
function removePoRow(btn) {
    btn.closest('tr').remove();
    document.querySelectorAll('#poTable .row-number').forEach(function (td, i) { td.innerText = i + 1; });
    calculatePoTotal();
}
function poLineAmount(qty, price) {
    const q = parseFloat(String(qty || '').replace(/,/g, '')) || 0;
    const p = parseFloat(String(price || '').replace(/,/g, '')) || 0;
    return Math.round(q * p * 100) / 100;
}
function updatePoVatBasisUi() {
    const vatBasisWrap = document.getElementById('vat_basis_wrap');
    const vatEnabled = document.getElementById('vat_enabled');
    if (!vatBasisWrap || !vatEnabled) return;
    const on = vatEnabled.checked;
    vatBasisWrap.classList.toggle('opacity-50', !on);
    vatBasisWrap.style.pointerEvents = on ? '' : 'none';
    vatBasisWrap.setAttribute('aria-disabled', on ? 'false' : 'true');
    vatBasisWrap.querySelectorAll('input[name="vat_basis"]').forEach(function (el) {
        el.disabled = !on;
    });
}
function calculatePoTotal() {
    const vatModeInput = document.getElementById('vat_mode');
    const vatEnabledEl = document.getElementById('vat_enabled');
    const retentionValueEl = document.getElementById('retention_value');
    const vatOn = !!(vatEnabledEl && vatEnabledEl.checked);
    let vatMode = 'exclusive';
    if (vatOn) {
        const selectedBasis = document.querySelector('input[name="vat_basis"]:checked');
        vatMode = selectedBasis ? selectedBasis.value : 'exclusive';
    }
    if (vatModeInput) vatModeInput.value = vatMode;

    let lineAmount = 0;
    const rows = document.getElementById('poTable').getElementsByTagName('tbody')[0].rows;
    for (const row of rows) {
        const total = poLineAmount(
            row.querySelector('.qty')?.value,
            row.querySelector('.price')?.value
        );
        const totalEl = row.querySelector('.row-total');
        if (totalEl) {
            totalEl.value = total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        lineAmount += total;
    }
    lineAmount = Math.round(lineAmount * 100) / 100;

    const split = tncPurchaseVatFromLineSum(lineAmount, vatOn, vatMode);
    const subtotal = split.subtotal;
    const vat = split.vat;
    const gross = split.gross;

    let retentionRaw = (retentionValueEl?.value || '').toString().trim().replace('%', '');
    let retention = Math.max(0, Math.round((parseFloat(retentionRaw) || 0) * 100) / 100);
    const net = Math.round((gross - retention) * 100) / 100;
    const fmt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };

    const subtotalDisplay = document.getElementById('subtotal_display');
    const vatDisplay = document.getElementById('vat_display');
    const vatRow = document.getElementById('vat_row');
    const totalAfterVatDisplay = document.getElementById('total_after_vat_display');
    const retentionDisplay = document.getElementById('retention_display');
    const retentionSummaryRow = document.getElementById('retention_summary_row');
    const grandTotal = document.getElementById('grand_total');
    const hireRemainingDisplay = document.getElementById('hire_remaining_display');
    const poForm = document.querySelector('form[data-hire-remaining]');

    if (subtotalDisplay) subtotalDisplay.innerText = subtotal.toLocaleString(undefined, fmt);
    if (vatOn && vatRow && vatDisplay) {
        vatRow.style.display = 'grid';
        vatDisplay.innerText = vat.toLocaleString(undefined, fmt);
    } else if (vatRow) {
        vatRow.style.display = 'none';
    }
    if (totalAfterVatDisplay) totalAfterVatDisplay.innerText = gross.toLocaleString(undefined, fmt);
    if (retentionDisplay) retentionDisplay.innerText = retention.toLocaleString(undefined, fmt);
    if (retentionSummaryRow) retentionSummaryRow.style.display = retention > 0 ? 'grid' : 'none';
    if (grandTotal) grandTotal.innerText = net.toLocaleString(undefined, fmt);

    if (hireRemainingDisplay && poForm) {
        const remaining = parseFloat(poForm.getAttribute('data-hire-remaining') || '0') || 0;
        const projected = Math.round((remaining - net) * 100) / 100;
        hireRemainingDisplay.textContent = projected.toLocaleString(undefined, fmt);
        hireRemainingDisplay.classList.remove('text-danger', 'text-success', 'text-tnc-orange', 'fw-bold');
        if (projected < -0.0005) {
            hireRemainingDisplay.classList.add('text-danger', 'fw-bold');
        } else if (projected <= 0.0005) {
            hireRemainingDisplay.classList.add('text-success', 'fw-bold');
        } else {
            hireRemainingDisplay.classList.add('text-tnc-orange', 'fw-bold');
        }
    }
    window.__hirePoNet = net;
    updatePoVatBasisUi();
}
document.addEventListener('DOMContentLoaded', function () {
    const issueDateEl = document.getElementById('issue_date');
    if (typeof flatpickr === 'function' && issueDateEl) {
        flatpickr(issueDateEl, { dateFormat: 'd/m/Y', defaultDate: issueDateEl.value || 'today', allowInput: true });
    }
    updatePoVatBasisUi();
    calculatePoTotal();
    const poForm = document.querySelector('form[data-hire-remaining]');
    const confirmOverInput = document.getElementById('confirm_over_contract');
    poForm?.addEventListener('submit', function (event) {
        const catEl = document.getElementById('cost_category_id');
        if (catEl && catEl.required && !(parseInt(catEl.value || '0', 10) > 0)) {
            event.preventDefault();
            alert('กรุณาเลือกหมวดค่าใช้จ่าย');
            catEl.focus();
            return;
        }
        if (!normalizeIssueDateInput(issueDateEl)) {
            event.preventDefault();
            alert('กรุณากรอกวันที่เป็น วัน/เดือน/ปี');
            issueDateEl?.focus();
            return;
        }
        if (poForm.getAttribute('data-hire-advance') === '1') {
            return;
        }
        calculatePoTotal();
        const net = window.__hirePoNet || 0;
        const remaining = parseFloat(poForm.getAttribute('data-hire-remaining') || '0') || 0;
        const alreadyConfirmed = confirmOverInput && confirmOverInput.value === '1';
        if (net > remaining + 0.0005 && !alreadyConfirmed) {
            event.preventDefault();
            const msg = <?= json_encode($isAdvanceMode
                ? 'จำนวนเงินที่ต้องการเบิก เกินมูลค่าคงเหลือในสัญญาแล้ว ท่านต้องการออก PO หรือไม่'
                : 'จำนวนเงินที่ต้องการจ่าย เกินมูลค่าสัญญานี้แล้ว ท่านต้องการออกใบสั่งจ่ายหรือไม่', JSON_UNESCAPED_UNICODE) ?>;
            if (confirm(msg)) {
                if (confirmOverInput) confirmOverInput.value = '1';
                poForm.requestSubmit();
            }
        }
    });
});
</script>
</body>
</html>
