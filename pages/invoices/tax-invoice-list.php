<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/tax_invoice_ref_search_catalog.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$is_admin = user_is_admin_role();
$csrfQ = '&_csrf=' . rawurlencode(csrf_token());

$taxRows = Db::tableRows('tax_invoices');
$invoices = Db::tableKeyed('invoices');
$customers = Db::tableKeyed('customers');
$users = Db::tableKeyed('users');

$listRows = [];
$grandTotalSum = 0.0;
foreach ($taxRows as $tax) {
    $invoiceId = (string) ($tax['invoice_id'] ?? '');
    $inv = $invoices[$invoiceId] ?? null;
    if ($inv === null) {
        continue;
    }

    $cust = $customers[(string) ($inv['customer_id'] ?? '')] ?? null;
    $issuer = $users[(string) ($inv['created_by'] ?? '')] ?? null;
    $grand = (float) ($tax['grand_total'] ?? ($inv['total_amount'] ?? 0));

    $listRows[] = [
        'tax_id' => (int) ($tax['id'] ?? 0),
        'tax_invoice_number' => strtoupper((string) ($tax['tax_invoice_number'] ?? '')),
        'tax_date' => (string) ($tax['tax_date'] ?? ''),
        'invoice_id' => (int) ($inv['id'] ?? 0),
        'invoice_number' => (string) ($inv['invoice_number'] ?? ''),
        'customer_name' => (string) ($cust['name'] ?? ''),
        'issuer_name' => trim((string) (($issuer['fname'] ?? '') . ' ' . ($issuer['lname'] ?? ''))),
        'grand_total' => $grand,
    ];
    $grandTotalSum += $grand;
}

usort($listRows, static function (array $a, array $b): int {
    $dateCmp = strcmp((string) ($b['tax_date'] ?? ''), (string) ($a['tax_date'] ?? ''));
    if ($dateCmp !== 0) {
        return $dateCmp;
    }
    return strcmp((string) ($b['tax_invoice_number'] ?? ''), (string) ($a['tax_invoice_number'] ?? ''));
});

$totalCount = count($listRows);
$tirSearchCatalog = tnc_invoice_ref_search_catalog();

