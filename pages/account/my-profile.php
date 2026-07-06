<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_shell_head.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$uid = (int) $_SESSION['user_id'];
$me = Db::row('users', (string) $uid);
if ($me === null) {
    header('Location: ' . app_path('index.php'));
    exit;
}

$err = isset($_GET['error']) ? trim((string) $_GET['error']) : '';
$ok = isset($_GET['success']) && (string) $_GET['success'] === '1';

$errMsg = match ($err) {
    'name_required' => 'กรุณากรอกชื่อและนามสกุล',
    'birth_date_invalid' => 'วันเกิดไม่ถูกต้อง กรุณาเลือกจากปฏิทินหรือเว้นว่าง',
    'national_id_invalid' => 'เลขบัตรประชาชนต้องเป็นตัวเลขครบ 13 หลัก หรือเว้นว่าง',
    'password_mismatch' => 'รหัสผ่านใหม่กับยืนยันไม่ตรงกัน',
    'password_weak' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร',
    'user_not_found' => 'ไม่พบข้อมูลผู้ใช้',
    'confirm_password_required' => 'กรุณากรอกรหัสผ่านปัจจุบันเพื่อยืนยันทุกครั้ง',
    'confirm_password_invalid' => 'รหัสผ่านยืนยันไม่ถูกต้อง',
    default => $err !== '' ? 'ไม่สามารถบันทึกได้' : '',
};

$fname = htmlspecialchars((string) ($me['fname'] ?? ''), ENT_QUOTES, 'UTF-8');
$lname = htmlspecialchars((string) ($me['lname'] ?? ''), ENT_QUOTES, 'UTF-8');
$address = htmlspecialchars((string) ($me['address'] ?? ''), ENT_QUOTES, 'UTF-8');
$birthIso = '';
$birthRaw = trim((string) ($me['birth_date'] ?? ''));
if ($birthRaw !== '' && $birthRaw !== '0000-00-00' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthRaw, $bm)) {
    $y = (int) $bm[1];
    $m = (int) $bm[2];
    $d = (int) $bm[3];
    if (checkdate($m, $d, $y)) {
        $birthIso = sprintf('%04d-%02d-%02d', $y, $m, $d);
    }
}
$birthValue = htmlspecialchars($birthIso, ENT_QUOTES, 'UTF-8');
$todayIso = htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8');
$nid = htmlspecialchars((string) ($me['national_id'] ?? ''), ENT_QUOTES, 'UTF-8');
$ah = htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php tnc_shell_head([
        'title' => 'แก้ไขข้อมูลส่วนตัว | Invoice System',
        'sweetalert' => true,
    ]); ?>
    <style>
        .tnc-sw-change-pw .swal2-html-container { text-align: start; }
        .tnc-sw-change-pw .swal2-html-container .input-group .form-control { font-size: 1rem; }
    </style>
</head>
<body class="tnc-app-body tnc-layout-form">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container pb-5" style="max-width: 40rem;">
    <div class="card shadow-sm border-0 rounded-4 mt-3">
        <div class="card-body p-4 p-md-5">
            <h4 class="fw-bold mb-1"><i class="bi bi-person-gear me-2 text-warning"></i>แก้ไขข้อมูลส่วนตัว</h4>

            <?php if (!$ok && $errMsg !== '' && !in_array($err, ['confirm_password_invalid', 'confirm_password_required', 'password_mismatch', 'password_weak'], true)): ?>
                <div class="alert alert-danger border-0 rounded-3 small"><?= htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form id="tnc-my-profile-form" action="<?= $ah ?>?action=update_my_profile" method="POST" data-tnc-fullnav="1">
                <?php csrf_field(); ?>
                <input type="hidden" name="confirm_password" value="" autocomplete="new-password" aria-hidden="true">
                <input type="hidden" name="new_password" id="tnc-profile-np" value="">
                <input type="hidden" name="new_password_confirm" id="tnc-profile-npc" value="">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">ชื่อ <span class="text-danger">*</span></label>
                        <input type="text" name="fname" class="form-control bg-light border-0 py-2" required maxlength="120" value="<?= $fname ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">นามสกุล <span class="text-danger">*</span></label>
                        <input type="text" name="lname" class="form-control bg-light border-0 py-2" required maxlength="120" value="<?= $lname ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">ที่อยู่</label>
                    <textarea name="address" class="form-control bg-light border-0 py-2" rows="3" maxlength="2000" placeholder="บ้านเลขที่ ถนน ตำบล อำเภอ จังหวัด รหัสไปรษณีย์"><?= $address ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">วันเกิด</label>
                    <input type="date" name="birth_date" class="form-control bg-light border-0 py-2" value="<?= $birthValue ?>" min="1900-01-01" max="<?= $todayIso ?>">
                    <p class="form-text small text-muted mb-0">เลือกวันที่จากปฏิทิน หรือเว้นว่างหากไม่ระบุ</p>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">เลขบัตรประจำตัวประชาชน 13 หลัก</label>
                    <input type="text" name="national_id" class="form-control bg-light border-0 py-2" maxlength="17" placeholder="ตัวเลข 13 หลัก หรือเว้นว่าง" inputmode="numeric" value="<?= $nid ?>">
                </div>

                <hr class="my-4 opacity-25">
                <div class="mb-4">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 py-2" id="tnc-change-password-btn">
                        <i class="bi bi-key me-2"></i>เปลี่ยนรหัสผ่าน
                    </button>
                </div>

                <button type="submit" class="btn btn-orange w-100 rounded-pill py-2 fw-bold shadow-sm">ยืนยันการบันทึก</button>
            </form>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<script>
