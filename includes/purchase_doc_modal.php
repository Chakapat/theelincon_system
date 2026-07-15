<?php

declare(strict_types=1);

/**
 * Payload สำหรับ Modal ดูรายละเอียด PR/PO (ตาราง) จาก Site Hub
 */

require_once __DIR__ . '/purchase_print/pr_document.php';
require_once __DIR__ . '/purchase_print/po_document.php';
require_once __DIR__ . '/line_pr_approval.php';

use Theelincon\Rtdb\Purchase;

if (!function_exists('tnc_purchase_doc_modal_map_items')) {
    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    function tnc_purchase_doc_modal_map_items(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $dIn = trim((string) ($item['discount_input'] ?? ''));
            $dAmt = (float) ($item['discount_amount'] ?? 0);
            if ($dIn !== '') {
                $discount = $dIn;
            } elseif ($dAmt > 0) {
                $discount = number_format($dAmt, 2, '.', '');
            } else {
                $discount = '';
            }
            $out[] = [
                'description' => (string) ($item['description'] ?? ''),
                'quantity' => round((float) ($item['quantity'] ?? 0), 2),
                'unit' => trim((string) ($item['unit'] ?? '')),
                'unit_price' => round((float) ($item['unit_price'] ?? 0), 2),
                'discount' => $discount,
                'total' => round((float) ($item['total'] ?? 0), 2),
                'vat_exempt' => (int) ($item['vat_exempt'] ?? 0) === 1,
            ];
        }

        return $out;
    }
}

if (!function_exists('tnc_purchase_doc_modal_format_date')) {
    function tnc_purchase_doc_modal_format_date(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '—';
        }
        if (function_exists('tnc_pr_format_date_thai')) {
            $thai = trim((string) tnc_pr_format_date_thai($raw));
            if ($thai !== '') {
                return $thai;
            }
        }
        $ts = strtotime($raw);

        return $ts === false ? $raw : date('d/m/Y', $ts);
    }
}

if (!function_exists('tnc_purchase_doc_modal_po_payment_label')) {
    function tnc_purchase_doc_modal_po_payment_label(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'paid' => 'จ่ายแล้ว',
            'partial' => 'จ่ายบางส่วน',
            'unpaid' => 'ยังไม่จ่าย',
            default => $status !== '' ? $status : 'ยังไม่จ่าย',
        };
    }
}

