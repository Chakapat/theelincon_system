<?php

declare(strict_types=1);

if (!function_exists('tnc_purchase_table_skeleton_tr')) {
    function tnc_purchase_table_skeleton_tr(int $colspan, string $variant = 'pr'): string
    {
        if ($variant === 'po') {
            $grid = '2rem 1.2fr 1fr 1.2fr 0.9fr 5rem';
            $cells = '<span class="tnc-table-skeleton-line sm"></span>'
                . '<span class="tnc-table-skeleton-line md"></span>'
                . '<span class="tnc-table-skeleton-line lg"></span>'
                . '<span class="tnc-table-skeleton-line md"></span>'
                . '<span class="tnc-table-skeleton-line md"></span>'
                . '<span class="tnc-table-skeleton-actions">'
                . '<span class="tnc-table-skeleton-dot"></span>'
                . '<span class="tnc-table-skeleton-dot"></span>'
                . '</span>';
        } else {
            $grid = '2rem 1.2fr 1fr 0.7fr 0.8fr 5rem';
            $cells = '<span class="tnc-table-skeleton-line sm"></span>'
                . '<span class="tnc-table-skeleton-line md"></span>'
                . '<span class="tnc-table-skeleton-line lg"></span>'
                . '<span class="tnc-table-skeleton-line sm"></span>'
                . '<span class="tnc-table-skeleton-line md"></span>'
                . '<span class="tnc-table-skeleton-actions">'
                . '<span class="tnc-table-skeleton-dot"></span>'
                . '<span class="tnc-table-skeleton-dot"></span>'
                . '</span>';
        }

        $gridAttr = htmlspecialchars($grid, ENT_QUOTES, 'UTF-8');
        $rows = '';
        for ($i = 0; $i < 3; ++$i) {
            $rows .= '<div class="tnc-table-skeleton-row" style="grid-template-columns:' . $gridAttr . '">' . $cells . '</div>';
        }

        return '<tr class="tnc-table-skeleton-row">'
            . '<td colspan="' . $colspan . '">'
            . '<div class="tnc-table-skeleton-wrap" aria-label="Loading table rows">' . $rows . '</div>'
            . '</td></tr>';
    }
}
