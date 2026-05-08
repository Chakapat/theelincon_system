<?php

declare(strict_types=1);


require_once __DIR__ . '/_page_root.php';
use Theelincon\Rtdb\Db;

session_start();
require_once THEELINCON_ROOT . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$print_modes = [
    ['key' => 'original', 'text' => 'ต้นฉบับ / ORIGINAL'],
    ['key' => 'copy', 'text' => 'สำเนา / COPY'],
];
$edit_mode = isset($_GET['edit']) && (string) $_GET['edit'] === '1';

function normalizeInvoiceRef(string $input): string
{
    $clean = strtolower(trim($input));
    if ($clean === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{3}$/', $clean) === 1) {
        return 'inv-tnc-' . $clean;
    }
    if (preg_match('/^tnc-\d{4}-\d{3}$/', $clean) === 1) {
        return 'inv-' . $clean;
    }
    return $clean;
}

function findInvoiceByReference(string $ref): ?array
{
    $target = normalizeInvoiceRef($ref);
    if ($target === '') {
        return null;
    }
    return Db::findFirst('invoices', static function (array $row) use ($target): bool {
        $invoiceNumber = strtolower(trim((string) ($row['invoice_number'] ?? '')));
        return $invoiceNumber === $target;
    });
}

function nextTaxInvoiceNumber(string $seedDate): string
{
    $stamp = date('ym', strtotime($seedDate !== '' ? $seedDate : date('Y-m-d')));
    $prefix = 'tax-inv-tnc-' . $stamp . '-';
    $max = 0;
    foreach (Db::tableRows('tax_invoices') as $row) {
        $num = strtolower((string) ($row['tax_invoice_number'] ?? ''));
        if (strpos($num, $prefix) !== 0) {
            continue;
        }
        $seq = (int) substr($num, strlen($prefix));
        if ($seq > $max) {
            $max = $seq;
        }
    }
    return strtoupper($prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT));
}

function toShortInvoiceRef(string $invoiceNumber): string
{
    if (preg_match('/^inv-tnc-(\d{4}-\d{3})$/i', trim($invoiceNumber), $m) === 1) {
        return strtolower($m[1]);
    }
    return '';
}

function findLatestTaxInvoiceByInvoiceId(int $invoiceId): ?array
{
    if ($invoiceId <= 0) {
        return null;
    }
    $rows = Db::filter('tax_invoices', static function (array $r) use ($invoiceId): bool {
        return isset($r['invoice_id']) && (int) $r['invoice_id'] === $invoiceId;
    });
    if (count($rows) === 0) {
        return null;
    }
    usort($rows, static function (array $a, array $b): int {
        $da = (string) ($a['tax_date'] ?? '');
        $db = (string) ($b['tax_date'] ?? '');
        if ($da !== $db) {
            return strcmp($db, $da); // ล่าสุดก่อน
        }
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0)); // id มากกว่าคือใหม่กว่า
    });
    return $rows[0];
}

function formatDateThai(string $date): string
{
    return date('d/m/Y', strtotime($date));
}

function formatQty($qty): string
{
    $n = (float) $qty;
    if (abs($n) <= 0.000001) {
        return '';
    }
    $s = number_format($n, 2, '.', ',');
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' ? '0' : $s;
}

function formatMoneyOrBlank($amount): string
{
    $n = (float) $amount;
    if (abs($n) <= 0.000001) {
        return '';
    }
    return number_format($n, 2);
}

function money2ByMode(float $value, bool $roundingEnabled): float
{
    if ($roundingEnabled) {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }
    return ((int) ($value * 100)) / 100;
}

function formatMoneyByModeOrBlank($amount, bool $roundingEnabled): string
{
    $n = money2ByMode((float) $amount, $roundingEnabled);
    if (abs($n) <= 0.000001) {
        return '';
    }
    return number_format($n, 2);
}

/** คำนวณรายการจากช่องราคาที่ใส่ได้ทั้งตัวเลขหรือ %สะสม (เหมือน invoice-create) */
function buildTaxItemsFromPostedRows(array $postedDescriptions, array $postedQuantities, array $postedUnits, array $postedPrices, callable $money2): array
{
    $payloads = [];
    $running = 0.0;

    foreach ($postedDescriptions as $idx => $descRaw) {
        $desc = trim((string) $descRaw);
        if ($desc === '') {
            continue;
        }
        $qty = (float) ($postedQuantities[$idx] ?? 0);
        $unit = trim((string) ($postedUnits[$idx] ?? ''));
        $priceRaw = trim((string) ($postedPrices[$idx] ?? ''));

        if ($priceRaw !== '' && str_contains($priceRaw, '%')) {
            $percent = (float) str_replace('%', '', $priceRaw);
            $lineTotal = $money2($running * ($percent / 100.0));
            $finalUnitPrice = $lineTotal;
        } else {
            $finalUnitPrice = (float) $priceRaw;
            $lineTotal = $money2($finalUnitPrice);
        }

        $payloads[] = [
            'description' => $desc,
            'quantity' => $qty,
            'unit' => $unit,
            'unit_price' => $finalUnitPrice,
            'total' => $lineTotal,
        ];
        $running += $lineTotal;
    }

    return dedupeExactItems($payloads);
}

function parseRetentionRaw(string $rawValue, float $subtotal): float
{
    $raw = trim($rawValue);
    if ($raw === '') {
        return 0.0;
    }
    if (str_contains($raw, '%')) {
        $percent = (float) str_replace('%', '', $raw);
        return $subtotal * ($percent / 100.0);
    }
    return (float) $raw;
}

/**
 * ลบรายการที่ซ้ำกันแบบทั้งแถว (description/qty/unit/price/total เหมือนกันทุกค่า)
 * เพื่อกันกรณีข้อมูลซ้ำจากต้นทาง/บันทึกซ้ำ
 */
function dedupeExactItems(array $rows): array
{
    $seen = [];
    $result = [];
    foreach ($rows as $row) {
        $desc = trim((string) ($row['description'] ?? ''));
        $qty = (float) ($row['quantity'] ?? 0);
        $unit = trim((string) ($row['unit'] ?? ''));
        $unitPrice = (float) ($row['unit_price'] ?? 0);
        $total = (float) ($row['total'] ?? 0);
        $key = json_encode([
            $desc,
            round($qty, 6),
            $unit,
            round($unitPrice, 6),
            round($total, 6),
        ], JSON_UNESCAPED_UNICODE);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $result[] = [
            'description' => $desc,
            'quantity' => $qty,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'total' => $total,
        ];
    }
    return $result;
}

/** คงรายการเดิมไว้ (ไม่ซ่อนทั้งแถว) */
function removeZeroQuantityItems(array $rows): array
{
    return $rows;
}

