<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__) . '/config/connect_database.php';
require_once dirname(__DIR__) . '/includes/datasets.php';

use Theelincon\Rtdb\Db;

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dataset = trim((string) ($_GET['dataset'] ?? ''));

if ($dataset === 'hire_contracts') {
    $rows = tnc_dataset_hire_contract_rows();
    $checksum = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok' => true, 'checksum' => $checksum, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($dataset === 'stock_movements_site') {
    $siteId = (int) ($_GET['site_id'] ?? 0);
    if ($siteId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'site_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $products = [];
    foreach (Db::tableRows('stock_products') as $p) {
        if (empty($p['is_active'])) {
            continue;
        }
        $pid = (int) ($p['id'] ?? 0);
        if ($pid > 0) {
            $products[$pid] = $p;
        }
    }
    $movements = [];
    foreach (Db::tableRows('stock_movements') as $m) {
        $pid = (int) ($m['product_id'] ?? 0);
        $rowSiteId = (int) ($m['site_id'] ?? 0);
        if ($rowSiteId !== $siteId || !isset($products[$pid])) {
            continue;
        }
        $movements[] = $m;
    }
    Db::sortRows($movements, 'created_at', true);
    $checksum = hash('sha256', json_encode($movements, JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok' => true, 'checksum' => $checksum, 'movements' => $movements, 'products' => $products], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedMirror = [
    'purchase_requests',
    'purchase_orders',
    'quotations',
    'suppliers',
    'purchase_needs',
    'invoices',
    'tax_invoices',
    'attendance_logs',
    'employee_payslip_requests',
];

if ($dataset === 'mirror_table') {
    $table = trim((string) ($_GET['table'] ?? ''));
    if (!in_array($table, $allowedMirror, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_table'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rows = Db::tableRows($table);
    $checksum = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok' => true, 'checksum' => $checksum, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown_dataset'], JSON_UNESCAPED_UNICODE);
