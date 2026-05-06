<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_is_finance_role()) {
    http_response_code(403);
    exit('Access denied');
}
$isAdmin = user_is_admin_role();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['delete_attendance_id'])) {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Access denied');
    }
    if (!csrf_verify_request()) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
    tnc_require_post_confirm_password();
    $deleteId = (int) ($_POST['delete_attendance_id'] ?? 0);
    if ($deleteId > 0) {
        $attPk = Db::pkForLogicalId('attendance_logs', $deleteId);
        $attSnap = Db::row('attendance_logs', $attPk);
        Db::deleteRow('attendance_logs', $attPk);
        tnc_audit_log('delete', 'attendance_log', (string) $deleteId, 'รายการ #' . $deleteId, [
            'source' => 'attendance-list.php',
            'action' => 'delete_attendance',
            'before' => $attSnap,
        ]);
    }
    header('Location: ' . app_path('pages/attendance/attendance-list.php') . '?deleted=1');
    exit;
}

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$eventType = trim((string) ($_GET['event_type'] ?? ''));
$keyword = trim((string) ($_GET['keyword'] ?? ''));

/**
 * @return array{date_key:string,date_text:string,time_text:string}
 */
function attendance_datetime_thai(string $eventAtRaw, string $eventDateRaw = ''): array
{
    $utc = new DateTimeZone('UTC');
    $thai = new DateTimeZone('Asia/Bangkok');
    $dt = null;

    if ($eventAtRaw !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $eventAtRaw, $utc);
        if ($dt instanceof DateTimeImmutable) {
            $dt = $dt->setTimezone($thai);
        } else {
            $ts = strtotime($eventAtRaw);
            if ($ts !== false) {
                $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($thai);
            }
        }
    }

    if (!($dt instanceof DateTimeImmutable) && $eventDateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDateRaw) === 1) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $eventDateRaw, $thai);
    }

    if ($dt instanceof DateTimeImmutable) {
        return [
            'date_key' => $dt->format('Y-m-d'),
            'date_text' => $dt->format('d/m/Y'),
            'time_text' => $dt->format('G:i'),
        ];
    }

    return [
        'date_key' => '',
        'date_text' => '-',
        'time_text' => '-',
    ];
}

$isDate = static function (string $v): bool {
    return $v === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
};
if (!$isDate($dateFrom)) {
    $dateFrom = '';
}
if (!$isDate($dateTo)) {
    $dateTo = '';
}
if (!in_array($eventType, ['', 'checkin', 'checkout'], true)) {
    $eventType = '';
}

$rows = Db::tableRows('attendance_logs');
usort($rows, static function (array $a, array $b): int {
    return strcmp((string) ($b['event_at'] ?? ''), (string) ($a['event_at'] ?? ''));
});

