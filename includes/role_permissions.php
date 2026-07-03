<?php

declare(strict_types=1);

/**
 * RBAC — สิทธิ์ตามบทบาท (Phase 1: PR / PO / Invoice)
 * ค่า override เก็บใน RTDB role_permissions/default
 */

use Theelincon\Rtdb\Db;

const TNC_ROLE_PERMISSIONS_TABLE = 'role_permissions';
const TNC_ROLE_PERMISSIONS_PK = 'default';

/** @return list<string> */
function tnc_role_permission_roles(): array
{
    return ['CEO', 'ADMIN', 'ACCOUNTING', 'USER'];
}

/**
 * @return array<string, array{label: string, group: string, hint?: string, kind?: string}>
 */
function tnc_role_action_permission_definitions(): array
{
    return [
        'pr.create' => ['label' => 'สร้าง PR', 'group' => 'ใบขอซื้อ (PR)', 'hint' => 'บันทึกใบขอซื้อใหม่', 'kind' => 'action'],
        'pr.update' => ['label' => 'แก้ไข PR', 'group' => 'ใบขอซื้อ (PR)', 'hint' => 'แก้ไข PR (รวมที่มี PO แล้ว — PO ไม่อัปเดตตามอัตโนมัติ)', 'kind' => 'action'],
        'pr.delete' => ['label' => 'ลบ PR', 'group' => 'ใบขอซื้อ (PR)', 'hint' => 'ลบใบขอซื้อ (ต้องยืนยันรหัสผ่าน)', 'kind' => 'action'],
        'pr.approve' => ['label' => 'อนุมัติ / ไม่อนุมัติ PR', 'group' => 'ใบขอซื้อ (PR)', 'hint' => 'กดอนุมัติบนเว็บ', 'kind' => 'action'],
        'pr.send_line' => ['label' => 'ส่ง PR ขออนุมัติ LINE', 'group' => 'ใบขอซื้อ (PR)', 'hint' => 'ส่งคำขออนุมัติไปกลุ่ม LINE', 'kind' => 'action'],
        'po.create' => ['label' => 'สร้าง PO', 'group' => 'ใบสั่งซื้อ (PO)', 'hint' => 'ออก PO จาก PR หรือสร้างตรง', 'kind' => 'action'],
        'po.update' => ['label' => 'แก้ไข / จ่าย / บิล PO', 'group' => 'ใบสั่งซื้อ (PO)', 'hint' => 'แก้ไข PO, สถานะจ่าย, บิลซื้อ, สลิป', 'kind' => 'action'],
        'po.cancel' => ['label' => 'ยกเลิก PO', 'group' => 'ใบสั่งซื้อ (PO)', 'hint' => 'เปลี่ยนสถานะเป็นยกเลิก', 'kind' => 'action'],
        'po.delete' => ['label' => 'ลบ PO', 'group' => 'ใบสั่งซื้อ (PO)', 'hint' => 'ลบใบสั่งซื้อที่ยังไม่จ่าย', 'kind' => 'action'],
        'invoice.edit' => ['label' => 'แก้ไข Invoice', 'group' => 'Invoice / Tax', 'hint' => 'แก้ไขใบแจ้งหนี้', 'kind' => 'action'],
        'invoice.cancel' => ['label' => 'ยกเลิก Invoice', 'group' => 'Invoice / Tax', 'hint' => 'เปลี่ยนสถานะเป็นยกเลิก (ระบุเหตุผล)', 'kind' => 'action'],
        'invoice.delete' => ['label' => 'ลบ Invoice', 'group' => 'Invoice / Tax', 'hint' => 'ลบใบแจ้งหนี้', 'kind' => 'action'],
        'invoice.tax_cancel' => ['label' => 'ยกเลิก Tax Invoice', 'group' => 'Invoice / Tax', 'hint' => 'เปลี่ยนสถานะใบกำกับภาษีเป็นยกเลิก (ระบุเหตุผล)', 'kind' => 'action'],
        'invoice.tax_delete' => ['label' => 'ลบ Tax Invoice', 'group' => 'Invoice / Tax', 'hint' => 'ลบใบกำกับภาษี', 'kind' => 'action'],
        'site.manage' => ['label' => 'จัดการไซต์งาน', 'group' => 'Site Workspace', 'hint' => 'สร้างไซต์ ตั้งงบ และหมวดค่าใช้จ่าย', 'kind' => 'action'],
    ];
}

