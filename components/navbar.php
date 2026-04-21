<?php
if (!function_exists('app_path')) {
    require_once __DIR__ . '/../config/foundation.php';
}

$announcement_gate_items = [];
$announcement_unread_nav = 0;
if (isset($_SESSION['user_id'])) {
    $meNav = (int) $_SESSION['user_id'];
    if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
        $announcement_gate_items = \Theelincon\Rtdb\Portal::announcementGateItems($meNav);
        $announcement_unread_nav = count($announcement_gate_items);
    }
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
                        <li>
                            <a class="dropdown-item rounded-2 mx-1 d-flex justify-content-between align-items-center" href="<?= htmlspecialchars(app_path('pages/announcements.php'), ENT_QUOTES, 'UTF-8') ?>">
                                <span><i class="bi bi-megaphone me-2 text-warning"></i>ข่าวสารภายใน</span>
                                <?php if ($announcement_unread_nav > 0): ?>
                                    <span class="badge bg-warning text-dark rounded-pill"><?= $announcement_unread_nav > 99 ? '99+' : (int) $announcement_unread_nav ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item rounded-2 mx-1 d-flex justify-content-between align-items-center" href="<?= htmlspecialchars(app_path('pages/chat.php'), ENT_QUOTES, 'UTF-8') ?>">
                                <span><i class="bi bi-chat-dots me-2 text-secondary"></i>แชทภายใน</span>
                                <span id="chatNavUnread" class="badge bg-danger rounded-pill d-none">0</span>
                            </a>
                        </li>
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                        <li><hr class="dropdown-divider mx-2 my-1"></li>
                        <li>
                            <a class="dropdown-item rounded-2 mx-1" href="<?= htmlspecialchars(app_path('pages/member-manage.php'), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-person-gear me-2 text-secondary"></i>จัดการสมาชิก
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item rounded-2 mx-1" href="<?= htmlspecialchars(app_path('pages/employment-certificate.php'), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-file-earmark-medical me-2 text-secondary"></i>หนังสือรับรองการทำงาน
                            </a>
                        </li>
                        <?php endif; ?>
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
        </div>
    </div>
</nav>
<?php include __DIR__ . '/announcement-gate-modal.php'; ?>
<?php if (isset($_SESSION['user_id'])): ?>
<script src="https://cdn.socket.io/4.7.5/socket.io.min.js" crossorigin="anonymous"></script>
<script>
window.__THEELIN_SOCKET_CFG__ = {
    tokenUrl: <?= json_encode(app_path('actions/socket-token.php'), JSON_UNESCAPED_SLASHES) ?>,
    chatUnreadUrl: <?= json_encode(app_path('actions/chat-api.php') . '?action=unread_total', JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="<?= htmlspecialchars(app_path('assets/js/theelin-socket.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
<script>
(function () {
    const path = (window.location.pathname || '').toLowerCase();
    const protectedPages = [
        '/pages/invoice-create.php',
        '/pages/invoice-edit.php',
        '/pages/quotation-create.php',
        '/pages/quotation-edit.php',
        '/pages/purchase-request-create.php',
        '/pages/purchase-order-from-pr.php',
        '/pages/tax-invoice-receipt.php'
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
