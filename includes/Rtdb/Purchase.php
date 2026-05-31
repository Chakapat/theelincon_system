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
        // มูลค่าสัญญา: ใช้ contract_value (มาตรฐาน) → hire_total_value (สำรอง) → total_amount
        $amount = (float) ($pr['contract_value'] ?? ($pr['hire_total_value'] ?? ($pr['total_amount'] ?? 0)));
        $installments = (int) ($pr['installment_total'] ?? ($pr['hire_installment_count'] ?? 1));
        if ($installments < 1) {
            $installments = 1;
        }
        $contractor = trim((string) ($pr['contractor_name'] ?? ($pr['hire_contractor_name'] ?? '')));
        $contractorId = (int) ($pr['contractor_id'] ?? 0);
        $title = trim((string) ($pr['hire_scope_details'] ?? ($pr['details'] ?? '')));

        Db::setRow('hire_contracts', (string) $contractId, [
            'id' => $contractId,
            'pr_id' => $prId,
            'pr_number' => (string) ($pr['pr_number'] ?? ''),
            'contractor_name' => $contractor,
            'contractor_id' => $contractorId,
            'title' => $title,
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

    /**
     * ยอด payable ที่ออก PO จัดจ้างไปแล้ว (ไม่รวม PO ที่ยกเลิก)
     */
    public static function hireContractCommittedPayable(int $hireContractId): float
    {
        if ($hireContractId <= 0) {
            return 0.0;
        }
        $sum = 0.0;
        foreach (Db::tableRows('purchase_orders') as $po) {
            if ((int) ($po['hire_contract_id'] ?? 0) !== $hireContractId) {
                continue;
            }
            if (trim((string) ($po['order_type'] ?? 'purchase')) !== 'hire') {
                continue;
            }
            if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
                continue;
            }
            $payable = (float) ($po['payable_amount'] ?? 0);
            if ($payable <= 0) {
                $payable = (float) ($po['total_amount'] ?? 0);
            }
            $sum += $payable;
        }

        return round($sum, 2);
    }

    /** ยอดคงเหลือของสัญญา (มูลค่าสัญญา − ยอดที่ออก PO แล้ว) — อาจติดลบหากจ่ายเกินสัญญา */
    public static function hireContractRemainingPayable(array $hc, int $hireContractId): float
    {
        $contractAmount = round((float) ($hc['contract_amount'] ?? 0), 2);

        return round($contractAmount - self::hireContractCommittedPayable($hireContractId), 2);
    }

    /**
     * ตรวจว่าออก PO จัดจ้างงวดใหม่ได้หรือไม่
     * หากยอดเกินคงเหลือ ต้องส่ง allowOverContract=true (ผู้ใช้ยืนยันแล้ว)
     *
     * @return array{ok: bool, remaining: float, message: string}
     */
    public static function hireContractCanIssuePo(int $hireContractId, float $newPayable, bool $allowOverContract = false): array
    {
        $hc = Db::row('hire_contracts', (string) $hireContractId);
        if ($hc === null) {
            return ['ok' => false, 'remaining' => 0.0, 'message' => 'contract'];
        }
        $remaining = self::hireContractRemainingPayable($hc, $hireContractId);
        $newPayable = round($newPayable, 2);
        if ($newPayable > $remaining + 0.0005 && !$allowOverContract) {
            return ['ok' => false, 'remaining' => $remaining, 'message' => 'contract_exceeds_confirm'];
        }

        return ['ok' => true, 'remaining' => $remaining, 'message' => ''];
    }

    /**
     * ตรวจว่าแก้ไขยอด payable ของ PO จัดจ้างที่มีอยู่ได้หรือไม่
     * (คืนยอด payable เดิมของ PO นี้เข้าไปในวงเงินคงเหลือของสัญญา)
     *
     * @return array{ok: bool, remaining: float, room: float, message: string}
     */
    public static function hireContractCanUpdatePoPayable(int $hireContractId, int $poId, float $newPayable, bool $allowOverContract = false): array
    {
        $hc = Db::row('hire_contracts', (string) $hireContractId);
        if ($hc === null) {
            return ['ok' => false, 'remaining' => 0.0, 'room' => 0.0, 'message' => 'contract'];
        }
        $po = Db::row('purchase_orders', (string) $poId);
        if ($po === null) {
            return ['ok' => false, 'remaining' => 0.0, 'room' => 0.0, 'message' => 'not_found'];
        }
        $oldPayable = round((float) ($po['payable_amount'] ?? 0), 2);
        if ($oldPayable <= 0) {
            $oldPayable = round((float) ($po['total_amount'] ?? 0), 2);
        }
        $remaining = self::hireContractRemainingPayable($hc, $hireContractId);
        $room = round($remaining + $oldPayable, 2);
        $newPayable = round($newPayable, 2);
        if ($newPayable > $room + 0.0005 && !$allowOverContract) {
            return ['ok' => false, 'remaining' => $remaining, 'room' => $room, 'message' => 'contract_exceeds_confirm'];
        }

        return ['ok' => true, 'remaining' => $remaining, 'room' => $room, 'message' => ''];
    }

    /** คำนวณ paid_amount / remaining_amount ของสัญญาจ้างจากงวดที่ยังมี PO อยู่ */
    public static function syncHireContractTotals(int $hireContractId): void
    {
        if ($hireContractId <= 0) {
            return;
        }
        $hire = Db::row('hire_contracts', (string) $hireContractId);
        if ($hire === null) {
            return;
        }
        $payments = Db::filter('hire_contract_payments', static function (array $row) use ($hireContractId): bool {
            return isset($row['hire_contract_id']) && (int) $row['hire_contract_id'] === $hireContractId;
        });
        $paidAmount = 0.0;
        $paidInstallments = 0;
        foreach ($payments as $p) {
            if (!self::isActiveHireContractPayment($p, $hireContractId)) {
                continue;
            }
            $paidAmount += (float) ($p['amount'] ?? 0);
            ++$paidInstallments;
        }
        $contractAmount = (float) ($hire['contract_amount'] ?? 0);
        Db::mergeRow('hire_contracts', (string) $hireContractId, [
            'paid_amount' => round($paidAmount, 2),
            'paid_installments' => $paidInstallments,
            'remaining_amount' => round($contractAmount - $paidAmount, 2),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** งวดจ่ายสัญญาที่ยังมี PO อยู่ในระบบ (กัน orphan หลังลบ PR/PO) */
    public static function isActiveHireContractPayment(array $row, ?int $hireContractId = null, ?int $prId = null): bool
    {
        if ($hireContractId !== null && $hireContractId > 0 && (int) ($row['hire_contract_id'] ?? 0) !== $hireContractId) {
            return false;
        }
        if ($prId !== null && $prId > 0 && (int) ($row['pr_id'] ?? 0) !== $prId) {
            return false;
        }
        $poId = (int) ($row['po_id'] ?? 0);
        if ($poId <= 0) {
            return false;
        }
        $po = Db::row('purchase_orders', (string) $poId);
        if ($po === null) {
            return false;
        }
        if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
            return false;
        }
        if ($prId !== null && $prId > 0 && (int) ($po['pr_id'] ?? 0) !== $prId) {
            return false;
        }
        if ($hireContractId !== null && $hireContractId > 0 && (int) ($po['hire_contract_id'] ?? 0) !== $hireContractId) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function filterActiveHireContractPayments(array $rows, ?int $hireContractId = null, ?int $prId = null): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($hireContractId, $prId): bool {
            return self::isActiveHireContractPayment($row, $hireContractId, $prId);
        }));
    }

    /** ลบ hire_contract_payments ที่ผูกกับ PO */
    public static function purgeHireContractPaymentsForPo(int $poId): array
    {
        if ($poId <= 0) {
            return [];
        }
        $deleted = [];
        foreach (Db::tableKeyed('hire_contract_payments') as $key => $row) {
            if (!is_array($row) || (int) ($row['po_id'] ?? 0) !== $poId) {
                continue;
            }
            $rowId = (string) (($row['id'] ?? 0) ?: $key);
            $deleted[] = $row;
            Db::deleteRow('hire_contract_payments', $rowId);
        }

        return $deleted;
    }

    /**
     * PO ทั้งหมดที่ผูกกับ PR (จาก pr_id และสัญญาจ้างของ PR)
     *
     * @return list<array<string, mixed>>
     */
    public static function collectPurchaseOrdersForPr(int $prId): array
    {
        if ($prId <= 0) {
            return [];
        }
        $hcIds = [];
        foreach (Db::filter('hire_contracts', static function (array $r) use ($prId): bool {
            return isset($r['pr_id']) && (int) $r['pr_id'] === $prId;
        }) as $hc) {
            $hcId = (int) ($hc['id'] ?? 0);
            if ($hcId > 0) {
                $hcIds[] = $hcId;
            }
        }
        $found = [];
        foreach (Db::tableRows('purchase_orders') as $po) {
            $poId = (int) ($po['id'] ?? 0);
            if ($poId <= 0) {
                continue;
            }
            if ((int) ($po['pr_id'] ?? 0) === $prId) {
                $found[$poId] = $po;
                continue;
            }
            if ($hcIds !== [] && in_array((int) ($po['hire_contract_id'] ?? 0), $hcIds, true)) {
                $found[$poId] = $po;
            }
        }

        return array_values($found);
    }

    /** ลบ hire_contract_payments ที่ผูกกับ PR (ตอนลบ PR) */
    public static function purgeHireContractPaymentsForPr(int $prId): array
    {
        if ($prId <= 0) {
            return [];
        }
        $hcIds = [];
        foreach (Db::filter('hire_contracts', static function (array $r) use ($prId): bool {
            return isset($r['pr_id']) && (int) $r['pr_id'] === $prId;
        }) as $hc) {
            $hcId = (int) ($hc['id'] ?? 0);
            if ($hcId > 0) {
                $hcIds[] = $hcId;
            }
        }
        $poIds = [];
        foreach (self::collectPurchaseOrdersForPr($prId) as $po) {
            $poId = (int) ($po['id'] ?? 0);
            if ($poId > 0) {
                $poIds[] = $poId;
            }
        }
        $deleted = [];
        foreach (Db::tableKeyed('hire_contract_payments') as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $match = (int) ($row['pr_id'] ?? 0) === $prId
                || in_array((int) ($row['hire_contract_id'] ?? 0), $hcIds, true)
                || in_array((int) ($row['po_id'] ?? 0), $poIds, true);
            if (!$match) {
                continue;
            }
            $rowId = (string) (($row['id'] ?? 0) ?: $key);
            $deleted[] = $row;
            Db::deleteRow('hire_contract_payments', $rowId);
        }

        return $deleted;
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
            if (!self::isActiveHireContractPayment($p, $hireContractId)) {
                continue;
            }
            $paidAmount += (float) ($p['amount'] ?? 0);
            ++$paidInstallments;
        }
        $contractAmount = (float) ($hire['contract_amount'] ?? 0);
        $remaining = round($contractAmount - $paidAmount, 2);

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
