<?php

declare(strict_types=1);

session_start();

use Theelincon\Rtdb\Db;

require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/daily_site_report_projects.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

$projectOptions = daily_site_report_project_options();
$companies = Db::tableRows('company');
Db::sortRows($companies, 'name', false);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$report = null;
$photos = [];

if ($id > 0) {
    $report = Db::row('daily_site_reports', (string) $id);
    if (!$report) {
        header('Location: ' . app_path('pages/daily-site-report-list.php') . '?err=missing');
        exit;
    }
    $creator = (int) ($report['created_by'] ?? 0);
    if ($creator !== $userId && $role !== 'admin') {
        header('Location: ' . app_path('pages/daily-site-report-list.php') . '?err=forbidden');
        exit;
    }

    $photos = Db::filter('daily_site_report_photos', static fn (array $r): bool => (int) ($r['report_id'] ?? 0) === $id);
    usort($photos, static function ($a, $b): int {
        $sa = (int) ($a['sort_order'] ?? 0);
        $sb = (int) ($b['sort_order'] ?? 0);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }

        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    });
}

$saveUrl = htmlspecialchars(app_path('actions/daily-site-report-save.php'), ENT_QUOTES, 'UTF-8');
$listUrl = htmlspecialchars(app_path('pages/daily-site-report-list.php'), ENT_QUOTES, 'UTF-8');
$isEdit = $report !== null;

$selCompanyId = isset($report['company_id']) ? (int) $report['company_id'] : 0;
$selProject = $report['project_name'] ?? '';
$workerCountVal = $report['worker_count'] ?? '';

