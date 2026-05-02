<?php

declare(strict_types=1);

/**
 * เดิมเป็นหน้าเต็ม — ย้ายไปฟอร์มใน modal ที่ advance-cash-list.php แล้ว
 * URL นี้ redirect เพื่อลิงก์เก่ายังใช้ได้
 */

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$isFinanceRole = user_is_finance_role();
if (!$isFinanceRole) {
    header('Location: ' . app_path('pages/advance-cash/advance-cash-list.php'));
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . app_path('pages/advance-cash/advance-cash-list.php'));
    exit;
}

$query = [
    'open_id' => $id,
    'open_receipt' => '1',
];
$err = isset($_GET['error']) ? trim((string) $_GET['error']) : '';
if ($err !== '') {
    $query['error'] = $err;
}

header('Location: ' . app_path('pages/advance-cash/advance-cash-list.php') . '?' . http_build_query($query));
exit;
