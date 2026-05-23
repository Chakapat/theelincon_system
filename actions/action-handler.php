<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/tnc_action_response.php';
require_once __DIR__ . '/../includes/tnc_audit_log.php';
require_once __DIR__ . '/../includes/purchase_po_payment_slips.php';

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

$action = $_GET['action'] ?? '';
$type = $_GET['type'] ?? '';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!isset($_SESSION['user_id'])) {
    exit('Access Denied: กรุณาเข้าสู่ระบบ');
}

// POST-only actions: prevent direct GET access to write endpoints.
if (($action === 'create_po_direct' || $action === 'create_po_from_pr' || $action === 'update_po_payment_status' || $action === 'add_po_payment_slips' || $action === 'remove_po_payment_slip' || $action === 'replace_po_payment_slip' || $action === 'update_po_direct' || $action === 'cancel_purchase_order' || $action === 'update_my_profile')
    && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $fallback = match ($action) {
        'create_po_direct' => app_path('pages/purchase/purchase-order-create.php'),
        'create_po_from_pr' => app_path('pages/purchase/purchase-order-list.php'),
        'update_po_direct' => app_path('pages/purchase/purchase-order-list.php'),
        'update_my_profile' => app_path('pages/account/my-profile.php'),
        default => app_path('pages/purchase/purchase-order-list.php'),
    };
    http_response_code(200);
    echo '<!doctype html><html lang="th"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>เปิดหน้านี้โดยตรงไม่ได้</title></head><body style="font-family:Arial,sans-serif;padding:24px;"><h3>หน้านี้เป็น endpoint สำหรับบันทึกข้อมูล</h3><p>กรุณาใช้งานผ่านฟอร์มของระบบ</p><p><a href="' . htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8') . '">กลับไปหน้าสร้างเอกสาร</a></p></body></html>';
    exit;
}

if ($action !== 'get_data' && !csrf_verify_request()) {
    if (function_exists('tnc_ajax_form_requested') && tnc_ajax_form_requested()) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'โทเค็นความปลอดภัยไม่ถูกต้อง กรุณาโหลดหน้าใหม่'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(403);
    exit('Invalid security token. Please refresh the page and try again.');
}

// Routing: POST overrides GET (ลบผ่านแบบฟอร์มที่แนบรหัสผ่านยืนยัน).
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$type = (string) ($_POST['type'] ?? $_GET['type'] ?? '');
$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

require_once __DIR__ . '/../includes/tnc_audit_log.php';
$tncDeletePwdActions = ['delete', 'delete_supplier', 'delete_pr', 'delete_leave_request'];
if (in_array($action, $tncDeletePwdActions, true)) {
    tnc_require_post_confirm_password();
}

$admin_only_actions = ['delete', 'delete_pr', 'add_member', 'edit_member', 'delete_supplier', 'delete_leave_request', 'add_company', 'edit_company', 'add_customer', 'edit_customer'];
if (in_array($action, $admin_only_actions, true) && !user_is_admin_role()) {
    exit('Access Denied: เฉพาะผู้ดูแลระบบเท่านั้นที่สามารถดำเนินการนี้ได้');
}

if ($action === 'purge_audit_logs') {
    if (!user_is_admin_only_role()) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์ — หน้านี้สำหรับผู้ดูแลระบบ (ADMIN) เท่านั้น');
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        tnc_action_redirect(app_path('pages/internal/audit-log.php') . '?error=invalid_method');
    }
    $confirm = trim((string) ($_POST['confirm_purge'] ?? ''));
    if ($confirm !== 'yes') {
        tnc_action_redirect(app_path('pages/internal/audit-log.php') . '?purge_declined=1');
    }
    $deleted = tnc_audit_logs_purge_all();
    tnc_action_redirect(app_path('pages/internal/audit-log.php') . '?purged=' . $deleted);
}

if ($action === 'update_my_profile') {
    tnc_require_post_confirm_password();
    $profileUrl = app_path('pages/account/my-profile.php');
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        tnc_action_redirect(app_path('sign-in.php'));
        exit;
    }
    $cur = Db::row('users', (string) $uid);
    if ($cur === null) {
        tnc_action_redirect($profileUrl . '?error=user_not_found');
        exit;
    }
    $fn = trim((string) ($_POST['fname'] ?? ''));
    $ln = trim((string) ($_POST['lname'] ?? ''));
    if ($fn === '' || $ln === '') {
        tnc_action_redirect($profileUrl . '?error=name_required');
        exit;
    }
    $address = trim((string) ($_POST['address'] ?? ''));
    $bd_raw = trim((string) ($_POST['birth_date'] ?? ''));
    $has_bd = $bd_raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd_raw);
    if ($bd_raw !== '' && !$has_bd) {
        tnc_action_redirect($profileUrl . '?error=birth_date_invalid');
        exit;
    }
    if ($has_bd) {
        $bd_y = (int) substr($bd_raw, 0, 4);
        $bd_m = (int) substr($bd_raw, 5, 2);
        $bd_d = (int) substr($bd_raw, 8, 2);
        if ($bd_y < 1900 || !checkdate($bd_m, $bd_d, $bd_y)) {
            tnc_action_redirect($profileUrl . '?error=birth_date_invalid');
            exit;
        }
        $yNow = (int) date('Y');
        $mNow = (int) date('n');
        $dNow = (int) date('j');
        if ($bd_y > $yNow || ($bd_y === $yNow && ($bd_m > $mNow || ($bd_m === $mNow && $bd_d > $dNow)))) {
            tnc_action_redirect($profileUrl . '?error=birth_date_invalid');
            exit;
        }
    }
    $nid_digits = preg_replace('/\D/', '', (string) ($_POST['national_id'] ?? ''));
    if (strlen($nid_digits) > 13) {
        tnc_action_redirect($profileUrl . '?error=national_id_invalid');
        exit;
    }
    if ($nid_digits !== '' && strlen($nid_digits) !== 13) {
        tnc_action_redirect($profileUrl . '?error=national_id_invalid');
        exit;
    }
    $newPw = (string) ($_POST['new_password'] ?? '');
    $newPw2 = (string) ($_POST['new_password_confirm'] ?? '');
    $base = [
        'fname' => $fn,
        'lname' => $ln,
        'address' => $address,
        'birth_date' => $has_bd ? $bd_raw : null,
        'national_id' => $nid_digits !== '' ? $nid_digits : null,
    ];
    if ($newPw !== '' || $newPw2 !== '') {
        if ($newPw !== $newPw2) {
            tnc_action_redirect($profileUrl . '?error=password_mismatch');
            exit;
        }
        if (strlen($newPw) < 6) {
            tnc_action_redirect($profileUrl . '?error=password_weak');
            exit;
        }
        $base['password'] = password_hash($newPw, PASSWORD_DEFAULT);
    }
    Db::setRow('users', (string) $uid, array_merge($cur, $base));
    $_SESSION['name'] = trim($fn . ' ' . $ln);
    $memAfter = Db::row('users', (string) $uid);
    tnc_audit_log('update', 'member', (string) $uid, trim($fn . ' ' . $ln) . ' (self)', [
        'source' => 'action-handler',
        'action' => 'update_my_profile',
        'before' => $cur,
        'after' => $memAfter,
        'meta' => ['password_changed' => ($newPw !== '')],
    ]);
    tnc_action_redirect($profileUrl . '?success=1');
    exit;
}

function tnc_audit_purchase_order_created(int $poId, string $sourceAction): void
{
    if ($poId <= 0) {
        return;
    }
    $po = Db::row('purchase_orders', (string) $poId);
    if ($po === null) {
        return;
    }
    $items = [];
    foreach (Db::filter('purchase_order_items', static function (array $r) use ($poId): bool {
        return isset($r['po_id']) && (int) $r['po_id'] === $poId;
    }) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $items[] = $row;
        if (count($items) >= 80) {
            break;
        }
    }
    $poNo = trim((string) ($po['po_number'] ?? ''));
    tnc_audit_log('create', 'purchase_order', (string) $poId, $poNo !== '' ? $poNo : ('#' . $poId), [
        'source' => 'action-handler',
        'action' => $sourceAction,
        'after' => $po,
        'meta' => [
            'line_count' => count($items),
            'lines' => $items,
        ],
    ]);
}

/**
 * PO totals aligned with purchase-order-create / purchase-order-edit JS (VAT 7%, WHT 3% on pre-VAT base).
 *
 * @return array{subtotal: float, vat: float, gross: float, wht: float, net: float, withholding_type: string, vat_mode: string}
 */
function tnc_po_compute_totals(float $lineSum, int $vatEnabled, string $vatMode, string $withholdingType): array
{
    $lineSum = round($lineSum, 2);
    $vatMode = in_array($vatMode, ['exclusive', 'inclusive'], true) ? $vatMode : 'exclusive';
    $subtotal = $lineSum;
    $vat = 0.0;
    $gross = $lineSum;
    if ($vatEnabled) {
        if ($vatMode === 'inclusive') {
            $vat = round($lineSum * 7 / 107, 2);
            $subtotal = round($lineSum - $vat, 2);
            $gross = $lineSum;
        } else {
            $vat = round($subtotal * 0.07, 2);
            $gross = round($subtotal + $vat, 2);
        }
    }
    $whtType = ($withholdingType === 'wht3') ? 'wht3' : 'none';
    $wht = $whtType === 'wht3' ? round($subtotal * 0.03, 2) : 0.0;
    $net = round($gross - $wht, 2);
    $storedVatMode = $vatEnabled ? $vatMode : 'exclusive';

    return [
        'subtotal' => $subtotal,
        'vat' => $vat,
        'gross' => $gross,
        'wht' => $wht,
        'net' => $net,
        'withholding_type' => $whtType,
        'vat_mode' => $storedVatMode,
    ];
}

/**
 * Per-line discount for PR/PO lines (same rules as purchase-bill: "10%" or baht amount).
 *
 * @return array{discount_input: string, discount_type: string, discount_value: float, discount_amount: float, line_base: float, line_total: float}
 */
function tnc_pr_parse_line_discount(float $qty, float $price, string $discountRaw): array
{
    $lineBase = round($qty * $price, 2);
    $discountRaw = trim($discountRaw);
    $discountAmount = 0.0;
    $discountType = 'amount';
    $discountValue = 0.0;
    if ($discountRaw !== '') {
        $pctMatch = [];
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*%$/', $discountRaw, $pctMatch) === 1) {
            $discountType = 'percent';
            $discountValue = (float) $pctMatch[1];
            if ($discountValue < 0) {
                $discountValue = 0.0;
            } elseif ($discountValue > 100) {
                $discountValue = 100.0;
            }
            $discountAmount = round($lineBase * $discountValue / 100, 2);
        } else {
            $discountType = 'amount';
            $discountValue = (float) str_replace([',', ' '], '', $discountRaw);
            if ($discountValue < 0) {
                $discountValue = 0.0;
            }
            $discountAmount = min($lineBase, round($discountValue, 2));
        }
    }
    $lineTotal = round($lineBase - $discountAmount, 2);

    return [
        'discount_input' => mb_substr($discountRaw, 0, 20),
        'discount_type' => $discountType,
        'discount_value' => $discountValue,
        'discount_amount' => $discountAmount,
        'line_base' => $lineBase,
        'line_total' => $lineTotal,
    ];
}

/**
 * Next PB-TNC-yymm-xxx bill number (same rule as purchase-bill.php).
 */