$input_ref = trim((string) ($_POST['invoice_ref'] ?? $_GET['ref'] ?? ''));
$message = '';
$error = '';

$isCreateSubmit = isset($_POST['create_tax_invoice']);
$isUpdateSubmit = isset($_POST['update_tax_invoice']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($isCreateSubmit || $isUpdateSubmit)) {
    if (!csrf_verify_request()) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }

    $input_ref = trim((string) ($_POST['invoice_ref'] ?? ''));
    $targetInv = findInvoiceByReference($input_ref);
    if ($targetInv === null) {
        $error = 'ไม่พบเลข Invoice ที่อ้างอิง';
    } else {
        $targetId = (int) ($targetInv['id'] ?? 0);
        $exists = findLatestTaxInvoiceByInvoiceId($targetId);

        if ($exists !== null && !$isUpdateSubmit) {
            $id = $targetId;
            $message = 'Invoice นี้มี Tax Invoice แล้ว';
        } else {
            $companyId = (int) ($_POST['company_id'] ?? 0);
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $taxDateRaw = trim((string) ($_POST['tax_date'] ?? ''));
            $issueDateForSeq = $taxDateRaw !== '' ? $taxDateRaw : (string) ($targetInv['issue_date'] ?? date('Y-m-d'));
            $taxNumber = nextTaxInvoiceNumber($issueDateForSeq);
            $taxDateSave = $taxDateRaw !== '' ? $taxDateRaw : date('Y-m-d');
            $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
            $allowedPaymentMethods = ['cash', 'transfer', 'cheque'];
            if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
                $paymentMethod = 'transfer';
            }
            $vatEnabled = isset($_POST['vat_enabled']);
            $withholdingEnabled = isset($_POST['withholding_enabled']);
            $roundingEnabled = (string) ($_POST['rounding_enabled'] ?? '0') === '1';
            $money2 = static function (float $value) use ($roundingEnabled): float {
                if ($roundingEnabled) {
                    return round($value, 2, PHP_ROUND_HALF_UP);
                }
                return ((int) ($value * 100)) / 100;
            };
            $retentionRaw = (string) ($_POST['retention_amount'] ?? '0');
            $subtotal = 0.0;
            $postedDescriptions = $_POST['description'] ?? [];
            $postedQuantities = $_POST['quantity'] ?? [];
            $postedUnits = $_POST['unit'] ?? [];
            $postedPrices = $_POST['price'] ?? [];
            $itemPayloads = buildTaxItemsFromPostedRows($postedDescriptions, $postedQuantities, $postedUnits, $postedPrices, $money2);
            foreach ($itemPayloads as $row) {
                $subtotal += (float) ($row['total'] ?? 0);
            }
            $subtotal = $money2($subtotal);

            if (count($itemPayloads) === 0) {
                $sourceItems = Db::filter('invoice_items', static function (array $r) use ($targetId): bool {
                    return isset($r['invoice_id']) && (int) $r['invoice_id'] === $targetId;
                });
                Db::sortRows($sourceItems, 'id', false);
                foreach ($sourceItems as $srcItem) {
                    $qty = (float) ($srcItem['quantity'] ?? 0);
                    $unitPrice = (float) ($srcItem['unit_price'] ?? 0);
                    $lineTotal = (float) ($srcItem['total'] ?? $money2($unitPrice));
                    $subtotal += $lineTotal;
                    $itemPayloads[] = [
                        'description' => (string) ($srcItem['description'] ?? ''),
                        'quantity' => $qty,
                        'unit' => (string) ($srcItem['unit'] ?? ''),
                        'unit_price' => $unitPrice,
                        'total' => $lineTotal,
                    ];
                }
                $subtotal = $money2($subtotal);
            }

            $retentionRawValue = parseRetentionRaw($retentionRaw, $subtotal);
            $retentionAmount = $money2($retentionRawValue);
            $vatRaw = $vatEnabled ? ($subtotal * 0.07) : 0.0;
            $withholdingRaw = $withholdingEnabled ? ($subtotal * 0.03) : 0.0;
            $vatAmount = $money2($vatRaw);
            $withholdingAmount = $money2($withholdingRaw);
            $grandTotal = $roundingEnabled
                ? $money2($subtotal + $vatAmount - $withholdingAmount - $retentionAmount)
                : $money2($subtotal + $vatRaw - $withholdingRaw - $retentionRawValue);

            try {
                $isUpdate = $exists !== null && $isUpdateSubmit;
                if ($isUpdate) {
                    $tid = (int) ($exists['id'] ?? 0);
                    $curTax = Db::row('tax_invoices', (string) $tid) ?? [];
                    Db::setRow('tax_invoices', (string) $tid, array_merge($curTax, [
                        'id' => $tid,
                        'invoice_id' => $targetId,
                        'tax_date' => $taxDateSave,
                        'company_id' => $companyId > 0 ? $companyId : (int) ($targetInv['company_id'] ?? 0),
                        'customer_id' => $customerId > 0 ? $customerId : (int) ($targetInv['customer_id'] ?? 0),
                        'subtotal' => $subtotal,
                        'vat_amount' => $vatAmount,
                        'withholding_tax' => $withholdingAmount,
                        'retention_amount' => $retentionAmount,
                        'grand_total' => $grandTotal,
                        'rounding_enabled' => $roundingEnabled ? 1 : 0,
                        'payment_method' => $paymentMethod,
                    ]));
                    Db::deleteWhereEquals('tax_invoice_items', 'tax_invoice_id', (string) $tid);
                } else {
                    $tid = Db::nextNumericId('tax_invoices', 'id');
                    Db::setRow('tax_invoices', (string) $tid, [
                        'id' => $tid,
                        'invoice_id' => $targetId,
                        'tax_invoice_number' => $taxNumber,
                        'tax_date' => $taxDateSave,
                        'company_id' => $companyId > 0 ? $companyId : (int) ($targetInv['company_id'] ?? 0),
                        'customer_id' => $customerId > 0 ? $customerId : (int) ($targetInv['customer_id'] ?? 0),
                        'subtotal' => $subtotal,
                        'vat_amount' => $vatAmount,
                        'withholding_tax' => $withholdingAmount,
                        'retention_amount' => $retentionAmount,
                        'grand_total' => $grandTotal,
                        'rounding_enabled' => $roundingEnabled ? 1 : 0,
                        'payment_method' => $paymentMethod,
                    ]);
                }

                foreach ($itemPayloads as $itemRow) {
                    $taxItemId = Db::nextNumericId('tax_invoice_items', 'id');
                    Db::setRow('tax_invoice_items', (string) $taxItemId, [
                        'id' => $taxItemId,
                        'tax_invoice_id' => $tid,
                        'description' => $itemRow['description'],
                        'quantity' => $itemRow['quantity'],
                        'unit' => $itemRow['unit'],
                        'unit_price' => $itemRow['unit_price'],
                        'total' => $itemRow['total'],
                    ]);
                }
            } catch (Throwable $e) {
                $error = 'บันทึก Tax INV ลง Firebase ไม่สำเร็จ: ' . $e->getMessage();
            }

            if ($error === '') {
                if ($exists !== null && $isUpdateSubmit) {
                    $q = http_build_query(['id' => $targetId, 'updated' => '1'], '', '&', PHP_QUERY_RFC3986);
                    header('Location: ' . app_path('pages/tax-invoice-receipt.php') . '?' . $q);
                } else {
                    $q = http_build_query(['created' => '1', 'tax_no' => $taxNumber], '', '&', PHP_QUERY_RFC3986);
                    header('Location: ' . app_path('pages/tax-invoice-list.php') . '?' . $q);
                }
                exit;
            }
        }
    }
}

