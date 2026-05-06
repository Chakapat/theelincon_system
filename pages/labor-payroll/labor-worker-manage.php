<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

use Theelincon\Rtdb\Db;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$handler = app_path('actions/labor-payroll-handler.php');
$ym = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : date('Y-m');
$half = (int) ($_GET['half'] ?? 1) === 2 ? 2 : 1;

$workerGroups = Db::filter('labor_worker_groups', static fn ($g) => !empty($g['is_active']));
Db::sortRows($workerGroups, 'name');
$selectedGroupId = (int) ($_GET['group_id'] ?? 0);
$groupNameById = [];
foreach ($workerGroups as $g) {
    $gid = (int) ($g['id'] ?? 0);
    if ($gid > 0) {
        $groupNameById[$gid] = (string) ($g['name'] ?? '');
    }
}
$workerRows = Db::filter('labor_workers', static function (array $w) use ($selectedGroupId): bool {
    if (empty($w['is_active'])) {
        return false;
    }
    if ($selectedGroupId <= 0) {
        return true;
    }
    return (int) ($w['group_id'] ?? 0) === $selectedGroupId;
});
/** ลำดับการบันทึกในเดือนนี้ (เดียวกับหน้าคำนวณค่าแรง) — labor_month_sheet_workers.sort_order */
$sheetSortByWorkerId = [];
foreach (Db::filter('labor_month_sheet_workers', static fn (array $r): bool => (string) ($r['year_month'] ?? '') === $ym) as $r) {
    $wid = (int) ($r['worker_id'] ?? 0);
    if ($wid > 0) {
        $sheetSortByWorkerId[$wid] = (int) ($r['sort_order'] ?? 0);
    }
}
usort($workerRows, static function (array $a, array $b) use ($sheetSortByWorkerId): int {
    $ga = (int) ($a['group_id'] ?? 0);
    $gb = (int) ($b['group_id'] ?? 0);
    if ($ga !== $gb) {
        return $ga <=> $gb;
    }
    $ida = (int) ($a['id'] ?? 0);
    $idb = (int) ($b['id'] ?? 0);
    // ไม่มีแถวใน labor_month_sheet_workers เดือนนี้ → ไว้ท้ายรายการ เรียงตาม id
    $missingRank = 1_000_000;
    $sa = $sheetSortByWorkerId[$ida] ?? ($missingRank + $ida);
    $sb = $sheetSortByWorkerId[$idb] ?? ($missingRank + $idb);
    if ($sa !== $sb) {
        return $sa <=> $sb;
    }

    return $ida <=> $idb;
});
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการกลุ่ม/คนงาน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f6f8fb; }
        .manage-top-card { height: 100%; }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-3 pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h5 class="fw-bold mb-0"><i class="bi bi-people me-2 text-primary"></i>จัดการกลุ่มและคนงาน</h5>
            <div class="text-muted small">สร้างกลุ่ม / เพิ่มคนงานใหม่ — เลือกเดือนให้ตรงกับหน้าคำนวณค่าแรงที่จะแก้</div>
        </div>
        <div class="d-flex flex-column align-items-end gap-2">
            <?php
            $laborPeriodYm = $ym;
            $laborPeriodHalf = $half;
            $laborPeriodAction = app_path('pages/labor-payroll/labor-worker-manage.php');
            $laborPeriodPreserve = $selectedGroupId > 0 ? ['group_id' => $selectedGroupId] : [];
            $laborPeriodInputId = 'laborManageMonth';
            include dirname(__DIR__, 2) . '/components/labor-period-selector.php';
            ?>
            <a href="<?= htmlspecialchars(app_path('pages/labor-payroll/labor-payroll.php') . '?month=' . urlencode($ym) . '&half=' . $half, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-secondary rounded-pill"><i class="bi bi-arrow-left me-1"></i>กลับหน้าคำนวณค่าแรง</a>
        </div>
    </div>

    <div class="row g-3 align-items-stretch">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-3 manage-top-card">
                <div class="card-body">
                    <div class="small fw-semibold mb-2"><i class="bi bi-collection me-1 text-secondary"></i>สร้างกลุ่มคนงาน</div>
                    <form method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>" class="d-flex gap-2">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="create_group">
                        <input type="hidden" name="year_month" value="<?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="half" value="<?= (int) $half ?>">
                        <input type="hidden" name="return_to" value="manage">
                        <input type="text" class="form-control form-control-sm" name="group_name" placeholder="เช่น ชุดช่างโครงสร้าง A" required>
                        <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">สร้าง</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-3 manage-top-card">
                <div class="card-body">
                    <div class="small fw-semibold mb-2"><i class="bi bi-person-plus me-1 text-secondary"></i>เพิ่มคนงานใหม่</div>
                    <form method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>" class="row g-2 align-items-end">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="create_worker">
                        <input type="hidden" name="year_month" value="<?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="half" value="<?= (int) $half ?>">
                        <input type="hidden" name="return_to" value="manage">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">ชื่อ</label>
                            <input type="text" class="form-control form-control-sm" name="worker_name" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">ค่าแรง/วัน</label>
                            <input type="number" class="form-control form-control-sm text-end" name="daily_wage" min="0" step="0.01" value="0">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">เพศ</label>
                            <select class="form-select form-select-sm" name="gender">
                                <option value="ชาย">ชาย</option>
                                <option value="หญิง">หญิง</option>
                                <option value="อื่นๆ">อื่นๆ</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">กลุ่ม</label>
                            <select class="form-select form-select-sm" name="group_id" required>
                                <option value="">เลือกกลุ่ม</option>
                                <?php foreach ($workerGroups as $g): ?>
                                    <?php $gid = (int) ($g['id'] ?? 0); ?>
                                    <option value="<?= $gid ?>"><?= htmlspecialchars((string) ($g['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3"><i class="bi bi-plus-lg me-1"></i>เพิ่มคนงาน</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3 mt-3">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2">
                <div class="small fw-semibold"><i class="bi bi-table me-1 text-secondary"></i>รายชื่อคนงานตามกลุ่ม</div>
                <form method="get" class="d-flex flex-wrap align-items-end gap-2">
                    <input type="hidden" name="month" value="<?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="half" value="<?= (int) $half ?>">
                    <div>
                        <label class="form-label small mb-1">กลุ่ม</label>
                        <select class="form-select form-select-sm" name="group_id">
                            <option value="0">ทุกกลุ่ม</option>
                            <?php foreach ($workerGroups as $g): ?>
                                <?php $gid = (int) ($g['id'] ?? 0); ?>
                                <option value="<?= $gid ?>" <?= $selectedGroupId === $gid ? 'selected' : '' ?>><?= htmlspecialchars((string) ($g['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-secondary">แสดง</button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0" id="tncWorkerManageTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 60px;" class="text-center">#</th>
                            <th>ชื่อคนงาน</th>
                            <th class="text-end" style="width: 140px;">ค่าแรง/วัน</th>
                            <th class="text-center" style="width: 100px;">เพศ</th>
                            <th class="text-center" style="width: 140px;">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($workerRows) === 0): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-3">ไม่พบรายชื่อคนงานในกลุ่มที่เลือก</td>
                            </tr>
                        <?php else: ?>
                            <?php $n = 1; foreach ($workerRows as $w): ?>
                                <?php
                                    $wid = (int) ($w['id'] ?? 0);
                                    $wname = (string) ($w['full_name'] ?? '');
                                    $wg = (string) ($w['gender'] ?? 'อื่นๆ');
                                    $wd = (float) ($w['default_daily_wage'] ?? 0);
                                ?>
                                <tr>
                                    <td class="text-center"><?= $n ?></td>
                                    <td><?= htmlspecialchars($wname, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end"><?= number_format($wd, 2) ?></td>
                                    <td class="text-center"><?= htmlspecialchars($wg, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-center">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary px-2 btn-edit-worker"
                                            data-id="<?= $wid ?>"
                                            data-name="<?= htmlspecialchars($wname, ENT_QUOTES, 'UTF-8') ?>"
                                            data-wage="<?= htmlspecialchars((string) $wd, ENT_QUOTES, 'UTF-8') ?>"
                                            data-gender="<?= htmlspecialchars($wg, ENT_QUOTES, 'UTF-8') ?>"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger px-2 btn-delete-worker" data-id="<?= $wid ?>" data-name="<?= htmlspecialchars($wname, ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                            <?php $n++; endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<form id="updateWorkerForm" method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>" class="d-none">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="update_worker">
    <input type="hidden" name="year_month" value="<?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="half" value="<?= (int) $half ?>">
    <input type="hidden" name="return_to" value="manage">
    <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
    <input type="hidden" name="worker_id" id="editWorkerId" value="0">
    <input type="hidden" name="worker_name" id="editWorkerName" value="">
    <input type="hidden" name="daily_wage" id="editWorkerWage" value="0">
    <input type="hidden" name="gender" id="editWorkerGender" value="อื่นๆ">
</form>

<form id="deleteWorkerForm" method="post" action="<?= htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') ?>" class="d-none">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="delete_worker">
    <input type="hidden" name="year_month" value="<?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="half" value="<?= (int) $half ?>">
    <input type="hidden" name="return_to" value="manage">
    <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
    <input type="hidden" name="worker_id" id="deleteWorkerId" value="0">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script>
(function ($) {
    if (typeof window.TncLiveDT === 'undefined' || !$ || !$.fn.DataTable) return;
    var $t = $('#tncWorkerManageTable');
    if (!$t.length) return;
    if ($t.find('tbody tr').length === 1 && $t.find('tbody td[colspan]').length) return;
    TncLiveDT.init('#tncWorkerManageTable', { order: [[1, 'asc']], columnDefs: [{ orderable: false, targets: [0, 4] }] });
})(jQuery);
</script>
<script>
(function () {
    function toast(icon, title) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2200,
            timerProgressBar: true,
            icon: icon,
            title: title
        });
    }

    var params = new URLSearchParams(window.location.search);
    if (params.has('group_created')) toast('success', 'สร้างกลุ่มคนงานเรียบร้อยแล้ว');
    if (params.has('worker_created')) toast('success', 'เพิ่มคนงานใหม่เรียบร้อยแล้ว');
    if (params.has('worker_updated')) toast('success', 'แก้ไขข้อมูลคนงานเรียบร้อยแล้ว');
    if (params.has('worker_deleted')) toast('success', 'ลบคนงานเรียบร้อยแล้ว');
    if (params.get('group_exists') === '1') toast('warning', 'ชื่อกลุ่มนี้มีอยู่แล้ว');
    if (params.get('group_err') === 'name') toast('warning', 'กรุณากรอกชื่อกลุ่มก่อนสร้าง');
    if (params.get('worker_err') === 'name') toast('warning', 'กรุณากรอกชื่อคนงาน');
    if (params.get('worker_err') === 'group') toast('warning', 'กรุณาเลือกกลุ่มคนงานที่ต้องการ');
    if (params.get('worker_err') === 'missing') toast('warning', 'ไม่พบข้อมูลคนงานที่ต้องการแก้ไข');

    document.querySelectorAll('.btn-edit-worker').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var wid = btn.getAttribute('data-id') || '0';
            var name = btn.getAttribute('data-name') || '';
            var wage = btn.getAttribute('data-wage') || '0';
            var gender = btn.getAttribute('data-gender') || 'อื่นๆ';
            var result = await Swal.fire({
                title: 'แก้ไขข้อมูลคนงาน',
                html:
                    '<input id="swalWorkerName" class="swal2-input" placeholder="ชื่อ">' +
                    '<input id="swalWorkerWage" type="number" min="0" step="0.01" class="swal2-input" placeholder="ค่าแรง/วัน">' +
                    '<select id="swalWorkerGender" class="swal2-select">' +
                        '<option value="ชาย">ชาย</option>' +
                        '<option value="หญิง">หญิง</option>' +
                        '<option value="อื่นๆ">อื่นๆ</option>' +
                    '</select>',
                didOpen: function () {
                    document.getElementById('swalWorkerName').value = name;
                    document.getElementById('swalWorkerWage').value = wage;
                    document.getElementById('swalWorkerGender').value = gender;
                },
                focusConfirm: false,
                showCancelButton: true,
                confirmButtonText: 'บันทึก',
                cancelButtonText: 'ยกเลิก',
                preConfirm: function () {
                    var n = document.getElementById('swalWorkerName').value.trim();
                    var w = document.getElementById('swalWorkerWage').value;
                    var g = document.getElementById('swalWorkerGender').value;
                    if (!n) {
                        Swal.showValidationMessage('กรุณากรอกชื่อคนงาน');
                        return false;
                    }
                    if (w === '' || Number(w) < 0) {
                        Swal.showValidationMessage('กรุณากรอกค่าแรง/วันให้ถูกต้อง');
                        return false;
                    }
                    return { name: n, wage: w, gender: g };
                }
            });
            if (!result.isConfirmed || !result.value) {
                return;
            }
            document.getElementById('editWorkerId').value = wid;
            document.getElementById('editWorkerName').value = result.value.name;
            document.getElementById('editWorkerWage').value = result.value.wage;
            document.getElementById('editWorkerGender').value = result.value.gender;
            document.getElementById('updateWorkerForm').submit();
        });
    });

    document.querySelectorAll('.btn-delete-worker').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var wid = btn.getAttribute('data-id') || '0';
            var name = btn.getAttribute('data-name') || '';
            var result = await Swal.fire({
                icon: 'warning',
                title: 'ยืนยันลบคนงาน',
                text: 'ต้องการลบ "' + name + '" ใช่หรือไม่?',
                showCancelButton: true,
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#d33'
            });
            if (!result.isConfirmed) {
                return;
            }
            document.getElementById('deleteWorkerId').value = wid;
            document.getElementById('deleteWorkerForm').submit();
        });
    });
})();
</script>
</body>
</html>
