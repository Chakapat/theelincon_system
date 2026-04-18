<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/connect_database.php';

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\LaborPayroll;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$back = app_path('pages/labor-payroll.php');
$action = $_POST['action'] ?? '';

function labor_payroll_redirect(string $base, array $query): void
{
    $q = http_build_query($query);
    header('Location: ' . $base . ($q !== '' ? '?' . $q : ''));
    exit;
}

if ($action === 'remove_row') {
    $ym = preg_match('/^\d{4}-\d{2}$/', (string) ($_POST['year_month'] ?? '')) ? $_POST['year_month'] : date('Y-m');
    $wid = (int) ($_POST['worker_id'] ?? 0);
    if ($wid > 0) {
        $pk = Db::compositeKey([$ym, (string) $wid]);
        Db::deleteRow('labor_month_sheet_workers', $pk);
    }
    labor_payroll_redirect($back, ['month' => $ym, 'half' => (int) ($_POST['half'] ?? 1) === 2 ? 2 : 1]);
}

if ($action !== 'save') {
    labor_payroll_redirect($back, []);
}

$ym = preg_match('/^\d{4}-\d{2}$/', (string) ($_POST['year_month'] ?? '')) ? $_POST['year_month'] : date('Y-m');
$half = (int) ($_POST['half'] ?? 1) === 2 ? 2 : 1;

$ts = strtotime($ym . '-01');
if ($ts === false) {
    labor_payroll_redirect($back, ['month' => date('Y-m'), 'half' => $half]);
}
$dim = (int) date('t', $ts);
$startD = $half === 1 ? 1 : 16;
$endD = $half === 1 ? min(15, $dim) : $dim;

$workersIn = $_POST['workers'] ?? [];
if (!is_array($workersIn)) {
    $workersIn = [];
}

$wantClose = !empty($_POST['close_and_archive']);
if ($wantClose) {
    $hasAnyWorker = false;
    foreach ($workersIn as $rw) {
        if (!is_array($rw)) {
            continue;
        }
        if ((int) ($rw['id'] ?? 0) > 0 || trim((string) ($rw['new_name'] ?? '')) !== '') {
            $hasAnyWorker = true;
            break;
        }
    }
    if (!$hasAnyWorker) {
        labor_payroll_redirect($back, ['month' => $ym, 'half' => $half, 'close_err' => 'empty']);
    }
}

$dateFrom = $ym . '-' . str_pad((string) $startD, 2, '0', STR_PAD_LEFT);
$dateTo = $ym . '-' . str_pad((string) $endD, 2, '0', STR_PAD_LEFT);
$closedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

