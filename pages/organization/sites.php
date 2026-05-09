<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_action_response.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}
if (!user_is_finance_role()) {
    header('Location: ' . app_path('index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_site'])) {
    if (!csrf_verify_request()) {
        tnc_action_redirect(app_path('pages/organization/sites.php'));
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $n = trim((string) ($_POST['name'] ?? ''));
    if ($n !== '' && strlen($n) <= 200) {
        if ($id > 0) {
            $cur = Db::rowByIdField('sites', $id);
            if ($cur !== null) {
                Db::setRow('sites', Db::pkForLogicalId('sites', $id), array_merge($cur, ['name' => $n]));
                $afterS = Db::rowByIdField('sites', $id);
                tnc_audit_log('update', 'site', (string) $id, $n, [
                    'source' => 'sites.php',
                    'action' => 'save_site',
                    'before' => $cur,
                    'after' => $afterS,
                ]);
            }
            tnc_action_redirect(app_path('pages/organization/sites.php') . '?updated=1');
        }
        $nid = Db::nextNumericId('sites', 'id');
        Db::setRow('sites', (string) $nid, [
            'id' => $nid,
            'name' => $n,
            'sort_order' => 0,
        ]);
        $afterNewS = Db::row('sites', (string) $nid);
        tnc_audit_log('create', 'site', (string) $nid, $n, [
            'source' => 'sites.php',
            'action' => 'save_site',
            'after' => $afterNewS,
        ]);
        tnc_action_redirect(app_path('pages/organization/sites.php') . '?created=1');
    }
    tnc_action_redirect(app_path('pages/organization/sites.php') . '?error=invalid_name');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_site'])) {
    if (!csrf_verify_request()) {
        tnc_action_redirect(app_path('pages/organization/sites.php'));
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    tnc_require_post_confirm_password();
    $sid = (int) ($_POST['site_id'] ?? 0);
    if ($sid > 0) {
        $inUse = Db::findFirst('cash_ledger', static function (array $row) use ($sid): bool {
            return (int) ($row['site_id'] ?? 0) === $sid;
        });
        if ($inUse !== null) {
            tnc_action_redirect(app_path('pages/organization/sites.php') . '?error=in_use');
        }
        $snap = Db::rowByIdField('sites', $sid);
        $sname = $snap !== null ? trim((string) ($snap['name'] ?? '')) : '';
        Db::deleteRow('sites', Db::pkForLogicalId('sites', $sid));
        tnc_audit_log('delete', 'site', (string) $sid, $sname !== '' ? $sname : ('#' . $sid), [
            'source' => 'sites.php',
            'action' => 'delete_site',
            'before' => $snap,
        ]);
    }
    tnc_action_redirect(app_path('pages/organization/sites.php') . '?deleted=1');
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = $editId > 0 ? Db::rowByIdField('sites', $editId) : null;
$isEditing = is_array($editRow);

$list = Db::tableRows('sites');
usort($list, static function (array $a, array $b): int {
    $so = ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
    if ($so !== 0) {
        return $so;
    }

    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ไซต์งาน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background: #fffaf5;
            color: #1f2937;
        }
        .sites-page-wrap {
            padding-top: 1rem;
        }
        .sites-add-card,
        .sites-table-card {
            border: 1px solid rgba(148, 163, 184, 0.14);
            border-radius: 16px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
            background: #ffffff;
        }
        .sites-add-title {
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: .8rem;
        }
        .sites-input {
            min-height: 45px;
            border-radius: 10px;
            border-color: #d7dde6;
            padding: .58rem .88rem;
        }
        .sites-input:focus {
            border-color: #fd7e14;
            box-shadow: 0 0 0 .2rem rgba(253, 126, 20, 0.14);
        }
        .sites-save-btn {
            min-height: 45px;
            border-radius: 10px;
            border: 0;
            background: linear-gradient(135deg, #fd7e14, #f97316);
            color: #fff;
            font-weight: 700;
            padding: .58rem 1rem;
            box-shadow: 0 10px 22px rgba(249, 115, 22, 0.24);
        }
        .sites-save-btn:hover {
            filter: brightness(.97);
            transform: translateY(-1px);
        }
        .sites-save-btn:active {
            transform: translateY(0);
        }
        #sitesTable thead th {
            background: #f8fafc;
            color: #374151;
            font-weight: 700;
            border-bottom: 1px solid #e8edf3;
            white-space: nowrap;
        }
        #sitesTable tbody td {
            padding-top: .85rem;
            padding-bottom: .85rem;
            border-color: #eef2f7;
        }
        #sitesTable tbody tr:nth-child(even) {
            background: rgba(248, 250, 252, 0.7);
        }
        #sitesTable tbody tr:hover {
            background: rgba(255, 247, 237, 0.92) !important;
        }
        .site-name-cell {
            font-weight: 600;
            color: #111827;
        }
        .site-action-btn {
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: transform .15s ease, box-shadow .2s ease, filter .2s ease;
        }
        .site-action-btn:hover {
            transform: translateY(-1px);
            filter: saturate(1.05);
        }
        .site-action-edit {
            background: rgba(59, 130, 246, 0.14);
            color: #2563eb;
        }
        .site-action-delete {
            background: rgba(239, 68, 68, 0.14);
            color: #dc2626;
        }
        .sites-table-card .dataTables_wrapper .dataTables_length,
        .sites-table-card .dataTables_wrapper .dataTables_filter {
            margin-bottom: .95rem;
        }
        .sites-table-card .dataTables_wrapper .dataTables_filter input,
        .sites-table-card .dataTables_wrapper .dataTables_length select {
            border: 1px solid #d7dde6;
            border-radius: 10px;
            min-height: 38px;
            padding: .3rem .65rem;
            background: #fff;
        }
        .sites-table-card .dataTables_wrapper .dataTables_filter input:focus,
        .sites-table-card .dataTables_wrapper .dataTables_length select:focus {
            outline: none;
            border-color: #fd7e14;
            box-shadow: 0 0 0 .2rem rgba(253, 126, 20, 0.14);
        }
        @media (max-width: 767.98px) {
            .sites-page-wrap {
                padding-top: .75rem;
            }
            .sites-form-row > [class*='col-'] {
                width: 100%;
                flex: 0 0 100%;
            }
            .sites-form-actions {
                width: 100%;
            }
            .sites-save-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container pb-5 sites-page-wrap">
    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">เพิ่มไซต์เรียบร้อยแล้ว</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success">แก้ไขไซต์เรียบร้อยแล้ว</div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">ลบไซต์เรียบร้อยแล้ว</div>
    <?php elseif (isset($_GET['error']) && $_GET['error'] === 'in_use'): ?>
        <div class="alert alert-danger">ลบไม่ได้: ไซต์นี้ถูกใช้งานในรายการรายรับ/รายจ่ายแล้ว</div>
    <?php elseif (isset($_GET['error']) && $_GET['error'] === 'invalid_name'): ?>
        <div class="alert alert-warning">กรุณาระบุชื่อไซต์ให้ถูกต้อง</div>
    <?php elseif (isset($_GET['error']) && $_GET['error'] === 'confirm_password_required'): ?>
        <div class="alert alert-warning">กรุณากรอกรหัสผ่านของคุณเพื่อยืนยันการลบ</div>
    <?php elseif (isset($_GET['error']) && $_GET['error'] === 'confirm_password_invalid'): ?>
        <div class="alert alert-danger">รหัสผ่านไม่ถูกต้อง</div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h4 class="fw-bold mb-0"><i class="bi bi-geo-alt me-2 text-warning"></i>ไซต์งาน / สถานที่ทำงาน</h4>
    </div>
    <div class="card sites-add-card mb-4">
        <div class="card-body p-4">
            <h6 class="sites-add-title"><?= $isEditing ? 'แก้ไขไซต์' : 'เพิ่มไซต์ใหม่' ?></h6>
            <form method="post" class="row g-2 align-items-end sites-form-row" data-tnc-ajax="1" data-tnc-soft-reload="1" action="<?= htmlspecialchars(app_path('pages/organization/sites.php'), ENT_QUOTES, 'UTF-8') ?>">
                <?php csrf_field(); ?>
                <input type="hidden" name="save_site" value="1">
                <?php if ($isEditing): ?>
                    <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">
                <?php endif; ?>
                <div class="col-md-8">
                    <input type="text" name="name" class="form-control sites-input" maxlength="200" required value="<?= htmlspecialchars((string) ($editRow['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-4 sites-form-actions">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn sites-save-btn flex-grow-1"><?= $isEditing ? 'บันทึกการแก้ไข' : 'บันทึก' ?></button>
                        <?php if ($isEditing): ?>
                            <a href="<?= htmlspecialchars(app_path('pages/organization/sites.php')) ?>" class="btn btn-outline-secondary rounded-3 d-inline-flex align-items-center">ยกเลิก</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div class="card sites-table-card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" id="sitesTable" width="100%">
                <thead class="table-light"><tr><th class="ps-4">ชื่อ</th><th class="pe-4 text-end">จัดการ</th></tr></thead>
                <tbody>
                    <?php foreach ($list as $r): ?>
                    <tr>
                        <td class="ps-4 site-name-cell"><?= htmlspecialchars((string) ($r['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="pe-4 text-end">
                            <a class="site-action-btn site-action-edit" href="?edit=<?= (int) ($r['id'] ?? 0) ?>" aria-label="แก้ไขไซต์">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                            <a class="site-action-btn site-action-delete tnc-delete-post" href="<?= htmlspecialchars(app_path('pages/organization/sites.php'), ENT_QUOTES, 'UTF-8') ?>?delete_site=1&amp;site_id=<?= (int) ($r['id'] ?? 0) ?>&amp;_csrf=<?= rawurlencode(csrf_token()) ?>" aria-label="ลบไซต์">
                                <i class="bi bi-trash3"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
    if (typeof $ === 'undefined' || !$.fn.DataTable) return;
    var dt = $('#sitesTable').DataTable({
        order: [[0, 'asc']],
        pageLength: 25,
        dom: '<"row align-items-center g-2 mb-2"<"col-md-6 col-12"l><"col-md-6 col-12 text-md-end"f>>rt<"row align-items-center g-2 mt-3"<"col-md-5 col-12"i><"col-md-7 col-12 text-md-end"p>>'
    });
    if (dt && dt.columns) {
        dt.columns.adjust();
    }
})();
</script>
</body>
</html>