function tnc_next_purchase_bill_number(): string
{
    $prefix = 'PB-TNC-' . date('ym') . '-';
    $nextRunning = 1;
    foreach (Db::tableRows('purchase_bills') as $billRow) {
        $billNumber = trim((string) ($billRow['bill_number'] ?? ''));
        if (strncmp($billNumber, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $runningPart = substr($billNumber, strlen($prefix));
        if (!ctype_digit($runningPart)) {
            continue;
        }
        $nextRunning = max($nextRunning, ((int) $runningPart) + 1);
    }

    return $prefix . str_pad((string) $nextRunning, 3, '0', STR_PAD_LEFT);
}

/**
 * After PO is marked paid: create a project purchase bill mirroring manual save_project_purchase_bill.
 * Idempotent via purchase_orders.auto_purchase_bill_id and purchase_bills.source_po_id.
 *
 * @param array<string, mixed>|null $po Fresh row after payment_status = paid
 * @return int|null New purchase_bills id, or null if skipped / failed softly
 */
function tnc_purchase_bill_create_from_paid_purchase_order(?array $po, int $createdBy): ?int
{
    if ($po === null || $createdBy <= 0) {
        return null;
    }
    $poId = (int) ($po['id'] ?? 0);
    if ($poId <= 0) {
        return null;
    }
    if (strtolower(trim((string) ($po['order_type'] ?? 'purchase'))) !== 'purchase') {
        return null;
    }
    if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
        return null;
    }
    if ((int) ($po['auto_purchase_bill_id'] ?? 0) > 0) {
        return null;
    }
    foreach (Db::tableRows('purchase_bills') as $exBill) {
        if ((int) ($exBill['source_po_id'] ?? 0) === $poId) {
            Db::mergeRow('purchase_orders', (string) $poId, [
                'auto_purchase_bill_id' => (int) ($exBill['id'] ?? 0),
            ]);

            return null;
        }
    }

    $poNumber = trim((string) ($po['po_number'] ?? ''));
    $items = Db::filter('purchase_order_items', static function (array $r) use ($poId, $poNumber): bool {
        $pid = isset($r['po_id']) ? (int) $r['po_id'] : 0;
        $purchaseOrderId = isset($r['purchase_order_id']) ? (int) $r['purchase_order_id'] : 0;
        $poNumberRef = trim((string) ($r['po_number'] ?? ''));

        return $pid === $poId
            || $purchaseOrderId === $poId
            || ($poNumberRef !== '' && $poNumberRef === $poNumber);
    });
    Db::sortRows($items, 'id', false);
    if (count($items) === 0) {
        $prIdFallback = (int) ($po['pr_id'] ?? 0);
        if ($prIdFallback > 0) {
            $items = Db::filter('purchase_request_items', static function (array $r) use ($prIdFallback): bool {
                return isset($r['pr_id']) && (int) $r['pr_id'] === $prIdFallback;
            });
            Db::sortRows($items, 'id', false);
        }
    }
    if (count($items) === 0) {
        return null;
    }

    $supplierId = (int) ($po['supplier_id'] ?? 0);
    $supplierRow = $supplierId > 0 ? Db::row('suppliers', (string) $supplierId) : null;
    $supplierName = $supplierRow !== null ? trim((string) ($supplierRow['name'] ?? '')) : '';
    if ($supplierName === '') {
        $supplierName = trim((string) ($po['supplier_name'] ?? ''));
    }
    if ($supplierName === '') {
        $supplierName = '—';
    }

    $siteId = (int) ($po['site_id'] ?? 0);
    if ($siteId <= 0) {
        $prId = (int) ($po['pr_id'] ?? 0);
        if ($prId > 0) {
            $prRow = Db::row('purchase_requests', (string) $prId);
            if (is_array($prRow)) {
                $siteId = (int) ($prRow['site_id'] ?? 0);
            }
        }
    }
    if ($siteId > 0) {
        $siteCheck = Db::row('sites', (string) $siteId);
        if ($siteCheck === null) {
            $siteId = 0;
        }
    }

    $line_rows = [];
    $subtotalLines = 0.0;
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $desc = trim((string) ($it['description'] ?? ''));
        $qty = (float) ($it['quantity'] ?? 0);
        $unit = trim((string) ($it['unit'] ?? ''));
        $price = (float) ($it['unit_price'] ?? 0);
        if ($desc === '' || $qty <= 0 || $price < 0) {
            continue;
        }
        $dIn = trim((string) ($it['discount_input'] ?? ''));
        if ($dIn === '') {
            $dt = (string) ($it['discount_type'] ?? 'amount');
            $dv = (float) ($it['discount_value'] ?? 0);
            $dAmt = (float) ($it['discount_amount'] ?? 0);
            if ($dAmt > 0 && $dt === 'percent' && $dv > 0) {
                $dIn = rtrim(rtrim(number_format($dv, 4, '.', ''), '0'), '.') . '%';
            } elseif ($dAmt > 0) {
                $dIn = (string) $dAmt;
            }
        }
        $parts = tnc_pr_parse_line_discount($qty, $price, $dIn);
        if ($parts['line_total'] <= 0) {
            continue;
        }
        $subtotalLines += $parts['line_total'];
        $line_rows[] = [
            'description' => mb_substr($desc, 0, 500),
            'quantity' => $qty,
            'unit' => mb_substr($unit, 0, 40),
            'unit_price' => $price,
            'discount_input' => $parts['discount_input'],
            'discount_type' => $parts['discount_type'],
            'discount_value' => $parts['discount_value'],
            'discount_amount' => $parts['discount_amount'],
            'line_total' => $parts['line_total'],
        ];
    }
    if ($line_rows === [] || $subtotalLines <= 0) {
        return null;
    }
    $subtotalLines = round($subtotalLines, 2);

    $vatEn = (int) ($po['vat_enabled'] ?? 0) === 1;
    $vatModePo = trim((string) ($po['vat_mode'] ?? ''));
    $vat_mode = 'none';
    if ($vatEn) {
        if (in_array($vatModePo, ['exclusive', 'inclusive'], true)) {
            $vat_mode = $vatModePo;
        } else {
            $prId = (int) ($po['pr_id'] ?? 0);
            if ($prId > 0) {
                $prRow = Db::row('purchase_requests', (string) $prId);
                $vmPr = is_array($prRow) ? trim((string) ($prRow['vat_mode'] ?? 'exclusive')) : 'exclusive';
                $vat_mode = in_array($vmPr, ['exclusive', 'inclusive'], true) ? $vmPr : 'exclusive';
            } else {
                $vat_mode = 'exclusive';
            }
        }
    }
    $vat_rate = 7.0;
    $subtotal = $subtotalLines;
    $vat_amount = 0.0;
    $grand_total = $subtotal;
    if ($vat_mode === 'exclusive') {
        $vat_amount = round($subtotal * $vat_rate / 100, 2);
        $grand_total = round($subtotal + $vat_amount, 2);
    } elseif ($vat_mode === 'inclusive') {
        if ($vat_rate > 0) {
            $base = round($subtotal / (1 + $vat_rate / 100), 2);
            $vat_amount = round($subtotal - $base, 2);
            $subtotal = $base;
            $grand_total = round($base + $vat_amount, 2);
        } else {
            $grand_total = $subtotal;
            $vat_amount = 0.0;
        }
    }

    $paidAt = trim((string) ($po['payment_marked_paid_at'] ?? ''));
    $bill_date = date('Y-m-d');
    if ($paidAt !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $paidAt, $m) === 1) {
        $bill_date = $m[1];
    } elseif (trim((string) ($po['issue_date'] ?? '')) !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $po['issue_date'])) {
        $bill_date = (string) $po['issue_date'];
    }

    $poNo = trim((string) ($po['po_number'] ?? ''));
    $refPr = trim((string) ($po['reference_pr_number'] ?? ''));
    if ($refPr === '' && (int) ($po['pr_id'] ?? 0) > 0) {
        $prSnap = Db::row('purchase_requests', (string) (int) ($po['pr_id'] ?? 0));
        $refPr = $prSnap !== null ? trim((string) ($prSnap['pr_number'] ?? '')) : '';
    }
    $noteParts = [
        '[อัตโนมัติจาก PO จ่ายแล้ว]',
        'PO: ' . ($poNo !== '' ? $poNo : ('#' . $poId)),
        'จ่ายเมื่อ: ' . ($paidAt !== '' ? $paidAt : date('Y-m-d H:i:s')),
    ];
    $payMethod = strtolower(trim((string) ($po['payment_method'] ?? 'transfer')));
    if (!in_array($payMethod, ['cash', 'transfer'], true)) {
        $payMethod = 'transfer';
    }
    $cashPaidBy = trim((string) ($po['payment_cash_paid_by'] ?? ''));
    if ($payMethod === 'cash') {
        $noteParts[] = 'ชำระ: เงินสด' . ($cashPaidBy !== '' ? (' · จ่ายโดย: ' . $cashPaidBy) : '');
    } else {
        $noteParts[] = 'ชำระ: โอน/ช่องทางอื่น (แนบหลักฐาน)';
    }
    if ($refPr !== '') {
        $noteParts[] = 'อ้างอิง PR: ' . $refPr;
    }
    $poNote = trim((string) ($po['po_note'] ?? ''));
    if ($poNote !== '') {
        $noteParts[] = 'หมายเหตุ PO: ' . $poNote;
    }
    $qNote = trim((string) ($po['quotation_note'] ?? ''));
    if ($qNote !== '') {
        $noteParts[] = 'หมายเหตุ QT: ' . $qNote;
    }
    $qNo = trim((string) ($po['quotation_number'] ?? ''));
    if ($qNo !== '') {
        $noteParts[] = 'เลขที่ใบเสนอราคา: ' . $qNo;
    }
    $vatLabel = !$vatEn ? 'ไม่มี VAT' : ($vat_mode === 'inclusive' ? 'VAT รวมในราคา' : 'VAT แยก (บวก 7%)');
    $noteParts[] = 'ภาษี: ' . $vatLabel . ' · ยอดรายการ ' . number_format($subtotal, 2) . ' · VAT ' . number_format($vat_amount, 2) . ' · สุทธิ ' . number_format($grand_total, 2);
    $bill_note = mb_substr(implode("\n", $noteParts), 0, 1000);

    $bid = Db::nextNumericId('purchase_bills', 'id');
    $bill_number = tnc_next_purchase_bill_number();
    $billPayload = [
        'id' => $bid,
        'bill_number' => $bill_number,
        'source_po_id' => $poId,
        'site_id' => $siteId,
        'bill_date' => $bill_date,
        'supplier_name' => $supplierName,
        'bill_note' => $bill_note,
        'vat_mode' => $vat_mode,
        'vat_rate' => $vat_rate,
        'subtotal_amount' => $subtotal,
        'vat_amount' => $vat_amount,
        'amount' => $grand_total,
        'attachment_path' => '',
        'attachment_url' => '',
        'created_by' => $createdBy,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'items' => array_map(static function (array $line, int $idx): array {
            return [
                'line_no' => $idx + 1,
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'unit_price' => $line['unit_price'],
                'discount_input' => $line['discount_input'],
                'discount_type' => $line['discount_type'],
                'discount_value' => $line['discount_value'],
                'discount_amount' => $line['discount_amount'],
                'line_total' => $line['line_total'],
            ];
        }, $line_rows, array_keys($line_rows)),
    ];
    Db::setRow('purchase_bills', (string) $bid, $billPayload);
    $billAfter = Db::row('purchase_bills', (string) $bid);
    Db::mergeRow('purchase_orders', (string) $poId, [
        'auto_purchase_bill_id' => $bid,
    ]);
    $summary = $bill_number . ' ← ' . ($poNo !== '' ? $poNo : ('PO#' . $poId));
    tnc_audit_log('create', 'purchase_bill', (string) $bid, $summary, [
        'source' => 'action-handler',
        'action' => 'auto_purchase_bill_from_paid_po',
        'after' => $billAfter,
        'meta' => ['po_id' => $poId],
    ]);

    return $bid;
}

function tnc_po_delete_line_items(int $poId): void
{
    if ($poId <= 0) {
        return;
    }
    foreach (Db::tableKeyed('purchase_order_items') as $itemPk => $itemRow) {
        if (!is_array($itemRow)) {
            continue;
        }
        $pid = (int) ($itemRow['po_id'] ?? 0);
        $poidAlt = (int) ($itemRow['purchase_order_id'] ?? 0);
        if ($pid === $poId || $poidAlt === $poId) {
            Db::deleteRow('purchase_order_items', (string) $itemPk);
        }
    }
}

