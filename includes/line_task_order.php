<?php

declare(strict_types=1);

require_once __DIR__ . '/line_notify_runtime.php';
require_once __DIR__ . '/line_messaging.php';
require_once __DIR__ . '/line_task_assignees.php';

use Theelincon\Rtdb\Db;

const LINE_TASKS_TABLE = 'line_tasks';

function line_task_new_action_token(): string
{
    return bin2hex(random_bytes(16));
}

function line_task_normalize_status(array $task): string
{
    $st = strtolower(trim((string) ($task['status'] ?? '')));

    return match ($st) {
        'pending', 'send_failed' => $st,
        'sent', 'awaiting', 'awaiting_accept' => 'sent',
        'in_progress', 'working' => 'in_progress',
        'completed', 'done', 'success' => 'completed',
        'rejected', 'declined' => 'rejected',
        default => $st !== '' ? $st : 'pending',
    };
}

function line_task_status_label_th(string $status): string
{
    return match (line_task_normalize_status(['status' => $status])) {
        'pending' => 'รอส่ง',
        'send_failed' => 'ส่งไม่สำเร็จ',
        'sent' => 'รอรับงาน',
        'in_progress' => 'กำลังทำ',
        'completed' => 'สำเร็จแล้ว',
        'rejected' => 'ปฏิเสธแล้ว',
        default => $status,
    };
}

function line_task_status_badge_class(string $status): string
{
    return match (line_task_normalize_status(['status' => $status])) {
        'sent' => 'text-bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        'in_progress' => 'text-bg-primary-subtle text-primary-emphasis border border-primary-subtle',
        'completed' => 'text-bg-success-subtle text-success-emphasis border border-success-subtle',
        'rejected' => 'text-bg-danger-subtle text-danger-emphasis border border-danger-subtle',
        'send_failed' => 'text-bg-danger-subtle text-danger-emphasis border border-danger-subtle',
        default => 'text-bg-secondary-subtle text-secondary-emphasis border',
    };
}

/** สถานะที่ใช้แสดงบน Flex Card (pending = กำลังส่งออกไป LINE ให้ถือว่ารอรับงาน) */
function line_task_card_display_status(array $task): string
{
    $status = line_task_normalize_status($task);

    return $status === 'pending' ? 'sent' : $status;
}

function line_task_ensure_action_token(int $taskId): string
{
    $task = Db::rowByIdField(LINE_TASKS_TABLE, $taskId);
    if ($task === null) {
        return '';
    }
    $token = trim((string) ($task['line_action_token'] ?? ''));
    if ($token !== '') {
        return $token;
    }
    $token = line_task_new_action_token();
    Db::mergeRow(LINE_TASKS_TABLE, (string) $taskId, [
        'line_action_token' => $token,
        'updated_at' => line_task_now_utc(),
    ]);

    return $token;
}

function line_task_order_no(int $taskId): string
{
    return 'WO-' . str_pad((string) $taskId, 5, '0', STR_PAD_LEFT);
}

function line_task_tz_bangkok(): \DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        $tz = new \DateTimeZone('Asia/Bangkok');
    }

    return $tz;
}

function line_task_tz_utc(): \DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        $tz = new \DateTimeZone('UTC');
    }

    return $tz;
}

/** เวลาปัจจุบันเก็บใน DB (UTC) */
function line_task_now_utc(): string
{
    return (new \DateTimeImmutable('now', line_task_tz_utc()))->format('Y-m-d H:i:s');
}

/**
 * แปลงวันเวลาจากฟอร์ม (เวลาไทย) → เก็บ UTC
 */
function line_task_due_at_to_utc(string $dueAt): string
{
    $dueAt = trim($dueAt);
    if ($dueAt === '') {
        return '';
    }

    $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dueAt, line_task_tz_bangkok());
    if ($parsed === false) {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $dueAt, line_task_tz_bangkok());
    }
    if ($parsed === false && preg_match(
        '/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/',
        $dueAt,
        $m
    )) {
        $parsed = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            sprintf(
                '%04d-%02d-%02d %02d:%02d:%02d',
                (int) $m[3],
                (int) $m[2],
                (int) $m[1],
                (int) ($m[4] ?? 17),
                (int) ($m[5] ?? 0),
                (int) ($m[6] ?? 0)
            ),
            line_task_tz_bangkok()
        );
    }
    if ($parsed === false) {
        return '';
    }

    return $parsed->setTimezone(line_task_tz_utc())->format('Y-m-d H:i:s');
}

