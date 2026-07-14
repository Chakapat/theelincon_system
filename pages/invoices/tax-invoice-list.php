<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/tax_invoice_ref_search_catalog.php';
require_once dirname(__DIR__, 2) . '/includes/invoice_cancel_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_invoice_head.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$is_admin = user_can('invoice.tax_delete');
$can_cancel_tax = user_can('invoice.tax_cancel');
$can_edit_tax = user_can('invoice.edit');
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
        'customer_logo' => trim((string) ($cust['logo'] ?? '')),
        'issuer_name' => trim((string) (($issuer['fname'] ?? '') . ' ' . ($issuer['lname'] ?? ''))),
        'grand_total' => $grand,
        'is_cancelled' => tnc_tax_invoice_is_cancelled($tax) || tnc_invoice_is_cancelled($inv),
        'is_tax_cancelled' => tnc_tax_invoice_is_cancelled($tax),
    ];
    $grandTotalSum += $grand;
}

usort($listRows, static function (array $a, array $b): int {
    return ((int) ($b['tax_id'] ?? 0)) <=> ((int) ($a['tax_id'] ?? 0));
});

$totalCount = count($listRows);
$tirSearchCatalog = tnc_invoice_ref_search_catalog();

?>

<!DOCTYPE html>
<html lang="th">
<head>
    <?php tnc_invoice_head(['title' => 'รายการ Tax Invoice', 'sweetalert' => true]); ?>
    <script>
    window.tncActionHandlerUrl = <?= json_encode(app_path('actions/action-handler.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    window.tncCsrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="<?= htmlspecialchars(app_path('assets/js/tnc-invoice-cancel.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body class="tnc-app-body tnc-layout-list">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <div class="tnc-page-head mb-4 flex-wrap gap-2">
        <div>
            <p class="tnc-page-kicker">Invoices · Tax</p>
            <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-file-earmark-break-fill"></i></span>รายการใบกำกับภาษี</h1>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php
            require_once dirname(__DIR__, 2) . '/includes/tnc_ui.php';
            echo tnc_ui_back_previous_button();
            ?>
            <button type="button" class="btn btn-orange px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#tirCreateModal">
                <i class="bi bi-plus-lg me-1"></i>สร้างใบกำกับภาษี
            </button>
        </div>
    </div>

    <div class="card index-table-card border-0 shadow-sm overflow-hidden mb-0 bg-white">
        <?php if ($totalCount > 0): ?>
            <div class="card-header bg-white border-bottom py-3 px-3 px-md-4">
                <div class="d-flex flex-column flex-sm-row flex-wrap gap-2 align-items-stretch">
                    <div class="position-relative flex-grow-1 index-table-toolbar" style="min-width: 220px; max-width: 100%;">
                        <label class="visually-hidden" for="taxSearchInput">ค้นหาใบกำกับภาษี</label>
                        <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted" aria-hidden="true"></i>
                        <input type="search" id="taxSearchInput" class="form-control index-search-input" autocomplete="off">
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                        <label class="small text-muted mb-0 text-nowrap" for="taxMonthYear">เดือน / ปี</label>
                        <input type="month" id="taxMonthYear" class="form-control form-control-sm tax-month-filter" title="กรองตามวันที่ใบกำกับภาษี" aria-label="กรองตามเดือนและปี">
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <div class="table-responsive tnc-mobile-table-wrap">
            <table class="table table-invoice-index table-hover align-middle mb-0 tnc-mobile-table" id="taxTable" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">เลขที่ใบกำกับภาษี</th>
                        <th>วันที่</th>
                        <th>รายชื่อบริษัทออกใบกำกับภาษี</th>
                        <th>ยอดสุทธิ</th>
                        <th class="text-end pe-4">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($totalCount > 0): ?>
                        <?php foreach ($listRows as $row): ?>
                            <?php
                            $taxDateRaw = (string) ($row['tax_date'] ?? '');
                            $taxDateTs = $taxDateRaw !== '' ? strtotime($taxDateRaw) : false;
                            $taxDateDisplay = $taxDateTs !== false ? date('d/m/Y', $taxDateTs) : '—';
                            $taxDateOrder = $taxDateTs !== false ? date('Y-m-d', $taxDateTs) : '0000-00-00';
                            $taxDateAttr = $taxDateRaw !== '' ? htmlspecialchars($taxDateRaw, ENT_QUOTES, 'UTF-8') : '';
                            $custLogo = trim((string) ($row['customer_logo'] ?? ''));
                            $taxSortOrder = sprintf('%010d', (int) ($row['tax_id'] ?? 0));
                            ?>
                            <tr<?= $taxDateAttr !== '' ? ' data-tax-date="' . $taxDateAttr . '"' : '' ?><?= !empty($row['is_cancelled']) ? ' class="inv-row-cancelled"' : '' ?>>
                                <td class="tnc-mobile-primary" data-label="เลขที่" data-order="<?= htmlspecialchars($taxSortOrder, ENT_QUOTES, 'UTF-8') ?>">
                                    <div><span class="badge rounded-pill <?= !empty($row['is_cancelled']) ? 'inv-badge-cancelled' : 'inv-badge-tax-issued' ?> px-3"><?= htmlspecialchars($row['tax_invoice_number'], ENT_QUOTES, 'UTF-8') ?></span></div>
                                    <?php if ($row['invoice_number'] !== ''): ?>
                                        <div class="tax-ref-invoice mt-1" title="อ้างอิงจากใบแจ้งหนี้"><span class="tax-ref-invoice-arrow" aria-hidden="true">→</span> <span class="tax-ref-invoice-no"><?= htmlspecialchars($row['invoice_number'], ENT_QUOTES, 'UTF-8') ?></span></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted tabular-nums" data-label="วันที่" data-order="<?= htmlspecialchars($taxDateOrder, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($taxDateDisplay, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="fw-semibold" data-label="ลูกค้า">
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($custLogo !== ''): ?>
                                            <img src="<?= htmlspecialchars(upload_logo_url($custLogo), ENT_QUOTES, 'UTF-8') ?>" alt="" class="cust-logo-thumb rounded border bg-light flex-shrink-0">
                                        <?php endif; ?>
                                        <span class="text-break"><?= htmlspecialchars($row['customer_name'] !== '' ? $row['customer_name'] : 'ไม่ระบุ', ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </td>
                                <td class="fw-bold text-dark tabular-nums" data-label="ยอดสุทธิ">฿<?= number_format((float) $row['grand_total'], 2) ?></td>
                                <td class="text-end pe-4 tnc-mobile-actions" data-label="จัดการ">
                                    <div class="d-inline-flex flex-wrap align-items-center justify-content-end gap-2">
                                        <a href="<?= htmlspecialchars(app_path('pages/invoices/tax-invoice-receipt.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $row['invoice_id'] ?>" class="btn btn-invoice-action btn-invoice-action-view" title="ดูเอกสาร Tax INV"><i class="bi bi-eye-fill"></i></a>
                                        <?php if ($can_edit_tax && empty($row['is_cancelled'])): ?>
                                        <a href="<?= htmlspecialchars(app_path('pages/invoices/tax-invoice-receipt.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $row['invoice_id'] ?>&edit=1" class="btn btn-invoice-action btn-invoice-action-edit" title="แก้ไข Tax INV"><i class="bi bi-pencil-square"></i></a>
                                        <?php endif; ?>
                                        <?php if ($can_cancel_tax && empty($row['is_tax_cancelled'])): ?>
                                        <button type="button" class="btn btn-invoice-action btn-invoice-action-cancel" data-tnc-cancel-tax-invoice data-tax-id="<?= (int) $row['tax_id'] ?>" data-invoice-id="<?= (int) $row['invoice_id'] ?>" title="ยกเลิก Tax INV"><i class="bi bi-x-circle"></i></button>
                                        <?php endif; ?>
                                        <?php if ($is_admin): ?>
                                            <a href="<?= htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8') ?>?action=delete&type=tax_invoice&id=<?= (int) $row['tax_id'] ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-invoice-action btn-invoice-action-delete tnc-delete-post" title="ลบรายการ Tax INV (ต้องใส่รหัสผ่าน)"><i class="bi bi-trash3-fill"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">ยังไม่มีรายการ Tax INV</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="tirCreateModal" tabindex="-1" aria-labelledby="tirCreateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-md-down">
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
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
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
        } else if (params.get('cancelled') === '1') {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'ยกเลิกใบกำกับภาษีเรียบร้อยแล้ว',
                showConfirmButton: false,
                timer: 2800,
                timerProgressBar: true
            });
            params.delete('cancelled');
            var qs3 = params.toString();
            window.history.replaceState({}, '', window.location.pathname + (qs3 ? '?' + qs3 : '') + window.location.hash);
        } else if (params.get('error') === 'need_cancel_reason') {
            Swal.fire({ icon: 'warning', title: 'กรุณาระบุเหตุผล', confirmButtonColor: '#ea580c' });
            params.delete('error');
            var qs4 = params.toString();
            window.history.replaceState({}, '', window.location.pathname + (qs4 ? '?' + qs4 : '') + window.location.hash);
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

        var table = window.TncLiveDT.init('#taxTable', {
            order: [[0, 'desc']],
            pageLength: 5,
            dom: 'rtp',
            info: false,
            columnDefs: [{ orderable: false, targets: [4] }]
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

<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
