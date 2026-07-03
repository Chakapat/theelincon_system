<?php

declare(strict_types=1);

/**
 * Shared UI render helpers (Phase 2).
 * CSS lives in assets/css/tnc-components.css (built into tailwind.css).
 */

if (!function_exists('tnc_ui_badge')) {
    /**
     * @param 'orange'|'success'|'warning'|'danger'|'muted'|'info' $variant
     */
    function tnc_ui_badge(string $label, string $variant = 'muted', string $extraClass = ''): string
    {
        $allowed = ['orange', 'success', 'warning', 'danger', 'muted', 'info'];
        if (!in_array($variant, $allowed, true)) {
            $variant = 'muted';
        }

        $classes = trim('tnc-badge tnc-badge--' . $variant . ' ' . $extraClass);

        return '<span class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
}

if (!function_exists('tnc_ui_page_head')) {
    /**
     * Standard list/form page header (matches .tnc-page-head in tnc-components.css).
     *
     * @param array{
     *   kicker?: string,
     *   title: string,
     *   icon?: string,
     *   actions_html?: string|null,
     *   class?: string,
     *   title_tag?: string,
     * } $options
     */
    function tnc_ui_page_head(array $options): void
    {
        $kicker = trim((string) ($options['kicker'] ?? ''));
        $title = (string) ($options['title'] ?? '');
        $icon = trim((string) ($options['icon'] ?? ''));
        $actionsHtml = $options['actions_html'] ?? null;
        $class = trim((string) ($options['class'] ?? 'mb-3'));
        $titleTag = strtolower(trim((string) ($options['title_tag'] ?? 'h1')));
        if (!in_array($titleTag, ['h1', 'h2', 'h3'], true)) {
            $titleTag = 'h1';
        }

        echo '<header class="tnc-page-head' . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') : '') . '">';
        echo '<div>';
        if ($kicker !== '') {
            echo '<p class="tnc-page-kicker">' . htmlspecialchars($kicker, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        echo '<' . $titleTag . ' class="tnc-list-title">';
        if ($icon !== '') {
            echo '<span class="tnc-list-title__icon me-2" aria-hidden="true"><i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i></span>';
        }
        echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        echo '</' . $titleTag . '>';
        echo '</div>';
        if (is_string($actionsHtml) && $actionsHtml !== '') {
            echo '<div class="d-flex flex-wrap gap-2">' . $actionsHtml . '</div>';
        }
        echo '</header>';
    }
}

if (!function_exists('tnc_ui_modal_shell_class')) {
    /** Bootstrap modal wrapper class for consistent polish. */
    function tnc_ui_modal_shell_class(string $extra = ''): string
    {
        return trim('modal fade tnc-modal ' . $extra);
    }
}

if (!function_exists('tnc_ui_purchase_page_head')) {
    /**
     * Purchase list/form page header (.purchase-page-head in purchase-ui.css).
     *
     * @param array{
     *   kicker?: string,
     *   title: string,
     *   icon?: string,
     *   actions_html?: string|null,
     *   class?: string,
     * } $options
     */
    function tnc_ui_purchase_page_head(array $options): void
    {
        $kicker = trim((string) ($options['kicker'] ?? ''));
        $title = (string) ($options['title'] ?? '');
        $icon = trim((string) ($options['icon'] ?? ''));
        $actionsHtml = $options['actions_html'] ?? null;
        $class = trim((string) ($options['class'] ?? 'mb-3'));

        echo '<header class="purchase-page-head' . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') : '') . '">';
        echo '<div>';
        if ($kicker !== '') {
            echo '<p class="purchase-page-kicker">' . htmlspecialchars($kicker, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        echo '<h1 class="purchase-list-title">';
        if ($icon !== '') {
            echo '<span class="po-list-title__icon me-2" aria-hidden="true"><i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i></span>';
        }
        echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        echo '</h1>';
        echo '</div>';
        if (is_string($actionsHtml) && $actionsHtml !== '') {
            echo '<div class="d-flex flex-wrap gap-2">' . $actionsHtml . '</div>';
        }
        echo '</header>';
    }
}
