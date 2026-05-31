<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__) . '/config/connect_database.php';
require_once dirname(__DIR__) . '/includes/web_notifications.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'list'));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($action === 'list') {
    echo json_encode([
        'ok' => true,
        'unread' => tnc_notif_unread_count($userId),
        'items' => tnc_notif_list_for_user($userId, 20),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'poll') {
    echo json_encode(array_merge(['ok' => true], tnc_notif_poll_state($userId)), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'mark_read' && $method === 'POST') {
    if (!csrf_verify_request()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    tnc_notif_mark_read($userId, $id);
    echo json_encode(['ok' => true, 'unread' => tnc_notif_unread_count($userId)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'mark_all_read' && $method === 'POST') {
    if (!csrf_verify_request()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    tnc_notif_mark_all_read($userId);
    echo json_encode(['ok' => true, 'unread' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'bad_request'], JSON_UNESCAPED_UNICODE);
