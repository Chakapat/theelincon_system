<?php

declare(strict_types=1);

/**
 * Bottom tab bar items for mobile shell (permission-aware).
 *
 * @return list<array{key: string, label: string, icon: string, url: string, active: bool, is_button: bool}>
 */
function tnc_hub_nav_bottom_tab_items(): array
{
    if (!isset($_SESSION['user_id'])) {
        return [];
    }

    $flat = tnc_role_page_registry_flat();
    $currentKey = tnc_hub_nav_current_page_key();
    $sections = tnc_hub_nav_section_page_keys();

    $resolveUrl = static function (string $pageKey) use ($flat): string {
        if (!isset($flat[$pageKey]) || !user_can_access_page($pageKey)) {
            return '';
        }

        return app_path((string) $flat[$pageKey]['path']);
    };

    $hubActive = static function (string $hubKey) use ($currentKey): bool {
        return tnc_hub_nav_hub_is_active($hubKey, $currentKey);
    };

    $firstHubUrl = static function (string $hubKey) use ($sections, $resolveUrl): string {
        foreach ($sections[$hubKey] ?? [] as $pageKey) {
            $url = $resolveUrl($pageKey);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    };

    $homeUrl = $resolveUrl('page.index');
    if ($homeUrl === '') {
        $homeUrl = app_path('index.php');
    }

    $purchaseUrl = $resolveUrl('page.site.picker');
    if ($purchaseUrl === '') {
        $purchaseUrl = $firstHubUrl('hub_purchase');
    }

    $cashUrl = $resolveUrl('page.cash');
    if ($cashUrl === '') {
        $cashUrl = $firstHubUrl('hub_cash');
    }

    $tabs = [];

    if ($homeUrl !== '') {
        $tabs[] = [
            'key' => 'home',
            'label' => 'หน้าแรก',
            'icon' => 'bi-house-door-fill',
            'url' => $homeUrl,
            'active' => $currentKey === 'page.index' || ($currentKey !== null && str_starts_with($currentKey, 'page.invoice')),
            'is_button' => false,
        ];
    }

    if ($purchaseUrl !== '') {
        $tabs[] = [
            'key' => 'purchase',
            'label' => 'จัดซื้อ',
            'icon' => 'bi-cart3',
            'url' => $purchaseUrl,
            'active' => $hubActive('hub_purchase'),
            'is_button' => false,
        ];
    }

    if ($cashUrl !== '') {
        $tabs[] = [
            'key' => 'cash',
            'label' => 'เงินสด',
            'icon' => 'bi-cash-stack',
            'url' => $cashUrl,
            'active' => $hubActive('hub_cash'),
            'is_button' => false,
        ];
    }

    $tabs[] = [
        'key' => 'more',
        'label' => 'เมนู',
        'icon' => 'bi-grid-fill',
        'url' => '#',
        'active' => false,
        'is_button' => true,
    ];

    return $tabs;
}

/**
 * Purchase module horizontal sub-nav (PR / PO).
 *
 * @return list<array{key: string, label: string, icon: string, url: string, active: bool}>
 */
function tnc_hub_nav_purchase_subnav_items(): array
{
    if (!isset($_SESSION['user_id'])) {
        return [];
    }

    $flat = tnc_role_page_registry_flat();
    $currentKey = tnc_hub_nav_current_page_key();
    $pageMeta = tnc_hub_nav_page_meta();

    $defs = [
        ['key' => 'page.pr', 'label' => 'PR'],
        ['key' => 'page.po', 'label' => 'PO'],
    ];

    $items = [];
    foreach ($defs as $def) {
        $pageKey = $def['key'];
        if (!isset($flat[$pageKey]) || !user_can_access_page($pageKey)) {
            continue;
        }
        $meta = $pageMeta[$pageKey] ?? ['icon' => 'bi-link-45deg'];
        $items[] = [
            'key' => $pageKey,
            'label' => $def['label'],
            'icon' => (string) ($meta['icon'] ?? 'bi-link-45deg'),
            'url' => app_path((string) $flat[$pageKey]['path']),
            'active' => tnc_hub_nav_subnav_active($pageKey),
        ];
    }

    return $items;
}

/**
 * Sub-nav chip active when current script is any path under the page registry entry.
 */
function tnc_hub_nav_subnav_active(string $pageKey): bool
{
    $flat = tnc_role_page_registry_flat();
    if (!isset($flat[$pageKey])) {
        return false;
    }

    $script = strtolower(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    if ($script === '') {
        return false;
    }

    $page = $flat[$pageKey];
    $paths = $page['paths'] ?? [$page['path']];
    foreach ($paths as $path) {
        $pathLower = strtolower((string) $path);
        if ($script === $pathLower || str_ends_with($script, '/' . $pathLower)) {
            return true;
        }
    }

    return false;
}

/**
 * Full hub sections for mobile «เมนู» bottom sheet (replaces FAB on small screens).
 *
 * @return list<array{key: string, label: string, icon: string, pages: list<array{label: string, url: string, icon: string, active: bool, link_class: string}>}>
 */
function tnc_hub_nav_mobile_menu_sections(): array
{
    if (!isset($_SESSION['user_id'])) {
        return [];
    }

    $tree = tnc_role_permission_menu_tree();
    $flat = tnc_role_page_registry_flat();
    $hubMeta = tnc_hub_nav_hub_meta();
    $pageMeta = tnc_hub_nav_page_meta();
    $sections = tnc_hub_nav_section_page_keys();
    $currentKey = tnc_hub_nav_current_page_key();

    $hubKeys = [
        'hub_master',
        'hub_home',
        'hub_purchase',
        'hub_cash',
        'hub_hr',
        'hub_internal',
        'hub_tools',
    ];

    $out = [];
    foreach ($hubKeys as $hubKey) {
        if (!isset($tree[$hubKey], $sections[$hubKey])) {
            continue;
        }

        $pages = [];
        foreach ($sections[$hubKey] as $pageKey) {
            if (!isset($flat[$pageKey]) || !user_can_access_page($pageKey)) {
                continue;
            }
            $page = $flat[$pageKey];
            $meta = $pageMeta[$pageKey] ?? ['icon' => 'bi-link-45deg'];
            $pages[] = [
                'label' => (string) ($meta['short_label'] ?? $page['label']),
                'url' => app_path((string) $page['path']),
                'icon' => (string) ($meta['icon'] ?? 'bi-link-45deg'),
                'active' => tnc_hub_nav_page_is_active($pageKey, $currentKey),
                'link_class' => (string) ($meta['link_class'] ?? ''),
            ];
        }

        if ($pages === []) {
            continue;
        }

        $meta = $hubMeta[$hubKey] ?? ['icon' => 'bi-grid'];
        $hubLabel = (string) ($meta['short_label'] ?? $tree[$hubKey]['label']);
        $directUrl = '';
        if (tnc_hub_nav_hub_is_direct_link($hubKey) && $pages !== []) {
            $directUrl = (string) $pages[0]['url'];
        }
        $out[] = [
            'key' => $hubKey,
            'label' => $hubLabel,
            'icon' => (string) ($meta['icon'] ?? 'bi-grid'),
            'pages' => $pages,
            'direct_url' => $directUrl,
            'active' => tnc_hub_nav_hub_is_active($hubKey, $currentKey),
        ];
    }

    return $out;
}

/**
 * True when current script is under purchase module lists/forms.
 */
function tnc_hub_nav_is_purchase_module_page(): bool
{
    $script = strtolower(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));

    return str_contains($script, '/pages/purchase/');
}
