<?php

declare(strict_types=1);

session_start();

use Theelincon\Rtdb\Db;

require_once __DIR__ . '/../config/connect_database.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header('Location: ' . app_path('pages/daily-site-report-list.php'));
    exit;
}

$report = Db::row('daily_site_reports', (string) $id);
if (!$report) {
    header('Location: ' . app_path('pages/daily-site-report-list.php') . '?err=missing');
    exit;
}

$u = Db::row('users', (string) ($report['created_by'] ?? ''));
$report['recorder_name'] = trim((string) (($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')));
$report['recorder_code'] = (string) ($u['user_code'] ?? '');
$c = Db::row('company', (string) ($report['company_id'] ?? ''));
$report['company_name'] = (string) ($c['name'] ?? '');
$report['company_logo'] = (string) ($c['logo'] ?? '');
$report['company_address'] = (string) ($c['address'] ?? '');

$photos = Db::filter('daily_site_report_photos', static fn (array $r): bool => (int) ($r['report_id'] ?? 0) === $id);
usort($photos, static function ($a, $b): int {
    $sa = (int) ($a['sort_order'] ?? 0);
    $sb = (int) ($b['sort_order'] ?? 0);
    if ($sa !== $sb) {
        return $sa <=> $sb;
    }

    return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
});

$companiesAll = Db::tableRows('company');
Db::sortRows($companiesAll, 'id', false);
$companyFallback = $companiesAll[0] ?? [];

$creator = (int) ($report['created_by'] ?? 0);
$canEdit = ($creator === $userId || $role === 'admin');

function dsr_esc(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function dsr_nl2(?string $s): string {
    return nl2br(dsr_esc($s ?? ''));
}

$listUrl = htmlspecialchars(app_path('pages/daily-site-report-list.php'), ENT_QUOTES, 'UTF-8');
$editUrl = htmlspecialchars(app_path('pages/daily-site-report-form.php'), ENT_QUOTES, 'UTF-8') . '?id=' . $id;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= dsr_esc($report['report_no']) ?> | สมุดรายวันหน้างาน</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body.screen { font-family: 'Sarabun', sans-serif; background: #fffaf5; }

        .dsr-print-sheet {
            box-sizing: border-box;
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 2rem;
            padding: 14mm 16mm 18mm;
            background: #fff;
            border-top: 6px solid #fd7e14;
            box-shadow: 0 8px 28px rgba(0,0,0,.08);
            color: #222;
            font-size: 11pt;
            line-height: 1.55;
        }

        .dsr-print-sheet h1 {
            font-size: 17pt;
            font-weight: 800;
            letter-spacing: .06em;
            margin-bottom: .25rem;
        }

        .dsr-meta { font-size: 10pt; color: #444; margin-bottom: 1rem; }

        .dsr-section-title {
            font-weight: 700;
            font-size: 11pt;
            color: #c2410c;
            border-bottom: 1px solid #eee;
            padding-bottom: .35rem;
            margin: 1rem 0 .5rem;
        }

        .dsr-work-detail-block { margin-top: 1rem; }
        .dsr-work-detail-block .dsr-section-title.dsr-st-inline { margin-top: 0; }
        .dsr-work-detail-block .col-12 .dsr-section-title { margin-top: 1rem; }

        .dsr-table { width: 100%; border-collapse: collapse; font-size: 10pt; margin-bottom: .75rem; }
        .dsr-table th, .dsr-table td {
            border: 1px solid #ddd;
            padding: 6px 8px;
            vertical-align: top;
        }
        .dsr-table thead th { background: #faf8f6; font-weight: 700; }

        .dsr-photo-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .dsr-photo-wrap { border: 1px solid #e9ecef; border-radius: 6px; overflow: hidden; background: #fafafa; }
        .dsr-photo-wrap img { width: 100%; height: auto; display: block; max-height: 62mm; object-fit: contain; }
        .dsr-photo-cap { font-size: 9pt; padding: 6px 8px; color: #444; }

        @media print {
            @page { size: A4; margin: 12mm; }
            body.screen { background: #fff !important; }
            .no-print { display: none !important; }
            .dsr-print-sheet {
                margin: 0;
                padding: 0;
                box-shadow: none;
                width: auto;
                min-height: auto;
                border-top: none;
                page-break-after: auto;
            }
            .container.py-4 { padding-top: 0 !important; }
            .dsr-photo-grid { grid-template-columns: repeat(3, 1fr); }
            .dsr-work-detail-block .row > .col-lg-6 {
                flex: 0 0 auto;
                width: 50%;
                max-width: 50%;
            }
        }
    </style>
</head>
<body class="screen">

<div class="no-print">
<?php include __DIR__ . '/../components/navbar.php'; ?>
</div>

<div class="container py-4 pb-5">
    <?php if (!empty($_GET['saved'])): ?>
        <div class="alert alert-success rounded-3 small no-print">บันทึกแล้ว</div>
    <?php endif; ?>

    <div class="no-print d-flex flex-wrap gap-2 justify-content-between align-items-center mb-4">
        <nav aria-label="breadcrumb" class="mb-0">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars(app_path('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none text-warning-emphasis">หน้าแรก</a></li>
                <li class="breadcrumb-item"><a href="<?= $listUrl ?>" class="text-decoration-none text-warning-emphasis">สมุดรายวันหน้างาน</a></li>
                <li class="breadcrumb-item active"><?= dsr_esc($report['report_no']) ?></li>
            </ol>
        </nav>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-success rounded-pill btn-sm fw-bold px-4" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>พิมพ์ / บันทึกเป็น PDF
            </button>
            <?php if ($canEdit): ?>
                <a href="<?= $editUrl ?>" class="btn btn-outline-warning rounded-pill btn-sm fw-bold"><i class="bi bi-pencil me-1"></i>แก้ไข</a>
                <form method="post" action="<?= htmlspecialchars(app_path('actions/daily-site-report-save.php'), ENT_QUOTES, 'UTF-8') ?>" class="d-inline" onsubmit="return confirm('ลบรายงานนี้ถาวร?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <button type="submit" class="btn btn-outline-danger rounded-pill btn-sm">ลบ</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="dsr-print-sheet" id="dsrPrintArea">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
            <div>
                <?php
                $hdrLogo = trim((string) ($report['company_logo'] ?? ''));
                if ($hdrLogo === '') {
                    $hdrLogo = trim((string) ($companyFallback['logo'] ?? ''));
                }
                if ($hdrLogo !== ''):
                    $logoUrl = htmlspecialchars(upload_logo_url($hdrLogo), ENT_QUOTES, 'UTF-8');
                ?>
                    <img src="<?= $logoUrl ?>" alt="" style="max-height:52px;max-width:200px;object-fit:contain;" class="mb-2">
                <?php endif; ?>
                <?php
                $hdrName = trim((string) ($report['company_name'] ?? ''));
                if ($hdrName === '') {
                    $hdrName = (string) ($companyFallback['name'] ?? 'THEELIN CON CO.,LTD.');
                }
                $hdrAddr = trim((string) ($report['company_address'] ?? ''));
                if ($hdrAddr === '') {
                    $hdrAddr = (string) ($companyFallback['address'] ?? '');
                }
                ?>
                <div class="fw-bold" style="font-size:12pt;"><?= dsr_esc($hdrName) ?></div>
                <?php if ($hdrAddr !== ''): ?>
                    <div class="small text-muted"><?= dsr_esc($hdrAddr) ?></div>
                <?php endif; ?>
            </div>
            <div class="text-end">
                <div class="badge bg-warning text-dark px-3 py-2 fs-6"><?= dsr_esc($report['report_no']) ?></div>
            </div>
        </div>

        <h1 class="text-center">สมุดรายวันหน้างาน</h1>
        <div class="text-center dsr-meta mb-3">Daily Site Report</div>

        <div class="dsr-meta">
            <strong>วันที่รายงาน:</strong> <?= $report['report_date'] ? dsr_esc(date('d/m/Y', strtotime($report['report_date']))) : '—' ?>
            <?php if (trim((string)($report['weather'] ?? '')) !== ''): ?>
                &nbsp;|&nbsp; <strong>สภาพอากาศ:</strong> <?= dsr_esc($report['weather']) ?>
            <?php endif; ?>
        </div>

        <div class="dsr-section-title">ข้อมูลพื้นฐาน (General Information)</div>
        <table class="table table-sm table-borderless mb-2" style="font-size:10pt;">
            <tbody>
                <tr><td class="text-secondary" style="width:28%;">ไซต์งาน</td><td><?= dsr_esc($report['site_name'] ?? '') ?: '—' ?></td></tr>
                <tr><td class="text-secondary">โครงการ</td><td><?= dsr_esc($report['project_name'] ?? '') ?: '—' ?></td></tr>
                <tr><td class="text-secondary">บริษัท</td><td><?= dsr_esc(trim((string)($report['company_name'] ?? ''))) ?: '—' ?></td></tr>
                <?php $wcShow = trim((string) ($report['worker_count'] ?? '')); ?>
                <tr><td class="text-secondary">คนงาน</td><td><?= $wcShow !== '' ? dsr_esc($wcShow) . ' <span class="text-muted">คน</span>' : '—' ?></td></tr>
            </tbody>
        </table>

        <div class="dsr-work-detail-block">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="dsr-section-title dsr-st-inline">รายละเอียดงานที่ทำ (Work Progress)</div>
                    <div class="mb-0"><?= trim((string)($report['work_progress'] ?? '')) !== '' ? dsr_nl2($report['work_progress']) : '<span class="text-muted small">—</span>' ?></div>
                </div>
                <div class="col-lg-6">
                    <div class="dsr-section-title dsr-st-inline">วัสดุและเครื่องจักร (Materials & Equipment)</div>
                    <div class="mb-0"><?= trim((string)($report['materials_equipment'] ?? '')) !== '' ? dsr_nl2($report['materials_equipment']) : '<span class="text-muted small">—</span>' ?></div>
                </div>
                <div class="col-12">
                    <div class="dsr-section-title">ปัญหาและอุปสรรค (Issues & Remarks)</div>
                    <div class="mb-3"><?= trim((string)($report['issues_remarks'] ?? '')) !== '' ? dsr_nl2($report['issues_remarks']) : '<span class="text-muted small">—</span>' ?></div>
                </div>
            </div>
        </div>

        <?php if (!empty($photos)): ?>
            <div class="dsr-section-title">รูปภาพประกอบ (Photo Documentation)</div>
            <div class="dsr-photo-grid">
                <?php foreach ($photos as $ph): ?>
                    <div class="dsr-photo-wrap">
                        <img src="<?= htmlspecialchars(app_path($ph['file_path']), ENT_QUOTES, 'UTF-8') ?>" alt="">
                        <?php if (trim((string)($ph['caption'] ?? '')) !== ''): ?>
                            <div class="dsr-photo-cap"><?= dsr_esc($ph['caption']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="mt-4 pt-3 border-top small text-muted">
            <strong>ผู้บันทึก:</strong> <?= dsr_esc(trim($report['recorder_name'] ?? '') ?: '—') ?>
            <?php if (trim((string)($report['recorder_code'] ?? '')) !== ''): ?>
                <span class="text-secondary">(<?= dsr_esc($report['recorder_code']) ?>)</span>
            <?php endif; ?>
            &nbsp;|&nbsp;
            <strong>วันเวลาที่บันทึก:</strong> <?= !empty($report['created_at']) ? dsr_esc(date('d/m/Y H:i', strtotime($report['created_at']))) : '—' ?>
            <?php if (!empty($report['updated_at']) && $report['updated_at'] !== $report['created_at']): ?>
                &nbsp;|&nbsp; <strong>แก้ไขล่าสุด:</strong> <?= dsr_esc(date('d/m/Y H:i', strtotime($report['updated_at']))) ?>
            <?php endif; ?>
        </div>

        <p class="small text-muted mt-4 mb-0 no-print" style="font-size:9pt;">พิมพ์ผ่านเมนูเบราว์เซอร์ — เลือก “บันทึกเป็น PDF” หรือเครื่องพิมพ์ได้ตามต้องการ</p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
