<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/line_pr_notifier.php';

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

$action = $_GET['action'] ?? '';
$type = $_GET['type'] ?? '';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($action === 'line_pr_decision') {
    $decision = (string) ($_GET['decision'] ?? '');
    $token = trim((string) ($_GET['token'] ?? ''));
    $pr = Db::row('purchase_requests', (string) $id);

    $ok = $pr !== null
        && $token !== ''
        && hash_equals((string) ($pr['line_approval_token'] ?? ''), $token)
        && (string) ($pr['status'] ?? '') === 'pending'
        && in_array($decision, ['approve', 'reject'], true);

    if ($ok) {
        $nextStatus = $decision === 'approve' ? 'approved' : 'rejected';
        Db::mergeRow('purchase_requests', (string) $id, [
            'status' => $nextStatus,
            'line_decision' => $decision,
            'line_decided_at' => date('Y-m-d H:i:s'),
            'line_approval_token' => '',
        ]);
        if ($nextStatus === 'approved' && method_exists(Purchase::class, 'createHireContractIfNeededForPr')) {
            Purchase::createHireContractIfNeededForPr($id);
        }
        http_response_code(200);
        echo '<!doctype html><html lang="th"><head><meta charset="UTF-8"><title>บันทึกผลแล้ว</title></head><body style="font-family:sans-serif;padding:24px;"><h2>บันทึกผลเรียบร้อย</h2><p>ระบบได้อัปเดตใบ PR แล้ว: <strong>' . htmlspecialchars(strtoupper($nextStatus), ENT_QUOTES, 'UTF-8') . '</strong></p></body></html>';
        exit;
    }

    http_response_code(400);
    echo '<!doctype html><html lang="th"><head><meta charset="UTF-8"><title>ไม่สามารถดำเนินการได้</title></head><body style="font-family:sans-serif;padding:24px;"><h2>ไม่สามารถดำเนินการได้</h2><p>ลิงก์หมดอายุ หรือรายการนี้ถูกดำเนินการไปแล้ว</p></body></html>';
    exit;
}

if ($action === 'line_quote_decision') {
    $decision = (string) ($_GET['decision'] ?? '');
    $token = trim((string) ($_GET['token'] ?? ''));
    $quote = Db::rowByIdField('quotations', $id);

    $ok = $quote !== null
        && $token !== ''
        && hash_equals((string) ($quote['line_approval_token'] ?? ''), $token)
        && (string) ($quote['status'] ?? '') === 'pending'
        && in_array($decision, ['approve', 'reject'], true);

    if ($ok) {
        $nextStatus = $decision === 'approve' ? 'approved' : 'rejected';
        $qpk = Db::pkForLogicalId('quotations', $id);
        $cur = Db::row('quotations', $qpk) ?? [];
        Db::setRow('quotations', $qpk, array_merge($cur, [
            'status' => $nextStatus,
            'line_decision' => $decision,
            'line_decided_at' => date('Y-m-d H:i:s'),
            'line_approval_token' => '',
        ]));
        http_response_code(200);
        echo '<!doctype html><html lang="th"><head><meta charset="UTF-8"><title>บันทึกผลแล้ว</title></head><body style="font-family:sans-serif;padding:24px;"><h2>บันทึกผลเรียบร้อย</h2><p>ระบบได้อัปเดตใบเสนอราคาแล้ว: <strong>' . htmlspecialchars(strtoupper($nextStatus), ENT_QUOTES, 'UTF-8') . '</strong></p></body></html>';
        exit;
    }

    http_response_code(400);
    echo '<!doctype html><html lang="th"><head><meta charset="UTF-8"><title>ไม่สามารถดำเนินการได้</title></head><body style="font-family:sans-serif;padding:24px;"><h2>ไม่สามารถดำเนินการได้</h2><p>ลิงก์หมดอายุ หรือรายการนี้ถูกดำเนินการไปแล้ว</p></body></html>';
    exit;
}

if ($action === 'line_need_decision') {
    $decision = (string) ($_GET['decision'] ?? '');
    $token = trim((string) ($_GET['token'] ?? ''));
    $need = Db::row('purchase_needs', (string) $id);

    $ok = $need !== null
        && $token !== ''
        && hash_equals((string) ($need['line_approval_token'] ?? ''), $token)
        && (string) ($need['status'] ?? '') === 'pending'
        && in_array($decision, ['approve', 'reject'], true);

    if ($ok) {
        $nextStatus = $decision === 'approve' ? 'approved' : 'rejected';
        Db::mergeRow('purchase_needs', (string) $id, [
            'status' => $nextStatus,
            'line_decision' => $decision,
            'line_decided_at' => date('Y-m-d H:i:s'),
            'line_approval_token' => '',
        ]);
        http_response_code(200);
        echo '<!doctype html><html lang="th"><head><meta charset="UTF-8"><title>บันทึกผลแล้ว</title></head><body style="font-family:sans-serif;padding:24px;"><h2>บันทึกผลเรียบร้อย</h2><p>ใบต้องการซื้ออัปเดตเป็น <strong>' . htmlspecialchars(strtoupper($nextStatus), ENT_QUOTES, 'UTF-8') . '</strong></p></body></html>';
        exit;
    }

    http_response_code(400);
    echo '<!doctype html><html lang="th"><head><meta charset="UTF-8"><title>ไม่สามารถดำเนินการได้</title></head><body style="font-family:sans-serif;padding:24px;"><h2>ไม่สามารถดำเนินการได้</h2><p>ลิงก์หมดอายุ หรือรายการนี้ถูกดำเนินการไปแล้ว</p></body></html>';
    exit;
}

if (!isset($_SESSION['user_id'])) {
    exit('Access Denied: กรุณาเข้าสู่ระบบ');
}

// POST-only actions: prevent direct GET access to write endpoints.
if (($action === 'create_po_direct' || $action === 'create_po_from_pr' || $action === 'update_po_payment_status')
    && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $fallback = match ($action) {
        'create_po_direct' => app_path('pages/purchase/purchase-order-create.php'),
        'create_po_from_pr' => app_path('pages/purchase/purchase-request-list.php'),
        default => app_path('pages/purchase/purchase-order-list.php'),
    };
    http_response_code(200);
    echo '<!doctype html><html lang="th"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>เปิดหน้านี้โดยตรงไม่ได้</title></head><body style="font-family:Arial,sans-serif;padding:24px;"><h3>หน้านี้เป็น endpoint สำหรับบันทึกข้อมูล</h3><p>กรุณาใช้งานผ่านฟอร์มของระบบ</p><p><a href="' . htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8') . '">กลับไปหน้าสร้างเอกสาร</a></p></body></html>';
    exit;
}

if ($action !== 'get_data' && !csrf_verify_request()) {
    http_response_code(403);
    exit('Invalid security token. Please refresh the page and try again.');
}

$user_role = $_SESSION['role'] ?? 'user';

$admin_actions = ['approve_pr', 'reject_pr', 'delete', 'delete_quotation', 'delete_pr', 'delete_leave_request', 'delete_purchase_need', 'add_member', 'edit_member', 'delete_supplier'];
if (in_array($action, $admin_actions, true) && $user_role !== 'admin') {
    exit('Access Denied: เฉพาะผู้ดูแลระบบเท่านั้นที่สามารถดำเนินการนี้ได้');
}

