<?php

declare(strict_types=1);

/**
 * Cascade delete helpers for PR / PO (shared by action-handler and site delete).
 */

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

if (!function_exists('tnc_delete_linked_bills_by_po')) {
    /**
     * @return array{purchase_bills: list<array<string,mixed>>, bills: list<array<string,mixed>>}
     */
    function tnc_delete_linked_bills_by_po(int $poId): array
    {
        $deletedPurchaseBills = [];
        $deletedBills = [];
        if ($poId <= 0) {
            return ['purchase_bills' => $deletedPurchaseBills, 'bills' => $deletedBills];
        }

        foreach (Db::tableKeyed('purchase_bills') as $pbPk => $pbRow) {
            if (!is_array($pbRow) || (int) ($pbRow['source_po_id'] ?? 0) !== $poId) {
                continue;
            }
            $pbId = (int) ($pbRow['id'] ?? 0);
            if ($pbId > 0) {
                Db::deleteWhereEquals('purchase_bill_items', 'bill_id', (string) $pbId);
                Db::deleteWhereEquals('purchase_bill_items', 'purchase_bill_id', (string) $pbId);
                Db::deleteWhereEquals('purchase_bill_items', 'purchase_bills_id', (string) $pbId);
            }
            $deletedPurchaseBills[] = $pbRow;
            Db::deleteRow('purchase_bills', (string) $pbPk);
        }

        foreach (Db::tableKeyed('bills') as $bPk => $bRow) {
            if (!is_array($bRow) || (int) ($bRow['po_id'] ?? 0) !== $poId) {
                continue;
            }
            $deletedBills[] = $bRow;
            Db::deleteRow('bills', (string) $bPk);
        }

        return ['purchase_bills' => $deletedPurchaseBills, 'bills' => $deletedBills];
    }
}

if (!function_exists('tnc_po_delete_line_items')) {
    function tnc_po_delete_line_items(int $poId): void
    {
        if ($poId <= 0) {
            return;
        }
        foreach (Db::tableKeyed('purchase_order_items') as $itemPk => $itemRow) {
            if (!is_array($itemRow)) {
                continue;
            }
            $pid = (int) ($itemRow['po_id'] ?? 0);
            $poidAlt = (int) ($itemRow['purchase_order_id'] ?? 0);
            if ($pid === $poId || $poidAlt === $poId) {
                Db::deleteRow('purchase_order_items', (string) $itemPk);
            }
        }
    }
}

if (!function_exists('tnc_delete_purchase_order_cascade')) {
    /**
     * @return list<array{verb: string, entity_type: string, entity_id: string, snapshot: array<string, mixed>}>
     */
    function tnc_delete_purchase_order_cascade(int $poId): array
    {
        $nested = [];
        if ($poId <= 0) {
            return $nested;
        }
        $poDel = Db::row('purchase_orders', (string) $poId);
        if ($poDel === null) {
            return $nested;
        }

        $linkedBillDeleted = tnc_delete_linked_bills_by_po($poId);
        foreach ($linkedBillDeleted['purchase_bills'] as $pbDel) {
            $nested[] = ['verb' => 'delete', 'entity_type' => 'purchase_bill', 'entity_id' => (string) ((int) ($pbDel['id'] ?? 0)), 'snapshot' => $pbDel];
        }
        foreach ($linkedBillDeleted['bills'] as $bDel) {
            $nested[] = ['verb' => 'delete', 'entity_type' => 'bill', 'entity_id' => (string) ((int) ($bDel['id'] ?? 0)), 'snapshot' => $bDel];
        }
        Db::deleteWhereEquals('po_payments', 'po_id', (string) $poId);
        tnc_po_delete_line_items($poId);
        Db::deleteRow('purchase_orders', (string) $poId);
        $nested[] = ['verb' => 'delete', 'entity_type' => 'purchase_order', 'entity_id' => (string) $poId, 'snapshot' => $poDel];

        return $nested;
    }
}

if (!function_exists('tnc_delete_pr_cascade')) {
    /**
     * @return list<array{verb: string, entity_type: string, entity_id: string, snapshot: array<string, mixed>}>
     */
    function tnc_delete_pr_cascade(int $prId): array
    {
        $nested = [];
        if ($prId <= 0) {
            return $nested;
        }

        foreach (Purchase::collectPurchaseOrdersForPr($prId) as $poDel) {
            $poId = (int) ($poDel['id'] ?? 0);
            if ($poId > 0) {
                $nested = array_merge($nested, tnc_delete_purchase_order_cascade($poId));
            }
        }

        foreach (Db::filter('purchase_request_items', static fn (array $r): bool => isset($r['pr_id']) && (int) $r['pr_id'] === $prId) as $pri) {
            $priId = (int) ($pri['id'] ?? 0);
            if ($priId > 0) {
                $nested[] = ['verb' => 'delete', 'entity_type' => 'purchase_request_item', 'entity_id' => (string) $priId, 'snapshot' => $pri];
            }
        }
        Db::deleteWhereEquals('purchase_request_items', 'pr_id', (string) $prId);

        foreach (Db::tableKeyed('web_notifications') as $notifKey => $notifRow) {
            if (!is_array($notifRow)) {
                continue;
            }
            if ((string) ($notifRow['entity_type'] ?? '') !== 'purchase_request') {
                continue;
            }
            if ((int) ($notifRow['entity_id'] ?? 0) !== $prId) {
                continue;
            }
            $notifId = (string) (($notifRow['id'] ?? 0) ?: $notifKey);
            $nested[] = ['verb' => 'delete', 'entity_type' => 'web_notification', 'entity_id' => $notifId, 'snapshot' => $notifRow];
            Db::deleteRow('web_notifications', $notifId);
        }

        return $nested;
    }
}
