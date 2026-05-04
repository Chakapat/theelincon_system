<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$canManage = user_is_finance_role();
if (!$canManage) {
    header('Location: ' . app_path('pages/stock/stock-list.php'));
    exit();
}

$prodRows = Db::filter('stock_products', static fn (array $r): bool => !empty($r['is_active']));
Db::sortRows($prodRows, 'name', false);
$preId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
$preSiteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;
$operatorDisplay = trim((string) ($_SESSION['name'] ?? ''));
$productLabelById = [];
$productIdByLabel = [];
foreach ($prodRows as $p) {
    $pid = (int) ($p['id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    $label = trim((string) ($p['name'] ?? '')) . ' (' . trim((string) ($p['unit'] ?? '')) . ')';
    $productLabelById[$pid] = $label;
    $productIdByLabel[$label] = $pid;
}
$preProductLabel = $preId > 0 && isset($productLabelById[$preId]) ? $productLabelById[$preId] : '';
$sites = Db::tableRows('sites');
usort($sites, static function (array $a, array $b): int {
    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
$handler = app_path('actions/stock-handler.php');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บันทึก Stock | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f8fafc; }
        .stock-form-card { border: 1px solid #e8edf5; border-radius: 1rem; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05); }
        .mode-pill .btn-check:checked + .btn { background: #fd7e14; border-color: #fd7e14; color: #fff; }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 pb-5" style="max-width: 760px;">
    <h5 class="fw-bold mb-2"><i class="bi bi-plus-circle text-warning me-2"></i>บันทึกรายการคลัง</h5>
    <div class="text-muted small mb-3">รับเข้า / จ่ายออก หรือโอนระหว่างไซต์ (ไซต์ปลายทางจะเห็นเป็นรับเข้าพร้อมระบุว่ามาจากไซต์ใด)</div>

    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger rounded-3"><?php
            $map = [
                'insufficient' => 'จำนวนคงเหลือไม่พอสำหรับรายการนำออกหรือโอน',
                'qty' => 'กรุณากรอกจำนวนให้ถูกต้อง (มากกว่า 0)',
                'type' => 'กรุณาเลือกประเภทการทำรายการ',
                'product' => 'กรุณาเลือกอุปกรณ์',
                'site' => 'กรุณาเลือกไซต์งาน',
                'transfer' => 'กรุณาเลือกไซต์ต้นทางและปลายทางให้ถูกต้อง',
            ];
            echo htmlspecialchars($map[(string) $_GET['error']] ?? 'บันทึกไม่สำเร็จ', ENT_QUOTES, 'UTF-8');
        ?></div>
    <?php endif; ?>

    <div class="btn-group mode-pill w-100 mb-3" role="group" aria-label="โหมด">
        <input type="radio" class="btn-check" name="txn_mode" id="modeIo" value="io" autocomplete="off" checked>
        <label class="btn btn-outline-secondary rounded-start-pill" for="modeIo">รับเข้า / จ่ายออก</label>
        <input type="radio" class="btn-check" name="txn_mode" id="modeTr" value="transfer" autocomplete="off">
        <label class="btn btn-outline-secondary rounded-end-pill" for="modeTr">โอนระหว่างไซต์</label>
    </div>

    <form id="formIo" method="post" enctype="multipart/form-data" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>?action=save_transaction" class="stock-form-card bg-white p-4">
        <?php csrf_field(); ?>
        <div class="mb-3">
            <label class="form-label fw-bold small">ไซต์งาน</label>
            <select name="site_id" class="form-select rounded-3" required>
                <option value="">— เลือกไซต์ —</option>
                <?php foreach ($sites as $s): ?>
                    <?php $sid = (int) ($s['id'] ?? 0); ?>
                    <option value="<?= $sid ?>" <?= $preSiteId === $sid ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label fw-bold small">วันที่</label>
                <input type="date" name="txn_date" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" class="form-control rounded-3" required>
            </div>
            <div class="col-12 col-md-8">
                <label class="form-label fw-bold small">ผู้ดำเนินการ</label>
                <input type="text" name="person_name" class="form-control rounded-3" required maxlength="120" placeholder="ชื่อผู้ดำเนินการ" value="<?= htmlspecialchars($operatorDisplay, ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small mt-3">อุปกรณ์</label>
            <input type="text" class="form-control rounded-3" id="ioProductSearch" list="stockProductDatalist" autocomplete="off" placeholder="พิมพ์ค้นหาชื่ออุปกรณ์หรือหน่วย" value="<?= htmlspecialchars($preProductLabel, ENT_QUOTES, 'UTF-8') ?>">
            <datalist id="stockProductDatalist">
                <?php foreach ($prodRows as $p): ?>
                    <?php $pl = trim((string) ($p['name'] ?? '')) . ' (' . trim((string) ($p['unit'] ?? '')) . ')'; ?>
                    <option value="<?= htmlspecialchars($pl, ENT_QUOTES, 'UTF-8') ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <input type="hidden" name="product_id" id="ioProductId" value="<?= $preId > 0 ? (string) $preId : '' ?>">
            <div class="form-text">เลือกจากรายการที่ตรงกับที่พิมพ์ หรือเลือกจากรายการแนะนำ</div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6">
                <label class="form-label fw-bold small">ประเภทรายการ</label>
                <select name="movement_type" class="form-select rounded-3" required id="kindSel">
                    <option value="in">รับเข้า (+)</option>
                    <option value="out">จ่ายออก (−)</option>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-bold small">จำนวน</label>
                <input type="number" name="qty" step="0.01" min="0.01" class="form-control rounded-3" required>
                <div class="form-text" id="qtyHint">รับเข้า: ระบบบวกยอดที่ไซต์นี้</div>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label fw-bold small">หมายเหตุ</label>
            <textarea name="note" class="form-control rounded-3" maxlength="500" rows="2"></textarea>
        </div>
        <div class="mb-4">
            <label class="form-label fw-bold small">รูปภาพ (ไม่บังคับ)</label>
            <input type="file" name="photo" class="form-control rounded-3" accept="image/*">
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-warning text-white fw-bold rounded-pill px-4">บันทึก</button>
            <a href="<?= htmlspecialchars(app_path('pages/stock/stock-list.php') . ($preSiteId > 0 ? '?site_id=' . $preSiteId : ''), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill">กลับ</a>
        </div>
    </form>

    <form id="formTr" method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>?action=save_site_transfer" class="stock-form-card bg-white p-4 mt-3" style="display:none;">
        <?php csrf_field(); ?>
        <div class="row g-3 mb-2">
            <div class="col-12 col-md-6">
                <label class="form-label fw-bold small">ไซต์ต้นทาง (ตัดสต็อก)</label>
                <select name="from_site_id" class="form-select rounded-3" required>
                    <option value="">— เลือก —</option>
                    <?php foreach ($sites as $s): ?>
                        <?php $sid = (int) ($s['id'] ?? 0); ?>
                        <option value="<?= $sid ?>" <?= $preSiteId === $sid ? 'selected' : '' ?>><?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-bold small">ไซต์ปลายทาง (รับเข้า)</label>
                <select name="to_site_id" class="form-select rounded-3" required>
                    <option value="">— เลือก —</option>
                    <?php foreach ($sites as $s): ?>
                        <?php $sid = (int) ($s['id'] ?? 0); ?>
                        <option value="<?= $sid ?>"><?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label fw-bold small">วันที่</label>
                <input type="date" name="txn_date" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" class="form-control rounded-3" required>
            </div>
            <div class="col-12 col-md-8">
                <label class="form-label fw-bold small">ผู้ดำเนินการ</label>
                <input type="text" name="person_name" class="form-control rounded-3" required maxlength="120" placeholder="ชื่อผู้ดำเนินการ" value="<?= htmlspecialchars($operatorDisplay, ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>
        <div class="mb-3 mt-2">
            <label class="form-label fw-bold small">อุปกรณ์</label>
            <input type="text" class="form-control rounded-3" id="trProductSearch" list="stockProductDatalist" autocomplete="off" placeholder="พิมพ์ค้นหาชื่ออุปกรณ์หรือหน่วย" value="<?= htmlspecialchars($preProductLabel, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="product_id" id="trProductId" value="<?= $preId > 0 ? (string) $preId : '' ?>">
            <div class="form-text">เลือกจากรายการที่ตรงกับที่พิมพ์ หรือเลือกจากรายการแนะนำ</div>
        </div>
        <div class="mb-3">
            <label class="form-label fw-bold small">จำนวนที่โอน</label>
            <input type="number" name="qty" step="0.01" min="0.01" class="form-control rounded-3" required>
            <div class="form-text">ระบบสร้าง 2 รายการ: ตัดจากไซต์ต้นทาง และรับเข้าที่ไซต์ปลายทางพร้อมแหล่งที่มา</div>
        </div>
        <div class="mb-4">
            <label class="form-label fw-bold small">หมายเหตุ (ไม่บังคับ)</label>
            <textarea name="note" class="form-control rounded-3" maxlength="500" rows="2"></textarea>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary fw-bold rounded-pill px-4">บันทึกการโอน</button>
            <a href="<?= htmlspecialchars(app_path('pages/stock/stock-list.php') . ($preSiteId > 0 ? '?site_id=' . $preSiteId : ''), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill">กลับ</a>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
var stockProductIdByLabel = <?= json_encode($productIdByLabel, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;

function stockSyncProductId(inputEl, hiddenEl) {
    if (!inputEl || !hiddenEl) return;
    var v = (inputEl.value || '').trim();
    var id = stockProductIdByLabel[v];
    if (id !== undefined && id !== null) {
        hiddenEl.value = String(id);
        return;
    }
    var lower = v.toLowerCase();
    for (var label in stockProductIdByLabel) {
        if (!Object.prototype.hasOwnProperty.call(stockProductIdByLabel, label)) continue;
        if (label.trim().toLowerCase() === lower) {
            hiddenEl.value = String(stockProductIdByLabel[label]);
            inputEl.value = label;
            return;
        }
    }
    hiddenEl.value = '';
}

function stockWireProductSearch(inputEl, hiddenEl) {
    if (!inputEl || !hiddenEl) return;
    ['change', 'blur', 'input'].forEach(function (ev) {
        inputEl.addEventListener(ev, function () {
            stockSyncProductId(inputEl, hiddenEl);
        });
    });
    stockSyncProductId(inputEl, hiddenEl);
}

document.getElementById('formIo')?.addEventListener('submit', function (e) {
    var inp = document.getElementById('ioProductSearch');
    var hid = document.getElementById('ioProductId');
    stockSyncProductId(inp, hid);
    if (!hid || !hid.value) {
        e.preventDefault();
        alert('กรุณาเลือกอุปกรณ์จากรายการให้ตรงกับชื่อที่แสดง (พิมพ์ให้ตรงหรือเลือกจากรายการแนะนำ)');
        inp?.focus();
    }
});
document.getElementById('formTr')?.addEventListener('submit', function (e) {
    var inp = document.getElementById('trProductSearch');
    var hid = document.getElementById('trProductId');
    stockSyncProductId(inp, hid);
    if (!hid || !hid.value) {
        e.preventDefault();
        alert('กรุณาเลือกอุปกรณ์จากรายการให้ตรงกับชื่อที่แสดง (พิมพ์ให้ตรงหรือเลือกจากรายการแนะนำ)');
        inp?.focus();
    }
});

stockWireProductSearch(document.getElementById('ioProductSearch'), document.getElementById('ioProductId'));
stockWireProductSearch(document.getElementById('trProductSearch'), document.getElementById('trProductId'));

document.querySelectorAll('input[name="txn_mode"]').forEach(function (r) {
    r.addEventListener('change', function () {
        var tr = document.getElementById('formTr');
        var io = document.getElementById('formIo');
        if (!tr || !io) return;
        if (document.getElementById('modeTr').checked) {
            tr.style.display = 'block';
            io.style.display = 'none';
        } else {
            tr.style.display = 'none';
            io.style.display = 'block';
        }
    });
});
document.getElementById('kindSel').addEventListener('change', function () {
    var h = document.getElementById('qtyHint');
    if (this.value === 'out') h.textContent = 'จ่ายออก: ระบบหักยอดที่ไซต์นี้';
    else h.textContent = 'รับเข้า: ระบบบวกยอดที่ไซต์นี้';
});
</script>
</body>
</html>
