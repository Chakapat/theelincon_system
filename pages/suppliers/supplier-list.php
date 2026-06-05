<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_flash.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$is_admin = user_is_admin_role();

$suppliers = Db::tableRows('suppliers');
Db::sortRows($suppliers, 'name', false);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการผู้ขาย (Suppliers)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600&display=swap" rel="stylesheet">
    <style>
        /* canvas + cards: tnc-app.css */
    </style>
</head>
<body class="tnc-app-body">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-5">
    <?php
    $supplierFlash = tnc_flash_from_query($_GET);
    if ($supplierFlash !== null && isset($_GET['success'])) {
        $supplierFlash['message'] = 'บันทึกข้อมูลเรียบร้อยแล้ว';
    }
    if (isset($_GET['error']) && $_GET['error'] === 'in_use') {
        $supplierFlash = ['type' => 'danger', 'message' => 'ไม่สามารถลบได้: ผู้ขายรายนี้ถูกใช้ในใบสั่งซื้อ (PO) แล้ว'];
    }
    tnc_render_flash($supplierFlash);
    ?>

    <div class="tnc-page-head">
        <div>
            <p class="tnc-page-kicker">Organization</p>
            <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-truck"></i></span>ระบบจัดการผู้ขาย</h1>
        </div>
        <a href="<?= htmlspecialchars(app_path('pages/suppliers/supplier-form.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange px-4 shadow-sm"><i class="bi bi-plus-lg me-2"></i> เพิ่มผู้ขายใหม่</a>
    </div>

    <div class="card main-card tnc-list-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="supplierTable" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th>ชื่อบริษัท/ร้านค้า</th>
                        <th>ผู้ติดต่อ</th>
                        <th>เบอร์โทรศัพท์</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($suppliers) === 0): ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted">ยังไม่มีข้อมูลผู้ขายในระบบ</td></tr>
                    <?php else: ?>
                    <?php foreach ($suppliers as $row): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['contact_person']) ?: '-' ?></td>
                        <td><?= $row['phone'] ?></td>
                        <td class="text-center">
                            <a href="<?= htmlspecialchars(app_path('pages/suppliers/supplier-form.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                            <?php if($is_admin): ?>
                            <button onclick="deleteSup(<?= $row['id'] ?>)" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
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
function deleteSup(id) {
    Swal.fire({
        icon: 'warning',
        title: 'ยืนยันการลบ',
        html: 'ลบผู้ขายรายนี้ถาวร — กรุณาใส่<strong>รหัสผ่านของคุณ</strong>',
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
        [['action', 'delete_supplier'], ['id', String(id)], ['_csrf', csrfToken], ['confirm_password', result.value]].forEach(function (pair) {
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
    if ($('#supplierTable tbody tr td[colspan]').length === 0 && $('#supplierTable tbody tr').length) {
        $('#supplierTable').DataTable({
            order: [[0, 'asc']],
            pageLength: 25,
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
            columnDefs: [{ targets: [-1], orderable: false, searchable: false }]
        });
    }
    var u = <?= json_encode(app_path('actions/live-datasets.php?dataset=mirror_table&table=suppliers'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
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