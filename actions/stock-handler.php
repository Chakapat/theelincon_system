<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/tnc_action_response.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit('Unauthorized');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !csrf_verify_request()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden');
}

$me = (int) $_SESSION['user_id'];
$canManage = user_is_finance_role();

function stock_redirect(string $path): void
{
    tnc_action_redirect(app_path($path));
}

function stock_site_name(int $siteId): string
{
    if ($siteId <= 0) {
        return '';
    }
    $row = Db::rowByIdField('sites', $siteId);

    return trim((string) ($row['name'] ?? ''));
}

/** คงเหลือตามไซต์ (ไม่นับ movement ที่ระบุ ถ้ามี) */
function stock_balance_site_product(int $productId, int $siteId, ?int $excludeMovementLogicalId = null): float
{
    $sum = 0.0;
    foreach (Db::tableRows('stock_movements') as $m) {
        if ((int) ($m['product_id'] ?? 0) !== $productId) {
            continue;
        }
        if ((int) ($m['site_id'] ?? 0) !== $siteId) {
            continue;
        }
        if ($excludeMovementLogicalId !== null && (int) ($m['id'] ?? 0) === $excludeMovementLogicalId) {
            continue;
        }
        $sum += (float) ($m['qty'] ?? 0);
    }

    return $sum;
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

function stock_combine_datetime(string $dateYmd): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
        return date('Y-m-d H:i:s');
    }

    return $dateYmd . ' ' . date('H:i:s');
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'save_product' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$canManage) {
        stock_redirect('pages/stock/stock-list.php?error=forbidden');
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
        stock_redirect('pages/stock/stock-product-form.php' . ($id ? '?id=' . $id : '') . '&error=required');
    }

    if ($id > 0) {
        $cur = Db::row('stock_products', (string) $id);
        if ($cur === null || empty($cur['is_active'])) {
            stock_redirect('pages/stock/stock-list.php?error=notfound');
        }
        if (stock_code_exists($code, $id)) {
            stock_redirect('pages/stock/stock-product-form.php?id=' . $id . '&error=duplicate');
        }
        $rv = (float) $reorderVal;
        Db::mergeRow('stock_products', (string) $id, [
            'code' => $code,
            'name' => $name,
            'unit' => $unit,
            'reorder_level' => $rv,
        ]);
        stock_redirect('pages/stock/stock-list.php?success=updated');
    }

    if (stock_code_exists($code, null)) {
        stock_redirect('pages/stock/stock-product-form.php?error=duplicate');
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
            'site_id' => 0,
            'qty' => $qty,
            'movement_type' => 'opening',
            'note' => 'ยอดยกมา',
            'created_by' => $me,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    stock_redirect('pages/stock/stock-list.php?success=1');
}

