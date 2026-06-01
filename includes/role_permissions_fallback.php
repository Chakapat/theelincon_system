<?php

declare(strict_types=1);

/**
 * Legacy fallback when includes/role_permissions.php is missing on server (ป้องกัน HTTP 500 ทั้งระบบ)
 * ใช้ค่า default เดิม — หน้าตั้งค่าสิทธิ์จะไม่พร้อมใ until ไฟล์หลักถูก deploy
 */

if (!function_exists('user_can')) {
    function user_can(string $permission): bool
    {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        $role = session_role_normalized();

        if ($role === 'CEO' || $role === 'ADMIN') {
            return true;
        }

        if ($role === 'ACCOUNTING') {
            $deny = ['pr.delete', 'pr.send_line', 'po.delete', 'invoice.delete', 'invoice.tax_delete'];

            return !in_array($permission, $deny, true);
        }

        $userAllow = ['pr.create', 'pr.update'];

        return in_array($permission, $userAllow, true);
    }
}

if (!function_exists('tnc_require_can')) {
    function tnc_require_can(string $permission, string $message = 'ไม่มีสิทธิ์ดำเนินการนี้'): void
    {
        if (!user_can($permission)) {
            http_response_code(403);
            exit($message);
        }
    }
}
