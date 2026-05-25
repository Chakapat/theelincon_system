<?php

declare(strict_types=1);

/** ส่ง/ตอบข้อความ LINE Messaging API (Flex / text) */

function line_messaging_push(string $channelToken, string $to, array $messages): bool
{
    if ($channelToken === '' || $to === '' || $messages === []) {
        return false;
    }

    return line_messaging_api_post(
        $channelToken,
        'https://api.line.me/v2/bot/message/push',
        ['to' => $to, 'messages' => array_values($messages)]
    );
}

function line_messaging_reply(string $channelToken, string $replyToken, array $messages): bool
{
    if ($channelToken === '' || $replyToken === '' || $messages === []) {
        return false;
    }

    return line_messaging_api_post(
        $channelToken,
        'https://api.line.me/v2/bot/message/reply',
        ['replyToken' => $replyToken, 'messages' => array_values($messages)]
    );
}

/**
 * @param array<string, mixed> $payload
 */
function line_messaging_api_post(string $channelToken, string $url, array $payload): bool
{
    $ch = curl_init($url);
    if ($ch === false) {
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
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $body !== false && $code >= 200 && $code < 300;
}
