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
        $activePoIds = [];
        foreach (tnc_pr_collect_active_purchase_orders($prId) as $po) {
            $poId = (int) ($po['id'] ?? 0);
            if ($poId > 0) {
                $activePoIds[$poId] = true;
            }
        }
        if ($activePoIds === []) {
            return $map;
        }

        // โหลด purchase_order_items ครั้งเดียว แล้วกรองใน PHP (เลี่ยง N+1 Db::filter)
        foreach (Db::tableRows('purchase_order_items') as $item) {
            if (!is_array($item)) {
                continue;
            }
            $poId = (int) ($item['po_id'] ?? 0);
            if ($poId <= 0) {
                $poId = (int) ($item['purchase_order_id'] ?? 0);
            }
            if ($poId <= 0 || !isset($activePoIds[$poId])) {
                continue;
            }
            $qty = (float) ($item['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $key = tnc_pr_po_line_key((string) ($item['description'] ?? ''), (string) ($item['unit'] ?? ''));
            $map[$key] = ($map[$key] ?? 0.0) + $qty;
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

if (!function_exists('tnc_pr_items_for_po_create')) {
    /**
     * รายการสำหรับฟอร์มสร้าง PO จาก PR
     * ถ้ายังเหลือยอด → คืนรายการที่เหลือ
     * ถ้าครบแล้ว → คืนรายการ PR เดิม (อนุญาตออกเกิน พร้อมธง _pr_over_order)
     *
     * @return list<array<string, mixed>>
     */
    function tnc_pr_items_for_po_create(int $prId): array
    {
        if ($prId <= 0) {
            return [];
        }
        $remaining = tnc_pr_remaining_items_for_po($prId);
        if ($remaining !== []) {
            return $remaining;
        }

        $allocated = tnc_pr_po_allocated_qty_map($prId);
        $out = [];
        foreach (tnc_pr_load_purchase_line_items($prId) as $item) {
            $prQty = (float) ($item['quantity'] ?? 0);
            if ($prQty <= 0) {
                continue;
            }
            $key = tnc_pr_po_line_key((string) ($item['description'] ?? ''), (string) ($item['unit'] ?? ''));
            $used = (float) ($allocated[$key] ?? 0);
            $item['_pr_qty_original'] = $prQty;
            $item['_pr_qty_allocated'] = $used;
            $item['_pr_qty_remaining'] = round($prQty - $used, 4);
            $item['_pr_over_order'] = true;
            $out[] = $item;
        }

        return $out;
    }
}

if (!function_exists('tnc_pr_amount_total')) {
    function tnc_pr_amount_total(int $prId): float
    {
        if ($prId <= 0) {
            return 0.0;
        }
        $pr = Db::rowByIdField('purchase_requests', $prId);
        if ($pr === null) {
            return 0.0;
        }
        $total = (float) ($pr['total_amount'] ?? 0);
        if ($total <= 0.0001) {
            $total = (float) ($pr['gross_amount'] ?? 0);
        }

        return round($total, 2);
    }
}

if (!function_exists('tnc_pr_allocated_po_amount')) {
    function tnc_pr_allocated_po_amount(int $prId): float
    {
        $sum = 0.0;
        foreach (tnc_pr_collect_active_purchase_orders($prId) as $po) {
            $amt = (float) ($po['total_amount'] ?? 0);
            if ($amt <= 0.0001) {
                $amt = (float) ($po['gross_amount'] ?? 0);
            }
            $sum += $amt;
        }

        return round($sum, 2);
    }
}

if (!function_exists('tnc_pr_po_exceed_summary')) {
    /**
     * สรุปยอด PO เทียบกับ PR — สำหรับเตือนเมื่อออกเกินยอด
     *
     * @return array{pr_total:float, allocated:float, projected:float, exceeds:bool, over_by:float, fully_ordered:bool}
     */
    function tnc_pr_po_exceed_summary(int $prId, float $additionalAmount = 0.0): array
    {
        $prTotal = tnc_pr_amount_total($prId);
        $allocated = tnc_pr_allocated_po_amount($prId);
        $projected = round($allocated + max(0.0, $additionalAmount), 2);
        $overBy = $prTotal > 0.01 ? max(0.0, round($projected - $prTotal, 2)) : 0.0;
        $qtyExceeds = false;
        if ($additionalAmount <= 0.0001) {
            $qtyExceeds = !tnc_pr_has_remaining_for_po($prId) && tnc_pr_po_pr_qty_map($prId) !== [];
        }

        return [
            'pr_total' => $prTotal,
            'allocated' => $allocated,
            'projected' => $projected,
            'exceeds' => ($prTotal > 0.01 && $projected > $prTotal + 0.01) || $qtyExceeds,
            'over_by' => $overBy,
            'fully_ordered' => !tnc_pr_has_remaining_for_po($prId) && tnc_pr_po_pr_qty_map($prId) !== [],
        ];
    }
}

if (!function_exists('tnc_pr_new_po_would_exceed')) {
    /**
     * ตรวจว่า PO ใหม่จะทำให้ยอด/จำนวนเกิน PR หรือไม่ (ไม่บล็อก — ใช้แสดงเตือน)
     *
     * @param list<array{description?:string, quantity?:float, unit?:string, total?:float}> $newItems
     */
    function tnc_pr_new_po_would_exceed(int $prId, array $newItems, float $newPoTotal = 0.0): bool
    {
        if ($prId <= 0) {
            return false;
        }
        if ($newPoTotal > 0.0001) {
            $summary = tnc_pr_po_exceed_summary($prId, $newPoTotal);
            if ($summary['exceeds']) {
                return true;
            }
        }

        $prQtyMap = tnc_pr_po_pr_qty_map($prId);
        if ($prQtyMap === []) {
            return false;
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
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('tnc_pr_validate_new_po_items')) {
    /**
     * Soft advisory only — ไม่บล็อกการสร้าง PO เกินยอด PR แล้ว
     * คืน null เสมอ (คงชื่อฟังก์ชันไว้เพื่อความเข้ากันได้ของจุดเรียกเดิม)
     *
     * @param list<array{description:string, quantity:float, unit:string}> $newItems
     */
    function tnc_pr_validate_new_po_items(int $prId, array $newItems): ?string
    {
        unset($prId, $newItems);

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
