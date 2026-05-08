<?php

declare(strict_types=1);


require_once __DIR__ . '/_page_root.php';
use Theelincon\Rtdb\Db;

session_start();
require_once THEELINCON_ROOT . '/config/connect_database.php';
require_once THEELINCON_ROOT . '/includes/cash_ledger_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
if (!$isAdmin) {
    header('Location: ' . app_path('index.php'));
    exit;
}
$me = (int) $_SESSION['user_id'];
$handler = app_path('actions/cash-ledger-handler.php');

cash_ledger_auto_archive_monthly_if_due();

$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$query = ['month' => $month];
if (isset($_GET['edit']) && (int) $_GET['edit'] > 0) {
    $query['edit'] = (int) $_GET['edit'];
}
foreach (['saved', 'deleted', 'err', 'page'] as $k) {
    if (isset($_GET[$k]) && (string) $_GET[$k] !== '') {
        $query[$k] = (string) $_GET[$k];
    }
}
header('Location: ' . app_path('pages/cash-ledger-dashboard.php') . '?' . http_build_query($query));
exit;
