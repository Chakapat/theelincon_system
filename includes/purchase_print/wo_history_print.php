<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/purchase/wo_contract_summary_data.php';

/** @return 'wo_history'|null */
function tnc_wo_history_resolve_print_mode(): ?string
{
    $m = strtolower(trim((string) ($_GET['print_mode'] ?? '')));
    if (in_array($m, ['wo_history', 'wo_pay', 'wo_advance'], true)) {
        return 'wo_history';
    }

    return null;
}

/** @param array<string, mixed> $ctx จาก tnc_wo_contract_summary_context() */
function tnc_wo_history_print_render(array $ctx): void
{
    $rows = (array) ($ctx['history_rows'] ?? []);
    if ($rows === []) {
        $rows = tnc_wo_contract_summary_history_rows(
            (array) ($ctx['pay_rows'] ?? []),
            (array) ($ctx['advance_rows'] ?? [])
        );
    }

    $reportTitle = trim((string) ($ctx['history_report_title'] ?? 'รายงานประวัติการจ่าย'));
    $contractDocNo = trim((string) ($ctx['contract_doc_no'] ?? ''));
    $contractorName = trim((string) ($ctx['contractor_name'] ?? ''));
    $hireOpenPaymentsView = !empty($ctx['hire_open_payments_view']);

    $sumSub = 0.0;
    $sumVat = 0.0;
    $sumWht = 0.0;
    $sumRetention = 0.0;
    $sumNet = 0.0;
    foreach ($rows as $row) {
        $sumSub += (float) ($row['sub'] ?? 0);
        $sumVat += (float) ($row['vat'] ?? 0);
        $sumWht += (float) ($row['wht'] ?? 0);
        $sumRetention += (float) ($row['retention'] ?? 0);
        $sumNet += (float) ($row['net'] ?? 0);
    }

    $fmt = static fn (float $n): string => number_format($n, 2);
    $contractAmount = (float) ($ctx['contract_amount'] ?? 0);
    $hireCommittedPayable = (float) ($ctx['hire_committed_payable'] ?? 0);
    $hireCommittedAdvance = (float) ($ctx['hire_committed_advance'] ?? 0);
    $historyTotalPaid = (float) ($ctx['history_total_paid'] ?? ($hireCommittedPayable + $hireCommittedAdvance));
    $historyRemaining = (float) ($ctx['history_remaining'] ?? ($contractAmount - $historyTotalPaid));
    $summaryRemainingCss = (string) ($ctx['history_remaining_css'] ?? 'text-primary');
    $paymentPoCount = (int) ($ctx['payment_po_count'] ?? 0);
    $advancePoCount = (int) ($ctx['advance_po_count'] ?? 0);
    $installmentTotalCount = (int) ($ctx['installment_total_count'] ?? 0);
    $colSpan = 11;
    $seqColLabel = $hireOpenPaymentsView ? 'ครั้ง' : 'งวด';
    ?>
    <div class="wo-hist-print-sheet">
        <div class="wo-hist-print-header">
            <div>
                <h1 class="wo-hist-print-title"><?= htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            </div>
            <div class="wo-hist-print-meta">
                <div>WORK ORDER: <strong><?= htmlspecialchars($contractDocNo !== '' ? $contractDocNo : '-', ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div>มูลค่าสัญญา: <strong><?= $fmt($contractAmount) ?></strong> บาท</div>
                <div>สั่งจ่ายแล้ว: <strong><?= $fmt($hireCommittedPayable) ?></strong> บาท<?php if ($paymentPoCount > 0): ?> (<?= number_format($paymentPoCount) ?> ครั้ง)<?php endif; ?></div>
                <div>เบิกล่วงหน้า: <strong><?= $fmt($hireCommittedAdvance) ?></strong> บาท<?php if ($advancePoCount > 0): ?> (<?= number_format($advancePoCount) ?> ครั้ง)<?php endif; ?></div>
                <?php if (!$hireOpenPaymentsView && $installmentTotalCount > 0): ?>
                <div>งวดจ่ายแล้ว: <strong><?= number_format($paymentPoCount) ?> / <?= $installmentTotalCount ?></strong></div>
                <?php endif; ?>
                <div>ผู้รับจ้าง: <strong><?= htmlspecialchars($contractorName !== '' ? $contractorName : '-', ENT_QUOTES, 'UTF-8') ?></strong></div>
            </div>
        </div>

        <div class="wo-hist-print-table-wrap">
            <table class="table table-bordered wo-hist-print-table wo-hist-print-table--history mb-0">
                <colgroup>
                    <col class="col-idx">
                    <col class="col-po">
                    <col class="col-date">
                    <col class="col-type">
                    <col class="col-cat">
                    <col class="col-seq">
                    <col class="col-sub">
                    <col class="col-vat">
                    <col class="col-wht">
                    <col class="col-ret">
                    <col class="col-net">
                </colgroup>
                <thead>
                    <tr>
                        <th class="wo-hist-cell-center">#</th>
                        <th class="wo-hist-cell-text">PO No.</th>
                        <th class="wo-hist-cell-center">วันที่</th>
                        <th class="wo-hist-cell-text">ประเภท</th>
                        <th class="wo-hist-cell-text">หมวดหมู่</th>
                        <th class="wo-hist-cell-text"><?= htmlspecialchars($seqColLabel, ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="wo-hist-cell-num">ก่อนภาษี</th>
                        <th class="wo-hist-cell-num">VAT</th>
                        <th class="wo-hist-cell-num wo-hist-th-compact">หัก&nbsp;ณ<br>ที่จ่าย</th>
                        <th class="wo-hist-cell-num wo-hist-th-compact">หัก<br>ประกัน</th>
                        <th class="wo-hist-cell-num">สุทธิจ่าย</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="<?= $colSpan ?>" class="wo-hist-print-empty">ไม่มีรายการในรายงานนี้</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $i => $row): ?>
                        <tr>
                            <td class="wo-hist-cell-center"><?= $i + 1 ?></td>
                            <td class="wo-hist-cell-text"><?= htmlspecialchars((string) ($row['po_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="wo-hist-cell-center"><?= htmlspecialchars((string) ($row['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="wo-hist-cell-text"><?= htmlspecialchars((string) ($row['row_type_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="wo-hist-cell-text"><?= htmlspecialchars((string) ($row['cost_category'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="wo-hist-cell-text"><?= htmlspecialchars((string) ($row['installment'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="wo-hist-cell-num"><?= $fmt((float) ($row['sub'] ?? 0)) ?></td>
                            <td class="wo-hist-cell-num"><?= $fmt((float) ($row['vat'] ?? 0)) ?></td>
                            <td class="wo-hist-cell-num"><?= $fmt((float) ($row['wht'] ?? 0)) ?></td>
                            <td class="wo-hist-cell-num"><?= $fmt((float) ($row['retention'] ?? 0)) ?></td>
                            <td class="wo-hist-cell-num"><?= $fmt((float) ($row['net'] ?? 0)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if ($rows !== []): ?>
                <tfoot>
                    <tr class="wo-hist-print-total-row">
                        <th colspan="6" class="wo-hist-cell-text text-end">รวม</th>
                        <th class="wo-hist-cell-num"><?= $fmt($sumSub) ?></th>
                        <th class="wo-hist-cell-num"><?= $fmt($sumVat) ?></th>
                        <th class="wo-hist-cell-num"><?= $fmt($sumWht) ?></th>
                        <th class="wo-hist-cell-num"><?= $fmt($sumRetention) ?></th>
                        <th class="wo-hist-cell-num"><?= $fmt($sumNet) ?></th>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <div class="wo-hist-print-summary">
            <strong>สรุป:</strong>
            มูลค่าสัญญา <?= $fmt($contractAmount) ?>
            − ยอดจ่ายรวม <?= $fmt($historyTotalPaid) ?>
            <span class="wo-hist-print-summary-note">(สั่งจ่าย <?= $fmt($hireCommittedPayable) ?> + เบิกล่วงหน้า <?= $fmt($hireCommittedAdvance) ?>)</span>
            = คงเหลือ <strong class="<?= htmlspecialchars($summaryRemainingCss, ENT_QUOTES, 'UTF-8') ?>"><?= $fmt($historyRemaining) ?></strong> บาท
        </div>
    </div>
    <?php
}
