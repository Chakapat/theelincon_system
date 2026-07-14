<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/site_budget.php';
require_once dirname(__DIR__, 2) . '/includes/site_cost_categories.php';
require_once dirname(__DIR__, 2) . '/includes/sites.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_flash.php';

if (!function_exists('tnc_site_hub_post_redirect')) {
    function tnc_site_hub_post_redirect(string $url): void
    {
        header('Location: ' . $url, true, 303);
        exit;
    }
}

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

@set_time_limit(120);

$hubUrl = app_path('pages/sites/site-hub.php?site_id=' . $siteId);
$pickerUrl = app_path('pages/sites/site-picker.php');
$siteName = trim((string) ($site['name'] ?? ''));
$canEditBudget = user_can('site.manage');
$canDeleteSite = user_is_admin_only_role();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_site_budget'])) {
    if (!$canEditBudget) {
        tnc_site_hub_post_redirect(app_path('index.php') . '?error=forbidden');
    }
    if (!csrf_verify_request()) {
        tnc_site_hub_post_redirect($hubUrl . '&error=csrf');
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $postSiteId = (int) ($_POST['site_id'] ?? 0);
    if ($postSiteId !== $siteId) {
        tnc_site_hub_post_redirect($hubUrl . '&error=invalid');
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
    tnc_site_hub_post_redirect($hubUrl . '&updated=1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_site_name'])) {
    if (!$canEditBudget) {
        tnc_site_hub_post_redirect(app_path('index.php') . '?error=forbidden');
    }
    if (!csrf_verify_request()) {
        tnc_site_hub_post_redirect($hubUrl . '&error=csrf&open_rename=1');
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $postSiteId = (int) ($_POST['site_id'] ?? 0);
    if ($postSiteId !== $siteId) {
        tnc_site_hub_post_redirect($hubUrl . '&error=invalid&open_rename=1');
    }
    $newName = trim((string) ($_POST['site_name'] ?? ''));
    $beforeSite = Db::rowByIdField('sites', $siteId);
    $saveResult = tnc_site_save_name($siteId, $newName);
    if (empty($saveResult['ok'])) {
        $code = (string) ($saveResult['error_code'] ?? 'invalid_name');
        tnc_site_hub_post_redirect($hubUrl . '&error=' . rawurlencode($code) . '&open_rename=1');
    }
    $afterSite = Db::rowByIdField('sites', $siteId);
    tnc_audit_log('update', 'site', (string) $siteId, $newName, [
        'source' => 'site-hub.php',
        'action' => 'save_site_name',
        'before' => $beforeSite,
        'after' => $afterSite,
    ]);
    tnc_site_hub_post_redirect($hubUrl . '&name_updated=1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_site_category'])) {
    if (!$canEditBudget) {
        tnc_site_hub_post_redirect(app_path('index.php') . '?error=forbidden');
    }
    if (!csrf_verify_request()) {
        tnc_site_hub_post_redirect($hubUrl . '&error=csrf&open_cat=1');
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $postSiteId = (int) ($_POST['category_site_id'] ?? 0);
    if ($postSiteId !== $siteId) {
        tnc_site_hub_post_redirect($hubUrl . '&error=invalid&open_cat=1');
    }
    $catId = (int) ($_POST['category_id'] ?? 0);
    if ($catId > 0 && !tnc_site_category_belongs_to_site($catId, $siteId)) {
        tnc_site_hub_post_redirect($hubUrl . '&error=cat_forbidden&open_cat=1');
    }
    $catName = trim((string) ($_POST['category_name'] ?? ''));
    $pctRaw = trim(str_replace('%', '', (string) ($_POST['category_budget_percent'] ?? '')));
    $catBudgetPercent = null;
    if ($pctRaw !== '') {
        $catBudgetPercent = round((float) str_replace([',', ' '], '', $pctRaw), 2);
        $catBudgetPercent = max(0.0, min(100.0, $catBudgetPercent));
    }
    if ($catName === '') {
        $catOpen = $catId > 0 ? '&open_cat=1&edit_cat=' . $catId : '&open_cat=1';
        tnc_site_hub_post_redirect($hubUrl . '&error=invalid_name' . $catOpen);
    }
    $catParentId = (int) ($_POST['category_parent_id'] ?? 0);
    if ($catId > 0 && $catParentId <= 0) {
        $catParentId = tnc_site_category_parent_id($catId);
    }
    if ($catParentId > 0) {
        $catBudgetPercent = null;
    }
    $savedId = tnc_site_category_save($catId, $siteId, $catName, 0, $catBudgetPercent, $catParentId);
    if (is_array($savedId) && isset($savedId['error']) && $savedId['error'] === 'percent_sum_exceeded') {
        $catOpen = $catId > 0 ? '&open_cat=1&edit_cat=' . $catId : '&open_cat=1';
        tnc_site_hub_post_redirect($hubUrl . '&error=percent_sum' . $catOpen);
    }
    if (is_array($savedId) && isset($savedId['error']) && in_array($savedId['error'], ['invalid_parent', 'has_children'], true)) {
        $catOpen = $catId > 0 ? '&open_cat=1&edit_cat=' . $catId : '&open_cat=1';
        tnc_site_hub_post_redirect($hubUrl . '&error=' . rawurlencode((string) $savedId['error']) . $catOpen);
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
                'parent_id' => $catParentId,
            ],
        ]);
        tnc_site_hub_post_redirect($hubUrl . '&cat_saved=1');
    }
    tnc_site_hub_post_redirect($hubUrl . '&error=invalid&open_cat=1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_site_category'])) {
    if (!$canEditBudget) {
        tnc_site_hub_post_redirect(app_path('index.php') . '?error=forbidden');
    }
    if (!csrf_verify_request()) {
        tnc_site_hub_post_redirect($hubUrl . '&error=csrf');
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $postSiteId = (int) ($_POST['category_site_id'] ?? 0);
    if ($postSiteId !== $siteId) {
        tnc_site_hub_post_redirect($hubUrl . '&error=invalid');
    }
    $catId = (int) ($_POST['category_id'] ?? 0);
    $deleteResult = tnc_site_category_delete_for_site($catId, $siteId);
    if (empty($deleteResult['ok'])) {
        $code = (string) ($deleteResult['error_code'] ?? 'invalid');
        $redirect = $hubUrl . '&error=' . rawurlencode($code);
        if ($code === 'in_use' && $catId > 0) {
            $redirect .= '&cat_ref=' . $catId;
        }
        tnc_site_hub_post_redirect($redirect);
    }
    $beforeCat = is_array($deleteResult['before'] ?? null) ? $deleteResult['before'] : [];
    tnc_audit_log('delete', 'site_cost_category', (string) $catId, trim((string) ($beforeCat['name'] ?? '')), [
        'source' => 'site-hub.php',
        'action' => 'delete_site_category',
        'before' => $beforeCat,
        'site_id' => $siteId,
    ]);
    tnc_site_hub_post_redirect($hubUrl . '&cat_deleted=1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remap_site_category_docs'])) {
    if (!$canEditBudget) {
        tnc_site_hub_post_redirect(app_path('index.php') . '?error=forbidden');
    }
    if (!csrf_verify_request()) {
        tnc_site_hub_post_redirect($hubUrl . '&error=csrf');
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $postSiteId = (int) ($_POST['category_site_id'] ?? 0);
    if ($postSiteId !== $siteId) {
        tnc_site_hub_post_redirect($hubUrl . '&error=invalid');
    }
    $sourceCategoryId = (int) ($_POST['remap_source_category_id'] ?? 0);
    $targetCategoryId = (int) ($_POST['remap_target_category_id'] ?? 0);
    if (!function_exists('tnc_site_category_remap_documents_for_site')) {
        tnc_site_hub_post_redirect($hubUrl . '&error=server_config');
    }
    $remapResult = tnc_site_category_remap_documents_for_site($siteId, $sourceCategoryId, $targetCategoryId);
    $remapError = (string) ($remapResult['error_code'] ?? '');
    if (in_array($remapError, ['invalid', 'same_category', 'forbidden', 'invalid_target', 'no_documents'], true)) {
        tnc_site_hub_post_redirect($hubUrl . '&error=' . rawurlencode($remapError));
    }
    $prUpdated = (int) ($remapResult['pr_updated'] ?? 0);
    $poUpdated = (int) ($remapResult['po_updated'] ?? 0);
    $failedCount = (int) ($remapResult['failed'] ?? 0);
    if ($prUpdated <= 0 && $poUpdated <= 0 && $failedCount <= 0) {
        tnc_site_hub_post_redirect($hubUrl . '&error=no_documents');
    }
    foreach ($remapResult['results'] ?? [] as $remapItem) {
        if (!is_array($remapItem) || ($remapItem['status'] ?? '') !== 'updated') {
            continue;
        }
        $docType = (string) ($remapItem['doc_type'] ?? '');
        $docId = (int) ($remapItem['doc_id'] ?? 0);
        if ($docId <= 0 || !in_array($docType, ['pr', 'po'], true)) {
            continue;
        }
        $entity = $docType === 'pr' ? 'purchase_request' : 'purchase_order';
        $docNo = trim((string) ($remapItem['doc_number'] ?? ''));
        tnc_audit_log('update', $entity, (string) $docId, $docNo !== '' ? $docNo : ('#' . $docId), [
            'source' => 'site-hub.php',
            'action' => 'remap_site_category_docs',
            'before' => is_array($remapItem['before'] ?? null) ? $remapItem['before'] : [],
            'after' => is_array($remapItem['after'] ?? null) ? $remapItem['after'] : [],
            'source_category_id' => $sourceCategoryId,
            'target_category_id' => $targetCategoryId,
            'site_id' => $siteId,
        ]);
    }
    if ($failedCount > 0) {
        tnc_site_hub_post_redirect($hubUrl . '&cat_remap_partial=1&prs=' . $prUpdated . '&pos=' . $poUpdated . '&failed=' . $failedCount);
    }
    tnc_site_hub_post_redirect($hubUrl . '&cat_remapped=1&prs=' . $prUpdated . '&pos=' . $poUpdated);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_site'])) {
    if (!$canDeleteSite) {
        tnc_site_hub_post_redirect(app_path('index.php') . '?error=forbidden');
    }
    if (!csrf_verify_request()) {
        tnc_site_hub_post_redirect($hubUrl . '&error=csrf&open_delete=1');
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    $postSiteId = (int) ($_POST['site_id'] ?? 0);
    if ($postSiteId !== $siteId) {
        tnc_site_hub_post_redirect($hubUrl . '&error=invalid&open_delete=1');
    }
    $confirmName = trim((string) ($_POST['confirm_site_name'] ?? ''));
    if ($confirmName !== $siteName) {
        tnc_site_hub_post_redirect($hubUrl . '&error=confirm_mismatch&open_delete=1');
    }
    $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));
    if ($confirmPassword === '') {
        tnc_site_hub_post_redirect($hubUrl . '&error=confirm_password_required&open_delete=1');
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
        tnc_site_hub_post_redirect($hubUrl . '&error=confirm_password_invalid&open_delete=1');
    }
    $beforeSite = Db::rowByIdField('sites', $siteId);
    $deleteResult = tnc_site_delete($siteId);
    if (empty($deleteResult['ok'])) {
        $code = (string) ($deleteResult['error_code'] ?? 'site_delete_failed');
        tnc_site_hub_post_redirect($hubUrl . '&error=' . rawurlencode($code) . '&open_delete=1');
    }
    tnc_audit_log('delete', 'site', (string) $siteId, $siteName, [
        'source' => 'site-hub.php',
        'action' => 'delete_site',
        'before' => $beforeSite,
        'nested' => $deleteResult['nested'] ?? [],
    ]);
    tnc_site_hub_post_redirect($pickerUrl . '?deleted=1');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tnc_site_hub_post_redirect($hubUrl);
}

