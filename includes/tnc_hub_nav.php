<?php

declare(strict_types=1);

/**
 * เมนู Hub กลาง — sidebar หน้าแรก + FAB ทุกหน้า (กรองตาม user_can)
 */

require_once __DIR__ . '/tnc_page_access.php';

/**
 * ลำดับหมวด + หน้าย่อยที่แสดงใน Hub
 *
 * @return array<string, list<string>>
 */
function tnc_hub_nav_section_page_keys(): array
{
    return [
        'hub_master' => [
            'page.org.customer',
            'page.org.company',
            'page.org.members',
            'page.org.suppliers',
        ],
        'hub_purchase' => [
            'page.site.picker',
        ],
        'hub_cash' => [
            'page.report.vat',
            'page.report.site',
            'page.cash',
        ],
        'hub_home' => [
            'page.index',
            'page.invoice.create',
            'page.invoice.tax_list',
        ],
        'hub_hr' => [
            'page.account.profile',
        ],
        'hub_internal' => [
            'page.internal.roles',
            'page.internal.audit',
            'page.internal.line',
            'page.internal.doc_colors',
        ],
        'hub_tools' => [
            'page.tools.po_payment',
        ],
    ];
}

/**
 * @return array<string, array{icon: string, ico_class: string, sidebar_label?: string}>
 */
function tnc_hub_nav_hub_meta(): array
{
    return [
        'hub_master' => ['icon' => 'bi-folder2', 'ico_class' => 'home-hub-ico--master', 'short_label' => 'ข้อมูลหลัก', 'hint' => 'ลูกค้า บริษัท ผู้ขาย'],
        'hub_purchase' => ['icon' => 'bi-cart3', 'ico_class' => 'home-hub-ico--purchase', 'sidebar_label' => 'ระบบจัดซื้อ', 'short_label' => 'จัดซื้อ', 'hint' => 'เลือกไซต์ สร้าง PR/PO', 'direct_link' => true],
        'hub_cash' => ['icon' => 'bi-cash-stack', 'ico_class' => 'home-hub-ico--cash', 'sidebar_label' => 'ระบบการเงิน', 'short_label' => 'การเงิน', 'hint' => 'VAT รายงานไซต์ สดย่อย'],
        'hub_home' => ['icon' => 'bi-house-door', 'ico_class' => 'home-hub-ico--master', 'short_label' => 'หน้าแรก'],
        'hub_hr' => ['icon' => 'bi-person-badge', 'ico_class' => 'home-hub-ico--docs'],
        'hub_internal' => ['icon' => 'bi-shield-lock', 'ico_class' => 'home-hub-ico--docs'],
        'hub_tools' => ['icon' => 'bi-tools', 'ico_class' => 'home-hub-ico--docs'],
    ];
}

/**
 * @return array<string, array{icon: string, short_label?: string, pin?: bool, pin_order?: int}>
 */
function tnc_hub_nav_page_meta(): array
{
    return [
        'page.index' => ['icon' => 'bi-house-door', 'short_label' => 'หน้าแรก', 'pin' => true, 'pin_order' => 1],
        'page.invoice.create' => ['icon' => 'bi-file-earmark-plus', 'short_label' => 'Invoice', 'pin' => true, 'pin_order' => 2],
        'page.invoice.tax_list' => ['icon' => 'bi-receipt', 'short_label' => 'ใบกำกับ'],
        'page.org.customer' => ['icon' => 'bi-people', 'short_label' => 'ลูกค้า'],
        'page.org.company' => ['icon' => 'bi-building', 'short_label' => 'บริษัท'],
        'page.org.members' => ['icon' => 'bi-person-gear', 'link_class' => 'js-hub-member-manage', 'short_label' => 'สมาชิก'],
        'page.org.suppliers' => ['icon' => 'bi-truck', 'short_label' => 'ผู้ขาย'],
        'page.site.picker' => ['icon' => 'bi-geo-alt-fill', 'short_label' => 'เข้าไซต์', 'pin' => true, 'pin_order' => 2],
        'page.site.hub' => ['icon' => 'bi-grid-3x3-gap', 'short_label' => 'Site Hub'],
        'page.pr' => ['icon' => 'bi-cart-plus', 'short_label' => 'PR'],
        'page.po' => ['icon' => 'bi-bag-check', 'short_label' => 'PO'],
        'page.stock' => ['icon' => 'bi-box-seam', 'short_label' => 'คลัง'],
        'page.report.vat' => ['icon' => 'bi-file-earmark-bar-graph', 'short_label' => 'VAT', 'pin' => true, 'pin_order' => 6],
        'page.report.site' => ['icon' => 'bi-geo-alt'],
        'page.cash' => ['icon' => 'bi-speedometer2', 'short_label' => 'สดย่อย', 'pin' => true, 'pin_order' => 5],
        'page.account.profile' => ['icon' => 'bi-person-circle'],
        'page.internal.roles' => ['icon' => 'bi-sliders'],
        'page.internal.audit' => ['icon' => 'bi-journal-text'],
        'page.internal.line' => ['icon' => 'bi-line'],
        'page.internal.doc_colors' => ['icon' => 'bi-palette'],
        'page.tools.po_payment' => ['icon' => 'bi-paperclip'],
    ];
}

