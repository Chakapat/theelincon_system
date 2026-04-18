<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/line_settings.php';

/**
 * Append LINE debug log for troubleshooting push failures.
 */
function line_append_debug_log(array $data): void
{
    $logPath = ROOT_PATH . '/uploads/line-push-debug.log';
    $line = date('c') . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents($logPath, $line, FILE_APPEND);
}

/**
 * Trim text to LINE template-safe length.
 */
function line_mb_truncate(string $text, int $maxLen): string
{
    $text = trim($text);
    if (mb_strlen($text) <= $maxLen) {
        return $text;
    }
    if ($maxLen <= 1) {
        return mb_substr($text, 0, $maxLen);
    }
    return mb_substr($text, 0, $maxLen - 1) . '…';
}

/**
 * Build absolute URL from app path.
 */
function line_absolute_app_url(string $path): string
{
    $relativePath = app_path($path);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return $relativePath;
    }

    return $scheme . '://' . $host . $relativePath;
}

/**
 * Send PR approval card to LINE user.
 */
function line_send_pr_approval_notification(array $prRow, string $requesterName, string $itemsPreview = ''): bool
{
    $channelToken = (string) LINE_MESSAGING_CHANNEL_ACCESS_TOKEN;
    $targetUserId = (string) LINE_TARGET_USER_ID;
    $targetGroupId = (string) LINE_TARGET_GROUP_ID;
    $targetId = $targetGroupId !== '' ? $targetGroupId : $targetUserId;
    $targetType = $targetGroupId !== '' ? 'group' : 'user';

    if ($channelToken === '' || $targetId === '') {
        line_append_debug_log([
            'ok' => false,
            'reason' => 'missing_config',
            'has_token' => $channelToken !== '',
            'has_target_user' => $targetUserId !== '',
            'has_target_group' => $targetGroupId !== '',
        ]);
        return false;
    }

    $prId = (int) ($prRow['id'] ?? 0);
    $approvalToken = (string) ($prRow['line_approval_token'] ?? '');
    if ($prId <= 0 || $approvalToken === '') {
        line_append_debug_log([
            'ok' => false,
            'reason' => 'missing_pr_data',
            'pr_id' => $prId,
            'has_approval_token' => $approvalToken !== '',
        ]);
        return false;
    }

    $approveUrl = line_absolute_app_url(
        'actions/action-handler.php?action=line_pr_decision&id=' . $prId . '&decision=approve&token=' . rawurlencode($approvalToken)
    );
    $rejectUrl = line_absolute_app_url(
        'actions/action-handler.php?action=line_pr_decision&id=' . $prId . '&decision=reject&token=' . rawurlencode($approvalToken)
    );

    $prNumber = (string) ($prRow['pr_number'] ?? '-');
    $totalAmount = number_format((float) ($prRow['total_amount'] ?? 0), 2);
    $requesterName = trim($requesterName);
    if ($requesterName === '') {
        $requesterName = 'Unknown User';
    }
    $requesterName = mb_substr($requesterName, 0, 30);
    $details = trim((string) ($prRow['details'] ?? ''));
    if ($details === '') {
        $details = '-';
    }
    $itemsPreview = trim($itemsPreview);
    if ($itemsPreview === '') {
        $itemsPreview = '-';
    }
    $details = line_mb_truncate($details, 120);
    $itemsPreview = line_mb_truncate($itemsPreview, 120);

    $payload = [
        'to' => $targetId,
        'messages' => [[
            'type' => 'flex',
            'altText' => 'PR ' . $prNumber . ' รอการอนุมัติ',
            'contents' => [
                'type' => 'bubble',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => '#FFF4E5',
                    'paddingAll' => '16px',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => 'PR APPROVAL',
                            'size' => 'xs',
                            'color' => '#C97A00',
                            'weight' => 'bold',
                        ],
                        [
                            'type' => 'text',
                            'text' => 'ใบขอซื้อ ' . $prNumber,
                            'weight' => 'bold',
                            'size' => 'lg',
                            'color' => '#222222',
                            'wrap' => true,
                            'margin' => 'sm',
                        ],
                    ],
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'md',
                    'paddingAll' => '16px',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'spacing' => 'xs',
                            'contents' => [
                                [
                                    'type' => 'text',
                                    'text' => 'รายละเอียด',
                                    'size' => 'xs',
                                    'color' => '#8A8A8A',
                                    'weight' => 'bold',
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $details,
                                    'wrap' => true,
                                    'size' => 'sm',
                                    'color' => '#222222',
                                ],
                            ],
                        ],
                        [
                            'type' => 'separator',
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'spacing' => 'xs',
                            'contents' => [
                                [
                                    'type' => 'text',
                                    'text' => 'รายการ',
                                    'size' => 'xs',
                                    'color' => '#8A8A8A',
                                    'weight' => 'bold',
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $itemsPreview,
                                    'wrap' => true,
                                    'size' => 'sm',
                                    'color' => '#222222',
                                ],
                            ],
                        ],
                        [
                            'type' => 'separator',
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'baseline',
                            'contents' => [
                                [
                                    'type' => 'text',
                                    'text' => 'ยอดเงิน',
                                    'size' => 'xs',
                                    'color' => '#8A8A8A',
                                    'flex' => 3,
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $totalAmount . ' บาท',
                                    'size' => 'md',
                                    'weight' => 'bold',
                                    'color' => '#0B8043',
                                    'align' => 'end',
                                    'flex' => 5,
                                ],
                            ],
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'baseline',
                            'contents' => [
                                [
                                    'type' => 'text',
                                    'text' => 'ผู้ขอ',
                                    'size' => 'xs',
                                    'color' => '#8A8A8A',
                                    'flex' => 2,
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $requesterName,
                                    'size' => 'sm',
                                    'color' => '#222222',
                                    'align' => 'end',
                                    'wrap' => true,
                                    'flex' => 6,
                                ],
                            ],
                        ],
                    ],
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'paddingAll' => '16px',
                    'contents' => [
                        [
                            'type' => 'button',
                            'style' => 'primary',
                            'color' => '#1DB446',
                            'action' => [
                                'type' => 'uri',
                                'label' => 'อนุมัติ',
                                'uri' => $approveUrl,
                            ],
                        ],
                        [
                            'type' => 'button',
                            'style' => 'secondary',
                            'action' => [
                                'type' => 'uri',
                                'label' => 'ไม่อนุมัติ',
                                'uri' => $rejectUrl,
                            ],
                        ],
                    ],
                ],
                'styles' => [
                    'footer' => [
                        'separator' => true,
                    ],
                ],
            ],
        ]],
    ];

    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    if ($ch === false) {
        line_append_debug_log([
            'ok' => false,
            'reason' => 'curl_init_failed',
            'pr_id' => $prId,
        ]);
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $channelToken,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrNo = curl_errno($ch);
    $curlErrMsg = curl_error($ch);
    $hasCurlError = $curlErrNo !== 0;
    curl_close($ch);

    $ok = !$hasCurlError && $response !== false && $statusCode >= 200 && $statusCode < 300;
    line_append_debug_log([
        'ok' => $ok,
        'pr_id' => $prId,
        'http_status' => $statusCode,
        'curl_errno' => $curlErrNo,
        'curl_error' => $curlErrMsg,
        'target_type' => $targetType,
        'target_id_prefix' => substr($targetId, 0, 8),
        'line_response' => is_string($response) ? $response : '',
    ]);

    return $ok;
}

