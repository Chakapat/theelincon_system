<?php

declare(strict_types=1);

/**
 * ปัดเงินเป็น 2 ทศนิยม แบบ half-up (ดูตำแหน่งที่ 3) — ค่าเริ่มต้นของ PR/PO
 */
if (!function_exists('tnc_money_round2')) {
    function tnc_money_round2(float $amount): float
    {
        if (!is_finite($amount)) {
            return 0.0;
        }

        return round($amount, 2, PHP_ROUND_HALF_UP);
    }
}

if (!function_exists('tnc_po_parse_retention')) {
    /**
     * แปลงค่า Retention จากฟอร์ม — บาท หรือ % ของฐานก่อน VAT (subtotal)
     *
     * @return array{retention_type: string, retention_value: string|float, retention_amount: float}
     */
    function tnc_po_parse_retention(string $raw, float $subtotal): array
    {
        $none = ['retention_type' => 'none', 'retention_value' => '', 'retention_amount' => 0.0];
        $raw = trim(str_replace([',', ' '], '', $raw));
        if ($raw === '' || $raw === '0') {
            return $none;
        }
        if (str_contains($raw, '%')) {
            $pct = (float) str_replace('%', '', $raw);
            if ($pct <= 0.0) {
                return $none;
            }
            $pct = min(100.0, $pct);
            $amount = tnc_money_round2(max(0.0, $subtotal) * ($pct / 100.0));
            if ($amount <= 0.0) {
                return $none;
            }

            return [
                'retention_type' => 'percent',
                'retention_value' => round($pct, 2),
                'retention_amount' => $amount,
            ];
        }
        $fixed = tnc_money_round2((float) $raw);
        if ($fixed <= 0.0) {
            return $none;
        }

        return [
            'retention_type' => 'fixed',
            'retention_value' => $fixed,
            'retention_amount' => $fixed,
        ];
    }
}

if (!function_exists('tnc_po_retention_input_display')) {
    /** ค่าในช่อง retention_input สำหรับแก้ไข PO */
    function tnc_po_retention_input_display(array $po): string
    {
        $type = trim((string) ($po['retention_type'] ?? 'none'));
        if ($type === 'percent') {
            $val = $po['retention_value'] ?? '';
            if ($val !== '' && $val !== null) {
                $pct = rtrim(rtrim(number_format((float) $val, 2, '.', ''), '0'), '.');

                return $pct . '%';
            }
        } elseif ($type === 'fixed') {
            $amt = (float) ($po['retention_amount'] ?? 0);
            if ($amt > 0.0) {
                return rtrim(rtrim(number_format($amt, 2, '.', ''), '0'), '.');
            }
        }

        return '';
    }
}

if (!function_exists('tnc_po_retention_label_default')) {
    function tnc_po_retention_label_default(): string
    {
        return 'หักประกันผลงาน Retention';
    }
}

if (!function_exists('tnc_po_retention_label_normalize')) {
    function tnc_po_retention_label_normalize(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return tnc_po_retention_label_default();
        }

        return mb_substr($raw, 0, 120);
    }
}

