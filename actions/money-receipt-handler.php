<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/tnc_action_response.php';
require_once __DIR__ . '/../includes/tnc_audit_log.php';
require_once __DIR__ . '/../includes/money_receipt_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_is_finance_role()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์เข้าใช้งาน'], JSON_UNESCAPED_UNICODE);
    exit;
}

$listUrl = app_path('pages/tools/money-receipt-list.php');
$issueUrl = app_path('pages/tools/money-receipt-issue.php');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string) ($_REQUEST['action'] ?? '');

/**
 * @return never
 */
function money_receipt_fail_redirect(string $code, bool $toIssue = false): void
{
    global $listUrl, $issueUrl;
    $base = $toIssue ? $issueUrl : $listUrl;
    tnc_action_redirect($base . '?error=' . rawurlencode($code));
}

/**
 * @param array<string, mixed> $payload
 */
function money_receipt_json_out(array $payload, int $http = 200): void
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** GET — ดึงข้อมูลหนึ่งรายการสำหรับ popup แก้ไข */
if ($method === 'GET' && ($action === 'fetch' || isset($_GET['fetch']))) {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        money_receipt_json_out(['ok' => false, 'message' => 'ไม่พบรายการ'], 422);
    }
    $pk = Db::pkForLogicalId('money_receipts', $id);
    $row = Db::row('money_receipts', $pk);
    if ($row === null || (int) ($row['id'] ?? 0) !== $id) {
        money_receipt_json_out(['ok' => false, 'message' => 'ไม่พบรายการ'], 404);
    }
    $items = money_receipt_items_from_json_field((string) ($row['items_json'] ?? ''));
    money_receipt_json_out([
        'ok' => true,
        'receipt' => [
            'id' => $id,
            'company_id' => (int) ($row['company_id'] ?? 0),
            'doc_date' => (string) ($row['doc_date'] ?? ''),
            'issuer_name' => (string) ($row['issuer_name'] ?? ''),
            'receipt_no' => (string) ($row['receipt_no'] ?? ''),
            'pay_cash' => (int) ($row['pay_cash'] ?? 0),
            'pay_transfer' => (int) ($row['pay_transfer'] ?? 0),
            'pay_check' => (int) ($row['pay_check'] ?? 0),
            'transfer_slip' => (string) ($row['transfer_slip'] ?? ''),
            'items' => $items,
        ],
    ]);
}

if ($method !== 'POST') {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(405);
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>ไม่รองรับ</title></head><body style="font-family:sarabun,sans-serif;padding:24px;"><p>ใช้งานผ่านฟอร์มในระบบเท่านั้น</p></body></html>';
    exit;
}

if (!csrf_verify_request()) {
    money_receipt_fail_redirect('csrf', $action === 'create');
}

/**
 * บันทึกไฟล์ที่อัปโหลดไปโฟลเดอร์ของเลขที่ใบเสร็จ
 *
 * @return array{ok:bool, path:string, error:?string}
 */
function money_receipt_store_uploaded_slip(int $receiptId): array
{
    if (
        !isset($_FILES['transfer_slip'])
        || !is_array($_FILES['transfer_slip'])
        || (int) ($_FILES['transfer_slip']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        return ['ok' => false, 'path' => '', 'error' => 'payment_slip_required'];
    }
    $f = $_FILES['transfer_slip'];
    if (!is_array($f) || (int) ($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => '', 'error' => 'upload_failed'];
    }
    $tmp = (string) ($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'path' => '', 'error' => 'upload_failed'];
    }
    $originalName = trim((string) ($f['name'] ?? 'slip'));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'path' => '', 'error' => 'upload_type'];
    }
    $dirAbs = ROOT_PATH . '/uploads/money-receipt-slips/' . $receiptId;
    if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
        return ['ok' => false, 'path' => '', 'error' => 'upload_failed'];
    }
    $storedName = 'slip_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destAbs = $dirAbs . '/' . $storedName;
    if (!@move_uploaded_file($tmp, $destAbs)) {
        return ['ok' => false, 'path' => '', 'error' => 'upload_failed'];
    }

    return ['ok' => true, 'path' => 'uploads/money-receipt-slips/' . $receiptId . '/' . $storedName, 'error' => null];
}

function money_receipt_remove_slip_file(?string $rel): void
{
    $rel = trim(str_replace('\\', '/', (string) $rel), '/');
    if ($rel === '' || strpos($rel, '..') !== false) {
        return;
    }
    $abs = ROOT_PATH . '/' . $rel;
    if (is_file($abs)) {
        @unlink($abs);
    }
}

/**
 * @param list<array{detail:string, deduct:float, receive:float}> $items
 * @return array<string, mixed>|null
 */
function money_receipt_validate_payload(array $post, array $items, int $companyId): ?array
{
    $docDate = money_receipt_validate_doc_date((string) ($post['doc_date'] ?? ''));
    if ($docDate === null) {
        return null;
    }
    if (count($items) === 0) {
        return null;
    }
    $flags = money_receipt_pay_flags($post);
    if ($flags['pay_cash'] + $flags['pay_transfer'] + $flags['pay_check'] === 0) {
        return null;
    }
    if ($companyId <= 0 || Db::rowByIdField('company', $companyId) === null) {
        return null;
    }

    return [
        'company_id' => $companyId,
        'doc_date' => $docDate,
        'pay_cash' => $flags['pay_cash'],
        'pay_transfer' => $flags['pay_transfer'],
        'pay_check' => $flags['pay_check'],
        'items_json' => json_encode($items, JSON_UNESCAPED_UNICODE),
    ];
}