(function () {
    var form = document.getElementById('tnc-my-profile-form');
    if (!form || typeof Swal === 'undefined') return;

    var params = new URLSearchParams(window.location.search);
    if (params.get('success') === '1') {
        Swal.fire({
            icon: 'success',
            title: 'บันทึกแล้ว',
            text: 'อัปเดตข้อมูลส่วนตัวเรียบร้อย',
            confirmButtonColor: '#ea580c'
        });
        params.delete('success');
        try {
            window.history.replaceState({}, '', window.location.pathname + (params.toString() ? ('?' + params.toString()) : ''));
        } catch (e) {}
    } else if (params.get('error') === 'confirm_password_invalid') {
        Swal.fire({
            icon: 'error',
            title: 'รหัสผ่านไม่ถูกต้อง',
            text: 'กรุณาลองอีกครั้ง',
            confirmButtonColor: '#ea580c'
        });
        params.delete('error');
        try {
            window.history.replaceState({}, '', window.location.pathname + (params.toString() ? ('?' + params.toString()) : ''));
        } catch (e2) {}
    } else if (params.get('error') === 'confirm_password_required') {
        Swal.fire({
            icon: 'warning',
            title: 'ต้องยืนยันรหัสผ่าน',
            text: 'กรุณาใช้ปุ่มบันทึกและกรอกรหัสผ่านในหน้าต่างที่ขึ้นมา',
            confirmButtonColor: '#ea580c'
        });
        params.delete('error');
        try {
            window.history.replaceState({}, '', window.location.pathname + (params.toString() ? ('?' + params.toString()) : ''));
        } catch (e3) {}
    } else if (params.get('error') === 'password_mismatch') {
        Swal.fire({
            icon: 'error',
            title: 'รหัสผ่านใหม่ไม่ตรงกัน',
            text: 'กรุณาลองเปลี่ยนรหัสผ่านอีกครั้ง',
            confirmButtonColor: '#ea580c'
        });
        params.delete('error');
        try {
            window.history.replaceState({}, '', window.location.pathname + (params.toString() ? ('?' + params.toString()) : ''));
        } catch (e4) {}
    } else if (params.get('error') === 'password_weak') {
        Swal.fire({
            icon: 'warning',
            title: 'รหัสผ่านสั้นเกินไป',
            text: 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร',
            confirmButtonColor: '#ea580c'
        });
        params.delete('error');
        try {
            window.history.replaceState({}, '', window.location.pathname + (params.toString() ? ('?' + params.toString()) : ''));
        } catch (e5) {}
    }

    function bindPwEye(inputId, btnId) {
        var inp = document.getElementById(inputId);
        var btn = document.getElementById(btnId);
        if (!inp || !btn) return;
        btn.addEventListener('click', function () {
            var show = inp.getAttribute('type') === 'password';
            inp.setAttribute('type', show ? 'text' : 'password');
            var ico = btn.querySelector('i');
            if (ico) {
                ico.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
            }
            btn.setAttribute('aria-label', show ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
        });
    }

    function tncSubmitProfileWithConfirm(opts) {
        opts = opts || {};
        var clearNewPwOnCancel = !!opts.clearNewPwOnCancel;
        var dlgTitle = opts.confirmTitle || 'ยืนยันการบันทึก';
        var dlgHtml = opts.confirmHtml || 'กรอก<strong>รหัสผ่านปัจจุบัน</strong>ของคุณเพื่อบันทึกการเปลี่ยนแปลง';
        Swal.fire({
            title: dlgTitle,
            html: dlgHtml,
            icon: 'question',
            input: 'password',
            inputAttributes: { autocapitalize: 'off', autocorrect: 'off', autocomplete: 'current-password' },
            showCancelButton: true,
            confirmButtonText: 'บันทึก',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#ea580c',
            cancelButtonColor: '#6c757d',
            focusCancel: false,
            preConfirm: function (pw) {
                if (!pw || !String(pw).trim()) {
                    Swal.showValidationMessage('กรุณากรอกรหัสผ่าน');
                    return false;
                }
                return pw;
            }
        }).then(function (res) {
            if (!res.isConfirmed || !res.value) {
                if (clearNewPwOnCancel) {
                    var npEl = document.getElementById('tnc-profile-np');
                    var npcEl = document.getElementById('tnc-profile-npc');
                    if (npEl) npEl.value = '';
                    if (npcEl) npcEl.value = '';
                }
                return;
            }
            var hid = form.querySelector('input[name="confirm_password"]');
            if (hid) hid.value = res.value;
            if (typeof HTMLFormElement !== 'undefined' && HTMLFormElement.prototype.submit) {
                HTMLFormElement.prototype.submit.call(form);
            } else {
                form.submit();
            }
        });
    }

    var changePwBtn = document.getElementById('tnc-change-password-btn');
    if (changePwBtn) {
        changePwBtn.addEventListener('click', function () {
            Swal.fire({
                title: 'เปลี่ยนรหัสผ่าน',
                html: ''
                    + '<p class="small text-muted mb-3">รหัสผ่านใหม่อย่างน้อย 6 ตัวอักษร</p>'
                    + '<label class="form-label small fw-bold" for="swal-np">รหัสผ่านใหม่</label>'
                    + '<div class="input-group mb-3">'
                    + '<input type="password" id="swal-np" class="form-control" autocomplete="new-password" minlength="6">'
                    + '<button type="button" class="btn btn-outline-secondary" id="swal-np-eye" aria-label="แสดงรหัสผ่าน"><i class="bi bi-eye"></i></button>'
                    + '</div>'
                    + '<label class="form-label small fw-bold" for="swal-npc">ยืนยันรหัสผ่านใหม่</label>'
                    + '<div class="input-group mb-1">'
                    + '<input type="password" id="swal-npc" class="form-control" autocomplete="new-password" minlength="6">'
                    + '<button type="button" class="btn btn-outline-secondary" id="swal-npc-eye" aria-label="แสดงรหัสผ่าน"><i class="bi bi-eye"></i></button>'
                    + '</div>',
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'เปลี่ยน',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#ea580c',
                cancelButtonColor: '#6c757d',
                focusConfirm: false,
                customClass: { popup: 'tnc-sw-change-pw' },
                didOpen: function () {
                    bindPwEye('swal-np', 'swal-np-eye');
                    bindPwEye('swal-npc', 'swal-npc-eye');
                    var el = document.getElementById('swal-np');
                    if (el) el.focus();
                },
                preConfirm: function () {
                    var a = document.getElementById('swal-np');
                    var b = document.getElementById('swal-npc');
                    var v1 = a ? String(a.value) : '';
                    var v2 = b ? String(b.value) : '';
                    if (v1.length < 6) {
                        Swal.showValidationMessage('รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร');
                        return false;
                    }
                    if (v1 !== v2) {
                        Swal.showValidationMessage('รหัสผ่านใหม่กับยืนยันไม่ตรงกัน');
                        return false;
                    }
                    return { np: v1, npc: v2 };
                }
            }).then(function (res1) {
                if (!res1.isConfirmed || !res1.value || !res1.value.np) return;
                var npEl = document.getElementById('tnc-profile-np');
                var npcEl = document.getElementById('tnc-profile-npc');
                if (npEl) npEl.value = res1.value.np;
                if (npcEl) npcEl.value = res1.value.npc;
                tncSubmitProfileWithConfirm({
                    clearNewPwOnCancel: true,
                    confirmTitle: 'ยืนยันรหัสผ่านปัจจุบัน',
                    confirmHtml: 'กรอก<strong>รหัสผ่านปัจจุบัน</strong>เพื่อยืนยันการตั้งรหัสผ่านใหม่และบันทึกข้อมูลโปรไฟล์'
                });
            });
        });
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
            return;
        }
        tncSubmitProfileWithConfirm();
    });
})();
</script>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
