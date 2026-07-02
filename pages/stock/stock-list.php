<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_flash.php';
require_once dirname(__DIR__, 2) . '/includes/stock_site_data.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$canManage = user_is_finance_role();
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
$balances = [];
$movements = [];
$totalIn = 0.0;
$totalOut = 0.0;
$balanceRows = [];
$stockLiveChecksum = '';

if ($selectedSite !== null) {
    $livePayload = tnc_stock_site_live_payload($siteId);
    $products = $livePayload['products'];
    $stockLiveChecksum = $livePayload['checksum'];

    foreach ($livePayload['movements'] as $m) {
        $pid = (int) ($m['product_id'] ?? 0);
        if ($pid <= 0 || !isset($products[$pid])) {
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

        $tref = trim((string) ($m['transfer_ref'] ?? ''));
        $movements[] = [
            'id' => (int) ($m['id'] ?? 0),
            'product_id' => $pid,
            'created_at' => $createdAt,
            'product_name' => (string) ($products[$pid]['name'] ?? ''),
            'product_code' => $productCode,
            'unit' => (string) ($products[$pid]['unit'] ?? 'ชิ้น'),
            'movement_type' => (string) ($m['movement_type'] ?? ''),
            'qty' => $qty,
            'person_name' => $personName,
            'person_label' => $personName !== '' ? $personName : 'ไม่ระบุ',
            'note' => $note,
            'photo_path' => $photoPath,
            'transfer_ref' => $tref,
            'source_site_name' => trim((string) ($m['source_site_name'] ?? '')),
            'source_site_id' => (int) ($m['source_site_id'] ?? 0),
            'counter_site_name' => trim((string) ($m['counter_site_name'] ?? '')),
            'counter_site_id' => (int) ($m['counter_site_id'] ?? 0),
            'is_transfer' => $tref !== '',
        ];
    }

    foreach ($products as $pid => $p) {
        $balanceRows[] = [
            'name' => (string) ($p['name'] ?? ''),
            'code' => (string) ($p['code'] ?? ''),
            'unit' => (string) ($p['unit'] ?? 'ชิ้น'),
            'qty' => (float) ($balances[$pid] ?? 0.0),
        ];
    }
    usort($balanceRows, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>คลังสินค้า | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/tnc-app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/stock-list.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="tnc-app-body tnc-layout-list">

<div class="no-print">
    <?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
</div>

<div class="container py-4 pb-5">
    <?php if ($selectedSite === null): ?>
        <div class="tnc-page-head mb-4 flex-wrap gap-3">
            <div>
                <p class="tnc-page-kicker">Stock</p>
                <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-geo-alt"></i></span>เลือกไซต์งาน</h1>
            </div>
            <?php if ($canManage): ?>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= htmlspecialchars(app_path('pages/stock/stock-product-form.php')) ?>" class="btn btn-outline-orange rounded-pill">
                        <i class="bi bi-box me-1"></i>เพิ่มประเภทสินค้า/วัสดุ
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $stockHubFlash = tnc_flash_from_query($_GET);
        if ($stockHubFlash !== null && !empty($_GET['product_added'])) {
            $stockHubFlash['message'] = 'เพิ่มประเภทสินค้า/วัสดุเรียบร้อยแล้ว';
        }
        tnc_render_flash($stockHubFlash);
        ?>
        <div class="row g-3">
            <?php if (!$sites): ?>
                <div class="col-12">
                    <div class="alert alert-warning mb-0">ยังไม่มีไซต์งานในระบบ กรุณาเพิ่มที่หน้า <a href="<?= htmlspecialchars(app_path('pages/sites/site-picker.php')) ?>">เลือกไซต์งาน</a></div>
                </div>
            <?php else: ?>
                <?php foreach ($sites as $site): ?>
                    <?php $sid = (int) ($site['id'] ?? 0); ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <a class="stock-card bg-white d-block p-3 text-decoration-none text-dark h-100" href="<?= htmlspecialchars(app_path('pages/stock/stock-list.php')) ?>?site_id=<?= $sid ?>">
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
    <div class="tnc-page-head mb-4 no-print flex-wrap gap-3">
        <div>
            <p class="tnc-page-kicker">Stock</p>
            <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-box-seam"></i></span>คลังสินค้า</h1>
            <div class="text-muted small mt-1">
                ไซต์: <span class="fw-semibold"><?= htmlspecialchars((string) ($selectedSite['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars(app_path('pages/stock/stock-list.php')) ?>" class="btn btn-outline-secondary rounded-pill">
                <i class="bi bi-arrow-left me-1"></i>เปลี่ยนไซต์
            </a>
            <button type="button" class="btn btn-outline-dark rounded-pill" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>พิมพ์รายงาน
            </button>
            <?php if ($canManage): ?>
                <a href="<?= htmlspecialchars(app_path('pages/stock/stock-product-form.php')) ?>?site_id=<?= $siteId ?>" class="btn btn-outline-orange rounded-pill">
                    <i class="bi bi-box me-1"></i>เพิ่มอุปกรณ์
                </a>
                <a href="<?= htmlspecialchars(app_path('pages/stock/stock-adjust.php')) ?>?site_id=<?= $siteId ?>" class="btn btn-orange fw-bold rounded-pill">
                    <i class="bi bi-plus-lg me-1"></i>บันทึกรายการ
                </a>
                <a href="<?= htmlspecialchars(app_path('pages/stock/stock-adjust.php')) ?>?site_id=<?= $siteId ?>&amp;mode=transfer" class="btn btn-outline-orange rounded-pill">
                    <i class="bi bi-arrow-left-right me-1"></i>โอนไซต์
                </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="print-only print-header mb-3">
        <div class="print-title">สรุปรายการของเข้าโครงการ <?= htmlspecialchars((string) ($selectedSite['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="print-date">วันที่พิมพ์ <?= htmlspecialchars(date('d/m/Y')) ?></div>
    </div>

    <?php
    $stockFlash = tnc_flash_from_query($_GET);
    if ($stockFlash !== null) {
        $stockFlash['message'] = match (true) {
            !empty($_GET['saved']) => 'บันทึกรายการเรียบร้อยแล้ว',
            !empty($_GET['updated']) => 'แก้ไขรายการเรียบร้อยแล้ว',
            !empty($_GET['deleted']) => 'ลบรายการเรียบร้อยแล้ว',
            !empty($_GET['product_added']) => 'เพิ่มประเภทสินค้า/วัสดุเรียบร้อยแล้ว',
            default => $stockFlash['message'],
        };
    }
    if (!empty($_GET['error']) && (string) $_GET['error'] === 'transfer_locked') {
        $stockFlash = ['type' => 'warning', 'message' => 'รายการโอนระหว่างไซต์แก้ไขแยกทีละรายการไม่ได้ ให้ลบคู่รายการแล้วบันทึกใหม่'];
    }
    tnc_render_flash($stockFlash);
    ?>

    <div class="row g-3 mb-3 no-print">
        <div class="col-12 col-md-4">
            <div class="stock-kpi p-3">
                <div class="small text-muted">รายการเข้า (ตามตัวกรอง)</div>
                <div class="h4 mb-0 text-success fw-bold"><?= number_format($totalIn, 2) ?></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="stock-kpi p-3">
                <div class="small text-muted">รายการออก (ตามตัวกรอง)</div>
                <div class="h4 mb-0 text-danger fw-bold"><?= number_format($totalOut, 2) ?></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="stock-kpi p-3">
                <div class="small text-muted">จำนวนรายการที่พบ</div>
                <div class="h4 mb-0 fw-bold"><?= number_format(count($movements)) ?> รายการ</div>
            </div>
        </div>
    </div>

    <form id="stockFilterForm" method="get" action="<?= htmlspecialchars(app_path('pages/stock/stock-list.php')) ?>" class="stock-card p-3 mb-3 bg-white no-print">
        <input type="hidden" name="site_id" value="<?= $siteId ?>">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-2">
                <label class="form-label small fw-semibold mb-1" for="stockFilterDateFrom">วันที่เริ่ม</label>
                <input type="date" name="date_from" id="stockFilterDateFrom" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>" class="form-control">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small fw-semibold mb-1" for="stockFilterDateTo">วันที่สิ้นสุด</label>
                <input type="date" name="date_to" id="stockFilterDateTo" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>" class="form-control">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold mb-1" for="stockFilterProductCode">รหัสสินค้า</label>
                <input
                    type="text"
                    name="product_code"
                    id="stockFilterProductCode"
                    value="<?= htmlspecialchars($productCodeQuery, ENT_QUOTES, 'UTF-8') ?>"
                    class="form-control"
                >
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold mb-1" for="stockFilterPerson">ค้นหาชื่อผู้นำเข้า/นำออก</label>
                <input type="text" name="person" id="stockFilterPerson" value="<?= htmlspecialchars($personQuery, ENT_QUOTES, 'UTF-8') ?>" class="form-control">
            </div>
            <div class="col-12 col-md-2 d-grid">
                <button type="submit" class="btn btn-outline-secondary">ค้นหา</button>
            </div>
        </div>
    </form>

    <div class="stock-card bg-white mb-4 no-print">
        <div class="table-responsive tnc-mobile-table-wrap">
            <table class="table table-hover align-middle mb-0 tnc-mobile-table" id="stockMovementsTable" style="width:100%">
                <caption class="visually-hidden">รายการเคลื่อนไหวสต็อกของไซต์ <?= htmlspecialchars((string) ($selectedSite['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></caption>
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">วันที่</th>
                        <th>ชื่อคน</th>
                        <th>อุปกรณ์</th>
                        <th>ต้นทาง / ปลายทาง</th>
                        <th>ประเภท</th>
                        <th class="text-end">จำนวน</th>
                        <th>รูป</th>
                        <th class="pe-3">หมายเหตุ</th>
                        <?php if ($canManage): ?><th class="text-end pe-3">จัดการ</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$movements): ?>
                        <tr><td colspan="<?= $canManage ? '9' : '8' ?>" class="text-center text-muted py-5">
                            <div>ไม่พบรายการตามตัวกรอง</div>
                            <?php if ($canManage): ?>
                                <p class="stock-empty-hint small mb-0 mt-2">ลองปรับตัวกรอง หรือ <a href="<?= htmlspecialchars(app_path('pages/stock/stock-adjust.php')) ?>?site_id=<?= $siteId ?>">บันทึกรายการใหม่</a></p>
                            <?php else: ?>
                                <p class="stock-empty-hint small mb-0 mt-2">ลองปรับช่วงวันที่หรือคำค้นหา</p>
                            <?php endif; ?>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($movements as $m): ?>
                        <tr
                            class="stock-row"
                            data-date="<?= htmlspecialchars(substr((string) $m['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?>"
                            data-product-code="<?= htmlspecialchars((string) $m['product_code'], ENT_QUOTES, 'UTF-8') ?>"
                            data-person-name="<?= htmlspecialchars((string) $m['person_label'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <td class="ps-3 small text-nowrap tnc-mobile-primary" data-label="วันที่"><?= htmlspecialchars(date('d/m/Y', strtotime((string) $m['created_at']))) ?></td>
                            <td class="small fw-semibold" data-label="ชื่อคน"><?= htmlspecialchars((string) $m['person_label'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="อุปกรณ์">
                                <div class="fw-semibold"><?= htmlspecialchars((string) $m['product_name']) ?></div>
                                <div class="small text-muted">
                                    <?= htmlspecialchars((string) ($m['product_code'] !== '' ? $m['product_code'] . ' | ' : '')) ?>
                                    <?= htmlspecialchars((string) $m['unit']) ?>
                                </div>
                            </td>
                            <td class="small text-muted" data-label="ต้นทาง / ปลายทาง">
                                <?php
                                $route = 'ไม่ระบุ';
                                if ((string) $m['movement_type'] === 'in' && ((string) $m['source_site_name'] !== '' || (int) $m['source_site_id'] > 0)) {
                                    $route = 'จาก ' . ((string) $m['source_site_name'] !== '' ? (string) $m['source_site_name'] : ('ไซต์ #' . (int) $m['source_site_id']));
                                } elseif ((string) $m['movement_type'] === 'out' && ((string) $m['counter_site_name'] !== '' || (int) $m['counter_site_id'] > 0)) {
                                    $route = 'ไป ' . ((string) $m['counter_site_name'] !== '' ? (string) $m['counter_site_name'] : ('ไซต์ #' . (int) $m['counter_site_id']));
                                }
                                echo htmlspecialchars($route, ENT_QUOTES, 'UTF-8');
                                ?>
                            </td>
                            <td data-label="ประเภท">
                                <?php if ((string) $m['movement_type'] === 'out'): ?>
                                    <span class="badge bg-danger-subtle text-danger-emphasis border stock-txn-badge">นำออก</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success-emphasis border stock-txn-badge">นำเข้า</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold tnc-mobile-amount <?= (float) $m['qty'] < 0 ? 'text-danger' : 'text-success' ?>" data-label="จำนวน">
                                <?= number_format(abs((float) $m['qty']), 2) ?>
                            </td>
                            <td data-label="รูป">
                                <?php if ((string) $m['photo_path'] !== ''): ?>
                                    <a href="<?= htmlspecialchars(app_path((string) $m['photo_path']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="stock-thumb-link">
                                        <img src="<?= htmlspecialchars(app_path((string) $m['photo_path']), ENT_QUOTES, 'UTF-8') ?>" class="stock-thumb" alt="รูปแนบรายการ" loading="lazy" decoding="async">
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">ไม่มี</span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-3 small" data-label="หมายเหตุ"><?= htmlspecialchars((string) ($m['note'] !== '' ? $m['note'] : 'ไม่ระบุ'), ENT_QUOTES, 'UTF-8') ?></td>
                            <?php if ($canManage): ?>
                                <td class="text-end pe-3 text-nowrap tnc-mobile-actions stock-row-actions" data-label="จัดการ">
                                    <?php if (!empty($m['is_transfer'])): ?>
                                        <span class="small text-muted me-1">โอนไซต์</span>
                                    <?php else: ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-orange stock-edit-btn"
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
                                    <?php endif; ?>
                                    <a
                                        href="<?= htmlspecialchars(app_path('actions/stock-handler.php')) ?>?action=delete_transaction&id=<?= (int) $m['id'] ?>&site_id=<?= $siteId ?>&_csrf=<?= rawurlencode(csrf_token()) ?>"
                                        class="btn btn-sm btn-outline-danger tnc-delete-post"
                                        aria-label="ลบรายการ (ต้องใส่รหัสผ่าน)"
                                        title="ลบรายการ (ต้องใส่รหัสผ่าน)"
                                    >ลบ</a>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="stock-card bg-white print-balance-only no-print">
        <div class="table-responsive tnc-mobile-table-wrap">
            <table class="table table-sm align-middle mb-0 w-100 tnc-mobile-table" id="stockBalanceTable" style="width:100%">
                <caption class="visually-hidden">ยอดคงเหลือสต็อกของไซต์ <?= htmlspecialchars((string) ($selectedSite['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></caption>
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
                            <td class="ps-3 small text-muted tnc-mobile-primary" data-label="รหัส"><?= htmlspecialchars((string) $r['code']) ?></td>
                            <td data-label="อุปกรณ์"><?= htmlspecialchars((string) $r['name']) ?></td>
                            <td class="small text-muted" data-label="หน่วย"><?= htmlspecialchars((string) $r['unit']) ?></td>
                            <td class="text-end pe-3 fw-semibold tnc-mobile-amount <?= (float) $r['qty'] < 0 ? 'text-danger' : '' ?>" data-label="คงเหลือ">
                                <?= number_format((float) $r['qty'], 0) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($canManage): ?>
        <div class="modal fade" id="editMovementModal" tabindex="-1" aria-labelledby="editMovementModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" action="<?= htmlspecialchars(app_path('actions/stock-handler.php')) ?>?action=update_transaction">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="id" id="editMovementId">
                        <input type="hidden" name="site_id" value="<?= $siteId ?>">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="editMovementModalLabel">แก้ไขรายการบันทึก</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-2">
                                <label class="form-label small fw-semibold" for="editTxnDate">วันที่</label>
                                <input type="date" name="txn_date" id="editTxnDate" class="form-control" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold" for="editPersonName">ชื่อผู้นำเข้า/นำออก</label>
                                <input type="text" name="person_name" id="editPersonName" class="form-control" maxlength="120" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold" for="editProductId">ประเภทสินค้า/วัสดุ</label>
                                <select name="product_id" id="editProductId" class="form-select" required>
                                    <?php foreach ($products as $pid => $p): ?>
                                        <option value="<?= (int) $pid ?>"><?= htmlspecialchars((string) ($p['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold" for="editMovementType">ประเภท</label>
                                    <select name="movement_type" id="editMovementType" class="form-select" required>
                                        <option value="in">รับเข้า</option>
                                        <option value="out">จ่ายออก</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold" for="editQty">จำนวน</label>
                                    <input type="number" name="qty" id="editQty" class="form-control" min="0.01" step="0.01" required>
                                </div>
                            </div>
                            <div class="mt-2">
                                <label class="form-label small fw-semibold" for="editNote">หมายเหตุ</label>
                                <textarea name="note" id="editNote" class="form-control" rows="2" maxlength="500"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                            <button type="submit" class="btn btn-orange">บันทึกการแก้ไข</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($selectedSite !== null): ?>
<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.__tncStockListBoot = {
    checksum: <?= json_encode($stockLiveChecksum, JSON_UNESCAPED_UNICODE) ?>,
    checksumUrl: <?= json_encode(app_path('actions/live-datasets.php?dataset=stock_site_checksum&site_id=' . $siteId), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    movementColumnDefs: <?= json_encode(array_merge(
        [['targets' => [6, 7], 'orderable' => false]],
        $canManage ? [['targets' => [8], 'orderable' => false, 'searchable' => false]] : []
    ), JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= htmlspecialchars(app_path('assets/js/stock-list.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php else: ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>
</body>
</html>
