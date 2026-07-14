<?php

declare(strict_types=1);

/**
 * Flash + audio feedback กลางทั้งระบบ
 * ใช้ร่วมกับ assets/js/tnc-pr-po-audio.js (CRUD audio โหลดจาก navbar)
 *
 * One-shot flash: ค่า feedback ใน query (?success=1, ?error=…, ?deleted=1 ฯลฯ)
 * ถูกย้ายเข้า session แล้ว redirect ไป URL สะอาด — refresh จะไม่เด้งแจ้งเตือนซ้ำ
 */

if (!function_exists('tnc_flash_ephemeral_keys')) {
    /**
     * Query keys ที่เป็น feedback ครั้งเดียว (ไม่ใช่ state ของหน้า เช่น id / open_*)
     *
     * @return list<string>
     */
    function tnc_flash_ephemeral_keys(): array
    {
        return [
            'success',
            'deleted',
            'cancelled',
            'created',
            'updated',
            'saved',
            'name_updated',
            'product_added',
            'cat_saved',
            'cat_deleted',
            'cat_remapped',
            'cat_remap_partial',
            'payment_saved',
            'billing_saved',
            'payment_reverted',
            'payment_slips_updated',
            'approved',
            'web_approved',
            'rejected',
            'web_rejected',
            'error',
            'err',
            'line_error',
            'line_notify',
            'pr_updated',
            'invoice_updated',
            'exceeds_pr',
            'po_number',
            'print_po_id',
            'po_ignored',
            'po_unignored',
            'message',
            'auto_bill',
            'bill_month',
            'bill_id',
            'prs',
            'pos',
            'failed',
        ];
    }
}

if (!function_exists('tnc_flash_extract_from_array')) {
    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    function tnc_flash_extract_from_array(array $query): array
    {
        $flash = [];
        foreach (tnc_flash_ephemeral_keys() as $key) {
            if (array_key_exists($key, $query)) {
                $flash[$key] = $query[$key];
            }
        }

        return $flash;
    }
}

if (!function_exists('tnc_flash_store_query')) {
    /**
     * @param array<string, mixed> $flash
     */
    function tnc_flash_store_query(array $flash): void
    {
        if ($flash === [] || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION['_tnc_flash_query'] = $flash;
    }
}

if (!function_exists('tnc_flash_split_url')) {
    /**
     * แยก ephemeral flash ออกจาก URL → [cleanUrl, flashBag]
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    function tnc_flash_split_url(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['', []];
        }

        $hash = '';
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $hash = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }

        $qPos = strpos($url, '?');
        if ($qPos === false) {
            return [$url . $hash, []];
        }

        $base = substr($url, 0, $qPos);
        $queryString = substr($url, $qPos + 1);
        $query = [];
        parse_str($queryString, $query);
        $flash = tnc_flash_extract_from_array($query);
        foreach (array_keys($flash) as $key) {
            unset($query[$key]);
        }

        $clean = $base;
        if ($query !== []) {
            $clean .= '?' . http_build_query($query);
        }

        return [$clean . $hash, $flash];
    }
}

if (!function_exists('tnc_flash_current_request_url_without')) {
    /**
     * @param list<string> $removeKeys
     */
    function tnc_flash_current_request_url_without(array $removeKeys): string
    {
        $https = (!empty($_SERVER['HTTPS']) && (string) $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $parts = parse_url($uri);
        $path = (string) ($parts['path'] ?? '/');
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach ($removeKeys as $key) {
            unset($query[$key]);
        }
        $out = $scheme . '://' . $host . $path;
        if ($query !== []) {
            $out .= '?' . http_build_query($query);
        }

        return $out;
    }
}

if (!function_exists('tnc_flash_location')) {
    /**
     * Redirect แบบ one-shot flash: ย้าย ephemeral query เข้า session แล้วไป URL สะอาด
     *
     * @return never
     */
    function tnc_flash_location(string $url, int $status = 302): void
    {
        [$clean, $flash] = tnc_flash_split_url($url);
        if ($flash !== []) {
            tnc_flash_store_query($flash);
        }
        if ($clean === '') {
            $clean = '/';
        }
        header('Location: ' . $clean, true, $status);
        exit;
    }
}

if (!function_exists('tnc_flash_bag')) {
    /**
     * Flash bag ของ request นี้ (หลัง hydrate)
     *
     * @return array<string, mixed>
     */
    function tnc_flash_bag(): array
    {
        $bag = $GLOBALS['_tnc_flash_bag'] ?? null;

        return is_array($bag) ? $bag : [];
    }
}

if (!function_exists('tnc_flash_hydrate_get')) {
    /**
     * ดึง flash จาก session ใส่ $_GET ของ request นี้ครั้งเดียว (กัน refresh ซ้ำ)
     */
    function tnc_flash_hydrate_get(): void
    {
        if (!empty($GLOBALS['_tnc_flash_hydrated'])) {
            return;
        }
        $GLOBALS['_tnc_flash_hydrated'] = true;
        $GLOBALS['_tnc_flash_bag'] = [];

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $bag = $_SESSION['_tnc_flash_query'] ?? null;
        unset($_SESSION['_tnc_flash_query']);
        if (!is_array($bag) || $bag === []) {
            return;
        }

        $GLOBALS['_tnc_flash_bag'] = $bag;
        foreach ($bag as $key => $value) {
            $_GET[$key] = $value;
        }
    }
}

if (!function_exists('tnc_flash_bootstrap_request')) {
    /**
     * ถ้า URL มี flash query → ย้ายเข้า session แล้ว 303 ไป URL สะอาด
     * มิฉะนั้น hydrate flash จาก session เข้า $_GET
     */
    function tnc_flash_bootstrap_request(): void
    {
        if (!empty($GLOBALS['_tnc_flash_bootstrapped'])) {
            return;
        }
        $GLOBALS['_tnc_flash_bootstrapped'] = true;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            return;
        }

        $flash = tnc_flash_extract_from_array($_GET);
        if ($flash !== []) {
            tnc_flash_store_query($flash);
            header(
                'Location: ' . tnc_flash_current_request_url_without(array_keys($flash)),
                true,
                303
            );
            exit;
        }

        tnc_flash_hydrate_get();
    }
}

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
