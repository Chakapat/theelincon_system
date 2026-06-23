<?php

declare(strict_types=1);

if (!isset($_SESSION['user_id'])) {
    return;
}

if (!function_exists('tnc_hub_nav_bottom_tab_items')) {
    require_once dirname(__DIR__) . '/includes/tnc_hub_nav.php';
    require_once dirname(__DIR__) . '/includes/tnc_hub_nav_mobile.php';
}

$bottomTabs = tnc_hub_nav_bottom_tab_items();
if ($bottomTabs === []) {
    return;
}

$mobileMenuSections = tnc_hub_nav_mobile_menu_sections();

?>
<nav class="tnc-mobile-bottom-nav no-print" id="tncMobileBottomNav" aria-label="เมนูหลัก">
    <div class="tnc-mobile-bottom-nav__inner">
        <?php foreach ($bottomTabs as $tab): ?>
            <?php if (!empty($tab['is_button'])): ?>
                <button
                    type="button"
                    class="tnc-mobile-bottom-nav__item tnc-mobile-bottom-nav__item--more<?= !empty($tab['active']) ? ' is-active' : '' ?>"
                    id="tncMobileNavMore"
                    aria-label="<?= htmlspecialchars((string) $tab['label'], ENT_QUOTES, 'UTF-8') ?>"
                    aria-expanded="false"
                    aria-controls="tncMobileMenuSheet"
                >
                    <i class="bi <?= htmlspecialchars((string) $tab['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                    <span><?= htmlspecialchars((string) $tab['label'], ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php else: ?>
                <a
                    href="<?= htmlspecialchars((string) $tab['url'], ENT_QUOTES, 'UTF-8') ?>"
                    class="tnc-mobile-bottom-nav__item<?= !empty($tab['active']) ? ' is-active' : '' ?>"
                    <?= !empty($tab['active']) ? 'aria-current="page"' : '' ?>
                >
                    <i class="bi <?= htmlspecialchars((string) $tab['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                    <span><?= htmlspecialchars((string) $tab['label'], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</nav>

<?php if ($mobileMenuSections !== []): ?>
<div class="tnc-mobile-menu-sheet no-print" id="tncMobileMenuSheet" hidden aria-hidden="true">
    <div class="tnc-mobile-menu-sheet__backdrop" id="tncMobileMenuBackdrop" aria-hidden="true"></div>
    <div class="tnc-mobile-menu-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="tncMobileMenuTitle">
        <div class="tnc-mobile-menu-sheet__head">
            <h2 class="tnc-mobile-menu-sheet__title" id="tncMobileMenuTitle">เมนูระบบ</h2>
            <button type="button" class="tnc-mobile-menu-sheet__close" id="tncMobileMenuClose" aria-label="ปิดเมนู">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>
        <div class="tnc-mobile-menu-sheet__body">
            <?php foreach ($mobileMenuSections as $section): ?>
                <details class="tnc-mobile-menu-hub"<?= count($section['pages']) === 1 ? ' open' : '' ?>>
                    <summary class="tnc-mobile-menu-hub__summary">
                        <i class="bi <?= htmlspecialchars((string) $section['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                        <span><?= htmlspecialchars((string) $section['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    </summary>
                    <nav class="tnc-mobile-menu-hub__links" aria-label="<?= htmlspecialchars((string) $section['label'], ENT_QUOTES, 'UTF-8') ?>">
                        <?php foreach ($section['pages'] as $page): ?>
                            <a
                                href="<?= htmlspecialchars((string) $page['url'], ENT_QUOTES, 'UTF-8') ?>"
                                class="tnc-mobile-menu-hub__link<?= !empty($page['active']) ? ' is-active' : '' ?><?= ($page['link_class'] ?? '') !== '' ? ' ' . htmlspecialchars((string) $page['link_class'], ENT_QUOTES, 'UTF-8') : '' ?>"
                                <?= !empty($page['active']) ? 'aria-current="page"' : '' ?>
                            >
                                <i class="bi <?= htmlspecialchars((string) $page['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                                <span><?= htmlspecialchars((string) $page['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    if (document.body.classList.contains('tnc-has-mobile-nav')) {
        return;
    }
    document.body.classList.add('tnc-has-mobile-nav');
})();
</script>
