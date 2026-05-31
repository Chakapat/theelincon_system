<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

/**
 * รายการจัดจ้าง: ราคา/หน่วย = ค่าวัสดุ + ค่าแรง, ราคารวม = จำนวน × ราคา/หน่วย
 */

if (!function_exists('tnc_hire_line_is_group')) {
    function tnc_hire_line_is_group(array $line): bool
    {
        return trim((string) ($line['line_type'] ?? 'item')) === 'group';
    }
}

if (!function_exists('tnc_hire_lines_apply_display_numbers')) {
    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return list<array<string, mixed>>
     */
    function tnc_hire_lines_apply_display_numbers(array $lines): array
    {
        $major = 0;
        $minor = 0;
        $out = [];
        foreach ($lines as $line) {
            if (tnc_hire_line_is_group($line)) {
                $major++;
                $minor = 0;
                $line['display_no'] = (string) $major;
            } else {
                if ($major > 0) {
                    $minor++;
                    $line['display_no'] = $major . '.' . $minor;
                } else {
                    $major++;
                    $line['display_no'] = (string) $major;
                }
            }
            $out[] = $line;
        }

        return $out;
    }
}

if (!function_exists('tnc_hire_line_calc')) {
    /** @return array{quantity: float, material_price: float, labor_price: float, unit_price: float, total: float} */
    function tnc_hire_line_calc(float $qty, float $material, float $labor): array
    {
        $qty = max(0.0, round($qty, 4));
        $material = max(0.0, round($material, 2));
        $labor = max(0.0, round($labor, 2));
        $unitPrice = round($material + $labor, 2);

        return [
            'quantity' => $qty,
            'material_price' => $material,
            'labor_price' => $labor,
            'unit_price' => $unitPrice,
            'total' => round($qty * $unitPrice, 2),
        ];
    }
}

