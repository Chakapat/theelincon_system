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

if (!function_exists('tnc_ui_back_previous_button')) {
    /**
     * Previous-page back control (NOT home). Uses history.back(); optional same-origin fallback.
     *
     * @param array{
     *   label?: string,
     *   fallback?: string,
     *   class?: string,
     *   no_print?: bool,
     *   attrs?: array<string, string>,
     * } $options
     */
    function tnc_ui_back_previous_button(array $options = []): string
    {
        $label = trim((string) ($options['label'] ?? 'ย้อนกลับ'));
        if ($label === '') {
            $label = 'ย้อนกลับ';
        }
        $fallback = trim((string) ($options['fallback'] ?? ''));
        $extraClass = trim((string) ($options['class'] ?? ''));
        $noPrint = !empty($options['no_print']);
        $extraAttrs = $options['attrs'] ?? [];
        if (!is_array($extraAttrs)) {
            $extraAttrs = [];
        }

        $classes = trim(
            'btn btn-outline-secondary rounded-pill tnc-btn-back-previous'
            . ($noPrint ? ' no-print' : '')
            . ($extraClass !== '' ? ' ' . $extraClass : '')
        );

        $onclick = 'try{'
            . 'var f=(this.getAttribute("data-fallback")||"").trim();'
            . 'if(window.history&&window.history.length>1){window.history.back();return false;}'
            . 'if(f){window.location.href=f;return false;}'
            . 'if(document.referrer){try{var r=new URL(document.referrer,window.location.href);'
            . 'if(r.origin===window.location.origin&&r.href!==window.location.href){window.location.href=r.href;return false;}}catch(e){}}'
            . '}catch(err){}return false;';

        $attrs = 'type="button"'
            . ' class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-tnc-back-previous="1"'
            . ' onclick="' . htmlspecialchars($onclick, ENT_QUOTES, 'UTF-8') . '"'
            . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"';

        if ($fallback !== '') {
            $attrs .= ' data-fallback="' . htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8') . '"';
        }

        foreach ($extraAttrs as $attrName => $attrVal) {
            $name = trim((string) $attrName);
            if ($name === '' || !preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $name)) {
                continue;
            }
            $attrs .= ' ' . $name . '="' . htmlspecialchars((string) $attrVal, ENT_QUOTES, 'UTF-8') . '"';
        }

        return '<button ' . $attrs . '>'
            . '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</button>';
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
     *   back_previous?: bool,
     *   back_fallback?: string,
     *   back_label?: string,
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
        $backPrevious = array_key_exists('back_previous', $options)
            ? (bool) $options['back_previous']
            : true;

        $actionBits = [];
        if ($backPrevious) {
            $actionBits[] = tnc_ui_back_previous_button([
                'fallback' => (string) ($options['back_fallback'] ?? ''),
                'label' => (string) ($options['back_label'] ?? 'ย้อนกลับ'),
                'no_print' => true,
            ]);
        }
        if (is_string($actionsHtml) && $actionsHtml !== '') {
            $actionBits[] = $actionsHtml;
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
        if ($actionBits !== []) {
            echo '<div class="d-flex flex-wrap gap-2 align-items-center">' . implode('', $actionBits) . '</div>';
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
     *   back_previous?: bool,
     *   back_fallback?: string,
     *   back_label?: string,
     * } $options
     */
    function tnc_ui_purchase_page_head(array $options): void
    {
        $kicker = trim((string) ($options['kicker'] ?? ''));
        $title = (string) ($options['title'] ?? '');
        $icon = trim((string) ($options['icon'] ?? ''));
        $actionsHtml = $options['actions_html'] ?? null;
        $class = trim((string) ($options['class'] ?? 'mb-3'));
        $backPrevious = array_key_exists('back_previous', $options)
            ? (bool) $options['back_previous']
            : true;

        $actionBits = [];
        if ($backPrevious) {
            $actionBits[] = tnc_ui_back_previous_button([
                'fallback' => (string) ($options['back_fallback'] ?? ''),
                'label' => (string) ($options['back_label'] ?? 'ย้อนกลับ'),
                'no_print' => true,
            ]);
        }
        if (is_string($actionsHtml) && $actionsHtml !== '') {
            $actionBits[] = $actionsHtml;
        }

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
        if ($actionBits !== []) {
            echo '<div class="d-flex flex-wrap gap-2 align-items-center">' . implode('', $actionBits) . '</div>';
        }
        echo '</header>';
    }
}
