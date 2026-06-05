<?php

declare(strict_types=1);

use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = Purchase::workOrderListUrl();
if ($query !== '') {
    $target .= (str_contains($target, '?') ? '&' : '?') . $query;
}
header('Location: ' . $target);
exit();
