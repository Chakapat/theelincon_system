<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/contractors.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$is_admin = user_is_admin_role();

$contractors = Db::tableRows('contractors');
usort($contractors, static function (array $a, array $b): int {
    return strnatcasecmp(tnc_contractor_full_name_th($a), tnc_contractor_full_name_th($b));
});
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลงทะเบียนผู้รับจ้าง</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* body canvas: tnc-app.css */
        .main-card { border: none; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
    </style>
</head>
<body class="tnc-app-body">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-5 mb-5">
    <?php if (!empty($_GET['success'])): ?>
        <div class="alert alert-success">บันทึกข้อมูลผู้รับจ้างเรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if (($_GET['error'] ?? '') === 'in_use'): ?>
        <div class="alert alert-danger">ไม่สามารถลบได้: ผู้รับจ้างรายนี้ถูกใช้ใน PR / PO / สัญญาจ้างแล้ว</div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h2 class="fw-bold text-tnc-orange mb-0"><i class="bi bi-person-badge"></i> ลงทะเบียนผู้รับจ้าง</h2>
        <a href="<?= htmlspecialchars(app_path('pages/contractors/contractor-form.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange px-4 shadow-sm">
            <i class="bi bi-plus-lg me-2"></i>เพิ่มผู้รับจ้าง
        </a>
    </div>

    <div class="card main-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="contractorTable" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th>ชื่อ-นามสกุล (ไทย)</th>
                        <th>ชื่อ-นามสกุล (อังกฤษ)</th>
                        <th>เลขบัตรประชาชน</th>
                        <th>ธนาคาร / เลขบัญชี</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($contractors) === 0): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">ยังไม่มีผู้รับจ้างในระบบ</td></tr>
                    <?php else: ?>
                    <?php foreach ($contractors as $row): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars(tnc_contractor_full_name_th($row), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(tnc_contractor_full_name_en($row), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($row['national_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small">
                            <?= htmlspecialchars((string) ($row['bank_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?><br>
                            <span class="text-muted"><?= htmlspecialchars((string) ($row['bank_account_no'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td class="text-center text-nowrap">
                            <a href="<?= htmlspecialchars(app_path('pages/contractors/contractor-form.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) ($row['id'] ?? 0) ?>" class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                            <?php if ($is_admin): ?>
                            <button type="button" onclick="deleteContractor(<?= (int) ($row['id'] ?? 0) ?>)" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const actionHandlerUrl = <?= json_encode(app_path('actions/action-handler.php'), JSON_UNESCAPED_SLASHES) ?>;
const csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
function deleteContractor(id) {
    Swal.fire({
        icon: 'warning',
        title: 'ยืนยันการลบ',
        html: 'ลบผู้รับจ้างรายนี้ถาวร — กรุณาใส่<strong>รหัสผ่านของคุณ</strong>',
        input: 'password',
        inputPlaceholder: 'รหัสผ่าน',
        showCancelButton: true,
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#d33',
        focusCancel: true,
        preConfirm: function (pw) {
            if (!pw || !String(pw).trim()) {
                Swal.showValidationMessage('กรุณากรอกรหัสผ่าน');
                return false;
            }
            return pw;
        }
    }).then(function (result) {
        if (!result.isConfirmed || !result.value) return;
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = actionHandlerUrl;
        form.style.display = 'none';
        [['action', 'delete_contractor'], ['id', String(id)], ['_csrf', csrfToken], ['confirm_password', result.value]].forEach(function (pair) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = pair[0];
            inp.value = pair[1];
            form.appendChild(inp);
        });
        document.body.appendChild(form);
        form.submit();
    });
}
</script>
<script>
(function ($) {
    if ($('#contractorTable tbody tr td[colspan]').length === 0 && $('#contractorTable tbody tr').length) {
        $('#contractorTable').DataTable({
            order: [[0, 'asc']],
            pageLength: 25,
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
            columnDefs: [{ targets: [-1], orderable: false, searchable: false }]
        });
    }
    var u = <?= json_encode(app_path('actions/live-datasets.php?dataset=mirror_table&table=contractors'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
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
