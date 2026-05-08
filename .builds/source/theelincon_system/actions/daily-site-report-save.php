<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/daily_site_report_schema.php';
require_once __DIR__ . '/../includes/daily_site_report_projects.php';

use Theelincon\Rtdb\Db;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

$action = $_POST['action'] ?? '';
$listUrl = app_path('pages/daily-site-report-list.php');
$formBase = app_path('pages/daily-site-report-form.php');

function dsr_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function dsr_upload_dir(int $reportId): string
{
    return dirname(__DIR__) . '/uploads/daily_site_reports/' . $reportId;
}

function dsr_web_path(int $reportId, string $filename): string
{
    return 'uploads/daily_site_reports/' . $reportId . '/' . $filename;
}

/** @return array{0:bool,1:string} */
function dsr_save_upload(array $file, int $reportId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [false, ''];
    }
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return [false, ''];
    }
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return [false, ''];
    }
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($tmp) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        return [false, ''];
    }
    $ext = $allowed[$mime];
    $name = 'img_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dir = dsr_upload_dir($reportId);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return [false, ''];
    }
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        return [false, ''];
    }

    return [true, $name];
}

if ($action === 'create' || $action === 'update') {
    $reportDate = trim((string) ($_POST['report_date'] ?? ''));
    if ($reportDate === '' || strtotime($reportDate) === false) {
        dsr_redirect($formBase . '?err=date');
    }

    $weather = trim((string) ($_POST['weather'] ?? ''));
    $siteName = trim((string) ($_POST['site_name'] ?? ''));
    $projectNameIn = trim((string) ($_POST['project_name'] ?? ''));
    $allowedProjects = daily_site_report_project_options();
    if ($projectNameIn === '' || !in_array($projectNameIn, $allowedProjects, true)) {
        dsr_redirect($formBase . '?err=project');
    }
    $projectName = $projectNameIn;

    $companyId = isset($_POST['company_id']) ? (int) $_POST['company_id'] : 0;
    if ($companyId <= 0 || Db::row('company', (string) $companyId) === null) {
        dsr_redirect($formBase . '?err=company');
    }

    $workerCount = trim((string) ($_POST['worker_count'] ?? ''));

    $workProgress = trim((string) ($_POST['work_progress'] ?? ''));
    $materialsEquipment = trim((string) ($_POST['materials_equipment'] ?? ''));
    $issuesRemarks = trim((string) ($_POST['issues_remarks'] ?? ''));

    $now = date('Y-m-d H:i:s');

    if ($action === 'create') {
        $reportNo = daily_site_report_next_number();
        $reportId = Db::nextNumericId('daily_site_reports', 'id');
        Db::setRow('daily_site_reports', (string) $reportId, [
            'id' => $reportId,
            'report_no' => $reportNo,
            'report_date' => $reportDate,
            'weather' => $weather,
            'site_name' => $siteName,
            'project_name' => $projectName,
            'company_id' => $companyId,
            'worker_count' => $workerCount,
            'work_progress' => $workProgress,
            'materials_equipment' => $materialsEquipment,
            'issues_remarks' => $issuesRemarks,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    } else {
        $reportId = (int) ($_POST['id'] ?? 0);
        if ($reportId <= 0) {
            dsr_redirect($formBase . '?err=id');
        }

        $existing = Db::row('daily_site_reports', (string) $reportId);
        if ($existing === null) {
            dsr_redirect($listUrl . '?err=missing');
        }
        $creator = (int) ($existing['created_by'] ?? 0);
        if ($creator !== $userId && $role !== 'admin') {
            dsr_redirect($listUrl . '?err=forbidden');
        }

        Db::setRow('daily_site_reports', (string) $reportId, array_merge($existing, [
            'report_date' => $reportDate,
            'weather' => $weather,
            'site_name' => $siteName,
            'project_name' => $projectName,
            'company_id' => $companyId,
            'worker_count' => $workerCount,
            'work_progress' => $workProgress,
            'materials_equipment' => $materialsEquipment,
            'issues_remarks' => $issuesRemarks,
            'updated_at' => $now,
        ]));

        Db::deleteWhereEquals('daily_site_manpower', 'report_id', (string) $reportId);
    }

    if ($action === 'update') {
        $delPhotos = $_POST['delete_photo'] ?? [];
        if (is_array($delPhotos)) {
            foreach ($delPhotos as $pid) {
                $pid = (int) $pid;
                if ($pid <= 0) {
                    continue;
                }
                $prow = Db::row('daily_site_report_photos', (string) $pid);
                if ($prow !== null && (int) ($prow['report_id'] ?? 0) === $reportId) {
                    $full = dirname(__DIR__) . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($prow['file_path'] ?? ''));
                    if (is_file($full)) {
                        @unlink($full);
                    }
                    Db::deleteRow('daily_site_report_photos', (string) $pid);
                }
            }
        }

        $capUp = $_POST['photo_caption'] ?? [];
        if (is_array($capUp)) {
            foreach ($capUp as $pidStr => $capText) {
                $pid = (int) $pidStr;
                if ($pid <= 0) {
                    continue;
                }
                $capText = trim((string) $capText);
                $cur = Db::row('daily_site_report_photos', (string) $pid);
                if ($cur !== null && (int) ($cur['report_id'] ?? 0) === $reportId) {
                    Db::setRow('daily_site_report_photos', (string) $pid, array_merge($cur, ['caption' => $capText]));
                }
            }
        }
    }

    $captionNew = $_POST['photo_caption_new'] ?? [];
    if (!is_array($captionNew)) {
        $captionNew = [];
    }

    if (!empty($_FILES['photos_new']) && is_array($_FILES['photos_new']['name'])) {
        $files = $_FILES['photos_new'];
        $n = count($files['name']);
        $maxOrder = -1;
        foreach (Db::filter('daily_site_report_photos', static fn (array $r): bool => (int) ($r['report_id'] ?? 0) === $reportId) as $r) {
            $maxOrder = max($maxOrder, (int) ($r['sort_order'] ?? 0));
        }
        for ($i = 0; $i < $n; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $single = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i],
            ];
            [$ok, $fname] = dsr_save_upload($single, $reportId);
            if (!$ok || $fname === '') {
                continue;
            }
            $cap = isset($captionNew[$i]) ? trim((string) $captionNew[$i]) : '';
            $rel = dsr_web_path($reportId, $fname);
            ++$maxOrder;
            $photoId = Db::nextNumericId('daily_site_report_photos', 'id');
            Db::setRow('daily_site_report_photos', (string) $photoId, [
                'id' => $photoId,
                'report_id' => $reportId,
                'sort_order' => $maxOrder,
                'file_path' => $rel,
                'caption' => $cap,
            ]);
        }
    }

    dsr_redirect($listUrl . '?saved=1');
}

