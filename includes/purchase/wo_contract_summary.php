<?php

declare(strict_types=1);

/**
 * สรุปสัญญาจ้างบนหน้า Work Order (WO) — hire_contract = WO ในมุม UI
 * ต้องมีใน scope: $hireContractIdForPo (int), $po (array), Purchase, Db
 */

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

$woSummaryIncluded = true;

$resolvedContractId = (int) $hireContractIdForPo;
$contract = Db::row('hire_contracts', (string) $resolvedContractId);
if (!is_array($contract)) {
    return;
}

Purchase::purgeStaleHireContractPayments($resolvedContractId);

$resolvedPrId = (int) ($contract['pr_id'] ?? 0);
$contractDocNo = Purchase::hireContractDocumentNumber($contract);
$contractRemaining = Purchase::hireContractRemainingPayable($contract, $resolvedContractId);
$hireCommittedPayable = Purchase::hireContractCommittedPayable($resolvedContractId);
$hireCommittedAdvance = Purchase::hireContractCommittedAdvance($resolvedContractId);
$contractRemainingOver = $contractRemaining < -0.0005;
$contractRemainingCss = $contractRemainingOver
    ? 'text-danger fw-bold'
    : ($contractRemaining <= 0.0005 ? 'text-success' : 'text-primary');

$payments = Purchase::filterActiveHireContractPaymentPos(
    Db::filter('hire_contract_payments', static function (array $r) use ($resolvedContractId, $resolvedPrId): bool {
        $hid = (int) ($r['hire_contract_id'] ?? 0);
        if ($resolvedContractId > 0 && $hid > 0) {
            return $hid === $resolvedContractId;
        }

        return $resolvedPrId > 0 && (int) ($r['pr_id'] ?? 0) === $resolvedPrId;
    }),
    $resolvedContractId > 0 ? $resolvedContractId : null,
    $resolvedPrId > 0 ? $resolvedPrId : null
);
Db::sortRows($payments, 'installment_no', false);
$paymentPoCount = count(Purchase::activeHirePaymentPos($resolvedContractId, $resolvedPrId));
$advancePoCount = count(Purchase::activeHireAdvancePos($resolvedContractId, $resolvedPrId));
$activePaymentPos = Purchase::activeHirePaymentPos($resolvedContractId, $resolvedPrId);
$activeAdvancePos = Purchase::activeHireAdvancePos($resolvedContractId, $resolvedPrId);
usort($activePaymentPos, static function (array $a, array $b): int {
    return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
});
usort($activeAdvancePos, static function (array $a, array $b): int {
    return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
});

$poRows = Db::filter('purchase_orders', static function (array $r) use ($resolvedContractId, $resolvedPrId): bool {
    if (trim((string) ($r['order_type'] ?? 'purchase')) !== 'hire') {
        return false;
    }
    if ($resolvedContractId > 0 && (int) ($r['hire_contract_id'] ?? 0) === $resolvedContractId) {
        return true;
    }

    return $resolvedPrId > 0 && (int) ($r['pr_id'] ?? 0) === $resolvedPrId;
});
Db::sortRows($poRows, 'installment_no', false);
$poByNumber = [];
foreach ($poRows as $poRow) {
    $poNum = trim((string) ($poRow['po_number'] ?? ''));
    if ($poNum !== '') {
        $poByNumber[$poNum] = $poRow;
    }
}

$installmentTotalCount = (int) ($contract['installment_total'] ?? 0);
if ($installmentTotalCount < 0) {
    $installmentTotalCount = 0;
}
$hireOpenPaymentsView = Purchase::hireInstallmentsUnspecified($installmentTotalCount);

$woSummaryFormatDate = static function (string $raw): string {
    $value = trim($raw);
    if ($value === '') {
        return '-';
    }
    try {
        $dt = new DateTime($value, new DateTimeZone('Asia/Bangkok'));

        return $dt->format('d/m/Y');
    } catch (Throwable $e) {
        return $value;
    }
};

$woSummaryCostCategory = static function (array $poRow): string {
    $catName = trim((string) ($poRow['cost_category_name'] ?? ''));
    $catId = (int) ($poRow['cost_category_id'] ?? 0);
    if ($catName === '' && $catId > 0) {
        if (!function_exists('tnc_site_category_name')) {
            require_once dirname(__DIR__) . '/site_cost_categories.php';
        }
        $catName = tnc_site_category_name($catId);
    }

    return $catName !== '' ? $catName : '-';
};

