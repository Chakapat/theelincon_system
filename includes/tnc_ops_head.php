<?php

declare(strict_types=1);

require_once __DIR__ . '/tnc_shell_head.php';
require_once __DIR__ . '/tnc_tailwind_assets.php';

/**
 * Standard head for Ops module pages (Phase 5): cash ledger, sites, stock, reports, org extras.
 *
 * @param array{
 *   title?: string,
 *   extra_css?: list<string>,
 *   include_ops_ui?: bool,
 *   cash_ledger_ui?: bool,
 *   cash_print?: bool,
 *   site_hub?: bool,
 *   site_spending?: bool,
 *   vat_report?: bool,
 *   vat_print?: bool,
 *   line_notify?: bool,
 *   doc_color_config?: bool,
 *   po_payment?: bool,
 *   document_color?: bool,
 *   document_color_style?: bool,
 *   flatpickr?: bool,
 *   sweetalert?: bool,
 *   minimal?: bool,
 *   sarabun_weights?: string,
 *   extra_head?: string,
 *   include_bootstrap?: bool,
 * } $options
 */
function tnc_ops_head(array $options = []): void
{
    $title = (string) ($options['title'] ?? 'THEELIN CON');
    $minimal = (bool) ($options['minimal'] ?? false);
    $includeOpsUi = (bool) ($options['include_ops_ui'] ?? !$minimal);
    $needsOpsUiShared = $includeOpsUi
        || !empty($options['site_spending'])
        || !empty($options['vat_report']);

    $extraCss = $options['extra_css'] ?? [];
    if (!is_array($extraCss)) {
        $extraCss = [];
    }

    $moduleCssMap = [
        'include_ops_ui' => 'assets/css/ops-ui.css',
        'cash_ledger_ui' => 'assets/css/cash-ledger-ui.css',
        'cash_print' => 'assets/css/cash-ledger-print.css',
        'site_hub' => 'assets/css/site-hub.css',
        'site_spending' => 'assets/css/site-spending-report.css',
        'vat_report' => 'assets/css/vat-report-ui.css',
        'vat_print' => 'assets/css/vat-report-print.css',
        'line_notify' => 'assets/css/line-notify-ui.css',
        'doc_color_config' => 'assets/css/doc-color-config.css',
        'po_payment' => 'assets/css/po-payment-document.css',
    ];

    if ($needsOpsUiShared) {
        $opsUi = $moduleCssMap['include_ops_ui'];
        if (!in_array($opsUi, $extraCss, true)) {
            array_unshift($extraCss, $opsUi);
        }
    }

    foreach ($moduleCssMap as $flag => $cssFile) {
        if ($flag === 'include_ops_ui') {
            continue;
        }
        if (!empty($options[$flag]) && !in_array($cssFile, $extraCss, true)) {
            $extraCss[] = $cssFile;
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
        'sarabun_weights' => (string) ($options['sarabun_weights'] ?? '300;400;600;700'),
        'flatpickr' => (bool) ($options['flatpickr'] ?? false),
        'sweetalert' => (bool) ($options['sweetalert'] ?? false),
        'extra_head' => $extraHead,
        'include_bootstrap' => (bool) ($options['include_bootstrap'] ?? true),
    ]);
}