function renderPoCreatedPopupAndRedirect(string $poNumber)
{
    $listUrl = app_path('pages/purchase/purchase-order-list.php')
        . '?success=1&po_number=' . rawurlencode($poNumber);
    if (tnc_ajax_form_requested()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => true,
            'message' => 'สร้าง PO สำเร็จ หมายเลข ' . $poNumber,
            'po_number' => $poNumber,
            'action' => 'po_created',
            'redirect' => $listUrl,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    tnc_action_redirect($listUrl);
}

// --- get_data (Modal) ---
if ($action === 'get_data') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!csrf_verify_request()) {
        http_response_code(403);
        echo json_encode(['error' => 'csrf'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($type === 'member') {
        if (!user_is_admin_role()) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $row = Db::row('users', (string) $id);
    } elseif ($type === 'supplier') {
        tnc_require_finance_role();
        $row = Db::row('suppliers', (string) $id);
    } elseif ($type === 'company' || $type === 'customer') {
        tnc_require_finance_role();
        $table = ($type === 'company') ? 'company' : 'customers';
        $row = Db::row($table, (string) $id);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'bad_type'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode($row !== null ? tnc_sanitize_api_row($row) : [], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- suppliers ---
if ($action === 'save_supplier') {
    $s_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $name = trim((string) ($_POST['name'] ?? ''));
    $tax = trim((string) ($_POST['tax_id'] ?? ''));
    $contact = trim((string) ($_POST['contact_person'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $addr = trim((string) ($_POST['address'] ?? ''));

    $data = [
        'name' => $name,
        'tax_id' => $tax,
        'contact_person' => $contact,
        'phone' => $phone,
        'email' => $email,
        'address' => $addr,
    ];

    if ($s_id > 0) {
        $cur = Db::row('suppliers', (string) $s_id) ?? [];
        Db::setRow('suppliers', (string) $s_id, array_merge($cur, $data));
        $after = Db::row('suppliers', (string) $s_id) ?? [];
        tnc_audit_log('update', 'supplier', (string) $s_id, $name !== '' ? $name : ('#' . $s_id), [
            'source' => 'action-handler',
            'action' => 'save_supplier',
            'before' => $cur,
            'after' => $after,
        ]);
    } else {
        $nid = Db::nextNumericId('suppliers', 'id');
        $data['id'] = $nid;
        Db::setRow('suppliers', (string) $nid, $data);
        $after = Db::row('suppliers', (string) $nid) ?? [];
        tnc_audit_log('create', 'supplier', (string) $nid, $name !== '' ? $name : ('#' . $nid), [
            'source' => 'action-handler',
            'action' => 'save_supplier',
            'after' => $after,
        ]);
    }
    tnc_action_redirect( app_path('pages/suppliers/supplier-list.php') . '?success=1');
}

if ($action === 'delete_supplier') {
    $po = Db::findFirst('purchase_orders', static function (array $r) use ($id): bool {
        return isset($r['supplier_id']) && (int) $r['supplier_id'] === $id;
    });
    if ($po !== null) {
        tnc_action_redirect( app_path('pages/suppliers/supplier-list.php') . '?error=in_use');
    }
    $sDel = Db::row('suppliers', (string) $id);
    $sDelName = $sDel !== null ? trim((string) ($sDel['name'] ?? '')) : '';
    Db::deleteRow('suppliers', (string) $id);
    tnc_audit_log('delete', 'supplier', (string) $id, $sDelName !== '' ? $sDelName : ('#' . $id), [
        'source' => 'action-handler',
        'action' => 'delete_supplier',
        'before' => $sDel,
    ]);
    tnc_action_redirect( app_path('pages/suppliers/supplier-list.php') . '?deleted=1');
}

// --- PR ---
if ($action === 'save_pr') {
    $sitesForPr = Db::tableRows('sites');
    $site_id = (int) ($_POST['site_id'] ?? 0);
    if (count($sitesForPr) > 0 && $site_id <= 0) {
        tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?error=need_site');
    }
    $site_name_saved = '';
    if ($site_id > 0) {
        $siteRow = Db::row('sites', (string) $site_id);
        if ($siteRow === null) {
            tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?error=need_site');
        }
        $site_name_saved = trim((string) ($siteRow['name'] ?? ''));
    }

    $pr_number = trim((string) ($_POST['pr_number'] ?? ''));
    $created_at = trim((string) ($_POST['created_at'] ?? date('Y-m-d')));
    $requested_by = (int) ($_POST['requested_by'] ?? 0);
    $created_by = (int) $_SESSION['user_id'];
    $details = trim((string) ($_POST['details'] ?? ''));
    $procurement_type = trim((string) ($_POST['request_type'] ?? ($_POST['procurement_type'] ?? 'purchase')));
    if ($procurement_type !== 'hire') {
        $procurement_type = 'purchase';
    }

    $hire_contractor_name = '';
    $hire_employer_company_id = 0;
    $hire_scope_details = '';
    $hire_total_value = 0.0;
    $hire_installment_count = 1;

    if ($procurement_type === 'hire') {
        $hire_contractor_name = trim((string) ($_POST['hire_contractor_name'] ?? ($_POST['contractor_name'] ?? '')));
        $hire_employer_company_id = (int) ($_POST['hire_employer_company_id'] ?? 0);
        if ($hire_employer_company_id <= 0) {
            $companies = Db::tableRows('company');
            Db::sortRows($companies, 'id', false);
            $hire_employer_company_id = (int) (($companies[0] ?? [])['id'] ?? 0);
        }
        $hire_scope_details = trim((string) ($_POST['hire_scope_details'] ?? ($_POST['details'] ?? '')));
        $hire_total_value = round((float) str_replace([',', ' '], '', (string) ($_POST['hire_total_value'] ?? ($_POST['contract_value'] ?? '0'))), 2);
        $hire_installment_count = max(1, min(120, (int) ($_POST['hire_installment_count'] ?? ($_POST['installment_total'] ?? 1))));
        if ($hire_contractor_name === '' || $hire_employer_company_id <= 0 || $hire_scope_details === '' || $hire_total_value <= 0) {
            tnc_action_redirect( app_path('pages/purchase/purchase-request-create.php') . '?error=hire_invalid');
        }
        $vat_enabled = 0;
        $subtotal = $hire_total_value;
        $vat_amount = 0.0;
        $total_amount = $hire_total_value;
    } else {
        $vat_enabled = !empty($_POST['vat_enabled']) ? 1 : 0;
        $subtotal = 0.0;
        $purchaseLineCount = 0;
        foreach ($_POST['item_description'] ?? [] as $key => $desc) {
            if (!isset($_POST['item_qty'][$key], $_POST['item_price'][$key])) {
                continue;
            }
            if (trim((string) $desc) === '') {
                continue;
            }
            $qty = (float) $_POST['item_qty'][$key];
            if ($qty <= 0) {
                continue;
            }
            $purchaseLineCount++;
            $price = max(0.0, (float) $_POST['item_price'][$key]);
            $discRaw = trim((string) ($_POST['item_discount'][$key] ?? ''));
            $parts = tnc_pr_parse_line_discount($qty, $price, $discRaw);
            $subtotal += $parts['line_total'];
        }
        $subtotal = round($subtotal, 2);
        if ($purchaseLineCount <= 0) {
            tnc_action_redirect( app_path('pages/purchase/purchase-request-create.php') . '?error=no_items');
        }
        $vat_mode_post = trim((string) ($_POST['vat_mode'] ?? 'exclusive'));
        $totalsPr = tnc_po_compute_totals($subtotal, $vat_enabled, $vat_mode_post, 'none');
        $subtotal = $totalsPr['subtotal'];
        $vat_amount = $totalsPr['vat'];
        $total_amount = $totalsPr['gross'];
    }

    $pr_id = Db::nextNumericId('purchase_requests', 'id');
    $quoteAttachmentPath = '';
    $quoteAttachmentUrl = '';
    $quoteAttachmentName = '';
    $quoteAttachmentMime = '';
    $quoteAttachmentSize = 0;

    $wantQuotationUpload = !empty($_POST['quotation_attach']);
    if ($wantQuotationUpload && !empty($_FILES['quotation_file']) && (int) ($_FILES['quotation_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['quotation_file'];
        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            tnc_action_redirect( app_path('pages/purchase/purchase-request-create.php') . '?error=upload_failed');
        }

        $tmp = (string) ($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            tnc_action_redirect( app_path('pages/purchase/purchase-request-create.php') . '?error=upload_failed');
        }

        $originalName = trim((string) ($f['name'] ?? 'quotation'));
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff'];
        if (!in_array($ext, $allowedExt, true)) {
            tnc_action_redirect( app_path('pages/purchase/purchase-request-create.php') . '?error=upload_type');
        }

        $dirAbs = ROOT_PATH . '/uploads/pr-quotations/' . $pr_id;
        if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            tnc_action_redirect( app_path('pages/purchase/purchase-request-create.php') . '?error=upload_failed');
        }

        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $safeBase = trim((string) $safeBase, '._-');
        if ($safeBase === '') {
            $safeBase = 'quotation';
        }
        $storedName = $safeBase . '_' . date('Ymd_His') . '.' . $ext;
        $destAbs = $dirAbs . '/' . $storedName;
        if (!@move_uploaded_file($tmp, $destAbs)) {
            tnc_action_redirect( app_path('pages/purchase/purchase-request-create.php') . '?error=upload_failed');
        }

        $quoteAttachmentPath = 'uploads/pr-quotations/' . $pr_id . '/' . $storedName;
        $quoteAttachmentUrl = app_path($quoteAttachmentPath);
        $quoteAttachmentName = $originalName;
        $quoteAttachmentMime = (string) ($f['type'] ?? '');
        $quoteAttachmentSize = (int) ($f['size'] ?? 0);
    }

    $vat_mode_stored = 'exclusive';
    if ($procurement_type !== 'hire') {
        $vm = trim((string) ($_POST['vat_mode'] ?? 'exclusive'));
        $vat_mode_stored = $vat_enabled && in_array($vm, ['exclusive', 'inclusive'], true) ? $vm : 'exclusive';
        if (!$vat_enabled) {
            $vat_mode_stored = 'exclusive';
        }
    }

    $pr_row = [
        'id' => $pr_id,
        'pr_number' => $pr_number,
        'created_at' => $created_at,
        'requested_by' => $requested_by,
        'created_by' => $created_by,
        'details' => $details,
        'site_id' => $site_id,
        'site_name' => $site_name_saved,
        'total_amount' => $total_amount,
        'status' => 'ready',
        'vat_enabled' => $vat_enabled,
        'vat_mode' => $vat_mode_stored,
        'subtotal_amount' => $subtotal,
        'vat_amount' => $vat_amount,
        'procurement_type' => $procurement_type,
        'request_type' => $procurement_type,
        'contractor_name' => $procurement_type === 'hire' ? $hire_contractor_name : '',
        'contract_value' => $procurement_type === 'hire' ? $hire_total_value : 0.0,
        'installment_total' => $procurement_type === 'hire' ? $hire_installment_count : 1,
        'hire_contractor_name' => $hire_contractor_name,
        'hire_employer_company_id' => $hire_employer_company_id,
        'hire_scope_details' => $hire_scope_details,
        'hire_total_value' => $hire_total_value,
        'hire_installment_count' => $hire_installment_count,
        'line_approval_token' => '',
        'quotation_attachment_path' => $quoteAttachmentPath,
        'quotation_attachment_url' => $quoteAttachmentUrl,
        'quotation_attachment_name' => $quoteAttachmentName,
        'quotation_attachment_mime' => $quoteAttachmentMime,
        'quotation_attachment_size' => $quoteAttachmentSize,
    ];
    Db::setRow('purchase_requests', (string) $pr_id, $pr_row);

    foreach ($_POST['item_description'] ?? [] as $key => $desc) {
        if (!isset($_POST['item_qty'][$key], $_POST['item_price'][$key])) {
            continue;
        }
        $desc = trim((string) $desc);
        if ($desc === '') {
            continue;
        }
        $qty = (float) $_POST['item_qty'][$key];
        if ($qty <= 0) {
            continue;
        }
        $iid = Db::nextNumericId('purchase_request_items', 'id');
        $unit = trim((string) ($_POST['item_unit'][$key] ?? ''));
        $price = max(0.0, (float) $_POST['item_price'][$key]);
        $discRaw = trim((string) ($_POST['item_discount'][$key] ?? ''));
        $parts = tnc_pr_parse_line_discount($qty, $price, $discRaw);
        $total = $parts['line_total'];
        Db::setRow('purchase_request_items', (string) $iid, [
            'id' => $iid,
            'pr_id' => $pr_id,
            'description' => $desc,
            'quantity' => $qty,
            'unit' => $unit,
            'unit_price' => $price,
            'total' => $total,
            'discount_input' => $parts['discount_input'],
            'discount_type' => $parts['discount_type'],
            'discount_value' => $parts['discount_value'],
            'discount_amount' => $parts['discount_amount'],
        ]);
    }

    $prAfterSave = Db::row('purchase_requests', (string) $pr_id);
    $prItemsAfter = [];
    foreach (Db::filter('purchase_request_items', static function (array $r) use ($pr_id): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
    }) as $pi) {
        if (!is_array($pi)) {
            continue;
        }
        $prItemsAfter[] = $pi;
        if (count($prItemsAfter) >= 120) {
            break;
        }
    }
    tnc_audit_log('create', 'purchase_request', (string) $pr_id, $pr_number !== '' ? $pr_number : ('#' . $pr_id), [
        'source' => 'action-handler',
        'action' => 'save_pr',
        'after' => $prAfterSave,
        'meta' => ['lines' => $prItemsAfter],
    ]);

    tnc_action_redirect(app_path('pages/purchase/purchase-request-list.php') . '?success=1');
}

if ($action === 'update_pr') {
    $pr_id = (int) ($_POST['pr_id'] ?? 0);
    if ($pr_id <= 0) {
        tnc_action_redirect(app_path('pages/purchase/purchase-request-list.php') . '?error=invalid_pr');
    }
    $existing = Db::rowByIdField('purchase_requests', $pr_id);
    if ($existing === null) {
        tnc_action_redirect(app_path('pages/purchase/purchase-request-list.php') . '?error=invalid_pr');
    }
    $hasPo = Db::findFirst('purchase_orders', static function (array $r) use ($pr_id): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
    });
    if ($hasPo !== null) {
        tnc_action_redirect(app_path('pages/purchase/purchase-request-list.php') . '?error=pr_has_po');
    }

    $sitesForPr = Db::tableRows('sites');
    $site_id = (int) ($_POST['site_id'] ?? 0);
    if (count($sitesForPr) > 0 && $site_id <= 0) {
        tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id . '&error=need_site');
    }
    $site_name_saved = '';
    if ($site_id > 0) {
        $siteRow = Db::row('sites', (string) $site_id);
        if ($siteRow === null) {
            tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id . '&error=need_site');
        }
        $site_name_saved = trim((string) ($siteRow['name'] ?? ''));
    }

    $pr_number = trim((string) ($existing['pr_number'] ?? ''));
    $created_at = trim((string) ($_POST['created_at'] ?? ''));
    if ($created_at === '') {
        $created_at = (string) ($existing['created_at'] ?? date('Y-m-d'));
    }
    $requested_by = (int) ($_POST['requested_by'] ?? 0);
    if ($requested_by <= 0) {
        $requested_by = (int) ($existing['requested_by'] ?? 0);
    }
    $created_by = (int) ($existing['created_by'] ?? 0);
    $details = trim((string) ($_POST['details'] ?? ''));
    $procurement_type = trim((string) ($existing['request_type'] ?? ($existing['procurement_type'] ?? 'purchase')));
    if ($procurement_type !== 'hire') {
        $procurement_type = 'purchase';
    }

    $hire_contractor_name = '';
    $hire_employer_company_id = (int) ($existing['hire_employer_company_id'] ?? 0);
    $hire_scope_details = '';
    $hire_total_value = 0.0;
    $hire_installment_count = 1;

    if ($procurement_type === 'hire') {
        $hire_contractor_name = trim((string) ($_POST['hire_contractor_name'] ?? ($_POST['contractor_name'] ?? '')));
        $hire_employer_company_id = (int) ($_POST['hire_employer_company_id'] ?? $hire_employer_company_id);
        if ($hire_employer_company_id <= 0) {
            $companies = Db::tableRows('company');
            Db::sortRows($companies, 'id', false);
            $hire_employer_company_id = (int) (($companies[0] ?? [])['id'] ?? 0);
        }
        $hire_scope_details = trim((string) ($_POST['hire_scope_details'] ?? ($_POST['details'] ?? '')));
        $hire_total_value = round((float) str_replace([',', ' '], '', (string) ($_POST['hire_total_value'] ?? ($_POST['contract_value'] ?? '0'))), 2);
        $hire_installment_count = max(1, min(120, (int) ($_POST['hire_installment_count'] ?? ($_POST['installment_total'] ?? 1))));
        if ($hire_contractor_name === '' || $hire_employer_company_id <= 0 || $hire_scope_details === '' || $hire_total_value <= 0) {
            tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id . '&error=hire_invalid');
        }
        $vat_enabled = 0;
        $subtotal = $hire_total_value;
        $vat_amount = 0.0;
        $total_amount = $hire_total_value;
    } else {
        $vat_enabled = !empty($_POST['vat_enabled']) ? 1 : 0;
        $subtotal = 0.0;
        $purchaseLineCount = 0;
        foreach ($_POST['item_description'] ?? [] as $key => $desc) {
            if (!isset($_POST['item_qty'][$key], $_POST['item_price'][$key])) {
                continue;
            }
            if (trim((string) $desc) === '') {
                continue;
            }
            $qty = (float) $_POST['item_qty'][$key];
            if ($qty <= 0) {
                continue;
            }
            $purchaseLineCount++;
            $price = max(0.0, (float) $_POST['item_price'][$key]);
            $discRaw = trim((string) ($_POST['item_discount'][$key] ?? ''));
            $parts = tnc_pr_parse_line_discount($qty, $price, $discRaw);
            $subtotal += $parts['line_total'];
        }
        $subtotal = round($subtotal, 2);
        if ($purchaseLineCount <= 0) {
            tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id . '&error=no_items');
        }
        $vat_mode_post = trim((string) ($_POST['vat_mode'] ?? 'exclusive'));
        $totalsPr = tnc_po_compute_totals($subtotal, $vat_enabled, $vat_mode_post, 'none');
        $subtotal = $totalsPr['subtotal'];
        $vat_amount = $totalsPr['vat'];
        $total_amount = $totalsPr['gross'];
    }

    $quoteAttachmentPath = trim((string) ($existing['quotation_attachment_path'] ?? ''));
    $quoteAttachmentUrl = trim((string) ($existing['quotation_attachment_url'] ?? ''));
    $quoteAttachmentName = trim((string) ($existing['quotation_attachment_name'] ?? ''));
    $quoteAttachmentMime = trim((string) ($existing['quotation_attachment_mime'] ?? ''));
    $quoteAttachmentSize = (int) ($existing['quotation_attachment_size'] ?? 0);

    $wantQuotationUpload = !empty($_POST['quotation_attach']);
    if ($wantQuotationUpload && !empty($_FILES['quotation_file']) && (int) ($_FILES['quotation_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['quotation_file'];
        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id . '&error=upload_failed');
        }

        $tmp = (string) ($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id . '&error=upload_failed');
        }

        $originalName = trim((string) ($f['name'] ?? 'quotation'));
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff'];
        if (!in_array($ext, $allowedExt, true)) {
            tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id . '&error=upload_type');
        }

        $dirAbs = ROOT_PATH . '/uploads/pr-quotations/' . $pr_id;
        if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id . '&error=upload_failed');
        }

        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $safeBase = trim((string) $safeBase, '._-');
        if ($safeBase === '') {
            $safeBase = 'quotation';
        }
        $storedName = $safeBase . '_' . date('Ymd_His') . '.' . $ext;
        $destAbs = $dirAbs . '/' . $storedName;
        if (!@move_uploaded_file($tmp, $destAbs)) {
            tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id . '&error=upload_failed');
        }

        $quoteAttachmentPath = 'uploads/pr-quotations/' . $pr_id . '/' . $storedName;
        $quoteAttachmentUrl = app_path($quoteAttachmentPath);
        $quoteAttachmentName = $originalName;
        $quoteAttachmentMime = (string) ($f['type'] ?? '');
        $quoteAttachmentSize = (int) ($f['size'] ?? 0);
    }

    $vat_mode_stored = 'exclusive';
    if ($procurement_type !== 'hire') {
        $vm = trim((string) ($_POST['vat_mode'] ?? 'exclusive'));
        $vat_mode_stored = $vat_enabled && in_array($vm, ['exclusive', 'inclusive'], true) ? $vm : 'exclusive';
        if (!$vat_enabled) {
            $vat_mode_stored = 'exclusive';
        }
    }

    $beforeSnap = $existing;
    $pr_row = array_merge($existing, [
        'id' => $pr_id,
        'pr_number' => $pr_number,
        'created_at' => $created_at,
        'requested_by' => $requested_by,
        'created_by' => $created_by,
        'details' => $details,
        'site_id' => $site_id,
        'site_name' => $site_name_saved,
        'total_amount' => $total_amount,
        'vat_enabled' => $vat_enabled,
        'vat_mode' => $vat_mode_stored,
        'subtotal_amount' => $subtotal,
        'vat_amount' => $vat_amount,
        'procurement_type' => $procurement_type,
        'request_type' => $procurement_type,
        'contractor_name' => $procurement_type === 'hire' ? $hire_contractor_name : '',
        'contract_value' => $procurement_type === 'hire' ? $hire_total_value : 0.0,
        'installment_total' => $procurement_type === 'hire' ? $hire_installment_count : 1,
        'hire_contractor_name' => $hire_contractor_name,
        'hire_employer_company_id' => $hire_employer_company_id,
        'hire_scope_details' => $hire_scope_details,
        'hire_total_value' => $hire_total_value,
        'hire_installment_count' => $hire_installment_count,
        'quotation_attachment_path' => $quoteAttachmentPath,
        'quotation_attachment_url' => $quoteAttachmentUrl,
        'quotation_attachment_name' => $quoteAttachmentName,
        'quotation_attachment_mime' => $quoteAttachmentMime,
        'quotation_attachment_size' => $quoteAttachmentSize,
    ]);
    Db::setRow('purchase_requests', (string) $pr_id, $pr_row);

    Db::deleteWhereEquals('purchase_request_items', 'pr_id', (string) $pr_id);
    foreach ($_POST['item_description'] ?? [] as $key => $desc) {
        if (!isset($_POST['item_qty'][$key], $_POST['item_price'][$key])) {
            continue;
        }
        $desc = trim((string) $desc);
        if ($desc === '') {
            continue;
        }
        $qty = (float) $_POST['item_qty'][$key];
        if ($qty <= 0) {
            continue;
        }
        $iid = Db::nextNumericId('purchase_request_items', 'id');
        $unit = trim((string) ($_POST['item_unit'][$key] ?? ''));
        $price = max(0.0, (float) $_POST['item_price'][$key]);
        $discRaw = trim((string) ($_POST['item_discount'][$key] ?? ''));
        $parts = tnc_pr_parse_line_discount($qty, $price, $discRaw);
        $total = $parts['line_total'];
        Db::setRow('purchase_request_items', (string) $iid, [
            'id' => $iid,
            'pr_id' => $pr_id,
            'description' => $desc,
            'quantity' => $qty,
            'unit' => $unit,
            'unit_price' => $price,
            'total' => $total,
            'discount_input' => $parts['discount_input'],
            'discount_type' => $parts['discount_type'],
            'discount_value' => $parts['discount_value'],
            'discount_amount' => $parts['discount_amount'],
        ]);
    }

    $prAfterSave = Db::row('purchase_requests', (string) $pr_id);
    $prItemsAfter = [];
    foreach (Db::filter('purchase_request_items', static function (array $r) use ($pr_id): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
    }) as $pi) {
        if (!is_array($pi)) {
            continue;
        }
        $prItemsAfter[] = $pi;
        if (count($prItemsAfter) >= 120) {
            break;
        }
    }
    tnc_audit_log('update', 'purchase_request', (string) $pr_id, $pr_number !== '' ? $pr_number : ('#' . $pr_id), [
        'source' => 'action-handler',
        'action' => 'update_pr',
        'before' => $beforeSnap,
        'after' => $prAfterSave,
        'meta' => ['lines' => $prItemsAfter],
    ]);

    if (trim((string) ($_POST['after_pr_update'] ?? '')) === 'po_from_pr' && $pr_id > 0) {
        tnc_action_redirect(app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&pr_updated=1');
    }

    tnc_action_redirect(app_path('pages/purchase/purchase-request-list.php') . '?updated=1');
}