/**
 * แสดงวันเวลาจาก DB (UTC) เป็น Asia/Bangkok
 */
function line_task_format_datetime_th(?string $dt): string
{
    $dt = trim((string) $dt);
    if ($dt === '') {
        return '—';
    }

    $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dt, line_task_tz_utc());
    if ($parsed === false) {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $dt, line_task_tz_utc());
    }
    if ($parsed === false) {
        return $dt;
    }

    $bangkok = $parsed->setTimezone(line_task_tz_bangkok());

    return sprintf(
        '%02d/%02d/%04d %02d:%02d',
        (int) $bangkok->format('d'),
        (int) $bangkok->format('m'),
        (int) $bangkok->format('Y'),
        (int) $bangkok->format('H'),
        (int) $bangkok->format('i')
    );
}

function line_task_format_date_th(?string $dt): string
{
    return line_task_format_datetime_th($dt);
}

/** เวลาปัจจุบันแสดงบนหน้าเว็บ / preview */
function line_task_format_now_th(): string
{
    return line_task_format_datetime_th(line_task_now_utc());
}

/**
 * @param array<string, mixed> $opts
 *
 * @return array<string, mixed>
 */
function line_task_flex_text(string $text, array $opts = []): array
{
    $row = [
        'type' => 'text',
        'text' => $text,
        'wrap' => true,
        'size' => (string) ($opts['size'] ?? 'sm'),
        'color' => (string) ($opts['color'] ?? '#374151'),
    ];
    if (!empty($opts['weight'])) {
        $row['weight'] = (string) $opts['weight'];
    }
    if (!empty($opts['margin'])) {
        $row['margin'] = (string) $opts['margin'];
    }
    if (!empty($opts['align'])) {
        $row['align'] = (string) $opts['align'];
    }

    return $row;
}

/** @return array<string, mixed> */
function line_task_flex_separator(): array
{
    return [
        'type' => 'separator',
        'margin' => 'md',
        'color' => '#e5e7eb',
    ];
}

/** @return array<string, mixed> */
function line_task_flex_kv(string $label, string $value, bool $boldValue = false): array
{
    return [
        'type' => 'box',
        'layout' => 'horizontal',
        'spacing' => 'sm',
        'contents' => [
            line_task_flex_text($label, ['size' => 'xs', 'color' => '#6b7280']),
            [
                'type' => 'text',
                'text' => $value !== '' ? $value : '—',
                'size' => 'xs',
                'color' => '#111827',
                'align' => 'end',
                'wrap' => true,
                'flex' => 2,
                'weight' => $boldValue ? 'bold' : 'regular',
            ],
        ],
    ];
}

function line_task_flex_trunc(string $text, int $maxLen): string
{
    $text = trim($text);
    if ($text === '') {
        return '—';
    }
    if (mb_strlen($text) <= $maxLen) {
        return $text;
    }

    return mb_substr($text, 0, $maxLen - 1) . '…';
}

/** @return array<string, mixed> */
function line_task_flex_assignee_row(string $assigneeName): array
{
    $assigneeName = line_task_flex_trunc(trim($assigneeName), 80);
    if ($assigneeName === '' || $assigneeName === '—') {
        return line_task_flex_kv('ผู้รับผิดชอบ', '—', true);
    }

    return [
        'type' => 'box',
        'layout' => 'vertical',
        'margin' => 'md',
        'paddingAll' => 'md',
        'backgroundColor' => '#eff6ff',
        'cornerRadius' => 'md',
        'contents' => [
            line_task_flex_text('ผู้รับผิดชอบ', ['size' => 'xs', 'color' => '#6b7280']),
            line_task_flex_text('@' . $assigneeName, [
                'size' => 'md',
                'color' => '#2563eb',
                'weight' => 'bold',
            ]),
        ],
    ];
}

/**
 * @param array<string, mixed> $task
 * @param array<string, mixed> $assignee
 *
 * @return array<string, mixed>
 */
