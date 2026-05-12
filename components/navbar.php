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

    /* Pretty confirm popup style (logout) */
    .swal2-container.tnc-logout-container {
        background: rgba(15, 23, 42, 0.34) !important;
        backdrop-filter: blur(7px);
        -webkit-backdrop-filter: blur(7px);
    }
    .swal2-popup.tnc-logout-popup {
        width: min(92vw, 430px) !important;
        border-radius: 18px !important;
        border: 1px solid rgba(255, 255, 255, 0.46) !important;
        background: rgba(255, 255, 255, 0.88) !important;
        box-shadow: 0 1rem 2.2rem rgba(0, 0, 0, 0.24) !important;
        padding: 1.35rem 1.25rem 1.1rem !important;
    }
    .swal2-popup.tnc-logout-popup .swal2-title {
        color: #9a3412 !important;
        font-weight: 800 !important;
        letter-spacing: 0.01em;
        font-size: 1.12rem !important;
        margin-top: .15rem !important;
    }
    .swal2-popup.tnc-logout-popup .swal2-html-container {
        color: #4b5563 !important;
        font-size: 0.94rem !important;
        line-height: 1.65 !important;
        margin-top: .2rem !important;
    }
    .swal2-popup.tnc-logout-popup .swal2-actions {
        gap: 0.45rem;
        width: 100%;
        margin-top: 1rem !important;
    }
    .swal2-popup.tnc-logout-popup .swal2-confirm,
    .swal2-popup.tnc-logout-popup .swal2-cancel {
        border-radius: 999px !important;
        font-weight: 700 !important;
        padding: 0.62rem 1.15rem !important;
        min-height: 44px !important;
    }
    .swal2-popup.tnc-logout-popup .swal2-confirm {
        flex: 1 1 auto;
        background: linear-gradient(135deg, #fd7e14 0%, #f76707 100%) !important;
        box-shadow: 0 .45rem .95rem rgba(253,126,20,.28) !important;
    }
    .swal2-popup.tnc-logout-popup .swal2-cancel {
        flex: 0 0 auto;
        color: #475569 !important;
        border: 1px solid rgba(100, 116, 139, 0.28) !important;
        background: rgba(255, 255, 255, 0.74) !important;
    }
    .tnc-logout-question {
        width: 72px;
        height: 72px;
        margin: 0 auto .28rem;
        border-radius: 999px;
        border: 1px solid rgba(253, 126, 20, 0.28);
        background: rgba(253, 126, 20, 0.1);
        color: #ea580c;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        transform-origin: 72% 72%;
        animation: tncLogoutWave 1.3s ease-in-out infinite;
    }
    @keyframes tncLogoutWave {
        0%, 100% { transform: rotate(0deg); }
        20% { transform: rotate(14deg); }
        40% { transform: rotate(-8deg); }
        60% { transform: rotate(10deg); }
        80% { transform: rotate(-4deg); }
    }
    #goodbye-overlay {
        position: fixed;
        inset: 0;
        z-index: 4000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        background:
            radial-gradient(1200px 520px at -10% -10%, rgba(253, 126, 20, 0.22), transparent 55%),
            radial-gradient(900px 460px at 110% 110%, rgba(255, 193, 7, 0.18), transparent 50%),
            rgba(15, 23, 42, 0.34);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
    }
    #goodbye-overlay.show {
        display: flex;
        animation: tncGoodbyeOverlayIn .35s ease both;
    }
    #goodbye-overlay {
        z-index: 9999 !important;
    }
    .tnc-goodbye-card {
        width: min(92vw, 420px);
        border-radius: 18px;
        border: 1px solid rgba(255, 255, 255, 0.46);
        background: rgba(255, 255, 255, 0.88);
        box-shadow: 0 1rem 2.1rem rgba(0, 0, 0, 0.24);
        text-align: center;
        padding: 1.35rem 1.2rem 1.05rem;
    }
    .tnc-goodbye-hand {
        width: 78px;
        height: 78px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(253, 126, 20, 0.14);
        border: 1px solid rgba(253, 126, 20, 0.28);
        color: #ea580c;
        font-size: 2.15rem;
        transform-origin: 70% 75%;
        animation: tncGoodbyeWave .95s ease-in-out infinite;
    }
    .tnc-goodbye-title {
        margin-top: .85rem;
        font-size: 1.08rem;
        font-weight: 800;
        color: #9a3412;
        letter-spacing: .01em;
        opacity: 0;
        animation: tncGoodbyeTextIn .5s ease .15s forwards;
    }
    .tnc-goodbye-sub {
        margin-top: .15rem;
        font-size: .9rem;
        color: #64748b;
        opacity: 0;
        animation: tncGoodbyeTextIn .5s ease .28s forwards;
    }
    .tnc-goodbye-lottie {
        width: 86px;
        height: 86px;
        margin: 0 auto .35rem;
        display: none;
    }
    #goodbye-overlay[data-lottie-ready="1"] .tnc-goodbye-lottie {
        display: block;
    }
    #goodbye-overlay[data-lottie-ready="1"] .tnc-goodbye-hand {
        display: none;
    }
    @keyframes tncGoodbyeOverlayIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    @keyframes tncGoodbyeWave {
        0%, 100% { transform: rotate(0deg); }
        15% { transform: rotate(16deg); }
        30% { transform: rotate(-8deg); }
        45% { transform: rotate(13deg); }
        60% { transform: rotate(-5deg); }
        75% { transform: rotate(8deg); }
    }
    @keyframes tncGoodbyeTextIn {
        from { opacity: 0; transform: translateY(4px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @media (max-width: 575.98px) {
        .swal2-popup.tnc-logout-popup {
            padding: 1.15rem .95rem .95rem !important;
        }
        .swal2-popup.tnc-logout-popup .swal2-actions {
            flex-direction: column-reverse;
        }
        .swal2-popup.tnc-logout-popup .swal2-cancel,
        .swal2-popup.tnc-logout-popup .swal2-confirm {
            width: 100%;
        }
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
                        <li>
                            <a class="dropdown-item rounded-2 mx-1 text-danger fw-semibold" href="<?= htmlspecialchars(app_path('sign-out.php'), ENT_QUOTES, 'UTF-8') ?>" data-swal-logout="1">
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
<script src="https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js"></script>
<?php if (isset($_SESSION['user_id'])): ?>
<script src="<?= htmlspecialchars(app_path('assets/js/tnc-delete-confirm.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<script>
(function () {
    const path = (window.location.pathname || '').toLowerCase();
    const protectedPages = [
        '/pages/invoices/invoice.php',
        '/pages/quotations/quotation-create.php',
        '/pages/quotations/quotation-edit.php',
        '/pages/purchase/purchase-request-create.php',
        '/pages/purchase/purchase-order-from-pr.php',
        '/pages/invoices/tax-invoice-receipt.php',
        '/pages/tools/money-receipt-issue.php'
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

    function playGoodbyeThenRedirect(href) {
        if (!href) {
            return;
        }
        var existing = document.getElementById('goodbye-overlay');
        if (existing) {
            existing.remove();
        }
        var overlay = document.createElement('div');
        overlay.id = 'goodbye-overlay';
        overlay.innerHTML =
            '<div class="tnc-goodbye-card">' +
                '<lottie-player class="tnc-goodbye-lottie" src="https://assets2.lottiefiles.com/packages/lf20_6wutsrox.json" background="transparent" speed="1" loop autoplay></lottie-player>' +
                '<div class="tnc-goodbye-hand" aria-hidden="true">👋</div>' +
                '<div class="tnc-goodbye-title">แล้วพบกันใหม่นะครับ</div>' +
                '<div class="tnc-goodbye-sub">See you again soon!</div>' +
            '</div>';
        document.body.appendChild(overlay);
        if (overlay.querySelector('lottie-player')) {
            overlay.setAttribute('data-lottie-ready', '1');
        }
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            overlay.classList.add('show');
        });

        setTimeout(function () {
            window.location.href = href;
        }, 2000);
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
        var logoutTrigger = target.closest('[data-swal-logout]');
        if (logoutTrigger) {
            event.preventDefault();
            event.stopPropagation();
            Swal.fire({
                title: 'ยืนยันออกจากระบบ',
                html: '<div class="tnc-logout-question" aria-hidden="true">👋</div><div>คุณต้องการออกจากระบบตอนนี้ใช่หรือไม่?<br><span style="color:#6b7280">ระบบจะพาคุณไปหน้าเข้าสู่ระบบทันที</span></div>',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-box-arrow-right me-1"></i>ออกจากระบบ',
                cancelButtonText: 'ยกเลิก',
                reverseButtons: true,
                customClass: { container: 'tnc-logout-container', popup: 'tnc-logout-popup' }
            }).then(function (res) {
                if (!res.isConfirmed) return;
                var href = logoutTrigger.getAttribute('href');
                playGoodbyeThenRedirect(href);
            });
            return;
        }
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
