<?php

declare(strict_types=1);


require_once __DIR__ . '/_page_root.php';
session_start();

use Theelincon\Rtdb\Dsr;

require_once THEELINCON_ROOT . '/config/connect_database.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$rows = Dsr::listRowsForCalendarPage();
$normalized = [];
$companies = [];
$sites = [];
$projects = [];

foreach ($rows as $r) {
    $companyName = trim((string) ($r['company_name'] ?? ''));
    $siteName = trim((string) ($r['site_name'] ?? ''));
    $projectName = trim((string) ($r['project_name'] ?? ''));

    if ($companyName !== '') {
        $companies[$companyName] = true;
    }
    if ($siteName !== '') {
        $sites[$siteName] = true;
    }
    if ($projectName !== '') {
        $projects[$projectName] = true;
    }

    $photos = [];
    foreach (($r['photos'] ?? []) as $ph) {
        $filePath = trim((string) ($ph['file_path'] ?? ''));
        if ($filePath === '') {
            continue;
        }
        $photos[] = [
            'url' => app_path($filePath),
            'caption' => (string) ($ph['caption'] ?? ''),
        ];
    }

    $normalized[] = [
        'id' => (int) ($r['id'] ?? 0),
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
        'view_url' => app_path('pages/daily-site-report-view.php') . '?id=' . (int) ($r['id'] ?? 0),
        'edit_url' => app_path('pages/daily-site-report-form.php') . '?id=' . (int) ($r['id'] ?? 0),
    ];
}

$companyOptions = array_keys($companies);
$siteOptions = array_keys($sites);
$projectOptions = array_keys($projects);
sort($companyOptions, SORT_NATURAL | SORT_FLAG_CASE);
sort($siteOptions, SORT_NATURAL | SORT_FLAG_CASE);
sort($projectOptions, SORT_NATURAL | SORT_FLAG_CASE);
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
<body>

<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>

