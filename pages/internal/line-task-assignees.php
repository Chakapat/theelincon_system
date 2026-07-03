<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/line_task_assignees.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_flash.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_can('page.internal.line_task')) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
}

$assignees = line_task_assignees_list(false);
$errorCode = trim((string) ($_GET['error'] ?? ''));
$flashCreated = !empty($_GET['created']);
$flashUpdated = !empty($_GET['updated']);
$flashDeleted = !empty($_GET['deleted']);

$errorMessages = [
    'csrf' => 'คำขอไม่ถูกต้อง กรุณาลองใหม่',
    'need_name' => 'กรุณาระบุชื่อผู้รับผิดชอบ',
    'invalid_line_id' => 'User LINE ID ไม่ถูกต้อง (ต้องขึ้นต้นด้วย U และความยาว 33 ตัวอักษร)',
    'not_found' => 'ไม่พบรายการที่ต้องการ',
    'delete_failed' => 'ลบไม่สำเร็จ',
    'save_failed' => 'บันทึกไม่สำเร็จ',
];
$errorText = $errorMessages[$errorCode] ?? ($errorCode !== '' ? $errorCode : '');

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = $editId > 0 ? line_task_assignee_by_id($editId) : null;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายชื่อผู้รับงาน LINE | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background:#f6f8fb; font-family:'Sarabun', sans-serif; }
        .task-shell { max-width: 920px; }
        .task-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 .28rem .95rem rgba(0,0,0,.055);
            background: #fff;
        }
        .btn-copper {
            min-height: 44px;
            border-radius: 10px;
            border: none;
            color: #fff;
            font-weight: 700;
            background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%);
        }
        .btn-copper:hover { color:#fff; filter: brightness(1.05); }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<main class="container task-shell py-4 py-lg-5">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">รายชื่อผู้รับงาน LINE</h1>
            <p class="text-muted mb-0 small">แยกจากพนักงานในระบบ — ใช้เลือกผู้รับผิดชอบเมื่อสั่งงานในกลุ่ม LINE</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars(app_path('pages/internal/line-task-create.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-3">
                <i class="bi bi-clipboard-check me-1"></i>หน้าสั่งงาน
            </a>
            <a href="<?= htmlspecialchars(app_path('pages/internal/line-notify-config.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-success rounded-pill px-3">
                <i class="bi bi-gear me-1"></i>ตั้งค่า LINE
            </a>
        </div>
    </div>

    <?php if ($errorText !== ''): ?>
        <div class="alert alert-danger rounded-3"><?= htmlspecialchars($errorText, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($flashCreated): ?>
        <div class="alert alert-success rounded-3">เพิ่มรายชื่อเรียบร้อย</div>
    <?php endif; ?>
    <?php if ($flashUpdated): ?>
        <div class="alert alert-success rounded-3">บันทึกการแก้ไขเรียบร้อย</div>
    <?php endif; ?>
    <?php if ($flashDeleted): ?>
        <div class="alert alert-success rounded-3">ลบรายชื่อเรียบร้อย</div>
    <?php endif; ?>

    <div class="task-card p-3 p-lg-4 mb-4">
        <h2 class="h5 fw-bold mb-3"><?= $editRow ? 'แก้ไขผู้รับงาน' : 'เพิ่มผู้รับงาน' ?></h2>
        <form method="post" action="<?= htmlspecialchars(app_path('actions/line-task-handler.php?action=save_assignee'), ENT_QUOTES, 'UTF-8') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_assignee">
            <?php if ($editRow): ?>
                <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold" for="name">ชื่อ <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name" required maxlength="120"
                           value="<?= htmlspecialchars((string) ($editRow['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="เช่น คุณสมชาย">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold" for="user_line_id">User LINE ID <span class="text-danger">*</span></label>
                    <input type="text" class="form-control mono" id="user_line_id" name="user_line_id" required
                           value="<?= htmlspecialchars((string) ($editRow['user_line_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Uxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" autocomplete="off">
                    <div class="form-text">ดึงจาก Webhook / ตั้งค่า LINE — ต้องอยู่ในกลุ่มสั่งงานด้วย</div>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                            <?= !$editRow || !isset($editRow['is_active']) || (int) $editRow['is_active'] !== 0 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">ใช้งาน</label>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-copper px-4">
                    <i class="bi bi-check2-circle me-1"></i><?= $editRow ? 'บันทึก' : 'เพิ่มรายชื่อ' ?>
                </button>
                <?php if ($editRow): ?>
                    <a href="<?= htmlspecialchars(app_path('pages/internal/line-task-assignees.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light rounded-pill px-3">ยกเลิก</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="task-card p-3 p-lg-4">
        <h2 class="h5 fw-bold mb-3">รายชื่อทั้งหมด</h2>
        <?php if ($assignees === []): ?>
            <p class="text-muted mb-0">ยังไม่มีรายชื่อ — เพิ่มผู้รับงานด้านบนก่อนสั่งงาน</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">ชื่อ</th>
                            <th scope="col">User LINE ID</th>
                            <th scope="col">สถานะ</th>
                            <th scope="col" class="text-end">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignees as $row): ?>
                            <?php
                            $rid = (int) ($row['id'] ?? 0);
                            $active = !isset($row['is_active']) || (int) $row['is_active'] !== 0;
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="mono small"><?= htmlspecialchars((string) ($row['user_line_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ($active): ?>
                                        <span class="badge rounded-pill text-bg-success-subtle text-success-emphasis border border-success-subtle">ใช้งาน</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill text-bg-secondary-subtle text-secondary-emphasis border">ปิด</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?= htmlspecialchars(app_path('pages/internal/line-task-assignees.php?edit=' . $rid), ENT_QUOTES, 'UTF-8') ?>"
                                       class="btn btn-sm btn-outline-primary rounded-pill px-3 me-1">แก้ไข</a>
                                    <form method="post" action="<?= htmlspecialchars(app_path('actions/line-task-handler.php?action=delete_assignee'), ENT_QUOTES, 'UTF-8') ?>"
                                          class="d-inline" onsubmit="return confirm('ลบรายชื่อนี้?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_assignee">
                                        <input type="hidden" name="id" value="<?= $rid ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">ลบ</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
