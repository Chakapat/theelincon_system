<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../config/line_settings.php';

header('Content-Type: application/json; charset=UTF-8');

/**
 * Return true when LINE group/room text explicitly mentions this bot.
 */
function line_is_mention_to_bot(array $event): bool
{
    $message = $event['message'] ?? null;
    if (!is_array($message) || (string) ($message['type'] ?? '') !== 'text') {
        return false;
    }

    $text = (string) ($message['text'] ?? '');
    $mention = $message['mention'] ?? null;
    if (is_array($mention) && isset($mention['mentionees']) && is_array($mention['mentionees'])) {
        $botUserId = (string) (defined('LINE_BOT_USER_ID') ? LINE_BOT_USER_ID : '');
        foreach ($mention['mentionees'] as $m) {
            if (!is_array($m)) {
                continue;
            }
            if (!empty($m['isSelf'])) {
                return true;
            }
            if ($botUserId !== '' && isset($m['userId']) && (string) $m['userId'] === $botUserId) {
                return true;
            }
        }
    }

    // Fallback text check for clients where mention metadata is missing.
    return mb_strpos($text, '@') !== false;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_body'], JSON_UNESCAPED_UNICODE);
    exit;
}

$lineSignature = (string) ($_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '');
$channelSecret = (string) LINE_MESSAGING_CHANNEL_SECRET;

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
if (is_array($events)) {
    foreach ($events as $event) {
        if (!is_array($event)) {
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
        $eventType = (string) ($event['type'] ?? '');

        // Group/room mode: only process text messages that mention the bot.
        if (($sourceType === 'group' || $sourceType === 'room') && $eventType === 'message') {
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

// Upsert records by identity so file does not grow on every message.
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
], JSON_UNESCAPED_UNICODE);