if ($action === 'delete_pr') {
    if ($id <= 0) {
        tnc_action_redirect( app_path('pages/purchase/purchase-request-list.php') . '?error=invalid_pr');
    }
    $prSnap = Db::row('purchase_requests', (string) $id);
    $prNo = $prSnap !== null ? trim((string) ($prSnap['pr_number'] ?? '')) : '';
    $nestedDel = [];
    foreach (Db::filter('hire_contracts', static fn (array $r): bool => isset($r['pr_id']) && (int) $r['pr_id'] === $id) as $hc) {
        $hcId = (int) ($hc['id'] ?? 0);
        if ($hcId > 0) {
            $nestedDel[] = ['verb' => 'delete', 'entity_type' => 'hire_contract', 'entity_id' => (string) $hcId, 'snapshot' => $hc];
            Db::deleteRow('hire_contracts', (string) $hcId);
        }
    }
    foreach (Db::filter('purchase_orders', static fn (array $r): bool => isset($r['pr_id']) && (int) $r['pr_id'] === $id) as $poDel) {
        $poid = (int) ($poDel['id'] ?? 0);
        if ($poid > 0) {
            $nestedDel[] = ['verb' => 'delete', 'entity_type' => 'purchase_order', 'entity_id' => (string) $poid, 'snapshot' => $poDel];
            Db::deleteWhereEquals('po_payments', 'po_id', (string) $poid);
            Db::deleteWhereEquals('purchase_order_items', 'po_id', (string) $poid);
            Db::deleteRow('purchase_orders', (string) $poid);
        }
    }
    foreach (Db::filter('purchase_request_items', static fn (array $r): bool => isset($r['pr_id']) && (int) $r['pr_id'] === $id) as $pri) {
        $priId = (int) ($pri['id'] ?? 0);
        if ($priId > 0) {
            $nestedDel[] = ['verb' => 'delete', 'entity_type' => 'purchase_request_item', 'entity_id' => (string) $priId, 'snapshot' => $pri];
        }
    }
    Db::deleteWhereEquals('purchase_request_items', 'pr_id', (string) $id);
    Db::deleteRow('purchase_requests', (string) $id);
    tnc_audit_log('delete', 'purchase_request', (string) $id, $prNo !== '' ? $prNo : ('#' . $id), [
        'source' => 'action-handler',
        'action' => 'delete_pr',
        'before' => $prSnap,
        'nested' => $nestedDel,
    ]);
    tnc_action_redirect( app_path('pages/purchase/purchase-request-list.php') . '?deleted=1');
}

