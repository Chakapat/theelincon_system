<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/site_budget.php';
require_once dirname(__DIR__, 2) . '/includes/site_cost_categories.php';
require_once dirname(__DIR__, 2) . '/includes/sites.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_flash.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_can('page.site.hub')) {
    header('Location: ' . app_path('index.php') . '?error=forbidden');
    exit;
}

$siteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;
if ($siteId <= 0) {
    header('Location: ' . app_path('pages/sites/site-picker.php'));
    exit;
}

$site = Db::rowByIdField('sites', $siteId);
if ($site === null) {
    header('Location: ' . app_path('pages/sites/site-picker.php') . '?error=not_found');
    exit;
}

$hubUrl = app_path('pages/sites/site-hub.php?site_id=' . $siteId);
$pickerUrl = app_path('pages/sites/site-picker.php');
$siteName = trim((string) ($site['name'] ?? ''));
$canEditBudget = user_can('site.manage');
$canDeleteSite = user_is_admin_only_role();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_site_budget'])) {
    if (!$canEditBudget) {
        header('Location: ' . app_path('index.php') . '?error=forbidden');
        exit;
    }
    if (!csrf_verify_request()) {
        header('Location: ' . $hubUrl . '&error=csrf');
        exit;
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $postSiteId = (int) ($_POST['site_id'] ?? 0);
    if ($postSiteId !== $siteId) {
        header('Location: ' . $hubUrl . '&error=invalid');
        exit;
    }
    $budgetRaw = trim(str_replace([',', ' '], '', (string) ($_POST['site_budget'] ?? '0')));
    $siteBudget = max(0.0, round((float) $budgetRaw, 2));
    $cur = Db::rowByIdField('sites', $siteId);
    if ($cur !== null) {
        $pk = Db::pkForLogicalId('sites', $siteId);
        if ($pk !== null) {
            Db::setRow('sites', $pk, array_merge($cur, [
                'site_budget' => $siteBudget,
            ]));
            $after = Db::rowByIdField('sites', $siteId);
            tnc_audit_log('update', 'site', (string) $siteId, trim((string) ($cur['name'] ?? '')), [
                'source' => 'site-hub.php',
                'action' => 'save_site_budget',
                'before' => $cur,
                'after' => $after,
            ]);
        }
    }
    header('Location: ' . $hubUrl . '&updated=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_site_name'])) {
    if (!$canEditBudget) {
        header('Location: ' . app_path('index.php') . '?error=forbidden');
        exit;
    }
    if (!csrf_verify_request()) {
        header('Location: ' . $hubUrl . '&error=csrf&open_rename=1');
        exit;
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $postSiteId = (int) ($_POST['site_id'] ?? 0);
    if ($postSiteId !== $siteId) {
        header('Location: ' . $hubUrl . '&error=invalid&open_rename=1');
        exit;
    }
    $newName = trim((string) ($_POST['site_name'] ?? ''));
    $beforeSite = Db::rowByIdField('sites', $siteId);
    $saveResult = tnc_site_save_name($siteId, $newName);
    if (empty($saveResult['ok'])) {
        $code = (string) ($saveResult['error_code'] ?? 'invalid_name');
        header('Location: ' . $hubUrl . '&error=' . rawurlencode($code) . '&open_rename=1');
        exit;
    }
    $afterSite = Db::rowByIdField('sites', $siteId);
    tnc_audit_log('update', 'site', (string) $siteId, $newName, [
        'source' => 'site-hub.php',
        'action' => 'save_site_name',
        'before' => $beforeSite,
        'after' => $afterSite,
    ]);
    header('Location: ' . $hubUrl . '&name_updated=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_site_category'])) {
    if (!$canEditBudget) {
        header('Location: ' . app_path('index.php') . '?error=forbidden');
        exit;
    }
    if (!csrf_verify_request()) {
        header('Location: ' . $hubUrl . '&error=csrf&open_cat=1');
        exit;
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $postSiteId = (int) ($_POST['category_site_id'] ?? 0);
    if ($postSiteId !== $siteId) {
        header('Location: ' . $hubUrl . '&error=invalid&open_cat=1');
        exit;
    }
    $catId = (int) ($_POST['category_id'] ?? 0);
    $catName = trim((string) ($_POST['category_name'] ?? ''));
    $pctRaw = trim(str_replace('%', '', (string) ($_POST['category_budget_percent'] ?? '')));
    $catBudgetPercent = null;
    if ($pctRaw !== '') {
        $catBudgetPercent = round((float) str_replace([',', ' '], '', $pctRaw), 2);
        $catBudgetPercent = max(0.0, min(100.0, $catBudgetPercent));
    }
    if ($catName === '') {
        $catOpen = $catId > 0 ? '&open_cat=1&edit_cat=' . $catId : '&open_cat=1';
        header('Location: ' . $hubUrl . '&error=invalid_name' . $catOpen);
        exit;
    }
    $savedId = tnc_site_category_save($catId, $siteId, $catName, 0, $catBudgetPercent);
    if (is_array($savedId) && isset($savedId['error']) && $savedId['error'] === 'percent_sum_exceeded') {
        $catOpen = $catId > 0 ? '&open_cat=1&edit_cat=' . $catId : '&open_cat=1';
        header('Location: ' . $hubUrl . '&error=percent_sum' . $catOpen);
        exit;
    }
    if (is_int($savedId) && $savedId > 0) {
        tnc_audit_log($catId > 0 ? 'update' : 'create', 'site_cost_category', (string) $savedId, $catName, [
            'source' => 'site-hub.php',
            'action' => 'save_site_category',
            'after' => [
                'id' => $savedId,
                'site_id' => $siteId,
                'name' => $catName,
                'budget_percent' => $catBudgetPercent,
            ],
        ]);
        header('Location: ' . $hubUrl . '&cat_saved=1');
        exit;
    }
    header('Location: ' . $hubUrl . '&error=invalid&open_cat=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_site'])) {
    if (!$canDeleteSite) {
        header('Location: ' . app_path('index.php') . '?error=forbidden');
        exit;
    }
    if (!csrf_verify_request()) {
        header('Location: ' . $hubUrl . '&error=csrf&open_delete=1');
        exit;
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $postSiteId = (int) ($_POST['site_id'] ?? 0);
    if ($postSiteId !== $siteId) {
        header('Location: ' . $hubUrl . '&error=invalid&open_delete=1');
        exit;
    }
    $confirmName = trim((string) ($_POST['confirm_site_name'] ?? ''));
    if ($confirmName !== $siteName) {
        header('Location: ' . $hubUrl . '&error=confirm_mismatch&open_delete=1');
        exit;
    }
    $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));
    if ($confirmPassword === '') {
        header('Location: ' . $hubUrl . '&error=confirm_password_required&open_delete=1');
        exit;
    }
    $user = null;
    $uid = (string) ($_SESSION['user_id'] ?? '');
    if ($uid !== '') {
        $user = Db::row('users', $uid);
        if ($user === null) {
            $user = Db::rowByIdField('users', $uid, 'userid');
        }
        if ($user === null) {
            $user = Db::rowByIdField('users', $uid, 'id');
        }
    }
    if (!tnc_verify_user_password_row($user, $confirmPassword)) {
        header('Location: ' . $hubUrl . '&error=confirm_password_invalid&open_delete=1');
        exit;
    }
    $beforeSite = Db::rowByIdField('sites', $siteId);
    $deleteResult = tnc_site_delete($siteId);
    if (empty($deleteResult['ok'])) {
        $code = (string) ($deleteResult['error_code'] ?? 'site_delete_failed');
        header('Location: ' . $hubUrl . '&error=' . rawurlencode($code) . '&open_delete=1');
        exit;
    }
    tnc_audit_log('delete', 'site', (string) $siteId, $siteName, [
        'source' => 'site-hub.php',
        'action' => 'delete_site',
        'before' => $beforeSite,
        'nested' => $deleteResult['nested'] ?? [],
    ]);
    header('Location: ' . $pickerUrl . '?deleted=1');
    exit;
}

