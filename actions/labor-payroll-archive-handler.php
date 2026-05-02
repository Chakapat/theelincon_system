<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/connect_database.php';

use Theelincon\Rtdb\Db;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$hist = app_path('pages/labor-payroll/labor-payroll-history.php');
$action = $_POST['action'] ?? '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action !== '' && !csrf_verify_request()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden');
}

function labor_archive_redirect(string $base, array $query): void
{
    $q = http_build_query($query);
    header('Location: ' . $base . ($q !== '' ? '?' . $q : ''));
    exit;
}

/**
 * @return array{0: float, 1: float} [gross, net]
 */
function labor_archive_calc_line(float $daily, float $adv, int $days, float $ot, int $periodHalf): array
{
    $otRate = ($daily / 8) * 1.5;
    $gross = round($days * $daily + $ot * $otRate, 2);
    $net = $periodHalf === 2 ? round($gross - $adv, 2) : $gross;

    return [$gross, $net];
}

if ($action === 'delete') {
    $aid = (int) ($_POST['archive_id'] ?? 0);
    if ($aid > 0) {
        Db::deleteWhereEquals('labor_payroll_archive_lines', 'archive_id', (string) $aid);
        Db::deleteRow('labor_payroll_archive', (string) $aid);
    }
    labor_archive_redirect($hist, ['deleted' => 1]);
}

if ($action !== 'save') {
    labor_archive_redirect($hist, []);
}

$aid = (int) ($_POST['archive_id'] ?? 0);
if ($aid <= 0) {
    labor_archive_redirect($hist, ['err' => 1]);
}

$arch = Db::row('labor_payroll_archive', (string) $aid);
if (!$arch) {
    labor_archive_redirect($hist, ['err' => 1]);
}

$periodHalf = (int) ($arch['period_half'] ?? 1) === 2 ? 2 : 1;
$linesIn = $_POST['lines'] ?? [];
if (!is_array($linesIn)) {
    $linesIn = [];
}

try {
    Db::deleteWhereEquals('labor_payroll_archive_lines', 'archive_id', (string) $aid);

    $sumGross = 0.0;
    $sumNet = 0.0;
    $wcount = 0;

    $lineBase = Db::nextNumericId('labor_payroll_archive_lines');
    $li = 0;

    foreach ($linesIn as $row) {
        if (!is_array($row)) {
            continue;
        }
        $wname = trim((string) ($row['worker_name'] ?? ''));
        if ($wname === '') {
            continue;
        }
        if (function_exists('mb_substr')) {
            $wname = mb_substr($wname, 0, 200, 'UTF-8');
        } else {
            $wname = substr($wname, 0, 200);
        }
        $wid = (int) ($row['worker_id'] ?? 0);
        $days = (int) ($row['days_present'] ?? 0);
        if ($days < 0) {
            $days = 0;
        }
        $ot = (float) str_replace(',', '', (string) ($row['ot_hours'] ?? 0));
        if ($ot < 0) {
            $ot = 0;
        }
        $daily = (float) str_replace(',', '', (string) ($row['daily_wage'] ?? 0));
        $adv = (float) str_replace(',', '', (string) ($row['advance_draw'] ?? 0));
        if ($daily < 0) {
            $daily = 0;
        }
        if ($adv < 0) {
            $adv = 0;
        }

        [$gross, $net] = labor_archive_calc_line($daily, $adv, $days, $ot, $periodHalf);
        $sumGross += $gross;
        $sumNet += $net;
        $wcount++;

        $widBind = $wid > 0 ? $wid : 0;
        $lineId = $lineBase + $li;
        $li++;
        Db::setRow('labor_payroll_archive_lines', (string) $lineId, [
            'id' => $lineId,
            'archive_id' => $aid,
            'worker_id' => $widBind,
            'worker_name' => $wname,
            'days_present' => $days,
            'ot_hours' => $ot,
            'daily_wage' => $daily,
            'advance_draw' => $adv,
            'gross_amount' => $gross,
            'net_amount' => $net,
        ]);
    }

    if ($wcount === 0) {
        throw new RuntimeException('no_lines');
    }

    $sumGross = round($sumGross, 2);
    $sumNet = round($sumNet, 2);

    Db::mergeRow('labor_payroll_archive', (string) $aid, [
        'total_gross' => $sumGross,
        'total_net' => $sumNet,
        'worker_count' => $wcount,
    ]);
} catch (Throwable $e) {
    labor_archive_redirect(app_path('pages/labor-payroll/labor-payroll-archive-edit.php'), ['id' => $aid, 'save_err' => 1]);
    exit;
}

labor_archive_redirect(app_path('pages/labor-payroll/labor-payroll-archive-view.php'), ['id' => $aid, 'saved' => 1]);