$payRows = [];
foreach ($activePaymentPos as $linkedPo) {
    $poNumber = trim((string) ($linkedPo['po_number'] ?? ''));
    $linkedPoId = (int) ($linkedPo['id'] ?? 0);
    $subAmt = (float) ($linkedPo['subtotal_amount'] ?? 0);
    $vatAmt = (float) ($linkedPo['vat_amount'] ?? 0);
    $whtAmt = (float) ($linkedPo['withholding_amount'] ?? 0);
    $netAmt = Purchase::hirePoPayableAmount($linkedPo);
    $createdRaw = trim((string) ($linkedPo['created_at'] ?? ($linkedPo['issue_date'] ?? '')));
    $payRows[] = [
        'po_number' => $poNumber !== '' ? $poNumber : '-',
        'po_id' => $linkedPoId,
        'created_at' => $woSummaryFormatDate($createdRaw),
        'cost_category' => $woSummaryCostCategory($linkedPo),
        'installment' => Purchase::hirePayablePoSequenceLabel(
            $linkedPo,
            (int) ($linkedPo['installment_total'] ?? $installmentTotalCount)
        ),
        'sub' => $subAmt,
        'vat' => $vatAmt,
        'wht' => $whtAmt,
        'net' => $netAmt,
        'contract_line' => $netAmt,
    ];
}

$advanceRows = [];
foreach ($activeAdvancePos as $advancePo) {
    $poNumber = trim((string) ($advancePo['po_number'] ?? ''));
    $linkedPoId = (int) ($advancePo['id'] ?? 0);
    $subAmt = (float) ($advancePo['subtotal_amount'] ?? 0);
    $vatAmt = (float) ($advancePo['vat_amount'] ?? 0);
    $whtAmt = (float) ($advancePo['withholding_amount'] ?? 0);
    $netAmt = Purchase::hirePoPayableAmount($advancePo);
    $createdRaw = trim((string) ($advancePo['created_at'] ?? ($advancePo['issue_date'] ?? '')));
    $advanceRows[] = [
        'po_number' => $poNumber !== '' ? $poNumber : '-',
        'po_id' => $linkedPoId,
        'created_at' => $woSummaryFormatDate($createdRaw),
        'cost_category' => $woSummaryCostCategory($advancePo),
        'installment' => Purchase::formatHireAdvanceLabel($advancePo),
        'sub' => $subAmt,
        'vat' => $vatAmt,
        'wht' => $whtAmt,
        'net' => $netAmt,
        'contract_line' => $netAmt,
    ];
}

