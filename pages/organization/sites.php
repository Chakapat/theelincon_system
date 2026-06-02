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
            if (tnc_ajax_form_requested()) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'ok' => true,
                    'message' => 'แก้ไขไซต์แล้ว',
                    'action' => 'site_saved',
                    'mode' => 'update',
                    'site' => ['id' => $id, 'name' => $n],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
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
        if (tnc_ajax_form_requested()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok' => true,
                'message' => 'เพิ่มไซต์แล้ว',
                'action' => 'site_saved',
                'mode' => 'create',
                'site' => ['id' => $nid, 'name' => $n],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        tnc_action_redirect(app_path('pages/organization/sites.php') . '?created=1');
    }
    if (tnc_ajax_form_requested()) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'message' => 'กรุณาระบุชื่อไซต์ให้ถูกต้อง',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
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
        if (tnc_ajax_form_requested()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'message' => 'หมดอายุการเชื่อมต่อ กรุณารีเฟรชหน้าแล้วลองใหม่',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        tnc_action_redirect(app_path('pages/organization/sites.php'));
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $catId = (int) ($_POST['category_id'] ?? 0);
    $catSiteId = (int) ($_POST['category_site_id'] ?? 0);
    $catName = trim((string) ($_POST['category_name'] ?? ''));
    if ($catSiteId <= 0) {
        if (tnc_ajax_form_requested()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'message' => 'กรุณาเลือกไซต์งาน',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        tnc_action_redirect(app_path('pages/organization/sites.php') . '?error=invalid_name');
    }
    if ($catName === '') {
        if (tnc_ajax_form_requested()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'message' => 'กรุณาระบุชื่อหัวข้อย่อย',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        tnc_action_redirect(app_path('pages/organization/sites.php') . '?error=invalid_name');
    }
    $savedId = tnc_site_category_save($catId, $catSiteId, $catName);
    if ($savedId > 0) {
        tnc_audit_log($catId > 0 ? 'update' : 'create', 'site_cost_category', (string) $savedId, $catName, [
            'source' => 'sites.php',
            'action' => 'save_site_category',
            'after' => ['id' => $savedId, 'site_id' => $catSiteId, 'name' => $catName],
        ]);
        if (tnc_ajax_form_requested()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok' => true,
                'message' => 'บันทึกหัวข้อย่อยแล้ว',
                'action' => 'site_category_saved',
                'category' => [
                    'id' => $savedId,
                    'name' => $catName,
                    'site_id' => $catSiteId,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    } elseif (tnc_ajax_form_requested()) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'message' => 'บันทึกหัวข้อย่อยไม่สำเร็จ',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $anchor = $catSiteId > 0 ? ('#site-' . $catSiteId) : '';
    tnc_action_redirect(app_path('pages/organization/sites.php') . '?cat_saved=1' . $anchor);
}

// ---- หมวดค่าใช้จ่าย: ลบ ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_site_category'])) {
    if (!csrf_verify_request()) {
        if (tnc_ajax_form_requested()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'message' => 'หมดอายุการเชื่อมต่อ กรุณารีเฟรชหน้าแล้วลองใหม่',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        tnc_action_redirect(app_path('pages/organization/sites.php'));
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $catId = (int) ($_POST['category_id'] ?? 0);
    $catSiteId = (int) ($_POST['category_site_id'] ?? 0);
    if ($catId <= 0) {
        if (tnc_ajax_form_requested()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'message' => 'ไม่พบหัวข้อย่อยที่ต้องการลบ',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        tnc_action_redirect(app_path('pages/organization/sites.php') . '?error=invalid_name');
    }
    $snap = Db::rowByIdField('site_cost_categories', $catId);
    tnc_site_category_delete($catId);
    tnc_audit_log('delete', 'site_cost_category', (string) $catId, (string) ($snap['name'] ?? ('#' . $catId)), [
        'source' => 'sites.php',
        'action' => 'delete_site_category',
        'before' => $snap,
    ]);
    if (tnc_ajax_form_requested()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => true,
            'message' => 'ลบหัวข้อย่อยแล้ว',
            'action' => 'site_category_deleted',
            'category' => [
                'id' => $catId,
                'site_id' => $catSiteId > 0 ? $catSiteId : (int) ($snap['site_id'] ?? 0),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $anchor = $catSiteId > 0 ? ('#site-' . $catSiteId) : '';
    tnc_action_redirect(app_path('pages/organization/sites.php') . '?cat_deleted=1' . $anchor);
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = $editId > 0 ? Db::rowByIdField('sites', $editId) : null;
$isEditing = is_array($editRow);

// หมวดค่าใช้จ่าย: จัดกลุ่มตามไซต์
$catBySite = [];
foreach (tnc_site_categories_all(true) as $cat) {
    $sid = (int) ($cat['site_id'] ?? 0);
    if ($sid > 0) {
        $catBySite[$sid][] = $cat;
    }
}

/**
 * เรนเดอร์รายการหมวด + ฟอร์มเพิ่มหมวด สำหรับไซต์หนึ่ง
 *
 * @param array<int,array<string,mixed>> $cats
 */
$renderCategoryBlock = static function (int $siteId, array $cats): void {
    $selfUrl = htmlspecialchars(app_path('pages/organization/sites.php'), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="site-cat-block" data-site-cat-block="<?= $siteId ?>">
    <div class="site-cat-list mb-2">
        <?php if (count($cats) === 0): ?>
            <div class="text-muted small fst-italic js-site-cat-empty">ยังไม่มีหัวข้อย่อย</div>
        <?php else: ?>
            <?php foreach ($cats as $c): ?>
                <span class="site-cat-chip" data-cat-id="<?= (int) ($c['id'] ?? 0) ?>">
                    <i class="bi bi-tag-fill"></i>
                    <span><?= htmlspecialchars((string) ($c['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <form method="post" action="<?= $selfUrl ?>" class="d-inline js-site-cat-delete-form">
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
    <form method="post" action="<?= $selfUrl ?>" class="d-flex gap-2 align-items-center flex-wrap js-site-cat-form" data-sites-inline-cat="1">
        <?php csrf_field(); ?>
        <input type="hidden" name="save_site_category" value="1">
        <input type="hidden" name="category_site_id" value="<?= $siteId ?>">
        <input type="text" name="category_name" class="form-control form-control-sm site-cat-input js-site-cat-name" maxlength="150" required style="max-width:280px;" autocomplete="off">
        <button type="submit" class="btn btn-sm btn-outline-warning fw-semibold js-site-cat-submit"><i class="bi bi-plus-lg me-1"></i>เพิ่ม</button>
    </form>
    </div>
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
            border-color: #ea580c;
            box-shadow: 0 0 0 .2rem rgba(253, 126, 20, 0.14);
        }
        .sites-save-btn {
            min-height: 45px;
            border-radius: 10px;
            border: 0;
            background: linear-gradient(135deg, #ea580c, #f97316);
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
            border-color: #ea580c;
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
        .site-cat-chip .bi { color: #ea580c; font-size: .8rem; }
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
            border-color: #ea580c;
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
<body class="tnc-app-body">
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

    <div class="tnc-page-head mb-4">
        <div>
            <p class="tnc-page-kicker">Organization</p>
            <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-geo-alt"></i></span>ไซต์งาน / สถานที่ทำงาน</h1>
        </div>
    </div>
    <div class="card sites-add-card mb-4">
        <div class="card-body p-4">
            <h6 class="sites-add-title js-site-form-title"><?= $isEditing ? 'แก้ไขไซต์' : 'เพิ่มไซต์ใหม่' ?></h6>
            <form method="post" class="row g-2 align-items-end sites-form-row js-site-form" data-tnc-ajax="1" data-tnc-soft-reload="0" action="<?= htmlspecialchars(app_path('pages/organization/sites.php'), ENT_QUOTES, 'UTF-8') ?>">
                <?php csrf_field(); ?>
                <input type="hidden" name="save_site" value="1">
                <?php if ($isEditing): ?>
                    <input type="hidden" name="id" class="js-site-id" value="<?= (int) ($editRow['id'] ?? 0) ?>">
                <?php endif; ?>
                <div class="col-md-8">
                    <input type="text" name="name" class="form-control sites-input js-site-name" maxlength="200" required value="<?= htmlspecialchars((string) ($editRow['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-4 sites-form-actions">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn sites-save-btn flex-grow-1 js-site-submit"><?= $isEditing ? 'บันทึกการแก้ไข' : 'บันทึก' ?></button>
                        <?php if ($isEditing): ?>
                            <a href="<?= htmlspecialchars(app_path('pages/organization/sites.php')) ?>" class="btn btn-outline-secondary rounded-3 d-inline-flex align-items-center js-site-cancel">ยกเลิก</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card sites-table-card">
        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap px-4 pt-3 pb-2">
            <span class="fw-bold text-secondary"><i class="bi bi-geo-alt me-2 text-warning"></i>ไซต์งาน &amp; หัวข้อย่อย</span>
            <input type="search" id="siteSearchInput" class="form-control form-control-sm sites-input" placeholder="ค้นหาไซต์..." style="max-width:240px;min-height:38px;">
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" id="sitesTable" width="100%">
                <thead class="table-light"><tr><th class="ps-4">รายการสถานที่ทำงาน</th><th class="text-center" style="width:9rem;">จำนวนหัวข้อย่อย</th><th class="pe-4 text-end" style="width:7rem;">การจัดการ</th></tr></thead>
                <tbody id="sitesTableBody">
                    <?php foreach ($list as $r): ?>
                    <?php $rid = (int) ($r['id'] ?? 0); $siteCats = $catBySite[$rid] ?? []; ?>
                    <tr id="site-<?= $rid ?>">
                        <td class="ps-4 site-name-cell"><?= htmlspecialchars((string) ($r['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-toggle="collapse" data-bs-target="#cat-collapse-<?= $rid ?>" aria-expanded="false">
                                <i class="bi bi-list-nested me-1"></i><span class="badge bg-warning text-dark js-site-cat-count" data-site-id="<?= $rid ?>"><?= count($siteCats) ?></span>
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

// บันทึกไซต์/หมวดแบบ AJAX — แสดงผลทันที ไม่รีเฟรชหน้า กรอกต่อได้
(function () {
    var selfUrl = <?= json_encode(app_path('pages/organization/sites.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function escHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function csrfFromForm(form) {
        var el = form && form.querySelector('input[name="_csrf"]');
        return el ? el.value : '';
    }

    function rescanAjaxForms() {
        if (window.TncAjaxForm && typeof window.TncAjaxForm.rescan === 'function') {
            window.TncAjaxForm.rescan();
        }
    }

    function buildCategoryBlockHtml(siteId, csrfToken) {
        return ''
            + '<div class="site-cat-block" data-site-cat-block="' + siteId + '">'
            + '<div class="site-cat-list mb-2">'
            + '<div class="text-muted small fst-italic js-site-cat-empty">ยังไม่มีหัวข้อย่อย</div>'
            + '</div>'
            + '<form method="post" action="' + escHtml(selfUrl) + '" class="d-flex gap-2 align-items-center flex-wrap js-site-cat-form" data-sites-inline-cat="1">'
            + '<input type="hidden" name="_csrf" value="' + escHtml(csrfToken) + '">'
            + '<input type="hidden" name="save_site_category" value="1">'
            + '<input type="hidden" name="category_site_id" value="' + siteId + '">'
            + '<input type="text" name="category_name" class="form-control form-control-sm site-cat-input js-site-cat-name" maxlength="150" placeholder="เพิ่มหัวข้อย่อย เช่น ค่าน้ำมันรถ" required style="max-width:280px;" autocomplete="off">'
            + '<button type="submit" class="btn btn-sm btn-outline-warning fw-semibold js-site-cat-submit"><i class="bi bi-plus-lg me-1"></i>เพิ่ม</button>'
            + '</form>'
            + '</div>';
    }

    function buildSiteRows(site, csrfToken) {
        var rid = parseInt(site.id, 10) || 0;
        var name = escHtml(site.name);
        var deleteUrl = selfUrl + '?delete_site=1&site_id=' + rid + '&_csrf=' + encodeURIComponent(csrfToken);
        var editUrl = selfUrl + '?edit=' + rid;

        var mainRow = document.createElement('tr');
        mainRow.id = 'site-' + rid;
        mainRow.innerHTML =
            '<td class="ps-4 site-name-cell">' + name + '</td>'
            + '<td class="text-center">'
            + '<button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-toggle="collapse" data-bs-target="#cat-collapse-' + rid + '" aria-expanded="false">'
            + '<i class="bi bi-list-nested me-1"></i><span class="badge bg-warning text-dark js-site-cat-count" data-site-id="' + rid + '">0</span>'
            + '</button>'
            + '</td>'
            + '<td class="pe-4 text-end">'
            + '<a class="site-action-btn site-action-edit" href="' + escHtml(editUrl) + '" aria-label="แก้ไขไซต์"><i class="bi bi-pencil-square"></i></a>'
            + '<a class="site-action-btn site-action-delete tnc-delete-post" href="' + escHtml(deleteUrl) + '" aria-label="ลบไซต์"><i class="bi bi-trash3"></i></a>'
            + '</td>';

        var catRow = document.createElement('tr');
        catRow.className = 'site-cat-row';
        catRow.innerHTML =
            '<td colspan="3" class="p-0 border-0">'
            + '<div class="collapse" id="cat-collapse-' + rid + '">'
            + '<div class="site-cat-panel p-3">' + buildCategoryBlockHtml(rid, csrfToken) + '</div>'
            + '</div>'
            + '</td>';

        return [mainRow, catRow];
    }

    function resetSiteFormToCreate() {
        var form = document.querySelector('.js-site-form');
        if (!form) return;
        var idInput = form.querySelector('.js-site-id');
        if (idInput) idInput.remove();
        var nameInput = form.querySelector('.js-site-name');
        if (nameInput) {
            nameInput.value = '';
            nameInput.focus();
        }
        var title = document.querySelector('.js-site-form-title');
        if (title) title.textContent = 'เพิ่มไซต์ใหม่';
        var submitBtn = form.querySelector('.js-site-submit');
        if (submitBtn) submitBtn.textContent = 'บันทึก';
        var cancel = form.querySelector('.js-site-cancel');
        if (cancel) cancel.remove();
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', selfUrl);
        }
    }

    function buildCategoryChip(cat, csrfToken) {
        var chip = document.createElement('span');
        chip.className = 'site-cat-chip';
        chip.setAttribute('data-cat-id', String(cat.id));
        chip.innerHTML =
            '<i class="bi bi-tag-fill"></i>'
            + '<span>' + escHtml(cat.name) + '</span>'
            + '<form method="post" action="' + escHtml(selfUrl) + '" class="d-inline js-site-cat-delete-form">'
            + '<input type="hidden" name="_csrf" value="' + escHtml(csrfToken) + '">'
            + '<input type="hidden" name="delete_site_category" value="1">'
            + '<input type="hidden" name="category_id" value="' + escHtml(String(cat.id)) + '">'
            + '<input type="hidden" name="category_site_id" value="' + escHtml(String(cat.site_id)) + '">'
            + '<button type="submit" class="site-cat-del" title="ลบ" aria-label="ลบหัวข้อย่อย">&times;</button>'
            + '</form>';
        return chip;
    }

    function bumpSiteCategoryCount(siteId, delta) {
        if (siteId <= 0) return;
        var badge = document.querySelector('.js-site-cat-count[data-site-id="' + siteId + '"]');
        if (!badge) return;
        var n = parseInt(badge.textContent, 10);
        var next = Number.isFinite(n) ? n + delta : Math.max(0, delta);
        badge.textContent = String(Math.max(0, next));
    }

    function applyCategorySaved(cat, form) {
        var siteId = parseInt(cat.site_id, 10) || 0;
        var block = form ? form.closest('[data-site-cat-block]') : document.querySelector('[data-site-cat-block="' + siteId + '"]');
        if (!block) return;

        var list = block.querySelector('.site-cat-list');
        var empty = list && list.querySelector('.js-site-cat-empty');
        if (empty) empty.remove();

        var csrf = csrfFromForm(form || block.querySelector('.js-site-cat-form'));
        if (list) {
            list.appendChild(buildCategoryChip(cat, csrf));
        }

        var catForm = form || block.querySelector('.js-site-cat-form');
        if (catForm) {
            var nameInput = catForm.querySelector('.js-site-cat-name');
            if (nameInput) {
                nameInput.value = '';
                nameInput.focus();
            }
        }

        bumpSiteCategoryCount(siteId, 1);
    }

    function applyCategoryDeleted(cat, chipEl) {
        var siteId = parseInt(cat.site_id, 10) || 0;
        var block = chipEl ? chipEl.closest('[data-site-cat-block]') : document.querySelector('[data-site-cat-block="' + siteId + '"]');
        if (chipEl) chipEl.remove();

        var list = block && block.querySelector('.site-cat-list');
        if (list && list.querySelectorAll('.site-cat-chip').length === 0 && !list.querySelector('.js-site-cat-empty')) {
            var empty = document.createElement('div');
            empty.className = 'text-muted small fst-italic js-site-cat-empty';
            empty.textContent = 'ยังไม่มีหัวข้อย่อย';
            list.appendChild(empty);
        }

        bumpSiteCategoryCount(siteId, -1);
    }

    function postCategoryForm(form, onSuccess) {
        var submitBtn = form.querySelector('[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        var fd = new FormData(form);
        fd.set('_tnc_ajax', '1');

        return fetch(form.getAttribute('action') || selfUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Tnc-Ajax': '1',
                Accept: 'application/json'
            }
        })
            .then(function (r) {
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') === -1) {
                    throw new Error('invalid_response');
                }
                return r.json().then(function (j) {
                    return { ok: r.ok, json: j };
                });
            })
            .then(function (res) {
                var j = res.json || {};
                if (!j.ok) {
                    showInlineToast(false, j.message || 'ดำเนินการไม่สำเร็จ');
                    return;
                }
                if (typeof onSuccess === 'function') {
                    onSuccess(j);
                }
            })
            .catch(function () {
                showInlineToast(false, 'เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่');
            })
            .finally(function () {
                if (submitBtn) submitBtn.disabled = false;
            });
    }

    function showInlineToast(ok, message) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: ok ? 'success' : 'error',
                title: message,
                showConfirmButton: false,
                timer: ok ? 1800 : 3500
            });
            return;
        }
        if (!ok) window.alert(message);
    }

    function ensureDeleteSwalStyles() {
        if (document.getElementById('tnc-delete-swal-style')) return;
        var style = document.createElement('style');
        style.id = 'tnc-delete-swal-style';
        style.textContent = ''
            + '.swal2-container.tnc-delete-overlay{background:rgba(15,23,42,.38)!important;backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);}'
            + '.swal2-popup.tnc-delete-popup{width:min(92vw,430px)!important;border-radius:12px!important;border:1px solid rgba(255,255,255,.42)!important;background:rgba(255,255,255,.9)!important;box-shadow:0 1rem 2.2rem rgba(0,0,0,.24)!important;padding:1.25rem 1.2rem 1.05rem!important;}'
            + '.swal2-popup.tnc-delete-popup .swal2-title{font-size:1.08rem!important;font-weight:800!important;letter-spacing:.01em;color:#991b1b!important;}'
            + '.swal2-popup.tnc-delete-popup .swal2-html-container{line-height:1.65!important;font-size:.94rem!important;color:#475569!important;margin-top:.22rem!important;}'
            + '.swal2-popup.tnc-delete-popup .swal2-actions{width:100%;gap:.45rem;margin-top:.95rem!important;}'
            + '.swal2-popup.tnc-delete-popup .swal2-confirm,.swal2-popup.tnc-delete-popup .swal2-cancel{min-height:44px!important;border-radius:12px!important;font-weight:700!important;padding:.62rem 1.08rem!important;}'
            + '.swal2-popup.tnc-delete-popup .swal2-confirm{background:#dc3545!important;box-shadow:0 .45rem .95rem rgba(220,53,69,.26)!important;}'
            + '.swal2-popup.tnc-delete-popup .swal2-cancel{background:rgba(255,255,255,.72)!important;color:#475569!important;border:1px solid rgba(100,116,139,.28)!important;}'
            + '.tnc-delete-alert-icon{width:68px;height:68px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;margin:0 auto .35rem;background:rgba(220,53,69,.12);border:1px solid rgba(220,53,69,.3);color:#dc3545;font-size:1.95rem;animation:tncDeletePulse 1.2s ease-in-out infinite;}'
            + '@keyframes tncDeletePulse{0%,100%{transform:scale(1);opacity:1;}50%{transform:scale(1.08);opacity:.9;}}';
        document.head.appendChild(style);
    }

    function confirmCategoryDelete(label) {
        if (typeof Swal === 'undefined') {
            return Promise.resolve(window.confirm('ลบหัวข้อย่อย "' + label + '"?'));
        }
        ensureDeleteSwalStyles();
        var safeLabel = escHtml(label || 'หัวข้อย่อยนี้');
        return Swal.fire({
            title: 'ยืนยันการลบ',
            html: '<div class="tnc-delete-alert-icon" aria-hidden="true"><i class="bi bi-exclamation-lg"></i></div>'
                + '<div>ต้องการลบ <strong>' + safeLabel + '</strong> ออกจากรายการนี้หรือไม่</div>',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-trash3 me-1"></i>ลบ',
            cancelButtonText: 'ยกเลิก',
            reverseButtons: true,
            focusCancel: true,
            showClass: { popup: 'swal2-show' },
            hideClass: { popup: 'swal2-hide' },
            customClass: { container: 'tnc-delete-overlay', popup: 'tnc-delete-popup' }
        }).then(function (res) {
            return !!res.isConfirmed;
        });
    }

    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form || !form.classList) return;

        if (form.classList.contains('js-site-cat-delete-form')) {
            ev.preventDefault();
            var chipEl = form.closest('.site-cat-chip');
            var labelEl = chipEl ? chipEl.querySelector(':scope > span') : null;
            var label = labelEl ? labelEl.textContent.trim() : '';

            confirmCategoryDelete(label).then(function (confirmed) {
                if (!confirmed) return;
                postCategoryForm(form, function (j) {
                    if (!j.category) return;
                    applyCategoryDeleted(j.category, chipEl);
                    showInlineToast(true, j.message || 'ลบหัวข้อย่อยแล้ว');
                });
            });
            return;
        }

        if (!form.classList.contains('js-site-cat-form')) return;
        ev.preventDefault();

        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        postCategoryForm(form, function (j) {
            if (!j.category) {
                showInlineToast(false, j.message || 'บันทึกหัวข้อย่อยไม่สำเร็จ');
                return;
            }
            applyCategorySaved(j.category, form);
            showInlineToast(true, j.message || 'บันทึกหัวข้อย่อยแล้ว');
        });
    });

    document.addEventListener('tnc:form-ajax-success', function (ev) {
        var d = ev.detail || {};

        if (d.action === 'site_saved' && d.site) {
            var site = d.site;
            var rid = parseInt(site.id, 10) || 0;
            var form = document.querySelector('.js-site-form');
            var csrfToken = csrfFromForm(form);

            if (d.mode === 'create' && rid > 0) {
                var tbody = document.getElementById('sitesTableBody');
                if (tbody) {
                    buildSiteRows(site, csrfToken).forEach(function (row) {
                        tbody.appendChild(row);
                    });
                    rescanAjaxForms();
                }
                resetSiteFormToCreate();
            } else if (d.mode === 'update' && rid > 0) {
                var row = document.getElementById('site-' + rid);
                var cell = row && row.querySelector('.site-name-cell');
                if (cell) cell.textContent = site.name || '';
                resetSiteFormToCreate();
            }
            return;
        }

        if (d.action === 'site_category_saved' && d.category) {
            applyCategorySaved(d.category, null);
        }
    });
})();
</script>
</body>
</html>