if (!function_exists('tnc_po_retention_label_display')) {
    function tnc_po_retention_label_display(array $row): string
    {
        $stored = trim((string) ($row['retention_label'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        return tnc_po_retention_label_default();
    }
}

if (!function_exists('tnc_po_retention_label_from_post')) {
    function tnc_po_retention_label_from_post(): string
    {
        return tnc_po_retention_label_normalize($_POST['retention_label'] ?? null);
    }
}

if (!function_exists('tnc_po_retention_db_fields')) {
    /** @param array<string,mixed> $totals จาก tnc_po_compute_totals */
    function tnc_po_retention_db_fields(array $totals): array
    {
        return [
            'retention_type' => (string) ($totals['retention_type'] ?? 'none'),
            'retention_value' => $totals['retention_value'] ?? '',
            'retention_amount' => (float) ($totals['retention_amount'] ?? 0),
            'payable_amount' => (float) ($totals['payable_amount'] ?? ($totals['net'] ?? 0)),
        ];
    }
}

if (!function_exists('tnc_po_retention_save_fields')) {
    /** @deprecated ใช้ tnc_po_adjustment_save_fields */
    function tnc_po_retention_save_fields(array $totals): array
    {
        return tnc_po_adjustment_save_fields($totals);
    }
}

if (!function_exists('tnc_po_parse_adjustment_lines')) {
    /**
     * @param list<array{label?:string,input?:string,sign?:string}> $lines
     * @return list<array{label:string,input:string,sign:string,value_type:string,value:string|float,amount:float}>
     */
    function tnc_po_parse_adjustment_lines(array $lines, float $subtotal): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $inputRaw = trim(str_replace(',', '', (string) ($line['input'] ?? '')));
            if ($inputRaw === '' || $inputRaw === '0') {
                continue;
            }
            $sign = (($line['sign'] ?? 'subtract') === 'add') ? 'add' : 'subtract';
            $labelRaw = trim((string) ($line['label'] ?? ''));
            $label = $labelRaw !== '' ? tnc_po_retention_label_normalize($labelRaw) : tnc_po_retention_label_default();
            $parsed = tnc_po_parse_retention($inputRaw, $subtotal);
            $amount = (float) ($parsed['retention_amount'] ?? 0);
            if ($amount <= 0.0) {
                continue;
            }
            $out[] = [
                'label' => $label,
                'input' => trim((string) ($line['input'] ?? '')),
                'sign' => $sign,
                'value_type' => (string) ($parsed['retention_type'] ?? 'fixed'),
                'value' => $parsed['retention_value'] ?? '',
                'amount' => $amount,
            ];
        }

        return $out;
    }
}

if (!function_exists('tnc_po_adjustment_delta')) {
    function tnc_po_adjustment_delta(array $adjustments): float
    {
        $delta = 0.0;
        foreach ($adjustments as $adj) {
            if (!is_array($adj)) {
                continue;
            }
            $amount = (float) ($adj['amount'] ?? 0);
            if ($amount <= 0.0) {
                continue;
            }
            $delta += (($adj['sign'] ?? 'subtract') === 'add') ? $amount : -$amount;
        }

        return tnc_money_round2($delta);
    }
}

if (!function_exists('tnc_po_adjustment_lines_from_post')) {
    /** @return list<array{label:string,input:string,sign:string}> */
    function tnc_po_adjustment_lines_from_post(): array
    {
        $labels = $_POST['adjustment_label'] ?? null;
        if (is_array($labels)) {
            $lines = [];
            $inputs = is_array($_POST['adjustment_input'] ?? null) ? $_POST['adjustment_input'] : [];
            $signs = is_array($_POST['adjustment_sign'] ?? null) ? $_POST['adjustment_sign'] : [];
            foreach ($labels as $idx => $label) {
                $lines[] = [
                    'label' => trim((string) $label),
                    'input' => trim((string) ($inputs[$idx] ?? '')),
                    'sign' => (($signs[$idx] ?? 'subtract') === 'add') ? 'add' : 'subtract',
                ];
            }

            return $lines;
        }
        $legacyInput = trim(str_replace(',', '', (string) ($_POST['retention_input'] ?? '')));
        if ($legacyInput === '' || $legacyInput === '0') {
            return [];
        }

        return [[
            'label' => tnc_po_retention_label_from_post(),
            'input' => trim((string) ($_POST['retention_input'] ?? '')),
            'sign' => 'subtract',
        ]];
    }
}

if (!function_exists('tnc_po_adjustment_lines_from_row')) {
    /** @return list<array{label:string,input:string,sign:string}> */
    function tnc_po_adjustment_lines_from_row(array $row): array
    {
        $stored = $row['po_adjustments'] ?? null;
        if (is_array($stored) && $stored !== []) {
            $lines = [];
            foreach ($stored as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $input = trim((string) ($item['input'] ?? ''));
                if ($input === '' && ($item['value_type'] ?? '') === 'percent') {
                    $val = $item['value'] ?? '';
                    if ($val !== '' && $val !== null) {
                        $input = rtrim(rtrim(number_format((float) $val, 2, '.', ''), '0'), '.') . '%';
                    }
                } elseif ($input === '' && (float) ($item['amount'] ?? 0) > 0.0) {
                    $input = rtrim(rtrim(number_format((float) ($item['amount'] ?? 0), 2, '.', ''), '0'), '.');
                }
                $lines[] = [
                    'label' => trim((string) ($item['label'] ?? '')),
                    'input' => $input,
                    'sign' => (($item['sign'] ?? 'subtract') === 'add') ? 'add' : 'subtract',
                ];
            }
            if ($lines !== []) {
                return $lines;
            }
        }
        $legacyInput = tnc_po_retention_input_display($row);
        if ($legacyInput === '') {
            return [];
        }

        return [[
            'label' => tnc_po_retention_label_display($row),
            'input' => $legacyInput,
            'sign' => 'subtract',
        ]];
    }
}

if (!function_exists('tnc_po_adjustments_from_row')) {
    /** @return list<array{label:string,input:string,sign:string,value_type:string,value:string|float,amount:float}> */
    function tnc_po_adjustments_from_row(array $row, ?float $subtotal = null): array
    {
        if ($subtotal === null) {
            $subtotal = (float) ($row['subtotal_amount'] ?? 0);
            if ($subtotal <= 0.0) {
                $gross = (float) ($row['gross_amount'] ?? ($row['total_amount'] ?? 0));
                $vat = (float) ($row['vat_amount'] ?? 0);
                $subtotal = tnc_money_round2(max(0.0, $gross - $vat));
            }
        }

        return tnc_po_parse_adjustment_lines(tnc_po_adjustment_lines_from_row($row), $subtotal);
    }
}

if (!function_exists('tnc_po_legacy_retention_fields')) {
    /** @param list<array<string,mixed>> $adjustments */
    function tnc_po_legacy_retention_fields(array $adjustments): array
    {
        foreach ($adjustments as $adj) {
            if (!is_array($adj) || (($adj['sign'] ?? 'subtract') !== 'subtract')) {
                continue;
            }
            if ((float) ($adj['amount'] ?? 0) <= 0.0) {
                continue;
            }

            return [
                'retention_type' => (string) ($adj['value_type'] ?? 'fixed'),
                'retention_value' => $adj['value'] ?? '',
                'retention_amount' => (float) ($adj['amount'] ?? 0),
                'retention_label' => (string) ($adj['label'] ?? tnc_po_retention_label_default()),
            ];
        }

        return [
            'retention_type' => 'none',
            'retention_value' => '',
            'retention_amount' => 0.0,
            'retention_label' => '',
        ];
    }
}

if (!function_exists('tnc_po_adjustment_save_fields')) {
    function tnc_po_adjustment_save_fields(array $totals): array
    {
        $adjustments = is_array($totals['po_adjustments'] ?? null) ? $totals['po_adjustments'] : [];

        return array_merge([
            'po_adjustments' => $adjustments,
            'payable_amount' => (float) ($totals['payable_amount'] ?? ($totals['net'] ?? 0)),
        ], tnc_po_legacy_retention_fields($adjustments));
    }
}

/**
 * ปัดเงินใกล้จำนวนเต็มบาท (half-up)
 */
if (!function_exists('tnc_money_round_baht')) {
    function tnc_money_round_baht(float $amount): float
    {
        if (!is_finite($amount)) {
            return 0.0;
        }

        return (float) round($amount, 0, PHP_ROUND_HALF_UP);
    }
}

/**
 * ปัดตามโหมด: เต็มบาท หรือ 2 ทศนิยม
 */
if (!function_exists('tnc_money_round_mode')) {
    function tnc_money_round_mode(float $amount, bool $roundToBaht): float
    {
        return $roundToBaht ? tnc_money_round_baht($amount) : tnc_money_round2($amount);
    }
}

/**
 * คูณแล้วปัดตามโหมด (จำนวน × ราคา/หน่วย)
 */
if (!function_exists('tnc_money_mul2')) {
    function tnc_money_mul2(float $a, float $b, bool $roundToBaht = false): float
    {
        if (function_exists('bcmul')) {
            $product = bcmul(sprintf('%.12F', $a), sprintf('%.12F', $b), 12);

            return tnc_money_round_mode((float) $product, $roundToBaht);
        }

        return tnc_money_round_mode($a * $b, $roundToBaht);
    }
}

/**
 * รวมยอดจากคอลัมน์ total ของแต่ละแถว (หลังส่วนลด) — ไม่ใช้ qty × unit_price
 */
if (!function_exists('tnc_purchase_po_items_line_sum')) {
    function tnc_purchase_po_items_line_sum(array $items, string $orderType = 'purchase'): float
    {
        $sum = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sum += (float) ($item['total'] ?? 0);
        }

        return tnc_money_round2($sum);
    }
}

