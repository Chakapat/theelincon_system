<?php

declare(strict_types=1);

if (!isset($_SESSION['user_id'])) {
    return;
}

if (!function_exists('tnc_hub_nav_build_for_user')) {
    require_once dirname(__DIR__) . '/includes/tnc_hub_nav.php';
}

$hubNav = tnc_hub_nav_build_for_user();
if ($hubNav['hubs'] === [] && $hubNav['pins'] === []) {
    return;
}

$hubFabCssPath = dirname(__DIR__) . '/assets/css/tnc-hub-fab.css';
$hubFabJsPath = dirname(__DIR__) . '/assets/js/tnc-hub-fab.js';
$hubFabCssVer = @filemtime($hubFabCssPath);
$hubFabJsVer = @filemtime($hubFabJsPath);
if (!is_int($hubFabCssVer) || $hubFabCssVer <= 0) {
    $hubFabCssVer = time();
}
if (!is_int($hubFabJsVer) || $hubFabJsVer <= 0) {
    $hubFabJsVer = time();
}

$hideOnIndexDesktop = !empty($hubNav['is_index']);
$bodyClass = 'tnc-has-hub-fab' . ($hideOnIndexDesktop ? ' tnc-index-desktop-sidebar' : '');

?>
<link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/tnc-hub-fab.css') . '?v=' . $hubFabCssVer, ENT_QUOTES, 'UTF-8') ?>">

<div class="tnc-hub-fab-backdrop" id="tncHubFabBackdrop" hidden aria-hidden="true"></div>

<div class="tnc-hub-fab-root<?= $hideOnIndexDesktop ? ' tnc-hub-fab-root--index' : '' ?>" id="tncHubFabRoot" aria-label="เมนูระบบ">
    <div class="tnc-hub-fab-stack">
        <div class="tnc-hub-fab-flyout" id="tncHubFabFlyout" hidden aria-hidden="true">
            <div class="tnc-hub-fab-flyout-panel" id="tncHubFabFlyoutPanel">
                <div class="tnc-hub-fab-flyout-head" id="tncHubFabFlyoutHead"></div>
                <nav class="tnc-hub-fab-flyout-links" id="tncHubFabFlyoutLinks" aria-label="เมนูย่อย"></nav>
            </div>
            <span class="tnc-hub-fab-flyout-bridge" id="tncHubFabFlyoutBridge" aria-hidden="true"></span>
        </div>
        <div class="tnc-hub-fab-hubs" id="tncHubFabHubs" aria-label="หมวดเมนู"></div>
        <button type="button" class="tnc-hub-fab-main" id="tncHubFabMain" aria-expanded="false" aria-controls="tncHubFabHubs" title="เปิดเมนูระบบ">
            <span class="tnc-hub-fab-main-icon tnc-hub-fab-main-icon--open" aria-hidden="true"><i class="bi bi-grid-fill"></i></span>
            <span class="tnc-hub-fab-main-icon tnc-hub-fab-main-icon--close" aria-hidden="true"><i class="bi bi-x-lg"></i></span>
            <span class="visually-hidden">เมนูระบบ</span>
        </button>
    </div>
</div>

<script>
window.TncHubFabConfig = <?= json_encode([
    'hubs' => $hubNav['hubs'],
    'hideOnIndexDesktop' => $hideOnIndexDesktop,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= htmlspecialchars(app_path('assets/js/tnc-hub-fab.js') . '?v=' . $hubFabJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<script>
(function () {
    if (document.body.classList.contains('tnc-has-hub-fab')) {
        return;
    }
    document.body.classList.add(<?= json_encode($bodyClass, JSON_UNESCAPED_UNICODE) ?>);
})();
</script>
