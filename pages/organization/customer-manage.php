<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$is_admin = user_is_admin_role();

$customers = Db::tableRows('customers');
Db::sortRows($customers, 'id', true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการข้อมูลลูกค้า | Invoice System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background-color: #fffaf5; font-family: 'Sarabun', sans-serif; }
        .btn-orange { background-color: #fd7e14; color: white; border-radius: 10px; }
        .btn-orange:hover { background-color: #e8590c; color: white; }
        .logo-preview { height: 50px; width: 50px; object-fit: contain; border-radius: 8px; background: #f8f9fa; }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-0 text-dark"><i class="bi bi-people-fill me-2 text-warning"></i>ข้อมูลลูกค้า</h2>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-4 text-dark">เพิ่มลูกค้าใหม่</h5>
                    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=add_customer" method="POST" enctype="multipart/form-data">
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

        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
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
                                <td class="ps-4">
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
                                <td><span class="badge bg-warning-subtle text-dark fw-normal border"><?= htmlspecialchars($row['tax_id'] ?: '-') ?></span></td>
                                <td class="small">
                                    <div><i class="bi bi-telephone text-warning me-1"></i><?= $row['phone'] ?></div>
                                    <div class="text-muted"><i class="bi bi-envelope text-warning me-1"></i><?= $row['email'] ?></div>
                                </td>
                                <td class="text-end pe-4">
                                    <button onclick="editCustomer(<?= $row['id'] ?>)" class="btn btn-sm btn-outline-warning rounded-circle me-1"><i class="bi bi-pencil-square"></i></button>
                                    <?php if($is_admin): ?>
                                    <button onclick="confirmDelete(<?= $row['id'] ?>, 'customer')" class="btn btn-sm btn-outline-danger rounded-circle"><i class="bi bi-trash3"></i></button>
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
            <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=edit_customer" method="POST" enctype="multipart/form-data">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const actionHandlerUrl = <?= json_encode(app_path('actions/action-handler.php'), JSON_UNESCAPED_SLASHES) ?>;
const csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const uploadsLogosBase = <?= json_encode(upload_logos_base_url(), JSON_UNESCAPED_SLASHES) ?>;
function editCustomer(id) {
    fetch(`${actionHandlerUrl}?action=get_data&type=customer&id=${id}`).then(res => res.json()).then(data => {
        const fields = { id: 'edit_cust_id', name: 'edit_cust_name', tax_id: 'edit_cust_tax', address: 'edit_cust_address', phone: 'edit_cust_phone', email: 'edit_cust_email' };
        Object.keys(fields).forEach(key => document.getElementById(fields[key]).value = data[key]);
        document.getElementById('edit_cust_logo_view').innerHTML = data.logo ? `<img src="${uploadsLogosBase}${encodeURIComponent(data.logo)}" class="logo-preview" style="width:70px;height:70px">` : `<div class="logo-preview mx-auto d-flex align-items-center justify-content-center border" style="width:70px;height:70px"><i class="bi bi-person fs-2"></i></div>`;
        new bootstrap.Modal(document.getElementById('editCustomerModal')).show();
    });
}
function confirmDelete(id, type) {
    Swal.fire({ title: 'ยืนยันการลบ?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#fd7e14', confirmButtonText: 'ยืนยัน', cancelButtonText: 'ยกเลิก' })
    .then((r) => { if (r.isConfirmed) window.location.href = `${actionHandlerUrl}?action=delete&type=${type}&id=${id}&_csrf=${encodeURIComponent(csrfToken)}`; });
}
const params = new URLSearchParams(window.location.search);
if(params.has('success')) Swal.fire({ icon: 'success', title: 'สำเร็จ!', confirmButtonColor: '#fd7e14' });
if(params.has('deleted')) Swal.fire({ icon: 'success', title: 'ลบแล้ว!', confirmButtonColor: '#fd7e14' });
</script>
</body>
</html>