if ($id <= 0 && $input_ref !== '') {
    $invFromRef = findInvoiceByReference($input_ref);
    if ($invFromRef !== null) {
        $id = (int) ($invFromRef['id'] ?? 0);
    } else {
        $error = 'ไม่พบเลข Invoice ที่ค้นหา';
    }
}

$autocompleteOptions = [];
$invoiceSearchOptions = [];
$inv = $id > 0 ? Db::row('invoices', (string) $id) : null;
if (!$inv) {
    $allInvoices = Db::tableRows('invoices');
    Db::sortRows($allInvoices, 'issue_date', true);
    $customersMap = [];
    foreach (Db::tableRows('customers') as $cRow) {
        $cid = (int) ($cRow['id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $customersMap[$cid] = (string) ($cRow['name'] ?? '');
    }
    foreach ($allInvoices as $invRow) {
        $invId = (int) ($invRow['id'] ?? 0);
        $fullNumber = strtolower(trim((string) ($invRow['invoice_number'] ?? '')));
        if ($fullNumber === '' || $invId <= 0) {
            continue;
        }
        $autocompleteOptions[] = $fullNumber;
        $shortNumber = toShortInvoiceRef($fullNumber);
        if ($shortNumber !== '') {
            $autocompleteOptions[] = $shortNumber;
        }
        $custName = trim((string) ($customersMap[(int) ($invRow['customer_id'] ?? 0)] ?? ''));
        $issueDateText = trim((string) ($invRow['issue_date'] ?? ''));
        $invoiceSearchOptions[] = [
            'id' => $invId,
            'invoice_number' => strtoupper($fullNumber),
            'customer_name' => $custName,
            'issue_date' => $issueDateText,
            'search_ref' => $shortNumber !== '' ? $shortNumber : $fullNumber,
        ];
    }
    $autocompleteOptions = array_values(array_unique($autocompleteOptions));

    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <title>สร้าง Tax Invoice</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        <style>body{background:#f8f9fa;font-family:'Sarabun',sans-serif;}</style>
    </head>
    <body>
    <div class="container py-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h4 class="fw-bold mb-3">สร้าง Tax Invoice จาก Invoice อ้างอิง</h4>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <form method="get" class="row g-2">
                    <div class="col-12 col-md-8">
                        <input type="text" name="ref" class="form-control invoice-ref-input" list="invoice_ref_list" autocomplete="off" placeholder="เช่น 0426-001" required>
                        <datalist id="invoice_ref_list">
                            <?php foreach ($autocompleteOptions as $refOpt): ?>
                                <option value="<?= htmlspecialchars($refOpt, ENT_QUOTES, 'UTF-8') ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <div class="autocomplete-list list-group position-absolute w-100 mt-1 shadow-sm" style="z-index: 1050; display: none;"></div>
                    </div>
                    <div class="col-12 col-md-4 d-grid">
                        <button type="submit" class="btn btn-primary">ค้นหารายละเอียด Invoice</button>
                    </div>
                    <div class="col-12"><div class="text-muted small mt-1">พิมพ์เลข Invoice หรือชื่อลูกค้า เพื่อค้นหาและเลือกจากรายการที่เด้งลงมา</div></div>
                </form>
                <div class="mt-3">
                    <a href="<?= htmlspecialchars(app_path('index.php')) ?>" class="btn btn-outline-secondary btn-sm">กลับหน้าหลัก</a>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function () {
        const allRefs = <?= json_encode($autocompleteOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const invoiceSearchOptions = <?= json_encode($invoiceSearchOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const MAX_ITEMS = 8;

        function renderSuggestions(input, list) {
            const raw = (input.value || '').trim().toLowerCase();
            if (!raw) {
                list.style.display = 'none';
                list.innerHTML = '';
                return;
            }
            const tokenResults = allRefs
                .filter(ref => ref.includes(raw))
                .map(ref => ({ text: ref, value: ref }));

            const richResults = invoiceSearchOptions
                .filter(row => {
                    const label = (row.invoice_number + ' ' + (row.customer_name || '') + ' ' + (row.issue_date || '')).toLowerCase();
                    return label.includes(raw);
                })
                .map(row => {
                    let text = row.invoice_number;
                    if (row.customer_name) text += ' | ' + row.customer_name;
                    if (row.issue_date) text += ' | ' + row.issue_date;
                    return { text, value: row.search_ref };
                });

            const mergedMap = new Map();
            [...richResults, ...tokenResults].forEach(item => {
                const key = item.text + '|' + item.value;
                if (!mergedMap.has(key)) mergedMap.set(key, item);
            });
            const results = Array.from(mergedMap.values()).slice(0, MAX_ITEMS);
            if (results.length === 0) {
                list.style.display = 'none';
                list.innerHTML = '';
                return;
            }
            list.innerHTML = results.map(item => (
                '<button type="button" class="list-group-item list-group-item-action py-2" data-value="' + String(item.value).replace(/"/g, '&quot;') + '">' + item.text + '</button>'
            )).join('');
            list.style.display = 'block';
            list.querySelectorAll('button').forEach(btn => {
                btn.addEventListener('click', () => {
                    input.value = btn.getAttribute('data-value') || '';
                    list.style.display = 'none';
                    list.innerHTML = '';
                    input.focus();
                });
            });
        }

        document.querySelectorAll('.invoice-ref-input').forEach(input => {
            const list = input.parentElement ? input.parentElement.querySelector('.autocomplete-list') : null;
            if (!list) return;
            input.addEventListener('input', () => renderSuggestions(input, list));
            input.addEventListener('blur', () => {
                setTimeout(() => {
                    list.style.display = 'none';
                    list.innerHTML = '';
                }, 120);
            });
        });
    })();
    </script>
    </body>
    </html>
    <?php
    exit;
}

$creator = Db::row('users', (string) ($inv['created_by'] ?? ''));
$tax = findLatestTaxInvoiceByInvoiceId($id);

$invoice_number = (string) ($inv['invoice_number'] ?? '');
$has_tax_invoice = $tax !== null && trim((string) ($tax['tax_invoice_number'] ?? '')) !== '';

$taxId = (int) ($tax['id'] ?? 0);
$draftItems = [];
if ($taxId > 0) {
    $draftItems = Db::filter('tax_invoice_items', static function (array $r) use ($taxId): bool {
        return isset($r['tax_invoice_id']) && (int) $r['tax_invoice_id'] === $taxId;
    });
}
if (count($draftItems) === 0) {
    $draftItems = Db::filter('invoice_items', static function (array $r) use ($id): bool {
        return isset($r['invoice_id']) && (int) $r['invoice_id'] === $id;
    });
}
Db::sortRows($draftItems, 'id', false);
$draftItems = dedupeExactItems($draftItems);
$draftItems = removeZeroQuantityItems($draftItems);
if (!$has_tax_invoice && count($draftItems) === 0) {
    $draftItems[] = ['description' => '', 'quantity' => 1, 'unit' => '', 'unit_price' => 0, 'total' => 0];
}

if (!$has_tax_invoice || $edit_mode) {
    $companies = Db::tableRows('company');
    Db::sortRows($companies, 'id', false);
    $customers = Db::tableRows('customers');
    Db::sortRows($customers, 'name', false);
    $creatorDraft = Db::row('users', (string) ($inv['created_by'] ?? ''));
    $creatorDraftName = trim(($creatorDraft['fname'] ?? '') . ' ' . ($creatorDraft['lname'] ?? ''));
    ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สร้าง Tax Invoice - <?= htmlspecialchars($invoice_number, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .border-orange { border-left: 5px solid #FF6600 !important; }
        .btn-orange { background: linear-gradient(135deg, #FF9966 0%, #FF6600 100%); color: white; border: none; border-radius: 10px; font-weight: 600; padding: 10px 25px; }
        .btn-orange:hover { opacity: 0.9; color: white; }
        .readonly-grand-total {
            font-size: 2.2rem; font-weight: bold; color: #FF6600;
            border: none; background-color: transparent !important;
            text-align: right; width: 100%; padding: 10px; outline: none;
        }
        .total-box { background: #fff; border-radius: 15px; padding: 25px; border: 1px solid #eee; }
        .remove-row { color: #dc3545; cursor: pointer; font-size: 1.3rem; transition: 0.2s; }
        .remove-row:hover { transform: scale(1.2); }
    </style>
</head>
<body>
<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="bi bi-file-earmark-break text-success"></i> <?= $has_tax_invoice ? 'แก้ไข Tax Invoice' : 'สร้าง Tax Invoice' ?></h3>
        <a href="<?= htmlspecialchars($has_tax_invoice ? (app_path('pages/tax-invoice-receipt.php') . '?id=' . $id) : app_path('index.php')) ?>" class="btn btn-outline-secondary rounded-pill">กลับ</a>
    </div>

    <form method="post" id="taxDraftForm">
        <?php csrf_field(); ?>
        <input type="hidden" name="invoice_ref" value="<?= htmlspecialchars($invoice_number, ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($has_tax_invoice): ?>
            <input type="hidden" name="update_tax_invoice" value="1">
        <?php else: ?>
            <input type="hidden" name="create_tax_invoice" value="1">
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="card h-100 border-orange p-4 shadow-sm">
                    <label class="form-label fw-bold">ผู้ออกเอกสาร (บริษัท)</label>
                    <select name="company_id" class="form-select mb-3 shadow-sm" required>
                        <?php foreach ($companies as $com): ?>
                            <option value="<?= (int) $com['id'] ?>" <?= ((int) $com['id'] === (int) ($inv['company_id'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars((string) $com['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="form-label fw-bold text-muted small">เลขที่ Invoice อ้างอิง</label>
                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($invoice_number, ENT_QUOTES, 'UTF-8') ?>" readonly>
                    <p class="small text-muted mt-2 mb-0">ผู้ออก Invoice (ตามระบบ): <?= htmlspecialchars($creatorDraftName !== '' ? $creatorDraftName : 'ไม่ระบุ', ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100 border-orange p-4 shadow-sm">
                    <label class="form-label fw-bold">ลูกค้า</label>
                    <select name="customer_id" class="form-select mb-3 shadow-sm" required>
                        <?php foreach ($customers as $cus): ?>
                            <option value="<?= (int) $cus['id'] ?>" <?= ((int) $cus['id'] === (int) ($inv['customer_id'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars((string) $cus['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label small fw-bold">วันที่ออก Tax INV</label>
                            <input type="date" name="tax_date" class="form-control" value="<?= htmlspecialchars((string) ($tax['tax_date'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-4 border-orange shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <span class="fw-bold" style="color: #FF6600;"><i class="bi bi-list-task me-2"></i>รายการ (แก้ไขก่อนบันทึก Tax INV)</span>
                <button type="button" class="btn btn-success btn-sm rounded-pill px-3" onclick="addRow()"><i class="bi bi-plus"></i> เพิ่มรายการ</button>
            </div>
            <div class="table-responsive">
                <table class="table align-middle" id="items_table">
                    <thead>
                        <tr class="small text-muted">
                            <th width="40%" class="ps-4">รายละเอียด</th>
                            <th width="10%" class="text-center">จำนวน</th>
                            <th width="10%" class="text-center">หน่วย</th>
                            <th width="15%" class="text-end">ราคา/หน่วย</th>
                            <th width="15%" class="text-end pe-4">ยอดรวม</th>
                            <th width="5%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($draftItems as $item): ?>
                        <tr>
                            <td class="ps-4"><input type="text" name="description[]" class="form-control" value="<?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></td>
                            <td><input type="number" name="quantity[]" class="form-control qty text-center" value="<?= htmlspecialchars((string) ($item['quantity'] ?? 1), ENT_QUOTES, 'UTF-8') ?>" step="0.01"></td>
                            <td><input type="text" name="unit[]" class="form-control text-center" value="<?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input type="text" name="price[]" class="form-control price text-end" value="<?= ((float) ($item['unit_price'] ?? 0) != 0.0) ? htmlspecialchars((string) ($item['unit_price'] ?? ''), ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="เช่น 100 หรือ 10%"></td>
                            <td><input type="number" name="total[]" class="form-control total text-end fw-bold bg-light" value="<?= htmlspecialchars((string) ($item['total'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" readonly></td>
                            <td class="text-center"><i class="bi bi-trash-fill remove-row remove"></i></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row mt-4 g-4">
            <div class="col-md-6">
                <div class="card p-4 border-orange h-100 shadow-sm">
                    <h6 class="fw-bold mb-3">การตั้งค่าภาษีและเงินหัก</h6>
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" name="vat_enabled" class="form-check-input" id="vatCheck" <?= ((float) (($tax['vat_amount'] ?? $inv['vat_amount'] ?? 0)) > 0) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold text-primary" for="vatCheck">บวกภาษีมูลค่าเพิ่ม VAT 7% (+)</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" name="withholding_enabled" class="form-check-input" id="whtCheck" <?= ((float) (($tax['withholding_tax'] ?? $inv['withholding_tax'] ?? 0)) > 0) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold text-danger" for="whtCheck">หัก ณ ที่จ่าย 3% (-) <span class="text-muted small fw-normal">(คิดจากยอดก่อน VAT)</span></label>
                    </div>
                    <hr>
                    <label class="form-label text-danger fw-bold">หักประกันผลงาน Retention (บาท หรือ %)</label>
                    <input type="text" name="retention_amount" id="retentionInput" class="form-control shadow-sm" value="<?= htmlspecialchars((string) (($tax['retention_amount'] ?? $inv['retention_amount'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>" placeholder="เช่น 500 หรือ 5%">
                    <?php $roundingEnabledDraft = (($tax['rounding_enabled'] ?? $inv['rounding_enabled'] ?? 0) === 1 || (string) ($tax['rounding_enabled'] ?? $inv['rounding_enabled'] ?? '0') === '1'); ?>
                    <div class="form-check form-switch mt-3">
                        <input type="hidden" name="rounding_enabled" id="roundingEnabledInput" value="<?= $roundingEnabledDraft ? '1' : '0' ?>">
                        <input type="checkbox" class="form-check-input" id="roundingCheck" <?= $roundingEnabledDraft ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold text-secondary" for="roundingCheck">ปัดเศษทศนิยม (หลักตัวที่ 3 ตั้งแต่ 5 ขึ้นไป)</label>
                    </div>
                    <?php $pmDraft = trim((string) ($tax['payment_method'] ?? 'transfer')); ?>
                    <hr>
                    <label class="form-label fw-bold">วิธีชำระเงิน</label>
                    <div class="d-flex flex-wrap gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="payment_method" id="pay_cash" value="cash" <?= $pmDraft === 'cash' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="pay_cash">เงินสด</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="payment_method" id="pay_transfer" value="transfer" <?= ($pmDraft === '' || $pmDraft === 'transfer') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="pay_transfer">เงินโอน</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="payment_method" id="pay_cheque" value="cheque" <?= $pmDraft === 'cheque' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="pay_cheque">เช็คธนาคาร (Cheque)</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="total-box shadow-sm border-0">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">ยอดรวม (Subtotal):</span>
                        <span id="subtotal_text" class="fw-bold text-dark">0.00</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 text-primary">
                        <span>ภาษีมูลค่าเพิ่ม VAT 7% (+):</span>
                        <span id="vat_text" class="fw-bold">0.00</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2 mb-2">
                        <span class="fw-bold text-muted">ยอดรวม VAT:</span>
                        <span id="total_after_vat_text" class="fw-bold text-dark">0.00</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 text-danger">
                        <span>หัก ณ ที่จ่าย 3% (-) <small class="text-muted fw-normal">(คิดจากยอดก่อน VAT)</small></span>
                        <span id="wht_text" class="fw-bold">0.00</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2" style="padding-left: 10px; border-left: 3px solid #dc3545;">
                        <span class="small text-muted fw-bold">ยอดรวมหลังหัก ณ ที่จ่าย:</span>
                        <span id="after_wht_text" class="fw-bold text-dark">0.00</span>
                    </div>
                    <hr class="my-2">
                    <div id="retention_summary_row" class="d-flex justify-content-between mb-2 text-danger" style="display: none;">
                        <span>หักประกันผลงาน Retention (-):</span>
                        <span id="retention_display" class="fw-bold">0.00</span>
                    </div>
                    <hr class="my-3" style="border-top: 2px solid #FF6600;">
                    <div class="total-container">
                        <label class="form-label fw-bold text-dark small mb-0">ยอดสุทธิ (ประมาณการ)</label>
                        <div id="grand_total_display" class="readonly-grand-total">0.00</div>
                    </div>
                    <button type="button" onclick="confirmSaveTax()" class="btn btn-orange w-100 py-3 shadow mt-3">
                        <i class="bi bi-save2 me-2"></i> <?= $has_tax_invoice ? 'บันทึกการแก้ไข Tax INV' : 'บันทึกเป็น Tax INV' ?>
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
function calculate(){
    const roundingEnabled = document.getElementById("roundingCheck")?.checked ?? false;
    const money2 = (v) => {
        const n = Number(v) || 0;
        if (roundingEnabled) {
            return Math.round((n + Number.EPSILON) * 100) / 100;
        }
        return Math.trunc(n * 100) / 100;
    };

    let subtotal = 0;
    let running = 0;
    document.querySelectorAll("#items_table tbody tr").forEach(row => {
        let qty = parseFloat(row.querySelector(".qty").value) || 0;
        let pIn = row.querySelector(".price").value.trim();
        let rowTotal;
        if (pIn.includes('%')) {
            let pct = parseFloat(pIn.replace('%', '')) || 0;
            rowTotal = money2(running * (pct / 100));
        } else {
            let price = parseFloat(pIn) || 0;
            rowTotal = money2(price);
        }
        row.querySelector(".total").value = rowTotal.toFixed(2);
        subtotal += rowTotal;
        running += rowTotal;
    });
    subtotal = money2(subtotal);

    const opt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };

    let vatRaw = document.getElementById("vatCheck").checked ? subtotal * 0.07 : 0;
    let vat = money2(vatRaw);
    let totalAfterVat = money2(subtotal + vat);
    let whtRaw = document.getElementById("whtCheck").checked ? subtotal * 0.03 : 0;
    let wht = money2(whtRaw);
    let afterWht = money2(totalAfterVat - wht);

    let retInput = (document.getElementById("retentionInput").value || "").trim();
    let ret = 0;
    let retRaw = 0;
    if (retInput.includes('%')) {
        const pct = parseFloat(retInput.replace('%', '')) || 0;
        retRaw = subtotal * (pct / 100);
        ret = money2(retRaw);
    } else {
        retRaw = parseFloat(retInput) || 0;
        ret = money2(retRaw);
    }
    let grandRaw = roundingEnabled
        ? (subtotal + vat - wht - ret)
        : (subtotal + vatRaw - whtRaw - retRaw);
    let grand = money2(grandRaw);

    document.getElementById("subtotal_text").innerText = subtotal.toLocaleString('th-TH', opt);
    document.getElementById("vat_text").innerText = "+ " + vat.toLocaleString('th-TH', opt);
    document.getElementById("total_after_vat_text").innerText = totalAfterVat.toLocaleString('th-TH', opt);
    document.getElementById("wht_text").innerText = "- " + wht.toLocaleString('th-TH', opt);
    document.getElementById("after_wht_text").innerText = afterWht.toLocaleString('th-TH', opt);
    document.getElementById("retention_display").innerText = "- " + ret.toLocaleString('th-TH', opt);
    const retRow = document.getElementById("retention_summary_row");
    if (retRow) retRow.style.display = ret > 0 ? "flex" : "none";

    document.getElementById("grand_total_display").innerText = grand.toLocaleString('th-TH', opt);
}

function addRow(){
    const table = document.querySelector("#items_table tbody");
    const firstRow = table.querySelector("tr");
    if (firstRow) {
        const newRow = firstRow.cloneNode(true);
        newRow.querySelectorAll("input").forEach(i => {
            if (i.classList.contains('qty')) i.value = "1";
            else if (i.classList.contains('total')) i.value = "0.00";
            else if (i.classList.contains('price')) i.value = "";
            else i.value = "";
        });
        table.appendChild(newRow);
        calculate();
    }
}

document.addEventListener("click", e => {
    if(e.target.closest(".remove")){
        const rows = document.querySelectorAll("#items_table tbody tr");
        if(rows.length > 1) {
            e.target.closest("tr").remove();
            calculate();
        }
    }
});

document.getElementById("taxDraftForm").addEventListener("input", calculate);
document.getElementById("taxDraftForm").addEventListener("change", calculate);
const roundingCheck = document.getElementById("roundingCheck");
const roundingEnabledInput = document.getElementById("roundingEnabledInput");
const syncRoundingFlag = () => {
    if (roundingEnabledInput && roundingCheck) {
        roundingEnabledInput.value = roundingCheck.checked ? "1" : "0";
    }
};
if (roundingCheck) {
    roundingCheck.addEventListener("change", () => {
        syncRoundingFlag();
        calculate();
        <?php if ($has_tax_invoice): ?>
        document.getElementById("taxDraftForm").submit();
        <?php endif; ?>
    });
}
document.getElementById("taxDraftForm").addEventListener("submit", syncRoundingFlag);

async function confirmSaveTax() {
    const result = await Swal.fire({
        title: <?= json_encode($has_tax_invoice ? 'บันทึกการแก้ไข Tax INV?' : 'บันทึกเป็น Tax INV?', JSON_UNESCAPED_UNICODE) ?>,
        text: <?= json_encode($has_tax_invoice ? 'ระบบจะอัปเดตรายการ Tax Invoice นี้ตามข้อมูลล่าสุด' : 'ระบบจะสร้างเลข Tax Invoice และเก็บรายการแยกจาก Invoice ต้นทาง', JSON_UNESCAPED_UNICODE) ?>,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#FF6600',
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก'
    });
    if (result.isConfirmed) {
        document.getElementById('taxDraftForm').submit();
    }
}

window.onload = calculate;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
    exit;
}

$custIdForDisplay = (int) ($tax['customer_id'] ?? 0);
if ($custIdForDisplay <= 0) {
    $custIdForDisplay = (int) ($inv['customer_id'] ?? 0);
}
$comIdForDisplay = (int) ($tax['company_id'] ?? 0);
if ($comIdForDisplay <= 0) {
    $comIdForDisplay = (int) ($inv['company_id'] ?? 0);
}

$cust = Db::row('customers', (string) $custIdForDisplay);
$com = Db::row('company', (string) $comIdForDisplay);

$data = $inv;
$data['customer_name'] = $cust['name'] ?? '';
$data['customer_address'] = $cust['address'] ?? '';
$data['customer_tax'] = $cust['tax_id'] ?? '';
$data['customer_phone'] = $cust['phone'] ?? '';
$data['tax_invoice_number'] = $tax['tax_invoice_number'] ?? '';
foreach (['name', 'logo', 'address', 'phone', 'tax_id', 'bank_name', 'bank_account_name', 'bank_account_number'] as $ck) {
    $data[$ck] = $com[$ck] ?? '';
}

$issuer_display = trim(($creator['fname'] ?? '') . ' ' . ($creator['lname'] ?? ''));
$issuer_display = $issuer_display !== '' ? htmlspecialchars($issuer_display, ENT_QUOTES, 'UTF-8') : 'ไม่ระบุ';

$tax_invoice_number = strtoupper(trim((string) ($data['tax_invoice_number'] ?? '')));
$has_tax_invoice = $tax_invoice_number !== '';
$paymentMethod = trim((string) ($tax['payment_method'] ?? ''));
$roundingEnabledView = (($tax['rounding_enabled'] ?? $inv['rounding_enabled'] ?? 0) === 1 || (string) ($tax['rounding_enabled'] ?? $inv['rounding_enabled'] ?? '0') === '1');
$pmCashMark = $paymentMethod === 'cash' ? '☑' : '☐';
$pmTransferMark = $paymentMethod === 'transfer' ? '☑' : '☐';
$pmChequeMark = $paymentMethod === 'cheque' ? '☑' : '☐';

$taxId = (int) ($tax['id'] ?? 0);
$items = [];
if ($taxId > 0) {
    $items = Db::filter('tax_invoice_items', static function (array $r) use ($taxId): bool {
        return isset($r['tax_invoice_id']) && (int) $r['tax_invoice_id'] === $taxId;
    });
}
if (count($items) === 0) {
    $items = Db::filter('invoice_items', static function (array $r) use ($id): bool {
        return isset($r['invoice_id']) && (int) $r['invoice_id'] === $id;
    });
}
Db::sortRows($items, 'id', false);
$items = dedupeExactItems($items);
$items = removeZeroQuantityItems($items);

$subtotal = (float) (($tax['subtotal'] ?? '') !== '' ? $tax['subtotal'] : $data['subtotal']);
$vat = (float) (($tax['vat_amount'] ?? '') !== '' ? $tax['vat_amount'] : $data['vat_amount']);
$wht = (float) (($tax['withholding_tax'] ?? '') !== '' ? $tax['withholding_tax'] : $data['withholding_tax']);
$retention = (float) (($tax['retention_amount'] ?? '') !== '' ? $tax['retention_amount'] : $data['retention_amount']);
$storedGrandTotal = (float) (($tax['grand_total'] ?? '') !== '' ? $tax['grand_total'] : ($data['total_amount'] ?? 0));
$subtotal = money2ByMode($subtotal, $roundingEnabledView);
$vat = money2ByMode($vat, $roundingEnabledView);
$wht = money2ByMode($wht, $roundingEnabledView);
$retention = money2ByMode($retention, $roundingEnabledView);
$total_after_vat = money2ByMode($subtotal + $vat, $roundingEnabledView);
$after_wht = money2ByMode($total_after_vat - $wht, $roundingEnabledView);
$final_grand_total = money2ByMode($storedGrandTotal, $roundingEnabledView);

$displayIssueDate = (string) ($inv['issue_date'] ?? '');
if (!empty($tax['tax_date'])) {
    $displayIssueDate = (string) $tax['tax_date'];
}

/** แสดงที่อยู่เป็นบรรทัดเดียว (ยุบ newline ในข้อมูล) */
$company_address_one_line = preg_replace('/\s+/u', ' ', trim(str_replace(["\r\n", "\r", "\n"], ' ', (string) ($data['address'] ?? ''))));
$customer_address_one_line = preg_replace('/\s+/u', ' ', trim(str_replace(["\r\n", "\r", "\n"], ' ', (string) ($data['customer_address'] ?? ''))));
$company_contact_bits = array_filter([
    trim((string) ($data['phone'] ?? '')) !== '' ? 'โทร: ' . trim((string) $data['phone']) : '',
    trim((string) ($data['tax_id'] ?? '')) !== '' ? 'Tax ID: ' . trim((string) $data['tax_id']) : '',
], static fn (string $s): bool => $s !== '');
$company_detail_line = $company_address_one_line;
if ($company_detail_line !== '' && count($company_contact_bits) > 0) {
    $company_detail_line .= ' | ' . implode(' | ', $company_contact_bits);
} elseif ($company_detail_line === '' && count($company_contact_bits) > 0) {
    $company_detail_line = implode(' | ', $company_contact_bits);
}
$customer_tax_trim = trim((string) ($data['customer_tax'] ?? ''));
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Receipt/Tax Invoice - <?= htmlspecialchars($invoice_number); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/document-print.css')) ?>">
    
    <style>
        :root { --orange: #FF6600; --dark: #333; }
        body { font-family: 'Sarabun', 'Leelawadee UI', 'Segoe UI', Tahoma, sans-serif; background: #f4f4f4; color: var(--dark); margin: 0; padding: 0; font-weight: 500; }
        
        .invoice-box { 
            width: 210mm; height: 297mm; margin: 0 auto; background: #fff; padding: 10mm 15mm; 
            position: relative; box-shadow: 0 5px 20px rgba(0,0,0,0.05); border-top: 8px solid var(--orange); overflow: hidden;
        }
        .invoice-sheet { margin-bottom: 12px; }
        .invoice-sheet:last-child { margin-bottom: 0; }

        .doc-type-badge { text-align: right; margin-bottom: 5px; }
        .doc-type-text {
            border: 2px solid var(--orange); color: var(--orange);
            padding: 2px 12px; font-weight: 700; font-size: 14px; border-radius: 4px;
            display: inline-block; background-color: #fff9f5;
        }

        .company-logo { max-height: 84px; width: auto; max-width: 220px; object-fit: contain; }
        .invoice-title { font-size: 28px; font-weight: 800; color: var(--orange); line-height: 1; }
        .table-custom { margin-top: 10px; margin-bottom: 0; }
        .table-custom thead th { background: #fafafa; border-bottom: 2px solid var(--orange); font-size: 13px; padding: 8px 10px; }
        .table-custom td { padding: 8px 10px; font-size: 13px; border-bottom: 1px solid #f2f2f2; }
        
        .footer-sticky { position: absolute; bottom: 12mm; left: 15mm; right: 15mm; }
        .payment-info-box { border: 1px solid #eee; border-radius: 8px; padding: 10px; background: #fafafa; font-size: 11.5px; line-height: 1.4; }
        
        .summary-item { display: flex; justify-content: space-between; padding: 2px 0; font-size: 13px; }
        .summary-divider { border-top: 1px dashed #ddd; margin: 4px 0; }
        .grand-total-row { 
            display: flex; justify-content: space-between; align-items: center; 
            background: var(--orange); color: white; padding: 10px 12px; border-radius: 5px; margin-top: 8px; 
        }
        
        .signature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; text-align: center; margin-top: 25px; }
        .sig-space { height: 80px; }
        .sig-box { border-top: 1px solid #333; padding-top: 15px; font-size: 13px; font-weight: 600; }

        @media print {
            @page { size: A4; margin: 0; }
            body { background: none; }
            .no-print { display: none; }
            .invoice-box { margin: 0; box-shadow: none; border-top: 8px solid var(--orange); }
            .invoice-sheet { margin: 0; page-break-after: always; break-after: page; }
            .invoice-sheet:last-child { page-break-after: auto; break-after: auto; }
        }
    </style>
</head>
<body>

<div class="controls-wrapper no-print p-3 text-center bg-dark shadow-sm mb-4">
    <?php if (isset($_GET['created']) && $_GET['created'] === '1'): ?>
        <div class="alert alert-success mb-3 py-2">สร้าง Tax Invoice สำเร็จแล้ว</div>
    <?php elseif (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
        <div class="alert alert-success mb-3 py-2">บันทึกการแก้ไข Tax Invoice สำเร็จแล้ว</div>
    <?php elseif ($message !== ''): ?>
        <div class="alert alert-warning mb-3 py-2"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger mb-3 py-2"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <button onclick="window.print()" class="btn btn-warning btn-sm fw-bold" style="padding: 5px 30px;">พิมพ์ ต้นฉบับ + สำเนา</button>
    <a href="<?= htmlspecialchars(app_path('index.php')) ?>" class="btn btn-outline-danger btn-sm ms-2">กลับหน้าหลัก</a>
</div>

<?php foreach ($print_modes as $pm): ?>
<div class="invoice-sheet">
<div class="invoice-box">
    <div class="doc-type-badge">
        <div class="doc-type-text"><?= htmlspecialchars((string) ($pm['text'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="row align-items-start mb-2">
        <div class="col-6">
            <?php if(!empty($data['logo'])): ?>
                <img src="<?= htmlspecialchars(upload_logo_url($data['logo'])) ?>" class="company-logo" alt="Logo">
            <?php endif; ?>
            <div class="fw-bold" style="font-size: 15px;"><?= $data['name']; ?></div>
            <div class="small text-muted" style="font-size: 11px; line-height: 1.2;">
                <?= htmlspecialchars($company_detail_line, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
        <div class="col-6 text-end">
            <div class="invoice-title">RECEIPT / TAX INVOICE</div>
            <div class="fw-bold text-muted" style="font-size: 16px;">ใบเสร็จรับเงิน / ใบกำกับภาษี</div>
            <div class="fw-bold text-dark" style="margin-top: 5px;">เลขที่ใบกำกับภาษี: <?= htmlspecialchars($tax_invoice_number); ?></div>
            <?php if ($tax_invoice_number !== ''): ?>
                <div class="small text-muted mt-1">อ้างอิงใบแจ้งหนี้: <?= htmlspecialchars($invoice_number); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row mb-2 mt-3">
        <div class="col-7">
            <div style="font-size: 11px; color: var(--orange); font-weight: bold; border-bottom: 1px solid #eee; margin-bottom: 3px;">BILLED TO / ลูกค้า</div>
            <div class="fw-bold" style="font-size: 14px;"><?= $data['customer_name']; ?></div>
            <div class="small text-muted" style="font-size: 11px;">
                <?php if ($customer_address_one_line !== ''): ?><?= htmlspecialchars($customer_address_one_line, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?><?php if ($customer_address_one_line !== '' && $customer_tax_trim !== ''): ?> | <?php endif; ?><?php if ($customer_tax_trim !== ''): ?><strong>Tax ID:</strong> <?= htmlspecialchars($customer_tax_trim, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
            </div>
        </div>
        <div class="col-5 text-end">
            <div style="font-size: 11px; color: var(--orange); font-weight: bold; border-bottom: 1px solid #eee; margin-bottom: 3px;">DATE / วันที่</div>
            <div class="fw-bold" style="font-size: 14px;"><?= formatDateThai($displayIssueDate); ?></div>
        </div>
    </div>

    <table class="table table-custom">
        <thead>
            <tr>
                <th width="42%">รายละเอียด</th>
                <th class="text-center">จำนวน</th>
                <th class="text-center">หน่วย</th>
                <th class="text-end">ราคา/หน่วย</th>
                <th class="text-end">ยอดรวม</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
                <td class="fw-bold"><?= htmlspecialchars($item['description']); ?></td>
                <td class="text-center"><?= formatQty($item['quantity']); ?></td>
                <td class="text-center"><?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="text-end"><?= formatMoneyByModeOrBlank($item['unit_price'], $roundingEnabledView); ?></td>
                <td class="text-end fw-bold"><?= formatMoneyByModeOrBlank($item['total'], $roundingEnabledView); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer-sticky">
        <div class="row align-items-end mb-3">
            <div class="col-6">
                <div class="payment-info-box mb-2">
                    <div style="font-size: 9px; color: var(--orange); font-weight: bold; margin-bottom: 3px; border-bottom: 1px solid #ddd;">Payment method</div>
                    <?= $pmCashMark ?> เงินสด&nbsp;&nbsp;
                    <?= $pmTransferMark ?> เงินโอน&nbsp;&nbsp;
                    <?= $pmChequeMark ?> เช็คธนาคาร (Cheque)
                </div>
                <div class="payment-info-box">
                    <div style="font-size: 9px; color: var(--orange); font-weight: bold; margin-bottom: 3px; border-bottom: 1px solid #ddd;">PAYMENT INFO</div>
                    <strong>ธนาคาร:</strong> <?= $data['bank_name']; ?><br>
                    <strong>ชื่อบัญชี:</strong> <?= $data['bank_account_name']; ?><br>
                    <strong>เลขที่บัญชี:</strong> <span style="font-family: monospace; font-weight: bold; font-size: 13px;"><?= $data['bank_account_number']; ?></span>
                </div>
            </div>

            <div class="col-6">
                <div class="summary-box">
                    <div class="summary-item">
                        <span>ยอดรวม (Subtotal)</span>
                        <span><?= formatMoneyByModeOrBlank($subtotal, $roundingEnabledView); ?></span>
                    </div>
                    <div class="summary-item <?= $vat > 0 ? 'text-primary' : 'text-muted' ?>">
                        <span>ภาษีมูลค่าเพิ่ม 7% (VAT) (+)</span>
                        <span><?= formatMoneyByModeOrBlank($vat, $roundingEnabledView); ?></span>
                    </div>
                    <div class="summary-item fw-bold border-bottom pb-1 mb-1" style="color: #333;">
                        <span>ยอดรวม VAT</span>
                        <span><?= formatMoneyByModeOrBlank($total_after_vat, $roundingEnabledView); ?></span>
                    </div>

                    <div class="summary-divider"></div>

                    <div class="summary-item <?= $wht > 0 ? 'text-danger' : 'text-muted' ?>">
                        <span>หัก ณ ที่จ่าย 3% (-) <small class="text-muted">(คิดจากยอดก่อน VAT)</small></span>
                        <span><?= formatMoneyByModeOrBlank($wht, $roundingEnabledView); ?></span>
                    </div>
                    <div class="summary-item fw-bold border-bottom pb-1 mb-1" style="font-size: 13px; color: #444;">
                        <span>ยอดรวมหลังหัก ณ ที่จ่าย</span>
                        <span><?= formatMoneyByModeOrBlank($after_wht, $roundingEnabledView); ?></span>
                    </div>

                    <?php if ($retention > 0): ?>
                        <div class="summary-item text-danger">
                            <span>หักประกันผลงาน Retention (-)</span>
                            <span><?= formatMoneyByModeOrBlank($retention, $roundingEnabledView); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="grand-total-row">
                        <span class="fw-bold" style="font-size: 14px;">ยอดสุทธิ</span>
                        <span style="font-size: 20px; font-weight: 800;">฿ <?= formatMoneyByModeOrBlank($final_grand_total, $roundingEnabledView); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="signature-grid">
            <div><div class="sig-space"></div><div class="sig-box">ผู้รับเงิน / วันที่</div></div>
        </div>
        <div class="text-center mt-3 small text-muted" style="font-size: 9px; border-top: 1px solid #eee; padding-top: 5px;">
            ใบเสร็จรับเงินฉบับนี้จะสมบูรณ์ต่อเมื่อบริษัทฯ ได้รับเงินเรียบร้อยแล้ว
        </div>
    </div>
</div>
</div>
<?php endforeach; ?>

<?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: 'success',
        title: 'อัปเดตสำเร็จ',
        text: 'บันทึกการแก้ไข Tax Invoice เรียบร้อยแล้ว',
        confirmButtonText: 'ไปหน้ารายการ',
        timer: 1500,
        timerProgressBar: true
    }).then(function () {
        window.location.href = <?= json_encode(app_path('pages/tax-invoice-list.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    });
});
</script>
<?php endif; ?>

</body>
</html>