if ($action === 'save_standalone_hire_contract' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $contractor = trim((string) ($_POST['contractor_name'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $amount = (float) ($_POST['contract_amount'] ?? 0);
    $installments = max(1, min(120, (int) ($_POST['installment_total'] ?? 1)));
    if ($contractor === '' || $title === '' || $amount <= 0) {
        tnc_action_redirect( app_path('pages/hire-contracts/hire-contract-create.php') . '?error=required');
    }
    $docNo = Purchase::nextHireContractNumber();
    $contractId = Db::nextNumericId('hire_contracts', 'id');
    $now = date('Y-m-d H:i:s');
    Db::setRow('hire_contracts', (string) $contractId, [
        'id' => $contractId,
        'pr_id' => 0,
        'pr_number' => $docNo,
        'contractor_name' => $contractor,
        'title' => $title,
        'contract_amount' => round($amount, 2),
        'installment_total' => $installments,
        'paid_installments' => 0,
        'paid_amount' => 0,
        'remaining_amount' => round($amount, 2),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $hcAfterCreate = Db::row('hire_contracts', (string) $contractId);
    tnc_audit_log('create', 'hire_contract', (string) $contractId, $docNo . ' — ' . $contractor, [
        'source' => 'action-handler',
        'action' => 'save_standalone_hire_contract',
        'after' => $hcAfterCreate,
    ]);
    tnc_action_redirect( app_path('pages/hire-contracts/hire-contract-view.php') . '?id=' . $contractId . '&created=1');
}

// --- PO from PR ---
if ($action === 'create_po_from_pr') {
    tnc_require_finance_role();
    $pr_id = (int) ($_POST['pr_id'] ?? 0);
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $hire_contract_id = (int) ($_POST['hire_contract_id'] ?? 0);
    $po_number = Purchase::generatePONumber();
    $created_by = (int) $_SESSION['user_id'];

    $pr_row = Db::row('purchase_requests', (string) $pr_id);
    if ($pr_row === null) {
        tnc_action_redirect( app_path('pages/purchase/purchase-request-list.php') . '?error=pr_not_found');
    }

    $reqType = trim((string) ($pr_row['request_type'] ?? 'purchase'));
    $isHirePr = ($reqType === 'hire');

    if (!$isHirePr) {
        $dup = Db::findFirst('purchase_orders', static function (array $r) use ($pr_id): bool {
            return $pr_id > 0 && isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
        });
        if ($dup !== null) {
            tnc_action_redirect( app_path('pages/purchase/purchase-order-view.php') . '?id=' . (int) ($dup['id'] ?? 0));
        }
        if ($supplier_id <= 0) {
            tnc_action_redirect(app_path('pages/purchase/purchase-order-create.php') . '?pr_id=' . $pr_id . '&error=supplier');
        }
    }

    if ($isHirePr) {
        $hcId = $hire_contract_id;
        if ($hcId <= 0) {
            $foundHc = Db::findFirst('hire_contracts', static function (array $r) use ($pr_id): bool {
                return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
            });
            $hcId = $foundHc !== null ? (int) ($foundHc['id'] ?? 0) : 0;
        }
        if ($hcId <= 0) {
            tnc_action_redirect( app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&error=contract');
        }
        $hc = Db::row('hire_contracts', (string) $hcId);
        if ($hc === null || (int) ($hc['pr_id'] ?? 0) !== $pr_id) {
            tnc_action_redirect( app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&error=contract');
        }

        $installmentNo = (int) ($_POST['installment_no'] ?? 0);
        if ($installmentNo < 1) {
            tnc_action_redirect( app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&error=invalid_installment');
        }
        foreach (Db::tableRows('purchase_orders') as $poEx) {
            if ((int) ($poEx['pr_id'] ?? 0) !== $pr_id) {
                continue;
            }
            if (trim((string) ($poEx['order_type'] ?? 'purchase')) !== 'hire') {
                continue;
            }
            if ((int) ($poEx['installment_no'] ?? 0) === $installmentNo) {
                tnc_action_redirect( app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&error=duplicate_installment');
            }
        }

        $hireSubtotal = 0.0;
        foreach ($_POST['hire_description'] ?? [] as $key => $desc) {
            if (!isset($_POST['hire_qty'][$key], $_POST['hire_unit_price'][$key])) {
                continue;
            }
            if (trim((string) $desc) === '') {
                continue;
            }
            $hireSubtotal += (float) $_POST['hire_qty'][$key] * (float) $_POST['hire_unit_price'][$key];
        }
        $hireSubtotal = round($hireSubtotal, 2);
        if ($hireSubtotal <= 0) {
            tnc_action_redirect( app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&error=invalid_hire_rows');
        }

        $vat_en = !empty($_POST['vat_enabled']) ? 1 : 0;
        $vat_amt = $vat_en ? round($hireSubtotal * 0.07, 2) : 0.0;
        $gross = round($hireSubtotal + $vat_amt, 2);
        $retRaw = trim((string) ($_POST['retention_value'] ?? '0'));
        $retRaw = str_replace('%', '', $retRaw);
        $retention = max(0.0, round((float) $retRaw, 2));
        $payable = max(0.0, round($gross - $retention, 2));
        if ($payable <= 0) {
            tnc_action_redirect( app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&error=invalid_installment_amount');
        }

        $installmentTotal = max(1, (int) ($hc['installment_total'] ?? 1));
        $contractorName = trim((string) ($hc['contractor_name'] ?? ($pr_row['contractor_name'] ?? '')));
        $hirePrSiteId = (int) ($pr_row['site_id'] ?? 0);
        $hirePrSiteName = trim((string) ($pr_row['site_name'] ?? ''));
        if ($hirePrSiteName === '' && $hirePrSiteId > 0) {
            $hsr = Db::row('sites', (string) $hirePrSiteId);
            if (is_array($hsr)) {
                $hirePrSiteName = trim((string) ($hsr['name'] ?? ''));
            }
        }

        $po_id = Db::nextNumericId('purchase_orders', 'id');
        Db::setRow('purchase_orders', (string) $po_id, [
            'id' => $po_id,
            'po_number' => $po_number,
            'pr_id' => $pr_id,
            'hire_contract_id' => $hcId,
            'supplier_id' => $supplier_id,
            'created_at' => date('Y-m-d'),
            'issue_date' => date('Y-m-d'),
            'total_amount' => $gross,
            'status' => 'ordered',
            'created_by' => $created_by,
            'vat_enabled' => $vat_en,
            'subtotal_amount' => $hireSubtotal,
            'vat_amount' => $vat_amt,
            'order_type' => 'hire',
            'installment_no' => $installmentNo,
            'installment_total' => $installmentTotal,
            'contractor_name' => $contractorName,
            'reference_pr_number' => (string) ($pr_row['pr_number'] ?? ''),
            'gross_amount' => $gross,
            'payable_amount' => $payable,
            'retention_type' => $retention > 0 ? 'fixed' : 'none',
            'retention_amount' => $retention,
            'withholding_type' => 'none',
            'withholding_amount' => 0,
            'site_id' => $hirePrSiteId,
            'site_name' => $hirePrSiteName,
        ]);

        foreach ($_POST['hire_description'] ?? [] as $key => $desc) {
            if (!isset($_POST['hire_qty'][$key], $_POST['hire_unit_price'][$key])) {
                continue;
            }
            $desc = trim((string) $desc);
            if ($desc === '') {
                continue;
            }
            $iid = Db::nextNumericId('purchase_order_items', 'id');
            $qty = (float) $_POST['hire_qty'][$key];
            $price = (float) $_POST['hire_unit_price'][$key];
            $lineTotal = round($qty * $price, 2);
            Db::setRow('purchase_order_items', (string) $iid, [
                'id' => $iid,
                'po_id' => $po_id,
                'description' => $desc,
                'quantity' => $qty,
                'unit' => '',
                'unit_price' => $price,
                'total' => $lineTotal,
            ]);
        }
        if (method_exists(Purchase::class, 'seedPoPayments')) {
            Purchase::seedPoPayments($po_id, $payable, $hcId);
        }
        tnc_audit_purchase_order_created($po_id, 'create_po_from_pr_hire');
        renderPoCreatedPopupAndRedirect((string) $po_number);
    }

    if ($hire_contract_id > 0) {
        $hc = Db::row('hire_contracts', (string) $hire_contract_id);
        if ($hc === null || (int) ($hc['pr_id'] ?? 0) !== $pr_id) {
            tnc_action_redirect( app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&error=contract');
        }
    }

    $total_amount = (float) ($pr_row['total_amount'] ?? 0);
    $vat_en = isset($pr_row['vat_enabled']) ? (int) $pr_row['vat_enabled'] : 0;
    $vat_amt = isset($pr_row['vat_amount']) ? (float) $pr_row['vat_amount'] : 0.0;
    if (array_key_exists('subtotal_amount', $pr_row) && $pr_row['subtotal_amount'] !== null && $pr_row['subtotal_amount'] !== '') {
        $sub_amt = (float) $pr_row['subtotal_amount'];
    } else {
        $sub_amt = round($total_amount - $vat_amt, 2);
    }

    $hasQt = !empty($_POST['has_qt']);
    $quotation_number = $hasQt ? mb_substr(trim((string) ($_POST['quotation_number'] ?? '')), 0, 120) : '';
    $quotation_date = $hasQt ? trim((string) ($_POST['quotation_date'] ?? '')) : '';
    if ($quotation_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $quotation_date)) {
        $quotation_date = '';
    }
    $quotation_note = $hasQt ? mb_substr(trim((string) ($_POST['quotation_note'] ?? '')), 0, 500) : '';
    $po_note = mb_substr(trim((string) ($_POST['po_note'] ?? '')), 0, 500);

    $issue_date = trim((string) ($_POST['issue_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) {
        $issue_date = date('Y-m-d');
    }

    $prSiteId = (int) ($pr_row['site_id'] ?? 0);
    $prSiteName = trim((string) ($pr_row['site_name'] ?? ''));
    if ($prSiteName === '' && $prSiteId > 0) {
        $siteRowPo = Db::row('sites', (string) $prSiteId);
        if (is_array($siteRowPo)) {
            $prSiteName = trim((string) ($siteRowPo['name'] ?? ''));
        }
    }

    $po_id = Db::nextNumericId('purchase_orders', 'id');
    Db::setRow('purchase_orders', (string) $po_id, [
        'id' => $po_id,
        'po_number' => $po_number,
        'pr_id' => $pr_id,
        'hire_contract_id' => $hire_contract_id,
        'supplier_id' => $supplier_id,
        'created_at' => date('Y-m-d'),
        'issue_date' => $issue_date,
        'reference_pr_number' => trim((string) ($pr_row['pr_number'] ?? '')),
        'quotation_number' => $quotation_number,
        'quotation_date' => $quotation_date,
        'quotation_note' => $quotation_note,
        'po_note' => $po_note,
        'total_amount' => $total_amount,
        'status' => 'ordered',
        'created_by' => $created_by,
        'vat_enabled' => $vat_en,
        'vat_mode' => in_array(trim((string) ($pr_row['vat_mode'] ?? 'exclusive')), ['exclusive', 'inclusive'], true)
            ? trim((string) ($pr_row['vat_mode'] ?? 'exclusive'))
            : 'exclusive',
        'subtotal_amount' => $sub_amt,
        'vat_amount' => $vat_amt,
        'order_type' => 'purchase',
        'site_id' => $prSiteId,
        'site_name' => $prSiteName,
    ]);

    foreach (Db::filter('purchase_request_items', static fn (array $r): bool => isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id) as $item) {
        $iid = Db::nextNumericId('purchase_order_items', 'id');
        Db::setRow('purchase_order_items', (string) $iid, [
            'id' => $iid,
            'po_id' => $po_id,
            'description' => $item['description'] ?? '',
            'quantity' => $item['quantity'] ?? 0,
            'unit' => $item['unit'] ?? '',
            'unit_price' => $item['unit_price'] ?? 0,
            'total' => $item['total'] ?? 0,
            'discount_input' => trim((string) ($item['discount_input'] ?? '')),
            'discount_type' => trim((string) ($item['discount_type'] ?? 'amount')) ?: 'amount',
            'discount_value' => (float) ($item['discount_value'] ?? 0),
            'discount_amount' => (float) ($item['discount_amount'] ?? 0),
        ]);
    }
    if (method_exists(Purchase::class, 'seedPoPayments')) {
        Purchase::seedPoPayments($po_id, $total_amount, $hire_contract_id > 0 ? $hire_contract_id : null);
    }
    tnc_audit_purchase_order_created($po_id, 'create_po_from_pr_purchase');
    renderPoCreatedPopupAndRedirect((string) $po_number);
}

// --- PO โดยตรง (ไม่อิง PR) ---
if ($action === 'create_po_direct') {
    tnc_require_finance_role();
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $hire_contract_id = (int) ($_POST['hire_contract_id'] ?? 0);
    $pr_id_link = (int) ($_POST['pr_id'] ?? 0);
    $vat_enabled = !empty($_POST['vat_enabled']) ? 1 : 0;
    $created_by = (int) $_SESSION['user_id'];
    $po_number = Purchase::generatePONumber();
    $hireFallback = $hire_contract_id > 0
        ? app_path('pages/purchase/purchase-order-from-hire-contract.php') . '?hire_contract_id=' . $hire_contract_id
        : ($pr_id_link > 0
            ? app_path('pages/purchase/purchase-order-create.php') . '?pr_id=' . $pr_id_link
            : app_path('pages/purchase/purchase-request-list.php'));
    $hireFbSep = str_contains($hireFallback, '?') ? '&' : '?';

    if ($pr_id_link <= 0 && $hire_contract_id <= 0) {
        tnc_action_redirect(app_path('pages/purchase/purchase-request-list.php'));
    }

    $hc = null;
    if ($hire_contract_id > 0) {
        $hc = Db::row('hire_contracts', (string) $hire_contract_id);
        if ($hc === null) {
            tnc_action_redirect($hireFallback . $hireFbSep . 'error=contract');
        }
        if ($supplier_id <= 0) {
            tnc_action_redirect($hireFallback . $hireFbSep . 'error=po_supplier');
        }
        $installmentNo = (int) ($_POST['installment_no'] ?? 0);
        if ($installmentNo < 1) {
            tnc_action_redirect($hireFallback . $hireFbSep . 'error=invalid_installment');
        }
        foreach (Db::tableRows('purchase_orders') as $poEx) {
            if ((int) ($poEx['hire_contract_id'] ?? 0) !== $hire_contract_id) {
                continue;
            }
            if ((int) ($poEx['installment_no'] ?? 0) === $installmentNo) {
                tnc_action_redirect($hireFallback . $hireFbSep . 'error=duplicate_installment');
            }
        }
    }

    $subtotal = 0.0;
    foreach ($_POST['item_description'] ?? [] as $key => $desc) {
        if (!isset($_POST['item_qty'][$key], $_POST['item_price'][$key])) {
            continue;
        }
        if (trim((string) $desc) === '') {
            continue;
        }
        $subtotal += (float) $_POST['item_qty'][$key] * (float) $_POST['item_price'][$key];
    }
    if ($subtotal <= 0 && $hire_contract_id > 0) {
        foreach ($_POST['hire_description'] ?? [] as $key => $desc) {
            if (!isset($_POST['hire_qty'][$key], $_POST['hire_unit_price'][$key])) {
                continue;
            }
            if (trim((string) $desc) === '') {
                continue;
            }
            $subtotal += (float) $_POST['hire_qty'][$key] * (float) $_POST['hire_unit_price'][$key];
        }
    }
    $lineSum = round($subtotal, 2);
    if ($lineSum <= 0) {
        tnc_action_redirect($hireFallback . $hireFbSep . 'error=no_items');
    }

    $vat_mode_post = trim((string) ($_POST['vat_mode'] ?? 'exclusive'));
    if (!in_array($vat_mode_post, ['exclusive', 'inclusive'], true)) {
        $vat_mode_post = 'exclusive';
    }
    if ($pr_id_link > 0) {
        $prRowVat = Db::rowByIdField('purchase_requests', $pr_id_link);
        if ($prRowVat === null) {
            tnc_action_redirect(app_path('pages/purchase/purchase-request-list.php') . '?error=not_found');
        }
        $vat_enabled = (int) ($prRowVat['vat_enabled'] ?? 0) === 1 ? 1 : 0;
        $vmPr = trim((string) ($prRowVat['vat_mode'] ?? 'exclusive'));
        $vat_mode_post = in_array($vmPr, ['exclusive', 'inclusive'], true) ? $vmPr : 'exclusive';
    }
    $wht_post = trim((string) ($_POST['withholding_type'] ?? 'none'));
    if ($wht_post !== 'wht3') {
        $wht_post = 'none';
    }

    $isHireFlow = $hire_contract_id > 0 && $hc !== null;
    $totals = tnc_po_compute_totals($lineSum, $vat_enabled, $vat_mode_post, $isHireFlow ? 'none' : $wht_post);
    $vat_amt = $totals['vat'];
    $gross = $totals['gross'];
    $subtotal_db = $totals['subtotal'];

    $issue_date = trim((string) ($_POST['issue_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) {
        $issue_date = date('Y-m-d');
    }

    $hasQuotation = !empty($_POST['has_quotation']);
    $quotFilePending = !empty($_FILES['quotation_file']) && (int) ($_FILES['quotation_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $quotation_number = '';
    if ($hasQuotation) {
        $quotation_number = mb_substr(trim((string) ($_POST['quotation_number'] ?? '')), 0, 120);
        if ($quotation_number === '' && !$quotFilePending) {
            tnc_action_redirect($hireFallback . $hireFbSep . 'error=quotation_required');
        }
        if ($quotFilePending && (int) ($_FILES['quotation_file']['error'] ?? 0) !== UPLOAD_ERR_OK) {
            tnc_action_redirect($hireFallback . $hireFbSep . 'error=quotation_upload_failed');
        }
    }
    $quotation_note = mb_substr(trim((string) ($_POST['quotation_note'] ?? '')), 0, 500);
    $po_note_direct = mb_substr(trim((string) ($_POST['po_note'] ?? '')), 0, 500);

    $retention = 0.0;
    $payable = $gross;
    $seedAmount = $totals['net'];
    $hireExtra = [];

    if ($isHireFlow) {
        $retRaw = trim((string) ($_POST['retention_value'] ?? '0'));
        $retRaw = str_replace('%', '', $retRaw);
        $retention = max(0.0, round((float) $retRaw, 2));
        $payable = max(0.0, round($gross - $retention, 2));
        if ($payable <= 0) {
            tnc_action_redirect($hireFallback . $hireFbSep . 'error=invalid_installment_amount');
        }
        $seedAmount = $payable;
        $installmentNo = (int) ($_POST['installment_no'] ?? 0);
        $installmentTotal = max(1, (int) ($hc['installment_total'] ?? 1));
        $contractorName = trim((string) ($hc['contractor_name'] ?? ''));
        $hireExtra = [
            'order_type' => 'hire',
            'installment_no' => $installmentNo,
            'installment_total' => $installmentTotal,
            'contractor_name' => $contractorName,
            'reference_pr_number' => (string) ($hc['pr_number'] ?? ''),
            'gross_amount' => $gross,
            'payable_amount' => $payable,
            'retention_type' => $retention > 0 ? 'fixed' : 'none',
            'retention_amount' => $retention,
            'withholding_type' => 'none',
            'withholding_amount' => 0,
        ];
    }

    $po_id = Db::nextNumericId('purchase_orders', 'id');

    $quoteAttachmentPath = '';
    $quoteAttachmentName = '';
    $quoteAttachmentMime = '';
    $quoteAttachmentSize = 0;
    if ($hasQuotation && $quotFilePending) {
        $f = $_FILES['quotation_file'];
        $tmp = (string) ($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            tnc_action_redirect($hireFallback . $hireFbSep . 'error=quotation_upload_failed');
        }
        $originalName = trim((string) ($f['name'] ?? 'quotation'));
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff'];
        if (!in_array($ext, $allowedExt, true)) {
            tnc_action_redirect($hireFallback . $hireFbSep . 'error=quotation_upload_type');
        }
        $dirAbs = ROOT_PATH . '/uploads/po-quotations/' . $po_id;
        if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            tnc_action_redirect($hireFallback . $hireFbSep . 'error=quotation_upload_failed');
        }
        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $safeBase = trim((string) $safeBase, '._-');
        if ($safeBase === '') {
            $safeBase = 'quotation';
        }
        $storedName = $safeBase . '_' . date('Ymd_His') . '.' . $ext;
        $destAbs = $dirAbs . '/' . $storedName;
        if (!@move_uploaded_file($tmp, $destAbs)) {
            tnc_action_redirect($hireFallback . $hireFbSep . 'error=quotation_upload_failed');
        }
        $quoteAttachmentPath = 'uploads/po-quotations/' . $po_id . '/' . $storedName;
        $quoteAttachmentName = $originalName;
        $quoteAttachmentMime = (string) ($f['type'] ?? '');
        $quoteAttachmentSize = (int) ($f['size'] ?? 0);
    }

    Db::setRow('purchase_orders', (string) $po_id, array_merge([
        'id' => $po_id,
        'po_number' => $po_number,
        'pr_id' => $pr_id_link > 0 ? $pr_id_link : 0,
        'hire_contract_id' => $hire_contract_id,
        'supplier_id' => $supplier_id,
        'created_at' => date('Y-m-d'),
        'issue_date' => $issue_date,
        'quotation_number' => $quotation_number,
        'quotation_note' => $quotation_note,
        'po_note' => $po_note_direct,
        'quotation_attachment_path' => $quoteAttachmentPath,
        'quotation_attachment_name' => $quoteAttachmentName,
        'quotation_attachment_mime' => $quoteAttachmentMime,
        'quotation_attachment_size' => $quoteAttachmentSize,
        'total_amount' => $isHireFlow ? $gross : $totals['net'],
        'status' => 'ordered',
        'created_by' => $created_by,
        'vat_enabled' => $vat_enabled,
        'vat_mode' => $totals['vat_mode'],
        'subtotal_amount' => $subtotal_db,
        'vat_amount' => $vat_amt,
        'gross_amount' => $gross,
        'withholding_type' => $isHireFlow ? 'none' : $totals['withholding_type'],
        'withholding_amount' => $isHireFlow ? 0.0 : $totals['wht'],
    ], $hireExtra));

    $useHireLines = $hire_contract_id > 0 && (count(array_filter($_POST['item_description'] ?? [], static fn ($d): bool => trim((string) $d) !== '')) === 0);
    if ($useHireLines) {
        foreach ($_POST['hire_description'] ?? [] as $key => $desc) {
            if (!isset($_POST['hire_qty'][$key], $_POST['hire_unit_price'][$key])) {
                continue;
            }
            $desc = trim((string) $desc);
            if ($desc === '') {
                continue;
            }
            $iid = Db::nextNumericId('purchase_order_items', 'id');
            $qty = (float) $_POST['hire_qty'][$key];
            $price = (float) $_POST['hire_unit_price'][$key];
            $lineTotal = round($qty * $price, 2);
            Db::setRow('purchase_order_items', (string) $iid, [
                'id' => $iid,
                'po_id' => $po_id,
                'description' => $desc,
                'quantity' => $qty,
                'unit' => '',
                'unit_price' => $price,
                'total' => $lineTotal,
            ]);
        }
    } else {
        foreach ($_POST['item_description'] ?? [] as $key => $desc) {
            if (!isset($_POST['item_qty'][$key], $_POST['item_price'][$key])) {
                continue;
            }
            $desc = trim((string) $desc);
            if ($desc === '') {
                continue;
            }
            $iid = Db::nextNumericId('purchase_order_items', 'id');
            $qty = (float) $_POST['item_qty'][$key];
            $unit = trim((string) ($_POST['item_unit'][$key] ?? ''));
            $price = (float) $_POST['item_price'][$key];
            $lineTotal = round($qty * $price, 2);
            Db::setRow('purchase_order_items', (string) $iid, [
                'id' => $iid,
                'po_id' => $po_id,
                'description' => $desc,
                'quantity' => $qty,
                'unit' => $unit,
                'unit_price' => $price,
                'total' => $lineTotal,
            ]);
        }
    }
    if (method_exists(Purchase::class, 'seedPoPayments')) {
        Purchase::seedPoPayments($po_id, $seedAmount, $hire_contract_id > 0 ? $hire_contract_id : null);
    }
    tnc_audit_purchase_order_created($po_id, 'create_po_direct');
    renderPoCreatedPopupAndRedirect((string) $po_number);
}

// --- แก้ไข PO โดยตรง (purchase-order-edit.php) ---
if ($action === 'update_po_direct' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_finance_role();
    $listUrl = app_path('pages/purchase/purchase-order-list.php');
    $editUrl = app_path('pages/purchase/purchase-order-edit.php');
    $po_id = (int) ($_GET['id'] ?? $_POST['po_id'] ?? $_POST['id'] ?? 0);
    if ($po_id <= 0) {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    $pk = Db::pkForLogicalId('purchase_orders', $po_id);
    $existing = Db::row('purchase_orders', $pk);
    if ($existing === null) {
        tnc_action_redirect($listUrl . '?error=not_found');
    }
    if (strtolower(trim((string) ($existing['status'] ?? ''))) === 'cancelled') {
        tnc_action_redirect($listUrl . '?error=po_cancelled');
    }
    if (strtolower(trim((string) ($existing['payment_status'] ?? 'unpaid'))) === 'paid') {
        tnc_action_redirect($listUrl . '?error=po_paid');
    }
    if (trim((string) ($existing['order_type'] ?? 'purchase')) === 'hire' && (int) ($existing['hire_contract_id'] ?? 0) > 0) {
        tnc_action_redirect($editUrl . '?id=' . $po_id . '&error=hire_po');
    }

    $lineSum = 0.0;
    foreach ($_POST['item_description'] ?? [] as $key => $desc) {
        if (!isset($_POST['item_qty'][$key], $_POST['item_price'][$key])) {
            continue;
        }
        if (trim((string) $desc) === '') {
            continue;
        }
        $lineSum += (float) $_POST['item_qty'][$key] * (float) $_POST['item_price'][$key];
    }
    $lineSum = round($lineSum, 2);
    if ($lineSum <= 0) {
        tnc_action_redirect($editUrl . '?id=' . $po_id . '&error=no_items');
    }

    $po_pr_id = (int) ($existing['pr_id'] ?? 0);
    $vat_enabled = !empty($_POST['vat_enabled']) ? 1 : 0;
    $vat_mode_post = trim((string) ($_POST['vat_mode'] ?? 'exclusive'));
    if (!in_array($vat_mode_post, ['exclusive', 'inclusive'], true)) {
        $vat_mode_post = 'exclusive';
    }
    if ($po_pr_id > 0) {
        $prRowPoEdit = Db::rowByIdField('purchase_requests', $po_pr_id);
        if ($prRowPoEdit !== null) {
            $vat_enabled = (int) ($prRowPoEdit['vat_enabled'] ?? 0) === 1 ? 1 : 0;
            $vmPoPr = trim((string) ($prRowPoEdit['vat_mode'] ?? 'exclusive'));
            $vat_mode_post = in_array($vmPoPr, ['exclusive', 'inclusive'], true) ? $vmPoPr : 'exclusive';
        }
    }
    $totals = tnc_po_compute_totals($lineSum, $vat_enabled, $vat_mode_post, 'none');

    $issue_date = trim((string) ($_POST['issue_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) {
        $issue_date = (string) ($existing['issue_date'] ?? date('Y-m-d'));
    }
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);

    $beforeSnap = $existing;
    Db::setRow('purchase_orders', $pk, array_merge($existing, [
        'issue_date' => $issue_date,
        'supplier_id' => $supplier_id,
        'quotation_number' => mb_substr(trim((string) ($existing['quotation_number'] ?? '')), 0, 120),
        'quotation_note' => mb_substr(trim((string) ($_POST['quotation_note'] ?? ($existing['quotation_note'] ?? ''))), 0, 500),
        'po_note' => mb_substr(trim((string) ($_POST['po_note'] ?? ($existing['po_note'] ?? ''))), 0, 500),
        'total_amount' => $totals['net'],
        'gross_amount' => $totals['gross'],
        'subtotal_amount' => $totals['subtotal'],
        'vat_amount' => $totals['vat'],
        'vat_enabled' => $vat_enabled,
        'vat_mode' => $totals['vat_mode'],
        'withholding_type' => $totals['withholding_type'],
        'withholding_amount' => $totals['wht'],
    ]));

    tnc_po_delete_line_items($po_id);
    foreach ($_POST['item_description'] ?? [] as $key => $desc) {
        if (!isset($_POST['item_qty'][$key], $_POST['item_price'][$key])) {
            continue;
        }
        $desc = trim((string) $desc);
        if ($desc === '') {
            continue;
        }
        $iid = Db::nextNumericId('purchase_order_items', 'id');
        $qty = (float) $_POST['item_qty'][$key];
        $unit = trim((string) ($_POST['item_unit'][$key] ?? ''));
        $price = (float) $_POST['item_price'][$key];
        $lineTotal = round($qty * $price, 2);
        Db::setRow('purchase_order_items', (string) $iid, [
            'id' => $iid,
            'po_id' => $po_id,
            'description' => $desc,
            'quantity' => $qty,
            'unit' => $unit,
            'unit_price' => $price,
            'total' => $lineTotal,
        ]);
    }

    foreach (Db::filter('po_payments', static fn (array $r): bool => (int) ($r['po_id'] ?? 0) === $po_id) as $payRow) {
        $payId = (int) ($payRow['id'] ?? 0);
        if ($payId <= 0) {
            continue;
        }
        $st = strtolower(trim((string) ($payRow['status'] ?? 'unpaid')));
        if ($st !== 'paid') {
            $payPk = Db::pkForLogicalId('po_payments', $payId);
            Db::mergeRow('po_payments', $payPk, ['amount' => $totals['net']]);
        }
    }

    $afterSnap = Db::row('purchase_orders', $pk);
    $poNo = $afterSnap !== null ? trim((string) ($afterSnap['po_number'] ?? '')) : '';
    tnc_audit_log('update', 'purchase_order', (string) $po_id, $poNo !== '' ? $poNo : ('#' . $po_id), [
        'source' => 'action-handler',
        'action' => 'update_po_direct',
        'before' => $beforeSnap,
        'after' => $afterSnap,
    ]);

    tnc_action_redirect($listUrl . '?updated=1');
}

// --- ยกเลิกใบสั่งซื้อ (สถานะ cancelled) ---
if ($action === 'cancel_purchase_order' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_finance_role();
    $listUrl = app_path('pages/purchase/purchase-order-list.php');
    $viewUrl = app_path('pages/purchase/purchase-order-view.php');
    $po_id = (int) ($_POST['po_id'] ?? 0);
    if ($po_id <= 0) {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    $pk = Db::pkForLogicalId('purchase_orders', $po_id);
    $existing = Db::row('purchase_orders', $pk);
    if ($existing === null) {
        tnc_action_redirect($listUrl . '?error=not_found');
    }
    $st = strtolower(trim((string) ($existing['status'] ?? 'ordered')));
    if ($st === 'cancelled') {
        tnc_action_redirect($listUrl . '?error=already_cancelled');
    }
    if (strtolower(trim((string) ($existing['payment_status'] ?? 'unpaid'))) === 'paid') {
        $returnToPaid = trim((string) ($_POST['return_to'] ?? ''));
        if ($returnToPaid === 'view') {
            tnc_action_redirect($viewUrl . '?id=' . $po_id . '&error=po_paid');
        }
        tnc_action_redirect($listUrl . '?error=po_paid');
    }
    $beforeSnap = $existing;
    Db::mergeRow('purchase_orders', $pk, [
        'status' => 'cancelled',
        'cancelled_at' => date('Y-m-d H:i:s'),
        'cancelled_by' => (int) ($_SESSION['user_id'] ?? 0),
    ]);
    $afterSnap = Db::row('purchase_orders', $pk);
    $poNo = $afterSnap !== null ? trim((string) ($afterSnap['po_number'] ?? '')) : '';
    tnc_audit_log('update', 'purchase_order', (string) $po_id, $poNo !== '' ? $poNo : ('#' . $po_id), [
        'source' => 'action-handler',
        'action' => 'cancel_purchase_order',
        'before' => $beforeSnap,
        'after' => $afterSnap,
    ]);
    $returnTo = trim((string) ($_POST['return_to'] ?? ''));
    if ($returnTo === 'view') {
        tnc_action_redirect($viewUrl . '?id=' . $po_id . '&cancelled=1');
    }
    tnc_action_redirect($listUrl . '?cancelled=1');
}

/** รายการ PO: แนบสลิป + ตั้งสถานะจ่ายแล้ว (purchase_orders.payment_slip_path) */
if ($action === 'update_po_payment_status' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_finance_role();
    $listUrl = app_path('pages/purchase/purchase-order-list.php');
    $po_id = (int) ($_POST['po_id'] ?? 0);
    $payment_status = strtolower(trim((string) ($_POST['payment_status'] ?? '')));
    if ($po_id <= 0 || $payment_status !== 'paid') {
        tnc_action_redirect( $listUrl . '?error=invalid');
    }
    $po = Db::row('purchase_orders', (string) $po_id);
    if ($po === null) {
        tnc_action_redirect( $listUrl . '?error=invalid');
    }
    if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
        tnc_action_redirect($listUrl . '?error=po_cancelled');
    }
    $payment_method = strtolower(trim((string) ($_POST['payment_method'] ?? 'transfer')));
    if (!in_array($payment_method, ['cash', 'transfer'], true)) {
        $payment_method = 'transfer';
    }
    $payment_cash_paid_by = trim((string) ($_POST['payment_cash_paid_by'] ?? ''));
    if ($payment_method === 'cash') {
        $payment_cash_paid_by = mb_substr($payment_cash_paid_by, 0, 255);
        if ($payment_cash_paid_by === '') {
            tnc_action_redirect($listUrl . '?error=cash_paid_by_required');
        }
    } else {
        $payment_cash_paid_by = '';
    }
    $newSlipPaths = tnc_po_payment_slip_upload_many($po_id, 'payment_slips');
    if ($newSlipPaths === [] && !empty($_FILES['payment_slip']) && (int) ($_FILES['payment_slip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $legacy = tnc_po_payment_slip_upload_one($po_id, $_FILES['payment_slip']);
        if ($legacy !== null) {
            $newSlipPaths[] = $legacy;
        }
    }
    if ($newSlipPaths === [] && $payment_method !== 'cash') {
        tnc_action_redirect($listUrl . '?error=payment_slip_required');
    }
    if ($newSlipPaths === [] && $payment_method === 'cash') {
        $poBeforePay = Db::row('purchase_orders', (string) $po_id);
        Db::mergeRow('purchase_orders', (string) $po_id, [
            'payment_status' => 'paid',
            'payment_marked_paid_at' => date('Y-m-d H:i:s'),
            'payment_method' => $payment_method,
            'payment_cash_paid_by' => $payment_cash_paid_by,
        ]);
        $poAfterPay = Db::row('purchase_orders', (string) $po_id);
        $poNoMark = $poAfterPay !== null ? trim((string) ($poAfterPay['po_number'] ?? '')) : '';
        tnc_audit_log('update', 'purchase_order', (string) $po_id, $poNoMark !== '' ? ('จ่ายแล้ว ' . $poNoMark) : 'ทำเครื่องหมายจ่าย PO', [
            'source' => 'action-handler',
            'action' => 'update_po_payment_status',
            'before' => $poBeforePay,
            'after' => $poAfterPay,
            'meta' => ['payment_method' => 'cash', 'payment_cash_paid_by' => $payment_cash_paid_by],
        ]);
        $uidPay = (int) ($_SESSION['user_id'] ?? 0);
        $autoBillId = $poAfterPay !== null ? tnc_purchase_bill_create_from_paid_purchase_order($poAfterPay, $uidPay) : null;
        if ($autoBillId !== null && $autoBillId > 0) {
            $billMonth = date('Y-m');
            $paidTs = trim((string) ($poAfterPay['payment_marked_paid_at'] ?? ''));
            if ($paidTs !== '' && preg_match('/^(\d{4}-\d{2})/', $paidTs, $mm) === 1) {
                $billMonth = $mm[1];
            }
            tnc_action_redirect($listUrl . '?payment_saved=1&auto_bill=1&bill_month=' . rawurlencode($billMonth) . '&bill_id=' . (int) $autoBillId . '&print_po_id=' . $po_id);
        }
        tnc_action_redirect($listUrl . '?payment_saved=1&print_po_id=' . $po_id);
    }
    $poBeforePay = Db::row('purchase_orders', (string) $po_id);
    $existingPaths = $poBeforePay !== null ? tnc_po_payment_slip_paths($poBeforePay) : [];
    $allPaths = array_values(array_unique(array_merge($existingPaths, $newSlipPaths)));
    Db::mergeRow('purchase_orders', (string) $po_id, [
        'payment_status' => 'paid',
        'payment_slip_paths' => json_encode($allPaths, JSON_UNESCAPED_UNICODE),
        'payment_slip_path' => $allPaths[0] ?? '',
        'payment_marked_paid_at' => date('Y-m-d H:i:s'),
        'payment_method' => $payment_method,
        'payment_cash_paid_by' => $payment_cash_paid_by,
    ]);
    $poAfterPay = Db::row('purchase_orders', (string) $po_id);
    $poNoMark = $poAfterPay !== null ? trim((string) ($poAfterPay['po_number'] ?? '')) : '';
    tnc_audit_log('update', 'purchase_order', (string) $po_id, $poNoMark !== '' ? ('จ่ายแล้ว ' . $poNoMark) : 'ทำเครื่องหมายจ่าย PO', [
        'source' => 'action-handler',
        'action' => 'update_po_payment_status',
        'before' => $poBeforePay,
        'after' => $poAfterPay,
        'meta' => [
            'payment_slip_paths' => $allPaths,
            'payment_method' => $payment_method,
            'payment_cash_paid_by' => $payment_cash_paid_by,
        ],
    ]);
    $uidPay = (int) ($_SESSION['user_id'] ?? 0);
    $autoBillId = null;
    if ($poAfterPay !== null) {
        $autoBillId = tnc_purchase_bill_create_from_paid_purchase_order($poAfterPay, $uidPay);
    }
    if ($autoBillId !== null && $autoBillId > 0) {
        $billMonth = date('Y-m');
        $paidTs = trim((string) ($poAfterPay['payment_marked_paid_at'] ?? ''));
        if ($paidTs !== '' && preg_match('/^(\d{4}-\d{2})/', $paidTs, $mm) === 1) {
            $billMonth = $mm[1];
        }
        tnc_action_redirect($listUrl . '?payment_saved=1&auto_bill=1&bill_month=' . rawurlencode($billMonth) . '&bill_id=' . (int) $autoBillId . '&print_po_id=' . $po_id);
    }
    tnc_action_redirect($listUrl . '?payment_saved=1&print_po_id=' . $po_id);
}

/** เพิ่มไฟล์หลักฐานการจ่าย (หลายไฟล์) สำหรับ PO ที่จ่ายแล้ว */
if ($action === 'add_po_payment_slips' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_finance_role();
    $listUrl = app_path('pages/purchase/purchase-order-list.php');
    $po_id = (int) ($_POST['po_id'] ?? 0);
    if ($po_id <= 0) {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    $po = Db::row('purchase_orders', (string) $po_id);
    if ($po === null) {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
        tnc_action_redirect($listUrl . '?error=po_cancelled');
    }
    if (strtolower(trim((string) ($po['payment_status'] ?? ''))) !== 'paid') {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    $added = tnc_po_payment_slip_upload_many($po_id, 'payment_slips');
    if ($added === [] && !empty($_FILES['payment_slip'])) {
        $one = tnc_po_payment_slip_upload_one($po_id, $_FILES['payment_slip']);
        if ($one !== null) {
            $added[] = $one;
        }
    }
    if ($added === []) {
        tnc_action_redirect($listUrl . '?error=upload_failed');
    }
    $before = tnc_po_payment_slip_paths($po);
    $merged = array_values(array_unique(array_merge($before, $added)));
    tnc_po_payment_slip_save_paths($po_id, $merged);
    $poAfter = Db::row('purchase_orders', (string) $po_id);
    $poNo = trim((string) ($poAfter['po_number'] ?? ''));
    tnc_audit_log('update', 'purchase_order', (string) $po_id, $poNo !== '' ? ('เพิ่มหลักฐานจ่าย ' . $poNo) : 'เพิ่มหลักฐานจ่าย PO', [
        'source' => 'action-handler',
        'action' => 'add_po_payment_slips',
        'before' => $po,
        'after' => $poAfter,
        'meta' => ['added' => $added],
    ]);
    tnc_action_redirect($listUrl . '?payment_slips_updated=1');
}

/** ลบไฟล์หลักฐานการจ่ายรายการเดียว */
if ($action === 'remove_po_payment_slip' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_finance_role();
    $listUrl = app_path('pages/purchase/purchase-order-list.php');
    $po_id = (int) ($_POST['po_id'] ?? 0);
    $removePath = trim((string) ($_POST['slip_path'] ?? ''));
    if ($po_id <= 0 || $removePath === '') {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    $po = Db::row('purchase_orders', (string) $po_id);
    if ($po === null) {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    if (strtolower(trim((string) ($po['payment_status'] ?? ''))) !== 'paid') {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    $before = tnc_po_payment_slip_paths($po);
    if (!in_array($removePath, $before, true)) {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    tnc_po_payment_slip_delete_file($removePath);
    $afterPaths = array_values(array_filter($before, static fn (string $p): bool => $p !== $removePath));
    tnc_po_payment_slip_save_paths($po_id, $afterPaths);
    $poAfter = Db::row('purchase_orders', (string) $po_id);
    $poNo = trim((string) ($poAfter['po_number'] ?? ''));
    tnc_audit_log('update', 'purchase_order', (string) $po_id, $poNo !== '' ? ('ลบหลักฐานจ่าย ' . $poNo) : 'ลบหลักฐานจ่าย PO', [
        'source' => 'action-handler',
        'action' => 'remove_po_payment_slip',
        'before' => $po,
        'after' => $poAfter,
        'meta' => ['removed' => $removePath],
    ]);
    tnc_action_redirect($listUrl . '?payment_slips_updated=1');
}

/** เปลี่ยนไฟล์หลักฐานการจ่าย (แทนที่ไฟล์เดิม) */
if ($action === 'replace_po_payment_slip' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_finance_role();
    $listUrl = app_path('pages/purchase/purchase-order-list.php');
    $po_id = (int) ($_POST['po_id'] ?? 0);
    $oldPath = trim((string) ($_POST['slip_path'] ?? ''));
    if ($po_id <= 0) {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    $po = Db::row('purchase_orders', (string) $po_id);
    if ($po === null) {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
        tnc_action_redirect($listUrl . '?error=po_cancelled');
    }
    if (strtolower(trim((string) ($po['payment_status'] ?? ''))) !== 'paid') {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    $newPath = null;
    if (!empty($_FILES['payment_slip'])) {
        $newPath = tnc_po_payment_slip_upload_one($po_id, $_FILES['payment_slip']);
    }
    if ($newPath === null) {
        $uploaded = tnc_po_payment_slip_upload_many($po_id, 'payment_slips');
        $newPath = $uploaded[0] ?? null;
    }
    if ($newPath === null) {
        tnc_action_redirect($listUrl . '?error=upload_failed');
    }
    $before = tnc_po_payment_slip_paths($po);
    if ($oldPath !== '' && in_array($oldPath, $before, true)) {
        tnc_po_payment_slip_delete_file($oldPath);
        $afterPaths = [];
        foreach ($before as $p) {
            $afterPaths[] = ($p === $oldPath) ? $newPath : $p;
        }
    } elseif ($before !== []) {
        foreach ($before as $p) {
            tnc_po_payment_slip_delete_file($p);
        }
        $afterPaths = [$newPath];
    } else {
        $afterPaths = [$newPath];
    }
    $afterPaths = array_values(array_unique($afterPaths));
    tnc_po_payment_slip_save_paths($po_id, $afterPaths);
    $poAfter = Db::row('purchase_orders', (string) $po_id);
    $poNo = trim((string) ($poAfter['po_number'] ?? ''));
    tnc_audit_log('update', 'purchase_order', (string) $po_id, $poNo !== '' ? ('เปลี่ยนหลักฐานจ่าย ' . $poNo) : 'เปลี่ยนหลักฐานจ่าย PO', [
        'source' => 'action-handler',
        'action' => 'replace_po_payment_slip',
        'before' => $po,
        'after' => $poAfter,
        'meta' => ['old' => $oldPath, 'new' => $newPath],
    ]);
    tnc_action_redirect($listUrl . '?payment_slips_updated=1');
}

if ($action === 'upload_po_payment_slip' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_finance_role();
    $po_id = (int) ($_POST['po_id'] ?? 0);
    $payment_id = (int) ($_POST['payment_id'] ?? 0);
    $backTo = trim((string) ($_POST['back_to'] ?? ''));
    if ($payment_id <= 0) {
        $cand = Db::filter('po_payments', static fn (array $r): bool => isset($r['po_id']) && (int) $r['po_id'] === $po_id);
        usort($cand, static fn (array $a, array $b): int => ((int) ($a['seq'] ?? 0)) <=> ((int) ($b['seq'] ?? 0)));
        foreach ($cand as $c) {
            if (trim((string) ($c['slip_path'] ?? '')) === '') {
                $payment_id = (int) ($c['id'] ?? 0);
                break;
            }
        }
        if ($payment_id <= 0 && count($cand) > 0) {
            $payment_id = (int) ($cand[0]['id'] ?? 0);
        }
    }
    $pay = Db::row('po_payments', (string) $payment_id);
    if ($pay === null || (int) ($pay['po_id'] ?? 0) !== $po_id) {
        tnc_action_redirect( app_path('pages/purchase/purchase-order-list.php') . '?error=payment');
    }
    if (empty($_FILES['slip_file']) || (int) ($_FILES['slip_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        tnc_action_redirect( app_path('pages/purchase/purchase-order-list.php') . '?id=' . $po_id . '&error=upload_failed');
    }
    $f = $_FILES['slip_file'];
    if ((int) ($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
        tnc_action_redirect( app_path('pages/purchase/purchase-order-list.php') . '?id=' . $po_id . '&error=upload_failed');
    }
    $tmp = (string) ($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        tnc_action_redirect( app_path('pages/purchase/purchase-order-list.php') . '?id=' . $po_id . '&error=upload_failed');
    }
    $originalName = trim((string) ($f['name'] ?? 'slip'));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowedExt, true)) {
        tnc_action_redirect( app_path('pages/purchase/purchase-order-list.php') . '?id=' . $po_id . '&error=upload_type');
    }
    $dirAbs = ROOT_PATH . '/uploads/po-payments/' . $po_id;
    if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
        tnc_action_redirect( app_path('pages/purchase/purchase-order-list.php') . '?id=' . $po_id . '&error=upload_failed');
    }
    $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $safeBase = trim((string) $safeBase, '._-');
    if ($safeBase === '') {
        $safeBase = 'slip';
    }
    $storedName = 'pay' . $payment_id . '_' . date('Ymd_His') . '.' . $ext;
    $destAbs = $dirAbs . '/' . $storedName;
    if (!@move_uploaded_file($tmp, $destAbs)) {
        tnc_action_redirect( app_path('pages/purchase/purchase-order-list.php') . '?id=' . $po_id . '&error=upload_failed');
    }
    $rel = 'uploads/po-payments/' . $po_id . '/' . $storedName;
    $payBefore = Db::row('po_payments', (string) $payment_id);
    Db::mergeRow('po_payments', (string) $payment_id, [
        'slip_path' => $rel,
        'slip_url' => app_path($rel),
        'paid_amount' => (float) str_replace([',', ' '], '', (string) ($_POST['paid_amount'] ?? ($pay['amount'] ?? 0))),
        'payment_note' => mb_substr(trim((string) ($_POST['payment_note'] ?? '')), 0, 500),
        'slip_uploaded_at' => date('Y-m-d H:i:s'),
    ]);
    $payAfter = Db::row('po_payments', (string) $payment_id);
    tnc_audit_log('update', 'po_payment', (string) $payment_id, 'แนบสลิปชำระ PO #' . $po_id, [
        'source' => 'action-handler',
        'action' => 'upload_po_payment_slip',
        'before' => $payBefore,
        'after' => $payAfter,
        'meta' => ['po_id' => $po_id, 'slip_path' => $rel],
    ]);
    if ($backTo === 'po_list') {
        tnc_action_redirect( app_path('pages/purchase/purchase-order-list.php') . '?payment_saved=1');
    } else {
        tnc_action_redirect( app_path('pages/purchase/purchase-order-list.php') . '?id=' . $po_id . '&payment_saved=1');
    }
}

// --- บันทึกบิลซื้อตามโครงการ (ไซต์งาน) ---
if ($action === 'save_project_purchase_bill' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $bill_id = (int) ($_POST['bill_id'] ?? 0);
    $editingBill = $bill_id > 0 ? (Db::rowByIdField('purchase_bills', $bill_id) ?? Db::row('purchase_bills', (string) $bill_id)) : null;
    if ($bill_id > 0 && $editingBill === null) {
        tnc_action_redirect( app_path('pages/purchase/purchase-bill.php') . '?error=invalid');
    }
    $site_id = (int) ($_POST['site_id'] ?? 0);
    $bill_date = trim((string) ($_POST['bill_date'] ?? date('Y-m-d')));
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $bill_date, $m) === 1) {
        $bill_date = $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    $supplier_name = trim((string) ($_POST['supplier_name'] ?? ''));
    $bill_note = trim((string) ($_POST['bill_note'] ?? ''));
    $vat_mode = trim((string) ($_POST['vat_mode'] ?? 'none'));
    if (!in_array($vat_mode, ['none', 'exclusive', 'inclusive'], true)) {
        $vat_mode = 'none';
    }
    $vat_rate = (float) str_replace([',', ' '], '', (string) ($_POST['vat_rate'] ?? '7'));
    if ($vat_rate < 0 || $vat_rate > 100) {
        $vat_rate = 7.0;
    }
    $created_by = (int) $_SESSION['user_id'];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bill_date)) {
        tnc_action_redirect( app_path('pages/purchase/purchase-bill.php') . '?error=invalid');
    }
    if ($site_id > 0) {
        $site = Db::row('sites', (string) $site_id);
        if ($site === null) {
            tnc_action_redirect( app_path('pages/purchase/purchase-bill.php') . '?error=site');
        }
    }

    $descs = $_POST['line_description'] ?? ($_POST['item_name'] ?? []);
    $qtys = $_POST['line_qty'] ?? ($_POST['item_qty'] ?? []);
    $units = $_POST['line_unit'] ?? ($_POST['item_unit'] ?? []);
    $prices = $_POST['line_price'] ?? ($_POST['item_price'] ?? []);
    $discountInputs = $_POST['line_discount'] ?? [];
    $discountTypes = $_POST['line_discount_type'] ?? [];
    $discountVals = $_POST['line_discount_value'] ?? [];

    if (!is_array($descs) && $descs !== '') {
        $descs = [(string) $descs];
    }
    if (!is_array($qtys) && $qtys !== '') {
        $qtys = [(string) $qtys];
    }
    if (!is_array($prices) && $prices !== '') {
        $prices = [(string) $prices];
    }
    if (!is_array($units) && $units !== '') {
        $units = [(string) $units];
    }
    if (!is_array($discountInputs) && $discountInputs !== '') {
        $discountInputs = [(string) $discountInputs];
    }
    if (!is_array($discountTypes) && $discountTypes !== '') {
        $discountTypes = [(string) $discountTypes];
    }
    if (!is_array($discountVals) && $discountVals !== '') {
        $discountVals = [(string) $discountVals];
    }

    if (!is_array($descs) || !is_array($qtys) || !is_array($prices)) {
        tnc_action_redirect( app_path('pages/purchase/purchase-bill.php') . '?error=invalid');
    }

    $line_rows = [];
    $subtotal = 0.0;
    $n = max(
        count($descs),
        count($qtys),
        count($prices),
        count($units),
        count($discountInputs),
        count($discountTypes),
        count($discountVals)
    );
    for ($i = 0; $i < $n; $i++) {
        $desc = trim((string) ($descs[$i] ?? ''));
        $qty = (float) str_replace([',', ' '], '', (string) ($qtys[$i] ?? 0));
        $unit = trim((string) ($units[$i] ?? ''));
        $price = (float) str_replace([',', ' '], '', (string) ($prices[$i] ?? 0));
        $discountRaw = trim((string) ($discountInputs[$i] ?? ''));
        if ($discountRaw === '' && isset($discountTypes[$i], $discountVals[$i])) {
            $legacyType = (string) $discountTypes[$i];
            $legacyVal = trim((string) $discountVals[$i]);
            if ($legacyType === 'percent' && $legacyVal !== '') {
                $discountRaw = $legacyVal . '%';
            } elseif ($legacyVal !== '') {
                $discountRaw = $legacyVal;
            }
        }

        if ($desc === '' && $qty == 0.0 && $price == 0.0) {
            continue;
        }
        if ($desc === '' || $qty <= 0 || $price < 0) {
            continue;
        }

        $lineBase = round($qty * $price, 2);
        $discountAmount = 0.0;
        $discountType = 'amount';
        $discountValue = 0.0;
        if ($discountRaw !== '') {
            $pctMatch = [];
            if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*%$/', $discountRaw, $pctMatch) === 1) {
                $discountType = 'percent';
                $discountValue = (float) $pctMatch[1];
                if ($discountValue < 0) {
                    $discountValue = 0;
                } elseif ($discountValue > 100) {
                    $discountValue = 100;
                }
                $discountAmount = round($lineBase * $discountValue / 100, 2);
            } else {
                $discountType = 'amount';
                $discountValue = (float) str_replace([',', ' '], '', $discountRaw);
                if ($discountValue < 0) {
                    $discountValue = 0;
                }
                $discountAmount = min($lineBase, round($discountValue, 2));
            }
        }
        $lineTotal = round($lineBase - $discountAmount, 2);
        $subtotal += $lineTotal;
        $line_rows[] = [
            'description' => mb_substr($desc, 0, 500),
            'quantity' => $qty,
            'unit' => mb_substr($unit, 0, 40),
            'unit_price' => $price,
            'discount_input' => mb_substr($discountRaw, 0, 20),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => $discountAmount,
            'line_total' => $lineTotal,
        ];
    }

    if (count($line_rows) === 0 || $subtotal <= 0) {
        tnc_action_redirect( app_path('pages/purchase/purchase-bill.php') . '?error=need_lines');
    }

    $subtotal = round($subtotal, 2);
    $vat_amount = 0.0;
    $grand_total = $subtotal;
    if ($vat_mode === 'exclusive') {
        $vat_amount = round($subtotal * $vat_rate / 100, 2);
        $grand_total = round($subtotal + $vat_amount, 2);
    } elseif ($vat_mode === 'inclusive') {
        if ($vat_rate > 0) {
            $base = round($subtotal / (1 + $vat_rate / 100), 2);
            $vat_amount = round($subtotal - $base, 2);
            $subtotal = $base;
            $grand_total = round($base + $vat_amount, 2);
        } else {
            $grand_total = $subtotal;
            $vat_amount = 0.0;
        }
    }

    $bid = $bill_id > 0 ? $bill_id : Db::nextNumericId('purchase_bills', 'id');
    $attachmentPath = (string) ($editingBill['attachment_path'] ?? '');
    $attachmentUrl = (string) ($editingBill['attachment_url'] ?? '');
    if (!empty($_FILES['attachment']) && (int) ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['attachment'];
        if ((int) ($f['error'] ?? 0) === UPLOAD_ERR_OK) {
            $tmp = (string) ($f['tmp_name'] ?? '');
            $originalName = trim((string) ($f['name'] ?? 'bill'));
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
            if ($tmp !== '' && is_uploaded_file($tmp) && in_array($ext, $allowedExt, true)) {
                $dirAbs = ROOT_PATH . '/uploads/project-bills/' . $bid;
                if (is_dir($dirAbs) || @mkdir($dirAbs, 0775, true) || is_dir($dirAbs)) {
                    $storedName = 'bill_' . date('Ymd_His') . '.' . $ext;
                    if (@move_uploaded_file($tmp, $dirAbs . '/' . $storedName)) {
                        $attachmentPath = 'uploads/project-bills/' . $bid . '/' . $storedName;
                        $attachmentUrl = app_path($attachmentPath);
                    }
                }
            }
        }
    }

    $billPayload = [
        'id' => $bid,
        'site_id' => $site_id,
        'bill_date' => $bill_date,
        'supplier_name' => $supplier_name,
        'bill_note' => mb_substr($bill_note, 0, 1000),
        'vat_mode' => $vat_mode,
        'vat_rate' => $vat_rate,
        'subtotal_amount' => $subtotal,
        'vat_amount' => $vat_amount,
        'amount' => $grand_total,
        'attachment_path' => $attachmentPath,
        'attachment_url' => $attachmentUrl,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($editingBill === null) {
        $billPayload['created_by'] = $created_by;
        $billPayload['created_at'] = date('Y-m-d H:i:s');
    } else {
        $billPayload['created_by'] = (int) ($editingBill['created_by'] ?? $created_by);
        $billPayload['created_at'] = (string) ($editingBill['created_at'] ?? date('Y-m-d H:i:s'));
    }
    $billPayload['items'] = array_map(static function (array $line, int $idx): array {
        return [
            'line_no' => $idx + 1,
            'description' => $line['description'],
            'quantity' => $line['quantity'],
            'unit' => $line['unit'],
            'unit_price' => $line['unit_price'],
            'discount_input' => $line['discount_input'],
            'discount_type' => $line['discount_type'],
            'discount_value' => $line['discount_value'],
            'discount_amount' => $line['discount_amount'],
            'line_total' => $line['line_total'],
        ];
    }, $line_rows, array_keys($line_rows));
    $billBeforeSave = $editingBill;
    Db::setRow('purchase_bills', (string) $bid, $billPayload);
    $billAfterSave = Db::row('purchase_bills', (string) $bid);
    $billSummary = trim($supplier_name . ' · ' . $bill_date . ' · ยอด ' . (string) $grand_total);
    tnc_audit_log(
        $editingBill === null ? 'create' : 'update',
        'purchase_bill',
        (string) $bid,
        $billSummary !== '' ? $billSummary : ('บิล #' . $bid),
        [
            'source' => 'action-handler',
            'action' => 'save_project_purchase_bill',
            'before' => $billBeforeSave,
            'after' => $billAfterSave,
        ]
    );
    $resultKey = $editingBill === null ? 'success=1' : 'updated=1';
    $month = substr($bill_date, 0, 7);
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    tnc_action_redirect( app_path('pages/purchase/purchase-bill.php') . '?month=' . rawurlencode($month) . '&site_id=' . $site_id . '&' . $resultKey);
}

// --- company / customer / member ---
if (in_array($action, ['add_company', 'edit_company', 'add_customer', 'edit_customer', 'add_member', 'edit_member'], true)) {
    if (strpos($action, 'member') !== false) {
        $page = 'pages/organization/member-manage.php';
        if ($action === 'add_member') {
            $dup = null;
            for ($i = 0; $i < 20; $i++) {
                $allUsers = Db::tableRows('users');
                $u_code = next_sequential_member_user_code($allUsers);
                $dup = Db::findFirst('users', static function (array $r) use ($u_code): bool {
                    return isset($r['user_code']) && strcasecmp(trim((string) $r['user_code']), $u_code) === 0;
                });
                if ($dup === null) {
                    break;
                }
            }
            if ($dup !== null) {
                tnc_action_redirect( app_path($page) . '?error=code_gen');
            }
        } else {
            $u_code = trim((string) ($_POST['user_code'] ?? ''));
        }
        $fn = trim((string) ($_POST['fname'] ?? ''));
        $ln = trim((string) ($_POST['lname'] ?? ''));
        $line_id = $action === 'add_member' ? '' : trim((string) ($_POST['user_line_id'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? ''));
        $allowed_roles = ['CEO', 'ADMIN', 'ACCOUNTING', 'USER'];
        if (!in_array($role, $allowed_roles, true)) {
            tnc_action_redirect( app_path($page) . '?error=invalid_role');
        }
        $job_raw = trim((string) ($_POST['job_title'] ?? ''));
        if (strlen($job_raw) > 160) {
            $job_raw = substr($job_raw, 0, 160);
        }
        $address = trim((string) ($_POST['address'] ?? ''));
        $salary_raw = str_replace([',', ' '], '', trim((string) ($_POST['salary_base'] ?? '')));
        $has_salary = $salary_raw !== '' && is_numeric($salary_raw);
        $salary_val = $has_salary ? (string) round((float) $salary_raw, 2) : null;
        if ($action === 'add_member') {
            $salary_val = null;
        }
        $bd_raw = trim((string) ($_POST['birth_date'] ?? ''));
        $has_bd = $bd_raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd_raw);
        $nid_digits = preg_replace('/\D/', '', (string) ($_POST['national_id'] ?? ''));
        $nid_digits = substr($nid_digits, 0, 13);

        $base = [
            'user_code' => $u_code,
            'fname' => $fn,
            'lname' => $ln,
            'nickname' => '',
            'user_line_id' => $action === 'add_member' ? null : ($line_id !== '' ? $line_id : null),
            'role' => $role,
            'job_title' => $job_raw,
            'address' => $address,
            'salary_base' => $salary_val,
            'birth_date' => $has_bd ? $bd_raw : null,
            'national_id' => $nid_digits !== '' ? $nid_digits : null,
        ];

        if ($action === 'add_member') {
            if (trim((string) ($_POST['password'] ?? '')) === '') {
                tnc_action_redirect( app_path($page) . '?error=password_required');
            }
            $pw = password_hash((string) $_POST['password'], PASSWORD_DEFAULT);
            $uid = Db::nextNumericId('users', 'userid');
            $base['userid'] = $uid;
            $base['password'] = $pw;
            Db::setRow('users', (string) $uid, $base);
            $memAfter = Db::row('users', (string) $uid);
            tnc_audit_log('create', 'member', (string) $uid, trim($fn . ' ' . $ln) . ' (' . $u_code . ')', [
                'source' => 'action-handler',
                'action' => 'add_member',
                'after' => $memAfter,
            ]);
        } else {
            $edit_id = (int) ($_POST['id'] ?? 0);
            $cur = Db::row('users', (string) $edit_id) ?? [];
            if (!empty($_POST['password'])) {
                $base['password'] = password_hash((string) $_POST['password'], PASSWORD_DEFAULT);
            }
            Db::setRow('users', (string) $edit_id, array_merge($cur, $base));
            $memAfterEd = Db::row('users', (string) $edit_id);
            tnc_audit_log('update', 'member', (string) $edit_id, trim($fn . ' ' . $ln) . ' (' . $u_code . ')', [
                'source' => 'action-handler',
                'action' => 'edit_member',
                'before' => $cur,
                'after' => $memAfterEd,
                'meta' => ['password_changed' => !empty($_POST['password'])],
            ]);
        }
        $ok = ($action === 'edit_member') ? 'updated' : '1';
        tnc_action_redirect( app_path($page) . '?success=' . $ok);
    } else {
        $table = (strpos($action, 'company') !== false) ? 'company' : 'customers';
        $page = ($table === 'company') ? 'pages/organization/company-manage.php' : 'pages/organization/customer-manage.php';
        $name = trim((string) ($_POST['name'] ?? ''));
        $tax = trim((string) ($_POST['tax_id'] ?? ''));
        $addr = trim((string) ($_POST['address'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));

        $row = [
            'name' => $name,
            'tax_id' => $tax,
            'address' => $addr,
            'phone' => $phone,
            'email' => $email,
        ];

        if (strpos($action, 'add') !== false) {
            $nid = Db::nextNumericId($table, 'id');
            $row['id'] = $nid;
            if ($table === 'customers') {
                $row['customer_type'] = trim((string) ($_POST['customer_type'] ?? ''));
            }
            Db::setRow($table, (string) $nid, $row);
            $entityLabel = $table === 'company' ? 'company' : 'customer';
            $orgAfter = Db::row($table, (string) $nid);
            tnc_audit_log('create', $entityLabel, (string) $nid, $name !== '' ? $name : ('#' . $nid), [
                'source' => 'action-handler',
                'action' => $action,
                'after' => $orgAfter,
            ]);
        } else {
            $edit_id = (int) ($_POST['id'] ?? 0);
            $cur = Db::row($table, (string) $edit_id) ?? [];
            Db::setRow($table, (string) $edit_id, array_merge($cur, $row));
            $entityLabel = $table === 'company' ? 'company' : 'customer';
            $orgAfterEd = Db::row($table, (string) $edit_id);
            tnc_audit_log('update', $entityLabel, (string) $edit_id, $name !== '' ? $name : ('#' . $edit_id), [
                'source' => 'action-handler',
                'action' => $action,
                'before' => $cur,
                'after' => $orgAfterEd,
            ]);
        }
        tnc_action_redirect( app_path($page) . '?success=1');
    }
}

// --- ลบใบลา (admin + POST + รหัสผ่านยืนยัน) ---
if ($action === 'delete_leave_request' && $id > 0) {
    $leave = Db::rowByIdField('leave_requests', $id);
    if ($leave === null) {
        tnc_action_redirect(app_path('pages/leave-requests/leave-request-list.php') . '?scope=all&error=not_found');
    }
    $leaveNo = trim((string) ($leave['leave_number'] ?? ''));
    Db::deleteRow('leave_requests', Db::pkForLogicalId('leave_requests', $id));
    tnc_audit_log('delete', 'leave_request', (string) $id, $leaveNo !== '' ? $leaveNo : ('#' . $id), [
        'source' => 'action-handler',
        'action' => 'delete_leave_request',
        'before' => $leave,
    ]);
    $scopeQ = isset($_GET['scope']) && (string) $_GET['scope'] === 'all' ? '&scope=all' : '';
    tnc_action_redirect(app_path('pages/leave-requests/leave-request-list.php') . '?scope=all&deleted=1');
}

// --- delete ---
if ($action === 'delete' && $id > 0) {
    if ($type === 'invoice') {
        $invPk = Db::pkForLogicalId('invoices', $id);
        $invSnap = Db::row('invoices', $invPk);
        $invNo = $invSnap !== null ? trim((string) ($invSnap['invoice_number'] ?? '')) : '';
        $invItemsDel = [];
        foreach (Db::filter('invoice_items', static function (array $r) use ($id): bool {
            return isset($r['invoice_id']) && (int) $r['invoice_id'] === $id;
        }) as $ir) {
            if (!is_array($ir)) {
                continue;
            }
            $invItemsDel[] = $ir;
            if (count($invItemsDel) >= 120) {
                break;
            }
        }
        $taxRows = Db::filter('tax_invoices', static function (array $r) use ($id): bool {
            return isset($r['invoice_id']) && (int) $r['invoice_id'] === $id;
        });
        $taxBlocksDel = [];
        foreach ($taxRows as $taxRow) {
            if (!is_array($taxRow)) {
                continue;
            }
            $taxId = (int) ($taxRow['id'] ?? 0);
            if ($taxId <= 0) {
                continue;
            }
            $tis = [];
            foreach (Db::filter('tax_invoice_items', static function (array $r) use ($taxId): bool {
                return isset($r['tax_invoice_id']) && (int) $r['tax_invoice_id'] === $taxId;
            }) as $ti) {
                if (!is_array($ti)) {
                    continue;
                }
                $tis[] = $ti;
                if (count($tis) >= 80) {
                    break;
                }
            }
            $taxBlocksDel[] = ['tax_invoice' => $taxRow, 'items' => $tis];
        }
        Db::deleteWhereEquals('invoice_items', 'invoice_id', (string) $id);
        foreach ($taxBlocksDel as $blk) {
            $taxId = (int) ($blk['tax_invoice']['id'] ?? 0);
            if ($taxId > 0) {
                Db::deleteWhereEquals('tax_invoice_items', 'tax_invoice_id', (string) $taxId);
                Db::deleteRow('tax_invoices', (string) $taxId);
            }
        }
        Db::deleteRow('invoices', (string) $invPk);
        tnc_audit_log('delete', 'invoice', (string) $id, $invNo !== '' ? $invNo : ('#' . $id), [
            'source' => 'action-handler',
            'action' => 'delete',
            'before' => $invSnap,
            'meta' => [
                'invoice_items' => $invItemsDel,
                'related_tax_invoices' => $taxBlocksDel,
            ],
        ]);
        tnc_action_redirect( app_path('index.php') . '?deleted=1');
    } elseif ($type === 'member') {
        $uSnap = Db::row('users', (string) $id);
        $uLabel = $uSnap !== null ? trim((string) (($uSnap['fname'] ?? '') . ' ' . ($uSnap['lname'] ?? ''))) : '';
        $uCode = $uSnap !== null ? trim((string) ($uSnap['user_code'] ?? '')) : '';
        $memSummary = trim($uLabel . ($uCode !== '' ? ' (' . $uCode . ')' : ''));
        Db::deleteRow('users', (string) $id);
        tnc_audit_log('delete', 'member', (string) $id, $memSummary !== '' ? $memSummary : ('#' . $id), [
            'source' => 'action-handler',
            'action' => 'delete',
            'before' => $uSnap,
        ]);
        tnc_action_redirect( app_path('pages/organization/member-manage.php') . '?deleted=1');
    } elseif ($type === 'tax_invoice') {
        $taxPk = Db::pkForLogicalId('tax_invoices', $id);
        $taxSnap = Db::row('tax_invoices', $taxPk);
        $taxNo = $taxSnap !== null ? trim((string) ($taxSnap['tax_invoice_number'] ?? '')) : '';
        $taxItemsDel = [];
        foreach (Db::filter('tax_invoice_items', static function (array $r) use ($id): bool {
            return isset($r['tax_invoice_id']) && (int) $r['tax_invoice_id'] === $id;
        }) as $txi) {
            if (!is_array($txi)) {
                continue;
            }
            $taxItemsDel[] = $txi;
            if (count($taxItemsDel) >= 120) {
                break;
            }
        }
        Db::deleteWhereEquals('tax_invoice_items', 'tax_invoice_id', (string) $id);
        Db::deleteRow('tax_invoices', (string) $taxPk);
        tnc_audit_log('delete', 'tax_invoice', (string) $id, $taxNo !== '' ? strtoupper($taxNo) : ('#' . $id), [
            'source' => 'action-handler',
            'action' => 'delete',
            'before' => $taxSnap,
            'meta' => ['tax_invoice_items' => $taxItemsDel],
        ]);
        tnc_action_redirect( app_path('pages/invoices/tax-invoice-list.php') . '?deleted=1');
    } elseif ($type === 'purchase_order') {
        $poPk = Db::pkForLogicalId('purchase_orders', $id);
        $poSnap = Db::row('purchase_orders', $poPk);
        $poNo = $poSnap !== null ? trim((string) ($poSnap['po_number'] ?? '')) : '';
        if ($poSnap !== null && strtolower(trim((string) ($poSnap['payment_status'] ?? 'unpaid'))) === 'paid') {
            tnc_action_redirect(app_path('pages/purchase/purchase-order-list.php') . '?error=po_paid');
        }
        $poPayDel = [];
        foreach (Db::filter('po_payments', static function (array $r) use ($id): bool {
            return isset($r['po_id']) && (int) $r['po_id'] === $id;
        }) as $pp) {
            if (!is_array($pp)) {
                continue;
            }
            $poPayDel[] = $pp;
            if (count($poPayDel) >= 60) {
                break;
            }
        }
        $poLinesDel = [];
        foreach (Db::filter('purchase_order_items', static function (array $r) use ($id): bool {
            return isset($r['po_id']) && (int) $r['po_id'] === $id;
        }) as $pol) {
            if (!is_array($pol)) {
                continue;
            }
            $poLinesDel[] = $pol;
            if (count($poLinesDel) >= 120) {
                break;
            }
        }
        Db::deleteWhereEquals('po_payments', 'po_id', (string) $id);
        Db::deleteWhereEquals('purchase_order_items', 'po_id', (string) $id);
        Db::deleteRow('purchase_orders', (string) $poPk);
        tnc_audit_log('delete', 'purchase_order', (string) $id, $poNo !== '' ? $poNo : ('#' . $id), [
            'source' => 'action-handler',
            'action' => 'delete',
            'before' => $poSnap,
            'meta' => [
                'po_payments' => $poPayDel,
                'purchase_order_items' => $poLinesDel,
            ],
        ]);
        tnc_action_redirect( app_path('pages/purchase/purchase-order-list.php') . '?deleted=1');
    } elseif ($type === 'project_purchase_bill') {
        $billPk = Db::pkForLogicalId('purchase_bills', $id);
        $billSnap = Db::row('purchase_bills', $billPk);
        $billLabel = $billSnap !== null ? trim((string) ($billSnap['bill_number'] ?? ($billSnap['doc_number'] ?? ''))) : '';
        $billTableItems = [];
        foreach (Db::tableRows('purchase_bill_items') as $bItem) {
            if (!is_array($bItem)) {
                continue;
            }
            $bidRef = (int) ($bItem['bill_id'] ?? $bItem['purchase_bill_id'] ?? $bItem['purchase_bills_id'] ?? 0);
            if ($bidRef !== $id) {
                continue;
            }
            $billTableItems[] = $bItem;
            if (count($billTableItems) >= 120) {
                break;
            }
        }
        Db::deleteWhereEquals('purchase_bill_items', 'bill_id', (string) $id);
        Db::deleteWhereEquals('purchase_bill_items', 'purchase_bill_id', (string) $id);
        Db::deleteWhereEquals('purchase_bill_items', 'purchase_bills_id', (string) $id);
        Db::deleteRow('purchase_bills', (string) $billPk);
        $embeddedItems = $billSnap['items'] ?? [];
        if (is_string($embeddedItems)) {
            $decodedEmb = json_decode($embeddedItems, true);
            $embeddedItems = is_array($decodedEmb) ? $decodedEmb : [];
        }
        tnc_audit_log('delete', 'purchase_bill', (string) $id, $billLabel !== '' ? $billLabel : ('#' . $id), [
            'source' => 'action-handler',
            'action' => 'delete',
            'before' => $billSnap,
            'meta' => [
                'purchase_bill_items_table' => $billTableItems,
                'items_embedded_in_record' => is_array($embeddedItems) ? $embeddedItems : [],
            ],
        ]);
        tnc_action_redirect( app_path('pages/purchase/purchase-bill.php') . '?deleted=1');
    } else {
        $table = ($type === 'company') ? 'company' : 'customers';
        $page = ($table === 'company') ? 'pages/organization/company-manage.php' : 'pages/organization/customer-manage.php';
        $orgPk = Db::pkForLogicalId($table, $id);
        $orgSnap = Db::row($table, $orgPk);
        $orgName = $orgSnap !== null ? trim((string) ($orgSnap['name'] ?? '')) : '';
        Db::deleteRow($table, (string) $orgPk);
        $ent = $table === 'company' ? 'company' : 'customer';
        tnc_audit_log('delete', $ent, (string) $id, $orgName !== '' ? $orgName : ('#' . $id), [
            'source' => 'action-handler',
            'action' => 'delete',
            'before' => $orgSnap,
        ]);
        tnc_action_redirect( app_path($page) . '?deleted=1');
    }
}