?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการ Tax Invoice</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f1f3f5; font-family: 'Sarabun', sans-serif; }
        .radius-page { border-radius: 12px; }
        .shadow-soft {
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
            border: none;
        }
        .table-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
        }
        /* .btn-orange: tnc-app.css */
        .summary-card {
            background: #fff;
            border-radius: 12px;
            padding: 1.25rem 1.35rem;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
            border: none;
        }
        .summary-icon {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }
        .stat-label { color: #868e96; font-size: 0.875rem; font-weight: 500; }
        .stat-count { font-size: 1.75rem; font-weight: 800; color: #212529; letter-spacing: -0.02em; line-height: 1.15; }
        .stat-total {
            font-size: 1.85rem;
            font-weight: 800;
            color: #0f766e;
            letter-spacing: -0.02em;
            line-height: 1.15;
        }
        .tabular-nums { font-variant-numeric: tabular-nums; }
        .tax-search-wrap {
            position: relative;
            flex: 1 1 220px;
            min-width: 200px;
            max-width: 420px;
        }
        .tax-search-wrap .bi-search {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #868e96;
            font-size: 1rem;
            pointer-events: none;
        }
        .tax-search-wrap .form-control {
            border-radius: 12px;
            padding-left: 2.5rem;
            border: 1px solid #e9ecef;
            box-shadow: none;
        }
        .tax-search-wrap .form-control:focus {
            border-color: #ced4da;
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.12);
        }
        .tax-month-filter {
            border-radius: 12px;
            border: 1px solid #e9ecef;
            max-width: 200px;
        }
        #taxTable.dataTable tbody td {
            padding-top: 0.95rem;
            padding-bottom: 0.95rem;
            vertical-align: middle;
            border-bottom: 1px solid #eef1f4;
        }
        #taxTable.dataTable tbody tr:last-child td { border-bottom: none; }
        #taxTable.dataTable thead th {
            border-bottom: 1px solid #e9ecef;
            font-weight: 600;
            color: #495057;
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
        }
        .badge-ref-invoice {
            display: inline-block;
            font-size: 0.7rem;
            font-weight: 500;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            color: #6c757d;
            background: #f1f3f5;
            border: none;
        }
        .btn-icon-action {
            width: 2.25rem;
            height: 2.25rem;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            border: none;
            transition: transform 0.12s ease, box-shadow 0.12s ease, opacity 0.12s ease;
        }
        .btn-icon-action:hover { transform: translateY(-1px); }
        .btn-icon-view {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .btn-icon-view:hover { background: #c8e6c9; color: #1b5e20; }
        .btn-icon-edit {
            background: #e7f1ff;
            color: #ea580c;
        }
        .btn-icon-edit:hover { background: #cfe2ff; color: #0a58ca; }
        .btn-icon-delete {
            background: #fde8e8;
            color: #dc3545;
        }
        .btn-icon-delete:hover { background: #f8d7da; color: #b02a37; }
        .tax-toolbar .dataTables_length label {
            margin-bottom: 0;
            font-size: 0.875rem;
            color: #495057;
        }
        .tax-toolbar .dataTables_length select {
            border-radius: 10px;
            margin: 0 0.35rem;
        }
        #taxTable_wrapper .dataTables_info,
        #taxTable_wrapper .dataTables_paginate { padding-top: 0.75rem; }

        /* —— Modal: สร้างใบกำกับภาษี (solid + search) —— */
        #tirCreateModal .modal-dialog {
            max-width: min(520px, calc(100vw - 1.5rem));
        }
        #tirCreateModal .tir-modal-glass {
            border-radius: 18px;
            background: #fff;
            box-shadow: var(--tnc-shadow-md, 0 8px 28px rgba(15, 23, 42, 0.08));
            border: 1px solid var(--tnc-orange-border, #fdba74);
        }
        #tirCreateModal .modal-header {
            padding: 1.25rem 1.35rem 0.5rem;
        }
        #tirCreateModal .modal-body {
            padding: 0.5rem 1.35rem 1.35rem;
        }
        #tirCreateModal .tir-modal-title {
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #0f172a;
        }
        #tirCreateModal .tir-modal-helper {
            font-size: 0.8125rem;
            color: #94a3b8;
            line-height: 1.45;
        }
        #tirCreateModal .tir-modal-search-wrap {
            position: relative;
        }
        #tirCreateModal .tir-modal-search-ico {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.1rem;
            color: #94a3b8;
            pointer-events: none;
            z-index: 2;
        }
        #tirCreateModal .tir-modal-ref-input {
            height: 3.1rem;
            padding-left: 2.85rem;
            padding-right: 1rem;
            font-size: 1rem;
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: #fff;
        }
        #tirCreateModal .tir-modal-ref-input:focus {
            border-color: #ea580c;
            box-shadow: 0 0 0 4px rgba(234, 88, 12, 0.2);
            outline: none;
            background: #fff;
        }
        #tirCreateModal .tir-modal-autocomplete {
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 6px);
            z-index: 1090;
            display: none;
            max-height: 260px;
            overflow-y: auto;
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 0.5rem 1.5rem rgba(15, 23, 42, 0.14);
            background: #fff;
        }
        #tirCreateModal .tir-modal-autocomplete .list-group-item {
            border: 0;
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
            padding: 0.65rem 1rem;
            font-size: 0.92rem;
        }
        #tirCreateModal .tir-modal-autocomplete .list-group-item:last-child {
            border-bottom: 0;
        }
        #tirCreateModal .tir-modal-autocomplete .list-group-item:hover,
        #tirCreateModal .tir-modal-autocomplete .list-group-item:focus {
            background: rgba(234, 88, 12, 0.09);
        }
        #tirCreateModal .tir-suggest-num { font-weight: 600; color: #0f172a; }
        #tirCreateModal .tir-suggest-meta { font-size: 0.78rem; color: #64748b; }
        #tirCreateModal .tir-modal-btn-search {
            height: 3.05rem;
            font-weight: 600;
            border-radius: 14px;
            border: none;
            background: linear-gradient(135deg, #ea580c 0%, #ff922b 100%);
            color: #fff;
            box-shadow: 0 0.35rem 1rem rgba(234, 88, 12, 0.35);
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
        }
        #tirCreateModal .tir-modal-btn-search:hover:not(:disabled) {
            color: #fff;
            filter: brightness(1.03);
            transform: scale(1.02);
            box-shadow: 0 0.45rem 1.15rem rgba(253, 126, 20, 0.42);
        }
        #tirCreateModal .tir-modal-btn-search:disabled {
            opacity: 0.92;
            cursor: wait;
            transform: none;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="tnc-app-body">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <div class="tnc-page-head mb-4 flex-wrap gap-2">
        <div>
            <p class="tnc-page-kicker">Invoices · Tax</p>
            <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-file-earmark-break-fill"></i></span>รายการใบกำกับภาษี</h1>
        </div>
        <button type="button" class="btn btn-orange px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#tirCreateModal">
            <i class="bi bi-plus-lg me-1"></i>สร้างใบกำกับภาษี
        </button>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="summary-card h-100 shadow-soft">
                <div class="d-flex align-items-center gap-3">
                    <span class="summary-icon bg-success-subtle text-success"><i class="bi bi-receipt"></i></span>
                    <div>
                        <div class="stat-label">จำนวนใบกำกับภาษีทั้งหมด</div>
                        <div class="stat-count"><?= number_format($totalCount) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="summary-card h-100 shadow-soft">
                <div class="d-flex align-items-center gap-3">
                    <span class="summary-icon bg-warning-subtle text-warning"><i class="bi bi-cash-coin"></i></span>
                    <div>
                        <div class="stat-label">ยอดเงินรวมใบกำกับภาษี</div>
                        <div class="stat-total">฿ <?= number_format($grandTotalSum, 2) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card table-card p-3 p-md-4">
        <?php if ($totalCount > 0): ?>
            <div class="tax-toolbar d-flex flex-wrap align-items-center gap-2 gap-md-3 mb-3">
                <div class="tax-search-wrap">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <label class="visually-hidden" for="taxSearchInput">ค้นหา</label>
                    <input type="search" id="taxSearchInput" class="form-control" placeholder="ค้นหาเลขที่, ลูกค้า, ใบแจ้งหนี้..." autocomplete="off">
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <label class="small text-muted mb-0 text-nowrap" for="taxMonthYear">เดือน / ปี</label>
                    <input type="month" id="taxMonthYear" class="form-control form-control-sm tax-month-filter" title="กรองตามวันที่ใบกำกับภาษี" aria-label="กรองตามเดือนและปี">
                </div>
                <div id="taxLengthSlot" class="ms-md-auto"></div>
            </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="taxTable" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่ใบกำกับภาษี</th>
                        <th>วันที่</th>
                        <th>อ้างอิงใบแจ้งหนี้</th>
                        <th>ลูกค้า</th>
                        <th class="text-end tabular-nums">ยอดสุทธิ</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($totalCount > 0): ?>
                        <?php foreach ($listRows as $row): ?>
                            <?php
                            $taxDateRaw = (string) ($row['tax_date'] ?? '');
                            $taxDateAttr = $taxDateRaw !== '' ? htmlspecialchars($taxDateRaw, ENT_QUOTES, 'UTF-8') : '';
                            ?>
                            <tr<?= $taxDateAttr !== '' ? ' data-tax-date="' . $taxDateAttr . '"' : '' ?>>
                                <td>
                                    <div class="fw-bold text-success"><?= htmlspecialchars($row['tax_invoice_number'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($row['issuer_name'] !== '' ? $row['issuer_name'] : '-', ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td data-order="<?= $taxDateAttr !== '' ? htmlspecialchars($taxDateRaw, ENT_QUOTES, 'UTF-8') : '0' ?>"><?= $row['tax_date'] !== '' ? htmlspecialchars(date('d/m/Y', strtotime($row['tax_date'])), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                                <td><span class="badge-ref-invoice"><?= htmlspecialchars($row['invoice_number'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars($row['customer_name'] !== '' ? $row['customer_name'] : 'ไม่ระบุ', ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-end fw-semibold tabular-nums">฿ <?= number_format((float) $row['grand_total'], 2) ?></td>
                                <td class="text-center">
                                    <div class="d-inline-flex align-items-center justify-content-center gap-1 flex-wrap">
                                        <a href="<?= htmlspecialchars(app_path('pages/invoices/tax-invoice-receipt.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $row['invoice_id'] ?>" class="btn-icon-action btn-icon-view" title="ดูเอกสาร Tax INV">
                                            <i class="bi bi-eye-fill"></i>
                                        </a>
                                        <a href="<?= htmlspecialchars(app_path('pages/invoices/tax-invoice-receipt.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $row['invoice_id'] ?>&edit=1" class="btn-icon-action btn-icon-edit" title="แก้ไข Tax INV">
                                            <i class="bi bi-pencil-square"></i>
                                        </a>
                                        <?php if ($is_admin): ?>
                                            <a href="<?= htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8') ?>?action=delete&type=tax_invoice&id=<?= (int) $row['tax_id'] ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn-icon-action btn-icon-delete tnc-delete-post" title="ลบรายการ Tax INV (ต้องใส่รหัสผ่าน)">
                                                <i class="bi bi-trash-fill"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">ยังไม่มีรายการ Tax INV</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="tirCreateModal" tabindex="-1" aria-labelledby="tirCreateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content tir-modal-glass">
            <div class="modal-header">
                <h2 class="modal-title tir-modal-title mb-0" id="tirCreateModalLabel">สร้างใบกำกับภาษี</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <form id="tirModalForm" novalidate>
                    <div class="tir-modal-search-wrap mb-3">
                        <i class="bi bi-search tir-modal-search-ico" aria-hidden="true"></i>
                        <label class="visually-hidden" for="tirModalRefInput">ค้นหา Invoice</label>
                        <input type="text" id="tirModalRefInput" class="form-control tir-modal-ref-input tir-modal-ref-field" autocomplete="off" placeholder="พิมพ์เลข Invoice หรือชื่อลูกค้า" required>
                        <div id="tirModalAutocomplete" class="tir-modal-autocomplete list-group" role="listbox" aria-label="รายการแนะนำ"></div>
                    </div>
                    <button type="submit" id="tirModalSubmit" class="btn tir-modal-btn-search w-100">
                        <i class="bi bi-file-earmark-search me-2" aria-hidden="true"></i>สร้างเลย
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var receiptUrl = <?= json_encode(app_path('pages/invoices/tax-invoice-receipt.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var allRefs = <?= json_encode($tirSearchCatalog['autocomplete'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var invoiceSearchOptions = <?= json_encode($tirSearchCatalog['options'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var MAX_ITEMS = 10;

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
    function escAttr(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;');
    }

    function goToReceipt(ref) {
        var q = String(ref || '').trim();
        if (!q) return;
        window.location.href = receiptUrl + '?ref=' + encodeURIComponent(q);
    }

    function renderSuggestions(input, list) {
        var raw = (input.value || '').trim().toLowerCase();
        if (!raw) {
            list.style.display = 'none';
            list.innerHTML = '';
            return;
        }
        var tokenResults = allRefs
            .filter(function (ref) { return String(ref).toLowerCase().includes(raw); })
            .map(function (ref) {
                return { html: '<span class="tir-suggest-num">' + escHtml(String(ref).toUpperCase()) + '</span>', value: ref };
            });

        var richResults = invoiceSearchOptions
            .filter(function (row) {
                var label = (String(row.invoice_number || '') + ' ' + String(row.customer_name || '') + ' ' + String(row.issue_date || '')).toLowerCase();
                return label.includes(raw);
            })
            .map(function (row) {
                var meta = [];
                if (row.customer_name) meta.push(row.customer_name);
                if (row.issue_date) meta.push(row.issue_date);
                var metaStr = meta.length ? '<div class="tir-suggest-meta">' + escHtml(meta.join(' · ')) + '</div>' : '';
                return {
                    html: '<span class="tir-suggest-num">' + escHtml(String(row.invoice_number || '')) + '</span>' + metaStr,
                    value: row.search_ref
                };
            });

        var mergedMap = new Map();
        richResults.concat(tokenResults).forEach(function (item) {
            var v = String(item.value || '');
            if (!mergedMap.has(v)) mergedMap.set(v, item);
        });
        var results = Array.from(mergedMap.values()).slice(0, MAX_ITEMS);
        if (results.length === 0) {
            list.style.display = 'none';
            list.innerHTML = '';
            return;
        }
        list.innerHTML = results.map(function (item) {
            return '<button type="button" class="list-group-item list-group-item-action text-start tir-modal-suggest-btn" data-ref="' + escAttr(String(item.value)) + '">' + item.html + '</button>';
        }).join('');
        list.style.display = 'block';
        list.querySelectorAll('.tir-modal-suggest-btn').forEach(function (btn) {
            btn.addEventListener('mousedown', function (e) {
                e.preventDefault();
            });
            btn.addEventListener('click', function () {
                goToReceipt(btn.getAttribute('data-ref') || '');
            });
        });
    }

    function resetModalForm() {
        var input = document.getElementById('tirModalRefInput');
        var list = document.getElementById('tirModalAutocomplete');
        var submitBtn = document.getElementById('tirModalSubmit');
        if (input) input.value = '';
        if (list) {
            list.style.display = 'none';
            list.innerHTML = '';
        }
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-file-earmark-search me-2" aria-hidden="true"></i>ค้นหารายละเอียด Invoice';
        }
    }

    var modalEl = document.getElementById('tirCreateModal');
    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', resetModalForm);
        modalEl.addEventListener('shown.bs.modal', function () {
            var input = document.getElementById('tirModalRefInput');
            if (input) input.focus();
        });
    }

    var tirInput = document.getElementById('tirModalRefInput');
    var tirList = document.getElementById('tirModalAutocomplete');
    if (tirInput && tirList) {
        tirInput.addEventListener('input', function () { renderSuggestions(tirInput, tirList); });
        tirInput.addEventListener('blur', function () {
            setTimeout(function () {
                tirList.style.display = 'none';
                tirList.innerHTML = '';
            }, 200);
        });
    }

    var tirForm = document.getElementById('tirModalForm');
    var tirSubmit = document.getElementById('tirModalSubmit');
    if (tirForm && tirSubmit && tirInput) {
        tirForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!tirInput.value.trim()) {
                tirInput.focus();
                return;
            }
            tirSubmit.disabled = true;
            tirSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>กำลังค้นหา…';
            goToReceipt(tirInput.value);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Swal === 'undefined') return;
        var params = new URLSearchParams(window.location.search);
        if (params.get('created') === '1') {
            var taxNo = params.get('tax_no') || '';
            var msg = taxNo !== ''
                ? 'บันทึก Tax INV สำเร็จ เลขที่ ' + taxNo
                : 'บันทึก Tax INV สำเร็จแล้ว';
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: msg,
                showConfirmButton: false,
                timer: 3200,
                timerProgressBar: true
            });
            params.delete('created');
            params.delete('tax_no');
            var qs = params.toString();
            window.history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
        } else if (params.get('deleted') === '1') {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'ลบรายการ Tax INV เรียบร้อยแล้ว',
                showConfirmButton: false,
                timer: 2800,
                timerProgressBar: true
            });
            params.delete('deleted');
            var qs2 = params.toString();
            window.history.replaceState({}, '', window.location.pathname + (qs2 ? '?' + qs2 : '') + window.location.hash);
        }
    });
})();

