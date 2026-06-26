<?php

declare(strict_types=1);

if (!function_exists('tnc_purchase_po_list_error_message')) {
    function tnc_purchase_po_list_error_message(string $code): string
    {
        return match ($code) {
            'upload_type' => 'ไฟล์แนบต้องเป็นรูปภาพหรือ PDF (JPG, PNG, WEBP, GIF, PDF)',
            'upload_failed' => 'อัปโหลดรูปหลักฐานไม่สำเร็จ กรุณาลองใหม่',
            'payment_slip_required' => 'ต้องแนบรูปหลักฐานก่อนเปลี่ยนสถานะเป็น จ่ายแล้ว',
            'cash_paid_by_required' => 'กรุณาระบุ «จ่ายโดย» เมื่อเลือกชำระด้วยเงินสด',
            'site_budget_exceeded' => 'งบไซต์ไม่พอ — ไม่สามารถออก PO ได้ (เกินวงเงินรวมของไซต์)',
            'site_budget_cat_exceeded' => 'งบหมวดไม่พอ — ไม่สามารถออก PO ได้ (เกินวงเงินหมวดที่กำหนด)',
            'invalid' => 'ไม่พบใบสั่งซื้อ หรือข้อมูลไม่ถูกต้อง',
            'not_found' => 'ไม่พบใบสั่งซื้อ',
            'po_cancelled' => 'ใบสั่งซื้อนี้ถูกยกเลิกแล้ว ไม่สามารถดำเนินการนี้ได้',
            'already_cancelled' => 'ใบสั่งซื้อนี้ยกเลิกไปแล้ว',
            'po_paid' => 'ใบสั่งซื้อนี้สถานะการจ่ายเป็น «จ่ายแล้ว» ไม่สามารถแก้ไข ยกเลิก หรือลบได้',
            'contract_po_not_payable' => 'WO สัญญาจ้างไม่ใช่ใบสั่งจ่าย — ใช้ «ออก PO สั่งจ่าย» สำหรับงวด/ครั้งที่ต้องโอนเงิน',
            'billing_required' => 'กรุณากรอกเลขที่บิลซื้อและวันที่บนบิลให้ครบถ้วน',
            'billing_amount_invalid' => 'ยอดเงินรวมและยอด VAT ต้องไม่เป็นค่าว่างหรือติดลบ',
            default => 'เกิดข้อผิดพลาดในการจัดการใบสั่งซื้อ กรุณาลองใหม่',
        };
    }
}

if (!function_exists('tnc_purchase_po_payment_print_links_html')) {
    /**
     * @param array<string, mixed> $get
     */
    function tnc_purchase_po_payment_print_links_html(array $get): string
    {
        $printPoIdSaved = (int) ($get['print_po_id'] ?? 0);
        if ($printPoIdSaved <= 0) {
            return '';
        }
        $poAutoprintBase = htmlspecialchars(
            app_path('pages/purchase/purchase-order-view.php') . '?id=' . $printPoIdSaved,
            ENT_QUOTES,
            'UTF-8'
        );
        $poAutoprintPoUrl = $poAutoprintBase . '&print_mode=po&autoprint=1';
        $poAutoprintSlipUrl = $poAutoprintBase . '&print_mode=slip&autoprint=1';
        $poAutoprintBothUrl = $poAutoprintBase . '&print_mode=both&autoprint=1';
        $poAutoprintAllUrl = $poAutoprintBase . '&print_mode=all&autoprint=1';

        return '<div class="mt-2 small">'
            . '<span class="text-muted">พิมพ์อัตโนมัติ:</span> '
            . '<a href="' . $poAutoprintPoUrl . '" class="alert-link fw-semibold">1. เฉพาะใบสั่งซื้อ</a>'
            . ' <span class="text-muted">·</span> '
            . '<a href="' . $poAutoprintSlipUrl . '" class="alert-link fw-semibold">2. เฉพาะสลิป</a>'
            . ' <span class="text-muted">·</span> '
            . '<a href="' . $poAutoprintBothUrl . '" class="alert-link fw-semibold">3. ใบสั่งซื้อ + สลิป</a>'
            . ' <span class="text-muted">·</span> '
            . '<a href="' . $poAutoprintAllUrl . '" class="alert-link fw-semibold">4. PR + PO + สลิป/แนบ</a>'
            . '</div>';
    }
}

