<?php

declare(strict_types=1);

if (!defined('TNC_DOC_ITEMS_PER_PAGE')) {
    define('TNC_DOC_ITEMS_PER_PAGE', 15);
}

if (!defined('TNC_DOC_PAGE_BODY_MM')) {
    define('TNC_DOC_PAGE_BODY_MM', 277.0);
}

if (!defined('TNC_DOC_TABLE_HEAD_MM')) {
    define('TNC_DOC_TABLE_HEAD_MM', 8.0);
}

if (!defined('TNC_DOC_PAGE_INDICATOR_MM')) {
    define('TNC_DOC_PAGE_INDICATOR_MM', 6.0);
}

if (!defined('TNC_DOC_ROW_BASE_MM')) {
    define('TNC_DOC_ROW_BASE_MM', 7.5);
}

if (!defined('TNC_DOC_ROW_LINE_MM')) {
    define('TNC_DOC_ROW_LINE_MM', 4.2);
}

if (!defined('TNC_DOC_DESC_CHARS_PER_LINE')) {
    define('TNC_DOC_DESC_CHARS_PER_LINE', 42);
}

if (!function_exists('tnc_doc_estimate_item_row_height_mm')) {
    /**
     * ประมาณความสูงแถวตารางจากความยาวรายละเอียด (รองรับข้อความหลายบรรทัด)
     *
     * @param array<string, mixed> $item
     */
    function tnc_doc_estimate_item_row_height_mm(array $item, array $options = []): float
    {
        $baseMm = (float) ($options['row_base_mm'] ?? TNC_DOC_ROW_BASE_MM);
        $lineMm = (float) ($options['row_line_mm'] ?? TNC_DOC_ROW_LINE_MM);
        $charsPerLine = (int) ($options['desc_chars_per_line'] ?? TNC_DOC_DESC_CHARS_PER_LINE);
        if ($charsPerLine < 12) {
            $charsPerLine = TNC_DOC_DESC_CHARS_PER_LINE;
        }

        $description = trim((string) ($item['description'] ?? ''));
        if ((int) ($item['vat_exempt'] ?? 0) === 1) {
            $description .= ' (ไม่คิด VAT)';
        }

        $lineCount = 1;
        if ($description !== '') {
            $lineCount = max(1, (int) ceil(mb_strlen($description, 'UTF-8') / $charsPerLine));
        }

        return $baseMm + (($lineCount - 1) * $lineMm);
    }
}

if (!function_exists('tnc_doc_estimate_text_block_height_mm')) {
    function tnc_doc_estimate_text_block_height_mm(string $text, float $charsPerLine = 50.0, float $headingMm = 8.0, float $lineMm = 4.2): float
    {
        $text = trim($text);
        if ($text === '') {
            return 0.0;
        }
        if ($charsPerLine < 12.0) {
            $charsPerLine = 50.0;
        }

        $lines = max(1, (int) ceil(mb_strlen($text, 'UTF-8') / $charsPerLine));

        return $headingMm + ($lines * $lineMm) + 4.0;
    }
}

if (!function_exists('tnc_doc_po_first_page_overhead_mm')) {
    /** @param array<string, mixed> $ctx */
    function tnc_doc_po_first_page_overhead_mm(array $ctx): float
    {
        $mm = !empty($ctx['has_logo']) ? 52.0 : 38.0;
        if (!empty($ctx['has_site'])) {
            $mm += 11.0;
        }
        $mm += 11.0;
        if (!empty($ctx['has_supplier_address'])) {
            $mm += 12.0;
        }
        if (!empty($ctx['has_reference_pr'])) {
            $mm += 5.0;
        }
        if (!empty($ctx['has_qt_header'])) {
            $mm += 8.0;
        }

        return $mm + 4.0;
    }
}

if (!function_exists('tnc_doc_po_footer_height_mm')) {
    /** @param array<string, mixed> $ctx */
    function tnc_doc_po_footer_height_mm(array $ctx): float
    {
        $mm = 78.0;
        $mm += tnc_doc_estimate_text_block_height_mm((string) ($ctx['po_note_po'] ?? ''));
        $mm += tnc_doc_estimate_text_block_height_mm((string) ($ctx['po_note_qt'] ?? ''));

        if (!empty($ctx['has_deductions'])) {
            $mm += 5.0;
        }
        if (!empty($ctx['has_wht'])) {
            $mm += 5.0;
        }
        if (!empty($ctx['has_retention'])) {
            $mm += 5.0;
        }

        $adjCount = (int) ($ctx['adjustment_count'] ?? 0);
        if ($adjCount > 0) {
            $mm += $adjCount * 5.0;
        }

        return $mm;
    }
}

if (!function_exists('tnc_doc_pr_first_page_overhead_mm')) {
    /** @param array<string, mixed> $ctx */
    function tnc_doc_pr_first_page_overhead_mm(array $ctx): float
    {
        $mm = !empty($ctx['has_logo']) ? 52.0 : 38.0;
        $mm += 14.0;
        if (!empty($ctx['has_creator_line'])) {
            $mm += 10.0;
        }
        if (!empty($ctx['has_qt_header'])) {
            $mm += 8.0;
        }

        return $mm + 4.0;
    }
}

