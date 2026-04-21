<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/connect_database.php';

function chat_json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    chat_json_out(['ok' => false, 'error' => 'unauthorized'], 401);
}

$me = (int) $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? '';

$postMutating = ['mark_read', 'send'];
if (in_array($action, $postMutating, true) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !csrf_verify_request()) {
    chat_json_out(['ok' => false, 'error' => 'csrf'], 403);
}

if ($action === 'user') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0 || $id === $me) {
        chat_json_out(['ok' => false, 'error' => 'invalid_id'], 400);
    }
    $row = Db::row('users', (string) $id);
    if ($row === null) {
        chat_json_out(['ok' => false, 'error' => 'not_found'], 404);
    }
    chat_json_out(['ok' => true, 'user' => [
        'userid' => $row['userid'] ?? $id,
        'user_code' => $row['user_code'] ?? '',
        'fname' => $row['fname'] ?? '',
        'lname' => $row['lname'] ?? '',
        'nickname' => $row['nickname'] ?? '',
        'role' => $row['role'] ?? '',
    ]]);
}

if ($action === 'users') {
    $q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
    $rows = [];
    foreach (Db::tableRows('users') as $u) {
        $uid = (int) ($u['userid'] ?? 0);
        if ($uid === $me || $uid <= 0) {
            continue;
        }
        if ($q !== '') {
            $hay = mb_strtolower(
                ($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '') . ' ' . ($u['user_code'] ?? '')
            );
            if (!str_contains($hay, $q)) {
                continue;
            }
        }
        $rows[] = [
            'userid' => $uid,
            'user_code' => $u['user_code'] ?? '',
            'fname' => $u['fname'] ?? '',
            'lname' => $u['lname'] ?? '',
            'nickname' => $u['nickname'] ?? '',
            'role' => $u['role'] ?? '',
        ];
    }
    usort($rows, static function ($a, $b): int {
        return strcmp((string) ($a['fname'] ?? ''), (string) ($b['fname'] ?? ''))
            ?: strcmp((string) ($a['lname'] ?? ''), (string) ($b['lname'] ?? ''));
    });
    $rows = array_slice($rows, 0, 200);
    chat_json_out(['ok' => true, 'users' => $rows]);
}

if ($action === 'threads') {
    $usersKeyed = Db::tableKeyed('users');
    $peerStat = [];

    foreach (Db::tableRows('chat_messages') as $m) {
        $sid = (int) ($m['sender_id'] ?? 0);
        $rid = (int) ($m['recipient_id'] ?? 0);
        $peer = null;
        if ($sid === $me && $rid !== $me) {
            $peer = $rid;
        } elseif ($rid === $me && $sid !== $me) {
            $peer = $sid;
        }
        if ($peer === null || !isset($usersKeyed[(string) $peer])) {
            continue;
        }
        $ca = (string) ($m['created_at'] ?? '');
        if (!isset($peerStat[$peer]['last_at']) || strcmp($ca, (string) $peerStat[$peer]['last_at']) > 0) {
            $peerStat[$peer]['last_at'] = $ca;
        }
        if ($rid === $me && $sid === $peer && empty($m['read_at'])) {
            $peerStat[$peer]['unread'] = ($peerStat[$peer]['unread'] ?? 0) + 1;
        }
    }

    $rows = [];
    foreach ($peerStat as $peerId => $info) {
        $u = $usersKeyed[(string) $peerId] ?? null;
        if ($u === null) {
            continue;
        }
        $rows[] = [
            'userid' => $u['userid'] ?? $peerId,
            'user_code' => $u['user_code'] ?? '',
            'fname' => $u['fname'] ?? '',
            'lname' => $u['lname'] ?? '',
            'nickname' => $u['nickname'] ?? '',
            'role' => $u['role'] ?? '',
            'last_at' => $info['last_at'] ?? null,
            'unread' => (int) ($info['unread'] ?? 0),
        ];
    }
    usort($rows, static function ($a, $b): int {
        return strcmp((string) ($b['last_at'] ?? ''), (string) ($a['last_at'] ?? ''));
    });

    chat_json_out(['ok' => true, 'threads' => $rows]);
}

