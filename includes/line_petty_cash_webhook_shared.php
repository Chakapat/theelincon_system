<?php

declare(strict_types=1);

require_once __DIR__ . '/line_notify_runtime.php';
require_once __DIR__ . '/cash_ledger_line_daily_report.php';

/**
 * ผู้ใช้ที่อนุญาต: LINE_TARGET_USER_ID (comma) + approver_user_id จาก line_notify_config
 *
 * @return list<string>
 */
function line_petty_cash_allowed_user_ids(): array
{
    $raw = trim((string) (defined('LINE_TARGET_USER_ID') ? LINE_TARGET_USER_ID : ''));
    $out = [];
    if ($raw !== '') {
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
    }
    $approver = line_effective_approver_user_id();
    if ($approver !== '') {
        $parts = preg_split('/\s*,\s*/', $approver) ?: [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
    }

    return array_values(array_unique($out));
}

function line_petty_cash_push_target(array $source): string
{
    $t = (string) ($source['type'] ?? '');
    if ($t === 'group') {
        return (string) ($source['groupId'] ?? '');
    }
    if ($t === 'room') {
        return (string) ($source['roomId'] ?? '');
    }

    return (string) ($source['userId'] ?? '');
}

function line_petty_cash_message_requests_report(string $text): bool
{
    return str_contains($text, 'รายงานสดย่อย') || str_contains($text, 'รายงานย้อนหลัง');
}

/**
 * @return array{ymd: string, error: ?string}
 */
function line_petty_cash_resolve_report_date(string $text): array
{
    if (preg_match('/(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})/u', $text, $m) === 1) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if (checkdate($mo, $d, $y)) {
            return ['ymd' => sprintf('%04d-%02d-%02d', $y, $mo, $d), 'error' => null];
        }

        return ['ymd' => '', 'error' => 'วันที่ไม่ถูกต้อง ลองรูปแบบ วัน-เดือน-ปี เช่น 08-05-2026'];
    }

    if (preg_match('/(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/u', $text, $m2) === 1) {
        $y = (int) $m2[1];
        $mo = (int) $m2[2];
        $d = (int) $m2[3];
        if (checkdate($mo, $d, $y)) {
            return ['ymd' => sprintf('%04d-%02d-%02d', $y, $mo, $d), 'error' => null];
        }

        return ['ymd' => '', 'error' => 'วันที่ไม่ถูกต้อง'];
    }

    if (str_contains($text, 'รายงานสดย่อย')) {
        return ['ymd' => date('Y-m-d'), 'error' => null];
    }

    if (str_contains($text, 'รายงานย้อนหลัง')) {
        return ['ymd' => '', 'error' => 'ระบุวันที่ เช่น รายงานย้อนหลัง 08-05-2026'];
    }

    return ['ymd' => date('Y-m-d'), 'error' => null];
}

/**
 * @param array<string, mixed> $source LINE event source
 */
function line_petty_cash_handle_text_command(string $channelToken, string $replyToken, array $source, string $userId, string $text): void
{
    if ($channelToken === '' || $replyToken === '') {
        return;
    }

    $allowedUsers = line_petty_cash_allowed_user_ids();

    if ($allowedUsers === []) {
        cash_ledger_daily_line_reply($channelToken, $replyToken, [
            [
                'type' => 'text',
                'text' => "ยังไม่มีรายชื่อผู้ใช้ที่อนุญาต\n\nให้ตั้งค่า LINE_TARGET_USER_ID ใน config (หรือตั้ง approver ในระบบแจ้งเตือน LINE) ให้ตรงกับ \"userId\" ของคุณ\n\nถ้าเซิร์ฟเวอร์เป็น localhost LINE จะส่ง Webhook เข้ามาไม่ได้ ต้องใช้ URL สาธารณะ (HTTPS)",
            ],
        ]);

        return;
    }

    if ($userId === '' || !in_array($userId, $allowedUsers, true)) {
        cash_ledger_daily_line_reply($channelToken, $replyToken, [
            [
                'type' => 'text',
                'text' => 'บัญชี LINE นี้ยังไม่อยู่ในรายชื่อที่อนุญาตใช้คำสั่งรายงานสดย่อย กรุณาให้แอดมินเพิ่ม userId ของคุณใน LINE_TARGET_USER_ID (หรือตั้งคุณเป็น approver ในระบบ)',
            ],
        ]);

        return;
    }

    $pushTarget = line_petty_cash_push_target($source);
    $parsed = line_petty_cash_resolve_report_date($text);
    if ($parsed['error'] !== null) {
        cash_ledger_daily_line_reply($channelToken, $replyToken, [
            [
                'type' => 'text',
                'text' => $parsed['error'],
            ],
        ]);

        return;
    }

    $reportDate = $parsed['ymd'];
    $entries = cash_ledger_daily_entries_for_date($reportDate);
    $flexMessages = cash_ledger_daily_build_flex_messages($reportDate, $entries);
    cash_ledger_daily_line_deliver_reply_then_push($channelToken, $replyToken, $pushTarget, $flexMessages);
}
