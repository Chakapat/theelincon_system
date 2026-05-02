<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$me = (int) $_SESSION['user_id'];
$isAdmin = user_is_admin_role();
$handler = app_path('actions/action-handler.php');
$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : '';
$siteFilter = trim((string) ($_GET['site_filter'] ?? ''));
$dateFilter = trim((string) ($_GET['date_filter'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $dateFilter = '';
}
$hasMonthFilter = $month !== '';
$ymStart = $hasMonthFilter ? ($month . '-01') : '';
$ymEnd = $hasMonthFilter ? date('Y-m-t', strtotime($ymStart)) : '';
$csrfQ = '&_csrf=' . rawurlencode(csrf_token());

$sites = Db::tableRows('sites');
usort($sites, static function (array $a, array $b): int {
    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
$siteNames = [];
foreach ($sites as $site) {
    $sid = (int) ($site['id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }
    $siteNames[$sid] = trim((string) ($site['name'] ?? ''));
}

$suppliers = Db::tableRows('suppliers');
usort($suppliers, static function (array $a, array $b): int {
    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
$vendorOptions = [];
foreach ($suppliers as $sup) {
    $name = trim((string) ($sup['name'] ?? ''));
    if ($name === '') {
        continue;
    }
    $vendorOptions[mb_strtolower($name)] = $name;
}
asort($vendorOptions, SORT_NATURAL | SORT_FLAG_CASE);

$users = Db::tableKeyed('users');
$billItemsByBillId = [];
foreach (Db::tableRows('purchase_bill_items') as $itemRow) {
    $billId = (int) ($itemRow['bill_id'] ?? 0);
    if ($billId <= 0) {
        continue;
    }
    if (!isset($billItemsByBillId[$billId])) {
        $billItemsByBillId[$billId] = [];
    }
    $billItemsByBillId[$billId][] = $itemRow;
}
foreach ($billItemsByBillId as &$items) {
    usort($items, static function (array $a, array $b): int {
        return ((int) ($a['line_no'] ?? 0)) <=> ((int) ($b['line_no'] ?? 0));
    });
}
unset($items);

$rows = [];
$sumTotal = 0.0;
foreach (Db::tableRows('purchase_bills') as $bill) {
    $billId = (int) ($bill['id'] ?? 0);
    if ($billId > 0 && !isset($billItemsByBillId[$billId])) {
        $embedded = $bill['items'] ?? [];
        if (is_string($embedded)) {
            $decoded = json_decode($embedded, true);
            $embedded = is_array($decoded) ? $decoded : [];
        }
        if (is_array($embedded) && count($embedded) > 0) {
            $billItemsByBillId[$billId] = array_map(static function (array $item, int $idx): array {
                return [
                    'line_no' => (int) ($item['line_no'] ?? ($idx + 1)),
                    'item_name' => (string) ($item['description'] ?? $item['item_name'] ?? ''),
                    'quantity' => (float) ($item['quantity'] ?? 0),
                    'unit' => (string) ($item['unit'] ?? ''),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'discount_type' => (string) ($item['discount_type'] ?? 'amount'),
                    'discount_value' => (float) ($item['discount_value'] ?? 0),
                    'discount_amount' => (float) ($item['discount_amount'] ?? 0),
                    'line_total' => (float) ($item['line_total'] ?? 0),
                ];
            }, $embedded, array_keys($embedded));
        }
    }
    $billDate = (string) ($bill['bill_date'] ?? '');
    if ($hasMonthFilter && ($billDate < $ymStart || $billDate > $ymEnd)) {
        continue;
    }
    $siteId = (int) ($bill['site_id'] ?? 0);
    $siteLabel = trim((string) ($bill['site_name'] ?? ''));
    if ($siteId > 0) {
        $siteLabel = trim((string) ($siteNames[$siteId] ?? $siteLabel));
    }
    if ($siteLabel === '') {
        $siteLabel = 'ไม่ระบุไซต์';
    }
    if ($siteFilter !== '' && stripos($siteLabel, $siteFilter) === false) {
        continue;
    }
    if ($dateFilter !== '' && $billDate !== $dateFilter) {
        continue;
    }
    $creator = $users[(string) ((int) ($bill['created_by'] ?? 0))] ?? null;
    $creatorName = trim((string) ($creator['fname'] ?? '') . ' ' . (string) ($creator['lname'] ?? ''));

    $rows[] = array_merge($bill, [
        'site_label' => $siteLabel,
        'creator_name' => $creatorName !== '' ? $creatorName : '—',
    ]);
    $sumTotal += (float) ($bill['amount'] ?? $bill['total_amount'] ?? 0);
}

usort($rows, static function (array $a, array $b): int {
    $d = strcmp((string) ($b['bill_date'] ?? ''), (string) ($a['bill_date'] ?? ''));
    if ($d !== 0) {
        return $d;
    }

    return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
});

$billCount = count($rows);
$perPage = 10;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
$totalPages = max(1, (int) ceil($billCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$pagedRows = array_slice($rows, $offset, $perPage);
$showFrom = $billCount > 0 ? ($offset + 1) : 0;
$showTo = $billCount > 0 ? min($offset + $perPage, $billCount) : 0;
$siteExpenseSummary = [];
foreach ($rows as $row) {
    $label = trim((string) ($row['site_label'] ?? ''));
    if ($label === '') {
        $label = 'ไม่ระบุไซต์';
    }
    if (!isset($siteExpenseSummary[$label])) {
        $siteExpenseSummary[$label] = [
            'site_label' => $label,
            'bill_count' => 0,
            'total_amount' => 0.0,
        ];
    }
    $siteExpenseSummary[$label]['bill_count']++;
    $siteExpenseSummary[$label]['total_amount'] += (float) ($row['amount'] ?? $row['total_amount'] ?? 0);
}
$siteExpenseRows = array_values($siteExpenseSummary);
usort($siteExpenseRows, static function (array $a, array $b): int {
    $cmp = ((float) ($b['total_amount'] ?? 0)) <=> ((float) ($a['total_amount'] ?? 0));
    if ($cmp !== 0) {
        return $cmp;
    }

    return strcmp((string) ($a['site_label'] ?? ''), (string) ($b['site_label'] ?? ''));
});
$siteExpenseCount = count($siteExpenseRows);
$billPrefix = 'PB-TNC-' . date('ym') . '-';
$nextRunning = 1;
foreach (Db::tableRows('purchase_bills') as $billRow) {
    $billNumber = trim((string) ($billRow['bill_number'] ?? ''));
    if (strncmp($billNumber, $billPrefix, strlen($billPrefix)) !== 0) {
        continue;
    }
    $runningPart = substr($billNumber, strlen($billPrefix));
    if (!ctype_digit($runningPart)) {
        continue;
    }
    $nextRunning = max($nextRunning, ((int) $runningPart) + 1);
}
$nextBillNo = $billPrefix . str_pad((string) $nextRunning, 3, '0', STR_PAD_LEFT);
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editBill = null;
$editLines = [];
if ($editId > 0) {
    $candidate = Db::rowByIdField('purchase_bills', $editId);
    if (is_array($candidate)) {
        $canEditCandidate = $isAdmin || (int) ($candidate['created_by'] ?? 0) === $me;
        if ($canEditCandidate) {
            $editBill = $candidate;
            $editLines = $billItemsByBillId[$editId] ?? [];
        }
    }
}
$isEditing = is_array($editBill);
$formAction = 'save_project_purchase_bill';
$currentBillNo = $isEditing ? (string) ($editBill['bill_number'] ?? $nextBillNo) : $nextBillNo;
$currentBillDate = $isEditing
    ? (string) ($editBill['bill_date'] ?? date('Y-m-d'))
    : '';
if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $currentBillDate, $m) === 1) {
    $currentBillDate = $m[3] . '-' . $m[2] . '-' . $m[1];
}
$currentVendorName = $isEditing ? (string) ($editBill['supplier_name'] ?? $editBill['vendor_name'] ?? '') : '';
$currentSiteId = $isEditing ? (int) ($editBill['site_id'] ?? 0) : 0;
$currentNote = $isEditing ? (string) ($editBill['bill_note'] ?? $editBill['note'] ?? '') : '';
$currentVatMode = $isEditing ? (string) ($editBill['vat_mode'] ?? 'none') : 'none';
if (!in_array($currentVatMode, ['none', 'inclusive', 'exclusive'], true)) {
    $currentVatMode = 'none';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บันทึกบิลซื้อ | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Sarabun', sans-serif; background:#f7f8fa; }
        .po-card { border:none; border-radius:18px; box-shadow: 0 6px 24px rgba(16, 24, 40, 0.08); }
        .po-head { background: linear-gradient(135deg, #0d6efd 0%, #4c8dff 100%); color:#fff; border-radius:18px; }
        .summary-box { background:#f8faff; border:1px solid #dbe7ff; border-radius:12px; }
        .bill-toggle-btn { border-radius: 999px; }
        .site-expense-toggle { border: 0; background: transparent; padding: 0; }
        .site-expense-chevron { transition: transform 0.2s ease; }
        .site-expense-toggle[aria-expanded="false"] .site-expense-chevron { transform: rotate(-180deg); }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container pb-5">
    <div class="card po-card mb-4">
        <div class="card-body p-0">
            <div class="po-head px-4 py-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="small text-white-50">
                    เลขที่บิล: <span class="fw-semibold text-white"><?= htmlspecialchars($currentBillNo, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($isEditing): ?>
                        <span class="badge bg-warning text-dark ms-2">โหมดแก้ไข</span>
                    <?php endif; ?>
                </div>
                <button class="btn btn-light btn-sm bill-toggle-btn px-3 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#purchaseBillFormCollapse" aria-expanded="false" aria-controls="purchaseBillFormCollapse" id="togglePurchaseBillFormBtn" aria-label="เปิดหรือซ่อนฟอร์มบันทึกบิล">
                    <i class="bi bi-chevron-down"></i>
                </button>
            </div>
            <div class="collapse<?= $isEditing ? ' show' : '' ?>" id="purchaseBillFormCollapse">
            <div class="p-4">
                <form method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>?action=<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>" id="billForm" class="row g-3" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="month" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($isEditing): ?>
                        <input type="hidden" name="bill_id" value="<?= (int) ($editBill['id'] ?? 0) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="vat_mode" id="vat_mode" value="<?= htmlspecialchars($currentVatMode, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">วันที่บิล</label>
                        <input type="date" name="bill_date" id="bill_date" class="form-control rounded-3" value="<?= htmlspecialchars($currentBillDate, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-bold">ผู้ขาย</label>
                        <input type="text" name="supplier_name" class="form-control rounded-3" list="vendor_list" maxlength="255" value="<?= htmlspecialchars($currentVendorName, ENT_QUOTES, 'UTF-8') ?>" required>
                        <datalist id="vendor_list">
                            <?php foreach ($vendorOptions as $vendorName): ?>
                                <option value="<?= htmlspecialchars((string) $vendorName, ENT_QUOTES, 'UTF-8') ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">ไซต์งาน</label>
                        <select class="form-select rounded-3" id="site_id" name="site_id">
                            <option value="0">-- ไม่ระบุ --</option>
                            <?php foreach ($sites as $site): ?>
                                <?php $sid = (int) ($site['id'] ?? 0); ?>
                                <option value="<?= $sid ?>" <?= $sid === $currentSiteId ? 'selected' : '' ?>><?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="site_name" id="site_name">
                    </div>

                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label small fw-bold mb-0">รายการที่ซื้อ</label>
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-3" id="btnAddRow"><i class="bi bi-plus-lg me-1"></i>เพิ่มรายการ</button>
                        </div>
                        <div class="table-responsive border rounded-3 bg-white">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:3rem;">#</th>
                                        <th>รายการ</th>
                                        <th style="width:8rem;">จำนวน</th>
                                        <th style="width:8rem;">หน่วย</th>
                                        <th style="width:10rem;">ราคา/หน่วย</th>
                                        <th style="width:9rem;">ส่วนลด</th>
                                        <th style="width:10rem;">รวม</th>
                                        <th style="width:3rem;"></th>
                                    </tr>
                                </thead>
                                <tbody id="lineBody">
                                    <?php if ($isEditing && count($editLines) > 0): ?>
                                        <?php foreach ($editLines as $idx => $line): ?>
                                            <tr class="line-row">
                                                <td class="line-no text-secondary small"><?= $idx + 1 ?></td>
                                                <td><input type="text" class="form-control form-control-sm line-name" name="line_description[]" maxlength="500" value="<?= htmlspecialchars((string) ($line['description'] ?? $line['item_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></td>
                                                <td><input type="number" class="form-control form-control-sm line-qty" name="line_qty[]" step="0.001" min="0" value="<?= htmlspecialchars((string) ($line['quantity'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" required></td>
                                                <td><input type="text" class="form-control form-control-sm line-unit" name="line_unit[]" maxlength="40" value="<?= htmlspecialchars((string) ($line['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                                <td><input type="number" class="form-control form-control-sm line-price" name="line_price[]" step="0.001" min="0" value="<?= htmlspecialchars((string) ($line['unit_price'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" required></td>
                                                <td><input type="text" class="form-control form-control-sm line-discount" name="line_discount[]" maxlength="20" placeholder="เช่น 10% หรือ 100" value="<?= htmlspecialchars((string) ($line['discount_input'] ?? ((string) ($line['discount_type'] ?? '') === 'percent' ? ((string) ($line['discount_value'] ?? 0) . '%') : (string) ($line['discount_value'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"></td>
                                                <td><input type="text" class="form-control form-control-sm line-total bg-light" readonly></td>
                                                <td><button type="button" class="btn btn-sm btn-outline-danger line-del"><i class="bi bi-x-lg"></i></button></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr class="line-row">
                                            <td class="line-no text-secondary small">1</td>
                                            <td><input type="text" class="form-control form-control-sm line-name" name="line_description[]" maxlength="500" required></td>
                                            <td><input type="number" class="form-control form-control-sm line-qty" name="line_qty[]" step="0.001" min="0" value="1" required></td>
                                            <td><input type="text" class="form-control form-control-sm line-unit" name="line_unit[]" maxlength="40"></td>
                                            <td><input type="number" class="form-control form-control-sm line-price" name="line_price[]" step="0.001" min="0" required></td>
                                            <td><input type="text" class="form-control form-control-sm line-discount" name="line_discount[]" maxlength="20" placeholder="เช่น 10% หรือ 100"></td>
                                            <td><input type="text" class="form-control form-control-sm line-total bg-light" readonly></td>
                                            <td><button type="button" class="btn btn-sm btn-outline-danger line-del"><i class="bi bi-x-lg"></i></button></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="col-md-7">
                        <label class="form-label small fw-bold">หมายเหตุบิล</label>
                        <textarea name="bill_note" class="form-control rounded-3" rows="3" maxlength="1000"><?= htmlspecialchars($currentNote, ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-bold">ภาษีมูลค่าเพิ่ม</label>
                        <div class="summary-box p-3">
                            <div class="mb-2">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="vat_enabled" <?= $currentVatMode !== 'none' ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold small" for="vat_enabled">มี VAT</label>
                                </div>
                                <div id="vat_basis_wrap" class="pt-2 border-top">
                                    <div class="form-check mb-1">
                                        <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_inclusive" value="inclusive" <?= $currentVatMode === 'exclusive' ? '' : 'checked' ?>>
                                        <label class="form-check-label small" for="vat_basis_inclusive">รวม VAT</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_exclusive" value="exclusive" <?= $currentVatMode === 'exclusive' ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="vat_basis_exclusive">แยก VAT</label>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-2">
                                <input type="number" class="form-control form-control-sm rounded-2 bg-light" name="vat_rate" id="vat_rate" step="0.01" min="0" max="100" value="7" readonly aria-readonly="true">
                            </div>
                            <div class="small d-flex justify-content-between"><span>ยอดรายการ</span><span id="sum_subtotal">฿0.00</span></div>
                            <div class="small d-flex justify-content-between"><span>VAT</span><span id="sum_vat">฿0.00</span></div>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between fw-bold"><span>ยอดสุทธิ</span><span id="sum_grand">฿0.00</span></div>
                        </div>
                    </div>

                    <div class="col-12 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary rounded-pill px-4">
                            <i class="bi bi-check2-circle me-1"></i><?= $isEditing ? 'บันทึกการแก้ไข' : 'บันทึกบิลซื้อ' ?>
                        </button>
                        <?php if ($isEditing): ?>
                            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-bill.php') . '?month=' . rawurlencode($month), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill">ยกเลิกแก้ไข</a>
                        <?php else: ?>
                            <button type="reset" class="btn btn-outline-secondary rounded-pill">ล้างฟอร์ม</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            </div>
        </div>
    </div>

    <div class="card po-card">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
                <h5 class="fw-bold mb-0">รายการบิลซื้อ <?= $hasMonthFilter ? ('เดือน ' . htmlspecialchars($month, ENT_QUOTES, 'UTF-8')) : '(ทุกเดือน)' ?></h5>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6"><div class="summary-box p-3"><div class="small text-muted">จำนวนบิล</div><div class="fs-5 fw-bold"><?= number_format($billCount) ?> รายการ</div></div></div>
                <div class="col-md-6"><div class="summary-box p-3"><div class="small text-muted">ยอดรวมทั้งหมด</div><div class="fs-5 fw-bold text-primary">฿<?= number_format($sumTotal, 2) ?></div></div></div>
            </div>

            <div class="summary-box p-3 mb-3" id="siteExpenseSummaryBlock">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <button type="button" class="site-expense-toggle d-inline-flex align-items-center gap-2" id="toggleSiteExpenseBtn" aria-expanded="false" aria-controls="siteExpenseSummaryContent">
                        <h6 class="fw-bold mb-0"><i class="bi bi-geo-alt me-1 text-primary"></i>ค่าใช้จ่ายแต่ละไซต์</h6>
                        <i class="bi bi-chevron-up site-expense-chevron text-muted" aria-hidden="true"></i>
                    </button>
                    <div class="d-flex align-items-center gap-2">
                        <span class="small text-muted"><?= number_format($siteExpenseCount) ?> ไซต์</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="printSiteExpenseBtn">
                            <i class="bi bi-printer me-1"></i>
                        </button>
                    </div>
                </div>
                <div class="table-responsive d-none" id="siteExpenseSummaryContent">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:3rem;">#</th>
                                <th>ไซต์งาน</th>
                                <th class="text-center">จำนวนบิล</th>
                                <th class="text-end">ยอดรวม</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($siteExpenseCount === 0): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">ยังไม่มีข้อมูลค่าใช้จ่ายตามไซต์ในเดือนนี้</td></tr>
                            <?php else: ?>
                                <?php $sn = 0; foreach ($siteExpenseRows as $siteRow): $sn++; ?>
                                    <tr>
                                        <td class="text-secondary small"><?= $sn ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars((string) ($siteRow['site_label'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="text-center"><?= number_format((int) ($siteRow['bill_count'] ?? 0)) ?></td>
                                        <td class="text-end fw-bold text-primary">฿<?= number_format((float) ($siteRow['total_amount'] ?? 0), 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <form method="get" class="row g-2 align-items-center mb-3">
                <div class="col-12 col-md-3">
                    <input type="month" name="month" class="form-control form-control-sm" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-12 col-md-3">
                    <select name="site_filter" class="form-select form-select-sm">
                        <option value="">ทุกไซต์</option>
                        <?php foreach ($sites as $site): ?>
                            <?php $siteNameOpt = trim((string) ($site['name'] ?? '')); ?>
                            <?php if ($siteNameOpt === '') continue; ?>
                            <option value="<?= htmlspecialchars($siteNameOpt, ENT_QUOTES, 'UTF-8') ?>" <?= $siteFilter === $siteNameOpt ? 'selected' : '' ?>>
                                <?= htmlspecialchars($siteNameOpt, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <input type="date" name="date_filter" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFilter, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="ค้นหา" aria-label="ค้นหา">
                        <i class="bi bi-search"></i>
                    </button>
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-bill.php') . '?month=' . rawurlencode($month), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-secondary" title="ล้างตัวกรอง" aria-label="ล้างตัวกรอง">
                        <i class="bi bi-eraser"></i>
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="printBillListBtn" title="พิมพ์" aria-label="พิมพ์">
                        <i class="bi bi-printer"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="printBillListDetailBtn" title="พิมพ์พร้อมรายละเอียด" aria-label="พิมพ์พร้อมรายละเอียด">
                        <i class="bi bi-printer-fill"></i>
                    </button>
                </div>
            </form>
            <div class="table-responsive" id="billListTableWrap">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>วันที่</th>
                            <th>ซื้อจาก</th>
                            <th>ไซต์</th>
                            <th class="text-end">ยอดรวม</th>
                            <th class="text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($billCount === 0): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีข้อมูลบิลซื้อตามเงื่อนไขที่เลือก</td></tr>
                        <?php else: ?>
                            <?php foreach ($pagedRows as $row): ?>
                                <?php
                                $canDelete = $isAdmin || (int) ($row['created_by'] ?? 0) === $me;
                                $billId = (int) ($row['id'] ?? 0);
                                $billItems = $billItemsByBillId[$billId] ?? [];
                                $qsBase = [
                                    'month' => $month,
                                    'page' => $page,
                                    'site_filter' => $siteFilter,
                                    'date_filter' => $dateFilter,
                                ];
                                ?>
                                <tr>
                                    <td class="small text-nowrap">
                                        <?php
                                        $billDate = trim((string) ($row['bill_date'] ?? ''));
                                        echo $billDate !== '' ? htmlspecialchars(date('d/m/Y', strtotime($billDate)), ENT_QUOTES, 'UTF-8') : '—';
                                        ?>
                                    </td>
                                    <td class="small"><?= htmlspecialchars((string) ($row['supplier_name'] ?? $row['vendor_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="small"><?= htmlspecialchars((string) ($row['site_label'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end fw-bold">฿<?= number_format((float) ($row['amount'] ?? $row['total_amount'] ?? 0), 2) ?></td>
                                    <td class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <?php if ($isAdmin || (int) ($row['created_by'] ?? 0) === $me): ?>
                                                <a
                                                    href="<?= htmlspecialchars(app_path('pages/purchase/purchase-bill.php') . '?' . http_build_query(array_merge($qsBase, ['edit' => (int) ($row['id'] ?? 0)])), ENT_QUOTES, 'UTF-8') ?>"
                                                    class="btn btn-sm btn-outline-warning"
                                                    title="แก้ไขบิล"
                                                >
                                                    <i class="bi bi-pencil-square"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (count($billItems) > 0): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#billItems<?= $billId ?>"
                                                    aria-expanded="false"
                                                    aria-controls="billItems<?= $billId ?>"
                                                    title="ดูรายละเอียดรายการ"
                                                >
                                                    <i class="bi bi-card-list"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($canDelete): ?>
                                                <a href="<?= htmlspecialchars($handler . '?' . http_build_query(['action' => 'delete', 'type' => 'purchase_bill', 'id' => (int) ($row['id'] ?? 0)]) . $csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('ยืนยันลบบิลนี้ ?');" title="ลบบิล">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php if (count($billItems) > 0): ?>
                                    <tr class="collapse" id="billItems<?= $billId ?>">
                                        <td colspan="5" class="bg-light">
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered mb-0 bg-white">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th style="width:3rem;">#</th>
                                                            <th>รายการ</th>
                                                            <th style="width:8rem;" class="text-end">จำนวน</th>
                                                            <th style="width:8rem;">หน่วย</th>
                                                            <th style="width:10rem;" class="text-end">ราคา/หน่วย</th>
                                                            <th style="width:10rem;" class="text-end">ส่วนลด</th>
                                                            <th style="width:10rem;" class="text-end">รวม</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($billItems as $item): ?>
                                                            <tr>
                                                                <td class="text-secondary"><?= (int) ($item['line_no'] ?? 0) ?></td>
                                                                <td><?= htmlspecialchars((string) ($item['item_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td class="text-end"><?= number_format((float) ($item['quantity'] ?? 0), 3) ?></td>
                                                                <td><?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td class="text-end"><?= number_format((float) ($item['unit_price'] ?? 0), 3) ?></td>
                                                                <td class="text-end">
                                                                    <?php
                                                                    $discInput = trim((string) ($item['discount_input'] ?? ''));
                                                                    $discAmount = (float) ($item['discount_amount'] ?? 0);
                                                                    echo $discInput !== ''
                                                                        ? htmlspecialchars($discInput, ENT_QUOTES, 'UTF-8')
                                                                        : ($discAmount > 0 ? number_format($discAmount, 2) : '—');
                                                                    ?>
                                                                </td>
                                                                <td class="text-end fw-semibold"><?= number_format((float) ($item['line_total'] ?? 0), 2) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($billCount > 0): ?>
                <?php
                $prevQuery = [
                    'month' => $month,
                    'page' => max(1, $page - 1),
                    'site_filter' => $siteFilter,
                    'date_filter' => $dateFilter,
                ];
                $nextQuery = [
                    'month' => $month,
                    'page' => min($totalPages, $page + 1),
                    'site_filter' => $siteFilter,
                    'date_filter' => $dateFilter,
                ];
                ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                    <div class="small text-muted">
                        แสดง <?= number_format($showFrom) ?>-<?= number_format($showTo) ?> จาก <?= number_format($billCount) ?> รายการ
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-bill.php') . '?' . http_build_query($prevQuery), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-secondary<?= $page <= 1 ? ' disabled' : '' ?>"<?= $page <= 1 ? ' tabindex="-1" aria-disabled="true"' : '' ?>>ดูก่อนหน้า</a>
                        <span class="small text-muted">หน้า <?= number_format($page) ?>/<?= number_format($totalPages) ?></span>
                        <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-bill.php') . '?' . http_build_query($nextQuery), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-secondary<?= $page >= $totalPages ? ' disabled' : '' ?>"<?= $page >= $totalPages ? ' tabindex="-1" aria-disabled="true"' : '' ?>>ดูถัดไป</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<template id="lineTpl">
    <tr class="line-row">
        <td class="line-no text-secondary small">0</td>
        <td><input type="text" class="form-control form-control-sm line-name" name="line_description[]" maxlength="500" required></td>
        <td><input type="number" class="form-control form-control-sm line-qty" name="line_qty[]" step="0.001" min="0" value="1" required></td>
        <td><input type="text" class="form-control form-control-sm line-unit" name="line_unit[]" maxlength="40" placeholder="หน่วย"></td>
        <td><input type="number" class="form-control form-control-sm line-price" name="line_price[]" step="0.001" min="0" required></td>
        <td><input type="text" class="form-control form-control-sm line-discount" name="line_discount[]" maxlength="20" placeholder="เช่น 10% หรือ 100"></td>
        <td><input type="text" class="form-control form-control-sm line-total bg-light" readonly></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger line-del"><i class="bi bi-x-lg"></i></button></td>
    </tr>
</template>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
(function() {
    const billDateEl = document.getElementById('bill_date');
    const isEditingBill = <?= $isEditing ? 'true' : 'false' ?>;
    const toLocalIsoDate = function(dateObj) {
        const y = dateObj.getFullYear();
        const m = String(dateObj.getMonth() + 1).padStart(2, '0');
        const d = String(dateObj.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    };
    if (billDateEl && !isEditingBill) {
        billDateEl.value = toLocalIsoDate(new Date());
    }
    if (billDateEl && window.flatpickr) {
        flatpickr(billDateEl, {
            dateFormat: 'Y-m-d',
            defaultDate: billDateEl.value || null,
            allowInput: true,
            disableMobile: true
        });
    }

    const body = document.getElementById('lineBody');
    const tpl = document.getElementById('lineTpl');
    const btnAdd = document.getElementById('btnAddRow');
    const vatEnabled = document.getElementById('vat_enabled');
    const vatBasisWrap = document.getElementById('vat_basis_wrap');
    const vatMode = document.getElementById('vat_mode');
    const vatRate = document.getElementById('vat_rate');
    const sumSubtotal = document.getElementById('sum_subtotal');
    const sumVat = document.getElementById('sum_vat');
    const sumGrand = document.getElementById('sum_grand');
    const formCollapseEl = document.getElementById('purchaseBillFormCollapse');
    const toggleFormBtn = document.getElementById('togglePurchaseBillFormBtn');

    function renderFormToggleText() {
        if (!formCollapseEl || !toggleFormBtn) return;
        const expanded = formCollapseEl.classList.contains('show');
        toggleFormBtn.innerHTML = expanded
            ? '<i class="bi bi-chevron-up"></i>'
            : '<i class="bi bi-chevron-down"></i>';
        toggleFormBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }
    renderFormToggleText();
    if (formCollapseEl) {
        formCollapseEl.addEventListener('shown.bs.collapse', renderFormToggleText);
        formCollapseEl.addEventListener('hidden.bs.collapse', renderFormToggleText);
    }
    const siteSelect = document.getElementById('site_id');
    const siteName = document.getElementById('site_name');

    function money(n) {
        return '฿' + (Math.round(n * 100) / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function num(v) {
        const n = parseFloat(String(v || '').replace(/,/g, ''));
        return Number.isFinite(n) ? n : 0;
    }
    function renumber() {
        body.querySelectorAll('.line-row').forEach((tr, i) => {
            const no = tr.querySelector('.line-no');
            if (no) no.textContent = String(i + 1);
        });
    }
    function recalcRow(tr) {
        const q = num(tr.querySelector('.line-qty').value);
        const p = num(tr.querySelector('.line-price').value);
        const dRaw = String((tr.querySelector('.line-discount')?.value || '')).trim();
        const base = Math.round(q * p * 100) / 100;
        let discount = 0;
        if (dRaw !== '') {
            const pctMatch = dRaw.match(/^([0-9]+(?:\.[0-9]+)?)\s*%$/);
            if (pctMatch) {
                let pct = num(pctMatch[1]);
                if (pct < 0) pct = 0;
                if (pct > 100) pct = 100;
                discount = Math.round(base * pct / 100 * 100) / 100;
            } else {
                discount = Math.round(num(dRaw.replace(/,/g, '')) * 100) / 100;
                if (discount < 0) discount = 0;
            }
        }
        if (discount > base) discount = base;
        const t = Math.round((base - discount) * 100) / 100;
        tr.querySelector('.line-total').value = t > 0 ? t.toFixed(2) : '';
    }
    function recalcAll() {
        const FIXED_VAT_RATE = 7;
        let s = 0;
        body.querySelectorAll('.line-row').forEach((tr) => {
            recalcRow(tr);
            s += num(tr.querySelector('.line-total').value);
        });
        s = Math.round(s * 100) / 100;
        let mode = 'none';
        if (vatEnabled && vatEnabled.checked) {
            const selectedBasis = document.querySelector('input[name="vat_basis"]:checked');
            mode = selectedBasis ? selectedBasis.value : 'inclusive';
        }
        if (!['none', 'inclusive', 'exclusive'].includes(mode)) mode = 'none';
        if (vatMode) vatMode.value = mode;
        let rate = FIXED_VAT_RATE;
        if (vatRate) {
            vatRate.value = String(FIXED_VAT_RATE);
        }
        let vat = 0;
        let grand = s;
        if (mode === 'exclusive') {
            vat = Math.round(s * rate / 100 * 100) / 100;
            grand = Math.round((s + vat) * 100) / 100;
        } else if (mode === 'inclusive' && rate > 0) {
            const base = Math.round((s / (1 + rate / 100)) * 100) / 100;
            vat = Math.round((s - base) * 100) / 100;
            grand = s;
        }
        if (sumSubtotal) sumSubtotal.textContent = money(s);
        if (sumVat) sumVat.textContent = money(vat);
        if (sumGrand) sumGrand.textContent = money(grand);
        renumber();
    }
    function bindRow(tr) {
        tr.querySelectorAll('.line-qty, .line-price, .line-discount').forEach((el) => {
            el.addEventListener('input', recalcAll);
        });
        const del = tr.querySelector('.line-del');
        if (del) {
            del.addEventListener('click', function() {
                if (body.querySelectorAll('.line-row').length <= 1) return;
                tr.remove();
                recalcAll();
            });
        }
    }
    body.querySelectorAll('.line-row').forEach(bindRow);
    if (btnAdd) {
        btnAdd.addEventListener('click', function() {
            const node = tpl.content.firstElementChild.cloneNode(true);
            body.appendChild(node);
            bindRow(node);
            recalcAll();
        });
    }
    function updateVatBasisUi() {
        if (!vatBasisWrap || !vatEnabled) return;
        const on = vatEnabled.checked;
        vatBasisWrap.classList.toggle('opacity-50', !on);
        vatBasisWrap.style.pointerEvents = on ? '' : 'none';
        vatBasisWrap.setAttribute('aria-disabled', on ? 'false' : 'true');
    }
    if (vatEnabled) {
        vatEnabled.addEventListener('change', function() {
            updateVatBasisUi();
            recalcAll();
        });
    }
    document.querySelectorAll('input[name="vat_basis"]').forEach((el) => {
        el.addEventListener('change', recalcAll);
    });
    if (vatRate) vatRate.addEventListener('input', recalcAll);
    if (siteSelect && siteName) {
        siteSelect.addEventListener('change', function() {
            const opt = siteSelect.options[siteSelect.selectedIndex];
            siteName.value = (opt && opt.value !== '0') ? (opt.text || '') : '';
        });
        const selected = siteSelect.options[siteSelect.selectedIndex];
        siteName.value = (selected && selected.value !== '0') ? (selected.text || '') : '';
    }
    updateVatBasisUi();
    recalcAll();
})();

(function() {
    const q = new URLSearchParams(window.location.search);
    if (q.get('saved') === '1' || q.get('success') === '1') {
        Swal.fire({ icon: 'success', title: 'บันทึกบิลซื้อแล้ว', confirmButtonColor: '#0d6efd' });
    }
    if (q.get('updated') === '1') {
        Swal.fire({ icon: 'success', title: 'แก้ไขบิลแล้ว', confirmButtonColor: '#0d6efd' });
    }
    if (q.get('deleted') === '1') {
        Swal.fire({ icon: 'success', title: 'ลบบิลแล้ว', confirmButtonColor: '#0d6efd' });
    }
    const errCode = q.get('err') || q.get('error');
    if (errCode) {
        const map = {
            invalid: 'ข้อมูลไม่ถูกต้อง กรุณาตรวจสอบอีกครั้ง',
            csrf: 'เซสชันหมดอายุ กรุณาลองใหม่',
            date: 'กรุณาระบุวันที่บิลให้ถูกต้อง',
            site: 'ไม่พบข้อมูลไซต์งาน',
            vendor: 'กรุณาระบุผู้ขาย/ร้านค้า',
            need_lines: 'กรุณาระบุรายการซื้ออย่างน้อย 1 รายการ',
            line_total: 'ยอดรวมรายการต้องมากกว่า 0',
            forbidden: 'คุณไม่มีสิทธิ์ลบบิลนี้'
        };
        Swal.fire({ icon: 'error', title: 'ไม่สามารถดำเนินการได้', text: map[errCode] || errCode, confirmButtonColor: '#0d6efd' });
    }
})();

(function () {
    const toggleBtn = document.getElementById('toggleSiteExpenseBtn');
    const content = document.getElementById('siteExpenseSummaryContent');
    if (toggleBtn && content) {
        toggleBtn.addEventListener('click', function () {
            const expanded = toggleBtn.getAttribute('aria-expanded') !== 'false';
            if (expanded) {
                content.classList.add('d-none');
                toggleBtn.setAttribute('aria-expanded', 'false');
            } else {
                content.classList.remove('d-none');
                toggleBtn.setAttribute('aria-expanded', 'true');
            }
        });
    }

    const printBtn = document.getElementById('printSiteExpenseBtn');
    const printBlock = document.getElementById('siteExpenseSummaryBlock');
    if (!printBtn || !printBlock) {
        return;
    }
    printBtn.addEventListener('click', function () {
        const popup = window.open('', '_blank', 'width=980,height=720');
        if (!popup) {
            return;
        }
        const now = new Date();
        const printedAt = now.toLocaleString('th-TH', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
        const monthValue = <?= json_encode($month, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const monthText = monthValue && monthValue.length === 7 ? monthValue : '-';
        const sourceTable = printBlock.querySelector('.table-responsive');
        const clonedTableHtml = sourceTable ? sourceTable.innerHTML : '';

        let totalAmount = 0;
        printBlock.querySelectorAll('tbody tr').forEach(function (row) {
            const amountCell = row.querySelector('td:last-child');
            if (!amountCell) {
                return;
            }
            const amountText = (amountCell.textContent || '').replace(/[^\d.-]/g, '');
            const n = parseFloat(amountText);
            if (!isNaN(n)) {
                totalAmount += n;
            }
        });
        const totalAmountText = totalAmount.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        popup.document.write('<!doctype html><html lang="th"><head><meta charset="UTF-8"><title>ค่าใช้จ่ายแต่ละไซต์</title>');
        popup.document.write('<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">');
        popup.document.write('<style>');
        popup.document.write('body{font-family:Sarabun,sans-serif;padding:28px 34px;color:#1f2937;}');
        popup.document.write('.doc-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:14px;}');
        popup.document.write('.doc-title{font-size:20px;font-weight:700;margin:0;color:#0f172a;}');
        popup.document.write('.doc-sub{font-size:12px;color:#64748b;margin-top:4px;}');
        popup.document.write('.doc-meta{font-size:12px;color:#475569;text-align:right;line-height:1.5;}');
        popup.document.write('.doc-box{border:1px solid #dbe3ef;border-radius:10px;padding:14px 14px 8px 14px;}');
        popup.document.write('table{width:100%;border-collapse:collapse;font-size:13px;}');
        popup.document.write('thead th{background:#f1f5f9;color:#334155;font-weight:700;padding:9px 10px;border-bottom:1px solid #dbe3ef;text-align:left;}');
        popup.document.write('tbody td{padding:9px 10px;border-bottom:1px solid #eef2f7;}');
        popup.document.write('td.text-end,th.text-end{text-align:right;} td.text-center,th.text-center{text-align:center;}');
        popup.document.write('.doc-footer{margin-top:14px;display:flex;justify-content:flex-end;}');
        popup.document.write('.total-card{min-width:260px;border:1px solid #dbe3ef;border-radius:10px;padding:10px 12px;}');
        popup.document.write('.total-row{display:flex;justify-content:space-between;align-items:center;font-size:13px;}');
        popup.document.write('.total-row strong{font-size:16px;color:#0f172a;}');
        popup.document.write('@media print{body{padding:12mm 10mm;} .doc-box{border:1px solid #cbd5e1;}}');
        popup.document.write('</style>');
        popup.document.write('</head><body>');
        popup.document.write('<div class="doc-header">');
        popup.document.write('<div><h1 class="doc-title">รายงานค่าใช้จ่ายแต่ละไซต์</h1><div class="doc-sub">จากข้อมูลบิลซื้อประจำเดือน ' + monthText + '</div></div>');
        popup.document.write('<div class="doc-meta">THEELIN CON CO., LTD.<br>พิมพ์เมื่อ: ' + printedAt + '</div>');
        popup.document.write('</div>');
        popup.document.write('<div class="doc-box">');
        popup.document.write(clonedTableHtml);
        popup.document.write('</div>');
        popup.document.write('<div class="doc-footer"><div class="total-card"><div class="total-row"><span>ยอดรวมทั้งสิ้น</span><strong>฿ ' + totalAmountText + '</strong></div></div></div>');
        popup.document.write('</body></html>');
        popup.document.close();
        popup.focus();
        popup.print();
    });
})();

(function () {
    const printBtn = document.getElementById('printBillListBtn');
    if (!printBtn) {
        return;
    }
    printBtn.addEventListener('click', function () {
        const popup = window.open('', '_blank', 'width=1100,height=760');
        if (!popup) {
            return;
        }
        const now = new Date();
        const printedAt = now.toLocaleString('th-TH', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
        const monthValue = <?= json_encode($month, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const billCount = <?= (int) $billCount ?>;
        const totalAmount = <?= json_encode((float) $sumTotal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const rows = <?= json_encode(array_map(static function (array $row): array {
            $billDate = trim((string) ($row['bill_date'] ?? ''));
            $billDateText = $billDate !== '' ? date('d/m/Y', strtotime($billDate)) : '—';
            return [
                'bill_date_text' => $billDateText,
        'vendor_name' => (string) ($row['supplier_name'] ?? $row['vendor_name'] ?? '—'),
                'site_label' => (string) ($row['site_label'] ?? '—'),
        'total_amount' => (float) ($row['amount'] ?? $row['total_amount'] ?? 0),
            ];
        }, $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const totalAmountText = Number(totalAmount || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        popup.document.write('<!doctype html><html lang="th"><head><meta charset="UTF-8"><title>รายการบิลซื้อ</title>');
        popup.document.write('<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">');
        popup.document.write('<style>');
        popup.document.write('body{font-family:Sarabun,sans-serif;padding:28px 34px;color:#1f2937;}');
        popup.document.write('.doc-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:14px;}');
        popup.document.write('.doc-title{font-size:20px;font-weight:700;margin:0;color:#0f172a;}');
        popup.document.write('.doc-sub{font-size:12px;color:#64748b;margin-top:4px;}');
        popup.document.write('.doc-meta{font-size:12px;color:#475569;text-align:right;line-height:1.5;}');
        popup.document.write('.doc-box{border:1px solid #dbe3ef;border-radius:10px;padding:0;overflow:hidden;}');
        popup.document.write('table{width:100%;border-collapse:collapse;font-size:13px;}');
        popup.document.write('thead th{background:#f8fafc;color:#334155;font-weight:700;padding:10px 12px;border-bottom:1px solid #dbe3ef;text-align:left;white-space:nowrap;}');
        popup.document.write('tbody td{padding:10px 12px;border-bottom:1px solid #eef2f7;vertical-align:top;}');
        popup.document.write('tbody tr:last-child td{border-bottom:0;}');
        popup.document.write('.col-date{width:14%;} .col-vendor{width:42%;} .col-site{width:28%;} .col-amount{width:16%;}');
        popup.document.write('.text-end{text-align:right;}');
        popup.document.write('.amount{font-weight:700;color:#0f172a;white-space:nowrap;}');
        popup.document.write('.doc-footer{margin-top:14px;display:flex;justify-content:space-between;align-items:center;gap:12px;}');
        popup.document.write('.muted{font-size:12px;color:#64748b;}');
        popup.document.write('.total-card{min-width:260px;border:1px solid #dbe3ef;border-radius:10px;padding:10px 12px;}');
        popup.document.write('.total-row{display:flex;justify-content:space-between;align-items:center;font-size:13px;}');
        popup.document.write('.total-row strong{font-size:16px;color:#0f172a;}');
        popup.document.write('.btn{display:none!important;} a{color:inherit;text-decoration:none;}');
        popup.document.write('@media print{body{padding:12mm 10mm;}}');
        popup.document.write('</style></head><body>');
        popup.document.write('<div class="doc-header">');
        popup.document.write('<div><h1 class="doc-title">รายงานรายการบิลซื้อ</h1><div class="doc-sub">เดือนรายงาน ' + monthValue + '</div></div>');
        popup.document.write('<div class="doc-meta">THEELIN CON CO., LTD.<br>พิมพ์เมื่อ: ' + printedAt + '</div>');
        popup.document.write('</div>');
        popup.document.write('<div class="doc-box">');
        if (!rows || rows.length === 0) {
            popup.document.write('<div class="muted">ไม่พบรายการตามตัวกรอง</div>');
        } else {
            popup.document.write('<table>');
            popup.document.write('<thead><tr><th class="col-date">วันที่</th><th class="col-vendor">ซื้อจาก</th><th class="col-site">ไซต์</th><th class="col-amount text-end">ยอดรวม</th></tr></thead>');
            popup.document.write('<tbody>');
            rows.forEach(function (r) {
                const amountText = Number(r.total_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                popup.document.write('<tr><td>' + (r.bill_date_text || '—') + '</td><td>' + (r.vendor_name || '—') + '</td><td>' + (r.site_label || '—') + '</td><td class="text-end amount">฿' + amountText + '</td></tr>');
            });
            popup.document.write('</tbody></table>');
        }
        popup.document.write('</div>');
        popup.document.write('<div class="doc-footer">');
        popup.document.write('<div class="muted">จำนวนบิลทั้งหมด ' + billCount.toLocaleString('en-US') + ' รายการ</div>');
        popup.document.write('<div class="total-card"><div class="total-row"><span>ยอดรวมทั้งสิ้น</span><strong>฿ ' + totalAmountText + '</strong></div></div>');
        popup.document.write('</div>');
        popup.document.write('</body></html>');
        popup.document.close();
        popup.focus();
        popup.print();
    });
})();

(function () {
    const printBtn = document.getElementById('printBillListDetailBtn');
    if (!printBtn) {
        return;
    }
    printBtn.addEventListener('click', function () {
        const popup = window.open('', '_blank', 'width=1180,height=820');
        if (!popup) {
            return;
        }
        const now = new Date();
        const printedAt = now.toLocaleString('th-TH', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
        const monthValue = <?= json_encode($month, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const totalAmount = <?= json_encode((float) $sumTotal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const rows = <?= json_encode(array_map(static function (array $row) use ($billItemsByBillId): array {
            $billId = (int) ($row['id'] ?? 0);
            $billDate = trim((string) ($row['bill_date'] ?? ''));
            $billDateText = $billDate !== '' ? date('d/m/Y', strtotime($billDate)) : '—';
            $items = [];
            foreach (($billItemsByBillId[$billId] ?? []) as $item) {
                $items[] = [
                    'line_no' => (int) ($item['line_no'] ?? 0),
                    'item_name' => (string) ($item['item_name'] ?? ''),
                    'quantity' => (float) ($item['quantity'] ?? 0),
                    'unit' => (string) ($item['unit'] ?? ''),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'line_total' => (float) ($item['line_total'] ?? 0),
                ];
            }
            return [
                'bill_date_text' => $billDateText,
                'vendor_name' => (string) ($row['supplier_name'] ?? $row['vendor_name'] ?? '—'),
                'site_label' => (string) ($row['site_label'] ?? '—'),
                'total_amount' => (float) ($row['amount'] ?? $row['total_amount'] ?? 0),
                'items' => $items,
            ];
        }, $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        const totalAmountText = Number(totalAmount || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        popup.document.write('<!doctype html><html lang="th"><head><meta charset="UTF-8"><title>รายการบิลซื้อ (พร้อมรายละเอียด)</title>');
        popup.document.write('<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">');
        popup.document.write('<style>');
        popup.document.write('body{font-family:Sarabun,sans-serif;padding:28px 34px;color:#1f2937;}');
        popup.document.write('.doc-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:14px;}');
        popup.document.write('.doc-title{font-size:14pt;font-weight:700;margin:0;color:#0f172a;}');
        popup.document.write('.doc-sub{font-size:12pt;color:#64748b;margin-top:4px;}');
        popup.document.write('.doc-meta{font-size:12px;color:#475569;text-align:right;line-height:1.5;}');
        popup.document.write('.bill-card{border:1px solid #dbe3ef;border-radius:10px;padding:12px 14px;margin-bottom:12px;}');
        popup.document.write('.bill-head{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:8px;font-size:9.5pt;line-height:1.35;padding-bottom:7px;border-bottom:1px solid #e5eaf3;}');
        popup.document.write('.bill-head strong{font-weight:600;font-size:10pt;color:#0f172a;}');
        popup.document.write('.bill-items{margin-top:8px;}');
        popup.document.write('table{width:100%;border-collapse:collapse;font-size:11pt;}');
        popup.document.write('thead th{background:#f8fafc;color:#334155;font-weight:700;padding:8px;border-bottom:1px solid #dbe3ef;text-align:left;}');
        popup.document.write('tbody td{padding:7px 8px;border-bottom:1px solid #eef2f7;vertical-align:top;}');
        popup.document.write('.col-item{white-space:nowrap;}');
        popup.document.write('tbody tr:last-child td{border-bottom:0;}');
        popup.document.write('.text-end{text-align:right;}');
        popup.document.write('.doc-footer{margin-top:14px;display:flex;justify-content:flex-end;}');
        popup.document.write('.total-card{min-width:280px;border:1px solid #dbe3ef;border-radius:10px;padding:10px 12px;}');
        popup.document.write('.total-row{display:flex;justify-content:space-between;align-items:center;font-size:13px;}');
        popup.document.write('.total-row strong{font-size:16px;color:#0f172a;}');
        popup.document.write('@media print{body{padding:10mm 8mm;}}');
        popup.document.write('</style></head><body>');
        popup.document.write('<div class="doc-header">');
        popup.document.write('<div><h1 class="doc-title">รายงานรายการบิลซื้อ (พร้อมรายละเอียด)</h1><div class="doc-sub">เดือนรายงาน ' + monthValue + '</div></div>');
        popup.document.write('<div class="doc-meta">THEELIN CON CO., LTD.<br>พิมพ์เมื่อ: ' + printedAt + '</div>');
        popup.document.write('</div>');

        if (!rows || rows.length === 0) {
            popup.document.write('<div>ไม่พบรายการตามตัวกรอง</div>');
        } else {
            rows.forEach(function (r) {
                const amountText = Number(r.total_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                popup.document.write('<section class="bill-card">');
                popup.document.write('<div class="bill-head"><div>วันที่ <strong>' + escapeHtml(r.bill_date_text || '—') + '</strong></div><div>ซื้อจาก <strong>' + escapeHtml(r.vendor_name || '—') + '</strong></div><div>ไซต์ <strong>' + escapeHtml(r.site_label || '—') + '</strong></div><div class="text-end">ยอดรวม <strong>฿' + amountText + '</strong></div></div>');
                popup.document.write('<div class="bill-items"><table><thead><tr><th style="width:3rem;">#</th><th class="col-item">รายการ</th><th class="text-end" style="width:5rem;">จำนวน</th><th style="width:4rem;">หน่วย</th><th class="text-end" style="width:7rem;">ราคา/หน่วย</th><th class="text-end" style="width:8rem;">รวม</th></tr></thead><tbody>');
                if (r.items && r.items.length > 0) {
                    r.items.forEach(function (it) {
                        const qty = Number(it.quantity || 0).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
                        const unitPrice = Number(it.unit_price || 0).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
                        const lineTotal = Number(it.line_total || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        popup.document.write('<tr><td>' + escapeHtml(it.line_no || '') + '</td><td class="col-item">' + escapeHtml(it.item_name || '—') + '</td><td class="text-end">' + qty + '</td><td>' + escapeHtml(it.unit || '') + '</td><td class="text-end">' + unitPrice + '</td><td class="text-end">' + lineTotal + '</td></tr>');
                    });
                } else {
                    popup.document.write('<tr><td colspan="6" class="text-center">ไม่มีรายละเอียดรายการ</td></tr>');
                }
                popup.document.write('</tbody></table></div>');
                popup.document.write('</section>');
            });
        }

        popup.document.write('<div class="doc-footer"><div class="total-card"><div class="total-row"><span>ยอดรวมทั้งสิ้น</span><strong>฿ ' + totalAmountText + '</strong></div></div></div>');
        popup.document.write('</body></html>');
        popup.document.close();
        popup.focus();
        popup.print();
    });
})();
</script>
</body>
</html>
