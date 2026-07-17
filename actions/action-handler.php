<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/tnc_action_response.php';
require_once __DIR__ . '/../includes/tnc_audit_log.php';
require_once __DIR__ . '/../includes/purchase_po_payment_slips.php';
require_once __DIR__ . '/../includes/purchase_quotation_attachment.php';
require_once __DIR__ . '/../includes/line_pr_approval.php';
require_once __DIR__ . '/../includes/purchase_print/vat_print_summary.php';
require_once __DIR__ . '/../includes/site_cost_categories.php';
require_once __DIR__ . '/../includes/site_budget.php';
require_once __DIR__ . '/../includes/suppliers.php';

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

$action = $_GET['action'] ?? '';
$type = $_GET['type'] ?? '';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!isset($_SESSION['user_id'])) {
    exit('Access Denied: กรุณาเข้าสู่ระบบ');
}

// POST-only actions: prevent direct GET access to write endpoints.
if (($action === 'create_po_direct' || $action === 'create_po_from_pr' || $action === 'update_po_payment_status' || $action === 'receive_po_bill' || $action === 'add_po_payment_slips' || $action === 'remove_po_payment_slip' || $action === 'replace_po_payment_slip' || $action === 'update_po_direct' || $action === 'cancel_purchase_order' || $action === 'cancel_purchase_request' || $action === 'cancel_invoice' || $action === 'cancel_tax_invoice' || $action === 'ignore_incomplete_po' || $action === 'unignore_incomplete_po' || $action === 'update_my_profile' || $action === 'send_pr_line_approval' || $action === 'pr_web_decision')
    && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $fallback = match ($action) {
        'create_po_direct' => app_path('pages/purchase/purchase-order-create-direct.php'),
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
$tncDeletePwdActions = ['delete', 'delete_supplier', 'delete_pr'];
if (in_array($action, $tncDeletePwdActions, true)) {
    tnc_require_post_confirm_password();
}

$admin_only_actions = ['add_member', 'edit_member', 'delete_supplier', 'add_company', 'edit_company', 'add_customer', 'edit_customer'];
if (in_array($action, $admin_only_actions, true) && !user_is_admin_role()) {
    if (tnc_ajax_form_requested()) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'เฉพาะผู้ดูแลระบบ (CEO/ADMIN) เท่านั้น'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    exit('Access Denied: เฉพาะผู้ดูแลระบบเท่านั้นที่สามารถดำเนินการนี้ได้');
}

if ($action === 'delete' && $id > 0) {
    $deletePermByType = [
        'invoice' => 'invoice.delete',
        'tax_invoice' => 'invoice.tax_delete',
        'purchase_order' => 'po.delete',
    ];
    if (isset($deletePermByType[$type])) {
        tnc_require_can($deletePermByType[$type], 'ไม่มีสิทธิ์ลบรายการนี้');
    } elseif (!user_is_admin_role()) {
        exit('Access Denied: เฉพาะผู้ดูแลระบบเท่านั้นที่สามารถดำเนินการนี้ได้');
    }
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
function tnc_po_compute_totals(float $taxableSum, int $vatEnabled, string $vatMode, string $withholdingType, float $exemptSum = 0.0, bool $roundToBaht = false): array
{
    $split = tnc_purchase_vat_split_from_line_sums($taxableSum, $exemptSum, $vatEnabled === 1, $vatMode, $roundToBaht);
    $subtotal = $split['subtotal'];
    $vat = $split['vat'];
    $gross = $split['gross'];
    $whtType = ($withholdingType === 'wht3') ? 'wht3' : 'none';
    // WHT / สุทธิ ใช้สตางค์เสมอ (ไม่ปัดเต็มบาท)
    $wht = $whtType === 'wht3' ? tnc_money_round2($subtotal * 0.03) : 0.0;
    $net = tnc_money_round2($gross - $wht);
    $storedVatMode = $vatEnabled ? (in_array($vatMode, ['exclusive', 'inclusive'], true) ? $vatMode : 'exclusive') : 'exclusive';

    return [
        'subtotal' => $subtotal,
        'vat' => $vat,
        'gross' => $gross,
        'wht' => $wht,
        'net' => $net,
        'withholding_type' => $whtType,
        'vat_mode' => $storedVatMode,
        'exempt_sum' => $split['exempt_sum'],
    ];
}

function tnc_purchase_round_to_baht_from_post(): bool
{
    return !empty($_POST['round_to_baht']);
}

function tnc_purchase_item_vat_exempt_from_post(int $key): int
{
    $flags = $_POST['item_vat_exempt'] ?? [];
    if (!is_array($flags)) {
        return 0;
    }

    return (int) ($flags[$key] ?? 0) === 1 ? 1 : 0;
}

/** @return array{taxable: float, exempt: float, line_count: int} */
function tnc_pr_post_purchase_line_vat_sums(bool $roundToBaht = false): array
{
    $taxable = 0.0;
    $exempt = 0.0;
    $lineCount = 0;
    foreach ($_POST['item_description'] ?? [] as $key => $desc) {
        if (!isset($_POST['item_qty'][$key])) {
            continue;
        }
        if (trim((string) $desc) === '') {
            continue;
        }
        $qty = (float) $_POST['item_qty'][$key];
        if ($qty <= 0) {
            continue;
        }
        $lineCount++;
        $price = (float) ($_POST['item_price'][$key] ?? 0);
        $discRaw = trim((string) ($_POST['item_discount'][$key] ?? ''));
        $parts = tnc_pr_parse_line_discount($qty, $price, $discRaw, $roundToBaht);
        if (tnc_purchase_item_vat_exempt_from_post((int) $key) === 1) {
            $exempt += $parts['line_total'];
        } else {
            $taxable += $parts['line_total'];
        }
    }

    return [
        'taxable' => tnc_money_round_mode($taxable, $roundToBaht),
        'exempt' => tnc_money_round_mode($exempt, $roundToBaht),
        'line_count' => $lineCount,
    ];
}

/**
 * Per-line discount for PR/PO lines (same rules as purchase-bill: "10%" or baht amount).
 *
 * @return array{discount_input: string, discount_type: string, discount_value: float, discount_amount: float, line_base: float, line_total: float}
 */
function tnc_pr_parse_line_discount(float $qty, float $price, string $discountRaw, bool $roundToBaht = false): array
{
    $lineBase = tnc_money_mul2($qty, $price, $roundToBaht);
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
            $discountAmount = tnc_money_round_mode($lineBase * $discountValue / 100, $roundToBaht);
        } else {
            $discountType = 'amount';
            $discountValue = (float) str_replace([',', ' '], '', $discountRaw);
            if ($discountValue < 0) {
                $discountValue = 0.0;
            }
            $discountAmount = min($lineBase, tnc_money_round_mode($discountValue, $roundToBaht));
        }
    }
    $lineTotal = tnc_money_round_mode($lineBase - $discountAmount, $roundToBaht);

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
 */
require_once __DIR__ . '/../includes/purchase_cascade_delete.php';

function renderPoCreatedPopupAndRedirect(string $poNumber, ?string $redirectBase = null, bool $paymentExtrasSaved = false, bool $exceedsPr = false)
{
    $listUrl = app_path('pages/purchase/purchase-order-list.php')
        . '?success=1&po_number=' . rawurlencode($poNumber)
        . ($paymentExtrasSaved ? '&payment_saved=1' : '')
        . ($exceedsPr ? '&exceeds_pr=1' : '');
    $message = 'สร้าง PO สำเร็จ หมายเลข ' . $poNumber
        . ($paymentExtrasSaved ? ' (บันทึกบิลและ/หรือสลิปแล้ว)' : '')
        . ($exceedsPr ? ' — ออก PO เกินยอด PR' : '');
    $actionKey = 'po_created';
    if (tnc_ajax_form_requested()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => true,
            'message' => $message,
            'po_number' => $poNumber,
            'action' => $actionKey,
            'exceeds_pr' => $exceedsPr,
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
        $row = Db::rowByIdField('suppliers', $id);
    } elseif ($type === 'company') {
        if (!user_can('page.org.company')) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        require_once dirname(__DIR__) . '/includes/party_logo.php';
        $row = Db::rowByIdField('company', $id);
        if ($row !== null) {
            $row['logo_url'] = tnc_party_logo_public_url((string) ($row['logo'] ?? ''));
        }
    } elseif ($type === 'customer') {
        if (!user_can('page.org.customer')) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        require_once dirname(__DIR__) . '/includes/party_logo.php';
        $row = Db::rowByIdField('customers', $id);
        if ($row !== null) {
            $row['logo_url'] = tnc_party_logo_public_url((string) ($row['logo'] ?? ''));
        }
    } elseif ($type === 'purchase_request' || $type === 'purchase_order') {
        if (!user_can('page.site.hub') && !user_can($type === 'purchase_request' ? 'page.pr' : 'page.po')) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        require_once dirname(__DIR__) . '/includes/purchase_doc_modal.php';
        $payload = tnc_purchase_doc_modal_payload($type === 'purchase_request' ? 'pr' : 'po', $id);
        if ($payload === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
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
    $addr = trim((string) ($_POST['address'] ?? ''));

    $data = array_merge([
        'name' => $name,
        'tax_id' => $tax,
        'contact_person' => $contact,
        'address' => $addr,
    ], tnc_supplier_bank_fields_from_post($_POST));

    if ($s_id > 0) {
        $existing = Db::rowByIdField('suppliers', $s_id);
        if ($existing === null) {
            tnc_action_redirect(app_path('pages/suppliers/supplier-list.php') . '?error=not_found');
        }
        $pk = Db::pkForLogicalId('suppliers', $s_id);
        $data['id'] = $s_id;
        $merged = array_merge($existing, $data);
        unset($merged['phone'], $merged['email']);
        Db::setRow('suppliers', $pk, $merged);
        $after = Db::row('suppliers', $pk) ?? [];
        tnc_audit_log('update', 'supplier', (string) $s_id, $name !== '' ? $name : ('#' . $s_id), [
            'source' => 'action-handler',
            'action' => 'save_supplier',
            'before' => $existing,
            'after' => $after,
        ]);
    } else {
        $nid = Db::nextNumericId('suppliers', 'id');
        $pk = (string) $nid;
        $data['id'] = $nid;
        Db::setRow('suppliers', $pk, $data);
        $after = Db::row('suppliers', $pk) ?? [];
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
    $sDel = Db::rowByIdField('suppliers', $id);
    $sDelName = $sDel !== null ? trim((string) ($sDel['name'] ?? '')) : '';
    Db::deleteRow('suppliers', Db::pkForLogicalId('suppliers', $id));
    tnc_audit_log('delete', 'supplier', (string) $id, $sDelName !== '' ? $sDelName : ('#' . $id), [
        'source' => 'action-handler',
        'action' => 'delete_supplier',
        'before' => $sDel,
    ]);
    tnc_action_redirect( app_path('pages/suppliers/supplier-list.php') . '?deleted=1');
}

// --- PR ---
if ($action === 'save_pr') {
    tnc_require_can('pr.create', 'ไม่มีสิทธิ์สร้าง PR');
    $returnTo = trim((string) ($_POST['return_to'] ?? ''));
    $returnSiteId = (int) ($_POST['return_site_id'] ?? 0);
    $embedMode = !empty($_POST['embed']) || $returnTo === 'site_hub';
    $prCreateRedirect = static function (string $qs = '') use ($returnTo, $returnSiteId, $embedMode): void {
        $base = app_path('pages/purchase/purchase-request-create.php');
        $parts = [];
        if ($embedMode && $returnSiteId > 0) {
            $parts[] = 'site_id=' . $returnSiteId;
            $parts[] = 'embed=1';
        }
        $qs = ltrim($qs, '?&');
        if ($qs !== '') {
            $parts[] = $qs;
        }
        tnc_action_redirect($base . ($parts !== [] ? '?' . implode('&', $parts) : ''));
    };
    $sitesForPr = Db::tableRows('sites');
    $site_id = (int) ($_POST['site_id'] ?? 0);
    if (count($sitesForPr) > 0 && $site_id <= 0) {
        $prCreateRedirect('error=need_site');
    }
    $site_name_saved = '';
    if ($site_id > 0) {
        $siteRow = Db::row('sites', (string) $site_id);
        if ($siteRow === null) {
            $prCreateRedirect('error=need_site');
        }
        $site_name_saved = trim((string) ($siteRow['name'] ?? ''));
    }

    // หมวดค่าใช้จ่าย (หัวข้อย่อยของไซต์) — บังคับเลือกเมื่อมีไซต์ในระบบ
    $cost_category_id = (int) ($_POST['cost_category_id'] ?? 0);
    $cost_category_name = '';
    if (count($sitesForPr) > 0) {
        if ($cost_category_id <= 0 || !tnc_site_category_is_valid_selection_for_site($cost_category_id, $site_id)) {
            $prCreateRedirect('error=need_cost_category');
        }
        $cost_category_name = tnc_site_category_display_name($cost_category_id);
    } elseif ($cost_category_id > 0 && tnc_site_category_is_valid_selection_for_site($cost_category_id, $site_id)) {
        $cost_category_name = tnc_site_category_display_name($cost_category_id);
    } else {
        $cost_category_id = 0;
    }

    $created_at = trim((string) ($_POST['created_at'] ?? date('Y-m-d')));
    $pr_number = Purchase::nextPRNumber();
    $requested_by = (int) ($_POST['requested_by'] ?? 0);
    $created_by = (int) $_SESSION['user_id'];
    $details = trim((string) ($_POST['details'] ?? ''));
    $procurement_type = 'purchase';

    $vat_enabled = !empty($_POST['vat_enabled']) ? 1 : 0;
    $lineSums = tnc_pr_post_purchase_line_vat_sums(tnc_purchase_round_to_baht_from_post());
    $purchaseLineCount = $lineSums['line_count'];
    if ($purchaseLineCount <= 0) {
        $prCreateRedirect('error=no_items');
    }
    $vat_mode_post = trim((string) ($_POST['vat_mode'] ?? 'exclusive'));
    $totalsPr = tnc_po_compute_totals($lineSums['taxable'], $vat_enabled, $vat_mode_post, 'none', $lineSums['exempt'], tnc_purchase_round_to_baht_from_post());
    $subtotal = $totalsPr['subtotal'];
    $vat_amount = $totalsPr['vat'];
    $total_amount = $totalsPr['gross'];

    $pr_id = Db::nextNumericId('purchase_requests', 'id');
    $quoteAttachmentPath = '';
    $quoteAttachmentUrl = '';
    $quoteAttachmentName = '';
    $quoteAttachmentMime = '';
    $quoteAttachmentSize = 0;

    $quotUpload = tnc_purchase_quotation_upload('pr-quotations', $pr_id, $_FILES['quotation_file'] ?? []);
    if (is_array($quotUpload) && empty($quotUpload['ok'])) {
        $prCreateRedirect('error=' . (string) ($quotUpload['error'] ?? 'upload_failed'));
    }
    if (is_array($quotUpload) && !empty($quotUpload['ok'])) {
        $quoteAttachmentPath = (string) $quotUpload['path'];
        $quoteAttachmentUrl = (string) $quotUpload['url'];
        $quoteAttachmentName = (string) $quotUpload['name'];
        $quoteAttachmentMime = (string) $quotUpload['mime'];
        $quoteAttachmentSize = (int) $quotUpload['size'];
    }

    $vat_mode_stored = 'exclusive';
    $vm = trim((string) ($_POST['vat_mode'] ?? 'exclusive'));
    $vat_mode_stored = $vat_enabled && in_array($vm, ['exclusive', 'inclusive'], true) ? $vm : 'exclusive';
    if (!$vat_enabled) {
        $vat_mode_stored = 'exclusive';
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
        'round_to_baht' => tnc_purchase_round_to_baht_from_post() ? 1 : 0,
        'vat_mode' => $vat_mode_stored,
        'subtotal_amount' => $subtotal,
        'vat_amount' => $vat_amount,
        'procurement_type' => $procurement_type,
        'request_type' => $procurement_type,
        'quotation_attachment_path' => $quoteAttachmentPath,
        'quotation_attachment_url' => $quoteAttachmentUrl,
        'quotation_attachment_name' => $quoteAttachmentName,
        'quotation_attachment_mime' => $quoteAttachmentMime,
        'quotation_attachment_size' => $quoteAttachmentSize,
    ];
    Db::setRow('purchase_requests', (string) $pr_id, $pr_row);

    foreach ($_POST['item_description'] ?? [] as $key => $desc) {
        if (!isset($_POST['item_qty'][$key])) {
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
        $price = (float) ($_POST['item_price'][$key] ?? 0);
        $discRaw = trim((string) ($_POST['item_discount'][$key] ?? ''));
        $parts = tnc_pr_parse_line_discount($qty, $price, $discRaw, tnc_purchase_round_to_baht_from_post());
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
            'vat_exempt' => tnc_purchase_item_vat_exempt_from_post((int) $key),
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

    $viewUrl = app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&created=1';
    if (!empty($_POST['send_line_after_save'])) {
        $lineSend = line_pr_prepare_and_send_line($pr_id);
        if ($lineSend['ok']) {
            $viewUrl .= '&line_notify=sent';
        } else {
            $viewUrl .= '&line_notify=' . rawurlencode((string) ($lineSend['error'] ?? 'failed'));
        }
    }
    if ($returnTo === 'site_hub' && $returnSiteId > 0) {
        tnc_action_redirect(app_path('pages/sites/site-hub.php') . '?site_id=' . $returnSiteId . '&pr_created=1');
    }
    tnc_action_redirect($viewUrl);
}

if ($action === 'send_pr_line_approval') {
    tnc_require_can('pr.send_line', 'ไม่มีสิทธิ์ส่ง LINE ขออนุมัติ PR');
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
    tnc_require_can('pr.approve', 'ไม่มีสิทธิ์อนุมัติ PR');
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
    if (!user_can('pr.update') && !user_can('pr.create')) {
        tnc_action_redirect(app_path('pages/purchase/purchase-request-list.php') . '?error=forbidden');
    }
    $pr_id = (int) ($_POST['pr_id'] ?? 0);
    if ($pr_id <= 0) {
        tnc_action_redirect(app_path('pages/purchase/purchase-request-list.php') . '?error=invalid_pr');
    }
    $existing = Db::rowByIdField('purchase_requests', $pr_id);
    if ($existing === null) {
        tnc_action_redirect(app_path('pages/purchase/purchase-request-list.php') . '?error=invalid_pr');
    }
    if (!line_pr_user_can_edit($existing)) {
        tnc_action_redirect(app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&error=pr_approved_locked');
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

    // หมวดค่าใช้จ่าย (หัวข้อย่อยของไซต์) — บังคับเลือกเมื่อมีไซต์ในระบบ
    $cost_category_id = (int) ($_POST['cost_category_id'] ?? 0);
    $cost_category_name = '';
    if (count($sitesForPr) > 0) {
        if ($cost_category_id <= 0 || !tnc_site_category_is_valid_selection_for_site($cost_category_id, $site_id)) {
            tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id . '&error=need_cost_category');
        }
        $cost_category_name = tnc_site_category_display_name($cost_category_id);
    } elseif ($cost_category_id > 0 && tnc_site_category_is_valid_selection_for_site($cost_category_id, $site_id)) {
        $cost_category_name = tnc_site_category_display_name($cost_category_id);
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
    $procurement_type = 'purchase';

    $vat_enabled = !empty($_POST['vat_enabled']) ? 1 : 0;
    $lineSums = tnc_pr_post_purchase_line_vat_sums(tnc_purchase_round_to_baht_from_post());
    $purchaseLineCount = $lineSums['line_count'];
    if ($purchaseLineCount <= 0) {
        tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id . '&error=no_items');
    }
    $vat_mode_post = trim((string) ($_POST['vat_mode'] ?? 'exclusive'));
    $totalsPr = tnc_po_compute_totals($lineSums['taxable'], $vat_enabled, $vat_mode_post, 'none', $lineSums['exempt'], tnc_purchase_round_to_baht_from_post());
    $subtotal = $totalsPr['subtotal'];
    $vat_amount = $totalsPr['vat'];
    $total_amount = $totalsPr['gross'];

    $quoteAttachmentPath = trim((string) ($existing['quotation_attachment_path'] ?? ''));
    $quoteAttachmentUrl = trim((string) ($existing['quotation_attachment_url'] ?? ''));
    $quoteAttachmentName = trim((string) ($existing['quotation_attachment_name'] ?? ''));
    $quoteAttachmentMime = trim((string) ($existing['quotation_attachment_mime'] ?? ''));
    $quoteAttachmentSize = (int) ($existing['quotation_attachment_size'] ?? 0);

    $quotUpload = tnc_purchase_quotation_upload('pr-quotations', $pr_id, $_FILES['quotation_file'] ?? []);
    if (is_array($quotUpload) && empty($quotUpload['ok'])) {
        tnc_action_redirect(app_path('pages/purchase/purchase-request-create.php') . '?id=' . $pr_id . '&error=' . (string) ($quotUpload['error'] ?? 'upload_failed'));
    }
    if (is_array($quotUpload) && !empty($quotUpload['ok'])) {
        $quoteAttachmentPath = (string) $quotUpload['path'];
        $quoteAttachmentUrl = (string) $quotUpload['url'];
        $quoteAttachmentName = (string) $quotUpload['name'];
        $quoteAttachmentMime = (string) $quotUpload['mime'];
        $quoteAttachmentSize = (int) $quotUpload['size'];
    }

    $vat_mode_stored = 'exclusive';
    $vm = trim((string) ($_POST['vat_mode'] ?? 'exclusive'));
    $vat_mode_stored = $vat_enabled && in_array($vm, ['exclusive', 'inclusive'], true) ? $vm : 'exclusive';
    if (!$vat_enabled) {
        $vat_mode_stored = 'exclusive';
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
        'round_to_baht' => tnc_purchase_round_to_baht_from_post() ? 1 : 0,
        'vat_mode' => $vat_mode_stored,
        'subtotal_amount' => $subtotal,
        'vat_amount' => $vat_amount,
        'procurement_type' => $procurement_type,
        'request_type' => $procurement_type,
        'quotation_attachment_path' => $quoteAttachmentPath,
        'quotation_attachment_url' => $quoteAttachmentUrl,
        'quotation_attachment_name' => $quoteAttachmentName,
        'quotation_attachment_mime' => $quoteAttachmentMime,
        'quotation_attachment_size' => $quoteAttachmentSize,
    ], line_pr_status_fields_for_update($existing));
    Db::setRow('purchase_requests', (string) $pr_id, $pr_row);

    Db::deleteWhereEquals('purchase_request_items', 'pr_id', (string) $pr_id);
    foreach ($_POST['item_description'] ?? [] as $key => $desc) {
        if (!isset($_POST['item_qty'][$key])) {
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
        $price = (float) ($_POST['item_price'][$key] ?? 0);
        $discRaw = trim((string) ($_POST['item_discount'][$key] ?? ''));
        $parts = tnc_pr_parse_line_discount($qty, $price, $discRaw, tnc_purchase_round_to_baht_from_post());
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
            'vat_exempt' => tnc_purchase_item_vat_exempt_from_post((int) $key),
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

    require_once dirname(__DIR__) . '/includes/pr_po_sync.php';
    $poSyncResult = is_array($prAfterSave)
        ? tnc_pr_sync_linked_purchase_orders($pr_id, $prAfterSave)
        : ['synced' => 0, 'skipped' => [], 'errors' => []];

    $lineNotifyQ = '';
    if (!empty($_POST['send_line_after_save'])) {
        $lineSendUp = line_pr_prepare_and_send_line($pr_id);
        $lineNotifyQ = $lineSendUp['ok']
            ? '&line_notify=sent'
            : '&line_notify=' . rawurlencode((string) ($lineSendUp['error'] ?? 'failed'));
    }

    if (trim((string) ($_POST['after_pr_update'] ?? '')) === 'po_from_pr' && $pr_id > 0) {
        tnc_action_redirect(app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&pr_updated=1' . $lineNotifyQ . tnc_pr_po_sync_query_suffix($poSyncResult));
    }

    tnc_action_redirect(app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&updated=1' . $lineNotifyQ . tnc_pr_po_sync_query_suffix($poSyncResult));
}

if ($action === 'delete_pr') {
    tnc_require_can('pr.delete', 'ไม่มีสิทธิ์ลบ PR');
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
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    $hubBase = app_path('pages/sites/site-hub.php');
    if ($redirectTo !== '' && str_starts_with($redirectTo, $hubBase)) {
        $sep = str_contains($redirectTo, '?') ? '&' : '?';
        tnc_action_redirect($redirectTo . $sep . 'doc_deleted=1');
    }
    tnc_action_redirect( app_path('pages/purchase/purchase-request-list.php') . '?deleted=1');
}

// --- ยกเลิกใบขอซื้อ (สถานะ cancelled) ---
if ($action === 'cancel_purchase_request' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_can('pr.cancel', 'ไม่มีสิทธิ์ยกเลิก PR');
    $listUrl = app_path('pages/purchase/purchase-request-list.php');
    $viewUrl = app_path('pages/purchase/purchase-request-view.php');
    $pr_id = (int) ($_POST['pr_id'] ?? 0);
    if ($pr_id <= 0) {
        tnc_action_redirect($listUrl . '?error=invalid_pr');
    }
    $pk = Db::pkForLogicalId('purchase_requests', $pr_id);
    $existing = Db::row('purchase_requests', $pk);
    if ($existing === null) {
        $existing = Db::rowByIdField('purchase_requests', $pr_id);
        if ($existing === null) {
            tnc_action_redirect($listUrl . '?error=invalid_pr');
        }
        $pk = Db::pkForLogicalId('purchase_requests', $pr_id);
    }
    if (line_pr_is_cancelled($existing)) {
        $returnTo = trim((string) ($_POST['return_to'] ?? ''));
        if ($returnTo === 'view') {
            tnc_action_redirect($viewUrl . '?id=' . $pr_id . '&error=already_cancelled');
        }
        tnc_action_redirect($listUrl . '?error=already_cancelled');
    }

    require_once dirname(__DIR__) . '/includes/pr_po_split.php';
    $activePos = function_exists('tnc_pr_collect_active_purchase_orders')
        ? tnc_pr_collect_active_purchase_orders($pr_id)
        : [];
    if ($activePos !== []) {
        $returnTo = trim((string) ($_POST['return_to'] ?? ''));
        if ($returnTo === 'view') {
            tnc_action_redirect($viewUrl . '?id=' . $pr_id . '&error=pr_has_active_po');
        }
        tnc_action_redirect($listUrl . '?error=pr_has_active_po');
    }

    $beforeSnap = $existing;
    Db::mergeRow('purchase_requests', $pk, [
        'status' => 'cancelled',
        'cancelled_at' => date('Y-m-d H:i:s'),
        'cancelled_by' => (int) ($_SESSION['user_id'] ?? 0),
    ]);
    $afterSnap = Db::row('purchase_requests', $pk);
    $prNo = $afterSnap !== null ? trim((string) ($afterSnap['pr_number'] ?? '')) : '';
    tnc_audit_log('update', 'purchase_request', (string) $pr_id, $prNo !== '' ? $prNo : ('#' . $pr_id), [
        'source' => 'action-handler',
        'action' => 'cancel_purchase_request',
        'before' => $beforeSnap,
        'after' => $afterSnap,
    ]);
    $returnTo = trim((string) ($_POST['return_to'] ?? ''));
    if ($returnTo === 'view') {
        tnc_action_redirect($viewUrl . '?id=' . $pr_id . '&cancelled=1');
    }
    tnc_action_redirect($listUrl . '?cancelled=1');
}

// --- PO from PR ---
if ($action === 'create_po_from_pr') {
    tnc_require_can('po.create', 'ไม่มีสิทธิ์สร้าง PO');
    $pr_id = (int) ($_POST['pr_id'] ?? 0);
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $created_by = (int) $_SESSION['user_id'];

    $pr_row = Db::row('purchase_requests', (string) $pr_id);
    if ($pr_row === null) {
        tnc_action_redirect( app_path('pages/purchase/purchase-request-list.php') . '?error=pr_not_found');
    }
    try {
        $po_number = Purchase::poNumberFromPrSplit($pr_row, $pr_id);
    } catch (InvalidArgumentException) {
        tnc_action_redirect(app_path('pages/purchase/purchase-order-create.php') . '?pr_id=' . $pr_id . '&error=invalid_pr_number');
    }
    if (Purchase::poNumberTaken($po_number)) {
        tnc_action_redirect(app_path('pages/purchase/purchase-order-create.php') . '?pr_id=' . $pr_id . '&error=po_number_conflict');
    }
    if (!line_pr_is_approved_for_po($pr_row)) {
        $st = line_pr_normalize_status($pr_row);
        $err = match ($st) {
            'rejected' => 'pr_rejected',
            'cancelled' => 'pr_cancelled',
            default => 'pr_not_approved',
        };
        tnc_action_redirect(app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&error=' . $err);
    }

    require_once dirname(__DIR__) . '/includes/pr_po_split.php';

    $poCreateFromPrUrl = app_path('pages/purchase/purchase-order-create.php') . '?pr_id=' . $pr_id;

    $vat_en = !empty($_POST['vat_enabled']) ? 1 : 0;
    $vat_mode_post = trim((string) ($_POST['vat_mode'] ?? 'exclusive'));
    if (!in_array($vat_mode_post, ['exclusive', 'inclusive'], true)) {
        $vat_mode_post = 'exclusive';
    }

    $subtotal = 0.0;
    $purchaseLineCount = 0;
    $poItemsToSave = [];
    foreach ($_POST['item_description'] ?? [] as $key => $desc) {
        if (!isset($_POST['item_qty'][$key])) {
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
        $price = (float) ($_POST['item_price'][$key] ?? 0);
        $discRaw = trim((string) ($_POST['item_discount'][$key] ?? ''));
        $parts = tnc_pr_parse_line_discount($qty, $price, $discRaw, tnc_purchase_round_to_baht_from_post());
        $purchaseLineCount++;
        $subtotal += $parts['line_total'];
        $poItemsToSave[] = [
            'description' => $desc,
            'quantity' => $qty,
            'unit' => trim((string) ($_POST['item_unit'][$key] ?? '')),
            'unit_price' => $price,
            'total' => $parts['line_total'],
            'discount_input' => $parts['discount_input'],
            'discount_type' => $parts['discount_type'],
            'discount_value' => $parts['discount_value'],
            'discount_amount' => $parts['discount_amount'],
            'vat_exempt' => tnc_purchase_item_vat_exempt_from_post((int) $key),
        ];
    }
    $lineSums = tnc_pr_post_purchase_line_vat_sums(tnc_purchase_round_to_baht_from_post());
    $subtotal = round($lineSums['taxable'] + $lineSums['exempt'], 2);
    if ($purchaseLineCount <= 0 || $subtotal <= 0) {
        tnc_action_redirect($poCreateFromPrUrl . '&error=no_items');
    }
    $totalsPr = tnc_po_compute_totals($lineSums['taxable'], $vat_en, $vat_mode_post, 'none', $lineSums['exempt'], tnc_purchase_round_to_baht_from_post());
    $sub_amt = $totalsPr['subtotal'];
    $vat_amt = $totalsPr['vat'];
    $total_amount = $totalsPr['gross'];
    $vat_mode_stored = $totalsPr['vat_mode'];

    $postedBillTotal = trim((string) ($_POST['billed_total_amount'] ?? ''));
    $postedBillVat = trim((string) ($_POST['billed_vat_amount'] ?? ''));
    if ($postedBillTotal !== '') {
        $parsedBillTotal = (float) str_replace([',', ' '], '', $postedBillTotal);
        if ($parsedBillTotal > 0) {
            $total_amount = $parsedBillTotal;
        }
    }
    if ($postedBillVat !== '') {
        $parsedBillVat = (float) str_replace([',', ' '], '', $postedBillVat);
        if ($parsedBillVat >= 0) {
            $vat_amt = $parsedBillVat;
        }
    }

    $poExceedsPr = tnc_pr_new_po_would_exceed($pr_id, $poItemsToSave, (float) $total_amount);

    $quotation_number = mb_substr(trim((string) ($_POST['quotation_number'] ?? '')), 0, 120);
    $quotation_date = trim((string) ($_POST['quotation_date'] ?? ''));
    if ($quotation_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $quotation_date)) {
        $quotation_date = '';
    }
    $quotation_note = mb_substr(trim((string) ($_POST['quotation_note'] ?? '')), 0, 500);
    $po_note = mb_substr(trim((string) ($_POST['po_note'] ?? '')), 0, 500);

    $issue_date = trim((string) ($_POST['issue_date'] ?? date('Y-m-d')));
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $issue_date, $issueDm) === 1) {
        $issue_date = sprintf('%04d-%02d-%02d', (int) $issueDm[3], (int) $issueDm[2], (int) $issueDm[1]);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) {
        tnc_action_redirect($poCreateFromPrUrl . '&error=billing_required');
    }

    $paymentMethodPre = strtolower(trim((string) ($_POST['payment_method'] ?? 'transfer')));
    if (!in_array($paymentMethodPre, ['cash', 'transfer'], true)) {
        $paymentMethodPre = 'transfer';
    }
    if ($paymentMethodPre === 'cash' && trim((string) ($_POST['payment_cash_paid_by'] ?? '')) === '') {
        tnc_action_redirect($poCreateFromPrUrl . '&error=cash_paid_by_required');
    }

    $prSiteId = (int) ($_POST['site_id'] ?? 0);
    if ($prSiteId <= 0) {
        $prSiteId = (int) ($pr_row['site_id'] ?? 0);
    }
    $prSiteName = trim((string) ($_POST['site_name'] ?? ''));
    if ($prSiteName === '') {
        $prSiteName = trim((string) ($pr_row['site_name'] ?? ''));
    }
    if ($prSiteName === '' && $prSiteId > 0) {
        $siteRowPo = Db::row('sites', (string) $prSiteId);
        if (is_array($siteRowPo)) {
            $prSiteName = trim((string) ($siteRowPo['name'] ?? ''));
        }
    }

    $prCostCategoryId = (int) ($_POST['cost_category_id'] ?? 0);
    if ($prCostCategoryId <= 0) {
        $prCostCategoryId = (int) ($pr_row['cost_category_id'] ?? 0);
    }
    $prCostCategoryName = trim((string) ($_POST['cost_category_name'] ?? ''));
    if ($prCostCategoryName === '') {
        $prCostCategoryName = trim((string) ($pr_row['cost_category_name'] ?? ''));
    }
    if ($prCostCategoryName === '' && $prCostCategoryId > 0) {
        if (!function_exists('tnc_site_category_display_name')) {
            require_once dirname(__DIR__) . '/includes/site_cost_categories.php';
        }
        $prCostCategoryName = tnc_site_category_display_name($prCostCategoryId);
    }

    require_once dirname(__DIR__) . '/includes/site_budget.php';
    tnc_site_budget_abort_if_invalid(
        $prSiteId,
        $prCostCategoryId,
        (float) $total_amount,
        null,
        $poCreateFromPrUrl
    );

    $po_id = Db::nextNumericId('purchase_orders', 'id');
    $quoteAttachmentPath = '';
    $quoteAttachmentUrl = '';
    $quoteAttachmentName = '';
    $quoteAttachmentMime = '';
    $quoteAttachmentSize = 0;

    $quotUpload = tnc_purchase_quotation_upload('po-quotations', $po_id, $_FILES['quotation_file'] ?? []);
    if (is_array($quotUpload) && empty($quotUpload['ok'])) {
        $errCode = (string) ($quotUpload['error'] ?? 'upload_failed');
        if ($errCode === 'upload_type') {
            $errCode = 'quotation_upload_type';
        } elseif ($errCode === 'upload_failed') {
            $errCode = 'quotation_upload_failed';
        }
        tnc_action_redirect($poCreateFromPrUrl . '&error=' . $errCode);
    }
    if (is_array($quotUpload) && !empty($quotUpload['ok'])) {
        $quoteAttachmentPath = (string) $quotUpload['path'];
        $quoteAttachmentUrl = (string) $quotUpload['url'];
        $quoteAttachmentName = (string) $quotUpload['name'];
        $quoteAttachmentMime = (string) $quotUpload['mime'];
        $quoteAttachmentSize = (int) $quotUpload['size'];
    } else {
        $prQuotePath = trim((string) ($pr_row['quotation_attachment_path'] ?? ''));
        if ($prQuotePath !== '') {
            $copied = tnc_purchase_quotation_copy_from_path(
                $prQuotePath,
                'po-quotations',
                $po_id,
                trim((string) ($pr_row['quotation_attachment_name'] ?? '')),
                trim((string) ($pr_row['quotation_attachment_mime'] ?? ''))
            );
            if (is_array($copied) && !empty($copied['ok'])) {
                $quoteAttachmentPath = (string) $copied['path'];
                $quoteAttachmentUrl = (string) $copied['url'];
                $quoteAttachmentName = (string) $copied['name'];
                $quoteAttachmentMime = (string) $copied['mime'];
                $quoteAttachmentSize = (int) $copied['size'];
            }
        }
    }

    $optionalExtras = tnc_po_optional_create_extras(
        $po_id,
        $po_number,
        $supplier_id,
        $created_by,
        $issue_date,
        $total_amount,
        $vat_amt
    );
    Db::setRow('purchase_orders', (string) $po_id, array_merge([
        'id' => $po_id,
        'po_number' => $po_number,
        'pr_id' => $pr_id,
        'supplier_id' => $supplier_id,
        'created_at' => date('Y-m-d'),
        'issue_date' => $issue_date,
        'reference_pr_number' => trim((string) ($pr_row['pr_number'] ?? '')),
        'quotation_number' => $quotation_number,
        'quotation_date' => $quotation_date,
        'quotation_note' => $quotation_note,
        'po_note' => $po_note,
        'quotation_attachment_path' => $quoteAttachmentPath,
        'quotation_attachment_url' => $quoteAttachmentUrl,
        'quotation_attachment_name' => $quoteAttachmentName,
        'quotation_attachment_mime' => $quoteAttachmentMime,
        'quotation_attachment_size' => $quoteAttachmentSize,
        'total_amount' => $total_amount,
        'status' => 'ordered',
        'payment_status' => 'unpaid',
        'billing_status' => 'pending',
        'created_by' => $created_by,
        'vat_enabled' => $vat_en,
        'round_to_baht' => tnc_purchase_round_to_baht_from_post() ? 1 : 0,
        'vat_mode' => $vat_mode_stored,
        'subtotal_amount' => $sub_amt,
        'vat_amount' => $vat_amt,
        'gross_amount' => $total_amount,
        'order_type' => 'purchase',
        'site_id' => $prSiteId,
        'site_name' => $prSiteName,
        'cost_category_id' => $prCostCategoryId,
        'cost_category_name' => $prCostCategoryName,
        'exceeds_pr' => $poExceedsPr ? 1 : 0,
    ], $optionalExtras['po_fields']));

    foreach ($poItemsToSave as $item) {
        $iid = Db::nextNumericId('purchase_order_items', 'id');
        Db::setRow('purchase_order_items', (string) $iid, [
            'id' => $iid,
            'po_id' => $po_id,
            'description' => $item['description'],
            'quantity' => $item['quantity'],
            'unit' => $item['unit'],
            'unit_price' => $item['unit_price'],
            'total' => $item['total'],
            'discount_input' => $item['discount_input'],
            'discount_type' => $item['discount_type'],
            'discount_value' => $item['discount_value'],
            'discount_amount' => $item['discount_amount'],
            'vat_exempt' => (int) ($item['vat_exempt'] ?? 0),
        ]);
    }
    if (method_exists(Purchase::class, 'seedPoPayments')) {
        Purchase::seedPoPayments($po_id, $total_amount, null);
    }

    tnc_audit_purchase_order_created($po_id, 'create_po_from_pr_purchase');
    renderPoCreatedPopupAndRedirect((string) $po_number, null, $optionalExtras['extras_saved'], $poExceedsPr);
}

// --- PO โดยตรง (ไม่อิง PR) ---
if ($action === 'create_po_direct') {
    tnc_require_can('po.create', 'ไม่มีสิทธิ์สร้าง PO');
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $pr_id_link = (int) ($_POST['pr_id'] ?? 0);
    $vat_enabled = !empty($_POST['vat_enabled']) ? 1 : 0;
    $created_by = (int) $_SESSION['user_id'];
    $po_number = Purchase::generateDirectPONumber();
    $poCreateDirectUrl = app_path('pages/purchase/purchase-order-create-direct.php');
    $returnTo = trim((string) ($_POST['return_to'] ?? ''));
    $returnSiteId = (int) ($_POST['return_site_id'] ?? 0);
    $embedMode = !empty($_POST['embed']) || $returnTo === 'site_hub';
    $poDirectErrorRedirect = static function (string $qs) use ($poCreateDirectUrl, $pr_id_link, $embedMode, $returnSiteId): void {
        $qs = ltrim($qs, '?&');
        if ($pr_id_link > 0) {
            tnc_action_redirect(app_path('pages/purchase/purchase-order-create.php') . '?pr_id=' . $pr_id_link . ($qs !== '' ? '&' . $qs : ''));
        }
        $parts = [];
        if ($embedMode && $returnSiteId > 0) {
            $parts[] = 'site_id=' . $returnSiteId;
            $parts[] = 'embed=1';
        }
        if ($qs !== '') {
            $parts[] = $qs;
        }
        tnc_action_redirect($poCreateDirectUrl . ($parts !== [] ? '?' . implode('&', $parts) : ''));
    };
    $formFallbackUrl = $pr_id_link > 0
        ? app_path('pages/purchase/purchase-order-create.php') . '?pr_id=' . $pr_id_link
        : $poCreateDirectUrl;
    $formFbSep = str_contains($formFallbackUrl, '?') ? '&' : '?';

    if ($supplier_id <= 0) {
        $poDirectErrorRedirect('error=supplier');
    }


    $lineSums = tnc_pr_post_purchase_line_vat_sums(tnc_purchase_round_to_baht_from_post());
    if ($lineSums['line_count'] <= 0 || ($lineSums['taxable'] + $lineSums['exempt']) <= 0) {
        $poDirectErrorRedirect('error=no_items');
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

    $totals = tnc_po_compute_totals($lineSums['taxable'], $vat_enabled, $vat_mode_post, $wht_post, $lineSums['exempt'], tnc_purchase_round_to_baht_from_post());
    $vat_amt = $totals['vat'];
    $gross = $totals['gross'];
    $subtotal_db = $totals['subtotal'];

    $issue_date = trim((string) ($_POST['issue_date'] ?? date('Y-m-d')));
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $issue_date, $issueDm) === 1) {
        $issue_date = sprintf('%04d-%02d-%02d', (int) $issueDm[3], (int) $issueDm[2], (int) $issueDm[1]);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) {
        $issue_date = date('Y-m-d');
    }

    $isStandalonePurchasePo = $pr_id_link <= 0;
    if ($isStandalonePurchasePo) {
        $paymentMethodPre = strtolower(trim((string) ($_POST['payment_method'] ?? 'transfer')));
        if (!in_array($paymentMethodPre, ['cash', 'transfer'], true)) {
            $paymentMethodPre = 'transfer';
        }
        if ($paymentMethodPre === 'cash' && trim((string) ($_POST['payment_cash_paid_by'] ?? '')) === '') {
            $poDirectErrorRedirect('error=cash_paid_by_required');
        }
    }
    $poSiteId = 0;
    $poSiteName = '';
    $poCostCategoryId = 0;
    $poCostCategoryName = '';
    if ($isStandalonePurchasePo) {
        $sitesForPo = Db::tableRows('sites');
        $poSiteId = (int) ($_POST['site_id'] ?? 0);
        if (count($sitesForPo) > 0 && $poSiteId <= 0) {
            $poDirectErrorRedirect('error=need_site');
        }
        if ($poSiteId > 0) {
            $siteRowPoDirect = Db::row('sites', (string) $poSiteId);
            if ($siteRowPoDirect === null) {
                $poDirectErrorRedirect('error=need_site');
            }
            $poSiteName = trim((string) ($siteRowPoDirect['name'] ?? ''));
        }
        $poCostCategoryId = (int) ($_POST['cost_category_id'] ?? 0);
        if (count($sitesForPo) > 0) {
            if ($poCostCategoryId <= 0 || !tnc_site_category_is_valid_selection_for_site($poCostCategoryId, $poSiteId)) {
                $poDirectErrorRedirect('error=need_cost_category');
            }
            $poCostCategoryName = tnc_site_category_display_name($poCostCategoryId);
        } elseif ($poCostCategoryId > 0 && tnc_site_category_is_valid_selection_for_site($poCostCategoryId, $poSiteId)) {
            $poCostCategoryName = tnc_site_category_display_name($poCostCategoryId);
        } else {
            $poCostCategoryId = 0;
        }
    }
    $standaloneInvoiceNo = '';
    $standaloneInvoiceDate = '';
    $standaloneBilledTotal = 0.0;
    $standaloneBilledVat = 0.0;
    if ($isStandalonePurchasePo) {
        $standaloneInvoiceNo = mb_substr(trim((string) ($_POST['supplier_invoice_no'] ?? '')), 0, 120);
        $standaloneInvoiceDate = trim((string) ($_POST['supplier_invoice_date'] ?? ''));
        if ($standaloneInvoiceDate === '') {
            $standaloneInvoiceDate = $issue_date;
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $standaloneInvoiceDate, $invDm) === 1) {
            $standaloneInvoiceDate = sprintf('%04d-%02d-%02d', (int) $invDm[3], (int) $invDm[2], (int) $invDm[1]);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $standaloneInvoiceDate) !== 1) {
            $poDirectErrorRedirect('error=billing_required');
        }
        $postedBillTotal = trim((string) ($_POST['billed_total_amount'] ?? ''));
        $postedBillVat = trim((string) ($_POST['billed_vat_amount'] ?? ''));
        $standaloneBilledTotal = $postedBillTotal !== ''
            ? (float) str_replace([',', ' '], '', $postedBillTotal)
            : (float) $totals['net'];
        $standaloneBilledVat = $postedBillVat !== ''
            ? (float) str_replace([',', ' '], '', $postedBillVat)
            : (float) $vat_amt;
        if ($standaloneBilledTotal <= 0) {
            $standaloneBilledTotal = (float) $totals['net'];
        }
        if ($standaloneBilledVat < 0) {
            $standaloneBilledVat = (float) $vat_amt;
        }
    }

    $quotation_number = mb_substr(trim((string) ($_POST['quotation_number'] ?? '')), 0, 120);
    $quotation_note = mb_substr(trim((string) ($_POST['quotation_note'] ?? '')), 0, 500);
    $po_note_direct = mb_substr(trim((string) ($_POST['po_note'] ?? '')), 0, 500);
    $seedAmount = $totals['net'];


    $po_id = Db::nextNumericId('purchase_orders', 'id');

    $quoteAttachmentPath = '';
    $quoteAttachmentUrl = '';
    $quoteAttachmentName = '';
    $quoteAttachmentMime = '';
    $quoteAttachmentSize = 0;
    $quotUpload = tnc_purchase_quotation_upload('po-quotations', $po_id, $_FILES['quotation_file'] ?? []);
    if (is_array($quotUpload) && empty($quotUpload['ok'])) {
        $errCode = (string) ($quotUpload['error'] ?? 'upload_failed');
        if ($errCode === 'upload_type') {
            $errCode = 'quotation_upload_type';
        } elseif ($errCode === 'upload_failed') {
            $errCode = 'quotation_upload_failed';
        }
        $poDirectErrorRedirect('error=' . $errCode);
    }
    if (is_array($quotUpload) && !empty($quotUpload['ok'])) {
        $quoteAttachmentPath = (string) $quotUpload['path'];
        $quoteAttachmentUrl = (string) $quotUpload['url'];
        $quoteAttachmentName = (string) $quotUpload['name'];
        $quoteAttachmentMime = (string) $quotUpload['mime'];
        $quoteAttachmentSize = (int) $quotUpload['size'];
    }

    if ($isStandalonePurchasePo) {
        $budgetRedirectUrl = $poCreateDirectUrl;
        if ($embedMode && $returnSiteId > 0) {
            $budgetRedirectUrl .= '?site_id=' . $returnSiteId . '&embed=1';
        }
        tnc_site_budget_abort_if_invalid(
            $poSiteId,
            $poCostCategoryId,
            (float) $totals['net'],
            null,
            $budgetRedirectUrl
        );
    }

    Db::setRow('purchase_orders', (string) $po_id, array_merge([
        'id' => $po_id,
        'po_number' => $po_number,
        'pr_id' => $pr_id_link > 0 ? $pr_id_link : 0,
        'supplier_id' => $supplier_id,
        'order_type' => 'purchase',
        'created_at' => date('Y-m-d'),
        'issue_date' => $issue_date,
        'quotation_number' => $quotation_number,
        'quotation_note' => $quotation_note,
        'po_note' => $po_note_direct,
        'quotation_attachment_path' => $quoteAttachmentPath,
        'quotation_attachment_url' => $quoteAttachmentUrl,
        'quotation_attachment_name' => $quoteAttachmentName,
        'quotation_attachment_mime' => $quoteAttachmentMime,
        'quotation_attachment_size' => $quoteAttachmentSize,
        'total_amount' => $totals['net'],
        'status' => 'ordered',
        'payment_status' => 'unpaid',
        'billing_status' => 'pending',
        'created_by' => $created_by,
        'vat_enabled' => $vat_enabled,
        'round_to_baht' => tnc_purchase_round_to_baht_from_post() ? 1 : 0,
        'vat_mode' => $totals['vat_mode'],
        'subtotal_amount' => $subtotal_db,
        'vat_amount' => $vat_amt,
        'gross_amount' => $gross,
        'withholding_type' => $totals['withholding_type'],
        'withholding_amount' => $totals['wht'],
    ], $isStandalonePurchasePo ? [
        'site_id' => $poSiteId,
        'site_name' => $poSiteName,
        'cost_category_id' => $poCostCategoryId,
        'cost_category_name' => $poCostCategoryName,
    ] : []));

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
            $discRaw = trim((string) ($_POST['item_discount'][$key] ?? ''));
            $parts = tnc_pr_parse_line_discount($qty, $price, $discRaw, tnc_purchase_round_to_baht_from_post());
            $lineTotal = $parts['line_total'];
            Db::setRow('purchase_order_items', (string) $iid, [
                'id' => $iid,
                'po_id' => $po_id,
                'description' => $desc,
                'quantity' => $qty,
                'unit' => $unit,
                'unit_price' => $price,
                'total' => $lineTotal,
                'discount_input' => $parts['discount_input'],
                'discount_type' => $parts['discount_type'],
                'discount_value' => $parts['discount_value'],
                'discount_amount' => $parts['discount_amount'],
                'vat_exempt' => tnc_purchase_item_vat_exempt_from_post((int) $key),
            ]);
        }
    if (method_exists(Purchase::class, 'seedPoPayments')) {
        Purchase::seedPoPayments($po_id, $seedAmount, null);
    }
    $standalonePoExtrasSaved = false;
    if ($isStandalonePurchasePo) {
        $standaloneExtras = tnc_po_optional_create_extras(
            $po_id,
            $po_number,
            $supplier_id,
            $created_by,
            $issue_date,
            $standaloneBilledTotal,
            $standaloneBilledVat
        );
        if ($standaloneExtras['po_fields'] !== []) {
            tnc_po_merge_optional_fields($po_id, $standaloneExtras['po_fields']);
            $standalonePoExtrasSaved = $standaloneExtras['extras_saved'];
        }
    }
    tnc_audit_purchase_order_created($po_id, 'create_po_direct');
    if ($isStandalonePurchasePo) {
        if ($returnTo === 'site_hub' && $returnSiteId > 0) {
            $doneUrl = app_path('pages/sites/site-hub.php') . '?site_id=' . $returnSiteId . '&po_created=1';
        } else {
            $doneUrl = app_path('pages/purchase/purchase-order-list.php')
                . '?success=1&po_number=' . rawurlencode($po_number)
                . ($standalonePoExtrasSaved ? '&payment_saved=1' : '');
        }
        if (tnc_ajax_form_requested()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok' => true,
                'message' => 'สร้าง PO สำเร็จ หมายเลข ' . $po_number
                    . ($standalonePoExtrasSaved ? ' (บันทึกบิลและ/หรือสลิปแล้ว)' : ''),
                'po_number' => $po_number,
                'action' => 'po_created',
                'redirect' => $doneUrl,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        tnc_action_redirect($doneUrl);
    }
    renderPoCreatedPopupAndRedirect((string) $po_number);
}

// --- แก้ไข PO โดยตรง (purchase-order-edit.php) ---
if ($action === 'update_po_direct' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_can('po.update', 'ไม่มีสิทธิ์แก้ไข PO');
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
    if (Purchase::poPaidLocksMutation($existing)) {
        tnc_action_redirect($listUrl . '?error=po_paid');
    }

    $lineSums = tnc_pr_post_purchase_line_vat_sums(tnc_purchase_round_to_baht_from_post());
    if ($lineSums['line_count'] <= 0 || ($lineSums['taxable'] + $lineSums['exempt']) <= 0) {
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
    $totals = tnc_po_compute_totals($lineSums['taxable'], $vat_enabled, $vat_mode_post, 'none', $lineSums['exempt'], tnc_purchase_round_to_baht_from_post());

    $issue_date = trim((string) ($_POST['issue_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) {
        $issue_date = (string) ($existing['issue_date'] ?? date('Y-m-d'));
    }
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);

    $editSiteId = (int) ($existing['site_id'] ?? 0);
    $editSiteName = trim((string) ($existing['site_name'] ?? ''));
    $editCostCategoryId = (int) ($existing['cost_category_id'] ?? 0);
    $editCostCategoryName = trim((string) ($existing['cost_category_name'] ?? ''));
    $sitesForPoEdit = Db::tableRows('sites');
    if (count($sitesForPoEdit) > 0) {
        $editSiteId = (int) ($_POST['site_id'] ?? 0);
        if ($editSiteId <= 0) {
            tnc_action_redirect($editUrl . '?id=' . $po_id . '&error=need_site');
        }
        $siteRowEdit = Db::row('sites', (string) $editSiteId);
        if ($siteRowEdit === null) {
            tnc_action_redirect($editUrl . '?id=' . $po_id . '&error=need_site');
        }
        $editSiteName = trim((string) ($siteRowEdit['name'] ?? ''));
        $editCostCategoryId = (int) ($_POST['cost_category_id'] ?? 0);
        if ($editCostCategoryId <= 0 || !tnc_site_category_is_valid_selection_for_site($editCostCategoryId, $editSiteId)) {
            tnc_action_redirect($editUrl . '?id=' . $po_id . '&error=need_cost_category');
        }
        $editCostCategoryName = tnc_site_category_display_name($editCostCategoryId);
    } elseif ((int) ($_POST['site_id'] ?? 0) > 0) {
        $editSiteId = (int) $_POST['site_id'];
        $siteRowEdit = Db::row('sites', (string) $editSiteId);
        if ($siteRowEdit !== null) {
            $editSiteName = trim((string) ($siteRowEdit['name'] ?? ''));
        }
        $editCostCategoryId = (int) ($_POST['cost_category_id'] ?? 0);
        if ($editCostCategoryId > 0 && tnc_site_category_is_valid_selection_for_site($editCostCategoryId, $editSiteId)) {
            $editCostCategoryName = tnc_site_category_display_name($editCostCategoryId);
        } else {
            $editCostCategoryId = 0;
            $editCostCategoryName = '';
        }
    }

    tnc_site_budget_abort_if_invalid(
        $editSiteId,
        $editCostCategoryId,
        (float) $totals['net'],
        $po_id,
        $editUrl . '?id=' . $po_id
    );

    $quoteAttachmentPath = trim((string) ($existing['quotation_attachment_path'] ?? ''));
    $quoteAttachmentUrl = trim((string) ($existing['quotation_attachment_url'] ?? ''));
    $quoteAttachmentName = trim((string) ($existing['quotation_attachment_name'] ?? ''));
    $quoteAttachmentMime = trim((string) ($existing['quotation_attachment_mime'] ?? ''));
    $quoteAttachmentSize = (int) ($existing['quotation_attachment_size'] ?? 0);
    $quotUpload = tnc_purchase_quotation_upload('po-quotations', $po_id, $_FILES['quotation_file'] ?? []);
    if (is_array($quotUpload) && empty($quotUpload['ok'])) {
        $errCode = (string) ($quotUpload['error'] ?? 'upload_failed');
        if ($errCode === 'upload_type') {
            $errCode = 'quotation_upload_type';
        } elseif ($errCode === 'upload_failed') {
            $errCode = 'quotation_upload_failed';
        }
        tnc_action_redirect($editUrl . '?id=' . $po_id . '&error=' . $errCode);
    }
    if (is_array($quotUpload) && !empty($quotUpload['ok'])) {
        $quoteAttachmentPath = (string) $quotUpload['path'];
        $quoteAttachmentUrl = (string) $quotUpload['url'];
        $quoteAttachmentName = (string) $quotUpload['name'];
        $quoteAttachmentMime = (string) $quotUpload['mime'];
        $quoteAttachmentSize = (int) $quotUpload['size'];
    }

    $beforeSnap = $existing;
    Db::setRow('purchase_orders', $pk, array_merge($existing, [
        'issue_date' => $issue_date,
        'supplier_id' => $supplier_id,
        'site_id' => $editSiteId,
        'site_name' => $editSiteName,
        'cost_category_id' => $editCostCategoryId,
        'cost_category_name' => $editCostCategoryName,
        'quotation_number' => mb_substr(trim((string) ($existing['quotation_number'] ?? '')), 0, 120),
        'quotation_note' => mb_substr(trim((string) ($_POST['quotation_note'] ?? ($existing['quotation_note'] ?? ''))), 0, 500),
        'po_note' => mb_substr(trim((string) ($_POST['po_note'] ?? ($existing['po_note'] ?? ''))), 0, 500),
        'quotation_attachment_path' => $quoteAttachmentPath,
        'quotation_attachment_url' => $quoteAttachmentUrl,
        'quotation_attachment_name' => $quoteAttachmentName,
        'quotation_attachment_mime' => $quoteAttachmentMime,
        'quotation_attachment_size' => $quoteAttachmentSize,
        'total_amount' => $totals['net'],
        'gross_amount' => $totals['gross'],
        'subtotal_amount' => $totals['subtotal'],
        'vat_amount' => $totals['vat'],
        'vat_enabled' => $vat_enabled,
        'round_to_baht' => tnc_purchase_round_to_baht_from_post() ? 1 : 0,
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
        $discRaw = trim((string) ($_POST['item_discount'][$key] ?? ''));
        $parts = tnc_pr_parse_line_discount($qty, $price, $discRaw, tnc_purchase_round_to_baht_from_post());
        $lineTotal = $parts['line_total'];
        Db::setRow('purchase_order_items', (string) $iid, [
            'id' => $iid,
            'po_id' => $po_id,
            'description' => $desc,
            'quantity' => $qty,
            'unit' => $unit,
            'unit_price' => $price,
            'total' => $lineTotal,
            'discount_input' => $parts['discount_input'],
            'discount_type' => $parts['discount_type'],
            'discount_value' => $parts['discount_value'],
            'discount_amount' => $parts['discount_amount'],
            'vat_exempt' => tnc_purchase_item_vat_exempt_from_post((int) $key),
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
    $supplierInvoiceNoEdit = mb_substr(trim((string) ($_POST['supplier_invoice_no'] ?? '')), 0, 120);
    if ($supplierInvoiceNoEdit !== '' && $afterSnap !== null) {
        $supplierInvoiceDateEdit = trim((string) ($_POST['supplier_invoice_date'] ?? ''));
        $billedTotalEdit = (float) str_replace([',', ' '], '', trim((string) ($_POST['billed_total_amount'] ?? '')));
        $billedVatEdit = (float) str_replace([',', ' '], '', trim((string) ($_POST['billed_vat_amount'] ?? '')));
        tnc_po_sync_billing_on_edit(
            $po_id,
            $afterSnap,
            $supplierInvoiceNoEdit,
            $supplierInvoiceDateEdit,
            $billedTotalEdit,
            $billedVatEdit,
            (int) ($_SESSION['user_id'] ?? 0)
        );
        $afterSnap = Db::row('purchase_orders', $pk);
    }

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
    tnc_require_can('po.cancel', 'ไม่มีสิทธิ์ยกเลิก PO');
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
    if (Purchase::poPaidLocksMutation($existing)) {
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

require_once __DIR__ . '/../includes/invoice_cancel_helpers.php';

if ($action === 'cancel_invoice' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_can('invoice.cancel', 'ไม่มีสิทธิ์ยกเลิกใบแจ้งหนี้');
    $listUrl = app_path('index.php');
    $viewUrl = app_path('pages/invoices/invoice-view.php');
    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    $reason = trim((string) ($_POST['cancellation_reason'] ?? ''));
    if ($invoiceId <= 0) {
        tnc_action_redirect($listUrl . '?error=invalid');
    }
    if ($reason === '') {
        $returnToReason = trim((string) ($_POST['return_to'] ?? ''));
        if ($returnToReason === 'view') {
            tnc_action_redirect($viewUrl . '?id=' . $invoiceId . '&error=need_cancel_reason');
        }
        tnc_action_redirect($listUrl . '?error=need_cancel_reason');
    }
    $pk = Db::pkForLogicalId('invoices', $invoiceId);
    $existing = Db::row('invoices', $pk);
    if ($existing === null) {
        tnc_action_redirect($listUrl . '?error=not_found');
    }
    if (tnc_invoice_is_cancelled($existing)) {
        tnc_action_redirect($listUrl . '?error=already_cancelled');
    }
    $beforeSnap = $existing;
    $cancelFields = tnc_invoice_cancel_fields($reason);
    Db::mergeRow('invoices', $pk, $cancelFields);
    tnc_invoice_cancel_linked_tax_invoices($invoiceId, $reason);
    $afterSnap = Db::row('invoices', $pk);
    $invNo = $afterSnap !== null ? trim((string) ($afterSnap['invoice_number'] ?? '')) : '';
    tnc_audit_log('update', 'invoice', (string) $invoiceId, $invNo !== '' ? $invNo : ('#' . $invoiceId), [
        'source' => 'action-handler',
        'action' => 'cancel_invoice',
        'before' => $beforeSnap,
        'after' => $afterSnap,
        'meta' => ['cancellation_reason' => $reason],
    ]);
    $returnTo = trim((string) ($_POST['return_to'] ?? ''));
    if ($returnTo === 'view') {
        tnc_action_redirect($viewUrl . '?id=' . $invoiceId . '&cancelled=1');
    }
    tnc_action_redirect($listUrl . '?cancelled=1');
}

if ($action === 'cancel_tax_invoice' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_can('invoice.tax_cancel', 'ไม่มีสิทธิ์ยกเลิกใบกำกับภาษี');
    $taxListUrl = app_path('pages/invoices/tax-invoice-list.php');
    $taxViewUrl = app_path('pages/invoices/tax-invoice-receipt.php');
    $taxId = (int) ($_POST['tax_id'] ?? 0);
    $reason = trim((string) ($_POST['cancellation_reason'] ?? ''));
    if ($taxId <= 0) {
        tnc_action_redirect($taxListUrl . '?error=invalid');
    }
    if ($reason === '') {
        $returnToReason = trim((string) ($_POST['return_to'] ?? ''));
        if ($returnToReason === 'view') {
            $invIdGuess = (int) ($_POST['invoice_id'] ?? 0);
            if ($invIdGuess > 0) {
                tnc_action_redirect($taxViewUrl . '?id=' . $invIdGuess . '&error=need_cancel_reason');
            }
        }
        tnc_action_redirect($taxListUrl . '?error=need_cancel_reason');
    }
    $tpk = Db::pkForLogicalId('tax_invoices', $taxId);
    $existing = Db::row('tax_invoices', $tpk);
    if ($existing === null) {
        tnc_action_redirect($taxListUrl . '?error=not_found');
    }
    if (tnc_tax_invoice_is_cancelled($existing)) {
        tnc_action_redirect($taxListUrl . '?error=already_cancelled');
    }
    $invoiceId = (int) ($existing['invoice_id'] ?? 0);
    $beforeSnap = $existing;
    Db::mergeRow('tax_invoices', $tpk, tnc_invoice_cancel_fields($reason));
    $afterSnap = Db::row('tax_invoices', $tpk);
    $taxNo = $afterSnap !== null ? trim((string) ($afterSnap['tax_invoice_number'] ?? '')) : '';
    tnc_audit_log('update', 'tax_invoice', (string) $taxId, $taxNo !== '' ? $taxNo : ('#' . $taxId), [
        'source' => 'action-handler',
        'action' => 'cancel_tax_invoice',
        'before' => $beforeSnap,
        'after' => $afterSnap,
        'meta' => ['cancellation_reason' => $reason, 'invoice_id' => $invoiceId],
    ]);
    $returnTo = trim((string) ($_POST['return_to'] ?? ''));
    if ($returnTo === 'view' && $invoiceId > 0) {
        tnc_action_redirect($taxViewUrl . '?id=' . $invoiceId . '&cancelled=1');
    }
    tnc_action_redirect($taxListUrl . '?cancelled=1');
}

/** ปัดทิ้ง/คืนค่า PO ที่ไม่สมบูรณ์ — ไม่ให้นับในกล่องแจ้งเตือน (สำหรับใบเก่าที่กรอกย้อนหลังไม่ได้แล้ว) */
if (($action === 'ignore_incomplete_po' || $action === 'unignore_incomplete_po') && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_can('po.update', 'ไม่มีสิทธิ์จัดการ PO');
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
    tnc_require_can('po.update', 'ไม่มีสิทธิ์จัดการ PO');
    $listUrl = app_path('pages/purchase/purchase-order-list.php');
    $poReturnRedirect = static function (string $qs) use ($listUrl): void {
        $qs = ltrim($qs, '?&');
        $returnTo = trim((string) ($_POST['return_to'] ?? ''));
        $returnSiteId = (int) ($_POST['return_site_id'] ?? 0);
        $returnCatId = (int) ($_POST['return_cat_id'] ?? 0);
        if ($returnTo === 'site_hub' && $returnSiteId > 0) {
            $url = app_path('pages/sites/site-hub.php') . '?site_id=' . $returnSiteId;
            if ($returnCatId > 0) {
                $url .= '&open_docs_cat=' . $returnCatId;
            }
            tnc_action_redirect($url . ($qs !== '' ? '&' . $qs : ''));
        }
        tnc_action_redirect($listUrl . ($qs !== '' ? '?' . $qs : ''));
    };
    $po_id = (int) ($_POST['po_id'] ?? 0);
    $payment_status = strtolower(trim((string) ($_POST['payment_status'] ?? '')));
    if ($po_id <= 0 || $payment_status !== 'paid') {
        $poReturnRedirect('error=invalid');
    }
    $po = Db::row('purchase_orders', (string) $po_id);
    if ($po === null) {
        $poReturnRedirect('error=invalid');
    }
    if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
        $poReturnRedirect('error=po_cancelled');
    }
    $payment_method = strtolower(trim((string) ($_POST['payment_method'] ?? 'transfer')));
    if (!in_array($payment_method, ['cash', 'transfer'], true)) {
        $payment_method = 'transfer';
    }
    $payment_cash_paid_by = trim((string) ($_POST['payment_cash_paid_by'] ?? ''));
    if ($payment_method === 'cash') {
        $payment_cash_paid_by = mb_substr($payment_cash_paid_by, 0, 255);
        if ($payment_cash_paid_by === '') {
            $poReturnRedirect('error=cash_paid_by_required');
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
        $poReturnRedirect('error=payment_slip_required');
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
            $poReturnRedirect('payment_saved=1&auto_bill=1&bill_month=' . rawurlencode($billMonth) . '&bill_id=' . (int) $autoBillId . '&print_po_id=' . $po_id);
        }
        $poReturnRedirect('payment_saved=1&print_po_id=' . $po_id);
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
        $poReturnRedirect('payment_saved=1&auto_bill=1&bill_month=' . rawurlencode($billMonth) . '&bill_id=' . (int) $autoBillId . '&print_po_id=' . $po_id);
    }
    $poReturnRedirect('payment_saved=1&print_po_id=' . $po_id);
}

/** บันทึกเลขที่บิลซื้อย้อนหลังจาก PO + สร้างรายการใน /bills */
if ($action === 'receive_po_bill' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_can('po.update', 'ไม่มีสิทธิ์จัดการ PO');
    $listUrl = app_path('pages/purchase/purchase-order-list.php');
    $po_id = (int) ($_POST['po_id'] ?? 0);
    $return_to = trim((string) ($_POST['return_to'] ?? 'list'));
    $returnSiteId = (int) ($_POST['return_site_id'] ?? 0);
    $returnCatId = (int) ($_POST['return_cat_id'] ?? 0);
    $billReturnRedirect = static function (string $qs, ?string $viewFallback = null) use ($listUrl, $return_to, $returnSiteId, $returnCatId, $po_id): void {
        $qs = ltrim($qs, '?&');
        if ($return_to === 'site_hub' && $returnSiteId > 0) {
            $url = app_path('pages/sites/site-hub.php') . '?site_id=' . $returnSiteId;
            if ($returnCatId > 0) {
                $url .= '&open_docs_cat=' . $returnCatId;
            }
            tnc_action_redirect($url . ($qs !== '' ? '&' . $qs : ''));
        }
        if ($return_to === 'view' && $viewFallback !== null) {
            tnc_action_redirect($viewFallback);
        }
        tnc_action_redirect($listUrl . ($qs !== '' ? '?' . $qs : ''));
    };

    if ($po_id <= 0) {
        $billReturnRedirect('error=invalid');
    }

    $poPk = Db::pkForLogicalId('purchase_orders', $po_id);
    $po = Db::row('purchase_orders', $poPk);
    if ($po === null) {
        $billReturnRedirect('error=invalid');
    }
    if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
        $billReturnRedirect('error=po_cancelled');
    }

    $supplierInvoiceNo = mb_substr(trim((string) ($_POST['supplier_invoice_no'] ?? '')), 0, 120);
    $supplierInvoiceDate = trim((string) ($_POST['supplier_invoice_date'] ?? ''));
    if ($supplierInvoiceNo === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $supplierInvoiceDate) !== 1) {
        $billReturnRedirect(
            'error=billing_required',
            app_path('pages/purchase/purchase-order-view.php') . '?id=' . $po_id . '&error=billing_required'
        );
    }

    $postedTotal = trim((string) ($_POST['billed_total_amount'] ?? ''));
    $postedVat = trim((string) ($_POST['billed_vat_amount'] ?? ''));
    if ($postedTotal === '' || $postedVat === '') {
        $billReturnRedirect(
            'error=billing_amount_invalid',
            app_path('pages/purchase/purchase-order-view.php') . '?id=' . $po_id . '&error=billing_amount_invalid'
        );
    }
    $billedTotalAmount = (float) str_replace([',', ' '], '', $postedTotal);
    $billedVatAmount = (float) str_replace([',', ' '], '', $postedVat);
    if (!is_finite($billedTotalAmount) || !is_finite($billedVatAmount) || $billedTotalAmount < 0 || $billedVatAmount < 0) {
        $billReturnRedirect(
            'error=billing_amount_invalid',
            app_path('pages/purchase/purchase-order-view.php') . '?id=' . $po_id . '&error=billing_amount_invalid'
        );
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
        $supplierName = trim((string) ($po['supplier_name'] ?? ''));
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
    $listQs = 'billing_saved=1';
    if ($autoBillId !== null && $autoBillId > 0) {
        $billMonth = date('Y-m');
        if (preg_match('/^(\d{4}-\d{2})/', $supplierInvoiceDate, $mm) === 1) {
            $billMonth = $mm[1];
        }
        $listQs .= '&auto_bill=1&bill_month=' . rawurlencode($billMonth) . '&bill_id=' . (int) $autoBillId . '&print_po_id=' . $po_id;
    }
    $billReturnRedirect($listQs);
}

/** เพิ่มไฟล์หลักฐานการจ่าย (หลายไฟล์) สำหรับ PO ที่จ่ายแล้ว */
if ($action === 'add_po_payment_slips' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_can('po.update', 'ไม่มีสิทธิ์จัดการ PO');
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
        tnc_po_slip_action_redirect($po_id, 'payment_slips_updated=1&auto_bill=1&bill_month=' . rawurlencode($billMonth) . '&bill_id=' . (int) $autoBillId . '&print_po_id=' . $po_id);
    }
    tnc_po_slip_action_redirect($po_id, 'payment_slips_updated=1');
}

/** ลบไฟล์หลักฐานการจ่ายรายการเดียว */
if ($action === 'remove_po_payment_slip' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_can('po.update', 'ไม่มีสิทธิ์จัดการ PO');
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
    tnc_po_slip_action_redirect($po_id, 'payment_slips_updated=1' . ($revertedToUnpaid ? '&payment_reverted=1' : ''));
}

/** เปลี่ยนไฟล์หลักฐานการจ่าย (แทนที่ไฟล์เดิม) */
if ($action === 'replace_po_payment_slip' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_can('po.update', 'ไม่มีสิทธิ์จัดการ PO');
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
        tnc_po_slip_action_redirect($po_id, 'payment_slips_updated=1&auto_bill=1&bill_month=' . rawurlencode($billMonth) . '&bill_id=' . (int) $autoBillId . '&print_po_id=' . $po_id);
    }
    tnc_po_slip_action_redirect($po_id, 'payment_slips_updated=1');
}

if ($action === 'upload_po_payment_slip' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_require_can('po.update', 'ไม่มีสิทธิ์จัดการ PO');
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

        $partyType = trim((string) ($_POST['company_type'] ?? $_POST['customer_type'] ?? ''));
        if (!in_array($partyType, ['company', 'individual'], true)) {
            $partyType = 'company';
        }

        $row = [
            'name' => $name,
            'tax_id' => $tax,
            'address' => $addr,
        ];
        if ($table === 'customers') {
            $row['phone'] = $phone;
            $row['email'] = $email;
        }

        if ($table === 'company') {
            require_once dirname(__DIR__) . '/includes/banks.php';
            $row = array_merge($row, tnc_normalize_company_bank_fields([
                'bank_name' => $_POST['bank_name'] ?? '',
                'bank_account_name' => $_POST['bank_account_name'] ?? '',
                'bank_account_number' => $_POST['bank_account_number'] ?? '',
            ]));
        }

        require_once dirname(__DIR__) . '/includes/party_logo.php';
        $removeLogo = !empty($_POST['remove_logo']);
        $logoUpload = tnc_party_logo_upload_from_post($_FILES['logo'] ?? []);
        if (!$logoUpload['ok']) {
            $logoErr = ($logoUpload['error'] ?? '') === 'upload_type' ? 'logo_upload_type' : 'logo_upload_failed';
            tnc_action_redirect(app_path($page) . '?error=' . $logoErr);
        }

        if (strpos($action, 'add') !== false) {
            $nid = Db::nextNumericId($table, 'id');
            $row['id'] = $nid;
            if ($table === 'customers') {
                $row['customer_type'] = $partyType;
            } elseif ($table === 'company') {
                $row['company_type'] = $partyType;
            }
            if ($logoUpload['filename'] !== '') {
                $row['logo'] = $logoUpload['filename'];
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
            $cur = Db::rowByIdField($table, $edit_id) ?? [];
            if ($edit_id <= 0 || $cur === []) {
                tnc_action_redirect(app_path($page) . '?error=not_found');
            }
            $pk = Db::pkForLogicalId($table, $edit_id);
            if ($table === 'company') {
                $row['company_type'] = $partyType;
            } elseif ($table === 'customers' && array_key_exists('customer_type', $_POST)) {
                $row['customer_type'] = $partyType;
            }
            if ($removeLogo) {
                tnc_party_logo_delete_stored($cur['logo'] ?? '');
                $row['logo'] = '';
            } elseif ($logoUpload['filename'] !== '') {
                tnc_party_logo_delete_stored($cur['logo'] ?? '');
                $row['logo'] = $logoUpload['filename'];
            } else {
                $row['logo'] = (string) ($cur['logo'] ?? '');
            }
            $merged = array_merge($cur, $row);
            if ($table === 'company') {
                unset($merged['phone'], $merged['email']);
            }
            Db::setRow($table, $pk, $merged);
            $entityLabel = $table === 'company' ? 'company' : 'customer';
            $orgAfterEd = Db::row($table, $pk);
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
        if ($poSnap !== null && Purchase::poPaidLocksMutation($poSnap)) {
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
        $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
        $hubBase = app_path('pages/sites/site-hub.php');
        if ($redirectTo !== '' && str_starts_with($redirectTo, $hubBase)) {
            $sep = str_contains($redirectTo, '?') ? '&' : '?';
            tnc_action_redirect($redirectTo . $sep . 'doc_deleted=1');
        }
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
