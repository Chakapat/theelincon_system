<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$csrfQ = '&_csrf=' . rawurlencode(csrf_token());

$users = Db::tableKeyed('users');

$needItemsByNeedId = [];
foreach (Db::tableRows('purchase_need_items') as $itemRow) {
    $nid = (int) ($itemRow['need_id'] ?? 0);
    if ($nid <= 0) {
        continue;
    }
    if (!isset($needItemsByNeedId[$nid])) {
        $needItemsByNeedId[$nid] = [];
    }
    $needItemsByNeedId[$nid][] = $itemRow;
}
foreach ($needItemsByNeedId as &$itemList) {
    usort($itemList, static function (array $a, array $b): int {
        return ((int) ($a['line_no'] ?? 0)) <=> ((int) ($b['line_no'] ?? 0));
    });
}
unset($itemList);

$need_rows = Db::tableRows('purchase_needs');
foreach ($need_rows as &$needRow) {
    $rb = $users[(string) ($needRow['requested_by'] ?? '')] ?? null;
    $needRow['fname'] = $rb['fname'] ?? '';
    $needRow['lname'] = $rb['lname'] ?? '';
}
unset($needRow);
Db::sortRows($need_rows, 'created_at', true);

$printPageBase = app_path('pages/purchase/purchase-need-print.php');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการใบต้องการซื้อ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .table-card { border: none; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
        #needPrintModal .modal-content {
            border: none;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 1rem 2.5rem rgba(0, 0, 0, 0.12);
        }
        #needPrintModal .modal-header {
            background: linear-gradient(135deg, #fd7e14 0%, #ff922b 100%);
            color: #fff;
            border-bottom: none;
            padding: 0.85rem 1.25rem;
        }
        #needPrintModal .modal-title { font-size: 1.05rem; }
        #needPrintModal .btn-close { filter: brightness(0) invert(1); opacity: 0.9; }
        #needPrintModal #needPrintModalPrintBtn {
            background: #fff;
            color: #c2410c;
            border: none;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
        }
        #needPrintModal #needPrintModalPrintBtn:hover {
            background: #fffaf5;
            color: #9a3412;
        }
        #needPrintModal .modal-body {
            background: #e7e5e4;
        }
        #needPrintModal .modal-body iframe {
            min-height: 72vh;
            background: #fff;
            vertical-align: top;
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['need_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            บันทึกใบต้องการซื้อเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['line_error'])): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            บันทึกแล้ว แต่ส่งแจ้งเตือน LINE ไม่สำเร็จ — ตรวจสอบการตั้งค่า LINE / กลุ่มปลายทาง
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['need_deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ลบใบต้องการซื้อเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['approved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            อนุมัติใบต้องการซื้อเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['rejected'])): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            ไม่อนุมัติใบต้องการซื้อเรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'invalid_need'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            ไม่พบรหัสใบต้องการซื้อที่ถูกต้อง
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="bi bi-card-checklist text-primary me-2"></i>รายการใบต้องการซื้อ</h3>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars(app_path('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
                <i class="bi bi-arrow-left"></i> กลับหน้าเมนูหลัก
            </a>
            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-need-create.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary rounded-pill px-4 shadow-sm">
                <i class="bi bi-plus-lg"></i> สร้างใบต้องการซื้อ
            </a>
        </div>
    </div>

    <div class="card table-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="needListTable" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่เอกสาร</th>
                        <th>วันที่</th>
                        <th>ผู้ขอ</th>
                        <th>สรุป</th>
                        <th class="text-center">สถานะ</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($need_rows) === 0): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">ยังไม่มีข้อมูลใบต้องการซื้อ</td></tr>
                    <?php else: ?>
                        <?php foreach ($need_rows as $row): ?>
                            <?php
                            $needId = (int) ($row['id'] ?? 0);
                            $needItems = $needItemsByNeedId[$needId] ?? [];
                            $hasItems = count($needItems) > 0;
                            $remarks = trim((string) ($row['remarks'] ?? ''));
                            ?>
                            <tr>
                                <td class="fw-bold text-primary"><?= htmlspecialchars((string) ($row['need_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(date('d/m/Y', strtotime((string) ($row['created_at'] ?? date('Y-m-d')))), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="small">
                                    <?php if (trim((string) ($row['site_name'] ?? '')) !== ''): ?>
                                        <div class="text-muted"><i class="bi bi-geo-alt-fill me-1"></i><?= htmlspecialchars((string) ($row['site_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php
                                    $detPreview = (string) ($row['details'] ?? '');
                                    $detLen = function_exists('mb_strlen') ? mb_strlen($detPreview, 'UTF-8') : strlen($detPreview);
                                    $detMax = 90;
                                    $detShown = $detPreview === ''
                                        ? ''
                                        : (function_exists('mb_substr') ? mb_substr($detPreview, 0, $detMax, 'UTF-8') : substr($detPreview, 0, $detMax));
                                    if ($detPreview !== '' && $detLen > $detMax) {
                                        $detShown .= '…';
                                    }
                                    ?>
                                    <div class="text-secondary text-truncate" style="max-width: 280px;" title="<?= htmlspecialchars($detPreview, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= $detPreview === '' ? '<span class="text-muted">—</span>' : htmlspecialchars($detShown, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if (($row['status'] ?? '') === 'approved'): ?>
                                        <span class="badge bg-success px-3 rounded-pill">APPROVED</span>
                                    <?php elseif (($row['status'] ?? '') === 'rejected'): ?>
                                        <span class="badge bg-danger px-3 rounded-pill">REJECTED</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark px-3 rounded-pill">PENDING</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-inline-flex flex-wrap gap-1 justify-content-center">
                                        <?php if ($hasItems): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-white text-primary border shadow-sm"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#needItems<?= $needId ?>"
                                                aria-expanded="false"
                                                aria-controls="needItems<?= $needId ?>"
                                                title="แสดงรายละเอียดรายการในแถว"
                                            >
                                                <i class="bi bi-card-list"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-white text-dark border shadow-sm btn-need-print-preview"
                                            data-need-id="<?= $needId ?>"
                                            title="ดูเอกสารก่อนพิมพ์"
                                        >
                                            <i class="bi bi-printer-fill"></i>
                                        </button>
                                        <?php if (user_is_admin_role()): ?>
                                            <a href="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=delete_purchase_need&id=<?= $needId ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-white text-secondary border shadow-sm" onclick="return confirm('ยืนยันการลบข้อมูลถาวร?')" title="ลบ">
                                                <i class="bi bi-trash3-fill text-danger"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php if ($hasItems): ?>
                                <tr class="collapse" id="needItems<?= $needId ?>">
                                    <td colspan="6" class="bg-light border-top-0 pt-0">
                                        <div class="p-3 pt-2">
                                            <div class="small fw-semibold text-secondary mb-2"><i class="bi bi-list-ul me-1"></i>รายละเอียดรายการ — <?= htmlspecialchars((string) ($row['need_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="table-responsive rounded border bg-white">
                                                <table class="table table-sm table-bordered mb-0 align-middle">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th style="width:3rem;">#</th>
                                                            <th>รายการ</th>
                                                            <th style="width:8rem;" class="text-end">จำนวน</th>
                                                            <th style="width:8rem;">หน่วย</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php $no = 0; foreach ($needItems as $item): $no++; ?>
                                                            <tr>
                                                                <td class="text-secondary"><?= $no ?></td>
                                                                <td><?= htmlspecialchars((string) ($item['description'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td class="text-end"><?= htmlspecialchars(number_format((float) ($item['quantity'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td><?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php if ($remarks !== ''): ?>
                                                <div class="mt-2 small">
                                                    <span class="text-muted fw-semibold">หมายเหตุ:</span>
                                                    <span class="text-dark"><?= nl2br(htmlspecialchars($remarks, ENT_QUOTES, 'UTF-8')) ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="needPrintModal" tabindex="-1" aria-labelledby="needPrintModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header align-items-center">
                <h5 class="modal-title fw-bold mb-0" id="needPrintModalLabel"><i class="bi bi-file-earmark-text me-2 opacity-90"></i>ตัวอย่างเอกสารก่อนพิมพ์</h5>
                <div class="d-flex align-items-center gap-2 ms-auto">
                    <button type="button" class="btn btn-sm rounded-pill px-4" id="needPrintModalPrintBtn">
                        <i class="bi bi-printer me-1"></i>พิมพ์เอกสาร
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
            </div>
            <div class="modal-body p-0 bg-light">
                <iframe id="needPrintFrame" title="เอกสารใบต้องการซื้อ" class="w-100 d-block"></iframe>
            </div>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var printBase = <?= json_encode($printPageBase, JSON_UNESCAPED_SLASHES) ?>;
    var modalEl = document.getElementById('needPrintModal');
    var frameEl = document.getElementById('needPrintFrame');
    var printBtn = document.getElementById('needPrintModalPrintBtn');
    var modalInstance = null;

    function getModal() {
        if (!modalInstance && modalEl && typeof bootstrap !== 'undefined') {
            modalInstance = new bootstrap.Modal(modalEl);
        }
        return modalInstance;
    }

    document.querySelectorAll('.btn-need-print-preview').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-need-id');
            if (!id || !frameEl) return;
            var sep = printBase.indexOf('?') >= 0 ? '&' : '?';
            frameEl.src = printBase + sep + 'id=' + encodeURIComponent(id) + '&embed=1';
            var m = getModal();
            if (m) m.show();
        });
    });

    if (printBtn && frameEl) {
        printBtn.addEventListener('click', function () {
            try {
                if (frameEl.contentWindow) {
                    frameEl.contentWindow.focus();
                    frameEl.contentWindow.print();
                }
            } catch (e) {}
        });
    }

    if (modalEl && frameEl) {
        modalEl.addEventListener('hidden.bs.modal', function () {
            frameEl.src = 'about:blank';
        });
    }
})();
</script>
<script>
(function ($) {
    if ($('#needListTable tbody tr td[colspan]').length === 0 && $('#needListTable tbody tr').length) {
        $('#needListTable').DataTable({
            order: [[1, 'desc']],
            pageLength: 25,
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
            columnDefs: [{ targets: [5], orderable: false, searchable: false }]
        });
    }
    var u = <?= json_encode(app_path('actions/live-datasets.php?dataset=mirror_table&table=purchase_needs'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
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
</body>
</html>
