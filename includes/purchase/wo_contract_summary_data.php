<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

/**
 * @param list<array<string, mixed>> $payRows
 * @param list<array<string, mixed>> $advanceRows
 * @return list<array<string, mixed>>
 */
function tnc_wo_contract_summary_history_rows(array $payRows, array $advanceRows): array
{
    $rows = [];
    foreach ($advanceRows as $row) {
        $row['row_type'] = 'advance';
        $row['row_type_label'] = 'เบิกล่วงหน้า';
        $rows[] = $row;
    }
    foreach ($payRows as $row) {
        $row['row_type'] = 'payment';
        $row['row_type_label'] = 'สั่งจ่าย';
        $rows[] = $row;
    }

    usort($rows, static function (array $a, array $b): int {
        $dateA = tnc_po_parse_date_ymd((string) ($a['created_at'] ?? ''));
        $dateB = tnc_po_parse_date_ymd((string) ($b['created_at'] ?? ''));
        if ($dateA !== $dateB) {
            return strcmp($dateB, $dateA);
        }

        return ((int) ($b['po_id'] ?? 0)) <=> ((int) ($a['po_id'] ?? 0));
    });

    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function tnc_wo_contract_summary_context(int $hireContractId): ?array
{
    if ($hireContractId <= 0) {
        return null;
    }

    $contract = Db::row('hire_contracts', (string) $hireContractId);
    if (!is_array($contract)) {
        return null;
    }

    if (!function_exists('tnc_po_issue_date_ymd')) {
        require_once dirname(__DIR__) . '/purchase_po_payment_slips.php';
    }

    Purchase::purgeStaleHireContractPayments($hireContractId);

    $resolvedPrId = (int) ($contract['pr_id'] ?? 0);
    $contractDocNo = Purchase::hireContractDocumentNumber($contract);
    $contractRemaining = Purchase::hireContractRemainingPayable($contract, $hireContractId);
    $hireCommittedPayable = Purchase::hireContractCommittedPayable($hireContractId);
    $hireCommittedAdvance = Purchase::hireContractCommittedAdvance($hireContractId);
    $contractRemainingOver = $contractRemaining < -0.0005;
    $contractRemainingCss = $contractRemainingOver
        ? 'text-danger fw-bold'
        : ($contractRemaining <= 0.0005 ? 'text-success' : 'text-primary');

    $paymentPoCount = count(Purchase::activeHirePaymentPos($hireContractId, $resolvedPrId));
    $advancePoCount = count(Purchase::activeHireAdvancePos($hireContractId, $resolvedPrId));
    $activePaymentPos = Purchase::activeHirePaymentPos($hireContractId, $resolvedPrId);
    $activeAdvancePos = Purchase::activeHireAdvancePos($hireContractId, $resolvedPrId);
    usort($activePaymentPos, static function (array $a, array $b): int {
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });
    usort($activeAdvancePos, static function (array $a, array $b): int {
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });

    $installmentTotalCount = (int) ($contract['installment_total'] ?? 0);
    if ($installmentTotalCount < 0) {
        $installmentTotalCount = 0;
    }
    $hireOpenPaymentsView = Purchase::hireInstallmentsUnspecified($installmentTotalCount);
    $payTableTitle = $hireOpenPaymentsView ? 'ประวัติสั่งจ่าย (ครั้ง)' : 'ประวัติจ่ายงวด';

    $woSummaryFormatDate = static function (array $poRow): string {
        $ymd = tnc_po_issue_date_ymd($poRow);
        if ($ymd !== '') {
            return tnc_po_ymd_to_dmy($ymd);
        }

        return '-';
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
        $retAmt = (float) ($linkedPo['retention_amount'] ?? 0);
        $netAmt = Purchase::hirePoPayableAmount($linkedPo);
        $payRows[] = [
            'po_number' => $poNumber !== '' ? $poNumber : '-',
            'po_id' => $linkedPoId,
            'created_at' => $woSummaryFormatDate($linkedPo),
            'cost_category' => $woSummaryCostCategory($linkedPo),
            'installment' => Purchase::hirePayablePoSequenceLabel(
                $linkedPo,
                (int) ($linkedPo['installment_total'] ?? $installmentTotalCount)
            ),
            'sub' => $subAmt,
            'vat' => $vatAmt,
            'wht' => $whtAmt,
            'retention' => $retAmt,
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
        $retAmt = (float) ($advancePo['retention_amount'] ?? 0);
        $netAmt = Purchase::hirePoPayableAmount($advancePo);
        $advanceRows[] = [
            'po_number' => $poNumber !== '' ? $poNumber : '-',
            'po_id' => $linkedPoId,
            'created_at' => $woSummaryFormatDate($advancePo),
            'cost_category' => $woSummaryCostCategory($advancePo),
            'installment' => Purchase::formatHireAdvanceLabel($advancePo),
            'sub' => $subAmt,
            'vat' => $vatAmt,
            'wht' => $whtAmt,
            'retention' => $retAmt,
            'net' => $netAmt,
            'contract_line' => $netAmt,
        ];
    }

    $companies = Db::tableRows('company');
    Db::sortRows($companies, 'id', false);
    $company = array_values($companies)[0] ?? [];

    $contractAmount = (float) ($contract['contract_amount'] ?? 0);
    $historyTotalPaid = round($hireCommittedPayable + $hireCommittedAdvance, 2);
    $historyRemaining = round($contractAmount - $historyTotalPaid, 2);
    $historyRemainingOver = $historyRemaining < -0.0005;
    $historyRemainingCss = $historyRemainingOver
        ? 'text-danger fw-bold'
        : ($historyRemaining <= 0.0005 ? 'text-success' : 'text-primary');
    $historyRows = tnc_wo_contract_summary_history_rows($payRows, $advanceRows);

    return [
        'hire_contract_id' => $hireContractId,
        'contract' => $contract,
        'contract_doc_no' => $contractDocNo,
        'contract_title' => trim((string) ($contract['title'] ?? '')),
        'contractor_name' => trim((string) ($contract['contractor_name'] ?? '')),
        'contract_amount' => $contractAmount,
        'contract_remaining' => $contractRemaining,
        'contract_remaining_over' => $contractRemainingOver,
        'contract_remaining_css' => $contractRemainingCss,
        'history_total_paid' => $historyTotalPaid,
        'history_remaining' => $historyRemaining,
        'history_remaining_over' => $historyRemainingOver,
        'history_remaining_css' => $historyRemainingCss,
        'history_rows' => $historyRows,
        'history_table_title' => 'ประวัติการจ่าย (สั่งจ่าย / เบิกล่วงหน้า)',
        'history_report_title' => 'รายงานประวัติการจ่าย',
        'hire_committed_payable' => $hireCommittedPayable,
        'hire_committed_advance' => $hireCommittedAdvance,
        'payment_po_count' => $paymentPoCount,
        'advance_po_count' => $advancePoCount,
        'installment_total_count' => $installmentTotalCount,
        'hire_open_payments_view' => $hireOpenPaymentsView,
        'pay_table_title' => $payTableTitle,
        'pay_rows' => $payRows,
        'advance_rows' => $advanceRows,
        'employer_name' => trim((string) ($company['name'] ?? '')),
        'employer_address' => trim((string) ($company['address'] ?? '')),
        'employer_tax_id' => trim((string) ($company['tax_id'] ?? '')),
        'employer_phone' => trim((string) ($company['phone'] ?? '')),
        'employer_logo' => trim((string) ($company['logo'] ?? '')),
        'po_view_base' => app_path('pages/purchase/purchase-order-view.php'),
    ];
}