/**
 * แยกยอด VAT 7% จากผลรวมแถว (ใช้ร่วมกัน PR/PO บันทึก + รายงาน)
 *
 * รวม VAT: ยอดรายการ = round(lineSum × 100 ÷ 107, 2), ยอดสุทธิ = round(ยอดรายการ × 1.07, 2)
 * แยก VAT: ยอดรายการ = lineSum, VAT = round(ยอด × 7%, 2), ยอดสุทธิ = round(ยอด × 1.07, 2)
 *
 * @return array{subtotal: float, vat: float, gross: float, net: float, line_sum: float}
 */
if (!function_exists('tnc_purchase_items_vat_sums')) {
    /** @return array{taxable: float, exempt: float} */
    function tnc_purchase_items_vat_sums(array $items): array
    {
        $taxable = 0.0;
        $exempt = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $total = function_exists('tnc_money_round2')
                ? tnc_money_round2((float) ($item['total'] ?? 0))
                : round((float) ($item['total'] ?? 0), 2);
            if ((int) ($item['vat_exempt'] ?? 0) === 1) {
                $exempt += $total;
            } else {
                $taxable += $total;
            }
        }

        return [
            'taxable' => tnc_money_round2($taxable),
            'exempt' => tnc_money_round2($exempt),
        ];
    }
}

