<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/includes/line_notify_runtime.php';

use Theelincon\Rtdb\Db;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo 'ไม่มีสิทธิ์เข้าถึง';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_line_notify'])) {
    if (csrf_verify_request()) {
        $targetGroup = trim((string) ($_POST['target_group_id'] ?? ''));
        $approver = trim((string) ($_POST['approver_user_id'] ?? ''));
        Db::mergeRow(LINE_NOTIFY_CONFIG_TABLE, LINE_NOTIFY_CONFIG_PK, [
            'target_group_id' => $targetGroup === '' ? null : $targetGroup,
            'approver_user_id' => $approver === '' ? null : $approver,
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => (int) $_SESSION['user_id'],
        ]);
        header('Location: ' . app_path('pages/internal/line-notify-config.php') . '?saved=1');
        exit;
    }
}

$row = line_notify_config_row();
$formGroup = line_notify_field_string($row, 'target_group_id');
$formApprover = line_notify_field_string($row, 'approver_user_id');

$effectiveGroup = line_effective_target_group_id();
$effectiveApprover = line_effective_approver_user_id();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่า LINE แจ้งเตือน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>body { background:#f8f9fa; font-family:'Sarabun', sans-serif; }</style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4" style="max-width: 720px;">
    <h4 class="fw-bold mb-3"><i class="bi bi-bell-fill me-2 text-success"></i>ตั้งค่า LINE แจ้งเตือน</h4>

    <?php if (!empty($_GET['saved'])): ?>
        <div class="alert alert-success rounded-3">บันทึกการตั้งค่าแล้ว</div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body">
            <dl class="row small mb-0">
                <dt class="col-sm-4 text-muted">กลุ่มปลายทาง</dt>
                <dd class="col-sm-8 font-monospace text-break"><?= htmlspecialchars($effectiveGroup !== '' ? $effectiveGroup : '(ว่าง)', ENT_QUOTES, 'UTF-8') ?></dd>
                <dt class="col-sm-4 text-muted">ผู้อนุมัติ (User ID)</dt>
                <dd class="col-sm-8 font-monospace text-break"><?= htmlspecialchars($effectiveApprover !== '' ? $effectiveApprover : '(ว่าง)', ENT_QUOTES, 'UTF-8') ?></dd>
            </dl>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body">
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="save_line_notify" value="1">

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="target_group_id">LINE Target Group ID</label>
                    <input type="text" class="form-control font-monospace" id="target_group_id" name="target_group_id"
                           value="<?= htmlspecialchars($formGroup, ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="off">
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold" for="approver_user_id">LINE Approver User ID</label>
                    <input type="text" class="form-control font-monospace" id="approver_user_id" name="approver_user_id"
                           value="<?= htmlspecialchars($formApprover, ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="off">
                </div>

                <button type="submit" class="btn btn-warning fw-semibold px-4 rounded-pill">
                    <i class="bi bi-check2-circle me-1"></i>บันทึก
                </button>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
