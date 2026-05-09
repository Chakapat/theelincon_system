<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/connect_database.php';
require_once __DIR__ . '/includes/tnc_audit_log.php';

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
    <title>Login | Invoice System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --tnc-orange: #fd7e14;
            --tnc-orange-strong: #e8590c;
            --tnc-text: #1f2937;
            --tnc-muted: #6b7280;
        }
        body.signin-page {
            font-family: 'Sarabun', sans-serif;
            min-height: 100vh;
            background:
                radial-gradient(1200px 500px at -10% -10%, rgba(253, 126, 20, 0.28), transparent 55%),
                radial-gradient(900px 480px at 110% 110%, rgba(255, 193, 7, 0.24), transparent 50%),
                linear-gradient(140deg, #fff7ed 0%, #fff3e0 48%, #ffedd5 100%);
            color: var(--tnc-text);
            position: relative;
            overflow-x: hidden;
        }
        body.signin-page::before,
        body.signin-page::after {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
        }
        /* soft "background image" texture layer */
        body.signin-page::before {
            background:
                linear-gradient(120deg, rgba(255,255,255,0.75) 0%, rgba(255,255,255,0.05) 65%),
                radial-gradient(circle at 22% 22%, rgba(253,126,20,0.18) 0 1px, transparent 1px),
                radial-gradient(circle at 78% 78%, rgba(245,158,11,0.16) 0 1px, transparent 1px);
            background-size: auto, 22px 22px, 28px 28px;
            mix-blend-mode: multiply;
            opacity: 0.55;
        }
        /* moving light beam layer */
        body.signin-page::after {
            background: linear-gradient(110deg, transparent 15%, rgba(255,255,255,0.45) 45%, transparent 75%);
            transform: translateX(-70%);
            animation: signinShimmer 9s linear infinite;
        }
        .signin-shell {
            min-height: 100vh;
            padding: 1rem 0;
            position: relative;
            z-index: 2;
        }
        .signin-card {
            border: 1px solid rgba(255, 255, 255, 0.52);
            background: rgba(255, 255, 255, 0.84);
            backdrop-filter: blur(8px);
            box-shadow: 0 1rem 2.2rem rgba(233, 89, 12, 0.16);
            position: relative;
            overflow: hidden;
        }
        .signin-card::before {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(620px 220px at -10% 0%, rgba(253,126,20,0.15), transparent 62%);
            pointer-events: none;
        }
        .signin-card .card-header {
            background: linear-gradient(135deg, var(--tnc-orange) 0%, #ff922b 100%);
        }
        .signin-hero-icon {
            width: 64px;
            height: 64px;
            border: 2px solid rgba(255, 255, 255, 0.72);
            position: relative;
            animation: signinIconFloat 3.6s ease-in-out infinite;
        }
        .signin-hero-icon::after {
            content: "";
            position: absolute;
            inset: -8px;
            border-radius: 999px;
            border: 2px solid rgba(255, 255, 255, 0.35);
            animation: signinPulseRing 2.4s ease-out infinite;
        }
        .signin-subtitle {
            color: var(--tnc-muted);
            letter-spacing: 0.01em;
        }
        .signin-field-label {
            color: #374151;
        }
        .signin-input-group .input-group-text {
            background: #fff;
            border-color: #e5e7eb;
            color: var(--tnc-orange);
        }
        .signin-input-group .form-control {
            border-color: #e5e7eb;
            background: #fff;
        }
        .signin-input-group .form-control:focus {
            border-color: rgba(253, 126, 20, 0.55);
            box-shadow: 0 0 0 0.2rem rgba(253, 126, 20, 0.16);
        }
        .signin-password-toggle {
            border-color: #e5e7eb;
            background: #fff;
            color: #6b7280;
            min-width: 2.7rem;
        }
        .signin-password-toggle:hover {
            background: #f8fafc;
            color: #374151;
        }
        .signin-card.is-shaking {
            animation: signinShake 0.38s ease-in-out 1;
        }
        @keyframes signinShake {
            0% { transform: translateX(0); }
            20% { transform: translateX(-8px); }
            40% { transform: translateX(7px); }
            60% { transform: translateX(-5px); }
            80% { transform: translateX(4px); }
            100% { transform: translateX(0); }
        }
        .btn-signin {
            background: linear-gradient(135deg, var(--tnc-orange) 0%, var(--tnc-orange-strong) 100%);
            border: none;
            color: #fff;
            letter-spacing: 0.02em;
        }
        .btn-signin:hover {
            color: #fff;
            filter: brightness(1.03);
            transform: translateY(-1px);
        }
        .signin-copy {
            font-size: 0.78rem;
            color: var(--tnc-muted);
        }
        .signin-success-overlay {
            position: fixed;
            inset: 0;
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(15, 23, 42, 0.28);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        .signin-success-overlay.show { display: flex; }
        .signin-success-box {
            width: min(92vw, 440px);
            border-radius: 18px;
            border: 1px solid rgba(255, 255, 255, 0.45);
            background: rgba(255, 255, 255, 0.88);
            box-shadow: 0 1.05rem 2.2rem rgba(15, 23, 42, 0.22);
            padding: 1.25rem 1.1rem 1.05rem;
            position: relative;
            overflow: hidden;
            text-align: center;
        }
        .signin-success-title {
            font-weight: 800;
            color: #1f2937;
            letter-spacing: 0.01em;
            margin-bottom: 0.2rem;
            font-size: clamp(1.18rem, 2.8vw, 1.45rem);
        }
        .signin-success-subtitle {
            color: #4b5563;
            font-size: 0.92rem;
            margin-bottom: 0.95rem;
        }
        .signin-success-check {
            width: 82px;
            height: 82px;
            margin: 0 auto 0.75rem;
            border-radius: 999px;
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.28);
            display: grid;
            place-items: center;
        }
        .signin-success-check svg {
            width: 56px;
            height: 56px;
        }
        .signin-success-check .check-path {
            stroke: #10b981;
            stroke-width: 4.2;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-dasharray: 80;
            stroke-dashoffset: 80;
        }
        .signin-success-overlay.show .signin-success-check .check-path {
            animation: drawCheck 0.72s ease forwards;
        }
        .signin-success-progress {
            margin-top: 0.9rem;
            width: 100%;
            height: 0.32rem;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.26);
            overflow: hidden;
        }
        .signin-success-progress-fill {
            width: 0%;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #fd7e14 0%, #f59e0b 100%);
            transition: width 3s linear;
        }
        .signin-confetti-layer {
            position: absolute;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
        }
        .signin-confetti {
            position: absolute;
            width: 8px;
            height: 12px;
            border-radius: 2px;
            opacity: 0.9;
            animation: confettiFall 900ms ease-out forwards;
        }
        body.signin-fadeout {
            opacity: 0;
            transition: opacity 0.35s ease;
        }
        @keyframes drawCheck {
            to { stroke-dashoffset: 0; }
        }
        @keyframes confettiFall {
            0% { transform: translateY(-25px) rotate(0deg); opacity: 0.95; }
            100% { transform: translateY(180px) rotate(220deg); opacity: 0; }
        }
        .signin-floating-icon {
            position: fixed;
            z-index: 1;
            pointer-events: none;
            color: rgba(253, 126, 20, 0.32);
            text-shadow:
                0 10px 20px rgba(253, 126, 20, 0.2),
                0 0 10px rgba(255, 196, 128, 0.35);
            filter: saturate(1.1);
            opacity: 0.9;
            animation-duration: var(--dur, 10s);
            animation-delay: var(--delay, 0s);
            animation-iteration-count: infinite;
            animation-timing-function: ease-in-out;
        }
        .signin-floating-icon.i1 { top: 11%; left: 8%; font-size: 1.9rem; --dur: 5.9s; --delay: -1.5s; animation-name: signinOrbitA; }
        .signin-floating-icon.i2 { top: 21%; right: 10%; font-size: 2.05rem; --dur: 6.3s; --delay: -3s; animation-name: signinOrbitB; }
        .signin-floating-icon.i3 { bottom: 19%; left: 11%; font-size: 2.25rem; --dur: 6.6s; --delay: -2.1s; animation-name: signinOrbitC; }
        .signin-floating-icon.i4 { bottom: 13%; right: 12%; font-size: 1.85rem; --dur: 5.95s; --delay: -4s; animation-name: signinOrbitA; }
        .signin-floating-icon.i5 { top: 36%; left: 4%; font-size: 1.45rem; --dur: 7.1s; --delay: -5.2s; opacity: 0.58; animation-name: signinOrbitB; }
        .signin-floating-icon.i6 { top: 9%; right: 23%; font-size: 1.3rem; --dur: 6.7s; --delay: -1.1s; opacity: 0.54; animation-name: signinOrbitC; }
        .signin-floating-icon.i7 { bottom: 30%; right: 4%; font-size: 1.5rem; --dur: 7.4s; --delay: -3.7s; opacity: 0.56; animation-name: signinOrbitA; }
        .signin-floating-icon.i8 { bottom: 7%; left: 24%; font-size: 1.35rem; --dur: 6.95s; --delay: -6s; opacity: 0.52; animation-name: signinOrbitB; }
        .signin-floating-icon.i9 { top: 14%; left: 24%; font-size: 1.2rem; --dur: 6.1s; --delay: -2.7s; opacity: 0.45; animation-name: signinOrbitC; }
        .signin-floating-icon.i10 { top: 30%; right: 22%; font-size: 1.55rem; --dur: 7.2s; --delay: -4.8s; opacity: 0.48; animation-name: signinOrbitA; }
        .signin-floating-icon.i11 { bottom: 21%; left: 30%; font-size: 1.25rem; --dur: 6.2s; --delay: -3.2s; opacity: 0.42; animation-name: signinOrbitB; }
        .signin-floating-icon.i12 { top: 42%; right: 8%; font-size: 1.4rem; --dur: 6.9s; --delay: -5.5s; opacity: 0.47; animation-name: signinOrbitC; }
        .signin-floating-icon.i13 { bottom: 10%; right: 28%; font-size: 1.3rem; --dur: 6.4s; --delay: -1.8s; opacity: 0.44; animation-name: signinOrbitA; }
        .signin-floating-icon.i14 { top: 6%; left: 42%; font-size: 1.15rem; --dur: 5.8s; --delay: -3.6s; opacity: 0.4; animation-name: signinOrbitB; }
        .signin-floating-icon.i15 { bottom: 36%; left: 6%; font-size: 1.5rem; --dur: 7s; --delay: -4.4s; opacity: 0.46; animation-name: signinOrbitC; }
        .signin-floating-icon.i16 { top: 25%; right: 2%; font-size: 1.25rem; --dur: 6.5s; --delay: -2.3s; opacity: 0.43; animation-name: signinOrbitA; }
        .signin-floating-icon.i17 { bottom: 4%; left: 40%; font-size: 1.1rem; --dur: 5.7s; --delay: -5.1s; opacity: 0.38; animation-name: signinOrbitB; }
        .signin-floating-icon.i18 { top: 48%; left: 18%; font-size: 1.35rem; --dur: 6.8s; --delay: -1.9s; opacity: 0.45; animation-name: signinOrbitC; }

        @keyframes signinIconFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-4px); }
        }
        @keyframes signinPulseRing {
            0% { transform: scale(0.9); opacity: 0.55; }
            70% { transform: scale(1.12); opacity: 0; }
            100% { transform: scale(1.12); opacity: 0; }
        }
        @keyframes signinOrbitA {
            0% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(7px, -9px) rotate(5deg); }
            50% { transform: translate(14px, 2px) rotate(0deg); }
            75% { transform: translate(5px, 9px) rotate(-5deg); }
            100% { transform: translate(0, 0) rotate(0deg); }
        }
        @keyframes signinOrbitB {
            0% { transform: translate(0, 0) rotate(0deg); }
            30% { transform: translate(-9px, -6px) rotate(-4deg); }
            60% { transform: translate(-14px, 4px) rotate(3deg); }
            85% { transform: translate(-5px, 10px) rotate(-2deg); }
            100% { transform: translate(0, 0) rotate(0deg); }
        }
        @keyframes signinOrbitC {
            0% { transform: translate(0, 0) rotate(0deg); }
            20% { transform: translate(5px, -10px) rotate(4deg); }
            48% { transform: translate(-6px, -4px) rotate(-4deg); }
            72% { transform: translate(-10px, 8px) rotate(2deg); }
            100% { transform: translate(0, 0) rotate(0deg); }
        }
        @keyframes signinShimmer {
            from { transform: translateX(-70%); }
            to { transform: translateX(120%); }
        }

        @media (prefers-reduced-motion: reduce) {
            body.signin-page::after,
            .signin-hero-icon,
            .signin-hero-icon::after,
            .signin-floating-icon {
                animation: none !important;
            }
        }
        @media (max-width: 575.98px) {
            .signin-shell {
                padding: 0.75rem 0;
            }
            .signin-floating-icon {
                display: none;
            }
            .signin-card .card-body {
                padding: 1.1rem !important;
            }
            .btn-signin {
                min-height: 2.8rem;
            }
        }
        @media (max-width: 991.98px) {
            .signin-floating-icon.i5,
            .signin-floating-icon.i6,
            .signin-floating-icon.i7,
            .signin-floating-icon.i8,
            .signin-floating-icon.i9,
            .signin-floating-icon.i10,
            .signin-floating-icon.i11,
            .signin-floating-icon.i12,
            .signin-floating-icon.i13,
            .signin-floating-icon.i14,
            .signin-floating-icon.i15,
            .signin-floating-icon.i16,
            .signin-floating-icon.i17,
            .signin-floating-icon.i18 {
                display: none;
            }
        }
    </style>
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

