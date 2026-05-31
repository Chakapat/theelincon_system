<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/tnc_action_response.php';
require_once __DIR__ . '/../includes/tnc_audit_log.php';
require_once __DIR__ . '/../includes/purchase_po_payment_slips.php';
require_once __DIR__ . '/../includes/line_pr_approval.php';
require_once __DIR__ . '/../includes/hire_line_items.php';
require_once __DIR__ . '/../includes/site_cost_categories.php';
require_once __DIR__ . '/../includes/contractors.php';

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

$action = $_GET['action'] ?? '';
$type = $_GET['type'] ?? '';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!isset($_SESSION['user_id'])) {
    exit('Access Denied: กรุณาเข้าสู่ระบบ');
}

// POST-only actions: prevent direct GET access to write endpoints.
if (($action === 'create_po_direct' || $action === 'create_po_from_pr' || $action === 'update_po_payment_status' || $action === 'receive_po_bill' || $action === 'add_po_payment_slips' || $action === 'remove_po_payment_slip' || $action === 'replace_po_payment_slip' || $action === 'update_po_direct' || $action === 'cancel_purchase_order' || $action === 'ignore_incomplete_po' || $action === 'unignore_incomplete_po' || $action === 'update_my_profile' || $action === 'send_pr_line_approval' || $action === 'pr_web_decision')
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
$tncDeletePwdActions = ['delete', 'delete_supplier', 'delete_contractor', 'delete_pr', 'delete_leave_request'];
if (in_array($action, $tncDeletePwdActions, true)) {
    tnc_require_post_confirm_password();
}

$admin_only_actions = ['delete', 'delete_pr', 'add_member', 'edit_member', 'delete_supplier', 'delete_contractor', 'delete_leave_request', 'add_company', 'edit_company', 'add_customer', 'edit_customer'];
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
    // ใช้ส่วนลดเฉพาะบรรทัดมูลค่าเป็นบวก (บรรทัดติดลบ เช่น มัดจำ/หักเงิน ไม่คิดส่วนลด)
    if ($discountRaw !== '' && $lineBase > 0) {
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
 * เลิกใช้ตาราง purchase_bills แล้ว — ภาษีซื้อใช้ตาราง bills (จาก receive_po_bill) เป็นแหล่งเดียว
 * คง stub ไว้เพื่อความเข้ากันได้ของจุดเรียกเดิม โดยไม่สร้างข้อมูลซ้ำอีกต่อไป
 */
function tnc_purchase_bill_create_from_paid_purchase_order(?array $po, int $createdBy): ?int
{
    return null;
}

function tnc_po_try_auto_bill_on_complete(int $poId, int $createdBy): ?int
{
    return null;
}

/**
 * Delete all bill records linked to a PO from both /purchase_bills and /bills.
 *
 * @return array{purchase_bills: list<array<string,mixed>>, bills: list<array<string,mixed>>}
 */
function tnc_delete_linked_bills_by_po(int $poId): array
{
    $deletedPurchaseBills = [];
    $deletedBills = [];
    if ($poId <= 0) {
        return ['purchase_bills' => $deletedPurchaseBills, 'bills' => $deletedBills];
    }

    foreach (Db::tableKeyed('purchase_bills') as $pbPk => $pbRow) {
        if (!is_array($pbRow) || (int) ($pbRow['source_po_id'] ?? 0) !== $poId) {
            continue;
        }
        $pbId = (int) ($pbRow['id'] ?? 0);
        if ($pbId > 0) {
            Db::deleteWhereEquals('purchase_bill_items', 'bill_id', (string) $pbId);
            Db::deleteWhereEquals('purchase_bill_items', 'purchase_bill_id', (string) $pbId);
            Db::deleteWhereEquals('purchase_bill_items', 'purchase_bills_id', (string) $pbId);
        }
        $deletedPurchaseBills[] = $pbRow;
        Db::deleteRow('purchase_bills', (string) $pbPk);
    }

    foreach (Db::tableKeyed('bills') as $bPk => $bRow) {
        if (!is_array($bRow) || (int) ($bRow['po_id'] ?? 0) !== $poId) {
            continue;
        }
        $deletedBills[] = $bRow;
        Db::deleteRow('bills', (string) $bPk);
    }

    return ['purchase_bills' => $deletedPurchaseBills, 'bills' => $deletedBills];
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

/**
 * ลบ PO และข้อมูลที่ผูก (บิล, งวดชำระ, รายการ, ประวัติงวดสัญญา)
 *
 * @return list<array{verb: string, entity_type: string, entity_id: string, snapshot: array<string, mixed>}>
 */
function tnc_delete_purchase_order_cascade(int $poId): array
{
    $nested = [];
    if ($poId <= 0) {
        return $nested;
    }
    $poDel = Db::row('purchase_orders', (string) $poId);
    if ($poDel === null) {
        return $nested;
    }

    $linkedBillDeleted = tnc_delete_linked_bills_by_po($poId);
    foreach ($linkedBillDeleted['purchase_bills'] as $pbDel) {
        $nested[] = ['verb' => 'delete', 'entity_type' => 'purchase_bill', 'entity_id' => (string) ((int) ($pbDel['id'] ?? 0)), 'snapshot' => $pbDel];
    }
    foreach ($linkedBillDeleted['bills'] as $bDel) {
        $nested[] = ['verb' => 'delete', 'entity_type' => 'bill', 'entity_id' => (string) ((int) ($bDel['id'] ?? 0)), 'snapshot' => $bDel];
    }
    foreach (Purchase::purgeHireContractPaymentsForPo($poId) as $hcpDel) {
        $nested[] = ['verb' => 'delete', 'entity_type' => 'hire_contract_payment', 'entity_id' => (string) ((int) ($hcpDel['id'] ?? 0)), 'snapshot' => $hcpDel];
    }
    Db::deleteWhereEquals('po_payments', 'po_id', (string) $poId);
    tnc_po_delete_line_items($poId);
    Db::deleteRow('purchase_orders', (string) $poId);
    $nested[] = ['verb' => 'delete', 'entity_type' => 'purchase_order', 'entity_id' => (string) $poId, 'snapshot' => $poDel];

    return $nested;
}

/**
 * ลบ PR และข้อมูลที่ผูกทั้งหมด (PO, สัญญาจ้าง, งวดจ่าย, รายการ PR)
 *
 * @return list<array{verb: string, entity_type: string, entity_id: string, snapshot: array<string, mixed>}>
 */
function tnc_delete_pr_cascade(int $prId): array
{
    $nested = [];
    if ($prId <= 0) {
        return $nested;
    }

    foreach (Purchase::purgeHireContractPaymentsForPr($prId) as $hcpDel) {
        $hcpId = (int) ($hcpDel['id'] ?? 0);
        $nested[] = [
            'verb' => 'delete',
            'entity_type' => 'hire_contract_payment',
            'entity_id' => (string) ($hcpId > 0 ? $hcpId : 0),
            'snapshot' => $hcpDel,
        ];
    }

    foreach (Purchase::collectPurchaseOrdersForPr($prId) as $poDel) {
        $poId = (int) ($poDel['id'] ?? 0);
        if ($poId > 0) {
            $nested = array_merge($nested, tnc_delete_purchase_order_cascade($poId));
        }
    }

    foreach (Db::filter('hire_contracts', static fn (array $r): bool => isset($r['pr_id']) && (int) $r['pr_id'] === $prId) as $hc) {
        $hcId = (int) ($hc['id'] ?? 0);
        if ($hcId > 0) {
            $nested[] = ['verb' => 'delete', 'entity_type' => 'hire_contract', 'entity_id' => (string) $hcId, 'snapshot' => $hc];
            Db::deleteRow('hire_contracts', (string) $hcId);
        }
    }

    foreach (Db::filter('purchase_request_items', static fn (array $r): bool => isset($r['pr_id']) && (int) $r['pr_id'] === $prId) as $pri) {
        $priId = (int) ($pri['id'] ?? 0);
        if ($priId > 0) {
            $nested[] = ['verb' => 'delete', 'entity_type' => 'purchase_request_item', 'entity_id' => (string) $priId, 'snapshot' => $pri];
        }
    }
    Db::deleteWhereEquals('purchase_request_items', 'pr_id', (string) $prId);

    foreach (Db::tableKeyed('web_notifications') as $notifKey => $notifRow) {
        if (!is_array($notifRow)) {
            continue;
        }
        if ((string) ($notifRow['entity_type'] ?? '') !== 'purchase_request') {
            continue;
        }
        if ((int) ($notifRow['entity_id'] ?? 0) !== $prId) {
            continue;
        }
        $notifId = (string) (($notifRow['id'] ?? 0) ?: $notifKey);
        $nested[] = ['verb' => 'delete', 'entity_type' => 'web_notification', 'entity_id' => $notifId, 'snapshot' => $notifRow];
        Db::deleteRow('web_notifications', $notifId);
    }

    return $nested;
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
    } elseif ($type === 'contractor') {
        $row = Db::rowByIdField('contractors', $id);
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

if ($action === 'save_contractor') {
    $c_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $formUrl = app_path('pages/contractors/contractor-form.php');
    $listUrl = app_path('pages/contractors/contractor-list.php');
    $redirectErr = static function (string $code) use ($formUrl, $c_id): void {
        $q = $c_id > 0 ? ('?id=' . $c_id . '&error=' . $code) : ('?error=' . $code);
        tnc_action_redirect($formUrl . $q);
    };

    $existing = $c_id > 0 ? Db::rowByIdField('contractors', $c_id) : null;
    if ($c_id > 0 && $existing === null) {
        tnc_action_redirect($listUrl . '?error=not_found');
    }

    $titlePrefixTh = tnc_contractor_normalize_title_prefix_th((string) ($_POST['title_prefix_th'] ?? ''));
    $titlePrefixEn = tnc_contractor_normalize_title_prefix_en((string) ($_POST['title_prefix_en'] ?? ''));
    $nationalId = tnc_contractor_normalize_national_id((string) ($_POST['national_id'] ?? ''));
    $birthDate = trim((string) ($_POST['birth_date'] ?? ''));
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'bank_transfer'));
    if (!array_key_exists($paymentMethod, tnc_contractor_payment_methods())) {
        $paymentMethod = 'bank_transfer';
    }

    $photoPath = $existing !== null ? trim((string) ($existing['id_card_photo_path'] ?? '')) : '';
    $photoName = $existing !== null ? trim((string) ($existing['id_card_photo_name'] ?? '')) : '';
    $photoMime = $existing !== null ? trim((string) ($existing['id_card_photo_mime'] ?? '')) : '';
    $photoSize = $existing !== null ? (int) ($existing['id_card_photo_size'] ?? 0) : 0;

    $fields = [
        'title_prefix_th' => $titlePrefixTh,
        'first_name_th' => mb_substr(trim((string) ($_POST['first_name_th'] ?? '')), 0, 120),
        'last_name_th' => mb_substr(trim((string) ($_POST['last_name_th'] ?? '')), 0, 120),
        'title_prefix_en' => $titlePrefixEn,
        'first_name_en' => mb_substr(trim((string) ($_POST['first_name_en'] ?? '')), 0, 120),
        'last_name_en' => mb_substr(trim((string) ($_POST['last_name_en'] ?? '')), 0, 120),
        'national_id' => $nationalId,
        'birth_date' => $birthDate,
        'address' => mb_substr(trim((string) ($_POST['address'] ?? '')), 0, 1000),
        'payment_method' => $paymentMethod,
        'bank_account_no' => mb_substr(trim((string) ($_POST['bank_account_no'] ?? '')), 0, 30),
        'bank_name' => mb_substr(trim((string) ($_POST['bank_name'] ?? '')), 0, 120),
        'bank_account_name' => mb_substr(trim((string) ($_POST['bank_account_name'] ?? '')), 0, 200),
        'id_card_photo_path' => $photoPath,
    ];

    $hasNewPhoto = !empty($_FILES['id_card_photo']) && (int) ($_FILES['id_card_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $requirePhoto = ($photoPath === '' && !$hasNewPhoto);
    $validationErrors = tnc_contractor_validate_fields($fields, $requirePhoto);
    if ($validationErrors !== []) {
        $redirectErr('required');
    }
    if ($nationalId !== '' && !tnc_contractor_is_valid_national_id($nationalId)) {
        $redirectErr('invalid_national_id');
    }

    foreach (Db::tableRows('contractors') as $other) {
        $otherId = (int) ($other['id'] ?? 0);
        if ($otherId <= 0 || $otherId === $c_id) {
            continue;
        }
        if (tnc_contractor_normalize_national_id((string) ($other['national_id'] ?? '')) === $nationalId) {
            $redirectErr('duplicate_national_id');
        }
    }

    if ($c_id <= 0) {
        $c_id = Db::nextNumericId('contractors', 'id');
    }

    if ($hasNewPhoto) {
        $upload = tnc_contractor_save_id_photo($c_id, $_FILES['id_card_photo']);
        if (!$upload['ok']) {
            $redirectErr($upload['error'] === 'upload_type' ? 'upload_type' : 'upload_failed');
        }
        $photoPath = $upload['path'];
        $photoName = $upload['name'];
        $photoMime = $upload['mime'];
        $photoSize = $upload['size'];
    } elseif ($photoPath === '') {
        $redirectErr('photo_required');
    }

    $now = date('Y-m-d H:i:s');
    $data = [
        'id' => $c_id,
        'title_prefix_th' => $fields['title_prefix_th'],
        'first_name_th' => $fields['first_name_th'],
        'last_name_th' => $fields['last_name_th'],
        'title_prefix_en' => $fields['title_prefix_en'],
        'first_name_en' => $fields['first_name_en'],
        'last_name_en' => $fields['last_name_en'],
        'national_id' => $nationalId,
        'birth_date' => $birthDate,
        'address' => $fields['address'],
        'payment_method' => $paymentMethod,
        'bank_account_no' => $fields['bank_account_no'],
        'bank_name' => $fields['bank_name'],
        'bank_account_name' => $fields['bank_account_name'],
        'id_card_photo_path' => $photoPath,
        'id_card_photo_name' => $photoName,
        'id_card_photo_mime' => $photoMime,
        'id_card_photo_size' => $photoSize,
        'updated_at' => $now,
    ];
    if ($existing === null) {
        $data['created_at'] = $now;
    }

    $beforeSnap = $existing;
    Db::setRow('contractors', (string) $c_id, $existing !== null ? array_merge($existing, $data) : $data);
    $afterSnap = Db::row('contractors', (string) $c_id) ?? [];
    $docLabel = tnc_contractor_display_label($afterSnap);
    tnc_audit_log($existing !== null ? 'update' : 'create', 'contractor', (string) $c_id, $docLabel, [
        'source' => 'action-handler',
        'action' => 'save_contractor',
        'before' => $beforeSnap,
        'after' => $afterSnap,
    ]);
    tnc_action_redirect($listUrl . '?success=1');
}

if ($action === 'delete_contractor') {
    $listUrl = app_path('pages/contractors/contractor-list.php');
    $inUse = static function (string $table) use ($id): bool {
        return Db::findFirst($table, static function (array $r) use ($id): bool {
            return isset($r['contractor_id']) && (int) $r['contractor_id'] === $id;
        }) !== null;
    };
    if ($inUse('purchase_requests') || $inUse('purchase_orders') || $inUse('hire_contracts')) {
        tnc_action_redirect($listUrl . '?error=in_use');
    }
    $cDel = Db::rowByIdField('contractors', $id);
    $cDelLabel = $cDel !== null ? tnc_contractor_display_label($cDel) : ('#' . $id);
    Db::deleteRow('contractors', (string) $id);
    tnc_audit_log('delete', 'contractor', (string) $id, $cDelLabel, [
        'source' => 'action-handler',
        'action' => 'delete_contractor',
        'before' => $cDel,
    ]);
    tnc_action_redirect($listUrl . '?success=1');
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

    // หมวดค่าใช้จ่าย (หัวข้อย่อยของไซต์) — รับเฉพาะหมวดที่ใช้ได้กับไซต์นี้
    $cost_category_id = (int) ($_POST['cost_category_id'] ?? 0);
    $cost_category_name = '';
    if ($cost_category_id > 0 && tnc_site_category_is_valid_for_site($cost_category_id, $site_id)) {
        $cost_category_name = tnc_site_category_name($cost_category_id);
    } else {
        $cost_category_id = 0;
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
    $hire_contractor_id = 0;
    $hire_employer_company_id = 0;
    $hire_scope_details = '';
    $hire_total_value = 0.0;
    $hire_installment_count = 1;

    $hireTotals = null;

    if ($procurement_type === 'hire') {
        $resolvedContractor = tnc_contractor_resolve_from_post($_POST);
        if ($resolvedContractor['row'] === null) {
            tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . ($pr_id > 0 ? ('?id=' . $pr_id . '&error=hire_contractor_required') : '?type=hire&error=hire_contractor_required'));
        }
        $hire_contractor_id = $resolvedContractor['id'];
        $hire_contractor_name = $resolvedContractor['name'];
        $hire_employer_company_id = (int) ($_POST['hire_employer_company_id'] ?? 0);
        if ($hire_employer_company_id <= 0) {
            $companies = Db::tableRows('company');
            Db::sortRows($companies, 'id', false);
            $hire_employer_company_id = (int) (($companies[0] ?? [])['id'] ?? 0);
        }
        $hire_scope_details = trim((string) ($_POST['hire_scope_details'] ?? ($_POST['details'] ?? '')));
        $hireLines = tnc_hire_lines_from_post($_POST);
        $directSubtotal = tnc_hire_subtotal_from_lines($hireLines);
        $overheadPct = (float) ($_POST['overhead_percent'] ?? 0);
        $preliminaryPct = (float) ($_POST['preliminary_percent'] ?? 0);
        $vat_enabled = !empty($_POST['vat_enabled']) ? 1 : 0;
        $hireTotals = tnc_hire_pr_compute_totals($directSubtotal, $overheadPct, $preliminaryPct, $vat_enabled === 1);
        $hire_total_value = $hireTotals['excluded_vat'];
        $hire_installment_count = max(1, min(120, (int) ($_POST['hire_installment_count'] ?? ($_POST['installment_total'] ?? 1))));
        if ($hire_contractor_name === '' || $hire_employer_company_id <= 0 || $hire_scope_details === '' || $hire_total_value <= 0 || tnc_hire_count_billable_lines($hireLines) === 0) {
            tnc_action_redirect( app_path('pages/purchase/purchase-request-create.php') . '?error=hire_invalid');
        }
        $subtotal = $hireTotals['excluded_vat'];
        $vat_amount = $hireTotals['vat'];
        $total_amount = $hireTotals['grand_total'];
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
            $price = (float) $_POST['item_price'][$key];
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
        'cost_category_id' => $cost_category_id,
        'cost_category_name' => $cost_category_name,
        'status' => 'pending',
        'line_approval_token' => '',
        'line_decision' => '',
        'line_decided_at' => '',
        'line_decided_by_line_user_id' => '',
        'line_decided_by_user_id' => 0,
        'line_decision_source' => '',
        'vat_enabled' => $vat_enabled,
        'vat_mode' => $vat_mode_stored,
        'subtotal_amount' => $subtotal,
        'vat_amount' => $vat_amount,
        'procurement_type' => $procurement_type,
        'request_type' => $procurement_type,
        'contractor_name' => $procurement_type === 'hire' ? $hire_contractor_name : '',
        'contractor_id' => $procurement_type === 'hire' ? $hire_contractor_id : 0,
        'contract_value' => $procurement_type === 'hire' ? $hire_total_value : 0.0,
        'installment_total' => $procurement_type === 'hire' ? $hire_installment_count : 1,
        'hire_contractor_name' => $hire_contractor_name,
        'hire_employer_company_id' => $hire_employer_company_id,
        'hire_scope_details' => $hire_scope_details,
        'hire_total_value' => $hire_total_value,
        'hire_installment_count' => $hire_installment_count,
        'hire_direct_subtotal' => $hireTotals !== null ? $hireTotals['direct_subtotal'] : 0.0,
        'overhead_percent' => $hireTotals !== null ? $hireTotals['overhead_percent'] : 0.0,
        'preliminary_percent' => $hireTotals !== null ? $hireTotals['preliminary_percent'] : 0.0,
        'overhead_amount' => $hireTotals !== null ? $hireTotals['overhead_amount'] : 0.0,
        'preliminary_amount' => $hireTotals !== null ? $hireTotals['preliminary_amount'] : 0.0,
        'quotation_attachment_path' => $quoteAttachmentPath,
        'quotation_attachment_url' => $quoteAttachmentUrl,
        'quotation_attachment_name' => $quoteAttachmentName,
        'quotation_attachment_mime' => $quoteAttachmentMime,
        'quotation_attachment_size' => $quoteAttachmentSize,
    ];
    Db::setRow('purchase_requests', (string) $pr_id, $pr_row);

    if ($procurement_type === 'hire') {
        tnc_hire_save_pr_items($pr_id, $hireLines);
    } else {
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
        $price = (float) $_POST['item_price'][$key];
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

    $viewUrl = app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&created=1';
    if (!empty($_POST['send_line_after_save'])) {
        $lineSend = line_pr_prepare_and_send_line($pr_id);
        if ($lineSend['ok']) {
            $viewUrl .= '&line_notify=sent';
        } else {
            $viewUrl .= '&line_notify=' . rawurlencode((string) ($lineSend['error'] ?? 'failed'));
        }
    }
    tnc_action_redirect($viewUrl);
}

if ($action === 'send_pr_line_approval') {
    $pr_id = (int) ($_POST['pr_id'] ?? 0);
    $viewUrl = $pr_id > 0
        ? app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id
        : app_path('pages/purchase/purchase-request-list.php');
    if ($pr_id <= 0) {
        tnc_action_redirect($viewUrl . '&error=invalid_pr');
    }
    $lineSend = line_pr_prepare_and_send_line($pr_id);
    if ($lineSend['ok']) {
        tnc_action_redirect($viewUrl . '&line_notify=sent');
    }
    tnc_action_redirect($viewUrl . '&line_notify=' . rawurlencode((string) ($lineSend['error'] ?? 'failed')));
}

if ($action === 'pr_web_decision') {
    if (!user_is_admin_only_role()) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์ — เฉพาะ ADMIN');
    }
    $pr_id = (int) ($_POST['pr_id'] ?? 0);
    $decision = strtolower(trim((string) ($_POST['decision'] ?? '')));
    $viewUrl = $pr_id > 0
        ? app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id
        : app_path('pages/purchase/purchase-request-list.php');
    if ($pr_id <= 0) {
        tnc_action_redirect($viewUrl . '&error=invalid_pr');
    }
    $result = line_pr_apply_decision_web($pr_id, $decision, (int) $_SESSION['user_id']);
    if ($result['ok']) {
        $q = $decision === 'approve' ? 'web_approved=1' : 'web_rejected=1';
        tnc_action_redirect($viewUrl . '&' . $q);
    }
    tnc_action_redirect($viewUrl . '&error=pr_decision&message=' . rawurlencode($result['message']));
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
    if (line_pr_normalize_status($existing) === 'approved') {
        tnc_action_redirect(app_path('pages/purchase/purchase-request-list.php') . '?error=pr_approved_locked');
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

    // หมวดค่าใช้จ่าย (หัวข้อย่อยของไซต์)
    $cost_category_id = (int) ($_POST['cost_category_id'] ?? 0);
    $cost_category_name = '';
    if ($cost_category_id > 0 && tnc_site_category_is_valid_for_site($cost_category_id, $site_id)) {
        $cost_category_name = tnc_site_category_name($cost_category_id);
    } else {
        $cost_category_id = 0;
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
    $postRequestType = trim((string) ($_POST['request_type'] ?? ''));
    if (in_array($postRequestType, ['purchase', 'hire'], true)) {
        $procurement_type = $postRequestType;
    } else {
        $procurement_type = trim((string) ($existing['request_type'] ?? ($existing['procurement_type'] ?? 'purchase')));
        if ($procurement_type !== 'hire') {
            $procurement_type = 'purchase';
        }
    }

    $hire_contractor_name = '';
    $hire_contractor_id = 0;
    $hire_employer_company_id = (int) ($existing['hire_employer_company_id'] ?? 0);
    $hire_scope_details = '';
    $hire_total_value = 0.0;
    $hire_installment_count = 1;

    $hireTotals = null;

    if ($procurement_type === 'hire') {
        $resolvedContractor = tnc_contractor_resolve_from_post($_POST);
        if ($resolvedContractor['row'] === null) {
            tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id . '&error=hire_contractor_required');
        }
        $hire_contractor_id = $resolvedContractor['id'];
        $hire_contractor_name = $resolvedContractor['name'];
        $hire_employer_company_id = (int) ($_POST['hire_employer_company_id'] ?? $hire_employer_company_id);
        if ($hire_employer_company_id <= 0) {
            $companies = Db::tableRows('company');
            Db::sortRows($companies, 'id', false);
            $hire_employer_company_id = (int) (($companies[0] ?? [])['id'] ?? 0);
        }
        $hire_scope_details = trim((string) ($_POST['hire_scope_details'] ?? ($_POST['details'] ?? '')));
        $hireLines = tnc_hire_lines_from_post($_POST);
        $directSubtotal = tnc_hire_subtotal_from_lines($hireLines);
        $overheadPct = (float) ($_POST['overhead_percent'] ?? 0);
        $preliminaryPct = (float) ($_POST['preliminary_percent'] ?? 0);
        $vat_enabled = !empty($_POST['vat_enabled']) ? 1 : 0;
        $hireTotals = tnc_hire_pr_compute_totals($directSubtotal, $overheadPct, $preliminaryPct, $vat_enabled === 1);
        $hire_total_value = $hireTotals['excluded_vat'];
        $hire_installment_count = max(1, min(120, (int) ($_POST['hire_installment_count'] ?? ($_POST['installment_total'] ?? 1))));
        if ($hire_contractor_name === '' || $hire_employer_company_id <= 0 || $hire_scope_details === '' || $hire_total_value <= 0 || tnc_hire_count_billable_lines($hireLines) === 0) {
            tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id . '&error=hire_invalid');
        }
        $subtotal = $hireTotals['excluded_vat'];
        $vat_amount = $hireTotals['vat'];
        $total_amount = $hireTotals['grand_total'];
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
            $price = (float) $_POST['item_price'][$key];
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
        'cost_category_id' => $cost_category_id,
        'cost_category_name' => $cost_category_name,
        'total_amount' => $total_amount,
        'vat_enabled' => $vat_enabled,
        'vat_mode' => $vat_mode_stored,
        'subtotal_amount' => $subtotal,
        'vat_amount' => $vat_amount,
        'procurement_type' => $procurement_type,
        'request_type' => $procurement_type,
        'contractor_name' => $procurement_type === 'hire' ? $hire_contractor_name : '',
        'contractor_id' => $procurement_type === 'hire' ? $hire_contractor_id : 0,
        'contract_value' => $procurement_type === 'hire' ? $hire_total_value : 0.0,
        'installment_total' => $procurement_type === 'hire' ? $hire_installment_count : 1,
        'hire_contractor_name' => $hire_contractor_name,
        'hire_employer_company_id' => $hire_employer_company_id,
        'hire_scope_details' => $hire_scope_details,
        'hire_total_value' => $hire_total_value,
        'hire_installment_count' => $hire_installment_count,
        'hire_direct_subtotal' => $hireTotals !== null ? $hireTotals['direct_subtotal'] : 0.0,
        'overhead_percent' => $hireTotals !== null ? $hireTotals['overhead_percent'] : 0.0,
        'preliminary_percent' => $hireTotals !== null ? $hireTotals['preliminary_percent'] : 0.0,
        'overhead_amount' => $hireTotals !== null ? $hireTotals['overhead_amount'] : 0.0,
        'preliminary_amount' => $hireTotals !== null ? $hireTotals['preliminary_amount'] : 0.0,
        'quotation_attachment_path' => $quoteAttachmentPath,
        'quotation_attachment_url' => $quoteAttachmentUrl,
        'quotation_attachment_name' => $quoteAttachmentName,
        'quotation_attachment_mime' => $quoteAttachmentMime,
        'quotation_attachment_size' => $quoteAttachmentSize,
        'status' => 'pending',
        // คงโทเคนเดิมไว้ เพื่อให้ลิงก์อนุมัติ LINE ที่ส่งไปแล้วยังใช้ได้ (อายุไม่จำกัด)
        'line_approval_token' => trim((string) ($existing['line_approval_token'] ?? '')),
        'line_decision' => '',
        'line_decided_at' => '',
        'line_decided_by_line_user_id' => '',
        'line_decided_by_user_id' => 0,
        'line_decision_source' => '',
    ]);
    Db::setRow('purchase_requests', (string) $pr_id, $pr_row);

    Db::deleteWhereEquals('purchase_request_items', 'pr_id', (string) $pr_id);
    if ($procurement_type === 'hire') {
        tnc_hire_save_pr_items($pr_id, $hireLines);
    } else {
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
        $price = (float) $_POST['item_price'][$key];
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

    $lineNotifyQ = '';
    if (!empty($_POST['send_line_after_save'])) {
        $lineSendUp = line_pr_prepare_and_send_line($pr_id);
        $lineNotifyQ = $lineSendUp['ok']
            ? '&line_notify=sent'
            : '&line_notify=' . rawurlencode((string) ($lineSendUp['error'] ?? 'failed'));
    }

    if (trim((string) ($_POST['after_pr_update'] ?? '')) === 'po_from_pr' && $pr_id > 0) {
        tnc_action_redirect(app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&pr_updated=1' . $lineNotifyQ);
    }

    tnc_action_redirect(app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&updated=1' . $lineNotifyQ);
}

if ($action === 'delete_pr') {
    if ($id <= 0) {
        tnc_action_redirect( app_path('pages/purchase/purchase-request-list.php') . '?error=invalid_pr');
    }
    $prSnap = Db::row('purchase_requests', (string) $id);
    if ($prSnap === null) {
        tnc_action_redirect( app_path('pages/purchase/purchase-request-list.php') . '?error=invalid_pr');
    }
    $prNo = trim((string) ($prSnap['pr_number'] ?? ''));
    $nestedDel = tnc_delete_pr_cascade($id);
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
    $resolvedContractor = tnc_contractor_resolve_from_post($_POST);
    if ($resolvedContractor['row'] === null) {
        tnc_action_redirect(app_path('pages/hire-contracts/hire-contract-create.php') . '?error=contractor_required');
    }
    $contractor = $resolvedContractor['name'];
    $contractorId = $resolvedContractor['id'];
    $title = trim((string) ($_POST['title'] ?? ''));
    $amount = (float) ($_POST['contract_amount'] ?? 0);
    $installments = max(1, min(120, (int) ($_POST['installment_total'] ?? 1)));
    if ($contractor === '' || $title === '' || $amount <= 0) {
        tnc_action_redirect(app_path('pages/hire-contracts/hire-contract-create.php') . '?error=required');
    }
    $docNo = Purchase::nextHireContractNumber();
    $contractId = Db::nextNumericId('hire_contracts', 'id');
    $now = date('Y-m-d H:i:s');
    Db::setRow('hire_contracts', (string) $contractId, [
        'id' => $contractId,
        'pr_id' => 0,
        'pr_number' => $docNo,
        'contractor_name' => $contractor,
        'contractor_id' => $contractorId,
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
    if (!line_pr_is_approved_for_po($pr_row)) {
        $st = line_pr_normalize_status($pr_row);
        $err = $st === 'rejected' ? 'pr_rejected' : 'pr_not_approved';
        tnc_action_redirect(app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&error=' . $err);
    }

    $reqType = trim((string) ($pr_row['request_type'] ?? 'purchase'));
    $isHirePr = ($reqType === 'hire');

    if (!$isHirePr) {
        $dup = Db::findFirst('purchase_orders', static function (array $r) use ($pr_id): bool {
            if ($pr_id <= 0 || !isset($r['pr_id']) || (int) $r['pr_id'] !== $pr_id) {
                return false;
            }
            // ข้าม PO ที่ถูกยกเลิกแล้ว เพื่อให้ออก PO ใหม่จาก PR เดิมได้
            return strtolower(trim((string) ($r['status'] ?? ''))) !== 'cancelled';
        });
        if ($dup !== null) {
            tnc_action_redirect( app_path('pages/purchase/purchase-order-view.php') . '?id=' . (int) ($dup['id'] ?? 0));
        }
        if ($supplier_id <= 0) {
            tnc_action_redirect(app_path('pages/purchase/purchase-order-create.php') . '?pr_id=' . $pr_id . '&error=supplier');
        }
    }

    if ($isHirePr) {
        // กันพลาด: สร้างสัญญาจ้างอัตโนมัติถ้ายังไม่มี (เผื่อ PR อนุมัติก่อนมีระบบ auto-create)
        if (method_exists(Purchase::class, 'createHireContractIfNeededForPr')) {
            Purchase::createHireContractIfNeededForPr($pr_id);
        }
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
            // ข้าม PO ที่ถูกยกเลิก เพื่อให้ออกงวดเดิมซ้ำได้
            if (strtolower(trim((string) ($poEx['status'] ?? ''))) === 'cancelled') {
                continue;
            }
            if ((int) ($poEx['installment_no'] ?? 0) === $installmentNo) {
                tnc_action_redirect( app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&error=duplicate_installment');
            }
        }

        $hireLines = tnc_hire_lines_from_post($_POST);
        $hireSubtotal = tnc_hire_subtotal_from_lines($hireLines);
        if ($hireSubtotal <= 0 || count($hireLines) === 0) {
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

        $po_note = mb_substr(trim((string) ($_POST['po_note'] ?? '')), 0, 500);

        $hcCheck = Purchase::hireContractCanIssuePo($hcId, $payable, !empty($_POST['confirm_over_contract']));
        if (!$hcCheck['ok']) {
            tnc_action_redirect(app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&error=' . $hcCheck['message']);
        }

        $installmentTotal = max(1, (int) ($hc['installment_total'] ?? 1));
        $contractorName = trim((string) ($hc['contractor_name'] ?? ($pr_row['contractor_name'] ?? '')));
        $contractorId = (int) ($pr_row['contractor_id'] ?? ($hc['contractor_id'] ?? 0));
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
            'payment_status' => 'unpaid',
            'billing_status' => 'pending',
            'created_by' => $created_by,
            'vat_enabled' => $vat_en,
            'subtotal_amount' => $hireSubtotal,
            'vat_amount' => $vat_amt,
            'order_type' => 'hire',
            'installment_no' => $installmentNo,
            'installment_total' => $installmentTotal,
            'contractor_name' => $contractorName,
            'contractor_id' => $contractorId,
            'reference_pr_number' => (string) ($pr_row['pr_number'] ?? ''),
            'gross_amount' => $gross,
            'payable_amount' => $payable,
            'retention_type' => $retention > 0 ? 'fixed' : 'none',
            'retention_amount' => $retention,
            'withholding_type' => 'none',
            'withholding_amount' => 0,
            'site_id' => $hirePrSiteId,
            'site_name' => $hirePrSiteName,
            'cost_category_id' => (int) ($pr_row['cost_category_id'] ?? 0),
            'cost_category_name' => trim((string) ($pr_row['cost_category_name'] ?? '')),
            'po_note' => $po_note,
        ]);

        tnc_hire_save_po_items($po_id, $hireLines);
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
        'payment_status' => 'unpaid',
        'billing_status' => 'pending',
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
        'cost_category_id' => (int) ($pr_row['cost_category_id'] ?? 0),
        'cost_category_name' => trim((string) ($pr_row['cost_category_name'] ?? '')),
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
            if (strtolower(trim((string) ($poEx['status'] ?? ''))) === 'cancelled') {
                continue;
            }
            if ((int) ($poEx['installment_no'] ?? 0) === $installmentNo) {
                tnc_action_redirect($hireFallback . $hireFbSep . 'error=duplicate_installment');
            }
        }
    }

    $hireLinesDirect = tnc_hire_lines_from_item_post($_POST);
    if (count($hireLinesDirect) > 0) {
        $subtotal = tnc_hire_subtotal_from_lines($hireLinesDirect);
    } else {
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
            $hireLinesDirect = tnc_hire_lines_from_post($_POST);
            if (count($hireLinesDirect) > 0) {
                $subtotal = tnc_hire_subtotal_from_lines($hireLinesDirect);
            }
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
        if (!line_pr_is_approved_for_po($prRowVat)) {
            $st = line_pr_normalize_status($prRowVat);
            $err = $st === 'rejected' ? 'pr_rejected' : 'pr_not_approved';
            tnc_action_redirect(app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id_link . '&error=' . $err);
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
        $hcCheck = Purchase::hireContractCanIssuePo($hire_contract_id, $payable, !empty($_POST['confirm_over_contract']));
        if (!$hcCheck['ok']) {
            tnc_action_redirect($hireFallback . $hireFbSep . 'error=' . $hcCheck['message']);
        }
        $seedAmount = $payable;
        $installmentNo = (int) ($_POST['installment_no'] ?? 0);
        $installmentTotal = max(1, (int) ($hc['installment_total'] ?? 1));
        $contractorName = trim((string) ($hc['contractor_name'] ?? ''));
        $contractorId = (int) ($hc['contractor_id'] ?? 0);
        $hireExtra = [
            'order_type' => 'hire',
            'installment_no' => $installmentNo,
            'installment_total' => $installmentTotal,
            'contractor_name' => $contractorName,
            'contractor_id' => $contractorId,
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
        'payment_status' => 'unpaid',
        'billing_status' => 'pending',
        'created_by' => $created_by,
        'vat_enabled' => $vat_enabled,
        'vat_mode' => $totals['vat_mode'],
        'subtotal_amount' => $subtotal_db,
        'vat_amount' => $vat_amt,
        'gross_amount' => $gross,
        'withholding_type' => $isHireFlow ? 'none' : $totals['withholding_type'],
        'withholding_amount' => $isHireFlow ? 0.0 : $totals['wht'],
    ], $hireExtra));

    $useHireLines = $hire_contract_id > 0 && count($hireLinesDirect) > 0;
    if ($useHireLines) {
        tnc_hire_save_po_items($po_id, $hireLinesDirect);
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
    $isHirePo = trim((string) ($existing['order_type'] ?? 'purchase')) === 'hire';
    if ($isHirePo) {
        $hireLines = tnc_hire_lines_from_post($_POST);
        $hireSubtotal = tnc_hire_subtotal_from_lines($hireLines);
        if ($hireSubtotal <= 0 || count($hireLines) === 0) {
            tnc_action_redirect($editUrl . '?id=' . $po_id . '&error=invalid_hire_rows');
        }

        $vat_enabled = !empty($_POST['vat_enabled']) ? 1 : 0;
        $vat_amt = $vat_enabled ? round($hireSubtotal * 0.07, 2) : 0.0;
        $gross = round($hireSubtotal + $vat_amt, 2);
        $retRaw = trim((string) ($_POST['retention_value'] ?? '0'));
        $retRaw = str_replace('%', '', $retRaw);
        $retention = max(0.0, round((float) $retRaw, 2));
        $payable = max(0.0, round($gross - $retention, 2));
        if ($payable <= 0) {
            tnc_action_redirect($editUrl . '?id=' . $po_id . '&error=invalid_installment_amount');
        }

        $hcId = (int) ($existing['hire_contract_id'] ?? 0);
        if ($hcId > 0) {
            $hcCheck = Purchase::hireContractCanUpdatePoPayable($hcId, $po_id, $payable, !empty($_POST['confirm_over_contract']));
            if (!$hcCheck['ok']) {
                tnc_action_redirect($editUrl . '?id=' . $po_id . '&error=' . $hcCheck['message']);
            }
        }

        $issue_date = trim((string) ($_POST['issue_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) {
            $issue_date = (string) ($existing['issue_date'] ?? date('Y-m-d'));
        }
        $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
        $po_note = mb_substr(trim((string) ($_POST['po_note'] ?? ($existing['po_note'] ?? ''))), 0, 500);

        $beforeSnap = $existing;
        Db::setRow('purchase_orders', $pk, array_merge($existing, [
            'issue_date' => $issue_date,
            'supplier_id' => $supplier_id,
            'po_note' => $po_note,
            'total_amount' => $gross,
            'gross_amount' => $gross,
            'subtotal_amount' => $hireSubtotal,
            'vat_amount' => $vat_amt,
            'vat_enabled' => $vat_enabled,
            'vat_mode' => 'exclusive',
            'payable_amount' => $payable,
            'retention_type' => $retention > 0 ? 'fixed' : 'none',
            'retention_amount' => $retention,
            'withholding_type' => 'none',
            'withholding_amount' => 0,
        ]));

        tnc_po_delete_line_items($po_id);
        tnc_hire_save_po_items($po_id, $hireLines);

        foreach (Db::filter('po_payments', static fn (array $r): bool => (int) ($r['po_id'] ?? 0) === $po_id) as $payRow) {
            $payId = (int) ($payRow['id'] ?? 0);
            if ($payId <= 0) {
                continue;
            }
            $st = strtolower(trim((string) ($payRow['status'] ?? 'unpaid')));
            if ($st !== 'paid') {
                $payPk = Db::pkForLogicalId('po_payments', $payId);
                Db::mergeRow('po_payments', $payPk, ['amount' => $payable]);
            }
        }

        foreach (Db::filter('hire_contract_payments', static fn (array $r): bool => (int) ($r['po_id'] ?? 0) === $po_id) as $hcpRow) {
            $hcpId = (int) ($hcpRow['id'] ?? 0);
            if ($hcpId <= 0) {
                continue;
            }
            Db::mergeRow('hire_contract_payments', (string) $hcpId, ['amount' => $payable]);
        }

        if ($hcId > 0) {
            Purchase::syncHireContractTotals($hcId);
        }

        $afterSnap = Db::row('purchase_orders', $pk);
        $poNo = $afterSnap !== null ? trim((string) ($afterSnap['po_number'] ?? '')) : '';
        tnc_audit_log('update', 'purchase_order', (string) $po_id, $poNo !== '' ? $poNo : ('#' . $po_id), [
            'source' => 'action-handler',
            'action' => 'update_po_direct_hire',
            'before' => $beforeSnap,
            'after' => $afterSnap,
        ]);

        tnc_action_redirect($listUrl . '?updated=1');
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

/** ปัดทิ้ง/คืนค่า PO ที่ไม่สมบูรณ์ — ไม่ให้นับในกล่องแจ้งเตือน (สำหรับใบเก่าที่กรอกย้อนหลังไม่ได้แล้ว) */
if (($action === 'ignore_incomplete_po' || $action === 'unignore_incomplete_po') && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_finance_role();
    $listUrl = app_path('pages/purchase/purchase-order-list.php');
    $po_id = (int) ($_POST['po_id'] ?? 0);
    if ($po_id <= 0) {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    $pk = Db::pkForLogicalId('purchase_orders', $po_id);
    $existing = Db::row('purchase_orders', $pk);
    if ($existing === null) {
        tnc_action_redirect($listUrl . '?error=not_found');
    }
    $ignore = $action === 'ignore_incomplete_po';
    $beforeSnap = $existing;
    Db::mergeRow('purchase_orders', $pk, [
        'incomplete_ignored' => $ignore ? 1 : 0,
        'incomplete_ignored_at' => $ignore ? date('Y-m-d H:i:s') : '',
        'incomplete_ignored_by' => $ignore ? (int) ($_SESSION['user_id'] ?? 0) : 0,
    ]);
    $afterSnap = Db::row('purchase_orders', $pk);
    $poNo = $afterSnap !== null ? trim((string) ($afterSnap['po_number'] ?? '')) : '';
    tnc_audit_log('update', 'purchase_order', (string) $po_id, $poNo !== '' ? $poNo : ('#' . $po_id), [
        'source' => 'action-handler',
        'action' => $action,
        'before' => $beforeSnap,
        'after' => $afterSnap,
    ]);
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'ignored' => $ignore, 'po_id' => $po_id], JSON_UNESCAPED_UNICODE);
        exit;
    }
    tnc_action_redirect($listUrl . ($ignore ? '?po_ignored=1' : '?po_unignored=1'));
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
        $autoBillId = tnc_po_try_auto_bill_on_complete($po_id, $uidPay);
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
    $autoBillId = tnc_po_try_auto_bill_on_complete($po_id, $uidPay);
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

/** บันทึกเลขที่บิลซื้อย้อนหลังจาก PO + สร้างรายการใน /bills */
if ($action === 'receive_po_bill' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_finance_role();
    $listUrl = app_path('pages/purchase/purchase-order-list.php');
    $po_id = (int) ($_POST['po_id'] ?? 0);
    $return_to = trim((string) ($_POST['return_to'] ?? 'list'));

    if ($po_id <= 0) {
        tnc_action_redirect($listUrl . '?error=invalid');
    }

    $poPk = Db::pkForLogicalId('purchase_orders', $po_id);
    $po = Db::row('purchase_orders', $poPk);
    if ($po === null) {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
        tnc_action_redirect($listUrl . '?error=po_cancelled');
    }

    $supplierInvoiceNo = mb_substr(trim((string) ($_POST['supplier_invoice_no'] ?? '')), 0, 120);
    $supplierInvoiceDate = trim((string) ($_POST['supplier_invoice_date'] ?? ''));
    if ($supplierInvoiceNo === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $supplierInvoiceDate) !== 1) {
        $errUrl = $listUrl . '?error=billing_required';
        if ($return_to === 'view') {
            $errUrl = app_path('pages/purchase/purchase-order-view.php') . '?id=' . $po_id . '&error=billing_required';
        }
        tnc_action_redirect($errUrl);
    }

    $postedTotal = trim((string) ($_POST['billed_total_amount'] ?? ''));
    $postedVat = trim((string) ($_POST['billed_vat_amount'] ?? ''));
    if ($postedTotal === '' || $postedVat === '') {
        $errUrl = $listUrl . '?error=billing_amount_invalid';
        if ($return_to === 'view') {
            $errUrl = app_path('pages/purchase/purchase-order-view.php') . '?id=' . $po_id . '&error=billing_amount_invalid';
        }
        tnc_action_redirect($errUrl);
    }
    $billedTotalAmount = (float) str_replace([',', ' '], '', $postedTotal);
    $billedVatAmount = (float) str_replace([',', ' '], '', $postedVat);
    if (!is_finite($billedTotalAmount) || !is_finite($billedVatAmount) || $billedTotalAmount < 0 || $billedVatAmount < 0) {
        $errUrl = $listUrl . '?error=billing_amount_invalid';
        if ($return_to === 'view') {
            $errUrl = app_path('pages/purchase/purchase-order-view.php') . '?id=' . $po_id . '&error=billing_amount_invalid';
        }
        tnc_action_redirect($errUrl);
    }

    $supplierId = (int) ($po['supplier_id'] ?? 0);
    $supplierName = '';
    if ($supplierId > 0) {
        $supplierRow = Db::rowByIdField('suppliers', $supplierId);
        if (is_array($supplierRow)) {
            $supplierName = trim((string) ($supplierRow['name'] ?? ''));
        }
    }
    if ($supplierName === '') {
        $supplierName = trim((string) ($po['supplier_name'] ?? $po['contractor_name'] ?? ''));
    }

    $poBefore = $po;
    Db::mergeRow('purchase_orders', $poPk, [
        'billing_status' => 'billed',
        'supplier_invoice_no' => $supplierInvoiceNo,
        'supplier_invoice_date' => $supplierInvoiceDate,
        'billed_total_amount' => round($billedTotalAmount, 2),
        'billed_vat_amount' => round($billedVatAmount, 2),
        'billing_recorded_at' => date('Y-m-d H:i:s'),
        'billing_recorded_by' => (int) ($_SESSION['user_id'] ?? 0),
    ]);
    $poAfter = Db::row('purchase_orders', $poPk) ?? [];

    $billPoId = (int) ($poAfter['id'] ?? $po_id);
    $billPayload = [
        'po_id' => $billPoId,
        'po_number' => trim((string) ($poAfter['po_number'] ?? '')),
        'supplier_id' => $supplierId,
        'supplier_name' => $supplierName,
        'supplier_invoice_no' => $supplierInvoiceNo,
        'supplier_invoice_date' => $supplierInvoiceDate,
        'vat_amount' => round($billedVatAmount, 2),
        'total_amount' => round($billedTotalAmount, 2),
        'source' => 'po_receive_bill',
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    // ป้องกันบิลซ้ำในตาราง bills: ถ้า PO นี้เคยรับบิลแล้ว ให้แก้ไขแถวเดิมแทนการสร้างใหม่
    $existingBill = Db::findFirst('bills', static function (array $r) use ($billPoId): bool {
        return (int) ($r['po_id'] ?? 0) === $billPoId
            && trim((string) ($r['source'] ?? '')) === 'po_receive_bill';
    });
    if ($existingBill !== null && (int) ($existingBill['id'] ?? 0) > 0) {
        $billId = (int) $existingBill['id'];
        $billPk = Db::pkForLogicalId('bills', $billId);
        Db::mergeRow('bills', $billPk, $billPayload);
    } else {
        $billId = Db::nextNumericId('bills', 'id');
        Db::setRow('bills', (string) $billId, array_merge([
            'id' => $billId,
            'created_by' => (int) ($_SESSION['user_id'] ?? 0),
            'created_at' => date('Y-m-d H:i:s'),
        ], $billPayload));
    }

    $poNo = trim((string) ($poAfter['po_number'] ?? ''));
    tnc_audit_log('update', 'purchase_order', (string) $po_id, $poNo !== '' ? ('บันทึกบิลซื้อ ' . $poNo) : ('บันทึกบิลซื้อ PO#' . $po_id), [
        'source' => 'action-handler',
        'action' => 'receive_po_bill',
        'before' => $poBefore,
        'after' => $poAfter,
        'meta' => [
            'bills_id' => $billId,
            'supplier_invoice_no' => $supplierInvoiceNo,
            'supplier_invoice_date' => $supplierInvoiceDate,
            'billed_total_amount' => round($billedTotalAmount, 2),
            'billed_vat_amount' => round($billedVatAmount, 2),
        ],
    ]);

    $uidBill = (int) ($_SESSION['user_id'] ?? 0);
    $autoBillId = null;
    if ($uidBill > 0) {
        $autoBillId = tnc_purchase_bill_create_from_paid_purchase_order($poAfter, $uidBill);
    }

    if ($return_to === 'view') {
        $url = app_path('pages/purchase/purchase-order-view.php') . '?id=' . $po_id . '&billing_saved=1';
        if ($autoBillId !== null && $autoBillId > 0) {
            $url .= '&auto_bill=1&bill_id=' . (int) $autoBillId;
        }
        tnc_action_redirect($url);
    }
    $listRedirect = $listUrl . '?billing_saved=1';
    if ($autoBillId !== null && $autoBillId > 0) {
        $billMonth = date('Y-m');
        if (preg_match('/^(\d{4}-\d{2})/', $supplierInvoiceDate, $mm) === 1) {
            $billMonth = $mm[1];
        }
        $listRedirect .= '&auto_bill=1&bill_month=' . rawurlencode($billMonth) . '&bill_id=' . (int) $autoBillId . '&print_po_id=' . $po_id;
    }
    tnc_action_redirect($listRedirect);
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
    $uidSlip = (int) ($_SESSION['user_id'] ?? 0);
    $autoBillId = tnc_po_try_auto_bill_on_complete($po_id, $uidSlip);
    if ($autoBillId !== null && $autoBillId > 0) {
        $billMonth = date('Y-m');
        $billDate = trim((string) ($poAfter['supplier_invoice_date'] ?? ''));
        if (preg_match('/^(\d{4}-\d{2})/', $billDate, $mm) === 1) {
            $billMonth = $mm[1];
        }
        tnc_action_redirect($listUrl . '?payment_slips_updated=1&auto_bill=1&bill_month=' . rawurlencode($billMonth) . '&bill_id=' . (int) $autoBillId . '&print_po_id=' . $po_id);
    }
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
    // กันสถานะเพี้ยน: จ่ายแบบโอนแต่ไม่เหลือหลักฐานเลย ให้คืนสถานะเป็น "ยังไม่จ่าย"
    $payMethod = strtolower(trim((string) ($po['payment_method'] ?? 'transfer')));
    $revertedToUnpaid = false;
    if ($afterPaths === [] && $payMethod !== 'cash') {
        Db::mergeRow('purchase_orders', (string) $po_id, [
            'payment_status' => 'unpaid',
            'payment_marked_paid_at' => '',
        ]);
        $revertedToUnpaid = true;
    }
    $poAfter = Db::row('purchase_orders', (string) $po_id);
    $poNo = trim((string) ($poAfter['po_number'] ?? ''));
    tnc_audit_log('update', 'purchase_order', (string) $po_id, $poNo !== '' ? ('ลบหลักฐานจ่าย ' . $poNo) : 'ลบหลักฐานจ่าย PO', [
        'source' => 'action-handler',
        'action' => 'remove_po_payment_slip',
        'before' => $po,
        'after' => $poAfter,
        'meta' => ['removed' => $removePath, 'reverted_to_unpaid' => $revertedToUnpaid],
    ]);
    tnc_action_redirect($listUrl . '?payment_slips_updated=1' . ($revertedToUnpaid ? '&payment_reverted=1' : ''));
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
    $uidReplace = (int) ($_SESSION['user_id'] ?? 0);
    $autoBillId = tnc_po_try_auto_bill_on_complete($po_id, $uidReplace);
    if ($autoBillId !== null && $autoBillId > 0) {
        $billMonth = date('Y-m');
        $billDate = trim((string) ($poAfter['supplier_invoice_date'] ?? ''));
        if (preg_match('/^(\d{4}-\d{2})/', $billDate, $mm) === 1) {
            $billMonth = $mm[1];
        }
        tnc_action_redirect($listUrl . '?payment_slips_updated=1&auto_bill=1&bill_month=' . rawurlencode($billMonth) . '&bill_id=' . (int) $autoBillId . '&print_po_id=' . $po_id);
    }
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
        $linkedBillDeleted = tnc_delete_linked_bills_by_po($id);
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
                'linked_purchase_bills' => $linkedBillDeleted['purchase_bills'],
                'linked_bills' => $linkedBillDeleted['bills'],
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
        tnc_action_redirect( app_path('pages/purchase/purchase-order-list.php') . '?deleted=1');
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