$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', false);
$company = array_values($companies)[0] ?? [];
$employerName = trim((string) ($company['name'] ?? ''));
$employerAddress = trim((string) ($company['address'] ?? ''));
$employerTaxId = trim((string) ($company['tax_id'] ?? ''));
$employerPhone = trim((string) ($company['phone'] ?? ''));
$poViewBase = app_path('pages/purchase/purchase-order-view.php');
$contractTitle = trim((string) ($contract['title'] ?? ''));
?>
<section class="wo-summary-panel no-print container-xl px-3 px-md-4 pb-4" id="wo-contract-summary">
    <style>
        .wo-summary-panel { margin-top: -0.25rem; }
        .wo-summary-kpi {
            border-radius: 0.875rem;
            border: 1px solid #e2e8f0;
            background: #fff;
            padding: 0.85rem 1rem;
            height: 100%;
            box-shadow: 0 2px 12px rgba(15, 23, 42, 0.04);
        }
        .wo-summary-kpi .label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; font-weight: 600; }
        .wo-summary-kpi .value { font-size: 1.2rem; font-weight: 700; font-variant-numeric: tabular-nums; }
        .wo-summary-card {
            border-radius: 0.875rem;
            background: #fff;
            border: 1px solid #e8edf4;
            box-shadow: 0 2px 12px rgba(15, 23, 42, 0.04);
        }
        .wo-summary-card h2 { font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; font-weight: 700; }
        .wo-summary-dl { display: grid; grid-template-columns: 130px 1fr; gap: 0.3rem 0.85rem; padding: 0.3rem 0; border-bottom: 1px solid #f1f5f9; font-size: 0.92rem; }
        .wo-summary-dl:last-child { border-bottom: 0; }
        .wo-summary-dl dt { color: #64748b; font-weight: 500; margin: 0; }
        .wo-summary-dl dd { margin: 0; color: #0f172a; }
    </style>

    <?php if ($contractRemainingOver): ?>
        <div class="alert alert-danger py-2 mb-3"><i class="bi bi-exclamation-octagon-fill me-1"></i>จ่ายเกินมูลค่าสัญญาแล้ว <strong><?= number_format(abs($contractRemaining), 2) ?> บาท</strong> (คงเหลือ <?= number_format($contractRemaining, 2) ?> บาท)</div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg">
            <div class="wo-summary-kpi">
                <div class="label">มูลค่าสัญญา</div>
                <div class="value text-primary"><?= number_format((float) ($contract['contract_amount'] ?? 0), 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="wo-summary-kpi">
                <div class="label">สั่งจ่ายแล้ว</div>
                <div class="value text-success"><?= number_format($hireCommittedPayable, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="wo-summary-kpi">
                <div class="label">คงเหลือ</div>
                <div class="value <?= $contractRemainingCss ?>"><?= number_format($contractRemaining, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="wo-summary-kpi">
                <div class="label"><?= $hireOpenPaymentsView ? 'ครั้งที่สั่งจ่าย' : 'งวด (สั่งจ่ายแล้ว/ทั้งหมด)' ?></div>
                <div class="value"><?php if ($hireOpenPaymentsView): ?><?= number_format($paymentPoCount) ?> ครั้ง<?php else: ?><?= number_format($paymentPoCount) ?> / <?= $installmentTotalCount ?><?php endif; ?></div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="wo-summary-kpi">
                <div class="label">เบิกล่วงหน้า</div>
                <div class="value text-warning"><?= number_format($hireCommittedAdvance, 2) ?><?php if ($advancePoCount > 0): ?> <span class="fs-6 text-muted fw-normal">(<?= number_format($advancePoCount) ?> ครั้ง)</span><?php endif; ?></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="wo-summary-card p-3 p-md-4 h-100">
                <h2 class="mb-3">คู่สัญญา</h2>
                <dl class="m-0">
                    <div class="wo-summary-dl">
                        <dt>ผู้ว่าจ้าง</dt>
                        <dd><?= htmlspecialchars($employerName !== '' ? $employerName : '-', ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <?php if ($employerTaxId !== '' || $employerPhone !== ''): ?>
                    <div class="wo-summary-dl">
                        <dt>เลขผู้เสียภาษี / โทร</dt>
                        <dd><?= htmlspecialchars($employerTaxId !== '' ? $employerTaxId : '-', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($employerPhone !== '' ? $employerPhone : '-', ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <?php endif; ?>
                    <?php if ($employerAddress !== ''): ?>
                    <div class="wo-summary-dl">
                        <dt>ที่อยู่</dt>
                        <dd><?= nl2br(htmlspecialchars($employerAddress, ENT_QUOTES, 'UTF-8')) ?></dd>
                    </div>
                    <?php endif; ?>
                    <div class="wo-summary-dl">
                        <dt>ผู้รับจ้าง</dt>
                        <dd class="fw-semibold"><?= htmlspecialchars((string) ($contract['contractor_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                </dl>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="wo-summary-card p-3 p-md-4 h-100">
                <h2 class="mb-2">หมายเหตุ</h2>
                <div class="text-secondary small" style="line-height: 1.65;">
                    <?= nl2br(htmlspecialchars($contractTitle !== '' ? $contractTitle : '-', ENT_QUOTES, 'UTF-8')) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="wo-summary-card p-3 p-md-4 mb-3">
        <h2 class="mb-3"><?= $hireOpenPaymentsView ? 'ประวัติสั่งจ่าย (ครั้ง)' : 'ประวัติจ่ายงวด' ?></h2>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 w-100" id="woPayDT">
                <thead class="table-light">
                    <tr>
                        <th>PO No.</th>
                        <th class="text-center">วันที่</th>
                        <th>หมวดหมู่</th>
                        <th class="text-center"><?= $hireOpenPaymentsView ? 'ครั้ง' : 'งวด' ?></th>
                        <th class="text-end">ก่อนภาษี</th>
                        <th class="text-end">VAT</th>
                        <th class="text-end">หัก ณ ที่จ่าย</th>
                        <th class="text-end">สุทธิจ่าย</th>
                        <th class="text-end">มูลค่าสัญญา (บรรทัด)</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="wo-summary-card p-3 p-md-4">
        <h2 class="mb-3">ประวัติเบิกล่วงหน้า</h2>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 w-100" id="woAdvanceDT">
                <thead class="table-light">
                    <tr>
                        <th>PO No.</th>
                        <th class="text-center">วันที่</th>
                        <th>หมวดหมู่</th>
                        <th class="text-center">ครั้ง</th>
                        <th class="text-end">ก่อนภาษี</th>
                        <th class="text-end">VAT</th>
                        <th class="text-end">หัก ณ ที่จ่าย</th>
                        <th class="text-end">สุทธิจ่าย</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</section>
<script>
window.__woSummaryPayRows = <?= json_encode($payRows, JSON_UNESCAPED_UNICODE) ?>;
window.__woSummaryAdvanceRows = <?= json_encode($advanceRows, JSON_UNESCAPED_UNICODE) ?>;
window.__woSummaryPoViewBase = <?= json_encode($poViewBase, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