if (!function_exists('tnc_doc_pr_footer_height_mm')) {
    /** @param array<string, mixed> $ctx */
    function tnc_doc_pr_footer_height_mm(array $ctx): float
    {
        $mm = 92.0;
        $mm += tnc_doc_estimate_text_block_height_mm((string) ($ctx['details_text'] ?? ''), 48.0);

        if (!empty($ctx['has_vat_line'])) {
            $mm += 5.0;
        }

        return $mm;
    }
}

if (!function_exists('tnc_doc_table_body_budget_mm')) {
    function tnc_doc_table_body_budget_mm(int $pageIndex, bool $isLastPage, array $options): float
    {
        $pageBodyMm = (float) ($options['page_body_mm'] ?? TNC_DOC_PAGE_BODY_MM);
        $tableHeadMm = (float) ($options['table_head_mm'] ?? TNC_DOC_TABLE_HEAD_MM);
        $pageIndicatorMm = (float) ($options['page_indicator_mm'] ?? TNC_DOC_PAGE_INDICATOR_MM);
        $firstPageOverheadMm = (float) ($options['first_page_overhead_mm'] ?? 85.0);
        $continuationOverheadMm = (float) ($options['continuation_page_overhead_mm'] ?? 14.0);
        $footerMm = $isLastPage ? (float) ($options['footer_mm'] ?? 0.0) : 0.0;

        $overheadMm = $pageIndex === 0 ? $firstPageOverheadMm : $continuationOverheadMm;
        $indicatorMm = (!$isLastPage || !empty($options['reserve_page_indicator'])) ? $pageIndicatorMm : 0.0;

        return max(20.0, $pageBodyMm - $overheadMm - $tableHeadMm - $indicatorMm - $footerMm);
    }
}

if (!function_exists('tnc_doc_sum_item_rows_height_mm')) {
    /** @param array<int, array<string, mixed>> $items */
    function tnc_doc_sum_item_rows_height_mm(array $items, array $options = []): float
    {
        $sum = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sum += tnc_doc_estimate_item_row_height_mm($item, $options);
        }

        return $sum;
    }
}

if (!function_exists('tnc_doc_paginate_items_by_height')) {
    /**
     * แบ่งรายการตามงบความสูง (mm) แทนจำนวนแถวคงที่
     *
     * @param array<int, array<string, mixed>> $items
     * @return list<array<int, array<string, mixed>>>
     */
    function tnc_doc_paginate_items_by_height(array $items, array $options = []): array
    {
        if ($items === []) {
            return [[]];
        }

        $options['reserve_page_indicator'] = true;

        $chunks = [];
        $current = [];
        $currentHeight = 0.0;
        $pageIndex = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $rowHeight = tnc_doc_estimate_item_row_height_mm($item, $options);
            $budget = tnc_doc_table_body_budget_mm($pageIndex, false, $options);

            if ($current !== [] && ($currentHeight + $rowHeight) > $budget) {
                $chunks[] = $current;
                $current = [];
                $currentHeight = 0.0;
                $pageIndex++;
            }

            $current[] = $item;
            $currentHeight += $rowHeight;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        if ($chunks === []) {
            return [[]];
        }

        while (true) {
            $lastIdx = count($chunks) - 1;
            $lastChunk = $chunks[$lastIdx];
            if ($lastChunk === []) {
                array_pop($chunks);
                if ($chunks === []) {
                    return [[]];
                }
                continue;
            }

            $footerBudget = tnc_doc_table_body_budget_mm($lastIdx, true, $options);
            $rowsHeight = tnc_doc_sum_item_rows_height_mm($lastChunk, $options);

            if ($rowsHeight <= $footerBudget || count($lastChunk) <= 1) {
                break;
            }

            $movedItem = array_pop($chunks[$lastIdx]);
            if ($chunks[$lastIdx] === []) {
                array_pop($chunks);
            }
            $chunks[] = [$movedItem];
        }

        return array_values($chunks);
    }
}

if (!function_exists('tnc_doc_paginate_items')) {
    /**
     * แบ่งรายการสินค้า/บริการเป็นหน้า
     *
     * ค่าเริ่มต้นใช้งบความสูง (mm) — ตั้ง 'mode' => 'count' เพื่อแบ่งตามจำนวนแถว
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $options
     * @return list<array<int, array<string, mixed>>>
     */
    function tnc_doc_paginate_items(array $items, array $options = []): array
    {
        $mode = (string) ($options['mode'] ?? 'height');
        if ($mode === 'count') {
            $perPage = (int) ($options['per_page'] ?? TNC_DOC_ITEMS_PER_PAGE);
            if ($perPage < 1) {
                $perPage = TNC_DOC_ITEMS_PER_PAGE;
            }
            if ($items === []) {
                return [[]];
            }

            return array_values(array_chunk($items, $perPage, false));
        }

        return tnc_doc_paginate_items_by_height($items, $options);
    }
}

if (!function_exists('tnc_doc_page_indicator_label')) {
    function tnc_doc_page_indicator_label(int $page, int $total): string
    {
        if ($total <= 1) {
            return '';
        }

        return 'หน้า ' . $page . '/' . $total;
    }
}
