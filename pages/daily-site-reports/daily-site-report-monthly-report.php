<?php

declare(strict_types=1);

session_start();

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Dsr;

require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/daily_site_report_projects.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}
if ($month < 1 || $month > 12) {
    $month = (int) date('n');
}

$filterCompany = trim((string) ($_GET['company'] ?? ''));
$filterSite = trim((string) ($_GET['site'] ?? ''));
$filterProject = trim((string) ($_GET['project'] ?? ''));
$selectedCompanyName = trim((string) ($_GET['header_company'] ?? $filterCompany));
$selectedCustomerName = trim((string) ($_GET['header_customer'] ?? ''));

$rows = Dsr::listRowsForCalendarPage();
$filtered = [];

foreach ($rows as $r) {
    $reportDate = trim((string) ($r['report_date'] ?? ''));
    if ($reportDate === '') {
        continue;
    }
    $ts = strtotime($reportDate);
    if ($ts === false) {
        continue;
    }
    if ((int) date('Y', $ts) !== $year || (int) date('n', $ts) !== $month) {
        continue;
    }

    $company = trim((string) ($r['company_name'] ?? ''));
    $site = trim((string) ($r['site_name'] ?? ''));
    $project = trim((string) ($r['project_name'] ?? ''));

    if ($filterCompany !== '' && $company !== $filterCompany) {
        continue;
    }
    if ($filterSite !== '' && $site !== $filterSite) {
        continue;
    }
    if ($filterProject !== '' && $project !== $filterProject) {
        continue;
    }

    $filtered[] = [
        'id' => (int) ($r['id'] ?? 0),
        'report_no' => (string) ($r['report_no'] ?? ''),
        'report_date' => $reportDate,
        'work_progress' => trim((string) ($r['work_progress'] ?? '')),
        'materials_equipment' => trim((string) ($r['materials_equipment'] ?? '')),
        'issues_remarks' => trim((string) ($r['issues_remarks'] ?? '')),
        'company_name' => $company,
        'site_name' => $site,
        'project_name' => $project,
    ];
}

usort($filtered, static function (array $a, array $b): int {
    if ($a['report_date'] !== $b['report_date']) {
        return strcmp($a['report_date'], $b['report_date']);
    }

    return $a['id'] <=> $b['id'];
});

$grouped = [];
$blockedDays = [];
foreach ($filtered as $item) {
    if (trim((string) $item['issues_remarks']) === '') {
        continue;
    }
    $k = $item['report_date'];
    if (!isset($grouped[$k])) {
        $grouped[$k] = [];
    }
    $grouped[$k][] = $item;
    $blockedDays[$k] = true;
}
$blockedDaysCount = count($blockedDays);

$companyRows = Db::tableRows('company');
Db::sortRows($companyRows, 'name', false);
$companyOptions = [];
foreach ($companyRows as $cr) {
    $name = trim((string) ($cr['name'] ?? ''));
    if ($name !== '') {
        $companyOptions[$name] = true;
    }
}
$companyOptions = array_keys($companyOptions);
sort($companyOptions, SORT_NATURAL | SORT_FLAG_CASE);

