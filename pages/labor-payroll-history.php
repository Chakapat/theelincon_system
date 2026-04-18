<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/connect_database.php';

use Theelincon\Rtdb\Db;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$rows = Db::tableRows('labor_payroll_archive');
usort(
    $rows,
    static function ($a, $b): int {
        $ta = strtotime((string) ($a['closed_at'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['closed_at'] ?? '')) ?: 0;
        if ($ta !== $tb) {
            return $tb <=> $ta;
        }

        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    }
);

function labor_half_label(array $r): string
{
    $h = (int) ($r['period_half'] ?? 1);
    $de = (int) ($r['day_end'] ?? 31);

    return $h === 1 ? 'บัตรตอก วันที่ 1–15' : ('บัตรตอก วันที่ 16–' . $de);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประวัติตัดยอดค่าแรงคนงาน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f6f8fb; }
        .card-hist { border-radius: 14px; border: 1px solid #e3e8ef; box-shadow: 0 4px 18px rgba(15, 23, 42, 0.06); }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 mt-2">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-archive me-2 text-primary"></i>ประวัติตัดยอดค่าแรงคนงาน</h4>
            <p class="text-muted small mb-0">สรุปตามรอบบัตรตอกที่ปิดแล้ว — ดู แก้ไข หรือลบรายการได้</p>
        </div>
        <a href="<?= htmlspecialchars(app_path('pages/labor-payroll.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill"><i class="bi bi-arrow-left me-1"></i>กลับบัตรค่าแรง</a>
    </div>

    <?php if (count($rows) === 0): ?>
        <div class="card card-hist"><div class="card-body text-center text-muted py-5">ยังไม่มีรายการตัดยอด</div></div>
    <?php else: ?>
        <?php if (!empty($_GET['deleted'])): ?>
            <div class="alert alert-success py-2 rounded-3 small">ลบรายการแล้ว</div>
        <?php endif; ?>
        <div class="table-responsive card card-hist">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่เอกสาร</th>
                        <th>เดือน (ปี-เดือน)</th>
                        <th>บัตรตอก</th>
                        <th class="text-end">ยอดรวม (บาท)</th>
                        <th class="text-end">จ่ายจริงรวม (บาท)</th>
                        <th class="text-center">จำนวนคน</th>
                        <th>วันที่ตัดยอด</th>
                        <th class="text-end" style="min-width: 200px;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $archHandler = app_path('actions/labor-payroll-archive-handler.php');
                    foreach ($rows as $r):
                    ?>
                    <tr>
                        <td><span class="badge text-bg-light border font-monospace"><?= htmlspecialchars((string) ($r['doc_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td class="fw-semibold"><?= htmlspecialchars((string) $r['period_ym'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(labor_half_label($r), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-end"><?= number_format((float) $r['total_gross'], 2) ?></td>
                        <td class="text-end fw-bold text-success"><?= number_format((float) $r['total_net'], 2) ?></td>
                        <td class="text-center"><?= (int) $r['worker_count'] ?></td>
                        <td class="small text-secondary"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $r['closed_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm shadow-sm">
                                <a class="btn btn-outline-primary" href="<?= htmlspecialchars(app_path('pages/labor-payroll-archive-view.php') . '?id=' . (int) $r['id'], ENT_QUOTES, 'UTF-8') ?>" title="ดู"><i class="bi bi-eye"></i></a>
                                <a class="btn btn-outline-warning" href="<?= htmlspecialchars(app_path('pages/labor-payroll-archive-edit.php') . '?id=' . (int) $r['id'], ENT_QUOTES, 'UTF-8') ?>" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                            </div>
                            <form method="post" action="<?= htmlspecialchars($archHandler, ENT_QUOTES, 'UTF-8') ?>" class="d-inline ms-1" onsubmit="return confirm('ลบรายการตัดยอดนี้ถาวร — ยืนยัน?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="archive_id" value="<?= (int) $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash3"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