/**
 * Generate leave request running number (LR-YYYYMM-XXX).
 */
function generateLeaveRequestNumber(string $seedDate = ''): string
{
    $baseDate = $seedDate !== '' ? $seedDate : date('Y-m-d');
    $stamp = date('Ym', strtotime($baseDate));
    $prefix = 'LR-' . $stamp . '-';
    $max = 0;
    foreach (Db::tableRows('leave_requests') as $row) {
        $num = (string) ($row['leave_number'] ?? '');
        if (strpos($num, $prefix) !== 0) {
            continue;
        }
        $seq = (int) substr($num, strlen($prefix));
        if ($seq > $max) {
            $max = $seq;
        }
    }
    return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
}

/**
 * Generate advance cash running number (AC-TNC-YYMM-XXX).
 */
function generateAdvanceCashNumber(string $seedDate = ''): string
{
    $baseDate = $seedDate !== '' ? $seedDate : date('Y-m-d');
    $stamp = date('ym', strtotime($baseDate));
    $prefix = 'AC-TNC-' . $stamp . '-';
    $max = 0;
    foreach (Db::tableRows('advance_cash_requests') as $row) {
        $num = (string) ($row['request_number'] ?? '');
        if (strpos($num, $prefix) !== 0) {
            continue;
        }
        $seq = (int) substr($num, strlen($prefix));
        if ($seq > $max) {
            $max = $seq;
        }
    }
    return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
}

/**
 * Show success popup with countdown before returning to PO list.
 */
function renderPoCreatedPopupAndRedirect(string $poNumber)
{
    $target = app_path('pages/purchase/purchase-order-list.php');
    $safePoNumber = htmlspecialchars($poNumber, ENT_QUOTES, 'UTF-8');
    ?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สร้าง PO สำเร็จ</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<script>
(function () {
    var target = <?= json_encode($target, JSON_UNESCAPED_SLASHES) ?>;
    Swal.fire({
        icon: 'success',
        title: 'สร้าง PO สำเร็จ',
        html: 'หมายเลข PO : <b><?= $safePoNumber ?></b><br><small>กำลังกลับไปหน้ารายการใน 3 วินาที...</small>',
        timer: 3000,
        timerProgressBar: true,
        allowOutsideClick: false,
        allowEscapeKey: false,
        confirmButtonText: 'ไปหน้ารายการทันที'
    }).then(function () {
        window.location.href = target;
    });
})();
</script>
</body>
</html>
<?php
    exit;
}

// --- get_data (Modal) ---
if ($action === 'get_data') {
    header('Content-Type: application/json; charset=UTF-8');
    if ($type === 'member') {
        $row = Db::row('users', (string) $id);
    } elseif ($type === 'supplier') {
        $row = Db::row('suppliers', (string) $id);
    } else {
        $table = ($type === 'company') ? 'company' : 'customers';
        $row = Db::row($table, (string) $id);
    }
    echo json_encode($row ?? []);
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
    } else {
        $nid = Db::nextNumericId('suppliers', 'id');
        $data['id'] = $nid;
        Db::setRow('suppliers', (string) $nid, $data);
    }
    header('Location: ' . app_path('pages/suppliers/supplier-list.php') . '?success=1');
    exit;
}

if ($action === 'delete_supplier') {
    $po = Db::findFirst('purchase_orders', static function (array $r) use ($id): bool {
        return isset($r['supplier_id']) && (int) $r['supplier_id'] === $id;
    });
    if ($po !== null) {
        header('Location: ' . app_path('pages/suppliers/supplier-list.php') . '?error=in_use');
    } else {
        Db::deleteRow('suppliers', (string) $id);
        header('Location: ' . app_path('pages/suppliers/supplier-list.php') . '?deleted=1');
    }
    exit;
}

// --- Advance cash ---
if (in_array($action, ['save_advance_cash_request', 'approve_advance_cash_request', 'reject_advance_cash_request', 'save_advance_cash_receipt', 'delete_advance_cash_request'], true)) {
    $isFinanceRole = in_array((string) ($_SESSION['role'] ?? ''), ['admin', 'Accounting'], true);
    if (!$isFinanceRole) {
        exit('Access Denied: เฉพาะฝ่ายการเงิน/ผู้ดูแลระบบเท่านั้น');
    }
}