function rep_esc(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function rep_date_print(string $ymd): string
{
    $ts = strtotime($ymd);
    if ($ts === false) {
        return $ymd;
    }

    return date('d - m - Y', $ts);
}

$monthNamesTh = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
$monthLabelTh = ($monthNamesTh[$month] ?? sprintf('%02d', $month)) . ' ' . ($year + 543);
$hubUrl = daily_site_report_hub_url();
$calendarBackUrl = $hubUrl . '?year=' . $year . '&month=' . $month;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สรุปรายงานประจำเดือน <?= rep_esc($monthLabelTh) ?> | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --print-heading-size: 14pt;
            --print-detail-size: 12pt;
        }
        body.dsr-monthly-page {
            font-family: 'Sarabun', sans-serif;
            background: #fffaf5;
        }
        .dsr-monthly-actions {
            position: sticky;
            top: 0;
            z-index: 1020;
            background: rgba(255, 250, 245, .95);
            backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(253, 126, 20, .15);
            padding: .75rem 0;
            margin-bottom: 1rem;
        }
        .editor-card {
            border: 1px solid rgba(253, 126, 20, .2);
            border-radius: 1rem;
            background: #fff;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .04);
        }
        #dsrPreviewModal .modal-content {
            border: 0;
            border-radius: 1rem;
            overflow: hidden;
        }
        #dsrPreviewModal .modal-header {
            background: linear-gradient(135deg, #fff7ed 0%, #fff 70%);
            border-bottom: 1px solid #fde68a;
        }
        #dsrPreviewModal .modal-body {
            max-height: min(70vh, 720px);
            overflow-y: auto;
            background: #f8fafc;
        }
        .paper {
            max-width: 190mm;
            margin: 0 auto;
            padding: 1.25rem 1rem 2rem;
            color: #111;
            font-family: 'TH Sarabun New', 'THSarabunNew', 'Sarabun', sans-serif;
        }
        .paper-title { font-size: 16pt; text-align: center; margin: 0 0 .2rem; font-weight: 700; line-height: 1.2; }
        .paper-sub { text-align: center; color: #111; margin-bottom: .75rem; font-size: 11pt; line-height: 1.25; }
        .alert-issue {
            color: #111;
            padding: 0;
            margin-bottom: .9rem;
            font-size: var(--print-detail-size);
            font-weight: 600;
            line-height: 1.3;
        }
        .day-title { font-size: var(--print-heading-size); font-weight: 700; margin: .9rem 0 .3rem; line-height: 1.2; }
        .item-title {
            font-size: 12pt;
            font-weight: 700;
            color: #111;
            margin: .2rem 0;
            line-height: 1.2;
            margin-left: 1.2rem;
        }
        .item-list { margin: 0 0 .35rem 2.4rem; }
        .item-line { margin: .06rem 0; font-size: 10.5pt; line-height: 1.2; }
        .custom-note-line { font-size: var(--print-detail-size); line-height: 1.28; margin: 0; }
        .empty-preview {
            padding: 2rem 1rem;
            text-align: center;
            color: #6b7280;
        }
        @media print {
            @page { size: A4; margin: 10mm; }
            body.dsr-monthly-page { background: #fff; }
            body.dsr-monthly-page > *:not(#dsrPreviewModal) { display: none !important; }
            .modal-backdrop { display: none !important; }
            #dsrPreviewModal {
                position: static !important;
                display: block !important;
                overflow: visible !important;
                opacity: 1 !important;
            }
            #dsrPreviewModal .modal-dialog {
                max-width: none !important;
                margin: 0 !important;
            }
            #dsrPreviewModal .modal-header,
            #dsrPreviewModal .modal-footer { display: none !important; }
            #dsrPreviewModal .modal-body {
                max-height: none !important;
                overflow: visible !important;
                padding: 0 !important;
                background: #fff !important;
            }
            .paper { padding: 0; max-width: none; }
            .paper-title, .day-title, .item-title { font-size: var(--print-heading-size) !important; }
            .paper-sub, .alert-issue, .item-list li { font-size: var(--print-detail-size) !important; }
        }
    </style>
