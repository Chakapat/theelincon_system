<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

// เฉพาะบทบาท ADMIN — CEO และบทบาทอื่นเข้าไม่ได้ (ดู user_is_admin_only_role ใน config/foundation.php)
if (!user_can('page.internal.audit')) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์เข้าถึง — หน้านี้สำหรับผู้ดูแลระบบ (ADMIN) เท่านั้น');
}

$auditLogLimit = 1000;
$auditLogCount = tnc_audit_logs_count();
$auditLogOverLimit = $auditLogCount >= $auditLogLimit;
$auditLogPurged = isset($_GET['purged']) ? (int) $_GET['purged'] : -1;
$auditLogPurgeDeclined = !empty($_GET['purge_declined']);

$rows = Db::tableRows('audit_logs');
usort($rows, static function (array $a, array $b): int {
    return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
});

/** สำหรับสคริปต์หน้าเว็บ: สรุป + JSON รายละเอียด (ถ้ามี) */
$auditUiPayloads = [];
foreach ($rows as $r) {
    $dj = trim((string) ($r['detail_json'] ?? ''));
    $auditUiPayloads[] = [
        'verb' => trim((string) ($r['verb'] ?? '')),
        'entity_type' => trim((string) ($r['entity_type'] ?? '')),
        'entity_id' => trim((string) ($r['entity_id'] ?? '')),
        'summary' => trim((string) ($r['summary'] ?? '')),
        'detail_json' => $dj !== '' ? $dj : null,
    ];
}

if (!function_exists('tnc_audit_log_page_verb_th')) {
    function tnc_audit_log_page_verb_th(string $v): string
    {
        return match ($v) {
            'create' => 'เพิ่ม',
            'update' => 'แก้ไข',
            'delete' => 'ลบ',
            default => $v,
        };
    }
}

if (!function_exists('tnc_audit_log_page_verb_badge_class')) {
    function tnc_audit_log_page_verb_badge_class(string $v): string
    {
        return match ($v) {
            'create' => 'verb-badge verb-add',
            'update' => 'verb-badge verb-ed',
            'delete' => 'verb-badge verb-del',
            default => 'verb-badge verb-unk',
        };
    }
}

if (!function_exists('tnc_audit_log_entity_badge_class')) {
    function tnc_audit_log_entity_badge_class(string $type): string
    {
        $t = strtolower(trim($type));
        return match ($t) {
            'cash_ledger' => 'entity-badge entity-cash',
            'purchase_bill' => 'entity-badge entity-purchase',
            'invoice' => 'entity-badge entity-invoice',
            'line_notify_config' => 'entity-badge entity-config',
            'user' => 'entity-badge entity-user',
            default => 'entity-badge entity-default',
        };
    }
}

