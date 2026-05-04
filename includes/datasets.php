<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

/**
 * @return list<array<string, mixed>>
 */
function tnc_dataset_hire_contract_rows(): array
{
    $contracts = Db::tableRows('hire_contracts');
    Db::sortRows($contracts, 'id', true);
    $purchaseRequests = Db::tableKeyed('purchase_requests');
    $out = [];
    foreach ($contracts as $c) {
        $prId = (int) ($c['pr_id'] ?? 0);
        $hcId = (int) ($c['id'] ?? 0);
        $prRow = $purchaseRequests[(string) $prId] ?? null;
        $startDate = trim((string) ($prRow['created_at'] ?? ''));
        if ($startDate === '' && $prId === 0) {
            $startDate = trim((string) ($c['created_at'] ?? ''));
        }
        $startDateText = '-';
        if ($startDate !== '') {
            $ts = strtotime($startDate);
            if ($ts !== false) {
                $startDateText = date('d/m/Y', $ts);
            }
        }
        $out[] = [
            'hire_contract_id' => $hcId,
            'pr_id' => $prId,
            'pr_number' => (string) ($c['pr_number'] ?? '-'),
            'start_date' => $startDateText,
            'contractor_name' => (string) ($c['contractor_name'] ?? '-'),
            'contract_amount' => (float) ($c['contract_amount'] ?? 0),
            'paid_amount' => (float) ($c['paid_amount'] ?? 0),
            'paid_installments' => (int) ($c['paid_installments'] ?? 0),
            'installment_total' => (int) ($c['installment_total'] ?? 0),
            'remaining_amount' => (float) ($c['remaining_amount'] ?? 0),
        ];
    }

    return $out;
}
