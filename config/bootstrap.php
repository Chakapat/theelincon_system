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

if (!function_exists('tnc_default_company_logo_filename')) {
    function tnc_default_company_logo_filename(): string
    {
        return 'theelincon-logo.png';
    }
}

if (!function_exists('tnc_company_logo_url')) {
    /** Company (employer) logo URL — custom upload first, then bundled default. */
    function tnc_company_logo_url(?string $storedFilename = null): string
    {
        if ($storedFilename !== null && $storedFilename !== '') {
            $basename = basename(str_replace('\\', '/', trim($storedFilename)));
            if ($basename !== '' && $basename !== '.' && $basename !== '..') {
                $uploadAbs = ROOT_PATH . '/uploads/logos/' . $basename;
                if (is_file($uploadAbs)) {
                    return upload_logo_url($basename);
                }
                $assetAbs = ROOT_PATH . '/assets/img/' . $basename;
                if (is_file($assetAbs)) {
                    return app_path('assets/img/' . $basename);
                }
            }
        }

        $bundledRel = 'assets/img/' . tnc_default_company_logo_filename();
        $bundledAbs = ROOT_PATH . '/' . $bundledRel;
        if (is_file($bundledAbs)) {
            return app_path($bundledRel);
        }

        return '';
    }
}

if (!function_exists('tnc_company_logo_light_url')) {
    /** White/light logo for orange navbar and sign-in header. */
    function tnc_company_logo_light_url(): string
    {
        $rel = 'assets/img/theelincon-logo-light.png';
        if (is_file(ROOT_PATH . '/' . $rel)) {
            return app_path($rel);
        }

        return tnc_company_logo_url('');
    }
}
