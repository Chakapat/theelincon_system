<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

require_once __DIR__ . '/../hire_line_items.php';
require_once __DIR__ . '/../contractors.php';
require_once __DIR__ . '/vat_print_summary.php';

if (!function_exists('tnc_po_format_date_thai')) {
    function tnc_po_format_date_thai(mixed $date): string
    {
        $s = trim((string) $date);
        if ($s === '') {
            return '-';
        }
        $ts = strtotime($s);
        if ($ts === false) {
            return '-';
        }

        return date('d/m/Y', $ts);
    }
}

if (!function_exists('tnc_po_public_absolute_url')) {
    /**
     * แปลง path จาก app_path() เป็น URL เต็ม (scheme + host) เพื่อให้เบราว์เซอร์โหลดรูปตอนพิมพ์/preview ได้เสถียรขึ้น
     */
    function tnc_po_public_absolute_url(string $pathFromApp): string
    {
        $path = trim($pathFromApp);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return $path;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        $scheme = $https ? 'https' : 'http';

        return $scheme . '://' . $host . $path;
    }
}

if (!function_exists('tnc_po_note_lines')) {
    /** @return list<string> */
    function tnc_po_note_lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $raw) {
            $line = trim(preg_replace('/[ \t]+/u', ' ', (string) $raw));
            if ($line === '') {
                continue;
            }
            $lines[] = $line;
        }

        return tnc_po_note_merge_continuations($lines);
    }
}

if (!function_exists('tnc_po_note_merge_continuations')) {
    /**
     * รวมบรรทัดต่อเนื่อง เช่น เลขบัตร/บัญชีที่ผู้ใช้ขึ้นบรรทัดใหม่
     *
     * @param list<string> $lines
     * @return list<string>
     */
    function tnc_po_note_merge_continuations(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if ($out !== [] && tnc_po_note_is_continuation_line($line, $out[count($out) - 1])) {
                $out[count($out) - 1] = trim($out[count($out) - 1] . ' ' . $line);

                continue;
            }
            $out[] = $line;
        }

        return $out;
    }
}

