<?php

declare(strict_types=1);

namespace Theelincon\Rtdb;

/** เลขที่เอกสาร Invoice / Quotation (แทน MAX SQL) */
final class Invoice
{
    public static function nextInvoiceNumber(string $issueDate): string
    {
        $month = date('m', strtotime($issueDate));
        $year = date('y', strtotime($issueDate));
        $prefix = "INV-TNC-$month$year-";
        $max = 0;
        foreach (Db::tableRows('invoices') as $r) {
            $inv = (string) ($r['invoice_number'] ?? '');
            if (str_starts_with($inv, $prefix)) {
                $tail = substr($inv, -3);
                $max = max($max, (int) $tail);
            }
        }

        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    public static function nextQuotationNumber(string $issueDate): string
    {
        $month = date('m', strtotime($issueDate));
        $year = date('y', strtotime($issueDate));
        $prefix = "QT-TNC-$month$year-";
        $max = 0;
        foreach (Db::tableRows('quotations') as $r) {
            $qn = (string) ($r['quote_number'] ?? '');
            if (str_starts_with($qn, $prefix)) {
                $tail = substr($qn, -3);
                $max = max($max, (int) $tail);
            }
        }

        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}
