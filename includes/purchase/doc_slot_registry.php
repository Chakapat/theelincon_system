<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

if (!function_exists('tnc_purchase_doc_slot_registry_path')) {
    function tnc_purchase_doc_slot_registry_path(): string
    {
        return __FILE__;
    }
}

if (!function_exists('tnc_purchase_doc_ym')) {
    function tnc_purchase_doc_ym(?string $dateYmd = null): string
    {
        if ($dateYmd !== null && $dateYmd !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateYmd, $m) === 1) {
            return substr($m[1], 2, 2) . $m[2];
        }

        return date('ym');
    }
}

if (!function_exists('tnc_purchase_doc_tail_from_number')) {
    function tnc_purchase_doc_tail_from_number(string $docNumber, string $prefix): ?int
    {
        $docNumber = trim($docNumber);
        if ($docNumber === '' || strncmp($docNumber, $prefix, strlen($prefix)) !== 0) {
            return null;
        }
        $tail = substr($docNumber, -3);
        if (!preg_match('/^\d{3}$/', $tail)) {
            return null;
        }

        return (int) $tail;
    }
}

if (!function_exists('tnc_purchase_shared_max_tail')) {
    /** Max tail used in PR-TNC + PO-TNC for a month (shared pool; cancelled docs still count). */
    function tnc_purchase_shared_max_tail(string $ym): int
    {
        $prPrefix = 'PR-TNC-' . $ym . '-';
        $poPrefix = 'PO-TNC-' . $ym . '-';
        $max = 0;

        foreach (Db::tableRows('purchase_requests') as $row) {
            $tail = tnc_purchase_doc_tail_from_number((string) ($row['pr_number'] ?? ''), $prPrefix);
            if ($tail !== null) {
                $max = max($max, $tail);
            }
        }

        foreach (Db::tableRows('purchase_orders') as $row) {
            $tail = tnc_purchase_doc_tail_from_number((string) ($row['po_number'] ?? ''), $poPrefix);
            if ($tail !== null) {
                $max = max($max, $tail);
            }
        }

        return $max;
    }
}

if (!function_exists('tnc_purchase_direct_po_max_tail')) {
    function tnc_purchase_direct_po_max_tail(string $ym): int
    {
        $prefix = 'PO-D-TNC-' . $ym . '-';
        $max = 0;
        foreach (Db::tableRows('purchase_orders') as $row) {
            $tail = tnc_purchase_doc_tail_from_number((string) ($row['po_number'] ?? ''), $prefix);
            if ($tail !== null) {
                $max = max($max, $tail);
            }
        }

        return $max;
    }
}

if (!function_exists('tnc_purchase_format_doc_number')) {
    function tnc_purchase_format_doc_number(string $prefix, string $ym, int $tail): string
    {
        return $prefix . $ym . '-' . str_pad((string) max(1, $tail), 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('tnc_purchase_next_pr_number')) {
    function tnc_purchase_next_pr_number(?string $ym = null): string
    {
        $ym = $ym ?? tnc_purchase_doc_ym();
        $tail = tnc_purchase_shared_max_tail($ym) + 1;

        return tnc_purchase_format_doc_number('PR-TNC-', $ym, $tail);
    }
}

if (!function_exists('tnc_purchase_parse_pr_po_pair')) {
    /**
     * @return array{ok:bool, ym?:string, tail?:int, po_number?:string, error?:string}
     */
    function tnc_purchase_parse_pr_po_pair(array $prRow): array
    {
        $prNumber = trim((string) ($prRow['pr_number'] ?? ''));
        if ($prNumber === '') {
            return ['ok' => false, 'error' => 'missing_pr_number'];
        }

        if (preg_match('/^PR-TNC-(\d{4})-(\d{3})$/', $prNumber, $m) !== 1) {
            return ['ok' => false, 'error' => 'invalid_pr_number'];
        }

        $ym = $m[1];
        $tail = (int) $m[2];
        if ($tail <= 0) {
            return ['ok' => false, 'error' => 'invalid_pr_number'];
        }

        return [
            'ok' => true,
            'ym' => $ym,
            'tail' => $tail,
            'po_number' => tnc_purchase_format_doc_number('PO-TNC-', $ym, $tail),
        ];
    }
}

if (!function_exists('tnc_purchase_po_number_from_pr')) {
    function tnc_purchase_po_number_from_pr(array $prRow): string
    {
        $parsed = tnc_purchase_parse_pr_po_pair($prRow);
        if (empty($parsed['ok']) || !isset($parsed['po_number'])) {
            throw new InvalidArgumentException((string) ($parsed['error'] ?? 'invalid_pr_number'));
        }

        return (string) $parsed['po_number'];
    }
}

if (!function_exists('tnc_purchase_po_number_taken')) {
    function tnc_purchase_po_number_taken(string $poNumber, int $ignorePoId = 0): bool
    {
        $poNumber = trim($poNumber);
        if ($poNumber === '') {
            return false;
        }

        $found = Db::findFirst('purchase_orders', static function (array $row) use ($poNumber, $ignorePoId): bool {
            if ($ignorePoId > 0 && (int) ($row['id'] ?? 0) === $ignorePoId) {
                return false;
            }
            if (trim((string) ($row['po_number'] ?? '')) !== $poNumber) {
                return false;
            }

            return strtolower(trim((string) ($row['status'] ?? ''))) !== 'cancelled';
        });

        return $found !== null;
    }
}

if (!function_exists('tnc_purchase_next_direct_po_number')) {
    function tnc_purchase_next_direct_po_number(?string $ym = null): string
    {
        $ym = $ym ?? tnc_purchase_doc_ym();
        $tail = tnc_purchase_direct_po_max_tail($ym) + 1;

        return tnc_purchase_format_doc_number('PO-D-TNC-', $ym, $tail);
    }
}