if (!function_exists('tnc_hire_lines_from_post')) {
    /**
     * @return list<array{description: string, unit: string, quantity: float, material_price: float, labor_price: float, unit_price: float, total: float}>
     */
    function tnc_hire_lines_from_post(array $post): array
    {
        $lines = [];
        foreach ($post['hire_description'] ?? [] as $key => $desc) {
            $desc = trim((string) $desc);
            if ($desc === '') {
                continue;
            }
            $lineType = trim((string) ($post['hire_line_type'][$key] ?? 'item'));
            if ($lineType === 'group') {
                $lines[] = [
                    'line_type' => 'group',
                    'description' => $desc,
                    'unit' => '',
                    'quantity' => 0.0,
                    'material_price' => 0.0,
                    'labor_price' => 0.0,
                    'unit_price' => 0.0,
                    'total' => 0.0,
                ];
                continue;
            }
            if (!isset($post['hire_qty'][$key])) {
                continue;
            }
            $qty = (float) ($post['hire_qty'][$key] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $unit = trim((string) ($post['hire_unit'][$key] ?? ''));
            $hasMat = array_key_exists('hire_material', $post) && array_key_exists($key, $post['hire_material'] ?? []);
            $hasLabor = array_key_exists('hire_labor', $post) && array_key_exists($key, $post['hire_labor'] ?? []);
            if ($hasMat || $hasLabor) {
                $material = (float) ($post['hire_material'][$key] ?? 0);
                $labor = (float) ($post['hire_labor'][$key] ?? 0);
            } elseif (isset($post['hire_unit_price'][$key])) {
                $material = 0.0;
                $labor = (float) ($post['hire_unit_price'][$key] ?? 0);
            } else {
                continue;
            }
            $calc = tnc_hire_line_calc($qty, $material, $labor);
            $lines[] = array_merge([
                'line_type' => 'item',
                'description' => $desc,
                'unit' => $unit,
            ], $calc);
        }

        return $lines;
    }
}

if (!function_exists('tnc_hire_lines_from_item_post')) {
    /**
     * รายการจากฟอร์ม PO สัญญาอิสระ (item_description[], item_material[], item_labor[])
     *
     * @return list<array{description: string, unit: string, quantity: float, material_price: float, labor_price: float, unit_price: float, total: float}>
     */
    function tnc_hire_lines_from_item_post(array $post): array
    {
        $lines = [];
        foreach ($post['item_description'] ?? [] as $key => $desc) {
            $desc = trim((string) $desc);
            if ($desc === '') {
                continue;
            }
            $lineType = trim((string) ($post['item_line_type'][$key] ?? 'item'));
            if ($lineType === 'group') {
                $lines[] = [
                    'line_type' => 'group',
                    'description' => $desc,
                    'unit' => '',
                    'quantity' => 0.0,
                    'material_price' => 0.0,
                    'labor_price' => 0.0,
                    'unit_price' => 0.0,
                    'total' => 0.0,
                ];
                continue;
            }
            if (!isset($post['item_qty'][$key])) {
                continue;
            }
            $qty = (float) ($post['item_qty'][$key] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $unit = trim((string) ($post['item_unit'][$key] ?? ''));
            $hasMat = array_key_exists('item_material', $post) && array_key_exists($key, $post['item_material'] ?? []);
            $hasLabor = array_key_exists('item_labor', $post) && array_key_exists($key, $post['item_labor'] ?? []);
            if ($hasMat || $hasLabor) {
                $material = (float) ($post['item_material'][$key] ?? 0);
                $labor = (float) ($post['item_labor'][$key] ?? 0);
            } elseif (isset($post['item_price'][$key])) {
                $material = 0.0;
                $labor = (float) ($post['item_price'][$key] ?? 0);
            } else {
                continue;
            }
            $calc = tnc_hire_line_calc($qty, $material, $labor);
            $lines[] = array_merge([
                'line_type' => 'item',
                'description' => $desc,
                'unit' => $unit,
            ], $calc);
        }

        return $lines;
    }
}

if (!function_exists('tnc_hire_count_billable_lines')) {
    function tnc_hire_count_billable_lines(array $lines): int
    {
        $count = 0;
        foreach ($lines as $line) {
            if (tnc_hire_line_is_group($line)) {
                continue;
            }
            if ((float) ($line['quantity'] ?? 0) > 0) {
                $count++;
            }
        }

        return $count;
    }
}

if (!function_exists('tnc_hire_subtotal_from_lines')) {
    function tnc_hire_subtotal_from_lines(array $lines): float
    {
        $sum = 0.0;
        foreach ($lines as $line) {
            if (tnc_hire_line_is_group($line)) {
                continue;
            }
            $sum += (float) ($line['total'] ?? 0);
        }

        return round($sum, 2);
    }
}

if (!function_exists('tnc_hire_pr_compute_totals')) {
    /**
     * คำนวณยอด PR จัดจ้าง: ยอดรายการ + Overhead % + Preliminary % → Excluded VAT → VAT 7% → Grand total
     *
     * @return array{
     *   direct_subtotal: float,
     *   overhead_percent: float,
     *   preliminary_percent: float,
     *   overhead_amount: float,
     *   preliminary_amount: float,
     *   excluded_vat: float,
     *   vat: float,
     *   grand_total: float
     * }
     */
    function tnc_hire_pr_compute_totals(float $directSubtotal, float $overheadPct, float $preliminaryPct, bool $vatEnabled): array
    {
        $directSubtotal = round(max(0.0, $directSubtotal), 2);
        $overheadPct = max(0.0, min(100.0, round($overheadPct, 4)));
        $preliminaryPct = max(0.0, min(100.0, round($preliminaryPct, 4)));
        $overheadAmount = round($directSubtotal * $overheadPct / 100, 2);
        $preliminaryAmount = round($directSubtotal * $preliminaryPct / 100, 2);
        $excludedVat = round($directSubtotal + $overheadAmount + $preliminaryAmount, 2);
        $vat = $vatEnabled ? round($excludedVat * 0.07, 2) : 0.0;
        $grandTotal = round($excludedVat + $vat, 2);

        return [
            'direct_subtotal' => $directSubtotal,
            'overhead_percent' => $overheadPct,
            'preliminary_percent' => $preliminaryPct,
            'overhead_amount' => $overheadAmount,
            'preliminary_amount' => $preliminaryAmount,
            'excluded_vat' => $excludedVat,
            'vat' => $vat,
            'grand_total' => $grandTotal,
        ];
    }
}

if (!function_exists('tnc_hire_item_material_labor')) {
    /** @return array{material: float, labor: float} */
    function tnc_hire_item_material_labor(array $item): array
    {
        $material = (float) ($item['material_price'] ?? 0);
        $labor = (float) ($item['labor_price'] ?? 0);
        if ($material <= 0 && $labor <= 0) {
            $unit = (float) ($item['unit_price'] ?? 0);
            if ($unit > 0) {
                $labor = $unit;
            }
        }

        return ['material' => $material, 'labor' => $labor];
    }
}

if (!function_exists('tnc_hire_save_pr_items')) {
    function tnc_hire_save_pr_items(int $prId, array $lines): void
    {
        if ($prId <= 0) {
            return;
        }
        foreach ($lines as $line) {
            $iid = Db::nextNumericId('purchase_request_items', 'id');
            Db::setRow('purchase_request_items', (string) $iid, [
                'id' => $iid,
                'pr_id' => $prId,
                'line_type' => (string) ($line['line_type'] ?? 'item'),
                'description' => (string) ($line['description'] ?? ''),
                'quantity' => (float) ($line['quantity'] ?? 0),
                'unit' => (string) ($line['unit'] ?? ''),
                'material_price' => (float) ($line['material_price'] ?? 0),
                'labor_price' => (float) ($line['labor_price'] ?? 0),
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'total' => (float) ($line['total'] ?? 0),
                'discount_input' => '',
                'discount_type' => 'amount',
                'discount_value' => 0,
                'discount_amount' => 0,
            ]);
        }
    }
}

if (!function_exists('tnc_hire_save_po_items')) {
    function tnc_hire_save_po_items(int $poId, array $lines): void
    {
        if ($poId <= 0) {
            return;
        }
        foreach ($lines as $line) {
            $iid = Db::nextNumericId('purchase_order_items', 'id');
            Db::setRow('purchase_order_items', (string) $iid, [
                'id' => $iid,
                'po_id' => $poId,
                'line_type' => (string) ($line['line_type'] ?? 'item'),
                'description' => (string) ($line['description'] ?? ''),
                'quantity' => (float) ($line['quantity'] ?? 0),
                'unit' => (string) ($line['unit'] ?? ''),
                'material_price' => (float) ($line['material_price'] ?? 0),
                'labor_price' => (float) ($line['labor_price'] ?? 0),
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'total' => (float) ($line['total'] ?? 0),
            ]);
        }
    }
}
