<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/line_notify_runtime.php';
require_once __DIR__ . '/../includes/line_messaging.php';
require_once __DIR__ . '/../includes/line_pr_approval.php';
require_once __DIR__ . '/../includes/tnc_audit_log.php';

header('Content-Type: application/json; charset=UTF-8');

/**
 * Return true when LINE group/room text @mentions this bot (isSelf จาก LINE API).
 */
function line_is_mention_to_bot(array $event): bool
{
    $message = $event['message'] ?? null;
    if (!is_array($message) || (string) ($message['type'] ?? '') !== 'text') {
        return false;
    }

    $mention = $message['mention'] ?? null;
    if (!is_array($mention) || !isset($mention['mentionees']) || !is_array($mention['mentionees'])) {
        return false;
    }
    foreach ($mention['mentionees'] as $m) {
        if (is_array($m) && !empty($m['isSelf'])) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, string>
 */
function line_webhook_parse_postback_data(string $raw): array
{
    $out = [];
    parse_str($raw, $parsed);
    if (!is_array($parsed)) {
        return $out;
    }
    foreach ($parsed as $k => $v) {
        $out[(string) $k] = is_scalar($v) ? trim((string) $v) : '';
    }

    return $out;
}

function line_webhook_reply_text(string $replyToken, string $text): void
{
    $token = line_effective_channel_access_token();
    if ($token === '' || $replyToken === '') {
        return;
    }
    line_messaging_reply($token, $replyToken, [
        ['type' => 'text', 'text' => $text],
    ]);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_body'], JSON_UNESCAPED_UNICODE);
    exit;
}

$lineSignature = (string) ($_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '');
$channelSecret = line_effective_channel_secret();

if ($channelSecret === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing_channel_secret'], JSON_UNESCAPED_UNICODE);
    exit;
}

$expectedSignature = base64_encode(hash_hmac('sha256', $rawBody, $channelSecret, true));
if ($lineSignature === '' || !hash_equals($expectedSignature, $lineSignature)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_signature'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_UNESCAPED_UNICODE);
    exit;
}

$events = $payload['events'] ?? [];
$capturedSources = [];
$postbackHandled = 0;
if (is_array($events)) {
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }

        $eventType = (string) ($event['type'] ?? '');
        if ($eventType === 'postback') {
            $postback = $event['postback'] ?? null;
            if (!is_array($postback)) {
                continue;
            }
            $dataRaw = (string) ($postback['data'] ?? '');
            $params = line_webhook_parse_postback_data($dataRaw);
            if (($params['action'] ?? '') !== 'line_pr_decision') {
                continue;
            }
            $prId = (int) ($params['id'] ?? 0);
            $decision = (string) ($params['decision'] ?? '');
            $approvalToken = (string) ($params['token'] ?? '');
            $source = $event['source'] ?? [];
            $lineUserId = is_array($source) ? trim((string) ($source['userId'] ?? '')) : '';
            $result = line_pr_apply_decision($prId, $decision, $lineUserId, $approvalToken);
            $replyToken = (string) ($event['replyToken'] ?? '');
            if ($replyToken !== '') {
                line_webhook_reply_text($replyToken, $result['message']);
            }
            $postbackHandled++;
            continue;
        }

        $source = $event['source'] ?? null;
        if (!is_array($source)) {
            continue;
        }

        $userId = isset($source['userId']) ? trim((string) $source['userId']) : '';
        $groupId = isset($source['groupId']) ? trim((string) $source['groupId']) : '';
        $roomId = isset($source['roomId']) ? trim((string) $source['roomId']) : '';
        $sourceType = (string) ($source['type'] ?? '');
        $configuredGroup = line_effective_target_group_id();
        $inConfiguredGroup = $configuredGroup !== ''
            && $sourceType === 'group'
            && $groupId !== ''
            && $groupId === $configuredGroup;

        if (($sourceType === 'group' || $sourceType === 'room') && $eventType === 'message' && !$inConfiguredGroup) {
            if (!line_is_mention_to_bot($event)) {
                continue;
            }
        }

        if ($userId === '' && $groupId === '' && $roomId === '') {
            continue;
        }

        $capturedSources[] = [
            'userId' => $userId,
            'groupId' => $groupId,
            'roomId' => $roomId,
            'type' => $sourceType,
            'event' => $eventType,
            'timestamp' => date('c'),
        ];
    }
}

$logPath = ROOT_PATH . '/uploads/line-user-ids.json';
$existing = [];
if (is_file($logPath)) {
    $existingRaw = file_get_contents($logPath);
    if ($existingRaw !== false) {
        $decoded = json_decode($existingRaw, true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }
}

$indexByKey = [];
foreach ($existing as $idx => $row) {
    if (!is_array($row)) {
        continue;
    }
    $kType = (string) ($row['type'] ?? '');
    $kUser = (string) ($row['userId'] ?? '');
    $kGroup = (string) ($row['groupId'] ?? '');
    $kRoom = (string) ($row['roomId'] ?? '');
    $key = $kType . '|' . $kUser . '|' . $kGroup . '|' . $kRoom;
    $indexByKey[$key] = $idx;
}

foreach ($capturedSources as $row) {
    $key = (string) ($row['type'] ?? '') . '|' . (string) ($row['userId'] ?? '') . '|' . (string) ($row['groupId'] ?? '') . '|' . (string) ($row['roomId'] ?? '');
    if (isset($indexByKey[$key])) {
        $at = $indexByKey[$key];
        $prevCount = (int) ($existing[$at]['seen_count'] ?? 1);
        $existing[$at] = array_merge($existing[$at], $row, [
            'seen_count' => $prevCount + 1,
            'last_seen_at' => date('c'),
        ]);
    } else {
        $row['seen_count'] = 1;
        $row['last_seen_at'] = date('c');
        $existing[] = $row;
        $indexByKey[$key] = count($existing) - 1;
    }
}

if (count($existing) > 200) {
    $existing = array_slice($existing, -200);
}

file_put_contents($logPath, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode([
    'ok' => true,
    'captured_count' => count($capturedSources),
    'postback_handled' => $postbackHandled,
], JSON_UNESCAPED_UNICODE);
