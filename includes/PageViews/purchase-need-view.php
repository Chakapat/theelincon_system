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

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$need = $id > 0 ? Db::row('purchase_needs', (string) $id) : null;
if (!$need) {
    header('Location: ' . app_path('pages/purchase-need-list.php') . '?error=invalid_need');
    exit();
}

$items = Db::filter('purchase_need_items', static function (array $row) use ($id): bool {
    return (int) ($row['need_id'] ?? 0) === $id;
});

$users = Db::tableKeyed('users');
$requester = $users[(string) ($need['requested_by'] ?? '')] ?? null;
$requesterName = trim((string) ($requester['fname'] ?? '') . ' ' . (string) ($requester['lname'] ?? ''));
if ($requesterName === '') {
    $requesterName = 'Unknown User';
}
$isRejected = ((string) ($need['status'] ?? '') === 'rejected');
$isPending = ((string) ($need['status'] ?? '') === 'pending');
$isPrintLocked = $isRejected || $isPending;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดใบต้องการซื้อ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background:#f8f9fa; font-family: 'Sarabun', sans-serif; }
        .need-items-card { border: 2px solid #dbeafe; }
        .need-items-title { font-size: 1.2rem; }
        .need-items-table thead th { font-weight: 700; }
        .need-items-table td { font-size: 1.02rem; }
        .need-items-table .qty-cell { font-size: 1.12rem; font-weight: 700; color: #0d6efd; }
        .need-meta-card { background: #fbfcfe; }
        .need-meta-card small { font-size: 0.72rem; color: #7c8798 !important; }
        .need-meta-card .meta-value { font-size: 0.92rem; color: #344054; }
        .need-meta-card .meta-value.primary { color: #2563eb; font-weight: 600; }
        .need-meta-card .meta-value.muted { color: #6b7280; }
        .need-meta-card .meta-value.requester-small { font-size: 0.82rem; }
        .need-meta-card .badge { font-size: 0.7rem; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .navbar { display: none !important; }
            .card { box-shadow: none !important; border: 1px solid #d9dee3 !important; }
            .print-wrap { margin: 0 !important; max-width: 100% !important; }
            .need-items-card { border: 2px solid #9ca3af !important; }
            .need-items-title { font-size: 1.25rem; }
            .need-items-table td { font-size: 1.05rem; }
            .need-meta-card small { font-size: 0.68rem; }
            .need-meta-card .meta-value { font-size: 0.85rem; }
        }
    </style>
</head>
<body>
<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>
<div class="container mt-4 mb-5 print-wrap">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h4 class="fw-bold mb-0"><i class="bi bi-card-checklist text-primary me-2"></i>รายละเอียดใบต้องการซื้อ</h4>
        <div class="d-flex gap-2">
            <?php if (!$isPrintLocked): ?>
                <button type="button" class="btn btn-dark rounded-pill px-4" onclick="window.print()"><i class="bi bi-printer me-1"></i>พิมพ์เอกสาร</button>
            <?php else: ?>
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" disabled title="เอกสารสถานะรอดำเนินการหรือไม่อนุมัติไม่สามารถพิมพ์ได้">
                    <i class="bi bi-printer me-1"></i>พิมพ์เอกสาร
                </button>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(app_path('pages/purchase-need-list.php')) ?>" class="btn btn-outline-secondary rounded-pill px-4">กลับหน้ารายการ</a>
        </div>
    </div>
    <?php if ($isPrintLocked): ?>
        <div class="alert alert-warning no-print">
            เอกสารนี้มีสถานะ <strong><?= htmlspecialchars(strtoupper((string) ($need['status'] ?? 'pending')), ENT_QUOTES, 'UTF-8') ?></strong> จึงไม่สามารถพิมพ์ได้
        </div>
    <?php endif; ?>
    <div class="d-none d-print-block text-center border-bottom border-2 border-dark pb-3 mb-3">
        <h1 class="h4 fw-bold mb-1">THEELIN CON CO.,LTD.</h1>
        <h2 class="h5 fw-bold mb-2">ใบต้องการซื้อ</h2>
        <p class="small mb-0">พิมพ์เมื่อ <?= date('H:i') ?> น.</p>
    </div>
    <div class="d-none d-print-block border rounded-3 p-2 mb-2 small">
        <div>
            เลขที่เอกสาร <?= htmlspecialchars((string) ($need['need_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
            | วันที่ <?= htmlspecialchars((string) ($need['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
            | ผู้ขอ <?= htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8') ?>
            | ไซต์งาน <?= htmlspecialchars((string) ($need['site_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div>
            สถานะ <?= htmlspecialchars(strtoupper((string) ($need['status'] ?? 'pending')), ENT_QUOTES, 'UTF-8') ?>
            | รายละเอียด <?= htmlspecialchars((string) ($need['details'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>
    <div class="card border-0 shadow-sm p-4 mb-3 need-items-card">
        <h5 class="fw-bold mb-3 need-items-title"><i class="bi bi-list-check me-1 text-primary"></i>รายการที่ต้องการ</h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle need-items-table">
                <thead class="table-light">
                    <tr>
                        <th style="width:4rem;">#</th>
                        <th>รายการ</th>
                        <th style="width:10rem;">จำนวน</th>
                        <th style="width:10rem;">หน่วย</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($items) === 0): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">ไม่มีรายการ</td></tr>
                    <?php else: ?>
                        <?php $no = 0; foreach ($items as $item): $no++; ?>
                            <tr>
                                <td><?= $no ?></td>
                                <td><?= htmlspecialchars((string) ($item['description'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="qty-cell"><?= number_format((float) ($item['quantity'] ?? 0), 0) ?></td>
                                <td><?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card border-0 shadow-sm p-3 need-meta-card no-print">
        <div class="row g-3">
            <div class="col-md-4"><small class="text-muted">เลขที่เอกสาร</small><div class="meta-value primary"><?= htmlspecialchars((string) ($need['need_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div></div>
            <div class="col-md-4"><small class="text-muted">วันที่</small><div class="meta-value"><?= htmlspecialchars((string) ($need['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div></div>
            <div class="col-md-4"><small class="text-muted">ผู้ขอ</small><div class="meta-value requester-small"><?= htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8') ?></div></div>
            <div class="col-md-4"><small class="text-muted">ไซต์งาน</small><div class="meta-value"><?= htmlspecialchars((string) ($need['site_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div></div>
            <div class="col-md-4"><small class="text-muted">สถานะ</small><div>
                <?php if (($need['status'] ?? '') === 'approved'): ?>
                    <span class="badge bg-success px-3 rounded-pill">APPROVED</span>
                <?php elseif (($need['status'] ?? '') === 'rejected'): ?>
                    <span class="badge bg-danger px-3 rounded-pill">REJECTED</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark px-3 rounded-pill">PENDING</span>
                <?php endif; ?>
            </div></div>
            <div class="col-12"><small class="text-muted">รายละเอียดไซต์งาน</small><div class="meta-value muted"><?= nl2br(htmlspecialchars((string) ($need['site_details'] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div></div>
            <div class="col-12"><small class="text-muted">รายละเอียด</small><div class="meta-value muted"><?= nl2br(htmlspecialchars((string) ($need['details'] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div></div>
        </div>
    </div>
</div>
<?php if ($isPrintLocked): ?>
<script>
(function () {
    function stopPrint(event) {
        if (event) {
            event.preventDefault();
        }
        alert('เอกสารสถานะ PENDING หรือ REJECTED ไม่สามารถพิมพ์ได้');
        return false;
    }
    window.onbeforeprint = function () {
        stopPrint();
    };
    document.addEventListener('keydown', function (event) {
        const key = (event.key || '').toLowerCase();
        if ((event.ctrlKey || event.metaKey) && key === 'p') {
            stopPrint(event);
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
