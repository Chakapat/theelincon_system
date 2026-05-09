<?php

declare(strict_types=1);

/**
 * Shared invoice reference strings for Tax INV search UIs (autocomplete).
 * Requires Theelincon\Rtdb\Db (e.g. after config/connect_database.php).
 */
if (!function_exists('tnc_invoice_ref_to_short')) {
    function tnc_invoice_ref_to_short(string $invoiceNumber): string
    {
        if (preg_match('/^inv-tnc-(\d{4}-\d{3})$/i', trim($invoiceNumber), $m) === 1) {
            return strtolower($m[1]);
        }

        return '';
    }

    /**
     * @return array{autocomplete: list<string>, options: list<array<string, mixed>>}
     */
    function tnc_invoice_ref_search_catalog(): array
    {
        $autocompleteOptions = [];
        $invoiceSearchOptions = [];
        $allInvoices = \Theelincon\Rtdb\Db::tableRows('invoices');
        \Theelincon\Rtdb\Db::sortRows($allInvoices, 'issue_date', true);
        $customersMap = [];
        foreach (\Theelincon\Rtdb\Db::tableRows('customers') as $cRow) {
            $cid = (int) ($cRow['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $customersMap[$cid] = (string) ($cRow['name'] ?? '');
        }
        foreach ($allInvoices as $invRow) {
            $invId = (int) ($invRow['id'] ?? 0);
            $fullNumber = strtolower(trim((string) ($invRow['invoice_number'] ?? '')));
            if ($fullNumber === '' || $invId <= 0) {
                continue;
            }
            $autocompleteOptions[] = $fullNumber;
            $shortNumber = tnc_invoice_ref_to_short($fullNumber);
            if ($shortNumber !== '') {
                $autocompleteOptions[] = $shortNumber;
            }
            $custName = trim((string) ($customersMap[(int) ($invRow['customer_id'] ?? 0)] ?? ''));
            $issueDateText = trim((string) ($invRow['issue_date'] ?? ''));
            $invoiceSearchOptions[] = [
                'id' => $invId,
                'invoice_number' => strtoupper($fullNumber),
                'customer_name' => $custName,
                'issue_date' => $issueDateText,
                'search_ref' => $shortNumber !== '' ? $shortNumber : $fullNumber,
            ];
        }

        return [
            'autocomplete' => array_values(array_unique($autocompleteOptions)),
            'options' => $invoiceSearchOptions,
        ];
    }
}