if (!function_exists('tnc_po_note_is_continuation_line')) {
    function tnc_po_note_is_continuation_line(string $line, string $previous): bool
    {
        if (preg_match('/^[\d\s.\-]+$/u', $line) && preg_match('/\d/u', $previous)) {
            return true;
        }
        if (mb_strlen($line) <= 28 && preg_match('/^\d/u', $line) && preg_match('/[:：]\s*[\d\s]+$/u', $previous)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('tnc_po_render_note_panel')) {
    function tnc_po_render_note_panel(string $heading, string $body, bool $withMarginBottom = false): void
    {
        $lines = tnc_po_note_lines($body);
        if ($lines === []) {
            return;
        }
        $panelClass = 'po-notes-panel' . ($withMarginBottom ? ' po-notes-panel--spaced' : '');
        ?>
        <div class="<?= $panelClass ?>">
            <div class="po-note-heading"><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></div>
            <ul class="po-note-list">
                <?php foreach ($lines as $line): ?>
                    <?php if (preg_match('/^[-•*–—]\s*(.+)$/u', $line, $bulletMatch)): ?>
                        <li class="po-note-item po-note-item--bullet">
                            <span class="po-note-text"><?= htmlspecialchars(trim($bulletMatch[1]), ENT_QUOTES, 'UTF-8') ?></span>
                        </li>
                    <?php elseif (preg_match('/^([^:：]{2,48})[:：]\s*(.*)$/u', $line, $kvMatch)): ?>
                        <?php
                        $label = trim($kvMatch[1]);
                        $value = trim($kvMatch[2]);
                        ?>
                        <li class="po-note-item po-note-item--kv">
                            <span class="po-note-k"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="po-note-v<?= $value === '' ? ' po-note-v--empty' : '' ?>"><?= $value !== '' ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : '—' ?></span>
                        </li>
                    <?php else: ?>
                        <li class="po-note-item">
                            <span class="po-note-text"><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></span>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }
}

/**
 * @return array<string, mixed>|null
 */
function tnc_purchase_po_print_prepare(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $po = Db::rowByIdField('purchase_orders', $id);
    if (!$po) {
        return null;
    }

    $sup = Db::rowByIdField('suppliers', (int) ($po['supplier_id'] ?? 0));
    $prId = (int) ($po['pr_id'] ?? 0);
    $pr = $prId > 0 ? Db::rowByIdField('purchase_requests', $prId) : null;

    $companies = Db::tableRows('company');
    Db::sortRows($companies, 'id', false);
    $companyRows = array_values($companies);
    $com = $companyRows[0] ?? [];

    $data = $po;
    foreach (['name', 'logo', 'address', 'phone', 'tax_id'] as $ck) {
        $data[$ck] = $com[$ck] ?? '';
    }
    $data['s_name'] = $sup['name'] ?? '';
    $data['s_address'] = $sup['address'] ?? '';
    $data['s_tax'] = $sup['tax_id'] ?? '';
    $data['s_phone'] = $sup['phone'] ?? '';
    $data['contact_person'] = $sup['contact_person'] ?? '';
    $data['pr_number'] = is_array($pr) ? (string) ($pr['pr_number'] ?? '') : '';
    $orderType = trim((string) ($data['order_type'] ?? 'purchase'));
    if (!in_array($orderType, ['purchase', 'hire'], true)) {
        $orderType = 'purchase';
    }
    $contractorName = trim((string) ($data['contractor_name'] ?? ''));
    $contractorId = (int) ($data['contractor_id'] ?? 0);
    if ($contractorId <= 0 && is_array($pr)) {
        $contractorId = (int) ($pr['contractor_id'] ?? 0);
    }
    $contractorPrint = tnc_contractor_print_profile($contractorId, $contractorName);
    if ($contractorPrint['name_th'] !== '') {
        $contractorName = $contractorPrint['name_th'];
    }
    $installmentNo = (int) ($data['installment_no'] ?? 0);
    $installmentTotal = (int) ($data['installment_total'] ?? 0);
    if ($installmentTotal < 0) {
        $installmentTotal = 0;
    }
    $hirePoKind = $orderType === 'hire' ? Purchase::hirePoKind($po) : '';
    $referencePrNumber = trim((string) ($data['reference_pr_number'] ?? ($data['pr_number'] ?? '')));
    $referenceContractPoNumber = trim((string) ($data['reference_contract_po_number'] ?? ''));
    if ($orderType === 'hire') {
        if ($hirePoKind === 'contract') {
            $referencePrNumber = '';
        } elseif (preg_match('/^HC-TNC-/i', $referencePrNumber)) {
            $referencePrNumber = '';
        }
        if ($referenceContractPoNumber === '' && in_array($hirePoKind, ['payment', 'advance'], true)) {
            $hcIdPrint = (int) ($data['hire_contract_id'] ?? 0);
            if ($hcIdPrint > 0) {
                $hcPrint = Db::row('hire_contracts', (string) $hcIdPrint);
                if (is_array($hcPrint)) {
                    $woDoc = Purchase::hireContractDocumentNumber($hcPrint);
                    if ($woDoc !== '' && !preg_match('/^HC-TNC-/i', $woDoc)) {
                        $referenceContractPoNumber = $woDoc;
                    }
                }
            }
        }
    }
    $withholdingType = trim((string) ($data['withholding_type'] ?? 'none'));
    if ($withholdingType === 'wht5') {
        $withholdingType = 'wht3';
    }
    if (!in_array($withholdingType, ['none', 'wht3'], true)) {
        $withholdingType = 'none';
    }
    $withholdingAmount = (float) ($data['withholding_amount'] ?? 0);
    $retentionType = trim((string) ($data['retention_type'] ?? 'none'));
    if (!in_array($retentionType, ['none', 'percent', 'fixed'], true)) {
        $retentionType = 'none';
    }
    $retentionAmount = (float) ($data['retention_amount'] ?? 0);
    $poNotePo = trim((string) ($data['po_note'] ?? ''));
    $poNoteQt = trim((string) ($data['quotation_note'] ?? ''));
    $hireWorkConditions = '';
    if ($orderType === 'hire' && $hirePoKind === 'contract') {
        $hireWorkConditions = Purchase::hireWorkConditionsText($po);
        if ($hireWorkConditions !== '') {
            $poNotePo = $hireWorkConditions;
        }
    }

    $poSiteDisplay = trim((string) ($data['site_name'] ?? ''));
    $poSiteId = (int) ($data['site_id'] ?? 0);
    if ($poSiteDisplay === '' && $poSiteId > 0) {
        $siteRowPo = Db::row('sites', (string) $poSiteId);
        if (is_array($siteRowPo)) {
            $poSiteDisplay = trim((string) ($siteRowPo['name'] ?? ''));
        }
    }
    if ($poSiteDisplay === '' && is_array($pr)) {
        $poSiteDisplay = trim((string) ($pr['site_name'] ?? ''));
        if ($poSiteDisplay === '' && (int) ($pr['site_id'] ?? 0) > 0) {
            $sr2 = Db::row('sites', (string) (int) ($pr['site_id'] ?? 0));
            if (is_array($sr2)) {
                $poSiteDisplay = trim((string) ($sr2['name'] ?? ''));
            }
        }
    }

    $poCostCategoryId = (int) ($data['cost_category_id'] ?? 0);
    $poCostCategoryName = trim((string) ($data['cost_category_name'] ?? ''));
    if ($poCostCategoryId <= 0 && is_array($pr)) {
        $poCostCategoryId = (int) ($pr['cost_category_id'] ?? 0);
        if ($poCostCategoryName === '') {
            $poCostCategoryName = trim((string) ($pr['cost_category_name'] ?? ''));
        }
    }
    if ($poCostCategoryName === '' && $poCostCategoryId > 0) {
        if (!function_exists('tnc_site_category_name')) {
            require_once dirname(__DIR__) . '/site_cost_categories.php';
        }
        $poCostCategoryName = tnc_site_category_name($poCostCategoryId);
    }

    $poNumber = trim((string) ($po['po_number'] ?? ''));
    $items = Db::filter('purchase_order_items', static function (array $r) use ($id, $poNumber): bool {
        $poId = isset($r['po_id']) ? (int) $r['po_id'] : 0;
        $purchaseOrderId = isset($r['purchase_order_id']) ? (int) $r['purchase_order_id'] : 0;
        $poNumberRef = trim((string) ($r['po_number'] ?? ''));

        return $poId === $id
            || $purchaseOrderId === $id
            || ($poNumberRef !== '' && $poNumberRef === $poNumber);
    });
    if (count($items) === 0 && $prId > 0) {
        $items = Db::filter('purchase_order_items', static function (array $r) use ($prId): bool {
            return isset($r['pr_id']) && (int) $r['pr_id'] === $prId;
        });
    }
    if (count($items) === 0 && $prId > 0) {
        $items = Db::filter('purchase_request_items', static function (array $r) use ($prId): bool {
            return isset($r['pr_id']) && (int) $r['pr_id'] === $prId;
        });
    }
    Db::sortRows($items, 'id', false);

    $po_vat_enabled = (int) ($data['vat_enabled'] ?? 0);
    $po_vat_amount = (float) ($data['vat_amount'] ?? 0);
    $po_grand_total = (float) $data['total_amount'];
    $poVatMode = trim((string) ($data['vat_mode'] ?? ''));
    if (!in_array($poVatMode, ['exclusive', 'inclusive'], true)) {
        if ($po_vat_enabled && is_array($pr)) {
            $poVatMode = trim((string) ($pr['vat_mode'] ?? 'exclusive'));
        } else {
            $poVatMode = 'exclusive';
        }
        if (!in_array($poVatMode, ['exclusive', 'inclusive'], true)) {
            $poVatMode = 'exclusive';
        }
    }
    $poDerivedFromItems = null;
    if (
        $orderType === 'purchase'
        && function_exists('tnc_purchase_po_items_line_sum')
        && function_exists('tnc_purchase_totals_from_line_sum')
    ) {
        $itemsLineSum = tnc_purchase_po_items_line_sum($items, $orderType);
        if ($itemsLineSum > 0) {
            $poDerivedFromItems = tnc_purchase_totals_from_line_sum($itemsLineSum, $po_vat_enabled === 1, $poVatMode);
            $po_grand_total = $poDerivedFromItems['net'];
            $po_vat_amount = $poDerivedFromItems['vat'];
        }
    }
    $issueDate = (string) ($data['issue_date'] ?? '');
    if (trim($issueDate) === '') {
        $issueDate = (string) ($data['created_at'] ?? '');
    }
    if (trim($issueDate) === '' && is_array($pr) && trim((string) ($pr['created_at'] ?? '')) !== '') {
        $issueDate = (string) $pr['created_at'];
    }
    if ($poDerivedFromItems !== null) {
        $po_subtotal = $poDerivedFromItems['subtotal'];
        $po_gross_amount = $poDerivedFromItems['gross'];
    } elseif (isset($data['subtotal_amount']) && $data['subtotal_amount'] !== null && $data['subtotal_amount'] !== '') {
        $po_subtotal = (float) $data['subtotal_amount'];
        $po_gross_amount = (float) (($data['gross_amount'] ?? '') !== '' ? $data['gross_amount'] : ($po_subtotal + $po_vat_amount));
    } else {
        $po_subtotal = round($po_grand_total - $po_vat_amount, 2);
        $po_gross_amount = (float) (($data['gross_amount'] ?? '') !== '' ? $data['gross_amount'] : ($po_subtotal + $po_vat_amount));
    }
    $poVatPrint = tnc_purchase_vat_print_summary(
        $po_vat_enabled === 1,
        $poVatMode,
        $po_subtotal,
        $po_vat_amount,
        $po_grand_total
    );
    $hasRetentionPrint = ($retentionType !== 'none' && $retentionAmount > 0);
    $hasWhtPrint = ($withholdingType !== 'none' && $withholdingAmount > 0);
    $hasDeductionsPrint = $hasRetentionPrint || $hasWhtPrint;
    $poPayableAmount = round((float) ($data['payable_amount'] ?? 0), 2);
    if ($hasDeductionsPrint) {
        if ($poPayableAmount <= 0) {
            $poPayableAmount = round($po_gross_amount - $withholdingAmount - $retentionAmount, 2);
        }
        if ($poPayableAmount < 0) {
            $poPayableAmount = 0.0;
        }
    } else {
        $poPayableAmount = (float) ($poVatPrint['net_amount'] ?? $po_grand_total);
    }
    $poStatus = strtolower(trim((string) ($data['status'] ?? 'ordered')));
    $isPoCancelled = ($poStatus === 'cancelled');

    $poDocTitle = trim((string) ($po['po_number'] ?? ''));
    if ($poDocTitle === '') {
        $poDocTitle = 'PO-' . (int) ($po['id'] ?? $id);
    }

    $hirePaymentSequenceLabel = '';
    if ($orderType === 'hire' && $hirePoKind !== 'contract') {
        $hirePaymentSequenceLabel = Purchase::hirePayablePoSequenceLabel($po, $installmentTotal);
    }

    return [
        'po' => $po,
        'data' => $data,
        'items' => $items,
        'orderType' => $orderType,
        'hirePoKind' => $hirePoKind,
        'contractorName' => $contractorName,
        'contractorPrint' => $contractorPrint,
        'installmentNo' => $installmentNo,
        'installmentTotal' => $installmentTotal,
        'referencePrNumber' => $referencePrNumber,
        'referenceContractPoNumber' => $referenceContractPoNumber,
        'withholdingType' => $withholdingType,
        'withholdingAmount' => $withholdingAmount,
        'retentionType' => $retentionType,
        'retentionAmount' => $retentionAmount,
        'poNotePo' => $poNotePo,
        'poNoteQt' => $poNoteQt,
        'hireWorkConditions' => $hireWorkConditions,
        'hirePaymentSequenceLabel' => $hirePaymentSequenceLabel,
        'poSiteDisplay' => $poSiteDisplay,
        'poCostCategoryName' => $poCostCategoryName,
        'po_vat_enabled' => $po_vat_enabled,
        'poVatMode' => $poVatMode,
        'poVatPrint' => $poVatPrint,
        'po_vat_amount' => $po_vat_amount,
        'po_grand_total' => $po_grand_total,
        'po_subtotal' => $po_subtotal,
        'po_gross_amount' => $po_gross_amount,
        'poPayableAmount' => $poPayableAmount,
        'hasDeductionsPrint' => $hasDeductionsPrint,
        'hasRetentionPrint' => $hasRetentionPrint,
        'hasWhtPrint' => $hasWhtPrint,
        'issueDate' => $issueDate,
        'isPoCancelled' => $isPoCancelled,
        'poDocTitle' => $poDocTitle,
    ];
}

/**
 * โหมดพิมพ์ PO: po | slip | both | all (PR + PO + สลิป/แนบตามที่มี) — รองรับพารามิเตอร์เก่า with_attachments
 */
function tnc_purchase_po_resolve_print_mode(): string
{
    $m = strtolower(trim((string) ($_GET['print_mode'] ?? '')));
    if (in_array($m, ['po', 'slip', 'both', 'all'], true)) {
        return $m;
    }
    $wa = strtolower(trim((string) ($_GET['with_attachments'] ?? '1')));
    if (in_array($wa, ['0', 'false', 'no'], true)) {
        return 'po';
    }

    return 'both';
}

function tnc_purchase_po_print_render(array $ctx): void
{
    extract($ctx, EXTR_SKIP);
    include __DIR__ . '/po_invoice_body.php';
}

/** พิมพ์ควบคู่ใบ PO: แสดงสลิปหน้าใหม่เมื่อจ่ายแล้วและมีไฟล์แนบ (รองรับหลายไฟล์) */
function tnc_purchase_po_payment_slip_print_render(array $po, bool $pageBreakBefore = true): void
{
    if (strtolower(trim((string) ($po['payment_status'] ?? ''))) !== 'paid') {
        return;
    }
    if (!function_exists('tnc_po_payment_slip_items')) {
        require_once dirname(__DIR__) . '/purchase_po_payment_slips.php';
    }
    $slipItems = tnc_po_payment_slip_items($po);
    if ($slipItems === []) {
        return;
    }
    $isFirstSlip = true;
    foreach ($slipItems as $slipItem) {
        if (!($slipItem['is_image'] ?? false)) {
            continue;
        }
        $po_slip_page_break_before = $pageBreakBefore || !$isFirstSlip;
        $isFirstSlip = false;
        $po_slip_image_url = tnc_po_public_absolute_url((string) ($slipItem['url'] ?? ''));
        include __DIR__ . '/po_payment_slip_print.php';
    }
}

/** พิมพ์ควบคู่ใบ PO: ไฟล์แนบใบเสนอราคา (รูปหรือ PDF) */
function tnc_purchase_po_quotation_attachment_print_render(array $po, bool $pageBreakBefore = true): void
{
    $rel = trim((string) ($po['quotation_attachment_path'] ?? ''));
    if ($rel === '') {
        return;
    }
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    $imageExt = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff'];
    $isPdf = ($ext === 'pdf');
    if (!$isPdf && !in_array($ext, $imageExt, true)) {
        return;
    }
    $po_quotation_attach_url = tnc_po_public_absolute_url(app_path($rel));
    $name = trim((string) ($po['quotation_attachment_name'] ?? ''));
    $po_quotation_attach_caption = 'ไฟล์แนบใบเสนอราคา';
    if ($name !== '') {
        $po_quotation_attach_caption .= ' — ' . $name;
    }
    $po_quotation_attach_is_pdf = $isPdf;
    $po_slip_page_break_before = $pageBreakBefore;
    include __DIR__ . '/po_quotation_attachment_print.php';
}
