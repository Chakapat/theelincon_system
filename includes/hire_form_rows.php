<?php

declare(strict_types=1);

require_once __DIR__ . '/hire_line_items.php';

if (!function_exists('tnc_hire_form_field_names')) {
    /** @return array{type: string, desc: string, qty: string, unit: string, material: string, labor: string} */
    function tnc_hire_form_field_names(string $prefix): array
    {
        if ($prefix === 'item') {
            return [
                'type' => 'item_line_type',
                'desc' => 'item_description',
                'qty' => 'item_qty',
                'unit' => 'item_unit',
                'material' => 'item_material',
                'labor' => 'item_labor',
            ];
        }

        return [
            'type' => 'hire_line_type',
            'desc' => 'hire_description',
            'qty' => 'hire_qty',
            'unit' => 'hire_unit',
            'material' => 'hire_material',
            'labor' => 'hire_labor',
        ];
    }
}

if (!function_exists('tnc_hire_form_esc')) {
    function tnc_hire_form_esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tnc_hire_form_remove_btn_class')) {
    function tnc_hire_form_remove_btn_class(string $variant): string
    {
        return 'hire-btn-remove hire-remove-row';
    }
}

if (!function_exists('tnc_hire_form_row_group')) {
    /** @param array<string, mixed> $item */
    function tnc_hire_form_row_group(string $prefix, array $item = [], string $variant = 'pr', bool $disableRemove = false): void
    {
        $f = tnc_hire_form_field_names($prefix);
        $desc = tnc_hire_form_esc($item['description'] ?? '');
        $btnClass = tnc_hire_form_remove_btn_class($variant);
        $disabled = $disableRemove ? ' disabled' : '';
        ?>
        <tr class="hire-row-group">
            <td class="hire-line-no text-center fw-bold align-middle">1</td>
            <td colspan="7" class="py-2">
                <input type="hidden" name="<?= tnc_hire_form_esc($f['type']) ?>[]" class="hire-line-type" value="group">
                <input type="hidden" name="<?= tnc_hire_form_esc($f['qty']) ?>[]" value="0">
                <input type="hidden" name="<?= tnc_hire_form_esc($f['unit']) ?>[]" value="">
                <input type="hidden" name="<?= tnc_hire_form_esc($f['material']) ?>[]" value="0">
                <input type="hidden" name="<?= tnc_hire_form_esc($f['labor']) ?>[]" value="0">
                <input type="text" name="<?= tnc_hire_form_esc($f['desc']) ?>[]" class="form-control hire-desc-group fw-semibold" required placeholder="หัวข้อหลัก เช่น งาน Steel" value="<?= $desc ?>">
            </td>
            <td class="text-center align-middle"><button type="button" class="<?= $btnClass ?>"<?= $disabled ?> title="ลบหัวข้อ"><i class="bi bi-trash3"></i></button></td>
        </tr>
        <?php
    }
}

if (!function_exists('tnc_hire_form_row_item')) {
    /** @param array<string, mixed> $item */
    function tnc_hire_form_row_item(string $prefix, array $item = [], string $variant = 'pr', bool $disableRemove = false): void
    {
        if (!function_exists('tnc_hire_item_material_labor')) {
            require_once __DIR__ . '/hire_line_items.php';
        }
        $f = tnc_hire_form_field_names($prefix);
        $parts = tnc_hire_item_material_labor($item);
        $desc = tnc_hire_form_esc($item['description'] ?? '');
        $qty = tnc_hire_form_esc($item['quantity'] ?? '1');
        $unit = tnc_hire_form_esc($item['unit'] ?? '');
        $material = tnc_hire_form_esc($parts['material']);
        $labor = tnc_hire_form_esc($parts['labor']);
        $unitPrice = number_format((float) ($item['unit_price'] ?? 0), 2, '.', '');
        $total = number_format((float) ($item['total'] ?? 0), 2, '.', '');
        $btnClass = tnc_hire_form_remove_btn_class($variant);
        $disabled = $disableRemove ? ' disabled' : '';
        ?>
        <tr class="hire-row-item">
            <td class="hire-line-no text-center align-middle">1.1</td>
            <td class="hire-col-desc-cell">
                <input type="hidden" name="<?= tnc_hire_form_esc($f['type']) ?>[]" class="hire-line-type" value="item">
                <div class="hire-desc-wrap">
                    <span class="hire-desc-indent" aria-hidden="true"></span>
                    <input type="text" name="<?= tnc_hire_form_esc($f['desc']) ?>[]" class="form-control hire-desc" required placeholder="รายการย่อย เช่น ค่าแรงเชื่อมประกอบ" value="<?= $desc ?>">
                </div>
            </td>
            <td><input type="number" name="<?= tnc_hire_form_esc($f['qty']) ?>[]" class="form-control hire-qty text-end" min="0" step="0.01" value="<?= $qty ?>"></td>
            <td><input type="text" name="<?= tnc_hire_form_esc($f['unit']) ?>[]" class="form-control hire-unit text-end" placeholder="ชุด" value="<?= $unit ?>"></td>
            <td><input type="number" name="<?= tnc_hire_form_esc($f['material']) ?>[]" class="form-control hire-material text-end" min="0" step="0.01" value="<?= $material ?>"></td>
            <td><input type="number" name="<?= tnc_hire_form_esc($f['labor']) ?>[]" class="form-control hire-labor text-end" min="0" step="0.01" value="<?= $labor ?>"></td>
            <td class="hire-unit-price-sum"><input type="text" class="form-control hire-unit-price text-end bg-light" readonly value="<?= tnc_hire_form_esc($unitPrice) ?>" title="ค่าวัสดุ + ค่าแรง"></td>
            <td><input type="text" class="form-control hire-line-total text-end bg-light" readonly value="<?= tnc_hire_form_esc($total) ?>"></td>
            <td class="text-center"><button type="button" class="<?= $btnClass ?>"<?= $disabled ?> title="ลบรายการ"><i class="bi bi-trash3"></i></button></td>
        </tr>
        <?php
    }
}

if (!function_exists('tnc_hire_form_default_rows')) {
    function tnc_hire_form_default_rows(string $prefix, string $variant = 'pr'): void
    {
        tnc_hire_form_row_group($prefix, [], $variant, true);
        tnc_hire_form_row_item($prefix, ['quantity' => 1], $variant, true);
    }
}

if (!function_exists('tnc_hire_form_rows_from_items')) {
    /** @param list<array<string, mixed>> $items */
    function tnc_hire_form_rows_from_items(string $prefix, array $items, string $variant = 'pr'): void
    {
        if ($items === []) {
            tnc_hire_form_default_rows($prefix, $variant);

            return;
        }
        $disableRemove = count($items) <= 1;
        foreach ($items as $item) {
            if (tnc_hire_line_is_group($item)) {
                tnc_hire_form_row_group($prefix, $item, $variant, $disableRemove);
            } else {
                tnc_hire_form_row_item($prefix, $item, $variant, $disableRemove);
            }
        }
    }
}
