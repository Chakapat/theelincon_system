<?php

declare(strict_types=1);


require_once __DIR__ . '/_page_root.php';
use Theelincon\Rtdb\Db;

session_start();
require_once THEELINCON_ROOT . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$pos = $_SESSION['role'] ?? 'user';
$canManage = ($pos === 'admin' || $pos === 'Accounting');
$siteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$personQuery = trim((string) ($_GET['person'] ?? ''));
$productCodeQuery = trim((string) ($_GET['product_code'] ?? ''));

$sites = Db::tableRows('sites');
usort($sites, static function (array $a, array $b): int {
    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
$siteById = [];
foreach ($sites as $s) {
    $sid = (int) ($s['id'] ?? 0);
    if ($sid > 0) {
        $siteById[$sid] = $s;
    }
}
$selectedSite = $siteById[$siteId] ?? null;

$products = [];
foreach (Db::tableRows('stock_products') as $p) {
    if (empty($p['is_active'])) {
        continue;
    }
    $pid = (int) ($p['id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    $products[$pid] = $p;
}

$balances = [];
$movements = [];
$totalIn = 0.0;
$totalOut = 0.0;

foreach (Db::tableRows('stock_movements') as $m) {
    $pid = (int) ($m['product_id'] ?? 0);
    if ($pid <= 0 || !isset($products[$pid])) {
        continue;
    }
    $rowSiteId = (int) ($m['site_id'] ?? 0);
    if ($selectedSite !== null && $rowSiteId !== $siteId) {
        continue;
    }
    $productCode = trim((string) ($products[$pid]['code'] ?? ''));
    if ($productCodeQuery !== '' && mb_stripos($productCode, $productCodeQuery, 0, 'UTF-8') === false) {
        continue;
    }

    $qty = (float) ($m['qty'] ?? 0);
    $balances[$pid] = ($balances[$pid] ?? 0.0) + $qty;

    $createdAt = (string) ($m['created_at'] ?? '');
    $ymd = strlen($createdAt) >= 10 ? substr($createdAt, 0, 10) : '';
    if ($dateFrom !== '' && $ymd !== '' && $ymd < $dateFrom) {
        continue;
    }
    if ($dateTo !== '' && $ymd !== '' && $ymd > $dateTo) {
        continue;
    }

    $personName = trim((string) ($m['person_name'] ?? ''));
    if ($personName === '') {
        $personName = trim((string) ($m['note'] ?? ''));
    }
    if ($personQuery !== '' && mb_stripos($personName, $personQuery, 0, 'UTF-8') === false) {
        continue;
    }

    $note = (string) ($m['note'] ?? '');
    $photoPath = '';
    if (preg_match('/\[photo\](.+)$/m', $note, $match) === 1) {
        $photoPath = trim((string) ($match[1] ?? ''));
        $note = trim((string) preg_replace('/\s*\[photo\].+$/m', '', $note));
    }
    if ($qty >= 0) {
        $totalIn += $qty;
    } else {
        $totalOut += abs($qty);
    }

    $movements[] = [
        'id' => (int) ($m['id'] ?? 0),
        'product_id' => $pid,
        'created_at' => $createdAt,
        'product_name' => (string) ($products[$pid]['name'] ?? ''),
        'product_code' => $productCode,
        'unit' => (string) ($products[$pid]['unit'] ?? 'ชิ้น'),
        'movement_type' => (string) ($m['movement_type'] ?? ''),
        'qty' => $qty,
        'person_name' => $personName !== '' ? $personName : '—',
        'note' => $note,
        'photo_path' => $photoPath,
    ];
}

usort($movements, static function (array $a, array $b): int {
    return strcmp((string) $b['created_at'], (string) $a['created_at']);
});

$balanceRows = [];
foreach ($products as $pid => $p) {
    $balanceRows[] = [
        'name' => (string) ($p['name'] ?? ''),
        'code' => (string) ($p['code'] ?? ''),
        'unit' => (string) ($p['unit'] ?? 'ชิ้น'),
        'qty' => (float) ($balances[$pid] ?? 0.0),
    ];
}
usort($balanceRows, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Dashboard | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f8fafc; }
        .stock-card { border: 1px solid #e9edf4; border-radius: 1rem; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04); }
        .kpi { border-radius: 0.9rem; border: 1px solid #e8edf5; background: #ffffff; }
        .txn-badge { font-size: 0.78rem; }
        .thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 0.5rem; border: 1px solid #e2e8f0; }
        .mobile-stack td { vertical-align: top; }
        .pager-wrap { border-top: 1px solid #e9edf4; background: #fff; }
        .print-only { display: none; }
        @media print {
            @page { size: A4 portrait; margin: 12mm; }
            body { background: #fff; font-size: 12px; color: #111827; }
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            .stock-card { border: 0; box-shadow: none; border-radius: 0; }
            .table { font-size: 12px; margin-bottom: 0; }
            .thumb { width: 72px; height: 72px; }
            a { color: inherit !important; text-decoration: none !important; }
            .print-balance-only { display: block !important; }
            .container { max-width: 100% !important; padding: 0 !important; }
            .print-header { border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 12px !important; }
            .print-title { font-size: 19px; font-weight: 700; margin-bottom: 3px; }
            .print-date { font-size: 12px; color: #374151; }
            .print-balance-only .table thead th {
                background: #f3f4f6 !important;
                color: #111827 !important;
                border-top: 1px solid #9ca3af !important;
                border-bottom: 1px solid #9ca3af !important;
                padding-top: 8px;
                padding-bottom: 8px;
            }
            .print-balance-only .table td {
                border-color: #d1d5db !important;
                padding-top: 7px;
                padding-bottom: 7px;
            }
            .print-balance-only .table tbody tr:nth-child(even) {
                background: #f9fafb !important;
            }
        }
    </style>
</head>
<body>

<div class="no-print">
    <?php include THEELINCON_ROOT . '/components/navbar.php'; ?>
</div>

<div class="container py-4 pb-5">
    <?php if ($selectedSite === null): ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-geo-alt text-warning me-2"></i>เลือกไซต์งาน</h4>
            </div>
            <?php if ($canManage): ?>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= htmlspecialchars(app_path('pages/stock-product-form.php')) ?>" class="btn btn-outline-primary rounded-pill">
                        <i class="bi bi-box me-1"></i>เพิ่มประเภทสินค้า/วัสดุ
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($_GET['product_added'])): ?>
            <div class="alert alert-success rounded-3">เพิ่มประเภทสินค้า/วัสดุเรียบร้อยแล้ว</div>
        <?php endif; ?>
        <div class="row g-3">
            <?php if (!$sites): ?>
                <div class="col-12">
                    <div class="alert alert-warning mb-0">ยังไม่มีไซต์งานในระบบ กรุณาเพิ่มที่หน้า <a href="<?= htmlspecialchars(app_path('pages/sites.php')) ?>">จัดการไซต์</a></div>
                </div>
            <?php else: ?>
                <?php foreach ($sites as $site): ?>
                    <?php $sid = (int) ($site['id'] ?? 0); ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <a class="stock-card bg-white d-block p-3 text-decoration-none text-dark h-100" href="<?= htmlspecialchars(app_path('pages/stock-list.php')) ?>?site_id=<?= $sid ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="small text-muted">ไซต์งาน</div>
                                    <div class="fw-bold"><?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 no-print">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-box-seam text-warning me-2"></i>Stock Dashboard</h4>
            <div class="text-muted small">
                ไซต์: <span class="fw-semibold"><?= htmlspecialchars((string) ($selectedSite['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars(app_path('pages/stock-list.php')) ?>" class="btn btn-outline-secondary rounded-pill">
                <i class="bi bi-arrow-left me-1"></i>เปลี่ยนไซต์
            </a>
            <button type="button" class="btn btn-outline-dark rounded-pill" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>พิมพ์รายงาน
            </button>
            <?php if ($canManage): ?>
                <a href="<?= htmlspecialchars(app_path('pages/stock-adjust.php')) ?>?site_id=<?= $siteId ?>" class="btn btn-warning text-white fw-bold rounded-pill">
                    <i class="bi bi-plus-lg me-1"></i>บันทึกรายการเข้า-ออก
                </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="print-only print-header mb-3">
        <div class="print-title">สรุปรายการของเข้าโครงการ <?= htmlspecialchars((string) ($selectedSite['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="print-date">วันที่พิมพ์ <?= htmlspecialchars(date('d/m/Y')) ?></div>
    </div>

    <?php if (!empty($_GET['saved'])): ?>
        <div class="alert alert-success rounded-3">บันทึกรายการเรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if (!empty($_GET['updated'])): ?>
        <div class="alert alert-success rounded-3">แก้ไขรายการเรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if (!empty($_GET['deleted'])): ?>
        <div class="alert alert-success rounded-3">ลบรายการเรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if (!empty($_GET['product_added'])): ?>
        <div class="alert alert-success rounded-3">เพิ่มประเภทสินค้า/วัสดุเรียบร้อยแล้ว</div>
    <?php endif; ?>

    <div class="row g-3 mb-3 no-print">
        <div class="col-12 col-md-4">
            <div class="kpi p-3">
                <div class="small text-muted">รายการเข้า (ตามตัวกรอง)</div>
                <div class="h4 mb-0 text-success fw-bold"><?= number_format($totalIn, 2) ?></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="kpi p-3">
                <div class="small text-muted">รายการออก (ตามตัวกรอง)</div>
                <div class="h4 mb-0 text-danger fw-bold"><?= number_format($totalOut, 2) ?></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="kpi p-3">
                <div class="small text-muted">จำนวนรายการที่พบ</div>
                <div class="h4 mb-0 fw-bold"><?= number_format(count($movements)) ?> รายการ</div>
            </div>
        </div>
    </div>

    <form id="stockFilterForm" method="get" action="<?= htmlspecialchars(app_path('pages/stock-list.php')) ?>" class="stock-card p-3 mb-3 bg-white no-print">
        <input type="hidden" name="site_id" value="<?= $siteId ?>">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-2">
                <label class="form-label small fw-semibold mb-1">วันที่เริ่ม</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>" class="form-control">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small fw-semibold mb-1">วันที่สิ้นสุด</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>" class="form-control">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold mb-1">รหัสสินค้า</label>
                <input
                    type="text"
                    name="product_code"
                    value="<?= htmlspecialchars($productCodeQuery, ENT_QUOTES, 'UTF-8') ?>"
                    class="form-control"
                >
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold mb-1">ค้นหาชื่อผู้นำเข้า/นำออก</label>
                <input type="text" name="person" value="<?= htmlspecialchars($personQuery, ENT_QUOTES, 'UTF-8') ?>" class="form-control">
            </div>
            <div class="col-12 col-md-2 d-grid">
                <button class="btn btn-outline-secondary">ค้นหา</button>
            </div>
        </div>
    </form>

    <div class="stock-card bg-white mb-4 no-print">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 mobile-stack" id="stockMovementsTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">วันที่</th>
                        <th>ชื่อคน</th>
                        <th>อุปกรณ์</th>
                        <th>ประเภท</th>
                        <th class="text-end">จำนวน</th>
                        <th>รูป</th>
                        <th class="pe-3">หมายเหตุ</th>
                        <?php if ($canManage): ?><th class="text-end pe-3">จัดการ</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$movements): ?>
                        <tr><td colspan="<?= $canManage ? '8' : '7' ?>" class="text-center text-muted py-5">ไม่พบรายการตามตัวกรอง</td></tr>
                    <?php else: ?>
                        <?php foreach ($movements as $m): ?>
                        <tr
                            class="stock-row"
                            data-date="<?= htmlspecialchars(substr((string) $m['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?>"
                            data-product-code="<?= htmlspecialchars((string) $m['product_code'], ENT_QUOTES, 'UTF-8') ?>"
                            data-person-name="<?= htmlspecialchars((string) $m['person_name'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <td class="ps-3 small text-nowrap"><?= htmlspecialchars(date('d/m/Y', strtotime((string) $m['created_at']))) ?></td>
                            <td class="small fw-semibold"><?= htmlspecialchars((string) $m['person_name']) ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars((string) $m['product_name']) ?></div>
                                <div class="small text-muted">
                                    <?= htmlspecialchars((string) ($m['product_code'] !== '' ? $m['product_code'] . ' | ' : '')) ?>
                                    <?= htmlspecialchars((string) $m['unit']) ?>
                                </div>
                            </td>
                            <td>
                                <?php if ((string) $m['movement_type'] === 'out'): ?>
                                    <span class="badge bg-danger-subtle text-danger-emphasis border txn-badge">นำออก</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success-emphasis border txn-badge">นำเข้า</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold <?= (float) $m['qty'] < 0 ? 'text-danger' : 'text-success' ?>">
                                <?= number_format(abs((float) $m['qty']), 2) ?>
                            </td>
                            <td>
                                <?php if ((string) $m['photo_path'] !== ''): ?>
                                    <a href="<?= htmlspecialchars(app_path((string) $m['photo_path']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                                        <img src="<?= htmlspecialchars(app_path((string) $m['photo_path']), ENT_QUOTES, 'UTF-8') ?>" class="thumb" alt="รูปแนบ">
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-3 small"><?= htmlspecialchars((string) ($m['note'] !== '' ? $m['note'] : '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <?php if ($canManage): ?>
                                <td class="text-end pe-3 text-nowrap">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary stock-edit-btn"
                                        data-id="<?= (int) $m['id'] ?>"
                                        data-product-id="<?= (int) $m['product_id'] ?>"
                                        data-date="<?= htmlspecialchars(substr((string) $m['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?>"
                                        data-person="<?= htmlspecialchars((string) $m['person_name'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-type="<?= htmlspecialchars((string) $m['movement_type'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-qty="<?= htmlspecialchars((string) abs((float) $m['qty']), ENT_QUOTES, 'UTF-8') ?>"
                                        data-note="<?= htmlspecialchars((string) $m['note'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editMovementModal"
                                    >แก้ไข</button>
                                    <a
                                        href="<?= htmlspecialchars(app_path('actions/stock-handler.php')) ?>?action=delete_transaction&id=<?= (int) $m['id'] ?>&site_id=<?= $siteId ?>&_csrf=<?= rawurlencode(csrf_token()) ?>"
                                        class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('ยืนยันการลบรายการนี้?');"
                                    >ลบ</a>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($movements)): ?>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 pager-wrap no-print">
                <div class="small text-muted" id="stockPagerInfo">แสดงรายการ</div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="stockPrevBtn">ก่อนหน้า</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="stockNextBtn">ถัดไป</button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="stock-card bg-white print-balance-only">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">รหัส</th>
                        <th>อุปกรณ์</th>
                        <th>หน่วย</th>
                        <th class="text-end pe-3">คงเหลือ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($balanceRows as $r): ?>
                        <tr>
                            <td class="ps-3 small text-muted"><?= htmlspecialchars((string) $r['code']) ?></td>
                            <td><?= htmlspecialchars((string) $r['name']) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars((string) $r['unit']) ?></td>
                            <td class="text-end pe-3 fw-semibold <?= (float) $r['qty'] < 0 ? 'text-danger' : '' ?>">
                                <?= number_format((float) $r['qty'], 0) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($canManage): ?>
        <div class="modal fade" id="editMovementModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" action="<?= htmlspecialchars(app_path('actions/stock-handler.php')) ?>?action=update_transaction">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="id" id="editMovementId">
                        <input type="hidden" name="site_id" value="<?= $siteId ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">แก้ไขรายการบันทึก</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-2">
                                <label class="form-label small fw-semibold">วันที่</label>
                                <input type="date" name="txn_date" id="editTxnDate" class="form-control" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold">ชื่อผู้นำเข้า/นำออก</label>
                                <input type="text" name="person_name" id="editPersonName" class="form-control" maxlength="120" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold">ประเภทสินค้า/วัสดุ</label>
                                <select name="product_id" id="editProductId" class="form-select" required>
                                    <?php foreach ($products as $pid => $p): ?>
                                        <option value="<?= (int) $pid ?>"><?= htmlspecialchars((string) ($p['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">ประเภท</label>
                                    <select name="movement_type" id="editMovementType" class="form-select" required>
                                        <option value="in">รับเข้า</option>
                                        <option value="out">จ่ายออก</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">จำนวน</label>
                                    <input type="number" name="qty" id="editQty" class="form-control" min="0.01" step="0.01" required>
                                </div>
                            </div>
                            <div class="mt-2">
                                <label class="form-label small fw-semibold">หมายเหตุ</label>
                                <textarea name="note" id="editNote" class="form-control" rows="2" maxlength="500"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                            <button type="submit" class="btn btn-primary">บันทึกการแก้ไข</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var table = document.getElementById('stockMovementsTable');
    if (!table) {
        return;
    }
    var tbody = table.querySelector('tbody');
    if (!tbody) {
        return;
    }
    var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr.stock-row'));
    if (!allRows.length) {
        return;
    }
    var filteredRows = allRows.slice();
    var noResultRow = null;
    var filterForm = document.getElementById('stockFilterForm');
    var dateFromInput = filterForm ? filterForm.querySelector('input[name="date_from"]') : null;
    var dateToInput = filterForm ? filterForm.querySelector('input[name="date_to"]') : null;
    var productCodeInput = filterForm ? filterForm.querySelector('input[name="product_code"]') : null;
    var personInput = filterForm ? filterForm.querySelector('input[name="person"]') : null;

    var prevBtn = document.getElementById('stockPrevBtn');
    var nextBtn = document.getElementById('stockNextBtn');
    var infoEl = document.getElementById('stockPagerInfo');
    if (!prevBtn || !nextBtn || !infoEl) {
        return;
    }

    var pageSize = 5;
    var page = 1;
    var totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));

    function ensureNoResultRow() {
        if (noResultRow) {
            return noResultRow;
        }
        noResultRow = document.createElement('tr');
        var td = document.createElement('td');
        td.colSpan = <?= $canManage ? '8' : '7' ?>;
        td.className = 'text-center text-muted py-5';
        td.textContent = 'ไม่พบรายการตามตัวกรอง';
        noResultRow.appendChild(td);
        return noResultRow;
    }

    function normalize(value) {
        return String(value || '').trim().toLowerCase();
    }

    function applyTableFilter() {
        var dateFrom = dateFromInput ? dateFromInput.value : '';
        var dateTo = dateToInput ? dateToInput.value : '';
        var productCodeQuery = normalize(productCodeInput ? productCodeInput.value : '');
        var personQuery = normalize(personInput ? personInput.value : '');

        filteredRows = allRows.filter(function (row) {
            var rowDate = String(row.getAttribute('data-date') || '');
            if (dateFrom && rowDate && rowDate < dateFrom) {
                return false;
            }
            if (dateTo && rowDate && rowDate > dateTo) {
                return false;
            }
            var rowCode = normalize(row.getAttribute('data-product-code'));
            if (productCodeQuery && rowCode.indexOf(productCodeQuery) === -1) {
                return false;
            }
            var rowPerson = normalize(row.getAttribute('data-person-name'));
            if (personQuery && rowPerson.indexOf(personQuery) === -1) {
                return false;
            }
            return true;
        });

        page = 1;
        totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
        renderPage();
    }

    function renderPage() {
        var start = (page - 1) * pageSize;
        var end = start + pageSize;
        allRows.forEach(function (row) {
            row.style.display = 'none';
        });

        if (filteredRows.length === 0) {
            tbody.appendChild(ensureNoResultRow());
            infoEl.textContent = 'แสดง 0-0 จาก 0 รายการ';
            prevBtn.disabled = true;
            nextBtn.disabled = true;
            return;
        }

        if (noResultRow && noResultRow.parentNode) {
            noResultRow.parentNode.removeChild(noResultRow);
        }

        filteredRows.forEach(function (row, idx) {
            row.style.display = idx >= start && idx < end ? '' : 'none';
        });

        var shownStart = start + 1;
        var shownEnd = Math.min(end, filteredRows.length);
        infoEl.textContent = 'แสดง ' + shownStart + '-' + shownEnd + ' จาก ' + filteredRows.length + ' รายการ';
        prevBtn.disabled = page <= 1;
        nextBtn.disabled = page >= totalPages;
    }

    prevBtn.addEventListener('click', function () {
        if (page > 1) {
            page -= 1;
            renderPage();
        }
    });
    nextBtn.addEventListener('click', function () {
        if (page < totalPages) {
            page += 1;
            renderPage();
        }
    });

    if (filterForm) {
        filterForm.addEventListener('submit', function (event) {
            event.preventDefault();
            applyTableFilter();
        });

        var timer = null;
        [dateFromInput, dateToInput, productCodeInput, personInput].forEach(function (inputEl) {
            if (!inputEl) {
                return;
            }
            var eventName = inputEl.getAttribute('type') === 'date' ? 'change' : 'input';
            inputEl.addEventListener(eventName, function () {
                if (timer) {
                    clearTimeout(timer);
                }
                timer = window.setTimeout(applyTableFilter, 180);
            });
        });
    }

    renderPage();
})();

(function () {
    var buttons = document.querySelectorAll('.stock-edit-btn');
    if (!buttons.length) {
        return;
    }
    var idEl = document.getElementById('editMovementId');
    var dateEl = document.getElementById('editTxnDate');
    var personEl = document.getElementById('editPersonName');
    var productEl = document.getElementById('editProductId');
    var typeEl = document.getElementById('editMovementType');
    var qtyEl = document.getElementById('editQty');
    var noteEl = document.getElementById('editNote');
    if (!idEl || !dateEl || !personEl || !productEl || !typeEl || !qtyEl || !noteEl) {
        return;
    }
    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            idEl.value = btn.getAttribute('data-id') || '';
            dateEl.value = btn.getAttribute('data-date') || '';
            personEl.value = btn.getAttribute('data-person') || '';
            productEl.value = btn.getAttribute('data-product-id') || '';
            typeEl.value = btn.getAttribute('data-type') || 'in';
            qtyEl.value = btn.getAttribute('data-qty') || '';
            noteEl.value = btn.getAttribute('data-note') || '';
        });
    });
})();
</script>
</body>
</html>
