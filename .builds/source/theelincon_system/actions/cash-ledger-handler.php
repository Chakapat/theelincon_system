<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$me = (int) $_SESSION['user_id'];
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$back = app_path('pages/cash-ledger.php');
$action = $_REQUEST['action'] ?? '';

function cash_ledger_redirect(string $base, array $query): void
{
    $q = http_build_query($query);
    header('Location: ' . $base . ($q !== '' ? '?' . $q : ''));
    exit;
}

/**
 * @return list<array{desc:string,unit:string,qty:float,price:float,total:float}>
 */
function cash_ledger_parse_lines(array $post): array
{
    $descs = $post['line_desc'] ?? [];
    $units = $post['line_unit'] ?? [];
    $qtys = $post['line_qty'] ?? [];
    $prices = $post['line_price'] ?? [];
    if (!is_array($descs) || !is_array($qtys) || !is_array($prices)) {
        return [];
    }
    if (!is_array($units)) {
        $units = [];
    }
    $n = max(count($qtys), count($prices), count($descs), count($units));
    $lines = [];
    for ($i = 0; $i < $n; $i++) {
        $d = trim((string) ($descs[$i] ?? ''));
        $u = trim((string) ($units[$i] ?? ''));
        if (strlen($u) > 40) {
            $u = substr($u, 0, 40);
        }
        $q = (float) str_replace(',', '', (string) ($qtys[$i] ?? 0));
        $p = (float) str_replace(',', '', (string) ($prices[$i] ?? 0));
        if ($q == 0.0 && $p == 0.0 && $d === '') {
            continue;
        }
        $lt = round($q * $p, 2);
        $lines[] = ['desc' => $d, 'unit' => $u, 'qty' => $q, 'price' => $p, 'total' => $lt];
    }

    return $lines;
}

function cash_ledger_vat_compute(string $vatMode, float $vatRate, float $lineSum): array
{
    if ($lineSum <= 0) {
        return ['line_subtotal_sum' => 0.0, 'vat_amount' => 0.0, 'amount' => 0.0];
    }
    if ($vatMode === 'exclusive') {
        $sub = $lineSum;
        $vat = round($sub * $vatRate / 100, 2);

        return ['line_subtotal_sum' => $sub, 'vat_amount' => $vat, 'amount' => round($sub + $vat, 2)];
    }
    if ($vatMode === 'inclusive') {
        $amount = $lineSum;
        if ($vatRate > 0) {
            $sub = round($amount / (1 + $vatRate / 100), 2);
            $vat = round($amount - $sub, 2);
        } else {
            $sub = $amount;
            $vat = 0.0;
        }

        return ['line_subtotal_sum' => $sub, 'vat_amount' => $vat, 'amount' => $amount];
    }

    return ['line_subtotal_sum' => $lineSum, 'vat_amount' => 0.0, 'amount' => $lineSum];
}

/**
 * @return array{0: int, 1: string}
 */
function cash_ledger_resolve_store(string $search): array
{
    $t = trim($search);
    if ($t === '') {
        return [0, ''];
    }
    if (strlen($t) > 255) {
        $t = substr($t, 0, 255);
    }
    foreach (Db::tableRows('cash_ledger_stores') as $row) {
        if (empty($row['is_active'])) {
            continue;
        }
        if (trim((string) ($row['name'] ?? '')) === $t) {
            return [(int) ($row['id'] ?? 0), ''];
        }
    }

    return [0, $t];
}

/**
 * @return array{0: int, 1: string}
 */
