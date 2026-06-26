<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/hire_form_rows.php';
require_once dirname(__DIR__, 2) . '/includes/contractors.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

if (!user_can('po.create')) {
    header('Location: ' . app_path('pages/purchase/purchase-order-list.php') . '?error=forbidden');
    exit();
}

$contractorRows = Db::tableRows('contractors');
usort($contractorRows, static function (array $a, array $b): int {
    return strnatcasecmp(tnc_contractor_full_name_th($a), tnc_contractor_full_name_th($b));
});

$sites = Db::tableRows('sites');
Db::sortRows($sites, 'name', false);
$sitesUrl = app_path('pages/sites/site-picker.php');

$po_number = Purchase::generateWorkOrderNumber();
$errorCode = trim((string) ($_GET['error'] ?? ''));
$listUrl = app_path('pages/purchase/work-order-list.php');
$handlerUrl = app_path('actions/action-handler.php') . '?action=create_hire_contract_po';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ออก Work Order (WO) | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/purchase-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/hire-line-table.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/pr-hire-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/po-hire-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        .po-hire-shell { max-width: 1080px; }
        .po-hire-create-page .container-fluid { padding-top: 1rem !important; padding-bottom: 1.5rem !important; }
        .po-hire-create-page .po-from-pr-head { padding: 1rem 1.15rem !important; }
        .po-hire-create-page .po-from-pr-head h1 { font-size: 1.2rem !important; }
        .po-hire-create-page .po-from-pr-head .sub { font-size: 0.82rem; }
        .po-hire-create-page .po-from-pr-card > .p-4 { padding: 1.15rem !important; }
        .po-hire-create-page .section-card { border: 1px solid #e9ecef; border-radius: 10px; background: #fff; padding: 0.85rem !important; }
        .po-hire-create-page .section-title { font-size: 0.92rem; font-weight: 700; color: var(--tnc-orange); margin-bottom: 0.55rem; }
        .po-hire-create-page .po-field-label { font-size: 0.75rem; font-weight: 600; color: var(--tnc-muted); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.25rem; }
        .po-hire-create-page .form-control,
        .po-hire-create-page .form-select { font-size: 0.92rem; padding: 0.42rem 0.65rem; }
        .po-hire-create-page .form-text { font-size: 0.78rem; margin-top: 0.2rem; }
        .po-hire-create-page .row.g-3 { --bs-gutter-y: 0.75rem; }
        .po-hire-create-page .btn-orange { padding: 0.55rem 1rem; font-size: 0.95rem; }
        .po-hire-create-page .hire-lines-toolbar .btn { font-size: 0.82rem; padding: 0.28rem 0.55rem; }
    </style>
</head>
<body class="po-hire-mode po-hire-create-page purchase-module tnc-app-body tnc-layout-form">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container-fluid px-3 px-lg-4 py-3 py-md-4">
    <div class="row justify-content-center">
        <div class="col-12 po-hire-layout-inner">
            <div class="po-hire-shell mx-auto">
                <div class="card po-from-pr-card border-0">
                    <div class="po-from-pr-head">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <h1 class="d-flex align-items-center gap-2 mb-0">
                                    <i class="bi bi-file-earmark-ruled-fill opacity-90"></i>
                                    ออก Work Order (WO)
                                </h1>
                                <div class="sub">ใบสั่งงานสัญญาจ้าง · รันเลข WO-TNC-xxx · ส่งให้ผู้รับจ้าง · จากนั้นค่อยออก PO สั่งจ่ายรายงวด/ครั้ง</div>
                            </div>
                            <a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm rounded-pill">กลับรายการ PO</a>
                        </div>
                    </div>
                    <div class="p-3 p-md-3">
                        <?php if ($errorCode === 'contractor_required'): ?>
                            <div class="alert alert-warning py-2">กรุณาเลือกผู้รับจ้าง</div>
                        <?php elseif ($errorCode === 'site_required'): ?>
                            <div class="alert alert-warning py-2">กรุณาเลือกชื่อโครงการ — ถ้ายังไม่มีในระบบให้<a href="<?= htmlspecialchars($sitesUrl, ENT_QUOTES, 'UTF-8') ?>" class="alert-link">เพิ่มที่หน้าจัดการไซต์</a></div>
                        <?php elseif ($errorCode === 'scope_required'): ?>
                            <div class="alert alert-warning py-2">กรุณากรอกหมายเหตุ</div>
                        <?php elseif ($errorCode === 'invalid_hire_rows'): ?>
                            <div class="alert alert-warning py-2">กรุณากรอกรายการงานอย่างน้อย 1 รายการให้ถูกต้อง</div>
                        <?php endif; ?>

                        <form action="<?= htmlspecialchars($handlerUrl, ENT_QUOTES, 'UTF-8') ?>" method="POST" data-tnc-fullnav="1">
                            <?php csrf_field(); ?>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <div class="po-field-label">เลขที่ WO (Work Order)</div>
                                    <input type="text" class="form-control bg-light border-0" value="<?= htmlspecialchars($po_number, ENT_QUOTES, 'UTF-8') ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="po-field-label" for="installment_total">จำนวนงวดจ่าย</label>
                                    <input type="number" name="installment_total" id="installment_total" class="form-control" min="0" max="120" value="1">
                                    <div class="form-text">ใส่ <strong>0</strong> = ไม่ระบุจำนวนงวด — การสั่งจ่ายแต่ละครั้งจะนับเป็น «ครั้ง» ไม่ใช่งวด</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="po-field-label" for="site_id">ชื่อโครงการ <span class="text-danger">*</span></label>
                                    <select name="site_id" id="site_id" class="form-select" required>
                                        <option value="" disabled selected>— เลือกโครงการ —</option>
                                        <?php foreach ($sites as $site): ?>
                                            <?php $sid = (int) ($site['id'] ?? 0); if ($sid <= 0) { continue; } ?>
                                            <option value="<?= $sid ?>"><?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">ดึงจากรายการไซต์ในระบบ · <a href="<?= htmlspecialchars($sitesUrl, ENT_QUOTES, 'UTF-8') ?>">จัดการไซต์ / เพิ่มโครงการใหม่</a></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="po-field-label" for="contractor_search">ผู้รับจ้าง <span class="text-danger">*</span></label>
                                    <input type="text" id="contractor_search" class="form-control" list="contractor_list" placeholder="เลือกผู้รับจ้าง" autocomplete="off" required>
                                    <datalist id="contractor_list">
                                        <?php foreach ($contractorRows as $cr): ?>
                                            <option value="<?= htmlspecialchars(tnc_contractor_display_label($cr), ENT_QUOTES, 'UTF-8') ?>" data-id="<?= (int) ($cr['id'] ?? 0) ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                    <input type="hidden" name="contractor_id" id="contractor_id" value="">
                                </div>
                                <div class="col-12">
                                    <label class="po-field-label" for="work_conditions">หมายเหตุ <span class="text-danger">*</span></label>
                                    <textarea name="work_conditions" id="work_conditions" class="form-control" rows="3" required maxlength="2000" placeholder="ขอบเขตงาน เงื่อนไขการชำระ ข้อตกลงอื่น ๆ (แสดงบนเอกสารพิมพ์)"></textarea>
                                </div>
                            </div>

                            <div class="section-card mb-3 hire-lines-section" data-tnc-hire-root>
                                <div class="section-title"><i class="bi bi-table me-1"></i>รายละเอียดสัญญา / มูลค่างาน</div>
                                <div class="hire-table-panel">
                                <div class="table-responsive hire-table-scroll">
                                    <table class="table align-middle mb-0 table-hire-lines" id="hireInstallmentTable">
                                        <thead>
                                            <tr>
                                                <th class="hire-col-no text-center">#</th>
                                                <th class="hire-col-desc">รายการ</th>
                                                <th class="hire-col-qty text-end">จำนวน</th>
                                                <th class="hire-col-unit text-end">หน่วย</th>
                                                <th class="hire-col-money text-end">ค่าวัสดุ</th>
                                                <th class="hire-col-money text-end">ค่าแรง</th>
                                                <th class="hire-col-money text-end">ราคา/หน่วย</th>
                                                <th class="hire-col-money text-end">ราคารวม</th>
                                                <th class="hire-col-action text-center">ลบ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php tnc_hire_form_default_rows('hire', 'po'); ?>
                                        </tbody>
                                    </table>
                                </div>
                                </div>
                                <div class="hire-lines-toolbar mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="addHireGroupBtn" data-tnc-hire-add="group"><i class="bi bi-folder-plus me-1"></i>หัวข้อหลัก</button>
                                    <button type="button" class="btn btn-sm btn-outline-orange" id="addHireRowBtn" data-tnc-hire-add="item"><i class="bi bi-plus-circle me-1"></i>รายการย่อย</button>
                                </div>
                            </div>

                            <div class="section-card mb-3">
                                <div class="section-title"><i class="bi bi-calculator me-1"></i>สรุปมูลค่าสัญญา</div>
                                <div class="po-hire-summary-grid">
                                    <div class="po-hire-summary-settings">
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" name="vat_enabled" id="vat_enabled">
                                            <label class="form-check-label fw-semibold" for="vat_enabled">บวก VAT 7%</label>
                                        </div>
                                        <input type="hidden" name="retention_value" value="0">
                                        <input type="hidden" name="withholding_type" value="none">
                                        <input type="hidden" name="retention_type" value="fixed">
                                    </div>
                                    <div class="po-hire-totals-card">
                                        <div class="po-hire-sum-row"><span>ยอดรวม</span><span id="subtotal_text">0.00</span></div>
                                        <div class="po-hire-sum-row text-tnc-orange"><span>VAT</span><span id="vat_text">0.00</span></div>
                                        <div class="po-hire-grand-row">
                                            <span class="label">มูลค่าสัญญา</span>
                                            <span class="amount" id="grand_total">0.00</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-none d-lg-flex justify-content-end pt-1">
                                <button type="submit" class="btn btn-orange rounded-pill fw-semibold px-4">ยืนยันออก Work Order</button>
                            </div>

                            <div class="tnc-mobile-sticky-cta d-lg-none">
                                <div class="tnc-mobile-sticky-inner">
                                    <div class="tnc-mobile-sticky-meta">
                                        <div class="tnc-mobile-sticky-label">มูลค่าสัญญา</div>
                                        <div class="tnc-mobile-sticky-total" id="grand_total_sticky">0.00</div>
                                    </div>
                                    <div class="tnc-mobile-sticky-actions">
                                        <button type="submit" class="btn btn-orange rounded-pill fw-semibold">ยืนยัน WO</button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php
$hireLineJsPath = dirname(__DIR__, 2) . '/assets/js/hire-line-table.js';
$hireLineJsVer = is_file($hireLineJsPath) ? (string) filemtime($hireLineJsPath) : '1';
?>
<script src="<?= htmlspecialchars(app_path('assets/js/hire-line-table.js') . '?v=' . $hireLineJsVer, ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function () {
    function initHireContractCreatePage() {
        const search = document.getElementById('contractor_search');
        const idInput = document.getElementById('contractor_id');
        const list = document.getElementById('contractor_list');
        if (search && idInput && list) {
            const sync = () => {
                const typed = (search.value || '').trim().toLowerCase();
                idInput.value = '';
                list.querySelectorAll('option').forEach((opt) => {
                    if ((opt.value || '').trim().toLowerCase() === typed) {
                        idInput.value = opt.getAttribute('data-id') || '';
                    }
                });
            };
            search.addEventListener('input', sync);
            search.addEventListener('change', sync);
        }

        const table = document.getElementById('hireInstallmentTable');
        const subtotalTextEl = document.getElementById('subtotal_text');
        const vatTextEl = document.getElementById('vat_text');
        const grandTotalEl = document.getElementById('grand_total');
        const vatEnabledEl = document.getElementById('vat_enabled');
        const addGroupBtn = document.getElementById('addHireGroupBtn');
        const addRowBtn = document.getElementById('addHireRowBtn');
        if (!table || !subtotalTextEl || !window.TncHireLineTable) {
            return;
        }

        const applySubtotal = (subtotal) => {
            subtotal = Math.round(subtotal * 100) / 100;
            const vat = vatEnabledEl && vatEnabledEl.checked ? Math.round(subtotal * 0.07 * 100) / 100 : 0;
            const net = Math.round((subtotal + vat) * 100) / 100;
            const fmt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
            subtotalTextEl.textContent = subtotal.toLocaleString(undefined, fmt);
            if (vatTextEl) vatTextEl.textContent = vat.toLocaleString(undefined, fmt);
            if (grandTotalEl) grandTotalEl.textContent = net.toLocaleString(undefined, fmt);
            return net;
        };

        const hireLineApi = window.TncHireLineTable.bindTable(table, {
            fieldPrefix: 'hire',
            addGroupButton: addGroupBtn,
            addItemButton: addRowBtn,
            onSubtotal: applySubtotal,
        });

        const recalcTotals = () => {
            if (hireLineApi) {
                applySubtotal(hireLineApi.recalc());
            }
        };

        if (vatEnabledEl) {
            vatEnabledEl.addEventListener('change', recalcTotals);
        }

        recalcTotals();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initHireContractCreatePage);
    } else {
        initHireContractCreatePage();
    }
})();
</script>
</body>
</html>
