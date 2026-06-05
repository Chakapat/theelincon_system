<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$contractId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$prId = isset($_GET['pr_id']) ? (int) $_GET['pr_id'] : 0;

$contract = null;
if ($contractId > 0) {
    $contract = Db::row('hire_contracts', (string) $contractId);
} elseif ($prId > 0) {
    $contract = Db::findFirst('hire_contracts', static function (array $r) use ($prId): bool {
        return (int) ($r['pr_id'] ?? 0) === $prId;
    });
}

if ($contract === null) {
    header('Location: ' . Purchase::workOrderListUrl() . '?error=not_found');
    exit();
}

$resolvedContractId = (int) ($contract['id'] ?? 0);
$resolvedPrId = (int) ($contract['pr_id'] ?? 0);
$woUrl = Purchase::workOrderViewUrl($resolvedContractId, $resolvedPrId);

$extraParams = [];
foreach (['created', 'error'] as $key) {
    if (isset($_GET[$key]) && (string) $_GET[$key] !== '') {
        $extraParams[$key] = (string) $_GET[$key];
    }
}

if ($woUrl !== null) {
    if ($extraParams !== []) {
        $woUrl .= (str_contains($woUrl, '?') ? '&' : '?') . http_build_query($extraParams);
    }
    header('Location: ' . $woUrl);
    exit();
}

if ($resolvedPrId > 0) {
    header('Location: ' . app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $resolvedPrId . '&mode=contract');
    exit();
}

$fallback = Purchase::workOrderListUrl() . '?error=no_wo';
if ($resolvedContractId > 0) {
    $fallback .= '&hire_contract_id=' . $resolvedContractId;
}
header('Location: ' . $fallback);
exit();
