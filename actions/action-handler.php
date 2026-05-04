<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/tnc_action_response.php';
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
    if (function_exists('tnc_ajax_form_requested') && tnc_ajax_form_requested()) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'โทเค็นความปลอดภัยไม่ถูกต้อง กรุณาโหลดหน้าใหม่'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(403);
    exit('Invalid security token. Please refresh the page and try again.');
}

$finance_ok_actions = ['approve_pr', 'reject_pr'];
$admin_only_actions = ['delete', 'delete_quotation', 'delete_pr', 'delete_purchase_need', 'add_member', 'edit_member', 'delete_supplier'];
if (in_array($action, $finance_ok_actions, true) && !user_is_finance_role()) {
    exit('Access Denied: เฉพาะฝ่ายการเงินหรือผู้ดูแลระบบเท่านั้นที่สามารถดำเนินการนี้ได้');
}
if (in_array($action, $admin_only_actions, true) && !user_is_admin_role()) {
    exit('Access Denied: เฉพาะผู้ดูแลระบบเท่านั้นที่สามารถดำเนินการนี้ได้');
}

/**
 * Show success popup with countdown before returning to PO list.
 */
function renderPoCreatedPopupAndRedirect(string $poNumber)
{
    if (tnc_ajax_form_requested()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => true,
            'message' => 'สร้าง PO สำเร็จ หมายเลข ' . $poNumber,
            'po_number' => $poNumber,
            'action' => 'po_created',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
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
    tnc_action_redirect( app_path('pages/suppliers/supplier-list.php') . '?success=1');
}

if ($action === 'delete_supplier') {
    $po = Db::findFirst('purchase_orders', static function (array $r) use ($id): bool {
        return isset($r['supplier_id']) && (int) $r['supplier_id'] === $id;
    });
    if ($po !== null) {
        tnc_action_redirect( app_path('pages/suppliers/supplier-list.php') . '?error=in_use');
    } else {
        Db::deleteRow('suppliers', (string) $id);
        tnc_action_redirect( app_path('pages/suppliers/supplier-list.php') . '?deleted=1');
    }
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
            tnc_action_redirect( app_path('pages/purchase/purchase-request-create.php') . '?error=hire_invalid');
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
            tnc_action_redirect( app_path('pages/purchase/purchase-request-create.php') . '?error=no_items');
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

    tnc_action_redirect( $redirect);
}

if ($action === 'approve_pr') {
    Db::mergeRow('purchase_requests', (string) $id, ['status' => 'approved']);
    tnc_action_redirect( app_path('pages/purchase/purchase-request-list.php') . '?approved=1');
}

if ($action === 'reject_pr') {
    Db::mergeRow('purchase_requests', (string) $id, ['status' => 'rejected']);
    tnc_action_redirect( app_path('pages/purchase/purchase-request-list.php') . '?rejected=1');
}

if ($action === 'delete_pr') {
    if ($id <= 0) {
        tnc_action_redirect( app_path('pages/purchase/purchase-request-list.php') . '?error=invalid_pr');
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
    tnc_action_redirect( app_path('pages/hire-contracts/hire-contract-view.php') . '?id=' . $contractId . '&created=1');
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
        tnc_action_redirect( app_path('pages/purchase/purchase-need-create.php') . '?error=need_site');
    }

    $siteRow = Db::rowByIdField('sites', $siteId);
    $siteName = $siteRow !== null ? trim((string) ($siteRow['name'] ?? '')) : '';
    if ($siteName === '') {
        tnc_action_redirect( app_path('pages/purchase/purchase-need-create.php') . '?error=need_site');
    }

    $remarks = trim((string) ($_POST['remarks'] ?? ''));

    $descs = $_POST['need_item_description'] ?? [];
    $qtys = $_POST['need_item_qty'] ?? [];
    $units = $_POST['need_item_unit'] ?? [];
    $lines = [];
    if (!is_array($descs) || !is_array($qtys)) {
        tnc_action_redirect( app_path('pages/purchase/purchase-need-create.php') . '?error=need_no_items');
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
        tnc_action_redirect( app_path('pages/purchase/purchase-need-create.php') . '?error=need_no_items');
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

    tnc_action_redirect( $redirect);
}

if ($action === 'delete_purchase_need') {
    if ($id <= 0) {
        tnc_action_redirect( app_path('pages/purchase/purchase-need-list.php') . '?error=invalid_need');
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
    tnc_action_redirect( app_path('pages/purchase/purchase-need-list.php') . '?need_deleted=1');
}

// --- PO from PR ---
if ($action === 'create_po_from_pr') {
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

    if (!$isHirePr && $supplier_id <= 0) {
        tnc_action_redirect( app_path('pages/purchase/purchase-request-list.php') . '?error=po_supplier');
    }

    if (!$isHirePr) {
        $dup = Db::findFirst('purchase_orders', static function (array $r) use ($pr_id): bool {
            return $pr_id > 0 && isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
        });
        if ($dup !== null) {
            tnc_action_redirect( app_path('pages/purchase/purchase-request-view.php') . '?id=' . $pr_id . '&error=po_exists');
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
        'order_type' => 'purchase',
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
    $hireFallback = $hire_contract_id > 0
        ? app_path('pages/purchase/purchase-order-from-hire-contract.php') . '?hire_contract_id=' . $hire_contract_id
        : app_path('pages/purchase/purchase-order-create.php');
    $hireFbSep = str_contains($hireFallback, '?') ? '&' : '?';

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
    $subtotal = round($subtotal, 2);
    if ($subtotal <= 0) {
        tnc_action_redirect($hireFallback . $hireFbSep . 'error=no_items');
    }
    $vat_amt = $vat_enabled ? round($subtotal * 0.07, 2) : 0.0;
    $gross = round($subtotal + $vat_amt, 2);

    $retention = 0.0;
    $payable = $gross;
    $seedAmount = $gross;
    $hireExtra = [];

    if ($hire_contract_id > 0 && $hc !== null) {
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
    Db::setRow('purchase_orders', (string) $po_id, array_merge([
        'id' => $po_id,
        'po_number' => $po_number,
        'pr_id' => 0,
        'hire_contract_id' => $hire_contract_id,
        'supplier_id' => $supplier_id,
        'created_at' => date('Y-m-d'),
        'issue_date' => date('Y-m-d'),
        'total_amount' => $gross,
        'status' => 'ordered',
        'created_by' => $created_by,
        'vat_enabled' => $vat_enabled,
        'subtotal_amount' => $subtotal,
        'vat_amount' => $vat_amt,
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
    renderPoCreatedPopupAndRedirect((string) $po_number);
}

/** รายการ PO: แนบสลิป + ตั้งสถานะจ่ายแล้ว (purchase_orders.payment_slip_path) */
if ($action === 'update_po_payment_status' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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
    if (empty($_FILES['payment_slip']) || (int) ($_FILES['payment_slip']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        tnc_action_redirect( $listUrl . '?error=payment_slip_required');
    }
    $f = $_FILES['payment_slip'];
    if ((int) ($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
        tnc_action_redirect( $listUrl . '?error=upload_failed');
    }
    $tmp = (string) ($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        tnc_action_redirect( $listUrl . '?error=upload_failed');
    }
    $originalName = trim((string) ($f['name'] ?? 'slip'));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowedExt, true)) {
        tnc_action_redirect( $listUrl . '?error=upload_type');
    }
    $dirAbs = ROOT_PATH . '/uploads/po-payment-slips/' . $po_id;
    if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
        tnc_action_redirect( $listUrl . '?error=upload_failed');
    }
    $storedName = 'slip_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destAbs = $dirAbs . '/' . $storedName;
    if (!@move_uploaded_file($tmp, $destAbs)) {
        tnc_action_redirect( $listUrl . '?error=upload_failed');
    }
    $rel = 'uploads/po-payment-slips/' . $po_id . '/' . $storedName;
    Db::mergeRow('purchase_orders', (string) $po_id, [
        'payment_status' => 'paid',
        'payment_slip_path' => $rel,
        'payment_marked_paid_at' => date('Y-m-d H:i:s'),
    ]);
    tnc_action_redirect( $listUrl . '?payment_saved=1');
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
    Db::mergeRow('po_payments', (string) $payment_id, [
        'slip_path' => $rel,
        'slip_url' => app_path($rel),
        'paid_amount' => (float) str_replace([',', ' '], '', (string) ($_POST['paid_amount'] ?? ($pay['amount'] ?? 0))),
        'payment_note' => mb_substr(trim((string) ($_POST['payment_note'] ?? '')), 0, 500),
        'slip_uploaded_at' => date('Y-m-d H:i:s'),
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
    Db::setRow('purchase_bills', (string) $bid, $billPayload);
    $resultKey = $editingBill === null ? 'success=1' : 'updated=1';
    $month = substr($bill_date, 0, 7);
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    tnc_action_redirect( app_path('pages/purchase/purchase-bill.php') . '?month=' . rawurlencode($month) . '&site_id=' . $site_id . '&' . $resultKey);
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
    tnc_action_redirect( app_path('pages/quotations/quotation-list.php') . '?success=1');
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
        } else {
            $edit_id = (int) ($_POST['id'] ?? 0);
            $cur = Db::row('users', (string) $edit_id) ?? [];
            if (!empty($_POST['password'])) {
                $base['password'] = password_hash((string) $_POST['password'], PASSWORD_DEFAULT);
            }
            Db::setRow('users', (string) $edit_id, array_merge($cur, $base));
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
        } else {
            $edit_id = (int) ($_POST['id'] ?? 0);
            $cur = Db::row($table, (string) $edit_id) ?? [];
            Db::setRow($table, (string) $edit_id, array_merge($cur, $row));
        }
        tnc_action_redirect( app_path($page) . '?success=1');
    }
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
        tnc_action_redirect( app_path('index.php') . '?deleted=1');
    } elseif ($type === 'quotation') {
        Db::deleteWhereEquals('quotation_items', 'quotation_id', (string) $id);
        Db::deleteRow('quotations', (string) $id);
        tnc_action_redirect( app_path('pages/quotations/quotation-list.php') . '?deleted=1');
    } elseif ($type === 'member') {
        Db::deleteRow('users', (string) $id);
        tnc_action_redirect( app_path('pages/organization/member-manage.php') . '?deleted=1');
    } elseif ($type === 'tax_invoice') {
        Db::deleteWhereEquals('tax_invoice_items', 'tax_invoice_id', (string) $id);
        Db::deleteRow('tax_invoices', (string) $id);
        tnc_action_redirect( app_path('pages/invoices/tax-invoice-list.php') . '?deleted=1');
    } elseif ($type === 'purchase_order') {
        Db::deleteWhereEquals('po_payments', 'po_id', (string) $id);
        Db::deleteWhereEquals('purchase_order_items', 'po_id', (string) $id);
        Db::deleteRow('purchase_orders', (string) $id);
        tnc_action_redirect( app_path('pages/purchase/purchase-order-list.php') . '?deleted=1');
    } elseif ($type === 'project_purchase_bill') {
        Db::deleteWhereEquals('purchase_bill_items', 'bill_id', (string) $id);
        Db::deleteWhereEquals('purchase_bill_items', 'purchase_bill_id', (string) $id);
        Db::deleteWhereEquals('purchase_bill_items', 'purchase_bills_id', (string) $id);
        Db::deleteRow('purchase_bills', (string) $id);
        tnc_action_redirect( app_path('pages/purchase/purchase-bill.php') . '?deleted=1');
    } else {
        $table = ($type === 'company') ? 'company' : 'customers';
        $page = ($table === 'company') ? 'pages/organization/company-manage.php' : 'pages/organization/customer-manage.php';
        Db::deleteRow($table, (string) $id);
        tnc_action_redirect( app_path($page) . '?deleted=1');
    }
}