if ($action === 'unread_total') {
    $c = 0;
    foreach (Db::tableRows('chat_messages') as $m) {
        if ((int) ($m['recipient_id'] ?? 0) === $me && empty($m['read_at'])) {
            ++$c;
        }
    }
    chat_json_out(['ok' => true, 'total' => $c]);
}

if ($action === 'messages') {
    $with = (int) ($_GET['with'] ?? 0);
    if ($with <= 0 || $with === $me) {
        chat_json_out(['ok' => false, 'error' => 'invalid_peer'], 400);
    }
    if (Db::row('users', (string) $with) === null) {
        chat_json_out(['ok' => false, 'error' => 'user_not_found'], 404);
    }

    $after = (int) ($_GET['after'] ?? 0);
    $pool = [];
    foreach (Db::tableRows('chat_messages') as $m) {
        $sid = (int) ($m['sender_id'] ?? 0);
        $rid = (int) ($m['recipient_id'] ?? 0);
        $between = ($sid === $me && $rid === $with) || ($sid === $with && $rid === $me);
        if (!$between) {
            continue;
        }
        $mid = (int) ($m['id'] ?? 0);
        if ($after > 0 && $mid <= $after) {
            continue;
        }
        $pool[] = $m;
    }

    usort($pool, static function ($a, $b): int {
        return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
    });

    if ($after > 0) {
        $pool = array_slice($pool, 0, 500);
    } else {
        $pool = array_slice(array_reverse($pool), 0, 150);
        usort($pool, static function ($a, $b): int {
            return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
        });
    }

    chat_json_out(['ok' => true, 'messages' => $pool]);
}

if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $in = json_decode($raw, true) ?: $_POST;
    $from = (int) ($in['from'] ?? 0);
    if ($from <= 0 || $from === $me) {
        chat_json_out(['ok' => false, 'error' => 'invalid_from'], 400);
    }
    $updated = 0;
    $now = date('Y-m-d H:i:s');
    foreach (Db::tableKeyed('chat_messages') as $pk => $m) {
        if ((int) ($m['recipient_id'] ?? 0) !== $me || (int) ($m['sender_id'] ?? 0) !== $from) {
            continue;
        }
        if (!empty($m['read_at'])) {
            continue;
        }
        Db::mergeRow('chat_messages', (string) $pk, ['read_at' => $now]);
        ++$updated;
    }
    chat_json_out(['ok' => true, 'updated' => $updated]);
}

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $in = json_decode($raw, true) ?: $_POST;
    $to = (int) ($in['to'] ?? 0);
    $body = trim((string) ($in['body'] ?? ''));
    if ($to <= 0 || $to === $me) {
        chat_json_out(['ok' => false, 'error' => 'invalid_recipient'], 400);
    }
    if ($body === '') {
        chat_json_out(['ok' => false, 'error' => 'empty_body'], 400);
    }
    if (mb_strlen($body, 'UTF-8') > 5000) {
        $body = mb_substr($body, 0, 5000, 'UTF-8');
    }
    if (Db::row('users', (string) $to) === null) {
        chat_json_out(['ok' => false, 'error' => 'user_not_found'], 404);
    }

    $newId = Db::nextNumericId('chat_messages', 'id');
    $createdAt = date('Y-m-d H:i:s');
    Db::setRow('chat_messages', (string) $newId, [
        'id' => $newId,
        'sender_id' => $me,
        'recipient_id' => $to,
        'body' => $body,
        'created_at' => $createdAt,
    ]);

    $row = Db::row('chat_messages', (string) $newId);
    chat_json_out(['ok' => true, 'message' => $row]);
}

chat_json_out(['ok' => false, 'error' => 'unknown_action'], 400);
