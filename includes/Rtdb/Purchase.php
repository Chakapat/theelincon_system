<?php

declare(strict_types=1);

namespace Theelincon\Rtdb;

final class Purchase
{
    private static function tncAuditEnsure(): void
    {
        static $loaded = false;
        if (!$loaded) {
            require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
            $loaded = true;
        }
    }

    public static function generatePONumber(): string
    {
        $prefix = 'PO-TNC-' . date('ym') . '-';
        $rows = Db::tableRows('purchase_orders');
        $max = 0;
        foreach ($rows as $r) {
            $pn = (string) ($r['po_number'] ?? '');
            if (strncmp($pn, $prefix, strlen($prefix)) === 0) {
                $tail = substr($pn, -3);
                $max = max($max, (int) $tail);
            }
        }

        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /** PR-TNC-YYMM-xxx */
    public static function nextPRNumber(): string
    {
        $suffix = date('ym');
        $prefix = 'PR-TNC-' . $suffix . '-';
        $max = 0;
        foreach (Db::tableRows('purchase_requests') as $r) {
            $pn = (string) ($r['pr_number'] ?? '');
            if (strncmp($pn, $prefix, strlen($prefix)) === 0) {
                $tail = substr($pn, -3);
                $max = max($max, (int) $tail);
            }
        }

        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /** NEED-TNC-MMYY-xxx */
    public static function nextNeedNumber(): string
    {
        $suffix = date('my');
        $prefix = 'NEED-TNC-' . $suffix . '-';
        $max = 0;
        foreach (Db::tableRows('purchase_needs') as $r) {
            $num = (string) ($r['need_number'] ?? '');
            if (strncmp($num, $prefix, strlen($prefix)) === 0) {
                $tail = substr($num, -3);
                $max = max($max, (int) $tail);
            }
        }

        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /** HC-TNC-YYMM-xxx — เลขที่สัญญาจ้างอิสระ (ไม่อิง PR) */
    public static function nextHireContractNumber(): string
    {
        $suffix = date('ym');
        $prefix = 'HC-TNC-' . $suffix . '-';
        $max = 0;
        foreach (Db::tableRows('hire_contracts') as $r) {
            $pn = (string) ($r['pr_number'] ?? '');
            if (strncmp($pn, $prefix, strlen($prefix)) === 0) {
                $tail = substr($pn, -3);
                $max = max($max, (int) $tail);
            }
        }

        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    public static function createHireContractIfNeededForPr(int $prId): void
    {
        if ($prId <= 0) {
            return;
        }

        $pr = Db::row('purchase_requests', (string) $prId);
        if ($pr === null) {
            return;
        }

        $requestType = trim((string) ($pr['request_type'] ?? ($pr['procurement_type'] ?? 'purchase')));
        if (!in_array($requestType, ['hire', 'จัดจ้าง'], true)) {
            return;
        }

        $exists = Db::findFirst('hire_contracts', static function (array $row) use ($prId): bool {
            return isset($row['pr_id']) && (int) $row['pr_id'] === $prId;
        });
        if ($exists !== null) {
            return;
        }

        $contractId = Db::nextNumericId('hire_contracts', 'id');
        $amount = (float) ($pr['contract_amount'] ?? ($pr['total_amount'] ?? 0));
        $installments = (int) ($pr['installment_total'] ?? 1);
        if ($installments < 1) {
            $installments = 1;
        }

        Db::setRow('hire_contracts', (string) $contractId, [
            'id' => $contractId,
            'pr_id' => $prId,
            'pr_number' => (string) ($pr['pr_number'] ?? ''),
            'contractor_name' => (string) ($pr['contractor_name'] ?? ''),
            'title' => (string) ($pr['details'] ?? ''),
            'contract_amount' => round($amount, 2),
            'installment_total' => $installments,
            'paid_installments' => 0,
            'paid_amount' => 0,
            'remaining_amount' => round($amount, 2),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        self::tncAuditEnsure();
        $hcRow = Db::row('hire_contracts', (string) $contractId);
        $prNoHc = (string) ($pr['pr_number'] ?? '');
        tnc_audit_log('create', 'hire_contract', (string) $contractId, $prNoHc !== '' ? ($prNoHc . ' (จาก PR)') : ('PR#' . $prId), [
            'source' => 'Purchase::createHireContractIfNeededForPr',
            'after' => $hcRow,
            'meta' => ['pr_id' => $prId],
        ]);
    }

    public static function seedPoPayments(int $poId, float $totalAmount, ?int $hireContractId = null): void
    {
        if ($poId <= 0) {
            return;
        }

        $po = Db::row('purchase_orders', (string) $poId);
        if ($po === null) {
            return;
        }

        $amount = round($totalAmount, 2);
        $seq = 1;
        $existing = Db::findFirst('po_payments', static function (array $r) use ($poId): bool {
            return isset($r['po_id']) && (int) $r['po_id'] === $poId;
        });
        if ($existing !== null) {
            return;
        }

        $payId = Db::nextNumericId('po_payments', 'id');
        Db::setRow('po_payments', (string) $payId, [
            'id' => $payId,
            'po_id' => $poId,
            'po_number' => (string) ($po['po_number'] ?? ''),
            'seq' => $seq,
            'amount' => $amount,
            'paid_amount' => 0,
            'status' => 'unpaid',
            'slip_path' => '',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        self::tncAuditEnsure();
        $payRowSnap = Db::row('po_payments', (string) $payId);
        $poNoSeed = (string) ($po['po_number'] ?? '');
        tnc_audit_log('create', 'po_payment', (string) $payId, $poNoSeed !== '' ? ($poNoSeed . ' งวดชำระ') : ('PO#' . $poId . ' งวดชำระ'), [
            'source' => 'Purchase::seedPoPayments',
            'after' => $payRowSnap,
            'meta' => ['po_id' => $poId],
        ]);

        if ($hireContractId === null || $hireContractId <= 0) {
            return;
        }

        $hire = Db::row('hire_contracts', (string) $hireContractId);
        if ($hire === null) {
            return;
        }

        $installmentNo = (int) ($po['installment_no'] ?? 0);
        if ($installmentNo <= 0) {
            $installmentNo = max(1, ((int) ($hire['paid_installments'] ?? 0)) + 1);
        }
        $installmentTotal = max(1, (int) ($hire['installment_total'] ?? 1));

        $hirePayId = Db::nextNumericId('hire_contract_payments', 'id');
        Db::setRow('hire_contract_payments', (string) $hirePayId, [
            'id' => $hirePayId,
            'hire_contract_id' => $hireContractId,
            'pr_id' => (int) ($hire['pr_id'] ?? 0),
            'po_id' => $poId,
            'po_number' => (string) ($po['po_number'] ?? ''),
            'installment_no' => $installmentNo,
            'installment_total' => $installmentTotal,
            'amount' => $amount,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $payments = Db::filter('hire_contract_payments', static function (array $row) use ($hireContractId): bool {
            return isset($row['hire_contract_id']) && (int) $row['hire_contract_id'] === $hireContractId;
        });
        $paidAmount = 0.0;
        $paidInstallments = 0;
        foreach ($payments as $p) {
            $paidAmount += (float) ($p['amount'] ?? 0);
            ++$paidInstallments;
        }
        $contractAmount = (float) ($hire['contract_amount'] ?? 0);
        $remaining = round($contractAmount - $paidAmount, 2);
        if ($remaining < 0) {
            $remaining = 0.0;
        }

        $hireBeforeUp = $hire;
        Db::mergeRow('hire_contracts', (string) $hireContractId, [
            'paid_amount' => round($paidAmount, 2),
            'paid_installments' => $paidInstallments,
            'remaining_amount' => $remaining,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $hireAfterUp = Db::row('hire_contracts', (string) $hireContractId);
        $hcpSnap = Db::row('hire_contract_payments', (string) $hirePayId);
        tnc_audit_log('create', 'hire_contract_payment', (string) $hirePayId, $poNoSeed !== '' ? ('งวดสัญญา ' . $poNoSeed) : ('สัญญาจ้าง #' . $hireContractId), [
            'source' => 'Purchase::seedPoPayments',
            'after' => $hcpSnap,
            'meta' => [
                'hire_contract_id' => $hireContractId,
                'po_id' => $poId,
                'po_payment_id' => $payId,
            ],
        ]);
        $hcDoc = trim((string) ($hireAfterUp['pr_number'] ?? ''));
        tnc_audit_log('update', 'hire_contract', (string) $hireContractId, $hcDoc !== '' ? $hcDoc : ('#' . $hireContractId), [
            'source' => 'Purchase::seedPoPayments',
            'action' => 'hire_contract_payment_totals',
            'before' => $hireBeforeUp,
            'after' => $hireAfterUp,
            'meta' => [
                'po_id' => $poId,
                'hire_contract_payment_id' => $hirePayId,
            ],
        ]);
    }
}
