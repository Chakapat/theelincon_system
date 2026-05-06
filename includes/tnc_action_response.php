<?php

declare(strict_types=1);

/**
 * รองรับฟอร์ม AJAX: ส่ง _tnc_ajax=1 ใน POST หรือ header X-Tnc-Ajax: 1
 * แทน redirect จะตอบ JSON { ok, message, query, url }
 */

if (!function_exists('tnc_ajax_form_requested')) {
    function tnc_ajax_form_requested(): bool
    {
        if (isset($_POST['_tnc_ajax']) && (string) $_POST['_tnc_ajax'] === '1') {
            return true;
        }
        if (isset($_GET['_tnc_ajax']) && (string) $_GET['_tnc_ajax'] === '1') {
            return true;
        }
        $h = isset($_SERVER['HTTP_X_TNC_AJAX']) ? strtolower((string) $_SERVER['HTTP_X_TNC_AJAX']) : '';

        return $h === '1' || $h === 'true';
    }
}

if (!function_exists('tnc_ajax_flash_message')) {
    function tnc_ajax_flash_message(array $q): string
    {
        if (isset($q['error'])) {
            return tnc_ajax_error_message((string) $q['error']);
        }
        if (isset($q['deleted'])) {
            return 'ลบเรียบร้อย';
        }
        if (isset($q['need_deleted'])) {
            return 'ลบใบต้องการซื้อแล้ว';
        }
        if (isset($q['approved'])) {
            return 'อนุมัติใบขอซื้อแล้ว';
        }
        if (isset($q['rejected'])) {
            return 'ปฏิเสธใบขอซื้อแล้ว';
        }
        if (isset($q['payment_saved'])) {
            return 'บันทึกการชำระเงินแล้ว';
        }
        if (isset($q['updated'])) {
            return 'อัปเดตเรียบร้อย';
        }
        if (isset($q['success']) || isset($q['saved'])) {
            return 'บันทึกสำเร็จ';
        }
        if (isset($q['created'])) {
            return 'เพิ่มข้อมูลแล้ว';
        }
        if (isset($q['updated'])) {
            return 'อัปเดตแล้ว';
        }
        if (isset($q['invoice_updated'])) {
            return 'อัปเดตใบแจ้งหนี้แล้ว';
        }
        if (isset($q['line_error'])) {
            return 'บันทึกแล้ว แต่แจ้ง LINE ไม่สำเร็จ';
        }

        return 'ดำเนินการเรียบร้อย';
    }
}

if (!function_exists('tnc_ajax_error_message')) {
    function tnc_ajax_error_message(string $code): string
    {
        $map = [
            'in_use' => 'ไม่สามารถลบได้ — ข้อมูลถูกใช้งานอยู่',
            'hire_invalid' => 'กรอกข้อมูลสัญญาจ้างไม่ครบหรือไม่ถูกต้อง',
            'no_items' => 'ต้องมีอย่างน้อย 1 รายการ',
            'upload_failed' => 'อัปโหลดไฟล์ไม่สำเร็จ',
            'upload_type' => 'ชนิดไฟล์ไม่รองรับ',
            'invalid_pr' => 'ไม่พบใบขอซื้อ',
            'po_supplier' => 'กรุณาเลือกผู้ขาย',
            'po_exists' => 'มี PO จากใบขอซื้อนี้แล้ว',
            'pr_not_found' => 'ไม่พบใบขอซื้อ',
            'contract' => 'ข้อมูลสัญญาไม่ถูกต้อง',
            'need_site' => 'กรุณาเลือกไซต์งาน',
            'need_no_items' => 'ต้องมีรายการสินค้า',
            'invalid_need' => 'ไม่พบใบต้องการซื้อ',
            'invalid' => 'ข้อมูลไม่ถูกต้อง',
            'site' => 'กรุณาเลือกไซต์',
            'need_lines' => 'ต้องมีรายการบิล',
            'payment' => 'ไม่พบข้อมูลการชำระ',
            'payment_slip_required' => 'ต้องแนบสลิป',
            'code_gen' => 'สร้างรหัสพนักงานซ้ำ กรุณาลองใหม่',
            'invalid_role' => 'สิทธิ์ไม่ถูกต้อง',
            'password_required' => 'ต้องกรอกรหัสผ่าน',
            'confirm_password_required' => 'กรุณากรอกรหัสผ่านของคุณเพื่อยืนยันการลบ',
            'confirm_password_invalid' => 'รหัสผ่านไม่ถูกต้อง',
            'invalid_name' => 'ชื่อไม่ถูกต้อง',
        ];

        return $map[$code] ?? ('ไม่สามารถดำเนินการได้ (' . $code . ')');
    }
}

if (!function_exists('tnc_action_redirect')) {
    /**
     * ถ้าเป็น AJAX ตอบ JSON ไม่เปลี่ยนหน้า — มิฉะนั้น redirect ตามปกติ
     *
     * @return never
     */
    function tnc_action_redirect(string $url): void
    {
        if (tnc_ajax_form_requested()) {
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $q);
            $ok = !isset($q['error']);
            $message = tnc_ajax_flash_message($q);
            header('Content-Type: application/json; charset=UTF-8');
            if (!$ok) {
                http_response_code(422);
            }
            echo json_encode([
                'ok' => $ok,
                'message' => $message,
                'query' => $q,
                'url' => $url,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        header('Location: ' . $url);
        exit;
    }
}
