<?php

declare(strict_types=1);

/**
 * สิทธิ์เข้าถึงหน้า (page.*) + map path → permission
 * ใช้ร่วมกับ includes/role_permissions.php
 */

/**
 * โครงเมนู: หมวดหลัก → หน้า → สิทธิ์การทำงานบนหน้านั้น
 *
 * @return array<string, array{label: string, pages: array<string, array{label: string, path: string, paths: list<string>, actions: list<string>}>}>
 */
function tnc_role_permission_menu_tree(): array
{
    return [
        'hub_home' => [
            'label' => 'หน้าแรก / Invoice',
            'pages' => [
                'page.index' => [
                    'label' => 'หน้าแรก — รายการใบแจ้งหนี้',
                    'path' => 'index.php',
                    'paths' => ['index.php'],
                    'actions' => ['invoice.edit', 'invoice.delete'],
                ],
                'page.invoice.create' => [
                    'label' => 'สร้าง / แก้ไข Invoice',
                    'path' => 'pages/invoices/invoice.php',
                    'paths' => [
                        'pages/invoices/invoice.php',
                        'pages/invoices/invoice-create.php',
                        'pages/invoices/invoice-edit.php',
                    ],
                    'actions' => ['invoice.edit'],
                ],
                'page.invoice.view' => [
                    'label' => 'ดู / พิมพ์ Invoice',
                    'path' => 'pages/invoices/invoice-view.php',
                    'paths' => ['pages/invoices/invoice-view.php'],
                    'actions' => [],
                ],
                'page.invoice.tax_list' => [
                    'label' => 'รายการใบกำกับภาษี',
                    'path' => 'pages/invoices/tax-invoice-list.php',
                    'paths' => ['pages/invoices/tax-invoice-list.php'],
                    'actions' => ['invoice.tax_delete'],
                ],
                'page.invoice.tax' => [
                    'label' => 'ออกใบกำกับภาษี / ใบเสร็จ',
                    'path' => 'pages/invoices/tax-invoice-receipt.php',
                    'paths' => ['pages/invoices/tax-invoice-receipt.php'],
                    'actions' => ['invoice.edit', 'invoice.tax_delete'],
                ],
            ],
        ],
        'hub_master' => [
            'label' => 'ข้อมูลหลัก (Information Data)',
            'pages' => [
                'page.org.customer' => [
                    'label' => 'ลูกค้า (Customer)',
                    'path' => 'pages/organization/customer-manage.php',
                    'paths' => ['pages/organization/customer-manage.php'],
                    'actions' => ['invoice.edit'],
                ],
                'page.org.company' => [
                    'label' => 'บริษัท / ผู้ประกอบการ (Company)',
                    'path' => 'pages/organization/company-manage.php',
                    'paths' => ['pages/organization/company-manage.php'],
                    'actions' => [],
                ],
                'page.org.sites' => [
                    'label' => 'ไซต์งาน (Sites)',
                    'path' => 'pages/organization/sites.php',
                    'paths' => ['pages/organization/sites.php'],
                    'actions' => [],
                ],
                'page.org.members' => [
                    'label' => 'จัดการสมาชิก (Members)',
                    'path' => 'pages/organization/member-manage.php',
                    'paths' => ['pages/organization/member-manage.php'],
                    'actions' => [],
                ],
                'page.org.suppliers' => [
                    'label' => 'ผู้ขาย (Suppliers)',
                    'path' => 'pages/suppliers/supplier-list.php',
                    'paths' => [
                        'pages/suppliers/supplier-list.php',
                        'pages/suppliers/supplier-form.php',
                    ],
                    'actions' => [],
                ],
                'page.org.contractors' => [
                    'label' => 'ผู้รับจ้าง (Contractors)',
                    'path' => 'pages/contractors/contractor-list.php',
                    'paths' => [
                        'pages/contractors/contractor-list.php',
                        'pages/contractors/contractor-form.php',
                    ],
                    'actions' => [],
                ],
            ],
        ],
        'hub_purchase' => [
            'label' => 'จัดซื้อ / จัดจ้าง (Purchase / Hire)',
            'pages' => [
                'page.pr' => [
                    'label' => 'ใบขอซื้อ (PR)',
                    'path' => 'pages/purchase/purchase-request-list.php',
                    'paths' => [
                        'pages/purchase/purchase-request-list.php',
                        'pages/purchase/purchase-request-create.php',
                        'pages/purchase/purchase-request-view.php',
                        'pages/purchase/purchase-request.php',
                    ],
                    'actions' => ['pr.create', 'pr.update', 'pr.delete', 'pr.approve', 'pr.send_line'],
                ],
                'page.po' => [
                    'label' => 'ใบสั่งซื้อ / จ่าย (PO)',
                    'path' => 'pages/purchase/purchase-order-list.php',
                    'paths' => [
                        'pages/purchase/purchase-order-list.php',
                        'pages/purchase/purchase-order-view.php',
                        'pages/purchase/purchase-order-create.php',
                        'pages/purchase/purchase-order-edit.php',
                        'pages/purchase/purchase-order-create-direct.php',
                        'pages/purchase/purchase-order-from-pr.php',
                        'pages/purchase/purchase-order.php',
                        'pages/purchase/purchase-batch-print.php',
                    ],
                    'actions' => ['po.create', 'po.update', 'po.cancel', 'po.delete'],
                ],
                'page.wo' => [
                    'label' => 'Work Order (WO) / ใบสั่งจ้าง',
                    'path' => 'pages/purchase/work-order-list.php',
                    'paths' => [
                        'pages/purchase/work-order-list.php',
                        'pages/purchase/purchase-order-hire-contract-create.php',
                        'pages/purchase/purchase-order-from-hire-contract.php',
                    ],
                    'actions' => ['po.create', 'po.update', 'po.cancel'],
                ],
                'page.hire' => [
                    'label' => 'สัญญาจ้าง (Hire Contract)',
                    'path' => 'pages/purchase/work-order-list.php',
                    'paths' => [
                        'pages/purchase/work-order-list.php',
                        'pages/purchase/purchase-order-hire-contract-create.php',
                        'pages/purchase/purchase-order-from-hire-contract.php',
                    ],
                    'actions' => ['po.create'],
                ],
            ],
        ],
        'hub_docs' => [
            'label' => 'ระบบเอกสาร (Documents)',
            'pages' => [
                'page.stock' => [
                    'label' => 'คลังสินค้า (Stock)',
                    'path' => 'pages/stock/stock-list.php',
                    'paths' => [
                        'pages/stock/stock-list.php',
                        'pages/stock/stock-adjust.php',
                        'pages/stock/stock-product-form.php',
                        'pages/stock/stock-movements.php',
                    ],
                    'actions' => [],
                ],
            ],
        ],
        'hub_cash' => [
            'label' => 'ระบบการเงิน (Cash / Reports)',
            'pages' => [
                'page.report.vat' => [
                    'label' => 'รายงานภาษีซื้อ/ขาย (VAT Report)',
                    'path' => 'pages/reports/vat-report.php',
                    'paths' => ['pages/reports/vat-report.php'],
                    'actions' => [],
                ],
                'page.report.site' => [
                    'label' => 'รายงานใช้จ่ายตามไซต์',
                    'path' => 'pages/reports/site-spending-report.php',
                    'paths' => ['pages/reports/site-spending-report.php'],
                    'actions' => [],
                ],
                'page.cash' => [
                    'label' => 'สดย่อย (Petty Cash)',
                    'path' => 'pages/cash-ledger/cash-ledger-dashboard.php',
                    'paths' => [
                        'pages/cash-ledger/cash-ledger.php',
                        'pages/cash-ledger/cash-ledger-dashboard.php',
                        'pages/cash-ledger/cash-ledger-master-stores.php',
                        'pages/cash-ledger/cash-ledger-master-sites.php',
                        'pages/cash-ledger/cash-ledger-site-expenses.php',
                    ],
                    'actions' => [],
                ],
            ],
        ],
        'hub_hr' => [
            'label' => 'HR / สวัสดิการ',
            'pages' => [
                'page.account.profile' => [
                    'label' => 'ข้อมูลส่วนตัว (My Profile)',
                    'path' => 'pages/account/my-profile.php',
                    'paths' => ['pages/account/my-profile.php'],
                    'actions' => [],
                ],
            ],
        ],
        'hub_internal' => [
            'label' => 'ตั้งค่าระบบ (Admin)',
            'pages' => [
                'page.internal.roles' => [
                    'label' => 'ตั้งค่าสิทธิ์ตามบทบาท',
                    'path' => 'pages/internal/role-permissions.php',
                    'paths' => ['pages/internal/role-permissions.php'],
                    'actions' => [],
                ],
                'page.internal.audit' => [
                    'label' => 'Audit Log',
                    'path' => 'pages/internal/audit-log.php',
                    'paths' => ['pages/internal/audit-log.php'],
                    'actions' => [],
                ],
                'page.internal.line' => [
                    'label' => 'ตั้งค่า LINE แจ้งเตือน',
                    'path' => 'pages/internal/line-notify-config.php',
                    'paths' => ['pages/internal/line-notify-config.php'],
                    'actions' => [],
                ],
            ],
        ],
        'hub_tools' => [
            'label' => 'เครื่องมือ (Tools)',
            'pages' => [
                'page.tools.po_payment' => [
                    'label' => 'เอกสารแนบการจ่าย PO',
                    'path' => 'pages/tools/po-payment-document.php',
                    'paths' => ['pages/tools/po-payment-document.php'],
                    'actions' => ['po.update'],
                ],
            ],
        ],
    ];
}

