<?php

function cash_ledger_format_lines_summary(array $lines): string
{
    if (count($lines) === 0) {
        return '—';
    }
    $parts = [];
    $i = 0;
    foreach ($lines as $ln) {
        $i++;
        $d = trim((string) ($ln['item_description'] ?? ''));
        $u = trim((string) ($ln['unit'] ?? ''));
        $q = (float) ($ln['quantity'] ?? 0);
        $p = (float) ($ln['unit_price'] ?? 0);
        $t = (float) ($ln['line_total'] ?? 0);
        $qtyStr = rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
        $mid = $qtyStr . ($u !== '' ? ' ' . $u : '');
        $parts[] = $i . ') ' . ($d !== '' ? $d . ' ' : '') . $mid . ' ×' . number_format($p, 2) . ' = ' . number_format($t, 2);
    }
    return implode("\n", $parts);
}

function cash_ledger_vat_label(array $row): string
{
    $mode = $row['vat_mode'] ?? 'none';
    $rate = isset($row['vat_rate']) ? (float) $row['vat_rate'] : 7;
    $vat = isset($row['vat_amount']) ? (float) $row['vat_amount'] : 0;
    if ($mode === 'none' || $vat <= 0) {
        return 'ไม่มี VAT';
    }
    if ($mode === 'exclusive') {
        return 'ยอดรายการ + VAT ' . rtrim(rtrim((string) $rate, '0'), '.') . '% (฿' . number_format($vat, 2) . ')';
    }
    return 'รวม VAT แล้ว (แยก VAT ฿' . number_format($vat, 2) . ')';
}

/**
 * Auto archive previous month summary to RTDB once per month.
 */
function cash_ledger_auto_archive_monthly_if_due(): bool
{
    $archiveMonth = date('Y-m', strtotime('first day of last month'));
    $periodStart = $archiveMonth . '-01';
    $periodEnd = date('Y-m-t', strtotime($periodStart));

    $existing = \Theelincon\Rtdb\Db::row('cash_ledger_monthly_archives', $archiveMonth);
    if (is_array($existing) && !empty($existing['month'])) {
        return false;
    }

    $incomeTotal = 0.0;
    $expenseTotal = 0.0;
    $entryCount = 0;
    $siteSummary = [];
    $archiveLedgerIds = [];
    $sitesKeyed = \Theelincon\Rtdb\Db::tableKeyed('cash_ledger_sites');

    foreach (\Theelincon\Rtdb\Db::tableRows('cash_ledger') as $row) {
        $entryDate = (string) ($row['entry_date'] ?? '');
        if ($entryDate < $periodStart || $entryDate > $periodEnd) {
            continue;
        }

        $entryCount++;
        $ledgerId = (int) ($row['id'] ?? 0);
        if ($ledgerId > 0) {
            $archiveLedgerIds[$ledgerId] = true;
        }

        $amount = (float) ($row['amount'] ?? 0);
        $entryType = (string) ($row['entry_type'] ?? '');
        if ($entryType === 'income') {
            $incomeTotal += $amount;
            continue;
        }
        if ($entryType !== 'expense') {
            continue;
        }

        $expenseTotal += $amount;
        $siteId = (int) ($row['site_id'] ?? 0);
        $siteName = '';
        if ($siteId > 0) {
            $siteName = trim((string) ($sitesKeyed[(string) $siteId]['name'] ?? ''));
        }
        $siteText = trim((string) ($row['used_at_site'] ?? ''));

        if ($siteId > 0) {
            $key = 'id:' . $siteId;
            if (!isset($siteSummary[$key])) {
                $siteSummary[$key] = [
                    'site_label' => $siteName !== '' ? $siteName : ('ไซต์งาน #' . $siteId),
                    'site_ref' => $siteId,
                    'expense_total' => 0.0,
                    'expense_count' => 0,
                ];
            }
        } else {
            $label = $siteText !== '' ? $siteText : 'ไม่ระบุไซต์';
            $key = 'text:' . mb_strtolower($label);
            if (!isset($siteSummary[$key])) {
                $siteSummary[$key] = [
                    'site_label' => $label,
                    'site_ref' => 0,
                    'expense_total' => 0.0,
                    'expense_count' => 0,
                ];
            }
        }
        $siteSummary[$key]['expense_total'] += $amount;
        $siteSummary[$key]['expense_count']++;
    }

    $lineItemCount = 0;
    if (count($archiveLedgerIds) > 0) {
        foreach (\Theelincon\Rtdb\Db::tableRows('cash_ledger_lines') as $ln) {
            $lid = (int) ($ln['ledger_id'] ?? 0);
            if ($lid > 0 && isset($archiveLedgerIds[$lid])) {
                $lineItemCount++;
            }
        }
    }

    $siteRows = array_values($siteSummary);
    usort($siteRows, static function (array $a, array $b): int {
        $cmp = ((float) ($b['expense_total'] ?? 0)) <=> ((float) ($a['expense_total'] ?? 0));
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string) ($a['site_label'] ?? ''), (string) ($b['site_label'] ?? ''));
    });

    $incomeTotal = round($incomeTotal, 2);
    $expenseTotal = round($expenseTotal, 2);
    $netTotal = round($incomeTotal - $expenseTotal, 2);
    \Theelincon\Rtdb\Db::setRow('cash_ledger_monthly_archives', $archiveMonth, [
        'month' => $archiveMonth,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'income_total' => $incomeTotal,
        'expense_total' => $expenseTotal,
        'net_total' => $netTotal,
        'entry_count' => $entryCount,
        'line_item_count' => $lineItemCount,
        'site_group_count' => count($siteRows),
        'archived_at' => date('Y-m-d H:i:s'),
    ]);

    \Theelincon\Rtdb\Db::deleteWhereEquals('cash_ledger_monthly_archive_sites', 'month', $archiveMonth);
    foreach ($siteRows as $idx => $siteRow) {
        $rid = $archiveMonth . '-' . str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT);
        \Theelincon\Rtdb\Db::setRow('cash_ledger_monthly_archive_sites', $rid, [
            'id' => $rid,
            'month' => $archiveMonth,
            'site_label' => (string) ($siteRow['site_label'] ?? ''),
            'site_ref' => (int) ($siteRow['site_ref'] ?? 0),
            'expense_total' => round((float) ($siteRow['expense_total'] ?? 0), 2),
            'expense_count' => (int) ($siteRow['expense_count'] ?? 0),
            'archived_at' => date('Y-m-d H:i:s'),
        ]);
    }

    return true;
}
