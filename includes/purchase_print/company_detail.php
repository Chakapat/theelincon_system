<?php

declare(strict_types=1);

if (!function_exists('tnc_doc_address_one_line')) {
    function tnc_doc_address_one_line(mixed $address): string
    {
        return preg_replace('/\s+/u', ' ', trim(str_replace(["\r\n", "\r", "\n"], ' ', (string) $address)));
    }
}

if (!function_exists('tnc_doc_company_detail_html')) {
    /**
     * ที่อยู่ + เลขภาษีในย่อหน้าเดียว — wrap ตามความกว้างคอลัมน์ (เลขภาษีต่อท้ายบรรทัดสุดท้ายของที่อยู่)
     *
     * @param array<string, mixed> $company
     */
    function tnc_doc_company_detail_html(array $company, string $taxLabel = 'เลขผู้เสียภาษี'): string
    {
        $addr = tnc_doc_address_one_line($company['address'] ?? '');
        $tax = trim((string) ($company['tax_id'] ?? ''));
        if ($addr === '' && $tax === '') {
            return '';
        }

        $out = '';
        if ($addr !== '') {
            $out .= htmlspecialchars($addr, ENT_QUOTES, 'UTF-8');
        }
        if ($tax !== '') {
            if ($addr !== '') {
                $out .= ' ';
            }
            $out .= htmlspecialchars($taxLabel, ENT_QUOTES, 'UTF-8') . ': '
                . '<span class="doc-company-tax-id">' . htmlspecialchars($tax, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        return $out;
    }
}
