<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__) . '/config/connect_database.php';
require_once dirname(__DIR__) . '/includes/purchase_po_payment_slips.php';
require_once dirname(__DIR__) . '/includes/purchase/po_item_search.php';
require_once dirname(__DIR__) . '/includes/stock_site_data.php';

use Theelincon\Rtdb\Db;

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dataset = trim((string) ($_GET['dataset'] ?? ''));

if ($dataset === 'stock_movements_site' || $dataset === 'stock_site_checksum') {
    $siteId = (int) ($_GET['site_id'] ?? 0);
    if ($siteId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'site_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = tnc_stock_site_live_payload($siteId);
    if ($dataset === 'stock_site_checksum') {
        echo json_encode(['ok' => true, 'checksum' => $payload['checksum']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'checksum' => $payload['checksum'],
        'movements' => $payload['movements'],
        'products' => $payload['products'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedMirror = [
    'purchase_requests',
    'purchase_orders',
    'suppliers',
    'invoices',
    'tax_invoices',
];

if ($dataset === 'mirror_table' || $dataset === 'mirror_checksum') {
    tnc_require_finance_role();
    $table = trim((string) ($_GET['table'] ?? ''));
    if (!in_array($table, $allowedMirror, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_table'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rows = Db::tableRows($table);
    $checksum = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE));
    if ($dataset === 'mirror_checksum') {
        echo json_encode(['ok' => true, 'checksum' => $checksum], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'checksum' => $checksum, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($dataset === 'po_action_row') {
    $poId = (int) ($_GET['po_id'] ?? 0);
    $row = tnc_po_action_row_for_modal($poId);
    if ($row === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'row' => $row], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($dataset === 'po_item_search') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $limit = (int) ($_GET['limit'] ?? 200);
    $siteId = (int) ($_GET['site_id'] ?? 0);
    $searchOptions = ['limit' => $limit];
    if ($siteId > 0) {
        $searchOptions['site_id'] = $siteId;
    }
    $result = tnc_po_item_search($q, $searchOptions);
    echo json_encode([
        'ok' => true,
        'q' => $result['q'],
        'tokens' => $result['tokens'],
        'count' => $result['count'],
        'truncated' => $result['truncated'],
        'rows' => $result['rows'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown_dataset'], JSON_UNESCAPED_UNICODE);
