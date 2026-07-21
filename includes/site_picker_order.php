<?php

declare(strict_types=1);

/**
 * ลำดับการ์ดไซต์ในหน้า site picker (ต่อผู้ใช้)
 */

use Theelincon\Rtdb\Db;

require_once __DIR__ . '/site_favorites.php';

if (!function_exists('tnc_site_picker_order_normalize_ids')) {
    /**
     * @param mixed $raw
     * @return list<int>
     */
    function tnc_site_picker_order_normalize_ids($raw): array
    {
        return tnc_site_favorites_normalize_ids($raw);
    }
}

if (!function_exists('tnc_site_picker_order_for_user')) {
    /**
     * @return list<int>
     */
    function tnc_site_picker_order_for_user(int $userId): array
    {
        $user = tnc_site_favorites_user_row($userId);

        return $user !== null ? tnc_site_picker_order_normalize_ids($user['site_picker_order'] ?? []) : [];
    }
}

if (!function_exists('tnc_site_picker_order_save_for_user')) {
    /**
     * @param list<int> $siteIds
     */
    function tnc_site_picker_order_save_for_user(int $userId, array $siteIds): bool
    {
        if ($userId <= 0 || tnc_site_favorites_user_row($userId) === null) {
            return false;
        }
        $pk = tnc_site_favorites_user_pk($userId);
        if ($pk === '') {
            return false;
        }
        Db::mergeRow('users', $pk, ['site_picker_order' => tnc_site_picker_order_normalize_ids($siteIds)]);

        return true;
    }
}

if (!function_exists('tnc_site_picker_order_sort_sites')) {
    /**
     * @param list<array<string,mixed>> $sites
     * @param list<int> $orderIds
     * @return list<array<string,mixed>>
     */
    function tnc_site_picker_order_sort_sites(array $sites, array $orderIds): array
    {
        $orderIds = tnc_site_picker_order_normalize_ids($orderIds);
        if ($orderIds === []) {
            return $sites;
        }

        $rank = [];
        foreach ($orderIds as $index => $siteId) {
            $rank[$siteId] = $index;
        }

        usort($sites, static function (array $a, array $b) use ($rank): int {
            $aId = (int) ($a['id'] ?? 0);
            $bId = (int) ($b['id'] ?? 0);
            $aRank = $rank[$aId] ?? PHP_INT_MAX;
            $bRank = $rank[$bId] ?? PHP_INT_MAX;
            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }

            $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
            if ($so !== 0) {
                return $so;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $sites;
    }
}
