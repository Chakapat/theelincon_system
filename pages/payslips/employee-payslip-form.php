<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

if (!user_is_finance_role()) {
    header('Location: ' . app_path('index.php'));
    exit();
}

$users = Db::tableRows('users');
usort($users, static function (array $a, array $b): int {
    $an = trim((string) ($a['fname'] ?? '') . ' ' . (string) ($a['lname'] ?? ''));
    $bn = trim((string) ($b['fname'] ?? '') . ' ' . (string) ($b['lname'] ?? ''));

    return strcmp($an, $bn);
});

$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', false);
$company = $companies[0] ?? [];

$uid = (int) ($_GET['id'] ?? 0);
$period = trim((string) ($_GET['period'] ?? date('Y-m')));
$payDate = trim((string) ($_GET['pay_date'] ?? date('Y-m-d')));

if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
    $payDate = date('Y-m-d');
}

$employee = $uid > 0 ? Db::rowByIdField('users', $uid, 'userid') : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_request()) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create_payslip_request') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $periodPost = trim((string) ($_POST['period'] ?? date('Y-m')));
        $payDatePost = trim((string) ($_POST['pay_date'] ?? date('Y-m-d')));
        if ($employeeId > 0 && preg_match('/^\d{4}-\d{2}$/', $periodPost) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDatePost)) {
            $emp = Db::rowByIdField('users', $employeeId, 'userid');
            if ($emp !== null) {
                $salary = (float) ($emp['salary_base'] ?? 0);
                $income = round($salary, 2);
                $ss = round(min($salary * 0.05, 875.0), 2);
                $deduct = $ss;
                $net = round($income - $deduct, 2);
                $rid = Db::nextNumericId('employee_payslip_requests', 'id');
                Db::setRow('employee_payslip_requests', (string) $rid, [
                    'id' => $rid,
                    'employee_user_id' => $employeeId,
                    'period' => $periodPost,
                    'pay_date' => $payDatePost,
                    'salary_base' => $salary,
                    'income_total' => $income,
                    'social_security' => $ss,
                    'deduct_total' => $deduct,
                    'net_total' => $net,
                    'status' => 'approved',
                    'requested_by' => (int) ($_SESSION['user_id'] ?? 0),
                    'requested_at' => date('Y-m-d H:i:s'),
                    'line_approval_token' => '',
                    'line_sent_at' => '',
                    'line_decision' => 'auto_approved',
                    'line_decided_at' => date('Y-m-d H:i:s'),
                ]);
                $reqAfter = Db::row('employee_payslip_requests', (string) $rid);
                $empLabel = trim((string) (($emp['fname'] ?? '') . ' ' . ($emp['lname'] ?? '')));
                tnc_audit_log('create', 'employee_payslip_request', (string) $rid, $empLabel !== '' ? $empLabel : ('พนักงาน #' . $employeeId), [
                    'source' => 'employee-payslip-form.php',
                    'action' => 'create_payslip_request',
                    'after' => $reqAfter,
                    'meta' => [
                        'period' => $periodPost,
                        'pay_date' => $payDatePost,
                        'employee_user_id' => $employeeId,
                    ],
                ]);
                header('Location: ' . app_path('pages/payslips/employee-payslip-request-list.php') . '?created=1');
                exit();
            }
        }
        header('Location: ' . app_path('pages/payslips/employee-payslip.php') . '?err=1');
        exit();
    }
}

function payslipEmployeeCode(array $employee): string
{
    $code = trim((string) ($employee['user_code'] ?? ''));
    if ($code === '') {
        $code = 'UID-' . (int) ($employee['userid'] ?? 0);
    }

    return strtoupper($code);
}

function payslipMonthThai(string $period): string
{
    $months = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
    ];
    $year = (int) substr($period, 0, 4);
    $month = (int) substr($period, 5, 2);
    $thaiMonth = $months[$month] ?? $period;

    return $thaiMonth . ' ' . ($year + 543);
}

function fmtDate(string $date): string
{
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }

    return date('d/m/Y', $ts);
}

