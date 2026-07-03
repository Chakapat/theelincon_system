<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/connect_database.php';
require_once __DIR__ . '/includes/tnc_audit_log.php';
require_once __DIR__ . '/includes/tnc_tailwind_assets.php';

use Theelincon\Rtdb\Db;

$login_status = '';
$user_code = '';
$password = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_request()) {
        $login_status = 'csrf_fail';
    } else {
    $user_code = trim((string) ($_POST['user_code'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $password_hash_upgraded = false;

    $user = Db::findFirst('users', static function (array $r) use ($user_code): bool {
        return isset($r['user_code']) && (string) $r['user_code'] === $user_code;
    });

    if ($user !== null) {
        $stored = (string) ($user['password'] ?? '');
        $password_ok = false;
        if ($stored !== '' && password_verify($password, $stored)) {
            $password_ok = true;
        } elseif (strlen($stored) === 32 && ctype_xdigit($stored) && hash_equals($stored, md5($password))) {
            $password_ok = true;
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $uid = (string) ($user['userid'] ?? '');
            if ($uid !== '') {
                Db::mergeRow('users', $uid, ['password' => $newHash]);
                $password_hash_upgraded = true;
            }
        }
        if ($password_ok) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) ($user['userid'] ?? 0);
            $_SESSION['name'] = trim((string) (($user['fname'] ?? '') . ' ' . ($user['lname'] ?? '')));
            $_SESSION['role'] = (string) ($user['role'] ?? 'USER');
            unset($_SESSION['position']);
            $login_status = 'success';
            if ($password_hash_upgraded) {
                $uidStr = (string) ($user['userid'] ?? '');
                $ucode = trim((string) ($user['user_code'] ?? ''));
                tnc_audit_log('update', 'user', $uidStr, $ucode !== '' ? $ucode : ('ผู้ใช้ #' . $uidStr), [
                    'source' => 'sign-in.php',
                    'action' => 'password_hash_upgrade',
                    'meta' => [
                        'from' => 'md5_legacy',
                        'to' => 'password_hash_bcrypt',
                    ],
                ]);
            }
        } else {
            $login_status = 'fail_password';
        }
    } else {
        $login_status = 'fail_user';
    }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ | Theelincon Office</title>
    <?php tnc_bootstrap_icons_tag(); ?>
    <?php tnc_sarabun_font_tag(); ?>
    <?php tnc_tailwind_css_tag(); ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="signin-page">
<i class="bi bi-coin signin-floating-icon i1" aria-hidden="true"></i>
<i class="bi bi-shield-lock signin-floating-icon i2" aria-hidden="true"></i>
<i class="bi bi-cash-stack signin-floating-icon i3" aria-hidden="true"></i>
<i class="bi bi-buildings signin-floating-icon i4" aria-hidden="true"></i>
<i class="bi bi-receipt-cutoff signin-floating-icon i5" aria-hidden="true"></i>
<i class="bi bi-safe2 signin-floating-icon i6" aria-hidden="true"></i>
<i class="bi bi-file-earmark-bar-graph signin-floating-icon i7" aria-hidden="true"></i>
<i class="bi bi-bank2 signin-floating-icon i8" aria-hidden="true"></i>
<i class="bi bi-piggy-bank signin-floating-icon i9" aria-hidden="true"></i>
<i class="bi bi-briefcase signin-floating-icon i10" aria-hidden="true"></i>
<i class="bi bi-graph-up-arrow signin-floating-icon i11" aria-hidden="true"></i>
<i class="bi bi-wallet2 signin-floating-icon i12" aria-hidden="true"></i>
<i class="bi bi-journal-check signin-floating-icon i13" aria-hidden="true"></i>
<i class="bi bi-building-check signin-floating-icon i14" aria-hidden="true"></i>
<i class="bi bi-archive signin-floating-icon i15" aria-hidden="true"></i>
<i class="bi bi-credit-card-2-front signin-floating-icon i16" aria-hidden="true"></i>
<i class="bi bi-calculator signin-floating-icon i17" aria-hidden="true"></i>
<i class="bi bi-file-earmark-lock2 signin-floating-icon i18" aria-hidden="true"></i>

<div class="signin-shell flex min-h-screen items-center justify-center px-4 py-6">
    <div class="w-full max-w-md">
        <div class="signin-card rounded-2xl overflow-hidden">
            <div class="signin-card-header py-4 text-center">
                <?php $tncSigninLogoUrl = function_exists('tnc_company_logo_light_url') ? tnc_company_logo_light_url() : ''; ?>
                <?php if ($tncSigninLogoUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($tncSigninLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="THEELIN CON" class="signin-brand-logo">
                <?php else: ?>
                    <div class="signin-hero-icon inline-flex items-center justify-center rounded-full bg-white text-tnc-orange shadow-sm">
                        <i class="bi bi-box-arrow-in-right text-2xl"></i>
                    </div>
                <?php endif; ?>
            </div>

            <div class="signin-card-body p-8 pt-6">
                <div class="text-center mb-6">
                    <h1 class="text-xl font-bold text-gray-800 mb-1">สวัสดี</h1>
                    <p class="signin-subtitle text-sm mb-0">ระบบสำนักงาน Theelincon</p>
                </div>

                <form method="POST" action="<?= htmlspecialchars(app_path('sign-in.php')) ?>" id="signinForm">
                    <?php csrf_field(); ?>
                    <div class="mb-4">
                        <label class="signin-field-label block text-sm font-bold mb-1.5" for="user_code">รหัสผู้ใช้</label>
                        <div class="signin-input-group">
                            <span class="signin-input-prefix" aria-hidden="true">
                                <i class="bi bi-person-fill"></i>
                            </span>
                            <input type="text" name="user_code" id="user_code" class="signin-input-field" placeholder="รหัสผู้ใช้" required autocomplete="username" value="<?= htmlspecialchars($user_code, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>

                    <div class="mb-5">
                        <label class="signin-field-label block text-sm font-bold mb-1.5" for="password">รหัสผ่าน</label>
                        <div class="signin-input-group">
                            <span class="signin-input-prefix" aria-hidden="true">
                                <i class="bi bi-key-fill"></i>
                            </span>
                            <input type="password" name="password" id="password" class="signin-input-field" placeholder="รหัสผ่าน" required autocomplete="current-password">
                            <button type="button" class="signin-password-toggle" id="togglePasswordBtn" aria-label="แสดงหรือซ่อนรหัสผ่าน" aria-pressed="false">
                                <i class="bi bi-eye" id="togglePasswordIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-5 flex items-center gap-2">
                        <input type="checkbox" name="remember" value="1" id="rememberCreds" class="signin-remember h-4 w-4 rounded border-gray-300">
                        <label class="text-sm text-gray-500 cursor-pointer" for="rememberCreds">จดจำชื่อผู้ใช้</label>
                    </div>

                    <button type="submit" class="btn-signin w-full rounded-xl py-3 text-base font-bold shadow-sm mb-3">
                        เข้าสู่ระบบ<i class="bi bi-chevron-right ml-1"></i>
                    </button>
                </form>

                <div class="text-center mt-6">
                    <hr class="border-gray-200/60 mb-4">
                    <p class="signin-copy mb-0 font-light">
                        © 2026 <span class="font-bold text-tnc-orange">THEELIN CON CO.,LTD.</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="signin-success-overlay" id="signinSuccessOverlay" aria-hidden="true">
    <div class="signin-success-box">
        <div class="signin-confetti-layer" id="signinConfettiLayer" aria-hidden="true"></div>
        <div class="signin-success-check">
            <svg viewBox="0 0 64 64" aria-hidden="true">
                <path class="check-path" d="M16 34 L28 46 L48 20"></path>
            </svg>
        </div>
        <div class="signin-success-title">Welcome to THEELIN CON</div>
        <div class="signin-success-subtitle">เข้าสู่ระบบสำเร็จ กำลังเตรียมแดชบอร์ดให้คุณ...</div>
        <div class="signin-success-progress"><div class="signin-success-progress-fill" id="signinSuccessProgress"></div></div>
    </div>
</div>

<script>
(function () {
    var K_USER = 'theelincon_signin_user_code';
    var K_PASS_LEGACY = 'theelincon_signin_password';
    try {
        localStorage.removeItem(K_PASS_LEGACY);
        var u = localStorage.getItem(K_USER);
        var userEl = document.getElementById('user_code');
        var chk = document.getElementById('rememberCreds');
        if (u && userEl) {
            userEl.value = u;
            if (chk) chk.checked = true;
        }
    } catch (e) {}
})();

(function () {
    var passEl = document.getElementById('password');
    var btn = document.getElementById('togglePasswordBtn');
    var icon = document.getElementById('togglePasswordIcon');
    if (!passEl || !btn || !icon) return;

    btn.addEventListener('click', function () {
        var hidden = passEl.type === 'password';
        passEl.type = hidden ? 'text' : 'password';
        icon.className = hidden ? 'bi bi-eye-slash' : 'bi bi-eye';
        btn.setAttribute('aria-pressed', hidden ? 'true' : 'false');
    });
})();

function triggerLoginShake() {
    var card = document.querySelector('.signin-card');
    if (!card) return;
    card.classList.remove('is-shaking');
    void card.offsetWidth;
    card.classList.add('is-shaking');
}

function runLoginSuccessExperience(redirectUrl) {
    var overlay = document.getElementById('signinSuccessOverlay');
    var progress = document.getElementById('signinSuccessProgress');
    var confettiLayer = document.getElementById('signinConfettiLayer');
    if (!overlay || !progress || !confettiLayer) {
        window.location.href = redirectUrl;
        return;
    }
    progress.classList.remove('is-running');
    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    var colors = ['#ea580c', '#f59e0b', '#10b981', '#3b82f6', '#ef4444'];
    for (var i = 0; i < 26; i++) {
        var piece = document.createElement('span');
        piece.className = 'signin-confetti';
        piece.style.left = (Math.random() * 100) + '%';
        piece.style.top = (Math.random() * 28) + '%';
        piece.style.background = colors[i % colors.length];
        piece.style.animationDelay = (Math.random() * 220) + 'ms';
        confettiLayer.appendChild(piece);
    }

    requestAnimationFrame(function () {
        progress.classList.add('is-running');
    });

    setTimeout(function () {
        document.body.classList.add('signin-fadeout');
        setTimeout(function () {
            window.location.href = redirectUrl;
        }, 360);
    }, 2850);
}

<?php if ($login_status === 'success'): ?>
    <?php
    $remember_user = isset($_POST['remember']) && (string) $_POST['remember'] === '1';
    $user_code_js = json_encode($user_code, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    ?>
    (function () {
        var K_USER = 'theelincon_signin_user_code';
        var K_PASS_LEGACY = 'theelincon_signin_password';
        try {
            localStorage.removeItem(K_PASS_LEGACY);
            <?php if ($remember_user): ?>
            localStorage.setItem(K_USER, <?= $user_code_js ?>);
            <?php else: ?>
            localStorage.removeItem(K_USER);
            <?php endif; ?>
        } catch (e) {}
    })();
    runLoginSuccessExperience("<?= htmlspecialchars(app_path('index.php')) ?>");
<?php elseif ($login_status === 'csrf_fail'): ?>
    Swal.fire({ icon: 'error', title: 'เซสชันไม่ปลอดภัย', text: 'กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง', confirmButtonColor: '#ea580c' });
<?php elseif ($login_status === 'fail_password'): ?>
    triggerLoginShake();
    document.getElementById('password')?.focus();
<?php elseif ($login_status === 'fail_user'): ?>
    triggerLoginShake();
    document.getElementById('user_code')?.focus();
<?php endif; ?>
</script>
</body>
</html>
