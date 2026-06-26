<?php

declare(strict_types=1);

require_once __DIR__ . '/document_color_runtime.php';

if (!function_exists('tnc_doc_color_document_print_css_url')) {
    function tnc_doc_color_document_print_css_url(): string
    {
        $abs = dirname(__DIR__) . '/assets/css/document-print.css';
        $ver = is_file($abs) ? (string) filemtime($abs) : (string) time();
        $rev = preg_replace('/[^a-zA-Z0-9._-]/', '', tnc_doc_color_config_revision());

        return app_path('assets/css/document-print.css') . '?v=' . rawurlencode($ver . '-' . $rev);
    }
}

if (!function_exists('tnc_doc_color_render_style_tag')) {
    function tnc_doc_color_render_style_tag(): void
    {
        $rev = htmlspecialchars(tnc_doc_color_config_revision(), ENT_QUOTES, 'UTF-8');
        echo '<meta name="tnc-doc-color-rev" content="' . $rev . '">';
        echo '<style id="tnc-doc-color-vars">' . tnc_doc_color_css_string() . '</style>';
    }
}

if (!function_exists('tnc_doc_color_render_print_style_tag')) {
    /** ซ้ำ CSS variables ใน media print — ให้ preview/พิมพ์เห็นสีจาก config */
    function tnc_doc_color_render_print_style_tag(): void
    {
        echo '<style media="print" id="tnc-doc-color-vars-print">' . tnc_doc_color_css_string() . '</style>';
    }
}

if (!function_exists('tnc_doc_color_render_head_assets')) {
    /** Style vars + document-print.css (cache bust ตาม filemtime + config revision) */
    function tnc_doc_color_render_head_assets(): void
    {
        tnc_doc_color_render_style_tag();
        echo '<link rel="stylesheet" href="'
            . htmlspecialchars(tnc_doc_color_document_print_css_url(), ENT_QUOTES, 'UTF-8')
            . '">';
    }
}
