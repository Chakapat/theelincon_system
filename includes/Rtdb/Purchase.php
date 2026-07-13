<?php

declare(strict_types=1);

namespace Theelincon\Rtdb;

final class Purchase
{
    private static function docSlotRegistryEnsure(): void
    {
        static $loaded = false;
        if (!$loaded) {
            require_once dirname(__DIR__) . '/purchase/doc_slot_registry.php';
            $loaded = true;
        }
    }

    private static function tncAuditEnsure(): void
    {
        static $loaded = false;
        if (!$loaded) {
            require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
            $loaded = true;
        }
    }

    /** @deprecated Prefer poNumberFromPr() or generateDirectPONumber() */
    public static function generatePONumber(): string
    {
        self::docSlotRegistryEnsure();

        return tnc_purchase_next_direct_po_number();
    }

    public static function generateDirectPONumber(?string $ym = null): string
    {
        self::docSlotRegistryEnsure();

        return tnc_purchase_next_direct_po_number($ym);
    }

    public static function nextPRNumber(?string $ym = null): string
    {
        self::docSlotRegistryEnsure();

        return tnc_purchase_next_pr_number($ym);
    }

    /**
     * PO จาก PR — ใช้เลขท้ายเดียวกับ PR (PR-TNC-…-011 → PO-TNC-…-011)
     *
     * @param array<string, mixed> $prRow
     */
    public static function poNumberFromPr(array $prRow): string
    {
        self::docSlotRegistryEnsure();

        return tnc_purchase_po_number_from_pr($prRow);
    }

    /**
     * PO จาก PR — ใบแรกใช้เลขคู่ PR, ใบถัดไปใช้ suffix -2, -3, …
     *
     * @param array<string, mixed> $prRow
     */
    public static function poNumberFromPrSplit(array $prRow, int $prId): string
    {
        self::docSlotRegistryEnsure();
        if (!function_exists('tnc_purchase_po_split_next_number')) {
            require_once dirname(__DIR__) . '/pr_po_split.php';
        }

        return tnc_purchase_po_split_next_number($prRow, $prId);
    }

    public static function poNumberTaken(string $poNumber, int $ignorePoId = 0): bool
    {
        self::docSlotRegistryEnsure();

        return tnc_purchase_po_number_taken($poNumber, $ignorePoId);
    }

    /** CEO / ADMIN — แก้ไข ยกเลิก ลบ PO ที่จ่ายแล้ว (สมบูรณ์) ได้ */
    public static function adminCanModifyPaidPo(): bool
    {
        return function_exists('user_is_admin_role') && user_is_admin_role();
    }

    /**
     * PO จ่ายแล้วและผู้ใช้ไม่ใช่ admin → ห้ามแก้ไข/ยกเลิก/ลบ
     *
     * @param array<string, mixed> $po
     */
    public static function poPaidLocksMutation(array $po): bool
    {
        if (strtolower(trim((string) ($po['payment_status'] ?? 'unpaid'))) !== 'paid') {
            return false;
        }

        return !self::adminCanModifyPaidPo();
    }

    /**
     * PO ทั้งหมดที่ผูกกับ PR
     *
     * @return list<array<string, mixed>>
     */
    public static function collectPurchaseOrdersForPr(int $prId): array
    {
        if ($prId <= 0) {
            return [];
        }
        $found = [];
        foreach (Db::tableRows('purchase_orders') as $po) {
            $poId = (int) ($po['id'] ?? 0);
            if ($poId <= 0) {
                continue;
            }
            if ((int) ($po['pr_id'] ?? 0) === $prId) {
                $found[$poId] = $po;
            }
        }

        return array_values($found);
    }

    public static function seedPoPayments(int $poId, float $totalAmount, ?int $ignoredHireContractId = null): void
    {
        unset($ignoredHireContractId);
        if ($poId <= 0) {
            return;
        }

        $po = Db::row('purchase_orders', (string) $poId);
        if ($po === null) {
            return;
        }

        $amount = round($totalAmount, 2);
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
            'seq' => 1,
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
    }
}
