<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/connect_database.php';

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

if (!isset($_SESSION['user_id'])) {
    exit('Access Denied: กรุณาเข้าสู่ระบบ');
}

$action = $_GET['action'] ?? '';
$type = $_GET['type'] ?? '';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$user_role = $_SESSION['role'] ?? 'user';

$admin_actions = ['approve_pr', 'reject_pr', 'delete', 'delete_quotation', 'delete_pr', 'add_member', 'edit_member', 'delete_supplier'];
if (in_array($action, $admin_actions, true) && $user_role !== 'admin') {
    exit('Access Denied: เฉพาะผู้ดูแลระบบเท่านั้นที่สามารถดำเนินการนี้ได้');
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
    header('Location: ' . app_path('pages/supplier-list.php') . '?success=1');
    exit;
}

if ($action === 'delete_supplier') {
    $po = Db::findFirst('purchase_orders', static function (array $r) use ($id): bool {
        return isset($r['supplier_id']) && (int) $r['supplier_id'] === $id;
    });
    if ($po !== null) {
        header('Location: ' . app_path('pages/supplier-list.php') . '?error=in_use');
    } else {
        Db::deleteRow('suppliers', (string) $id);
        header('Location: ' . app_path('pages/supplier-list.php') . '?deleted=1');
    }
    exit;
}

// --- PR ---
if ($action === 'save_pr') {
    $pr_number = trim((string) ($_POST['pr_number'] ?? ''));
    $requested_by = (int) ($_POST['requested_by'] ?? 0);
    $created_by = (int) $_SESSION['user_id'];
    $details = trim((string) ($_POST['details'] ?? ''));
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
    $vat_amount = $vat_enabled ? round($subtotal * 0.07, 2) : 0.0;
    $total_amount = round($subtotal + $vat_amount, 2);

    $pr_id = Db::nextNumericId('purchase_requests', 'id');
    $pr_row = [
        'id' => $pr_id,
        'pr_number' => $pr_number,
        'requested_by' => $requested_by,
        'created_by' => $created_by,
        'details' => $details,
        'total_amount' => $total_amount,
        'status' => 'pending',
        'vat_enabled' => $vat_enabled,
        'subtotal_amount' => $subtotal,
        'vat_amount' => $vat_amount,
    ];
    Db::setRow('purchase_requests', (string) $pr_id, $pr_row);

    foreach ($_POST['item_description'] ?? [] as $key => $desc) {
        if (!isset($_POST['item_qty'][$key], $_POST['item_price'][$key])) {
            continue;
        }
        if (trim((string) $desc) === '') {
            continue;
        }
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
    }
    header('Location: ' . app_path('pages/purchase-request-list.php') . '?success=1');
    exit;
}

if ($action === 'approve_pr') {
    Db::mergeRow('purchase_requests', (string) $id, ['status' => 'approved']);
    header('Location: ' . app_path('pages/purchase-request-list.php') . '?approved=1');
    exit;
}

if ($action === 'reject_pr') {
    Db::mergeRow('purchase_requests', (string) $id, ['status' => 'rejected']);
    header('Location: ' . app_path('pages/purchase-request-list.php') . '?rejected=1');
    exit;
}

if ($action === 'delete_pr') {
    if ($id <= 0) {
        header('Location: ' . app_path('pages/purchase-request-list.php') . '?error=invalid_pr');
        exit;
    }
    Db::deleteWhereEquals('purchase_orders', 'pr_id', (string) $id);
    Db::deleteWhereEquals('purchase_request_items', 'pr_id', (string) $id);
    Db::deleteRow('purchase_requests', (string) $id);
    header('Location: ' . app_path('pages/purchase-request-list.php') . '?deleted=1');
    exit;
}

// --- PO from PR ---
if ($action === 'create_po_from_pr') {
    $pr_id = (int) ($_POST['pr_id'] ?? 0);
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $po_number = Purchase::generatePONumber();
    $created_by = (int) $_SESSION['user_id'];

    $dup = Db::findFirst('purchase_orders', static function (array $r) use ($pr_id): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
    });
    if ($dup !== null) {
        header('Location: ' . app_path('pages/purchase-request-view.php') . '?id=' . $pr_id . '&error=po_exists');
        exit;
    }

    $pr_row = Db::row('purchase_requests', (string) $pr_id);
    if ($pr_row === null) {
        header('Location: ' . app_path('pages/purchase-request-list.php') . '?error=pr_not_found');
        exit;
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
        'supplier_id' => $supplier_id,
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
    header('Location: ' . app_path('pages/purchase-order-list.php') . '?success=1');
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
    header('Location: ' . app_path('pages/quotation-list.php') . '?success=1');
    exit;
}

// --- company / customer / member ---
if (in_array($action, ['add_company', 'edit_company', 'add_customer', 'edit_customer', 'add_member', 'edit_member'], true)) {
    if (strpos($action, 'member') !== false) {
        $page = 'pages/member-manage.php';
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
        $page = ($table === 'company') ? 'pages/company-manage.php' : 'pages/customer-manage.php';
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
        Db::deleteWhereEquals('tax_invoices', 'invoice_id', (string) $id);
        Db::deleteRow('invoices', (string) $id);
        header('Location: ' . app_path('index.php') . '?deleted=1');
    } elseif ($type === 'quotation') {
        Db::deleteWhereEquals('quotation_items', 'quotation_id', (string) $id);
        Db::deleteRow('quotations', (string) $id);
        header('Location: ' . app_path('pages/quotation-list.php') . '?deleted=1');
    } elseif ($type === 'member') {
        Db::deleteRow('users', (string) $id);
        header('Location: ' . app_path('pages/member-manage.php') . '?deleted=1');
    } else {
        $table = ($type === 'company') ? 'company' : 'customers';
        $page = ($table === 'company') ? 'pages/company-manage.php' : 'pages/customer-manage.php';
        Db::deleteRow($table, (string) $id);
        header('Location: ' . app_path($page) . '?deleted=1');
    }
    exit;
}
