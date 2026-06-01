<?php
if (!function_exists('app_path')) {
    require_once __DIR__ . '/../config/foundation.php';
}

?>
<style>
    .navbar-hub {
        gap: 0.35rem;
    }
    @media (min-width: 992px) {
        .navbar-hub { gap: 0.45rem; }
    }
    .navbar-hub .nav-hub-block {
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.38);
        border-radius: 0.5rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .navbar-hub .nav-hub-block:hover {
        background: rgba(255, 255, 255, 0.22);
        border-color: rgba(255, 255, 255, 0.55);
    }
    .navbar-hub .nav-hub-block.show {
        background: rgba(255, 255, 255, 0.24);
        border-color: rgba(255, 255, 255, 0.65);
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    }
    .navbar-hub .nav-hub-block .nav-link {
        border-radius: 0.45rem;
    }
    .navbar-hub .nav-hub-block .nav-hub-toggle-inner {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }
    .navbar-hub .nav-hub-ico {
        font-size: 1.1rem;
        opacity: 0.95;
        line-height: 1;
    }
    .navbar-hub .nav-hub-label {
        letter-spacing: 0.01em;
    }
    @media (max-width: 991.98px) {
        .navbar-hub .nav-hub-block { width: 100%; }
    }

    /* ---------- Web notifications bell ---------- */
    .tnc-notif-menu { min-width: 23rem; max-width: 94vw; }
    .tnc-notif-list { max-height: 62vh; overflow-y: auto; }
    .tnc-notif-item {
        display: flex; gap: 0.6rem; align-items: flex-start;
        padding: 0.65rem 0.9rem; border-bottom: 1px solid #f1f3f5;
        color: #212529; text-decoration: none; transition: background-color .12s ease;
    }
    .tnc-notif-item:last-child { border-bottom: 0; }
    .tnc-notif-item:hover { background-color: #f8f9fa; }
    .tnc-notif-item.is-unread { background-color: #fff9f0; }
    .tnc-notif-item.is-unread:hover { background-color: #fff3e0; }
    .tnc-notif-ico {
        width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
        display: inline-flex; align-items: center; justify-content: center; font-size: 1rem;
    }
    .tnc-notif-ico.ok { background: #e6f4ea; color: #1e7e34; }
    .tnc-notif-ico.no { background: #fdecea; color: #c0392b; }
    .tnc-notif-title { font-weight: 600; font-size: .86rem; line-height: 1.25; }
    .tnc-notif-msg { font-size: .8rem; color: #5b6166; line-height: 1.3; }
    .tnc-notif-time { font-size: .72rem; color: #98a1a8; margin-top: .15rem; }
    .tnc-notif-dot { width: .5rem; height: .5rem; border-radius: 50%; background: #fd7e14; flex-shrink: 0; margin-top: .4rem; }
    @media (max-width: 991.98px) {
        .tnc-notif-menu { min-width: 100%; }
    }
    .tnc-notif-toast {
        top: 4.5rem;
        right: 1rem;
        z-index: 1080;
        min-width: min(22rem, calc(100vw - 2rem));
        max-width: calc(100vw - 2rem);
        animation: tncNotifToastIn .25s ease-out;
    }
    @keyframes tncNotifToastIn {
        from { opacity: 0; transform: translateY(-8px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .tnc-pr-po-audio-toggle {
        position: fixed;
        bottom: 1rem;
        right: 1rem;
        z-index: 1075;
        width: 2.35rem;
        height: 2.35rem;
        border-radius: 50%;
        padding: 0;
        display: none;
        align-items: center;
        justify-content: center;
    }
    @media print {
        .tnc-pr-po-audio-toggle { display: none !important; }
    }

    /* ---------- Global mobile/responsive hardening (system-wide) ---------- */
    html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
    body { overflow-x: hidden; }
    img, svg, video, canvas { max-width: 100%; height: auto; }
    table { max-width: 100%; }
    .table-responsive { -webkit-overflow-scrolling: touch; }
    .text-break, .text-wrap, .font-monospace { overflow-wrap: anywhere; word-break: break-word; }
    pre, code { white-space: pre-wrap; overflow-wrap: anywhere; }

    /* Prevent iOS zoom-on-focus (small font inputs) + improve tap targets */
    @media (max-width: 575.98px) {
        .form-control,
        .form-select,
        .input-group-text,
        .btn {
            font-size: 16px;
        }
        .btn { padding-top: 0.55rem; padding-bottom: 0.55rem; }
        .navbar .dropdown-menu { width: min(92vw, 22rem); }
    }

    /* Print-sheet style pages: adapt A4 mm layouts to phone screens */
    @media (max-width: 575.98px) {
        .dsr-print-sheet {
            width: auto !important;
            min-height: auto !important;
            margin: 0.75rem auto 1.25rem !important;
            padding: 1rem !important;
            box-shadow: none !important;
        }
        .dsr-photo-grid { grid-template-columns: 1fr !important; }
        .dsr-photo-wrap img { height: auto !important; max-height: 46vh; object-fit: contain !important; }
    }

</style>
<nav class="navbar navbar-expand-lg navbar-dark shadow-sm py-2 mb-3 mb-md-3 tnc-navbar-compact" style="background-color: #fd7e14; min-height: 3.25rem;">
    <div class="container py-0">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2 flex-shrink-0 py-1 fs-6" href="<?= htmlspecialchars(app_path('index.php')) ?>">
            <i class="bi bi-receipt-cutoff fs-5"></i>
            <span class="d-none d-sm-inline">THEELIN CON CO.,LTD.</span>
            <span class="d-inline d-sm-none">TNC</span>
        </a>

        <button class="navbar-toggler border-0 py-1 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="เมนู">
            <span class="navbar-toggler-icon" style="width: 1.25rem; height: 1.25rem;"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <?php if (isset($_SESSION['user_id'])): ?>
            <ul class="navbar-nav ms-auto navbar-hub flex-wrap align-items-lg-center py-1 py-lg-0">
                <li class="nav-item dropdown nav-hub-block" id="tncNotifBlock">
                    <a class="nav-link text-white fw-semibold px-2 px-lg-3 py-1 py-lg-2 position-relative" href="#" id="tncNotifToggle" role="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="การแจ้งเตือน">
                        <span class="nav-hub-toggle-inner">
                            <i class="bi bi-bell-fill nav-hub-ico flex-shrink-0" aria-hidden="true"></i>
                            <span class="nav-hub-label d-inline d-lg-none">แจ้งเตือน</span>
                        </span>
                        <span id="tncNotifBadge" class="position-absolute translate-middle badge rounded-pill bg-danger d-none" style="top:0.35rem; left:auto; right:-0.15rem; font-size:.6rem;">0</span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end shadow border-0 p-0 tnc-notif-menu" aria-labelledby="tncNotifToggle">
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                            <span class="fw-semibold text-dark"><i class="bi bi-bell me-1"></i>การแจ้งเตือน</span>
                            <button type="button" class="btn btn-sm btn-link text-decoration-none p-0" id="tncNotifMarkAll">อ่านทั้งหมด</button>
                        </div>
                        <div id="tncNotifList" class="tnc-notif-list">
                            <div class="text-center text-muted py-4 small">กำลังโหลด…</div>
                        </div>
                    </div>
                </li>
                <li class="nav-item dropdown nav-hub-block">
                    <a class="nav-link dropdown-toggle text-white fw-semibold px-2 px-lg-3 py-1 py-lg-2" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
                        <span class="nav-hub-toggle-inner text-start">
                            <i class="bi bi-person-circle nav-hub-ico flex-shrink-0" aria-hidden="true"></i>
                            <span class="nav-hub-label">
                                <span class="d-inline d-lg-none">บัญชี</span>
                                <span class="d-none d-lg-inline-block text-truncate align-bottom" style="max-width: 9rem;"><?= htmlspecialchars($_SESSION['name'] ?? 'ผู้ใช้งาน', ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2" style="min-width: 13rem;" aria-labelledby="userDropdown">
                        <li>
                            <a class="dropdown-item rounded-2 mx-1" href="<?= htmlspecialchars(app_path('pages/account/my-profile.php'), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-person-gear me-2 text-secondary"></i>แก้ไขข้อมูลส่วนตัว
                            </a>
                        </li>
                        <?php if (user_is_admin_only_role()): ?>
                        <li>
                            <a class="dropdown-item rounded-2 mx-1" href="<?= htmlspecialchars(app_path('pages/internal/audit-log.php'), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-clock-history me-2 text-primary"></i>Audit Log
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item rounded-2 mx-1" href="<?= htmlspecialchars(app_path('pages/internal/line-notify-config.php'), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-bell-fill me-2 text-success"></i>ตั้งค่า LINE แจ้งเตือน
                            </a>
                        </li>
                        <li><hr class="dropdown-divider mx-2 my-1"></li>
                        <?php endif; ?>
                        <li class="px-3 py-2">
                            <div class="small fw-semibold text-secondary mb-2"><i class="bi bi-volume-up-fill me-1"></i>เสียงในระบบ</div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="tncSoundNotifToggle" checked>
                                <label class="form-check-label small" for="tncSoundNotifToggle">เสียงแจ้งเตือน (กระดิ่ง)</label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="tncSoundPrPoToggle" checked>
                                <label class="form-check-label small" for="tncSoundPrPoToggle">เสียง PR / PO</label>
                            </div>
                        </li>
                        <li><hr class="dropdown-divider mx-2 my-1"></li>
                        <li>
                            <a class="dropdown-item rounded-2 mx-1 text-danger fw-semibold" href="<?= htmlspecialchars(app_path('sign-out.php'), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
            <?php else: ?>
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link text-white" href="<?= htmlspecialchars(app_path('sign-in.php'), ENT_QUOTES, 'UTF-8') ?>">เข้าสู่ระบบ</a>
                </li>
            </ul>
            <?php endif; ?>
            <div id="tnc-mobile-index-menu-slot" class="d-lg-none w-100 mt-2"></div>
        </div>
    </div>
</nav>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php if (isset($_SESSION['user_id'])): ?>
<?php
$tncDeleteJsPath = dirname(__DIR__) . '/assets/js/tnc-delete-confirm.js';
$tncDeleteJsVer = @filemtime($tncDeleteJsPath);
if (!is_int($tncDeleteJsVer) || $tncDeleteJsVer <= 0) {
    $tncDeleteJsVer = time();
}
?>
<script src="<?= htmlspecialchars(app_path('assets/js/tnc-delete-confirm.js') . '?v=' . $tncDeleteJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<script>
(function () {
    const path = (window.location.pathname || '').toLowerCase();
    const protectedPages = [
        '/pages/invoices/invoice.php',
        '/pages/purchase/purchase-request-create.php',
        '/pages/purchase/purchase-order-from-pr.php',
        '/pages/invoices/tax-invoice-receipt.php'
    ];

    const shouldPreventEnterSubmit = protectedPages.some(function (p) {
        return path.endsWith(p);
    });

    if (!shouldPreventEnterSubmit) {
        return;
    }

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || event.isComposing) {
            return;
        }

        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const form = target.closest('form');
        if (!form) {
            return;
        }

        const tag = (target.tagName || '').toLowerCase();
        if (tag === 'textarea' || target.isContentEditable) {
            return;
        }

        if (target.matches('button, [type="submit"], [data-allow-enter-submit="true"]')) {
            return;
        }

        event.preventDefault();
    });
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var alerts = Array.from(document.querySelectorAll('.alert.alert-success, .alert.alert-warning, .alert.alert-danger, .alert.alert-info'));
    if (alerts.length === 0 || typeof Swal === 'undefined') {
        return;
    }

    var toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 2400,
        timerProgressBar: true
    });

    alerts.forEach(function (el, idx) {
        if (el.getAttribute('data-no-swal') === '1') {
            return;
        }
        var title = (el.textContent || '').trim();
        if (title === '') {
            return;
        }
        var icon = 'info';
        if (el.classList.contains('alert-success')) icon = 'success';
        else if (el.classList.contains('alert-warning')) icon = 'warning';
        else if (el.classList.contains('alert-danger')) icon = 'error';
        else if (el.classList.contains('alert-info')) icon = 'info';

        setTimeout(function () {
            toast.fire({ icon: icon, title: title });
        }, idx * 240);
        el.remove();
    });
});
</script>
<script>
(function () {
    if (typeof Swal === 'undefined') {
        return;
    }

    function extractConfirmMessage(code) {
        if (!code) return '';
        var m = String(code).match(/confirm\((['"])([\s\S]*?)\1\)/i);
        return m ? m[2] : '';
    }

    function askConfirm(message, onYes) {
        Swal.fire({
            icon: 'warning',
            title: 'ยืนยันการทำรายการ',
            text: message || 'ต้องการดำเนินการต่อใช่หรือไม่?',
            showCancelButton: true,
            confirmButtonText: 'ยืนยัน',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#d33'
        }).then(function (res) {
            if (res.isConfirmed) {
                onYes();
            }
        });
    }

    function prepareInlineConfirmHooks() {
        document.querySelectorAll('[onclick*="confirm("]').forEach(function (el) {
            var code = el.getAttribute('onclick') || '';
            var msg = extractConfirmMessage(code);
            if (!msg) return;
            el.setAttribute('data-swal-confirm-click', msg);
            el.removeAttribute('onclick');
        });
        document.querySelectorAll('form[onsubmit*="confirm("]').forEach(function (form) {
            var code = form.getAttribute('onsubmit') || '';
            var msg = extractConfirmMessage(code);
            if (!msg) return;
            form.setAttribute('data-swal-confirm-submit', msg);
            form.removeAttribute('onsubmit');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', prepareInlineConfirmHooks);
    } else {
        prepareInlineConfirmHooks();
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) return;
        var trigger = target.closest('[data-swal-confirm-click]');
        if (!trigger) return;
        var msg = trigger.getAttribute('data-swal-confirm-click') || '';
        if (!msg) return;

        event.preventDefault();
        event.stopPropagation();

        askConfirm(msg, function () {
            if (trigger.tagName === 'A') {
                var href = trigger.getAttribute('href');
                if (href) window.location.href = href;
                return;
            }
            var form = trigger.closest('form');
            if (form) {
                form.dataset.swalBypass = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(trigger);
                } else {
                    form.submit();
                }
            }
        });
    }, true);

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.dataset.swalBypass === '1') {
            form.dataset.swalBypass = '';
            return;
        }
        var msg = form.getAttribute('data-swal-confirm-submit') || '';
        if (!msg) return;

        event.preventDefault();
        askConfirm(msg, function () {
            form.dataset.swalBypass = '1';
            form.submit();
        });
    }, true);
})();
</script>
<script src="<?= htmlspecialchars(app_path('assets/js/tnc-loading-overlay.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(app_path('assets/js/tnc-ajax-form.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php
$tncSoundSettingsJsPath = dirname(__DIR__) . '/assets/js/tnc-sound-settings.js';
$tncSoundSettingsJsVer = @filemtime($tncSoundSettingsJsPath);
if (!is_int($tncSoundSettingsJsVer) || $tncSoundSettingsJsVer <= 0) {
    $tncSoundSettingsJsVer = time();
}
$tncPrPoAudioJsPath = dirname(__DIR__) . '/assets/js/tnc-pr-po-audio.js';
$tncPrPoAudioJsVer = @filemtime($tncPrPoAudioJsPath);
if (!is_int($tncPrPoAudioJsVer) || $tncPrPoAudioJsVer <= 0) {
    $tncPrPoAudioJsVer = time();
}
$tncTrashAudioPath = dirname(__DIR__) . '/assets/audio/trash-delete.mp3';
$tncTrashAudioVer = @filemtime($tncTrashAudioPath);
if (!is_int($tncTrashAudioVer) || $tncTrashAudioVer <= 0) {
    $tncTrashAudioVer = time();
}
$tncNotifBellPath = dirname(__DIR__) . '/assets/audio/notification-bell.mp3';
$tncNotifBellVer = @filemtime($tncNotifBellPath);
if (!is_int($tncNotifBellVer) || $tncNotifBellVer <= 0) {
    $tncNotifBellVer = time();
}
?>
<script>
window.TNC_PR_PO_AUDIO = window.TNC_PR_PO_AUDIO || {};
window.TNC_PR_PO_AUDIO.trashDeleteUrl = <?= json_encode(app_path('assets/audio/trash-delete.mp3') . '?v=' . $tncTrashAudioVer, JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= htmlspecialchars(app_path('assets/js/tnc-sound-settings.js') . '?v=' . $tncSoundSettingsJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<script src="<?= htmlspecialchars(app_path('assets/js/tnc-pr-po-audio.js') . '?v=' . $tncPrPoAudioJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php if (isset($_SESSION['user_id'])): ?>
<script>
(function () {
    var endpoint = <?= json_encode(app_path('actions/notifications-handler.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var csrf = <?= json_encode(function_exists('csrf_token') ? csrf_token() : '', JSON_UNESCAPED_SLASHES) ?>;
    var notifBellUrl = <?= json_encode(app_path('assets/audio/notification-bell.mp3') . '?v=' . $tncNotifBellVer, JSON_UNESCAPED_SLASHES) ?>;
    var badge = document.getElementById('tncNotifBadge');
    var listEl = document.getElementById('tncNotifList');
    var toggle = document.getElementById('tncNotifToggle');
    var markAllBtn = document.getElementById('tncNotifMarkAll');
    if (!badge || !listEl) return;

    var pollMs = 3000;
    var lastChecksum = '';
    var lastUnread = 0;
    var pollTimer = null;
    var toastTimer = null;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ---------- เสียงแจ้งเตือน (ดังซ้ำจนกว่าจะอ่านครบ) ----------
    var notifBellAudio = null;
    var soundTimer = null;
    var notifSoundPending = false;

    function getNotifBellAudio() {
        if (!notifBellAudio) {
            notifBellAudio = new Audio(notifBellUrl);
            notifBellAudio.preload = 'auto';
            notifBellAudio.volume = 0.85;
        }
        return notifBellAudio;
    }

    function playNotifBell() {
        if (window.TncSoundSettings && TncSoundSettings.isNotifMuted()) {
            return;
        }
        try {
            var audio = getNotifBellAudio();
            if (!audio.src || audio.src.indexOf('notification-bell.mp3') === -1) {
                audio.src = notifBellUrl;
            }
            audio.currentTime = 0;
            var playPromise = audio.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(function () {
                    notifSoundPending = true;
                });
            }
        } catch (e) {
            notifSoundPending = true;
        }
    }

    function startSoundLoop() {
        if (window.TncSoundSettings && TncSoundSettings.isNotifMuted()) {
            stopSoundLoop();
            return;
        }
        if (soundTimer) return;
        playNotifBell();
        soundTimer = setInterval(function () {
            if (document.hidden) return;
            if (window.TncSoundSettings && TncSoundSettings.isNotifMuted()) {
                stopSoundLoop();
                return;
            }
            playNotifBell();
        }, 5000);
    }

    function stopSoundLoop() {
        if (soundTimer) { clearInterval(soundTimer); soundTimer = null; }
    }

    // ปลดล็อกเสียงหลังผู้ใช้มีการโต้ตอบหน้าเว็บครั้งแรก (ตามนโยบาย autoplay ของเบราว์เซอร์)
    function unlockAudio() {
        if (notifSoundPending) {
            notifSoundPending = false;
            playNotifBell();
        }
        document.removeEventListener('click', unlockAudio);
        document.removeEventListener('keydown', unlockAudio);
        document.removeEventListener('touchstart', unlockAudio);
    }
    document.addEventListener('click', unlockAudio);
    document.addEventListener('keydown', unlockAudio);
    document.addEventListener('touchstart', unlockAudio);

    window.addEventListener('tnc:sound-settings-changed', function (e) {
        var d = e.detail || {};
        if (d.type !== 'notif') {
            return;
        }
        if (d.muted) {
            stopSoundLoop();
        } else if (lastUnread > 0) {
            startSoundLoop();
        }
    });

    function setBadge(n) {
        n = parseInt(n, 10) || 0;
        if (n > 0) {
            badge.textContent = n > 99 ? '99+' : String(n);
            badge.classList.remove('d-none');
            startSoundLoop();
        } else {
            badge.classList.add('d-none');
            stopSoundLoop();
        }
    }

    function showToast(item) {
        if (!item || item.is_read) return;
        var existing = document.getElementById('tncNotifToast');
        if (existing) existing.remove();
        if (toastTimer) { clearTimeout(toastTimer); toastTimer = null; }

        var ok = item.type === 'pr_approved';
        var ico = ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger';
        var el = document.createElement('div');
        el.id = 'tncNotifToast';
        el.className = 'tnc-notif-toast alert alert-light shadow-sm border position-fixed d-flex align-items-start gap-2 py-2 px-3';
        el.setAttribute('role', 'alert');
        el.innerHTML = '<i class="bi ' + ico + ' fs-5 flex-shrink-0 mt-1"></i>'
            + '<div class="min-w-0 flex-grow-1">'
            +   '<div class="fw-semibold small">' + esc(item.title) + '</div>'
            +   '<div class="text-muted small text-truncate">' + esc(item.message) + '</div>'
            + '</div>'
            + '<button type="button" class="btn-close btn-close-sm ms-1" aria-label="ปิด"></button>';
        document.body.appendChild(el);

        el.querySelector('.btn-close')?.addEventListener('click', function () { el.remove(); });
        el.addEventListener('click', function (e) {
            if (e.target.closest('.btn-close')) return;
            if (item.link) { window.location.href = item.link; }
        });
        toastTimer = setTimeout(function () { el.remove(); toastTimer = null; }, 8000);
    }

    function render(items) {
        if (!items || items.length === 0) {
            listEl.innerHTML = '<div class="text-center text-muted py-4 small">ยังไม่มีการแจ้งเตือน</div>';
            return;
        }
        var html = '';
        items.forEach(function (it) {
            var ok = it.type === 'pr_approved';
            var icoCls = ok ? 'ok' : 'no';
            var ico = ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
            var unread = it.is_read ? '' : ' is-unread';
            var href = it.link ? esc(it.link) : '#';
            html += '<a class="tnc-notif-item' + unread + '" href="' + href + '" data-id="' + it.id + '">'
                + '<span class="tnc-notif-ico ' + icoCls + '"><i class="bi ' + ico + '"></i></span>'
                + '<span class="flex-grow-1 min-w-0">'
                +   '<span class="tnc-notif-title d-block">' + esc(it.title) + '</span>'
                +   '<span class="tnc-notif-msg d-block">' + esc(it.message) + '</span>'
                +   '<span class="tnc-notif-time d-block">' + esc(it.ago) + '</span>'
                + '</span>'
                + (it.is_read ? '' : '<span class="tnc-notif-dot"></span>')
                + '</a>';
        });
        listEl.innerHTML = html;
    }

    function load(showToastForNew) {
        fetch(endpoint + '?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) return;
                setBadge(d.unread);
                render(d.items);
                lastUnread = parseInt(d.unread, 10) || 0;
                if (showToastForNew && d.items && d.items.length) {
                    for (var i = 0; i < d.items.length; i++) {
                        if (!d.items[i].is_read) {
                            showToast(d.items[i]);
                            break;
                        }
                    }
                }
            })
            .catch(function () {});
    }

    function poll() {
        fetch(endpoint + '?action=poll', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) return;
                var unread = parseInt(d.unread, 10) || 0;
                var checksum = String(d.checksum || '');
                if (lastChecksum === '') {
                    lastChecksum = checksum;
                    lastUnread = unread;
                    setBadge(unread);
                    return;
                }
                if (checksum === lastChecksum) {
                    return;
                }
                var hasNew = unread > lastUnread;
                lastChecksum = checksum;
                lastUnread = unread;
                load(hasNew);
            })
            .catch(function () {});
    }

    function startPolling() {
        if (pollTimer) return;
        poll();
        pollTimer = setInterval(function () {
            if (!document.hidden) poll();
        }, pollMs);
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    function post(action, extra) {
        var fd = new FormData();
        fd.append('_csrf', csrf);
        for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) fd.append(k, extra[k]); }
        return fetch(endpoint + '?action=' + action, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    listEl.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('.tnc-notif-item') : null;
        if (!a) return;
        var id = a.getAttribute('data-id');
        var href = a.getAttribute('href');
        var wasUnread = a.classList.contains('is-unread');
        e.preventDefault();
        if (wasUnread && id) {
            post('mark_read', { id: id }).then(function (d) { if (d && d.ok) setBadge(d.unread); }).catch(function () {});
        }
        if (href && href !== '#') { window.location.href = href; }
    });

    if (markAllBtn) {
        markAllBtn.addEventListener('click', function () {
            post('mark_all_read', {}).then(function (d) { if (d && d.ok) { setBadge(0); load(); } }).catch(function () {});
        });
    }

    if (toggle) { toggle.addEventListener('click', function () { load(false); }); }
    load(false);
    startPolling();
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopPolling();
            return;
        }
        poll();
        startPolling();
    });
})();
</script>
<?php endif; ?>
