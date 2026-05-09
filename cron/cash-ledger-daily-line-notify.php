<?php

declare(strict_types=1);

/**
 * Optional CLI: ส่งสรุปสดย่อยรายวันไป LINE (หลักใช้ปุ่มบนหน้า cash-ledger-dashboard แทน)
 *
 *   TZ=Asia/Bangkok php cash-ledger-daily-line-notify.php
 *   php cash-ledger-daily-line-notify.php --date=2026-05-09
 *   CASH_LEDGER_LINE_CRON_SECRET=x php cash-ledger-daily-line-notify.php --secret=x
 */

$root = dirname(__DIR__);
require_once $root . '/config/connect_database.php';
require_once $root . '/config/line_settings.php';
require_once $root . '/includes/line_notify_runtime.php';
require_once $root . '/includes/cash_ledger_line_daily_report.php';

if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Asia/Bangkok');
}

/** @param list<string> $argv */
function cash_ledger_daily_parse_cli_args(array $argv): array
{
    $date = '';
    $secret = '';
    foreach ($argv as $i => $arg) {
        if ($i === 0) {
            continue;
        }
        if (str_starts_with($arg, '--date=')) {
            $date = substr($arg, 7);
        } elseif (str_starts_with($arg, '--secret=')) {
            $secret = substr($arg, 9);
        }
    }

    return [$date, $secret];
}

[$dateArg, $secretArg] = cash_ledger_daily_parse_cli_args($argv);
$expectedSecret = trim((string) (getenv('CASH_LEDGER_LINE_CRON_SECRET') ?: ''));
if ($expectedSecret !== '' && $secretArg !== $expectedSecret) {
    fwrite(STDERR, "cash-ledger-daily-line-notify: missing or wrong --secret\n");
    exit(1);
}

if ($dateArg !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateArg)) {
        fwrite(STDERR, "cash-ledger-daily-line-notify: invalid --date (use YYYY-MM-DD)\n");
        exit(1);
    }
    $reportDate = $dateArg;
} else {
    $reportDate = date('Y-m-d');
}

$channelToken = trim((string) (defined('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN') ? LINE_MESSAGING_CHANNEL_ACCESS_TOKEN : ''));
$targetGroupId = line_effective_target_group_id();
$targetUserId = trim((string) (defined('LINE_TARGET_USER_ID') ? LINE_TARGET_USER_ID : ''));
$targetId = $targetGroupId !== '' ? $targetGroupId : $targetUserId;
if ($channelToken === '' || $targetId === '') {
    fwrite(STDERR, "cash-ledger-daily-line-notify: LINE token or target (group/user) not configured\n");
    exit(1);
}

$entries = cash_ledger_daily_entries_for_date($reportDate);
$flexMessages = cash_ledger_daily_build_flex_messages($reportDate, $entries);

$ok = cash_ledger_daily_line_push($channelToken, $targetId, $flexMessages);
if (PHP_SAPI === 'cli') {
    echo $ok ? "LINE daily petty cash report sent ({$reportDate}).\n" : "LINE send completed with errors.\n";
}

exit($ok ? 0 : 1);