function line_task_build_flex_bubble(array $task, array $assignee): array
{
    $taskId = (int) ($task['id'] ?? 0);
    $orderNo = line_task_order_no($taskId);
    $status = line_task_card_display_status($task);
    $orderedAt = line_task_format_datetime_th((string) ($task['ordered_at'] ?? ''));
    $destination = line_task_flex_trunc(line_task_destination_label($task), 120);
    $assigneeName = line_task_flex_trunc((string) ($assignee['name'] ?? ''), 80);
    $dueAt = line_task_format_datetime_th((string) ($task['due_at'] ?? ''));
    $details = line_task_flex_trunc((string) ($task['details'] ?? ''), 800);
    $actionToken = trim((string) ($task['line_action_token'] ?? ''));
    if ($actionToken === '') {
        $actionToken = line_task_ensure_action_token($taskId);
    }
    $postbackBase = 'action=line_task_status&id=' . $taskId
        . '&token=' . rawurlencode($actionToken)
        . '&status=' . rawurlencode($status);

    $headerBg = '#fff7ed';
    $headerAccent = '#9a3412';

    $bodyContents = [
        line_task_flex_kv('วันที่สั่ง', $orderedAt),
        line_task_flex_kv('สถานที่ปลายทาง', $destination, true),
        line_task_flex_assignee_row($assigneeName),
        line_task_flex_kv('ภายใน', $dueAt, true),
        line_task_flex_separator(),
        line_task_flex_text('รายละเอียด', ['size' => 'xs', 'color' => '#6b7280', 'weight' => 'bold']),
        line_task_flex_text($details, ['size' => 'sm', 'color' => '#1f2937', 'margin' => 'sm']),
    ];

    $bubble = [
        'type' => 'bubble',
        'size' => 'mega',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => $headerBg,
            'paddingAll' => 'lg',
            'contents' => [
                line_task_flex_text('ใบสั่งงาน', ['weight' => 'bold', 'size' => 'md', 'color' => $headerAccent]),
                line_task_flex_text($orderNo, [
                    'weight' => 'bold',
                    'size' => 'xl',
                    'color' => '#1f2937',
                    'margin' => 'md',
                ]),
            ],
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'paddingAll' => 'lg',
            'contents' => $bodyContents,
        ],
    ];

    $footer = line_task_flex_footer($postbackBase, $orderNo, $status);
    if ($footer !== null) {
        $bubble['footer'] = $footer;
    }

    return $bubble;
}

/**
 * @return array<string, mixed>
 */
function line_task_flex_postback_button(
    string $label,
    string $data,
    string $displayText,
    string $style = 'secondary',
    ?string $color = null,
    bool $fullWidth = false
): array {
    $btn = [
        'type' => 'button',
        'style' => $style,
        'height' => 'sm',
        'action' => [
            'type' => 'postback',
            'label' => $label,
            'data' => $data,
            'displayText' => $displayText,
        ],
    ];
    if (!$fullWidth) {
        $btn['flex'] = 1;
    }
    if ($color !== null && $color !== '') {
        $btn['color'] = $color;
    }

    return $btn;
}

/**
 * @return array<string, mixed>|null
 */
function line_task_flex_footer(string $postbackBase, string $orderNo, string $status): ?array
{
    if ($status === 'completed' || $status === 'rejected') {
        return null;
    }

    if ($status === 'sent' || $status === 'in_progress') {
        return [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'paddingAll' => 'lg',
            'contents' => [
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'spacing' => 'sm',
                    'contents' => [
                        line_task_flex_postback_button(
                            'ปฏิเสธ',
                            $postbackBase . '&decision=reject',
                            'ปฏิเสธ ' . $orderNo,
                            'secondary',
                            '#dc2626'
                        ),
                        line_task_flex_postback_button(
                            'เสร็จสิ้น',
                            $postbackBase . '&decision=complete',
                            'เสร็จสิ้น ' . $orderNo,
                            'primary',
                            '#ea580c'
                        ),
                    ],
                ],
                line_task_flex_postback_button(
                    'รับงาน',
                    $postbackBase . '&decision=accept',
                    'รับงาน ' . $orderNo,
                    'primary',
                    '#16a34a',
                    true
                ),
            ],
        ];
    }

    return null;
}