$siteBudgetRaw = round((float) ($site['site_budget'] ?? 0), 2);
$summary = tnc_site_budget_site_summary($siteId);
$catPercentUsed = tnc_site_category_percent_sum($siteId);
$catPercentRoom = round(max(0.0, 100.0 - $catPercentUsed), 2);
$openCatEditId = isset($_GET['edit_cat']) ? (int) $_GET['edit_cat'] : 0;
$openCatModal = !empty($_GET['open_cat']) || $openCatEditId > 0 || (isset($_GET['error']) && in_array((string) $_GET['error'], ['percent_sum', 'invalid_name'], true));
$openDeleteModal = !empty($_GET['open_delete']) || (isset($_GET['error']) && in_array((string) $_GET['error'], ['confirm_mismatch', 'site_delete_failed', 'confirm_password_required', 'confirm_password_invalid'], true));
$openRenameModal = !empty($_GET['open_rename']);
$qSite = 'site_id=' . $siteId;
$sitePurchaseCounts = tnc_site_purchase_counts($siteId);

$menuActions = [];
$menuLists = [];

if (user_can('pr.create')) {
    $menuActions[] = [
        'icon' => 'bi-cart-plus',
        'title' => 'สร้างใบขอซื้อ',
        'meta' => 'Purchase Request',
        'tone' => 'primary',
        'url' => app_path('pages/purchase/purchase-request-create.php') . '?' . $qSite,
    ];
}
if (user_can('po.create')) {
    $menuActions[] = [
        'icon' => 'bi-bag-check',
        'title' => 'สร้างใบสั่งซื้อ',
        'meta' => 'Purchase Order',
        'tone' => 'success',
        'url' => app_path('pages/purchase/purchase-order-create-direct.php') . '?' . $qSite,
        'disabled' => !empty($summary['exhausted']) && empty($summary['unlimited']),
        'disabled_meta' => 'งบไซต์เต็มแล้ว',
    ];
}
if (user_can('page.stock')) {
    $menuActions[] = [
        'icon' => 'bi-box-seam',
        'title' => 'Stock List',
        'meta' => 'คลังสินค้าไซต์',
        'tone' => 'amber',
        'url' => app_path('pages/stock/stock-list.php') . '?' . $qSite,
    ];
}
if (user_can('page.pr')) {
    $menuLists[] = [
        'icon' => 'bi-list-ul',
        'title' => 'รายการใบขอซื้อ',
        'count' => (int) ($sitePurchaseCounts['pr'] ?? 0),
        'meta' => 'Purchase Request',
        'tone' => 'neutral',
        'url' => app_path('pages/purchase/purchase-request-list.php') . '?' . $qSite,
    ];
}
if (user_can('page.po')) {
    $menuLists[] = [
        'icon' => 'bi-receipt',
        'title' => 'รายการใบสั่งซื้อ',
        'count' => (int) ($sitePurchaseCounts['po'] ?? 0),
        'meta' => 'Purchase Order',
        'tone' => 'neutral',
        'url' => app_path('pages/purchase/purchase-order-list.php') . '?' . $qSite,
    ];
}

