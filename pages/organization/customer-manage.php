<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

if (!user_can('page.org.customer')) {
    header('Location: ' . app_path('index.php') . '?error=forbidden');
    exit();
}

$is_admin = user_is_admin_role();

$customers = Db::tableRows('customers');
Db::sortRows($customers, 'id', true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php
    require_once dirname(__DIR__, 2) . '/includes/tnc_ops_head.php';
    tnc_ops_head(['title' => 'จัดการข้อมูลลูกค้า | Invoice System', 'sweetalert' => true]);
    ?>
</head>
<body class="tnc-app-body tnc-layout-list">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4">
    <div class="tnc-page-head">
        <div>
            <p class="tnc-page-kicker">Organization</p>
            <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-people-fill"></i></span>ข้อมูลลูกค้า</h1>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php
            require_once dirname(__DIR__, 2) . '/includes/tnc_ui.php';
            echo tnc_ui_back_previous_button();
            ?>
        </div>
    </div>

    <div class="row g-4 tnc-mobile-master">
        <?php if ($is_admin): ?>
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-4 text-dark">เพิ่มลูกค้าใหม่</h5>
                    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=add_customer" method="POST" enctype="multipart/form-data" data-tnc-soft-reload="1">
                        <?php csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">โลโก้ลูกค้า/แบรนด์</label>
                            <input type="file" name="logo" class="form-control border-0 bg-light py-2 rounded-3" accept="image/*">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">ประเภท</label>
                            <select name="customer_type" class="form-select border-0 bg-light rounded-3">
                                <option value="company">บริษัท (Company)</option>
                                <option value="individual">บุคคลธรรมดา (Individual)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">ชื่อลูกค้า/บริษัท</label>
                            <input type="text" name="name" class="form-control border-0 bg-light py-2 rounded-3" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">เลขผู้เสียภาษี</label>
                            <input type="text" name="tax_id" class="form-control border-0 bg-light py-2 rounded-3">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">ที่อยู่</label>
                            <textarea name="address" class="form-control border-0 bg-light rounded-3" rows="2" required></textarea>
                        </div>
                        <div class="row g-2 mb-4">
                            <div class="col-6"><label class="small fw-bold text-muted">เบอร์โทร</label><input type="text" name="phone" class="form-control border-0 bg-light py-2 rounded-3"></div>
                            <div class="col-6"><label class="small fw-bold text-muted">อีเมล</label><input type="email" name="email" class="form-control border-0 bg-light py-2 rounded-3"></div>
                        </div>
                        <button type="submit" class="btn btn-orange w-100 py-2 fw-bold shadow-sm">บันทึกข้อมูล</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-lg-<?= $is_admin ? '8' : '12' ?>">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="table-responsive tnc-mobile-table-wrap">
                    <table class="table table-hover align-middle mb-0 tnc-mobile-table" id="customerTable" width="100%">
                        <thead class="bg-light text-muted small text-uppercase">
                            <tr>
                                <th class="ps-4 py-3">ลูกค้า</th>
                                <th class="py-3">เลขผู้เสียภาษี</th>
                                <th class="py-3">ติดต่อ</th>
                                <th class="text-end pe-4 py-3">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $row): ?>
                            <tr>
                                <td class="ps-4 tnc-mobile-primary" data-label="ลูกค้า">
                                    <div class="d-flex align-items-center">
                                        <?php if($row['logo']): ?>
                                            <img src="<?= htmlspecialchars(upload_logo_url($row['logo'])) ?>" class="logo-preview me-3 border">
                                        <?php else: ?>
                                            <div class="logo-preview me-3 d-flex align-items-center justify-content-center border text-muted"><i class="bi bi-person"></i></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars($row['name']) ?></div>
                                            <div class="text-muted small"><?= ($row['customer_type'] == 'company') ? 'บริษัท' : 'บุคคล' ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="เลขผู้เสียภาษี"><span class="badge bg-warning-subtle text-dark fw-normal border"><?= htmlspecialchars($row['tax_id'] ?: '-') ?></span></td>
                                <td class="small" data-label="ติดต่อ">
                                    <div><i class="bi bi-telephone text-warning me-1"></i><?= h((string) ($row['phone'] ?? '')) ?></div>
                                    <div class="text-muted"><i class="bi bi-envelope text-warning me-1"></i><?= h((string) ($row['email'] ?? '')) ?></div>
                                </td>
                                <td class="text-end pe-4 tnc-mobile-actions" data-label="จัดการ">
                                    <?php if ($is_admin): ?>
                                    <button type="button" onclick="editCustomer(<?= (int) $row['id'] ?>)" class="btn btn-sm btn-outline-warning rounded-circle me-1 tnc-icon-action" aria-label="แก้ไขลูกค้า"><i class="bi bi-pencil-square" aria-hidden="true"></i></button>
                                    <?php endif; ?>
                                    <?php if($is_admin): ?>
                                    <button type="button" onclick="confirmDelete(<?= $row['id'] ?>, 'customer')" class="btn btn-sm btn-outline-danger rounded-circle tnc-icon-action" aria-label="ลบลูกค้า"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=edit_customer" method="POST" enctype="multipart/form-data" data-tnc-soft-reload="1">
                <?php csrf_field(); ?>
                <div class="modal-header border-0 pt-4 px-4">
                    <h5 class="fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-warning"></i>แก้ไขข้อมูลลูกค้า</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 pt-0">
                    <input type="hidden" name="id" id="edit_cust_id">
                    <div class="text-center mb-3">
                        <div id="edit_cust_logo_view" class="mb-2"></div>
                        <input type="file" name="logo" class="form-control bg-light border-0 py-2 rounded-3" accept="image/*">
                    </div>
                    <div class="mb-3"><label class="small fw-bold">ชื่อลูกค้า/บริษัท</label><input type="text" name="name" id="edit_cust_name" class="form-control bg-light border-0 py-2 rounded-3" required></div>
                    <div class="mb-3"><label class="small fw-bold">เลขผู้เสียภาษี</label><input type="text" name="tax_id" id="edit_cust_tax" class="form-control bg-light border-0 py-2 rounded-3"></div>
                    <div class="mb-3"><label class="small fw-bold">ที่อยู่</label><textarea name="address" id="edit_cust_address" class="form-control bg-light border-0 rounded-3" rows="3" required></textarea></div>
                    <div class="row g-2">
                        <div class="col-6"><label class="small fw-bold">โทรศัพท์</label><input type="text" name="phone" id="edit_cust_phone" class="form-control bg-light border-0 py-2 rounded-3"></div>
                        <div class="col-6"><label class="small fw-bold">อีเมล</label><input type="email" name="email" id="edit_cust_email" class="form-control bg-light border-0 py-2 rounded-3"></div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0"><button type="submit" class="btn btn-orange w-100 py-2 fw-bold shadow-sm">บันทึกการแก้ไข</button></div>
            </form>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<script>
