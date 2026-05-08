<?php

declare(strict_types=1);

namespace Theelincon\Rtdb;

/**
 * หน้าแรก + navbar — แทน SQL JOIN / aggregate
 */
final class Portal
{
    /** @return list<array<string,mixed>> */
    public static function invoiceSearchRows(string $needle): array
    {
        $like = mb_strtolower(trim($needle));
        $matchAll = ($like === '');

        $invoices = Db::tableRows('invoices');
        $customers = Db::tableKeyed('customers');
        $users = Db::tableKeyed('users');

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

            $out[] = array_merge($inv, [
                'net_pay' => $net,
                'full_amount' => $full,
                'customer_name' => $custName,
                'customer_logo' => $cust['logo'] ?? '',
                'creator_name' => $creator,
            ]);
        }

        Db::sortRows($out, 'id', true);

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

    /**
     * navbar — ประกาศที่ต้องรับทราบและยังไม่อ่าน
     * @return list<array<string,mixed>>
     */
    public static function announcementGateItems(int $userId): array
    {
        $ann = Db::filter('internal_announcements', static function (array $r): bool {
            return !empty($r['must_ack']);
        });
        Db::sortRows($ann, 'created_at', true);

        $reads = Db::tableKeyed('announcement_reads');
        $out = [];
        foreach ($ann as $a) {
            $aid = (string) ($a['id'] ?? '');
            if ($aid === '') {
                continue;
            }
            $ck = Db::compositeKey([(string) $aid, (string) $userId]);
            $found = isset($reads[$ck]);
            if (!$found) {
                foreach ($reads as $k => $_r) {
                    if (isset($_r['announcement_id'], $_r['user_id'])
                        && (string) $_r['announcement_id'] === $aid
                        && (string) $_r['user_id'] === (string) $userId) {
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found) {
                $out[] = $a;
            }
        }

        usort($out, static function ($a, $b): int {
            $pa = !empty($a['is_pinned']) ? 1 : 0;
            $pb = !empty($b['is_pinned']) ? 1 : 0;
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }
            $ta = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['created_at'] ?? ''));

            return $tb <=> $ta;
        });

        return $out;
    }
}
