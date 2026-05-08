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
$isFinanceRole = isset($_SESSION['role']) && in_array((string) $_SESSION['role'], ['admin', 'Accounting'], true);
if (!$isFinanceRole) {
    header('Location: ' . app_path('index.php'));
    exit;
}

$me = (int) $_SESSION['user_id'];
$users = Db::tableRows('users');
usort($users, static function (array $a, array $b): int {
    return strcmp(
        trim((string) ($a['fname'] ?? '') . ' ' . (string) ($a['lname'] ?? '')),
        trim((string) ($b['fname'] ?? '') . ' ' . (string) ($b['lname'] ?? ''))
    );
});
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สร้างคำขอเบิกเงินล่วงหน้า</title>
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
<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'invalid_input'): ?>
        <div class="alert alert-danger">กรุณากรอกข้อมูลให้ครบ: วันที่ และจำนวนเงิน</div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="fw-bold"><i class="bi bi-cash-coin text-warning me-2"></i>สร้างคำขอเบิกเงินล่วงหน้า</h3>
        <a href="<?= htmlspecialchars(app_path('pages/advance-cash-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-4">กลับ</a>
    </div>

    <div class="card form-card p-4">
        <form action="<?= htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8') ?>?action=save_advance_cash_request" method="post">
            <?php csrf_field(); ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">ผู้ขอ</label>
                    <select name="requested_by" class="form-select" required>
                        <?php foreach ($users as $u): ?>
                            <?php
                            $uid = (int) ($u['userid'] ?? 0);
                            if ($uid <= 0) {
                                continue;
                            }
                            $name = trim((string) ($u['fname'] ?? '') . ' ' . (string) ($u['lname'] ?? ''));
                            if ($name === '') {
                                $name = (string) ($u['nickname'] ?? ('USER #' . $uid));
                            }
                            ?>
                            <option value="<?= $uid ?>" <?= $uid === $me ? 'selected' : '' ?>><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">วันที่ขอ</label>
                    <input type="date" name="request_date" class="form-control" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">จำนวนเงิน (บาท)</label>
                    <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">วัตถุประสงค์การเบิก <span class="text-muted fw-normal">(ไม่บังคับ)</span></label>
                    <textarea name="purpose" class="form-control" rows="4" placeholder="ระบุว่าจะนำเงินไปใช้ทำอะไร"></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-orange px-4">
                    <i class="bi bi-save2 me-1"></i> บันทึกคำขอ
                </button>
                <a href="<?= htmlspecialchars(app_path('pages/advance-cash-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light border px-4">ยกเลิก</a>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
