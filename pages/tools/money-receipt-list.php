<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/money_receipt_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_is_finance_role()) {
    $access_denied_title = 'ใบเสร็จรับเงิน';
    $access_denied_text = 'เข้าใช้งานได้เฉพาะผู้ใช้ที่มีสิทธิ์ CEO / ADMIN / ACCOUNTING';
    require dirname(__DIR__, 2) . '/includes/page_access_denied_swal.php';
    exit;
}

$rows = Db::tableRows('money_receipts');
usort($rows, static function (array $a, array $b): int {
    $da = (string) ($a['doc_date'] ?? '');
    $db = (string) ($b['doc_date'] ?? '');
    if ($da !== $db) {
        return strcmp($db, $da);
    }

    return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
});

$companies = Db::tableRows('company');
$companyNames = [];
foreach ($companies as $c) {
    $cid = (int) ($c['id'] ?? 0);
    if ($cid > 0) {
        $companyNames[$cid] = (string) ($c['name'] ?? '');
    }
}

$handler = app_path('actions/money-receipt-handler.php');
$issueUrl = app_path('pages/tools/money-receipt-issue.php');

function mr_pay_method_labels(array $r): string
{
    $bits = [];
    if (!empty($r['pay_cash'])) {
        $bits[] = 'เงินสด';
    }
    if (!empty($r['pay_transfer'])) {
        $bits[] = 'เงินโอน';
    }
    if (!empty($r['pay_check'])) {
        $bits[] = 'เช็คธนาคาร';
    }

    return $bits === [] ? '—' : implode(' · ', $bits);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการใบเสร็จรับเงิน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f8f9fa; }
        .btn-orange { background-color: #fd7e14; color: #fff; border-radius: 10px; }
        .btn-orange:hover { background-color: #e8590c; color: #fff; }
        #editTransferSlipWrap.d-none { display: none !important; }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="fw-bold mb-0"><i class="bi bi-journal-text me-2 text-warning"></i>ใบเสร็จรับเงิน</h2>
        </div>
        <a href="<?= htmlspecialchars($issueUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange fw-semibold rounded-pill"><i class="bi bi-plus-lg me-1"></i>ออกใบเสร็จ</a>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="mrListTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">วันที่เอกสาร</th>
                        <th>เลขที่เอกสาร</th>
                        <th>บริษัท</th>
                        <th>ผู้ออกเอกสาร</th>
                        <th class="text-end">ยอดสุทธิ</th>
                        <th>วิธีชำระ</th>
                        <th class="text-end pe-4" style="width:140px;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($rows) === 0): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">ยังไม่มีข้อมูล — <a href="<?= htmlspecialchars($issueUrl, ENT_QUOTES, 'UTF-8') ?>">ออกใบแรก</a></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r):
                        $rid = (int) ($r['id'] ?? 0);
                        $cid = (int) ($r['company_id'] ?? 0);
                        $items = money_receipt_items_from_json_field((string) ($r['items_json'] ?? ''));
                        $tot = money_receipt_totals($items);
                        $doc = (string) ($r['doc_date'] ?? '');
                        ?>
                        <tr data-id="<?= $rid ?>">
                            <td class="ps-4"><?= $doc !== '' ? htmlspecialchars(date('d/m/Y', strtotime($doc)), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                            <td><span class="badge bg-secondary-subtle text-dark border"><?= htmlspecialchars((string) ($r['receipt_no'] ?? ('#' . $rid)), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars($companyNames[$cid] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($r['issuer_name'] ?? 'ผู้ใช้งานระบบ'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-end fw-semibold">฿<?= number_format($tot['net'], 2) ?></td>
                            <td class="small"><?= htmlspecialchars(mr_pay_method_labels($r), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-end pe-4">
                                <div class="btn-group btn-group-sm">
                                    <a class="btn btn-outline-primary" title="พิมพ์" href="<?= htmlspecialchars(app_path('pages/tools/money-receipt-print.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= $rid ?>"><i class="bi bi-printer"></i></a>
                                    <button type="button" class="btn btn-outline-secondary mr-btn-edit" title="แก้ไข" data-id="<?= $rid ?>"><i class="bi bi-pencil-square"></i></button>
                                    <button type="button" class="btn btn-outline-danger mr-btn-del" title="ลบ" data-id="<?= $rid ?>"><i class="bi bi-trash3"></i></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal แก้ไข -->
<div class="modal fade" id="mrEditModal" tabindex="-1" aria-labelledby="mrEditModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="mrEditModalTitle">แก้ไขใบเสร็จรับเงิน</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body pt-2">
                <form id="mrEditForm" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="_tnc_ajax" value="1">
                    <input type="hidden" name="id" id="mrEditId" value="">

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">บริษัท</label>
                            <select name="company_id" id="mrEditCompany" class="form-select form-select-sm" required>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= (int) ($c['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($c['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">DATE</label>
                            <input type="date" name="doc_date" id="mrEditDocDate" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">ผู้ออกเอกสาร</label>
                            <input type="text" id="mrEditName" class="form-control form-control-sm" readonly>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small fw-semibold">รายการ</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="mrEditAddRow"><i class="bi bi-plus-lg"></i></button>
                    </div>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered" id="mrEditItemsTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:40px;">#</th>
                                    <th>รายละเอียด</th>
                                    <th style="width:100px;">ยอดหัก</th>
                                    <th style="width:100px;">ยอดรับ</th>
                                    <th style="width:40px;"></th>
                                </tr>
                            </thead>
                            <tbody id="mrEditBody"></tbody>
                        </table>
                    </div>

                    <div class="border rounded-3 p-2 mb-2 bg-light">
                        <div class="small fw-semibold mb-1">วิธีชำระเงิน</div>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="pay_cash" id="mrEditPayCash" value="1">
                                <label class="form-check-label small" for="mrEditPayCash">เงินสด</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="pay_transfer" id="mrEditPayTransfer" value="1">
                                <label class="form-check-label small" for="mrEditPayTransfer">เงินโอน</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="pay_check" id="mrEditPayCheck" value="1">
                                <label class="form-check-label small" for="mrEditPayCheck">เช็คธนาคาร</label>
                            </div>
                        </div>
                        <div id="editTransferSlipWrap" class="mt-2 d-none">
                            <label class="form-label small mb-0">เปลี่ยนสลิปโอน (ถ้าไม่เลือกไฟล์จะใช้สลิปเดิม)</label>
                            <input type="file" name="transfer_slip" id="mrEditSlip" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp,image/gif">
                            <div class="small text-muted mt-1" id="mrEditSlipHint"></div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-orange fw-semibold" id="mrEditSubmit">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script>
var mrHandlerUrl = <?= json_encode($handler, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
var mrCsrf = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

(function () {
    if (typeof jQuery !== 'undefined' && jQuery.fn.DataTable && document.querySelector('#mrListTable tbody tr td[colspan]') === null) {
        var rowCount = document.querySelectorAll('#mrListTable tbody tr').length;
        if (rowCount > 0) {
            jQuery('#mrListTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
                columnDefs: [{ orderable: false, targets: [-1] }]
            });
        }
    }

    var modalEl = document.getElementById('mrEditModal');
    var modal = modalEl && window.bootstrap ? new bootstrap.Modal(modalEl) : null;
    var editForm = document.getElementById('mrEditForm');
    var editBody = document.getElementById('mrEditBody');
    var payTransferEl = document.getElementById('mrEditPayTransfer');
    var slipWrap = document.getElementById('editTransferSlipWrap');
    var slipInput = document.getElementById('mrEditSlip');

    function syncEditSlip() {
        if (!payTransferEl || !slipWrap) return;
        if (payTransferEl.checked) {
            slipWrap.classList.remove('d-none');
            if (slipInput) slipInput.required = false;
        } else {
            slipWrap.classList.add('d-none');
            if (slipInput) slipInput.value = '';
        }
    }
    payTransferEl && payTransferEl.addEventListener('change', syncEditSlip);

    function editRowTemplate() {
        var tr = document.createElement('tr');
        tr.className = 'mr-er';
        tr.innerHTML = '<td class="text-muted small text-center er-i"></td>' +
            '<td><input type="text" name="item_detail[]" class="form-control form-control-sm"></td>' +
            '<td><input type="text" name="item_deduct[]" class="form-control form-control-sm text-end" inputmode="decimal"></td>' +
            '<td><input type="text" name="item_receive[]" class="form-control form-control-sm text-end" inputmode="decimal"></td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger border-0 er-del"><i class="bi bi-x-lg"></i></button></td>';
        return tr;
    }

    function erRenumber() {
        if (!editBody) return;
        editBody.querySelectorAll('.mr-er').forEach(function (tr, i) {
            var c = tr.querySelector('.er-i');
            if (c) c.textContent = String(i + 1);
        });
    }

    function bindErDel(btn) {
        btn.addEventListener('click', function () {
            var tr = btn.closest('tr');
            if (!tr || !editBody) return;
            if (editBody.querySelectorAll('.mr-er').length <= 1) return;
            tr.remove();
            erRenumber();
        });
    }

    document.getElementById('mrEditAddRow') && document.getElementById('mrEditAddRow').addEventListener('click', function () {
        if (!editBody) return;
        var tr = editRowTemplate();
        editBody.appendChild(tr);
        bindErDel(tr.querySelector('.er-del'));
        erRenumber();
    });

    function clearEditRows() {
        if (!editBody) return;
        editBody.innerHTML = '';
        var tr = editRowTemplate();
        editBody.appendChild(tr);
        bindErDel(tr.querySelector('.er-del'));
        erRenumber();
    }

    function fillEditRows(items) {
        if (!editBody) return;
        editBody.innerHTML = '';
        if (!items || !items.length) {
            clearEditRows();
            return;
        }
        items.forEach(function (it) {
            var tr = editRowTemplate();
            var ins = tr.querySelectorAll('input');
            if (ins[0]) ins[0].value = it.detail || '';
            if (ins[1]) ins[1].value = it.deduct != null ? String(it.deduct) : '';
            if (ins[2]) ins[2].value = it.receive != null ? String(it.receive) : '';
            editBody.appendChild(tr);
            bindErDel(tr.querySelector('.er-del'));
        });
        erRenumber();
    }

    document.querySelectorAll('.mr-btn-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(btn.getAttribute('data-id') || '0', 10);
            if (!id || !modal) return;
            fetch(mrHandlerUrl + '?action=fetch&id=' + encodeURIComponent(String(id)), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.ok || !d.receipt) {
                        Swal.fire({ icon: 'error', title: 'โหลดข้อมูลไม่สำเร็จ', confirmButtonColor: '#fd7e14' });
                        return;
                    }
                    var rc = d.receipt;
                    document.getElementById('mrEditId').value = String(rc.id);
                    document.getElementById('mrEditCompany').value = String(rc.company_id || '');
                    document.getElementById('mrEditDocDate').value = rc.doc_date || '';
                    document.getElementById('mrEditName').value = rc.issuer_name || 'ผู้ใช้งานระบบ';
                    document.getElementById('mrEditPayCash').checked = !!rc.pay_cash;
                    document.getElementById('mrEditPayTransfer').checked = !!rc.pay_transfer;
                    document.getElementById('mrEditPayCheck').checked = !!rc.pay_check;
                    var hint = document.getElementById('mrEditSlipHint');
                    if (hint) {
                        hint.textContent = rc.transfer_slip ? 'มีสลิปในระบบแล้ว — อัปโหลดใหม่เพื่อแทนที่' : 'ยังไม่มีสลิป — ต้องแนบเมื่อเลือกเงินโอน';
                    }
                    if (slipInput) slipInput.value = '';
                    fillEditRows(rc.items || []);
                    syncEditSlip();
                    modal.show();
                })
                .catch(function () {
                    Swal.fire({ icon: 'error', title: 'เชื่อมต่อไม่สำเร็จ', confirmButtonColor: '#fd7e14' });
                });
        });
    });

    editForm && editForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(editForm);
        fd.set('_csrf', mrCsrf);
        fd.set('_tnc_ajax', '1');
        var submitBtn = document.getElementById('mrEditSubmit');
        if (submitBtn) submitBtn.disabled = true;
        fetch(mrHandlerUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) {
                    Swal.fire({ icon: 'error', title: d && d.message ? d.message : 'บันทึกไม่สำเร็จ', confirmButtonColor: '#fd7e14' });
                    return;
                }
                modal && modal.hide();
                Swal.fire({ icon: 'success', title: d.message || 'อัปเดตแล้ว', toast: true, position: 'top-end', timer: 1800, showConfirmButton: false });
                setTimeout(function () { window.location.reload(); }, 400);
            })
            .catch(function () {
                Swal.fire({ icon: 'error', title: 'เชื่อมต่อไม่สำเร็จ', confirmButtonColor: '#fd7e14' });
            })
            .finally(function () {
                if (submitBtn) submitBtn.disabled = false;
            });
    });

    document.querySelectorAll('.mr-btn-del').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(btn.getAttribute('data-id') || '0', 10);
            if (!id) return;
            Swal.fire({
                icon: 'warning',
                title: 'ยืนยันการลบ',
                html: 'ลบใบเสร็จ #' + id + ' — ใส่<strong>รหัสผ่าน</strong>ของคุณ',
                input: 'password',
                showCancelButton: true,
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc3545',
                focusCancel: true,
                preConfirm: function (pw) {
                    if (!pw || !String(pw).trim()) {
                        Swal.showValidationMessage('กรุณากรอกรหัสผ่าน');
                        return false;
                    }
                    return pw;
                }
            }).then(function (res) {
                if (!res.isConfirmed || !res.value) return;
                var fd = new FormData();
                fd.append('_csrf', mrCsrf);
                fd.append('_tnc_ajax', '1');
                fd.append('action', 'delete');
                fd.append('id', String(id));
                fd.append('confirm_password', res.value);
                fetch(mrHandlerUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || !d.ok) {
                            Swal.fire({ icon: 'error', title: d && d.message ? d.message : 'ลบไม่สำเร็จ', confirmButtonColor: '#fd7e14' });
                            return;
                        }
                        Swal.fire({ icon: 'success', title: 'ลบแล้ว', toast: true, position: 'top-end', timer: 1600, showConfirmButton: false });
                        setTimeout(function () { window.location.reload(); }, 400);
                    })
                    .catch(function () {
                        Swal.fire({ icon: 'error', title: 'เชื่อมต่อไม่สำเร็จ', confirmButtonColor: '#fd7e14' });
                    });
            });
        });
    });

    var q = new URLSearchParams(window.location.search);
    if (q.get('updated') === '1') {
        Swal.fire({ icon: 'success', title: 'อัปเดตเรียบร้อย', toast: true, position: 'top-end', timer: 2200, showConfirmButton: false, timerProgressBar: true });
    }
    if (q.get('deleted') === '1') {
        Swal.fire({ icon: 'success', title: 'ลบเรียบร้อย', toast: true, position: 'top-end', timer: 2200, showConfirmButton: false, timerProgressBar: true });
    }
    if (q.get('error')) {
        var map = { invalid: 'ข้อมูลไม่ถูกต้อง', payment_slip_required: 'ต้องมีสลิปเมื่อเลือกเงินโอน', upload_failed: 'อัปโหลดไม่สำเร็จ', upload_type: 'ชนิดไฟล์ไม่รองรับ', csrf: 'เซสชันหมดอายุ' };
        Swal.fire({ icon: 'warning', title: map[q.get('error')] || 'เกิดข้อผิดพลาด', confirmButtonColor: '#fd7e14' });
    }
})();
</script>
</body>
</html>