(function ($) {
    if ($('#taxTable tbody tr td[colspan]').length === 0 && $('#taxTable tbody tr').length) {
        var taxYmFilter = function (settings, data, dataIndex) {
            if (settings.nTable.id !== 'taxTable') {
                return true;
            }
            var ymEl = document.getElementById('taxMonthYear');
            if (!ymEl || !ymEl.value) {
                return true;
            }
            var api = new $.fn.dataTable.Api(settings);
            var row = api.row(dataIndex).node();
            if (!row) {
                return true;
            }
            var iso = row.getAttribute('data-tax-date');
            if (!iso || iso.length < 7) {
                return false;
            }
            return iso.substring(0, 7) === ymEl.value;
        };
        $.fn.dataTable.ext.search.push(taxYmFilter);

        var table = $('#taxTable').DataTable({
            order: [[1, 'desc']],
            pageLength: 25,
            dom: 'lrtip',
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
            columnDefs: [{ targets: [5], orderable: false, searchable: false }],
            initComplete: function () {
                var $len = $('#taxTable_wrapper .dataTables_length');
                if ($len.length && $('#taxLengthSlot').length) {
                    $('#taxLengthSlot').append($len);
                }
            }
        });

        $('#taxSearchInput').on('keyup search input', function () {
            table.search(this.value).draw();
        });

        $('#taxMonthYear').on('change', function () {
            table.draw();
        });
    }
    var u = <?= json_encode(app_path('actions/live-datasets.php?dataset=mirror_table&table=tax_invoices'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var c = '';
    setInterval(function () {
        if (document.hidden) return;
        fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.ok) return;
            if (c === '') { c = d.checksum; return; }
            if (d.checksum !== c) window.location.reload();
        }).catch(function () {});
    }, 6000);
})(jQuery);
</script>

</body>
</html>