/** แปลง created_at (UTC Y-m-d H:i:s) → แสดงเป็น Asia/Bangkok */
if (!function_exists('tnc_audit_log_format_datetime_th')) {
    function tnc_audit_log_format_datetime_th(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone('UTC'));
        if ($dt === false) {
            return $raw;
        }

        return $dt->setTimezone(new DateTimeZone('Asia/Bangkok'))->format('d/m/Y H:i:s');
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประวัติระบบ | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f8f9fa; }
        .audit-card { border-radius: 12px; box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06); border: none; }
        .audit-toolbar { background: #fff; border: 1px solid #e9ecef; border-radius: 12px; padding: .7rem .8rem; }
        .audit-toolbar .form-control, .audit-toolbar .form-select { min-height: 42px; }
        .audit-table-wrap { max-height: min(70vh, 640px); overflow: auto; }
        table.audit-table { margin-bottom: 0 !important; }
        table.audit-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8f9fa !important;
            border-bottom: 1px solid #dee2e6 !important;
        }
        table.audit-table tbody td { vertical-align: middle; font-size: 0.92rem; padding-top: .72rem; padding-bottom: .72rem; }
        table.audit-table tbody tr { transition: background-color .15s ease, box-shadow .15s ease; }
        table.audit-table tbody tr:hover { background: #f9fbff; box-shadow: inset 0 0 0 1px rgba(13,110,253,.08); }
        .mono-time { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
        .verb-badge {
            display: inline-flex;
            align-items: center;
            padding: .28rem .62rem;
            border-radius: 999px;
            font-weight: 700;
            font-size: .78rem;
            border: 1px solid transparent;
            letter-spacing: .01em;
        }
        .verb-add { color: #0f5132; background: rgba(25,135,84,.14); border-color: rgba(25,135,84,.3); }
        .verb-ed { color: #084298; background: rgba(13,110,253,.14); border-color: rgba(13,110,253,.28); }
        .verb-del { color: #842029; background: rgba(220,53,69,.14); border-color: rgba(220,53,69,.28); }
        .verb-unk { color: #495057; background: rgba(108,117,125,.15); border-color: rgba(108,117,125,.28); }
        .entity-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: .22rem .58rem;
            font-size: .76rem;
            font-weight: 700;
            border: 1px solid transparent;
            text-transform: lowercase;
        }
        .entity-cash { color: #0c5460; background: #d1ecf1; border-color: #bee5eb; }
        .entity-purchase { color: #155724; background: #d4edda; border-color: #c3e6cb; }
        .entity-invoice { color: #856404; background: #fff3cd; border-color: #ffeeba; }
        .entity-config { color: #5a189a; background: #f3e8ff; border-color: #e9d5ff; }
        .entity-user { color: #004085; background: #d6e4ff; border-color: #b8daff; }
        .entity-default { color: #495057; background: #e9ecef; border-color: #dee2e6; }
        .btn-view-audit {
            width: 2rem;
            height: 2rem;
            padding: 0;
            border-radius: .55rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body class="tnc-app-body">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h4 class="fw-bold mb-0"><i class="bi bi-clock-history me-2 text-tnc-orange"></i>บันทึกการกระทำ (Audit)</h4>
        <span class="badge text-bg-secondary rounded-pill">เฉพาะผู้ดูแลระบบ</span>
    </div>

    <?php if ($auditLogPurged >= 0): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ลบบันทึก Audit ออกจากฐานข้อมูลแล้ว <?= number_format($auditLogPurged) ?> รายการ
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($auditLogPurgeDeclined): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            ยังคงเก็บบันทึก Audit ไว้ — จำนวนรายการยังเกิน <?= number_format($auditLogLimit) ?> รายการ
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($auditLogOverLimit): ?>
        <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4" role="alert">
            <div>
                <strong>มีบันทึก Audit <?= number_format($auditLogCount) ?> รายการ</strong> (เกิน <?= number_format($auditLogLimit) ?> รายการ) —
                แนะนำลบ log เก่าออกจากฐานข้อมูลเพื่อลดภาระระบบ
            </div>
            <button type="button" class="btn btn-warning btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#auditPurgeModal">
                <i class="bi bi-trash3 me-1"></i>ลบ log ทั้งหมด
            </button>
        </div>
    <?php endif; ?>

    <div class="card audit-card">
        <div class="card-body p-3 p-md-4">
            <div class="audit-toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <div class="d-flex align-items-center gap-2">
                    <label for="auditPageLength" class="small text-muted mb-0">แสดง</label>
                    <select id="auditPageLength" class="form-select form-select-sm" style="width:92px;">
                        <option value="25">25</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                    </select>
                    <span class="small text-muted">แถว</span>
                    <span class="small text-muted ms-2" id="auditRowMeta">ทั้งหมด 0 รายการ</span>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <select id="auditActionFilter" class="form-select form-select-sm" style="min-width:150px;">
                        <option value="">ทุกการกระทำ</option>
                        <option value="เพิ่ม">เพิ่ม</option>
                        <option value="แก้ไข">แก้ไข</option>
                        <option value="ลบ">ลบ</option>
                    </select>
                    <div class="position-relative">
                        <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-2 text-muted"></i>
                        <input type="search" id="auditSearchInput" class="form-control form-control-sm ps-4" placeholder="ค้นหา..." style="min-width:220px;">
                    </div>
                </div>
            </div>
            <div class="table-responsive audit-table-wrap">
                <table class="table table-hover align-middle audit-table mb-0" id="auditTable">
                    <thead class="table-light">
                        <tr>
                            <th>วันเวลา</th>
                            <th>ผู้ทำ</th>
                            <th>การกระทำ</th>
                            <th>ประเภท</th>
                            <th>รหัสอ้างอิง</th>
                            <th>รายละเอียด</th>
                            <th class="text-end">ข้อมูลเต็ม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($rows) === 0): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีข้อมูลบันทึก</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $idx => $r): ?>
                                <?php
                                $verb = trim((string) ($r['verb'] ?? ''));
                                $verbClass = tnc_audit_log_page_verb_badge_class($verb);
                                ?>
                                <tr>
                                    <?php $atRaw = (string) ($r['created_at'] ?? ''); ?>
                                    <td class="text-nowrap small mono-time" title="เวลาไทย (Asia/Bangkok)" data-order="<?= htmlspecialchars($atRaw, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(tnc_audit_log_format_datetime_th($atRaw), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($r['user_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="<?= htmlspecialchars($verbClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(tnc_audit_log_page_verb_th($verb), ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td class="small"><span class="<?= htmlspecialchars(tnc_audit_log_entity_badge_class((string) ($r['entity_type'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($r['entity_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td class="small font-monospace"><?= htmlspecialchars((string) ($r['entity_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="small"><?= htmlspecialchars((string) ($r['summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-view-audit tnc-open-audit-detail" data-row="<?= (int) $idx ?>" title="ดูรายละเอียด">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($auditLogOverLimit): ?>
<div class="modal fade" id="auditPurgeModal" tabindex="-1" aria-labelledby="auditPurgeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px;">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold" id="auditPurgeModalLabel"><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>ลบบันทึก Audit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">ระบบมีบันทึก Audit <strong><?= number_format($auditLogCount) ?> รายการ</strong> (เกิน <?= number_format($auditLogLimit) ?> รายการ)</p>
                <p class="text-muted small mb-0">ต้องการลบ log ทั้งหมดออกจากฐานข้อมูลหรือไม่? การดำเนินการนี้ลบเฉพาะตาราง <code>audit_logs</code> และไม่สามารถกู้คืนได้</p>
            </div>
            <div class="modal-footer border-top-0 pt-0 gap-2">
                <form method="post" action="<?= htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8') ?>?action=purge_audit_logs" class="d-inline">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="confirm_purge" value="no">
                    <button type="submit" class="btn btn-outline-secondary">ไม่</button>
                </form>
                <form method="post" action="<?= htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8') ?>?action=purge_audit_logs" class="d-inline" data-tnc-fullnav="1">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="confirm_purge" value="yes">
                    <button type="submit" class="btn btn-danger fw-semibold">ใช่ — ลบทั้งหมด</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="auditDetailModal" tabindex="-1" aria-labelledby="auditDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:12px;">
            <div class="modal-header">
                <h5 class="modal-title" id="auditDetailModalLabel">รายละเอียดบันทึก</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <dl class="row small mb-3" id="auditDetailMeta"></dl>
                <label class="form-label small text-muted mb-1">JSON</label>
                <pre id="auditDetailJson" class="bg-light border rounded p-3 small mb-0" style="max-height:420px;white-space:pre-wrap;word-break:break-word;font-size:0.8rem;"></pre>
            </div>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.TNC_AUDIT_PAYLOADS = <?= json_encode($auditUiPayloads, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
(function ($) {
    if (!$ || !$.fn.DataTable) return;
    if ($('#auditTable tbody tr td[colspan]').length) return;
    var table = $('#auditTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 50,
        dom: 'rt<"d-flex flex-wrap justify-content-between align-items-center gap-2 px-2 py-2"ip>',
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' }
    });

    var searchEl = document.getElementById('auditSearchInput');
    var actionEl = document.getElementById('auditActionFilter');
    var lenEl = document.getElementById('auditPageLength');
    var metaEl = document.getElementById('auditRowMeta');

    function refreshMeta() {
        if (!metaEl) return;
        var shown = table.rows({ filter: 'applied' }).count();
        var total = table.rows().count();
        metaEl.textContent = 'แสดง ' + shown.toLocaleString() + ' / ' + total.toLocaleString() + ' รายการ';
    }

    if (searchEl) {
        searchEl.addEventListener('input', function () {
            table.search(searchEl.value || '').draw();
        });
    }
    if (actionEl) {
        actionEl.addEventListener('change', function () {
            var v = actionEl.value || '';
            table.column(2).search(v ? '^' + v + '$' : '', true, false).draw();
        });
    }
    if (lenEl) {
        lenEl.addEventListener('change', function () {
            var n = parseInt(lenEl.value || '50', 10);
            table.page.len(Number.isFinite(n) ? n : 50).draw();
        });
    }
    table.on('draw', refreshMeta);
    refreshMeta();
})(jQuery);
(function () {
    var payloads = window.TNC_AUDIT_PAYLOADS || [];
    var metaEl = document.getElementById('auditDetailMeta');
    var jsonEl = document.getElementById('auditDetailJson');
    var modalEl = document.getElementById('auditDetailModal');
    if (!metaEl || !jsonEl || !modalEl) return;
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.querySelectorAll('.tnc-open-audit-detail').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var i = parseInt(btn.getAttribute('data-row') || '-1', 10);
            var p = payloads[i];
            if (!p) return;
            var verbTh = { create: 'เพิ่ม', update: 'แก้ไข', delete: 'ลบ' };
            metaEl.innerHTML = ''
                + '<dt class="col-sm-3">การกระทำ</dt><dd class="col-sm-9">' + (verbTh[p.verb] || p.verb || '—') + '</dd>'
                + '<dt class="col-sm-3">ประเภท</dt><dd class="col-sm-9">' + (p.entity_type || '—') + '</dd>'
                + '<dt class="col-sm-3">รหัสอ้างอิง</dt><dd class="col-sm-9 font-monospace">' + (p.entity_id || '—') + '</dd>'
                + '<dt class="col-sm-3">สรุป</dt><dd class="col-sm-9">' + (p.summary || '—') + '</dd>';
            if (p.detail_json) {
                try {
                    var obj = JSON.parse(p.detail_json);
                    jsonEl.textContent = JSON.stringify(obj, null, 2);
                } catch (e) {
                    jsonEl.textContent = p.detail_json;
                }
            } else {
                jsonEl.textContent = 'ไม่มีข้อมูลโครงสร้างเพิ่มเติมสำหรับรายการนี้ (บันทึกก่อนระบบเก็บรายละเอียดแบบเต็ม)';
            }
            modal.show();
        });
    });
})();
<?php if ($auditLogOverLimit && $auditLogPurged < 0): ?>
(function () {
    var purgeModal = document.getElementById('auditPurgeModal');
    if (!purgeModal || typeof bootstrap === 'undefined') return;
    bootstrap.Modal.getOrCreateInstance(purgeModal).show();
})();
<?php endif; ?>
</script>
</body>
</html>