if (!function_exists('tnc_purchase_po_list_flash')) {
    /**
     * @param array<string, mixed> $get
     * @return array{type: string, message: string, audio?: string, html?: string}|null
     */
    function tnc_purchase_po_list_flash(array $get): ?array
    {
        $error = trim((string) ($get['error'] ?? ''));
        if ($error !== '') {
            return ['type' => 'danger', 'message' => tnc_purchase_po_list_error_message($error)];
        }

        $poNumber = trim((string) ($get['po_number'] ?? ''));
        $hasCreated = !empty($get['success']);
        $hasPayment = !empty($get['payment_saved']);
        $hasBilling = !empty($get['billing_saved']);

        if ($hasCreated && $hasPayment) {
            $message = 'สร้างใบสั่งซื้อ (PO) สำเร็จ';
            if ($poNumber !== '') {
                $message .= ' — เลขที่ ' . $poNumber;
            }
            $message .= ' และบันทึกการจ่าย/เอกสารแล้ว';

            return [
                'type' => 'success',
                'message' => $message,
                'audio' => 'create',
                'html' => tnc_purchase_po_payment_print_links_html($get),
            ];
        }

        if ($hasCreated) {
            $message = 'สร้างใบสั่งซื้อ (PO) สำเร็จแล้ว';
            if ($poNumber !== '') {
                $message .= ' — เลขที่ ' . $poNumber;
            }

            return ['type' => 'success', 'message' => $message, 'audio' => 'create'];
        }

        if ($hasPayment) {
            return [
                'type' => 'success',
                'message' => 'บันทึกสถานะการจ่ายเงินเรียบร้อยแล้ว',
                'audio' => 'complete',
                'html' => tnc_purchase_po_payment_print_links_html($get),
            ];
        }

        if ($hasBilling) {
            return [
                'type' => 'success',
                'message' => 'บันทึกเลขที่บิลซื้อเรียบร้อยแล้ว และสร้างข้อมูลในตาราง bills แล้ว',
                'audio' => 'complete',
            ];
        }

        if (!empty($get['updated'])) {
            return ['type' => 'success', 'message' => 'แก้ไขใบสั่งซื้อ (PO) เรียบร้อยแล้ว', 'audio' => 'update'];
        }

        if (!empty($get['payment_slips_updated'])) {
            return ['type' => 'success', 'message' => 'อัปเดตไฟล์หลักฐานการจ่ายเรียบร้อยแล้ว', 'audio' => 'update'];
        }

        if (!empty($get['deleted'])) {
            return ['type' => 'success', 'message' => 'ลบใบสั่งซื้อเรียบร้อยแล้ว', 'audio' => 'delete'];
        }

        if (!empty($get['cancelled'])) {
            return ['type' => 'success', 'message' => 'ยกเลิกใบสั่งซื้อ (PO) เรียบร้อยแล้ว', 'audio' => 'delete'];
        }

        if (!empty($get['payment_reverted'])) {
            return [
                'type' => 'warning',
                'message' => 'ไม่เหลือหลักฐานการจ่าย (โอนเงิน) ระบบจึงคืนสถานะใบสั่งซื้อนี้เป็น ยังไม่จ่าย โดยอัตโนมัติ',
            ];
        }

        if (!empty($get['po_ignored'])) {
            return [
                'type' => 'secondary',
                'message' => 'ปัดทิ้งใบสั่งซื้อเรียบร้อยแล้ว — จะไม่ถูกนับในกล่อง «ใบสั่งซื้อที่ไม่สมบูรณ์» อีก (คืนค่าได้จากในกล่อง)',
            ];
        }

        if (!empty($get['po_unignored'])) {
            return ['type' => 'info', 'message' => 'คืนค่าใบสั่งซื้อกลับมานับใหม่เรียบร้อยแล้ว'];
        }

        return null;
    }
}

if (!function_exists('tnc_purchase_pr_list_flash')) {
    /**
     * @param array<string, mixed> $get
     * @return array{type: string, message: string, audio?: string}|null
     */
    function tnc_purchase_pr_list_flash(array $get): ?array
    {
        $error = trim((string) ($get['error'] ?? ''));
        if ($error !== '') {
            $message = match ($error) {
                'invalid_pr' => 'ไม่พบรหัสใบขอซื้อที่ถูกต้อง',
                'pr_has_po' => 'ใบขอซื้อนี้มีใบสั่งซื้อ (PO) แล้ว ไม่สามารถแก้ไขได้',
                'pr_approved_locked' => 'ไม่มีสิทธิ์แก้ไข PR นี้',
                'delete_pr_failed' => 'ไม่สามารถลบใบขอซื้อได้ กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ',
                default => 'เกิดข้อผิดพลาด (' . $error . ')',
            };

            return ['type' => 'danger', 'message' => $message];
        }

        if (!empty($get['success'])) {
            $lineNotify = trim((string) ($get['line_notify'] ?? ''));
            $message = 'บันทึกใบขอซื้อ (PR) เรียบร้อยแล้ว';
            if ($lineNotify === 'sent') {
                $message .= ' — ส่งขออนุมัติไป LINE แล้ว';
            } elseif ($lineNotify === 'missing_token') {
                $message .= ' — ยังไม่ได้ตั้ง Channel Access Token (หน้าตั้งค่า LINE)';
            } elseif ($lineNotify === 'missing_target') {
                $message .= ' — ยังไม่ได้ตั้งกลุ่ม LINE (หน้าตั้งค่า LINE)';
            } elseif ($lineNotify !== '') {
                $message .= ' — ส่ง LINE ไม่สำเร็จ (' . $lineNotify . ')';
            } else {
                $message .= ' — ส่งขออนุมัติ LINE หรือให้ ADMIN อนุมัติได้จากหน้ารายละเอียด PR';
            }

            return ['type' => 'success', 'message' => $message, 'audio' => 'create'];
        }

        if (!empty($get['updated'])) {
            return ['type' => 'success', 'message' => 'แก้ไขใบขอซื้อ (PR) เรียบร้อยแล้ว', 'audio' => 'update'];
        }

        if (!empty($get['deleted'])) {
            return ['type' => 'success', 'message' => 'ลบใบขอซื้อเรียบร้อยแล้ว', 'audio' => 'delete'];
        }

        return null;
    }
}

