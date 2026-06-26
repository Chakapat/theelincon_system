<?php

declare(strict_types=1);

/**
 * งบไซต์ (site_budget) + วงเงินหมวด (budget_percent)
 * PO purchase ที่ committed หักจากงบ — ยกเลิก PO คืนงบอัตโนมัติ
 */

use Theelincon\Rtdb\Db;

if (!function_exists('tnc_site_budget_po_amount')) {
    function tnc_site_budget_po_amount(array $po): float
    {
        $net = round((float) ($po['total_amount'] ?? 0), 2);
        if ($net > 0.0) {
            return $net;
        }

        return round((float) ($po['gross_amount'] ?? 0), 2);
    }
}

if (!function_exists('tnc_site_budget_is_committed')) {
    function tnc_site_budget_is_committed(array $po): bool
    {
        $status = strtolower(trim((string) ($po['status'] ?? '')));
        if (in_array($status, ['cancelled', 'canceled'], true)) {
            return false;
        }
        $orderType = strtolower(trim((string) ($po['order_type'] ?? 'purchase')));
        if ($orderType !== 'purchase') {
            return false;
        }

        return (int) ($po['site_id'] ?? 0) > 0;
    }
}

if (!function_exists('tnc_site_budget_site_limit')) {
    /** @return float|null null = ไม่จำกัด */
    function tnc_site_budget_site_limit(int $siteId): ?float
    {
        if ($siteId <= 0) {
            return null;
        }
        $row = Db::rowByIdField('sites', $siteId);
        if (!is_array($row)) {
            return null;
        }
        $budget = round((float) ($row['site_budget'] ?? 0), 2);
        if ($budget <= 0.0) {
            return null;
        }

        return $budget;
    }
}

if (!function_exists('tnc_site_budget_category_percent')) {
    function tnc_site_budget_category_percent(int $categoryId): ?float
    {
        if ($categoryId <= 0) {
            return null;
        }
        $row = Db::rowByIdField('site_cost_categories', $categoryId);
        if (!is_array($row) || (int) ($row['active'] ?? 1) !== 1) {
            return null;
        }
        if (!array_key_exists('budget_percent', $row) || $row['budget_percent'] === '' || $row['budget_percent'] === null) {
            return null;
        }
        $pct = round((float) $row['budget_percent'], 2);
        if ($pct < 0.0) {
            return 0.0;
        }

        return $pct;
    }
}

if (!function_exists('tnc_site_budget_cat_limit')) {
    /** @return float|null null = หมวดไม่จำกัด (แต่ยังโดนงบไซต์รวม) */
    function tnc_site_budget_cat_limit(int $siteId, int $categoryId): ?float
    {
        $siteLimit = tnc_site_budget_site_limit($siteId);
        if ($siteLimit === null) {
            return null;
        }
        $pct = tnc_site_budget_category_percent($categoryId);
        if ($pct === null) {
            return null;
        }

        return round($siteLimit * ($pct / 100.0), 2);
    }
}

if (!function_exists('tnc_site_budget_purchase_orders_cached')) {
    /**
     * โหลด purchase_orders ครั้งเดียวต่อ request (ลด RTDB round-trip)
     *
     * @return list<array<string,mixed>>
     */
    function tnc_site_budget_purchase_orders_cached(): array
    {
        static $rows = null;
        if ($rows === null) {
            $rows = Db::tableRows('purchase_orders');
        }

        return $rows;
    }
}

if (!function_exists('tnc_site_budget_committed_pos')) {
    /**
     * @return list<array<string,mixed>>
     */
    function tnc_site_budget_committed_pos(int $siteId, ?int $categoryId = null, ?int $excludePoId = null): array
    {
        if ($siteId <= 0) {
            return [];
        }
        $out = [];
        foreach (tnc_site_budget_purchase_orders_cached() as $po) {
            if (!is_array($po)) {
                continue;
            }
            if ((int) ($po['site_id'] ?? 0) !== $siteId) {
                continue;
            }
            if ($categoryId !== null && $categoryId > 0 && (int) ($po['cost_category_id'] ?? 0) !== $categoryId) {
                continue;
            }
            $poId = (int) ($po['id'] ?? 0);
            if ($excludePoId !== null && $excludePoId > 0 && $poId === $excludePoId) {
                continue;
            }
            if (!tnc_site_budget_is_committed($po)) {
                continue;
            }
            $out[] = $po;
        }

        return $out;
    }
}

if (!function_exists('tnc_site_budget_site_used')) {
    function tnc_site_budget_site_used(int $siteId, ?int $excludePoId = null): float
    {
        return tnc_site_budget_site_used_map($excludePoId)[$siteId] ?? 0.0;
    }
}

