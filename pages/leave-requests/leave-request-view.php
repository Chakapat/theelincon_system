<?php
declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$me = (int) $_SESSION['user_id'];
$isAdmin = isset($_SESSION['role']) && (string) $_SESSION['role'] === 'admin';
$row = Db::rowByIdField('leave_requests', $id);

if (!$row || (!$isAdmin && (int) ($row['requested_by'] ?? 0) !== $me)) {
    header('Location: ' . app_path('pages/leave-requests/leave-request-list.php'));
    exit;
}

$status = (string) ($row['status'] ?? 'draft');
$badgeClass = 'bg-secondary';
if ($status === 'pending') {
    $badgeClass = 'bg-warning text-dark';
} elseif ($status === 'approved') {
    $badgeClass = 'bg-success';
} elseif ($status === 'rejected') {
    $badgeClass = 'bg-danger';
}

$attachmentUrl = trim((string) ($row['attachment_url'] ?? ''));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดใบลา</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .detail-card { border: none; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['line_error']) && $_GET['line_error'] === '1'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            บันทึกใบลาแล้ว แต่ส่งไป LINE ไม่สำเร็จ — ตรวจสอบการตั้งค่า LINE หรือแจ้งผู้ดูแลระบบ (ใบลายังอยู่ในสถานะร่าง)
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="fw-bold"><i class="bi bi-file-earmark-text text-warning me-2"></i>รายละเอียดใบลา</h3>
        <a href="<?= htmlspecialchars(app_path('pages/leave-requests/leave-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-4">กลับรายการ</a>
    </div>

    <div class="card detail-card p-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="text-muted small">เลขที่คำขอ</div>
                <div class="fw-bold fs-5 text-primary"><?= htmlspecialchars((string) ($row['leave_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <span class="badge <?= $badgeClass ?> rounded-pill px-3 py-2"><?= strtoupper($status) ?></span>
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <div class="text-muted small">ประเภทการลา</div>
                <div class="fw-semibold"><?= htmlspecialchars((string) ($row['leave_type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">ตั้งแต่วันที่</div>
                <div class="fw-semibold"><?= htmlspecialchars((string) ($row['start_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">ถึงวันที่</div>
                <div class="fw-semibold"><?= htmlspecialchars((string) ($row['end_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">จำนวนวันลา</div>
                <div class="fw-semibold"><?= number_format((float) ($row['days_count'] ?? 0), 2) ?> วัน</div>
            </div>
            <div class="col-md-8">
                <div class="text-muted small">วันที่สร้างคำขอ</div>
                <div class="fw-semibold"><?= htmlspecialchars((string) ($row['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="col-12">
                <div class="text-muted small">สาเหตุการลา</div>
                <div class="p-3 bg-light rounded"><?= nl2br(htmlspecialchars((string) ($row['reason'] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div>
            </div>
            <?php if ($attachmentUrl !== ''): ?>
                <div class="col-12">
                    <div class="text-muted small mb-2">ภาพแนบ</div>
                    <img src="<?= htmlspecialchars(app_path($attachmentUrl), ENT_QUOTES, 'UTF-8') ?>" alt="leave attachment" class="img-fluid rounded border" style="max-height: 360px;">
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

