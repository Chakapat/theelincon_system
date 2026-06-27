<?php

declare(strict_types=1);

if (!function_exists('tnc_invoice_is_cancelled')) {
    function tnc_invoice_is_cancelled(array $row): bool
    {
        return tnc_doc_row_is_cancelled($row);
    }
}

if (!function_exists('tnc_tax_invoice_is_cancelled')) {
    function tnc_tax_invoice_is_cancelled(array $row): bool
    {
        return tnc_doc_row_is_cancelled($row);
    }
}

if (!function_exists('tnc_doc_row_is_cancelled')) {
    function tnc_doc_row_is_cancelled(array $row): bool
    {
        $status = strtolower(trim((string) ($row['status'] ?? '')));

        return in_array($status, ['cancelled', 'canceled', 'void'], true);
    }
}

if (!function_exists('tnc_doc_cancellation_reason')) {
    function tnc_doc_cancellation_reason(array $row): string
    {
        return trim((string) ($row['cancellation_reason'] ?? ''));
    }
}

if (!function_exists('tnc_invoice_cancel_fields')) {
    /** @return array<string, mixed> */
    function tnc_invoice_cancel_fields(string $reason, ?int $userId = null): array
    {
        if ($userId === null) {
            $userId = (int) ($_SESSION['user_id'] ?? 0);
        }

        return [
            'status' => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'cancelled_by' => $userId,
            'cancellation_reason' => trim($reason),
        ];
    }
}

if (!function_exists('tnc_invoice_cancel_linked_tax_invoices')) {
    function tnc_invoice_cancel_linked_tax_invoices(int $invoiceId, string $reason, ?int $userId = null): void
    {
        if ($invoiceId <= 0) {
            return;
        }
        if (!class_exists(\Theelincon\Rtdb\Db::class)) {
            return;
        }
        $fields = tnc_invoice_cancel_fields($reason, $userId);
        foreach (\Theelincon\Rtdb\Db::filter('tax_invoices', static function (array $r) use ($invoiceId): bool {
            return isset($r['invoice_id']) && (int) $r['invoice_id'] === $invoiceId;
        }) as $taxRow) {
            if (!is_array($taxRow) || tnc_tax_invoice_is_cancelled($taxRow)) {
                continue;
            }
            $taxId = (int) ($taxRow['id'] ?? 0);
            if ($taxId <= 0) {
                continue;
            }
            $tpk = \Theelincon\Rtdb\Db::pkForLogicalId('tax_invoices', $taxId);
            \Theelincon\Rtdb\Db::mergeRow('tax_invoices', $tpk, $fields);
        }
    }
}
