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
