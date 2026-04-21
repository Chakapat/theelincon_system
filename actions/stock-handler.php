<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit('Unauthorized');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !csrf_verify_request()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden');
}

$me = (int) $_SESSION['user_id'];
$pos = $_SESSION['role'] ?? 'user';
$canManage = ($pos === 'admin' || $pos === 'Accounting');

function stock_redirect(string $path): void
{
    header('Location: ' . app_path($path));
    exit;
}

function stock_balance_sum(int $productId): float
{
    $sum = 0.0;
    foreach (Db::filter('stock_movements', static function (array $r) use ($productId): bool {
        return isset($r['product_id']) && (int) $r['product_id'] === $productId;
    }) as $m) {
        $sum += (float) ($m['qty'] ?? 0);
    }

    return $sum;
}

function stock_code_exists(string $code, ?int $exceptId = null): bool
{
    $code = trim($code);
    foreach (Db::tableRows('stock_products') as $p) {
        if ($exceptId !== null && (int) ($p['id'] ?? 0) === $exceptId) {
            continue;
        }
        if (trim((string) ($p['code'] ?? '')) === $code) {
            return true;
        }
    }

    return false;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'save_product' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$canManage) {
        stock_redirect('pages/stock-list.php?error=forbidden');
    }
    $id = (int) ($_POST['id'] ?? 0);
    $code = trim((string) ($_POST['code'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    $unit = trim((string) ($_POST['unit'] ?? 'ชิ้น')) ?: 'ชิ้น';
    $reorder = str_replace([',', ' '], '', trim((string) ($_POST['reorder_level'] ?? '0')));
    $reorderVal = is_numeric($reorder) ? (string) round((float) $reorder, 2) : '0';
    $opening = str_replace([',', ' '], '', trim((string) ($_POST['opening_qty'] ?? '')));
    $openingVal = ($opening !== '' && is_numeric($opening)) ? (float) $opening : null;

    if ($code === '' || $name === '') {
        stock_redirect('pages/stock-product-form.php' . ($id ? '?id=' . $id : '') . '&error=required');
    }

    if ($id > 0) {
        $cur = Db::row('stock_products', (string) $id);
        if ($cur === null || empty($cur['is_active'])) {
            stock_redirect('pages/stock-list.php?error=notfound');
        }
        if (stock_code_exists($code, $id)) {
            stock_redirect('pages/stock-product-form.php?id=' . $id . '&error=duplicate');
        }
        $rv = (float) $reorderVal;
        Db::mergeRow('stock_products', (string) $id, [
            'code' => $code,
            'name' => $name,
            'unit' => $unit,
            'reorder_level' => $rv,
        ]);
        stock_redirect('pages/stock-list.php?success=updated');
    }

    if (stock_code_exists($code, null)) {
        stock_redirect('pages/stock-product-form.php?error=duplicate');
    }

    $rv = (float) $reorderVal;
    $newId = Db::nextNumericId('stock_products', 'id');
    Db::setRow('stock_products', (string) $newId, [
        'id' => $newId,
        'code' => $code,
        'name' => $name,
        'unit' => $unit,
        'reorder_level' => $rv,
        'is_active' => 1,
    ]);

    if ($openingVal !== null && $openingVal > 0) {
        $qty = round($openingVal, 3);
        $mid = Db::nextNumericId('stock_movements', 'id');
        Db::setRow('stock_movements', (string) $mid, [
            'id' => $mid,
            'product_id' => $newId,
            'qty' => $qty,
            'movement_type' => 'opening',
            'note' => 'ยอดยกมา',
            'created_by' => $me,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    stock_redirect('pages/stock-list.php?success=1');
}

if ($action === 'add_movement' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$canManage) {
        stock_redirect('pages/stock-list.php?error=forbidden');
    }
    $productId = (int) ($_POST['product_id'] ?? 0);
    $kind = (string) ($_POST['kind'] ?? '');
    $qtyRaw = str_replace([',', ' '], '', trim((string) ($_POST['qty'] ?? '')));
    $note = trim((string) ($_POST['note'] ?? ''));
    $noteEsc = $note === '' ? '' : mb_substr($note, 0, 500, 'UTF-8');

    if ($productId <= 0 || !is_numeric($qtyRaw) || (float) $qtyRaw == 0.0) {
        stock_redirect('pages/stock-adjust.php?error=invalid&product_id=' . $productId);
    }

    $prod = Db::row('stock_products', (string) $productId);
    if ($prod === null || empty($prod['is_active'])) {
        stock_redirect('pages/stock-list.php?error=notfound');
    }

    $qtyAbs = round(abs((float) $qtyRaw), 3);
    $delta = 0.0;
    $type = 'adjust';

    if ($kind === 'in') {
        $delta = $qtyAbs;
        $type = 'in';
    } elseif ($kind === 'out') {
        $delta = -$qtyAbs;
        $type = 'out';
    } elseif ($kind === 'adjust') {
        $delta = round((float) $qtyRaw, 3);
        $type = 'adjust';
    } else {
        stock_redirect('pages/stock-adjust.php?error=invalid&product_id=' . $productId);
    }

    $bal = stock_balance_sum($productId);
    if ($bal + $delta < -0.0001) {
        stock_redirect('pages/stock-adjust.php?error=insufficient&product_id=' . $productId);
    }

    $mid = Db::nextNumericId('stock_movements', 'id');
    Db::setRow('stock_movements', (string) $mid, [
        'id' => $mid,
        'product_id' => $productId,
        'qty' => $delta,
        'movement_type' => $type,
        'note' => $noteEsc,
        'created_by' => $me,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    stock_redirect('pages/stock-movements.php?product_id=' . $productId . '&success=1');
}

if ($action === 'deactivate' && $canManage) {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        Db::mergeRow('stock_products', (string) $id, ['is_active' => 0]);
    }
    stock_redirect('pages/stock-list.php?deactivated=1');
}

stock_redirect('pages/stock-list.php?error=bad');
