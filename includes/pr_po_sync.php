<?php

declare(strict_types=1);

/**
 * ซิงก์ PR → PO ที่เชื่อม (หมวดหมู่, ไซต์, รายการ, ยอดรวม)
 */

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

require_once __DIR__ . '/purchase_cascade_delete.php';
require_once __DIR__ . '/hire_line_items.php';
require_once __DIR__ . '/site_budget.php';

if (!function_exists('tnc_pr_po_sync_meta_from_pr')) {
    /** @return array<string, mixed> */
    function tnc_pr_po_sync_meta_from_pr(array $prRow): array
    {
        return [
            'site_id' => (int) ($prRow['site_id'] ?? 0),
            'site_name' => trim((string) ($prRow['site_name'] ?? '')),
            'cost_category_id' => (int) ($prRow['cost_category_id'] ?? 0),
            'cost_category_name' => trim((string) ($prRow['cost_category_name'] ?? '')),
            'reference_pr_number' => trim((string) ($prRow['pr_number'] ?? '')),
        ];
    }
}

if (!function_exists('tnc_pr_load_purchase_line_items')) {
    /**
     * @return list<array<string, mixed>>
     */
    function tnc_pr_load_purchase_line_items(int $prId): array
    {
        if ($prId <= 0) {
            return [];
        }
        $rows = Db::filter('purchase_request_items', static function (array $r) use ($prId): bool {
            return isset($r['pr_id']) && (int) $r['pr_id'] === $prId;
        });
        Db::sortRows($rows, 'id', false);
        $out = [];
        foreach ($rows as $row) {
            if (trim((string) ($row['line_type'] ?? 'item')) === 'group') {
                continue;
            }
            $qty = (float) ($row['quantity'] ?? 0);
            if (trim((string) ($row['description'] ?? '')) === '' || $qty <= 0) {
                continue;
            }
            $out[] = [
                'description' => trim((string) ($row['description'] ?? '')),
                'quantity' => $qty,
                'unit' => trim((string) ($row['unit'] ?? '')),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'total' => (float) ($row['total'] ?? 0),
                'discount_input' => (string) ($row['discount_input'] ?? ''),
                'discount_type' => (string) ($row['discount_type'] ?? 'amount'),
                'discount_value' => (float) ($row['discount_value'] ?? 0),
                'discount_amount' => (float) ($row['discount_amount'] ?? 0),
            ];
        }

        return $out;
    }
}

if (!function_exists('tnc_hire_lines_from_pr_id')) {
    /**
     * @return list<array<string, mixed>>
     */
    function tnc_hire_lines_from_pr_id(int $prId): array
    {
        if ($prId <= 0) {
            return [];
        }
        $rows = Db::filter('purchase_request_items', static function (array $r) use ($prId): bool {
            return isset($r['pr_id']) && (int) $r['pr_id'] === $prId;
        });
        Db::sortRows($rows, 'id', false);
        $out = [];
        foreach ($rows as $row) {
            $lineType = trim((string) ($row['line_type'] ?? 'item'));
            if ($lineType === 'group') {
                $out[] = [
                    'line_type' => 'group',
                    'description' => trim((string) ($row['description'] ?? '')),
                    'quantity' => 0.0,
                    'unit' => '',
                    'material_price' => 0.0,
                    'labor_price' => 0.0,
                    'unit_price' => 0.0,
                    'total' => 0.0,
                ];
                continue;
            }
            $qty = (float) ($row['quantity'] ?? 0);
            if (trim((string) ($row['description'] ?? '')) === '' || $qty <= 0) {
                continue;
            }
            $out[] = [
                'line_type' => 'item',
                'description' => trim((string) ($row['description'] ?? '')),
                'quantity' => $qty,
                'unit' => trim((string) ($row['unit'] ?? '')),
                'material_price' => (float) ($row['material_price'] ?? 0),
                'labor_price' => (float) ($row['labor_price'] ?? 0),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'total' => (float) ($row['total'] ?? 0),
            ];
        }

        return $out;
    }
}

