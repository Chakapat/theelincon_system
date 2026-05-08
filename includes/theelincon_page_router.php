<?php

declare(strict_types=1);

/**
 * Router สำหรับ pages/{module}/ — โหลดไฟล์ PHP ในโฟลเดอร์เดียวกัน
 *
 * @param array<string,string> $map action key => ชื่อไฟล์ (ใน $viewsDir)
 */
function theelincon_require_pageview(string $viewsDir, array $map, string $defaultAction): void
{
    $action = isset($_GET['action']) ? (string) $_GET['action'] : $defaultAction;
    if (!isset($map[$action])) {
        $action = $defaultAction;
    }
    $file = $map[$action];
    require $viewsDir . '/' . $file;
}
