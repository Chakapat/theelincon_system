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
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $n = trim((string) ($_POST['name'] ?? ''));
    if ($n !== '' && strlen($n) <= 200) {
        if ($id > 0) {
            $cur = Db::rowByIdField('sites', $id);
            if ($cur !== null) {
                Db::setRow('sites', Db::pkForLogicalId('sites', $id), array_merge($cur, ['name' => $n]));
            }
            tnc_action_redirect(app_path('pages/organization/sites.php') . '?updated=1');
        }
        $nid = Db::nextNumericId('sites', 'id');
        Db::setRow('sites', (string) $nid, [
            'id' => $nid,
            'name' => $n,
            'sort_order' => 0,
        ]);
        tnc_action_redirect(app_path('pages/organization/sites.php') . '?created=1');
    }
    tnc_action_redirect(app_path('pages/organization/sites.php') . '?error=invalid_name');
}

if (isset($_GET['delete'])) {
    if (!csrf_verify_request()) {
        tnc_action_redirect(app_path('pages/organization/sites.php'));
    }
    $id = (int) $_GET['delete'];
    if ($id > 0) {
        $inUse = Db::findFirst('cash_ledger', static function (array $row) use ($id): bool {
            return (int) ($row['site_id'] ?? 0) === $id;
        });
        if ($inUse !== null) {
            tnc_action_redirect(app_path('pages/organization/sites.php') . '?error=in_use');
        }
        Db::deleteRow('sites', Db::pkForLogicalId('sites', $id));
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
    <style>body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }</style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container pb-5">
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
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h4 class="fw-bold mb-0"><i class="bi bi-geo-alt me-2 text-warning"></i>ไซต์งาน / สถานที่ใช้</h4>
    </div>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3"><?= $isEditing ? 'แก้ไขไซต์' : 'เพิ่มไซต์ใหม่' ?></h6>
            <form method="post" class="row g-2 align-items-end" data-tnc-ajax="1" data-tnc-soft-reload="1" action="<?= htmlspecialchars(app_path('pages/organization/sites.php'), ENT_QUOTES, 'UTF-8') ?>">
                <?php csrf_field(); ?>
                <input type="hidden" name="save_site" value="1">
                <?php if ($isEditing): ?>
                    <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">
                <?php endif; ?>
                <div class="col-md-8">
                    <label class="form-label small">ชื่อไซต์ / โครงการ</label>
                    <input type="text" name="name" class="form-control rounded-3" maxlength="200" required placeholder="เช่น โครงการ ABC" value="<?= htmlspecialchars((string) ($editRow['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-4">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn text-white rounded-3 flex-grow-1" style="background-color:#fd7e14;"><?= $isEditing ? 'บันทึกการแก้ไข' : 'บันทึก' ?></button>
                        <?php if ($isEditing): ?>
                            <a href="<?= htmlspecialchars(app_path('pages/organization/sites.php')) ?>" class="btn btn-outline-secondary rounded-3">ยกเลิก</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" id="sitesTable" width="100%">
                <thead class="table-light"><tr><th class="ps-4">ชื่อ</th><th class="pe-4 text-end">จัดการ</th></tr></thead>
                <tbody>
                    <?php foreach ($list as $r): ?>
                    <tr>
                        <td class="ps-4"><?= htmlspecialchars((string) ($r['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="pe-4 text-end">
                            <a class="btn btn-sm btn-outline-primary rounded-3" href="?edit=<?= (int) ($r['id'] ?? 0) ?>">แก้ไข</a>
                            <a class="btn btn-sm btn-outline-danger rounded-3" href="?delete=<?= (int) ($r['id'] ?? 0) ?>&amp;_csrf=<?= rawurlencode(csrf_token()) ?>" onclick="return confirm('ยืนยันการลบไซต์นี้?');">ลบ</a>
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
    $('#sitesTable').DataTable({ order: [[0, 'asc']], pageLength: 25 });
})();
</script>
</body>
</html>