if ($action === 'delete') {
    $reportId = (int) ($_POST['id'] ?? 0);
    if ($reportId <= 0) {
        dsr_redirect($listUrl . '?err=id');
    }
    $existing = Db::row('daily_site_reports', (string) $reportId);
    if ($existing === null) {
        dsr_redirect($listUrl . '?err=missing');
    }
    $creator = (int) ($existing['created_by'] ?? 0);
    if ($creator !== $userId && $role !== 'admin') {
        dsr_redirect($listUrl . '?err=forbidden');
    }

    foreach (Db::filter('daily_site_report_photos', static fn (array $r): bool => (int) ($r['report_id'] ?? 0) === $reportId) as $ph) {
        $fp = (string) ($ph['file_path'] ?? '');
        if ($fp !== '') {
            $full = dirname(__DIR__) . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fp);
            if (is_file($full)) {
                @unlink($full);
            }
        }
        $pk = (string) ($ph['id'] ?? '');
        if ($pk !== '') {
            Db::deleteRow('daily_site_report_photos', $pk);
        }
    }
    $dir = dsr_upload_dir($reportId);
    if (is_dir($dir)) {
        @rmdir($dir);
    }

    Db::deleteWhereEquals('daily_site_manpower', 'report_id', (string) $reportId);
    Db::deleteRow('daily_site_reports', (string) $reportId);
    dsr_redirect($listUrl . '?deleted=1');
}

dsr_redirect($listUrl);
