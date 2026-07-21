<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/site_budget.php';
require_once dirname(__DIR__, 2) . '/includes/site_cost_categories.php';
require_once dirname(__DIR__, 2) . '/includes/site_favorites.php';
require_once dirname(__DIR__, 2) . '/includes/site_picker_order.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_flash.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_shell_head.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_can('page.site.picker')) {
    header('Location: ' . app_path('index.php') . '?error=forbidden');
    exit;
}

$pickerUrl = app_path('pages/sites/site-picker.php');
$canCreateSite = user_can('site.manage');
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_site_picker_order'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_verify_request()) {
        echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rawOrder = $_POST['site_order'] ?? '[]';
    $decoded = is_string($rawOrder) ? json_decode($rawOrder, true) : $rawOrder;
    if (!is_array($decoded)) {
        echo json_encode(['ok' => false, 'error' => 'invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $orderIds = tnc_site_picker_order_normalize_ids($decoded);
    foreach ($orderIds as $siteId) {
        if (Db::rowByIdField('sites', $siteId) === null) {
            echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    $saved = tnc_site_picker_order_save_for_user($currentUserId, $orderIds);
    echo json_encode([
        'ok' => $saved,
        'order' => $orderIds,
        'error' => $saved ? null : 'save_failed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_site_favorite'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_verify_request()) {
        echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $toggleSiteId = (int) ($_POST['site_id'] ?? 0);
    $result = tnc_site_favorites_toggle($currentUserId, $toggleSiteId);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_site'])) {
    if (!$canCreateSite) {
        header('Location: ' . app_path('index.php') . '?error=forbidden');
        exit;
    }
    if (!csrf_verify_request()) {
        header('Location: ' . $pickerUrl . '?error=csrf&open_create=1');
        exit;
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';

    $siteName = trim((string) ($_POST['site_name'] ?? ''));
    $budgetRaw = trim(str_replace([',', ' '], '', (string) ($_POST['site_budget'] ?? '0')));
    $siteBudget = max(0.0, round((float) $budgetRaw, 2));

    if ($siteName === '' || strlen($siteName) > 200) {
        header('Location: ' . $pickerUrl . '?error=invalid_name&open_create=1');
        exit;
    }

    $newSiteId = Db::nextNumericId('sites', 'id');
    Db::setRow('sites', (string) $newSiteId, [
        'id' => $newSiteId,
        'name' => $siteName,
        'sort_order' => 0,
        'site_budget' => $siteBudget,
    ]);
    tnc_audit_log('create', 'site', (string) $newSiteId, $siteName, [
        'source' => 'site-picker.php',
        'action' => 'create_site',
        'after' => Db::row('sites', (string) $newSiteId),
    ]);

    $catNames = is_array($_POST['category_name'] ?? null) ? $_POST['category_name'] : [];
    $catPcts = is_array($_POST['category_budget_percent'] ?? null) ? $_POST['category_budget_percent'] : [];
    $catCount = max(count($catNames), count($catPcts));
    $catFailed = [];
    for ($ci = 0; $ci < $catCount; $ci++) {
        $catName = trim((string) ($catNames[$ci] ?? ''));
        if ($catName === '') {
            continue;
        }
        $pctRaw = trim(str_replace('%', '', (string) ($catPcts[$ci] ?? '')));
        $catPct = null;
        if ($pctRaw !== '') {
            $catPct = max(0.0, min(100.0, round((float) str_replace([',', ' '], '', $pctRaw), 2)));
        }
        $savedCat = tnc_site_category_save(0, $newSiteId, $catName, 0, $catPct);
        if (is_array($savedCat) && ($savedCat['error'] ?? '') === 'percent_sum_exceeded') {
            $catFailed[] = $catName;
            continue;
        }
        if (is_int($savedCat) && $savedCat > 0) {
            tnc_audit_log('create', 'site_cost_category', (string) $savedCat, $catName, [
                'source' => 'site-picker.php',
                'action' => 'create_site_category',
                'after' => ['id' => $savedCat, 'site_id' => $newSiteId, 'name' => $catName],
            ]);
        }
    }

    $hubRedirect = app_path('pages/sites/site-hub.php?site_id=' . $newSiteId . '&created=1');
    if ($catFailed !== []) {
        $hubRedirect .= '&error=cat_partial';
    }
    header('Location: ' . $hubRedirect);
    exit;
}

$sites = Db::tableRows('sites');
$favoriteSiteIds = tnc_site_favorites_for_user($currentUserId);
$favoriteSiteIdSet = array_fill_keys($favoriteSiteIds, true);
$userSiteOrder = tnc_site_picker_order_for_user($currentUserId);
usort($sites, static function (array $a, array $b): int {
    $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
    if ($so !== 0) {
        return $so;
    }

    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
if ($userSiteOrder !== []) {
    $sites = tnc_site_picker_order_sort_sites($sites, $userSiteOrder);
} else {
    $sites = tnc_site_favorites_sort_sites($sites, $favoriteSiteIds);
}

$hubBase = app_path('pages/sites/site-hub.php');
$openCreateModal = !empty($_GET['open_create']) || (isset($_GET['error']) && in_array((string) $_GET['error'], ['invalid_name', 'percent_sum'], true));
$hasSites = false;
foreach ($sites as $siteRow) {
    if ((int) ($siteRow['id'] ?? 0) > 0) {
        $hasSites = true;
        break;
    }
}
$pickerQuickLinks = [];
if (user_can('page.pr')) {
    $pickerQuickLinks[] = [
        'url' => app_path('pages/purchase/purchase-request-list.php'),
        'title' => 'ดูรายการขอซื้อทั้งหมด',
        'meta' => 'Purchase Request · ทุกไซต์',
        'icon' => 'bi-list-ul',
        'tone' => 'primary',
    ];
}
if (user_can('page.po')) {
    $pickerQuickLinks[] = [
        'url' => app_path('pages/purchase/purchase-order-list.php'),
        'title' => 'ดูรายการสั่งซื้อทั้งหมด',
        'meta' => 'Purchase Order · ทุกไซต์',
        'icon' => 'bi-receipt',
        'tone' => 'success',
    ];
}
$sitePickerAssetVer = max(
    (int) (@filemtime(dirname(__DIR__, 2) . '/assets/js/site-picker.js') ?: 0),
    (int) (@filemtime(dirname(__DIR__, 2) . '/assets/css/site-picker.css') ?: 0)
) ?: time();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php tnc_shell_head([
        'title' => 'เลือกไซต์งาน | THEELIN CON',
        'extra_css' => ['assets/css/site-picker.css?v=' . $sitePickerAssetVer],
        'sarabun_weights' => '400;600;700;800',
    ]); ?>
</head>
<body class="tnc-app-body tnc-layout-list">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container py-4 pb-5">
    <?php
    $flash = tnc_flash_from_query($_GET);
    if ($flash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'percent_sum') {
        $flash['message'] = 'รวม % หมวดเกิน 100% กรุณาปรับสัดส่วน';
    }
    if ($flash !== null && !empty($_GET['deleted'])) {
        $flash['message'] = 'ลบไซต์เรียบร้อย';
    }
    tnc_render_flash($flash);
    ?>

    <header class="site-picker-head">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 w-100">
            <h1 class="site-picker-head__title mb-0">เลือกไซต์งาน</h1>
            <?php
            require_once dirname(__DIR__, 2) . '/includes/tnc_ui.php';
            echo tnc_ui_back_previous_button();
            ?>
        </div>
    </header>

    <?php if ($pickerQuickLinks !== []): ?>
    <section class="picker-quick-bar mb-4" aria-label="รายการจัดซื้อทั้งระบบ">
        <div class="row picker-quick-grid">
            <?php foreach ($pickerQuickLinks as $link): ?>
                <?php
                $tone = preg_replace('/[^a-z0-9-]/', '', (string) ($link['tone'] ?? 'neutral'));
                if ($tone === '') {
                    $tone = 'neutral';
                }
                ?>
                <div class="col-12 col-md-6 d-flex">
                    <a href="<?= htmlspecialchars((string) $link['url'], ENT_QUOTES, 'UTF-8') ?>" class="picker-action-tile picker-action-tile--<?= $tone ?> w-100">
                        <span class="picker-action-tile__icon" aria-hidden="true"><i class="bi <?= htmlspecialchars((string) ($link['icon'] ?? 'bi-link-45deg'), ENT_QUOTES, 'UTF-8') ?>"></i></span>
                        <span class="picker-action-tile__body">
                            <span class="picker-action-tile__title"><?= htmlspecialchars((string) ($link['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="picker-action-tile__meta"><?= htmlspecialchars((string) ($link['meta'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <span class="picker-action-tile__chevron" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($hasSites): ?>
    <section class="site-picker-search mb-4" id="sitePickerSearchBar" aria-label="ค้นหาไซต์งาน">
        <div class="site-picker-search__toolbar">
            <div class="site-picker-search__intro">
                <span class="site-picker-search__mark" aria-hidden="true"><i class="bi bi-search"></i></span>
                <div class="site-picker-search__copy">
                    <h2 class="site-picker-search__title">ค้นหาไซต์</h2>
                    <p class="site-picker-search__hint">พิมพ์ชื่อไซต์ ผลที่ตรงที่สุดจะขึ้นตำแหน่งแรก · ลากจากไอคอน <i class="bi bi-grip-vertical" aria-hidden="true"></i> มุมซ้ายบนของการ์ดเพื่อจัดลำดับ</p>
                </div>
            </div>
            <div class="site-picker-search__field">
                <label class="visually-hidden" for="sitePickerSearch">ค้นหาไซต์งาน</label>
                <input type="search"
                       class="site-picker-search__input"
                       id="sitePickerSearch"
                       autocomplete="off"
                       enterkeyhint="search"
                       spellcheck="false">
                <button type="button"
                        class="site-picker-search__clear d-none"
                        id="sitePickerSearchClear"
                        aria-label="ล้างคำค้นหา"
                        title="ล้างคำค้นหา">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <div class="row site-picker-grid" id="sitePickerGrid">
        <?php if ($canCreateSite): ?>
            <div class="col-12 col-md-6 col-lg-4 site-picker-card site-picker-add">
                <button type="button" class="site-card site-card--add w-100 h-100 p-3" data-bs-toggle="modal" data-bs-target="#sitePickerCreateModal" aria-label="เพิ่มไซต์งานใหม่">
                    <div class="d-flex flex-column align-items-center justify-content-center h-100 py-2">
                        <span class="site-card-add-icon" aria-hidden="true"><i class="bi bi-plus-lg"></i></span>
                    </div>
                </button>
            </div>
        <?php elseif (!$hasSites): ?>
            <div class="col-12">
                <div class="alert alert-warning mb-0">ยังไม่มีไซต์งาน · ติดต่อผู้ดูแลระบบเพื่อเพิ่มไซต์</div>
            </div>
        <?php endif; ?>
        <?php if ($hasSites): ?>
            <?php
            $siteBudgetUsedMap = tnc_site_budget_site_used_map();
            $siteSortIndex = 0;
            ?>
            <?php foreach ($sites as $site): ?>
                <?php
                $sid = (int) ($site['id'] ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                $isFavorite = !empty($favoriteSiteIdSet[$sid]);
                $summary = tnc_site_budget_site_summary_light($sid, $site, $siteBudgetUsedMap[$sid] ?? 0.0);
                $limit = $summary['limit'];
                $used = (float) ($summary['used'] ?? 0);
                $remaining = $summary['remaining'];
                $usedPct = null;
                if ($limit !== null && $limit > 0.0001) {
                    $usedPct = min(100.0, round(($used / $limit) * 100, 1));
                }
                $progressClass = '';
                if (!empty($summary['exhausted'])) {
                    $progressClass = 'is-full';
                } elseif (!empty($summary['low'])) {
                    $progressClass = 'is-low';
                }
                $remainingBoxClass = 'site-stat-box--remaining';
                if (!empty($summary['unlimited'])) {
                    $remainingBoxClass .= ' is-muted';
                } elseif ($remaining !== null && $remaining <= 0.0001) {
                    $remainingBoxClass .= ' is-danger';
                } elseif ($remaining !== null && !empty($summary['low'])) {
                    $remainingBoxClass .= ' is-warn';
                }
                ?>
                <div class="col-12 col-md-6 col-lg-4 site-picker-card"
                     data-site-id="<?= $sid ?>"
                     data-site-name="<?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                     data-favorite="<?= $isFavorite ? '1' : '0' ?>"
                     data-user-order="<?= (int) $siteSortIndex ?>">
                    <div class="site-card h-100<?= $isFavorite ? ' site-card--favorite' : '' ?>">
                        <button type="button"
                                class="site-card-drag-handle"
                                aria-label="ลากเพื่อจัดลำดับ: <?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                title="ลากเพื่อจัดลำดับ">
                            <i class="bi bi-grip-vertical" aria-hidden="true"></i>
                        </button>
                        <button type="button"
                                class="site-fav-btn<?= $isFavorite ? ' is-favorite' : '' ?>"
                                data-site-id="<?= $sid ?>"
                                aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>"
                                aria-label="<?= $isFavorite ? 'นำออกจากรายการโปรด' : 'เพิ่มในรายการโปรด' ?>: <?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                title="<?= $isFavorite ? 'นำออกจากรายการโปรด' : 'เพิ่มในรายการโปรด' ?>">
                            <i class="bi <?= $isFavorite ? 'bi-star-fill' : 'bi-star' ?>" aria-hidden="true"></i>
                        </button>
                        <a class="site-card-link" href="<?= htmlspecialchars($hubBase . '?site_id=' . $sid, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="site-card__body">
                                    <p class="site-card__name"><?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                    <div class="site-card__stats">
                                        <div class="site-stat-box">
                                            <span class="site-stat__label">งบ</span>
                                            <span class="site-stat__value<?= !empty($summary['unlimited']) ? ' site-stat__value--muted' : '' ?>">
                                                <?= !empty($summary['unlimited']) ? 'ไม่จำกัด' : tnc_site_budget_format_money($limit) ?>
                                            </span>
                                        </div>
                                        <div class="site-stat-box site-stat-box--spent">
                                            <span class="site-stat__label">ใช้ไป</span>
                                            <span class="site-stat__value"><?= tnc_site_budget_format_money($used) ?></span>
                                        </div>
                                        <div class="site-stat-box <?= htmlspecialchars($remainingBoxClass, ENT_QUOTES, 'UTF-8') ?>">
                                            <span class="site-stat__label">คงเหลือ</span>
                                            <span class="site-stat__value<?= ($remaining !== null && $remaining <= 0.0001) ? ' site-stat__value--danger' : (($remaining !== null && !empty($summary['low'])) ? ' site-stat__value--warn' : '') ?><?= !empty($summary['unlimited']) ? ' site-stat__value--muted' : '' ?>">
                                                <?php if (!empty($summary['unlimited'])): ?>
                                                    ไม่ระบุ
                                                <?php elseif ($remaining !== null): ?>
                                                    <?= tnc_site_budget_format_money($remaining) ?>
                                                <?php else: ?>
                                                    ไม่ระบุ
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if ($usedPct !== null): ?>
                                        <div class="site-card__detail">
                                            <div class="site-card__progress"
                                                 role="progressbar"
                                                 aria-valuemin="0"
                                                 aria-valuemax="100"
                                                 aria-valuenow="<?= htmlspecialchars((string) $usedPct, ENT_QUOTES, 'UTF-8') ?>"
                                                 aria-label="ใช้งบ <?= htmlspecialchars((string) $usedPct, ENT_QUOTES, 'UTF-8') ?> เปอร์เซ็นต์">
                                                <span class="site-card__progress-bar <?= htmlspecialchars($progressClass, ENT_QUOTES, 'UTF-8') ?>" style="width: <?= htmlspecialchars((string) $usedPct, ENT_QUOTES, 'UTF-8') ?>%;"></span>
                                            </div>
                                            <?php if (!empty($summary['exhausted'])): ?>
                                                <span class="site-card__status site-card__status--danger">เต็มวงเงินแล้ว</span>
                                            <?php elseif (!empty($summary['low'])): ?>
                                                <span class="site-card__status site-card__status--warn">งบเหลือน้อย</span>
                                            <?php elseif ($remaining !== null): ?>
                                                <span class="site-card__status site-card__status--ok">พร้อมใช้งาน</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                            </div>
                        </a>
                        <div class="site-card__nav-busy" aria-hidden="true">
                            <span class="site-card__nav-spinner"></span>
                            <span class="site-card__nav-label">กำลังเปิด…</span>
                        </div>
                    </div>
                </div>
                <?php ++$siteSortIndex; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($canCreateSite): ?>
<div class="modal fade picker-create-modal" id="sitePickerCreateModal" tabindex="-1" aria-labelledby="sitePickerCreateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="<?= htmlspecialchars($pickerUrl, ENT_QUOTES, 'UTF-8') ?>" id="sitePickerCreateForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="create_site" value="1">
                <div class="picker-create-modal__header">
                    <span class="picker-create-modal__icon" aria-hidden="true"><i class="bi bi-building"></i></span>
                    <div class="picker-create-modal__heading">
                        <h2 class="picker-create-modal__title" id="sitePickerCreateModalLabel">สร้างไซต์งานใหม่</h2>
                    </div>
                    <button type="button" class="btn-close picker-create-modal__close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="picker-create-modal__body">
                    <section class="picker-create-panel" aria-labelledby="pickerSiteInfoTitle">
                        <h3 class="picker-create-panel__title" id="pickerSiteInfoTitle">ข้อมูลไซต์</h3>
                        <div class="row g-3">
                            <div class="col-md-8 picker-create-field">
                                <label for="picker_site_name">ชื่อไซต์ <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="picker_site_name" name="site_name" maxlength="200" required autocomplete="off">
                            </div>
                            <div class="col-md-4 picker-create-field">
                                <label for="picker_site_budget">งบประมาณไซต์</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="picker_site_budget" name="site_budget" inputmode="decimal" value="0" placeholder="0" autocomplete="off">
                                    <span class="input-group-text">บาท</span>
                                </div>
                            </div>
                        </div>
                    </section>
                    <section class="picker-create-panel" aria-labelledby="pickerCatSectionTitle">
                        <div class="picker-create-panel__head">
                            <div>
                                <h3 class="picker-create-panel__title" id="pickerCatSectionTitle">หมวดค่าใช้จ่าย</h3>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-warning rounded-pill flex-shrink-0" id="pickerAddCatRow">
                                <i class="bi bi-plus-lg me-1"></i>เพิ่มหมวด
                            </button>
                        </div>
                        <div class="picker-cat-table">
                            <div class="picker-cat-table__head" aria-hidden="true">
                                <span>ชื่อหมวด</span>
                                <span>% งบ</span>
                                <span></span>
                            </div>
                            <div id="pickerCatRows">
                                <div class="picker-cat-row">
                                    <div class="picker-cat-row__name">
                                        <input type="text" class="form-control" name="category_name[]" maxlength="150" autocomplete="off" aria-label="ชื่อหมวด">
                                    </div>
                                    <div class="picker-cat-row__pct">
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control" name="category_budget_percent[]" inputmode="decimal" maxlength="6"  autocomplete="off" aria-label="เปอร์เซ็นต์งบ">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    <div class="picker-cat-row__remove text-end">
                                        <button type="button" class="picker-cat-remove btn-remove-cat" hidden aria-label="ลบหมวด"><i class="bi bi-trash3"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="picker-cat-foot">
                            <p class="picker-cat-rules mb-0">ว่าง % = ไม่จำกัดหมวด · 0% = งบหมด · รวมทุกหมวดไม่เกิน 100%</p>
                            <span class="picker-cat-total" id="pickerCatPctTotal" aria-live="polite">รวม 0%</span>
                        </div>
                    </section>
                </div>
                <div class="picker-create-modal__footer">
                    <button type="button" class="btn btn-outline-secondary btn-cancel" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-submit"><i class="bi bi-check-lg me-1"></i>สร้างไซต์</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<script>
window.__tncSitePickerBoot = {
    pickerUrl: <?= json_encode($pickerUrl, JSON_UNESCAPED_SLASHES) ?>,
    csrfToken: <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    canCreateSite: <?= $canCreateSite ? 'true' : 'false' ?>,
    openCreateModal: <?= $openCreateModal ? 'true' : 'false' ?>,
    hasCustomSiteOrder: <?= $userSiteOrder !== [] ? 'true' : 'false' ?>
};
</script>
<script src="<?= htmlspecialchars(app_path('assets/js/site-picker.js?v=' . $sitePickerAssetVer), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
