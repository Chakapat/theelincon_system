<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/cash_ledger_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$me = (int) $_SESSION['user_id'];
$handler = app_path('actions/cash-ledger-handler.php');

cash_ledger_auto_archive_monthly_if_due();

$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');

$stores = Db::filter('cash_ledger_stores', static fn (array $r): bool => !empty($r['is_active']));
usort($stores, static function (array $a, array $b): int {
    $so = ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
    if ($so !== 0) {
        return $so;
    }

    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
$sites = Db::filter('cash_ledger_sites', static fn (array $r): bool => !empty($r['is_active']));
usort($sites, static function (array $a, array $b): int {
    $so = ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
    if ($so !== 0) {
        return $so;
    }

    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
$editLines = [];
if ($editId > 0) {
    $editRow = Db::row('cash_ledger', (string) $editId);
    if ($editRow && (int) ($editRow['created_by'] ?? 0) !== $me && !$isAdmin) {
        $editRow = null;
        $editId = 0;
    }
    if ($editRow) {
        $editLines = Db::filter('cash_ledger_lines', static function (array $r) use ($editId): bool {
            return isset($r['ledger_id']) && (int) $r['ledger_id'] === $editId;
        });
        usort($editLines, static fn ($a, $b): int => (int) ($a['line_no'] ?? 0) <=> (int) ($b['line_no'] ?? 0));
        if (count($editLines) === 0) {
            $editLines = [[
                'item_description' => $editRow['description'] ?? '',
                'quantity' => 1,
                'unit_price' => (float) ($editRow['amount'] ?? 0),
                'line_total' => (float) ($editRow['amount'] ?? 0),
            ]];
        }
    }
}

$storeSearchValue = '';
$siteSearchValue = '';
if ($editRow) {
    $eStoreId = (int) ($editRow['store_id'] ?? 0);
    if ($eStoreId > 0) {
        $sn = null;
        foreach ($stores as $s) {
            if ((int) ($s['id'] ?? 0) === $eStoreId) {
                $sn = $s['name'];
                break;
            }
        }
        if ($sn === null) {
            $nr = Db::row('cash_ledger_stores', (string) $eStoreId);
            $sn = $nr ? (string) ($nr['name'] ?? '') : '';
        }
        $storeSearchValue = (string) ($sn ?? '');
    } else {
        $storeSearchValue = trim((string) ($editRow['bought_from'] ?? ''));
    }
    $eSiteId = (int) ($editRow['site_id'] ?? 0);
    if ($eSiteId > 0) {
        $zn = null;
        foreach ($sites as $s) {
            if ((int) ($s['id'] ?? 0) === $eSiteId) {
                $zn = $s['name'];
                break;
            }
        }
        if ($zn === null) {
            $nr = Db::row('cash_ledger_sites', (string) $eSiteId);
            $zn = $nr ? (string) ($nr['name'] ?? '') : '';
        }
        $siteSearchValue = (string) ($zn ?? '');
    } else {
        $siteSearchValue = trim((string) ($editRow['used_at_site'] ?? ''));
    }
}

$defaultLines = count($editLines) > 0 ? $editLines : [['item_description' => '', 'quantity' => 1, 'unit_price' => '', 'line_total' => '']];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายรับรายจ่ายภายใน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }
        .card-ledger { border-radius: 16px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,.06); }
        .line-table input[type="number"] { max-width: 7rem; }
        .line-table input.line-desc { max-width: 100%; }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-cash-stack text-success me-2"></i>บันทึกรายรับ — รายจ่าย</h4>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars(app_path('pages/cash-ledger-dashboard.php') . '?month=' . urlencode($month)) ?>" class="btn btn-outline-primary rounded-pill"><i class="bi bi-speedometer2 me-1"></i>หน้ารายการบันทึก</a>
        </div>
    </div>

    <div class="card card-ledger mb-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
                <h5 class="fw-bold mb-0"><?= $editRow ? 'แก้ไขรายการ' : 'เพิ่มรายการ' ?></h5>
                <form method="get" class="d-flex align-items-center gap-2">
                    <?php if ($editId > 0): ?><input type="hidden" name="edit" value="<?= (int) $editId ?>"><?php endif; ?>
                    <label class="small text-muted mb-0">ดูเดือน</label>
                    <input type="month" name="month" class="form-control form-control-sm rounded-3" style="width: auto;" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary rounded-3">แสดง</button>
                </form>
            </div>
            <form method="post" action="<?= htmlspecialchars($handler) ?>?action=save" class="row g-3" id="ledgerForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($editRow): ?>
                    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                <?php endif; ?>

                <div class="col-md-2">
                    <label class="form-label fw-bold small">ประเภท</label>
                    <select name="entry_type" id="entry_type" class="form-select rounded-3" required>
                        <option value="income" <?= ($editRow['entry_type'] ?? '') === 'income' ? 'selected' : '' ?>>รายรับ</option>
                        <option value="expense" <?= ($editRow['entry_type'] ?? '') === 'expense' ? 'selected' : '' ?>>รายจ่าย</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small">วันที่</label>
                    <input type="date" name="entry_date" class="form-control rounded-3" required
                           value="<?= htmlspecialchars($editRow['entry_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small">หมวด / แท็ก <span class="text-muted fw-normal">(ไม่บังคับ)</span></label>
                    <input type="text" name="category" class="form-control rounded-3" maxlength="120" placeholder="เช่น วัสดุก่อสร้าง"
                           value="<?= htmlspecialchars($editRow['category'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-bold small">หมายเหตุทั่วไป <span class="text-muted fw-normal">(ไม่บังคับ)</span></label>
                    <input type="text" name="description" class="form-control rounded-3" maxlength="1000" placeholder="เช่น ใบเสร็จเลขที่…"
                           value="<?= htmlspecialchars($editRow['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-bold small" for="store_search">ซื้อจาก (ร้านค้า / แหล่งซื้อ)</label>
                    <input type="text" name="store_search" id="store_search" class="form-control rounded-3" list="store_search_list" maxlength="255" autocomplete="off"
                           placeholder="พิมพ์ค้นหา"
                           value="<?= htmlspecialchars($storeSearchValue, ENT_QUOTES, 'UTF-8') ?>">
                    <datalist id="store_search_list">
                        <?php foreach ($stores as $s): ?>
                            <option value="<?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small" for="site_search">ใช้ที่ / ไซต์งาน</label>
                    <input type="text" name="site_search" id="site_search" class="form-control rounded-3" list="site_search_list" maxlength="255" autocomplete="off"
                           placeholder="พิมพ์ค้นหา"
                           value="<?= htmlspecialchars($siteSearchValue, ENT_QUOTES, 'UTF-8') ?>">
                    <datalist id="site_search_list">
                        <?php foreach ($sites as $s): ?>
                            <option value="<?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="col-12">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <label class="form-label fw-bold small mb-0"><span class="text-danger">*</span></label>
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-3" id="btnAddLine"><i class="bi bi-plus-lg"></i> เพิ่มแถว</button>
                    </div>
                    <div class="table-responsive border rounded-3 bg-white">
                        <table class="table table-sm mb-0 line-table align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:3rem;">#</th>
                                    <th>รายการ / รายละเอียดสินค้า</th>
                                    <th>จำนวน</th>
                                    <th style="min-width:5.5rem;">หน่วย</th>
                                    <th>ราคา/หน่วย (บาท)</th>
                                    <th>รวม (บาท)</th>
                                    <th style="width:3rem;"></th>
                                </tr>
                            </thead>
                            <tbody id="lineBody">
                                <?php foreach ($defaultLines as $idx => $ln): ?>
                                <tr class="line-row">
                                    <td class="line-no text-secondary small"><?= $idx + 1 ?></td>
                                    <td><input type="text" class="form-control form-control-sm rounded-2 line-desc" name="line_desc[]" maxlength="500" value="<?= htmlspecialchars($ln['item_description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></td>
                                    <td><input type="number" class="form-control form-control-sm rounded-2 line-qty" name="line_qty[]" step="0.001" min="0" value="<?= htmlspecialchars((string) ($ln['quantity'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                    <td><input type="text" class="form-control form-control-sm rounded-2 line-unit" name="line_unit[]" maxlength="40" value="<?= htmlspecialchars($ln['unit'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></td>
                                    <td><input type="number" class="form-control form-control-sm rounded-2 line-price" name="line_price[]" step="0.01" min="0" value="<?= htmlspecialchars((string) ($ln['unit_price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                                    <td><input type="text" class="form-control form-control-sm rounded-2 line-total bg-light" readonly value="<?= isset($ln['line_total']) && $ln['line_total'] !== '' ? htmlspecialchars(number_format((float) $ln['line_total'], 2, '.', ''), ENT_QUOTES, 'UTF-8') : '' ?>"></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger line-del" title="ลบแถว"><i class="bi bi-x-lg"></i></button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php $vm = $editRow['vat_mode'] ?? 'none'; ?>
                <div class="col-lg-4 col-md-12">
                    <label class="form-label fw-bold small">ภาษีมูลค่าเพิ่ม (VAT)</label>
                    <div class="border rounded-3 p-3 bg-white">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="vat_enabled" <?= $vm !== 'none' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="vat_enabled">มี VAT</label>
                        </div>
                        <p class="form-text small mb-2 mt-1">ปิด = ไม่มี VAT · เปิด = เลือกวิธีคิดยอดด้านล่าง</p>
                        <div id="vat_basis_wrap" class="pt-1 border-top">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_inclusive" value="inclusive"
                                    <?= ($vm === 'inclusive' || $vm === 'none') ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="vat_basis_inclusive">รวม VAT — ราคารายการรวมภาษีแล้ว</label>
                            </div>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_exclusive" value="exclusive"
                                    <?= $vm === 'exclusive' ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="vat_basis_exclusive">แยก VAT — ราคารายการยังไม่รวมภาษี (บวก VAT เพิ่ม)</label>
                            </div>
                        </div>
                        <input type="hidden" name="vat_mode" id="vat_mode" value="<?= htmlspecialchars($vm, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small">อัตรา VAT (%)</label>
                    <input type="number" name="vat_rate" id="vat_rate" class="form-control rounded-3" step="0.01" min="0" max="100"
                           value="<?= htmlspecialchars((string) ($editRow['vat_rate'] ?? '7'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small">สรุปยอด</label>
                    <div class="border rounded-3 p-3 bg-light small">
                        <div class="d-flex justify-content-between"><span>ผลรวมรายการ</span><span class="fw-bold" id="sumLines">฿0.00</span></div>
                        <div class="d-flex justify-content-between mt-1" id="rowVat"><span>VAT</span><span class="fw-bold" id="sumVat">฿0.00</span></div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between fs-6"><span class="fw-bold">ยอดรวมสุทธิ (บันทึก)</span><span class="fw-bold text-primary" id="sumGrand">฿0.00</span></div>
                    </div>
                </div>

                <div class="col-12 d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn rounded-pill px-4 text-white" style="background-color:#fd7e14;">
                        <i class="bi bi-check-lg me-1"></i><?= $editRow ? 'บันทึกการแก้ไข' : 'บันทึกรายการ' ?>
                    </button>
                    <?php if ($editRow): ?>
                        <a href="<?= htmlspecialchars(app_path('pages/cash-ledger.php') . '?month=' . urlencode($month)) ?>" class="btn btn-outline-secondary rounded-pill">ยกเลิก</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<template id="lineRowTpl">
    <tr class="line-row">
        <td class="line-no text-secondary small">0</td>
        <td><input type="text" class="form-control form-control-sm rounded-2 line-desc" name="line_desc[]" maxlength="500"></td>
        <td><input type="number" class="form-control form-control-sm rounded-2 line-qty" name="line_qty[]" step="0.001" min="0" value="1"></td>
        <td><input type="text" class="form-control form-control-sm rounded-2 line-unit" name="line_unit[]" maxlength="40" placeholder="ชิ้น"></td>
        <td><input type="number" class="form-control form-control-sm rounded-2 line-price" name="line_price[]" step="0.01" min="0" value=""></td>
        <td><input type="text" class="form-control form-control-sm rounded-2 line-total bg-light" readonly value=""></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger line-del" title="ลบแถว"><i class="bi bi-x-lg"></i></button></td>
    </tr>
</template>
<script>
(function() {
    const body = document.getElementById('lineBody');
    const tpl = document.getElementById('lineRowTpl');
    const vatMode = document.getElementById('vat_mode');
    const vatEnabled = document.getElementById('vat_enabled');
    const vatBasisWrap = document.getElementById('vat_basis_wrap');
    const vatRate = document.getElementById('vat_rate');
    const sumLinesEl = document.getElementById('sumLines');
    const sumVatEl = document.getElementById('sumVat');
    const sumGrandEl = document.getElementById('sumGrand');
    const rowVat = document.getElementById('rowVat');

    function money(n) {
        return '฿' + (Math.round(n * 100) / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function parseNum(el) {
        const v = parseFloat(String(el.value).replace(/,/g, ''));
        return Number.isFinite(v) ? v : 0;
    }

    function renumber() {
        body.querySelectorAll('.line-row').forEach((tr, i) => {
            tr.querySelector('.line-no').textContent = String(i + 1);
        });
    }

    function recalcRow(tr) {
        const q = parseNum(tr.querySelector('.line-qty'));
        const p = parseNum(tr.querySelector('.line-price'));
        const t = Math.round(q * p * 100) / 100;
        tr.querySelector('.line-total').value = t > 0 ? t.toFixed(2) : '';
    }

    function sumLineTotals() {
        let s = 0;
        body.querySelectorAll('.line-row').forEach(tr => {
            const q = parseNum(tr.querySelector('.line-qty'));
            const p = parseNum(tr.querySelector('.line-price'));
            s += Math.round(q * p * 100) / 100;
        });
        return Math.round(s * 100) / 100;
    }

    function recalcTotals() {
        body.querySelectorAll('.line-row').forEach(recalcRow);
        const S = sumLineTotals();
        const mode = (vatMode && vatMode.value) ? vatMode.value : 'none';
        let rate = parseFloat(String(vatRate && vatRate.value).replace(/,/g, ''));
        if (!Number.isFinite(rate) || rate < 0) rate = 7;
        if (rate > 100) rate = 100;

        let vat = 0, grand = S, base = S;
        if (mode === 'exclusive') {
            base = S;
            vat = Math.round(S * rate / 100 * 100) / 100;
            grand = Math.round((S + vat) * 100) / 100;
        } else if (mode === 'inclusive') {
            grand = S;
            if (rate > 0) {
                base = Math.round((S / (1 + rate / 100)) * 100) / 100;
                vat = Math.round((S - base) * 100) / 100;
            } else {
                base = S;
                vat = 0;
            }
        } else {
            base = S;
            vat = 0;
            grand = S;
        }

        sumLinesEl.textContent = money(S);
        sumVatEl.textContent = money(vat);
        sumGrandEl.textContent = money(grand);
        if (rowVat) rowVat.style.display = mode === 'none' ? 'none' : 'flex';
        renumber();
    }

    function bindRow(tr) {
        tr.querySelectorAll('.line-qty, .line-price').forEach(inp => inp.addEventListener('input', () => { recalcRow(tr); recalcTotals(); }));
        const del = tr.querySelector('.line-del');
        if (del) del.addEventListener('click', () => {
            if (body.querySelectorAll('.line-row').length <= 1) return;
            tr.remove();
            recalcTotals();
        });
    }

    body.querySelectorAll('.line-row').forEach(bindRow);
    document.getElementById('btnAddLine').addEventListener('click', () => {
        const node = tpl.content.firstElementChild.cloneNode(true);
        body.appendChild(node);
        bindRow(node);
        recalcTotals();
    });
    function syncVatModeFromUi() {
        if (!vatMode || !vatEnabled) return;
        if (!vatEnabled.checked) {
            vatMode.value = 'none';
        } else {
            const r = document.querySelector('input[name="vat_basis"]:checked');
            vatMode.value = r ? r.value : 'inclusive';
        }
    }

    function updateVatBasisUi() {
        if (!vatBasisWrap || !vatEnabled) return;
        const on = vatEnabled.checked;
        vatBasisWrap.classList.toggle('opacity-50', !on);
        vatBasisWrap.style.pointerEvents = on ? '' : 'none';
        vatBasisWrap.setAttribute('aria-disabled', on ? 'false' : 'true');
    }

    document.querySelectorAll('input[name="vat_basis"]').forEach(r => {
        r.addEventListener('change', () => { syncVatModeFromUi(); recalcTotals(); });
    });
    if (vatEnabled) {
        vatEnabled.addEventListener('change', () => {
            syncVatModeFromUi();
            updateVatBasisUi();
            recalcTotals();
        });
    }
    if (vatRate) vatRate.addEventListener('input', recalcTotals);
    const ledgerForm = document.getElementById('ledgerForm');
    if (ledgerForm) {
        ledgerForm.addEventListener('submit', () => { syncVatModeFromUi(); });
    }
    syncVatModeFromUi();
    updateVatBasisUi();
    recalcTotals();
})();

const params = new URLSearchParams(window.location.search);
if (params.get('saved') === '1') {
    Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', confirmButtonColor: '#fd7e14' }).then(() => {
        const u = new URL(window.location.href);
        u.searchParams.delete('saved');
        history.replaceState({}, '', u.pathname + (u.search ? u.search : '') + u.hash);
    });
}
if (params.get('deleted') === '1') {
    Swal.fire({ icon: 'success', title: 'ลบแล้ว', confirmButtonColor: '#fd7e14' }).then(() => {
        const u = new URL(window.location.href);
        u.searchParams.delete('deleted');
        history.replaceState({}, '', u.pathname + (u.search ? u.search : '') + u.hash);
    });
}
if (params.get('err')) {
    const map = { need_lines: 'กรุณากรอกอย่างน้อย 1 แถวรายการ', line_total: 'ผลรวมรายการต้องมากกว่า 0', save_failed: 'บันทึกไม่สำเร็จ ลองใหม่อีกครั้ง' };
    Swal.fire({ icon: 'error', title: 'ไม่สามารถดำเนินการได้', text: map[params.get('err')] || params.get('err'), confirmButtonColor: '#fd7e14' });
}
</script>
</body>
</html>