$filtered = [];
foreach ($rows as $row) {
    $eventAtRaw = trim((string) ($row['event_at'] ?? ''));
    $eventDateRaw = substr((string) ($row['event_date'] ?? ''), 0, 10);
    $dtThai = attendance_datetime_thai($eventAtRaw, $eventDateRaw);
    $d = $dtThai['date_key'];

    if ($dateFrom !== '' && $d !== '' && strcmp($d, $dateFrom) < 0) {
        continue;
    }
    if ($dateTo !== '' && $d !== '' && strcmp($d, $dateTo) > 0) {
        continue;
    }
    if ($eventType !== '' && (string) ($row['event_type'] ?? '') !== $eventType) {
        continue;
    }
    if ($keyword !== '') {
        $employeeName = trim((string) ($row['employee_name'] ?? ''));
        $employeeCode = trim((string) ($row['employee_code'] ?? ''));
        $haystack = mb_strtolower($employeeCode . ' ' . $employeeName, 'UTF-8');
        if (mb_strpos($haystack, mb_strtolower($keyword, 'UTF-8')) === false) {
            continue;
        }
    }
    $filtered[] = $row;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการเข้าออกงาน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background: #f8fafc; font-family: 'Sarabun', sans-serif; }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4">
    <?php if (!empty($_GET['deleted'])): ?>
        <div class="alert alert-success rounded-3 py-2">ลบรายการลงเวลาเรียบร้อยแล้ว</div>
    <?php elseif (isset($_GET['error']) && $_GET['error'] === 'confirm_password_required'): ?>
        <div class="alert alert-warning rounded-3 py-2">กรุณากรอกรหัสผ่านของคุณเพื่อยืนยันการลบ</div>
    <?php elseif (isset($_GET['error']) && $_GET['error'] === 'confirm_password_invalid'): ?>
        <div class="alert alert-danger rounded-3 py-2">รหัสผ่านไม่ถูกต้อง</div>
    <?php endif; ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h4 class="fw-bold mb-0"><i class="bi bi-qr-code-scan me-2 text-warning"></i>รายการลงเวลาเข้า/ออกงาน</h4>
        <div class="small text-muted">ทั้งหมด <?= number_format(count($filtered)) ?> รายการ</div>
    </div>

    <form method="get" class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-2">
                    <label class="form-label small mb-1">จากวันที่</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">ถึงวันที่</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">ประเภท</label>
                    <select name="event_type" class="form-select">
                        <option value="">ทั้งหมด</option>
                        <option value="checkin" <?= $eventType === 'checkin' ? 'selected' : '' ?>>เข้างาน</option>
                        <option value="checkout" <?= $eventType === 'checkout' ? 'selected' : '' ?>>ออกงาน</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">ค้นหา</label>
                    <input type="text" name="keyword" class="form-control" value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>" placeholder="รหัสพนักงาน หรือ ชื่อพนักงาน">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end mt-2">
                    <a href="<?= htmlspecialchars(app_path('pages/attendance/attendance-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill">ล้างตัวกรอง</a>
                    <button type="submit" class="btn btn-warning rounded-pill">ค้นหา</button>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="attendanceLogTable" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">วันที่</th>
                        <th>เวลา</th>
                        <th>ประเภท</th>
                        <th class="pe-3">พนักงาน</th>
                        <?php if ($isAdmin): ?>
                            <th class="pe-3 text-end">จัดการ</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($filtered) === 0): ?>
                        <tr>
                            <td colspan="<?= $isAdmin ? '5' : '4' ?>" class="text-center text-muted py-5">ยังไม่มีข้อมูลตามเงื่อนไข</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($filtered as $row): ?>
                            <?php
                            $type = (string) ($row['event_type'] ?? '');
                            $badge = $type === 'checkin' ? 'success' : 'danger';
                            $typeLabel = $type === 'checkin' ? 'เข้างาน' : 'ออกงาน';
                            $employeeName = trim((string) ($row['employee_name'] ?? ''));
                            $employeeCode = trim((string) ($row['employee_code'] ?? ''));
                            $employeeCodeUpper = $employeeCode !== '' ? mb_strtoupper($employeeCode, 'UTF-8') : '-';
                            $dtThai = attendance_datetime_thai(
                                trim((string) ($row['event_at'] ?? '')),
                                substr((string) ($row['event_date'] ?? ''), 0, 10)
                            );
                            $eventDateText = $dtThai['date_text'];
                            $eventTimeText = $dtThai['time_text'];
                            ?>
                            <tr>
                                <td class="ps-3"><?= htmlspecialchars($eventDateText, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($eventTimeText, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="badge text-bg-<?= $badge ?>"><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="pe-3"><?= htmlspecialchars($employeeCodeUpper . ' - ' . ($employeeName !== '' ? $employeeName : '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                <?php if ($isAdmin): ?>
                                    <td class="pe-3 text-end">
                                        <form method="post" class="d-inline tnc-attendance-delete-form">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="delete_attendance_id" value="<?= (int) ($row['id'] ?? 0) ?>">
                                            <input type="hidden" name="confirm_password" value="" autocomplete="new-password">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill">
                                                <i class="bi bi-trash3"></i> ลบ
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function ($) {
    if ($('#attendanceLogTable tbody tr td[colspan]').length === 0 && $('#attendanceLogTable tbody tr').length) {
        $('#attendanceLogTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
            columnDefs: <?= json_encode($isAdmin ? [['targets' => [4], 'orderable' => false, 'searchable' => false]] : []) ?>
        });
    }
    var u = <?= json_encode(app_path('actions/live-datasets.php?dataset=mirror_table&table=attendance_logs'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var c = '';
    setInterval(function () {
        if (document.hidden) return;
        fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.ok) return;
            if (c === '') { c = d.checksum; return; }
            if (d.checksum !== c) window.location.reload();
        }).catch(function () {});
    }, 6000);
})(jQuery);
</script>
<script>
(function () {
    if (typeof Swal === 'undefined') return;
    document.querySelectorAll('.tnc-attendance-delete-form').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            Swal.fire({
                title: 'ยืนยันการลบ?',
                html: 'กรอก<strong>รหัสผ่านของคุณ</strong>เพื่อยืนยันการลบรายการลงเวลา',
                icon: 'warning',
                input: 'password',
                showCancelButton: true,
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc3545',
                focusCancel: true,
                preConfirm: function (pw) {
                    if (!pw || !String(pw).trim()) {
                        Swal.showValidationMessage('กรุณากรอกรหัสผ่าน');
                        return false;
                    }
                    return pw;
                }
            }).then(function (res) {
                if (!res.isConfirmed || !res.value) return;
                var hid = form.querySelector('input[name="confirm_password"]');
                if (hid) hid.value = res.value;
                form.submit();
            });
        });
    });
})();
</script>
</body>
</html>

