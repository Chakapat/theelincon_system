<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

if (!function_exists('tnc_site_category_document_name')) {
    require_once __DIR__ . '/site_cost_categories.php';
}

if (!function_exists('tnc_site_category_document_name')) {
    /** ชื่อหมวดสำหรับพิมพ์ PR/PO — แสดงหมวดย่อย (leaf) จาก id เป็นหลัก */
    function tnc_site_category_document_name(int $id, string $storedName = ''): string
    {
        if ($id > 0) {
            if (function_exists('tnc_site_category_name')) {
                $resolved = tnc_site_category_name($id);
                if ($resolved !== '') {
                    return $resolved;
                }
            }
            $row = Db::rowByIdField('site_cost_categories', $id);
            if (is_array($row)) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
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
            if (function_exists('tnc_site_category_parent_id')) {
                $parentId = tnc_site_category_parent_id($id);
                if ($parentId > 0) {
                    $parentName = function_exists('tnc_site_category_name')
                        ? tnc_site_category_name($parentId)
                        : '';
                    if ($parentName !== '') {
                        return $parentName;
                    }
                    $parentRow = Db::rowByIdField('site_cost_categories', $parentId);
                    if (is_array($parentRow)) {
                        $name = trim((string) ($parentRow['name'] ?? ''));
                        if ($name !== '') {
                            return $name;
                        }
                    }
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
