<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/hire_form_rows.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$hire_contract_id = isset($_GET['hire_contract_id']) ? (int) $_GET['hire_contract_id'] : 0;
$hc = $hire_contract_id > 0 ? Db::row('hire_contracts', (string) $hire_contract_id) : null;
if ($hc === null) {
    echo "<script>alert('ไม่พบสัญญาจ้าง'); window.location.href='" . htmlspecialchars(app_path('pages/hire-contracts/hire-contract-list.php'), ENT_QUOTES) . "';</script>";
    exit();
}
if ((int) ($hc['pr_id'] ?? 0) > 0) {
    $rid = (int) ($hc['pr_id'] ?? 0);
    header('Location: ' . app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $rid);
    exit();
}

$contractorName = trim((string) ($hc['contractor_name'] ?? ''));
$installmentTotal = (int) ($hc['installment_total'] ?? 1);
if ($installmentTotal < 1) {
    $installmentTotal = 1;
}

$issuedInstallments = [];
$paidAmountSoFar = 0.0;
foreach (Db::tableRows('purchase_orders') as $row) {
    if ((int) ($row['hire_contract_id'] ?? 0) !== $hire_contract_id) {
        continue;
    }
    if (trim((string) ($row['order_type'] ?? 'purchase')) !== 'hire') {
        continue;
    }
    if (strtolower(trim((string) ($row['status'] ?? ''))) === 'cancelled') {
        continue;
    }
    $paidAmountSoFar += (float) (($row['subtotal_amount'] ?? '') !== '' ? $row['subtotal_amount'] : ($row['payable_amount'] ?? 0));
    $no = (int) ($row['installment_no'] ?? 0);
    if ($no > 0) {
        $issuedInstallments[$no] = true;
    }
}
$hirePaymentRows = Purchase::filterActiveHireContractPayments(
    Db::filter('hire_contract_payments', static function (array $r) use ($hire_contract_id): bool {
        return (int) ($r['hire_contract_id'] ?? 0) === $hire_contract_id;
    }),
    $hire_contract_id
);
Db::sortRows($hirePaymentRows, 'installment_no', false);

$hireContractAmount = round((float) ($hc['contract_amount'] ?? 0), 2);
$hireCommittedPayable = Purchase::hireContractCommittedPayable($hire_contract_id);
$hireContractRemaining = Purchase::hireContractRemainingPayable($hc, $hire_contract_id);
$remainingInstallments = max(0, $installmentTotal - count($issuedInstallments));
$submitDisabled = $remainingInstallments === 0;
$submitLabel = $remainingInstallments === 0 ? 'ออกครบทุกงวดแล้ว' : 'ยืนยันสร้างใบสั่งจ่ายงวดนี้';
$hireRemainingOver = $hireContractRemaining < -0.0005;
$hireRemainingCss = $hireRemainingOver
    ? 'text-danger fw-bold'
    : ($hireContractRemaining <= 0.0005 ? 'text-success fw-bold' : 'text-tnc-orange fw-bold');

$supplier_rows = Db::tableRows('suppliers');
Db::sortRows($supplier_rows, 'name', false);