function cash_ledger_resolve_site(string $search): array
{
    $t = trim($search);
    if ($t === '') {
        return [0, ''];
    }
    if (strlen($t) > 255) {
        $t = substr($t, 0, 255);
    }
    foreach (Db::tableRows('cash_ledger_sites') as $row) {
        if (empty($row['is_active'])) {
            continue;
        }
        if (trim((string) ($row['name'] ?? '')) === $t) {
            return [(int) ($row['id'] ?? 0), ''];
        }
    }

    return [0, $t];
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $retMonth = trim((string) ($_POST['redirect_month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $retMonth)) {
        $retMonth = '';
    }

    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $entryType = $_POST['entry_type'] ?? '';
    if ($entryType !== 'income' && $entryType !== 'expense') {
        cash_ledger_redirect($back, array_filter(['err' => 'invalid_type', 'month' => $retMonth]));
    }
    $entryDate = trim((string) ($_POST['entry_date'] ?? ''));
    if ($entryDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
        cash_ledger_redirect($back, array_filter(['err' => 'date', 'month' => $retMonth]));
    }
    $category = trim((string) ($_POST['category'] ?? ''));
    if (strlen($category) > 120) {
        $category = substr($category, 0, 120);
    }
    $description = trim((string) ($_POST['description'] ?? ''));
    if (strlen($description) > 1000) {
        $description = substr($description, 0, 1000);
    }

    [$storeId, $boughtFrom] = cash_ledger_resolve_store((string) ($_POST['store_search'] ?? ''));
    [$siteId, $usedAtSite] = cash_ledger_resolve_site((string) ($_POST['site_search'] ?? ''));

    $vatMode = $_POST['vat_mode'] ?? 'none';
    if (!in_array($vatMode, ['none', 'exclusive', 'inclusive'], true)) {
        $vatMode = 'none';
    }
    $vatRate = isset($_POST['vat_rate']) ? (float) str_replace(',', '', (string) $_POST['vat_rate']) : 7.0;
    if ($vatRate < 0 || $vatRate > 100) {
        $vatRate = 7.0;
    }

    $lines = cash_ledger_parse_lines($_POST);
    if (count($lines) === 0) {
        cash_ledger_redirect($back, array_filter(['err' => 'need_lines', 'month' => $retMonth]));
    }
    $lineSum = 0.0;
    foreach ($lines as $ln) {
        $lineSum += $ln['total'];
    }
    $lineSum = round($lineSum, 2);
    if ($lineSum <= 0) {
        cash_ledger_redirect($back, array_filter(['err' => 'line_total', 'month' => $retMonth]));
    }

    $vatBlock = cash_ledger_vat_compute($vatMode, $vatRate, $lineSum);
    $amount = $vatBlock['amount'];
    $lineSubtotalSum = $vatBlock['line_subtotal_sum'];
    $vatAmount = $vatBlock['vat_amount'];
    if ($amount <= 0) {
        cash_ledger_redirect($back, array_filter(['err' => 'amount', 'month' => $retMonth]));
    }

    try {
        if ($id > 0) {
            $cur = Db::row('cash_ledger', (string) $id);
            if ($cur === null) {
                cash_ledger_redirect($back, array_filter(['err' => 'notfound', 'month' => $retMonth]));
            }
            if ((int) ($cur['created_by'] ?? 0) !== $me && !$isAdmin) {
                cash_ledger_redirect($back, array_filter(['err' => 'forbidden', 'month' => $retMonth]));
            }

            Db::setRow('cash_ledger', (string) $id, array_merge($cur, [
                'entry_type' => $entryType,
                'amount' => $amount,
                'entry_date' => $entryDate,
                'category' => $category,
                'store_id' => $storeId > 0 ? $storeId : 0,
                'site_id' => $siteId > 0 ? $siteId : 0,
                'bought_from' => $boughtFrom,
                'used_at_site' => $usedAtSite,
                'description' => $description,
                'vat_mode' => $vatMode,
                'vat_rate' => $vatRate,
                'line_subtotal_sum' => $lineSubtotalSum,
                'vat_amount' => $vatAmount,
            ]));
            Db::deleteWhereEquals('cash_ledger_lines', 'ledger_id', (string) $id);
            $ledgerId = $id;
        } else {
            $ledgerId = Db::nextNumericId('cash_ledger', 'id');
            Db::setRow('cash_ledger', (string) $ledgerId, [
                'id' => $ledgerId,
                'entry_type' => $entryType,
                'amount' => $amount,
                'entry_date' => $entryDate,
                'category' => $category,
                'store_id' => $storeId > 0 ? $storeId : 0,
                'site_id' => $siteId > 0 ? $siteId : 0,
                'bought_from' => $boughtFrom,
                'used_at_site' => $usedAtSite,
                'description' => $description,
                'vat_mode' => $vatMode,
                'vat_rate' => $vatRate,
                'line_subtotal_sum' => $lineSubtotalSum,
                'vat_amount' => $vatAmount,
                'created_by' => $me,
            ]);
        }

        $lineNo = 0;
        foreach ($lines as $ln) {
            ++$lineNo;
            $lid = Db::nextNumericId('cash_ledger_lines', 'id');
            $d = substr($ln['desc'], 0, 500);
            $u = substr((string) ($ln['unit'] ?? ''), 0, 40);
            Db::setRow('cash_ledger_lines', (string) $lid, [
                'id' => $lid,
                'ledger_id' => $ledgerId,
                'line_no' => $lineNo,
                'item_description' => $d,
                'quantity' => $ln['qty'],
                'unit' => $u,
                'unit_price' => $ln['price'],
                'line_total' => $ln['total'],
            ]);
        }
    } catch (Throwable $e) {
        cash_ledger_redirect($back, array_filter(['err' => 'save_failed', 'month' => $retMonth]));
    }

    cash_ledger_redirect($back, array_filter(['saved' => '1', 'month' => $retMonth]));
}

if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $retMonth = trim((string) ($_GET['month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $retMonth)) {
        $retMonth = '';
    }
    if ($id <= 0) {
        cash_ledger_redirect($back, array_filter(['month' => $retMonth]));
    }
    $cur = Db::row('cash_ledger', (string) $id);
    if ($cur === null) {
        cash_ledger_redirect($back, array_filter(['err' => 'notfound', 'month' => $retMonth]));
    }
    if ((int) ($cur['created_by'] ?? 0) !== $me && !$isAdmin) {
        cash_ledger_redirect($back, array_filter(['err' => 'forbidden', 'month' => $retMonth]));
    }
    Db::deleteWhereEquals('cash_ledger_lines', 'ledger_id', (string) $id);
    Db::deleteRow('cash_ledger', (string) $id);
    cash_ledger_redirect($back, array_filter(['deleted' => '1', 'month' => $retMonth]));
}

cash_ledger_redirect($back, []);