const actionHandlerUrl = <?= json_encode(app_path('actions/action-handler.php'), JSON_UNESCAPED_SLASHES) ?>;
const csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const uploadsLogosBase = <?= json_encode(upload_logos_base_url(), JSON_UNESCAPED_SLASHES) ?>;
function editCustomer(id) {
    fetch(`${actionHandlerUrl}?action=get_data&type=customer&id=${id}&_csrf=${encodeURIComponent(csrfToken)}`).then(res => res.json()).then(data => {
        const fields = { id: 'edit_cust_id', name: 'edit_cust_name', tax_id: 'edit_cust_tax', address: 'edit_cust_address', phone: 'edit_cust_phone', email: 'edit_cust_email' };
        Object.keys(fields).forEach(key => document.getElementById(fields[key]).value = data[key]);
        document.getElementById('edit_cust_logo_view').innerHTML = data.logo ? `<img src="${uploadsLogosBase}${encodeURIComponent(data.logo)}" class="logo-preview" style="width:70px;height:70px">` : `<div class="logo-preview mx-auto d-flex align-items-center justify-content-center border" style="width:70px;height:70px"><i class="bi bi-person fs-2"></i></div>`;
        new bootstrap.Modal(document.getElementById('editCustomerModal')).show();
    });
}
function confirmDelete(id, type) {
    Swal.fire({
        title: 'ยืนยันการลบ?',
        html: 'ข้อมูลจะถูกลบถาวร กรุณาใส่<strong>รหัสผ่านเข้าระบบของคุณ</strong>',
        icon: 'warning',
        input: 'password',
        inputPlaceholder: 'รหัสผ่าน',
        showCancelButton: true,
        confirmButtonColor: '#ea580c',
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก',
        focusCancel: true,
        preConfirm: function (pw) {
            if (!pw || !String(pw).trim()) {
                Swal.showValidationMessage('กรุณากรอกรหัสผ่าน');
                return false;
            }
            return pw;
        }
    }).then(function (r) {
        if (!r.isConfirmed || !r.value) return;
        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('type', type);
        fd.append('id', String(id));
        fd.append('_csrf', csrfToken);
        fd.append('_tnc_ajax', '1');
        fd.append('confirm_password', r.value);
        fetch(actionHandlerUrl, {
            method: 'POST',
            body: fd,
            headers: { 'X-Tnc-Ajax': '1', Accept: 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (j) {
                if (j.ok) {
                    Swal.fire({ icon: 'success', title: j.message || 'ลบแล้ว', toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
                    setTimeout(function () { window.location.reload(); }, 500);
                } else {
                    Swal.fire({ icon: 'error', title: j.message || 'ลบไม่สำเร็จ' });
                }
            })
            .catch(function () { Swal.fire({ icon: 'error', title: 'เครือข่ายผิดพลาด' }); });
    });
}
const params = (typeof tncFlashSearchParams === 'function' ? tncFlashSearchParams() : new URLSearchParams(window.location.search));
if(params.has('success')) Swal.fire({ icon: 'success', title: 'สำเร็จ!', confirmButtonColor: '#ea580c' });
if(params.has('deleted')) Swal.fire({ icon: 'success', title: 'ลบแล้ว!', confirmButtonColor: '#ea580c' });
if (params.get('error') === 'confirm_password_required') Swal.fire({ icon: 'warning', title: 'กรุณากรอกรหัสผ่านเพื่อยืนยันการลบ', confirmButtonColor: '#ea580c' });
if (params.get('error') === 'confirm_password_invalid') Swal.fire({ icon: 'error', title: 'รหัสผ่านไม่ถูกต้อง', confirmButtonColor: '#ea580c' });
(function () {
    if (typeof $ === 'undefined' || !$.fn.DataTable) return;
    $('#customerTable').DataTable({ order: [[0, 'asc']] });
})();
</script>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>