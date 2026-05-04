<?php

declare(strict_types=1);


session_start();

use Theelincon\Rtdb\Dsr;

require_once dirname(__DIR__, 2) . '/config/connect_database.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$rows = Dsr::listRowsForListPage();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมุดรายวันหน้างาน (DSR) | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }
        .card-main { border: none; border-radius: 16px; box-shadow: 0 6px 24px rgba(0,0,0,.06); }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 pb-5">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars(app_path('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none text-warning-emphasis">หน้าแรก</a></li>
            <li class="breadcrumb-item active" aria-current="page">สมุดรายวันหน้างาน</li>
        </ol>
    </nav>

    <?php if (!empty($_GET['saved'])): ?>
        <div class="modal fade" id="dsrSavedModal" tabindex="-1" aria-labelledby="dsrSavedModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-4 border-0 shadow">
                    <div class="modal-body text-center py-4 px-4">
                        <div class="text-success mb-3"><i class="bi bi-check-circle-fill d-block" style="font-size: 3rem;" aria-hidden="true"></i></div>
                        <h5 class="fw-bold mb-2" id="dsrSavedModalLabel">บันทึกสำเร็จ</h5>
                        <p class="text-muted small mb-0">ข้อมูลรายงานหน้างานถูกบันทึกแล้ว</p>
                    </div>
                    <div class="modal-footer border-0 justify-content-center pb-4 pt-0">
                        <button type="button" class="btn btn-warning text-dark fw-bold rounded-pill px-4" data-bs-dismiss="modal">ตกลง</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($_GET['deleted'])): ?>
        <div class="alert alert-success rounded-3">ลบรายการแล้ว</div>
    <?php endif; ?>
    <?php if (isset($_GET['err'])): ?>
        <div class="alert alert-danger rounded-3">ไม่สามารถดำเนินการได้ (<?= htmlspecialchars((string) $_GET['err'], ENT_QUOTES, 'UTF-8') ?>)</div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-journal-text text-warning me-2"></i>สมุดรายวันหน้างาน <span class="text-muted fs-6 fw-normal">Daily Site Report</span></h4>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars(app_path('pages/daily-site-reports/daily-site-report-calendar.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary fw-semibold rounded-pill shadow-sm">
                <i class="bi bi-calendar3 me-1"></i>มุมมองปฏิทิน
            </a>
            <a href="<?= htmlspecialchars(app_path('pages/daily-site-reports/daily-site-report-form.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-warning text-dark fw-bold rounded-pill shadow-sm">
                <i class="bi bi-plus-lg me-1"></i>สร้างรายงานใหม่
            </a>
        </div>
    </div>

    <div class="card card-main">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="dsrListTable" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">เลขที่</th>
                        <th>วันที่รายงาน</th>
                        <th>บริษัท</th>
                        <th>ไซต์ / โครงการ</th>
                        <th>ผู้บันทึก</th>
                        <th>บันทึกเมื่อ</th>
                        <th class="text-end pe-4">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-5">ยังไม่มีรายการ</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="ps-4 fw-semibold"><span class="badge bg-warning-subtle text-dark border border-warning-subtle"><?= htmlspecialchars($r['report_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= $r['report_date'] ? htmlspecialchars(date('d/m/Y', strtotime($r['report_date'])), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                <td class="small"><?= htmlspecialchars(trim($r['company_name'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="small text-muted"><?= htmlspecialchars($r['site_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="fw-medium"><?= htmlspecialchars($r['project_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td><?= htmlspecialchars(trim($r['recorder_name'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="small text-secondary"><?= !empty($r['created_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime($r['created_at'])), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                <td class="text-end pe-4">
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= htmlspecialchars(app_path('pages/daily-site-reports/daily-site-report-view.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $r['id'] ?>" class="btn btn-outline-secondary rounded-start-pill" title="ดู / พิมพ์"><i class="bi bi-eye"></i></a>
                                        <a href="<?= htmlspecialchars(app_path('pages/daily-site-reports/daily-site-report-form.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $r['id'] ?>" class="btn btn-outline-warning rounded-end-pill" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function ($) {
    if ($('#dsrListTable tbody tr td[colspan]').length === 0 && $('#dsrListTable tbody tr').length) {
        $('#dsrListTable').DataTable({
            order: [[5, 'desc']],
            pageLength: 25,
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
            columnDefs: [{ targets: [6], orderable: false, searchable: false }]
        });
    }
})(jQuery);
</script>
<?php if (!empty($_GET['saved'])): ?>
<script>
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var u = new URL(window.location.href);
        u.searchParams.delete('saved');
        var clean = u.pathname + (u.search || '') + u.hash;
        history.replaceState(null, '', clean);
        var el = document.getElementById('dsrSavedModal');
        if (el && window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(el).show();
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