if ($action === 'add_movement' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$canManage) {
        stock_redirect('pages/stock/stock-list.php?error=forbidden');
    }
    $productId = (int) ($_POST['product_id'] ?? 0);
    $kind = (string) ($_POST['kind'] ?? '');
    $qtyRaw = str_replace([',', ' '], '', trim((string) ($_POST['qty'] ?? '')));
    $note = trim((string) ($_POST['note'] ?? ''));
    $noteEsc = $note === '' ? '' : mb_substr($note, 0, 500, 'UTF-8');

    if ($productId <= 0 || !is_numeric($qtyRaw) || (float) $qtyRaw == 0.0) {
        stock_redirect('pages/stock/stock-adjust.php?error=invalid&product_id=' . $productId);
    }

    $prod = Db::row('stock_products', (string) $productId);
    if ($prod === null || empty($prod['is_active'])) {
        stock_redirect('pages/stock/stock-list.php?error=notfound');
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
        stock_redirect('pages/stock/stock-adjust.php?error=invalid&product_id=' . $productId);
    }

    $bal = stock_balance_sum($productId);
    if ($bal + $delta < -0.0001) {
        stock_redirect('pages/stock/stock-adjust.php?error=insufficient&product_id=' . $productId);
    }

    $mid = Db::nextNumericId('stock_movements', 'id');
    Db::setRow('stock_movements', (string) $mid, [
        'id' => $mid,
        'product_id' => $productId,
        'site_id' => 0,
        'qty' => $delta,
        'movement_type' => $type,
        'note' => $noteEsc,
        'created_by' => $me,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    stock_redirect('pages/stock/stock-movements.php?product_id=' . $productId . '&success=1');
}

if ($action === 'save_transaction' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $canManage) {
    $siteId = (int) ($_POST['site_id'] ?? 0);
    $txnDate = trim((string) ($_POST['txn_date'] ?? ''));
    $personName = trim((string) ($_POST['person_name'] ?? ''));
    $productId = (int) ($_POST['product_id'] ?? 0);
    $movementType = (string) ($_POST['movement_type'] ?? '');
    $qtyRaw = str_replace([',', ' '], '', trim((string) ($_POST['qty'] ?? '')));
    $note = trim((string) ($_POST['note'] ?? ''));
    $noteEsc = $note === '' ? '' : mb_substr($note, 0, 500, 'UTF-8');

    if ($siteId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate) || $personName === '' || $productId <= 0) {
        stock_redirect('pages/stock/stock-adjust.php?error=site&site_id=' . $siteId);
    }
    if (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0) {
        stock_redirect('pages/stock/stock-adjust.php?error=qty&site_id=' . $siteId);
    }
    if (!in_array($movementType, ['in', 'out'], true)) {
        stock_redirect('pages/stock/stock-adjust.php?error=type&site_id=' . $siteId);
    }

    $prod = Db::row('stock_products', (string) $productId);
    if ($prod === null || empty($prod['is_active'])) {
        stock_redirect('pages/stock/stock-list.php?error=notfound');
    }

    $qtyAbs = round(abs((float) $qtyRaw), 3);
    $delta = $movementType === 'in' ? $qtyAbs : -$qtyAbs;

    $bal = stock_balance_site_product($productId, $siteId, null);
    if ($bal + $delta < -0.0001) {
        stock_redirect('pages/stock/stock-adjust.php?error=insufficient&site_id=' . $siteId);
    }

    $mid = Db::nextNumericId('stock_movements', 'id');

    $photoRel = '';
    if (!empty($_FILES['photo']) && (int) ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $f = $_FILES['photo'];
        $tmp = (string) ($f['tmp_name'] ?? '');
        $originalName = trim((string) ($f['name'] ?? 'photo'));
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if ($tmp !== '' && is_uploaded_file($tmp) && in_array($ext, $allowedExt, true)) {
            $dirAbs = ROOT_PATH . '/uploads/stock/' . $mid;
            if (is_dir($dirAbs) || @mkdir($dirAbs, 0775, true) || is_dir($dirAbs)) {
                $stored = 'photo_' . date('Ymd_His') . '.' . $ext;
                if (@move_uploaded_file($tmp, $dirAbs . '/' . $stored)) {
                    $photoRel = 'uploads/stock/' . $mid . '/' . $stored;
                }
            }
        }
    }

    $finalNote = $noteEsc;
    if ($photoRel !== '') {
        $finalNote = trim($finalNote . "\n[photo]" . $photoRel);
    }
    Db::setRow('stock_movements', (string) $mid, [
        'id' => $mid,
        'site_id' => $siteId,
        'product_id' => $productId,
        'person_name' => mb_substr($personName, 0, 120, 'UTF-8'),
        'qty' => $delta,
        'movement_type' => $movementType,
        'note' => $finalNote,
        'created_by' => $me,
        'created_at' => stock_combine_datetime($txnDate),
    ]);

    stock_redirect('pages/stock/stock-list.php?site_id=' . $siteId . '&saved=1');
}