if (!function_exists('tnc_purchase_vat_split_from_line_sums')) {
    /**
     * แยก VAT จากยอดรายการ
     * - ปัดเต็มบาท: ใช้กับยอดบรรทัดที่ปัดบาทแล้วเท่านั้น ส่วนฐาน/VAT ยังปัด 2 ทศนิยม (แบบบิลปั๊ม รวม VAT)
     * - รวม VAT: มูลค่า = round(ยอด×100/107, 2), VAT = ยอด − มูลค่า
     *
     * @return array{subtotal: float, vat: float, gross: float, net: float, line_sum: float, taxable_sum: float, exempt_sum: float}
     */
    function tnc_purchase_vat_split_from_line_sums(float $taxableSum, float $exemptSum, bool $vatOn, string $vatMode, bool $roundToBaht = false): array
    {
        // ยอดบรรทัดอาจถูกปัดบาทมาแล้ว — คงค่าไว้ แล้วคำนวณ VAT ด้วย 2 ทศนิยมเสมอ
        if ($roundToBaht) {
            $taxableSum = tnc_money_round_baht($taxableSum);
            $exemptSum = tnc_money_round_baht($exemptSum);
        } else {
            $taxableSum = tnc_money_round2($taxableSum);
            $exemptSum = tnc_money_round2($exemptSum);
        }
        $lineSum = tnc_money_round_mode($taxableSum + $exemptSum, $roundToBaht);
        $vatMode = in_array($vatMode, ['exclusive', 'inclusive'], true) ? $vatMode : 'exclusive';

        if (!$vatOn) {
            return [
                'subtotal' => $lineSum,
                'vat' => 0.0,
                'gross' => $lineSum,
                'net' => $lineSum,
                'line_sum' => $lineSum,
                'taxable_sum' => $taxableSum,
                'exempt_sum' => $exemptSum,
            ];
        }

        $subtotal = $taxableSum;
        $vat = 0.0;
        $gross = $lineSum;
        if ($vatOn && $taxableSum > 0) {
            if ($vatMode === 'inclusive') {
                // แบบบิลปั๊ม: ยอดรวมเป็นหลัก → ถอดฐาน/VAT เป็นสตางค์
                $gross = $lineSum;
                $subtotal = tnc_money_round2($taxableSum * 100 / 107);
                $vat = tnc_money_round2($gross - $exemptSum - $subtotal);
                // กันเศษลอย: ปรับฐานให้ ฐาน+VAT = ยอดรวมเป๊ะ
                $subtotal = tnc_money_round2($gross - $exemptSum - $vat);
            } else {
                $subtotal = $taxableSum;
                $vat = tnc_money_round2($subtotal * 0.07);
                $gross = tnc_money_round2($subtotal + $vat + $exemptSum);
            }
        }

        return [
            'subtotal' => $subtotal,
            'vat' => $vat,
            'gross' => $gross,
            'net' => $gross,
            'line_sum' => $lineSum,
            'taxable_sum' => $taxableSum,
            'exempt_sum' => $exemptSum,
        ];
    }
}

if (!function_exists('tnc_purchase_vat_split_from_line_sum')) {
    function tnc_purchase_vat_split_from_line_sum(float $lineSum, bool $vatOn, string $vatMode): array
    {
        return tnc_purchase_vat_split_from_line_sums($lineSum, 0.0, $vatOn, $vatMode);
    }
}