/**
 * @return array<string, array{label: string, group: string, hint?: string, kind?: string}>
 */
function tnc_role_permission_definitions(): array
{
    $pageFile = dirname(__FILE__) . '/tnc_page_access.php';
    if (is_file($pageFile)) {
        require_once $pageFile;

        return array_merge(
            tnc_role_page_permission_definitions(),
            tnc_role_action_permission_definitions()
        );
    }

    return tnc_role_action_permission_definitions();
}

/** @return list<string> */
function tnc_role_permission_keys(): array
{
    return array_keys(tnc_role_permission_definitions());
}

/**
 * ค่าเริ่มต้น — ตรงกับพฤติกรรมเดิมก่อนมีหน้าตั้งค่า
 *
 * @return array<string, array<string, bool>>
 */
function tnc_role_permission_defaults(): array
{
    $allTrue = [];
    foreach (tnc_role_permission_keys() as $key) {
        $allTrue[$key] = true;
    }

    $accountingPagesOn = [
        'page.index' => true,
        'page.invoice.create' => true,
        'page.invoice.view' => true,
        'page.invoice.tax_list' => true,
        'page.invoice.tax' => true,
        'page.org.customer' => true,
        'page.org.company' => true,
        'site.manage' => true,
        'page.org.suppliers' => true,
        'page.site.picker' => true,
        'page.site.hub' => true,
        'page.pr' => true,
        'page.po' => true,
        'page.stock' => true,
        'page.report.vat' => true,
        'page.report.site' => true,
        'page.account.profile' => true,
        'page.tools.po_payment' => true,
        'page.org.members' => false,
        'page.cash' => false,
        'page.internal.roles' => false,
        'page.internal.audit' => false,
        'page.internal.line' => false,
        'page.internal.line_task' => false,
        'page.internal.doc_colors' => false,
    ];

    $accounting = array_merge($allTrue, $accountingPagesOn, [
        'pr.delete' => false,
        'pr.send_line' => false,
        'po.delete' => false,
        'site.manage' => false,
        'invoice.delete' => false,
        'invoice.tax_delete' => false,
        'invoice.cancel' => true,
        'invoice.tax_cancel' => true,
    ]);

    $userPagesOn = [
        'page.index' => true,
        'page.invoice.view' => true,
        'page.pr' => true,
        'page.site.picker' => true,
        'page.account.profile' => true,
    ];
    if (function_exists('tnc_role_page_registry_flat')) {
        foreach (array_keys(tnc_role_page_registry_flat()) as $pageKey) {
            if (!isset($userPagesOn[$pageKey])) {
                $userPagesOn[$pageKey] = false;
            }
        }
    }

    $user = array_merge($allTrue, $userPagesOn, [
        'pr.delete' => false,
        'pr.approve' => false,
        'pr.send_line' => false,
        'po.create' => false,
        'po.update' => false,
        'po.cancel' => false,
        'po.delete' => false,
        'invoice.edit' => false,
        'invoice.delete' => false,
        'invoice.cancel' => false,
        'invoice.tax_cancel' => false,
        'invoice.tax_delete' => false,
    ]);

    return [
        'CEO' => $allTrue,
        'ADMIN' => $allTrue,
        'ACCOUNTING' => $accounting,
        'USER' => $user,
    ];
}

/** @return array<string, mixed> */
function tnc_role_permissions_config_row(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $r = Db::row(TNC_ROLE_PERMISSIONS_TABLE, TNC_ROLE_PERMISSIONS_PK);
        $cached = is_array($r) ? $r : [];
    } catch (\Throwable $e) {
        $cached = [];
    }

    return $cached;
}

