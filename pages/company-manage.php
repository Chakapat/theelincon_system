<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการบริษัท | Invoice System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background-color: #fffaf5; font-family: 'Sarabun', sans-serif; }
        .btn-orange { background-color: #fd7e14; color: white; border-radius: 10px; }
        .btn-orange:hover { background-color: #e8590c; color: white; }
        .logo-preview { height: 88px; width: 88px; object-fit: contain; border-radius: 10px; background: #f8f9fa; }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-0 text-dark"><i class="bi bi-building-add me-2 text-warning"></i>ข้อมูลบริษัท</h2>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-4 text-warning">
                        <i class="bi bi-plus-circle-fill fs-4 me-2"></i>
                        <h5 class="fw-bold mb-0 text-dark">เพิ่มบริษัทใหม่</h5>
                    </div>
                    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=add_company" method="POST" enctype="multipart/form-data">
                        <?php csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">โลโก้บริษัท</label>
                            <input type="file" name="logo" class="form-control border-0 bg-light py-2 rounded-3" accept="image/*">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">ชื่อบริษัท</label>
                            <input type="text" name="name" class="form-control border-0 bg-light py-2 rounded-3" placeholder="ชื่อบริษัทเต็ม" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">เลขผู้เสียภาษี</label>
                            <input type="text" name="tax_id" class="form-control border-0 bg-light py-2 rounded-3" placeholder="เลขประจำตัว 13 หลัก" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">ที่อยู่</label>
                            <textarea name="address" class="form-control border-0 bg-light rounded-3" rows="3" placeholder="ที่อยู่จดทะเบียน" required></textarea>
                        </div>
                        <div class="row g-2 mb-4">
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted">เบอร์โทรศัพท์</label>
                                <input type="text" name="phone" class="form-control border-0 bg-light py-2 rounded-3">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted">อีเมล</label>
                                <input type="email" name="email" class="form-control border-0 bg-light py-2 rounded-3">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-orange w-100 py-2 fw-bold shadow-sm"><i class="bi bi-save2 me-2"></i>บันทึกข้อมูล</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="p-4 border-bottom bg-white"><h5 class="fw-bold mb-0">รายชื่อบริษัททั้งหมด</h5></div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-muted small">
                            <tr>
                                <th class="ps-4 border-0">บริษัท</th>
                                <th class="border-0">เลขผู้เสียภาษี</th>
                                <th class="border-0">ติดต่อ</th>
                                <?php if($is_admin): ?>
                                <th class="border-0 pe-4 text-end">จัดการ</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($companies as $row): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <?php if($row['logo']): ?>
                                            <img src="<?= htmlspecialchars(upload_logo_url($row['logo'])) ?>" class="logo-preview me-3 border">
                                        <?php else: ?>
                                            <div class="logo-preview me-3 d-flex align-items-center justify-content-center border text-muted"><i class="bi bi-building"></i></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars($row['name']) ?></div>
                                            <small class="text-muted d-inline-block text-truncate" style="max-width: 180px;"><?= htmlspecialchars($row['address']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge bg-warning-subtle text-warning border px-3 py-2 rounded-pill"><?= htmlspecialchars($row['tax_id']) ?></span></td>
                                <td class="small">
                                    <div><i class="bi bi-telephone text-warning me-1"></i><?= htmlspecialchars($row['phone']) ?></div>
                                    <div class="text-muted"><i class="bi bi-envelope text-warning me-1"></i><?= htmlspecialchars($row['email']) ?></div>
                                </td>
                                <td class="text-end pe-4">
                                    <?php if($is_admin): ?>
                                    <button onclick="editCompany(<?= $row['id'] ?>)" class="btn btn-sm btn-outline-warning rounded-circle me-1"><i class="bi bi-pencil-square"></i></button>
                                    <button onclick="confirmDelete(<?= $row['id'] ?>, 'company')" class="btn btn-sm btn-outline-danger rounded-circle"><i class="bi bi-trash3"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($companies) === 0): ?>
                    <div class="text-center py-5 text-muted opacity-50"><i class="bi bi-inbox fs-1"></i><p>ยังไม่มีข้อมูลบริษัท</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editCompanyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=edit_company" method="POST" enctype="multipart/form-data">
                <?php csrf_field(); ?>
                <div class="modal-header border-0 pt-4 px-4">
                    <h5 class="fw-bold"><i class="bi bi-pencil-square me-2 text-warning"></i>แก้ไขข้อมูล</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 pt-0">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="text-center mb-3">
                        <div id="edit_logo_preview" class="mb-2"></div>
                        <input type="file" name="logo" class="form-control bg-light border-0 py-2 rounded-3" accept="image/*">
                    </div>
                    <div class="mb-3"><label class="small fw-bold">ชื่อบริษัท</label><input type="text" name="name" id="edit_name" class="form-control bg-light border-0 py-2 rounded-3" required></div>
                    <div class="mb-3"><label class="small fw-bold">เลขผู้เสียภาษี</label><input type="text" name="tax_id" id="edit_tax_id" class="form-control bg-light border-0 py-2 rounded-3" required></div>
                    <div class="mb-3"><label class="small fw-bold">ที่อยู่</label><textarea name="address" id="edit_address" class="form-control bg-light border-0 rounded-3" rows="2" required></textarea></div>
                    <div class="row g-2">
                        <div class="col-6"><label class="small fw-bold">โทรศัพท์</label><input type="text" name="phone" id="edit_phone" class="form-control bg-light border-0 py-2 rounded-3"></div>
                        <div class="col-6"><label class="small fw-bold">อีเมล</label><input type="email" name="email" id="edit_email" class="form-control bg-light border-0 py-2 rounded-3"></div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0"><button type="submit" class="btn btn-orange w-100 py-2 fw-bold">บันทึกการแก้ไข</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const actionHandlerUrl = <?= json_encode(app_path('actions/action-handler.php'), JSON_UNESCAPED_SLASHES) ?>;
const csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const uploadsLogosBase = <?= json_encode(upload_logos_base_url(), JSON_UNESCAPED_SLASHES) ?>;
function confirmDelete(id, type) {
    Swal.fire({
        title: 'ยืนยันการลบ?',
        text: "ข้อมูลนี้จะถูกลบถาวร!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#fd7e14',
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true
    }).then((r) => { if (r.isConfirmed) window.location.href = `${actionHandlerUrl}?action=delete&type=${type}&id=${id}&_csrf=${encodeURIComponent(csrfToken)}`; });
}

function editCompany(id) {
    fetch(`${actionHandlerUrl}?action=get_data&type=company&id=${id}`)
    .then(res => res.json()).then(data => {
        ['id','name','tax_id','address','phone','email'].forEach(k => document.getElementById(`edit_${k}`).value = data[k]);
        document.getElementById('edit_logo_preview').innerHTML = data.logo ? `<img src="${uploadsLogosBase}${encodeURIComponent(data.logo)}" class="logo-preview border p-1 shadow-sm mb-2" style="width:100px;height:100px">` : `<div class="logo-preview mx-auto d-flex align-items-center justify-content-center border mb-2" style="width:100px;height:100px"><i class="bi bi-image fs-2"></i></div>`;
        new bootstrap.Modal(document.getElementById('editCompanyModal')).show();
    });
}

const params = new URLSearchParams(window.location.search);
if (params.get('success')) Swal.fire({ icon: 'success', title: 'สำเร็จ!', confirmButtonColor: '#fd7e14' });
if (params.has('deleted')) Swal.fire({ icon: 'success', title: 'ลบเรียบร้อย!', confirmButtonColor: '#fd7e14' });
</script>
</body>
</html>