</head>
<body class="dsr-monthly-page">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-3 pb-5">
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= rep_esc(app_path('index.php')) ?>" class="text-decoration-none text-warning-emphasis">หน้าแรก</a></li>
            <li class="breadcrumb-item"><a href="<?= rep_esc($hubUrl) ?>" class="text-decoration-none text-warning-emphasis">สมุดรายวันหน้างาน</a></li>
            <li class="breadcrumb-item active" aria-current="page">สรุปรายเดือน</li>
        </ol>
    </nav>

    <div class="page-heading mb-2">
        <h4 class="fw-bold mb-1"><i class="bi bi-file-earmark-text text-warning me-2"></i>สรุปรายงานประจำเดือน</h4>
        <p class="text-muted small mb-0"><?= rep_esc($monthLabelTh) ?> · วันที่มีอุปสรรค <?= (int) $blockedDaysCount ?> วัน</p>
    </div>

    <div class="dsr-monthly-actions d-print-none">
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <a href="<?= rep_esc($calendarBackUrl) ?>" class="btn btn-outline-secondary rounded-pill">
                <i class="bi bi-arrow-left me-1"></i>กลับปฏิทิน
            </a>
            <button type="button" class="btn btn-warning text-dark fw-semibold rounded-pill" id="btnShowPreview">
                <i class="bi bi-eye me-1"></i>แสดงตัวอย่าง
            </button>
        </div>
    </div>

    <section class="editor-card card mb-3 d-print-none">
        <div class="card-body p-3 p-lg-4">
            <h6 class="fw-bold text-warning-emphasis mb-3"><i class="bi bi-pencil-square me-1"></i>กำหนดหัวเอกสารก่อนพิมพ์</h6>
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="headerCompany" class="form-label fw-semibold small">บริษัท</label>
                    <select id="headerCompany" class="form-select form-select-sm">
                        <option value="">— เลือกบริษัท —</option>
                        <?php foreach ($companyOptions as $co): ?>
                            <option value="<?= rep_esc($co) ?>" <?= $selectedCompanyName === $co ? 'selected' : '' ?>><?= rep_esc($co) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="headerCustomer" class="form-label fw-semibold small">ลูกค้า / ผู้รับผิดชอบ</label>
                    <input id="headerCustomer" type="text" class="form-control form-control-sm" value="<?= rep_esc($selectedCustomerName) ?>" placeholder="พิมพ์ชื่อลูกค้า">
                </div>
                <div class="col-12">
                    <label for="headerNote" class="form-label fw-semibold small">รายละเอียดเพิ่มเติม (แสดงในเอกสาร)</label>
                    <textarea id="headerNote" class="form-control form-control-sm" rows="3" placeholder="ระบุข้อความเพิ่มเติมสำหรับเอกสาร..."></textarea>
                </div>
            </div>
            <p class="small text-muted mb-0 mt-3"><i class="bi bi-info-circle me-1"></i>กรอกข้อมูลด้านบน แล้วกด「แสดงตัวอย่าง」เพื่อดูและพิมพ์เอกสาร</p>
        </div>
    </section>
</div>

