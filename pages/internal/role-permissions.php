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
require_once dirname(__DIR__, 2) . '/includes/tnc_flash.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_can('page.internal.roles')) {
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

$actionDefinitions = function_exists('tnc_role_action_permission_definitions')
    ? tnc_role_action_permission_definitions()
    : $definitions;
$menuTree = function_exists('tnc_role_permission_menu_tree') ? tnc_role_permission_menu_tree() : [];

/**
 * @param array<string, bool> $roleMatrix
 */
function perm_render_matrix_row(string $permKey, string $label, ?string $hint, string $rowClass, array $roles, array $roleMatrix, array $roleLabels): void
{
    ?>
    <tr class="<?= htmlspecialchars($rowClass, ENT_QUOTES, 'UTF-8') ?>">
        <td>
            <div class="perm-name"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($hint !== null && $hint !== ''): ?>
                <div class="perm-hint"><?= htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </td>
        <?php foreach ($roles as $role): ?>
            <?php $checked = !empty($roleMatrix[$role][$permKey]); ?>
            <td class="text-center role-col-<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
                <input type="checkbox"
                       class="form-check-input perm-cb"
                       name="permissions[<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>][<?= htmlspecialchars($permKey, ENT_QUOTES, 'UTF-8') ?>]"
                       value="1"
                       data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"
                       data-perm="<?= htmlspecialchars($permKey, ENT_QUOTES, 'UTF-8') ?>"
                       <?= $checked ? 'checked' : '' ?>>
            </td>
        <?php endforeach; ?>
    </tr>
    <?php
}

/**
 * @param string $groupName
 */