/**
 * @param array<string, mixed> $task
 * @param array<string, mixed> $assignee
 *
 * @return list<array<string, mixed>>
 */
function line_task_build_flex_messages(array $task, array $assignee): array
{
    $taskId = (int) ($task['id'] ?? 0);
    $orderNo = line_task_order_no($taskId);
    $assigneeName = line_task_flex_trunc((string) ($assignee['name'] ?? ''), 80);
    $destination = line_task_flex_trunc(line_task_destination_label($task), 120);
    $dueAt = line_task_format_datetime_th((string) ($task['due_at'] ?? ''));

    $bubble = line_task_build_flex_bubble($task, $assignee);

    $alt = line_task_flex_trunc(
        '@' . $assigneeName
        . ' | ใบสั่งงาน ' . $orderNo
        . ' | ' . $destination
        . ' | ภายใน ' . $dueAt,
        400
    );

    return [[
        'type' => 'flex',
        'altText' => $alt,
        'contents' => $bubble,
    ]];
}

/** @return array<string, mixed>|null */
function line_task_build_mention_message(string $userLineId): ?array
{
    $userLineId = trim($userLineId);
    if ($userLineId === '') {
        return null;
    }

    return [
        'type' => 'textV2',
        'text' => '{mention}',
        'substitution' => [
            'mention' => [
                'type' => 'mention',
                'mentionee' => [
                    'type' => 'user',
                    'userId' => $userLineId,
                ],
            ],
        ],
    ];
}

/** @return list<string> */
function line_task_notify_push_targets(): array
{
    $group = line_effective_task_group_id();

    return $group !== '' ? [$group] : [];
}

/**
 * @return array{ok: bool, sent: int, error: ?string, task_id: int}
 */
function line_task_send(int $taskId): array
{
    $task = Db::rowByIdField(LINE_TASKS_TABLE, $taskId);
    if ($task === null) {
        return ['ok' => false, 'sent' => 0, 'error' => 'not_found', 'task_id' => $taskId];
    }

    $assigneeId = (int) ($task['assignee_id'] ?? 0);
    $assignee = line_task_assignee_by_id($assigneeId);
    if ($assignee === null) {
        return ['ok' => false, 'sent' => 0, 'error' => 'assignee_not_found', 'task_id' => $taskId];
    }

    $token = line_effective_channel_access_token();
    if ($token === '') {
        return ['ok' => false, 'sent' => 0, 'error' => 'no_token', 'task_id' => $taskId];
    }

    $targets = line_task_notify_push_targets();
    if ($targets === []) {
        return ['ok' => false, 'sent' => 0, 'error' => 'no_group', 'task_id' => $taskId];
    }

    $actionToken = trim((string) ($task['line_action_token'] ?? ''));
    if ($actionToken === '') {
        $actionToken = line_task_new_action_token();
        Db::mergeRow(LINE_TASKS_TABLE, (string) $taskId, [
            'line_action_token' => $actionToken,
            'updated_at' => line_task_now_utc(),
        ]);
        $task['line_action_token'] = $actionToken;
    }

    $now = line_task_now_utc();
    $outboundStatus = 'sent';
    $taskForCard = array_merge($task, [
        'status' => $outboundStatus,
        'line_card_status' => $outboundStatus,
        'sent_at' => $now,
    ]);

    $messages = [];
    $mention = line_task_build_mention_message((string) ($assignee['user_line_id'] ?? ''));
    if ($mention !== null) {
        $messages[] = $mention;
    }
    foreach (line_task_build_flex_messages($taskForCard, $assignee) as $flexMsg) {
        $messages[] = $flexMsg;
    }

    $sent = 0;
    foreach ($targets as $to) {
        if (line_messaging_push($token, $to, $messages)) {
            ++$sent;
        }
    }

    if ($sent === 0) {
        Db::mergeRow(LINE_TASKS_TABLE, (string) $taskId, [
            'status' => 'send_failed',
            'updated_at' => line_task_now_utc(),
        ]);

        return ['ok' => false, 'sent' => 0, 'error' => 'push_failed', 'task_id' => $taskId];
    }

    $pk = (string) $taskId;
    Db::mergeRow(LINE_TASKS_TABLE, $pk, [
        'status' => 'sent',
        'line_card_status' => 'sent',
        'sent_at' => $now,
        'line_sent_count' => $sent,
        'updated_at' => $now,
    ]);

    return ['ok' => true, 'sent' => $sent, 'error' => null, 'task_id' => $taskId];
}

