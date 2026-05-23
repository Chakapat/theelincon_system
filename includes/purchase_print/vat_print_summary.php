<?php

declare(strict_types=1);

/**
 * สรุปยอด VAT สำหรับพิมพ์ PR/PO ตาม vat_mode ที่บันทึก
 *
 * @return array{
 *     vat_mode: string,
 *     line_amount: float,
 *     vat_label: string,
 *     vat_amount: float,
 *     net_amount: float
 * }
 */
function tnc_purchase_vat_print_summary(
    bool $vatOn,
    string $vatMode,
    float $subtotalStored,
    float $vatAmount,
    float $grandTotal
): array {
    $vatMode = in_array($vatMode, ['exclusive', 'inclusive'], true) ? $vatMode : 'exclusive';
    $grandTotal = round($grandTotal, 2);
    $subtotalStored = round($subtotalStored, 2);
    $vatAmount = round($vatAmount, 2);

    if (!$vatOn || $vatAmount <= 0) {
        $line = $grandTotal > 0 ? $grandTotal : $subtotalStored;

        return [
            'vat_mode' => 'none',
            'line_amount' => $line,
            'vat_label' => '',
            'vat_amount' => 0.0,
            'net_amount' => $line,
        ];
    }

    if ($vatMode === 'inclusive') {
        $lineAmount = round($subtotalStored + $vatAmount, 2);
        if ($grandTotal > 0 && abs($lineAmount - $grandTotal) > 0.02) {
            $lineAmount = $grandTotal;
        }

        return [
            'vat_mode' => 'inclusive',
            'line_amount' => $lineAmount,
            'vat_label' => 'ภาษีมูลค่าเพิ่มในราคาสินค้า',
            'vat_amount' => $vatAmount,
            'net_amount' => $grandTotal > 0 ? $grandTotal : $lineAmount,
        ];
    }

    $lineAmount = $subtotalStored > 0 ? $subtotalStored : round($grandTotal - $vatAmount, 2);

    return [
        'vat_mode' => 'exclusive',
        'line_amount' => $lineAmount,
        'vat_label' => 'ภาษีมูลค่าเพิ่มแยกจากราคาสินค้า',
        'vat_amount' => $vatAmount,
        'net_amount' => $grandTotal > 0 ? $grandTotal : round($lineAmount + $vatAmount, 2),
    ];
}
