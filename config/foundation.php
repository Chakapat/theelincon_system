<?php
/**
 * โหลดพื้นฐานแอป: ROOT_PATH, BASE_URL, autoload, ฟังก์ชัน path/โลโก้, CSRF
 * (ไม่เกี่ยวกับ Bootstrap CSS/UI)
 */

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

if (!function_exists('csrf_token')) {
    /** Session-bound CSRF token (generate once per session). */
    function csrf_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    /** Hidden input for HTML forms (requires active session). */
    function csrf_field(): void
    {
        $t = csrf_token();
        echo '<input type="hidden" name="_csrf" value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_verify_request')) {
    /**
     * Validates token from POST body, GET query, or X-CSRF-Token header (JSON/API).
     */
    function csrf_verify_request(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $expected = $_SESSION['_csrf_token'] ?? '';
        if ($expected === '' || !is_string($expected)) {
            return false;
        }
        $provided = '';
        if (isset($_POST['_csrf'])) {
            $provided = (string) $_POST['_csrf'];
        } elseif (isset($_GET['_csrf'])) {
            $provided = (string) $_GET['_csrf'];
        } elseif (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $provided = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
        }

        return $provided !== '' && hash_equals($expected, $provided);
    }
}

if (!function_exists('user_can_edit_invoice')) {
    /** แอดมินหรือ Accounting แก้ไขใบแจ้งหนี้ได้ */
    function user_can_edit_invoice(): bool
    {
        $r = (string) ($_SESSION['role'] ?? '');
        return $r === 'admin' || $r === 'Accounting';
    }
}

if (!function_exists('format_thai_doc_date')) {
    /**
     * แสดงวันที่เอกสารแบบ วัน/เดือน/ปี (รับค่า Y-m-d หรือสตริงที่ strtotime แปลงได้).
     */
    function format_thai_doc_date(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-') {
            return '-';
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }

        return date('d/m/Y', $ts);
    }
}
