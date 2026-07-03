<?php

declare(strict_types=1);

if (!function_exists('app_path')) {
    require_once __DIR__ . '/../config/foundation.php';
}

function tnc_asset_version(string $relativePath): string
{
    $root = dirname(__DIR__);
    $path = $root . '/' . ltrim($relativePath, '/');
    $mtime = @filemtime($path);

    return is_int($mtime) && $mtime > 0 ? (string) $mtime : (string) time();
}

function tnc_asset_href(string $relativePath, bool $withVersion = true): string
{
    $href = app_path($relativePath);
    if ($withVersion) {
        $href .= '?v=' . rawurlencode(tnc_asset_version($relativePath));
    }

    return $href;
}

function tnc_tailwind_css_tag(): void
{
    if (defined('TNC_TAILWIND_LOADED')) {
        return;
    }
    define('TNC_TAILWIND_LOADED', true);
    echo '<link rel="stylesheet" href="' . htmlspecialchars(tnc_asset_href('assets/css/tailwind.css'), ENT_QUOTES, 'UTF-8') . '">' . "\n";
}

function tnc_bootstrap_icons_tag(): void
{
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">' . "\n";
}

function tnc_sarabun_font_tag(string $weights = '400;600;700;800'): void
{
    $url = 'https://fonts.googleapis.com/css2?family=Sarabun:wght@' . rawurlencode($weights) . '&display=swap';
    echo '<link href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" rel="stylesheet">' . "\n";
}

function tnc_bootstrap_css_tag(): void
{
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n";
}

function tnc_app_css_tag(): void
{
    if (defined('TNC_APP_CSS_LOADED')) {
        return;
    }
    define('TNC_APP_CSS_LOADED', true);
    echo '<link rel="stylesheet" href="' . htmlspecialchars(tnc_asset_href('assets/css/tnc-app.css'), ENT_QUOTES, 'UTF-8') . '">' . "\n";
}

function tnc_bootstrap_js_tag(): void
{
    if (defined('TNC_BOOTSTRAP_JS_LOADED')) {
        return;
    }
    define('TNC_BOOTSTRAP_JS_LOADED', true);
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>' . "\n";
}