if (!function_exists('tnc_purchase_doc_modal_payload')) {
    /**
     * @return array<string,mixed>|null
     */
    function tnc_purchase_doc_modal_payload(string $type, int $id): ?array
    {
        $type = strtolower(trim($type));
        if ($id <= 0 || !in_array($type, ['pr', 'purchase_request', 'po', 'purchase_order'], true)) {
            return null;
        }

        if (in_array($type, ['pr', 'purchase_request'], true)) {
            $ctx = tnc_purchase_pr_print_prepare($id);
            if ($ctx === null) {
                return null;
            }
            /** @var array<string,mixed> $pr */
            $pr = is_array($ctx['pr'] ?? null) ? $ctx['pr'] : [];
            $items = tnc_purchase_doc_modal_map_items(is_array($ctx['item_rows'] ?? null) ? $ctx['item_rows'] : []);
            $vatPrint = is_array($ctx['vatPrint'] ?? null) ? $ctx['vatPrint'] : [];
            $canEdit = line_pr_user_can_edit($pr);
            $canDelete = function_exists('user_can') && user_can('pr.delete');
            $source = '';
            if (function_exists('tnc_site_category_document_source')) {
                $source = tnc_site_category_document_source($pr, 'pr');
            }
            $linkedPos = [];
            foreach (is_array($ctx['linked_pos'] ?? null) ? $ctx['linked_pos'] : [] as $lpo) {
                if (!is_array($lpo)) {
                    continue;
                }
                $poNo = trim((string) ($lpo['po_number'] ?? ''));
                if ($poNo === '' && (int) ($lpo['id'] ?? 0) > 0) {
                    $poNo = 'PO-' . (int) $lpo['id'];
                }
                if ($poNo !== '') {
                    $linkedPos[] = $poNo;
                }
            }

            $meta = [
                ['label' => 'เลขที่ PR', 'value' => (string) ($ctx['prDocTitle'] ?? ('PR-' . $id))],
                ['label' => 'วันที่', 'value' => tnc_purchase_doc_modal_format_date((string) ($ctx['createdRaw'] ?? ''))],
                ['label' => 'สถานะอนุมัติ', 'value' => (string) ($ctx['prApprovalLabel'] ?? '—')],
                ['label' => 'ไซต์งาน', 'value' => (string) (($ctx['siteDisplay'] ?? '') !== '' ? $ctx['siteDisplay'] : '—')],
                ['label' => 'หมวดค่าใช้จ่าย', 'value' => (string) (($ctx['prCostCategoryName'] ?? '') !== '' ? $ctx['prCostCategoryName'] : '—')],
                ['label' => 'แหล่งที่ซื้อ', 'value' => $source !== '' ? $source : '—'],
                ['label' => 'ผู้ขอซื้อ', 'value' => (string) (($ctx['requesterDisplay'] ?? '') !== '' ? $ctx['requesterDisplay'] : '—')],
                ['label' => 'ผู้บันทึก', 'value' => (string) (($ctx['creatorDisplay'] ?? '') !== '' ? $ctx['creatorDisplay'] : '—')],
            ];
            if ($linkedPos !== []) {
                $meta[] = ['label' => 'PO ที่เชื่อม', 'value' => implode(', ', $linkedPos)];
            }
            $detailsText = trim((string) ($ctx['detailsText'] ?? ''));
            if ($detailsText !== '') {
                $meta[] = ['label' => 'หมายเหตุ', 'value' => $detailsText];
            }
            if (!function_exists('tnc_purchase_quotation_info')) {
                require_once dirname(__DIR__) . '/purchase_quotation_attachment.php';
            }
            $prQtInfo = tnc_purchase_quotation_info($pr);
            $quotationPayload = null;
            if (!empty($prQtInfo['has'])) {
                $meta[] = ['label' => 'ใบเสนอราคา', 'value' => ($prQtInfo['name'] !== '' ? $prQtInfo['name'] : 'มีไฟล์แนบ') . ' — เปิดจากปุ่มด้านล่างหรือหน้ารายละเอียด'];
                $quotationPayload = [
                    'url' => (string) $prQtInfo['url'],
                    'name' => (string) ($prQtInfo['name'] !== '' ? $prQtInfo['name'] : 'เปิดไฟล์ใบเสนอราคา'),
                ];
            }

            $totals = [
                ['label' => 'ยอดก่อน VAT', 'value' => round((float) ($vatPrint['line_amount'] ?? ($ctx['ps'] ?? 0)), 2)],
            ];
            if (!empty($ctx['vatOn']) && (float) ($vatPrint['vat_amount'] ?? ($ctx['pv'] ?? 0)) > 0) {
                $totals[] = [
                    'label' => (string) ($vatPrint['vat_label'] ?? 'VAT'),
                    'value' => round((float) ($vatPrint['vat_amount'] ?? ($ctx['pv'] ?? 0)), 2),
                ];
            }
            $totals[] = [
                'label' => 'ยอดสุทธิ',
                'value' => round((float) ($vatPrint['net_amount'] ?? ($ctx['pg'] ?? 0)), 2),
                'emphasis' => true,
            ];

            return [
                'ok' => true,
                'type' => 'pr',
                'id' => $id,
                'number' => (string) ($ctx['prDocTitle'] ?? ('PR-' . $id)),
                'title' => 'ใบขอซื้อ (PR)',
                'status_label' => (string) ($ctx['prApprovalLabel'] ?? ''),
                'status_badge' => (string) ($ctx['prApprovalBadgeClass'] ?? 'text-bg-secondary'),
                'meta' => $meta,
                'items' => $items,
                'totals' => $totals,
                'edit_url' => $canEdit
                    ? (app_path('pages/purchase/purchase-request-create.php') . '?id=' . $id)
                    : '',
                'view_url' => app_path('pages/purchase/purchase-request-view.php') . '?id=' . $id,
                'can_edit' => $canEdit,
                'can_delete' => $canDelete,
                'delete_action' => 'delete_pr',
                'delete_type' => '',
                'quotation' => $quotationPayload,
            ];
        }

        $ctx = tnc_purchase_po_print_prepare($id);
        if ($ctx === null) {
            return null;
        }
        /** @var array<string,mixed> $po */
        $po = is_array($ctx['po'] ?? null) ? $ctx['po'] : [];
        /** @var array<string,mixed> $data */
        $data = is_array($ctx['data'] ?? null) ? $ctx['data'] : [];
        $items = tnc_purchase_doc_modal_map_items(is_array($ctx['items'] ?? null) ? $ctx['items'] : []);
        $vatPrint = is_array($ctx['poVatPrint'] ?? null) ? $ctx['poVatPrint'] : [];
        $paidLocked = Purchase::poPaidLocksMutation($po);
        $canEdit = function_exists('user_can') && user_can('po.update') && !$paidLocked;
        $canDelete = function_exists('user_can') && user_can('po.delete') && !$paidLocked;
        $paymentStatus = strtolower(trim((string) ($po['payment_status'] ?? 'unpaid')));
        $billingStatus = strtolower(trim((string) ($po['billing_status'] ?? 'pending')));
        $billingLabel = $billingStatus === 'billed' ? 'บันทึกบิลแล้ว' : 'ยังไม่บันทึกบิล';
        $issueDate = (string) ($ctx['issueDate'] ?? '');
        if ($issueDate !== '' && function_exists('tnc_po_ymd_to_dmy')) {
            $issueDisplay = tnc_po_ymd_to_dmy($issueDate);
        } else {
            $issueDisplay = tnc_purchase_doc_modal_format_date($issueDate);
        }
        $statusLabel = !empty($ctx['isPoCancelled'])
            ? 'ยกเลิกแล้ว'
            : tnc_purchase_doc_modal_po_payment_label($paymentStatus);

        $meta = [
            ['label' => 'เลขที่ PO', 'value' => (string) ($ctx['poDocTitle'] ?? ('PO-' . $id))],
            ['label' => 'วันที่ออก', 'value' => $issueDisplay !== '' ? $issueDisplay : '—'],
            ['label' => 'สถานะ', 'value' => $statusLabel],
            ['label' => 'สถานะบิล', 'value' => $billingLabel],
            ['label' => 'ไซต์งาน', 'value' => (string) (($ctx['poSiteDisplay'] ?? '') !== '' ? $ctx['poSiteDisplay'] : '—')],
            ['label' => 'หมวดค่าใช้จ่าย', 'value' => (string) (($ctx['poCostCategoryName'] ?? '') !== '' ? $ctx['poCostCategoryName'] : '—')],
            ['label' => 'ผู้ขาย', 'value' => (string) (($data['s_name'] ?? '') !== '' ? $data['s_name'] : '—')],
            ['label' => 'อ้างอิง PR', 'value' => (string) (($ctx['referencePrNumber'] ?? '') !== '' ? $ctx['referencePrNumber'] : '—')],
        ];
        $supplierInvoiceNo = trim((string) ($po['supplier_invoice_no'] ?? ''));
        if ($supplierInvoiceNo !== '') {
            $meta[] = ['label' => 'เลขที่บิลซื้อ', 'value' => $supplierInvoiceNo];
        }
        $poNote = trim((string) ($ctx['poNotePo'] ?? ''));
        if ($poNote !== '') {
            $meta[] = ['label' => 'หมายเหตุ PO', 'value' => $poNote];
        }
        $qtNote = trim((string) ($ctx['poNoteQt'] ?? ''));
        if ($qtNote !== '') {
            $meta[] = ['label' => 'หมายเหตุใบเสนอราคา', 'value' => $qtNote];
        }
        if (!function_exists('tnc_purchase_quotation_info')) {
            require_once dirname(__DIR__) . '/purchase_quotation_attachment.php';
        }
        $poQtInfo = is_array($ctx['poQuotationInfo'] ?? null)
            ? $ctx['poQuotationInfo']
            : tnc_purchase_quotation_info($po, !empty($po['quotation_attachment_from_pr']));
        $quotationPayload = null;
        if (!empty($poQtInfo['has'])) {
            $qtLabel = (string) (($poQtInfo['name'] ?? '') !== '' ? $poQtInfo['name'] : 'มีไฟล์แนบ');
            if (!empty($poQtInfo['from_pr'])) {
                $qtLabel .= ' (จาก PR)';
            }
            $meta[] = ['label' => 'ใบเสนอราคา', 'value' => $qtLabel . ' — เปิดจากปุ่มด้านล่างหรือหน้ารายละเอียด'];
            $quotationPayload = [
                'url' => (string) ($poQtInfo['url'] ?? ''),
                'name' => (string) (($poQtInfo['name'] ?? '') !== '' ? $poQtInfo['name'] : 'เปิดไฟล์ใบเสนอราคา'),
            ];
        }

        $totals = [
            ['label' => 'ยอดก่อน VAT', 'value' => round((float) ($vatPrint['line_amount'] ?? ($ctx['po_subtotal'] ?? 0)), 2)],
        ];
        if (!empty($ctx['po_vat_enabled']) && (float) ($vatPrint['vat_amount'] ?? ($ctx['po_vat_amount'] ?? 0)) > 0) {
            $totals[] = [
                'label' => (string) ($vatPrint['vat_label'] ?? 'VAT'),
                'value' => round((float) ($vatPrint['vat_amount'] ?? ($ctx['po_vat_amount'] ?? 0)), 2),
            ];
        }
        if (!empty($ctx['hasDeductionsPrint'])) {
            $totals[] = [
                'label' => 'ยอดรวมก่อนหัก',
                'value' => round((float) ($ctx['po_gross_amount'] ?? ($vatPrint['net_amount'] ?? 0)), 2),
            ];
        }
        if (!empty($ctx['hasWhtPrint'])) {
            $totals[] = [
                'label' => 'หัก ณ ที่จ่าย',
                'value' => round((float) ($ctx['withholdingAmount'] ?? 0), 2),
            ];
        }
        if (!empty($ctx['hasRetentionPrint'])) {
            $totals[] = [
                'label' => 'เงินประกันผลงาน',
                'value' => round((float) ($ctx['retentionAmount'] ?? 0), 2),
            ];
        }
        $totals[] = [
            'label' => 'ยอดสุทธิ / ยอดจ่าย',
            'value' => round((float) ($ctx['poPayableAmount'] ?? ($vatPrint['net_amount'] ?? ($ctx['po_grand_total'] ?? 0))), 2),
            'emphasis' => true,
        ];

        return [
            'ok' => true,
            'type' => 'po',
            'id' => $id,
            'number' => (string) ($ctx['poDocTitle'] ?? ('PO-' . $id)),
            'title' => 'ใบสั่งซื้อ (PO)',
            'status_label' => $statusLabel,
            'status_badge' => !empty($ctx['isPoCancelled'])
                ? 'text-bg-danger'
                : ($paymentStatus === 'paid' ? 'text-bg-success' : 'text-bg-secondary'),
            'meta' => $meta,
            'items' => $items,
            'totals' => $totals,
            'edit_url' => $canEdit
                ? (app_path('pages/purchase/purchase-order-edit.php') . '?id=' . $id)
                : '',
            'view_url' => app_path('pages/purchase/purchase-order-view.php') . '?id=' . $id,
            'can_edit' => $canEdit,
            'can_delete' => $canDelete,
            'delete_action' => 'delete',
            'delete_type' => 'purchase_order',
            'delete_blocked_reason' => $paidLocked ? 'ใบสั่งซื้อที่จ่ายแล้วไม่สามารถลบได้' : '',
            'quotation' => $quotationPayload,
        ];
    }
}
