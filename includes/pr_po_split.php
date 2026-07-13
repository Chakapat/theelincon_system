<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

require_once __DIR__ . '/pr_po_sync.php';

if (!function_exists('tnc_pr_po_line_key')) {
    function tnc_pr_po_line_key(string $description, string $unit = ''): string
    {
        return mb_strtolower(trim($description), 'UTF-8') . "\0" . mb_strtolower(trim($unit), 'UTF-8');
    }
}

if (!function_exists('tnc_pr_po_is_active')) {
    function tnc_pr_po_is_active(array $po): bool
    {
        return strtolower(trim((string) ($po['status'] ?? 'ordered'))) !== 'cancelled';
    }
}

if (!function_exists('tnc_pr_collect_active_purchase_orders')) {
    /**
     * @return list<array<string, mixed>>
     */
    function tnc_pr_collect_active_purchase_orders(int $prId): array
    {
        $out = [];
        foreach (Purchase::collectPurchaseOrdersForPr($prId) as $po) {
            if (!tnc_pr_po_is_active($po)) {
                continue;
            }
            $out[] = $po;
        }
        usort($out, static function (array $a, array $b): int {
            $ida = (int) ($a['id'] ?? 0);
            $idb = (int) ($b['id'] ?? 0);

            return $ida <=> $idb;
        });

        return $out;
    }
}

if (!function_exists('tnc_pr_po_pr_qty_map')) {
    /**
     * @return array<string, float>
     */
    function tnc_pr_po_pr_qty_map(int $prId): array
    {
        $map = [];
        foreach (tnc_pr_load_purchase_line_items($prId) as $item) {
            $key = tnc_pr_po_line_key((string) ($item['description'] ?? ''), (string) ($item['unit'] ?? ''));
            $map[$key] = ($map[$key] ?? 0.0) + (float) ($item['quantity'] ?? 0);
        }

        return $map;
    }
}

if (!function_exists('tnc_pr_po_allocated_qty_map')) {
    /**
     * @return array<string, float>
     */
    function tnc_pr_po_allocated_qty_map(int $prId): array
    {
        $map = [];
        foreach (tnc_pr_collect_active_purchase_orders($prId) as $po) {
            $poId = (int) ($po['id'] ?? 0);
            if ($poId <= 0) {
                continue;
            }
            foreach (Db::filter('purchase_order_items', static function (array $r) use ($poId): bool {
                $pid = (int) ($r['po_id'] ?? 0);
                $poidAlt = (int) ($r['purchase_order_id'] ?? 0);

                return $pid === $poId || $poidAlt === $poId;
            }) as $item) {
                $qty = (float) ($item['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $key = tnc_pr_po_line_key((string) ($item['description'] ?? ''), (string) ($item['unit'] ?? ''));
                $map[$key] = ($map[$key] ?? 0.0) + $qty;
            }
        }

        return $map;
    }
}

if (!function_exists('tnc_pr_po_scale_line_item')) {
    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    function tnc_pr_po_scale_line_item(array $item, float $newQty, float $originalQty): array
    {
        if ($originalQty <= 0.0001 || abs($newQty - $originalQty) < 0.0001) {
            $item['quantity'] = $newQty;

            return $item;
        }
        $ratio = $newQty / $originalQty;
        $item['quantity'] = $newQty;
        $item['total'] = round((float) ($item['total'] ?? 0) * $ratio, 2);
        $discAmt = (float) ($item['discount_amount'] ?? 0);
        if ($discAmt > 0) {
            $item['discount_amount'] = round($discAmt * $ratio, 2);
        }

        return $item;
    }
}

if (!function_exists('tnc_pr_remaining_items_for_po')) {
    /**
     * รายการ PR ที่ยังออก PO ได้ (หักยอดที่ออกไปแล้ว)
     *
     * @return list<array<string, mixed>>
     */
    function tnc_pr_remaining_items_for_po(int $prId): array
    {
        if ($prId <= 0) {
            return [];
        }
        $allocated = tnc_pr_po_allocated_qty_map($prId);
        $remaining = [];
        foreach (tnc_pr_load_purchase_line_items($prId) as $item) {
            $key = tnc_pr_po_line_key((string) ($item['description'] ?? ''), (string) ($item['unit'] ?? ''));
            $prQty = (float) ($item['quantity'] ?? 0);
            if ($prQty <= 0) {
                continue;
            }
            $used = (float) ($allocated[$key] ?? 0);
            $left = round($prQty - $used, 4);
            if ($left <= 0.0001) {
                continue;
            }
            $scaled = tnc_pr_po_scale_line_item($item, $left, $prQty);
            $scaled['_pr_qty_original'] = $prQty;
            $scaled['_pr_qty_allocated'] = $used;
            $scaled['_pr_qty_remaining'] = $left;
            $remaining[] = $scaled;
        }

        return $remaining;
    }
}

if (!function_exists('tnc_pr_has_remaining_for_po')) {
    function tnc_pr_has_remaining_for_po(int $prId): bool
    {
        return tnc_pr_remaining_items_for_po($prId) !== [];
    }
}

if (!function_exists('tnc_pr_validate_new_po_items')) {
    /**
     * @param list<array{description:string, quantity:float, unit:string}> $newItems
     */
    function tnc_pr_validate_new_po_items(int $prId, array $newItems): ?string
    {
        if ($prId <= 0) {
            return 'invalid_pr';
        }
        $prQtyMap = tnc_pr_po_pr_qty_map($prId);
        if ($prQtyMap === []) {
            return 'no_items';
        }
        $allocated = tnc_pr_po_allocated_qty_map($prId);
        $newByKey = [];
        foreach ($newItems as $item) {
            $qty = (float) ($item['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $desc = trim((string) ($item['description'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $key = tnc_pr_po_line_key($desc, (string) ($item['unit'] ?? ''));
            if (!isset($prQtyMap[$key])) {
                continue;
            }
            $newByKey[$key] = ($newByKey[$key] ?? 0.0) + $qty;
        }
        foreach ($newByKey as $key => $addQty) {
            $prQty = (float) ($prQtyMap[$key] ?? 0);
            $used = (float) ($allocated[$key] ?? 0) + $addQty;
            if ($used > $prQty + 0.01) {
                return 'qty_exceeds_pr';
            }
        }

        return null;
    }
}

if (!function_exists('tnc_purchase_po_split_next_number')) {
    function tnc_purchase_po_split_next_number(array $prRow, int $prId): string
    {
        if (!function_exists('tnc_purchase_po_number_from_pr')) {
            require_once __DIR__ . '/purchase/doc_slot_registry.php';
        }
        $base = tnc_purchase_po_number_from_pr($prRow);
        if (!tnc_purchase_po_number_taken($base)) {
            return $base;
        }
        for ($suffix = 2; $suffix <= 99; $suffix++) {
            $candidate = $base . '-' . $suffix;
            if (!tnc_purchase_po_number_taken($candidate)) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException('po_split_number_exhausted');
    }
}

if (!function_exists('tnc_pr_po_split_sequence_label')) {
    function tnc_pr_po_split_sequence_label(int $activeCount): string
    {
        if ($activeCount <= 0) {
            return 'ใบแรกจาก PR';
        }

        return 'ใบที่ ' . ($activeCount + 1) . ' จาก PR เดิม';
    }
}