$pageTitle = $isEdit ? 'แก้ไขรายงานหน้างาน' : 'สร้างรายงานหน้างาน';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }
        .sec-card { border-radius: 1rem; border: 1px solid rgba(253,126,20,.15); box-shadow: 0 4px 18px rgba(0,0,0,.04); }
        .sec-title { font-size: 0.95rem; font-weight: 700; color: #c2410c; letter-spacing: .02em; }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container py-4 pb-5">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars(app_path('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none text-warning-emphasis">หน้าแรก</a></li>
            <li class="breadcrumb-item"><a href="<?= $listUrl ?>" class="text-decoration-none text-warning-emphasis">สมุดรายวันหน้างาน</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></li>
        </ol>
    </nav>

    <?php if (!empty($_GET['err'])): ?>
        <div class="alert alert-danger rounded-3 small">ไม่สามารถบันทึกได้ — โปรดเลือกบริษัทและโครงการให้ครบ และตรวจสอบข้อมูลอีกครั้ง</div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h4>
            <?php if ($isEdit): ?>
                <p class="text-muted small mb-0">เลขที่เอกสาร <strong><?= htmlspecialchars($report['report_no'], ENT_QUOTES, 'UTF-8') ?></strong></p>
            <?php else: ?>
                
            <?php endif; ?>
        </div>
        <?php if ($isEdit): ?>
            <a href="<?= htmlspecialchars(app_path('pages/daily-site-report-view.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $id ?>" class="btn btn-outline-secondary rounded-pill btn-sm"><i class="bi bi-eye me-1"></i>ดู / พิมพ์</a>
        <?php endif; ?>
    </div>

    <form method="post" action="<?= $saveUrl ?>" enctype="multipart/form-data" id="dsrForm">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= (int) $id ?>">
        <?php endif; ?>

        <div class="card sec-card mb-4">
            <div class="card-body p-4">
                <div class="sec-title mb-3"><i class="bi bi-info-circle me-1"></i>ข้อมูลพื้นฐาน (General Information)</div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">วันที่รายงาน <span class="text-danger">*</span></label>
                        <input type="date" name="report_date" class="form-control" required
                               value="<?= htmlspecialchars($report['report_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">สภาพอากาศ</label>
                        <input type="text" name="weather" class="form-control" placeholder=""
                               value="<?= htmlspecialchars($report['weather'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">บริษัท <span class="text-danger">*</span></label>
                        <select name="company_id" class="form-select" required>
                            <option value="" disabled <?= $selCompanyId <= 0 ? 'selected' : '' ?>>— เลือกบริษัท —</option>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= $selCompanyId === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($companies)): ?>
                            <div class="form-text text-danger">ยังไม่มีข้อมูลบริษัทในระบบ — โปรดเพิ่มที่เมนูจัดการบริษัทก่อน</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <label class="form-label fw-semibold">ชื่อไซต์งาน</label>
                        <input type="text" name="site_name" class="form-control"
                               value="<?= htmlspecialchars($report['site_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <label class="form-label fw-semibold">ชื่อโครงการ <span class="text-danger">*</span></label>
                        <select name="project_name" class="form-select" required>
                            <option value="" disabled <?= $selProject === '' || !in_array($selProject, $projectOptions, true) ? 'selected' : '' ?>>— เลือกโครงการ —</option>
                            <?php foreach ($projectOptions as $p): ?>
                                <option value="<?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') ?>" <?= $selProject === $p ? 'selected' : '' ?>><?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <label class="form-label fw-semibold">คนงาน</label>
                        <input type="text" name="worker_count" class="form-control"
                               inputmode="numeric" autocomplete="off" placeholder="จำนวนคน เช่น 12"
                               value="<?= htmlspecialchars($workerCountVal, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card sec-card mb-4">
            <div class="card-body p-4">
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="sec-title mb-3"><i class="bi bi-list-task me-1"></i>รายละเอียดงานที่ทำ (Work Progress)</div>
                        <textarea name="work_progress" class="form-control" rows="8" placeholder="งานที่ดำเนินการในวันนี้ เปอร์เซ็นต์ความคืบหน้า ฯลฯ"><?= htmlspecialchars($report['work_progress'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <div class="col-lg-6">
                        <div class="sec-title mb-3"><i class="bi bi-truck me-1"></i>วัสดุและเครื่องจักร (Materials & Equipment)</div>
                        <textarea name="materials_equipment" class="form-control" rows="8" placeholder="วัสดุเข้า-ออก เครื่องจักรที่ใช้ สภาพอุปกรณ์"><?= htmlspecialchars($report['materials_equipment'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <div class="col-12">
                        <div class="sec-title mb-3"><i class="bi bi-exclamation-triangle me-1"></i>ปัญหาและอุปสรรค (Issues & Remarks)</div>
                        <textarea name="issues_remarks" class="form-control" rows="5" placeholder="อุปสรรค ความปลอดภัย ประเด็นที่ต้องติดตาม"><?= htmlspecialchars($report['issues_remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card sec-card mb-4">
            <div class="card-body p-4">
                <div class="sec-title mb-3"><i class="bi bi-images me-1"></i>รูปภาพประกอบ (Photo Documentation)</div>
                <?php if ($isEdit && !empty($photos)): ?>
                    <div class="row g-3 mb-4">
                        <?php foreach ($photos as $ph): ?>
                            <div class="col-md-4">
                                <div class="border rounded-3 p-2 bg-light">
                                    <div class="ratio ratio-4x3 mb-2 bg-white rounded overflow-hidden">
                                        <?php $pu = htmlspecialchars(app_path($ph['file_path']), ENT_QUOTES, 'UTF-8'); ?>
                                        <img src="<?= $pu ?>" alt="" class="object-fit-contain w-100 h-100" style="object-fit:contain;">
                                    </div>
                                    <input type="text" class="form-control form-control-sm mb-1" name="photo_caption[<?= (int) $ph['id'] ?>]" placeholder="คำอธิบาย" value="<?= htmlspecialchars($ph['caption'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="form-check small">
                                        <input class="form-check-input" type="checkbox" name="delete_photo[]" value="<?= (int) $ph['id'] ?>" id="del<?= (int) $ph['id'] ?>">
                                        <label class="form-check-label text-danger" for="del<?= (int) $ph['id'] ?>">ลบรูปนี้</label>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <label class="form-label fw-semibold">อัปโหลดรูปเพิ่ม (หลายไฟล์ได้)</label>
                <input type="file" name="photos_new[]" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                <p class="small text-muted mt-2 mb-2">หลังอัปโหลดสามารถใส่คำอธิบายแต่ละรูปในหน้าแก้ไขครั้งถัดไปได้ (ช่อง caption จะจับคู่ตามลำดับไฟล์)</p>
                <div id="newCaptions"></div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-5">
            <a href="<?= $listUrl ?>" class="btn btn-outline-secondary rounded-pill">ยกเลิก</a>
            <button type="submit" class="btn btn-warning text-dark fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-check2-circle me-1"></i>บันทึกรายงาน
            </button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const fileInput = document.querySelector('input[name="photos_new[]"]');
    const capContainer = document.getElementById('newCaptions');
    if (fileInput && capContainer) {
        fileInput.addEventListener('change', function () {
            capContainer.innerHTML = '';
            const n = this.files ? this.files.length : 0;
            if (n <= 1) return;
            const lab = document.createElement('div');
            lab.className = 'small fw-semibold text-muted mb-2 mt-3';
            lab.textContent = 'คำอธิบายแต่ละรูป (ตามลำดับไฟล์)';
            capContainer.appendChild(lab);
            for (let i = 0; i < n; i++) {
                const wrap = document.createElement('div');
                wrap.className = 'input-group input-group-sm mb-1';
                wrap.innerHTML = '<span class="input-group-text">' + (i + 1) + '</span>' +
                    '<input type="text" class="form-control" name="photo_caption_new[]" placeholder="คำอธิบาย">';
                capContainer.appendChild(wrap);
            }
        });
    }
})();
</script>
</body>
</html>