if (!function_exists('tnc_purchase_pr_view_flash')) {
    /**
     * @param array<string, mixed> $get
     * @return array{type: string, message: string, audio?: string}|null
     */
    function tnc_purchase_pr_view_flash(array $get): ?array
    {
        if (!empty($get['error']) && (string) $get['error'] === 'po_exists') {
            return ['type' => 'warning', 'message' => 'ใบขอซื้อนี้มีใบสั่งซื้อแล้ว ไม่สามารถออกซ้ำได้'];
        }

        if (!empty($get['error']) && (string) $get['error'] === 'pr_decision') {
            return [
                'type' => 'danger',
                'message' => trim((string) ($get['message'] ?? 'ไม่สามารถบันทึกผลได้')),
            ];
        }

        if (!empty($get['error']) && (string) $get['error'] === 'pr_not_approved') {
            return [
                'type' => 'warning',
                'message' => 'ใบขอซื้อยังรออนุมัติ — ออก PO ได้หลังอนุมัติ (LINE หรือ ADMIN บนเว็บ)',
            ];
        }

        if (!empty($get['error']) && (string) $get['error'] === 'pr_rejected') {
            return [
                'type' => 'danger',
                'message' => 'ใบขอซื้อไม่ได้รับการอนุมัติ — แก้ไข PR แล้วบันทึกใหม่เพื่อส่งขออนุมัติอีกครั้ง',
            ];
        }

        if (!empty($get['error']) && (string) $get['error'] === 'pr_approved_locked') {
            return [
                'type' => 'warning',
                'message' => 'ไม่มีสิทธิ์แก้ไข PR นี้',
            ];
        }

        if (!empty($get['created'])) {
            $lineNotify = trim((string) ($get['line_notify'] ?? ''));
            $message = 'บันทึกใบขอซื้อ (PR) เรียบร้อยแล้ว';
            if ($lineNotify === 'sent') {
                $message .= ' — ส่งคำขออนุมัติไป LINE แล้ว';
            }

            return ['type' => 'success', 'message' => $message, 'audio' => 'create'];
        }

        if (!empty($get['updated'])) {
            $message = 'แก้ไขใบขอซื้อเรียบร้อยแล้ว';
            $poSynced = (int) ($get['po_synced'] ?? 0);
            if ($poSynced > 0) {
                $message .= ' — อัปเดต PO ที่เชื่อม ' . number_format($poSynced) . ' ใบแล้ว';
            }
            $poSyncError = trim((string) ($get['po_sync_error'] ?? ''));
            if ($poSyncError !== '') {
                return [
                    'type' => 'warning',
                    'message' => $message . ' — บาง PO อัปเดตไม่ได้: ' . $poSyncError,
                    'audio' => 'update',
                ];
            }

            return ['type' => 'success', 'message' => $message, 'audio' => 'update'];
        }

        if (!empty($get['web_approved'])) {
            return ['type' => 'success', 'message' => 'อนุมัติ PR บนเว็บแล้ว — สามารถออก PO ได้', 'audio' => 'approve'];
        }

        if (!empty($get['web_rejected'])) {
            return ['type' => 'danger', 'message' => 'บันทึกผลไม่อนุมัติแล้ว'];
        }

        $lineNotify = trim((string) ($get['line_notify'] ?? ''));
        if ($lineNotify === 'sent') {
            return ['type' => 'info', 'message' => 'ส่งคำขออนุมัติไป LINE แล้ว'];
        }
        if ($lineNotify === 'missing_target') {
            return ['type' => 'warning', 'message' => 'ยังไม่ได้ตั้งกลุ่ม LINE — ไปที่หน้าตั้งค่า LINE'];
        }
        if ($lineNotify === 'missing_token') {
            return ['type' => 'warning', 'message' => 'ยังไม่ได้ตั้ง Channel Access Token — ไปที่หน้าตั้งค่า LINE'];
        }
        if ($lineNotify !== '') {
            return ['type' => 'warning', 'message' => 'ส่ง LINE ไม่สำเร็จ (' . $lineNotify . ')'];
        }

        return null;
    }
}