<div class="container">
    <div class="row signin-shell align-items-center justify-content-center">
        <div class="col-12 col-sm-8 col-md-6 col-lg-4">
            
            <div class="card signin-card border-0 rounded-4 overflow-hidden">
                <div class="card-header py-4 border-0 text-center">
                    <div class="signin-hero-icon d-inline-flex align-items-center justify-content-center bg-white text-warning rounded-circle shadow-sm">
                        <i class="bi bi-box-arrow-in-right fs-2"></i>
                    </div>
                </div>

                <div class="card-body p-5 pt-4">
                    <div class="text-center mb-4">
                        <h3 class="fw-bold mb-1 text-dark">Hello, Welcome!</h3>
                        <p class="signin-subtitle small mb-0">Construction Management System</p>
                    </div>

                    <form method="POST" action="<?= htmlspecialchars(app_path('sign-in.php')) ?>">
                        <?php csrf_field(); ?>
                        <div class="mb-3">
                            <label class="signin-field-label form-label small fw-bold">Username</label>
                            <div class="signin-input-group input-group">
                                <span class="input-group-text border-end-0">
                                    <i class="bi bi-person-fill"></i>
                                </span>
                                <input type="text" name="user_code" id="user_code" class="form-control border-start-0 ps-0" placeholder="Username" required autocomplete="username">
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="signin-field-label form-label small fw-bold">Password</label>
                            <div class="signin-input-group input-group">
                                <span class="input-group-text border-end-0">
                                    <i class="bi bi-key-fill"></i>
                                </span>
                                <input type="password" name="password" id="password" class="form-control border-start-0 ps-0" placeholder="Password" required autocomplete="current-password">
                                <button type="button" class="btn signin-password-toggle" id="togglePasswordBtn" aria-label="Show or hide password" aria-pressed="false">
                                    <i class="bi bi-eye" id="togglePasswordIcon"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-4 form-check">
                            <input type="checkbox" name="remember" value="1" id="rememberCreds" class="form-check-input border-warning">
                            <label class="form-check-label small text-muted" for="rememberCreds">Remember me</label>
                        </div>

                        <button type="submit" class="btn btn-signin btn-lg w-100 fw-bold shadow-sm rounded-3 mb-3">
                            Sign-in<i class="bi bi-chevron-right ms-1"></i>
                        </button>
                    </form>

                    <div class="text-center mt-4">
                        <hr class="opacity-10">
                        <p class="signin-copy mb-0 fw-light">
                            © 2026 <span class="fw-bold text-warning">THEELIN CON CO.,LTD.</span>
                        </p>
                    </div>
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
    <?php if ($login_status !== 'success'): ?>
    var K_USER = 'theelincon_signin_user_code';
    var K_PASS = 'theelincon_signin_password';
    try {
        var u = localStorage.getItem(K_USER);
        var p = localStorage.getItem(K_PASS);
        var userEl = document.getElementById('user_code');
        var passEl = document.getElementById('password');
        var chk = document.getElementById('rememberCreds');
        if (u && userEl) userEl.value = u;
        if (p && passEl) passEl.value = p;
        if ((u || p) && chk) chk.checked = true;
    } catch (e) {}
    <?php endif; ?>
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
    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    var colors = ['#fd7e14', '#f59e0b', '#10b981', '#3b82f6', '#ef4444'];
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
        progress.style.width = '100%';
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
    $remember_creds = isset($_POST['remember']) && (string) $_POST['remember'] === '1';
    $user_code_js = json_encode($user_code, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    $password_js = json_encode($password, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    ?>
    (function () {
        var K_USER = 'theelincon_signin_user_code';
        var K_PASS = 'theelincon_signin_password';
        try {
            <?php if ($remember_creds): ?>
            localStorage.setItem(K_USER, <?= $user_code_js ?>);
            localStorage.setItem(K_PASS, <?= $password_js ?>);
            <?php else: ?>
            localStorage.removeItem(K_USER);
            localStorage.removeItem(K_PASS);
            <?php endif; ?>
        } catch (e) {}
    })();
    runLoginSuccessExperience("<?= htmlspecialchars(app_path('index.php')) ?>");
<?php elseif ($login_status === 'csrf_fail'): ?>
    Swal.fire({ icon: 'error', title: 'เซสชันไม่ปลอดภัย', text: 'กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง', confirmButtonColor: '#fd7e14' });
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