/**
 * คำนวณ subtotal / VAT / gross / net จากผลรวมแถว (ตรงกับตอนบันทึก PO)
 *
 * @return array{subtotal: float, vat: float, gross: float, net: float, line_sum: float}
 */
if (!function_exists('tnc_purchase_totals_from_line_sum')) {
    function tnc_purchase_totals_from_line_sum(float $lineSum, bool $vatOn, string $vatMode, float $exemptSum = 0.0): array
    {
        return tnc_purchase_vat_split_from_line_sums(round($lineSum - $exemptSum, 2), $exemptSum, $vatOn, $vatMode);
    }
}

/**
 * โหลดรายการ PO ให้ตรงกับหน้า view/พิมพ์ (po_id, po_number, fallback จาก PR)
 *
 * @return list<array<string, mixed>>
 */
if (!function_exists('tnc_purchase_po_load_items')) {
    function tnc_purchase_po_load_items(int $poId, array $po, ?array $pr = null): array
    {
        if ($poId <= 0) {
            return [];
        }

        $poNumber = trim((string) ($po['po_number'] ?? ''));
        $prId = (int) ($po['pr_id'] ?? 0);
        if ($pr === null && $prId > 0) {
            $prRow = \Theelincon\Rtdb\Db::rowByIdField('purchase_requests', $prId);
            $pr = is_array($prRow) ? $prRow : null;
        }

        $items = \Theelincon\Rtdb\Db::filter('purchase_order_items', static function (array $r) use ($poId, $poNumber): bool {
            $pid = (int) ($r['po_id'] ?? 0);
            $purchaseOrderId = (int) ($r['purchase_order_id'] ?? 0);
            $poNumberRef = trim((string) ($r['po_number'] ?? ''));

            return $pid === $poId
                || $purchaseOrderId === $poId
                || ($poNumber !== '' && $poNumberRef === $poNumber);
        });

        if ($items === [] && $prId > 0) {
            $items = \Theelincon\Rtdb\Db::filter('purchase_order_items', static function (array $r) use ($prId): bool {
                return (int) ($r['pr_id'] ?? 0) === $prId;
            });
        }
        if ($items === [] && $prId > 0) {
            $items = \Theelincon\Rtdb\Db::filter('purchase_request_items', static function (array $r) use ($prId): bool {
                return (int) ($r['pr_id'] ?? 0) === $prId;
            });
        }

        \Theelincon\Rtdb\Db::sortRows($items, 'id', false);

        return $items;
    }
}

/**
 * ชื่อไซต์งานบน PO — ใช้ site_name ที่บันทึกไว้ก่อน แล้วค่อย lookup จาก PR / ตาราง sites
 */
if (!function_exists('tnc_purchase_po_resolve_site_name')) {
    function tnc_purchase_po_resolve_site_name(array $po, ?array $pr = null, array $siteNameById = []): string
    {
        $siteName = trim((string) ($po['site_name'] ?? ''));
        $siteId = (int) ($po['site_id'] ?? 0);

        if ($siteName === '' && $siteId <= 0 && is_array($pr)) {
            $siteId = (int) ($pr['site_id'] ?? 0);
            $siteName = trim((string) ($pr['site_name'] ?? ''));
        }

        if ($siteName === '' && $siteId > 0) {
            if (isset($siteNameById[$siteId]) && $siteNameById[$siteId] !== '') {
                $siteName = $siteNameById[$siteId];
            } else {
                $siteRow = \Theelincon\Rtdb\Db::row('sites', (string) $siteId);
                if (is_array($siteRow)) {
                    $siteName = trim((string) ($siteRow['name'] ?? ''));
                }
            }
        }

        return $siteName;
    }
}

if (!function_exists('tnc_purchase_po_resolve_site_id')) {
    function tnc_purchase_po_resolve_site_id(array $po, ?array $pr = null): int
    {
        $siteId = (int) ($po['site_id'] ?? 0);
        if ($siteId <= 0 && is_array($pr)) {
            $siteId = (int) ($pr['site_id'] ?? 0);
        }

        return $siteId;
    }
}

/**
 * จัดกลุ่มรายการ PO ตาม po_id (โหลดครั้งเดียวสำหรับหน้ารายการ)
 *
 * @return array<int, list<array<string, mixed>>>
 */
