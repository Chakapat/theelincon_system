<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/socket.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$exp = time() + 7200;
$payload = $userId . '|' . $exp;
$sig = hash_hmac('sha256', $payload, socket_io_secret());
$raw = $payload . '|' . $sig;
$token = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

echo json_encode([
    'ok' => true,
    'userId' => $userId,
    'token' => $token,
    'url' => socket_io_public_url(),
], JSON_UNESCAPED_UNICODE);
