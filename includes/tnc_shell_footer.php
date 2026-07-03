<?php

declare(strict_types=1);

require_once __DIR__ . '/tnc_tailwind_assets.php';

/**
 * Standard footer scripts for authenticated app pages (Phase 6).
 *
 * @param array{
 *   bootstrap_js?: bool,
 *   sweetalert?: bool,
 *   extra_scripts?: list<string>,
 * } $options
 */
function tnc_shell_footer(array $options = []): void
{
    if ((bool) ($options['bootstrap_js'] ?? true)) {
        tnc_bootstrap_js_tag();
    }

    if (!empty($options['sweetalert'])) {
        echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>' . "\n";
    }

    $extraScripts = $options['extra_scripts'] ?? [];
    if (!is_array($extraScripts)) {
        return;
    }

    foreach ($extraScripts as $scriptSrc) {
        if (!is_string($scriptSrc) || $scriptSrc === '') {
            continue;
        }
        echo '<script src="' . htmlspecialchars($scriptSrc, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    }
}
