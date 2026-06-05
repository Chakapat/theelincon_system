<?php

declare(strict_types=1);

/**
 * Flash + audio feedback กลางทั้งระบบ
 * ใช้ร่วมกับ assets/js/tnc-pr-po-audio.js (CRUD audio โหลดจาก navbar)
 */

if (!function_exists('tnc_audio_kinds')) {
    /** @return list<string> */
    function tnc_audio_kinds(): array
    {
        return ['create', 'update', 'approve', 'complete', 'delete'];
    }
}

if (!function_exists('tnc_audio_from_query')) {
    /**
     * แปลง query string หลัง redirect เป็น kind เสียง (หรือ null ถ้าไม่เล่น)
     *
     * @param array<string, mixed> $q
     */
    function tnc_audio_from_query(array $q): ?string
    {
        if (!empty($q['error'])) {
            return null;
        }

        if (!empty($q['deleted']) || !empty($q['cancelled']) || !empty($q['cat_deleted'])) {
            return 'delete';
        }

        if (!empty($q['web_approved']) || !empty($q['approved'])) {
            return 'approve';
        }

        if (!empty($q['rejected']) || !empty($q['web_rejected'])) {
            return 'delete';
        }

        if (!empty($q['payment_saved']) || !empty($q['billing_saved'])) {
            return 'complete';
        }

        if (
            !empty($q['created'])
            || !empty($q['success'])
            || !empty($q['product_added'])
        ) {
            return 'create';
        }

        if (
            !empty($q['updated'])
            || !empty($q['pr_updated'])
            || !empty($q['payment_slips_updated'])
            || !empty($q['invoice_updated'])
            || !empty($q['saved'])
            || !empty($q['cat_saved'])
        ) {
            return 'update';
        }

        return null;
    }
}

if (!function_exists('tnc_ajax_audio_from_action')) {
    /**
     * แปลง action จาก JSON AJAX เป็น kind เสียง
     *
     * @param array<string, mixed> $ctx
     */
    function tnc_ajax_audio_from_action(string $action, array $ctx = []): ?string
    {
        $action = trim($action);
        if ($action === '') {
            return null;
        }

        if ($action === 'site_saved') {
            return (($ctx['mode'] ?? '') === 'create') ? 'create' : 'update';
        }

        if ($action === 'po_created' || $action === 'save_pr') {
            return 'create';
        }

        if (
            $action === 'update_pr'
            || $action === 'update_po_direct'
            || $action === 'update_po_direct_hire'
        ) {
            return 'update';
        }

        if ($action === 'cancel_purchase_order' || $action === 'delete_pr') {
            return 'delete';
        }

        if (
            $action === 'update_po_payment_status'
            || $action === 'receive_po_bill'
            || $action === 'upload_po_payment_slip'
            || $action === 'add_po_payment_slips'
            || $action === 'replace_po_payment_slip'
        ) {
            return 'complete';
        }

        if (
            str_ends_with($action, '_created')
            || (str_ends_with($action, '_saved') && (($ctx['mode'] ?? '') === 'create'))
        ) {
            return 'create';
        }

        if (str_ends_with($action, '_deleted') || str_ends_with($action, '_cancelled')) {
            return 'delete';
        }

        if (str_ends_with($action, '_approved')) {
            return 'approve';
        }

        if (
            str_ends_with($action, '_updated')
            || str_ends_with($action, '_saved')
        ) {
            return 'update';
        }

        if (str_contains($action, 'payment') || str_contains($action, 'billing')) {
            return 'complete';
        }

        if (str_starts_with($action, 'pr_') || str_contains($action, 'po_')) {
            return 'update';
        }

        return null;
    }
}

if (!function_exists('tnc_flash_message_from_query')) {
    /**
     * @param array<string, mixed> $q
     */
    function tnc_flash_message_from_query(array $q): string
    {
        if (!empty($q['error']) && function_exists('tnc_ajax_error_message')) {
            return tnc_ajax_error_message((string) $q['error']);
        }

        $specific = [
            'created' => 'เพิ่มข้อมูลแล้ว',
            'updated' => 'อัปเดตแล้ว',
            'deleted' => 'ลบเรียบร้อย',
            'saved' => 'บันทึกสำเร็จ',
            'success' => 'บันทึกสำเร็จ',
            'product_added' => 'เพิ่มประเภทสินค้า/วัสดุเรียบร้อยแล้ว',
            'cat_saved' => 'บันทึกหัวข้อย่อยเรียบร้อยแล้ว',
            'cat_deleted' => 'ลบหัวข้อย่อยเรียบร้อยแล้ว',
            'payment_saved' => 'บันทึกการชำระเงินแล้ว',
            'billing_saved' => 'บันทึกเลขที่บิลแล้ว',
            'approved' => 'อนุมัติแล้ว',
            'web_approved' => 'อนุมัติแล้ว',
            'cancelled' => 'ยกเลิกเรียบร้อยแล้ว',
            'invoice_updated' => 'อัปเดตใบแจ้งหนี้แล้ว',
        ];

        foreach ($specific as $key => $message) {
            if (!empty($q[$key])) {
                return $message;
            }
        }

        if (function_exists('tnc_ajax_flash_message')) {
            return tnc_ajax_flash_message($q);
        }

        return 'ดำเนินการเรียบร้อย';
    }
}

if (!function_exists('tnc_flash_from_query')) {
    /**
     * @param array<string, mixed> $get
     * @return array{type: string, message: string, audio?: string, html?: string}|null
     */
    function tnc_flash_from_query(array $get): ?array
    {
        if (!empty($get['error'])) {
            return [
                'type' => 'danger',
                'message' => tnc_flash_message_from_query($get),
            ];
        }

        $audio = tnc_audio_from_query($get);
        if ($audio === null) {
            if (!empty($get['line_error'])) {
                return [
                    'type' => 'warning',
                    'message' => 'บันทึกแล้ว แต่แจ้ง LINE ไม่สำเร็จ',
                ];
            }

            return null;
        }

        return [
            'type' => 'success',
            'message' => tnc_flash_message_from_query($get),
            'audio' => $audio,
        ];
    }
}

if (!function_exists('tnc_render_flash')) {
    /**
     * @param array{type: string, message: string, audio?: string, html?: string}|null $flash
     */
    function tnc_render_flash(?array $flash, bool $dismissible = true, string $extraAttr = ''): void
    {
        if ($flash === null) {
            return;
        }

        $type = $flash['type'] ?? 'info';
        $allowed = ['success', 'danger', 'warning', 'info', 'secondary'];
        if (!in_array($type, $allowed, true)) {
            $type = 'info';
        }

        $audio = trim((string) ($flash['audio'] ?? ''));
        if ($audio !== '' && !in_array($audio, tnc_audio_kinds(), true)) {
            $audio = '';
        }

        $audioAttr = $audio !== '' ? ' data-tnc-audio="' . htmlspecialchars($audio, ENT_QUOTES, 'UTF-8') . '"' : '';
        $dismissClass = $dismissible ? ' alert-dismissible fade show' : '';
        $closeBtn = $dismissible
            ? '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
            : '';
        $extra = trim($extraAttr);
        $extraSpaced = $extra !== '' ? ' ' . $extra : '';

        echo '<div class="alert alert-' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . $dismissClass . '" role="alert" data-tnc-flash="1"' . $audioAttr . $extraSpaced . '>';
        echo htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8');
        if (!empty($flash['html'])) {
            echo $flash['html'];
        }
        echo $closeBtn;
        echo '</div>';
    }
}
