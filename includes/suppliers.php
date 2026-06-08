<?php

declare(strict_types=1);

if (!function_exists('tnc_supplier_has_payment_info')) {
    /**
     * @param array<string, mixed> $row
     */
    function tnc_supplier_has_payment_info(array $row): bool
    {
        return trim((string) ($row['bank_name'] ?? '')) !== ''
            || trim((string) ($row['bank_account_name'] ?? '')) !== ''
            || trim((string) ($row['bank_account_number'] ?? '')) !== '';
    }
}

if (!function_exists('tnc_supplier_payment_lines')) {
    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    function tnc_supplier_payment_lines(array $row): array
    {
        $bank = trim((string) ($row['bank_name'] ?? ''));
        $accName = trim((string) ($row['bank_account_name'] ?? ''));
        $accNo = trim((string) ($row['bank_account_number'] ?? ''));

        $lines = ['บัญชีรับโอน (ผู้ขาย)'];
        if ($bank !== '') {
            $lines[] = 'ธนาคาร: ' . $bank;
        }
        if ($accName !== '') {
            $lines[] = 'ชื่อบัญชี: ' . $accName;
        }
        if ($accNo !== '') {
            $lines[] = 'เลขที่บัญชี: ' . $accNo;
        }

        return $lines;
    }
}

if (!function_exists('tnc_supplier_payment_note_text')) {
    /**
     * @param array<string, mixed> $row
     */
    function tnc_supplier_payment_note_text(array $row): string
    {
        if (!tnc_supplier_has_payment_info($row)) {
            return '';
        }

        return implode("\n", tnc_supplier_payment_lines($row));
    }
}