/** @return array<string, array{label: string, path: string, paths: list<string>, actions: list<string>, hub: string}> */
function tnc_role_page_registry_flat(): array
{
    static $flat = null;
    if ($flat !== null) {
        return $flat;
    }

    $flat = [];
    foreach (tnc_role_permission_menu_tree() as $hubKey => $hub) {
        foreach ($hub['pages'] as $pageKey => $page) {
            $flat[$pageKey] = array_merge($page, ['hub' => (string) $hub['label']]);
        }
    }

    return $flat;
}

/** @return array<string, string> script suffix → page permission key */
function tnc_page_path_permission_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [];
    foreach (tnc_role_page_registry_flat() as $pageKey => $page) {
        foreach ($page['paths'] as $p) {
            $norm = str_replace('\\', '/', strtolower(trim($p)));
            $map[$norm] = $pageKey;
        }
    }

    return $map;
}

function tnc_page_key_for_script(string $scriptName): ?string
{
    $script = strtolower(str_replace('\\', '/', $scriptName));
    $map = tnc_page_path_permission_map();

    foreach ($map as $path => $key) {
        $pathLower = strtolower($path);
        if ($script === $pathLower || str_ends_with($script, '/' . $pathLower)) {
            return $key;
        }
    }

    if (str_ends_with($script, '/index.php') && !str_contains($script, '/pages/')) {
        return 'page.index';
    }

    return null;
}

