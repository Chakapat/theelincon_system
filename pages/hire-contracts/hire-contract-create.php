<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/contractors.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$error = trim((string) ($_GET['error'] ?? ''));
$listUrl = app_path('pages/hire-contracts/hire-contract-list.php');
$handler = app_path('actions/action-handler.php');
$contractorRows = Db::tableRows('contractors');
usort($contractorRows, static function (array $a, array $b): int {
    return strnatcasecmp(tnc_contractor_full_name_th($a), tnc_contractor_full_name_th($b));
});
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สร้างสัญญาจ้าง (HC) | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', system-ui, sans-serif; background: #f4f7fb; min-height: 100vh; }
        .hc-create-card { border-radius: 1rem; border: 1px solid #e8edf4; box-shadow: 0 8px 28px rgba(15, 23, 42, 0.06); }
    </style>
</head>
<body class="tnc-app-body">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 pb-5" style="max-width: 720px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 fw-bold mb-0"><i class="bi bi-file-earmark-plus text-tnc-orange me-2"></i>สร้างสัญญาจ้าง</h1>
        <a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm rounded-pill">กลับ</a>
    </div>
    <?php if ($error === 'required'): ?>
        <div class="alert alert-warning py-2">กรุณากรอกข้อมูลให้ครบ</div>
    <?php elseif ($error === 'contractor_required'): ?>
        <div class="alert alert-warning py-2">กรุณาเลือกผู้รับจ้างจากทะเบียนผู้รับจ้าง</div>
    <?php endif; ?>
    <div class="card hc-create-card bg-white p-4">
        <form action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>?action=save_standalone_hire_contract" method="post">
            <?php csrf_field(); ?>
            <div class="mb-3">
                <label class="form-label fw-bold" for="contractor_search">ผู้รับจ้าง <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="text" id="contractor_search" class="form-control" list="contractor_list" required placeholder="พิมพ์ชื่อหรือเลขบัตร แล้วเลือกจากรายการ" autocomplete="off">
                    <a href="<?= htmlspecialchars(app_path('pages/contractors/contractor-form.php'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="เพิ่มผู้รับจ้าง"><i class="bi bi-person-plus"></i></a>
                </div>
                <datalist id="contractor_list">
                    <?php foreach ($contractorRows as $contractorRow): ?>
                        <option value="<?= htmlspecialchars(tnc_contractor_display_label($contractorRow), ENT_QUOTES, 'UTF-8') ?>" data-id="<?= (int) ($contractorRow['id'] ?? 0) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <input type="hidden" name="contractor_id" id="contractor_id" value="">
                <div class="form-text">เลือกจาก<a href="<?= htmlspecialchars(app_path('pages/contractors/contractor-list.php'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">ทะเบียนผู้รับจ้าง</a></div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">ขอบเขต / รายละเอียดงาน <span class="text-danger">*</span></label>
                <textarea name="title" class="form-control" rows="5" required maxlength="8000" placeholder="รายละเอียดขอบเขตงาน"></textarea>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">มูลค่าสัญญา (บาท) <span class="text-danger">*</span></label>
                    <input type="number" name="contract_amount" class="form-control" min="0.01" step="0.01" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">จำนวนงวดจ่าย</label>
                    <input type="number" name="installment_total" class="form-control" min="1" max="120" value="1" required>
                </div>
            </div>
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-orange btn-lg rounded-pill fw-semibold">บันทึกสัญญาจ้าง</button>
            </div>
        </form>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const searchInput = document.getElementById('contractor_search');
    const contractorIdInput = document.getElementById('contractor_id');
    const datalist = document.getElementById('contractor_list');
    if (!searchInput || !contractorIdInput || !datalist) return;

    function syncContractorId() {
        const typed = (searchInput.value || '').trim();
        if (typed === '') {
            contractorIdInput.value = '';
            return;
        }
        let matchedId = '';
        datalist.querySelectorAll('option').forEach((opt) => {
            const optValue = (opt.value || '').trim();
            if (matchedId === '' && optValue.toLowerCase() === typed.toLowerCase()) {
                matchedId = (opt.getAttribute('data-id') || '').trim();
            }
        });
        contractorIdInput.value = matchedId;
    }

    searchInput.addEventListener('input', syncContractorId);
    searchInput.addEventListener('change', syncContractorId);
    searchInput.closest('form')?.addEventListener('submit', syncContractorId);
})();
</script>
</body>
</html>
