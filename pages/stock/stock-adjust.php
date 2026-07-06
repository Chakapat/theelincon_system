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
$openTransferMode = isset($_GET['mode']) && (string) $_GET['mode'] === 'transfer';
$operatorDisplay = trim((string) ($_SESSION['name'] ?? ''));
$sites = Db::tableRows('sites');
usort($sites, static function (array $a, array $b): int {
    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
$handler = app_path('actions/stock-handler.php');

/**
 * @param list<array<string, mixed>> $prodRows
 */
function stock_adjust_render_product_options(array $prodRows, int $selectedId = 0): void
{
    echo '<option value="">เลือกอุปกรณ์</option>';
    foreach ($prodRows as $p) {
        $pid = (int) ($p['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $code = trim((string) ($p['code'] ?? ''));
        $name = trim((string) ($p['name'] ?? ''));
        $unit = trim((string) ($p['unit'] ?? ''));
        $label = $code !== '' ? ($code . ' · ' . $name . ' (' . $unit . ')') : ($name . ' (' . $unit . ')');
        $sel = $selectedId === $pid ? ' selected' : '';
        echo '<option value="' . $pid . '"' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php
    require_once dirname(__DIR__, 2) . '/includes/tnc_ops_head.php';
    tnc_ops_head(['title' => 'บันทึก Stock | THEELIN CON', 'sarabun_weights' => '400;600;700']);
    ?>
</head>
<body class="tnc-app-body tnc-layout-form">

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
                'product' => 'กรุณาเลือกอุปกรณ์จากรายการ',
                'site' => 'กรุณาเลือกไซต์งาน',
                'transfer' => 'กรุณาเลือกไซต์ต้นทางและปลายทางให้ต่างกัน',
            ];
            echo htmlspecialchars($map[(string) $_GET['error']] ?? 'บันทึกไม่สำเร็จ', ENT_QUOTES, 'UTF-8');
        ?></div>
    <?php endif; ?>

    <div class="btn-group mode-pill w-100 mb-3" role="group" aria-label="โหมด">
        <input type="radio" class="btn-check" name="txn_mode" id="modeIo" value="io" autocomplete="off"<?= $openTransferMode ? '' : ' checked' ?>>
        <label class="btn btn-outline-secondary rounded-start-pill" for="modeIo">รับเข้า / จ่ายออก</label>
        <input type="radio" class="btn-check" name="txn_mode" id="modeTr" value="transfer" autocomplete="off"<?= $openTransferMode ? ' checked' : '' ?>>
        <label class="btn btn-outline-secondary rounded-end-pill" for="modeTr">โอนระหว่างไซต์</label>
    </div>

    <form id="formIo" method="post" enctype="multipart/form-data" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>?action=save_transaction" class="stock-form-card bg-white p-4"<?= $openTransferMode ? ' style="display:none;"' : '' ?>>
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
            <label class="form-label fw-bold small" for="ioProductId">อุปกรณ์</label>
            <select name="product_id" id="ioProductId" class="form-select rounded-3" required>
                <?php stock_adjust_render_product_options($prodRows, $preId); ?>
            </select>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6">
                <label class="form-label fw-bold small" for="kindSel">ประเภทรายการ</label>
                <select name="movement_type" class="form-select rounded-3" required id="kindSel">
                    <option value="in">รับเข้า (+)</option>
                    <option value="out">จ่ายออก (−)</option>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-bold small" for="ioQty">จำนวน</label>
                <input type="number" name="qty" id="ioQty" step="0.01" min="0.01" class="form-control rounded-3" required>
                <div class="form-text" id="qtyHint">รับเข้า: ระบบบวกยอดที่ไซต์นี้</div>
            </div>
        </div>

        <div class="mb-3" id="ioTransferDestWrap" style="display:none;">
            <label class="form-label fw-bold small" for="ioToSiteId">ไซต์ปลายทาง (โอน)</label>
            <select name="to_site_id" id="ioToSiteId" class="form-select rounded-3">
                <option value="">ไม่โอน (จ่ายออกภายในไซต์เท่านั้น)</option>
                <?php foreach ($sites as $s): ?>
                    <?php $sid = (int) ($s['id'] ?? 0); ?>
                    <?php if ($sid <= 0) {
                        continue;
                    } ?>
                    <option value="<?= $sid ?>" data-site-id="<?= $sid ?>"><?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">เลือกไซต์ปลายทางเพื่อโอนสต็อก (สร้างรายการตัด/รับคู่กันอัตโนมัติ)</div>
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

    <form id="formTr" method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>?action=save_site_transfer" class="stock-form-card bg-white p-4 mt-3"<?= $openTransferMode ? '' : ' style="display:none;"' ?>>
        <?php csrf_field(); ?>
        <div class="row g-3 mb-2">
            <div class="col-12 col-md-6">
                <label class="form-label fw-bold small" for="trFromSiteId">ไซต์ต้นทาง (ตัดสต็อก)</label>
                <select name="from_site_id" id="trFromSiteId" class="form-select rounded-3" required>
                    <option value="">เลือกไซต์</option>
                    <?php foreach ($sites as $s): ?>
                        <?php $sid = (int) ($s['id'] ?? 0); ?>
                        <option value="<?= $sid ?>" <?= $preSiteId === $sid ? 'selected' : '' ?>><?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-bold small" for="trToSiteId">ไซต์ปลายทาง (รับเข้า)</label>
                <select name="to_site_id" id="trToSiteId" class="form-select rounded-3" required>
                    <option value="">เลือกไซต์</option>
                    <?php foreach ($sites as $s): ?>
                        <?php $sid = (int) ($s['id'] ?? 0); ?>
                        <option value="<?= $sid ?>" data-site-id="<?= $sid ?>"><?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
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
            <label class="form-label fw-bold small" for="trProductId">อุปกรณ์</label>
            <select name="product_id" id="trProductId" class="form-select rounded-3" required>
                <?php stock_adjust_render_product_options($prodRows, $preId); ?>
            </select>
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
            <button type="submit" class="btn btn-orange fw-bold rounded-pill px-4">บันทึกการโอน</button>
            <a href="<?= htmlspecialchars(app_path('pages/stock/stock-list.php') . ($preSiteId > 0 ? '?site_id=' . $preSiteId : ''), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill">กลับ</a>
        </div>
    </form>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<script>
function stockApplyTxnMode() {
    var tr = document.getElementById('formTr');
    var io = document.getElementById('formIo');
    if (!tr || !io) {
        return;
    }
    var transferMode = document.getElementById('modeTr')?.checked;
    tr.style.display = transferMode ? 'block' : 'none';
    io.style.display = transferMode ? 'none' : 'block';
}

function stockSyncTransferSiteOptions(fromSelect, toSelect) {
    if (!fromSelect || !toSelect) {
        return;
    }
    var fromId = String(fromSelect.value || '');
    Array.prototype.forEach.call(toSelect.options, function (opt) {
        if (!opt.value) {
            opt.hidden = false;
            opt.disabled = false;
            return;
        }
        var blocked = opt.value === fromId;
        opt.hidden = blocked;
        opt.disabled = blocked;
        if (blocked && toSelect.value === opt.value) {
            toSelect.value = '';
        }
    });
}

function stockSyncIoTransferDest() {
    var kindSel = document.getElementById('kindSel');
    var wrap = document.getElementById('ioTransferDestWrap');
    var siteSel = document.querySelector('#formIo select[name="site_id"]');
    var toSel = document.getElementById('ioToSiteId');
    if (!kindSel || !wrap || !toSel) {
        return;
    }
    var show = kindSel.value === 'out';
    wrap.style.display = show ? 'block' : 'none';
    if (!show) {
        toSel.value = '';
        return;
    }
    if (siteSel) {
        stockSyncTransferSiteOptions(siteSel, toSel);
    }
}

document.querySelectorAll('input[name="txn_mode"]').forEach(function (r) {
    r.addEventListener('change', stockApplyTxnMode);
});

document.getElementById('kindSel')?.addEventListener('change', function () {
    var h = document.getElementById('qtyHint');
    if (this.value === 'out') {
        if (h) {
            h.textContent = 'จ่ายออก: ระบบหักยอดที่ไซต์นี้ (หรือเลือกไซต์ปลายทางเพื่อโอน)';
        }
    } else if (h) {
        h.textContent = 'รับเข้า: ระบบบวกยอดที่ไซต์นี้';
    }
    stockSyncIoTransferDest();
});

document.querySelector('#formIo select[name="site_id"]')?.addEventListener('change', stockSyncIoTransferDest);

var trFrom = document.getElementById('trFromSiteId');
var trTo = document.getElementById('trToSiteId');
if (trFrom && trTo) {
    trFrom.addEventListener('change', function () {
        stockSyncTransferSiteOptions(trFrom, trTo);
    });
    stockSyncTransferSiteOptions(trFrom, trTo);
}

stockApplyTxnMode();
stockSyncIoTransferDest();
</script>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