$salaryBase = (float) (($employee['salary_base'] ?? 0));
$incomeTotal = round($salaryBase, 2);
$socialSecurity = round(min($salaryBase * 0.05, 875.0), 2);
$deductTotal = $socialSecurity;
$netTotal = round($incomeTotal - $deductTotal, 2);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ใบแจ้งเงินได้ (Payslip) | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #ececec; }
        .payslip-sheet {
            width: 297mm;
            min-height: 210mm;
            margin: 0 auto 20px;
            background: #fff;
            padding: 10mm 12mm;
            box-shadow: 0 6px 24px rgba(0,0,0,0.12);
            border-top: 6px solid #fd7e14;
            color: #1f2937;
            display: flex;
            flex-direction: column;
        }
        .sheet-head {
            border: 1px solid #f2d7bf;
            background: linear-gradient(135deg, #fff8f1 0%, #fff 100%);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 10px;
        }
        .sheet-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: #c25f0a;
            line-height: 1.2;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 10px;
        }
        .meta-card {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 8px 10px;
            background: #fff;
        }
        .meta-label { font-size: 12px; color: #6b7280; margin-bottom: 2px; }
        .meta-value { font-size: 15px; font-weight: 700; }
        .section-title {
            font-size: 13px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 6px;
        }
        .table-wrap {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }
        .money { font-variant-numeric: tabular-nums; }
        .table { margin-bottom: 0; }
        .table thead th {
            font-size: 13px;
            background: #f9fafb;
            border-bottom-width: 1px;
        }
        .table td { font-size: 14px; }
        .summary-row td {
            background: #fff7ed;
            font-weight: 700;
        }
        .net-box {
            margin-top: 10px;
            border-radius: 12px;
            background: linear-gradient(135deg, #fd7e14 0%, #f97316 100%);
            color: #fff;
            padding: 12px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .net-label { font-size: 1.1rem; font-weight: 700; }
        .net-value { font-size: 1.9rem; font-weight: 800; }
        @media print {
            @page { size: A4 landscape; margin: 0; }
            html, body {
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .no-print { display: none !important; }
            .navbar { display: none !important; }
            .payslip-sheet {
                width: 100%;
                height: 210mm;
                min-height: 210mm;
                margin: 0;
                padding: 10mm 12mm;
                box-sizing: border-box;
                box-shadow: none;
                border-top: 6px solid #fd7e14;
            }
            .sheet-head,
            .meta-card,
            .table-wrap {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .net-box {
                margin-top: auto;
                border: 2px solid #fd7e14;
                background: #fff !important;
                color: #1f2937 !important;
            }
            .net-value {
                color: #c25f0a !important;
            }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 no-print">
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h4 class="fw-bold mb-0"><i class="bi bi-receipt-cutoff text-warning me-2"></i>ใบแจ้งเงินได้ (Payslip)</h4>
                <a href="<?= htmlspecialchars(app_path('pages/payslips/employee-payslip-request-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark">รายการคำขอสลิป</a>
            </div>
            <?php if (isset($_GET['err'])): ?><div class="alert alert-danger py-2">สร้างคำขอไม่สำเร็จ กรุณาตรวจสอบข้อมูล</div><?php endif; ?>
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">พนักงาน</label>
                    <select name="id" class="form-select" required>
                        <option value="">-- เลือกพนักงาน --</option>
                        <?php foreach ($users as $u): ?>
                            <?php $idOpt = (int) ($u['userid'] ?? 0); ?>
                            <?php if ($idOpt <= 0) { continue; } ?>
                            <option value="<?= $idOpt ?>" <?= $uid === $idOpt ? 'selected' : '' ?>>
                                <?= htmlspecialchars(strtoupper((string) ($u['user_code'] ?? '')) . ' | ' . trim((string) ($u['fname'] ?? '') . ' ' . (string) ($u['lname'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">งวดการจ่าย</label>
                    <input type="month" name="period" class="form-control" value="<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">วันที่จ่าย</label>
                    <input type="date" name="pay_date" class="form-control" value="<?= htmlspecialchars($payDate, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-12"><button type="submit" class="btn btn-dark rounded-pill px-4">แสดงตัวอย่างก่อนขอ</button></div>
            </form>
        </div>
    </div>
</div>

<?php if ($employee): ?>
<div class="payslip-sheet">
    <div class="sheet-head">
        <div class="row align-items-start">
            <div class="col-8">
                <div class="d-flex align-items-start gap-3">
                <?php if (!empty($company['logo'])): ?>
                    <img src="<?= htmlspecialchars(upload_logo_url((string) $company['logo']), ENT_QUOTES, 'UTF-8') ?>" alt="logo" style="max-height:72px;max-width:180px;object-fit:contain;">
                <?php endif; ?>
                <div>
                        <div class="fw-bold fs-5"><?= htmlspecialchars((string) ($company['name'] ?? 'THEELIN CON CO.,LTD.'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="small text-muted"><?= nl2br(htmlspecialchars((string) ($company['address'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-4 text-end">
                <div class="sheet-title">ใบแจ้งเงินได้ (Payslip)</div>
                <div class="small text-muted mt-1">งวดการจ่าย: <?= htmlspecialchars(payslipMonthThai($period), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>

    <div class="meta-grid">
        <div class="meta-card">
            <div class="meta-label">รหัสพนักงาน</div>
            <div class="meta-value"><?= htmlspecialchars(payslipEmployeeCode((array) $employee), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="meta-card">
            <div class="meta-label">ชื่อพนักงาน</div>
            <div class="meta-value"><?= htmlspecialchars(trim((string) ($employee['fname'] ?? '') . ' ' . (string) ($employee['lname'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="meta-card">
            <div class="meta-label">วันที่จ่าย</div>
            <div class="meta-value"><?= htmlspecialchars(fmtDate($payDate), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="section-title">ส่วนรายได้</div>
            <div class="table-wrap">
                <table class="table table-bordered mb-0" id="tncPayslipFormIncomeTable">
                    <thead class="table-light">
                        <tr>
                            <th>รายได้</th>
                            <th class="text-end">จำนวนเงิน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>เงินเดือน</td>
                            <td class="text-end money"><?= number_format($salaryBase, 2) ?></td>
                        </tr>
                        <tr class="summary-row">
                            <td>รวมรายได้</td>
                            <td class="text-end money"><?= number_format($incomeTotal, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col-md-6">
            <div class="section-title">ส่วนรายการหัก</div>
            <div class="table-wrap">
                <table class="table table-bordered mb-0" id="tncPayslipFormDeductTable">
                    <thead class="table-light">
                        <tr>
                            <th>รายการหัก</th>
                            <th class="text-end">จำนวนเงิน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>เงินประกันสังคม</td>
                            <td class="text-end money"><?= number_format($socialSecurity, 2) ?></td>
                        </tr>
                        <tr class="summary-row">
                            <td>รวมหัก</td>
                            <td class="text-end money"><?= number_format($deductTotal, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="net-box mt-auto">
        <div class="net-label">ยอดรวมสุทธิ</div>
        <div class="net-value money">฿ <?= number_format($netTotal, 2) ?></div>
    </div>
    <form method="post" class="no-print mt-3 d-flex justify-content-end gap-2">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="create_payslip_request">
        <input type="hidden" name="employee_id" value="<?= (int) ($employee['userid'] ?? 0) ?>">
        <input type="hidden" name="period" value="<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="pay_date" value="<?= htmlspecialchars($payDate, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-success"><i class="bi bi-plus-circle me-1"></i>เพิ่มขอใบสลิปเงินเดือน</button>
    </form>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script>
(function ($) {
    if (typeof window.TncLiveDT === 'undefined' || !$ || !$.fn.DataTable) return;
    var mini = { paging: false, searching: false, info: false, lengthChange: false, ordering: false };
    if ($('#tncPayslipFormIncomeTable').length) TncLiveDT.init('#tncPayslipFormIncomeTable', mini);
    if ($('#tncPayslipFormDeductTable').length) TncLiveDT.init('#tncPayslipFormDeductTable', mini);
})(jQuery);
</script>
</body>
</html>