if (!function_exists('tnc_purchase_po_items_group_by_po_id')) {
    function tnc_purchase_po_items_group_by_po_id(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $poNumberToId = [];
        $poSource = function_exists('tnc_site_budget_purchase_orders_cached')
            ? tnc_site_budget_purchase_orders_cached()
            : \Theelincon\Rtdb\Db::tableRows('purchase_orders');
        foreach ($poSource as $po) {
            if (!is_array($po)) {
                continue;
            }
            $id = (int) ($po['id'] ?? 0);
            $poNumber = trim((string) ($po['po_number'] ?? ''));
            if ($id > 0 && $poNumber !== '') {
                $poNumberToId[$poNumber] = $id;
            }
        }

        $byPo = [];
        foreach (\Theelincon\Rtdb\Db::tableRows('purchase_order_items') as $item) {
            if (!is_array($item)) {
                continue;
            }
            $poId = (int) ($item['po_id'] ?? 0);
            if ($poId <= 0) {
                $poId = (int) ($item['purchase_order_id'] ?? 0);
            }
            if ($poId <= 0) {
                $poNumberRef = trim((string) ($item['po_number'] ?? ''));
                if ($poNumberRef !== '' && isset($poNumberToId[$poNumberRef])) {
                    $poId = (int) $poNumberToId[$poNumberRef];
                }
            }
            if ($poId <= 0) {
                continue;
            }
            $byPo[$poId][] = $item;
        }

        $cached = $byPo;

        return $cached;
    }
}

/**
 * ยอด PO ที่แสดงในรายการ — ใช้ผลรวมคอลัมน์ total ของแต่ละแถว (หลังส่วนลด) แทนค่า header ที่อาจคลาดเคลื่อน
 *
 * @return array{subtotal: float, vat: float, net: float, gross: float}
 */
if (!function_exists('tnc_purchase_po_resolved_totals')) {
    function tnc_purchase_po_resolved_totals(array $po, array $items): array
    {
        $storedNet = (float) ($po['total_amount'] ?? 0);
        $storedVat = (float) ($po['vat_amount'] ?? 0);
        $storedSub = isset($po['subtotal_amount']) && $po['subtotal_amount'] !== '' && $po['subtotal_amount'] !== null
            ? (float) $po['subtotal_amount']
            : 0.0;
        $storedGross = (float) (($po['gross_amount'] ?? '') !== '' && ($po['gross_amount'] ?? null) !== null
            ? $po['gross_amount']
            : $storedNet);

        $orderType = trim((string) ($po['order_type'] ?? 'purchase'));
        if ($orderType !== 'purchase') {
            return [
                'subtotal' => $storedSub > 0 ? $storedSub : round($storedNet - $storedVat, 2),
                'vat' => $storedVat,
                'net' => $storedNet,
                'gross' => $storedGross > 0 ? $storedGross : $storedNet,
            ];
        }

        $lineSums = tnc_purchase_items_vat_sums($items);
        $lineSum = round($lineSums['taxable'] + $lineSums['exempt'], 2);
        if ($lineSum <= 0) {
            return [
                'subtotal' => $storedSub > 0 ? $storedSub : round($storedNet - $storedVat, 2),
                'vat' => $storedVat,
                'net' => $storedNet,
                'gross' => $storedGross > 0 ? $storedGross : $storedNet,
            ];
        }

        $vatOn = (int) ($po['vat_enabled'] ?? 0) === 1;
        $vatMode = trim((string) ($po['vat_mode'] ?? 'exclusive'));
        if (!in_array($vatMode, ['exclusive', 'inclusive'], true)) {
            $vatMode = 'exclusive';
        }

        $derived = tnc_purchase_vat_split_from_line_sums($lineSums['taxable'], $lineSums['exempt'], $vatOn, $vatMode);

        return [
            'subtotal' => $derived['subtotal'],
            'vat' => $derived['vat'],
            'net' => $derived['net'],
            'gross' => $derived['gross'],
        ];
    }
}

/** ป้ายโหมด VAT สำหรับแสดงใน PR/PO */
if (!function_exists('tnc_purchase_vat_mode_label')) {
    function tnc_purchase_vat_mode_label(string $vatMode, bool $suffixColon = false): string
    {
        $text = $vatMode === 'inclusive' ? 'รวมภาษีมูลค่าเพิ่ม' : 'แยกภาษีมูลค่าเพิ่ม';

        return $suffixColon ? $text . ':' : $text;
    }
}