<div class="container py-4 pb-5">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars(app_path('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none text-warning-emphasis">หน้าแรก</a></li>
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars(app_path('pages/daily-site-report-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none text-warning-emphasis">สมุดรายวันหน้างาน</a></li>
            <li class="breadcrumb-item active" aria-current="page">ปฏิทิน DSR</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-calendar3 text-warning me-2"></i>ปฏิทินสมุดรายวันหน้างาน <span class="text-muted fs-6 fw-normal">Daily Site Report Calendar</span></h4>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars(app_path('pages/daily-site-report-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill"><i class="bi bi-list-ul me-1"></i>มุมมองรายการ</a>
            <a href="<?= htmlspecialchars(app_path('pages/daily-site-report-form.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-warning text-dark fw-bold rounded-pill"><i class="bi bi-plus-lg me-1"></i>สร้าง DSR</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body p-3 p-lg-4">
            <div class="row g-2 g-lg-3 align-items-end">
                <div class="col-sm-6 col-lg-3">
                    <label for="filterCompany" class="form-label fw-semibold small">บริษัท</label>
                    <select id="filterCompany" class="form-select form-select-sm">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($companyOptions as $name): ?>
                            <option value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label for="filterSite" class="form-label fw-semibold small">ไซต์งาน</label>
                    <select id="filterSite" class="form-select form-select-sm">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($siteOptions as $name): ?>
                            <option value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label for="filterProject" class="form-label fw-semibold small">โครงการ</label>
                    <select id="filterProject" class="form-select form-select-sm">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($projectOptions as $name): ?>
                            <option value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <button id="btnResetFilter" type="button" class="btn btn-outline-secondary btn-sm rounded-pill w-100">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>ล้างตัวกรอง
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div class="btn-group" role="group" aria-label="เปลี่ยนเดือน">
            <button type="button" id="btnPrevMonth" class="btn btn-outline-warning"><i class="bi bi-chevron-left"></i></button>
            <button type="button" id="btnToday" class="btn btn-warning text-dark fw-semibold">เดือนปัจจุบัน</button>
            <button type="button" id="btnNextMonth" class="btn btn-outline-warning"><i class="bi bi-chevron-right"></i></button>
        </div>
        <h5 id="calendarTitle" class="fw-bold mb-0"></h5>
        <div class="small text-muted" id="calendarCountLabel"></div>
    </div>
    <div class="calendar-wrap">
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
                    <p class="print-month-desc">พิมพ์เฉพาะเดือนที่กำลังแสดงในปฏิทิน และยึดตามตัวกรองที่เลือกอยู่</p>
                </div>
            </div>
            <button type="button" id="btnPrintMonth" class="btn btn-warning text-dark btn-print-month">
                <i class="bi bi-printer-fill me-1"></i>พิมพ์รายเดือน
            </button>
        </div>
    </div>
</div>

<div class="modal fade" id="dsrDetailModal" tabindex="-1" aria-labelledby="dsrDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="dsrDetailModalLabel">รายละเอียด DSR</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-lg-4"><div class="small text-muted">เลขที่รายงาน</div><div id="mReportNo" class="fw-semibold"></div></div>
                    <div class="col-lg-4"><div class="small text-muted">วันที่รายงาน</div><div id="mReportDate" class="fw-semibold"></div></div>
                    <div class="col-lg-4"><div class="small text-muted">ผู้บันทึก</div><div id="mRecorder" class="fw-semibold"></div></div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-lg-4"><div class="small text-muted">บริษัท</div><div id="mCompany"></div></div>
                    <div class="col-lg-4"><div class="small text-muted">ไซต์งาน</div><div id="mSite"></div></div>
                    <div class="col-lg-4"><div class="small text-muted">โครงการ</div><div id="mProject"></div></div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-lg-6"><div class="small text-muted">สภาพอากาศ</div><div id="mWeather"></div></div>
                    <div class="col-lg-6"><div class="small text-muted">จำนวนคนงาน</div><div id="mWorkerCount"></div></div>
                </div>

                <div class="mb-2 fw-semibold text-warning-emphasis">รายละเอียดงานที่ทำ</div>
                <pre class="detail-pre" id="mWorkProgress"></pre>
                <div class="mb-2 mt-3 fw-semibold text-warning-emphasis">วัสดุและเครื่องจักร</div>
                <pre class="detail-pre" id="mMaterials"></pre>
                <div class="mb-2 mt-3 fw-semibold text-warning-emphasis">ปัญหาและอุปสรรค</div>
                <pre class="detail-pre" id="mIssues"></pre>
                <div class="mb-2 mt-3 fw-semibold text-warning-emphasis">รูปภาพประกอบ</div>
                <div id="mPhotos"></div>
            </div>
            <div class="modal-footer">
                <a href="#" id="mViewUrl" target="_blank" rel="noopener" class="btn btn-outline-secondary rounded-pill"><i class="bi bi-eye me-1"></i>เปิดหน้ารายละเอียด</a>
                <a href="#" id="mEditUrl" target="_blank" rel="noopener" class="btn btn-outline-warning rounded-pill"><i class="bi bi-pencil me-1"></i>แก้ไข</a>
                <button type="button" class="btn btn-warning text-dark fw-bold rounded-pill px-4" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const reports = <?= json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const weekdays = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
    const monthNames = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    const today = new Date();
    let currentYear = today.getFullYear();
    let currentMonth = today.getMonth();

    const elWeekdayRow = document.getElementById('weekdayRow');
    const elCalendarGrid = document.getElementById('calendarGrid');
    const elCalendarTitle = document.getElementById('calendarTitle');
    const elCalendarCountLabel = document.getElementById('calendarCountLabel');
    const filterCompany = document.getElementById('filterCompany');
    const filterSite = document.getElementById('filterSite');
    const filterProject = document.getElementById('filterProject');
    const btnResetFilter = document.getElementById('btnResetFilter');
    const modalEl = document.getElementById('dsrDetailModal');
    const detailModal = modalEl ? new bootstrap.Modal(modalEl) : null;
    const btnPrintMonth = document.getElementById('btnPrintMonth');
    const monthlyReportBaseUrl = <?= json_encode(app_path('pages/daily-site-report-monthly-report.php'), JSON_UNESCAPED_SLASHES) ?>;

    function pad2(v) {
        return String(v).padStart(2, '0');
    }

    function formatDateThai(dateText) {
        if (!dateText) return '—';
        const dt = new Date(dateText + 'T00:00:00');
        if (Number.isNaN(dt.getTime())) return dateText;
        const d = pad2(dt.getDate());
        const m = pad2(dt.getMonth() + 1);
        const y = dt.getFullYear() + 543;
        return d + '/' + m + '/' + y;
    }

    function formatDatePrint(dateText) {
        if (!dateText) return '—';
        const dt = new Date(dateText + 'T00:00:00');
        if (Number.isNaN(dt.getTime())) return dateText;
        const d = pad2(dt.getDate());
        const m = pad2(dt.getMonth() + 1);
        const y = dt.getFullYear();
        return d + ' - ' + m + ' - ' + y;
    }

    function formatDateTimeThai(dateText) {
        if (!dateText) return '—';
        const dt = new Date(String(dateText).replace(' ', 'T'));
        if (Number.isNaN(dt.getTime())) return dateText;
        const d = pad2(dt.getDate());
        const m = pad2(dt.getMonth() + 1);
        const y = dt.getFullYear() + 543;
        const hh = pad2(dt.getHours());
        const mm = pad2(dt.getMinutes());
        return d + '/' + m + '/' + y + ' ' + hh + ':' + mm;
    }

    function toDateKey(year, month, day) {
        return year + '-' + pad2(month + 1) + '-' + pad2(day);
    }

    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function (m) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m];
        });
    }

    function textOrDash(v) {
        const txt = String(v || '').trim();
        return txt === '' ? '—' : txt;
    }

    function filteredReports() {
        const company = String(filterCompany.value || '').trim();
        const site = String(filterSite.value || '').trim();
        const project = String(filterProject.value || '').trim();
        return reports.filter(function (r) {
            if (company !== '' && String(r.company_name || '').trim() !== company) return false;
            if (site !== '' && String(r.site_name || '').trim() !== site) return false;
            if (project !== '' && String(r.project_name || '').trim() !== project) return false;
            return true;
        });
    }

    function groupByDate(items) {
        const map = {};
        items.forEach(function (r) {
            const key = String(r.report_date || '').trim();
            if (key === '') return;
            if (!map[key]) map[key] = [];
            map[key].push(r);
        });
        Object.keys(map).forEach(function (k) {
            map[k].sort(function (a, b) {
                return Number(b.id || 0) - Number(a.id || 0);
            });
        });
        return map;
    }

    function renderWeekdayRow() {
        if (!elWeekdayRow) return;
        elWeekdayRow.innerHTML = weekdays.map(function (d) {
            return '<div class="calendar-weekday">' + d + '</div>';
        }).join('');
    }

    function showDetailModal(item) {
        if (!detailModal) return;
        const photos = Array.isArray(item.photos) ? item.photos : [];
        document.getElementById('mReportNo').textContent = textOrDash(item.report_no);
        document.getElementById('mReportDate').textContent = formatDateThai(item.report_date);
        document.getElementById('mRecorder').textContent = textOrDash(item.recorder_name);
        document.getElementById('mCompany').textContent = textOrDash(item.company_name);
        document.getElementById('mSite').textContent = textOrDash(item.site_name);
        document.getElementById('mProject').textContent = textOrDash(item.project_name);
        document.getElementById('mWeather').textContent = textOrDash(item.weather);
        document.getElementById('mWorkerCount').textContent = textOrDash(item.worker_count);
        document.getElementById('mWorkProgress').textContent = textOrDash(item.work_progress);
        document.getElementById('mMaterials').textContent = textOrDash(item.materials_equipment);
        document.getElementById('mIssues').textContent = textOrDash(item.issues_remarks);

        const photoWrap = document.getElementById('mPhotos');
        if (photoWrap) {
            if (photos.length === 0) {
                photoWrap.innerHTML = '<div class="text-muted small">ไม่มีรูปภาพประกอบ</div>';
            } else {
                photoWrap.innerHTML = '<div class="photo-grid">' + photos.map(function (p) {
                    const cap = String(p.caption || '').trim();
                    return '<div class="photo-item">' +
                        '<img src="' + esc(p.url) + '" alt="">' +
                        (cap !== '' ? '<div class="small mt-1 text-muted">' + esc(cap) + '</div>' : '') +
                        '</div>';
                }).join('') + '</div>';
            }
        }

        const viewLink = document.getElementById('mViewUrl');
        const editLink = document.getElementById('mEditUrl');
        if (viewLink) viewLink.setAttribute('href', String(item.view_url || '#'));
        if (editLink) editLink.setAttribute('href', String(item.edit_url || '#'));
        detailModal.show();
    }

    function renderCalendar() {
        if (!elCalendarGrid) return;
        const items = filteredReports();
        const mapByDate = groupByDate(items);
        const firstDay = new Date(currentYear, currentMonth, 1);
        const startWeekDay = firstDay.getDay();
        const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
        const prevMonthDays = new Date(currentYear, currentMonth, 0).getDate();
        const totalCells = 42;
        const html = [];

        for (let i = 0; i < totalCells; i++) {
            let dayNum = 0;
            let cellMonth = currentMonth;
            let cellYear = currentYear;
            let otherMonth = false;

            if (i < startWeekDay) {
                dayNum = prevMonthDays - startWeekDay + i + 1;
                cellMonth = currentMonth - 1;
                if (cellMonth < 0) {
                    cellMonth = 11;
                    cellYear -= 1;
                }
                otherMonth = true;
            } else if (i >= startWeekDay + daysInMonth) {
                dayNum = i - (startWeekDay + daysInMonth) + 1;
                cellMonth = currentMonth + 1;
                if (cellMonth > 11) {
                    cellMonth = 0;
                    cellYear += 1;
                }
                otherMonth = true;
            } else {
                dayNum = i - startWeekDay + 1;
            }

            const dateKey = toDateKey(cellYear, cellMonth, dayNum);
            const dayItems = mapByDate[dateKey] || [];
            const isToday = (cellYear === today.getFullYear() && cellMonth === today.getMonth() && dayNum === today.getDate());

            html.push('<div class="calendar-day' +
                (otherMonth ? ' other-month' : '') +
                (isToday ? ' today' : '') + '">');
            html.push('<div class="calendar-day-head">');
            html.push('<span class="calendar-day-num">' + dayNum + '</span>');
            html.push(dayItems.length > 0 ? '<span class="badge bg-warning text-dark rounded-pill">' + dayItems.length + '</span>' : '');
            html.push('</div>');
            html.push('<div class="event-list">');

            if (dayItems.length === 0) {
                html.push('<div class="event-empty">—</div>');
            } else {
                dayItems.forEach(function (item) {
                    const titleParts = [];
                    if (String(item.report_no || '').trim() !== '') titleParts.push(item.report_no);
                    if (String(item.project_name || '').trim() !== '') titleParts.push(item.project_name);
                    if (String(item.site_name || '').trim() !== '') titleParts.push(item.site_name);
                    const title = titleParts.join(' | ');
                    const hasIssues = String(item.issues_remarks || '').trim() !== '';
                    html.push('<button type="button" class="event-chip' + (hasIssues ? ' event-chip-danger' : '') + '" data-report-id="' + Number(item.id || 0) + '" title="' + esc(title) + '">' + esc(title || 'DSR') + '</button>');
                });
            }
            html.push('</div></div>');
        }

        elCalendarGrid.innerHTML = html.join('');
        elCalendarTitle.textContent = monthNames[currentMonth] + ' ' + (currentYear + 543);
        elCalendarCountLabel.textContent = 'จำนวนรายงานที่แสดง: ' + items.length + ' รายการ';

        elCalendarGrid.querySelectorAll('.event-chip').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const id = Number(btn.getAttribute('data-report-id') || '0');
                const found = reports.find(function (r) { return Number(r.id || 0) === id; });
                if (found) showDetailModal(found);
            });
        });
    }

    function reportsForCurrentMonth() {
        return filteredReports().filter(function (r) {
            const key = String(r.report_date || '').trim();
            if (key === '') return false;
            const dt = new Date(key + 'T00:00:00');
            if (Number.isNaN(dt.getTime())) return false;
            return dt.getFullYear() === currentYear && dt.getMonth() === currentMonth;
        });
    }

    function buildPrintBlock(item, idx) {
        const photos = Array.isArray(item.photos) ? item.photos : [];
        return '<div class="rep-item">' +
            '<h4>รายการที่ ' + idx + ' [' + esc(item.report_no || 'DSR') + ']</h4>' +
            '<ul class="rep-list">' +
            '<li><span class="li-title">รายละเอียดที่ 1</span> ' + esc(textOrDash(item.work_progress)) + '</li>' +
            '<li><span class="li-title">รายละเอียดที่ 2</span> ' + esc(textOrDash(item.materials_equipment)) + '</li>' +
            '<li><span class="li-title">รายละเอียดที่ 3</span> ' + esc(textOrDash(item.issues_remarks)) + '</li>' +
            '<li><span class="li-title">[รูป]</span></li>' +
            '</ul>' +
            (photos.length > 0
                ? '<div class="photos">' + photos.map(function (p) {
                    return '<div class="photo-item"><img src="' + esc(p.url) + '" alt=""></div>';
                }).join('') + '</div>'
                : '<div class="no-photo">- ไม่มีรูปภาพ</div>') +
            '</div>';
    }

    function openPrintWindow(title, groupedRows) {
        const w = window.open('', '_blank');
        if (!w) {
            alert('ไม่สามารถเปิดหน้าพิมพ์ได้ กรุณาอนุญาต pop-up');
            return;
        }
        const body = groupedRows.map(function (group) {
            const sections = group.items.map(function (item, idx) {
                return buildPrintBlock(item, idx + 1);
            }).join('');
            return '<section class="day-group">' +
                '<h3>*** วันที่ ' + esc(formatDatePrint(group.date)) + '</h3>' +
                sections +
                '</section>';
        }).join('');

        w.document.write('<!doctype html><html lang="th"><head><meta charset="utf-8"><title>' + esc(title) + '</title>' +
            '<style>' +
            'body{font-family:Sarabun,Arial,sans-serif;color:#111;margin:22px 28px;line-height:1.55;font-size:18px;}' +
            'h1{font-size:46px;margin:0 0 18px;text-align:center;font-weight:700;}' +
            '.sub{font-size:14px;color:#555;margin-bottom:16px;text-align:center;}' +
            '.day-group{margin-bottom:22px;page-break-inside:avoid;}' +
            '.day-group>h3{font-size:40px;margin:0 0 10px;font-weight:700;}' +
            '.rep-item{margin:0 0 14px 0;}' +
            '.rep-item h4{font-size:34px;margin:0 0 6px;color:#9a3412;font-weight:700;}' +
            '.rep-list{margin:0 0 0 20px;padding-left:18px;} .rep-list li{margin:4px 0;font-size:31px;}' +
            '.li-title{display:inline-block;min-width:180px;}' +
            '.photos{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:8px 0 0 24px;max-width:980px;}' +
            '.photo-item img{width:100%;height:220px;object-fit:contain;border:1px solid #bbb;background:#fff;}' +
            '.no-photo{margin-left:24px;font-size:26px;}' +
            '@media print{@page{size:A4;margin:10mm;} body{margin:0;font-size:13px;} h1{font-size:22px;} .sub{font-size:10px;} .day-group>h3{font-size:18px;} .rep-item h4{font-size:16px;} .rep-list li{font-size:14px;} .li-title{min-width:110px;} .photo-item img{height:160px;} .no-print{display:none;}}' +
            '</style></head><body>' +
            '<div class="no-print" style="margin-bottom:10px;"><button onclick="window.print()">พิมพ์</button> <button onclick="window.close()">ปิด</button></div>' +
            '<h1>สรุปรายงานประจำเดือน</h1>' +
            '<div class="sub">' + esc(title) + ' | ออกเอกสารเมื่อ: ' + esc(formatDateTimeThai(new Date().toISOString())) + '</div>' +
            body +
            '</body></html>');
        w.document.close();
    }

    function groupRowsByDate(rows) {
        const map = {};
        rows.forEach(function (r) {
            const k = String(r.report_date || '').trim();
            if (k === '') return;
            if (!map[k]) map[k] = [];
            map[k].push(r);
        });
        return Object.keys(map).sort().map(function (dateKey) {
            return { date: dateKey, items: map[dateKey].sort(function (a, b) { return Number(a.id || 0) - Number(b.id || 0); }) };
        });
    }

    const btnPrevMonth = document.getElementById('btnPrevMonth');
    const btnNextMonth = document.getElementById('btnNextMonth');
    const btnToday = document.getElementById('btnToday');
    if (btnPrevMonth) {
        btnPrevMonth.addEventListener('click', function () {
            currentMonth -= 1;
            if (currentMonth < 0) {
                currentMonth = 11;
                currentYear -= 1;
            }
            renderCalendar();
        });
    }
    if (btnNextMonth) {
        btnNextMonth.addEventListener('click', function () {
            currentMonth += 1;
            if (currentMonth > 11) {
                currentMonth = 0;
                currentYear += 1;
            }
            renderCalendar();
        });
    }
    if (btnToday) {
        btnToday.addEventListener('click', function () {
            currentYear = today.getFullYear();
            currentMonth = today.getMonth();
            renderCalendar();
        });
    }
    if (btnPrintMonth) {
        btnPrintMonth.addEventListener('click', function () {
            const rows = reportsForCurrentMonth();
            if (rows.length === 0) {
                alert('ไม่พบข้อมูล DSR สำหรับการพิมพ์ในเดือนนี้');
                return;
            }
            const params = new URLSearchParams();
            params.set('year', String(currentYear));
            params.set('month', String(currentMonth + 1));
            const company = String(filterCompany ? (filterCompany.value || '') : '').trim();
            const site = String(filterSite ? (filterSite.value || '') : '').trim();
            const project = String(filterProject ? (filterProject.value || '') : '').trim();
            if (company !== '') params.set('company', company);
            if (site !== '') params.set('site', site);
            if (project !== '') params.set('project', project);
            window.open(monthlyReportBaseUrl + '?' + params.toString(), '_blank', 'noopener');
        });
    }
    [filterCompany, filterSite, filterProject].forEach(function (el) {
        if (!el) return;
        el.addEventListener('change', renderCalendar);
    });
    if (btnResetFilter) {
        btnResetFilter.addEventListener('click', function () {
            if (filterCompany) filterCompany.value = '';
            if (filterSite) filterSite.value = '';
            if (filterProject) filterProject.value = '';
            renderCalendar();
        });
    }

    renderWeekdayRow();
    renderCalendar();
})();
</script>
</body>
</html>
