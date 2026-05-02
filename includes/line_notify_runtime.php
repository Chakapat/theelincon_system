<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../config/line_settings.php';

use Theelincon\Rtdb\Db;

/** แถวเดียวใต้ theelincon_mirror/line_notify_config/default */
const LINE_NOTIFY_CONFIG_TABLE = 'line_notify_config';
const LINE_NOTIFY_CONFIG_PK = 'default';

/**
 * @return array<string, mixed>
 */
function line_notify_config_row(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $r = Db::row(LINE_NOTIFY_CONFIG_TABLE, LINE_NOTIFY_CONFIG_PK);
        $cached = is_array($r) ? $r : [];
    } catch (\Throwable $e) {
        $cached = [];
    }

    return $cached;
}

/**
 * ค่าจาก RTDB เท่านั้น — null / ว่าง = ไม่มีการตั้งค่า (ไม่ fallback ไปที่ไฟล์).
 */
function line_notify_field_string(array $row, string $key): string
{
    if (!array_key_exists($key, $row)) {
        return '';
    }
    $v = $row[$key];
    if ($v === null) {
        return '';
    }

    return trim((string) $v);
}

function line_effective_target_group_id(): string
{
    return line_notify_field_string(line_notify_config_row(), 'target_group_id');
}

function line_effective_approver_user_id(): string
{
    return line_notify_field_string(line_notify_config_row(), 'approver_user_id');
}
