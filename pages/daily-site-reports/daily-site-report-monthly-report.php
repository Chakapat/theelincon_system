<?php

declare(strict_types=1);


session_start();

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Dsr;

require_once dirname(__DIR__, 2) . '/config/connect_database.php';
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

$monthTitle = sprintf('%02d/%04d', $month, $year);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สรุปรายงานประจำเดือน <?= rep_esc($monthTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --print-heading-size: 14pt;
            --print-detail-size: 12pt;
        }
        body { font-family: 'TH Sarabun New', 'THSarabunNew', 'Sarabun', sans-serif; color: #111; background: #fff; margin: 0; }
        .paper { max-width: 190mm; margin: 0 auto; padding: 10mm 8mm; }
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
            margin-left: 1.2rem; /* tab ระดับรายการ */
        }
        .item-list { margin: 0 0 .35rem 2.4rem; } /* tab เพิ่มอีกระดับ */
        .item-line { margin: .06rem 0; font-size: 10.5pt; line-height: 1.2; }
        .custom-note-line { font-size: var(--print-detail-size); line-height: 1.28; margin: 0; }
        .toolbar { max-width: 190mm; margin: 10px auto 0; display: flex; justify-content: flex-end; }
        .toolbar button {
            font-family: 'TH Sarabun New', 'THSarabunNew', 'Sarabun', sans-serif;
            font-size: 11pt;
            padding: .25rem .7rem;
            border: 1px solid #999;
            background: #fff;
            cursor: pointer;
        }
        .editor-panel {
            max-width: 190mm;
            margin: 8px auto 0;
            border: 1px solid #ddd;
            padding: 10px;
        }
        .editor-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .editor-panel label { font-size: 10pt; font-weight: 600; display: block; margin-bottom: 2px; }
        .editor-panel input, .editor-panel select, .editor-panel textarea {
            width: 100%;
            border: 1px solid #bbb;
            padding: 4px 6px;
            font-family: 'Sarabun', sans-serif;
            font-size: 10pt;
            box-sizing: border-box;
        }
        .editor-panel textarea { min-height: 56px; resize: vertical; }
        .editor-actions { margin-top: 8px; display: flex; gap: 6px; flex-wrap: wrap; }
        @media print {
            @page { size: A4; margin: 10mm; }
            .toolbar { display: none !important; }
            .editor-panel { display: none !important; }
            .paper { padding: 0; max-width: none; }
            .paper-title, .day-title, .item-title { font-size: var(--print-heading-size) !important; }
            .paper-sub, .alert-issue, .item-list li { font-size: var(--print-detail-size) !important; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">พิมพ์เอกสาร</button>
</div>
<section class="editor-panel">
    <div class="editor-grid">
        <div>
            <label for="headerCompany">บริษัท</label>
            <select id="headerCompany">
                <option value="">— เลือกบริษัท —</option>
                <?php foreach ($companyOptions as $co): ?>
                    <option value="<?= rep_esc($co) ?>" <?= $selectedCompanyName === $co ? 'selected' : '' ?>><?= rep_esc($co) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="headerCustomer">ลูกค้า</label>
            <input id="headerCustomer" type="text" value="<?= rep_esc($selectedCustomerName) ?>" placeholder="พิมพ์ชื่อลูกค้า">
        </div>
    </div>
    <div style="margin-top:8px;">
        <label for="headerNote">รายละเอียดเพิ่มเติม (นำไปพิมพ์)</label>
        <textarea id="headerNote" placeholder="ระบุข้อความเพิ่มเติมสำหรับเอกสาร..."></textarea>
    </div>
</section>

<main class="paper">
    <div id="customReportHeading" class="paper-sub" style="text-align:left;margin-top:0;margin-bottom:.35rem;display:none;"></div>
    <div id="customReportNote" style="font-size:var(--print-detail-size);margin-bottom:.45rem;display:none;"></div>
    <div class="alert-issue" id="stopWorkSummary">
        จำนวนวันที่ต้องหยุดงาน รวมทั้งหมด <?= (int) $blockedDaysCount ?> วัน มีรายละเอียดดังนี้
    </div>

    <?php if (empty($grouped)): ?>
        <div style="font-size:var(--print-detail-size);color:#111;">ไม่พบวันที่มีอุปสรรคการทำงานในเดือนและตัวกรองที่เลือก</div>
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
<script>
(function () {
    const companyEl = document.getElementById('headerCompany');
    const customerEl = document.getElementById('headerCustomer');
    const noteEl = document.getElementById('headerNote');
    const headingEl = document.getElementById('customReportHeading');
    const notePrintEl = document.getElementById('customReportNote');
    const lists = Array.from(document.querySelectorAll('.render-detail-list'));

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
            const html = [
                '<div class="item-line">- รายละเอียดงานที่ทำ: ' + esc(work) + '</div>',
                '<div class="item-line">- วัสดุและเครื่องจักร: ' + esc(material) + '</div>',
                '<div class="item-line">- ปัญหาและอุปสรรค: ' + esc(issues) + '</div>'
            ].join('');
            ul.innerHTML = html;
        });
    }

    function attachInputListeners(el) {
        if (!el) return;
        el.addEventListener('input', function () {
            renderHeaderText();
            renderLists();
        });
    }

    [companyEl, customerEl, noteEl].forEach(attachInputListeners);
    renderHeaderText();
    renderLists();
})();
</script>
</body>
</html>