if (!function_exists('tnc_site_budget_site_used_map')) {
    /**
     * รวมยอด PO ที่ committed ต่อไซต์ — สแกน purchase_orders ครั้งเดียว
     *
     * @return array<int, float>
     */
    function tnc_site_budget_site_used_map(?int $excludePoId = null): array
    {
        $map = [];
        foreach (tnc_site_budget_purchase_orders_cached() as $po) {
            if (!is_array($po)) {
                continue;
            }
            if (!tnc_site_budget_is_committed($po)) {
                continue;
            }
            $poId = (int) ($po['id'] ?? 0);
            if ($excludePoId !== null && $excludePoId > 0 && $poId === $excludePoId) {
                continue;
            }
            $siteId = (int) ($po['site_id'] ?? 0);
            if ($siteId <= 0) {
                continue;
            }
            $map[$siteId] = ($map[$siteId] ?? 0.0) + tnc_site_budget_po_amount($po);
        }
        foreach ($map as $siteId => $sum) {
            $map[$siteId] = round($sum, 2);
        }

        return $map;
    }
}

if (!function_exists('tnc_site_budget_site_limit_from_row')) {
    /** @return float|null null = ไม่จำกัด */
    function tnc_site_budget_site_limit_from_row(array $siteRow): ?float
    {
        $budget = round((float) ($siteRow['site_budget'] ?? 0), 2);
        if ($budget <= 0.0) {
            return null;
        }

        return $budget;
    }
}

if (!function_exists('tnc_site_budget_site_summary_light')) {
    /**
     * สรุปงบไซต์แบบเบา (ไม่โหลดหมวด) — ใช้บน site-picker
     *
     * @return array<string,mixed>
     */
    function tnc_site_budget_site_summary_light(int $siteId, array $siteRow, float $used): array
    {
        $siteLimit = tnc_site_budget_site_limit_from_row($siteRow);
        $used = round(max(0.0, $used), 2);
        $remaining = $siteLimit !== null ? round($siteLimit - $used, 2) : null;
        $low = false;
        $exhausted = false;
        if ($siteLimit !== null) {
            if ($remaining !== null && $remaining <= 0.0001) {
                $exhausted = true;
            }
            if ($remaining !== null && $remaining <= tnc_site_budget_low_threshold($siteLimit) + 0.0001) {
                $low = true;
            }
        }

        return [
            'site_id' => $siteId,
            'limit' => $siteLimit,
            'used' => $used,
            'remaining' => $remaining,
            'unlimited' => $siteLimit === null,
            'low' => $low,
            'exhausted' => $exhausted,
        ];
    }
}

if (!function_exists('tnc_site_budget_cat_used')) {
    function tnc_site_budget_cat_used(int $siteId, int $categoryId, ?int $excludePoId = null): float
    {
        if ($categoryId <= 0) {
            return 0.0;
        }
        $sum = 0.0;
        foreach (tnc_site_budget_committed_pos($siteId, $categoryId, $excludePoId) as $po) {
            $sum += tnc_site_budget_po_amount($po);
        }

        return round($sum, 2);
    }
}

if (!function_exists('tnc_site_budget_low_threshold')) {
    function tnc_site_budget_low_threshold(float $limit): float
    {
        return round(max(0.0, $limit * 0.20), 2);
    }
}

if (!function_exists('tnc_site_budget_validate')) {
    /**
     * @return array{
     *   ok: bool,
     *   error_code: ?string,
     *   error_message: ?string,
     *   warnings: list<string>
     * }
     */
    function tnc_site_budget_validate(int $siteId, int $categoryId, float $amount, ?int $excludePoId = null): array
    {
        $amount = round(max(0.0, $amount), 2);
        $warnings = [];

        $siteLimit = tnc_site_budget_site_limit($siteId);
        if ($siteLimit === null) {
            return ['ok' => true, 'error_code' => null, 'error_message' => null, 'warnings' => []];
        }

        $usedSite = tnc_site_budget_site_used($siteId, $excludePoId);
        $remainingSite = round($siteLimit - $usedSite, 2);

        if ($amount > $remainingSite + 0.0001) {
            return [
                'ok' => false,
                'error_code' => 'site_budget_exceeded',
                'error_message' => 'งบไซต์คงเหลือ ' . number_format(max(0.0, $remainingSite), 2) . ' บาท — ไม่สามารถออก PO ยอด ' . number_format($amount, 2) . ' บาทได้',
                'warnings' => [],
            ];
        }

        $warnSiteAt = tnc_site_budget_low_threshold($siteLimit);
        $remainingAfterSite = round($remainingSite - $amount, 2);
        if ($remainingAfterSite <= $warnSiteAt + 0.0001) {
            $warnings[] = 'งบไซต์รวมเหลือน้อย (คงเหลือหลัง PO นี้ ' . number_format(max(0.0, $remainingAfterSite), 2) . ' บาท)';
        }

        $catLimit = tnc_site_budget_cat_limit($siteId, $categoryId);
        if ($catLimit !== null && $categoryId > 0) {
            $usedCat = tnc_site_budget_cat_used($siteId, $categoryId, $excludePoId);
            $remainingCat = round($catLimit - $usedCat, 2);
            $catName = function_exists('tnc_site_category_name') ? tnc_site_category_name($categoryId) : ('#' . $categoryId);
            $warnCatAt = tnc_site_budget_low_threshold($catLimit);
            $remainingAfterCat = round($remainingCat - $amount, 2);
            if ($remainingAfterCat < -0.0001) {
                $warnings[] = 'หมวด «' . $catName . '» เกินงบ (คงเหลือหลัง PO นี้ ' . number_format($remainingAfterCat, 2) . ' บาท)';
            } elseif ($remainingAfterCat >= 0.0 && $remainingAfterCat <= $warnCatAt + 0.0001) {
                $warnings[] = 'หมวด «' . $catName . '» เงินเหลือน้อย (คงเหลือหลัง PO นี้ ' . number_format($remainingAfterCat, 2) . ' บาท)';
            }
        }

        return [
            'ok' => true,
            'error_code' => null,
            'error_message' => null,
            'warnings' => $warnings,
        ];
    }
}