function user_can_access_page(string $pageKey): bool
{
    return function_exists('user_can') && user_can($pageKey);
}

/** @return never */
function tnc_require_page(string $pageKey, string $message = 'ไม่มีสิทธิ์เข้าถึงหน้านี้'): void
{
    if (!user_can_access_page($pageKey)) {
        http_response_code(403);
        if (function_exists('app_path')) {
            header('Location: ' . app_path('index.php') . '?error=forbidden');
            exit;
        }
        exit($message);
    }
}

/**
 * ตรวจสิทธิ์อัตโนมัติเมื่อโหลด pages/*.php (ยกเว้น handler / embed)
 */
function tnc_page_access_guard(): void
{
    if (!isset($_SESSION['user_id']) || !function_exists('user_can')) {
        return;
    }

    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === '') {
        return;
    }

    $lower = strtolower(str_replace('\\', '/', $script));
    if (str_contains($lower, '/actions/')
        || str_contains($lower, 'sign-in.php')
        || str_contains($lower, 'sign-out.php')) {
        return;
    }

    $pageKey = tnc_page_key_for_script($script);
    if ($pageKey === null) {
        return;
    }

    if (!user_can($pageKey)) {
        http_response_code(403);
        header('Location: ' . app_path('index.php') . '?error=forbidden');
        exit;
    }
}

/**
 * @return array<string, array{label: string, group: string, hint?: string, kind: string}>
 */
function tnc_role_page_permission_definitions(): array
{
    $defs = [];
    foreach (tnc_role_page_registry_flat() as $pageKey => $page) {
        $actionCount = count($page['actions']);
        $defs[$pageKey] = [
            'label' => 'เข้าถึงหน้า',
            'group' => (string) $page['hub'],
            'hint' => (string) ($page['label'] . ' — ' . $page['path']),
            'kind' => 'page_access',
            'page_label' => (string) $page['label'],
        ];
    }

    return $defs;
}
