<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . app_path('index.php'));
    exit();
}

$userRows = Db::tableRows('users');
Db::sortRows($userRows, 'userid', true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการสมาชิก | Invoice System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Sarabun', sans-serif; background-color: #fffaf5; }
        .btn-orange { background-color: #fd7e14; color: white; border: none; }
        .btn-orange:hover { background-color: #e8590c; color: white; }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container pb-5">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-4"><i class="bi bi-person-plus-fill me-2 text-warning"></i>เพิ่มสมาชิกใหม่</h5>
                    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=add_member" method="POST">
                        <?php csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">รหัสพนักงาน</label>
                            <input type="text" name="user_code" class="form-control bg-light border-0 py-2" placeholder="เช่น EMP01" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">ชื่อ</label>
                                <input type="text" name="fname" class="form-control bg-light border-0 py-2" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">นามสกุล</label>
                                <input type="text" name="lname" class="form-control bg-light border-0 py-2" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">สิทธิ์ระบบ</label>
                            <select name="role" class="form-select bg-light border-0 py-2">
                                <option value="user">User</option>
                                <option value="Accounting">Accounting</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">ตำแหน่งในการทำงาน <span class="text-muted fw-normal">(ออกหนังสือรับรอง)</span></label>
                            <input type="text" name="job_title" class="form-control bg-light border-0 py-2" maxlength="160" placeholder="เช่น โฟร์แมน, วิศวกร, คนขับรถ">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">ชื่อเล่น <span class="text-muted fw-normal">(ไม่บังคับ)</span></label>
                            <input type="text" name="nickname" class="form-control bg-light border-0 py-2" placeholder="ชื่อเล่น">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">ที่อยู่ <span class="text-muted fw-normal">(สำหรับหนังสือรับรอง)</span></label>
                            <textarea name="address" class="form-control bg-light border-0 py-2" rows="2" placeholder="บ้านเลขที่ ถนน ตำบล อำเภอ จังหวัด รหัสไปรษณีย์"></textarea>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">ฐานเงินเดือน (บาท)</label>
                                <input type="text" name="salary_base" class="form-control bg-light border-0 py-2" placeholder="เช่น 25000" inputmode="decimal">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">วันเกิด</label>
                                <input type="date" name="birth_date" class="form-control bg-light border-0 py-2">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">เลขบัตรประชาชน 13 หลัก</label>
                            <input type="text" name="national_id" class="form-control bg-light border-0 py-2" maxlength="17" placeholder="ตัวเลข 13 หลัก" inputmode="numeric">
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold">รหัสผ่าน</label>
                            <input type="password" name="password" class="form-control bg-light border-0 py-2" required>
                        </div>
                        <button type="submit" class="btn btn-orange w-100 rounded-pill py-2 fw-bold shadow-sm">บันทึกสมาชิก</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-4"><i class="bi bi-people-fill me-2 text-warning"></i>รายชื่อสมาชิก</h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="border-0 ps-3">รหัส</th>
                                    <th class="border-0">ชื่อ-นามสกุล</th>
                                    <th class="border-0 text-center">สิทธิ์</th>
                                    <th class="border-0">ตำแหน่งงาน</th>
                                    <th class="border-0 text-end pe-3">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userRows as $row): ?>
                                <tr>
                                    <td class="fw-bold text-orange ps-3" style="color:#fd7e14;"><?= $row['user_code'] ?></td>
                                    <td><?= "{$row['fname']} {$row['lname']}" ?></td>
                                    <td class="text-center">
                                        <span class="badge rounded-pill px-3 <?= $row['role'] == 'admin' ? 'bg-danger-subtle text-danger' : 'bg-info-subtle text-info' ?>">
                                            <?= htmlspecialchars(strtoupper((string) ($row['role'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="small text-muted"><?= htmlspecialchars(trim((string) ($row['job_title'] ?? '')) ?: '—') ?></td>
                                    <td class="text-end pe-3">
                                        <button onclick="editMember(<?= $row['userid'] ?>)" class="btn btn-sm btn-light border text-warning rounded-3"><i class="bi bi-pencil-square"></i></button>
                                        <button onclick="confirmDelete(<?= $row['userid'] ?>, 'member')" class="btn btn-sm btn-light border text-danger rounded-3 ms-1"><i class="bi bi-trash3-fill"></i></button>
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
</div>

<?php include __DIR__ . '/../components/modals_edit.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const actionHandlerUrl = <?= json_encode(app_path('actions/action-handler.php'), JSON_UNESCAPED_SLASHES) ?>;
const csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
// ดึงข้อมูลสมาชิกใส่ Modal
function editMember(id) {
    fetch(`${actionHandlerUrl}?action=get_data&type=member&id=${id}`)
    .then(res => res.json())
    .then(data => {
        document.getElementById('edit_member_id').value = data.userid;
        document.getElementById('edit_user_code').value = data.user_code || '';
        document.getElementById('edit_fname').value = data.fname || '';
        document.getElementById('edit_lname').value = data.lname || '';
        document.getElementById('edit_nickname').value = data.nickname || '';
        document.getElementById('edit_role').value = data.role || 'user';
        document.getElementById('edit_job_title').value = data.job_title || '';
        document.getElementById('edit_address').value = data.address || '';
        document.getElementById('edit_salary_base').value = data.salary_base != null && data.salary_base !== '' ? String(data.salary_base) : '';
        document.getElementById('edit_birth_date').value = (data.birth_date && data.birth_date !== '0000-00-00') ? data.birth_date.substring(0, 10) : '';
        document.getElementById('edit_national_id').value = data.national_id || '';
        new bootstrap.Modal(document.getElementById('editMemberModal')).show();
    });
}

// ระบบแจ้งเตือนและลบข้อมูล
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.has('success')) {
    const msg = urlParams.get('success') === 'updated' ? 'แก้ไขข้อมูลสมาชิกเรียบร้อยแล้ว' : 'บันทึกข้อมูลเรียบร้อยแล้ว';
    Swal.fire('สำเร็จ!', msg, 'success');
}

function confirmDelete(id, type) {
    Swal.fire({
        title: 'ยืนยันการลบ?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#fd7e14',
        confirmButtonText: 'ลบข้อมูล',
        cancelButtonText: 'ยกเลิก'
    }).then((r) => r.isConfirmed && (window.location.href = `${actionHandlerUrl}?action=delete&type=${type}&id=${id}&_csrf=${encodeURIComponent(csrfToken)}`));
}
</script>
</body>
</html>