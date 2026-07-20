<?php

declare(strict_types=1);

/**
 * หมวดค่าใช้จ่ายตามไซต์ (Site Cost Categories) — แบบผสม
 *   - site_id = 0  : หมวดกลาง ใช้ได้ทุกไซต์
 *   - site_id > 0  : หมวดเฉพาะไซต์นั้น
 * ตาราง: site_cost_categories { id, site_id, name, sort_order, active, budget_percent?, parent_id? }
 *   - parent_id = 0  : หมวดหลัก (budget_percent = % ของงบไซต์)
 *   - parent_id > 0  : หมวดย่อย (budget_percent = % ของงบหมวดหลัก — เลือกบน PR/PO)
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

if (!function_exists('tnc_site_category_parent_id')) {
    function tnc_site_category_parent_id(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }
        $row = Db::rowByIdField('site_cost_categories', $id);

        return is_array($row) ? max(0, (int) ($row['parent_id'] ?? 0)) : 0;
    }
}

if (!function_exists('tnc_site_category_has_children')) {
    function tnc_site_category_has_children(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        foreach (tnc_site_categories_all(true) as $r) {
            if ((int) ($r['parent_id'] ?? 0) === $id) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('tnc_site_category_child_ids')) {
    /** @return list<int> */
    function tnc_site_category_child_ids(int $parentId): array
    {
        if ($parentId <= 0) {
            return [];
        }
        $out = [];
        foreach (tnc_site_categories_all(true) as $r) {
            if ((int) ($r['parent_id'] ?? 0) !== $parentId) {
                continue;
            }
            $cid = (int) ($r['id'] ?? 0);
            if ($cid > 0) {
                $out[] = $cid;
            }
        }

        return $out;
    }
}

if (!function_exists('tnc_site_category_is_selectable')) {
    /** เลือกบน PR/PO ได้เมื่อเป็นหมวดย่อย หรือหมวดหลักที่ยังไม่มีหมวดย่อย */
    function tnc_site_category_is_selectable(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $row = Db::rowByIdField('site_cost_categories', $id);
        if (!is_array($row) || (int) ($row['active'] ?? 1) !== 1) {
            return false;
        }
        $parentId = (int) ($row['parent_id'] ?? 0);
        if ($parentId > 0) {
            return true;
        }

        return !tnc_site_category_has_children($id);
    }
}

if (!function_exists('tnc_site_category_budget_id')) {
    /** หมวดที่ใช้คำนวณงบ — หมวดย่อยชี้ไปหมวดหลัก */
    function tnc_site_category_budget_id(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }
        $parentId = tnc_site_category_parent_id($id);

        return $parentId > 0 ? $parentId : $id;
    }
}

if (!function_exists('tnc_site_category_matches_budget')) {
    function tnc_site_category_matches_budget(int $docCategoryId, int $budgetCategoryId): bool
    {
        if ($docCategoryId <= 0 || $budgetCategoryId <= 0) {
            return false;
        }
        if ($docCategoryId === $budgetCategoryId) {
            return true;
        }

        return tnc_site_category_budget_id($docCategoryId) === $budgetCategoryId;
    }
}

if (!function_exists('tnc_site_category_display_name')) {
    function tnc_site_category_display_name(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $row = Db::rowByIdField('site_cost_categories', $id);
        if (!is_array($row)) {
            return '';
        }
        $name = trim((string) ($row['name'] ?? ''));
        $parentId = (int) ($row['parent_id'] ?? 0);
        if ($parentId <= 0) {
            return $name;
        }
        $parentName = tnc_site_category_name($parentId);
        if ($parentName === '') {
            return $name;
        }

        return $parentName . ' › ' . $name;
    }
}

