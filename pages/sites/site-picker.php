<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/site_budget.php';
require_once dirname(__DIR__, 2) . '/includes/site_cost_categories.php';
require_once dirname(__DIR__, 2) . '/includes/site_favorites.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_flash.php';

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
usort($sites, static function (array $a, array $b): int {
    $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
    if ($so !== 0) {
        return $so;
    }

    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
$sites = tnc_site_favorites_sort_sites($sites, $favoriteSiteIds);

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
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เลือกไซต์งาน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --picker-copper: #ea580c;
            --picker-copper-dark: #c2410c;
            --picker-ink: #0f172a;
            --picker-muted: #64748b;
            --picker-border: #e2e8f0;
            --picker-surface: #ffffff;
        }
        body { font-family: 'Sarabun', sans-serif; background: #f3f4f6; }
        .site-picker-head { margin-bottom: 1.25rem; }
        .site-picker-head__hint {
            font-size: 0.9375rem;
            color: var(--picker-muted);
            margin: 0.35rem 0 0;
            max-width: 42ch;
        }
        .site-picker-section {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.5rem 1rem;
            margin-bottom: 0.85rem;
        }
        .site-picker-section__title {
            font-size: 1rem;
            font-weight: 800;
            color: var(--picker-ink);
            margin: 0;
        }
        .site-picker-section__meta {
            font-size: 0.8125rem;
            color: var(--picker-muted);
        }
        .site-picker-grid { --bs-gutter-x: 0.85rem; --bs-gutter-y: 0.85rem; }
        .site-card {
            position: relative;
            border: 1px solid var(--picker-border);
            border-radius: 0.875rem;
            background: var(--picker-surface);
            min-height: 11.75rem;
            overflow: hidden;
            transition: transform 0.18s ease-out, border-color 0.18s ease-out, background-color 0.18s ease-out;
        }
        .site-card:hover {
            transform: translateY(-2px);
            border-color: #fdba74;
            background: #fffdfa;
        }
        .site-card--favorite {
            border-color: #fdba74;
            background: linear-gradient(180deg, #fffaf5 0%, #ffffff 42%);
        }
        .site-card--favorite:hover {
            border-color: #fb923c;
            background: linear-gradient(180deg, #fff7ed 0%, #ffffff 42%);
        }
        .site-card-link {
            display: block;
            color: inherit;
            text-decoration: none;
            padding: 1rem 1rem 0.95rem;
            padding-right: 2.6rem;
        }
        .site-card-link:hover { color: inherit; }
        .site-card-link:focus-visible {
            outline: 2px solid var(--picker-copper);
            outline-offset: -2px;
            border-radius: 0.875rem;
        }
        .site-card__row {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
        }
        .site-card__mark {
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 0.625rem;
            background: #ffedd5;
            color: var(--picker-copper-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .site-card--favorite .site-card__mark {
            background: #fed7aa;
            color: #9a3412;
        }
        .site-card__body { flex: 1; min-width: 0; }
        .site-card__name {
            font-size: 1.0625rem;
            font-weight: 800;
            line-height: 1.35;
            color: var(--picker-ink);
            margin: 0;
            text-wrap: pretty;
        }
        .site-card__stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.5rem;
            margin-top: 0.85rem;
        }
        .site-stat-box {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
            min-width: 0;
            padding: 0.6rem 0.55rem;
            border-radius: 0.625rem;
            background: #f8fafc;
        }
        .site-card--favorite .site-stat-box {
            background: #fff7ed;
        }
        .site-stat-box--spent {
            background: #fff;
            box-shadow: inset 0 0 0 1px #e2e8f0;
        }
        .site-card--favorite .site-stat-box--spent {
            background: #fffaf5;
            box-shadow: inset 0 0 0 1px #fed7aa;
        }
        .site-stat-box--remaining {
            background: #f0fdf4;
        }
        .site-card--favorite .site-stat-box--remaining {
            background: #ecfdf5;
        }
        .site-stat-box--remaining.is-warn {
            background: #fff7ed;
        }
        .site-stat-box--remaining.is-danger {
            background: #fef2f2;
        }
        .site-stat-box--remaining.is-muted {
            background: #f8fafc;
        }
        .site-card__detail {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
            margin-top: 0.5rem;
            padding: 0.55rem 0.65rem;
            border-radius: 0.625rem;
            background: #f8fafc;
        }
        .site-card--favorite .site-card__detail {
            background: #fff7ed;
        }
        .site-stat__label {
            display: block;
            font-size: 0.6875rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            color: var(--picker-muted);
        }
        .site-stat__value {
            display: block;
            font-size: 0.8125rem;
            font-weight: 700;
            color: var(--picker-ink);
            font-variant-numeric: tabular-nums;
            line-height: 1.3;
            word-break: break-word;
        }
        .site-stat__value--muted { color: var(--picker-muted); font-weight: 600; }
        .site-stat__value--warn { color: #c2410c; }
        .site-stat__value--danger { color: #b91c1c; }
        .site-card__progress {
            height: 0.35rem;
            border-radius: 999px;
            background: #e2e8f0;
            overflow: hidden;
        }
        .site-card__progress-bar {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--picker-copper) 0%, #f97316 100%);
            transition: width 0.2s ease-out;
        }
        .site-card__progress-bar.is-low { background: linear-gradient(90deg, #ea580c 0%, #f59e0b 100%); }
        .site-card__progress-bar.is-full { background: linear-gradient(90deg, #dc2626 0%, #ef4444 100%); }
        .site-card__status {
            display: inline-flex;
            align-items: center;
            align-self: flex-start;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            font-size: 0.6875rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .site-card__status--ok { background: #f0fdf4; color: #15803d; }
        .site-card__status--warn { background: #fff7ed; color: #c2410c; }
        .site-card__status--danger { background: #fef2f2; color: #b91c1c; }
        .site-card__status--neutral { background: #f8fafc; color: #475569; }
        .site-fav-btn {
            position: absolute;
            top: 0.65rem;
            right: 0.65rem;
            z-index: 2;
            width: 2rem;
            height: 2rem;
            padding: 0;
            border: 1px solid transparent;
            border-radius: 999px;
            background: #f8fafc;
            color: #94a3b8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s ease, background 0.15s ease, border-color 0.15s ease;
        }
        .site-fav-btn:hover {
            color: var(--picker-copper);
            background: #fff;
            border-color: #fdba74;
        }
        .site-fav-btn.is-favorite {
            color: #d97706;
            background: #fffbeb;
            border-color: #fcd34d;
        }
        .site-fav-btn:focus-visible {
            outline: 2px solid rgba(234, 88, 12, 0.45);
            outline-offset: 2px;
        }
        .site-fav-btn[disabled] { opacity: 0.6; cursor: wait; }
        .site-card__nav-busy {
            position: absolute;
            inset: 0;
            z-index: 3;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 0.45rem;
            background: rgba(255, 255, 255, 0.82);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }
        .site-card.is-navigating .site-card__nav-busy { display: flex; }
        .site-card__nav-spinner {
            width: 1.75rem;
            height: 1.75rem;
            border: 2px solid #e2e8f0;
            border-top-color: var(--picker-copper);
            border-radius: 50%;
            animation: siteCardNavSpin 0.7s linear infinite;
        }
        .site-card__nav-label {
            font-size: 0.8125rem;
            font-weight: 700;
            color: var(--picker-copper-dark);
        }
        @keyframes siteCardNavSpin { to { transform: rotate(360deg); } }
        .site-picker-grid.is-site-navigating .site-picker-card:not(.is-active-nav) {
            opacity: 0.55;
            pointer-events: none;
        }
        .site-picker-grid.is-site-navigating .site-picker-add { opacity: 0.55; pointer-events: none; }
        .site-picker-grid.is-site-navigating .site-fav-btn { pointer-events: none; opacity: 0.45; }
        .site-card--skeleton { pointer-events: none; }
        .site-card--skeleton:hover { transform: none; border-color: var(--picker-border); background: var(--picker-surface); }
        .site-skeleton-line,
        .site-skeleton-pill,
        .site-skeleton-icon {
            display: block;
            border-radius: 999px;
            background: linear-gradient(90deg, #eef2f7 0%, #f8fafc 45%, #eef2f7 90%);
            background-size: 200% 100%;
            animation: sitePickerSkeletonWave 1.35s ease-in-out infinite;
        }
        .site-skeleton-line { height: 0.72rem; }
        .site-skeleton-line.xs { width: 28%; }
        .site-skeleton-line.sm { width: 42%; }
        .site-skeleton-line.md { width: 58%; }
        .site-skeleton-line.lg { width: 72%; }
        .site-skeleton-box {
            display: block;
            min-height: 3.1rem;
            border-radius: 0.625rem;
            background: linear-gradient(90deg, #eef2f7 0%, #f8fafc 45%, #eef2f7 90%);
            background-size: 200% 100%;
            animation: sitePickerSkeletonWave 1.35s ease-in-out infinite;
        }
        .site-skeleton-panel {
            display: block;
            height: 2.25rem;
            margin-top: 0.5rem;
            border-radius: 0.625rem;
            background: linear-gradient(90deg, #eef2f7 0%, #f8fafc 45%, #eef2f7 90%);
            background-size: 200% 100%;
            animation: sitePickerSkeletonWave 1.35s ease-in-out infinite;
        }
        .site-skeleton-icon { width: 2.75rem; height: 2.75rem; border-radius: 0.625rem; flex-shrink: 0; }
        @keyframes sitePickerSkeletonWave {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        .site-picker-is-loading > .site-picker-card:not(.site-picker-add) { display: none; }
        .site-card--add {
            border: 2px dashed #cbd5e1;
            background: #fff;
            min-height: 11.75rem;
            cursor: pointer;
            transition: border-color 0.18s ease-out, background-color 0.18s ease-out, transform 0.18s ease-out;
        }
        .site-card--add:hover {
            border-color: var(--picker-copper);
            background: #fffaf5;
            transform: translateY(-2px);
        }
        .site-card-add-icon {
            width: 3.25rem;
            height: 3.25rem;
            border-radius: 50%;
            border: 2px solid #94a3b8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.65rem;
            color: #64748b;
            transition: border-color .18s ease-out, color .18s ease-out;
        }
        .site-card--add:hover .site-card-add-icon {
            border-color: var(--picker-copper);
            color: var(--picker-copper);
        }
        .picker-cat-row .btn-remove-cat { line-height: 1; }
        .picker-quick-bar {
            border: 1px solid var(--picker-border);
            border-radius: 0.875rem;
            background: var(--picker-surface);
            padding: 0.85rem 1rem;
        }
        .picker-quick-bar__label {
            font-size: 0.8125rem;
            font-weight: 700;
            color: var(--picker-muted);
            margin-bottom: 0.65rem;
        }
        .picker-quick-grid { --bs-gutter-x: 0.65rem; --bs-gutter-y: 0.65rem; }
        .picker-action-tile {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            width: 100%;
            min-height: 4.5rem;
            padding: 0.85rem 1rem;
            border-radius: 0.75rem;
            border: 1px solid var(--picker-border);
            background: var(--picker-surface);
            text-decoration: none;
            color: inherit;
            transition: transform 0.18s ease-out, border-color 0.18s ease-out, background-color 0.18s ease-out;
        }
        .picker-action-tile:hover {
            transform: translateY(-1px);
        }
        .picker-action-tile:focus-visible {
            outline: 2px solid var(--picker-copper);
            outline-offset: 2px;
        }
        .picker-action-tile__icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.625rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .picker-action-tile__body { flex: 1; min-width: 0; text-align: left; }
        .picker-action-tile__title {
            display: block;
            font-weight: 700;
            font-size: 0.9375rem;
            line-height: 1.35;
            color: var(--picker-ink);
            text-wrap: pretty;
        }
        .picker-action-tile__meta {
            display: block;
            margin-top: 0.1rem;
            font-size: 0.75rem;
            line-height: 1.35;
            color: var(--picker-muted);
        }
        .picker-action-tile__chevron {
            flex-shrink: 0;
            font-size: 0.95rem;
            color: #cbd5e1;
            transition: transform 0.18s ease-out, color 0.18s ease-out;
        }
        .picker-action-tile:hover .picker-action-tile__chevron {
            color: #94a3b8;
            transform: translateX(2px);
        }
        .picker-action-tile--primary .picker-action-tile__icon { background: #ffedd5; color: var(--picker-copper-dark); }
        .picker-action-tile--primary:hover { border-color: #fdba74; background: #fffaf5; }
        .picker-action-tile--success .picker-action-tile__icon { background: #dcfce7; color: #15803d; }
        .picker-action-tile--success:hover { border-color: #86efac; background: #fafff9; }
        .picker-create-modal .modal-content {
            border: 1px solid var(--picker-border);
            border-radius: 0.875rem;
            overflow: hidden;
        }
        .picker-create-modal__header {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
            padding: 1.15rem 1.25rem;
            background: #fffaf5;
            border-bottom: 1px solid #fed7aa;
        }
        .picker-create-modal__icon {
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 0.75rem;
            background: #ffedd5;
            color: var(--picker-copper-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .picker-create-modal__heading { flex: 1; min-width: 0; }
        .picker-create-modal__title {
            margin: 0;
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--picker-ink);
            text-wrap: balance;
        }
        .picker-create-modal__subtitle {
            margin: 0.2rem 0 0;
            font-size: 0.8125rem;
            line-height: 1.45;
            color: var(--picker-muted);
            text-wrap: pretty;
        }
        .picker-create-modal__close {
            margin: -0.15rem -0.35rem 0 0;
            opacity: 0.55;
        }
        .picker-create-modal__close:hover { opacity: 1; }
        .picker-create-modal__body {
            padding: 1.15rem 1.25rem 0.5rem;
            background: var(--picker-surface);
        }
        .picker-create-panel + .picker-create-panel {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--picker-border);
        }
        .picker-create-panel__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.85rem;
        }
        .picker-create-panel__title {
            margin: 0;
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--picker-ink);
        }
        .picker-create-panel__hint {
            margin: 0.15rem 0 0;
            font-size: 0.75rem;
            line-height: 1.4;
            color: var(--picker-muted);
        }
        .picker-create-field label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.35rem;
        }
        .picker-create-field .form-control {
            border-color: var(--picker-border);
            border-radius: 0.625rem;
            padding: 0.55rem 0.75rem;
        }
        .picker-create-field .form-control:focus {
            border-color: #fdba74;
            box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.12);
        }
        .picker-create-field .input-group-text {
            border-color: var(--picker-border);
            background: #f8fafc;
            color: var(--picker-muted);
            font-size: 0.8125rem;
        }
        .picker-create-field .form-text {
            font-size: 0.75rem;
            color: var(--picker-muted);
        }
        .picker-cat-table {
            border: 1px solid var(--picker-border);
            border-radius: 0.75rem;
            overflow: hidden;
            background: #fafbfc;
        }
        .picker-cat-table__head {
            display: grid;
            grid-template-columns: 1fr 7.5rem 2.25rem;
            gap: 0.5rem;
            padding: 0.55rem 0.75rem;
            background: #f1f5f9;
            border-bottom: 1px solid var(--picker-border);
            font-size: 0.6875rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            color: #64748b;
        }
        .picker-cat-row {
            display: grid;
            grid-template-columns: 1fr 7.5rem 2.25rem;
            gap: 0.5rem;
            align-items: center;
            padding: 0.55rem 0.75rem;
            background: var(--picker-surface);
            border-bottom: 1px solid var(--picker-border);
        }
        .picker-cat-row:last-child { border-bottom: 0; }
        .picker-cat-row .form-control {
            border-color: var(--picker-border);
            border-radius: 0.5rem;
            font-size: 0.875rem;
            padding: 0.45rem 0.65rem;
        }
        .picker-cat-row .form-control:focus {
            border-color: #fdba74;
            box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.1);
        }
        .picker-cat-row .input-group-text {
            border-color: var(--picker-border);
            background: #f8fafc;
            font-size: 0.8125rem;
            padding-inline: 0.5rem;
        }
        .picker-cat-remove {
            width: 2.25rem;
            height: 2.25rem;
            padding: 0;
            border-radius: 0.5rem;
            border: 1px solid var(--picker-border);
            background: var(--picker-surface);
            color: #94a3b8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s ease-out, border-color 0.15s ease-out, background-color 0.15s ease-out;
        }
        .picker-cat-remove:hover:not(:disabled) {
            color: #dc2626;
            border-color: #fecaca;
            background: #fef2f2;
        }
        .picker-cat-remove:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }
        .picker-cat-foot {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            margin-top: 0.65rem;
        }
        .picker-cat-rules {
            font-size: 0.75rem;
            line-height: 1.45;
            color: var(--picker-muted);
            max-width: 42ch;
        }
        .picker-cat-total {
            font-size: 0.8125rem;
            font-weight: 700;
            color: #334155;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            background: #f1f5f9;
            border: 1px solid var(--picker-border);
        }
        .picker-cat-total.is-over {
            color: #b91c1c;
            background: #fef2f2;
            border-color: #fecaca;
        }
        .picker-create-modal__footer {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: 0.5rem;
            padding: 0.85rem 1.25rem 1.15rem;
            background: #f8fafc;
            border-top: 1px solid var(--picker-border);
        }
        .picker-create-modal__footer .btn-cancel {
            border-radius: 999px;
            padding-inline: 1.1rem;
            font-weight: 600;
        }
        .picker-create-modal__footer .btn-submit {
            border-radius: 999px;
            padding-inline: 1.25rem;
            font-weight: 700;
            background: var(--picker-copper);
            border-color: var(--picker-copper);
            color: #fff;
        }
        .picker-create-modal__footer .btn-submit:hover {
            background: var(--picker-copper-dark);
            border-color: var(--picker-copper-dark);
            color: #fff;
        }
        .picker-create-modal__footer .btn-submit:focus-visible {
            box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.25);
        }
        @media (max-width: 575.98px) {
            .picker-cat-table__head { display: none; }
            .picker-cat-row {
                grid-template-columns: 1fr 2.25rem;
                grid-template-areas:
                    "name remove"
                    "pct pct";
                gap: 0.45rem;
                padding: 0.75rem;
            }
            .picker-cat-row__name { grid-area: name; }
            .picker-cat-row__pct { grid-area: pct; }
            .picker-cat-row__remove { grid-area: remove; align-self: start; }
        }
        @media (prefers-reduced-motion: reduce) {
            .site-card,
            .site-card--add,
            .picker-action-tile,
            .picker-action-tile__chevron,
            .site-skeleton-line,
            .site-skeleton-box,
            .site-skeleton-panel,
            .site-skeleton-icon {
                transition: none;
                animation: none;
            }
            .site-card:hover,
            .site-card--add:hover,
            .picker-action-tile:hover {
                transform: none;
            }
            .site-card__nav-spinner {
                animation: none;
                border-top-color: var(--picker-copper);
            }
            .picker-action-tile:hover .picker-action-tile__chevron {
                transform: none;
            }
        }
    </style>
</head>
<body class="tnc-app-body tnc-layout-list">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
<div class="container py-4 pb-5">
    <?php
    $flash = tnc_flash_from_query($_GET);
    if ($flash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'percent_sum') {
        $flash['message'] = 'รวม % หมวดเกิน 100% — กรุณาปรับสัดส่วน';
    }
    if ($flash !== null && !empty($_GET['deleted'])) {
        $flash['message'] = 'ลบไซต์เรียบร้อย';
    }
    tnc_render_flash($flash);
    ?>

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

    <?php
    $visibleSiteCount = 0;
    foreach ($sites as $siteRowCount) {
        if ((int) ($siteRowCount['id'] ?? 0) > 0) {
            ++$visibleSiteCount;
        }
    }
    ?>

    <div class="row site-picker-grid<?= $hasSites ? ' site-picker-is-loading' : '' ?>" id="sitePickerGrid"<?= $hasSites ? ' aria-busy="true"' : '' ?>>
        <?php if ($hasSites): ?>
            <?php for ($sk = 0; $sk < 6; $sk++): ?>
                <div class="col-12 col-md-6 col-lg-4 site-picker-skeleton" aria-hidden="true">
                    <div class="site-card site-card--skeleton h-100">
                        <div class="site-card-link">
                            <div class="site-card__row">
                                <span class="site-skeleton-icon"></span>
                                <div class="site-card__body flex-grow-1">
                                    <span class="site-skeleton-line lg d-block"></span>
                                    <div class="site-card__stats">
                                        <span class="site-skeleton-box"></span>
                                        <span class="site-skeleton-box"></span>
                                        <span class="site-skeleton-box"></span>
                                    </div>
                                    <span class="site-skeleton-panel d-block"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endfor; ?>
            <?php $siteSortIndex = 0; ?>
            <?php foreach ($sites as $site): ?>
                <?php
                $sid = (int) ($site['id'] ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                $isFavorite = !empty($favoriteSiteIdSet[$sid]);
                $summary = tnc_site_budget_site_summary($sid);
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
                     data-favorite="<?= $isFavorite ? '1' : '0' ?>"
                     data-sort-index="<?= (int) $siteSortIndex ?>">
                    <div class="site-card h-100<?= $isFavorite ? ' site-card--favorite' : '' ?>">
                        <button type="button"
                                class="site-fav-btn<?= $isFavorite ? ' is-favorite' : '' ?>"
                                data-site-id="<?= $sid ?>"
                                aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>"
                                aria-label="<?= $isFavorite ? 'นำออกจากรายการโปรด' : 'เพิ่มในรายการโปรด' ?>: <?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                title="<?= $isFavorite ? 'นำออกจากรายการโปรด' : 'เพิ่มในรายการโปรด' ?>">
                            <i class="bi <?= $isFavorite ? 'bi-star-fill' : 'bi-star' ?>" aria-hidden="true"></i>
                        </button>
                        <a class="site-card-link" href="<?= htmlspecialchars($hubBase . '?site_id=' . $sid, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="site-card__row">
                                <span class="site-card__mark" aria-hidden="true"><i class="bi bi-building"></i></span>
                                <div class="site-card__body">
                                    <h2 class="site-card__name"><?= htmlspecialchars((string) ($site['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
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
                                                    —
                                                <?php elseif ($remaining !== null): ?>
                                                    <?= tnc_site_budget_format_money($remaining) ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if ($usedPct !== null): ?>
                                        <div class="site-card__detail">
                                            <div class="site-card__progress" role="presentation" aria-hidden="true">
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
                <div class="alert alert-warning mb-0">ยังไม่มีไซต์งาน — ติดต่อผู้ดูแลระบบเพื่อเพิ่มไซต์</div>
            </div>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var pickerUrl = <?= json_encode($pickerUrl, JSON_UNESCAPED_SLASHES) ?>;
    var csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

    function reorderSiteCards() {
        var grid = document.getElementById('sitePickerGrid');
        if (!grid) {
            return;
        }
        var addCard = grid.querySelector('.site-picker-add');
        var cards = Array.prototype.slice.call(grid.querySelectorAll('.site-picker-card:not(.site-picker-add)'));
        cards.sort(function (a, b) {
            var af = a.getAttribute('data-favorite') === '1' ? 0 : 1;
            var bf = b.getAttribute('data-favorite') === '1' ? 0 : 1;
            if (af !== bf) {
                return af - bf;
            }
            return (parseInt(a.getAttribute('data-sort-index') || '0', 10) || 0)
                - (parseInt(b.getAttribute('data-sort-index') || '0', 10) || 0);
        });
        cards.forEach(function (card) {
            if (addCard) {
                grid.insertBefore(card, addCard);
            } else {
                grid.appendChild(card);
            }
        });
    }

    function setFavoriteUi(card, btn, isFavorite) {
        if (!card || !btn) {
            return;
        }
        card.setAttribute('data-favorite', isFavorite ? '1' : '0');
        btn.classList.toggle('is-favorite', isFavorite);
        btn.setAttribute('aria-pressed', isFavorite ? 'true' : 'false');
        btn.setAttribute('title', isFavorite ? 'นำออกจากรายการโปรด' : 'เพิ่มในรายการโปรด');
        var icon = btn.querySelector('i');
        if (icon) {
            icon.className = isFavorite ? 'bi bi-star-fill' : 'bi bi-star';
        }
        var siteCard = card.querySelector('.site-card');
        if (siteCard) {
            siteCard.classList.toggle('site-card--favorite', isFavorite);
        }
        var siteName = card.querySelector('.site-card__name');
        if (siteName) {
            btn.setAttribute('aria-label', (isFavorite ? 'นำออกจากรายการโปรด' : 'เพิ่มในรายการโปรด') + ': ' + siteName.textContent.trim());
        }
    }

    function beginSiteHubNavigation(link, event) {
        if (event.defaultPrevented || event.button !== 0) {
            return;
        }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        var href = link.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#') {
            return;
        }
        var card = link.closest('.site-card');
        var pickerCard = link.closest('.site-picker-card');
        var grid = document.getElementById('sitePickerGrid');
        var siteNameEl = link.querySelector('.site-card__name');
        var siteName = siteNameEl ? siteNameEl.textContent.trim() : 'ไซต์งาน';
        if (card && !card.classList.contains('is-navigating')) {
            card.classList.add('is-navigating');
            link.setAttribute('aria-busy', 'true');
        }
        if (pickerCard) {
            pickerCard.classList.add('is-active-nav');
        }
        if (grid) {
            grid.classList.add('is-site-navigating');
            grid.setAttribute('aria-busy', 'true');
        }
        if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.showWithMessage === 'function') {
            window.TncLoadingOverlay.showWithMessage(
                'กำลังเปิด ' + siteName + '…',
                'กรุณารอสักครู่ ระบบกำลังโหลดข้อมูลไซต์'
            );
        } else if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.show === 'function') {
            window.TncLoadingOverlay.show();
        }
    }

    document.querySelectorAll('.site-card-link[href]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            beginSiteHubNavigation(link, event);
        });
    });

    document.querySelectorAll('.site-fav-btn').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (btn.disabled) {
                return;
            }
            var siteId = btn.getAttribute('data-site-id') || '';
            if (!siteId) {
                return;
            }
            btn.disabled = true;
            fetch(pickerUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken
                },
                body: new URLSearchParams({
                    toggle_site_favorite: '1',
                    site_id: siteId,
                    _csrf: csrfToken
                }).toString()
            })
                .then(function (res) {
                    return res.json().then(function (data) {
                        if (!res.ok || !data || !data.ok) {
                            var err = (data && data.error) ? data.error : 'request_failed';
                            throw new Error(err);
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    var card = btn.closest('.site-picker-card');
                    setFavoriteUi(card, btn, !!data.favorite);
                    reorderSiteCards();
                })
                .catch(function () {})
                .finally(function () {
                    btn.disabled = false;
                });
        });
    });

    function revealSitePicker() {
        var grid = document.getElementById('sitePickerGrid');
        if (!grid || !grid.classList.contains('site-picker-is-loading')) {
            return;
        }
        grid.querySelectorAll('.site-picker-skeleton').forEach(function (el) {
            el.remove();
        });
        grid.classList.remove('site-picker-is-loading');
        grid.setAttribute('aria-busy', 'false');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            window.requestAnimationFrame(revealSitePicker);
        });
    } else {
        window.requestAnimationFrame(revealSitePicker);
    }
}());

<?php if ($canCreateSite): ?>
(function () {
    var catRows = document.getElementById('pickerCatRows');
    var addBtn = document.getElementById('pickerAddCatRow');
    var pctTotalEl = document.getElementById('pickerCatPctTotal');
    var modalEl = document.getElementById('sitePickerCreateModal');
    var nameInput = document.getElementById('picker_site_name');

    function parsePct(raw) {
        var s = String(raw || '').replace(/%/g, '').replace(/,/g, '').trim();
        if (s === '') return 0;
        var n = parseFloat(s);
        return isNaN(n) ? 0 : Math.max(0, n);
    }

    function updatePctTotal() {
        if (!pctTotalEl || !catRows) return;
        var sum = 0;
        catRows.querySelectorAll('input[name="category_budget_percent[]"]').forEach(function (input) {
            sum += parsePct(input.value);
        });
        sum = Math.round(sum * 100) / 100;
        pctTotalEl.textContent = 'รวม ' + sum + '% / 100%';
        pctTotalEl.classList.toggle('is-over', sum > 100.0001);
    }

    function syncRemoveButtons() {
        if (!catRows) return;
        var rows = catRows.querySelectorAll('.picker-cat-row');
        rows.forEach(function (row) {
            var btn = row.querySelector('.btn-remove-cat');
            if (!btn) return;
            var disabled = rows.length <= 1;
            btn.hidden = disabled;
            btn.disabled = disabled;
        });
    }

    function bindPctInputs(scope) {
        (scope || document).querySelectorAll('input[name="category_budget_percent[]"]').forEach(function (input) {
            if (input.dataset.pickerPctBound === '1') return;
            input.dataset.pickerPctBound = '1';
            input.addEventListener('input', updatePctTotal);
        });
    }

    function bindRemove(btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('.picker-cat-row');
            if (!row || !catRows) return;
            if (catRows.querySelectorAll('.picker-cat-row').length <= 1) return;
            row.remove();
            syncRemoveButtons();
            updatePctTotal();
        });
    }

    if (addBtn && catRows) {
        addBtn.addEventListener('click', function () {
            var first = catRows.querySelector('.picker-cat-row');
            if (!first) return;
            var clone = first.cloneNode(true);
            clone.querySelectorAll('input').forEach(function (input) {
                input.value = '';
                delete input.dataset.pickerPctBound;
            });
            var removeBtn = clone.querySelector('.btn-remove-cat');
            if (removeBtn) {
                removeBtn.hidden = false;
                removeBtn.disabled = false;
                bindRemove(removeBtn);
            }
            catRows.appendChild(clone);
            bindPctInputs(clone);
            syncRemoveButtons();
            updatePctTotal();
            var catNameInput = clone.querySelector('input[name="category_name[]"]');
            if (catNameInput) catNameInput.focus();
        });
        catRows.querySelectorAll('.btn-remove-cat').forEach(bindRemove);
        bindPctInputs(catRows);
        syncRemoveButtons();
        updatePctTotal();
    }

    if (modalEl) {
        modalEl.addEventListener('shown.bs.modal', function () {
            if (nameInput) nameInput.focus();
        });
    }

    <?php if ($openCreateModal): ?>
    if (modalEl && window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    <?php endif; ?>
}());
<?php endif; ?>
</script>
</body>
</html>
