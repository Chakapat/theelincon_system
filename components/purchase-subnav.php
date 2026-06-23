<?php

declare(strict_types=1);

if (!isset($_SESSION['user_id'])) {
    return;
}

if (!function_exists('tnc_hub_nav_purchase_subnav_items')) {
    require_once dirname(__DIR__) . '/includes/tnc_hub_nav.php';
    require_once dirname(__DIR__) . '/includes/tnc_hub_nav_mobile.php';
}

if (!tnc_hub_nav_is_purchase_module_page()) {
    return;
}

$subnavItems = tnc_hub_nav_purchase_subnav_items();
if (count($subnavItems) < 2) {
    return;
}

?>
<nav class="tnc-purchase-subnav no-print d-lg-none" aria-label="เมนูจัดซื้อ">
    <?php foreach ($subnavItems as $item): ?>
        <a
            href="<?= htmlspecialchars((string) $item['url'], ENT_QUOTES, 'UTF-8') ?>"
            class="tnc-purchase-subnav__chip<?= !empty($item['active']) ? ' is-active' : '' ?>"
            <?= !empty($item['active']) ? 'aria-current="page"' : '' ?>
        >
            <i class="bi <?= htmlspecialchars((string) $item['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
            <?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') ?>
        </a>
    <?php endforeach; ?>
</nav>