if (!function_exists('tnc_purchase_po_view_flash')) {
    /**
     * @param array<string, mixed> $get
     * @return array{type: string, message: string, audio?: string}|null
     */
    function tnc_purchase_po_view_flash(array $get): ?array
    {
        if (!empty($get['cancelled'])) {
            return ['type' => 'success', 'message' => 'ยกเลิกใบสั่งซื้อเรียบร้อยแล้ว', 'audio' => 'delete'];
        }

        if (!empty($get['created'])) {
            return ['type' => 'success', 'message' => 'บันทึก Work Order เรียบร้อยแล้ว', 'audio' => 'create'];
        }

        if (!empty($get['billing_saved'])) {
            return [
                'type' => 'success',
                'message' => 'บันทึกเลขที่บิลซื้อเรียบร้อยแล้ว และสร้างข้อมูลในตาราง bills แล้ว',
                'audio' => 'complete',
            ];
        }

        if (!empty($get['updated'])) {
            return ['type' => 'success', 'message' => 'บันทึกการแก้ไขเรียบร้อยแล้ว', 'audio' => 'complete'];
        }

        if (!empty($get['payment_slips_updated'])) {
            return ['type' => 'success', 'message' => 'อัปเดตไฟล์หลักฐานการจ่ายเรียบร้อยแล้ว', 'audio' => 'update'];
        }

        if (!empty($get['payment_reverted'])) {
            return [
                'type' => 'warning',
                'message' => 'ไม่เหลือหลักฐานการจ่าย (โอนเงิน) ระบบจึงคืนสถานะใบสั่งซื้อนี้เป็น ยังไม่จ่าย โดยอัตโนมัติ',
            ];
        }

        if (!empty($get['error']) && (string) $get['error'] === 'no_contract_po') {
            return ['type' => 'warning', 'message' => 'ยังไม่มี Work Order (WO) — ต้องออก WO ก่อนจึงจะสั่งจ่าย PO ได้'];
        }

        if (!empty($get['error']) && (string) $get['error'] === 'po_paid') {
            return ['type' => 'warning', 'message' => 'ใบสั่งซื้อนี้สถานะการจ่ายเป็น «จ่ายแล้ว» ไม่สามารถยกเลิกได้'];
        }

        if (!empty($get['error']) && (string) $get['error'] === 'billing_required') {
            return ['type' => 'warning', 'message' => 'กรุณากรอกเลขที่บิลซื้อและวันที่บนบิลให้ครบถ้วน'];
        }

        if (!empty($get['error']) && (string) $get['error'] === 'billing_amount_invalid') {
            return ['type' => 'warning', 'message' => 'ยอดเงินรวมและยอด VAT ต้องไม่เป็นค่าว่างหรือติดลบ'];
        }

        return null;
    }
}

if (!function_exists('tnc_purchase_wo_list_flash')) {
    /**
     * @param array<string, mixed> $get
     * @return array{type: string, message: string, audio?: string}|null
     */
    function tnc_purchase_wo_list_flash(array $get): ?array
    {
        if (!empty($get['success'])) {
            $createdWoNo = trim((string) ($get['wo_number'] ?? ($get['po_number'] ?? '')));
            $message = 'สร้าง Work Order (WO) สำเร็จแล้ว';
            if ($createdWoNo !== '') {
                $message .= ' — เลขที่ ' . $createdWoNo;
            }

            return ['type' => 'success', 'message' => $message, 'audio' => 'create'];
        }

        if (!empty($get['cancelled'])) {
            return ['type' => 'success', 'message' => 'ยกเลิก Work Order เรียบร้อยแล้ว', 'audio' => 'delete'];
        }

        if (!empty($get['error']) && (string) $get['error'] === 'not_found') {
            return ['type' => 'warning', 'message' => 'ไม่พบ Work Order ที่ต้องการ'];
        }

        if (!empty($get['error']) && (string) $get['error'] === 'no_wo') {
            return ['type' => 'warning', 'message' => 'ยังไม่มี Work Order (WO)'];
        }

        return null;
    }
}

if (!function_exists('tnc_purchase_render_flash')) {
    /**
     * @param array{type: string, message: string, audio?: string, html?: string}|null $flash
     */
    function tnc_purchase_render_flash(?array $flash, bool $dismissible = true): void
    {
        if (!function_exists('tnc_render_flash')) {
            require_once __DIR__ . '/tnc_flash.php';
        }
        tnc_render_flash($flash, $dismissible, 'data-tnc-purchase-flash="1"');
    }
}