$po_number = Purchase::generatePONumber();
$errorCode = trim((string) ($_GET['error'] ?? ''));
$listUrl = app_path('pages/hire-contracts/hire-contract-list.php');
$viewHcUrl = app_path('pages/hire-contracts/hire-contract-view.php') . '?id=' . $hire_contract_id;
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ใบสั่งจ่าย PO (สัญญาจ้างอิสระ)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/purchase-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/hire-line-table.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/pr-hire-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/po-hire-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        .po-hire-shell { max-width: 720px; }
        .section-card { border: 1px solid #e9ecef; border-radius: 12px; background: #fff; }
        .section-title { font-size: 1rem; font-weight: 700; color: var(--tnc-orange); margin-bottom: 12px; }
    </style>
</head>
<body class="po-hire-mode purchase-module tnc-app-body">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
    <div class="container-fluid px-3 px-lg-4 py-4 py-md-5">
        <div class="row justify-content-center">
            <div class="col-12 po-hire-layout-inner">
                <div class="po-hire-shell mx-auto">
                <div class="card po-hire-card border-0">
                    <div class="po-from-pr-head">
                        <h1 class="d-flex align-items-center gap-2 mb-0">
                            <i class="bi bi-cash-coin"></i>
                            สร้างใบสั่งจ่าย
                        </h1>
                        <div class="sub">ออกเอกสารสั่งจ่ายจากสัญญาจ้าง (HC) อิสระ</div>
                    </div>
                    <div class="p-4 p-md-4">
                    <?php if ($errorCode === 'contract'): ?>
                        <div class="alert alert-warning py-2">ไม่พบสัญญาจ้าง</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'po_supplier'): ?>
                        <div class="alert alert-warning py-2">กรุณาเลือกผู้ขาย / ผู้รับจ้างในระบบ Supplier</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'invalid_installment'): ?>
                        <div class="alert alert-warning py-2">งวดที่เลือกไม่ถูกต้อง</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'duplicate_installment'): ?>
                        <div class="alert alert-warning py-2">งวดนี้ถูกออกเอกสารแล้ว</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'no_items' || $errorCode === 'invalid_hire_rows'): ?>
                        <div class="alert alert-warning py-2">กรุณากรอกรายการสั่งจ่ายอย่างน้อย 1 รายการ</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'invalid_installment_amount'): ?>
                        <div class="alert alert-warning py-2">ยอดสุทธิหลังหักประกันต้องมากกว่า 0</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'contract_fully_paid'): ?>
                        <div class="alert alert-danger py-2"><i class="bi bi-x-circle-fill me-1"></i>มูลค่าสัญญาจ้างจ่ายครบแล้ว (คงเหลือ 0 บาท) — ไม่สามารถออกใบสั่งจ่ายเพิ่มได้</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'contract_exceeds_remaining' || $errorCode === 'contract_exceeds_confirm'): ?>
                        <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle-fill me-1"></i>กรุณายืนยันการออกใบสั่งจ่ายเมื่อยอดเกินมูลค่าสัญญา</div>
                    <?php endif; ?>
                    <?php if ($remainingInstallments === 0 && $hireContractRemaining > 0.0005): ?>
                        <div class="alert alert-info py-2">ออกใบสั่งจ่ายครบทุกงวดแล้ว แต่ยังมียอดคงเหลือในสัญญา — ตรวจสอบยอดแต่ละงวด</div>
                    <?php endif; ?>
                    <?php if ($hireRemainingOver): ?>
                        <div class="alert alert-danger py-2"><i class="bi bi-exclamation-octagon-fill me-1"></i>จ่ายเกินมูลค่าสัญญาแล้ว <strong><?= number_format(abs($hireContractRemaining), 2) ?> บาท</strong> (คงเหลือ <?= number_format($hireContractRemaining, 2) ?> บาท)</div>
                    <?php elseif ($hireContractRemaining <= 0.0005 && $hireContractRemaining >= -0.0005): ?>
                        <div class="alert alert-success py-2"><i class="bi bi-check-circle-fill me-1"></i>มูลค่าสัญญาจ้างจ่ายครบแล้ว (คงเหลือ 0 บาท)</div>
                    <?php endif; ?>
                    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=create_po_direct" method="POST" data-hire-remaining="<?= htmlspecialchars(number_format($hireContractRemaining, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="confirm_over_contract" id="confirm_over_contract" value="">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="hire_contract_id" value="<?= $hire_contract_id ?>">
                        <input type="hidden" name="installment_no" value="<?php for ($i = 1; $i <= $installmentTotal; $i++) { if (!isset($issuedInstallments[$i])) { echo $i; break; } } ?>">

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="po-field-label">อ้างอิงสัญญาจ้าง (HC)</div>
                                <input type="text" class="form-control form-control-lg bg-light border-0" value="<?= htmlspecialchars((string) ($hc['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <div class="po-field-label">เลขที่ PO (อัตโนมัติ)</div>
                                <input type="text" name="po_number" class="form-control form-control-lg bg-light border-0" value="<?= htmlspecialchars($po_number, ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </div>
                        </div>

                        <div class="hire-meta-panel mb-4">
                            <div class="hire-meta-panel__head"><i class="bi bi-briefcase-fill"></i> ข้อมูลจัดจ้าง</div>
                            <div class="hire-meta-kv hire-meta-kv--readonly">
                                <span class="hire-meta-chip"><strong>ประเภท:</strong> จัดจ้าง (อิสระจาก PR)</span>
                                <span class="hire-meta-chip sep">|</span>
                                <span class="hire-meta-chip"><strong>ผู้รับจ้าง:</strong> <?= htmlspecialchars($contractorName !== '' ? $contractorName : '-', ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="hire-meta-chip sep">|</span>
                                <span class="hire-meta-chip"><strong>จำนวนงวด:</strong> <?= number_format($installmentTotal) ?> งวด</span>
                                <span class="hire-meta-chip sep">|</span>
                                <span class="hire-meta-chip"><strong>งวดถัดไป:</strong> <?php for ($i = 1; $i <= $installmentTotal; $i++) { if (!isset($issuedInstallments[$i])) { echo $i . ' / ' . $installmentTotal; break; } } ?></span>
                            </div>
                        </div>

                        <div class="po-hire-block">
                            <h3 class="po-hire-block__title"><i class="bi bi-journal-text"></i>รายละเอียดสัญญา</h3>
                            <div class="po-hire-block__body"><?= htmlspecialchars((string) ($hc['title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>

                        <div class="po-hire-block">
                            <h3 class="po-hire-block__title"><i class="bi bi-file-earmark-ruled"></i>สถานะสัญญาจ้าง</h3>
                            <?php
                                $paidInstallmentsDisplay = (int) ($hc['paid_installments'] ?? count($issuedInstallments));
                                $paidAmountDisplay = $hireCommittedPayable;
                            ?>
                            <div class="po-hire-stats">
                                <div class="po-hire-stat">
                                    <span class="po-hire-stat__label">มูลค่าสัญญา</span>
                                    <span class="po-hire-stat__value"><?= number_format($hireContractAmount, 2) ?> บาท</span>
                                </div>
                                <div class="po-hire-stat">
                                    <span class="po-hire-stat__label">จ่ายแล้ว</span>
                                    <span class="po-hire-stat__value"><?= number_format($paidAmountDisplay, 2) ?> บาท</span>
                                </div>
                                <div class="po-hire-stat">
                                    <span class="po-hire-stat__label">คงเหลือ</span>
                                    <span class="po-hire-stat__value <?= $hireRemainingCss ?>" id="hire_remaining_display"><?= number_format($hireContractRemaining, 2) ?> บาท</span>
                                </div>
                                <div class="po-hire-stat">
                                    <span class="po-hire-stat__label">งวดที่จ่ายแล้ว</span>
                                    <span class="po-hire-stat__value"><?= number_format($paidInstallmentsDisplay) ?> / <?= number_format($installmentTotal) ?></span>
                                </div>
                            </div>
                            <div class="po-hire-ledger-wrap">
                                <table class="table align-middle po-hire-ledger-table mb-0" id="tncHirePaidInstallmentsTable">
                                    <thead>
                                        <tr>
                                            <th>PO No.</th>
                                            <th>งวด</th>
                                            <th class="text-end">มูลค่างวด</th>
                                            <th>วันที่บันทึก</th>
                                            <th>สถานะ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($hirePaymentRows) === 0): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted">ยังไม่มีการจ่ายงวด</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($hirePaymentRows as $payment): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars((string) ($payment['po_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td>งวด <?= (int) ($payment['installment_no'] ?? 0) ?>/<?= (int) ($payment['installment_total'] ?? $installmentTotal) ?></td>
                                                    <td class="text-end"><?= number_format((float) ($payment['amount'] ?? 0), 2) ?> บาท</td>
                                                    <td><?= htmlspecialchars((string) ($payment['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><span class="badge bg-success">จ่ายแล้ว</span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="po-field-label">เลือกผู้ขาย (Supplier) *</div>
                            <input type="text" id="supplier_search" class="form-control form-control-lg" list="supplier_list" placeholder="พิมพ์ชื่อผู้ขายเพื่อค้นหา" required>
                            <datalist id="supplier_list">
                                <?php foreach ($supplier_rows as $s): ?>
                                    <option
                                        value="<?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-id="<?= (int) ($s['id'] ?? 0) ?>"
                                    ></option>
                                <?php endforeach; ?>
                            </datalist>
                            <input type="hidden" name="supplier_id" id="supplier_id" required>
                            <div class="form-text">เลือกจากรายการที่ตรงกัน ระบบจะผูกเป็น Supplier อัตโนมัติ</div>
                        </div>

                        <div class="section-card p-3 mb-3 hire-lines-section" data-tnc-hire-root>
                            <div class="section-title"><i class="bi bi-table me-1"></i>ตารางรายละเอียดสั่งจ่าย</div>
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="hire-table-panel">
                                    <div class="table-responsive hire-table-scroll">
                                        <table class="table align-middle mb-0 table-hire-lines" id="hireInstallmentTable">
                                            <thead>
                                                <tr>
                                                    <th class="hire-col-no text-center">#</th>
                                                    <th class="hire-col-desc">รายการ</th>
                                                    <th class="hire-col-qty text-end">จำนวน</th>
                                                    <th class="hire-col-unit text-end">หน่วย</th>
                                                    <th class="hire-col-money text-end">ค่าวัสดุ</th>
                                                    <th class="hire-col-money text-end">ค่าแรง</th>
                                                    <th class="hire-col-money text-end">ราคา/หน่วย</th>
                                                    <th class="hire-col-money text-end">ราคารวม</th>
                                                    <th class="hire-col-action text-center">ลบ</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php tnc_hire_form_default_rows('item', 'po'); ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    </div>
                                    <div class="hire-lines-toolbar">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="addHireGroupBtn" data-tnc-hire-add="group"><i class="bi bi-folder-plus me-1"></i>เพิ่มหัวข้อหลัก</button>
                                        <button type="button" class="btn btn-sm btn-outline-orange" id="addHireRowBtn" data-tnc-hire-add="item"><i class="bi bi-plus-circle me-1"></i>เพิ่มรายการย่อย</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="section-card p-3 mb-4">
                            <div class="section-title"><i class="bi bi-calculator me-1"></i>สรุปยอด</div>
                            <div class="po-hire-summary-grid">
                                <div class="po-hire-summary-settings">
                                    <h6 class="fw-bold mb-3 small text-uppercase text-secondary" style="letter-spacing:0.05em;">ภาษีและเงินหัก</h6>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="vat_enabled" id="vat_enabled">
                                        <label class="form-check-label fw-semibold" for="vat_enabled">บวก VAT 7% (+)</label>
                                    </div>
                                    <label class="form-label text-danger fw-bold mb-1" for="retention_value">หักประกันผลงาน (บาท)</label>
                                    <input type="text" name="retention_value" id="retention_value" class="form-control" value="0" placeholder="0">
                                    <input type="hidden" name="withholding_type" id="withholding_type" value="none">
                                    <input type="hidden" name="retention_type" id="retention_type" value="fixed">
                                </div>
                                <div class="po-hire-totals-card">
                                    <div class="po-hire-sum-row"><span>ยอดรวม (Subtotal)</span><span id="subtotal_text">0.00</span></div>
                                    <div class="po-hire-sum-row text-tnc-orange"><span>VAT (+)</span><span id="vat_text">0.00</span></div>
                                    <div class="po-hire-sum-row border-bottom pb-2 mb-1"><span class="text-muted fw-semibold">ยอดรวม VAT</span><span id="total_after_vat_text">0.00</span></div>
                                    <div id="retention_summary_row" class="po-hire-sum-row text-danger" style="display:none;"><span>หักประกันผลงาน (-)</span><span id="retention_display">0.00</span></div>
                                    <div class="po-hire-grand-row">
                                        <span class="label">ยอดสุทธิ</span>
                                        <span class="amount" id="grand_total">0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="section-card p-3 mb-3">
                            <label class="po-field-label" for="po_note">หมายเหตุ</label>
                            <textarea name="po_note" id="po_note" class="form-control" rows="3" maxlength="500" placeholder="หมายเหตุใบสั่งจ่าย (แสดงตอนพิมพ์)"></textarea>
                        </div>

                        <div class="d-grid gap-2 mt-2">
                            <button type="submit" class="btn btn-orange btn-lg shadow"<?= $submitDisabled ? ' disabled' : '' ?>><?= htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8') ?></button>
                            <a href="<?= htmlspecialchars($viewHcUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light">กลับไปดูสัญญา</a>
                            <a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">รายการสัญญาจ้าง</a>
                        </div>
                    </form>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </div>
<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script>
(function ($) {
    if (typeof window.TncLiveDT === 'undefined' || !$ || !$.fn.DataTable) return;
    var $t = $('#tncHirePaidInstallmentsTable');
    if (!$t.length) return;
    if ($t.find('tbody tr').length === 1 && $t.find('tbody td[colspan]').length) return;
    TncLiveDT.init('#tncHirePaidInstallmentsTable', { order: [[2, 'desc']], pageLength: 10 });
})(jQuery);
</script>
<script src="<?= htmlspecialchars(app_path('assets/js/hire-line-table.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function () {
    const searchInput = document.getElementById('supplier_search');
    const supplierIdInput = document.getElementById('supplier_id');
    const datalist = document.getElementById('supplier_list');
    if (!searchInput || !supplierIdInput || !datalist) {
        return;
    }

    function syncSupplierId() {
        const typed = (searchInput.value || '').trim();
        if (typed === '') {
            supplierIdInput.value = '';
            return;
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
    }

    searchInput.addEventListener('input', syncSupplierId);
    searchInput.addEventListener('change', syncSupplierId);

    const form = searchInput.closest('form');
    if (form) {
        form.addEventListener('submit', function (event) {
            syncSupplierId();
            if (!supplierIdInput.value) {
                event.preventDefault();
                alert('กรุณาเลือกผู้ขายจากรายการที่ระบบแนะนำ');
                searchInput.focus();
            }
        });
    }
})();

(function () {
    const subtotalTextEl = document.getElementById('subtotal_text');
    const vatTextEl = document.getElementById('vat_text');
    const totalAfterVatTextEl = document.getElementById('total_after_vat_text');
    const retentionDisplayEl = document.getElementById('retention_display');
    const grandTotalEl = document.getElementById('grand_total');
    const retentionSummaryRowEl = document.getElementById('retention_summary_row');
    const withholdingTypeEl = document.getElementById('withholding_type');
    const retentionTypeEl = document.getElementById('retention_type');
    const retentionValueEl = document.getElementById('retention_value');
    const vatEnabledEl = document.getElementById('vat_enabled');
    const table = document.getElementById('hireInstallmentTable');
    const addGroupBtn = document.getElementById('addHireGroupBtn');
    const addRowBtn = document.getElementById('addHireRowBtn');
    if (!subtotalTextEl || !table) {
        return;
    }

    const applySubtotal = (subtotal) => {
        subtotal = Math.round(subtotal * 100) / 100;
        const vat = vatEnabledEl?.checked ? Math.round(subtotal * 0.07 * 100) / 100 : 0;
        if (withholdingTypeEl) {
            withholdingTypeEl.value = 'none';
        }
        const retentionType = (retentionTypeEl?.value || 'fixed');
        let retentionValueRaw = (retentionValueEl?.value || '').toString().trim().replace('%', '');
        let retentionValue = parseFloat(retentionValueRaw) || 0;
        if (retentionValue < 0) retentionValue = 0;
        let retention = 0;
        if (retentionType === 'percent') {
            if (retentionValue > 100) retentionValue = 100;
            retention = Math.round(subtotal * (retentionValue / 100) * 100) / 100;
        } else if (retentionType === 'fixed') {
            retention = Math.round(retentionValue * 100) / 100;
        }
        const totalAfterVat = Math.round((subtotal + vat) * 100) / 100;
        const net = Math.round((totalAfterVat - retention) * 100) / 100;
        const fmt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
        subtotalTextEl.textContent = subtotal.toLocaleString(undefined, fmt);
        if (vatTextEl) vatTextEl.textContent = '+ ' + vat.toLocaleString(undefined, fmt);
        if (totalAfterVatTextEl) totalAfterVatTextEl.textContent = totalAfterVat.toLocaleString(undefined, fmt);
        if (retentionDisplayEl) retentionDisplayEl.textContent = '- ' + retention.toLocaleString(undefined, fmt);
        if (grandTotalEl) grandTotalEl.textContent = net.toLocaleString(undefined, fmt);
        if (retentionSummaryRowEl) retentionSummaryRowEl.style.display = retention > 0 ? 'flex' : 'none';
        return net;
    };

    const poForm = table.closest('form');
    const confirmOverInput = document.getElementById('confirm_over_contract');
    const hireRemainingDisplay = document.getElementById('hire_remaining_display');

    const updateHireRemainingPreview = (net) => {
        if (!hireRemainingDisplay || !poForm?.hasAttribute('data-hire-remaining')) {
            return;
        }
        const remaining = parseFloat(poForm.getAttribute('data-hire-remaining') || '0') || 0;
        const projected = Math.round((remaining - net) * 100) / 100;
        const fmt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
        hireRemainingDisplay.textContent = projected.toLocaleString(undefined, fmt) + ' บาท';
        hireRemainingDisplay.classList.remove('text-danger', 'text-success', 'text-tnc-orange', 'fw-bold');
        if (projected < -0.0005) {
            hireRemainingDisplay.classList.add('text-danger', 'fw-bold');
        } else if (projected <= 0.0005) {
            hireRemainingDisplay.classList.add('text-success', 'fw-bold');
        } else {
            hireRemainingDisplay.classList.add('text-tnc-orange', 'fw-bold');
        }
    };

    let lastNet = 0;
    const hireLineApi = window.TncHireLineTable ? window.TncHireLineTable.bindTable(table, {
        fieldPrefix: 'item',
        addGroupButton: addGroupBtn,
        addItemButton: addRowBtn,
        onSubtotal: (subtotal) => {
            lastNet = applySubtotal(subtotal);
            updateHireRemainingPreview(lastNet);
        },
    }) : null;

    const recalcWithNet = () => {
        if (hireLineApi) {
            lastNet = applySubtotal(hireLineApi.recalc());
            updateHireRemainingPreview(lastNet);
        }
        return lastNet;
    };

    withholdingTypeEl?.addEventListener('change', recalcWithNet);
    retentionTypeEl?.addEventListener('change', recalcWithNet);
    retentionValueEl?.addEventListener('input', recalcWithNet);
    vatEnabledEl?.addEventListener('change', recalcWithNet);
    poForm?.addEventListener('submit', (event) => {
        recalcWithNet();
        const remaining = parseFloat(poForm.getAttribute('data-hire-remaining') || '0') || 0;
        const alreadyConfirmed = confirmOverInput && confirmOverInput.value === '1';
        if (lastNet > remaining + 0.0005 && !alreadyConfirmed) {
            event.preventDefault();
            if (confirm('จำนวนเงินที่ต้องการจ่าย เกิน มูลค่าสัญญานี้แล้ว ท่านต้องการออกใบสั่งจ่ายหรือไม่')) {
                if (confirmOverInput) {
                    confirmOverInput.value = '1';
                }
                poForm.requestSubmit();
            }
        }
    });
    recalcWithNet();
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


