<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/connect_database.php';
require_once __DIR__ . '/../includes/tnc_action_response.php';

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
    $url = $base . ($q !== '' ? '?' . $q : '');
    tnc_action_redirect($url);
}

function labor_archive_parse_half_days_present(string $raw, int $periodLen): float
{
    $v = (float) str_replace([',', ' ', "\u{00A0}"], '', $raw);
    if (!is_finite($v) || $v < 0) {
        $v = 0.0;
    }
    $v = round($v * 2) / 2.0;
    $cap = (float) max(0, $periodLen);

    return $v > $cap ? $cap : $v;
}

/**
 * @return array{0: float, 1: float} [gross, net]
 */
function labor_archive_calc_line(float $daily, float $adv, float $days, float $ot, int $periodHalf): array
{
    if ($days <= 0) {
        $ot = 0.0;
    }
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

$ymPost = trim((string) ($_POST['period_ym'] ?? ''));
$ymNew = preg_match('/^\d{4}-\d{2}$/', $ymPost) ? $ymPost : (string) ($arch['period_ym'] ?? date('Y-m'));
$tsYm = strtotime($ymNew . '-01');
if ($tsYm === false) {
    labor_archive_redirect($hist, ['save_err' => 1, 'edit_open_id' => $aid]);
}
$halfNew = (int) ($_POST['period_half'] ?? 0) === 2 ? 2 : 1;
$dim = (int) date('t', $tsYm);
$startD = $halfNew === 1 ? 1 : 16;
$endD = $halfNew === 1 ? min(15, $dim) : $dim;

$periodHalf = $halfNew;
$linesIn = $_POST['lines'] ?? [];
if (!is_array($linesIn)) {
    $linesIn = [];
}

$periodLenArchive = $endD - $startD + 1;

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
        $days = labor_archive_parse_half_days_present((string) ($row['days_present'] ?? ''), $periodLenArchive);
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
        'period_ym' => $ymNew,
        'period_half' => $halfNew,
        'day_start' => $startD,
        'day_end' => $endD,
        'total_gross' => $sumGross,
        'total_net' => $sumNet,
        'worker_count' => $wcount,
    ]);
} catch (Throwable $e) {
    labor_archive_redirect($hist, ['save_err' => 1, 'edit_open_id' => $aid]);
    exit;
}

labor_archive_redirect($hist, ['saved' => 1, 'open_id' => $aid]);
