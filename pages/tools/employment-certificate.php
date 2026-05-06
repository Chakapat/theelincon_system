<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}
if (!user_is_admin_role()) {
    $access_denied_title = 'หนังสือรับรองการทำงาน';
    $access_denied_text = 'เข้าใช้งานได้เฉพาะผู้ใช้ที่มีสิทธิ์ ADMIN หรือ CEO เท่านั้น';
    require dirname(__DIR__, 2) . '/includes/page_access_denied_swal.php';
    exit;
}

$users_for_select = Db::tableRows('users');
usort($users_for_select, static function ($a, $b): int {
    return strcmp((string) ($a['fname'] ?? ''), (string) ($b['fname'] ?? ''))
        ?: strcmp((string) ($a['lname'] ?? ''), (string) ($b['lname'] ?? ''));
});

$companies_all = Db::tableRows('company');
Db::sortRows($companies_all, 'id', false);
$company = $companies_all[0] ?? [];

$uid = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$employee = null;
if ($uid > 0) {
    $employee = Db::rowByIdField('users', $uid, 'userid');
}

function thai_date_issue(): string {
    $m = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    $d = (int) date('j');
    $mo = (int) date('n');
    $y = (int) date('Y') + 543;
    return $d . ' ' . $m[$mo] . ' พ.ศ. ' . $y;
}

function fmt_birth(?string $d): string {
    if (!$d || $d === '0000-00-00') {
        return '—';
    }
    return date('d/m/Y', strtotime($d));
}

