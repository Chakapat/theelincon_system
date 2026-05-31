<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

require_once __DIR__ . '/../hire_line_items.php';
require_once __DIR__ . '/../contractors.php';

if (!function_exists('tnc_pr_format_date_thai')) {
    function tnc_pr_format_date_thai(mixed $date): string
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

/**
 * @return array<string, mixed>|null
 */
function tnc_purchase_pr_print_prepare(int $pr_id): ?array
{
    if ($pr_id <= 0) {
        return null;
    }
    $pr = Db::rowByIdField('purchase_requests', $pr_id);
    if (!$pr) {
        return null;
    }

    $users = Db::tableKeyed('users');
    $rb = $users[(string) ($pr['requested_by'] ?? '')] ?? null;
    $cb = $users[(string) ($pr['created_by'] ?? '')] ?? null;
    $pr['fname'] = $rb['fname'] ?? '';
    $pr['lname'] = $rb['lname'] ?? '';
    $pr['creator_fname'] = $cb['fname'] ?? '';
    $pr['creator_lname'] = $cb['lname'] ?? '';

    $item_rows = Db::filter('purchase_request_items', static function (array $r) use ($pr_id): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
    });
    Db::sortRows($item_rows, 'id', false);

    $existing_po = Db::findFirst('purchase_orders', static function (array $r) use ($pr_id): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
    });
    $requestType = trim((string) ($pr['request_type'] ?? ($pr['procurement_type'] ?? 'purchase')));
    if (!in_array($requestType, ['purchase', 'hire'], true)) {
        $requestType = 'purchase';
    }
    $contractorName = trim((string) ($pr['contractor_name'] ?? ($pr['hire_contractor_name'] ?? '')));
    $contractorPrint = tnc_contractor_print_profile((int) ($pr['contractor_id'] ?? 0), $contractorName);
    if ($contractorPrint['name_th'] !== '') {
        $contractorName = $contractorPrint['name_th'];
    }
    $contractValue = (float) ($pr['contract_value'] ?? ($pr['hire_total_value'] ?? 0));
    $installmentTotal = (int) ($pr['installment_total'] ?? ($pr['hire_installment_count'] ?? 1));
    if ($installmentTotal < 1) {
        $installmentTotal = 1;
    }
    $hireScope = trim((string) ($pr['hire_scope_details'] ?? ''));

    $companies = Db::tableRows('company');
    Db::sortRows($companies, 'id', false);
    $com = array_values($companies)[0] ?? [];

    $requesterDisplay = trim((string) ($pr['fname'] ?? '') . ' ' . (string) ($pr['lname'] ?? ''));
    $creatorDisplay = trim((string) ($pr['creator_fname'] ?? '') . ' ' . (string) ($pr['creator_lname'] ?? ''));

    $pv = (float) ($pr['vat_amount'] ?? 0);
    $pg = (float) $pr['total_amount'];
    if (isset($pr['subtotal_amount']) && $pr['subtotal_amount'] !== null && $pr['subtotal_amount'] !== '') {
        $ps = (float) $pr['subtotal_amount'];
    } else {
        $ps = round($pg - $pv, 2);
    }
    $vatOn = (int) ($pr['vat_enabled'] ?? 0) === 1;
    $vatMode = trim((string) ($pr['vat_mode'] ?? 'exclusive'));
    if (!in_array($vatMode, ['exclusive', 'inclusive'], true)) {
        $vatMode = 'exclusive';
    }
    if (!function_exists('tnc_purchase_vat_print_summary')) {
        require_once __DIR__ . '/vat_print_summary.php';
    }
    $vatPrint = tnc_purchase_vat_print_summary($vatOn, $vatMode, $ps, $pv, $pg);

    $siteDisplay = trim((string) ($pr['site_name'] ?? ''));
    $siteIdPr = (int) ($pr['site_id'] ?? 0);
    if ($siteDisplay === '' && $siteIdPr > 0) {
        $siteRowPr = Db::row('sites', (string) $siteIdPr);
        if (is_array($siteRowPr)) {
            $siteDisplay = trim((string) ($siteRowPr['name'] ?? ''));
        }
    }

    $createdRaw = trim((string) ($pr['created_at'] ?? ''));
    $quotationAttach = trim((string) ($pr['quotation_attachment_path'] ?? ''));
    $quotationName = trim((string) ($pr['quotation_attachment_name'] ?? ''));
    $detailsText = trim((string) ($pr['details'] ?? ''));
    $hireTableNote = $requestType === 'hire' && count($item_rows) === 0 && $hireScope !== '';

    if (!function_exists('line_pr_normalize_status')) {
        require_once dirname(__DIR__) . '/line_pr_approval.php';
    }
    $prApprovalStatus = line_pr_normalize_status($pr);
    $prIsApprovedForPo = line_pr_is_approved_for_po($pr);
    $prApprovalLabel = line_pr_status_label_th($prApprovalStatus);
    $prApprovalBadgeClass = line_pr_status_badge_class($prApprovalStatus);

    $poShortcutUrl = '';
    if (is_array($existing_po) && (int) ($existing_po['id'] ?? 0) > 0) {
        $poShortcutUrl = app_path('pages/purchase/purchase-order-view.php') . '?id=' . (int) $existing_po['id'];
    } elseif ($prIsApprovedForPo) {
        $poShortcutUrl = $requestType === 'hire'
            ? app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . (int) $pr['id']
            : app_path('pages/purchase/purchase-order-create.php') . '?pr_id=' . (int) $pr['id'];
    }

    $prDocTitle = trim((string) ($pr['pr_number'] ?? ''));
    if ($prDocTitle === '') {
        $prDocTitle = 'PR-' . (int) ($pr['id'] ?? $pr_id);
    }

    $poStatus = '';
    $isPoCancelled = false;
    if (is_array($existing_po)) {
        $poStatus = strtolower(trim((string) ($existing_po['status'] ?? 'ordered')));
        if ($poStatus === '') {
            $poStatus = 'ordered';
        }
        $isPoCancelled = ($poStatus === 'cancelled');
    }

    return [
        'pr' => $pr,
        'com' => $com,
        'item_rows' => $item_rows,
        'requestType' => $requestType,
        'contractorName' => $contractorName,
        'contractorPrint' => $contractorPrint,
        'contractValue' => $contractValue,
        'installmentTotal' => $installmentTotal,
        'hireScope' => $hireScope,
        'requesterDisplay' => $requesterDisplay,
        'creatorDisplay' => $creatorDisplay,
        'pv' => $pv,
        'pg' => $pg,
        'ps' => $ps,
        'vatOn' => $vatOn,
        'vatMode' => $vatMode,
        'vatPrint' => $vatPrint,
        'siteDisplay' => $siteDisplay,
        'createdRaw' => $createdRaw,
        'quotationAttach' => $quotationAttach,
        'quotationName' => $quotationName,
        'detailsText' => $detailsText,
        'hireTableNote' => $hireTableNote,
        'existing_po' => $existing_po,
        'poStatus' => $poStatus,
        'isPoCancelled' => $isPoCancelled,
        'poShortcutUrl' => $poShortcutUrl,
        'prDocTitle' => $prDocTitle,
        'prApprovalStatus' => $prApprovalStatus,
        'prIsApprovedForPo' => $prIsApprovedForPo,
        'prApprovalLabel' => $prApprovalLabel,
        'prApprovalBadgeClass' => $prApprovalBadgeClass,
    ];
}

function tnc_purchase_pr_print_render(array $ctx): void
{
    extract($ctx, EXTR_SKIP);
    include __DIR__ . '/pr_invoice_body.php';
}