if (!function_exists('tnc_pr_sync_po_unpaid_payment_amounts')) {
    function tnc_pr_sync_po_unpaid_payment_amounts(int $poId, float $amount): void
    {
        if ($poId <= 0) {
            return;
        }
        $amount = round(max(0.0, $amount), 2);
        foreach (Db::filter('po_payments', static fn (array $r): bool => (int) ($r['po_id'] ?? 0) === $poId) as $payRow) {
            $payId = (int) ($payRow['id'] ?? 0);
            if ($payId <= 0) {
                continue;
            }
            $st = strtolower(trim((string) ($payRow['status'] ?? 'unpaid')));
            if ($st === 'paid') {
                continue;
            }
            $payPk = Db::pkForLogicalId('po_payments', $payId);
            if ($payPk !== null && $payPk !== '') {
                Db::mergeRow('po_payments', $payPk, ['amount' => $amount]);
            }
        }
    }
}

if (!function_exists('tnc_pr_sync_purchase_po_from_pr')) {
    /**
     * @param list<array<string, mixed>> $prItems
     *
     * @return array{ok:bool,reason?:string}
     */
    function tnc_pr_sync_purchase_po_from_pr(int $poId, array $po, array $prRow, array $prItems): array
    {
        if ($poId <= 0 || $prItems === []) {
            return ['ok' => false, 'reason' => 'no_items'];
        }

        $lineSum = 0.0;
        foreach ($prItems as $item) {
            $lineSum += round((float) ($item['total'] ?? 0), 2);
        }
        $lineSum = round($lineSum, 2);
        if ($lineSum <= 0) {
            return ['ok' => false, 'reason' => 'no_items'];
        }

        $vatEnabled = (int) ($prRow['vat_enabled'] ?? 0) === 1 ? 1 : 0;
        $vatMode = trim((string) ($prRow['vat_mode'] ?? 'exclusive'));
        if (!in_array($vatMode, ['exclusive', 'inclusive'], true)) {
            $vatMode = 'exclusive';
        }
        $totals = tnc_po_compute_totals($lineSum, $vatEnabled, $vatMode, 'none');

        $siteId = (int) ($prRow['site_id'] ?? 0);
        $catId = (int) ($prRow['cost_category_id'] ?? 0);
        $budget = tnc_site_budget_validate($siteId, $catId, (float) $totals['net'], $poId);
        if (!$budget['ok']) {
            return ['ok' => false, 'reason' => (string) ($budget['error_code'] ?? 'site_budget_exceeded')];
        }

        $pk = Db::pkForLogicalId('purchase_orders', $poId);
        if ($pk === null || $pk === '') {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        $before = Db::row('purchase_orders', $pk) ?? $po;
        Db::setRow('purchase_orders', $pk, array_merge($before, tnc_pr_po_sync_meta_from_pr($prRow), [
            'total_amount' => $totals['net'],
            'gross_amount' => $totals['gross'],
            'subtotal_amount' => $totals['subtotal'],
            'vat_amount' => $totals['vat'],
            'vat_enabled' => $vatEnabled,
            'vat_mode' => $totals['vat_mode'],
            'withholding_type' => $totals['withholding_type'],
            'withholding_amount' => $totals['wht'],
        ]));

        tnc_po_delete_line_items($poId);
        foreach ($prItems as $item) {
            $iid = Db::nextNumericId('purchase_order_items', 'id');
            Db::setRow('purchase_order_items', (string) $iid, [
                'id' => $iid,
                'po_id' => $poId,
                'description' => (string) ($item['description'] ?? ''),
                'quantity' => (float) ($item['quantity'] ?? 0),
                'unit' => (string) ($item['unit'] ?? ''),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'total' => (float) ($item['total'] ?? 0),
                'discount_input' => (string) ($item['discount_input'] ?? ''),
                'discount_type' => (string) ($item['discount_type'] ?? 'amount'),
                'discount_value' => (float) ($item['discount_value'] ?? 0),
                'discount_amount' => (float) ($item['discount_amount'] ?? 0),
            ]);
        }

        tnc_pr_sync_po_unpaid_payment_amounts($poId, (float) $totals['net']);

        $after = Db::row('purchase_orders', $pk);
        if (function_exists('tnc_audit_log')) {
            $poNo = trim((string) ($after['po_number'] ?? ''));
            tnc_audit_log('update', 'purchase_order', (string) $poId, $poNo !== '' ? $poNo : ('#' . $poId), [
                'source' => 'pr_po_sync.php',
                'action' => 'sync_purchase_po_from_pr',
                'before' => $before,
                'after' => $after,
                'meta' => ['pr_id' => (int) ($prRow['id'] ?? 0)],
            ]);
        }

        return ['ok' => true];
    }
}

if (!function_exists('tnc_pr_sync_hire_contract_po_from_pr')) {
    /**
     * @param list<array<string, mixed>> $hireLines
     *
     * @return array{ok:bool,reason?:string}
     */
    function tnc_pr_sync_hire_contract_po_from_pr(int $poId, array $po, array $prRow, array $hireLines): array
    {
        if ($poId <= 0 || $hireLines === []) {
            return ['ok' => false, 'reason' => 'no_items'];
        }

        $hireSubtotal = round((float) ($prRow['subtotal_amount'] ?? 0), 2);
        if ($hireSubtotal <= 0) {
            $hireSubtotal = round(tnc_hire_subtotal_from_lines($hireLines), 2);
        }
        if ($hireSubtotal <= 0) {
            return ['ok' => false, 'reason' => 'no_items'];
        }

        $vatEnabled = (int) ($prRow['vat_enabled'] ?? 0) === 1 ? 1 : 0;
        $vatAmt = round((float) ($prRow['vat_amount'] ?? 0), 2);
        $gross = round((float) ($prRow['total_amount'] ?? 0), 2);
        if ($gross <= 0) {
            $gross = round($hireSubtotal + ($vatEnabled ? $vatAmt : 0.0), 2);
        }
        if ($vatEnabled && $vatAmt <= 0) {
            $vatAmt = round(max(0.0, $gross - $hireSubtotal), 2);
        }

        $retention = round((float) ($po['retention_amount'] ?? 0), 2);
        if (strtolower(trim((string) ($po['retention_type'] ?? 'none'))) === 'none') {
            $retention = 0.0;
        }
        $payable = round(max(0.0, $gross - $retention), 2);
        if ($payable <= 0) {
            return ['ok' => false, 'reason' => 'invalid_payable'];
        }

        $hcId = (int) ($po['hire_contract_id'] ?? 0);
        if ($hcId > 0) {
            $hcCheck = Purchase::hireContractCanUpdatePoPayable($hcId, $poId, $payable, true);
            if (!$hcCheck['ok']) {
                return ['ok' => false, 'reason' => (string) ($hcCheck['message'] ?? 'contract_exceeds')];
            }
        }

        $pk = Db::pkForLogicalId('purchase_orders', $poId);
        if ($pk === null || $pk === '') {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        $before = Db::row('purchase_orders', $pk) ?? $po;
        Db::setRow('purchase_orders', $pk, array_merge($before, tnc_pr_po_sync_meta_from_pr($prRow), [
            'total_amount' => $gross,
            'gross_amount' => $gross,
            'subtotal_amount' => $hireSubtotal,
            'vat_amount' => $vatAmt,
            'vat_enabled' => $vatEnabled,
            'vat_mode' => 'exclusive',
            'payable_amount' => $payable,
            'retention_type' => $retention > 0 ? 'fixed' : 'none',
            'retention_amount' => $retention,
            'withholding_type' => 'none',
            'withholding_amount' => 0,
        ]));

        tnc_po_delete_line_items($poId);
        tnc_hire_save_po_items($poId, $hireLines);

        tnc_pr_sync_po_unpaid_payment_amounts($poId, $payable);

        foreach (Db::filter('hire_contract_payments', static fn (array $r): bool => (int) ($r['po_id'] ?? 0) === $poId) as $hcpRow) {
            $hcpId = (int) ($hcpRow['id'] ?? 0);
            if ($hcpId > 0) {
                Db::mergeRow('hire_contract_payments', (string) $hcpId, ['amount' => $payable]);
            }
        }

        Purchase::syncHireContractFromContractPo($poId);

        $after = Db::row('purchase_orders', $pk);
        if (function_exists('tnc_audit_log')) {
            $poNo = trim((string) ($after['po_number'] ?? ''));
            tnc_audit_log('update', 'purchase_order', (string) $poId, $poNo !== '' ? $poNo : ('#' . $poId), [
                'source' => 'pr_po_sync.php',
                'action' => 'sync_hire_contract_po_from_pr',
                'before' => $before,
                'after' => $after,
                'meta' => ['pr_id' => (int) ($prRow['id'] ?? 0)],
            ]);
        }

        return ['ok' => true];
    }
}

if (!function_exists('tnc_pr_sync_linked_purchase_orders')) {
    /**
     * @return array{synced:int,skipped:list<array{po_id:int,po_number:string,reason:string}>,errors:list<string>}
     */
    function tnc_pr_sync_linked_purchase_orders(int $prId, array $prRow): array
    {
        $result = ['synced' => 0, 'skipped' => [], 'errors' => []];
        if ($prId <= 0) {
            return $result;
        }

        $procurement = trim((string) ($prRow['request_type'] ?? ($prRow['procurement_type'] ?? 'purchase')));
        $isHire = $procurement === 'hire';
        $purchaseItems = $isHire ? [] : tnc_pr_load_purchase_line_items($prId);
        $hireLines = $isHire ? tnc_hire_lines_from_pr_id($prId) : [];

        foreach (Purchase::collectPurchaseOrdersForPr($prId) as $po) {
            $poId = (int) ($po['id'] ?? 0);
            if ($poId <= 0) {
                continue;
            }
            $poNo = trim((string) ($po['po_number'] ?? ''));
            if ($poNo === '') {
                $poNo = 'PO-' . $poId;
            }

            if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
                $result['skipped'][] = ['po_id' => $poId, 'po_number' => $poNo, 'reason' => 'cancelled'];
                continue;
            }
            if (Purchase::poPaidLocksMutation($po)) {
                $result['skipped'][] = ['po_id' => $poId, 'po_number' => $poNo, 'reason' => 'paid'];
                continue;
            }

            $pk = Db::pkForLogicalId('purchase_orders', $poId);
            if ($pk === null || $pk === '') {
                $result['skipped'][] = ['po_id' => $poId, 'po_number' => $poNo, 'reason' => 'not_found'];
                continue;
            }

            $beforeMeta = Db::row('purchase_orders', $pk) ?? $po;

            if (Purchase::isHireContractPo($po) && $isHire) {
                $sync = tnc_pr_sync_hire_contract_po_from_pr($poId, $beforeMeta, $prRow, $hireLines);
            } elseif (Purchase::isHirePayablePo($po) || (trim((string) ($po['order_type'] ?? 'purchase')) === 'hire' && !Purchase::isHireContractPo($po))) {
                Db::mergeRow('purchase_orders', $pk, tnc_pr_po_sync_meta_from_pr($prRow));
                if (function_exists('tnc_audit_log')) {
                    $afterMeta = Db::row('purchase_orders', $pk);
                    tnc_audit_log('update', 'purchase_order', (string) $poId, $poNo, [
                        'source' => 'pr_po_sync.php',
                        'action' => 'sync_hire_payable_po_meta_from_pr',
                        'before' => $beforeMeta,
                        'after' => $afterMeta,
                        'meta' => ['pr_id' => $prId, 'scope' => 'meta_only'],
                    ]);
                }
                $sync = ['ok' => true];
            } else {
                $sync = tnc_pr_sync_purchase_po_from_pr($poId, $beforeMeta, $prRow, $purchaseItems);
            }

            if (!empty($sync['ok'])) {
                ++$result['synced'];
                continue;
            }

            $reason = (string) ($sync['reason'] ?? 'sync_failed');
            $result['skipped'][] = ['po_id' => $poId, 'po_number' => $poNo, 'reason' => $reason];
            $result['errors'][] = $poNo . ': ' . $reason;
        }

        return $result;
    }
}

if (!function_exists('tnc_pr_po_sync_query_suffix')) {
    /** @param array{synced:int,skipped:list<array<string,mixed>>,errors:list<string>} $result */
    function tnc_pr_po_sync_query_suffix(array $result): string
    {
        $q = '';
        $synced = (int) ($result['synced'] ?? 0);
        if ($synced > 0) {
            $q .= '&po_synced=' . $synced;
        }
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        if ($errors !== []) {
            $q .= '&po_sync_error=' . rawurlencode((string) $errors[0]);
        }

        return $q;
    }
}
