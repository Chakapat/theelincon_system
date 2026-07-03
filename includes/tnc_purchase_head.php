<?php

declare(strict_types=1);

require_once __DIR__ . '/tnc_shell_head.php';

/**
 * Standard head for Purchase module pages (Phase 3).
 * Loads Tailwind + tnc-app + purchase-ui.css; optional doc colors & flatpickr.
 *
 * @param array{
 *   title?: string,
 *   extra_css?: list<string>,
 *   document_color?: bool,
 *   flatpickr?: bool,
 *   sweetalert?: bool,
 *   sarabun_weights?: string,
 *   extra_head?: string,
 *   include_bootstrap?: bool,
 * } $options
 */
function tnc_purchase_head(array $options = []): void
{
    $extraCss = $options['extra_css'] ?? [];
    if (!is_array($extraCss)) {
        $extraCss = [];
    }

    $purchaseCss = 'assets/css/purchase-ui.css';
    if (!in_array($purchaseCss, $extraCss, true)) {
        array_unshift($extraCss, $purchaseCss);
    }

    $extraHead = (string) ($options['extra_head'] ?? '');
    if (!empty($options['document_color'])) {
        ob_start();
        require_once __DIR__ . '/document_color_css.php';
        if (function_exists('tnc_doc_color_render_head_assets')) {
            tnc_doc_color_render_head_assets();
        }
        $docColorOut = ob_get_clean();
        if (is_string($docColorOut)) {
            $extraHead .= $docColorOut;
        }
    }

    tnc_shell_head([
        'title' => (string) ($options['title'] ?? 'Purchase | THEELIN CON'),
        'extra_css' => $extraCss,
        'sarabun_weights' => (string) ($options['sarabun_weights'] ?? '400;600;700;800'),
        'flatpickr' => (bool) ($options['flatpickr'] ?? false),
        'sweetalert' => (bool) ($options['sweetalert'] ?? false),
        'extra_head' => $extraHead,
        'include_bootstrap' => (bool) ($options['include_bootstrap'] ?? true),
    ]);
}
