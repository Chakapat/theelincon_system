<?php

declare(strict_types=1);

namespace Theelincon\Rtdb;

/** เลขที่เอกสาร Invoice (แทน MAX SQL) */
final class Invoice
{
    public static function nextInvoiceNumber(string $issueDate): string
    {
        $stamp = date('ym', strtotime($issueDate));
        $prefix = "INV-TNC-$stamp-";
        $max = 0;
        foreach (Db::tableRows('invoices') as $r) {
            $inv = (string) ($r['invoice_number'] ?? '');
            if (strncmp($inv, $prefix, strlen($prefix)) === 0) {
                $tail = substr($inv, -3);
                $max = max($max, (int) $tail);
            }
        }

        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}
