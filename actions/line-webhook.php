<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../config/line_settings.php';

header('Content-Type: application/json; charset=UTF-8');

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
$capturedUsers = [];
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
        if ($userId === '' || strpos($userId, 'U') !== 0) {
            continue;
        }

        $capturedUsers[] = [
            'userId' => $userId,
            'type' => (string) ($source['type'] ?? ''),
            'event' => (string) ($event['type'] ?? ''),
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

foreach ($capturedUsers as $row) {
    $existing[] = $row;
}

if (count($existing) > 100) {
    $existing = array_slice($existing, -100);
}

file_put_contents($logPath, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode([
    'ok' => true,
    'captured_count' => count($capturedUsers),
], JSON_UNESCAPED_UNICODE);

