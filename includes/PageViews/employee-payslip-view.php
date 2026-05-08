<?php
declare(strict_types=1);


require_once __DIR__ . '/_page_root.php';
use Theelincon\Rtdb\Db;

session_start();
require_once THEELINCON_ROOT . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$requestId = (int) ($_GET['request_id'] ?? 0);
$req = $requestId > 0 ? Db::row('employee_payslip_requests', (string) $requestId) : null;
if ($req === null || (string) ($req['status'] ?? '') !== 'approved') {
    exit('ไม่พบข้อมูลสลิปหรือยังไม่อนุมัติ');
}

$me = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['role'] ?? 'user');
$ownerId = (int) ($req['employee_user_id'] ?? 0);
if (!in_array($role, ['admin', 'Accounting'], true) && $me !== $ownerId) {
    exit('Access denied');
}

$employee = Db::row('users', (string) $ownerId) ?? [];
$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', false);
$company = $companies[0] ?? [];

function ps_code(array $employee): string {
    $c = trim((string) ($employee['user_code'] ?? ''));
    if ($c === '') { $c = 'UID-' . (int) ($employee['userid'] ?? 0); }
    return strtoupper($c);
}
function ps_date(string $date): string {
    $ts = strtotime($date);
    return $ts !== false ? date('d/m/Y', $ts) : $date;
}

$salaryBase = (float) ($req['salary_base'] ?? 0);
$incomeTotal = (float) ($req['income_total'] ?? 0);
$socialSecurity = (float) ($req['social_security'] ?? 0);
$deductTotal = (float) ($req['deduct_total'] ?? 0);
$netTotal = (float) ($req['net_total'] ?? 0);
$period = (string) ($req['period'] ?? '');
$payDate = (string) ($req['pay_date'] ?? '');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip PDF</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{font-family:'Sarabun',sans-serif;background:#ececec}
        .sheet{width:297mm;min-height:210mm;margin:0 auto;background:#fff;padding:10mm 12mm;display:flex;flex-direction:column;border-top:6px solid #fd7e14}
        .net{margin-top:auto;border:2px solid #fd7e14;border-radius:12px;padding:12px 14px;display:flex;justify-content:space-between}
        @media print{
            @page{size:A4 landscape;margin:0}
            .no-print,.navbar{display:none!important}
            html,body{margin:0;padding:0;background:#fff}
            .sheet{width:100%;height:210mm;min-height:210mm;box-sizing:border-box}
        }
    </style>
</head>
<body>
<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>
<div class="container py-3 no-print text-end"><button class="btn btn-warning" onclick="window.print()">พิมพ์ / PDF</button></div>
<div class="sheet">
    <div class="d-flex justify-content-between mb-2">
        <div>
            <div class="fw-bold"><?= htmlspecialchars((string) ($company['name'] ?? 'THEELIN CON CO.,LTD.'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="small text-muted"><?= nl2br(htmlspecialchars((string) ($company['address'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
        </div>
        <div class="text-end">
            <div class="fw-bold text-warning">ใบแจ้งเงินได้ (Payslip)</div>
            <div class="small text-muted">งวด: <?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </div>
    <div class="row g-2 mb-3">
        <div class="col-4 border rounded p-2"><div class="small text-muted">รหัสพนักงาน</div><div class="fw-bold"><?= htmlspecialchars(ps_code($employee), ENT_QUOTES, 'UTF-8') ?></div></div>
        <div class="col-5 border rounded p-2"><div class="small text-muted">ชื่อพนักงาน</div><div class="fw-bold"><?= htmlspecialchars(trim((string) ($employee['fname'] ?? '') . ' ' . (string) ($employee['lname'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div></div>
        <div class="col-3 border rounded p-2"><div class="small text-muted">วันที่จ่าย</div><div class="fw-bold"><?= htmlspecialchars(ps_date($payDate), ENT_QUOTES, 'UTF-8') ?></div></div>
    </div>
    <div class="row g-3">
        <div class="col-6">
            <table class="table table-bordered">
                <thead><tr><th>รายได้</th><th class="text-end">จำนวนเงิน</th></tr></thead>
                <tbody><tr><td>เงินเดือน</td><td class="text-end"><?= number_format($salaryBase,2) ?></td></tr><tr><td class="fw-bold">รวมรายได้</td><td class="text-end fw-bold"><?= number_format($incomeTotal,2) ?></td></tr></tbody>
            </table>
        </div>
        <div class="col-6">
            <table class="table table-bordered">
                <thead><tr><th>รายการหัก</th><th class="text-end">จำนวนเงิน</th></tr></thead>
                <tbody><tr><td>เงินประกันสังคม</td><td class="text-end"><?= number_format($socialSecurity,2) ?></td></tr><tr><td class="fw-bold">รวมหัก</td><td class="text-end fw-bold"><?= number_format($deductTotal,2) ?></td></tr></tbody>
            </table>
        </div>
    </div>
    <div class="net"><div class="fw-bold fs-5">ยอดรวมสุทธิ</div><div class="fw-bold fs-3">฿ <?= number_format($netTotal,2) ?></div></div>
</div>
</body>
</html>