/** @return array<string, array<string, bool>> */
function tnc_role_permissions_parse_stored(mixed $stored): array
{
    if (!is_array($stored)) {
        return [];
    }

    $out = [];
    foreach (tnc_role_permission_roles() as $role) {
        $roleIn = $stored[$role] ?? null;
        if (!is_array($roleIn)) {
            continue;
        }
        $out[$role] = [];
        foreach (tnc_role_permission_keys() as $key) {
            if (!array_key_exists($key, $roleIn)) {
                $storageKey = str_replace('.', '_', $key);
                if (!array_key_exists($storageKey, $roleIn)) {
                    continue;
                }
                $raw = $roleIn[$storageKey];
            } else {
                $raw = $roleIn[$key];
            }
            $out[$role][$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        }
    }

    return $out;
}

/** @return array<string, array<string, bool>> */
function tnc_role_permissions_matrix(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $merged = tnc_role_permission_defaults();
    $row = tnc_role_permissions_config_row();
    $stored = [];

    if (!empty($row['permissions_json']) && is_string($row['permissions_json'])) {
        $decoded = json_decode($row['permissions_json'], true);
        if (is_array($decoded)) {
            $stored = $decoded;
        }
    } elseif (isset($row['permissions']) && is_array($row['permissions'])) {
        $stored = tnc_role_permissions_parse_stored($row['permissions']);
    }

    foreach (tnc_role_permission_roles() as $role) {
        if (!isset($merged[$role]) || !is_array($merged[$role])) {
            $merged[$role] = [];
        }
        $roleStored = $stored[$role] ?? null;
        if (!is_array($roleStored)) {
            continue;
        }
        foreach (tnc_role_permission_keys() as $key) {
            if (array_key_exists($key, $roleStored)) {
                $merged[$role][$key] = filter_var($roleStored[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $legacySites = $roleStored['page.org.sites'] ?? $roleStored['page_org_sites'] ?? null;
        if ($legacySites !== null && filter_var($legacySites, FILTER_VALIDATE_BOOLEAN)) {
            $merged[$role]['site.manage'] = true;
        }
    }

    $cached = $merged;

    return $cached;
}

function user_can(string $permission): bool
{
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    $permission = trim($permission);
    if ($permission === '' || !in_array($permission, tnc_role_permission_keys(), true)) {
        return false;
    }

    $role = session_role_normalized();
    $matrix = tnc_role_permissions_matrix();
    $rolePerms = $matrix[$role] ?? $matrix['USER'] ?? [];

    return !empty($rolePerms[$permission]);
}

/** @return void */
function tnc_require_can(string $permission, string $message = 'ไม่มีสิทธิ์ดำเนินการนี้'): void
{
    if (!user_can($permission)) {
        http_response_code(403);
        exit($message);
    }
}

/**
 * @param array<string, array<string, bool|string|int>> $submitted
 * @return array<string, array<string, bool>>
 */
function tnc_role_permissions_normalize_submitted(array $submitted): array
{
    $out = [];
    foreach (tnc_role_permission_roles() as $role) {
        $out[$role] = [];
        $roleIn = $submitted[$role] ?? [];
        if (!is_array($roleIn)) {
            $roleIn = [];
        }
        foreach (tnc_role_permission_keys() as $key) {
            $out[$role][$key] = !empty($roleIn[$key]);
        }
    }

    return $out;
}

/** @param array<string, array<string, bool>> $matrix */
function tnc_role_permissions_save(array $matrix, int $userId): void
{
    Db::mergeRow(TNC_ROLE_PERMISSIONS_TABLE, TNC_ROLE_PERMISSIONS_PK, [
        // เก็บเป็น JSON — คีย์ pr.create มีจุด ใช้ใน Firebase nested โดยตรงไม่ได้
        'permissions_json' => json_encode($matrix, JSON_UNESCAPED_UNICODE),
        'updated_at' => date('Y-m-d H:i:s'),
        'updated_by' => $userId,
    ]);
}