try {
    $sort = 0;
    $postedWorkerIds = [];
    $archiveSnapshots = [];

    foreach ($workersIn as $row) {
        if (!is_array($row)) {
            continue;
        }
        $wid = (int) ($row['id'] ?? 0);
        $newName = trim((string) ($row['new_name'] ?? ''));
        if ($wid <= 0 && $newName === '') {
            continue;
        }
        if ($wid <= 0 && $newName !== '') {
            if (function_exists('mb_substr')) {
                $newName = mb_substr($newName, 0, 200, 'UTF-8');
            } else {
                $newName = substr($newName, 0, 200);
            }
            $wid = Db::nextNumericId('labor_workers');
            Db::setRow('labor_workers', (string) $wid, [
                'id' => $wid,
                'full_name' => $newName,
                'is_active' => 1,
            ]);
        }
        if ($wid <= 0) {
            continue;
        }
        $postedWorkerIds[] = $wid;

        $daily = (float) str_replace(',', '', (string) ($row['daily_wage'] ?? 0));
        $adv = (float) str_replace(',', '', (string) ($row['advance'] ?? 0));
        if ($daily < 0) {
            $daily = 0;
        }
        if ($adv < 0) {
            $adv = 0;
        }

        Db::setRow('labor_month_sheet_workers', Db::compositeKey([$ym, (string) $wid]), [
            'year_month' => $ym,
            'worker_id' => $wid,
            'sort_order' => $sort,
        ]);

        Db::setRow('labor_worker_month_settings', Db::compositeKey([(string) $wid, $ym]), [
            'worker_id' => $wid,
            'year_month' => $ym,
            'daily_wage' => $daily,
            'advance_draw' => $adv,
        ]);

        $days = $row['days'] ?? [];
        if (!is_array($days)) {
            $days = [];
        }
        $dayCount = 0;
        $otSum = 0.0;
        for ($d = $startD; $d <= $endD; $d++) {
            $cell = $days[(string) $d] ?? ($days[$d] ?? null);
            $present = 0;
            $ot = 0.0;
            if (is_array($cell)) {
                $present = !empty($cell['p']) ? 1 : 0;
                $ot = (float) str_replace(',', '', (string) ($cell['ot'] ?? 0));
                if ($ot < 0) {
                    $ot = 0;
                }
            }
            if ($present) {
                $dayCount++;
            }
            $otSum += $ot;
            $dateStr = $ym . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
            Db::setRow('labor_attendance_days', Db::compositeKey([(string) $wid, $dateStr]), [
                'worker_id' => $wid,
                'work_date' => $dateStr,
                'is_present' => $present,
                'ot_hours' => $ot,
            ]);
        }

        $otRate = ($daily / 8) * 1.5;
        $gross = round($dayCount * $daily + $otSum * $otRate, 2);
        $net = $half === 2 ? round($gross - $adv, 2) : $gross;

        $snapName = trim((string) ($row['new_name'] ?? ''));
        if ($snapName === '' && $wid > 0) {
            $wr = Db::row('labor_workers', (string) $wid);
            $snapName = $wr ? trim((string) ($wr['full_name'] ?? '')) : '';
        }
        if ($snapName === '') {
            $snapName = '—';
        }

        if ($wantClose) {
            $archiveSnapshots[] = [
                'worker_id' => $wid,
                'worker_name' => $snapName,
                'days_present' => $dayCount,
                'ot_hours' => round($otSum, 2),
                'daily_wage' => $daily,
                'advance_draw' => $adv,
                'gross_amount' => $gross,
                'net_amount' => $net,
            ];
        }

        $sort++;
    }

    if (count($postedWorkerIds) === 0) {
        foreach (Db::tableKeyed('labor_month_sheet_workers') as $pk => $row) {
            if (($row['year_month'] ?? '') === $ym) {
                Db::deleteRow('labor_month_sheet_workers', (string) $pk);
            }
        }
    } else {
        foreach (Db::tableKeyed('labor_month_sheet_workers') as $pk => $row) {
            if (($row['year_month'] ?? '') !== $ym) {
                continue;
            }
            $w = (int) ($row['worker_id'] ?? 0);
            if ($w > 0 && !in_array($w, $postedWorkerIds, true)) {
                Db::deleteRow('labor_month_sheet_workers', (string) $pk);
            }
        }
    }

    if ($wantClose) {
        if (count($archiveSnapshots) === 0) {
            throw new RuntimeException('close_no_rows');
        }
        $sumGross = 0.0;
        $sumNet = 0.0;
        foreach ($archiveSnapshots as $ln) {
            $sumGross += (float) $ln['gross_amount'];
            $sumNet += (float) $ln['net_amount'];
        }
        $sumGross = round($sumGross, 2);
        $sumNet = round($sumNet, 2);
        $wcount = count($archiveSnapshots);

        $docNo = LaborPayroll::nextDocNumber($ym);
        $archiveId = Db::nextNumericId('labor_payroll_archive');

        $archRow = [
            'id' => $archiveId,
            'doc_number' => $docNo,
            'period_ym' => $ym,
            'period_half' => $half,
            'day_start' => $startD,
            'day_end' => $endD,
            'total_gross' => $sumGross,
            'total_net' => $sumNet,
            'worker_count' => $wcount,
            'closed_at' => date('Y-m-d H:i:s'),
        ];
        if ($closedBy > 0) {
            $archRow['closed_by'] = $closedBy;
        }
        Db::setRow('labor_payroll_archive', (string) $archiveId, $archRow);

        $lineBase = Db::nextNumericId('labor_payroll_archive_lines');
        $li = 0;
        foreach ($archiveSnapshots as $ln) {
            $widL = (int) $ln['worker_id'];
            $wname = (string) $ln['worker_name'];
            if (function_exists('mb_substr')) {
                $wname = mb_substr($wname, 0, 200, 'UTF-8');
            } else {
                $wname = substr($wname, 0, 200);
            }
            $dp = (int) $ln['days_present'];
            $oth = (float) $ln['ot_hours'];
            $dw = (float) $ln['daily_wage'];
            $ad = (float) $ln['advance_draw'];
            $gr = (float) $ln['gross_amount'];
            $nt = (float) $ln['net_amount'];
            $lineId = $lineBase + $li;
            $li++;
            Db::setRow('labor_payroll_archive_lines', (string) $lineId, [
                'id' => $lineId,
                'archive_id' => $archiveId,
                'worker_id' => $widL > 0 ? $widL : 0,
                'worker_name' => $wname,
                'days_present' => $dp,
                'ot_hours' => $oth,
                'daily_wage' => $dw,
                'advance_draw' => $ad,
                'gross_amount' => $gr,
                'net_amount' => $nt,
            ]);
        }

        if (count($postedWorkerIds) > 0) {
            foreach (Db::tableKeyed('labor_attendance_days') as $pk => $att) {
                $widA = (int) ($att['worker_id'] ?? 0);
                $wd = (string) ($att['work_date'] ?? '');
                if (!in_array($widA, $postedWorkerIds, true)) {
                    continue;
                }
                if ($wd >= $dateFrom && $wd <= $dateTo) {
                    Db::deleteRow('labor_attendance_days', (string) $pk);
                }
            }
        }
    }
} catch (Throwable $e) {
    labor_payroll_redirect($back, ['month' => $ym, 'half' => $half, 'save_err' => 1]);
    exit;
}

if ($wantClose) {
    labor_payroll_redirect($back, ['month' => $ym, 'half' => $half, 'closed' => 1]);
}

labor_payroll_redirect($back, ['month' => $ym, 'half' => $half, 'saved' => 1]);
