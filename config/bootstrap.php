<?php

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

if (is_file(ROOT_PATH . '/vendor/autoload.php')) {
    require_once ROOT_PATH . '/vendor/autoload.php';
}

if (!defined('BASE_URL')) {
    $docRoot = @realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $rootPath = @realpath(ROOT_PATH);
    $base = '';
    if ($docRoot !== false && $rootPath !== false && strpos($rootPath, $docRoot) === 0) {
        $base = substr($rootPath, strlen($docRoot));
        $base = str_replace('\\', '/', $base);
        $base = '/' . trim($base, '/');
        if ($base === '/') {
            $base = '';
        }
    }
    define('BASE_URL', $base);
}

if (!function_exists('app_path')) {
    /**
     * Absolute URL path from site root (e.g. /theelincon_system/pages/invoice.php).
     */
    function app_path(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (BASE_URL === '') {
            return '/' . $path;
        }
        return BASE_URL . '/' . $path;
    }
}

if (!function_exists('upload_logos_base_url')) {
    /** Web path to uploads/logos/ (trailing slash). */
    function upload_logos_base_url(): string
    {
        return rtrim(app_path('uploads/logos'), '/') . '/';
    }
}

if (!function_exists('upload_logo_url')) {
    /** Full URL path for one logo file under uploads/logos/. */
    function upload_logo_url(string $filename): string
    {
        $filename = basename(trim($filename));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return '';
        }

        return upload_logos_base_url() . rawurlencode($filename);
    }
}
