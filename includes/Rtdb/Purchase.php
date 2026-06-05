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

    /** WO-TNC-001 — ใบสั่งงาน (สัญญาจ้าง) */
    public static function generateWorkOrderNumber(): string
    {
        $prefix = 'WO-TNC-';
        $max = 0;
        foreach (Db::tableRows('purchase_orders') as $r) {
            $pn = (string) ($r['po_number'] ?? '');
            if (strncmp($pn, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $tail = substr($pn, strlen($prefix));
            if (preg_match('/^\d+$/', $tail) === 1) {
                $max = max($max, (int) $tail);
            }
        }

        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /** เอกสารสัญญาจ้าง (WO) — ไม่ใช่ PO สั่งจ่าย */
    public static function isWorkOrder(array $po): bool
    {
        return self::isHireContractPo($po);
    }

    /** ป้ายประเภทเอกสารบนหน้าจอ */
    public static function hireDocumentKindLabel(array $po): string
    {
        return self::isWorkOrder($po) ? 'WO' : 'PO';
    }

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

    /** @deprecated ใช้ WO-TNC-xxx เป็นเลขที่สัญญาจ้าง — คงไว้สำหรับข้อมูลเก่าเท่านั้น */
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

    /** เลขที่สัญญาจ้างสำหรับแสดงผล — WO-TNC-xxx (ข้อมูลเก่า HC-TNC จะ resolve จาก contract PO) */
    public static function hireContractDocumentNumber(array $hc): string
    {
        $contractPoId = (int) ($hc['contract_po_id'] ?? 0);
        if ($contractPoId > 0) {
            $po = Db::row('purchase_orders', (string) $contractPoId);
            if (is_array($po)) {
                $woNo = trim((string) ($po['po_number'] ?? ''));
                if ($woNo !== '') {
                    return $woNo;
                }
            }
        }

        $stored = trim((string) ($hc['pr_number'] ?? ''));
        if ($stored !== '' && preg_match('/^WO-TNC-/i', $stored)) {
            return $stored;
        }

        $hcId = (int) ($hc['id'] ?? 0);
        if ($hcId > 0) {
            $contractPo = self::hireContractPoFor((int) ($hc['pr_id'] ?? 0), $hcId);
            if ($contractPo !== null) {
                $woNo = trim((string) ($contractPo['po_number'] ?? ''));
                if ($woNo !== '') {
                    return $woNo;
                }
            }
        }

        return $stored;
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

    /** ประเภท PO จัดจ้าง: contract = WO, payment = สั่งจ่ายงวด/ครั้ง, advance = เบิกล่วงหน้า */
    public static function hirePoKind(array $po): string
    {
        if (trim((string) ($po['order_type'] ?? 'purchase')) !== 'hire') {
            return '';
        }
        $kind = trim((string) ($po['hire_po_kind'] ?? ''));
        if (in_array($kind, ['contract', 'payment', 'advance'], true)) {
            return $kind;
        }
        $pn = trim((string) ($po['po_number'] ?? ''));
        if ($pn !== '' && preg_match('/^WO-TNC-/i', $pn)) {
            return 'contract';
        }

        return 'payment';
    }

    public static function isHireContractPo(array $po): bool
    {
        return self::hirePoKind($po) === 'contract';
    }

    public static function isHireAdvancePo(array $po): bool
    {
        return trim((string) ($po['order_type'] ?? 'purchase')) === 'hire' && self::hirePoKind($po) === 'advance';
    }

    public static function isHirePaymentPo(array $po): bool
    {
        return trim((string) ($po['order_type'] ?? 'purchase')) === 'hire' && self::hirePoKind($po) === 'payment';
    }

    /** PO สั่งจ่าย/เบิกล่วงหน้า (ไม่รวม WO สัญญา) */
    public static function isHirePayablePo(array $po): bool
    {
        $kind = self::hirePoKind($po);

        return in_array($kind, ['payment', 'advance'], true);
    }

    /** PO สัญญาจ้างที่ยังใช้งาน (ไม่ถูกยกเลิก) */
    public static function hireContractPoFor(int $prId = 0, int $hireContractId = 0): ?array
    {
        foreach (Db::tableRows('purchase_orders') as $po) {
            if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
                continue;
            }
            if (!self::isHireContractPo($po)) {
                continue;
            }
            if ($prId > 0 && (int) ($po['pr_id'] ?? 0) !== $prId) {
                continue;
            }
            if ($hireContractId > 0 && (int) ($po['hire_contract_id'] ?? 0) !== $hireContractId) {
                continue;
            }

            return $po;
        }

        return null;
    }

    public static function hasHireContractPo(int $prId = 0, int $hireContractId = 0): bool
    {
        return self::hireContractPoFor($prId, $hireContractId) !== null;
    }

    /** รายการ Work Order (WO) — สัญญาจ้างใช้ WO เป็นหน้าหลัก */
    public static function workOrderListUrl(): string
    {
        return app_path('pages/purchase/work-order-list.php');
    }

    /** หน้าดู WO จาก hire_contract_id หรือ pr_id (legacy) */
    public static function workOrderViewUrl(int $hireContractId = 0, int $prId = 0): ?string
    {
        $po = self::hireContractPoFor($prId, $hireContractId);
        if ($po === null) {
            return null;
        }
        $woId = (int) ($po['id'] ?? 0);

        return $woId > 0
            ? app_path('pages/purchase/purchase-order-view.php') . '?id=' . $woId
            : null;
    }

    /** 0 = ไม่กำหนดจำนวนงวด — นับการสั่งจ่ายเป็น "ครั้ง" */
    public static function hireInstallmentsUnspecified(int $installmentTotal): bool
    {
        return $installmentTotal === 0;
    }

    public static function parseHireInstallmentTotalPost(mixed $raw): int
    {
        return min(120, max(0, (int) $raw));
    }

    public static function hirePoPayableAmount(array $po): float
    {
        $payable = (float) ($po['payable_amount'] ?? 0);
        if ($payable <= 0) {
            $payable = (float) ($po['total_amount'] ?? 0);
        }

        return round($payable, 2);
    }

    /** @return list<array<string, mixed>> PO สั่งจ่ายงวด/ครั้ง (ไม่รวมเบิกล่วงหน้า) */
    public static function activeHirePaymentPos(int $hireContractId = 0, int $prId = 0): array
    {
        $rows = [];
        foreach (Db::tableRows('purchase_orders') as $po) {
            if (trim((string) ($po['order_type'] ?? 'purchase')) !== 'hire') {
                continue;
            }
            if (self::isHireContractPo($po)) {
                continue;
            }
            if (!self::isHirePaymentPo($po)) {
                continue;
            }
            if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
                continue;
            }
            if ($hireContractId > 0 && (int) ($po['hire_contract_id'] ?? 0) !== $hireContractId) {
                continue;
            }
            if ($prId > 0 && (int) ($po['pr_id'] ?? 0) !== $prId) {
                continue;
            }

            $rows[] = $po;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> PO เบิกล่วงหน้า (ไม่รวมสั่งจ่ายงวด/ครั้ง) */
    public static function activeHireAdvancePos(int $hireContractId = 0, int $prId = 0): array
    {
        $rows = [];
        foreach (Db::tableRows('purchase_orders') as $po) {
            if (trim((string) ($po['order_type'] ?? 'purchase')) !== 'hire') {
                continue;
            }
            if (self::isHireContractPo($po)) {
                continue;
            }
            if (!self::isHireAdvancePo($po)) {
                continue;
            }
            if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
                continue;
            }
            if ($hireContractId > 0 && (int) ($po['hire_contract_id'] ?? 0) !== $hireContractId) {
                continue;
            }
            if ($prId > 0 && (int) ($po['pr_id'] ?? 0) !== $prId) {
                continue;
            }

            $rows[] = $po;
        }

        return $rows;
    }

    public static function hireNextPaymentNo(int $hireContractId = 0, int $prId = 0): int
    {
        $maxNo = 0;
        foreach (self::activeHirePaymentPos($hireContractId, $prId) as $po) {
            $no = (int) ($po['installment_no'] ?? 0);
            if ($no > $maxNo) {
                $maxNo = $no;
            }
        }

        return $maxNo + 1;
    }

    public static function formatHirePaymentSequenceLabel(int $no, int $installmentTotal): string
    {
        if ($no <= 0) {
            return '';
        }
        if (self::hireInstallmentsUnspecified($installmentTotal)) {
            return 'ครั้งที่ ' . number_format($no);
        }

        return 'งวดที่ ' . number_format($no) . ' / ' . number_format($installmentTotal);
    }

    /** ลำดับ PO เบิกล่วงหน้าของสัญญา (เรียงตาม id) */
    public static function hireAdvanceSequenceNo(array $po): int
    {
        if (!self::isHireAdvancePo($po)) {
            return 0;
        }
        $hcId = (int) ($po['hire_contract_id'] ?? 0);
        $poId = (int) ($po['id'] ?? 0);
        if ($hcId <= 0 || $poId <= 0) {
            return 1;
        }
        $seq = 0;
        $rows = [];
        foreach (Db::tableRows('purchase_orders') as $row) {
            if ((int) ($row['hire_contract_id'] ?? 0) !== $hcId) {
                continue;
            }
            if (!self::isHireAdvancePo($row)) {
                continue;
            }
            if (strtolower(trim((string) ($row['status'] ?? ''))) === 'cancelled') {
                continue;
            }
            $rows[] = $row;
        }
        usort($rows, static function (array $a, array $b): int {
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });
        foreach ($rows as $row) {
            ++$seq;
            if ((int) ($row['id'] ?? 0) === $poId) {
                return $seq;
            }
        }

        return max(1, $seq);
    }

    public static function formatHireAdvanceLabel(array $po): string
    {
        $n = self::hireAdvanceSequenceNo($po);

        return $n > 1 ? ('เบิกล่วงหน้า ครั้งที่ ' . number_format($n)) : 'เบิกล่วงหน้า';
    }

    public static function hirePayablePoSequenceLabel(array $po, int $installmentTotal = 0): string
    {
        if (self::isHireAdvancePo($po)) {
            return self::formatHireAdvanceLabel($po);
        }

        return self::formatHirePaymentSequenceLabel((int) ($po['installment_no'] ?? 0), $installmentTotal);
    }

    /** @param array<string, mixed> $paymentRow แถว hire_contract_payments */
    public static function hireContractPaymentLabel(array $paymentRow, int $installmentTotal = 0): string
    {
        $poId = (int) ($paymentRow['po_id'] ?? 0);
        if ($poId > 0) {
            $po = Db::row('purchase_orders', (string) $poId);
            if (is_array($po)) {
                return self::hirePayablePoSequenceLabel($po, $installmentTotal);
            }
        }

        return self::formatHirePaymentSequenceLabel((int) ($paymentRow['installment_no'] ?? 0), $installmentTotal);
    }

    /** ข้อความหมายเหตุจาก PO หรือสัญญาจ้าง */
    public static function hireWorkConditionsText(array $po, ?array $hireContract = null): string
    {
        $note = trim((string) ($po['po_note'] ?? ''));
        if ($note !== '') {
            return $note;
        }
        if ($hireContract !== null) {
            return trim((string) ($hireContract['title'] ?? ''));
        }
        $hcId = (int) ($po['hire_contract_id'] ?? 0);
        if ($hcId > 0) {
            $hc = Db::row('hire_contracts', (string) $hcId);
            if (is_array($hc)) {
                return trim((string) ($hc['title'] ?? ''));
            }
        }

        return '';
    }

    /** ข้อมูล PO สัญญาจ้างสำหรับผูกกับ PO สั่งจ่ายงวด */
    public static function referenceContractPoPayload(int $hireContractId, int $prId = 0): array
    {
        $contractPo = self::hireContractPoFor($prId, $hireContractId);
        if ($contractPo === null) {
            return [];
        }
        $poId = (int) ($contractPo['id'] ?? 0);
        $poNo = trim((string) ($contractPo['po_number'] ?? ''));
        if ($poId <= 0) {
            return [];
        }

        $payload = [
            'reference_contract_po_id' => $poId,
            'reference_contract_po_number' => $poNo,
        ];

        $siteId = (int) ($contractPo['site_id'] ?? 0);
        if ($siteId > 0) {
            $siteName = trim((string) ($contractPo['site_name'] ?? ''));
            if ($siteName === '') {
                $siteRow = Db::row('sites', (string) $siteId);
                if (is_array($siteRow)) {
                    $siteName = trim((string) ($siteRow['name'] ?? ''));
                }
            }
            $payload['site_id'] = $siteId;
            if ($siteName !== '') {
                $payload['site_name'] = $siteName;
            }
        }

        $catId = (int) ($contractPo['cost_category_id'] ?? 0);
        if ($catId > 0) {
            $catName = trim((string) ($contractPo['cost_category_name'] ?? ''));
            if ($catName === '') {
                $catHelper = dirname(__DIR__, 2) . '/includes/site_cost_categories.php';
                if (is_file($catHelper)) {
                    require_once $catHelper;
                    if (function_exists('tnc_site_category_name')) {
                        $catName = tnc_site_category_name($catId);
                    }
                }
            }
            $payload['cost_category_id'] = $catId;
            if ($catName !== '') {
                $payload['cost_category_name'] = $catName;
            }
        }

        return $payload;
    }

    /**
     * ยอด payable จาก PO สั่งจ่ายงวด/ครั้ง (ไม่รวมเบิกล่วงหน้า และ PO ที่ยกเลิก)
     */
    public static function hireContractCommittedPayable(int $hireContractId): float
    {
        if ($hireContractId <= 0) {
            return 0.0;
        }
        $sum = 0.0;
        foreach (self::activeHirePaymentPos($hireContractId) as $po) {
            $sum += self::hirePoPayableAmount($po);
        }

        return round($sum, 2);
    }

    /** ยอด payable จาก PO เบิกล่วงหน้า (แยกจากมูลค่าสัญญา — ไม่หักคงเหลือ) */
    public static function hireContractCommittedAdvance(int $hireContractId): float
    {
        if ($hireContractId <= 0) {
            return 0.0;
        }
        $sum = 0.0;
        foreach (self::activeHireAdvancePos($hireContractId) as $po) {
            $sum += self::hirePoPayableAmount($po);
        }

        return round($sum, 2);
    }

    /** ยอดคงเหลือของสัญญา (มูลค่าสัญญา − ยอดสั่งจ่ายงวด/ครั้ง) — ไม่หักเบิกล่วงหน้า */
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
        if (self::isHireAdvancePo($po)) {
            return ['ok' => true, 'remaining' => self::hireContractRemainingPayable($hc, $hireContractId), 'room' => 0.0, 'message' => ''];
        }
        $oldPayable = self::hirePoPayableAmount($po);
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
        self::purgeStaleHireContractPayments($hireContractId);
        $hire = Db::row('hire_contracts', (string) $hireContractId);
        if ($hire === null) {
            return;
        }
        $prId = (int) ($hire['pr_id'] ?? 0);
        $paidAmount = self::hireContractCommittedPayable($hireContractId);
        $paidInstallments = count(self::activeHirePaymentPos($hireContractId, $prId));
        $contractAmount = (float) ($hire['contract_amount'] ?? 0);
        Db::mergeRow('hire_contracts', (string) $hireContractId, [
            'paid_amount' => $paidAmount,
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
        if (self::isHireContractPo($po)) {
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

    /** งวดสัญญาที่ผูก PO สั่งจ่าย (ไม่รวมเบิกล่วงหน้า) */
    public static function isActiveHireContractPaymentPo(array $row, ?int $hireContractId = null, ?int $prId = null): bool
    {
        if (!self::isActiveHireContractPayment($row, $hireContractId, $prId)) {
            return false;
        }
        $poId = (int) ($row['po_id'] ?? 0);
        if ($poId <= 0) {
            return false;
        }
        $po = Db::row('purchase_orders', (string) $poId);

        return is_array($po) && self::isHirePaymentPo($po);
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

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function filterActiveHireContractPaymentPos(array $rows, ?int $hireContractId = null, ?int $prId = null): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($hireContractId, $prId): bool {
            return self::isActiveHireContractPaymentPo($row, $hireContractId, $prId);
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

    /**
     * ลบแถว hire_contract_payments ที่ค้าง (PO ยกเลิก/ไม่มีแล้ว หรือ PO เบิกล่วงหน้า legacy)
     */
    public static function purgeStaleHireContractPayments(int $hireContractId = 0): int
    {
        $removed = 0;
        foreach (Db::tableKeyed('hire_contract_payments') as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $hid = (int) ($row['hire_contract_id'] ?? 0);
            if ($hireContractId > 0 && $hid > 0 && $hid !== $hireContractId) {
                continue;
            }
            if (self::isActiveHireContractPaymentPo($row, $hireContractId > 0 ? $hireContractId : null, null)) {
                continue;
            }
            $poId = (int) ($row['po_id'] ?? 0);
            $shouldPurge = ($poId <= 0);
            if (!$shouldPurge) {
                $po = Db::row('purchase_orders', (string) $poId);
                if ($po === null) {
                    $shouldPurge = true;
                } elseif (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
                    $shouldPurge = true;
                } elseif (self::isHireAdvancePo($po)) {
                    $shouldPurge = true;
                }
            }
            if (!$shouldPurge) {
                continue;
            }
            $rowId = (string) (($row['id'] ?? 0) ?: $key);
            Db::deleteRow('hire_contract_payments', $rowId);
            ++$removed;
        }

        return $removed;
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
        if (self::isHireContractPo($po)) {
            $hireContractId = null;
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

        if (self::isHireAdvancePo($po)) {
            return;
        }

        $existingHcp = Db::findFirst('hire_contract_payments', static function (array $r) use ($poId): bool {
            return isset($r['po_id']) && (int) $r['po_id'] === $poId;
        });
        if ($existingHcp !== null) {
            self::syncHireContractTotals($hireContractId);

            return;
        }

        $installmentNo = (int) ($po['installment_no'] ?? 0);
        if ($installmentNo <= 0) {
            $installmentNo = self::hireNextPaymentNo($hireContractId, (int) ($hire['pr_id'] ?? 0));
        }
        $installmentTotal = (int) ($hire['installment_total'] ?? 1);
        if ($installmentTotal < 0) {
            $installmentTotal = 0;
        }

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

        $prIdHire = (int) ($hire['pr_id'] ?? 0);
        $paidAmount = self::hireContractCommittedPayable($hireContractId);
        $paidInstallments = count(self::activeHirePaymentPos($hireContractId, $prIdHire));
        $contractAmount = (float) ($hire['contract_amount'] ?? 0);
        $remaining = round($contractAmount - $paidAmount, 2);

        $hireBeforeUp = $hire;
        Db::mergeRow('hire_contracts', (string) $hireContractId, [
            'paid_amount' => $paidAmount,
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
        $hcDoc = is_array($hireAfterUp) ? Purchase::hireContractDocumentNumber($hireAfterUp) : '';
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
