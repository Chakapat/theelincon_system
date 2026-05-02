<?php

declare(strict_types=1);

namespace Theelincon\Rtdb;

/**
 * หน้าแรก — ค้นหาใบแจ้งหนี้ / สรุปยอด (แทน SQL JOIN / aggregate)
 */
final class Portal
{
    /** @return list<array<string,mixed>> */
    public static function invoiceSearchRows(string $needle, int $limit = 80): array
    {
        $like = mb_strtolower(trim($needle));
        $matchAll = ($like === '');
        if ($limit <= 0) {
            $limit = 80;
        }

        $invoices = Db::tableRows('invoices');
        $customers = Db::tableKeyed('customers');
        $users = Db::tableKeyed('users');

        $invoiceIdsWithTax = [];
        foreach (Db::tableRows('tax_invoices') as $tx) {
            $iid = isset($tx['invoice_id']) ? (int) $tx['invoice_id'] : 0;
            if ($iid <= 0) {
                continue;
            }
            if (trim((string) ($tx['tax_invoice_number'] ?? '')) === '') {
                continue;
            }
            $invoiceIdsWithTax[$iid] = true;
        }

        $out = [];
        foreach ($invoices as $inv) {
            $cid = isset($inv['customer_id']) ? (string) $inv['customer_id'] : '';
            $cust = $customers[$cid] ?? null;
            $custName = $cust ? (string) ($cust['name'] ?? '') : '';
            $invNo = (string) ($inv['invoice_number'] ?? '');
            if (!$matchAll && mb_strpos(mb_strtolower($invNo), $like) === false
                && mb_strpos(mb_strtolower($custName), $like) === false) {
                continue;
            }

            $uid = isset($inv['created_by']) ? (string) $inv['created_by'] : '';
            $u = $users[$uid] ?? null;
            $fn = $u['fname'] ?? '';
            $ln = $u['lname'] ?? '';
            $creator = trim($fn . ' ' . $ln);

            $sub = (float) ($inv['subtotal'] ?? 0);
            $vat = (float) ($inv['vat_amount'] ?? 0);
            $wht = (float) ($inv['withholding_tax'] ?? 0);
            $ret = (float) ($inv['retention_amount'] ?? 0);
            $net = $sub + $vat - $wht - $ret;
            $full = $sub + $vat;
            $invId = isset($inv['id']) ? (int) $inv['id'] : 0;

            $out[] = array_merge($inv, [
                'net_pay' => $net,
                'full_amount' => $full,
                'customer_name' => $custName,
                'customer_logo' => $cust['logo'] ?? '',
                'creator_name' => $creator,
                'has_tax_invoice' => $invId > 0 && isset($invoiceIdsWithTax[$invId]),
            ]);
        }

        Db::sortRows($out, 'id', true);

        if (count($out) > $limit) {
            $out = array_slice($out, 0, $limit);
        }

        return $out;
    }

    /** @return array{total_count:int,final_net_sum:float} */
    public static function invoiceSummary(): array
    {
        $rows = Db::tableRows('invoices');
        $total = count($rows);
        $sum = 0.0;
        foreach ($rows as $inv) {
            $sub = (float) ($inv['subtotal'] ?? 0);
            $vat = (float) ($inv['vat_amount'] ?? 0);
            $wht = (float) ($inv['withholding_tax'] ?? 0);
            $ret = (float) ($inv['retention_amount'] ?? 0);
            $sum += $sub + $vat - $wht - $ret;
        }

        return ['total_count' => $total, 'final_net_sum' => $sum];
    }
}
