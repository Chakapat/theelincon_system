<?php

declare(strict_types=1);

/**
 * หลักฐานการจ่าย PO — รองรับหลายไฟล์ (payment_slip_paths JSON + payment_slip_path ตัวแรก)
 */

function tnc_po_payment_slip_paths(array $po): array
{
    $paths = [];
    $json = trim((string) ($po['payment_slip_paths'] ?? ''));
    if ($json !== '') {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            foreach ($decoded as $p) {
                $p = trim((string) $p);
                if ($p !== '' && !in_array($p, $paths, true)) {
                    $paths[] = $p;
                }
            }
        }
    }
    $single = trim((string) ($po['payment_slip_path'] ?? ''));
    if ($single !== '' && !in_array($single, $paths, true)) {
        array_unshift($paths, $single);
    }

    return array_values(array_filter($paths, static function (string $rel): bool {
        if ($rel === '' || str_contains($rel, '..')) {
            return false;
        }
        $abs = ROOT_PATH . '/' . str_replace('\\', '/', $rel);

        return is_file($abs);
    }));
}

/**
 * @return list<array{path: string, url: string, name: string, is_image: bool, is_pdf: bool}>
 */
function tnc_po_payment_slip_items(array $po): array
{
    $items = [];
    foreach (tnc_po_payment_slip_paths($po) as $rel) {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $items[] = [
            'path' => $rel,
            'url' => app_path($rel),
            'name' => basename($rel),
            'is_image' => in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff'], true),
            'is_pdf' => $ext === 'pdf',
        ];
    }

    return $items;
}

function tnc_po_payment_slip_save_paths(int $po_id, array $paths): void
{
    if ($po_id <= 0) {
        return;
    }
    $clean = [];
    foreach ($paths as $p) {
        $p = trim((string) $p);
        if ($p !== '' && !str_contains($p, '..') && !in_array($p, $clean, true)) {
            $clean[] = $p;
        }
    }
    \Theelincon\Rtdb\Db::mergeRow('purchase_orders', (string) $po_id, [
        'payment_slip_paths' => json_encode($clean, JSON_UNESCAPED_UNICODE),
        'payment_slip_path' => $clean[0] ?? '',
    ]);
}

/**
 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file
 */
function tnc_po_payment_slip_upload_one(int $po_id, array $file): ?string
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return null;
    }
    $originalName = trim((string) ($file['name'] ?? 'slip'));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
    if (!in_array($ext, $allowedExt, true)) {
        return null;
    }
    $dirAbs = ROOT_PATH . '/uploads/po-payment-slips/' . $po_id;
    if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
        return null;
    }
    $storedName = 'slip_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destAbs = $dirAbs . '/' . $storedName;
    if (!@move_uploaded_file($tmp, $destAbs)) {
        return null;
    }

    return 'uploads/po-payment-slips/' . $po_id . '/' . $storedName;
}

/**
 * @return list<string> relative paths uploaded
 */
function tnc_po_payment_slip_upload_many(int $po_id, string $fieldName): array
{
    if ($po_id <= 0 || empty($_FILES[$fieldName])) {
        return [];
    }
    $f = $_FILES[$fieldName];
    $uploaded = [];
    if (!is_array($f['name'] ?? null)) {
        $rel = tnc_po_payment_slip_upload_one($po_id, $f);
        if ($rel !== null) {
            $uploaded[] = $rel;
        }

        return $uploaded;
    }
    $count = count($f['name']);
    for ($i = 0; $i < $count; $i++) {
        $rel = tnc_po_payment_slip_upload_one($po_id, [
            'name' => $f['name'][$i] ?? '',
            'type' => $f['type'][$i] ?? '',
            'tmp_name' => $f['tmp_name'][$i] ?? '',
            'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $f['size'][$i] ?? 0,
        ]);
        if ($rel !== null) {
            $uploaded[] = $rel;
        }
    }

    return $uploaded;
}

function tnc_po_payment_slip_delete_file(string $rel): void
{
    $rel = trim($rel);
    if ($rel === '' || str_contains($rel, '..')) {
        return;
    }
    if (!preg_match('#^uploads/po-payment-slips/\d+/[^/]+$#', str_replace('\\', '/', $rel))) {
        return;
    }
    $abs = ROOT_PATH . '/' . str_replace('\\', '/', $rel);
    if (is_file($abs)) {
        @unlink($abs);
    }
}

function tnc_po_issue_date_ymd(array $po): string
{
    $issue = trim((string) ($po['issue_date'] ?? ''));
    if ($issue !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) {
        return $issue;
    }
    if ($issue !== '') {
        $ts = strtotime($issue);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }
    $created = trim((string) ($po['created_at'] ?? ''));
    if ($created !== '') {
        $ts = strtotime($created);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }

    return '';
}

/** @return array<string, mixed>|null */
function tnc_po_action_row_for_modal(int $poId): ?array
{
    if ($poId <= 0) {
        return null;
    }
    $po = \Theelincon\Rtdb\Db::rowByIdField('purchase_orders', $poId);
    if ($po === null) {
        return null;
    }

    $poItems = [];
    foreach (\Theelincon\Rtdb\Db::filter('purchase_order_items', static function (array $r) use ($poId): bool {
        return (int) ($r['po_id'] ?? 0) === $poId || (int) ($r['purchase_order_id'] ?? 0) === $poId;
    }) as $itemRow) {
        if (is_array($itemRow)) {
            $poItems[] = $itemRow;
        }
    }
    if (!function_exists('tnc_purchase_po_resolved_totals')) {
        require_once dirname(__DIR__) . '/includes/purchase_print/vat_print_summary.php';
    }
    $resolvedTotals = tnc_purchase_po_resolved_totals($po, $poItems);
    $amt = $resolvedTotals['net'];
    $resolvedVat = $resolvedTotals['vat'];
    $status = strtolower(trim((string) ($po['status'] ?? 'ordered')));
    if ($status === '') {
        $status = 'ordered';
    }
    $paymentStatus = strtolower(trim((string) ($po['payment_status'] ?? 'unpaid')));
    if (!in_array($paymentStatus, ['paid', 'unpaid'], true)) {
        $paymentStatus = 'unpaid';
    }
    $billingStatus = strtolower(trim((string) ($po['billing_status'] ?? 'pending')));
    if (!in_array($billingStatus, ['pending', 'billed'], true)) {
        $billingStatus = 'pending';
    }
    $slipItems = tnc_po_payment_slip_items($po);

    return [
        'id' => $poId,
        'po_number' => (string) ($po['po_number'] ?? ''),
        'status' => $status,
        'payment_status' => $paymentStatus,
        'billing_status' => $billingStatus,
        'issue_date' => tnc_po_issue_date_ymd($po),
        'total_amount' => $amt,
        'billed_total_amount' => (float) ($po['billed_total_amount'] ?? $amt),
        'billed_vat_amount' => (float) ($po['billed_vat_amount'] ?? $resolvedVat),
        'payment_method' => strtolower(trim((string) ($po['payment_method'] ?? 'transfer'))) === 'cash' ? 'cash' : 'transfer',
        'payment_cash_paid_by' => trim((string) ($po['payment_cash_paid_by'] ?? '')),
        'supplier_invoice_no' => trim((string) ($po['supplier_invoice_no'] ?? '')),
        'slip_items' => $slipItems,
    ];
}