$siteBudgetRaw = round((float) ($site['site_budget'] ?? 0), 2);

$hubRequiredFunctions = [
    'tnc_site_category_references_site_index',
    'tnc_site_category_list_references',
    'tnc_site_category_remap_documents_for_site',
    'tnc_site_category_is_valid_for_site',
];
$hubMissingRequiredFn = null;
foreach ($hubRequiredFunctions as $hubRequiredFn) {
    if (!function_exists($hubRequiredFn)) {
        $hubMissingRequiredFn = $hubRequiredFn;
        break;
    }
}
if ($hubMissingRequiredFn !== null) {
    error_log('site-hub.php: missing function ' . $hubMissingRequiredFn . ' — upload includes/site_cost_categories.php');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        tnc_site_hub_post_redirect($hubUrl . '&error=server_config');
    }
    if ((string) ($_GET['error'] ?? '') !== 'server_config') {
        header('Location: ' . $hubUrl . '&error=server_config');
        exit;
    }
}

if ($hubMissingRequiredFn === null) {
$summary = tnc_site_budget_site_summary_for_site_row($siteId, $site);
$hubCatRefIndex = tnc_site_category_references_site_index($siteId);
$hubCatDocsMap = [];
foreach ($summary['categories'] ?? [] as $hubCatSummaryRow) {
    $hubCatSummaryId = (int) ($hubCatSummaryRow['id'] ?? 0);
    if ($hubCatSummaryId > 0) {
        $hubCatDocsMap[$hubCatSummaryId] = tnc_site_category_list_references($hubCatSummaryId, $siteId);
    }
    foreach ($hubCatSummaryRow['children'] ?? [] as $hubCatChildRow) {
        $hubCatChildId = (int) ($hubCatChildRow['id'] ?? 0);
        if ($hubCatChildId > 0) {
            $hubCatDocsMap[$hubCatChildId] = tnc_site_category_list_references($hubCatChildId, $siteId);
        }
    }
}
$hubCatTableRows = [];
foreach ($summary['categories'] ?? [] as $hubParentCatRow) {
    $hubParentChildCount = count($hubParentCatRow['children'] ?? []);
    $hubCatTableRows[] = array_merge($hubParentCatRow, [
        'row_kind' => 'parent',
        'child_count' => $hubParentChildCount,
    ]);
    foreach ($hubParentCatRow['children'] ?? [] as $hubSubCatRow) {
        $hubCatTableRows[] = array_merge($hubSubCatRow, [
            'row_kind' => 'sub',
            'parent_name' => (string) ($hubParentCatRow['name'] ?? ''),
            'parent_id' => (int) ($hubParentCatRow['id'] ?? 0),
        ]);
    }
}
$hubParentCategoryOptions = [];
foreach ($summary['categories'] ?? [] as $hubParentOptRow) {
    $hubParentOptId = (int) ($hubParentOptRow['id'] ?? 0);
    if ($hubParentOptId <= 0) {
        continue;
    }
    $hubParentCategoryOptions[] = [
        'id' => $hubParentOptId,
        'name' => (string) ($hubParentOptRow['name'] ?? ''),
        'is_global' => !empty($hubParentOptRow['is_global']),
    ];
}
$hubRemapRefIndex = $hubCatRefIndex;
$hubCatById = [];
foreach ($hubCatTableRows as $hubCatLookupRow) {
    $hubCatLookupId = (int) ($hubCatLookupRow['id'] ?? 0);
    if ($hubCatLookupId > 0) {
        $hubCatById[$hubCatLookupId] = $hubCatLookupRow;
    }
}
$hubRemapSourceOptions = [];
$hubRemapSourceCatIds = array_unique(array_merge(
    array_keys($hubRemapRefIndex['prs_by_cat'] ?? []),
    array_keys($hubRemapRefIndex['pos_by_cat'] ?? [])
));
sort($hubRemapSourceCatIds);
foreach ($hubRemapSourceCatIds as $hubRemapSrcId) {
    $hubRemapSrcId = (int) $hubRemapSrcId;
    if ($hubRemapSrcId <= 0) {
        continue;
    }
    $hubRemapPrCount = count($hubRemapRefIndex['prs_by_cat'][$hubRemapSrcId] ?? []);
    $hubRemapPoCount = count($hubRemapRefIndex['pos_by_cat'][$hubRemapSrcId] ?? []);
    $hubRemapDocCount = $hubRemapPrCount + $hubRemapPoCount;
    if ($hubRemapDocCount <= 0) {
        continue;
    }
    $hubRemapCatRow = $hubCatById[$hubRemapSrcId] ?? null;
    $hubRemapIsSub = is_array($hubRemapCatRow) && (($hubRemapCatRow['row_kind'] ?? '') === 'sub');
    $hubRemapSourceOptions[] = [
        'id' => $hubRemapSrcId,
        'name' => is_array($hubRemapCatRow) ? (string) ($hubRemapCatRow['name'] ?? '') : tnc_site_category_display_name($hubRemapSrcId),
        'is_sub' => $hubRemapIsSub,
        'parent_id' => $hubRemapIsSub ? (int) ($hubRemapCatRow['parent_id'] ?? 0) : 0,
        'parent_name' => $hubRemapIsSub ? (string) ($hubRemapCatRow['parent_name'] ?? '') : '',
        'is_global' => is_array($hubRemapCatRow) && !empty($hubRemapCatRow['is_global']),
        'doc_count' => $hubRemapDocCount,
        'pr_count' => $hubRemapPrCount,
        'po_count' => $hubRemapPoCount,
    ];
}
$hubRemapTargetOptions = [];
foreach (tnc_site_categories_for_site($siteId) as $hubRemapTargetRow) {
    $hubRemapTargetId = (int) ($hubRemapTargetRow['id'] ?? 0);
    if ($hubRemapTargetId <= 0 || !tnc_site_category_is_selectable($hubRemapTargetId)) {
        continue;
    }
    $hubRemapTargetParentId = (int) ($hubRemapTargetRow['parent_id'] ?? 0);
    $hubRemapTargetOptions[] = [
        'id' => $hubRemapTargetId,
        'name' => tnc_site_category_display_name($hubRemapTargetId),
        'is_sub' => $hubRemapTargetParentId > 0,
        'parent_id' => $hubRemapTargetParentId,
        'parent_name' => $hubRemapTargetParentId > 0 ? tnc_site_category_name($hubRemapTargetParentId) : '',
        'is_global' => (int) ($hubRemapTargetRow['site_id'] ?? 0) === 0,
    ];
}
$hubCanRemapCategories = $canEditBudget && $hubRemapSourceOptions !== [] && $hubRemapTargetOptions !== [];
$catPercentUsed = tnc_site_category_percent_sum($siteId);
$catPercentRoom = round(max(0.0, 100.0 - $catPercentUsed), 2);
$hubCatPercentRoomById = [];
$hubParentPcts = [];
foreach ($summary['categories'] ?? [] as $hubPctParentRow) {
    $hubPctParentId = (int) ($hubPctParentRow['id'] ?? 0);
    if ($hubPctParentId <= 0) {
        continue;
    }
    $hubPctVal = $hubPctParentRow['budget_percent'] ?? null;
    $hubParentPcts[$hubPctParentId] = ($hubPctVal !== null && (float) $hubPctVal > 0.0)
        ? round((float) $hubPctVal, 2)
        : 0.0;
}
$hubParentPctTotal = array_sum($hubParentPcts);
foreach ($hubParentPcts as $hubPctParentId => $hubPctAmount) {
    $hubCatPercentRoomById[$hubPctParentId] = round(max(0.0, 100.0 - ($hubParentPctTotal - $hubPctAmount)), 2);
}
$openCatEditId = isset($_GET['edit_cat']) ? (int) $_GET['edit_cat'] : 0;
$openCatModal = !empty($_GET['open_cat']) || $openCatEditId > 0 || (isset($_GET['error']) && in_array((string) $_GET['error'], ['percent_sum', 'invalid_name'], true));
$openDeleteModal = !empty($_GET['open_delete']) || (isset($_GET['error']) && in_array((string) $_GET['error'], ['confirm_mismatch', 'site_delete_failed', 'confirm_password_required', 'confirm_password_invalid'], true));
$openRenameModal = !empty($_GET['open_rename']);
$catRefId = isset($_GET['cat_ref']) ? (int) $_GET['cat_ref'] : 0;
$catRefBlock = null;
$openCatRefModal = false;
if ((string) ($_GET['error'] ?? '') === 'in_use' && $catRefId > 0 && $hubMissingRequiredFn === null) {
    $catRefBlock = tnc_site_category_list_references($catRefId, $siteId);
    if ((int) ($catRefBlock['total'] ?? 0) > 0) {
        $openCatRefModal = true;
    }
}
} else {
    $hubBudgetLimit = $siteBudgetRaw;
    $summary = [
        'categories' => [],
        'used' => 0.0,
        'limit' => $hubBudgetLimit > 0.0 ? $hubBudgetLimit : null,
        'remaining' => null,
        'unlimited' => $hubBudgetLimit <= 0.0,
        'exhausted' => false,
        'low' => false,
    ];
    $hubCatRefIndex = ['prs_by_cat' => [], 'pos_by_cat' => []];
    $hubCatDocsMap = [];
    $hubCatTableRows = [];
    $hubParentCategoryOptions = [];
    $hubRemapRefIndex = $hubCatRefIndex;
    $hubCatById = [];
    $hubRemapSourceOptions = [];
    $hubRemapTargetOptions = [];
    $hubCanRemapCategories = false;
    $catPercentUsed = 0.0;
    $catPercentRoom = 100.0;
    $hubCatPercentRoomById = [];
    $openCatEditId = isset($_GET['edit_cat']) ? (int) $_GET['edit_cat'] : 0;
    $openCatModal = !empty($_GET['open_cat']) || $openCatEditId > 0;
    $openDeleteModal = !empty($_GET['open_delete']);
    $openRenameModal = !empty($_GET['open_rename']);
    $catRefId = isset($_GET['cat_ref']) ? (int) $_GET['cat_ref'] : 0;
    $catRefBlock = null;
    $openCatRefModal = false;
}
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
    <?php
    require_once dirname(__DIR__, 2) . '/includes/tnc_ops_head.php';
    tnc_ops_head([
        'title' => $siteName . ' | Site Hub',
        'site_hub' => true,
        'include_ops_ui' => false,
        'sarabun_weights' => '400;600;700',
    ]);
    ?>
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
            <?php
            require_once dirname(__DIR__, 2) . '/includes/tnc_ui.php';
            echo tnc_ui_back_previous_button(['fallback' => $pickerUrl]);
            ?>
            <a href="<?= htmlspecialchars($pickerUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill">
                <i class="bi bi-geo-alt me-1"></i>เปลี่ยนไซต์
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
    if ($hubFlash === null && !empty($_GET['cat_deleted'])) {
        $hubFlash = ['type' => 'success', 'message' => 'ลบหมวดค่าใช้จ่ายแล้ว', 'audio' => 'update'];
    }
    if ($hubFlash === null && !empty($_GET['cat_remapped'])) {
        $remapPrs = max(0, (int) ($_GET['prs'] ?? 0));
        $remapPos = max(0, (int) ($_GET['pos'] ?? 0));
        $hubFlash = [
            'type' => 'success',
            'message' => 'เปลี่ยนหมวดในเอกสารแล้ว PR ' . number_format($remapPrs) . ' · PO ' . number_format($remapPos),
            'audio' => 'update',
        ];
    }
    if ($hubFlash === null && !empty($_GET['cat_remap_partial'])) {
        $remapPrs = max(0, (int) ($_GET['prs'] ?? 0));
        $remapPos = max(0, (int) ($_GET['pos'] ?? 0));
        $remapFailed = max(0, (int) ($_GET['failed'] ?? 0));
        $hubFlash = [
            'type' => 'warning',
            'message' => 'เปลี่ยนหมวดสำเร็จ PR ' . number_format($remapPrs) . ' · PO ' . number_format($remapPos) . ' · ไม่สำเร็จ ' . number_format($remapFailed) . ' รายการ',
            'audio' => 'update',
        ];
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
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'cat_forbidden') {
        $hubFlash['message'] = 'ไม่สามารถแก้ไขหมวดกลางจาก Site Hub ได้';
    }
    if ($openCatRefModal) {
        $hubFlash = null;
    } elseif ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'in_use') {
        $hubFlash['message'] = 'ลบหมวดไม่ได้ — มี PR/PO อ้างอิงหมวดนี้อยู่';
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'has_children') {
        $hubFlash['message'] = 'ลบหมวดไม่ได้ — กรุณาลบหมวดย่อยภายใต้หมวดนี้ก่อน';
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'no_selection') {
        $hubFlash['message'] = 'กรุณาเลือกหมวดต้นทางและปลายทาง';
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'same_category') {
        $hubFlash['message'] = 'หมวดต้นทางและปลายทางต้องไม่ใช่หมวดเดียวกัน';
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'invalid_target') {
        $hubFlash['message'] = 'หมวดปลายทางไม่ถูกต้อง — กรุณาเลือกหมวดที่ใช้บน PR/PO ได้';
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'no_documents') {
        $hubFlash['type'] = 'warning';
        $hubFlash['message'] = 'ไม่พบ PR/PO ที่ใช้หมวดต้นทางนี้';
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'no_change') {
        $hubFlash['type'] = 'warning';
        $hubFlash['message'] = 'หมวดที่เลือกอยู่ใต้หมวดหลักนี้อยู่แล้ว — ไม่มีการเปลี่ยนแปลง';
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'cycle') {
        $hubFlash['message'] = 'ย้ายหมวดไม่ได้ — ไม่สามารถย้ายไปอยู่ใต้หมวดย่อยของตัวเอง';
    }
    if ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'forbidden' && empty($_GET['open_delete'])) {
        $hubFlash['message'] = 'ไม่สามารถลบหมวดนี้ได้';
    }
    if ($hubFlash === null && !empty($_GET['error']) && (string) $_GET['error'] === 'server_config') {
        $hubFlash = [
            'type' => 'danger',
            'message' => 'ระบบยังไม่พร้อม — กรุณาอัปโหลด includes/site_cost_categories.php ให้ครบ แล้วเปิด site-hub-check.php ตรวจสอบ',
        ];
    } elseif ($hubFlash !== null && !empty($_GET['error']) && (string) $_GET['error'] === 'server_config') {
        $hubFlash['type'] = 'danger';
        $hubFlash['message'] = 'ระบบยังไม่พร้อม — กรุณาอัปโหลด includes/site_cost_categories.php ให้ครบ แล้วเปิด site-hub-check.php ตรวจสอบ';
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
                    <div class="small text-muted mb-1">หมวดค่าใช้จ่าย</div>
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
        <div class="hub-cat-section-head">
            <div>
                <h3 class="hub-cat-section-title">หมวดค่าใช้จ่าย</h3>
                <p class="hub-cat-section-meta">จัดสรรแล้ว <?= htmlspecialchars(number_format($catPercentUsed, 2), ENT_QUOTES, 'UTF-8') ?>% · เหลือ <?= htmlspecialchars(number_format($catPercentRoom, 2), ENT_QUOTES, 'UTF-8') ?>%</p>
            </div>
            <?php if ($canEditBudget): ?>
            <div class="hub-cat-section-actions d-flex flex-wrap gap-2 align-items-center">
                <?php if ($hubCanRemapCategories): ?>
                <button type="button"
                        class="btn btn-sm btn-outline-secondary rounded-pill hub-cat-remap-open"
                        id="hubCatRemapOpenBtn"
                        data-bs-toggle="modal"
                        data-bs-target="#hubCategoryRemapModal">
                    <i class="bi bi-arrow-left-right me-1"></i>ต้องการเปลี่ยนหมวดหมู่
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-warning rounded-pill hub-cat-open-add" data-bs-toggle="modal" data-bs-target="#hubCategoryModal">
                    <i class="bi bi-plus-lg me-1"></i>เพิ่มหมวด
                </button>
            </div>
            <?php endif; ?>
        </div>
        <div class="hub-cat-table-shell table-responsive">
            <table class="table table-sm align-middle mb-0 hub-cat-table">
                <colgroup>
                    <col class="hub-cat-col-name">
                    <col class="hub-cat-col-status">
                    <col class="hub-cat-col-budget">
                    <col class="hub-cat-col-used">
                    <col class="hub-cat-col-remain">
                    <col class="hub-cat-col-docs">
                    <?php if ($canEditBudget): ?><col class="hub-cat-col-actions"><?php endif; ?>
                </colgroup>
                <thead>
                    <tr>
                        <th class="hub-cat-th-name">หมวด</th>
                        <th class="hub-cat-th-status">สถานะ</th>
                        <th class="hub-cat-th-budget text-end">งบหมวด</th>
                        <th class="hub-cat-th-money text-end">ยอดซื้อ</th>
                        <th class="hub-cat-th-money text-end">คงเหลือ</th>
                        <th class="hub-cat-th-docs">เอกสาร</th>
                        <?php if ($canEditBudget): ?><th class="hub-cat-th-actions text-end">จัดการ</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hubCatTableRows as $cat): ?>
                        <?php
                        $catIdRow = (int) ($cat['id'] ?? 0);
                        $catIsSub = (($cat['row_kind'] ?? '') === 'sub');
                        $catCanManage = !empty($cat['can_manage']);
                        $catIsGlobal = !empty($cat['is_global']);
                        $catPctVal = $catIsSub ? null : ($cat['budget_percent'] ?? null);
                        $catPctInput = ($catPctVal === null) ? '' : rtrim(rtrim(number_format((float) $catPctVal, 2, '.', ''), '0'), '.');
                        $catEditPercentRoom = $catIsSub ? 0 : ($hubCatPercentRoomById[$catIdRow] ?? $catPercentRoom);
                        $catDocs = $hubCatDocsMap[$catIdRow] ?? ['prs' => [], 'pos' => [], 'total' => 0];
                        $catDocTotal = (int) ($catDocs['total'] ?? 0);
                        $catParentIdRow = $catIsSub ? (int) ($cat['parent_id'] ?? 0) : 0;
                        $catHasChildren = !$catIsSub && (int) ($cat['child_count'] ?? 0) > 0;
                        $catUsedVal = (float) ($cat['used'] ?? 0);
                        $catLimitVal = $catIsSub ? null : ($cat['limit'] ?? null);
                        $catProgressPct = 0.0;
                        $catProgressClass = '';
                        if (!$catIsSub && $catLimitVal !== null && (float) $catLimitVal > 0.0001) {
                            $catProgressPct = min(100.0, round($catUsedVal / (float) $catLimitVal * 100, 1));
                            if (!empty($cat['over_budget']) || ($cat['remaining'] !== null && $cat['remaining'] <= 0.0001)) {
                                $catProgressClass = 'is-full';
                            } elseif (!empty($cat['low'])) {
                                $catProgressClass = 'is-low';
                            }
                        }
                        if ($catIsSub) {
                            $catStatusClass = '';
                            $catStatusText = '';
                        } elseif (!empty($cat['over_budget'])) {
                            $catStatusClass = 'hub-cat-status--danger';
                            $catStatusText = 'เกินงบ';
                        } elseif ($cat['remaining'] !== null && $cat['remaining'] <= 0.0001) {
                            $catStatusClass = 'hub-cat-status--danger';
                            $catStatusText = 'หมดวงเงิน';
                        } elseif (!empty($cat['low'])) {
                            $catStatusClass = 'hub-cat-status--warn';
                            $catStatusText = 'เหลือน้อย';
                        } elseif (!empty($cat['unlimited'])) {
                            $catStatusClass = 'hub-cat-status--muted';
                            $catStatusText = 'ไม่จำกัด';
                        } elseif ($catPctVal === 0.0 || $catPctVal === 0) {
                            $catStatusClass = 'hub-cat-status--danger';
                            $catStatusText = 'งบหมด';
                        } else {
                            $catStatusClass = 'hub-cat-status--ok';
                            $catStatusText = 'ปกติ';
                        }
                        ?>
                        <tr class="<?= $catIsSub ? 'hub-cat-sub-row hub-cat-sub-of-' . $catParentIdRow . ' hub-cat-sub-row--hidden' : 'hub-cat-parent-row' ?><?= $catHasChildren ? ' hub-cat-parent-row--branch' : '' ?><?= !$catIsSub && (!empty($cat['over_budget']) || !empty($cat['low']) || ($cat['remaining'] !== null && $cat['remaining'] <= 0.0001)) ? ' cat-low' : '' ?>">
                            <td class="hub-cat-td-name" data-label="หมวด">
                                <div class="hub-cat-name-cell">
                                    <?php if ($catHasChildren): ?>
                                    <button type="button"
                                            class="hub-cat-sub-toggle"
                                            data-hub-cat-sub-target=".hub-cat-sub-of-<?= $catIdRow ?>"
                                            aria-expanded="false"
                                            title="แสดง/ซ่อนหมวดย่อย"
                                            aria-label="แสดงหมวดย่อย <?= (int) ($cat['child_count'] ?? 0) ?> รายการ">
                                        <i class="bi bi-chevron-right hub-cat-sub-toggle__icon" aria-hidden="true"></i>
                                    </button>
                                    <?php elseif ($catIsSub): ?>
                                    <span class="hub-cat-sub-indent" aria-hidden="true"></span>
                                    <?php else: ?>
                                    <span class="hub-cat-sub-toggle-spacer" aria-hidden="true"></span>
                                    <?php endif; ?>
                                    <div class="hub-cat-name-cell__text<?= $catIsSub ? ' hub-cat-name-cell__text--sub' : '' ?>">
                                        <span class="hub-cat-name-cell__title"><?= htmlspecialchars((string) ($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!$catIsSub && $catIsGlobal): ?>
                                            <span class="badge bg-secondary-subtle text-secondary ms-1">หมวดกลาง</span>
                                        <?php endif; ?>
                                        <?php if ($catHasChildren): ?>
                                            <span class="hub-cat-name-cell__hint"><?= number_format((int) ($cat['child_count'] ?? 0)) ?> หมวดย่อย</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="hub-cat-td-status" data-label="สถานะ">
                                <?php if ($catStatusText !== ''): ?>
                                <span class="hub-cat-status <?= $catStatusClass ?>"><?= htmlspecialchars($catStatusText, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                <span class="hub-cat-status-placeholder" aria-hidden="true">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end hub-cat-td-budget" data-label="งบหมวด">
                                <div class="hub-cat-budget-cell">
                                <?php if ($catIsSub): ?>
                                    <span class="hub-cat-budget-sub-note">ใช้งบหมวดหลัก</span>
                                <?php elseif (!empty($cat['unlimited'])): ?>
                                    <span class="hub-cat-budget-sub-note">ไม่จำกัดหมวด</span>
                                <?php elseif ($catPctVal === 0.0 || $catPctVal === 0): ?>
                                    <span class="hub-cat-budget-pct text-danger">0%</span>
                                    <div class="hub-cat-budget-limit">งบหมด</div>
                                <?php else: ?>
                                    <span class="hub-cat-budget-pct"><?= htmlspecialchars((string) $catPctVal, ENT_QUOTES, 'UTF-8') ?>%</span>
                                    <div class="hub-cat-budget-limit"><?= tnc_site_budget_format_money($catLimitVal) ?></div>
                                    <?php if ($catLimitVal !== null && (float) $catLimitVal > 0.0001): ?>
                                    <div class="hub-cat-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= htmlspecialchars((string) $catProgressPct, ENT_QUOTES, 'UTF-8') ?>" aria-label="ใช้งบ <?= htmlspecialchars((string) $catProgressPct, ENT_QUOTES, 'UTF-8') ?>%">
                                        <span class="hub-cat-progress__bar <?= htmlspecialchars($catProgressClass, ENT_QUOTES, 'UTF-8') ?>" style="width: <?= htmlspecialchars((string) $catProgressPct, ENT_QUOTES, 'UTF-8') ?>%;"></span>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-end hub-cat-money" data-label="ยอดซื้อ"><?= tnc_site_budget_format_money($catUsedVal) ?></td>
                            <td class="text-end hub-cat-td-remain" data-label="คงเหลือ">
                                <?php if ($catIsSub): ?>
                                    <span class="text-muted">—</span>
                                <?php elseif ($cat['remaining'] !== null): ?>
                                    <span class="<?= ($cat['remaining'] <= 0.0001) ? 'text-danger' : '' ?>">
                                        <?= tnc_site_budget_format_money($cat['remaining']) ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="hub-cat-td-docs" data-label="เอกสาร">
                                <?php if ($catDocTotal > 0): ?>
                                <button type="button"
                                        class="btn btn-link p-0 hub-cat-docs-open"
                                        data-cat-id="<?= $catIdRow ?>"
                                        data-cat-name="<?= htmlspecialchars((string) ($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= number_format($catDocTotal) ?> รายการ
                                </button>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($canEditBudget): ?>
                            <td class="text-end hub-cat-td-actions" data-label="จัดการ">
                                <?php if ($catCanManage): ?>
                                <div class="hub-cat-actions">
                                    <?php if (!$catIsSub): ?>
                                    <button type="button"
                                            class="btn btn-outline-warning btn-sm hub-cat-action-btn hub-cat-open-add-sub"
                                            data-bs-toggle="modal"
                                            data-bs-target="#hubCategoryModal"
                                            data-parent-id="<?= $catIdRow ?>"
                                            data-parent-name="<?= htmlspecialchars((string) ($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            title="เพิ่มหมวดย่อย"
                                            aria-label="เพิ่มหมวดย่อย <?= htmlspecialchars((string) ($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                        <span class="hub-cat-action-btn__label">หมวดย่อย</span>
                                    </button>
                                    <?php endif; ?>
                                    <button type="button"
                                            class="btn btn-outline-secondary btn-sm hub-cat-action-btn hub-cat-open-edit"
                                            data-bs-toggle="modal"
                                            data-bs-target="#hubCategoryModal"
                                            data-cat-id="<?= $catIdRow ?>"
                                            data-cat-name="<?= htmlspecialchars((string) ($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-cat-percent="<?= htmlspecialchars($catPctInput, ENT_QUOTES, 'UTF-8') ?>"
                                            data-cat-percent-room="<?= htmlspecialchars(number_format($catEditPercentRoom, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-cat-parent-id="<?= $catParentIdRow ?>"
                                            data-cat-is-sub="<?= $catIsSub ? '1' : '0' ?>"
                                            title="แก้ไขหมวด"
                                            aria-label="แก้ไข <?= htmlspecialchars((string) ($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="hub-cat-action-btn__label">แก้ไข</span>
                                    </button>
                                    <form method="post"
                                          class="hub-cat-action-form"
                                          action="<?= htmlspecialchars($hubUrl, ENT_QUOTES, 'UTF-8') ?>"
                                          onsubmit="return confirm(<?= json_encode('ลบหมวด «' . (string) ($cat['name'] ?? '') . '» ถาวร?', JSON_UNESCAPED_UNICODE) ?>);">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="delete_site_category" value="1">
                                        <input type="hidden" name="category_site_id" value="<?= $siteId ?>">
                                        <input type="hidden" name="category_id" value="<?= $catIdRow ?>">
                                        <button type="submit"
                                                class="btn btn-outline-danger btn-sm hub-cat-action-btn"
                                                title="ลบหมวด"
                                                aria-label="ลบ <?= htmlspecialchars((string) ($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                            <span class="hub-cat-action-btn__label">ลบ</span>
                                        </button>
                                    </form>
                                </div>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
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
                    <input type="hidden" name="category_parent_id" id="hub_category_parent_id" value="0">
                    <div class="modal-header">
                        <h5 class="modal-title" id="hubCategoryModalLabel"><i class="bi bi-tag-fill me-2 text-warning"></i><span id="hubCategoryModalTitleText">เพิ่มหมวดค่าใช้จ่าย</span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3 d-none" id="hub_category_parent_wrap">
                            <label for="hub_category_parent_select" class="form-label">หมวดหลัก</label>
                            <select class="form-select" id="hub_category_parent_select" disabled>
                                <option value="">— เลือกหมวดหลัก —</option>
                                <?php foreach ($hubParentCategoryOptions as $hubParentOpt): ?>
                                    <option value="<?= (int) ($hubParentOpt['id'] ?? 0) ?>">
                                        <?= htmlspecialchars((string) ($hubParentOpt['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?= !empty($hubParentOpt['is_global']) ? ' (หมวดกลาง)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">หมวดย่อยใช้งบร่วมกับหมวดหลักที่เลือก</div>
                        </div>
                        <div class="mb-3">
                            <label for="hub_category_name" class="form-label"><span id="hub_category_name_label">ชื่อหมวด</span> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="hub_category_name" name="category_name" maxlength="150" required autocomplete="off">
                        </div>
                        <div class="mb-2 hub-cat-budget-wrap" id="hub_category_budget_wrap">
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

    <?php if ($hubCanRemapCategories): ?>
    <div class="modal fade" id="hubCategoryRemapModal" tabindex="-1" aria-labelledby="hubCategoryRemapModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post" action="<?= htmlspecialchars($hubUrl, ENT_QUOTES, 'UTF-8') ?>" id="hubCategoryRemapForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="remap_site_category_docs" value="1">
                    <input type="hidden" name="category_site_id" value="<?= $siteId ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="hubCategoryRemapModalLabel">
                            <i class="bi bi-arrow-left-right me-2 text-warning"></i>เปลี่ยนหมวดใน PR/PO
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small hub-cat-move-intro">เลือกหมวดเดิมที่ใช้ในเอกสารทางซ้าย แล้วเลือกหมวดใหม่ทางขวา — ระบบจะอัปเดต PR/PO ทั้งหมดที่ใช้หมวดเดิมให้เป็นหมวดใหม่ (ไม่ได้ย้ายโครงสร้างหมวดในระบบ)</p>
                        <div class="hub-cat-move-panels">
                            <div class="hub-cat-move-panel hub-cat-move-panel--source">
                                <label for="hubCatRemapSourceSelect" class="hub-cat-move-panel__label">หมวดเดิมใน PR/PO</label>
                                <select class="form-select hub-cat-move-panel__select" name="remap_source_category_id" id="hubCatRemapSourceSelect" required>
                                    <option value="">— เลือกหมวดเดิม —</option>
                                    <?php foreach ($hubRemapSourceOptions as $hubRemapSrc): ?>
                                        <?php
                                        $hubRemapSrcId = (int) ($hubRemapSrc['id'] ?? 0);
                                        $hubRemapSrcLabel = (string) ($hubRemapSrc['name'] ?? '');
                                        if (!empty($hubRemapSrc['is_sub']) && !empty($hubRemapSrc['parent_name'])) {
                                            $hubRemapSrcLabel .= ' · จาก ' . (string) $hubRemapSrc['parent_name'];
                                        } elseif (empty($hubRemapSrc['is_sub'])) {
                                            $hubRemapSrcLabel .= ' · หมวดหลัก';
                                        }
                                        $hubRemapDocCount = (int) ($hubRemapSrc['doc_count'] ?? 0);
                                        if ($hubRemapDocCount > 0) {
                                            $hubRemapSrcLabel .= ' · ' . number_format($hubRemapDocCount) . ' เอกสาร';
                                        }
                                        if (!empty($hubRemapSrc['is_global'])) {
                                            $hubRemapSrcLabel .= ' (หมวดกลาง)';
                                        }
                                        ?>
                                        <option value="<?= $hubRemapSrcId ?>"
                                                data-doc-count="<?= $hubRemapDocCount ?>"
                                                data-pr-count="<?= (int) ($hubRemapSrc['pr_count'] ?? 0) ?>"
                                                data-po-count="<?= (int) ($hubRemapSrc['po_count'] ?? 0) ?>">
                                            <?= htmlspecialchars($hubRemapSrcLabel, ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="hub-cat-move-panel__hint" id="hubCatRemapSourceHint">แสดงเฉพาะหมวดที่มี PR/PO อ้างอิงอยู่</div>
                            </div>
                            <div class="hub-cat-move-arrow" aria-hidden="true">
                                <i class="bi bi-arrow-right"></i>
                            </div>
                            <div class="hub-cat-move-panel hub-cat-move-panel--target">
                                <label for="hubCatRemapTargetSelect" class="hub-cat-move-panel__label">เปลี่ยนเป็นหมวดนี้</label>
                                <select class="form-select hub-cat-move-panel__select" name="remap_target_category_id" id="hubCatRemapTargetSelect" required>
                                    <option value="">— เลือกหมวดใหม่ —</option>
                                    <?php foreach ($hubRemapTargetOptions as $hubRemapTarget): ?>
                                        <?php
                                        $hubRemapTargetId = (int) ($hubRemapTarget['id'] ?? 0);
                                        $hubRemapTargetLabel = (string) ($hubRemapTarget['name'] ?? '');
                                        if (!empty($hubRemapTarget['is_global'])) {
                                            $hubRemapTargetLabel .= ' (หมวดกลาง)';
                                        }
                                        ?>
                                        <option value="<?= $hubRemapTargetId ?>">
                                            <?= htmlspecialchars($hubRemapTargetLabel, ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="hub-cat-move-panel__hint">เลือกหมวดที่ใช้บน PR/PO ได้ (หมวดย่อย หรือหมวดหลักที่ไม่มีหมวดย่อย)</div>
                            </div>
                        </div>
                        <div class="alert alert-warning py-2 px-3 small mb-0 mt-3 d-none" id="hubCatRemapWarn" role="status"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-warning rounded-pill" id="hubCatRemapSubmitBtn" disabled>
                            <i class="bi bi-check-lg me-1"></i>ยืนยันเปลี่ยนหมวดในเอกสาร
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($summary['categories'])): ?>
    <div class="modal fade hub-cat-ref-modal" id="hubCatDocsModal" tabindex="-1" aria-labelledby="hubCatDocsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header hub-cat-ref-modal__header">
                    <h5 class="modal-title" id="hubCatDocsModalLabel">
                        <i class="bi bi-journal-text text-warning me-2"></i>เอกสารในหมวด
                        <span class="hub-cat-ref-modal__name ms-1" id="hubCatDocsModalName"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body" id="hubCatDocsModalBody">
                    <p class="text-muted mb-0">ไม่มีเอกสารในหมวดนี้</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">ปิด</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canEditBudget && $catRefBlock !== null): ?>
    <div class="modal fade hub-cat-ref-modal" id="hubCategoryRefModal" tabindex="-1" aria-labelledby="hubCategoryRefModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header hub-cat-ref-modal__header">
                    <h5 class="modal-title" id="hubCategoryRefModalLabel">
                        <i class="bi bi-exclamation-triangle text-warning me-2"></i>ลบหมวดไม่ได้
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        หมวด <span class="hub-cat-ref-modal__name"><?= htmlspecialchars((string) ($catRefBlock['category_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        ยังถูกใช้งานใน <?= (int) ($catRefBlock['total'] ?? 0) ?> เอกสาร — กรุณาแก้หรือลบ PR/PO เหล่านี้ก่อน
                    </p>

                    <?php if (!empty($catRefBlock['prs'])): ?>
                    <div class="mb-4">
                        <h6 class="hub-cat-ref-modal__section-title"><i class="bi bi-cart-check me-1 text-warning"></i>ใบขอซื้อ (PR) · <?= count($catRefBlock['prs']) ?> รายการ</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0 hub-cat-ref-modal__table">
                                <thead class="table-light">
                                    <tr>
                                        <th>เลขที่</th>
                                        <th>แหล่งที่ซื้อ</th>
                                        <th class="text-end">ยอดสุทธิ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($catRefBlock['prs'] as $refPr): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= htmlspecialchars((string) ($refPr['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="fw-semibold text-decoration-none" target="_blank" rel="noopener">
                                                <?= htmlspecialchars((string) ($refPr['number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                <i class="bi bi-box-arrow-up-right ms-1 small opacity-50"></i>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($refPr['source'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="text-end"><?= number_format((float) ($refPr['net_amount'] ?? $refPr['amount'] ?? 0), 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($catRefBlock['pos'])): ?>
                    <div class="mb-0">
                        <h6 class="hub-cat-ref-modal__section-title"><i class="bi bi-receipt me-1 text-success"></i>ใบสั่งซื้อ (PO) · <?= count($catRefBlock['pos']) ?> รายการ</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0 hub-cat-ref-modal__table">
                                <thead class="table-light">
                                    <tr>
                                        <th>เลขที่</th>
                                        <th>แหล่งที่ซื้อ</th>
                                        <th class="text-end">ยอดสุทธิ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($catRefBlock['pos'] as $refPo): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= htmlspecialchars((string) ($refPo['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="fw-semibold text-decoration-none" target="_blank" rel="noopener">
                                                <?= htmlspecialchars((string) ($refPo['number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                <i class="bi bi-box-arrow-up-right ms-1 small opacity-50"></i>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($refPo['source'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="text-end"><?= number_format((float) ($refPo['net_amount'] ?? $refPo['amount'] ?? 0), 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">ปิด</button>
                </div>
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
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
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
    var catParentInput = document.getElementById('hub_category_parent_id');
    var catParentWrap = document.getElementById('hub_category_parent_wrap');
    var catParentSelect = document.getElementById('hub_category_parent_select');
    var catBudgetWrap = document.getElementById('hub_category_budget_wrap');
    var catNameLabel = document.getElementById('hub_category_name_label');
    var catNameInput = document.getElementById('hub_category_name');
    var catPctInput = document.getElementById('hub_category_budget_percent');
    var catPctHelp = document.getElementById('hub_category_percent_help');
    var catTitleText = document.getElementById('hubCategoryModalTitleText');
    var catSubmitText = document.getElementById('hubCategorySubmitText');
    var defaultPercentRoom = <?= json_encode(number_format($catPercentRoom, 2, '.', ''), JSON_UNESCAPED_UNICODE) ?>;

    function syncParentSelect(parentId) {
        if (!catParentSelect) {
            return;
        }
        var pid = String(parentId || '0');
        catParentSelect.value = pid > '0' ? pid : '';
    }

    function setCategoryModalMode(mode, data) {
        data = data || {};
        var isEdit = mode === 'edit' || mode === 'edit-sub';
        var isSub = mode === 'add-sub' || mode === 'edit-sub' || data.isSub === true || data.isSub === '1' || parseInt(String(data.parentId || '0'), 10) > 0;
        if (catIdInput) {
            catIdInput.value = isEdit ? String(data.id || '0') : '0';
        }
        if (catParentInput) {
            catParentInput.value = isSub ? String(data.parentId || catParentInput.value || '0') : '0';
        }
        if (catParentWrap) {
            catParentWrap.classList.toggle('d-none', !isSub);
        }
        if (isSub) {
            syncParentSelect(data.parentId || catParentInput.value || '0');
        }
        if (catBudgetWrap) {
            catBudgetWrap.classList.toggle('d-none', isSub);
        }
        if (catNameInput) {
            catNameInput.value = isEdit ? (data.name || '') : '';
        }
        if (catPctInput) {
            catPctInput.value = isEdit && !isSub ? (data.percent || '') : '';
        }
        if (catNameLabel) {
            catNameLabel.textContent = isSub ? 'ชื่อหมวดย่อย' : 'ชื่อหมวดหลัก';
        }
        if (catTitleText) {
            if (isEdit) {
                catTitleText.textContent = isSub ? 'แก้ไขหมวดย่อย' : 'แก้ไขหมวดค่าใช้จ่าย';
            } else if (mode === 'add-sub') {
                catTitleText.textContent = 'เพิ่มหมวดย่อย';
            } else {
                catTitleText.textContent = 'เพิ่มหมวดค่าใช้จ่าย';
            }
        }
        if (catSubmitText) {
            catSubmitText.textContent = isEdit ? 'บันทึกการแก้ไข' : (isSub ? 'บันทึกหมวดย่อย' : 'บันทึกหมวด');
        }
        if (catPctHelp && !isSub) {
            var room = isEdit ? (data.percentRoom || defaultPercentRoom) : defaultPercentRoom;
            catPctHelp.textContent = 'ว่าง = ไม่จำกัดหมวด · เหลือจัดสรรได้ ' + room + '%';
        }
    }

    document.querySelectorAll('.hub-cat-open-add').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setCategoryModalMode('add');
        });
    });

    document.querySelectorAll('.hub-cat-open-add-sub').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setCategoryModalMode('add-sub', {
                parentId: btn.getAttribute('data-parent-id') || '0',
                parentName: btn.getAttribute('data-parent-name') || ''
            });
        });
    });

    document.querySelectorAll('.hub-cat-open-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var isSub = btn.getAttribute('data-cat-is-sub') === '1';
            setCategoryModalMode(isSub ? 'edit-sub' : 'edit', {
                id: btn.getAttribute('data-cat-id') || '0',
                name: btn.getAttribute('data-cat-name') || '',
                percent: btn.getAttribute('data-cat-percent') || '',
                percentRoom: btn.getAttribute('data-cat-percent-room') || defaultPercentRoom,
                parentId: btn.getAttribute('data-cat-parent-id') || '0',
                isSub: isSub ? '1' : '0'
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
        foreach ($hubCatTableRows as $catRow) {
            if ((int) ($catRow['id'] ?? 0) === $openCatEditId && !empty($catRow['can_manage'])) {
                $openCatEditRow = $catRow;
                break;
            }
        }
        if ($openCatEditRow !== null):
            $openCatIsSub = (($openCatEditRow['row_kind'] ?? '') === 'sub');
            $openCatPctVal = $openCatIsSub ? null : ($openCatEditRow['budget_percent'] ?? null);
            $openCatPctInput = ($openCatPctVal === null) ? '' : rtrim(rtrim(number_format((float) $openCatPctVal, 2, '.', ''), '0'), '.');
            $openCatEditRoom = $openCatIsSub ? 0 : round(max(0.0, 100.0 - tnc_site_category_percent_sum($siteId, $openCatEditId)), 2);
            $openCatParentId = $openCatIsSub ? (int) ($openCatEditRow['parent_id'] ?? 0) : 0;
        ?>
        setCategoryModalMode(<?= $openCatIsSub ? "'edit-sub'" : "'edit'" ?>, {
            id: '<?= $openCatEditId ?>',
            name: <?= json_encode((string) ($openCatEditRow['name'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
            percent: <?= json_encode($openCatPctInput, JSON_UNESCAPED_UNICODE) ?>,
            percentRoom: <?= json_encode(number_format($openCatEditRoom, 2, '.', ''), JSON_UNESCAPED_UNICODE) ?>,
            parentId: '<?= $openCatParentId ?>',
            isSub: <?= $openCatIsSub ? "'1'" : "'0'" ?>
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
<?php if ($hubCanRemapCategories): ?>
<script>
(function () {
    var remapModalEl = document.getElementById('hubCategoryRemapModal');
    if (!remapModalEl) {
        return;
    }

    var remapForm = document.getElementById('hubCategoryRemapForm');
    var sourceSelect = document.getElementById('hubCatRemapSourceSelect');
    var targetSelect = document.getElementById('hubCatRemapTargetSelect');
    var remapSubmitBtn = document.getElementById('hubCatRemapSubmitBtn');
    var remapWarn = document.getElementById('hubCatRemapWarn');

    function selectedSource() {
        if (!sourceSelect || sourceSelect.selectedIndex < 0) {
            return null;
        }
        var opt = sourceSelect.options[sourceSelect.selectedIndex];
        if (!opt || !opt.value) {
            return null;
        }
        return {
            id: parseInt(String(opt.value || '0'), 10),
            label: (opt.textContent || '').trim(),
            docCount: parseInt(String(opt.getAttribute('data-doc-count') || '0'), 10),
            prCount: parseInt(String(opt.getAttribute('data-pr-count') || '0'), 10),
            poCount: parseInt(String(opt.getAttribute('data-po-count') || '0'), 10)
        };
    }

    function syncTargetOptions() {
        if (!targetSelect) {
            return;
        }
        var source = selectedSource();
        Array.prototype.forEach.call(targetSelect.options, function (opt, index) {
            if (index === 0) {
                opt.disabled = false;
                return;
            }
            var targetId = parseInt(String(opt.value || '0'), 10);
            var blocked = source && source.id > 0 && targetId === source.id;
            opt.disabled = blocked;
            if (blocked && opt.selected) {
                targetSelect.selectedIndex = 0;
            }
        });
    }

    function syncRemapSubmitState() {
        var source = selectedSource();
        var targetId = parseInt(String(targetSelect ? targetSelect.value : '0'), 10);
        var warnMsg = '';
        var infoMsg = '';

        if (source && targetId > 0) {
            if (source.id === targetId) {
                warnMsg = 'หมวดใหม่ต้องไม่ใช่หมวดเดิม';
            } else if (source.docCount > 0) {
                var parts = [];
                if (source.prCount > 0) {
                    parts.push('PR ' + source.prCount);
                }
                if (source.poCount > 0) {
                    parts.push('PO ' + source.poCount);
                }
                infoMsg = 'จะเปลี่ยนหมวดในเอกสาร ' + (parts.length ? parts.join(' · ') : source.docCount + ' รายการ') + ' ทั้งหมด';
            }
        }

        if (remapWarn) {
            if (warnMsg) {
                remapWarn.textContent = warnMsg;
                remapWarn.className = 'alert alert-warning py-2 px-3 small mb-0 mt-3';
                remapWarn.classList.remove('d-none');
            } else if (infoMsg) {
                remapWarn.textContent = infoMsg;
                remapWarn.className = 'alert alert-info py-2 px-3 small mb-0 mt-3';
                remapWarn.classList.remove('d-none');
            } else {
                remapWarn.textContent = '';
                remapWarn.classList.add('d-none');
            }
        }

        if (remapSubmitBtn) {
            remapSubmitBtn.disabled = !(source && source.id > 0 && targetId > 0 && warnMsg === '');
        }
    }

    function resetRemapModal() {
        if (sourceSelect) {
            sourceSelect.selectedIndex = 0;
        }
        if (targetSelect) {
            targetSelect.selectedIndex = 0;
        }
        syncTargetOptions();
        syncRemapSubmitState();
    }

    if (sourceSelect) {
        sourceSelect.addEventListener('change', function () {
            syncTargetOptions();
            syncRemapSubmitState();
        });
    }

    if (targetSelect) {
        targetSelect.addEventListener('change', syncRemapSubmitState);
    }

    remapModalEl.addEventListener('show.bs.modal', resetRemapModal);
    remapModalEl.addEventListener('shown.bs.modal', function () {
        if (sourceSelect) {
            sourceSelect.focus();
        }
    });

    if (remapForm) {
        remapForm.addEventListener('submit', function (event) {
            syncRemapSubmitState();
            var source = selectedSource();
            var targetId = parseInt(String(targetSelect ? targetSelect.value : '0'), 10);
            if (!source || source.id <= 0 || targetId <= 0 || remapSubmitBtn.disabled) {
                event.preventDefault();
                return;
            }
            var targetLabel = targetSelect.options[targetSelect.selectedIndex].text.trim();
            var msg = 'เปลี่ยนหมวดใน PR/PO จาก «' + source.label + '» เป็น «' + targetLabel + '»?';
            if (source.docCount > 0) {
                msg += '\n\nเอกสาร ' + source.docCount + ' รายการจะถูกอัปเดตทั้งหมด';
            }
            if (!window.confirm(msg)) {
                event.preventDefault();
            }
        });
    }
}());
</script>
<?php endif; ?>
<?php if ($openCatRefModal): ?>
<script>
(function () {
    var refModalEl = document.getElementById('hubCategoryRefModal');
    if (refModalEl && window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(refModalEl).show();
    }
}());
</script>
<?php endif; ?>
<?php if (!empty($summary['categories'])): ?>
<script>
(function () {
    document.querySelectorAll('.hub-cat-sub-toggle').forEach(function (btn) {
        var targetSel = btn.getAttribute('data-hub-cat-sub-target');
        if (!targetSel) {
            return;
        }
        btn.addEventListener('click', function () {
            var targets = document.querySelectorAll(targetSel);
            if (targets.length === 0) {
                return;
            }
            var willHide = btn.getAttribute('aria-expanded') !== 'false';
            targets.forEach(function (row) {
                row.classList.toggle('hub-cat-sub-row--hidden', willHide);
            });
            btn.setAttribute('aria-expanded', willHide ? 'false' : 'true');
            var parentRow = btn.closest('tr');
            if (parentRow) {
                parentRow.classList.toggle('hub-cat-parent-row--expanded', !willHide);
            }
        });
    });
}());
</script>
<script>
(function () {
    <?php
    $hubCatDocsMapJson = json_encode(
        $hubCatDocsMap,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($hubCatDocsMapJson === false) {
        $hubCatDocsMapJson = '{}';
    }
    ?>
    var docsMap = <?= $hubCatDocsMapJson ?>;
    var docsModalEl = document.getElementById('hubCatDocsModal');
    var docsNameEl = document.getElementById('hubCatDocsModalName');
    var docsBodyEl = document.getElementById('hubCatDocsModalBody');

    function escHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatMoney(value) {
        var num = parseFloat(value);
        if (!isFinite(num)) {
            num = 0;
        }
        return num.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function isCancelled(status) {
        var s = String(status || '').toLowerCase();
        return s === 'cancelled' || s === 'canceled' || s === 'rejected';
    }

    function buildDocRows(docs, type) {
        var rows = '';
        var list = docs || [];
        var badgeClass = type === 'pr' ? 'hub-cat-doc-badge--pr' : 'hub-cat-doc-badge--po';
        var badgeLabel = type === 'pr' ? 'PR' : 'PO';
        list.forEach(function (doc) {
            var cancelled = isCancelled(doc.status);
            var rowClass = cancelled ? 'hub-cat-doc-cancelled' : '';
            var linkClass = 'fw-semibold text-decoration-none' + (cancelled ? ' text-danger' : '');
            rows += '<tr class="' + rowClass + '">';
            rows += '<td><span class="hub-cat-doc-badge ' + badgeClass + '">' + badgeLabel + '</span></td>';
            rows += '<td><a href="' + escHtml(doc.url || '#') + '" class="' + linkClass + '" target="_blank" rel="noopener">' + escHtml(doc.number || '') + '</a></td>';
            rows += '<td class="hub-cat-doc-source" title="' + escHtml(doc.source || '—') + '">' + escHtml(doc.source || '—') + '</td>';
            rows += '<td class="text-end fw-semibold">' + formatMoney(doc.net_amount || doc.amount || 0) + '</td>';
            rows += '</tr>';
        });
        return rows;
    }

    function renderDocsModal(catId, catName) {
        if (!docsBodyEl) {
            return;
        }
        var docs = docsMap[catId] || docsMap[String(catId)] || { prs: [], pos: [], total: 0 };
        if (docsNameEl) {
            docsNameEl.textContent = catName || '';
        }
        var prs = docs.prs || [];
        var pos = docs.pos || [];
        if (prs.length === 0 && pos.length === 0) {
            docsBodyEl.innerHTML = '<p class="text-muted mb-0">ไม่มีเอกสารในหมวดนี้</p>';
            return;
        }
        var html = '';
        if (prs.length > 0) {
            html += '<div class="mb-4">';
            html += '<h6 class="hub-cat-ref-modal__section-title"><i class="bi bi-cart-check me-1 text-warning"></i>ใบขอซื้อ (PR) · ' + prs.length + ' รายการ</h6>';
            html += '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 hub-cat-ref-modal__table">';
            html += '<thead class="table-light"><tr><th style="width:3.5rem;">ประเภท</th><th>เลขที่</th><th>แหล่งที่ซื้อ</th><th class="text-end">ยอดสุทธิ</th></tr></thead>';
            html += '<tbody>' + buildDocRows(prs, 'pr') + '</tbody></table></div></div>';
        }
        if (pos.length > 0) {
            html += '<div class="mb-0">';
            html += '<h6 class="hub-cat-ref-modal__section-title"><i class="bi bi-receipt me-1 text-success"></i>ใบสั่งซื้อ (PO) · ' + pos.length + ' รายการ</h6>';
            html += '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 hub-cat-ref-modal__table">';
            html += '<thead class="table-light"><tr><th style="width:3.5rem;">ประเภท</th><th>เลขที่</th><th>แหล่งที่ซื้อ</th><th class="text-end">ยอดสุทธิ</th></tr></thead>';
            html += '<tbody>' + buildDocRows(pos, 'po') + '</tbody></table></div></div>';
        }
        docsBodyEl.innerHTML = html;
    }

    document.querySelectorAll('.hub-cat-docs-open').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var catId = btn.getAttribute('data-cat-id') || '0';
            var catName = btn.getAttribute('data-cat-name') || '';
            renderDocsModal(catId, catName);
            if (docsModalEl && window.bootstrap) {
                window.bootstrap.Modal.getOrCreateInstance(docsModalEl).show();
            }
        });
    });
}());
</script>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