/**
 * @return array{ok: bool, message: string, reply_messages: list<array<string, mixed>>}
 */
function line_task_apply_postback(int $taskId, string $decision, string $lineUserId, string $token = '', string $postedStatus = ''): array
{
    $decision = strtolower(trim($decision));
    if (!in_array($decision, ['accept', 'complete', 'reject'], true)) {
        return ['ok' => false, 'message' => 'คำสั่งไม่ถูกต้อง', 'reply_messages' => []];
    }

    $task = Db::rowByIdField(LINE_TASKS_TABLE, $taskId);
    if ($task === null) {
        return ['ok' => false, 'message' => 'ไม่พบใบสั่งงาน', 'reply_messages' => []];
    }

    $assigneeId = (int) ($task['assignee_id'] ?? 0);
    $assignee = line_task_assignee_by_id($assigneeId);
    if ($assignee === null) {
        return ['ok' => false, 'message' => 'ไม่พบผู้รับผิดชอบงาน', 'reply_messages' => []];
    }

    $assigneeLineId = trim((string) ($assignee['user_line_id'] ?? ''));
    if ($lineUserId === '' || $assigneeLineId === '' || !hash_equals($assigneeLineId, $lineUserId)) {
        return ['ok' => false, 'message' => 'เฉพาะผู้รับผิดชอบที่ถูก @ ในใบสั่งงานเท่านั้นที่กดได้', 'reply_messages' => []];
    }

    $expectedToken = trim((string) ($task['line_action_token'] ?? ''));
    $token = trim($token);
    if ($expectedToken === '' || $token === '' || !hash_equals($expectedToken, $token)) {
        return ['ok' => false, 'message' => 'ลิงก์งานไม่ถูกต้อง', 'reply_messages' => []];
    }

    $status = line_task_normalize_status($task);
    $orderNo = line_task_order_no($taskId);
    $now = line_task_now_utc();

    if ($decision === 'accept') {
        if ($status !== 'sent') {
            return [
                'ok' => false,
                'message' => 'ใบสั่งงานนี้ดำเนินการไปแล้ว (สถานะ: ' . line_task_status_label_th($status) . ')',
                'reply_messages' => [],
            ];
        }
        Db::mergeRow(LINE_TASKS_TABLE, (string) $taskId, [
            'status' => 'in_progress',
            'line_card_status' => 'in_progress',
            'accepted_at' => $now,
            'accepted_by_line_user_id' => $lineUserId,
            'updated_at' => $now,
        ]);
        $message = 'รับงาน ' . $orderNo . ' แล้ว — สถานะ: กำลังทำ';
    } elseif ($decision === 'reject') {
        if ($status !== 'sent') {
            return [
                'ok' => false,
                'message' => 'ไม่สามารถปฏิเสธได้ (สถานะ: ' . line_task_status_label_th($status) . ')',
                'reply_messages' => [],
            ];
        }
        Db::mergeRow(LINE_TASKS_TABLE, (string) $taskId, [
            'status' => 'rejected',
            'line_card_status' => 'rejected',
            'rejected_at' => $now,
            'rejected_by_line_user_id' => $lineUserId,
            'updated_at' => $now,
        ]);
        $message = 'ปฏิเสธ ' . $orderNo . ' แล้ว';
    } else {
        if ($status !== 'in_progress') {
            return [
                'ok' => false,
                'message' => 'กรุณากดรับงานก่อน จึงจะกดเสร็จสิ้นได้',
                'reply_messages' => [],
            ];
        }
        Db::mergeRow(LINE_TASKS_TABLE, (string) $taskId, [
            'status' => 'completed',
            'line_card_status' => 'completed',
            'completed_at' => $now,
            'completed_by_line_user_id' => $lineUserId,
            'updated_at' => $now,
        ]);
        $message = 'เสร็จสิ้น ' . $orderNo . ' แล้ว — สถานะ: สำเร็จแล้ว';
    }

    return ['ok' => true, 'message' => $message, 'reply_messages' => []];
}

