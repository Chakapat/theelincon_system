<?php

declare(strict_types=1);

require_once __DIR__ . '/tnc_tailwind_assets.php';

/**
 * Shared head assets for authenticated app shell pages (Phase 1–6).
 * Bootstrap CSS: layout/grid compat until markup moves to Tailwind utilities.
 * Bootstrap JS: loaded once via tnc_bootstrap_js_tag() at end of body.
 *
 * @param array{
 *   title?: string,
 *   extra_css?: list<string>,
 *   include_bootstrap?: bool,
 *   sarabun_weights?: string,
 *   sweetalert?: bool,
 *   flatpickr?: bool,
 *   extra_head?: string,
 * } $options
 */
function tnc_shell_head(array $options = []): void
{
    $title = (string) ($options['title'] ?? 'Theelincon Office');
    $extraCss = $options['extra_css'] ?? [];
    $includeBootstrap = (bool) ($options['include_bootstrap'] ?? true);
    $sarabunWeights = (string) ($options['sarabun_weights'] ?? '300;400;600;700;800');
    $sweetalert = (bool) ($options['sweetalert'] ?? false);
    $flatpickr = (bool) ($options['flatpickr'] ?? false);
    $extraHead = (string) ($options['extra_head'] ?? '');

    echo '<meta charset="UTF-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>' . "\n";

    if ($includeBootstrap) {
        tnc_bootstrap_css_tag();
    }

    tnc_bootstrap_icons_tag();
    tnc_sarabun_font_tag($sarabunWeights);
    tnc_tailwind_css_tag();
    tnc_app_css_tag();

    foreach ($extraCss as $cssFile) {
        if (!is_string($cssFile) || $cssFile === '') {
            continue;
        }
        echo '<link rel="stylesheet" href="' . htmlspecialchars(tnc_asset_href($cssFile), ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    if ($flatpickr) {
        echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">' . "\n";
    }

    if ($extraHead !== '') {
        echo $extraHead;
    }

    if ($sweetalert) {
        echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>' . "\n";
    }
}

/** Alias for pages under `pages/` — same as tnc_shell_head(). */
function tnc_page_head(array $options = []): void
{
    tnc_shell_head($options);
}
