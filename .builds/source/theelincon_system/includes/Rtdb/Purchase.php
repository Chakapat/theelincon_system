<?php

declare(strict_types=1);

namespace Theelincon\Rtdb;

final class Purchase
{
    public static function generatePONumber(): string
    {
        $prefix = 'PO-TNC-' . date('my') . '-';
        $rows = Db::tableRows('purchase_orders');
        $max = 0;
        foreach ($rows as $r) {
            $pn = (string) ($r['po_number'] ?? '');
            if (str_starts_with($pn, $prefix)) {
                $tail = substr($pn, -3);
                $max = max($max, (int) $tail);
            }
        }

        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /** PR-TNC-MMYY-xxx (ตรงกับฟอร์มสร้าง PR) */
    public static function nextPRNumber(): string
    {
        $suffix = date('my');
        $prefix = 'PR-TNC-' . $suffix . '-';
        $max = 0;
        foreach (Db::tableRows('purchase_requests') as $r) {
            $pn = (string) ($r['pr_number'] ?? '');
            if (str_starts_with($pn, $prefix)) {
                $tail = substr($pn, -3);
                $max = max($max, (int) $tail);
            }
        }

        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}