$issue_thai = thai_date_issue();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หนังสือรับรองการทำงาน | Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('assets/css/document-print.css')) ?>">
    <style>
        /* --- หน้าจอ: ตัวอย่างขนาดใกล้ A4 --- */
        body.employment-cert-page {
            font-family: 'Sarabun', 'Leelawadee UI', 'Tahoma', sans-serif;
            background: #e8e8e8;
        }
        .cert-print-surface {
            box-sizing: border-box;
            max-width: 210mm;
            margin: 0 auto 2rem;
            padding: 0 0.5rem;
        }
        .cert-box {
            box-sizing: border-box;
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 18mm 20mm 22mm;
            background: #fff;
            box-shadow: 0 6px 28px rgba(0,0,0,.1);
            border-top: 5px solid #fd7e14;
            color: #1a1a1a;
        }
        .cert-header { text-align: center; margin-bottom: 6mm; }
        .cert-header .cert-logo { max-height: 80px; max-width: 240px; width: auto; object-fit: contain; margin-bottom: 3mm; }
        .cert-header .co-name {
            font-size: 13.5pt;
            font-weight: 700;
            letter-spacing: 0.06em;
            line-height: 1.45;
            margin-bottom: 2mm;
        }
        .cert-header .co-meta {
            font-size: 10.5pt;
            font-weight: 500;
            letter-spacing: 0.03em;
            line-height: 1.65;
            color: #444;
        }
        .cert-title {
            font-size: 16pt;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-align: center;
            margin: 5mm 0 7mm;
            padding-bottom: 3mm;
            border-bottom: 1px solid #dee2e6;
        }
        .cert-body {
            font-size: 12.5pt;
            font-weight: 500;
            line-height: 1.95;
            letter-spacing: 0.025em;
            text-align: justify;
            word-spacing: 0.05em;
        }
        .cert-lead {
            text-indent: 2.5em;
            margin-bottom: 5mm;
        }
        .cert-field {
            display: grid;
            grid-template-columns: 11.2rem 1fr;
            column-gap: 2.5mm;
            row-gap: 1mm;
            align-items: start;
            margin-bottom: 4mm;
            line-height: 1.75;
        }
        .cert-field .cert-label {
            font-weight: 700;
            letter-spacing: 0.04em;
            white-space: nowrap;
        }
        .cert-field .cert-value {
            letter-spacing: 0.02em;
            word-break: break-word;
        }
        .cert-para {
            text-indent: 2.5em;
            margin-top: 6mm;
            margin-bottom: 5mm;
        }
        .cert-date-line {
            margin-top: 5mm;
            text-align: right;
            font-size: 12pt;
            letter-spacing: 0.04em;
        }
        .sig-block {
            margin-top: 14mm;
            padding-top: 4mm;
            text-align: center;
        }
        .sig-line {
            display: inline-block;
            min-width: 58mm;
            max-width: 85%;
            padding-top: 2mm;
            margin-top: 18mm;
            border-top: 1px solid #222;
            font-weight: 600;
            font-size: 11.5pt;
            letter-spacing: 0.05em;
            line-height: 1.5;
        }
        .sig-note {
            font-size: 10pt;
            color: #555;
            margin-top: 2mm;
            letter-spacing: 0.03em;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 12mm 14mm 14mm 14mm;
            }
            html {
                font-size: 12pt;
            }
            body.employment-cert-page {
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            body.employment-cert-page .navbar,
            body.employment-cert-page .no-print {
                display: none !important;
            }
            body.employment-cert-page * {
                font-family: 'Sarabun', 'Leelawadee UI', 'Tahoma', sans-serif !important;
            }
            .cert-print-surface {
                max-width: none !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .cert-box {
                width: 100% !important;
                min-height: 0 !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
                border-top: none !important;
                page-break-inside: avoid;
            }
            .cert-header .cert-logo { max-height: 22mm; max-width: 65mm; }
            .cert-title {
                font-size: 15pt;
                letter-spacing: 0.16em;
                margin-top: 0;
            }
            .cert-body {
                font-size: 12pt;
                line-height: 2;
                letter-spacing: 0.03em;
            }
            .cert-field {
                grid-template-columns: 10.8rem 1fr;
                margin-bottom: 3.5mm;
            }
        }
    </style>
</head>
<body class="employment-cert-page">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 no-print">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-medical text-warning me-2"></i>หนังสือรับรองการทำงาน</h4>
    </div>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label fw-bold">เลือกพนักงาน</label>
                    <select name="id" class="form-select" required onchange="this.form.submit()">
                        <option value="">— เลือกรายชื่อ —</option>
                        <?php foreach ($users_for_select as $u): ?>
                            <option value="<?= (int)$u['userid'] ?>" <?= $uid === (int)$u['userid'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['fname'] . ' ' . $u['lname']) ?> (<?= htmlspecialchars($u['user_code'] ?? '') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-warning text-white fw-bold w-100">แสดงเอกสาร</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($employee): ?>
<div class="cert-print-surface">
    <div class="text-center no-print mb-3">
        <button type="button" class="btn btn-warning text-white fw-bold px-4" onclick="window.print()"><i class="bi bi-printer me-2"></i>พิมพ์หนังสือรับรอง</button>
    </div>

    <article class="cert-box">
        <header class="cert-header">
            <?php if (!empty($company['logo'])): ?>
                <img src="<?= htmlspecialchars(upload_logo_url($company['logo'])) ?>" alt="" class="cert-logo">
            <?php endif; ?>
            <div class="co-name"><?= htmlspecialchars($company['name'] ?? 'THEELIN CON CO.,LTD.') ?></div>
            <div class="co-meta"><?= nl2br(htmlspecialchars($company['address'] ?? '')) ?></div>
            <?php if (!empty($company['phone'])): ?>
                <div class="co-meta">โทร. <?= htmlspecialchars($company['phone']) ?></div>
            <?php endif; ?>
            <?php
            $coTax = trim((string) ($company['tax_id'] ?? ''));
            if ($coTax !== ''): ?>
                <div class="co-meta">เลขประจำตัวผู้เสียภาษีอากร <?= htmlspecialchars($coTax, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </header>

        <h1 class="cert-title">หนังสือรับรองการทำงาน</h1>

        <div class="cert-body">
            <p class="cert-lead">ขอรับรองว่า</p>

            <div class="cert-field">
                <span class="cert-label">ชื่อ - สกุล</span>
                <span class="cert-value"><?= htmlspecialchars(trim($employee['fname'] . ' ' . $employee['lname'])) ?></span>
            </div>
            <div class="cert-field">
                <span class="cert-label">ตำแหน่ง</span>
                <span class="cert-value"><?php
                    $job = trim((string) ($employee['job_title'] ?? ''));
                    echo $job !== '' ? htmlspecialchars($job, ENT_QUOTES, 'UTF-8') : '—';
                ?></span>
            </div>
            <div class="cert-field">
                <span class="cert-label">เลขประจำตัวประชาชน</span>
                <span class="cert-value"><?= htmlspecialchars(preg_replace('/\D/', '', (string)($employee['national_id'] ?? '')) ?: '—') ?></span>
            </div>
            <div class="cert-field">
                <span class="cert-label">วันเดือนปีเกิด</span>
                <span class="cert-value"><?= htmlspecialchars(fmt_birth($employee['birth_date'] ?? null)) ?></span>
            </div>
            <div class="cert-field">
                <span class="cert-label">ที่อยู่</span>
                <span class="cert-value"><?= nl2br(htmlspecialchars(trim((string)($employee['address'] ?? ''))) ?: '—') ?></span>
            </div>
            <div class="cert-field">
                <span class="cert-label">ฐานเงินเดือน</span>
                <span class="cert-value"><?php
                    $sb = $employee['salary_base'] ?? null;
                    echo ($sb !== null && $sb !== '') ? htmlspecialchars(number_format((float)$sb, 2)) . ' บาท (บาทถ้วน)' : '—';
                ?></span>
            </div>

            <p class="cert-para">ท่านดังกล่าวเป็นพนักงานของบริษัทฯ และปฏิบัติหน้าที่ตามตำแหน่งข้างต้น บริษัทฯ ได้ออกหนังสือฉบับนี้ให้ไว้เพื่อใช้ประกอบการตามที่เห็นสมควร</p>

            <p class="cert-date-line">ออกให้ ณ วันที่ <strong><?= htmlspecialchars($issue_thai) ?></strong></p>
        </div>

        <footer class="sig-block">
            <div class="sig-line">
                (<?= htmlspecialchars($company['name'] ?? 'ผู้มีอำนาจลงนาม') ?>)
            </div>
            <div class="sig-note">ผู้มีอำนาจลงนาม / Authorized Signature</div>
        </footer>
    </article>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