if ($action === 'create') {
    $items = money_receipt_parse_items_from_post($_POST);
    $companyId = (int) ($_POST['company_id'] ?? 0);
    $partial = money_receipt_validate_payload($_POST, $items, $companyId);
    if ($partial === null) {
        money_receipt_fail_redirect('invalid', true);
    }
    $flags = money_receipt_pay_flags($_POST);

    $newId = Db::nextNumericId('money_receipts');
    $pk = (string) $newId;
    $issuerName = trim((string) ($_SESSION['name'] ?? ''));
    if ($issuerName === '') {
        $issuerName = 'ผู้ใช้งานระบบ';
    }
    $receiptNo = money_receipt_next_receipt_no((string) ($partial['doc_date'] ?? ''));

    $slipPath = '';
    if ($flags['pay_transfer'] === 1) {
        $up = money_receipt_store_uploaded_slip($newId);
        if (!$up['ok']) {
            money_receipt_fail_redirect((string) ($up['error'] ?? 'upload_failed'), true);
        }
        $slipPath = $up['path'];
    }

    $now = date('Y-m-d H:i:s');
    $row = array_merge($partial, [
        'id' => $newId,
        'receipt_no' => $receiptNo,
        'issuer_name' => $issuerName,
        'transfer_slip' => $slipPath,
        'created_at' => $now,
        'updated_at' => $now,
        'created_by' => (int) $_SESSION['user_id'],
    ]);
    Db::setRow('money_receipts', $pk, $row);
    tnc_audit_log('create', 'money_receipt', $pk, 'ใบเสร็จรับเงิน #' . $newId, [
        'source' => 'money-receipt-handler',
        'action' => 'create',
        'after' => tnc_audit_sanitize_row($row),
    ]);
    tnc_action_redirect(app_path('pages/tools/money-receipt-print.php') . '?id=' . $newId . '&saved=1');
}

if ($action === 'update') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        money_receipt_fail_redirect('invalid');
    }
    $pk = Db::pkForLogicalId('money_receipts', $id);
    $before = Db::row('money_receipts', $pk);
    if ($before === null || (int) ($before['id'] ?? 0) !== $id) {
        money_receipt_fail_redirect('invalid');
    }

    $items = money_receipt_parse_items_from_post($_POST);
    $companyId = (int) ($_POST['company_id'] ?? 0);
    $partial = money_receipt_validate_payload($_POST, $items, $companyId);
    if ($partial === null) {
        money_receipt_fail_redirect('invalid');
    }

    $flags = money_receipt_pay_flags($_POST);
    $existingSlip = trim((string) ($before['transfer_slip'] ?? ''));

    $hasNewSlipUpload = isset($_FILES['transfer_slip'])
        && is_array($_FILES['transfer_slip'])
        && (int) ($_FILES['transfer_slip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    $slipPath = $existingSlip;
    if ($flags['pay_transfer'] === 1) {
        if ($hasNewSlipUpload) {
            $up = money_receipt_store_uploaded_slip($id);
            if (!$up['ok']) {
                money_receipt_fail_redirect((string) ($up['error'] ?? 'upload_failed'));
            }
            if ($existingSlip !== '' && $existingSlip !== $up['path']) {
                money_receipt_remove_slip_file($existingSlip);
            }
            $slipPath = $up['path'];
        } elseif ($existingSlip === '') {
            money_receipt_fail_redirect('payment_slip_required');
        }
    } else {
        if ($existingSlip !== '') {
            money_receipt_remove_slip_file($existingSlip);
        }
        $slipPath = '';
    }

    $now = date('Y-m-d H:i:s');
    $receiptNo = trim((string) ($before['receipt_no'] ?? ''));
    if ($receiptNo === '') {
        $receiptNo = money_receipt_next_receipt_no((string) ($partial['doc_date'] ?? ''));
    }
    $issuerName = trim((string) ($before['issuer_name'] ?? ''));
    if ($issuerName === '') {
        $issuerName = trim((string) ($_SESSION['name'] ?? ''));
        if ($issuerName === '') {
            $issuerName = 'ผู้ใช้งานระบบ';
        }
    }
    $merged = array_merge($before, $partial, [
        'receipt_no' => $receiptNo,
        'issuer_name' => $issuerName,
        'transfer_slip' => $slipPath,
        'updated_at' => $now,
    ]);
    Db::setRow('money_receipts', $pk, $merged);
    tnc_audit_log('update', 'money_receipt', $pk, 'ใบเสร็จรับเงิน #' . $id, [
        'source' => 'money-receipt-handler',
        'action' => 'update',
        'before' => tnc_audit_sanitize_row($before),
        'after' => tnc_audit_sanitize_row($merged),
    ]);
    tnc_action_redirect($listUrl . '?updated=1');
}

if ($action === 'delete') {
    tnc_require_post_confirm_password();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        money_receipt_fail_redirect('invalid');
    }
    $pk = Db::pkForLogicalId('money_receipts', $id);
    $before = Db::row('money_receipts', $pk);
    if ($before === null) {
        money_receipt_fail_redirect('invalid');
    }
    $slip = trim((string) ($before['transfer_slip'] ?? ''));
    if ($slip !== '') {
        money_receipt_remove_slip_file($slip);
    }
    $dir = ROOT_PATH . '/uploads/money-receipt-slips/' . $id;
    if (is_dir($dir)) {
        $files = glob($dir . '/*') ?: [];
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }
    Db::deleteRow('money_receipts', $pk);
    tnc_audit_log('delete', 'money_receipt', $pk, 'ใบเสร็จรับเงิน #' . $id, [
        'source' => 'money-receipt-handler',
        'action' => 'delete',
        'before' => tnc_audit_sanitize_row($before),
    ]);
    tnc_action_redirect($listUrl . '?deleted=1');
}

money_receipt_fail_redirect('invalid');