$renderHubMenuItems = static function (array $items): void {
    foreach ($items as $item) {
        $tone = preg_replace('/[^a-z0-9-]/', '', (string) ($item['tone'] ?? 'neutral'));
        if ($tone === '') {
            $tone = 'neutral';
        }
        $toneClass = 'hub-action-tile--' . $tone;
        $icon = htmlspecialchars((string) ($item['icon'] ?? 'bi-circle'), ENT_QUOTES, 'UTF-8');
        $titleText = (string) ($item['title'] ?? '');
        if (array_key_exists('count', $item)) {
            $titleText .= ' (' . number_format((int) $item['count']) . ' รายการ)';
        }
        $title = htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8');
        $meta = htmlspecialchars((string) ($item['meta'] ?? ''), ENT_QUOTES, 'UTF-8');
        $disabledMeta = htmlspecialchars((string) ($item['disabled_meta'] ?? ''), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="col-12 col-md-6 col-lg-4 d-flex">
            <?php if (!empty($item['disabled'])): ?>
                <div class="hub-action-tile <?= $toneClass ?> hub-action-tile--disabled w-100" aria-disabled="true" title="<?= $disabledMeta !== '' ? $disabledMeta : 'ไม่พร้อมใช้งาน' ?>">
                    <span class="hub-action-tile__icon" aria-hidden="true"><i class="bi <?= $icon ?>"></i></span>
                    <span class="hub-action-tile__body">
                        <span class="hub-action-tile__title"><?= $title ?></span>
                        <span class="hub-action-tile__meta"><?= $disabledMeta !== '' ? $disabledMeta : $meta ?></span>
                    </span>
                    <span class="hub-action-tile__lock" aria-hidden="true"><i class="bi bi-lock-fill"></i></span>
                </div>
            <?php else: ?>
                <a href="<?= htmlspecialchars((string) $item['url'], ENT_QUOTES, 'UTF-8') ?>" class="hub-action-tile <?= $toneClass ?> w-100">
                    <span class="hub-action-tile__icon" aria-hidden="true"><i class="bi <?= $icon ?>"></i></span>
                    <span class="hub-action-tile__body">
                        <span class="hub-action-tile__title"><?= $title ?></span>
                        <?php if ($meta !== ''): ?>
                            <span class="hub-action-tile__meta"><?= $meta ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="hub-action-tile__chevron" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }
};
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> | Site Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --hub-copper: #ea580c;
            --hub-copper-dark: #c2410c;
            --hub-ink: #0f172a;
            --hub-muted: #64748b;
            --hub-border: #e2e8f0;
            --hub-surface: #ffffff;
        }
        body { font-family: 'Sarabun', sans-serif; background: #f3f4f6; }
        .hub-card { border: 1px solid var(--hub-border); border-radius: 0.875rem; background: var(--hub-surface); box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06); }
        .hub-kpi { border-radius: 0.85rem; border: 1px solid #f0e6dc; background: #fffaf3; }
        .hub-menu-section-title { font-size: 1rem; font-weight: 700; color: var(--hub-ink); margin-bottom: 1rem; }
        .hub-action-grid { --bs-gutter-x: 0.85rem; --bs-gutter-y: 0.85rem; }
        .hub-action-tile {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            width: 100%;
            min-height: 5.75rem;
            padding: 1rem 1.05rem;
            border-radius: 0.875rem;
            border: 1px solid var(--hub-border);
            background: var(--hub-surface);
            text-decoration: none;
            color: inherit;
            transition: transform 0.18s ease-out, box-shadow 0.18s ease-out, border-color 0.18s ease-out, background-color 0.18s ease-out;
        }
        .hub-action-tile:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
        }
        .hub-action-tile:focus-visible {
            outline: 2px solid var(--hub-copper);
            outline-offset: 2px;
        }
        .hub-action-tile__icon {
            width: 2.85rem;
            height: 2.85rem;
            border-radius: 0.625rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .hub-action-tile__body { flex: 1; min-width: 0; text-align: left; }
        .hub-action-tile__title {
            display: block;
            font-weight: 700;
            font-size: 1rem;
            line-height: 1.35;
            color: var(--hub-ink);
            text-wrap: pretty;
        }
        .hub-action-tile__meta {
            display: block;
            margin-top: 0.15rem;
            font-size: 0.8125rem;
            line-height: 1.35;
            color: var(--hub-muted);
        }
        .hub-action-tile__chevron,
        .hub-action-tile__lock {
            flex-shrink: 0;
            font-size: 1rem;
            color: #cbd5e1;
            transition: transform 0.18s ease-out, color 0.18s ease-out;
        }
        .hub-action-tile:hover .hub-action-tile__chevron {
            color: #94a3b8;
            transform: translateX(2px);
        }
        .hub-action-tile--primary .hub-action-tile__icon { background: #ffedd5; color: var(--hub-copper-dark); }
        .hub-action-tile--primary:hover { border-color: #fdba74; background: #fffaf5; }
        .hub-action-tile--success .hub-action-tile__icon { background: #dcfce7; color: #15803d; }
        .hub-action-tile--success:hover { border-color: #86efac; background: #fafff9; }
        .hub-action-tile--amber .hub-action-tile__icon { background: #fef3c7; color: #b45309; }
        .hub-action-tile--amber:hover { border-color: #fcd34d; background: #fffdf5; }
        .hub-action-tile--neutral .hub-action-tile__icon { background: #f1f5f9; color: #475569; }
        .hub-action-tile--neutral:hover { border-color: #cbd5e1; background: #fafbfc; }
        .hub-action-tile--disabled {
            opacity: 0.62;
            cursor: not-allowed;
            box-shadow: none;
        }
        .hub-action-tile--disabled:hover { transform: none; box-shadow: none; }
        .hub-action-tile--disabled .hub-action-tile__meta { color: #dc2626; }
        .cat-low { background: #fef2f2; }
        .hub-budget-form .form-control { font-weight: 700; font-size: 1.05rem; border-color: #f0e6dc; background: #fff; }
        .hub-budget-form .form-control:focus { border-color: #f59e0b; box-shadow: 0 0 0 .2rem rgba(245, 158, 11, 0.15); }
        .hub-cat-add-btn { width: 1.65rem; height: 1.65rem; padding: 0; line-height: 1; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .hub-cat-edit-btn {
            width: 1.75rem;
            height: 1.75rem;
            padding: 0;
            border: 0;
            border-radius: 999px;
            background: transparent;
            color: var(--hub-muted);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: color 0.15s ease, background 0.15s ease;
        }
        .hub-cat-edit-btn:hover {
            color: var(--hub-copper);
            background: rgba(234, 88, 12, 0.08);
        }
        .hub-cat-edit-btn:focus-visible {
            outline: 2px solid rgba(234, 88, 12, 0.45);
            outline-offset: 2px;
        }
        .hub-cat-name-cell { display: flex; align-items: center; gap: 0.35rem; min-width: 0; }
        .hub-cat-name-cell__text { min-width: 0; }
        .hub-danger-zone {
            border: 1px solid #fecaca;
            border-radius: 0.875rem;
            background: #fffafa;
        }
        .hub-danger-zone__title {
            font-size: 1rem;
            font-weight: 700;
            color: #991b1b;
            margin: 0 0 0.35rem;
        }
        .hub-danger-zone__text {
            font-size: 0.875rem;
            line-height: 1.5;
            color: #7f1d1d;
            margin: 0;
            max-width: 52ch;
        }
        .hub-delete-modal .modal-content {
            border: 1px solid #fecaca;
            border-radius: 0.875rem;
            overflow: hidden;
        }
        .hub-delete-modal__header {
            background: #fef2f2;
            border-bottom: 1px solid #fecaca;
        }
        .hub-delete-modal__name-pill {
            display: inline-block;
            padding: 0.2rem 0.55rem;
            border-radius: 0.375rem;
            background: #fee2e2;
            color: #991b1b;
            font-weight: 700;
        }
        .hub-title-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.35rem;
        }
        .hub-rename-btn {
            width: 2rem;
            height: 2rem;
            padding: 0;
            border: 1px solid var(--hub-border);
            border-radius: 0.5rem;
            background: var(--hub-surface);
            color: var(--hub-muted);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            transition: color 0.15s ease-out, border-color 0.15s ease-out, background-color 0.15s ease-out;
        }
        .hub-rename-btn:hover {
            color: var(--hub-copper-dark);
            border-color: #fdba74;
            background: #fffaf5;
        }
        .hub-rename-btn:focus-visible {
            outline: 2px solid var(--hub-copper);
            outline-offset: 2px;
        }
        @media (prefers-reduced-motion: reduce) {
            .hub-action-tile,
            .hub-action-tile__chevron {
                transition: none;
            }
            .hub-action-tile:hover {
                transform: none;
            }
            .hub-action-tile:hover .hub-action-tile__chevron {
                transform: none;
            }
        }
    </style>
</head>
<body class="tnc-app-body tnc-layout-list">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container py-4 pb-5">
    <div class="tnc-page-head mb-4 flex-wrap gap-3">
        <div>
            <p class="tnc-page-kicker">Site Hub</p>
            <div class="hub-title-row">
                <h1 class="tnc-list-title mb-0"><span class="tnc-list-title__icon me-2"><i class="bi bi-building"></i></span><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></h1>
                <?php if ($canEditBudget): ?>
                    <button type="button"
                            class="hub-rename-btn"
                            data-bs-toggle="modal"
                            data-bs-target="#hubRenameSiteModal"
                            title="แก้ไขชื่อไซต์"
                            aria-label="แก้ไขชื่อไซต์">
                        <i class="bi bi-pencil"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars($pickerUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill">
                <i class="bi bi-arrow-left me-1"></i>เปลี่ยนไซต์
            </a>
        </div>
    </div>

    <?php
    $hubFlash = tnc_flash_from_query($_GET);
    if ($hubFlash === null && !empty($_GET['name_updated'])) {
        $hubFlash = ['type' => 'success', 'message' => 'เปลี่ยนชื่อไซต์แล้ว', 'audio' => 'update'];
    }
    if ($hubFlash === null && !empty($_GET['cat_saved'])) {
        $hubFlash = ['type' => 'success', 'message' => 'บันทึกหมวดค่าใช้จ่ายแล้ว', 'audio' => 'update'];
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'percent_sum') {
        $hubFlash['message'] = 'รวม % หมวดของไซต์นี้เกิน 100% — กรุณาปรับสัดส่วน';
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'cat_partial') {
        $hubFlash['type'] = 'warning';
        $hubFlash['message'] = 'สร้างไซต์แล้ว แต่บางหมวดไม่ได้บันทึก (รวม % เกิน 100%) — กรุณาเพิ่มหมวดใหม่ที่ Site Hub';
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'confirm_mismatch') {
        $hubFlash['message'] = 'ชื่อไซต์ที่พิมพ์ไม่ตรง — กรุณาพิมพ์ชื่อให้ตรงทุกตัวอักษร';
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'confirm_password_required') {
        $hubFlash['message'] = 'กรุณากรอกรหัสผ่านของคุณเพื่อยืนยันการลบ';
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'confirm_password_invalid') {
        $hubFlash['message'] = 'รหัสผ่านไม่ถูกต้อง';
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'site_delete_failed') {
        $hubFlash['message'] = 'ลบไซต์ไม่สำเร็จ — กรุณาลองใหม่';
    }
    if ($hubFlash !== null && !empty($_GET['name_updated'])) {
        $hubFlash['message'] = 'เปลี่ยนชื่อไซต์แล้ว';
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'invalid_name' && !empty($_GET['open_rename'])) {
        $hubFlash['message'] = 'ชื่อไซต์ไม่ถูกต้อง — กรุณากรอก 1–200 ตัวอักษร';
    }
    tnc_render_flash($hubFlash);
    ?>

    <?php if (!empty($summary['exhausted']) && empty($summary['unlimited'])): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-1"></i>งบไซต์เต็มแล้ว — ไม่สามารถออก PO ใหม่ได้</div>
    <?php elseif (!empty($summary['low']) && empty($summary['unlimited'])): ?>
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>งบไซต์เหลือน้อย (ไม่เกิน 20% ของวงเงิน)</div>
    <?php endif; ?>

    <div class="hub-card p-4 mb-4">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="hub-kpi p-3 h-100">
                    <div class="small text-muted mb-1">วงเงิน</div>
                    <?php if ($canEditBudget): ?>
                        <form method="post" action="<?= htmlspecialchars($hubUrl, ENT_QUOTES, 'UTF-8') ?>" class="hub-budget-form">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="save_site_budget" value="1">
                            <input type="hidden" name="site_id" value="<?= $siteId ?>">
                            <div class="input-group input-group-sm">
                                <input type="text" name="site_budget" class="form-control" inputmode="decimal" value="<?= htmlspecialchars((string) $siteBudgetRaw, ENT_QUOTES, 'UTF-8') ?>" placeholder="0 = ไม่จำกัด" aria-label="วงเงินไซต์">
                                <button type="submit" class="btn btn-warning" title="บันทึกวงเงิน"><i class="bi bi-check-lg"></i></button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="fw-bold fs-5"><?= !empty($summary['unlimited']) ? 'ไม่จำกัด' : tnc_site_budget_format_money($summary['limit']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-3">
                <div class="hub-kpi p-3 h-100">
                    <div class="small text-muted">ใช้ไปแล้ว</div>
                    <div class="fw-bold fs-5"><?= tnc_site_budget_format_money((float) ($summary['used'] ?? 0)) ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="hub-kpi p-3 h-100">
                    <div class="small text-muted">คงเหลือ</div>
                    <div class="fw-bold fs-5"><?= $summary['remaining'] !== null ? tnc_site_budget_format_money($summary['remaining']) : '—' ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="hub-kpi p-3 h-100">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                        <div class="small text-muted">หมวดค่าใช้จ่าย</div>
                        <?php if ($canEditBudget): ?>
                            <button type="button" class="btn btn-sm btn-warning rounded-circle hub-cat-add-btn hub-cat-open-add" data-bs-toggle="modal" data-bs-target="#hubCategoryModal" title="เพิ่มหมวดค่าใช้จ่าย" aria-label="เพิ่มหมวดค่าใช้จ่าย">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="fw-bold fs-5"><?= count($summary['categories'] ?? []) ?></div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($summary['categories']) || $canEditBudget): ?>
    <div class="hub-card p-4 mb-4">
        <?php if (empty($summary['categories'])): ?>
            <div class="text-center py-4 text-muted">
                <i class="bi bi-tags fs-3 d-block mb-2 opacity-50"></i>
                ยังไม่มีหมวดค่าใช้จ่าย
                <?php if ($canEditBudget): ?>
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-warning rounded-pill hub-cat-open-add" data-bs-toggle="modal" data-bs-target="#hubCategoryModal">
                            <i class="bi bi-plus-lg me-1"></i>เพิ่มหมวดแรก
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>หมวด</th>
                        <th class="text-end">วงเงิน</th>
                        <th class="text-end">ใช้ไป</th>
                        <th class="text-end">คงเหลือ</th>
                        <?php if ($canEditBudget): ?><th class="text-end" style="width:3rem;"><span class="visually-hidden">แก้ไข</span></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summary['categories'] as $cat): ?>
                        <?php
                        $catIdRow = (int) ($cat['id'] ?? 0);
                        $catPctVal = $cat['budget_percent'] ?? null;
                        $catPctInput = ($catPctVal === null) ? '' : rtrim(rtrim(number_format((float) $catPctVal, 2, '.', ''), '0'), '.');
                        $catEditPercentRoom = round(max(0.0, 100.0 - tnc_site_category_percent_sum($siteId, $catIdRow)), 2);
                        ?>
                        <tr class="<?= !empty($cat['over_budget']) || !empty($cat['low']) || ($cat['remaining'] !== null && $cat['remaining'] <= 0.0001) ? 'cat-low' : '' ?>">
                            <td>
                                <div class="hub-cat-name-cell">
                                    <div class="hub-cat-name-cell__text">
                                        <?= htmlspecialchars((string) ($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($cat['over_budget'])): ?>
                                            <span class="badge bg-danger ms-1">เกินงบ</span>
                                        <?php elseif ($cat['remaining'] !== null && $cat['remaining'] <= 0.0001): ?>
                                            <span class="badge bg-danger-subtle text-danger ms-1">หมดวงเงิน</span>
                                        <?php elseif (!empty($cat['low'])): ?>
                                            <span class="badge bg-danger-subtle text-danger ms-1">เหลือน้อย</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="text-end">
                                <?php if (!empty($cat['unlimited'])): ?>
                                    <span class="text-muted">ไม่จำกัดหมวด</span>
                                <?php elseif ($cat['budget_percent'] === 0.0 || $cat['budget_percent'] === 0): ?>
                                    <span class="text-danger fw-semibold">0% · งบหมด</span>
                                <?php else: ?>
                                    <?= $cat['budget_percent'] !== null ? htmlspecialchars((string) $cat['budget_percent'], ENT_QUOTES, 'UTF-8') . '%' : '' ?>
                                    <div class="small text-muted"><?= tnc_site_budget_format_money($cat['limit'] ?? null) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= tnc_site_budget_format_money((float) ($cat['used'] ?? 0)) ?></td>
                            <td class="text-end fw-semibold">
                                <?php if ($cat['remaining'] !== null): ?>
                                    <span class="<?= ($cat['remaining'] <= 0.0001) ? 'text-danger' : '' ?>">
                                        <?= tnc_site_budget_format_money($cat['remaining']) ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <?php if ($canEditBudget): ?>
                            <td class="text-end">
                                <button type="button"
                                        class="hub-cat-edit-btn hub-cat-open-edit"
                                        data-bs-toggle="modal"
                                        data-bs-target="#hubCategoryModal"
                                        data-cat-id="<?= $catIdRow ?>"
                                        data-cat-name="<?= htmlspecialchars((string) ($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-cat-percent="<?= htmlspecialchars($catPctInput, ENT_QUOTES, 'UTF-8') ?>"
                                        data-cat-percent-room="<?= htmlspecialchars(number_format($catEditPercentRoom, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                                        title="แก้ไขหมวด"
                                        aria-label="แก้ไขหมวด <?= htmlspecialchars((string) ($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($canEditBudget): ?>
    <div class="modal fade" id="hubCategoryModal" tabindex="-1" aria-labelledby="hubCategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="<?= htmlspecialchars($hubUrl, ENT_QUOTES, 'UTF-8') ?>" id="hubCategoryForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="save_site_category" value="1">
                    <input type="hidden" name="category_site_id" value="<?= $siteId ?>">
                    <input type="hidden" name="category_id" id="hub_category_id" value="0">
                    <div class="modal-header">
                        <h5 class="modal-title" id="hubCategoryModalLabel"><i class="bi bi-tag-fill me-2 text-warning"></i><span id="hubCategoryModalTitleText">เพิ่มหมวดค่าใช้จ่าย</span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="hub_category_name" class="form-label">ชื่อหมวด <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="hub_category_name" name="category_name" maxlength="150" required autocomplete="off">
                        </div>
                        <div class="mb-2">
                            <label for="hub_category_budget_percent" class="form-label">% งบของไซต์</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="hub_category_budget_percent" name="category_budget_percent" inputmode="decimal" maxlength="6" autocomplete="off">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="form-text" id="hub_category_percent_help">ว่าง = ไม่จำกัดหมวด · เหลือจัดสรรได้ <?= htmlspecialchars(number_format($catPercentRoom, 2), ENT_QUOTES, 'UTF-8') ?>%</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-warning rounded-pill" id="hubCategorySubmitBtn"><i class="bi bi-check-lg me-1"></i><span id="hubCategorySubmitText">บันทึกหมวด</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($menuActions !== []): ?>
    <div class="hub-card p-4 mb-4">
        <h2 class="hub-menu-section-title"><i class="bi bi-lightning-charge me-2" style="color: var(--hub-copper);"></i>ทำรายการ</h2>
        <div class="row hub-action-grid">
            <?php $renderHubMenuItems($menuActions); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($menuLists !== []): ?>
    <div class="hub-card p-4">
        <h2 class="hub-menu-section-title"><i class="bi bi-folder2-open me-2 text-secondary"></i>รายการ &amp; รายงาน</h2>
        <div class="row hub-action-grid">
            <?php $renderHubMenuItems($menuLists); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canEditBudget): ?>
    <div class="modal fade" id="hubRenameSiteModal" tabindex="-1" aria-labelledby="hubRenameSiteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="<?= htmlspecialchars($hubUrl, ENT_QUOTES, 'UTF-8') ?>" id="hubRenameSiteForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="save_site_name" value="1">
                    <input type="hidden" name="site_id" value="<?= $siteId ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="hubRenameSiteModalLabel"><i class="bi bi-pencil me-2 text-warning"></i>แก้ไขชื่อไซต์</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                    </div>
                    <div class="modal-body">
                        <label for="hub_site_name" class="form-label">ชื่อไซต์ <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control"
                               id="hub_site_name"
                               name="site_name"
                               maxlength="200"
                               value="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>"
                               required
                               autocomplete="off">
                        <div class="form-text">1–200 ตัวอักษร</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-warning rounded-pill"><i class="bi bi-check-lg me-1"></i>บันทึกชื่อ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canDeleteSite): ?>
    <section class="hub-danger-zone p-4 mt-4" aria-labelledby="hubDangerZoneTitle">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div>
                <h2 class="hub-danger-zone__title" id="hubDangerZoneTitle"><i class="bi bi-exclamation-triangle me-1"></i>ลบไซต์</h2>
                <p class="hub-danger-zone__text">ลบไซต์ «<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>» หมวดค่าใช้จ่าย และ PR/PO ที่อ้างอิงไซต์นี้ทั้งหมด การลบไม่สามารถย้อนกลับได้</p>
            </div>
            <button type="button"
                    class="btn btn-outline-danger rounded-pill flex-shrink-0"
                    data-bs-toggle="modal"
                    data-bs-target="#hubDeleteSiteModal">
                <i class="bi bi-trash3 me-1"></i>ลบไซต์นี้
            </button>
        </div>
    </section>

    <div class="modal fade hub-delete-modal" id="hubDeleteSiteModal" tabindex="-1" aria-labelledby="hubDeleteSiteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="<?= htmlspecialchars($hubUrl, ENT_QUOTES, 'UTF-8') ?>" id="hubDeleteSiteForm" autocomplete="off">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="delete_site" value="1">
                    <input type="hidden" name="site_id" value="<?= $siteId ?>">
                    <div class="modal-header hub-delete-modal__header">
                        <h5 class="modal-title text-danger" id="hubDeleteSiteModalLabel"><i class="bi bi-trash3 me-2"></i>ลบไซต์นี้</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3">จะลบไซต์ <span class="hub-delete-modal__name-pill"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></span> พร้อม PR/PO และหมวดค่าใช้จ่ายของไซต์นี้</p>
                        <p class="small text-muted mb-2">พิมพ์ชื่อไซต์และรหัสผ่าน ADMIN ของคุณเพื่อยืนยัน</p>
                        <div class="mb-3">
                            <label for="hub_confirm_site_name" class="form-label">ชื่อไซต์ <span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control"
                                   id="hub_confirm_site_name"
                                   name="confirm_site_name"
                                   maxlength="200"
                                   autocomplete="off"
                                   placeholder="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>"
                                   data-expected-name="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>"
                                   required>
                        </div>
                        <div class="mb-0">
                            <label for="hub_confirm_password" class="form-label">รหัสผ่านยืนยัน <span class="text-danger">*</span></label>
                            <input type="password"
                                   class="form-control"
                                   id="hub_confirm_password"
                                   name="confirm_password"
                                   autocomplete="current-password"
                                   required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-danger rounded-pill" id="hubDeleteSiteSubmit" disabled>
                            <i class="bi bi-trash3 me-1"></i>ลบไซต์ถาวร
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($canDeleteSite): ?>
<script>
(function () {
    var input = document.getElementById('hub_confirm_site_name');
    var pwdInput = document.getElementById('hub_confirm_password');
    var submitBtn = document.getElementById('hubDeleteSiteSubmit');
    var expected = input ? (input.getAttribute('data-expected-name') || '') : '';

    function syncDeleteSubmit() {
        if (!submitBtn || !input || !pwdInput) {
            return;
        }
        var nameOk = input.value.trim() === expected;
        var pwdOk = pwdInput.value.trim() !== '';
        submitBtn.disabled = !(nameOk && pwdOk);
    }

    if (input) {
        input.addEventListener('input', syncDeleteSubmit);
    }
    if (pwdInput) {
        pwdInput.addEventListener('input', syncDeleteSubmit);
    }
    syncDeleteSubmit();

    <?php if ($openDeleteModal): ?>
    var deleteModalEl = document.getElementById('hubDeleteSiteModal');
    if (deleteModalEl && window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(deleteModalEl).show();
    }
    <?php endif; ?>
}());
</script>
<?php endif; ?>
<?php if ($canEditBudget): ?>
<script>
(function () {
    var renameModalEl = document.getElementById('hubRenameSiteModal');
    var renameInput = document.getElementById('hub_site_name');
    if (renameModalEl) {
        renameModalEl.addEventListener('shown.bs.modal', function () {
            if (renameInput) {
                renameInput.focus();
                renameInput.select();
            }
        });
    }

    <?php if ($openRenameModal): ?>
    if (renameModalEl && window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(renameModalEl).show();
    }
    <?php endif; ?>
}());
</script>
<?php endif; ?>
<?php if ($canEditBudget): ?>
<script>
(function () {
    var modalEl = document.getElementById('hubCategoryModal');
    var catIdInput = document.getElementById('hub_category_id');
    var catNameInput = document.getElementById('hub_category_name');
    var catPctInput = document.getElementById('hub_category_budget_percent');
    var catPctHelp = document.getElementById('hub_category_percent_help');
    var catTitleText = document.getElementById('hubCategoryModalTitleText');
    var catSubmitText = document.getElementById('hubCategorySubmitText');
    var defaultPercentRoom = <?= json_encode(number_format($catPercentRoom, 2, '.', ''), JSON_UNESCAPED_UNICODE) ?>;

    function setCategoryModalMode(mode, data) {
        data = data || {};
        var isEdit = mode === 'edit';
        if (catIdInput) {
            catIdInput.value = isEdit ? String(data.id || '0') : '0';
        }
        if (catNameInput) {
            catNameInput.value = isEdit ? (data.name || '') : '';
        }
        if (catPctInput) {
            catPctInput.value = isEdit ? (data.percent || '') : '';
        }
        if (catTitleText) {
            catTitleText.textContent = isEdit ? 'แก้ไขหมวดค่าใช้จ่าย' : 'เพิ่มหมวดค่าใช้จ่าย';
        }
        if (catSubmitText) {
            catSubmitText.textContent = isEdit ? 'บันทึกการแก้ไข' : 'บันทึกหมวด';
        }
        if (catPctHelp) {
            var room = isEdit ? (data.percentRoom || defaultPercentRoom) : defaultPercentRoom;
            catPctHelp.textContent = 'ว่าง = ไม่จำกัดหมวด · เหลือจัดสรรได้ ' + room + '%';
        }
    }

    document.querySelectorAll('.hub-cat-open-add').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setCategoryModalMode('add');
        });
    });

    document.querySelectorAll('.hub-cat-open-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setCategoryModalMode('edit', {
                id: btn.getAttribute('data-cat-id') || '0',
                name: btn.getAttribute('data-cat-name') || '',
                percent: btn.getAttribute('data-cat-percent') || '',
                percentRoom: btn.getAttribute('data-cat-percent-room') || defaultPercentRoom
            });
        });
    });

    if (modalEl) {
        modalEl.addEventListener('shown.bs.modal', function () {
            if (catNameInput) {
                catNameInput.focus();
                catNameInput.select();
            }
        });
    }

    <?php if ($openCatModal): ?>
    if (modalEl && window.bootstrap) {
        <?php if ($openCatEditId > 0): ?>
        <?php
        $openCatEditRow = null;
        foreach ($summary['categories'] ?? [] as $catRow) {
            if ((int) ($catRow['id'] ?? 0) === $openCatEditId) {
                $openCatEditRow = $catRow;
                break;
            }
        }
        if ($openCatEditRow !== null):
            $openCatPctVal = $openCatEditRow['budget_percent'] ?? null;
            $openCatPctInput = ($openCatPctVal === null) ? '' : rtrim(rtrim(number_format((float) $openCatPctVal, 2, '.', ''), '0'), '.');
            $openCatEditRoom = round(max(0.0, 100.0 - tnc_site_category_percent_sum($siteId, $openCatEditId)), 2);
        ?>
        setCategoryModalMode('edit', {
            id: '<?= $openCatEditId ?>',
            name: <?= json_encode((string) ($openCatEditRow['name'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
            percent: <?= json_encode($openCatPctInput, JSON_UNESCAPED_UNICODE) ?>,
            percentRoom: <?= json_encode(number_format($openCatEditRoom, 2, '.', ''), JSON_UNESCAPED_UNICODE) ?>
        });
        <?php else: ?>
        setCategoryModalMode('add');
        <?php endif; ?>
        <?php else: ?>
        setCategoryModalMode('add');
        <?php endif; ?>
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    <?php endif; ?>
}());
</script>
<?php endif; ?>
</body>
</html>
