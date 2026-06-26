<?php

declare(strict_types=1);

/**
 * หมวดค่าใช้จ่ายตามไซต์ (Site Cost Categories) — แบบผสม
 *   - site_id = 0  : หมวดกลาง ใช้ได้ทุกไซต์
 *   - site_id > 0  : หมวดเฉพาะไซต์นั้น
 * ตาราง: site_cost_categories { id, site_id, name, sort_order, active, budget_percent? }
 */

use Theelincon\Rtdb\Db;

if (!function_exists('tnc_site_categories_all')) {
    /** @return array<int,array<string,mixed>> เฉพาะที่ active เรียงตาม sort_order แล้วชื่อ */
    function tnc_site_categories_all(bool $activeOnly = true): array
    {
        $rows = [];
        foreach (Db::tableRows('site_cost_categories') as $r) {
            if (!is_array($r) || (int) ($r['id'] ?? 0) <= 0) {
                continue;
            }
            if ($activeOnly && (int) ($r['active'] ?? 1) !== 1) {
                continue;
            }
            $rows[] = $r;
        }
        usort($rows, static function (array $a, array $b): int {
            $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
            if ($so !== 0) {
                return $so;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $rows;
    }
}

if (!function_exists('tnc_site_categories_for_site')) {
    /**
     * หมวดที่ใช้ได้กับไซต์หนึ่ง = หมวดกลาง (site_id=0) + หมวดเฉพาะไซต์นั้น
     *
     * @return array<int,array<string,mixed>>
     */
    function tnc_site_categories_for_site(int $siteId): array
    {
        $out = [];
        foreach (tnc_site_categories_all(true) as $r) {
            $sid = (int) ($r['site_id'] ?? 0);
            if ($sid === 0 || $sid === $siteId) {
                $out[] = $r;
            }
        }

        return $out;
    }
}

if (!function_exists('tnc_site_categories_map_by_site')) {
    /**
     * แผนที่สำหรับส่งให้ JS: [siteId => [ {id, name}, ... ]] โดยรวมหมวดกลางไว้ที่คีย์ 0
     *
     * @return array<int,array<int,array{id:int,name:string}>>
     */
    function tnc_site_categories_map_by_site(): array
    {
        $global = [];
        $bySite = [];
        foreach (tnc_site_categories_all(true) as $r) {
            $entry = ['id' => (int) $r['id'], 'name' => (string) ($r['name'] ?? '')];
            $sid = (int) ($r['site_id'] ?? 0);
            if ($sid === 0) {
                $global[] = $entry;
            } else {
                $bySite[$sid][] = $entry;
            }
        }
        $map = [0 => $global];
        foreach ($bySite as $sid => $list) {
            $map[$sid] = array_merge($global, $list);
        }

        return $map;
    }
}

if (!function_exists('tnc_site_category_name')) {
    function tnc_site_category_name(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $row = Db::rowByIdField('site_cost_categories', $id);

        return is_array($row) ? trim((string) ($row['name'] ?? '')) : '';
    }
}

if (!function_exists('tnc_site_category_is_valid_for_site')) {
    /** ตรวจว่าหมวด $id ใช้ได้กับไซต์ $siteId หรือไม่ (หมวดกลางหรือหมวดของไซต์นั้น) */
    function tnc_site_category_is_valid_for_site(int $id, int $siteId): bool
    {
        if ($id <= 0) {
            return false;
        }
        $row = Db::rowByIdField('site_cost_categories', $id);
        if (!is_array($row) || (int) ($row['active'] ?? 1) !== 1) {
            return false;
        }
        $sid = (int) ($row['site_id'] ?? 0);

        return $sid === 0 || $sid === $siteId;
    }
}

if (!function_exists('tnc_site_category_percent_sum')) {
    /** รวม budget_percent ของหมวดเฉพาะไซต์ (site_id > 0) */
    function tnc_site_category_percent_sum(int $siteId, ?int $excludeCategoryId = null): float
    {
        if ($siteId <= 0) {
            return 0.0;
        }
        $sum = 0.0;
        foreach (tnc_site_categories_all(true) as $r) {
            $cid = (int) ($r['id'] ?? 0);
            if ($cid <= 0 || ($excludeCategoryId !== null && $excludeCategoryId > 0 && $cid === $excludeCategoryId)) {
                continue;
            }
            if ((int) ($r['site_id'] ?? 0) !== $siteId) {
                continue;
            }
            if (!array_key_exists('budget_percent', $r) || $r['budget_percent'] === '' || $r['budget_percent'] === null) {
                continue;
            }
            $pct = round((float) $r['budget_percent'], 2);
            if ($pct > 0.0) {
                $sum += $pct;
            }
        }

        return round($sum, 2);
    }
}

if (!function_exists('tnc_site_category_percent_would_exceed')) {
    function tnc_site_category_percent_would_exceed(int $siteId, ?float $newPercent, ?int $excludeCategoryId = null): bool
    {
        if ($siteId <= 0 || $newPercent === null || $newPercent <= 0.0) {
            return false;
        }
        $sum = tnc_site_category_percent_sum($siteId, $excludeCategoryId);

        return round($sum + $newPercent, 2) > 100.0 + 0.0001;
    }
}

if (!function_exists('tnc_site_category_save')) {
    /**
     * สร้าง/แก้ไขหมวด คืนค่า id
     *
     * @return int|array{id:int}|array{error:string} 0 = ชื่อว่าง, array error = รวม % เกิน 100
     */
    function tnc_site_category_save(int $id, int $siteId, string $name, int $sortOrder = 0, ?float $budgetPercent = null)
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $name = mb_substr($name, 0, 150);
        $pctStored = null;
        if ($budgetPercent !== null) {
            $pctStored = round(max(0.0, min(100.0, $budgetPercent)), 2);
        }
        if ($siteId > 0 && $pctStored !== null && $pctStored > 0.0 && tnc_site_category_percent_would_exceed($siteId, $pctStored, $id > 0 ? $id : null)) {
            return ['error' => 'percent_sum_exceeded'];
        }
        $pctField = $pctStored !== null ? $pctStored : '';
        if ($id > 0) {
            $pk = Db::pkForLogicalId('site_cost_categories', $id);
            $cur = $pk !== null ? Db::row('site_cost_categories', $pk) : null;
            if (is_array($cur)) {
                Db::setRow('site_cost_categories', $pk, array_merge($cur, [
                    'name' => $name,
                    'site_id' => $siteId,
                    'sort_order' => $sortOrder,
                    'budget_percent' => $pctField,
                ]));

                return $id;
            }
        }
        $nid = Db::nextNumericId('site_cost_categories', 'id');
        Db::setRow('site_cost_categories', (string) $nid, [
            'id' => $nid,
            'site_id' => $siteId,
            'name' => $name,
            'sort_order' => $sortOrder,
            'active' => 1,
            'budget_percent' => $pctField,
        ]);

        return $nid;
    }
}

if (!function_exists('tnc_site_category_delete')) {
    function tnc_site_category_delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $pk = Db::pkForLogicalId('site_cost_categories', $id);
        if ($pk !== null) {
            Db::deleteRow('site_cost_categories', $pk);
        }
    }
}
