<?php

declare(strict_types=1);


require_once __DIR__ . '/_page_root.php';
use Theelincon\Rtdb\Db;

session_start();
require_once THEELINCON_ROOT . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$me = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read_all'])) {
    if (csrf_verify_request()) {
        foreach (Db::tableKeyed('web_notifications') as $pk => $row) {
            if ((int) ($row['user_id'] ?? 0) !== $me) {
                continue;
            }
            if (!empty($row['is_read'])) {
                continue;
            }
            $row['is_read'] = 1;
            Db::setRow('web_notifications', (string) $pk, $row);
        }
        header('Location: ' . app_path('pages/web-notifications.php') . '?read=1');
        exit;
    }
}

$rows = [];
foreach (Db::tableRows('web_notifications') as $row) {
    if ((int) ($row['user_id'] ?? 0) !== $me) {
        continue;
    }
    $rows[] = $row;
}
usort($rows, static function (array $a, array $b): int {
    return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
});
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แจ้งเตือนย้อนหลัง | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>body { background:#f8f9fa; font-family:'Sarabun', sans-serif; }</style>
</head>
<body>
<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>

<div class="container py-4">
    <?php if (!empty($_GET['read'])): ?>
        <div class="alert alert-success">ทำเครื่องหมายว่าอ่านแล้วเรียบร้อย</div>
    <?php endif; ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h4 class="fw-bold mb-0"><i class="bi bi-bell me-2 text-warning"></i>แจ้งเตือนย้อนหลัง</h4>
        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="mark_read_all" value="1">
            <button type="submit" class="btn btn-outline-secondary rounded-pill">อ่านทั้งหมดแล้ว</button>
        </form>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="list-group list-group-flush">
            <?php if (count($rows) === 0): ?>
                <div class="p-4 text-muted text-center">ยังไม่มีการแจ้งเตือน</div>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $link = trim((string) ($row['link'] ?? ''));
                    $isRead = !empty($row['is_read']);
                    ?>
                    <a class="list-group-item list-group-item-action py-3 <?= $isRead ? '' : 'bg-warning-subtle' ?>" href="<?= htmlspecialchars($link !== '' ? $link : app_path('pages/web-notifications.php'), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <div class="fw-semibold"><?= htmlspecialchars((string) ($row['title'] ?? 'แจ้งเตือน'), ENT_QUOTES, 'UTF-8') ?></div>
                            <small class="text-muted"><?= htmlspecialchars((string) ($row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                        <div class="small text-secondary mt-1"><?= htmlspecialchars((string) ($row['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
