<?php
declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$users = Db::tableKeyed('users');
$me = (int) $_SESSION['user_id'];
$meRow = $users[(string) $me] ?? [];
$meName = trim((string) ($meRow['fname'] ?? '') . ' ' . (string) ($meRow['lname'] ?? ''));
if ($meName === '') {
    $meName = (string) ($_SESSION['name'] ?? 'ผู้ใช้งาน');
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สร้างคำขอใบลา</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .form-card { border: none; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
        .btn-orange { background-color: #fd7e14; color: #fff; border: none; }
        .btn-orange:hover { background-color: #e86c00; color: #fff; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="fw-bold"><i class="bi bi-pencil-square text-warning me-2"></i>สร้างคำขออนุญาติลา</h3>
        <a href="<?= htmlspecialchars(app_path('pages/leave-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-4">กลับ</a>
    </div>

    <div class="card form-card p-4">
        <form action="<?= htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8') ?>?action=save_leave_request" method="post" enctype="multipart/form-data" id="leaveForm">
            <?php csrf_field(); ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">ผู้ขอ</label>
                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($meName, ENT_QUOTES, 'UTF-8') ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">ประเภทการลา</label>
                    <select name="leave_type" class="form-select" required>
                        <option value="">เลือกประเภทการลา</option>
                        <option value="ลาป่วย">ลาป่วย</option>
                        <option value="ลากิจ">ลากิจ</option>
                        <option value="ลาพักร้อน">ลาพักร้อน</option>
                        <option value="ลาอื่นๆ">ลาอื่นๆ</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">สาเหตุการลา</label>
                    <textarea name="reason" class="form-control" rows="4" placeholder="ระบุเหตุผลการลา" required></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">ตั้งแต่วันที่</label>
                    <input type="date" name="start_date" id="startDate" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">ถึงวันที่</label>
                    <input type="date" name="end_date" id="endDate" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">รวมจำนวนวันลา</label>
                    <input type="text" id="daysCountText" class="form-control bg-light" value="0 วัน" readonly>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">แนบภาพถ่าย (ไม่บังคับ)</label>
                    <input type="file" name="attachment" class="form-control" accept="image/*">
                    <div class="form-text">รองรับไฟล์ภาพ JPG, PNG, WEBP, GIF</div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-orange px-4">
                    <i class="bi bi-save2 me-1"></i> บันทึกคำขอ
                </button>
                <a href="<?= htmlspecialchars(app_path('pages/leave-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light border px-4">ยกเลิก</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const startDateEl = document.getElementById('startDate');
    const endDateEl = document.getElementById('endDate');
    const daysCountEl = document.getElementById('daysCountText');

    function updateDays() {
        const start = startDateEl.value ? new Date(startDateEl.value + 'T00:00:00') : null;
        const end = endDateEl.value ? new Date(endDateEl.value + 'T00:00:00') : null;
        if (!start || !end || Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end < start) {
            daysCountEl.value = '0 วัน';
            return;
        }
        const dayMs = 24 * 60 * 60 * 1000;
        const days = Math.floor((end.getTime() - start.getTime()) / dayMs) + 1;
        daysCountEl.value = days + ' วัน';
    }

    startDateEl.addEventListener('change', updateDays);
    endDateEl.addEventListener('change', updateDays);
})();
</script>
</body>
</html>

