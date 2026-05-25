<?php

declare(strict_types=1);

/**
 * ค่า LINE ทั้งระบบ — โหลดจาก RTDB line_notify_config/default (ตั้งค่าที่ line-notify-config.php)
 */

require_once __DIR__ . '/../config/connect_database.php';

use Theelincon\Rtdb\Db;

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

function line_notify_field(string $key): string
{
    $row = line_notify_config_row();
    if (!array_key_exists($key, $row) || $row[$key] === null) {
        return '';
    }

    return trim((string) $row[$key]);
}

function line_effective_channel_access_token(): string
{
    return line_notify_field('channel_access_token');
}

function line_effective_channel_secret(): string
{
    return line_notify_field('channel_secret');
}

function line_effective_target_group_id(): string
{
    return line_notify_field('target_group_id');
}

function line_effective_approver_user_id(): string
{
    return line_notify_field('approver_user_id');
}

/** @return list<string> LINE userId ของผู้อนุมัติ (คั่นด้วย comma ใน DB) */
function line_notify_approver_line_user_ids(): array
{
    return line_notify_split_csv_ids(line_effective_approver_user_id());
}

/**
 * @return list<string>
 */
function line_notify_split_csv_ids(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
        $p = trim((string) $part);
        if ($p !== '') {
            $out[] = $p;
        }
    }

    return array_values(array_unique($out));
}

/**
 * กลุ่ม LINE ที่เคยเห็นจาก Webhook (uploads/line-user-ids.json)
 *
 * @return list<array{id: string, label: string, last_seen: string}>
 */
function line_notify_captured_groups(): array
{
    $logPath = defined('ROOT_PATH') ? ROOT_PATH . '/uploads/line-user-ids.json' : '';
    if ($logPath === '' || !is_file($logPath)) {
        return [];
    }
    $raw = file_get_contents($logPath);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $byGroup = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $gid = trim((string) ($row['groupId'] ?? ''));
        if ($gid === '') {
            continue;
        }
        $seen = trim((string) ($row['last_seen_at'] ?? ($row['timestamp'] ?? '')));
        if (!isset($byGroup[$gid]) || $seen > (string) ($byGroup[$gid]['last_seen'] ?? '')) {
            $byGroup[$gid] = [
                'id' => $gid,
                'label' => $gid,
                'last_seen' => $seen,
            ];
        }
    }
    $out = array_values($byGroup);
    usort($out, static function (array $a, array $b): int {
        return strcmp((string) ($b['last_seen'] ?? ''), (string) ($a['last_seen'] ?? ''));
    });

    return $out;
}

/**
 * @param array<int, array<string, mixed>> $userRows
 * @param list<string> $lineIds
 *
 * @return list<int>
 */
function line_notify_internal_ids_matching_line_ids(array $userRows, array $lineIds): array
{
    if ($lineIds === []) {
        return [];
    }
    $set = array_fill_keys($lineIds, true);
    $out = [];
    foreach ($userRows as $u) {
        $lid = trim((string) ($u['user_line_id'] ?? ''));
        if ($lid === '' || !isset($set[$lid])) {
            continue;
        }
        $uid = (int) ($u['userid'] ?? 0);
        if ($uid > 0) {
            $out[] = $uid;
        }
    }

    return array_values(array_unique($out));
}
