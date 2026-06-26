<?php

declare(strict_types=1);

/**
 * จัดการไซต์งาน (sites)
 */

use Theelincon\Rtdb\Db;

if (!function_exists('tnc_site_purchase_counts')) {
    /**
     * @return array{pr: int, po: int}
     */
    function tnc_site_purchase_counts(int $siteId): array
    {
        $counts = ['pr' => 0, 'po' => 0];
        if ($siteId <= 0) {
            return $counts;
        }

        foreach (Db::tableRows('purchase_requests') as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int) ($row['site_id'] ?? 0) === $siteId) {
                ++$counts['pr'];
            }
        }
        foreach (Db::tableRows('purchase_orders') as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int) ($row['site_id'] ?? 0) === $siteId) {
                ++$counts['po'];
            }
        }

        return $counts;
    }
}

if (!function_exists('tnc_site_delete_purchase_data')) {
    /**
     * ลบ PR/PO ที่อ้างอิงไซต์ (cascade ตาม logic ลบ PR/PO ในระบบ)
     *
     * @return list<array{verb: string, entity_type: string, entity_id: string, snapshot: array<string, mixed>}>
     */
    function tnc_site_delete_purchase_data(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }

        require_once __DIR__ . '/purchase_cascade_delete.php';

        $nested = [];

        $prIds = [];
        foreach (Db::tableRows('purchase_requests') as $pr) {
            if (!is_array($pr)) {
                continue;
            }
            if ((int) ($pr['site_id'] ?? 0) !== $siteId) {
                continue;
            }
            $prId = (int) ($pr['id'] ?? 0);
            if ($prId > 0) {
                $prIds[] = $prId;
            }
        }

        foreach ($prIds as $prId) {
            $prSnap = Db::row('purchase_requests', (string) $prId);
            if ($prSnap === null) {
                continue;
            }
            $nested = array_merge($nested, tnc_delete_pr_cascade($prId));
            Db::deleteRow('purchase_requests', (string) $prId);
            $nested[] = [
                'verb' => 'delete',
                'entity_type' => 'purchase_request',
                'entity_id' => (string) $prId,
                'snapshot' => $prSnap,
            ];
        }

        $poIds = [];
        foreach (Db::tableRows('purchase_orders') as $po) {
            if (!is_array($po)) {
                continue;
            }
            if ((int) ($po['site_id'] ?? 0) !== $siteId) {
                continue;
            }
            $poId = (int) ($po['id'] ?? 0);
            if ($poId > 0) {
                $poIds[] = $poId;
            }
        }

        foreach ($poIds as $poId) {
            if (Db::row('purchase_orders', (string) $poId) === null) {
                continue;
            }
            $nested = array_merge($nested, tnc_delete_purchase_order_cascade($poId));
        }

        return $nested;
    }
}

if (!function_exists('tnc_site_delete')) {
    /**
     * ลบไซต์ หมวดค่าใช้จ่าย และ PR/PO ที่อ้างอิงไซต์
     *
     * @return array{ok: bool, error_code: ?string, message: ?string, nested: list<array<string,mixed>>}
     */
    function tnc_site_delete(int $siteId): array
    {
        if ($siteId <= 0) {
            return ['ok' => false, 'error_code' => 'invalid', 'message' => 'ไม่พบไซต์', 'nested' => []];
        }

        $site = Db::rowByIdField('sites', $siteId);
        if ($site === null) {
            return ['ok' => false, 'error_code' => 'not_found', 'message' => 'ไม่พบไซต์', 'nested' => []];
        }

        $nested = tnc_site_delete_purchase_data($siteId);

        if (!function_exists('tnc_site_category_delete')) {
            require_once __DIR__ . '/site_cost_categories.php';
        }

        foreach (Db::tableRows('site_cost_categories') as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            if ((int) ($cat['site_id'] ?? 0) !== $siteId) {
                continue;
            }
            $catId = (int) ($cat['id'] ?? 0);
            if ($catId > 0) {
                tnc_site_category_delete($catId);
            }
        }

        $pk = Db::pkForLogicalId('sites', $siteId);
        if ($pk === '') {
            return ['ok' => false, 'error_code' => 'not_found', 'message' => 'ไม่พบไซต์', 'nested' => $nested];
        }
        Db::deleteRow('sites', $pk);

        return ['ok' => true, 'error_code' => null, 'message' => null, 'nested' => $nested];
    }
}

if (!function_exists('tnc_site_save_name')) {
    /**
     * @return array{ok: bool, error_code: ?string, message: ?string}
     */
    function tnc_site_save_name(int $siteId, string $name): array
    {
        $name = trim($name);
        if ($siteId <= 0) {
            return ['ok' => false, 'error_code' => 'invalid', 'message' => 'ไม่พบไซต์'];
        }
        if ($name === '' || strlen($name) > 200) {
            return ['ok' => false, 'error_code' => 'invalid_name', 'message' => 'ชื่อไซต์ไม่ถูกต้อง'];
        }

        $cur = Db::rowByIdField('sites', $siteId);
        if ($cur === null) {
            return ['ok' => false, 'error_code' => 'not_found', 'message' => 'ไม่พบไซต์'];
        }

        $pk = Db::pkForLogicalId('sites', $siteId);
        if ($pk === '') {
            return ['ok' => false, 'error_code' => 'not_found', 'message' => 'ไม่พบไซต์'];
        }

        Db::setRow('sites', $pk, array_merge($cur, ['name' => $name]));

        return ['ok' => true, 'error_code' => null, 'message' => null];
    }
}
