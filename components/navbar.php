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
</style>
<nav class="navbar navbar-expand-lg navbar-dark shadow-sm mb-3 mb-md-4" style="background-color: #fd7e14;">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2 flex-shrink-0" href="<?= htmlspecialchars(app_path('index.php')) ?>">
            <i class="bi bi-receipt-cutoff"></i>
            <span class="d-none d-sm-inline">THEELIN CON CO.,LTD.</span>
            <span class="d-inline d-sm-none">TNC</span>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="เมนู">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <?php if (isset($_SESSION['user_id'])): ?>
            <ul class="navbar-nav ms-auto navbar-hub flex-wrap align-items-lg-center py-2 py-lg-0">
                <li class="nav-item dropdown nav-hub-block">
                    <a class="nav-link dropdown-toggle text-white fw-semibold px-2 px-lg-3 py-2" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
                        <span class="nav-hub-toggle-inner text-start">
                            <i class="bi bi-person-circle nav-hub-ico flex-shrink-0" aria-hidden="true"></i>
                            <span class="nav-hub-label">
                                <span class="d-inline d-lg-none">บัญชี</span>
                                <span class="d-none d-lg-inline-block text-truncate align-bottom" style="max-width: 9rem;"><?= htmlspecialchars($_SESSION['name'] ?? 'ผู้ใช้งาน', ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2" style="min-width: 13rem;" aria-labelledby="userDropdown">
                        <?php if (user_is_admin_role()): ?>
                        <li>
                            <a class="dropdown-item rounded-2 mx-1" href="<?= htmlspecialchars(app_path('pages/organization/member-manage.php'), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-person-gear me-2 text-secondary"></i>จัดการสมาชิก
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item rounded-2 mx-1" href="<?= htmlspecialchars(app_path('pages/tools/employment-certificate.php'), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-file-earmark-medical me-2 text-secondary"></i>หนังสือรับรองการทำงาน
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
        </div>
    </div>
</nav>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
    const path = (window.location.pathname || '').toLowerCase();
    const protectedPages = [
        '/pages/invoices/invoice.php',
        '/pages/quotations/quotation-create.php',
        '/pages/quotations/quotation-edit.php',
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