if (!function_exists('tnc_site_category_document_name')) {
    /** ชื่อหมวดย่อย (leaf) — ใช้บนฟอร์ม/หน้าจอ */
    function tnc_site_category_document_name(int $id, string $storedName = ''): string
    {
        if ($id > 0) {
            $resolved = tnc_site_category_name($id);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        $storedName = trim($storedName);
        if ($storedName === '') {
            return '';
        }
        if (str_contains($storedName, ' › ')) {
            $parts = explode(' › ', $storedName);

            return trim((string) end($parts));
        }

        return $storedName;
    }
}

if (!function_exists('tnc_site_category_document_parent_name')) {
    /** ชื่อหมวดหลักสำหรับพิมพ์ PR/PO — ถ้าเลือกหมวดย่อย แสดงหมวดหลัก */
    function tnc_site_category_document_parent_name(int $id, string $storedName = ''): string
    {
        if ($id > 0) {
            $parentId = tnc_site_category_parent_id($id);
            if ($parentId > 0) {
                $parentName = tnc_site_category_name($parentId);
                if ($parentName !== '') {
                    return $parentName;
                }
            }

            return tnc_site_category_document_name($id, $storedName);
        }

        $storedName = trim($storedName);
        if ($storedName === '') {
            return '';
        }
        if (str_contains($storedName, ' › ')) {
            return trim((string) explode(' › ', $storedName)[0]);
        }

        return $storedName;
    }
}

if (!function_exists('tnc_site_category_is_valid_selection_for_site')) {
    function tnc_site_category_is_valid_selection_for_site(int $id, int $siteId): bool
    {
        if (!tnc_site_category_is_valid_for_site($id, $siteId)) {
            return false;
        }

        return tnc_site_category_is_selectable($id);
    }
}

if (!function_exists('tnc_site_category_build_select_options')) {
    /**
     * โครงสร้างสำหรับ <select> / JS
     *
     * @return list<array<string,mixed>>
     */
    function tnc_site_category_build_select_options(int $siteId): array
    {
        $rows = tnc_site_categories_for_site($siteId);
        $parents = [];
        $childrenByParent = [];
        foreach ($rows as $r) {
            $cid = (int) ($r['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $parentId = (int) ($r['parent_id'] ?? 0);
            if ($parentId > 0) {
                $childrenByParent[$parentId][] = $r;
                continue;
            }
            $parents[] = $r;
        }

        $options = [];
        foreach ($parents as $parent) {
            $pid = (int) ($parent['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $children = $childrenByParent[$pid] ?? [];
            if ($children === []) {
                if (tnc_site_category_is_selectable($pid)) {
                    $options[] = [
                        'type' => 'option',
                        'id' => $pid,
                        'label' => (string) ($parent['name'] ?? ''),
                    ];
                }
                continue;
            }
            $items = [];
            foreach ($children as $child) {
                $childId = (int) ($child['id'] ?? 0);
                if ($childId <= 0 || !tnc_site_category_is_selectable($childId)) {
                    continue;
                }
                $items[] = [
                    'id' => $childId,
                    'label' => (string) ($child['name'] ?? ''),
                ];
            }
            if ($items !== []) {
                $options[] = [
                    'type' => 'group',
                    'label' => (string) ($parent['name'] ?? ''),
                    'items' => $items,
                ];
            }
        }

        return $options;
    }
}

if (!function_exists('tnc_site_category_render_select_options')) {
    function tnc_site_category_render_select_options(array $options, int $selectedId = 0): void
    {
        $hasSelected = false;
        foreach ($options as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['type'] ?? '') === 'group' && is_array($entry['items'] ?? null)) {
                $label = htmlspecialchars((string) ($entry['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                echo '<optgroup label="' . $label . '">';
                foreach ($entry['items'] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $id = (int) ($item['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $sel = $id === $selectedId;
                    if ($sel) {
                        $hasSelected = true;
                    }
                    echo '<option value="' . $id . '"' . ($sel ? ' selected' : '') . '>'
                        . htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8')
                        . '</option>';
                }
                echo '</optgroup>';
                continue;
            }
            $id = (int) ($entry['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $sel = $id === $selectedId;
            if ($sel) {
                $hasSelected = true;
            }
            echo '<option value="' . $id . '"' . ($sel ? ' selected' : '') . '>'
                . htmlspecialchars((string) ($entry['label'] ?? $entry['name'] ?? ''), ENT_QUOTES, 'UTF-8')
                . '</option>';
        }
        if (!$hasSelected && $selectedId > 0) {
            echo '<option value="' . $selectedId . '" selected>'
                . htmlspecialchars(tnc_site_category_display_name($selectedId), ENT_QUOTES, 'UTF-8')
                . '</option>';
        }
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
     * แผนที่สำหรับส่งให้ JS: [siteId => select options จาก tnc_site_category_build_select_options]
     * คีย์ 0 = หมวดกลางอย่างเดียว (ไม่มีหมวดเฉพาะไซต์)
     *
     * @return array<int,list<array<string,mixed>>>
     */
    function tnc_site_categories_map_by_site(): array
    {
        $global = tnc_site_category_build_select_options(0);
        $siteIds = [];
        foreach (tnc_site_categories_all(true) as $r) {
            $sid = (int) ($r['site_id'] ?? 0);
            if ($sid > 0) {
                $siteIds[$sid] = true;
            }
        }
        $map = [0 => $global];
        foreach (array_keys($siteIds) as $sid) {
            $map[(int) $sid] = tnc_site_category_build_select_options((int) $sid);
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
            if ((int) ($r['parent_id'] ?? 0) !== 0) {
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

if (!function_exists('tnc_site_category_sub_percent_sum')) {
    /** รวม budget_percent ของหมวดย่อยใต้หมวดหลัก (เฉพาะหมวดของไซต์นั้น) */
    function tnc_site_category_sub_percent_sum(int $parentId, int $siteId, ?int $excludeCategoryId = null): float
    {
        if ($parentId <= 0 || $siteId <= 0) {
            return 0.0;
        }
        $sum = 0.0;
        foreach (tnc_site_category_child_ids($parentId) as $childId) {
            if ($excludeCategoryId !== null && $excludeCategoryId > 0 && $childId === $excludeCategoryId) {
                continue;
            }
            $row = Db::rowByIdField('site_cost_categories', $childId);
            if (!is_array($row) || (int) ($row['active'] ?? 1) !== 1) {
                continue;
            }
            if ((int) ($row['site_id'] ?? 0) !== $siteId) {
                continue;
            }
            if (!array_key_exists('budget_percent', $row) || $row['budget_percent'] === '' || $row['budget_percent'] === null) {
                continue;
            }
            $pct = round((float) $row['budget_percent'], 2);
            if ($pct > 0.0) {
                $sum += $pct;
            }
        }

        return round($sum, 2);
    }
}

if (!function_exists('tnc_site_category_sub_percent_would_exceed')) {
    function tnc_site_category_sub_percent_would_exceed(int $parentId, int $siteId, ?float $newPercent, ?int $excludeCategoryId = null): bool
    {
        if ($parentId <= 0 || $siteId <= 0 || $newPercent === null || $newPercent <= 0.0) {
            return false;
        }
        $sum = tnc_site_category_sub_percent_sum($parentId, $siteId, $excludeCategoryId);

        return round($sum + $newPercent, 2) > 100.0 + 0.0001;
    }
}

if (!function_exists('tnc_site_category_save')) {
    /**
     * สร้าง/แก้ไขหมวด คืนค่า id
     *
     * @return int|array{id:int}|array{error:string} 0 = ชื่อว่าง, array error = รวม % เกิน 100
     */
    function tnc_site_category_save(int $id, int $siteId, string $name, int $sortOrder = 0, ?float $budgetPercent = null, int $parentId = 0)
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $name = mb_substr($name, 0, 150);
        $parentId = max(0, $parentId);
        if ($parentId > 0) {
            $parentRow = Db::rowByIdField('site_cost_categories', $parentId);
            if (!is_array($parentRow) || (int) ($parentRow['parent_id'] ?? 0) !== 0) {
                return ['error' => 'invalid_parent'];
            }
            $parentSiteId = (int) ($parentRow['site_id'] ?? 0);
            if ($parentSiteId !== 0 && $parentSiteId !== $siteId) {
                return ['error' => 'invalid_parent'];
            }
            if ($siteId > 0 && $parentSiteId === 0) {
                // หมวดย่อยใต้หมวดกลาง — ใช้ site_id ของไซต์ที่สร้าง
            } elseif ($siteId <= 0) {
                return ['error' => 'invalid_parent'];
            }
        }
        $pctStored = null;
        if ($budgetPercent !== null) {
            $pctStored = round(max(0.0, min(100.0, $budgetPercent)), 2);
        }
        if ($siteId > 0 && $parentId === 0 && $pctStored !== null && $pctStored > 0.0 && tnc_site_category_percent_would_exceed($siteId, $pctStored, $id > 0 ? $id : null)) {
            return ['error' => 'percent_sum_exceeded'];
        }
        if ($siteId > 0 && $parentId > 0 && $pctStored !== null && $pctStored > 0.0 && tnc_site_category_sub_percent_would_exceed($parentId, $siteId, $pctStored, $id > 0 ? $id : null)) {
            return ['error' => 'sub_percent_sum_exceeded'];
        }
        $pctField = $pctStored !== null ? $pctStored : '';
        if ($id > 0) {
            $pk = Db::pkForLogicalId('site_cost_categories', $id);
            $cur = $pk !== null ? Db::row('site_cost_categories', $pk) : null;
            if (is_array($cur)) {
                if ($parentId > 0 && tnc_site_category_has_children($id)) {
                    return ['error' => 'has_children'];
                }
                $merge = [
                    'name' => $name,
                    'site_id' => $siteId,
                    'sort_order' => $sortOrder,
                    'parent_id' => $parentId,
                ];
                $merge['budget_percent'] = $pctField;
                Db::setRow('site_cost_categories', $pk, array_merge($cur, $merge));

                return $id;
            }
        }
        $nid = Db::nextNumericId('site_cost_categories', 'id');
        Db::setRow('site_cost_categories', (string) $nid, [
            'id' => $nid,
            'site_id' => $siteId,
            'parent_id' => $parentId,
            'name' => $name,
            'sort_order' => $sortOrder,
            'active' => 1,
            'budget_percent' => $pctField,
        ]);

        return $nid;
    }
}

if (!function_exists('tnc_site_category_belongs_to_site')) {
    /** หมวดที่ site-hub จัดการได้ = หมวดที่ site_id ตรงกับไซต์ (ไม่รวมหมวดกลาง site_id=0) */
    function tnc_site_category_belongs_to_site(int $id, int $siteId): bool
    {
        if ($id <= 0 || $siteId <= 0) {
            return false;
        }
        $row = Db::rowByIdField('site_cost_categories', $id);
        if (!is_array($row) || (int) ($row['active'] ?? 1) !== 1) {
            return false;
        }

        return (int) ($row['site_id'] ?? 0) === $siteId;
    }
}

if (!function_exists('tnc_site_category_reference_count')) {
    /** นับ PR/PO ที่อ้างอิงหมวดนี้ */
    function tnc_site_category_reference_count(int $categoryId): int
    {
        $refs = tnc_site_category_list_references($categoryId);

        return (int) ($refs['total'] ?? 0);
    }
}

if (!function_exists('tnc_site_category_pr_status_label')) {
    function tnc_site_category_pr_status_label(array $pr): string
    {
        if (function_exists('line_pr_status_label_th') && function_exists('line_pr_normalize_status')) {
            return line_pr_status_label_th(line_pr_normalize_status($pr));
        }
        $st = strtolower(trim((string) ($pr['status'] ?? '')));

        return match ($st) {
            'approved', 'ready' => 'อนุมัติแล้ว',
            'rejected' => 'ไม่อนุมัติ',
            'pending' => 'รออนุมัติ',
            default => $st !== '' ? $st : '—',
        };
    }
}

if (!function_exists('tnc_site_category_po_status_label')) {
    function tnc_site_category_po_status_label(array $po): string
    {
        $st = strtolower(trim((string) ($po['status'] ?? '')));
        if (in_array($st, ['cancelled', 'canceled'], true)) {
            return 'ยกเลิก';
        }
        $billing = strtolower(trim((string) ($po['billing_status'] ?? 'pending')));
        if ($billing === 'billed') {
            return 'ชำระแล้ว';
        }
        if ($billing === 'partial') {
            return 'ชำระบางส่วน';
        }

        return 'ใช้งาน';
    }
}

if (!function_exists('tnc_site_category_format_doc_date')) {
    function tnc_site_category_format_doc_date(array $row, string $field = 'created_at'): string
    {
        $raw = trim((string) ($row[$field] ?? ''));
        if ($raw === '') {
            return '—';
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }

        return date('d/m/Y', $ts);
    }
}

if (!function_exists('tnc_site_category_document_source')) {
    /** ชื่อผู้ขาย / ผู้รับจ้าง / แหล่งซื้อ จาก PR หรือ PO */
    function tnc_site_category_document_source(array $row, string $docType): string
    {
        $docType = strtolower(trim($docType));
        if ($docType === 'po') {
            if (!function_exists('tnc_purchase_report_supplier_name')) {
                require_once __DIR__ . '/purchase_print/vat_print_summary.php';
            }
            if (function_exists('tnc_purchase_report_supplier_name')) {
                $name = trim(tnc_purchase_report_supplier_name($row));
                if ($name !== '') {
                    return $name;
                }
            }

            $name = trim((string) ($row['supplier_name'] ?? ''));

            return $name !== '' ? $name : '—';
        }

        $prId = (int) ($row['id'] ?? 0);
        if ($prId > 0) {
            $linkedPo = Db::findFirst('purchase_orders', static function (array $poRow) use ($prId): bool {
                return (int) ($poRow['pr_id'] ?? 0) === $prId;
            });
            if (is_array($linkedPo)) {
                return tnc_site_category_document_source($linkedPo, 'po');
            }
        }

        return '—';
    }
}

if (!function_exists('tnc_site_category_format_pr_reference')) {
    /** @return array<string,mixed>|null */
    function tnc_site_category_format_pr_reference(array $row): ?array
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $number = trim((string) ($row['pr_number'] ?? ''));
        if ($number === '') {
            $number = 'PR-' . $id;
        }
        $netAmount = round((float) ($row['total_amount'] ?? 0), 2);
        $source = tnc_site_category_document_source($row, 'pr');

        return [
            'id' => $id,
            'number' => $number,
            'status' => strtolower(trim((string) ($row['status'] ?? ''))),
            'status_label' => tnc_site_category_pr_status_label($row),
            'url' => app_path('pages/purchase/purchase-request-view.php') . '?id=' . $id,
            'date' => tnc_site_category_format_doc_date($row),
            'amount' => $netAmount,
            'net_amount' => $netAmount,
            'source' => $source,
        ];
    }
}

if (!function_exists('tnc_site_category_format_po_reference')) {
    /** @return array<string,mixed>|null */
    function tnc_site_category_format_po_reference(array $row): ?array
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $number = trim((string) ($row['po_number'] ?? ''));
        if ($number === '') {
            $number = 'PO-' . $id;
        }
        $orderType = trim((string) ($row['order_type'] ?? 'purchase'));
        if ($orderType !== 'purchase') {
            $orderType = 'purchase';
        }
        $netAmount = round((float) ($row['total_amount'] ?? 0), 2);
        if ($netAmount <= 0.0) {
            $netAmount = round((float) ($row['payable_amount'] ?? ($row['gross_amount'] ?? 0)), 2);
        }
        $source = tnc_site_category_document_source($row, 'po');
        if (!function_exists('tnc_purchase_po_missing_reasons')) {
            require_once __DIR__ . '/purchase_po_payment_slips.php';
        }
        $missingReasons = tnc_purchase_po_missing_reasons($row);
        $issueDateYmd = '';
        if (function_exists('tnc_po_issue_date_ymd')) {
            $issueDateYmd = tnc_po_issue_date_ymd($row);
        } else {
            $issueDateYmd = trim((string) ($row['issue_date'] ?? $row['supplier_invoice_date'] ?? ''));
        }
        $vatAmount = round((float) ($row['vat_amount'] ?? 0), 2);

        return [
            'id' => $id,
            'number' => $number,
            'status' => strtolower(trim((string) ($row['status'] ?? ''))),
            'status_label' => tnc_site_category_po_status_label($row),
            'url' => app_path('pages/purchase/purchase-order-view.php') . '?id=' . $id,
            'date' => tnc_site_category_format_doc_date($row),
            'amount' => $netAmount,
            'net_amount' => $netAmount,
            'vat_amount' => $vatAmount,
            'source' => $source,
            'order_type' => $orderType,
            'incomplete' => $missingReasons !== [],
            'missing_reasons' => $missingReasons,
            'need_payment' => in_array('ขาดหลักฐานการชำระ', $missingReasons, true),
            'need_invoice' => in_array('ขาดเลขที่ใบกำกับ', $missingReasons, true),
            'issue_date_ymd' => $issueDateYmd,
        ];
    }
}

if (!function_exists('tnc_site_category_references_site_index')) {
    /**
     * สแกน PR/PO ของไซต์ครั้งเดียว จัดกลุ่มตาม cost_category_id
     *
     * @return array{prs_by_cat: array<int, list<array<string,mixed>>>, pos_by_cat: array<int, list<array<string,mixed>>>}
     */
    function tnc_site_category_references_site_index(int $siteId): array
    {
        static $cache = [];
        if (isset($cache[$siteId])) {
            return $cache[$siteId];
        }
        if ($siteId <= 0) {
            return ['prs_by_cat' => [], 'pos_by_cat' => []];
        }

        $prsByCat = [];
        if (!function_exists('tnc_site_purchase_requests_cached')) {
            require_once __DIR__ . '/sites.php';
        }
        foreach (tnc_site_purchase_requests_cached() as $row) {
            if (!is_array($row) || (int) ($row['site_id'] ?? 0) !== $siteId) {
                continue;
            }
            $catId = (int) ($row['cost_category_id'] ?? 0);
            if ($catId <= 0) {
                continue;
            }
            $formatted = tnc_site_category_format_pr_reference($row);
            if ($formatted === null) {
                continue;
            }
            $prsByCat[$catId][] = $formatted;
        }

        $posByCat = [];
        if (!function_exists('tnc_site_budget_purchase_orders_cached')) {
            require_once __DIR__ . '/site_budget.php';
        }
        foreach (tnc_site_budget_purchase_orders_cached() as $row) {
            if (!is_array($row) || (int) ($row['site_id'] ?? 0) !== $siteId) {
                continue;
            }
            $catId = (int) ($row['cost_category_id'] ?? 0);
            if ($catId <= 0) {
                continue;
            }
            $formatted = tnc_site_category_format_po_reference($row);
            if ($formatted === null) {
                continue;
            }
            $posByCat[$catId][] = $formatted;
        }

        $cache[$siteId] = ['prs_by_cat' => $prsByCat, 'pos_by_cat' => $posByCat];

        return $cache[$siteId];
    }
}

if (!function_exists('tnc_site_category_match_ids')) {
    /** id หมวดหลัก + หมวดย่อยทั้งหมด (ใช้กรอง PR/PO / งบ) */
    function tnc_site_category_match_ids(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }
        $ids = [$categoryId];
        foreach (tnc_site_category_child_ids($categoryId) as $childId) {
            $ids[] = $childId;
        }

        return $ids;
    }
}

if (!function_exists('tnc_site_category_list_references')) {
    /**
     * รายการ PR/PO ที่อ้างอิงหมวดค่าใช้จ่าย
     *
     * @return array{
     *   category_id:int,
     *   category_name:string,
     *   prs:list<array{id:int,number:string,status:string,status_label:string,url:string,date:string,amount:float,net_amount:float,source:string}>,
     *   pos:list<array{id:int,number:string,status:string,status_label:string,url:string,date:string,amount:float,net_amount:float,source:string,order_type:string}>,
     *   total:int
     * }
     */
    function tnc_site_category_list_references(int $categoryId, ?int $siteId = null): array
    {
        if ($categoryId <= 0) {
            return [
                'category_id' => 0,
                'category_name' => '',
                'prs' => [],
                'pos' => [],
                'total' => 0,
            ];
        }

        $matchIds = tnc_site_category_match_ids($categoryId);
        $prs = [];
        $pos = [];

        if ($siteId !== null && $siteId > 0) {
            $index = tnc_site_category_references_site_index($siteId);
            foreach ($matchIds as $mid) {
                foreach ($index['prs_by_cat'][$mid] ?? [] as $prRow) {
                    $prs[] = $prRow;
                }
                foreach ($index['pos_by_cat'][$mid] ?? [] as $poRow) {
                    $pos[] = $poRow;
                }
            }
        } else {
            foreach (tnc_site_purchase_requests_cached() as $row) {
                if (!is_array($row) || !in_array((int) ($row['cost_category_id'] ?? 0), $matchIds, true)) {
                    continue;
                }
                $formatted = tnc_site_category_format_pr_reference($row);
                if ($formatted !== null) {
                    $prs[] = $formatted;
                }
            }

            if (!function_exists('tnc_site_budget_purchase_orders_cached')) {
                require_once __DIR__ . '/site_budget.php';
            }
            foreach (tnc_site_budget_purchase_orders_cached() as $row) {
                if (!is_array($row) || !in_array((int) ($row['cost_category_id'] ?? 0), $matchIds, true)) {
                    continue;
                }
                $formatted = tnc_site_category_format_po_reference($row);
                if ($formatted !== null) {
                    $pos[] = $formatted;
                }
            }
        }

        usort($prs, static fn (array $a, array $b): int => ($b['id'] <=> $a['id']));
        usort($pos, static fn (array $a, array $b): int => ($b['id'] <=> $a['id']));

        return [
            'category_id' => $categoryId,
            'category_name' => tnc_site_category_name($categoryId),
            'prs' => $prs,
            'pos' => $pos,
            'total' => count($prs) + count($pos),
        ];
    }
}

if (!function_exists('tnc_site_category_resolve_selection')) {
    /**
     * @return array{ok:bool,id:int,name:string}
     */
    function tnc_site_category_resolve_selection(int $id, int $siteId): array
    {
        if ($id <= 0 || !tnc_site_category_is_valid_selection_for_site($id, $siteId)) {
            return ['ok' => false, 'id' => 0, 'name' => ''];
        }

        return [
            'ok' => true,
            'id' => $id,
            'name' => tnc_site_category_display_name($id),
        ];
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

if (!function_exists('tnc_site_category_is_descendant')) {
    /** ตรวจว่า $candidateId อยู่ใต้ $ancestorId (หมวดย่อยโดยตรงหรือซ้อนลึก) */
    function tnc_site_category_is_descendant(int $ancestorId, int $candidateId): bool
    {
        if ($ancestorId <= 0 || $candidateId <= 0 || $ancestorId === $candidateId) {
            return false;
        }
        $parentId = tnc_site_category_parent_id($candidateId);
        if ($parentId === $ancestorId) {
            return true;
        }
        if ($parentId <= 0) {
            return false;
        }

        return tnc_site_category_is_descendant($ancestorId, $parentId);
    }
}

if (!function_exists('tnc_site_category_validate_parent_for_site')) {
    /**
     * ตรวจว่า parent_id เป็นหมวดหลักที่ใช้ได้กับไซต์
     *
     * @return array{ok:bool,error_code?:string}
     */
    function tnc_site_category_validate_parent_for_site(int $parentId, int $siteId): array
    {
        if ($parentId <= 0 || $siteId <= 0) {
            return ['ok' => false, 'error_code' => 'invalid_parent'];
        }
        $parentRow = Db::rowByIdField('site_cost_categories', $parentId);
        if (!is_array($parentRow) || (int) ($parentRow['parent_id'] ?? 0) !== 0) {
            return ['ok' => false, 'error_code' => 'invalid_parent'];
        }
        if ((int) ($parentRow['active'] ?? 1) !== 1) {
            return ['ok' => false, 'error_code' => 'invalid_parent'];
        }
        $parentSiteId = (int) ($parentRow['site_id'] ?? 0);
        if ($parentSiteId !== 0 && $parentSiteId !== $siteId) {
            return ['ok' => false, 'error_code' => 'invalid_parent'];
        }

        return ['ok' => true];
    }
}

if (!function_exists('tnc_site_category_remap_documents_for_site')) {
    /**
     * เปลี่ยนหมวด cost_category ใน PR/PO ของไซต์ (เฉพาะเอกสารที่อ้างอิง source ตรงๆ)
     *
     * @return array{
     *   ok:bool,
     *   pr_updated:int,
     *   po_updated:int,
     *   failed:int,
     *   results:list<array<string,mixed>>,
     *   error_code?:string|null
     * }
     */
    function tnc_site_category_remap_documents_for_site(int $siteId, int $sourceCategoryId, int $targetCategoryId): array
    {
        $fail = static function (string $code): array {
            return [
                'ok' => false,
                'pr_updated' => 0,
                'po_updated' => 0,
                'failed' => 0,
                'results' => [],
                'error_code' => $code,
            ];
        };

        if ($siteId <= 0 || $sourceCategoryId <= 0 || $targetCategoryId <= 0) {
            return $fail('invalid');
        }
        if ($sourceCategoryId === $targetCategoryId) {
            return $fail('same_category');
        }
        if (!tnc_site_category_is_valid_for_site($sourceCategoryId, $siteId)) {
            return $fail('forbidden');
        }
        $targetResolved = tnc_site_category_resolve_selection($targetCategoryId, $siteId);
        if (empty($targetResolved['ok'])) {
            return $fail('invalid_target');
        }
        $targetName = (string) ($targetResolved['name'] ?? '');

        if (!function_exists('tnc_site_purchase_requests_cached')) {
            require_once __DIR__ . '/sites.php';
        }
        if (!function_exists('tnc_site_budget_purchase_orders_cached')) {
            require_once __DIR__ . '/site_budget.php';
        }
        require_once __DIR__ . '/pr_po_sync.php';

        $prUpdated = 0;
        $poUpdated = 0;
        $failed = 0;
        $results = [];
        $updatedPoIds = [];

        $applyPoCategory = static function (int $poId, array $poRow) use (
            $siteId,
            $targetCategoryId,
            $targetName,
            &$poUpdated,
            &$failed,
            &$results,
            &$updatedPoIds
        ): bool {
            if ($poId <= 0) {
                return false;
            }
            if ((int) ($poRow['cost_category_id'] ?? 0) === $targetCategoryId) {
                $updatedPoIds[$poId] = $poId;

                return true;
            }
            $netAmount = round((float) ($poRow['total_amount'] ?? 0), 2);
            if ($netAmount <= 0.0) {
                $netAmount = round((float) ($poRow['payable_amount'] ?? ($poRow['gross_amount'] ?? 0)), 2);
            }
            if (!\Theelincon\Rtdb\Purchase::poPaidLocksMutation($poRow)) {
                $budget = tnc_site_budget_validate($siteId, $targetCategoryId, $netAmount, $poId);
                if (empty($budget['ok'])) {
                    ++$failed;
                    $results[] = [
                        'doc_type' => 'po',
                        'doc_id' => $poId,
                        'doc_number' => trim((string) ($poRow['po_number'] ?? '')),
                        'status' => 'failed',
                        'error_code' => (string) ($budget['error_code'] ?? 'budget'),
                    ];

                    return false;
                }
            }
            $pk = Db::pkForLogicalId('purchase_orders', $poId);
            if ($pk === null || $pk === '') {
                ++$failed;
                $results[] = [
                    'doc_type' => 'po',
                    'doc_id' => $poId,
                    'status' => 'failed',
                    'error_code' => 'not_found',
                ];

                return false;
            }
            $before = Db::row('purchase_orders', $pk) ?? $poRow;
            $after = array_merge($before, [
                'cost_category_id' => $targetCategoryId,
                'cost_category_name' => $targetName,
            ]);
            Db::setRow('purchase_orders', $pk, $after);
            ++$poUpdated;
            $updatedPoIds[$poId] = $poId;
            $results[] = [
                'doc_type' => 'po',
                'doc_id' => $poId,
                'doc_number' => trim((string) ($before['po_number'] ?? '')),
                'status' => 'updated',
                'before' => $before,
                'after' => $after,
            ];

            return true;
        };

        foreach (tnc_site_purchase_requests_cached() as $prRow) {
            if (!is_array($prRow) || (int) ($prRow['site_id'] ?? 0) !== $siteId) {
                continue;
            }
            if ((int) ($prRow['cost_category_id'] ?? 0) !== $sourceCategoryId) {
                continue;
            }
            $prId = (int) ($prRow['id'] ?? 0);
            if ($prId <= 0) {
                continue;
            }
            $pk = Db::pkForLogicalId('purchase_requests', $prId);
            if ($pk === null || $pk === '') {
                ++$failed;
                continue;
            }
            $before = Db::row('purchase_requests', $pk) ?? $prRow;
            $afterRow = array_merge($before, [
                'cost_category_id' => $targetCategoryId,
                'cost_category_name' => $targetName,
            ]);
            Db::setRow('purchase_requests', $pk, $afterRow);
            ++$prUpdated;
            $results[] = [
                'doc_type' => 'pr',
                'doc_id' => $prId,
                'doc_number' => trim((string) ($before['pr_number'] ?? '')),
                'status' => 'updated',
                'before' => $before,
                'after' => $afterRow,
            ];

            foreach (\Theelincon\Rtdb\Purchase::collectPurchaseOrdersForPr($prId) as $po) {
                $poId = (int) ($po['id'] ?? 0);
                if ($poId <= 0 || isset($updatedPoIds[$poId])) {
                    continue;
                }
                if (trim((string) ($po['order_type'] ?? 'purchase')) !== 'purchase') {
                    continue;
                }
                if (strtolower(trim((string) ($po['status'] ?? ''))) === 'cancelled') {
                    continue;
                }
                $freshPo = Db::rowByIdField('purchase_orders', $poId) ?? $po;
                if ((int) ($freshPo['cost_category_id'] ?? 0) !== $sourceCategoryId) {
                    continue;
                }
                if (\Theelincon\Rtdb\Purchase::poPaidLocksMutation($freshPo)) {
                    $applyPoCategory($poId, $freshPo);
                    continue;
                }
                $sync = tnc_pr_sync_purchase_po_from_pr(
                    $poId,
                    $freshPo,
                    $afterRow,
                    tnc_pr_load_purchase_line_items($prId)
                );
                if (!empty($sync['ok'])) {
                    $updatedPoIds[$poId] = $poId;
                    ++$poUpdated;
                    $results[] = [
                        'doc_type' => 'po',
                        'doc_id' => $poId,
                        'doc_number' => trim((string) ($freshPo['po_number'] ?? '')),
                        'status' => 'updated',
                        'via' => 'pr_sync',
                    ];
                    continue;
                }
                $applyPoCategory($poId, $freshPo);
            }
        }

        foreach (tnc_site_budget_purchase_orders_cached() as $poRow) {
            if (!is_array($poRow) || (int) ($poRow['site_id'] ?? 0) !== $siteId) {
                continue;
            }
            if ((int) ($poRow['cost_category_id'] ?? 0) !== $sourceCategoryId) {
                continue;
            }
            $poId = (int) ($poRow['id'] ?? 0);
            if ($poId <= 0 || isset($updatedPoIds[$poId])) {
                continue;
            }
            if (strtolower(trim((string) ($poRow['status'] ?? ''))) === 'cancelled') {
                continue;
            }
            $applyPoCategory($poId, $poRow);
        }

        if ($prUpdated <= 0 && $poUpdated <= 0 && $failed <= 0) {
            return $fail('no_documents');
        }

        return [
            'ok' => $failed === 0 && ($prUpdated > 0 || $poUpdated > 0),
            'pr_updated' => $prUpdated,
            'po_updated' => $poUpdated,
            'failed' => $failed,
            'results' => $results,
            'error_code' => $failed > 0 ? 'partial' : null,
        ];
    }
}

if (!function_exists('tnc_site_category_move_parent')) {
    /**
     * ย้ายหมวดไปอยู่ใต้หมวดหลักอื่น
     * หมวดหลักที่มีหมวดย่อย — หมวดย่อยเดิมจะถูกย้ายไปอยู่ใต้หมวดหลักปลายทางด้วย (โครงสร้าง 2 ระดับ)
     *
     * @return array{ok:bool,error_code?:string,no_change?:bool,before?:array<string,mixed>,after?:array<string,mixed>,reparented_children?:list<int>}
     */
    function tnc_site_category_move_parent(int $categoryId, int $newParentId, int $siteId): array
    {
        if ($categoryId <= 0 || $siteId <= 0) {
            return ['ok' => false, 'error_code' => 'invalid'];
        }
        if ($newParentId <= 0) {
            return ['ok' => false, 'error_code' => 'invalid_parent'];
        }
        if ($categoryId === $newParentId) {
            return ['ok' => false, 'error_code' => 'invalid_parent'];
        }
        if (!tnc_site_category_belongs_to_site($categoryId, $siteId)) {
            return ['ok' => false, 'error_code' => 'forbidden'];
        }
        $row = Db::rowByIdField('site_cost_categories', $categoryId);
        if (!is_array($row)) {
            return ['ok' => false, 'error_code' => 'not_found'];
        }
        $currentParentId = (int) ($row['parent_id'] ?? 0);
        if ($currentParentId === $newParentId) {
            return ['ok' => true, 'no_change' => true, 'before' => $row];
        }
        if (tnc_site_category_is_descendant($categoryId, $newParentId)) {
            return ['ok' => false, 'error_code' => 'cycle'];
        }
        $parentCheck = tnc_site_category_validate_parent_for_site($newParentId, $siteId);
        if (empty($parentCheck['ok'])) {
            return ['ok' => false, 'error_code' => (string) ($parentCheck['error_code'] ?? 'invalid_parent')];
        }

        $reparentedChildren = [];
        if ($currentParentId === 0) {
            foreach (tnc_site_category_child_ids($categoryId) as $childId) {
                if ($childId <= 0 || $childId === $newParentId) {
                    continue;
                }
                $childRow = Db::rowByIdField('site_cost_categories', $childId);
                if (!is_array($childRow)) {
                    continue;
                }
                if ((int) ($childRow['parent_id'] ?? 0) === $newParentId) {
                    continue;
                }
                $childPk = Db::pkForLogicalId('site_cost_categories', $childId);
                if ($childPk === null) {
                    continue;
                }
                Db::setRow('site_cost_categories', $childPk, array_merge($childRow, [
                    'parent_id' => $newParentId,
                ]));
                $reparentedChildren[] = $childId;
            }
        }

        $pk = Db::pkForLogicalId('site_cost_categories', $categoryId);
        if ($pk === null) {
            return ['ok' => false, 'error_code' => 'not_found'];
        }
        $merge = [
            'parent_id' => $newParentId,
            'budget_percent' => '',
        ];
        Db::setRow('site_cost_categories', $pk, array_merge($row, $merge));
        $after = array_merge($row, $merge);

        return [
            'ok' => true,
            'before' => $row,
            'after' => $after,
            'reparented_children' => $reparentedChildren,
        ];
    }
}

if (!function_exists('tnc_site_category_move_batch')) {
    /**
     * ย้ายหลายหมวดไปอยู่ใต้หมวดหลักเดียวกัน
     *
     * @param list<int> $categoryIds
     * @return array{
     *   ok:bool,
     *   moved:int,
     *   skipped:int,
     *   failed:int,
     *   results:list<array<string,mixed>>,
     *   error_code?:string
     * }
     */
    function tnc_site_category_move_batch(int $siteId, array $categoryIds, int $newParentId): array
    {
        if ($siteId <= 0) {
            return ['ok' => false, 'moved' => 0, 'skipped' => 0, 'failed' => 0, 'results' => [], 'error_code' => 'invalid'];
        }
        if ($newParentId <= 0) {
            return ['ok' => false, 'moved' => 0, 'skipped' => 0, 'failed' => 0, 'results' => [], 'error_code' => 'invalid_parent'];
        }
        $parentCheck = tnc_site_category_validate_parent_for_site($newParentId, $siteId);
        if (empty($parentCheck['ok'])) {
            return ['ok' => false, 'moved' => 0, 'skipped' => 0, 'failed' => 0, 'results' => [], 'error_code' => (string) ($parentCheck['error_code'] ?? 'invalid_parent')];
        }

        $uniqueIds = [];
        foreach ($categoryIds as $rawId) {
            $cid = (int) $rawId;
            if ($cid > 0) {
                $uniqueIds[$cid] = $cid;
            }
        }
        if ($uniqueIds === []) {
            return ['ok' => false, 'moved' => 0, 'skipped' => 0, 'failed' => 0, 'results' => [], 'error_code' => 'no_selection'];
        }
        if (isset($uniqueIds[$newParentId])) {
            return ['ok' => false, 'moved' => 0, 'skipped' => 0, 'failed' => 0, 'results' => [], 'error_code' => 'invalid_parent'];
        }

        $moved = 0;
        $skipped = 0;
        $failed = 0;
        $results = [];
        foreach (array_values($uniqueIds) as $categoryId) {
            $result = tnc_site_category_move_parent($categoryId, $newParentId, $siteId);
            if (!empty($result['no_change'])) {
                ++$skipped;
                $results[] = ['category_id' => $categoryId, 'status' => 'skipped'];
                continue;
            }
            if (empty($result['ok'])) {
                ++$failed;
                $results[] = [
                    'category_id' => $categoryId,
                    'status' => 'failed',
                    'error_code' => (string) ($result['error_code'] ?? 'invalid'),
                ];
                continue;
            }
            ++$moved;
            $results[] = [
                'category_id' => $categoryId,
                'status' => 'moved',
                'before' => is_array($result['before'] ?? null) ? $result['before'] : [],
                'after' => is_array($result['after'] ?? null) ? $result['after'] : [],
            ];
        }

        return [
            'ok' => $moved > 0 && $failed === 0,
            'moved' => $moved,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $results,
            'error_code' => $failed > 0 ? 'partial' : null,
        ];
    }
}

if (!function_exists('tnc_site_category_delete_for_site')) {
    /**
     * ลบหมวดเฉพาะไซต์จาก Site Hub
     *
     * @return array{ok:bool,error_code?:string,before?:array<string,mixed>}
     */
    function tnc_site_category_delete_for_site(int $id, int $siteId): array
    {
        if ($id <= 0 || $siteId <= 0) {
            return ['ok' => false, 'error_code' => 'invalid'];
        }
        $row = Db::rowByIdField('site_cost_categories', $id);
        if (!is_array($row)) {
            return ['ok' => false, 'error_code' => 'not_found'];
        }
        if (!tnc_site_category_belongs_to_site($id, $siteId)) {
            return ['ok' => false, 'error_code' => 'forbidden'];
        }
        if (tnc_site_category_reference_count($id) > 0) {
            return ['ok' => false, 'error_code' => 'in_use'];
        }
        if (tnc_site_category_has_children($id)) {
            return ['ok' => false, 'error_code' => 'has_children'];
        }
        tnc_site_category_delete($id);

        return ['ok' => true, 'before' => $row];
    }
}
