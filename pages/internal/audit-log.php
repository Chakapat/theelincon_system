<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_is_admin_role()) {
    http_response_code(403);
    exit('Access denied');
}

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
        table.audit-table tbody td { vertical-align: middle; font-size: 0.92rem; }
        .verb-del { color: #dc3545; font-weight: 600; }
        .verb-add { color: #198754; font-weight: 600; }
        .verb-ed { color: #0d6efd; font-weight: 600; }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h4 class="fw-bold mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>บันทึกการกระทำ (Audit)</h4>
        <span class="badge text-bg-secondary rounded-pill">เฉพาะผู้ดูแลระบบ</span>
    </div>

    <div class="card audit-card">
        <div class="card-body p-3 p-md-4">
            <div class="table-responsive">
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
                                $verbClass = $verb === 'delete' ? 'verb-del' : ($verb === 'create' ? 'verb-add' : 'verb-ed');
                                ?>
                                <tr>
                                    <?php $atRaw = (string) ($r['created_at'] ?? ''); ?>
                                    <td class="text-nowrap small" title="เวลาไทย (Asia/Bangkok)" data-order="<?= htmlspecialchars($atRaw, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(tnc_audit_log_format_datetime_th($atRaw), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($r['user_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="<?= htmlspecialchars($verbClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(tnc_audit_log_page_verb_th($verb), ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td class="small"><?= htmlspecialchars((string) ($r['entity_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="small font-monospace"><?= htmlspecialchars((string) ($r['entity_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="small"><?= htmlspecialchars((string) ($r['summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 tnc-open-audit-detail" data-row="<?= (int) $idx ?>">
                                            <i class="bi bi-file-text"></i> ดู
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
    $('#auditTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 50,
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' }
    });
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
</script>
</body>
</html>