/** หมวดที่แสดงใน sidebar หน้า index */
function tnc_hub_nav_sidebar_hub_keys(): array
{
    return ['hub_master', 'hub_purchase', 'hub_cash'];
}

/** หมวดที่แสดงใน FAB (เรียงตาม sidebar หน้า index) */
function tnc_hub_nav_fab_hub_keys(): array
{
    return [
        'hub_master',
        'hub_purchase',
        'hub_cash',
    ];
}

function tnc_hub_nav_current_page_key(): ?string
{
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === '') {
        return null;
    }

    return tnc_page_key_for_script($script);
}

function tnc_hub_nav_page_is_active(string $pageKey, ?string $currentKey): bool
{
    return $currentKey !== null && $currentKey !== '' && $pageKey === $currentKey;
}

function tnc_hub_nav_hub_is_direct_link(string $hubKey): bool
{
    $meta = tnc_hub_nav_hub_meta();

    return !empty($meta[$hubKey]['direct_link']);
}

/**
 * @return list<string>
 */
function tnc_hub_nav_hub_page_keys(string $hubKey): array
{
    $tree = tnc_role_permission_menu_tree();
    if (!isset($tree[$hubKey]['pages']) || !is_array($tree[$hubKey]['pages'])) {
        return [];
    }

    return array_keys($tree[$hubKey]['pages']);
}

function tnc_hub_nav_hub_is_active(string $hubKey, ?string $currentKey): bool
{
    if ($currentKey === null || $currentKey === '') {
        return false;
    }

    return in_array($currentKey, tnc_hub_nav_hub_page_keys($hubKey), true);
}

/**
 * @return array{hubs: list<array<string, mixed>>, pins: list<array<string, mixed>>, current_page_key: ?string, is_index: bool}
 */
