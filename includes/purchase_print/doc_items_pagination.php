<?php

declare(strict_types=1);

if (!defined('TNC_DOC_ITEMS_PER_PAGE')) {
    define('TNC_DOC_ITEMS_PER_PAGE', 15);
}

if (!function_exists('tnc_doc_paginate_items')) {
    /**
     * แบ่งรายการสินค้า/บริการเป็นหน้า — สูงสุด 15 รายการต่อหน้า (Doc Breakpoint)
     *
     * @param array<int, array<string, mixed>> $items
     * @return list<array<int, array<string, mixed>>>
     */
    function tnc_doc_paginate_items(array $items, int $perPage = TNC_DOC_ITEMS_PER_PAGE): array
    {
        if ($perPage < 1) {
            $perPage = TNC_DOC_ITEMS_PER_PAGE;
        }
        if ($items === []) {
            return [[]];
        }

        return array_values(array_chunk($items, $perPage, false));
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
