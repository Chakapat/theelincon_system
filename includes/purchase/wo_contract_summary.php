<?php

declare(strict_types=1);

/**
 * สรุปสัญญาจ้างบนหน้า Work Order (WO) — hire_contract = WO ในมุม UI
 * ต้องมีใน scope: $hireContractIdForPo (int), $po (array)
 */

require_once dirname(__DIR__) . '/purchase/wo_contract_summary_data.php';

$woSummaryIncluded = true;
$woSummaryViewPoId = (int) ($po['id'] ?? 0);

$woCtx = tnc_wo_contract_summary_context((int) $hireContractIdForPo);
if ($woCtx === null) {
    $woSummaryIncluded = false;

    return;
}

extract($woCtx, EXTR_OVERWRITE);
$contractRemainingOver = (bool) ($contract_remaining_over ?? false);
$contractRemainingCss = (string) ($contract_remaining_css ?? 'text-primary');
$contractRemaining = (float) ($contract_remaining ?? 0);
$hireCommittedPayable = (float) ($hire_committed_payable ?? 0);
$hireCommittedAdvance = (float) ($hire_committed_advance ?? 0);
$paymentPoCount = (int) ($payment_po_count ?? 0);
$advancePoCount = (int) ($advance_po_count ?? 0);
$installmentTotalCount = (int) ($installment_total_count ?? 0);
$hireOpenPaymentsView = !empty($hire_open_payments_view);
$payTableTitle = (string) ($pay_table_title ?? 'ประวัติสั่งจ่าย');
$payRows = (array) ($pay_rows ?? []);
$advanceRows = (array) ($advance_rows ?? []);
$historyRows = (array) ($history_rows ?? tnc_wo_contract_summary_history_rows($payRows, $advanceRows));
$historyTableTitle = (string) ($history_table_title ?? 'ประวัติการจ่าย (สั่งจ่าย / เบิกล่วงหน้า)');
$historyTotalPaid = (float) ($history_total_paid ?? ($hireCommittedPayable + $hireCommittedAdvance));
$historyRemaining = (float) ($history_remaining ?? ((float) ($contract['contract_amount'] ?? 0) - $historyTotalPaid));
$historyRemainingCss = (string) ($history_remaining_css ?? $contractRemainingCss);
$employerName = (string) ($employer_name ?? '');
$employerAddress = (string) ($employer_address ?? '');
$employerTaxId = (string) ($employer_tax_id ?? '');
$employerPhone = (string) ($employer_phone ?? '');
$poViewBase = (string) ($po_view_base ?? app_path('pages/purchase/purchase-order-view.php'));
$contractTitle = (string) ($contract_title ?? '');
$contractDocNo = (string) ($contract_doc_no ?? '');
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
        .wo-summary-formula {
            margin-top: 1rem;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            font-size: 0.92rem;
            font-variant-numeric: tabular-nums;
        }
        .wo-summary-formula strong { color: #0f172a; }
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
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="mb-0"><?= htmlspecialchars($historyTableTitle, ENT_QUOTES, 'UTF-8') ?></h2>
            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 js-wo-history-print" data-print-mode="wo_history">
                <i class="bi bi-printer me-1"></i>พิมพ์รายงาน
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 w-100" id="woHistoryDT">
                <thead class="table-light">
                    <tr>
                        <th>PO No.</th>
                        <th class="text-center">วันที่</th>
                        <th class="text-center">ประเภท</th>
                        <th>หมวดหมู่</th>
                        <th class="text-center"><?= $hireOpenPaymentsView ? 'ครั้ง' : 'งวด' ?></th>
                        <th class="text-end">ก่อนภาษี</th>
                        <th class="text-end">VAT</th>
                        <th class="text-end">หัก ณ ที่จ่าย</th>
                        <th class="text-end">หักประกันผลงาน</th>
                        <th class="text-end">สุทธิจ่าย</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="wo-summary-formula">
            <strong>สรุป:</strong>
            มูลค่าสัญญา <?= number_format((float) ($contract['contract_amount'] ?? 0), 2) ?>
            − ยอดจ่ายรวม <?= number_format($historyTotalPaid, 2) ?>
            <span class="text-muted">(สั่งจ่าย <?= number_format($hireCommittedPayable, 2) ?> + เบิกล่วงหน้า <?= number_format($hireCommittedAdvance, 2) ?>)</span>
            = คงเหลือ <strong class="<?= $historyRemainingCss ?>"><?= number_format($historyRemaining, 2) ?></strong> บาท
        </div>
    </div>
</section>
<script>
window.__woSummaryHistoryRows = <?= json_encode($historyRows, JSON_UNESCAPED_UNICODE) ?>;
window.__woSummaryPoViewBase = <?= json_encode($poViewBase, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
window.__woSummaryViewPoId = <?= (int) $woSummaryViewPoId ?>;
</script>