function tnc_hub_nav_build_for_user(): array
{
    $tree = tnc_role_permission_menu_tree();
    $flat = tnc_role_page_registry_flat();
    $hubMeta = tnc_hub_nav_hub_meta();
    $pageMeta = tnc_hub_nav_page_meta();
    $currentKey = tnc_hub_nav_current_page_key();
    $script = strtolower(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $isIndex = str_ends_with($script, '/index.php') && !str_contains($script, '/pages/');

    $buildPages = static function (array $pageKeys) use ($flat, $pageMeta, $currentKey): array {
        $pages = [];
        foreach ($pageKeys as $pageKey) {
            if (!isset($flat[$pageKey]) || !user_can_access_page($pageKey)) {
                continue;
            }
            $page = $flat[$pageKey];
            $meta = $pageMeta[$pageKey] ?? ['icon' => 'bi-link-45deg'];
            $pages[] = [
                'key' => $pageKey,
                'label' => (string) $page['label'],
                'short_label' => (string) ($meta['short_label'] ?? $page['label']),
                'url' => app_path((string) $page['path']),
                'icon' => (string) ($meta['icon'] ?? 'bi-link-45deg'),
                'link_class' => (string) ($meta['link_class'] ?? ''),
                'active' => tnc_hub_nav_page_is_active($pageKey, $currentKey),
                'pin' => !empty($meta['pin']),
                'pin_order' => (int) ($meta['pin_order'] ?? 99),
            ];
        }

        return $pages;
    };

    $sections = tnc_hub_nav_section_page_keys();
    $hubs = [];
    foreach (tnc_hub_nav_fab_hub_keys() as $hubKey) {
        if (!isset($tree[$hubKey], $sections[$hubKey])) {
            continue;
        }
        $pages = $buildPages($sections[$hubKey]);
        if ($pages === []) {
            continue;
        }
        $meta = $hubMeta[$hubKey] ?? ['icon' => 'bi-grid', 'ico_class' => 'home-hub-ico--docs'];
        $hubLabel = (string) ($meta['sidebar_label'] ?? $tree[$hubKey]['label']);
        $directUrl = '';
        if (tnc_hub_nav_hub_is_direct_link($hubKey) && $pages !== []) {
            $directUrl = (string) $pages[0]['url'];
        }

        $hubs[] = [
            'key' => $hubKey,
            'label' => $hubLabel,
            'short_label' => (string) ($meta['short_label'] ?? preg_replace('/\s*\([^)]*\)\s*$/u', '', $hubLabel)),
            'hint' => (string) ($meta['hint'] ?? ''),
            'tree_label' => (string) $tree[$hubKey]['label'],
            'icon' => (string) $meta['icon'],
            'ico_class' => (string) $meta['ico_class'],
            'pages' => $pages,
            'direct_url' => $directUrl,
            'active' => tnc_hub_nav_hub_is_active($hubKey, $currentKey),
        ];
    }

    $pins = [];
    foreach ($flat as $pageKey => $page) {
        if (!user_can_access_page($pageKey)) {
            continue;
        }
        $meta = $pageMeta[$pageKey] ?? null;
        if ($meta === null || empty($meta['pin'])) {
            continue;
        }
        $pins[] = [
            'key' => $pageKey,
            'label' => (string) ($meta['short_label'] ?? $page['label']),
            'url' => app_path((string) $page['path']),
            'icon' => (string) ($meta['icon'] ?? 'bi-link-45deg'),
            'pin_order' => (int) ($meta['pin_order'] ?? 99),
            'active' => tnc_hub_nav_page_is_active($pageKey, $currentKey),
        ];
    }
    usort($pins, static fn (array $a, array $b): int => ($a['pin_order'] <=> $b['pin_order']) ?: strcmp($a['label'], $b['label']));

    return [
        'hubs' => $hubs,
        'pins' => $pins,
        'current_page_key' => $currentKey,
        'is_index' => $isIndex,
    ];
}

/**
 * @param array<string, mixed> $opts sidebar_start_collapsed
 */
function tnc_hub_nav_render_sidebar(array $opts = []): void
{
    $startCollapsed = !empty($opts['start_collapsed']);
    $nav = tnc_hub_nav_build_for_user();
    $tree = tnc_role_permission_menu_tree();
    $hubMeta = tnc_hub_nav_hub_meta();
    $sections = tnc_hub_nav_section_page_keys();
    $flat = tnc_role_page_registry_flat();
    $pageMeta = tnc_hub_nav_page_meta();
    $currentKey = $nav['current_page_key'];

    $activeHubKey = null;
    foreach (tnc_hub_nav_sidebar_hub_keys() as $scanHubKey) {
        if (tnc_hub_nav_hub_is_active($scanHubKey, $currentKey)) {
            $activeHubKey = $scanHubKey;
            break;
        }
    }
    ?>
    <div class="home-hub-sidebar-intro px-3 pt-3 pb-2 border-bottom border-light-subtle">
        <p class="home-hub-sidebar-intro__title fw-bold mb-0">เมนูระบบ</p>
    </div>
    <?php

    $renderLink = static function (string $pageKey) use ($flat, $pageMeta, $currentKey): void {
        if (!isset($flat[$pageKey]) || !user_can_access_page($pageKey)) {
            return;
        }
        $page = $flat[$pageKey];
        $meta = $pageMeta[$pageKey] ?? ['icon' => 'bi-link-45deg'];
        $active = tnc_hub_nav_page_is_active($pageKey, $currentKey) ? ' active' : '';
        $extraClass = trim((string) ($meta['link_class'] ?? ''));
        $href = app_path((string) $page['path']);
        $icon = (string) ($meta['icon'] ?? 'bi-link-45deg');
        $label = (string) $page['label'];
        ?>
        <a class="home-hub-link d-flex align-items-center<?= $active ?><?= $extraClass !== '' ? ' ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8') : '' ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
            <i class="bi <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?> me-2 text-secondary"></i><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php
    };

    foreach (tnc_hub_nav_sidebar_hub_keys() as $hubKey) {
        if (!isset($tree[$hubKey], $sections[$hubKey])) {
            continue;
        }
        $visible = false;
        foreach ($sections[$hubKey] as $pk) {
            if (isset($flat[$pk]) && user_can_access_page($pk)) {
                $visible = true;
                break;
            }
        }
        if (!$visible) {
            continue;
        }

        $meta = $hubMeta[$hubKey] ?? ['icon' => 'bi-grid', 'ico_class' => 'home-hub-ico--docs'];
        $hubLabel = (string) ($meta['sidebar_label'] ?? $tree[$hubKey]['label']);
        $hubHasActive = tnc_hub_nav_hub_is_active($hubKey, $currentKey);
        $hubHint = trim((string) ($meta['hint'] ?? ''));

        if (tnc_hub_nav_hub_is_direct_link($hubKey)) {
            $directPageKey = null;
            foreach ($sections[$hubKey] as $pageKey) {
                if (isset($flat[$pageKey]) && user_can_access_page($pageKey)) {
                    $directPageKey = $pageKey;
                    break;
                }
            }
            if ($directPageKey === null) {
                continue;
            }
            $directPage = $flat[$directPageKey];
            $directHref = app_path((string) $directPage['path']);
            ?>
        <div class="home-hub-section">
            <a class="home-hub-link home-hub-link--hub d-flex align-items-center gap-2<?= $hubHasActive ? ' active' : '' ?>" href="<?= htmlspecialchars($directHref, ENT_QUOTES, 'UTF-8') ?>">
                <span class="home-hub-ico <?= htmlspecialchars((string) $meta['ico_class'], ENT_QUOTES, 'UTF-8') ?> flex-shrink-0"><i class="<?= htmlspecialchars((string) $meta['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i></span>
                <span class="home-hub-toggle__text min-w-0">
                    <span class="fw-semibold text-dark d-block"><?= htmlspecialchars($hubLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($hubHint !== ''): ?>
                    <span class="home-hub-toggle__hint small text-muted d-block"><?= htmlspecialchars($hubHint, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </span>
            </a>
        </div>
            <?php
            continue;
        }

        $collapseId = 'hub-collapse-' . preg_replace('/[^a-z0-9_-]/i', '', $hubKey);
        $toggleId = 'hub-toggle-' . preg_replace('/[^a-z0-9_-]/i', '', $hubKey);
        $expanded = $hubHasActive || ($activeHubKey === null && $hubKey === 'hub_master' && !$startCollapsed);
        ?>
        <div class="home-hub-section">
            <button type="button" class="home-hub-toggle<?= $expanded ? '' : ' collapsed' ?><?= $hubHasActive ? ' has-active-child' : '' ?>" data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>" aria-expanded="<?= $expanded ? 'true' : 'false' ?>" aria-controls="<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>" id="<?= htmlspecialchars($toggleId, ENT_QUOTES, 'UTF-8') ?>">
                <span class="home-hub-ico <?= htmlspecialchars((string) $meta['ico_class'], ENT_QUOTES, 'UTF-8') ?> flex-shrink-0"><i class="<?= htmlspecialchars((string) $meta['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i></span>
                <span class="home-hub-toggle__text min-w-0">
                    <span class="fw-semibold text-dark d-block"><?= htmlspecialchars($hubLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($hubHint !== ''): ?>
                    <span class="home-hub-toggle__hint small text-muted d-block"><?= htmlspecialchars($hubHint, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </span>
                <i class="bi bi-chevron-down home-hub-chevron" aria-hidden="true"></i>
            </button>
            <div id="<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>" class="collapse<?= $expanded ? ' show' : '' ?> home-hub-panel" aria-labelledby="<?= htmlspecialchars($toggleId, ENT_QUOTES, 'UTF-8') ?>">
                <div class="home-hub-panel-inner pb-1">
                    <?php foreach ($sections[$hubKey] as $pageKey) {
                        $renderLink($pageKey);
                    } ?>
                </div>
            </div>
        </div>
        <?php
    }
}
