<?php

declare(strict_types=1);


session_start();

use Theelincon\Rtdb\Dsr;

require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/daily_site_report_projects.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$userId = (int) $_SESSION['user_id'];
$isAdmin = user_is_admin_role();
$dsrFormProjectOptions = daily_site_report_project_options();
$dsrFormSiteOptions = ['Building H'];
$dsrFormCompanies = \Theelincon\Rtdb\Db::tableRows('company');
\Theelincon\Rtdb\Db::sortRows($dsrFormCompanies, 'name', false);
$dsrFormSaveUrl = app_path('actions/daily-site-report-save.php');
$dsrFormMaxPhotos = 2;
$dsrCalendarJsPath = dirname(__DIR__, 2) . '/assets/js/dsr-calendar-interaction.js';
$dsrCalendarJsVersion = is_file($dsrCalendarJsPath) ? (string) filemtime($dsrCalendarJsPath) : (string) time();

$rows = Dsr::listRowsForCalendarPage();
$normalized = [];

foreach ($rows as $r) {
    $companyName = trim((string) ($r['company_name'] ?? ''));
    $siteName = trim((string) ($r['site_name'] ?? ''));
    $projectName = trim((string) ($r['project_name'] ?? ''));

    $photos = [];
    foreach (($r['photos'] ?? []) as $ph) {
        $filePath = trim((string) ($ph['file_path'] ?? ''));
        if ($filePath === '') {
            continue;
        }
        $photos[] = [
            'id' => (int) ($ph['id'] ?? 0),
            'url' => app_path($filePath),
            'file_path' => $filePath,
            'caption' => (string) ($ph['caption'] ?? ''),
        ];
    }

    $normalized[] = [
        'id' => (int) ($r['id'] ?? 0),
        'created_by' => (int) ($r['created_by'] ?? 0),
        'company_id' => (int) ($r['company_id'] ?? 0),
        'report_no' => (string) ($r['report_no'] ?? ''),
        'report_date' => (string) ($r['report_date'] ?? ''),
        'company_name' => $companyName,
        'site_name' => $siteName,
        'project_name' => $projectName,
        'weather' => (string) ($r['weather'] ?? ''),
        'worker_count' => (string) ($r['worker_count'] ?? ''),
        'work_progress' => (string) ($r['work_progress'] ?? ''),
        'materials_equipment' => (string) ($r['materials_equipment'] ?? ''),
        'issues_remarks' => (string) ($r['issues_remarks'] ?? ''),
        'recorder_name' => (string) ($r['recorder_name'] ?? ''),
        'created_at' => (string) ($r['created_at'] ?? ''),
        'updated_at' => (string) ($r['updated_at'] ?? ''),
        'photos' => $photos,
        'view_url' => app_path('pages/daily-site-reports/daily-site-report-view.php') . '?id=' . (int) ($r['id'] ?? 0),
        'edit_url' => daily_site_report_hub_url() . '?open_id=' . (int) ($r['id'] ?? 0),
    ];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ปฏิทินสมุดรายวันหน้างาน (DSR) | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/dsr-calendar.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }
        .calendar-wrap { border: 1px solid rgba(253, 126, 20, .2); border-radius: 16px; overflow: hidden; background: #fff; box-shadow: 0 8px 24px rgba(0,0,0,.05); }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
        }
        .calendar-weekday {
            background: #fff4e8;
            color: #9a3412;
            font-weight: 700;
            text-align: center;
            padding: .65rem .25rem;
            border-bottom: 1px solid #ffe3cc;
            border-right: 1px solid #ffe3cc;
            font-size: .9rem;
        }
        .calendar-weekday:nth-child(7) { border-right: 0; }
        .calendar-day {
            min-height: 150px;
            border-right: 1px solid #f1f3f5;
            border-bottom: 1px solid #f1f3f5;
            padding: .45rem;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: .35rem;
        }
        .calendar-day:nth-child(7n) { border-right: 0; }
        .calendar-day.other-month { background: #fafafa; color: #adb5bd; }
        .calendar-day.today { background: #fff8ee; }
        .calendar-day-head { display: flex; justify-content: space-between; align-items: center; gap: .3rem; }
        .calendar-day-num { font-size: .95rem; font-weight: 700; }
        .event-list { display: grid; gap: .25rem; }
        .event-chip {
            text-align: start;
            border: 1px solid rgba(253, 126, 20, .18);
            background: rgba(255, 193, 7, .15);
            color: #7c2d12;
            border-radius: .5rem;
            font-size: .78rem;
            padding: .25rem .45rem;
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }
        .event-chip:hover { background: rgba(255, 193, 7, .28); }
        .event-chip.event-chip-danger {
            border-color: rgba(220, 53, 69, .45);
            background: rgba(220, 53, 69, .16);
            color: #842029;
        }
        .event-chip.event-chip-danger:hover { background: rgba(220, 53, 69, .25); }
        .event-empty { font-size: .76rem; color: #adb5bd; }
        .detail-pre {
            white-space: pre-wrap;
            margin: 0;
            font-size: .9rem;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: .6rem;
            padding: .6rem .75rem;
        }
        .photo-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .6rem;
        }
        .photo-item img {
            width: 100%;
            height: 170px;
            object-fit: contain;
            border-radius: .55rem;
            border: 1px solid #e9ecef;
            background: #fff;
        }
        .print-month-card {
            border: 1px solid rgba(253, 126, 20, .22);
            border-radius: 1rem;
            background: linear-gradient(135deg, #fff7ed 0%, #fff 65%);
            box-shadow: 0 8px 20px rgba(0, 0, 0, .04);
        }
        .print-month-title {
            font-weight: 700;
            color: #9a3412;
            margin-bottom: .15rem;
        }
        .print-month-desc {
            color: #6b7280;
            font-size: .9rem;
            margin-bottom: 0;
        }
        .btn-print-month {
            border-radius: 999px;
            padding: .5rem 1.15rem;
            font-weight: 700;
            box-shadow: 0 .35rem .9rem rgba(253, 126, 20, .25);
        }
        @media (max-width: 991.98px) {
            .calendar-day { min-height: 130px; }
        }
        @media (max-width: 767.98px) {
            .calendar-day { min-height: 105px; padding: .35rem; }
            .event-chip { font-size: .72rem; padding: .2rem .35rem; }
        }
    </style>
</head>
<body class="dsr-calendar-page">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 pb-5">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars(app_path('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none text-warning-emphasis">หน้าแรก</a></li>
            <li class="breadcrumb-item active" aria-current="page">สมุดรายวันหน้างาน</li>
        </ol>
    </nav>

    <h4 class="fw-bold mb-3"><i class="bi bi-calendar3 text-warning me-2"></i>ปฏิทินสมุดรายวันหน้างาน</h4>

    <?php if (!empty($_GET['err'])): ?>
        <?php
        $errMsg = match ((string) ($_GET['err'] ?? '')) {
            'missing' => 'ไม่พบรายงานที่ต้องการ',
            'forbidden' => 'คุณไม่มีสิทธิ์เข้าถึงรายงานนี้',
            default => 'เกิดข้อผิดพลาด',
        };
        ?>
        <div class="alert alert-danger rounded-3 small py-2"><?= htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif (!empty($_GET['saved'])): ?>
        <div class="alert alert-success rounded-3 small py-2">บันทึกรายงานเรียบร้อยแล้ว</div>
    <?php elseif (!empty($_GET['deleted'])): ?>
        <div class="alert alert-warning rounded-3 small py-2">ลบรายงานเรียบร้อยแล้ว</div>
    <?php endif; ?>

    <div class="dsr-month-toolbar d-flex flex-nowrap align-items-center gap-2 gap-md-3 mb-3">
        <div class="btn-group flex-shrink-0" role="group" aria-label="เปลี่ยนเดือน">
            <button type="button" id="btnPrevMonth" class="btn btn-outline-warning btn-sm" data-dsr-month="prev" aria-label="เดือนก่อนหน้า"><i class="bi bi-chevron-left"></i></button>
            <button type="button" id="btnToday" class="btn btn-warning text-dark fw-semibold btn-sm" data-dsr-month="today">เดือนนี้</button>
            <button type="button" id="btnNextMonth" class="btn btn-outline-warning btn-sm" data-dsr-month="next" aria-label="เดือนถัดไป"><i class="bi bi-chevron-right"></i></button>
        </div>
        <h5 id="calendarTitle" class="fw-bold mb-0 flex-grow-1 text-center text-truncate">—</h5>
        <div class="small text-muted flex-shrink-0 text-nowrap mb-0" id="calendarCountLabel"></div>
    </div>
    <div class="calendar-shell">
        <div class="calendar-grid" id="weekdayRow"></div>
        <div class="calendar-grid" id="calendarGrid"></div>
    </div>
    <div class="print-month-card mt-3">
        <div class="p-3 p-lg-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-start gap-3">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle text-warning-emphasis" style="width: 2.4rem; height: 2.4rem;">
                    <i class="bi bi-printer fs-5"></i>
                </span>
                <div>
                    <h6 class="print-month-title">พิมพ์รายงานรายเดือน</h6>
                    <p class="print-month-desc">พิมพ์เฉพาะเดือนที่กำลังแสดงในปฏิทิน</p>
                </div>
            </div>
            <button type="button" id="btnPrintMonth" class="btn btn-warning text-dark btn-print-month">
                <i class="bi bi-printer-fill me-1"></i>พิมพ์รายเดือน
            </button>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/daily_site_report_calendar_modals.php'; ?>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.DSR_CALENDAR_CONFIG = {
    reports: <?= json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    userId: <?= (int) $userId ?>,
    isAdmin: <?= $isAdmin ? 'true' : 'false' ?>,
    maxPhotos: <?= (int) $dsrFormMaxPhotos ?>,
    csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= htmlspecialchars(app_path('assets/js/dsr-calendar-interaction.js') . '?v=' . $dsrCalendarJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function () {
    const btnPrintMonth = document.getElementById('btnPrintMonth');
    const monthlyReportBaseUrl = <?= json_encode(app_path('pages/daily-site-reports/daily-site-report-monthly-report.php'), JSON_UNESCAPED_SLASHES) ?>;
    if (!btnPrintMonth) return;
    btnPrintMonth.addEventListener('click', function () {
        const today = new Date();
        const cm = window.dsrCalendarGetMonth ? window.dsrCalendarGetMonth() : { year: today.getFullYear(), month: today.getMonth() };
        const params = new URLSearchParams();
        params.set('year', String(cm.year));
        params.set('month', String(cm.month + 1));
        window.location.href = monthlyReportBaseUrl + '?' + params.toString();
    });
})();
</script>
</body>
</html>
