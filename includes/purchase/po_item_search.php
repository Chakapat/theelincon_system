<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

require_once dirname(__DIR__) . '/purchase_print/vat_print_summary.php';
require_once dirname(__DIR__) . '/hire_line_items.php';

if (!function_exists('tnc_po_item_search_tokens')) {
    /**
     * @return list<string>
     */
    function tnc_po_item_search_tokens(string $query): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if ($query === '') {
            return [];
        }
        $parts = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? array_values(array_filter($parts, static fn (string $t): bool => $t !== '')) : [];
    }
}

if (!function_exists('tnc_po_item_description_matches')) {
    /**
     * Partial match (contains) — every token must appear in description (AND).
     */
    function tnc_po_item_description_matches(string $description, array $tokens): bool
    {
        if ($tokens === []) {
            return false;
        }
        foreach ($tokens as $token) {
            if (mb_stripos($description, $token, 0, 'UTF-8') === false) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('tnc_po_item_discount_label')) {
    /**
     * @param array<string, mixed> $item
     */
    function tnc_po_item_discount_label(array $item): string
    {
        $discIn = trim((string) ($item['discount_input'] ?? ''));
        if ($discIn !== '') {
            return $discIn;
        }
        $discAmt = (float) ($item['discount_amount'] ?? 0);
        if ($discAmt > 0) {
            return number_format($discAmt, 2, '.', '');
        }
        $discType = (string) ($item['discount_type'] ?? 'amount');
        $discValue = (float) ($item['discount_value'] ?? 0);
        if ($discValue > 0) {
            return $discType === 'percent'
                ? (rtrim(rtrim(number_format($discValue, 4, '.', ''), '0'), '.') . '%')
                : number_format($discValue, 2, '.', '');
        }

        return '';
    }
}

if (!function_exists('tnc_po_item_search_row_unit_price')) {
    /**
     * @param array<string, mixed> $item
     */
    function tnc_po_item_search_row_unit_price(array $item, string $orderType): float
    {
        if ($orderType === 'hire') {
            $parts = tnc_hire_item_material_labor($item);
            $unitPrice = round($parts['material'] + $parts['labor'], 2);
            if ($unitPrice <= 0) {
                $unitPrice = (float) ($item['unit_price'] ?? 0);
            }

            return $unitPrice;
        }

        return (float) ($item['unit_price'] ?? 0);
    }
}

if (!function_exists('tnc_po_item_search_issue_ymd')) {
    function tnc_po_item_search_issue_ymd(array $po): string
    {
        $issue = trim((string) ($po['issue_date'] ?? ''));
        if ($issue !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) {
            return $issue;
        }
        if ($issue !== '') {
            $ts = strtotime($issue);
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }
        $created = trim((string) ($po['created_at'] ?? ''));
        if ($created !== '') {
            $ts = strtotime($created);
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }

        return '';
    }
}

if (!function_exists('tnc_po_item_search')) {
    /**
     * Search PO line items by description (partial / multi-word AND).
     *
     * @param array<string, mixed> $options
     *
     * @return array{q: string, tokens: list<string>, count: int, rows: list<array<string, mixed>>, truncated: bool}
     */
    function tnc_po_item_search(string $query, array $options = []): array
    {
        $tokens = tnc_po_item_search_tokens($query);
        $limit = (int) ($options['limit'] ?? 200);
        if ($limit < 1) {
            $limit = 200;
        }
        if ($limit > 500) {
            $limit = 500;
        }

        if ($tokens === []) {
            return [
                'q' => trim($query),
                'tokens' => [],
                'count' => 0,
                'rows' => [],
                'truncated' => false,
            ];
        }

        $suppliers = Db::tableKeyed('suppliers');
        $siteNameById = [];
        foreach (Db::tableRows('sites') as $site) {
            $sid = (int) ($site['id'] ?? 0);
            if ($sid > 0) {
                $siteNameById[$sid] = trim((string) ($site['name'] ?? ''));
            }
        }
        $prById = [];
        foreach (Db::tableRows('purchase_requests') as $pr) {
            $pid = (int) ($pr['id'] ?? 0);
            if ($pid > 0) {
                $prById[$pid] = $pr;
            }
        }

        $poItemsByPoId = tnc_purchase_po_items_group_by_po_id();
        $rows = [];
        $truncated = false;
        $viewBase = app_path('pages/purchase/purchase-order-view.php');

        foreach (Db::tableRows('purchase_orders') as $po) {
            if (!is_array($po)) {
                continue;
            }
            if (Purchase::isWorkOrder($po)) {
                continue;
            }
            $status = strtolower(trim((string) ($po['status'] ?? 'ordered')));
            if ($status === 'cancelled') {
                continue;
            }

            $poId = (int) ($po['id'] ?? 0);
            if ($poId <= 0) {
                continue;
            }

            $orderType = trim((string) ($po['order_type'] ?? 'purchase'));
            if (!in_array($orderType, ['purchase', 'hire'], true)) {
                $orderType = 'purchase';
            }

            $prIdForItems = (int) ($po['pr_id'] ?? 0);
            $prForItems = ($prIdForItems > 0 && isset($prById[$prIdForItems])) ? $prById[$prIdForItems] : null;
            $poItems = $poItemsByPoId[$poId] ?? [];
            if ($poItems === []) {
                $poItems = tnc_purchase_po_load_items($poId, $po, is_array($prForItems) ? $prForItems : null);
            }

            $issueYmd = tnc_po_item_search_issue_ymd($po);
            $siteDisplay = tnc_purchase_po_resolve_site_name($po, is_array($prForItems) ? $prForItems : null, $siteNameById);
            $supplierDisplay = $orderType === 'hire'
                ? trim((string) ($po['contractor_name'] ?? ''))
                : trim((string) (($suppliers[(string) ($po['supplier_id'] ?? '')]['name'] ?? '')));
            $poNumber = trim((string) ($po['po_number'] ?? ''));

            foreach ($poItems as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ($orderType === 'hire' && tnc_hire_line_is_group($item)) {
                    continue;
                }

                $description = trim((string) ($item['description'] ?? ''));
                if ($description === '' || !tnc_po_item_description_matches($description, $tokens)) {
                    continue;
                }

                $itemId = (int) ($item['id'] ?? 0);
                $qty = (float) ($item['quantity'] ?? 0);
                $unit = trim((string) ($item['unit'] ?? ''));
                $unitPrice = tnc_po_item_search_row_unit_price($item, $orderType);
                $discountLabel = $orderType === 'hire' ? '' : tnc_po_item_discount_label($item);
                $lineTotal = (float) ($item['total'] ?? 0);

                $rows[] = [
                    'po_id' => $poId,
                    'po_number' => $poNumber,
                    'issue_date' => $issueYmd,
                    'issue_date_display' => $issueYmd !== '' ? date('d/m/Y', strtotime($issueYmd)) : '',
                    'site_display' => $siteDisplay,
                    'supplier_display' => $supplierDisplay,
                    'order_type' => $orderType,
                    'item_id' => $itemId,
                    'description' => $description,
                    'quantity' => $qty,
                    'quantity_display' => number_format($qty, 2, '.', ','),
                    'unit' => $unit,
                    'unit_price' => $unitPrice,
                    'unit_price_display' => number_format($unitPrice, 2, '.', ','),
                    'discount_label' => $discountLabel,
                    'line_total' => $lineTotal,
                    'line_total_display' => number_format($lineTotal, 2, '.', ','),
                    'view_url' => $viewBase . '?id=' . $poId,
                ];

                if (count($rows) >= $limit) {
                    $truncated = true;
                    break 2;
                }
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $da = (string) ($a['issue_date'] ?? '');
            $db = (string) ($b['issue_date'] ?? '');
            $daKey = $da !== '' ? $da : '0000-00-00';
            $dbKey = $db !== '' ? $db : '0000-00-00';
            $cmp = strcmp($dbKey, $daKey);
            if ($cmp !== 0) {
                return $cmp;
            }
            $poCmp = ((int) ($b['po_id'] ?? 0)) <=> ((int) ($a['po_id'] ?? 0));
            if ($poCmp !== 0) {
                return $poCmp;
            }

            return ((int) ($b['item_id'] ?? 0)) <=> ((int) ($a['item_id'] ?? 0));
        });

        return [
            'q' => trim($query),
            'tokens' => $tokens,
            'count' => count($rows),
            'rows' => $rows,
            'truncated' => $truncated,
        ];
    }
}