if ($action === 'save_advance_cash_request' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $requestedBy = (int) ($_POST['requested_by'] ?? 0);
    $requestDate = trim((string) ($_POST['request_date'] ?? ''));
    $amount = round((float) str_replace([',', ' '], '', (string) ($_POST['amount'] ?? '0')), 2);
    $purpose = trim((string) ($_POST['purpose'] ?? ''));
    if ($requestedBy <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestDate) || $amount <= 0) {
        header('Location: ' . app_path('pages/advance-cash/advance-cash-create.php') . '?error=invalid_input');
        exit;
    }
    $reqId = Db::nextNumericId('advance_cash_requests', 'id');
    $reqNo = generateAdvanceCashNumber($requestDate);
    $now = date('Y-m-d H:i:s');
    $lineApprovalToken = bin2hex(random_bytes(24));
    Db::setRow('advance_cash_requests', (string) $reqId, [
        'id' => $reqId,
        'request_number' => $reqNo,
        'requested_by' => $requestedBy,
        'request_date' => $requestDate,
        'amount' => $amount,
        'purpose' => mb_substr($purpose, 0, 2000),
        'status' => 'pending',
        'line_approval_token' => $lineApprovalToken,
        'receipt_status' => 'none',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $u = Db::rowByIdField('users', $requestedBy);
    $requesterName = trim((string) ($u['fname'] ?? '') . ' ' . (string) ($u['lname'] ?? ''));
    $lineSent = function_exists('line_send_advance_cash_notification')
        ? line_send_advance_cash_notification([
            'id' => $reqId,
            'request_number' => $reqNo,
            'request_date' => $requestDate,
            'amount' => $amount,
            'purpose' => $purpose,
            'line_approval_token' => $lineApprovalToken,
        ], $requesterName)
        : false;
    $query = $lineSent ? 'success=1' : 'success=1&line_error=1';
    header('Location: ' . app_path('pages/advance-cash/advance-cash-list.php') . '?' . $query);
    exit;
}

if (($action === 'approve_advance_cash_request' || $action === 'reject_advance_cash_request') && $id > 0) {
    $row = Db::rowByIdField('advance_cash_requests', $id);
    if ($row === null) {
        header('Location: ' . app_path('pages/advance-cash/advance-cash-list.php'));
        exit;
    }
    $pk = Db::pkForLogicalId('advance_cash_requests', $id);
    $isApprove = $action === 'approve_advance_cash_request';
    Db::setRow('advance_cash_requests', $pk, array_merge($row, [
        'status' => $isApprove ? 'approved' : 'rejected',
        'approved_by' => (int) ($_SESSION['user_id'] ?? 0),
        'approved_at' => date('Y-m-d H:i:s'),
        'line_approval_token' => '',
        'updated_at' => date('Y-m-d H:i:s'),
    ]));
    header('Location: ' . app_path('pages/advance-cash/advance-cash-view.php') . '?id=' . $id . '&' . ($isApprove ? 'approved=1' : 'rejected=1'));
    exit;
}

if ($action === 'save_advance_cash_receipt' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $id > 0) {
    $row = Db::rowByIdField('advance_cash_requests', $id);
    if ($row === null) {
        header('Location: ' . app_path('pages/advance-cash/advance-cash-list.php'));
        exit;
    }
    if ((string) ($row['status'] ?? '') !== 'approved') {
        header('Location: ' . app_path('pages/advance-cash/advance-cash-view.php') . '?id=' . $id . '&error=receipt_requires_approved');
        exit;
    }
    $receiptDate = trim((string) ($_POST['receipt_date'] ?? ''));
    $paymentMethod = trim((string) ($_POST['receipt_payment_method'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiptDate) || !in_array($paymentMethod, ['cash', 'transfer'], true)) {
        header('Location: ' . app_path('pages/advance-cash/advance-cash-receipt.php') . '?id=' . $id . '&error=invalid_input');
        exit;
    }

    $slipPath = trim((string) ($row['receipt_transfer_slip_path'] ?? ''));
    $slipUrl = trim((string) ($row['receipt_transfer_slip_url'] ?? ''));
    if ($paymentMethod === 'transfer') {
        $hasUpload = isset($_FILES['transfer_slip']) && (int) ($_FILES['transfer_slip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($hasUpload) {
            $f = $_FILES['transfer_slip'];
            if ((int) ($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
                header('Location: ' . app_path('pages/advance-cash/advance-cash-receipt.php') . '?id=' . $id . '&error=upload_failed');
                exit;
            }
            $tmp = (string) ($f['tmp_name'] ?? '');
            $originalName = trim((string) ($f['name'] ?? 'slip'));
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'];
            if ($tmp === '' || !is_uploaded_file($tmp) || !in_array($ext, $allowedExt, true)) {
                header('Location: ' . app_path('pages/advance-cash/advance-cash-receipt.php') . '?id=' . $id . '&error=upload_type');
                exit;
            }
            $dirAbs = ROOT_PATH . '/uploads/advance-cash/' . $id;
            if (!(is_dir($dirAbs) || @mkdir($dirAbs, 0775, true) || is_dir($dirAbs))) {
                header('Location: ' . app_path('pages/advance-cash/advance-cash-receipt.php') . '?id=' . $id . '&error=upload_failed');
                exit;
            }
            $storedName = 'receipt_slip_' . date('Ymd_His') . '.' . $ext;
            if (!@move_uploaded_file($tmp, $dirAbs . '/' . $storedName)) {
                header('Location: ' . app_path('pages/advance-cash/advance-cash-receipt.php') . '?id=' . $id . '&error=upload_failed');
                exit;
            }
            $slipPath = 'uploads/advance-cash/' . $id . '/' . $storedName;
            $slipUrl = app_path($slipPath);
        }
        if ($slipUrl === '') {
            header('Location: ' . app_path('pages/advance-cash/advance-cash-receipt.php') . '?id=' . $id . '&error=slip_required');
            exit;
        }
    }

    $receiptNumber = trim((string) ($row['receipt_number'] ?? ''));
    if ($receiptNumber === '') {
        $receiptNumber = 'ACR-TNC-' . date('ym', strtotime($receiptDate)) . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }
    $requester = Db::rowByIdField('users', (int) ($row['requested_by'] ?? 0)) ?? [];
    $receiverName = trim((string) ($requester['fname'] ?? '') . ' ' . (string) ($requester['lname'] ?? ''));
    $pk = Db::pkForLogicalId('advance_cash_requests', $id);
    Db::setRow('advance_cash_requests', $pk, array_merge($row, [
        'receipt_status' => 'issued',
        'receipt_number' => $receiptNumber,
        'receipt_date' => $receiptDate,
        'receipt_payment_method' => $paymentMethod,
        'receipt_receiver_name' => $receiverName,
        'receipt_transfer_slip_path' => $paymentMethod === 'transfer' ? $slipPath : '',
        'receipt_transfer_slip_url' => $paymentMethod === 'transfer' ? $slipUrl : '',
        'updated_at' => date('Y-m-d H:i:s'),
    ]));
    header('Location: ' . app_path('pages/advance-cash/advance-cash-view.php') . '?id=' . $id . '&receipt_saved=1');
    exit;
}

if ($action === 'delete_advance_cash_request' && $id > 0) {
    $row = Db::rowByIdField('advance_cash_requests', $id);
    if ($row !== null) {
        $pk = Db::pkForLogicalId('advance_cash_requests', $id);
        Db::deleteRow('advance_cash_requests', $pk);
    }
    header('Location: ' . app_path('pages/advance-cash/advance-cash-list.php') . '?deleted=1');
    exit;
}

// --- PR ---
if ($action === 'save_pr') {
    $pr_number = trim((string) ($_POST['pr_number'] ?? ''));
    $created_at = trim((string) ($_POST['created_at'] ?? date('Y-m-d')));
    $requested_by = (int) ($_POST['requested_by'] ?? 0);
    $created_by = (int) $_SESSION['user_id'];
    $details = trim((string) ($_POST['details'] ?? ''));
    $procurement_type = trim((string) ($_POST['procurement_type'] ?? 'purchase'));
    if ($procurement_type !== 'hire') {
        $procurement_type = 'purchase';
    }

    $hire_contractor_name = '';
    $hire_employer_company_id = 0;
    $hire_scope_details = '';
    $hire_total_value = 0.0;
    $hire_installment_count = 1;

    if ($procurement_type === 'hire') {
        $hire_contractor_name = trim((string) ($_POST['hire_contractor_name'] ?? ''));
        $hire_employer_company_id = (int) ($_POST['hire_employer_company_id'] ?? 0);
        $hire_scope_details = trim((string) ($_POST['hire_scope_details'] ?? ''));
        $hire_total_value = round((float) str_replace([',', ' '], '', (string) ($_POST['hire_total_value'] ?? '0')), 2);
        $hire_installment_count = max(1, min(120, (int) ($_POST['hire_installment_count'] ?? 1)));
        if ($hire_contractor_name === '' || $hire_employer_company_id <= 0 || $hire_scope_details === '' || $hire_total_value <= 0) {
            header('Location: ' . app_path('pages/purchase/purchase-request-create.php') . '?error=hire_invalid');
            exit;
        }
        $vat_enabled = 0;
        $subtotal = $hire_total_value;
        $vat_amount = 0.0;
        $total_amount = $hire_total_value;
    } else {
        $vat_enabled = !empty($_POST['vat_enabled']) ? 1 : 0;
        $subtotal = 0.0;
        foreach ($_POST['item_description'] ?? [] as $key => $desc) {
            if (!isset($_POST['item_qty'][$key], $_POST['item_price'][$key])) {
                continue;
            }
            if (trim((string) $desc) === '') {
                continue;
            }
            $qty = (float) $_POST['item_qty'][$key];
            $price = (float) $_POST['item_price'][$key];
            $subtotal += $qty * $price;
        }
        $subtotal = round($subtotal, 2);
        if ($subtotal <= 0) {
            header('Location: ' . app_path('pages/purchase/purchase-request-create.php') . '?error=no_items');
            exit;
        }
        $vat_amount = $vat_enabled ? round($subtotal * 0.07, 2) : 0.0;
        $total_amount = round($subtotal + $vat_amount, 2);
    }

    $pr_id = Db::nextNumericId('purchase_requests', 'id');
    $quoteAttachmentPath = '';
    $quoteAttachmentUrl = '';
    $quoteAttachmentName = '';
    $quoteAttachmentMime = '';
    $quoteAttachmentSize = 0;

    if (!empty($_FILES['quotation_file']) && (int) ($_FILES['quotation_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['quotation_file'];
        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            header('Location: ' . app_path('pages/purchase/purchase-request-create.php') . '?error=upload_failed');
            exit;
        }

        $tmp = (string) ($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            header('Location: ' . app_path('pages/purchase/purchase-request-create.php') . '?error=upload_failed');
            exit;
        }

        $originalName = trim((string) ($f['name'] ?? 'quotation'));
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff'];
        if (!in_array($ext, $allowedExt, true)) {
            header('Location: ' . app_path('pages/purchase/purchase-request-create.php') . '?error=upload_type');
            exit;
        }

        $dirAbs = ROOT_PATH . '/uploads/pr-quotations/' . $pr_id;
        if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            header('Location: ' . app_path('pages/purchase/purchase-request-create.php') . '?error=upload_failed');
            exit;
        }

        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $safeBase = trim((string) $safeBase, '._-');
        if ($safeBase === '') {
            $safeBase = 'quotation';
        }
        $storedName = $safeBase . '_' . date('Ymd_His') . '.' . $ext;
        $destAbs = $dirAbs . '/' . $storedName;
        if (!@move_uploaded_file($tmp, $destAbs)) {
            header('Location: ' . app_path('pages/purchase/purchase-request-create.php') . '?error=upload_failed');
            exit;
        }

        $quoteAttachmentPath = 'uploads/pr-quotations/' . $pr_id . '/' . $storedName;
        $quoteAttachmentUrl = app_path($quoteAttachmentPath);
        $quoteAttachmentName = $originalName;
        $quoteAttachmentMime = (string) ($f['type'] ?? '');
        $quoteAttachmentSize = (int) ($f['size'] ?? 0);
    }

    $pr_row = [
        'id' => $pr_id,
        'pr_number' => $pr_number,
        'created_at' => $created_at,
        'requested_by' => $requested_by,
        'created_by' => $created_by,
        'details' => $details,
        'total_amount' => $total_amount,
        'status' => 'pending',
        'vat_enabled' => $vat_enabled,
        'subtotal_amount' => $subtotal,
        'vat_amount' => $vat_amount,
        'procurement_type' => $procurement_type,
        'hire_contractor_name' => $hire_contractor_name,
        'hire_employer_company_id' => $hire_employer_company_id,
        'hire_scope_details' => $hire_scope_details,
        'hire_total_value' => $hire_total_value,
        'hire_installment_count' => $hire_installment_count,
        'line_approval_token' => bin2hex(random_bytes(24)),
        'quotation_attachment_path' => $quoteAttachmentPath,
        'quotation_attachment_url' => $quoteAttachmentUrl,
        'quotation_attachment_name' => $quoteAttachmentName,
        'quotation_attachment_mime' => $quoteAttachmentMime,
        'quotation_attachment_size' => $quoteAttachmentSize,
    ];
    Db::setRow('purchase_requests', (string) $pr_id, $pr_row);

    $linePreviewLines = [];
    $lineItemsTotal = 0;
    $lineItemsShown = 0;
    foreach ($_POST['item_description'] ?? [] as $key => $desc) {
        if (!isset($_POST['item_qty'][$key], $_POST['item_price'][$key])) {
            continue;
        }
        $desc = trim((string) $desc);
        if ($desc === '') {
            continue;
        }
        $lineItemsTotal++;
        $iid = Db::nextNumericId('purchase_request_items', 'id');
        $qty = (float) $_POST['item_qty'][$key];
        $unit = trim((string) ($_POST['item_unit'][$key] ?? ''));
        $price = (float) $_POST['item_price'][$key];
        $total = $qty * $price;
        Db::setRow('purchase_request_items', (string) $iid, [
            'id' => $iid,
            'pr_id' => $pr_id,
            'description' => $desc,
            'quantity' => $qty,
            'unit' => $unit,
            'unit_price' => $price,
            'total' => $total,
        ]);
        if ($lineItemsShown < 12) {
            $lineItemsShown++;
            $linePreviewLines[] = $lineItemsShown . '. '
                . $desc
                . ' จำนวน ' . number_format($qty, 2) . ($unit !== '' ? ' ' . $unit : '')
                . ' x ราคา ' . number_format($price, 2);
        }
    }

    $requester = Db::row('users', (string) $requested_by);
    $requesterName = trim(((string) ($requester['fname'] ?? '')) . ' ' . ((string) ($requester['lname'] ?? '')));
    if ($requesterName === '') {
        $requesterName = 'Unknown User';
    }

    if ($lineItemsTotal > $lineItemsShown) {
        $linePreviewLines[] = '... และอีก ' . ($lineItemsTotal - $lineItemsShown) . ' รายการ';
    }
    $itemsPreview = count($linePreviewLines) > 0 ? implode("\n", $linePreviewLines) : '-';

    $lineQuoteText = $quoteAttachmentName !== ''
        ? 'มีใบเสนอราคา: ' . $quoteAttachmentName
        : 'ไม่มีใบเสนอราคา';
    $lineSent = line_send_pr_approval_notification($pr_row, $requesterName, $itemsPreview, $lineQuoteText, $quoteAttachmentUrl);
    $redirect = app_path('pages/purchase/purchase-request-list.php') . '?success=1';
    if (!$lineSent) {
        $redirect .= '&line_error=1';
    }

    header('Location: ' . $redirect);
    exit;
}

if ($action === 'save_leave_request') {
    $requestedBy = (int) $_SESSION['user_id'];
    $leaveType = trim((string) ($_POST['leave_type'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $startDate = trim((string) ($_POST['start_date'] ?? ''));
    $endDate = trim((string) ($_POST['end_date'] ?? ''));

    $validDate = static function (string $v): bool {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
    };
    if ($leaveType === '' || $reason === '' || !$validDate($startDate) || !$validDate($endDate) || strtotime($endDate) < strtotime($startDate)) {
        header('Location: ' . app_path('pages/leave-requests/leave-request-create.php') . '?error=invalid_input');
        exit;
    }

    $daysCount = (float) ((int) floor((strtotime($endDate) - strtotime($startDate)) / 86400) + 1);
    if ($daysCount < 1) {
        $daysCount = 1.0;
    }

    $leaveId = Db::nextNumericId('leave_requests', 'id');
    $leaveNumber = generateLeaveRequestNumber($startDate);

    $attachmentPath = '';
    $attachmentUrl = '';
    $attachmentName = '';
    if (!empty($_FILES['attachment']) && (int) ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['attachment'];
        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            header('Location: ' . app_path('pages/leave-requests/leave-request-create.php') . '?error=upload_failed');
            exit;
        }
        $tmp = (string) ($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            header('Location: ' . app_path('pages/leave-requests/leave-request-create.php') . '?error=upload_failed');
            exit;
        }

        $originalName = trim((string) ($f['name'] ?? 'leave-image'));
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($ext, $allowedExt, true)) {
            header('Location: ' . app_path('pages/leave-requests/leave-request-create.php') . '?error=upload_type');
            exit;
        }

        $dirAbs = ROOT_PATH . '/uploads/leave-requests/' . $leaveId;
        if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            header('Location: ' . app_path('pages/leave-requests/leave-request-create.php') . '?error=upload_failed');
            exit;
        }

        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $safeBase = trim((string) $safeBase, '._-');
        if ($safeBase === '') {
            $safeBase = 'leave';
        }
        $storedName = $safeBase . '_' . date('Ymd_His') . '.' . $ext;
        $destAbs = $dirAbs . '/' . $storedName;
        if (!@move_uploaded_file($tmp, $destAbs)) {
            header('Location: ' . app_path('pages/leave-requests/leave-request-create.php') . '?error=upload_failed');
            exit;
        }

        $attachmentPath = 'uploads/leave-requests/' . $leaveId . '/' . $storedName;
        $attachmentUrl = $attachmentPath;
        $attachmentName = $originalName;
    }

    $token = bin2hex(random_bytes(24));
    $lineSentAt = date('Y-m-d H:i:s');

    $leavePayload = [
        'id' => $leaveId,
        'leave_number' => $leaveNumber,
        'requested_by' => $requestedBy,
        'leave_type' => $leaveType,
        'reason' => $reason,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'days_count' => $daysCount,
        'status' => 'pending',
        'attachment_path' => $attachmentPath,
        'attachment_url' => $attachmentUrl,
        'attachment_name' => $attachmentName,
        'line_approval_token' => $token,
        'line_sent_at' => $lineSentAt,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    Db::setRow('leave_requests', (string) $leaveId, $leavePayload);

    $user = Db::row('users', (string) $requestedBy) ?? [];
    $requesterName = trim((string) ($user['fname'] ?? '') . ' ' . (string) ($user['lname'] ?? ''));
    if ($requesterName === '') {
        $requesterName = (string) ($_SESSION['name'] ?? 'Unknown User');
    }

    $lineSent = line_send_leave_approval_notification($leavePayload, $requesterName);
    if (!$lineSent) {
        Db::mergeRow('leave_requests', (string) $leaveId, [
            'status' => 'draft',
            'line_approval_token' => '',
            'line_sent_at' => '',
        ]);
        header('Location: ' . app_path('pages/leave-requests/leave-request-view.php') . '?id=' . $leaveId . '&line_error=1');
        exit;
    }

    header('Location: ' . app_path('pages/leave-requests/leave-request-list.php') . '?sent=1');
    exit;
}

if ($action === 'approve_pr') {
    Db::mergeRow('purchase_requests', (string) $id, ['status' => 'approved']);
    if (method_exists(Purchase::class, 'createHireContractIfNeededForPr')) {
        Purchase::createHireContractIfNeededForPr($id);
    }
    header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?approved=1');
    exit;
}

if ($action === 'reject_pr') {
    Db::mergeRow('purchase_requests', (string) $id, ['status' => 'rejected']);
    header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?rejected=1');
    exit;
}

if ($action === 'delete_pr') {
    if ($id <= 0) {
        header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?error=invalid_pr');
        exit;
    }
    foreach (Db::filter('hire_contracts', static fn (array $r): bool => isset($r['pr_id']) && (int) $r['pr_id'] === $id) as $hc) {
        $hcId = (int) ($hc['id'] ?? 0);
        if ($hcId > 0) {
            Db::deleteRow('hire_contracts', (string) $hcId);
        }
    }
    foreach (Db::filter('purchase_orders', static fn (array $r): bool => isset($r['pr_id']) && (int) $r['pr_id'] === $id) as $poDel) {
        $poid = (int) ($poDel['id'] ?? 0);
        if ($poid > 0) {
            Db::deleteWhereEquals('po_payments', 'po_id', (string) $poid);
            Db::deleteWhereEquals('purchase_order_items', 'po_id', (string) $poid);
            Db::deleteRow('purchase_orders', (string) $poid);
        }
    }
    Db::deleteWhereEquals('purchase_request_items', 'pr_id', (string) $id);
    Db::deleteRow('purchase_requests', (string) $id);
    header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?deleted=1');
    exit;
}

if ($action === 'delete_leave_request') {
    if ($id <= 0) {
        header('Location: ' . app_path('pages/leave-requests/leave-request-list.php') . '?error=invalid_leave');
        exit;
    }

    $leave = Db::row('leave_requests', (string) $id);
    if ($leave !== null) {
        $attachmentPath = trim((string) ($leave['attachment_path'] ?? ''));
        if ($attachmentPath !== '') {
            $abs = ROOT_PATH . '/' . ltrim(str_replace('\\', '/', $attachmentPath), '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        Db::deleteRow('leave_requests', (string) $id);
    }

    header('Location: ' . app_path('pages/leave-requests/leave-request-list.php') . '?deleted=1&scope=all');
    exit;
}

if ($action === 'save_purchase_need' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $need_number = trim((string) ($_POST['need_number'] ?? ''));
    if ($need_number === '') {
        $need_number = Purchase::nextNeedNumber();
    }

    $postedDate = trim((string) ($_POST['created_at'] ?? ''));
    $docDate = $postedDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $postedDate) === 1
        ? $postedDate
        : date('Y-m-d');

    $siteId = (int) ($_POST['site_id'] ?? 0);
    if ($siteId <= 0) {
        header('Location: ' . app_path('pages/purchase/purchase-need-create.php') . '?error=need_site');
        exit;
    }

    $siteRow = Db::rowByIdField('sites', $siteId);
    $siteName = $siteRow !== null ? trim((string) ($siteRow['name'] ?? '')) : '';
    if ($siteName === '') {
        header('Location: ' . app_path('pages/purchase/purchase-need-create.php') . '?error=need_site');
        exit;
    }

    $remarks = trim((string) ($_POST['remarks'] ?? ''));

    $descs = $_POST['need_item_description'] ?? [];
    $qtys = $_POST['need_item_qty'] ?? [];
    $units = $_POST['need_item_unit'] ?? [];
    $lines = [];
    if (!is_array($descs) || !is_array($qtys)) {
        header('Location: ' . app_path('pages/purchase/purchase-need-create.php') . '?error=need_no_items');
        exit;
    }
    foreach ($descs as $i => $descRaw) {
        $desc = trim((string) $descRaw);
        if ($desc === '') {
            continue;
        }
        $qty = isset($qtys[$i]) ? (float) str_replace([',', ' '], '', (string) $qtys[$i]) : 0.0;
        if ($qty <= 0) {
            continue;
        }
        $unit = isset($units[$i]) ? trim((string) $units[$i]) : '';
        $lines[] = ['description' => $desc, 'quantity' => $qty, 'unit' => $unit];
    }

    if (count($lines) === 0) {
        header('Location: ' . app_path('pages/purchase/purchase-need-create.php') . '?error=need_no_items');
        exit;
    }

    $previewParts = [];
    foreach (array_slice($lines, 0, 8) as $idx => $ln) {
        $previewParts[] = ($idx + 1) . '. ' . $ln['description'] . ' × ' . number_format($ln['quantity'], 2) . ($ln['unit'] !== '' ? ' ' . $ln['unit'] : '');
    }
    if (count($lines) > 8) {
        $previewParts[] = '… และอีก ' . (count($lines) - 8) . ' รายการ';
    }
    $detailsSummary = implode("\n", $previewParts);

    $requestedBy = (int) $_SESSION['user_id'];
    $needId = Db::nextNumericId('purchase_needs', 'id');
    $approvalToken = bin2hex(random_bytes(24));

    $needPayload = [
        'id' => $needId,
        'need_number' => $need_number,
        'created_at' => $docDate,
        'requested_by' => $requestedBy,
        'site_id' => $siteId,
        'site_name' => $siteName,
        'site_details' => '',
        'details' => $detailsSummary,
        'remarks' => $remarks,
        'status' => 'pending',
        'line_approval_token' => $approvalToken,
        'line_sent_at' => '',
    ];
    Db::setRow('purchase_needs', (string) $needId, $needPayload);

    $sortSeq = 0;
    foreach ($lines as $ln) {
        ++$sortSeq;
        $itemId = Db::nextNumericId('purchase_need_items', 'id');
        Db::setRow('purchase_need_items', (string) $itemId, [
            'id' => $itemId,
            'need_id' => $needId,
            'line_no' => $sortSeq,
            'description' => $ln['description'],
            'quantity' => $ln['quantity'],
            'unit' => $ln['unit'],
        ]);
    }

    $requester = Db::row('users', (string) $requestedBy) ?? [];
    $requesterName = trim((string) ($requester['fname'] ?? '') . ' ' . (string) ($requester['lname'] ?? ''));
    if ($requesterName === '') {
        $requesterName = (string) ($_SESSION['name'] ?? 'Unknown User');
    }

    $lineSent = line_send_purchase_need_notification($needPayload, $requesterName, $detailsSummary);
    if ($lineSent) {
        Db::mergeRow('purchase_needs', (string) $needId, ['line_sent_at' => date('Y-m-d H:i:s')]);
    }

    $redirect = app_path('pages/purchase/purchase-need-list.php') . '?need_success=1';
    if (!$lineSent) {
        $redirect .= '&line_error=1';
    }

    header('Location: ' . $redirect);
    exit;
}

if ($action === 'delete_purchase_need') {
    if ($id <= 0) {
        header('Location: ' . app_path('pages/purchase/purchase-need-list.php') . '?error=invalid_need');
        exit;
    }
    foreach (Db::tableKeyed('purchase_need_items') as $pk => $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((int) ($row['need_id'] ?? 0) === $id) {
            Db::deleteRow('purchase_need_items', (string) $pk);
        }
    }
    Db::deleteRow('purchase_needs', (string) $id);
    header('Location: ' . app_path('pages/purchase/purchase-need-list.php') . '?need_deleted=1');
    exit;
}

// --- PO from PR ---
if ($action === 'create_po_from_pr') {
    $pr_id = (int) ($_POST['pr_id'] ?? 0);
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $hire_contract_id = (int) ($_POST['hire_contract_id'] ?? 0);
    $po_number = Purchase::generatePONumber();
    $created_by = (int) $_SESSION['user_id'];

    if ($supplier_id <= 0) {
        header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?error=po_supplier');
        exit;
    }

    $dup = Db::findFirst('purchase_orders', static function (array $r) use ($pr_id): bool {
        return $pr_id > 0 && isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
    });
    if ($dup !== null) {
        header('Location: ' . app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&error=po_exists');
        exit;
    }

    $pr_row = Db::row('purchase_requests', (string) $pr_id);
    if ($pr_row === null) {
        header('Location: ' . app_path('pages/purchase/purchase-request-list.php') . '?error=pr_not_found');
        exit;
    }

    if ($hire_contract_id > 0) {
        $hc = Db::row('hire_contracts', (string) $hire_contract_id);
        if ($hc === null || (int) ($hc['pr_id'] ?? 0) !== $pr_id) {
            header('Location: ' . app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $pr_id . '&error=contract');
            exit;
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

    $po_id = Db::nextNumericId('purchase_orders', 'id');
    Db::setRow('purchase_orders', (string) $po_id, [
        'id' => $po_id,
        'po_number' => $po_number,
        'pr_id' => $pr_id,
        'hire_contract_id' => $hire_contract_id,
        'supplier_id' => $supplier_id,
        'created_at' => date('Y-m-d'),
        'issue_date' => date('Y-m-d'),
        'total_amount' => $total_amount,
        'status' => 'ordered',
        'created_by' => $created_by,
        'vat_enabled' => $vat_en,
        'subtotal_amount' => $sub_amt,
        'vat_amount' => $vat_amt,
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
        ]);
    }
    if (method_exists(Purchase::class, 'seedPoPayments')) {
        Purchase::seedPoPayments($po_id, $total_amount, $hire_contract_id > 0 ? $hire_contract_id : null);
    }
    renderPoCreatedPopupAndRedirect((string) $po_number);
}

// --- PO โดยตรง (ไม่อิง PR) ---
if ($action === 'create_po_direct') {
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $hire_contract_id = (int) ($_POST['hire_contract_id'] ?? 0);
    $vat_enabled = !empty($_POST['vat_enabled']) ? 1 : 0;
    $created_by = (int) $_SESSION['user_id'];
    $po_number = Purchase::generatePONumber();

    if ($hire_contract_id > 0) {
        $hc = Db::row('hire_contracts', (string) $hire_contract_id);
        if ($hc === null) {
            header('Location: ' . app_path('pages/purchase/purchase-order-create.php') . '?error=contract');
            exit;
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
    $subtotal = round($subtotal, 2);
    if ($subtotal <= 0) {
        header('Location: ' . app_path('pages/purchase/purchase-order-create.php') . '?error=no_items');
        exit;
    }
    $vat_amt = $vat_enabled ? round($subtotal * 0.07, 2) : 0.0;
    $total_amount = round($subtotal + $vat_amt, 2);

    $po_id = Db::nextNumericId('purchase_orders', 'id');
    Db::setRow('purchase_orders', (string) $po_id, [
        'id' => $po_id,
        'po_number' => $po_number,
        'pr_id' => 0,
        'hire_contract_id' => $hire_contract_id,
        'supplier_id' => $supplier_id,
        'created_at' => date('Y-m-d'),
        'issue_date' => date('Y-m-d'),
        'total_amount' => $total_amount,
        'status' => 'ordered',
        'created_by' => $created_by,
        'vat_enabled' => $vat_enabled,
        'subtotal_amount' => $subtotal,
        'vat_amount' => $vat_amt,
    ]);

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
    if (method_exists(Purchase::class, 'seedPoPayments')) {
        Purchase::seedPoPayments($po_id, $total_amount, $hire_contract_id > 0 ? $hire_contract_id : null);
    }
    renderPoCreatedPopupAndRedirect((string) $po_number);
}

/** รายการ PO: แนบสลิป + ตั้งสถานะจ่ายแล้ว (purchase_orders.payment_slip_path) */
if ($action === 'update_po_payment_status' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $listUrl = app_path('pages/purchase/purchase-order-list.php');
    $po_id = (int) ($_POST['po_id'] ?? 0);
    $payment_status = strtolower(trim((string) ($_POST['payment_status'] ?? '')));
    if ($po_id <= 0 || $payment_status !== 'paid') {
        header('Location: ' . $listUrl . '?error=invalid');
        exit;
    }
    $po = Db::row('purchase_orders', (string) $po_id);
    if ($po === null) {
        header('Location: ' . $listUrl . '?error=invalid');
        exit;
    }
    if (empty($_FILES['payment_slip']) || (int) ($_FILES['payment_slip']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        header('Location: ' . $listUrl . '?error=payment_slip_required');
        exit;
    }
    $f = $_FILES['payment_slip'];
    if ((int) ($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
        header('Location: ' . $listUrl . '?error=upload_failed');
        exit;
    }
    $tmp = (string) ($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        header('Location: ' . $listUrl . '?error=upload_failed');
        exit;
    }
    $originalName = trim((string) ($f['name'] ?? 'slip'));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowedExt, true)) {
        header('Location: ' . $listUrl . '?error=upload_type');
        exit;
    }
    $dirAbs = ROOT_PATH . '/uploads/po-payment-slips/' . $po_id;
    if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
        header('Location: ' . $listUrl . '?error=upload_failed');
        exit;
    }
    $storedName = 'slip_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destAbs = $dirAbs . '/' . $storedName;
    if (!@move_uploaded_file($tmp, $destAbs)) {
        header('Location: ' . $listUrl . '?error=upload_failed');
        exit;
    }
    $rel = 'uploads/po-payment-slips/' . $po_id . '/' . $storedName;
    Db::mergeRow('purchase_orders', (string) $po_id, [
        'payment_status' => 'paid',
        'payment_slip_path' => $rel,
        'payment_marked_paid_at' => date('Y-m-d H:i:s'),
    ]);
    header('Location: ' . $listUrl . '?payment_saved=1');
    exit;
}

if ($action === 'upload_po_payment_slip' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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
        header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?error=payment');
        exit;
    }
    if (empty($_FILES['slip_file']) || (int) ($_FILES['slip_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?id=' . $po_id . '&error=upload_failed');
        exit;
    }
    $f = $_FILES['slip_file'];
    if ((int) ($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
        header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?id=' . $po_id . '&error=upload_failed');
        exit;
    }
    $tmp = (string) ($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?id=' . $po_id . '&error=upload_failed');
        exit;
    }
    $originalName = trim((string) ($f['name'] ?? 'slip'));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowedExt, true)) {
        header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?id=' . $po_id . '&error=upload_type');
        exit;
    }
    $dirAbs = ROOT_PATH . '/uploads/po-payments/' . $po_id;
    if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
        header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?id=' . $po_id . '&error=upload_failed');
        exit;
    }
    $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $safeBase = trim((string) $safeBase, '._-');
    if ($safeBase === '') {
        $safeBase = 'slip';
    }
    $storedName = 'pay' . $payment_id . '_' . date('Ymd_His') . '.' . $ext;
    $destAbs = $dirAbs . '/' . $storedName;
    if (!@move_uploaded_file($tmp, $destAbs)) {
        header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?id=' . $po_id . '&error=upload_failed');
        exit;
    }
    $rel = 'uploads/po-payments/' . $po_id . '/' . $storedName;
    Db::mergeRow('po_payments', (string) $payment_id, [
        'slip_path' => $rel,
        'slip_url' => app_path($rel),
        'paid_amount' => (float) str_replace([',', ' '], '', (string) ($_POST['paid_amount'] ?? ($pay['amount'] ?? 0))),
        'payment_note' => mb_substr(trim((string) ($_POST['payment_note'] ?? '')), 0, 500),
        'slip_uploaded_at' => date('Y-m-d H:i:s'),
    ]);
    if ($backTo === 'po_list') {
        header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?payment_saved=1');
    } else {
        header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?id=' . $po_id . '&payment_saved=1');
    }
    exit;
}

// --- บันทึกบิลซื้อตามโครงการ (ไซต์งาน) ---
if ($action === 'save_project_purchase_bill' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $bill_id = (int) ($_POST['bill_id'] ?? 0);
    $editingBill = $bill_id > 0 ? (Db::rowByIdField('purchase_bills', $bill_id) ?? Db::row('purchase_bills', (string) $bill_id)) : null;
    if ($bill_id > 0 && $editingBill === null) {
        header('Location: ' . app_path('pages/purchase/purchase-bill.php') . '?error=invalid');
        exit;
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
        header('Location: ' . app_path('pages/purchase/purchase-bill.php') . '?error=invalid');
        exit;
    }
    if ($site_id > 0) {
        $site = Db::row('sites', (string) $site_id);
        if ($site === null) {
            header('Location: ' . app_path('pages/purchase/purchase-bill.php') . '?error=site');
            exit;
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
        header('Location: ' . app_path('pages/purchase/purchase-bill.php') . '?error=invalid');
        exit;
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
        header('Location: ' . app_path('pages/purchase/purchase-bill.php') . '?error=need_lines');
        exit;
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
    Db::setRow('purchase_bills', (string) $bid, $billPayload);
    $resultKey = $editingBill === null ? 'success=1' : 'updated=1';
    $month = substr($bill_date, 0, 7);
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    header('Location: ' . app_path('pages/purchase/purchase-bill.php') . '?month=' . rawurlencode($month) . '&site_id=' . $site_id . '&' . $resultKey);
    exit;
}

// --- Quotation ---
if ($action === 'create_quotation' || $action === 'edit_quotation') {
    $issue_date = (string) ($_POST['issue_date'] ?? '');
    $company_id = (int) ($_POST['company_id'] ?? 0);
    $customer_id = (int) ($_POST['customer_id'] ?? 0);
    $subtotal = (float) ($_POST['subtotal'] ?? 0);
    $vat_amount = !empty($_POST['vat_enabled']) ? ($subtotal * 0.07) : 0.0;
    $grand_total = $subtotal + $vat_amount;

    if ($action === 'create_quotation') {
        $quote_number = trim((string) ($_POST['quote_number'] ?? ''));
        $created_by = (int) $_SESSION['user_id'];
        $q_id = Db::nextNumericId('quotations', 'id');
        Db::setRow('quotations', (string) $q_id, [
            'id' => $q_id,
            'quote_number' => $quote_number,
            'date' => $issue_date,
            'company_id' => $company_id,
            'customer_id' => $customer_id,
            'subtotal' => $subtotal,
            'vat_amount' => $vat_amount,
            'grand_total' => $grand_total,
            'status' => 'pending',
            'created_by' => $created_by,
        ]);
        $target_id = $q_id;
    } else {
        $target_id = (int) ($_POST['quotation_id'] ?? 0);
        $cur = Db::row('quotations', (string) $target_id) ?? [];
        Db::setRow('quotations', (string) $target_id, array_merge($cur, [
            'date' => $issue_date,
            'company_id' => $company_id,
            'customer_id' => $customer_id,
            'subtotal' => $subtotal,
            'vat_amount' => $vat_amount,
            'grand_total' => $grand_total,
        ]));
        Db::deleteWhereEquals('quotation_items', 'quotation_id', (string) $target_id);
    }

    foreach ($_POST['description'] ?? [] as $key => $desc) {
        $desc = trim((string) $desc);
        if ($desc === '') {
            continue;
        }
        $qty = (float) ($_POST['quantity'][$key] ?? 0);
        $unit = trim((string) ($_POST['unit'][$key] ?? ''));
        $price = (float) ($_POST['price'][$key] ?? 0);
        $total = (float) ($_POST['total'][$key] ?? 0);
        $iid = Db::nextNumericId('quotation_items', 'id');
        Db::setRow('quotation_items', (string) $iid, [
            'id' => $iid,
            'quotation_id' => $target_id,
            'description' => $desc,
            'quantity' => $qty,
            'unit' => $unit,
            'unit_price' => $price,
            'total' => $total,
        ]);

    }
    header('Location: ' . app_path('pages/quotations/quotation-list.php') . '?success=1');
    exit;
}

// --- company / customer / member ---
if (in_array($action, ['add_company', 'edit_company', 'add_customer', 'edit_customer', 'add_member', 'edit_member'], true)) {
    if (strpos($action, 'member') !== false) {
        $page = 'pages/organization/member-manage.php';
        $u_code = trim((string) ($_POST['user_code'] ?? ''));
        $fn = trim((string) ($_POST['fname'] ?? ''));
        $ln = trim((string) ($_POST['lname'] ?? ''));
        $nn = trim((string) ($_POST['nickname'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? ''));
        $job_raw = trim((string) ($_POST['job_title'] ?? ''));
        if (strlen($job_raw) > 160) {
            $job_raw = substr($job_raw, 0, 160);
        }
        $address = trim((string) ($_POST['address'] ?? ''));
        $salary_raw = str_replace([',', ' '], '', trim((string) ($_POST['salary_base'] ?? '')));
        $has_salary = $salary_raw !== '' && is_numeric($salary_raw);
        $salary_val = $has_salary ? (string) round((float) $salary_raw, 2) : null;
        $bd_raw = trim((string) ($_POST['birth_date'] ?? ''));
        $has_bd = $bd_raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd_raw);
        $nid_digits = preg_replace('/\D/', '', (string) ($_POST['national_id'] ?? ''));
        $nid_digits = substr($nid_digits, 0, 13);

        $base = [
            'user_code' => $u_code,
            'fname' => $fn,
            'lname' => $ln,
            'nickname' => $nn,
            'role' => $role,
            'job_title' => $job_raw,
            'address' => $address,
            'salary_base' => $salary_val,
            'birth_date' => $has_bd ? $bd_raw : null,
            'national_id' => $nid_digits !== '' ? $nid_digits : null,
        ];

        if ($action === 'add_member') {
            if (trim((string) ($_POST['password'] ?? '')) === '') {
                header('Location: ' . app_path($page) . '?error=password_required');
                exit;
            }
            $pw = password_hash((string) $_POST['password'], PASSWORD_DEFAULT);
            $uid = Db::nextNumericId('users', 'userid');
            $base['userid'] = $uid;
            $base['password'] = $pw;
            Db::setRow('users', (string) $uid, $base);
        } else {
            $edit_id = (int) ($_POST['id'] ?? 0);
            $cur = Db::row('users', (string) $edit_id) ?? [];
            if (!empty($_POST['password'])) {
                $base['password'] = password_hash((string) $_POST['password'], PASSWORD_DEFAULT);
            }
            Db::setRow('users', (string) $edit_id, array_merge($cur, $base));
        }
        $ok = ($action === 'edit_member') ? 'updated' : '1';
        header('Location: ' . app_path($page) . '?success=' . $ok);
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
        } else {
            $edit_id = (int) ($_POST['id'] ?? 0);
            $cur = Db::row($table, (string) $edit_id) ?? [];
            Db::setRow($table, (string) $edit_id, array_merge($cur, $row));
        }
        header('Location: ' . app_path($page) . '?success=1');
    }
    exit;
}

// --- delete ---
if ($action === 'delete_quotation' && $id > 0) {
    $action = 'delete';
    $type = 'quotation';
}

if ($action === 'delete' && $id > 0) {
    if ($type === 'invoice') {
        Db::deleteWhereEquals('invoice_items', 'invoice_id', (string) $id);
        $taxRows = Db::filter('tax_invoices', static function (array $r) use ($id): bool {
            return isset($r['invoice_id']) && (int) $r['invoice_id'] === $id;
        });
        foreach ($taxRows as $taxRow) {
            $taxId = (int) ($taxRow['id'] ?? 0);
            if ($taxId > 0) {
                Db::deleteWhereEquals('tax_invoice_items', 'tax_invoice_id', (string) $taxId);
                Db::deleteRow('tax_invoices', (string) $taxId);
            }
        }
        Db::deleteRow('invoices', (string) $id);
        header('Location: ' . app_path('index.php') . '?deleted=1');
    } elseif ($type === 'quotation') {
        Db::deleteWhereEquals('quotation_items', 'quotation_id', (string) $id);
        Db::deleteRow('quotations', (string) $id);
        header('Location: ' . app_path('pages/quotations/quotation-list.php') . '?deleted=1');
    } elseif ($type === 'member') {
        Db::deleteRow('users', (string) $id);
        header('Location: ' . app_path('pages/organization/member-manage.php') . '?deleted=1');
    } elseif ($type === 'tax_invoice') {
        Db::deleteWhereEquals('tax_invoice_items', 'tax_invoice_id', (string) $id);
        Db::deleteRow('tax_invoices', (string) $id);
        header('Location: ' . app_path('pages/invoices/tax-invoice-list.php') . '?deleted=1');
    } elseif ($type === 'purchase_order') {
        Db::deleteWhereEquals('po_payments', 'po_id', (string) $id);
        Db::deleteWhereEquals('purchase_order_items', 'po_id', (string) $id);
        Db::deleteRow('purchase_orders', (string) $id);
        header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?deleted=1');
    } elseif ($type === 'project_purchase_bill') {
        Db::deleteWhereEquals('purchase_bill_items', 'bill_id', (string) $id);
        Db::deleteWhereEquals('purchase_bill_items', 'purchase_bill_id', (string) $id);
        Db::deleteWhereEquals('purchase_bill_items', 'purchase_bills_id', (string) $id);
        Db::deleteRow('purchase_bills', (string) $id);
        header('Location: ' . app_path('pages/purchase/purchase-bill.php') . '?deleted=1');
    } else {
        $table = ($type === 'company') ? 'company' : 'customers';
        $page = ($table === 'company') ? 'pages/organization/company-manage.php' : 'pages/organization/customer-manage.php';
        Db::deleteRow($table, (string) $id);
        header('Location: ' . app_path($page) . '?deleted=1');
    }
    exit;
}