if (!function_exists('tnc_purchase_vat_label_for_print')) {
    /** ป้าย VAT บนเอกสารพิมพ์ — ใช้โหมดปัจจุบัน และแปลงป้ายเก่าที่อาจค้างในข้อมูล */
    function tnc_purchase_vat_label_for_print(?string $vatMode = null, ?string $storedLabel = null): string
    {
        $stored = trim((string) $storedLabel);
        $withColon = str_ends_with($stored, ':');
        if ($stored === 'แยก VAT' || $stored === 'แยก VAT:') {
            return tnc_purchase_vat_mode_label('exclusive', $withColon);
        }
        if ($stored === 'รวม VAT' || $stored === 'รวม VAT:') {
            return tnc_purchase_vat_mode_label('inclusive', $withColon);
        }

        $mode = in_array((string) $vatMode, ['inclusive', 'exclusive'], true)
            ? (string) $vatMode
            : 'exclusive';

        return tnc_purchase_vat_mode_label($mode);
    }
}

/**
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
        $netAmount = $grandTotal > 0
            ? $grandTotal
            : round($subtotalStored * 1.07, 2);
        $lineAmount = $subtotalStored > 0
            ? $subtotalStored
            : round($netAmount - $vatAmount, 2);

        return [
            'vat_mode' => 'inclusive',
            'line_amount' => $lineAmount,
            'vat_label' => tnc_purchase_vat_mode_label('inclusive'),
            'vat_amount' => $vatAmount,
            'net_amount' => $netAmount,
        ];
    }

    $lineAmount = $subtotalStored > 0 ? $subtotalStored : round($grandTotal - $vatAmount, 2);

    return [
        'vat_mode' => 'exclusive',
        'line_amount' => $lineAmount,
        'vat_label' => tnc_purchase_vat_mode_label('exclusive'),
        'vat_amount' => $vatAmount,
        'net_amount' => $grandTotal > 0 ? $grandTotal : round($lineAmount + $vatAmount, 2),
    ];
}

/** PO สมบูรณ์สำหรับรายงาน = ไม่ยกเลิก + จ่ายแล้ว + มีเลขที่ใบกำกับ */
if (!function_exists('tnc_purchase_po_is_complete_for_report')) {
    function tnc_purchase_po_is_complete_for_report(array $po): bool
    {
        if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
            return false;
        }
        if (strtolower(trim((string) ($po['payment_status'] ?? 'unpaid'))) !== 'paid') {
            return false;
        }

        return trim((string) ($po['supplier_invoice_no'] ?? '')) !== '';
    }
}

/**
 * ยอด PO สำหรับรายงาน — คำนวณจากรายการปัจจุบัน (fallback ค่า header ถ้าไม่มีแถว)
 *
 * @return array{subtotal: float, vat: float, net: float, gross: float}
 */
if (!function_exists('tnc_purchase_report_amounts_from_po')) {
    function tnc_purchase_report_amounts_from_po(array $po, array $items = []): array
    {
        if (function_exists('tnc_purchase_po_resolved_totals')) {
            return tnc_purchase_po_resolved_totals($po, $items);
        }

        $net = round((float) ($po['total_amount'] ?? 0), 2);
        $vat = round((float) ($po['vat_amount'] ?? 0), 2);
        $sub = isset($po['subtotal_amount']) && $po['subtotal_amount'] !== '' && $po['subtotal_amount'] !== null
            ? round((float) $po['subtotal_amount'], 2)
            : round($net - $vat, 2);

        return [
            'subtotal' => $sub,
            'vat' => $vat,
            'net' => $net,
            'gross' => round((float) (($po['gross_amount'] ?? '') !== '' && ($po['gross_amount'] ?? null) !== null
                ? $po['gross_amount']
                : $net), 2),
        ];
    }
}

/** ชื่อผู้ขายจาก PO */
if (!function_exists('tnc_purchase_report_supplier_name')) {
    function tnc_purchase_report_supplier_name(array $po): string
    {
        $supplierId = (int) ($po['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $supplierRow = \Theelincon\Rtdb\Db::rowByIdField('suppliers', $supplierId);
            if (is_array($supplierRow)) {
                $name = trim((string) ($supplierRow['name'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        $name = trim((string) ($po['supplier_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return trim((string) ($po['supplier_name'] ?? ''));
    }
}
