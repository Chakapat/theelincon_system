<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_action_response.php';
require_once dirname(__DIR__, 2) . '/includes/site_cost_categories.php';

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

// ---- หมวดค่าใช้จ่าย: บันทึก/แก้ไข ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_site_category'])) {
    if (!csrf_verify_request()) {
        tnc_action_redirect(app_path('pages/organization/sites.php'));
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $catId = (int) ($_POST['category_id'] ?? 0);
    $catSiteId = (int) ($_POST['category_site_id'] ?? 0); // 0 = หมวดกลาง
    $catName = trim((string) ($_POST['category_name'] ?? ''));
    if ($catName === '') {
        tnc_action_redirect(app_path('pages/organization/sites.php') . '?error=invalid_name');
    }
    $savedId = tnc_site_category_save($catId, $catSiteId, $catName);
    if ($savedId > 0) {
        tnc_audit_log($catId > 0 ? 'update' : 'create', 'site_cost_category', (string) $savedId, $catName, [
            'source' => 'sites.php',
            'action' => 'save_site_category',
            'after' => ['id' => $savedId, 'site_id' => $catSiteId, 'name' => $catName],
        ]);
    }
    $anchor = $catSiteId > 0 ? ('#site-' . $catSiteId) : '#global-categories';
    tnc_action_redirect(app_path('pages/organization/sites.php') . '?cat_saved=1' . $anchor);
}

