<?php
declare(strict_types=1);


session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$canManage = user_is_finance_role();
if (!$canManage) {
    header('Location: ' . app_path('pages/stock/stock-list.php'));
    exit();
}

$siteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;
$handler = app_path('actions/stock-handler.php');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php
    require_once dirname(__DIR__, 2) . '/includes/tnc_ops_head.php';
    tnc_ops_head(['title' => 'เพิ่มประเภทอุปกรณ์ | Stock', 'sarabun_weights' => '400;600;700']);
    ?>
</head>
<body class="tnc-app-body tnc-layout-form">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 pb-5" style="max-width: 680px;">
    <h5 class="fw-bold mb-2"><i class="bi bi-box-seam text-warning me-2"></i>เพิ่มประเภทอุปกรณ์</h5>
    <div class="text-muted small mb-4">สร้างประเภทใหม่ให้เลือกใช้ในฟอร์มบันทึกรายการ</div>

    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger rounded-3">
            <?= (string) $_GET['error'] === 'duplicate' ? 'รหัสอุปกรณ์นี้มีอยู่แล้ว' : 'กรุณากรอกข้อมูลให้ครบ' ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>?action=save_product" class="stock-form-card bg-white p-4">
        <?php csrf_field(); ?>
        <input type="hidden" name="site_id" value="<?= $siteId ?>">
        <div class="mb-3">
            <label class="form-label fw-bold small">รหัสอุปกรณ์</label>
            <input type="text" name="code" class="form-control rounded-3" maxlength="64" required placeholder="เช่น PIPE-100">
        </div>
        <div class="mb-3">
            <label class="form-label fw-bold small">ชื่ออุปกรณ์</label>
            <input type="text" name="name" class="form-control rounded-3" maxlength="255" required placeholder="เช่น ท่อเหล็ก 100 ซม.">
        </div>
        <div class="mb-4">
            <label class="form-label fw-bold small">หน่วยนับ</label>
            <input type="text" name="unit" class="form-control rounded-3" maxlength="32" value="ชิ้น" required>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-warning text-white fw-bold rounded-pill px-4">บันทึกประเภทอุปกรณ์</button>
            <a href="<?= htmlspecialchars($siteId > 0 ? (app_path('pages/stock/stock-list.php') . '?site_id=' . $siteId) : app_path('pages/stock/stock-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill">กลับ</a>
        </div>
    </form>
</div>
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
</body>
</html>