if ($action === 'save_site_transfer' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $canManage) {
    $fromSite = (int) ($_POST['from_site_id'] ?? 0);
    $toSite = (int) ($_POST['to_site_id'] ?? 0);
    $productId = (int) ($_POST['product_id'] ?? 0);
    $personName = trim((string) ($_POST['person_name'] ?? ''));
    $txnDate = trim((string) ($_POST['txn_date'] ?? ''));
    $qtyRaw = str_replace([',', ' '], '', trim((string) ($_POST['qty'] ?? '')));
    $note = trim((string) ($_POST['note'] ?? ''));
    $noteEsc = $note === '' ? '' : mb_substr($note, 0, 500, 'UTF-8');

    if ($fromSite <= 0 || $toSite <= 0 || $fromSite === $toSite || $productId <= 0 || $personName === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate)) {
        stock_redirect('pages/stock/stock-adjust.php?error=transfer&site_id=' . $fromSite);
    }
    if (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0) {
        stock_redirect('pages/stock/stock-adjust.php?error=qty&site_id=' . $fromSite);
    }

    $prod = Db::row('stock_products', (string) $productId);
    if ($prod === null || empty($prod['is_active'])) {
        stock_redirect('pages/stock/stock-list.php?error=notfound');
    }

    $qtyAbs = round(abs((float) $qtyRaw), 3);
    $balFrom = stock_balance_site_product($productId, $fromSite, null);
    if ($balFrom + (-$qtyAbs) < -0.0001) {
        stock_redirect('pages/stock/stock-adjust.php?error=insufficient&site_id=' . $fromSite);
    }

    $fromName = stock_site_name($fromSite);
    $toName = stock_site_name($toSite);
    $transferRef = bin2hex(random_bytes(8));
    $createdAt = stock_combine_datetime($txnDate);

    $outId = Db::nextNumericId('stock_movements', 'id');
    Db::setRow('stock_movements', (string) $outId, [
        'id' => $outId,
        'site_id' => $fromSite,
        'product_id' => $productId,
        'person_name' => mb_substr($personName, 0, 120, 'UTF-8'),
        'qty' => -$qtyAbs,
        'movement_type' => 'out',
        'note' => $noteEsc !== '' ? $noteEsc : 'โอนไปยัง ' . ($toName !== '' ? $toName : 'ไซต์ #' . $toSite),
        'transfer_ref' => $transferRef,
        'counter_site_id' => $toSite,
        'counter_site_name' => $toName,
        'created_by' => $me,
        'created_at' => $createdAt,
    ]);

    $inId = Db::nextNumericId('stock_movements', 'id');
    Db::setRow('stock_movements', (string) $inId, [
        'id' => $inId,
        'site_id' => $toSite,
        'product_id' => $productId,
        'person_name' => mb_substr($personName, 0, 120, 'UTF-8'),
        'qty' => $qtyAbs,
        'movement_type' => 'in',
        'note' => $noteEsc !== '' ? $noteEsc : 'รับจาก ' . ($fromName !== '' ? $fromName : 'ไซต์ #' . $fromSite),
        'transfer_ref' => $transferRef,
        'source_site_id' => $fromSite,
        'source_site_name' => $fromName,
        'created_by' => $me,
        'created_at' => $createdAt,
    ]);

    stock_redirect('pages/stock/stock-list.php?site_id=' . $toSite . '&saved=1');
}

