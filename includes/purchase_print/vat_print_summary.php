<?php

declare(strict_types=1);

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

        return round($sum, 2);
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
if (!function_exists('tnc_purchase_vat_split_from_line_sum')) {
    function tnc_purchase_vat_split_from_line_sum(float $lineSum, bool $vatOn, string $vatMode): array
    {
        $lineSum = round($lineSum, 2);
        $vatMode = in_array($vatMode, ['exclusive', 'inclusive'], true) ? $vatMode : 'exclusive';
        $subtotal = $lineSum;
        $vat = 0.0;
        $gross = $lineSum;
        if ($vatOn && $lineSum > 0) {
            if ($vatMode === 'inclusive') {
                $subtotal = round($lineSum * 100 / 107, 2);
                $gross = round($subtotal * 1.07, 2);
                $vat = round($gross - $subtotal, 2);
            } else {
                $subtotal = $lineSum;
                $vat = round($subtotal * 0.07, 2);
                $gross = round($subtotal * 1.07, 2);
            }
        }

        return [
            'subtotal' => $subtotal,
            'vat' => $vat,
            'gross' => $gross,
            'net' => $gross,
            'line_sum' => $lineSum,
        ];
    }
}

/**
 * คำนวณ subtotal / VAT / gross / net จากผลรวมแถว (ตรงกับตอนบันทึก PO)
 *
 * @return array{subtotal: float, vat: float, gross: float, net: float, line_sum: float}
 */
if (!function_exists('tnc_purchase_totals_from_line_sum')) {
    function tnc_purchase_totals_from_line_sum(float $lineSum, bool $vatOn, string $vatMode): array
    {
        return tnc_purchase_vat_split_from_line_sum($lineSum, $vatOn, $vatMode);
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
        $poNumberToId = [];
        foreach (\Theelincon\Rtdb\Db::tableRows('purchase_orders') as $po) {
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

        return $byPo;
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

        $lineSum = tnc_purchase_po_items_line_sum($items, $orderType);
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

        $derived = tnc_purchase_totals_from_line_sum($lineSum, $vatOn, $vatMode);

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
        $text = $vatMode === 'inclusive' ? 'รวม VAT' : 'แยก VAT';

        return $suffixColon ? $text . ':' : $text;
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
