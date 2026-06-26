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

if (!function_exists('tnc_default_company_logo_filename')) {
    function tnc_default_company_logo_filename(): string
    {
        return 'theelincon-logo.png';
    }
}

if (!function_exists('tnc_company_logo_url')) {
    /** Company (employer) logo URL — uses bundled brand asset, then uploads/logos fallback. */
    function tnc_company_logo_url(?string $storedFilename = null): string
    {
        $bundledRel = 'assets/img/' . tnc_default_company_logo_filename();
        $bundledAbs = ROOT_PATH . '/' . $bundledRel;
        if (is_file($bundledAbs)) {
            return app_path($bundledRel);
        }

        if ($storedFilename !== null && $storedFilename !== '') {
            $basename = basename(str_replace('\\', '/', trim($storedFilename)));
            if ($basename !== '' && $basename !== '.' && $basename !== '..') {
                $uploadAbs = ROOT_PATH . '/uploads/logos/' . $basename;
                if (is_file($uploadAbs)) {
                    return upload_logo_url($basename);
                }
            }
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

if (!function_exists('normalized_user_role')) {
    /**
     * แปลง role ในฐานข้อมูล/เซสชันเป็นรหัสมาตรฐานตัวพิมพ์ใหญ่ (รองรับค่าเก่า admin, Accounting, user).
     */
    function normalized_user_role(?string $role): string
    {
        $r = strtoupper(trim((string) $role));

        return $r === '' ? 'USER' : $r;
    }
}

if (!function_exists('session_role_normalized')) {
    function session_role_normalized(): string
    {
        return normalized_user_role(isset($_SESSION['role']) ? (string) $_SESSION['role'] : null);
    }
}

if (!function_exists('user_is_admin_role')) {
    /** CEO หรือ ADMIN (รวมค่าเก่า admin) */
    function user_is_admin_role(): bool
    {
        $n = session_role_normalized();

        return $n === 'CEO' || $n === 'ADMIN';
    }
}

if (!function_exists('user_is_admin_only_role')) {
    /**
     * เฉพาะ ADMIN เท่านั้น (ไม่รวม CEO, ACCOUNTING, USER)
     * ใช้กับหน้าที่อ่อนไหว เช่น สดย่อย, Audit log, ตั้งค่า LINE
     */
    function user_is_admin_only_role(): bool
    {
        return session_role_normalized() === 'ADMIN';
    }
}

if (!function_exists('user_is_accounting_role')) {
    function user_is_accounting_role(): bool
    {
        return session_role_normalized() === 'ACCOUNTING';
    }
}

if (!function_exists('user_is_finance_role')) {
    /** CEO, ADMIN หรือ ACCOUNTING — สิทธิ์ฝ่ายการเงิน/อนุมัติ */
    function user_is_finance_role(): bool
    {
        $n = session_role_normalized();

        return $n === 'CEO' || $n === 'ADMIN' || $n === 'ACCOUNTING';
    }
}

if (!function_exists('user_can_pr_web_decide')) {
    /** ADMIN / ACCOUNTING (หรือตาม matrix pr.approve) — อนุมัติ/ไม่อนุมัติ PR บนเว็บ */
    function user_can_pr_web_decide(): bool
    {
        return function_exists('user_can') ? user_can('pr.approve') : (user_is_admin_only_role() || user_is_accounting_role());
    }
}

if (!function_exists('user_can_edit_invoice')) {
    /** แก้ไขใบแจ้งหนี้ (ตาม matrix invoice.edit) */
    function user_can_edit_invoice(): bool
    {
        return function_exists('user_can') ? user_can('invoice.edit') : user_is_finance_role();
    }
}

if (!function_exists('h')) {
    /** Escape for HTML text nodes and attributes (UTF-8). */
    function h(?string $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tnc_sanitize_api_row')) {
    /**
     * Strip sensitive fields before returning RTDB rows to the browser (get_data, etc.).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    function tnc_sanitize_api_row(array $row): array
    {
        foreach (['password', 'password_hash', 'line_approval_token', 'csrf', '_csrf'] as $key) {
            unset($row[$key]);
        }

        return $row;
    }
}

if (!function_exists('tnc_require_finance_role')) {
    /** @return never on failure */
    function tnc_require_finance_role(): void
    {
        if (!user_is_finance_role()) {
            http_response_code(403);
            exit('Access Denied: เฉพาะฝ่ายการเงิน/ผู้ดูแลระบบเท่านั้น');
        }
    }
}

if (!function_exists('tnc_require_admin_role')) {
    /** @return never on failure */
    function tnc_require_admin_role(): void
    {
        if (!user_is_admin_role()) {
            http_response_code(403);
            exit('Access Denied: เฉพาะผู้ดูแลระบบเท่านั้น');
        }
    }
}

if (!function_exists('member_user_code_prefix')) {
    /** Prefix รหัสพนักงานอัตโนมัติ เช่น emptnc000 */
    function member_user_code_prefix(): string
    {
        return 'emptnc';
    }
}

if (!function_exists('next_sequential_member_user_code')) {
    /**
     * รหัสถัดไปจากเลขท้ายที่มีในระบบ (เช่น emptnc000–emptnc005 → emptnc006)
     *
     * @param array<int, array<string, mixed>> $userRows
     */
    function next_sequential_member_user_code(array $userRows, ?string $prefix = null): string
    {
        $prefix = $prefix ?? member_user_code_prefix();
        $maxNum = -1;
        $maxWidth = 3;
        $re = '/^' . preg_quote($prefix, '/') . '(\d+)$/iu';
        foreach ($userRows as $row) {
            $code = trim((string) ($row['user_code'] ?? ''));
            if ($code === '' || !preg_match($re, $code, $m)) {
                continue;
            }
            $n = (int) $m[1];
            $width = strlen($m[1]);
            if ($width > $maxWidth) {
                $maxWidth = $width;
            }
            if ($n > $maxNum) {
                $maxNum = $n;
            }
        }
        $next = $maxNum + 1;
        if ($maxNum < 0) {
            $next = 0;
        }
        $outW = max($maxWidth, strlen((string) $next));

        return $prefix . str_pad((string) $next, $outW, '0', STR_PAD_LEFT);
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