<div class="modal fade" id="dsrPreviewModal" tabindex="-1" aria-labelledby="dsrPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="dsrPreviewModalLabel"><i class="bi bi-eye text-warning me-2"></i>ตัวอย่างเอกสารที่จะพิมพ์</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body p-0">
            <main class="paper" id="printArea">
                <div id="customReportHeading" class="paper-sub" style="text-align:left;margin-top:0;margin-bottom:.35rem;display:none;"></div>
                <div id="customReportNote" style="font-size:var(--print-detail-size);margin-bottom:.45rem;display:none;"></div>
                <div class="alert-issue" id="stopWorkSummary">
                    จำนวนวันที่ต้องหยุดงาน รวมทั้งหมด <?= (int) $blockedDaysCount ?> วัน มีรายละเอียดดังนี้
                </div>

                <?php if (empty($grouped)): ?>
                    <div class="empty-preview">
                        <i class="bi bi-calendar-x display-6 text-muted d-block mb-2"></i>
                        ไม่พบวันที่มีอุปสรรคการทำงานในเดือนนี้
                    </div>
                <?php else: ?>
                    <?php foreach ($grouped as $date => $items): ?>
                        <section>
                            <div class="day-title">วันที่ <?= rep_esc(rep_date_print($date)) ?></div>
                            <?php foreach ($items as $idx => $it): ?>
                                <article class="mb-3">
                                    <div class="item-title">- รายการที่ <?= (int) ($idx + 1) ?></div>
                                    <div class="item-list render-detail-list"
                                        data-work="<?= rep_esc($it['work_progress'] !== '' ? $it['work_progress'] : '-') ?>"
                                        data-material="<?= rep_esc($it['materials_equipment'] !== '' ? $it['materials_equipment'] : '-') ?>"
                                        data-issues="<?= rep_esc($it['issues_remarks'] !== '' ? $it['issues_remarks'] : '-') ?>">
                                        <div class="item-line">- รายละเอียดงานที่ทำ: <?= rep_esc($it['work_progress'] !== '' ? $it['work_progress'] : '-') ?></div>
                                        <div class="item-line">- วัสดุและเครื่องจักร: <?= rep_esc($it['materials_equipment'] !== '' ? $it['materials_equipment'] : '-') ?></div>
                                        <div class="item-line">- ปัญหาและอุปสรรค: <?= rep_esc($it['issues_remarks'] !== '' ? $it['issues_remarks'] : '-') ?></div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </section>
                    <?php endforeach; ?>
                <?php endif; ?>
            </main>
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">ปิด</button>
                <button type="button" class="btn btn-warning text-dark fw-semibold rounded-pill" id="btnPrintReport">
                    <i class="bi bi-printer-fill me-1"></i>พิมพ์เอกสาร
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const companyEl = document.getElementById('headerCompany');
    const customerEl = document.getElementById('headerCustomer');
    const noteEl = document.getElementById('headerNote');
    const headingEl = document.getElementById('customReportHeading');
    const notePrintEl = document.getElementById('customReportNote');
    const lists = Array.from(document.querySelectorAll('.render-detail-list'));
    const btnPrint = document.getElementById('btnPrintReport');
    const btnShowPreview = document.getElementById('btnShowPreview');
    const previewModalEl = document.getElementById('dsrPreviewModal');
    const previewModal = previewModalEl ? new bootstrap.Modal(previewModalEl) : null;

    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function (m) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
        });
    }

    function renderHeaderText() {
        const c = companyEl ? String(companyEl.value || '').trim() : '';
        const u = customerEl ? String(customerEl.value || '').trim() : '';
        const note = noteEl ? String(noteEl.value || '').trim() : '';
        const companyText = c !== '' ? c : '................................';
        const ownerText = u !== '' ? u : '................................';
        const headerText = 'สรุปจำนวนวันทำงานที่ บริษัท ' + companyText + ' ต้องหยุดงาน (เป็นงานในความรับผิดชอบของ ' + ownerText + ') ดังนี้';
        if (headingEl) {
            headingEl.textContent = headerText;
            headingEl.style.display = 'block';
        }
        if (notePrintEl) {
            if (note === '') {
                notePrintEl.innerHTML = '';
                notePrintEl.style.display = 'none';
            } else {
                const lines = note.split(/\r?\n/).map(function (ln) { return String(ln).trim(); }).filter(function (ln) { return ln !== ''; });
                notePrintEl.innerHTML = lines.map(function (ln) {
                    const normalized = ln.startsWith('-') ? ln : ('- ' + ln);
                    return '<div class="custom-note-line">' + esc(normalized) + '</div>';
                }).join('');
                notePrintEl.style.display = 'block';
            }
        }
    }

    function renderLists() {
        lists.forEach(function (ul) {
            const work = ul.getAttribute('data-work') || '-';
            const material = ul.getAttribute('data-material') || '-';
            const issues = ul.getAttribute('data-issues') || '-';
            ul.innerHTML = [
                '<div class="item-line">- รายละเอียดงานที่ทำ: ' + esc(work) + '</div>',
                '<div class="item-line">- วัสดุและเครื่องจักร: ' + esc(material) + '</div>',
                '<div class="item-line">- ปัญหาและอุปสรรค: ' + esc(issues) + '</div>'
            ].join('');
        });
    }

    function attachInputListeners(el) {
        if (!el) return;
        el.addEventListener('input', function () {
            renderHeaderText();
            renderLists();
        });
        el.addEventListener('change', function () {
            renderHeaderText();
            renderLists();
        });
    }

    [companyEl, customerEl, noteEl].forEach(attachInputListeners);

    function refreshPreview() {
        renderHeaderText();
        renderLists();
    }

    if (previewModalEl) {
        previewModalEl.addEventListener('show.bs.modal', refreshPreview);
    }

    if (btnShowPreview) {
        btnShowPreview.addEventListener('click', function () {
            refreshPreview();
            if (previewModal) {
                previewModal.show();
            }
        });
    }

    if (btnPrint) {
        btnPrint.addEventListener('click', function () {
            refreshPreview();
            window.print();
        });
    }
})();
</script>
</body>
</html>
