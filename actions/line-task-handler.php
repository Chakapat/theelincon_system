<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/line_task_assignees.php';
require_once __DIR__ . '/../includes/line_task_order.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit('Unauthorized');
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$me = (int) $_SESSION['user_id'];

function line_task_redirect(string $path): void
{
    header('Location: ' . app_path($path));
    exit;
}

function line_task_require_csrf(): void
{
    if (!csrf_verify_request()) {
        line_task_redirect('pages/internal/line-task-create.php?error=csrf');
    }
}

if ($action === 'save_assignee') {
    if (!user_can('page.internal.line_task')) {
        http_response_code(403);
        exit('Forbidden');
    }
    line_task_require_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $userLineId = trim((string) ($_POST['user_line_id'] ?? ''));
    $isActive = !isset($_POST['is_active']) || (string) $_POST['is_active'] === '1';

    $result = line_task_assignee_save($id, $name, $userLineId, $isActive);
    if (!$result['ok']) {
        $err = (string) ($result['error'] ?? 'save_failed');
        line_task_redirect('pages/internal/line-task-assignees.php?error=' . rawurlencode($err));
    }

    $q = $id > 0 ? 'updated=1' : 'created=1';
    line_task_redirect('pages/internal/line-task-assignees.php?' . $q);
}

if ($action === 'delete_assignee') {
    if (!user_can('page.internal.line_task')) {
        http_response_code(403);
        exit('Forbidden');
    }
    line_task_require_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    $result = line_task_assignee_delete($id);
    if (!$result['ok']) {
        line_task_redirect('pages/internal/line-task-assignees.php?error=' . rawurlencode((string) ($result['error'] ?? 'delete_failed')));
    }
    line_task_redirect('pages/internal/line-task-assignees.php?deleted=1');
}

if ($action === 'send_task') {
    if (!user_can('page.internal.line_task')) {
        http_response_code(403);
        exit('Forbidden');
    }
    line_task_require_csrf();

    $assigneeId = (int) ($_POST['assignee_id'] ?? 0);
    $siteId = (int) ($_POST['site_id'] ?? 0);
    $site = line_task_site_by_id($siteId);
    $destination = line_task_site_name($siteId, $site);
    $details = trim((string) ($_POST['details'] ?? ''));
    $dueDate = trim((string) ($_POST['due_date'] ?? ''));
    $dueTime = trim((string) ($_POST['due_time'] ?? '17:00'));
    $dueAt = $dueDate !== '' ? ($dueDate . ' ' . ($dueTime !== '' ? $dueTime : '17:00') . ':00') : '';

    $result = line_task_create_and_send($assigneeId, $destination, $details, $dueAt, $me, $siteId);
    if (!$result['ok']) {
        $err = (string) ($result['error'] ?? 'send_failed');
        if ($siteId > 0 && $destination === '') {
            $err = 'site_not_found';
        }
        line_task_redirect('pages/internal/line-task-create.php?error=' . rawurlencode($err));
    }

    $taskId = (int) ($result['task_id'] ?? 0);
    line_task_redirect('pages/internal/line-task-create.php?sent=1&task_id=' . $taskId);
}

if ($action === 'delete_task') {
    if (!user_can('page.internal.line_task')) {
        http_response_code(403);
        exit('Forbidden');
    }
    line_task_require_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    $result = line_task_delete($id);
    if (!$result['ok']) {
        line_task_redirect('pages/internal/line-task-create.php?error=' . rawurlencode((string) ($result['error'] ?? 'delete_failed')));
    }
    line_task_redirect('pages/internal/line-task-create.php?deleted=1');
}

http_response_code(400);
exit('Bad request');