if ($action === 'update_transaction' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $canManage) {
    $id = (int) ($_POST['id'] ?? 0);
    $siteId = (int) ($_POST['site_id'] ?? 0);
    $txnDate = trim((string) ($_POST['txn_date'] ?? ''));
    $personName = trim((string) ($_POST['person_name'] ?? ''));
    $productId = (int) ($_POST['product_id'] ?? 0);
    $movementType = (string) ($_POST['movement_type'] ?? '');
    $qtyRaw = str_replace([',', ' '], '', trim((string) ($_POST['qty'] ?? '')));
    $note = trim((string) ($_POST['note'] ?? ''));
    $noteEsc = $note === '' ? '' : mb_substr($note, 0, 500, 'UTF-8');

    if ($id <= 0 || $siteId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate) || $personName === '' || $productId <= 0) {
        stock_redirect('pages/stock/stock-list.php?site_id=' . $siteId . '&error=invalid');
    }
    if (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0 || !in_array($movementType, ['in', 'out'], true)) {
        stock_redirect('pages/stock/stock-list.php?site_id=' . $siteId . '&error=invalid');
    }

    $pk = Db::pkForLogicalId('stock_movements', $id);
    $row = Db::row('stock_movements', $pk);
    if ($row === null) {
        stock_redirect('pages/stock/stock-list.php?site_id=' . $siteId . '&error=notfound');
    }

    $transferRef = trim((string) ($row['transfer_ref'] ?? ''));
    if ($transferRef !== '') {
        stock_redirect('pages/stock/stock-list.php?site_id=' . $siteId . '&error=transfer_locked');
    }

    $rowSite = (int) ($row['site_id'] ?? 0);
    if ($rowSite !== $siteId) {
        stock_redirect('pages/stock/stock-list.php?site_id=' . $siteId . '&error=invalid');
    }

    $qtyAbs = round(abs((float) $qtyRaw), 3);
    $delta = $movementType === 'in' ? $qtyAbs : -$qtyAbs;

    $bal = stock_balance_site_product($productId, $siteId, $id);
    if ($bal + $delta < -0.0001) {
        stock_redirect('pages/stock/stock-list.php?site_id=' . $siteId . '&error=insufficient');
    }

    $photoMarker = '';
    if (preg_match('/\s*\[photo\].+$/m', (string) ($row['note'] ?? ''), $mm) === 1) {
        $photoMarker = trim((string) $mm[0]);
    }
    $baseNote = trim((string) preg_replace('/\s*\[photo\].+$/m', '', (string) ($row['note'] ?? '')));
    $finalNote = $noteEsc !== '' ? $noteEsc : $baseNote;
    if ($photoMarker !== '') {
        $finalNote = trim($finalNote . "\n" . ltrim($photoMarker, "\n"));
    }

    Db::setRow('stock_movements', $pk, array_merge($row, [
        'product_id' => $productId,
        'person_name' => mb_substr($personName, 0, 120, 'UTF-8'),
        'qty' => $delta,
        'movement_type' => $movementType,
        'note' => $finalNote,
        'created_at' => stock_combine_datetime($txnDate),
    ]));

    stock_redirect('pages/stock/stock-list.php?site_id=' . $siteId . '&updated=1');
}

if ($action === 'delete_transaction' && $canManage) {
    if (!csrf_verify_request()) {
        stock_redirect('pages/stock/stock-list.php?error=forbidden');
    }
    $id = (int) ($_GET['id'] ?? 0);
    $siteId = (int) ($_GET['site_id'] ?? 0);
    if ($id <= 0) {
        stock_redirect('pages/stock/stock-list.php?site_id=' . $siteId);
    }

    $pk = Db::pkForLogicalId('stock_movements', $id);
    $row = Db::row('stock_movements', $pk);
    if ($row === null) {
        stock_redirect('pages/stock/stock-list.php?site_id=' . $siteId);
    }

    $transferRef = trim((string) ($row['transfer_ref'] ?? ''));
    if ($transferRef !== '') {
        foreach (Db::tableRows('stock_movements') as $m) {
            if (trim((string) ($m['transfer_ref'] ?? '')) !== $transferRef) {
                continue;
            }
            $mid = (int) ($m['id'] ?? 0);
            if ($mid <= 0) {
                continue;
            }
            $mpk = Db::pkForLogicalId('stock_movements', $mid);
            Db::deleteRow('stock_movements', $mpk);
        }
    } else {
        Db::deleteRow('stock_movements', $pk);
    }

    stock_redirect('pages/stock/stock-list.php?site_id=' . $siteId . '&deleted=1');
}

if ($action === 'deactivate' && $canManage) {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        Db::mergeRow('stock_products', (string) $id, ['is_active' => 0]);
    }
    stock_redirect('pages/stock/stock-list.php?deactivated=1');
}

stock_redirect('pages/stock/stock-list.php?error=bad');