/**
 * @return array{ok: bool, task_id: int, error: ?string, send: array<string, mixed>}
 */
function line_task_create_and_send(
    int $assigneeId,
    string $destination,
    string $details,
    string $dueAt,
    int $createdBy,
    int $siteId = 0
): array {
    $destination = trim($destination);
    $details = trim($details);
    $dueAt = trim($dueAt);

    if ($destination === '' && $siteId > 0) {
        $destination = line_task_site_name($siteId);
    }

    if ($assigneeId <= 0) {
        return ['ok' => false, 'task_id' => 0, 'error' => 'need_assignee', 'send' => []];
    }
    if ($destination === '') {
        return ['ok' => false, 'task_id' => 0, 'error' => 'need_destination', 'send' => []];
    }
    if ($details === '') {
        return ['ok' => false, 'task_id' => 0, 'error' => 'need_details', 'send' => []];
    }
    if ($dueAt === '' || line_task_due_at_to_utc($dueAt) === '') {
        return ['ok' => false, 'task_id' => 0, 'error' => 'invalid_due', 'send' => []];
    }

    $assignee = line_task_assignee_by_id($assigneeId);
    if ($assignee === null || (isset($assignee['is_active']) && (int) $assignee['is_active'] === 0)) {
        return ['ok' => false, 'task_id' => 0, 'error' => 'assignee_not_found', 'send' => []];
    }

    $now = line_task_now_utc();
    $taskId = Db::nextNumericId(LINE_TASKS_TABLE);
    Db::setRow(LINE_TASKS_TABLE, (string) $taskId, [
        'id' => $taskId,
        'assignee_id' => $assigneeId,
        'site_id' => $siteId > 0 ? $siteId : null,
        'destination' => $destination,
        'details' => $details,
        'due_at' => line_task_due_at_to_utc($dueAt),
        'ordered_at' => $now,
        'status' => 'pending',
        'created_by' => $createdBy,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $send = line_task_send($taskId);
    if (!$send['ok']) {
        Db::mergeRow(LINE_TASKS_TABLE, (string) $taskId, [
            'status' => 'send_failed',
            'send_error' => (string) ($send['error'] ?? 'unknown'),
        ]);

        return ['ok' => false, 'task_id' => $taskId, 'error' => (string) ($send['error'] ?? 'send_failed'), 'send' => $send];
    }

    return ['ok' => true, 'task_id' => $taskId, 'error' => null, 'send' => $send];
}

/** @return array<string, mixed>|null */
function line_task_site_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $row = Db::row('sites', (string) $id);
    if ($row !== null) {
        return $row;
    }

    return Db::rowByIdField('sites', $id);
}

function line_task_site_name(int $siteId, ?array $site = null): string
{
    if ($siteId <= 0) {
        return '';
    }
    if ($site === null) {
        $site = line_task_site_by_id($siteId);
    }
    if ($site === null) {
        return '';
    }

    return trim((string) ($site['name'] ?? ''));
}

function line_task_destination_label(array $task): string
{
    $label = trim((string) ($task['destination'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    return line_task_site_name((int) ($task['site_id'] ?? 0));
}

/**
 * @return list<array<string, mixed>>
 */
function line_task_sites_list(): array
{
    $sites = Db::tableRows('sites');
    usort($sites, static function (array $a, array $b): int {
        $sort = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
        if ($sort !== 0) {
            return $sort;
        }

        return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return array_values(array_filter($sites, static function (array $site): bool {
        return (int) ($site['id'] ?? 0) > 0 && trim((string) ($site['name'] ?? '')) !== '';
    }));
}

/**
 * @return array{ok: bool, error: ?string}
 */
function line_task_delete(int $id): array
{
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'invalid_id'];
    }
    if (Db::rowByIdField(LINE_TASKS_TABLE, $id) === null) {
        return ['ok' => false, 'error' => 'task_not_found'];
    }
    Db::deleteRow(LINE_TASKS_TABLE, (string) $id);

    return ['ok' => true, 'error' => null];
}