if (!function_exists('tnc_site_budget_abort_if_invalid')) {
    function tnc_site_budget_abort_if_invalid(int $siteId, int $categoryId, float $amount, ?int $excludePoId, string $redirectUrl): void
    {
        if ($siteId <= 0 || $amount <= 0.0) {
            return;
        }
        if (!function_exists('tnc_site_category_name')) {
            require_once __DIR__ . '/site_cost_categories.php';
        }
        $result = tnc_site_budget_validate($siteId, $categoryId, $amount, $excludePoId);
        if ($result['ok']) {
            return;
        }
        $code = (string) ($result['error_code'] ?? 'site_budget_exceeded');
        $sep = str_contains($redirectUrl, '?') ? '&' : '?';
        tnc_action_redirect($redirectUrl . $sep . 'error=' . rawurlencode($code));
    }
}

if (!function_exists('tnc_site_budget_category_rows_for_site')) {
    /**
     * สรุปงบตามหมวดของไซต์ (สำหรับ hub / admin)
     *
     * @return list<array<string,mixed>>
     */
    function tnc_site_budget_category_rows_for_site(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }
        if (!function_exists('tnc_site_categories_for_site')) {
            require_once __DIR__ . '/site_cost_categories.php';
        }
        $siteLimit = tnc_site_budget_site_limit($siteId);
        $rows = [];
        foreach (tnc_site_categories_for_site($siteId) as $cat) {
            $cid = (int) ($cat['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $pct = tnc_site_budget_category_percent($cid);
            $limit = tnc_site_budget_cat_limit($siteId, $cid);
            $used = tnc_site_budget_cat_used($siteId, $cid);
            $remaining = $limit !== null ? round($limit - $used, 2) : null;
            $overBudget = $limit !== null && $remaining !== null && $remaining < -0.0001;
            $low = false;
            if ($limit !== null && $remaining !== null && $remaining > 0.0001 && $remaining <= tnc_site_budget_low_threshold($limit) + 0.0001) {
                $low = true;
            }
            $rows[] = [
                'id' => $cid,
                'name' => (string) ($cat['name'] ?? ''),
                'budget_percent' => $pct,
                'limit' => $limit,
                'used' => $used,
                'remaining' => $remaining,
                'low' => $low,
                'over_budget' => $overBudget,
                'unlimited' => $limit === null && $siteLimit !== null,
            ];
        }

        return $rows;
    }
}

if (!function_exists('tnc_site_budget_site_summary')) {
    /**
     * @return array<string,mixed>
     */
    function tnc_site_budget_site_summary(int $siteId): array
    {
        $siteLimit = tnc_site_budget_site_limit($siteId);
        $used = tnc_site_budget_site_used($siteId);
        $remaining = $siteLimit !== null ? round($siteLimit - $used, 2) : null;
        $low = false;
        $exhausted = false;
        if ($siteLimit !== null) {
            if ($remaining !== null && $remaining <= 0.0001) {
                $exhausted = true;
            }
            if ($remaining !== null && $remaining <= tnc_site_budget_low_threshold($siteLimit) + 0.0001) {
                $low = true;
            }
        }

        return [
            'site_id' => $siteId,
            'limit' => $siteLimit,
            'used' => $used,
            'remaining' => $remaining,
            'unlimited' => $siteLimit === null,
            'low' => $low,
            'exhausted' => $exhausted,
            'categories' => tnc_site_budget_category_rows_for_site($siteId),
        ];
    }
}

if (!function_exists('tnc_site_budget_format_money')) {
    function tnc_site_budget_format_money(?float $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        return number_format($amount, 2);
    }
}

if (!function_exists('tnc_site_list_filter_from_request')) {
    /**
     * อ่าน ?site_id= จาก URL สำหรับกรองรายการ PR/PO ตามไซต์
     *
     * @return array{site_id: int, site_name: string, query: string, hub_url: string}
     */
    function tnc_site_list_filter_from_request(): array
    {
        $siteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;
        $siteName = '';
        if ($siteId > 0) {
            $row = Db::rowByIdField('sites', $siteId);
            if ($row === null) {
                $siteId = 0;
            } else {
                $siteName = trim((string) ($row['name'] ?? ''));
            }
        }

        return [
            'site_id' => $siteId,
            'site_name' => $siteName,
            'query' => $siteId > 0 ? ('?site_id=' . $siteId) : '',
            'hub_url' => $siteId > 0 ? app_path('pages/sites/site-hub.php?site_id=' . $siteId) : app_path('pages/sites/site-picker.php'),
        ];
    }
}
