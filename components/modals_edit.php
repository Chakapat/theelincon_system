<?php
if (!function_exists('app_path')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
$ah = htmlspecialchars(app_path('actions/action-handler.php'));
?>
<div class="modal fade" id="editMemberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <form action="<?= $ah ?>?action=edit_member" method="POST">
                <div class="modal-header border-0 pt-4 px-4">
                    <h5 class="fw-bold text-dark"><i class="bi bi-person-gear me-2 text-warning"></i>แก้ไขสมาชิก</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 pt-0">
                    <input type="hidden" name="id" id="edit_member_id">
                    <input type="hidden" name="nickname" id="edit_nickname">
                    <div class="mb-3">
                        <label class="small fw-bold mb-1">รหัสพนักงาน</label>
                        <input type="text" name="user_code" id="edit_user_code" class="form-control bg-light border-0 py-2" readonly>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="small fw-bold mb-1">ชื่อ</label>
                            <input type="text" name="fname" id="edit_fname" class="form-control bg-light border-0 py-2" required>
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold mb-1">นามสกุล</label>
                            <input type="text" name="lname" id="edit_lname" class="form-control bg-light border-0 py-2" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold mb-1">สิทธิ์ระบบ</label>
                        <select name="role" id="edit_role" class="form-select bg-light border-0 py-2">
                            <option value="user">User</option>
                            <option value="Accounting">Accounting</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold mb-1">ตำแหน่งในการทำงาน <span class="text-muted fw-normal">(ออกหนังสือรับรอง)</span></label>
                        <input type="text" name="job_title" id="edit_job_title" class="form-control bg-light border-0 py-2" maxlength="160" placeholder="เช่น โฟร์แมน, วิศวกร, คนขับรถ">
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold mb-1">ที่อยู่ (สำหรับหนังสือรับรอง)</label>
                        <textarea name="address" id="edit_address" class="form-control bg-light border-0 py-2" rows="2" placeholder="บ้านเลขที่ ถนน ตำบล อำเภอ จังหวัด รหัสไปรษณีย์"></textarea>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="small fw-bold mb-1">ฐานเงินเดือน (บาท)</label>
                            <input type="text" name="salary_base" id="edit_salary_base" class="form-control bg-light border-0 py-2" placeholder="เช่น 25000" inputmode="decimal">
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold mb-1">วันเดือนปีเกิด</label>
                            <input type="date" name="birth_date" id="edit_birth_date" class="form-control bg-light border-0 py-2">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold mb-1">เลขบัตรประจำตัวประชาชน (13 หลัก)</label>
                        <input type="text" name="national_id" id="edit_national_id" class="form-control bg-light border-0 py-2" maxlength="17" placeholder="กรอกตัวเลข 13 หลัก" inputmode="numeric">
                    </div>
                    <div class="mb-0 text-muted">
                        <label class="small fw-bold mb-1">เปลี่ยนรหัสผ่าน (เว้นว่างไว้ถ้าไม่เปลี่ยน)</label>
                        <input type="password" name="password" class="form-control bg-light border-0 py-2" placeholder="New Password">
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="submit" class="btn w-100 py-2 fw-bold text-white shadow-sm" style="background-color: #fd7e14; border-radius: 10px;">บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>
</div>
