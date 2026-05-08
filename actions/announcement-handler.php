<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit('Unauthorized');
}

$me = (int) $_SESSION['user_id'];
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$ann_needs_csrf = ($action === 'ack' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
    || ($action === 'save' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
    || ($action === 'delete');
if ($ann_needs_csrf && !csrf_verify_request()) {
    if ($action === 'ack') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ann_redirect('pages/internal/announcements.php?error=csrf');
}

function ann_redirect(string $path): void
{
    header('Location: ' . app_path($path));
    exit;
}

if ($action === 'ack' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $raw = file_get_contents('php://input');
    $in = json_decode($raw, true) ?: $_POST;
    $ids = $in['ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids === []) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'no_ids'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    foreach ($ids as $aid) {
        if ($aid <= 0) {
            continue;
        }
        if (Db::row('internal_announcements', (string) $aid) === null) {
            continue;
        }
        $pk = Db::compositeKey([(string) $aid, (string) $me]);
        Db::setRow('announcement_reads', $pk, [
            'announcement_id' => $aid,
            'user_id' => $me,
        ]);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$isAdmin) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden');
}

if ($action === 'save' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $title = trim((string) ($_POST['title'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));
    $isPinned = isset($_POST['is_pinned']) && (string) $_POST['is_pinned'] === '1' ? 1 : 0;
    $mustAck = isset($_POST['must_ack']) && (string) $_POST['must_ack'] === '1' ? 1 : 0;
    $editId = (int) ($_POST['id'] ?? 0);

    if ($title === '' || $body === '') {
        ann_redirect('pages/internal/announcements.php?error=required');
    }
    if (mb_strlen($title, 'UTF-8') > 255) {
        $title = mb_substr($title, 0, 255, 'UTF-8');
    }

    $now = date('Y-m-d H:i:s');
    if ($editId > 0) {
        $cur = Db::row('internal_announcements', (string) $editId);
        if ($cur !== null) {
            Db::setRow('internal_announcements', (string) $editId, array_merge($cur, [
                'title' => $title,
                'body' => $body,
                'is_pinned' => $isPinned,
                'must_ack' => $mustAck,
                'updated_at' => $now,
            ]));
        }
    } else {
        $nid = Db::nextNumericId('internal_announcements', 'id');
        Db::setRow('internal_announcements', (string) $nid, [
            'id' => $nid,
            'title' => $title,
            'body' => $body,
            'is_pinned' => $isPinned,
            'must_ack' => $mustAck,
            'created_by' => $me,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    ann_redirect('pages/internal/announcements.php?success=1');
}

if ($action === 'delete' && $isAdmin) {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        Db::deleteWhereEquals('announcement_reads', 'announcement_id', (string) $id);
        Db::deleteRow('internal_announcements', (string) $id);
    }
    ann_redirect('pages/internal/announcements.php?deleted=1');
}

header('HTTP/1.1 400 Bad Request');
exit('Bad request');
