<?php

declare(strict_types=1);

require_once __DIR__ . '/tnc_shell_head.php';
require_once __DIR__ . '/tnc_tailwind_assets.php';

/**
 * Standard head for Invoice module pages (Phase 4).
 *
 * @param array{
 *   title?: string,
 *   extra_css?: list<string>,
 *   include_invoice_ui?: bool,
 *   document_color?: bool,
 *   document_color_style?: bool,
 *   doc_view_shell?: bool,
 *   sales_print?: bool,
 *   sweetalert?: bool,
 *   minimal?: bool,
 *   sarabun_weights?: string,
 *   extra_head?: string,
 *   include_bootstrap?: bool,
 * } $options
 */
function tnc_invoice_head(array $options = []): void
{
    $title = (string) ($options['title'] ?? 'Invoice | THEELIN CON');
    $minimal = (bool) ($options['minimal'] ?? false);
    $includeInvoiceUi = (bool) ($options['include_invoice_ui'] ?? !$minimal);

    $extraCss = $options['extra_css'] ?? [];
    if (!is_array($extraCss)) {
        $extraCss = [];
    }

    if ($includeInvoiceUi) {
        $invoiceUi = 'assets/css/invoice-ui.css';
        if (!in_array($invoiceUi, $extraCss, true)) {
            array_unshift($extraCss, $invoiceUi);
        }
    }

    if (!empty($options['doc_view_shell'])) {
        $docShell = 'assets/css/doc-view-shell.css';
        if (!in_array($docShell, $extraCss, true)) {
            $extraCss[] = $docShell;
        }
    }

    if (!empty($options['sales_print'])) {
        $salesPrint = 'assets/css/invoice-sales-print.css';
        if (!in_array($salesPrint, $extraCss, true)) {
            $extraCss[] = $salesPrint;
        }
    }

    $extraHead = (string) ($options['extra_head'] ?? '');
    if (!empty($options['document_color']) || !empty($options['document_color_style'])) {
        ob_start();
        require_once __DIR__ . '/document_color_css.php';
        if (!empty($options['document_color_style'])) {
            tnc_doc_color_render_style_tag();
        } else {
            tnc_doc_color_render_head_assets();
        }
        $docColorOut = ob_get_clean();
        if (is_string($docColorOut)) {
            $extraHead .= $docColorOut;
        }
    }

    if ($minimal) {
        echo '<meta charset="UTF-8">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
        echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>' . "\n";

        if ((bool) ($options['include_bootstrap'] ?? true)) {
            tnc_bootstrap_css_tag();
        }

        tnc_bootstrap_icons_tag();
        tnc_sarabun_font_tag((string) ($options['sarabun_weights'] ?? '400;500;600;700'));

        foreach ($extraCss as $cssFile) {
            if (!is_string($cssFile) || $cssFile === '') {
                continue;
            }
            echo '<link rel="stylesheet" href="' . htmlspecialchars(tnc_asset_href($cssFile), ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }

        if ($extraHead !== '') {
            echo $extraHead;
        }

        if (!empty($options['sweetalert'])) {
            echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>' . "\n";
        }

        return;
    }

    tnc_shell_head([
        'title' => $title,
        'extra_css' => $extraCss,
        'sarabun_weights' => (string) ($options['sarabun_weights'] ?? '300;400;600;700;800'),
        'sweetalert' => (bool) ($options['sweetalert'] ?? false),
        'extra_head' => $extraHead,
        'include_bootstrap' => (bool) ($options['include_bootstrap'] ?? true),
    ]);
}
