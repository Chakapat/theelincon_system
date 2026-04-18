<?php
if (!isset($announcement_gate_items) || !is_array($announcement_gate_items) || count($announcement_gate_items) === 0) {
    return;
}
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'announcements.php') {
    return;
}
$annGateIds = array_values(array_filter(array_map(static function ($r) {
    return isset($r['id']) ? (int) $r['id'] : 0;
}, $announcement_gate_items)));
?>
<div class="modal fade" id="announcementGateModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-megaphone-fill text-warning me-2"></i>ประกาศภายใน — โปรดอ่านและรับทราบ
                </h5>
            </div>
            <div class="modal-body pt-2">
                <p class="text-muted small mb-3">มีประกาศที่ยังไม่ได้รับทราบ <?= count($announcement_gate_items) ?> รายการ กรุณาอ่านแล้วกดปุ่มด้านล่างเพื่อดำเนินการต่อ</p>
                <?php foreach ($announcement_gate_items as $row): ?>
                    <div class="card border-0 bg-light mb-3 rounded-4">
                        <div class="card-body">
                            <h6 class="fw-bold text-dark mb-2"><?= htmlspecialchars($row['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></h6>
                            <div class="small text-muted mb-2">
                                <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)($row['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="announcement-body-text" style="white-space: pre-wrap; line-height: 1.65;">
                                <?= nl2br(htmlspecialchars((string)($row['body'] ?? ''), ENT_QUOTES, 'UTF-8')) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer border-0 flex-column gap-2">
                <button type="button" class="btn btn-warning text-white fw-bold w-100 py-2 rounded-3" id="announcementAckBtn">
                    รับทราบทั้งหมด
                </button>
                <a class="small text-muted" href="<?= htmlspecialchars(app_path('pages/announcements.php'), ENT_QUOTES, 'UTF-8') ?>">ไปที่กระดานประกาศ</a>
            </div>
        </div>
    </div>
</div>
<script>
window.addEventListener('load', function () {
    if (typeof bootstrap === 'undefined') return;
    var modalEl = document.getElementById('announcementGateModal');
    if (!modalEl) return;
    var ids = <?= json_encode($annGateIds, JSON_UNESCAPED_UNICODE) ?>;
    var url = <?= json_encode(app_path('actions/announcement-handler.php?action=ack'), JSON_UNESCAPED_SLASHES) ?>;
    var m = bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: 'static', keyboard: false });
    m.show();
    document.getElementById('announcementAckBtn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: ids })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) {
                    m.hide();
                    window.location.reload();
                } else {
                    btn.disabled = false;
                    alert('ไม่สามารถบันทึกการรับทราบได้ กรุณาลองใหม่');
                }
            })
            .catch(function () {
                btn.disabled = false;
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            });
    });
});
</script>