function perm_group_slug(string $groupName): string
{
    $slug = preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($groupName, 'UTF-8')) ?? '';
    $slug = trim((string) $slug, '-');

    return $slug !== '' ? 'perm-grp-' . $slug : 'perm-grp-other';
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
        .perm-accordion .accordion-item {
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem !important;
            overflow: hidden;
            margin-bottom: 0.65rem;
        }
        .perm-accordion .accordion-item:last-child { margin-bottom: 0; }
        .perm-accordion .accordion-button {
            font-weight: 700;
            font-size: 0.92rem;
            color: #334155;
            background: #f8fafc;
            box-shadow: none;
            padding: 0.85rem 1rem;
        }
        .perm-accordion .accordion-button:not(.collapsed) {
            background: #fff7ed;
            color: #9a3412;
        }
        .perm-accordion .accordion-button:focus {
            box-shadow: none;
            border-color: transparent;
        }
        .perm-accordion .accordion-button::after {
            opacity: 0.55;
        }
        .perm-group-meta {
            font-size: 0.78rem;
            font-weight: 500;
            color: #64748b;
        }
        .perm-group-count {
            font-size: 0.72rem;
            font-weight: 600;
            color: #94a3b8;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            padding: 0.1rem 0.55rem;
        }
        .perm-page-details {
            border-top: 1px solid #e8edf3;
        }
        .perm-page-details:first-child { border-top: 0; }
        .perm-page-details > summary {
            list-style: none;
            cursor: pointer;
            padding: 0.7rem 1rem;
            font-weight: 600;
            font-size: 0.88rem;
            color: #1e293b;
            background: #fafbfc;
        }
        .perm-page-details > summary::-webkit-details-marker { display: none; }
        .perm-page-details > summary::before {
            content: '▸';
            display: inline-block;
            margin-right: 0.45rem;
            color: #94a3b8;
            transition: transform 0.15s ease;
        }
        .perm-page-details[open] > summary::before { transform: rotate(90deg); }
        .perm-page-details[open] > summary {
            background: #fff9f5;
            color: #9a3412;
        }
        .perm-row-access td:first-child .perm-name { color: #0f766e; }
        .perm-row-action td:first-child { padding-left: 1.35rem; }
        .perm-row-action td:first-child .perm-name {
            font-weight: 500;
            font-size: 0.86rem;
        }
        .perm-row-action td:first-child .perm-name::before {
            content: '↳ ';
            color: #94a3b8;
        }
        .perm-page-path {
            font-size: 0.72rem;
            color: #64748b;
            font-weight: 500;
        }
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

    <?php
    $rolePermFlash = tnc_flash_from_query($_GET);
    if ($saved && $rolePermFlash !== null) {
        $rolePermFlash['message'] = 'บันทึกสิทธิ์เรียบร้อยแล้ว';
    }
    tnc_render_flash($rolePermFlash);
    ?>
    <?php if ($configError === 'csrf'): ?>
        <div class="alert alert-warning rounded-3 border-0 shadow-sm">โทเค็นความปลอดภัยไม่ถูกต้อง — กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง</div>
    <?php elseif ($configError === 'save_failed'): ?>
        <div class="alert alert-danger rounded-3 border-0 shadow-sm">บันทึกไม่สำเร็จ — ตรวจสอบการเชื่อมต่อ Firebase หรือ deploy ไฟล์ <code>includes/role_permissions.php</code> ให้ครบ</div>
    <?php endif; ?>

    <div class="card perm-card">
        <div class="card-body p-4">
            <p class="text-muted small mb-3">
                <i class="bi bi-info-circle me-1"></i>
                กำหนด <strong>เข้าถึงหน้า</strong> และ <strong>สิ่งที่ทำได้บนหน้านั้น</strong> — เปิดหมวด → เปิดชื่อหน้า → ติ๊กสิทธิ์ (Accounting ที่เข้าบางหน้าไม่ได้ ให้เปิด «เข้าถึงหน้า» ที่ต้องการ)
            </p>
            <form method="post" action="">
                <?php csrf_field(); ?>
                <input type="hidden" name="save_role_permissions" value="1">

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" id="btnExpandAllGroups">
                        <i class="bi bi-arrows-expand me-1"></i>เปิดทุกหมวด
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" id="btnCollapseAllGroups">
                        <i class="bi bi-arrows-collapse me-1"></i>ปิดทุกหมวด
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-3" id="btnResetDefaults">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>คืนค่าเริ่มต้น
                    </button>
                </div>

                <div class="accordion perm-accordion" id="permGroupAccordion">
                    <?php $hubIndex = 0; ?>
                    <?php foreach ($menuTree as $hubKey => $hub): ?>
                        <?php
                        $hubLabel = (string) ($hub['label'] ?? $hubKey);
                        $hubPages = is_array($hub['pages'] ?? null) ? $hub['pages'] : [];
                        $collapseId = 'perm-hub-' . $hubIndex;
                        $hubIndex++;
                        $pageCount = count($hubPages);
                        ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="heading-<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>">
                                <button class="accordion-button collapsed"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>"
                                        aria-expanded="false"
                                        aria-controls="<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>">
                                    <span class="me-2"><?= htmlspecialchars($hubLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="perm-group-count"><?= (int) $pageCount ?> หน้า</span>
                                </button>
                            </h2>
                            <div id="<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>"
                                 class="accordion-collapse collapse perm-group-panel"
                                 aria-labelledby="heading-<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="accordion-body p-0">
                                    <?php foreach ($hubPages as $pageKey => $pageDef): ?>
                                        <?php
                                        $pageLabel = (string) ($pageDef['label'] ?? $pageKey);
                                        $pagePath = (string) ($pageDef['path'] ?? '');
                                        $pageActions = is_array($pageDef['actions'] ?? null) ? $pageDef['actions'] : [];
                                        $actionCount = count($pageActions);
                                        ?>
                                        <details class="perm-page-details">
                                            <summary>
                                                <?= htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8') ?>
                                                <span class="perm-group-count ms-2">1 เข้าหน้า<?= $actionCount > 0 ? ' · ' . $actionCount . ' การทำงาน' : '' ?></span>
                                            </summary>
                                            <div class="table-responsive">
                                                <table class="table table-bordered perm-table mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th style="min-width: 14rem;">สิทธิ์</th>
                                                            <?php foreach ($roles as $role): ?>
                                                                <th class="text-center role-col-<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>" style="min-width: 5.5rem;">
                                                                    <?= htmlspecialchars($roleLabels[$role] ?? $role, ENT_QUOTES, 'UTF-8') ?>
                                                                </th>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php
                                                        perm_render_matrix_row(
                                                            $pageKey,
                                                            'เข้าถึงหน้านี้',
                                                            $pagePath !== '' ? $pagePath : null,
                                                            'perm-row-access',
                                                            $roles,
                                                            $matrix,
                                                            $roleLabels
                                                        );
                                                        foreach ($pageActions as $actionKey) {
                                                            $actionDef = $actionDefinitions[$actionKey] ?? null;
                                                            if ($actionDef === null) {
                                                                continue;
                                                            }
                                                            perm_render_matrix_row(
                                                                $actionKey,
                                                                (string) ($actionDef['label'] ?? $actionKey),
                                                                isset($actionDef['hint']) ? (string) $actionDef['hint'] : null,
                                                                'perm-row-action',
                                                                $roles,
                                                                $matrix,
                                                                $roleLabels
                                                            );
                                                        }
                                                        ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </details>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var defaults = <?= json_encode(tnc_role_permission_defaults(), JSON_UNESCAPED_UNICODE) ?>;

    function getGroupPanels() {
        return Array.from(document.querySelectorAll('.perm-group-panel'));
    }

    function setGroupExpanded(panel, expand) {
        if (!panel || typeof bootstrap === 'undefined' || !bootstrap.Collapse) {
            return;
        }
        var instance = bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false });
        if (expand) {
            instance.show();
        } else {
            instance.hide();
        }
    }

    function setAllPageDetails(open) {
        document.querySelectorAll('.perm-page-details').forEach(function (el) {
            el.open = open;
        });
    }

    document.getElementById('btnExpandAllGroups')?.addEventListener('click', function () {
        getGroupPanels().forEach(function (panel) { setGroupExpanded(panel, true); });
        setAllPageDetails(true);
    });

    document.getElementById('btnCollapseAllGroups')?.addEventListener('click', function () {
        getGroupPanels().forEach(function (panel) { setGroupExpanded(panel, false); });
        setAllPageDetails(false);
    });

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
</body>
</html>
