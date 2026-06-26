<?php

declare(strict_types=1);

/**
 * รายการโปรดไซต์ (ต่อผู้ใช้)
 */

use Theelincon\Rtdb\Db;

if (!function_exists('tnc_site_favorites_user_row')) {
    function tnc_site_favorites_user_row(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $user = Db::row('users', (string) $userId);
        if ($user === null) {
            $user = Db::rowByIdField('users', $userId, 'userid');
        }
        if ($user === null) {
            $user = Db::rowByIdField('users', $userId, 'id');
        }

        return is_array($user) ? $user : null;
    }
}

if (!function_exists('tnc_site_favorites_user_pk')) {
    function tnc_site_favorites_user_pk(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        if (Db::row('users', (string) $userId) !== null) {
            return (string) $userId;
        }
        foreach (['userid', 'id'] as $field) {
            $pk = Db::pkForLogicalId('users', $userId, $field);
            if ($pk !== '' && Db::row('users', $pk) !== null) {
                return $pk;
            }
        }

        return (string) $userId;
    }
}

if (!function_exists('tnc_site_favorites_normalize_ids')) {
    /**
     * @param mixed $raw
     * @return list<int>
     */
    function tnc_site_favorites_normalize_ids($raw): array
    {
        if (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '') {
                return [];
            }
            $decoded = json_decode($trim, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $trim);
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}

if (!function_exists('tnc_site_favorites_for_user')) {
    /**
     * @return list<int>
     */
    function tnc_site_favorites_for_user(int $userId): array
    {
        $user = tnc_site_favorites_user_row($userId);

        return $user !== null ? tnc_site_favorites_normalize_ids($user['favorite_site_ids'] ?? []) : [];
    }
}

if (!function_exists('tnc_site_favorites_save_for_user')) {
    /**
     * @param list<int> $siteIds
     */
    function tnc_site_favorites_save_for_user(int $userId, array $siteIds): bool
    {
        if ($userId <= 0 || tnc_site_favorites_user_row($userId) === null) {
            return false;
        }
        $pk = tnc_site_favorites_user_pk($userId);
        if ($pk === '') {
            return false;
        }
        Db::mergeRow('users', $pk, ['favorite_site_ids' => tnc_site_favorites_normalize_ids($siteIds)]);

        return true;
    }
}

if (!function_exists('tnc_site_favorites_toggle')) {
    /**
     * @return array{ok: bool, favorite: bool, favorites: list<int>, error?: string}
     */
    function tnc_site_favorites_toggle(int $userId, int $siteId): array
    {
        if ($userId <= 0 || $siteId <= 0) {
            return ['ok' => false, 'favorite' => false, 'favorites' => [], 'error' => 'invalid'];
        }
        if (Db::rowByIdField('sites', $siteId) === null) {
            return ['ok' => false, 'favorite' => false, 'favorites' => [], 'error' => 'not_found'];
        }

        $favorites = tnc_site_favorites_for_user($userId);
        $isFavorite = in_array($siteId, $favorites, true);
        if ($isFavorite) {
            $favorites = array_values(array_filter($favorites, static fn (int $id): bool => $id !== $siteId));
            $isFavorite = false;
        } else {
            array_unshift($favorites, $siteId);
            $isFavorite = true;
        }

        if (!tnc_site_favorites_save_for_user($userId, $favorites)) {
            return ['ok' => false, 'favorite' => false, 'favorites' => [], 'error' => 'save_failed'];
        }

        return ['ok' => true, 'favorite' => $isFavorite, 'favorites' => $favorites];
    }
}

if (!function_exists('tnc_site_favorites_sort_sites')) {
    /**
     * @param list<array<string,mixed>> $sites
     * @param list<int> $favoriteIds
     * @return list<array<string,mixed>>
     */
    function tnc_site_favorites_sort_sites(array $sites, array $favoriteIds): array
    {
        $favoriteIds = tnc_site_favorites_normalize_ids($favoriteIds);
        $rank = [];
        foreach ($favoriteIds as $index => $siteId) {
            $rank[$siteId] = $index;
        }

        usort($sites, static function (array $a, array $b) use ($rank): int {
            $aId = (int) ($a['id'] ?? 0);
            $bId = (int) ($b['id'] ?? 0);
            $aFav = array_key_exists($aId, $rank);
            $bFav = array_key_exists($bId, $rank);
            if ($aFav !== $bFav) {
                return $aFav ? -1 : 1;
            }
            if ($aFav && $bFav && $rank[$aId] !== $rank[$bId]) {
                return $rank[$aId] <=> $rank[$bId];
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
