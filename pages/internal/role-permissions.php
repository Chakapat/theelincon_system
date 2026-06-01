<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

$tncRolePermissionsFile = dirname(__DIR__, 2) . '/includes/role_permissions.php';
if (!is_file($tncRolePermissionsFile) || !function_exists('tnc_role_permission_definitions')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="th"><head><meta charset="UTF-8"><title>ยังติดตั้งไม่ครบ</title></head><body style="font-family:Sarabun,sans-serif;padding:2rem;">';
    echo '<h1>ยัง deploy ไฟล์สิทธิ์ไม่ครบ</h1>';
    echo '<p>อัปโหลด <code>includes/role_permissions.php</code> ขึ้น server แล้วรีเฟรชหน้านี้</p>';
    echo '</body></html>';
    exit;
}

require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_is_admin_only_role()) {
    http_response_code(403);
    echo 'ไม่มีสิทธิ์เข้าถึง — หน้านี้สำหรับผู้ดูแลระบบ (ADMIN) เท่านั้น';
    exit;
}

$configError = '';
$saved = !empty($_GET['saved']);

$definitions = tnc_role_permission_definitions();
$roles = tnc_role_permission_roles();
$matrix = tnc_role_permissions_matrix();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_role_permissions'])) {
    if (!csrf_verify_request()) {
        $configError = 'csrf';
    } else {
        $raw = $_POST['permissions'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $before = tnc_role_permissions_config_row();
        $newMatrix = tnc_role_permissions_normalize_submitted($raw);
        try {
            tnc_role_permissions_save($newMatrix, (int) $_SESSION['user_id']);
            tnc_audit_log('update', 'role_permissions', TNC_ROLE_PERMISSIONS_PK, 'ตั้งค่าสิทธิ์ตามบทบาท', [
                'source' => 'role-permissions.php',
                'before' => $before['permissions_json'] ?? ($before['permissions'] ?? tnc_role_permission_defaults()),
                'after' => $newMatrix,
            ]);
            header('Location: ' . app_path('pages/internal/role-permissions.php') . '?saved=1');
            exit;
        } catch (Throwable $e) {
            $configError = 'save_failed';
        }
    }
}

/** @var array<string, list<array{key: string, def: array{label: string, group: string, hint?: string}}>> $grouped */
$grouped = [];
foreach ($definitions as $key => $def) {
    $group = (string) ($def['group'] ?? 'อื่นๆ');
    $grouped[$group][] = ['key' => $key, 'def' => $def];
}

$roleLabels = [
    'CEO' => 'CEO',
    'ADMIN' => 'ADMIN',
    'ACCOUNTING' => 'Accounting',
    'USER' => 'User',
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าสิทธิ์ตามบทบาท | Theelincon</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/tnc-app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }
        .perm-card { border: 0; border-radius: 1rem; box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06); }
        .perm-table thead th { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; background: #f8fafc; vertical-align: middle; }
        .perm-table tbody td { vertical-align: middle; }
        .perm-table .perm-name { font-weight: 600; color: #0f172a; }
        .perm-table .perm-hint { font-size: 0.78rem; color: #64748b; }
        .perm-table input[type="checkbox"] { width: 1.1rem; height: 1.1rem; cursor: pointer; }
        .role-col-CEO { background: #fff5f5; }
        .role-col-ADMIN { background: #fff7ed; }
        .role-col-ACCOUNTING { background: #fffbeb; }
        .role-col-USER { background: #f0f9ff; }
        .perm-group-row td { background: #f1f5f9; font-weight: 700; font-size: 0.85rem; color: #334155; }
    </style>
</head>
<body class="tnc-app-body">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container pb-5">
    <div class="tnc-page-head pt-4">
        <div>
            <h1 class="tnc-list-title">
                <span class="tnc-list-title__icon me-2"><i class="bi bi-shield-lock"></i></span>
                ตั้งค่าสิทธิ์ตามบทบาท
            </h1>
        </div>
    </div>

    <?php if ($saved): ?>
        <div class="alert alert-success rounded-3 border-0 shadow-sm">บันทึกสิทธิ์เรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if ($configError === 'csrf'): ?>
        <div class="alert alert-warning rounded-3 border-0 shadow-sm">โทเค็นความปลอดภัยไม่ถูกต้อง — กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง</div>
    <?php elseif ($configError === 'save_failed'): ?>
        <div class="alert alert-danger rounded-3 border-0 shadow-sm">บันทึกไม่สำเร็จ — ตรวจสอบการเชื่อมต่อ Firebase หรือ deploy ไฟล์ <code>includes/role_permissions.php</code> ให้ครบ</div>
    <?php endif; ?>

    <div class="card perm-card">
        <div class="card-body p-4">
            <form method="post" action="">
                <?php csrf_field(); ?>
                <input type="hidden" name="save_role_permissions" value="1">

                <div class="table-responsive">
                    <table class="table table-bordered perm-table mb-0">
                        <thead>
                            <tr>
                                <th style="min-width: 14rem;">รายการสิทธิ์</th>
                                <?php foreach ($roles as $role): ?>
                                    <th class="text-center role-col-<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>" style="min-width: 5.5rem;">
                                        <?= htmlspecialchars($roleLabels[$role] ?? $role, ENT_QUOTES, 'UTF-8') ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped as $groupName => $items): ?>
                                <tr class="perm-group-row">
                                    <td colspan="<?= count($roles) + 1 ?>"><?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                                <?php foreach ($items as $item): ?>
                                    <?php
                                    $pKey = $item['key'];
                                    $def = $item['def'];
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="perm-name"><?= htmlspecialchars((string) $def['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php if (!empty($def['hint'])): ?>
                                                <div class="perm-hint"><?= htmlspecialchars((string) $def['hint'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <?php foreach ($roles as $role): ?>
                                            <?php $checked = !empty($matrix[$role][$pKey]); ?>
                                            <td class="text-center role-col-<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="checkbox"
                                                       class="form-check-input perm-cb"
                                                       name="permissions[<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>][<?= htmlspecialchars($pKey, ENT_QUOTES, 'UTF-8') ?>]"
                                                       value="1"
                                                       data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"
                                                       data-perm="<?= htmlspecialchars($pKey, ENT_QUOTES, 'UTF-8') ?>"
                                                       <?= $checked ? 'checked' : '' ?>>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-orange rounded-pill px-4 fw-semibold">
                        <i class="bi bi-check2-circle me-1"></i>บันทึกสิทธิ์
                    </button>
                    <a href="<?= htmlspecialchars(app_path('pages/organization/member-manage.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-4">
                        จัดการสมาชิก
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var defaults = <?= json_encode(tnc_role_permission_defaults(), JSON_UNESCAPED_UNICODE) ?>;
    document.getElementById('btnResetDefaults')?.addEventListener('click', function () {
        if (!confirm('ตั้งค่า checkbox กลับเป็นค่าเริ่มต้นของระบบ? (ยังไม่บันทึกจนกว่าจะกดบันทึกสิทธิ์)')) {
            return;
        }
        document.querySelectorAll('.perm-cb').forEach(function (cb) {
            var role = cb.getAttribute('data-role');
            var perm = cb.getAttribute('data-perm');
            cb.checked = !!(defaults[role] && defaults[role][perm]);
        });
    });
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