// ---- หมวดค่าใช้จ่าย: ลบ ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_site_category'])) {
    if (!csrf_verify_request()) {
        tnc_action_redirect(app_path('pages/organization/sites.php'));
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $catId = (int) ($_POST['category_id'] ?? 0);
    $catSiteId = (int) ($_POST['category_site_id'] ?? 0);
    if ($catId > 0) {
        $snap = Db::rowByIdField('site_cost_categories', $catId);
        tnc_site_category_delete($catId);
        tnc_audit_log('delete', 'site_cost_category', (string) $catId, (string) ($snap['name'] ?? ('#' . $catId)), [
            'source' => 'sites.php',
            'action' => 'delete_site_category',
            'before' => $snap,
        ]);
    }
    $anchor = $catSiteId > 0 ? ('#site-' . $catSiteId) : '#global-categories';
    tnc_action_redirect(app_path('pages/organization/sites.php') . '?cat_deleted=1' . $anchor);
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = $editId > 0 ? Db::rowByIdField('sites', $editId) : null;
$isEditing = is_array($editRow);

// หมวดค่าใช้จ่าย: แยกหมวดกลาง + จัดกลุ่มตามไซต์
$catGlobal = [];
$catBySite = [];
foreach (tnc_site_categories_all(true) as $cat) {
    $sid = (int) ($cat['site_id'] ?? 0);
    if ($sid === 0) {
        $catGlobal[] = $cat;
    } else {
        $catBySite[$sid][] = $cat;
    }
}

/**
 * เรนเดอร์รายการหมวด + ฟอร์มเพิ่มหมวด สำหรับไซต์หนึ่ง (siteId=0 = หมวดกลาง)
 *
 * @param array<int,array<string,mixed>> $cats
 */
$renderCategoryBlock = static function (int $siteId, array $cats): void {
    $selfUrl = htmlspecialchars(app_path('pages/organization/sites.php'), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="site-cat-list mb-2">
        <?php if (count($cats) === 0): ?>
            <div class="text-muted small fst-italic">ยังไม่มีหัวข้อย่อย</div>
        <?php else: ?>
            <?php foreach ($cats as $c): ?>
                <span class="site-cat-chip">
                    <i class="bi bi-tag-fill"></i>
                    <span><?= htmlspecialchars((string) ($c['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <form method="post" action="<?= $selfUrl ?>" class="d-inline" onsubmit="return confirm('ลบหัวข้อย่อยนี้?');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="delete_site_category" value="1">
                        <input type="hidden" name="category_id" value="<?= (int) ($c['id'] ?? 0) ?>">
                        <input type="hidden" name="category_site_id" value="<?= $siteId ?>">
                        <button type="submit" class="site-cat-del" title="ลบ" aria-label="ลบหัวข้อย่อย">&times;</button>
                    </form>
                </span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <form method="post" action="<?= $selfUrl ?>" class="d-flex gap-2 align-items-center flex-wrap">
        <?php csrf_field(); ?>
        <input type="hidden" name="save_site_category" value="1">
        <input type="hidden" name="category_site_id" value="<?= $siteId ?>">
        <input type="text" name="category_name" class="form-control form-control-sm site-cat-input" maxlength="150" placeholder="เพิ่มหัวข้อย่อย เช่น ค่าน้ำมันรถ" required style="max-width:280px;">
        <button type="submit" class="btn btn-sm btn-outline-warning fw-semibold"><i class="bi bi-plus-lg me-1"></i>เพิ่ม</button>
    </form>
    <?php
};

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
        .site-cat-panel {
            background: #fffaf3;
            border-top: 1px dashed #f0c896;
        }
        .site-cat-chip {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            background: #fff;
            border: 1px solid #f0c896;
            color: #92400e;
            border-radius: 999px;
            padding: .2rem .35rem .2rem .7rem;
            margin: 0 .35rem .35rem 0;
            font-size: .85rem;
            font-weight: 600;
        }
        .site-cat-chip .bi { color: #fd7e14; font-size: .8rem; }
        .site-cat-del {
            border: 0;
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
            border-radius: 999px;
            width: 20px;
            height: 20px;
            line-height: 1;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .site-cat-del:hover { background: rgba(239, 68, 68, 0.22); }
        .site-cat-input:focus {
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
    <?php elseif (isset($_GET['cat_saved'])): ?>
        <div class="alert alert-success">บันทึกหัวข้อย่อยเรียบร้อยแล้ว</div>
    <?php elseif (isset($_GET['cat_deleted'])): ?>
        <div class="alert alert-success">ลบหัวข้อย่อยเรียบร้อยแล้ว</div>
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
    <div class="card sites-add-card mb-4" id="global-categories">
        <div class="card-body p-4">
            <h6 class="sites-add-title mb-1"><i class="bi bi-tags me-2 text-warning"></i>หมวดค่าใช้จ่ายกลาง (ใช้ได้ทุกไซต์)</h6>
            <p class="text-muted small mb-3">หมวดเหล่านี้จะแสดงเป็นตัวเลือกในทุกไซต์ เช่น ค่าแรง ค่าน้ำมันรถ วัสดุ</p>
            <?php $renderCategoryBlock(0, $catGlobal); ?>
        </div>
    </div>

    <div class="card sites-table-card">
        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap px-4 pt-3 pb-2">
            <span class="fw-bold text-secondary"><i class="bi bi-geo-alt me-2 text-warning"></i>ไซต์งาน &amp; หัวข้อย่อย</span>
            <input type="search" id="siteSearchInput" class="form-control form-control-sm sites-input" placeholder="ค้นหาไซต์..." style="max-width:240px;min-height:38px;">
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" id="sitesTable" width="100%">
                <thead class="table-light"><tr><th class="ps-4">ชื่อ</th><th class="text-center" style="width:9rem;">หัวข้อย่อย</th><th class="pe-4 text-end" style="width:7rem;">จัดการ</th></tr></thead>
                <tbody>
                    <?php foreach ($list as $r): ?>
                    <?php $rid = (int) ($r['id'] ?? 0); $siteCats = $catBySite[$rid] ?? []; ?>
                    <tr id="site-<?= $rid ?>">
                        <td class="ps-4 site-name-cell"><?= htmlspecialchars((string) ($r['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-toggle="collapse" data-bs-target="#cat-collapse-<?= $rid ?>" aria-expanded="false">
                                <i class="bi bi-list-nested me-1"></i><span class="badge bg-warning text-dark"><?= count($siteCats) ?></span>
                            </button>
                        </td>
                        <td class="pe-4 text-end">
                            <a class="site-action-btn site-action-edit" href="?edit=<?= $rid ?>" aria-label="แก้ไขไซต์">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                            <a class="site-action-btn site-action-delete tnc-delete-post" href="<?= htmlspecialchars(app_path('pages/organization/sites.php'), ENT_QUOTES, 'UTF-8') ?>?delete_site=1&amp;site_id=<?= $rid ?>&amp;_csrf=<?= rawurlencode(csrf_token()) ?>" aria-label="ลบไซต์">
                                <i class="bi bi-trash3"></i>
                            </a>
                        </td>
                    </tr>
                    <tr class="site-cat-row">
                        <td colspan="3" class="p-0 border-0">
                            <div class="collapse" id="cat-collapse-<?= $rid ?>">
                                <div class="site-cat-panel p-3">
                                    <?php $renderCategoryBlock($rid, $siteCats); ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ค้นหาไซต์แบบง่าย (ไม่ใช้ DataTables เพราะมีแถวหัวข้อย่อยแบบขยาย)
(function () {
    var input = document.getElementById('siteSearchInput');
    if (!input) return;
    input.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        document.querySelectorAll('#sitesTable tbody tr[id^="site-"]').forEach(function (row) {
            var name = (row.querySelector('.site-name-cell')?.textContent || '').toLowerCase();
            var show = q === '' || name.indexOf(q) !== -1;
            row.style.display = show ? '' : 'none';
            var next = row.nextElementSibling;
            if (next && next.classList.contains('site-cat-row')) {
                next.style.display = show ? '' : 'none';
            }
        });
    });
})();
</script>
</body>
</html>
