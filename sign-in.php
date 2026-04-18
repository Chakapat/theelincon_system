<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/connect_database.php';

use Theelincon\Rtdb\Db;

$login_status = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_code = trim((string) ($_POST['user_code'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

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
            }
        }
        if ($password_ok) {
            $_SESSION['user_id'] = (int) ($user['userid'] ?? 0);
            $_SESSION['name'] = trim((string) (($user['fname'] ?? '') . ' ' . ($user['lname'] ?? '')));
            $_SESSION['role'] = (string) ($user['role'] ?? 'user');
            unset($_SESSION['position']);
            $login_status = 'success';
        } else {
            $login_status = 'fail_password';
        }
    } else {
        $login_status = 'fail_user';
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
</head>
<body style="background: linear-gradient(135deg, #fff5e6 0%, #ffdfb3 100%);">

<div class="container">
    <div class="row vh-100 align-items-center justify-content-center">
        <div class="col-12 col-sm-8 col-md-6 col-lg-4">
            
            <div class="card border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="card-header bg-warning bg-gradient py-4 border-0 text-center">
                    <div class="d-inline-flex align-items-center justify-content-center bg-white text-warning rounded-circle shadow-sm" style="width: 60px; height: 60px;">
                        <i class="bi bi-box-arrow-in-right fs-2"></i>
                    </div>
                </div>

                <div class="card-body p-5 pt-4">
                    <div class="text-center mb-4">
                        <h3 class="fw-bold mb-1 text-dark">เข้าสู่ระบบ</h3>
                        <p class="text-muted small">Construction Management System</p>
                    </div>

                    <form method="POST" action="<?= htmlspecialchars(app_path('sign-in.php')) ?>">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark">ชื่อผู้ใช้งาน</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-warning">
                                    <i class="bi bi-person-fill"></i>
                                </span>
                                <input type="text" name="user_code" class="form-control bg-light border-start-0 ps-0" placeholder="Username" required>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-dark">รหัสผ่าน</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-warning">
                                    <i class="bi bi-key-fill"></i>
                                </span>
                                <input type="password" name="password" class="form-control bg-light border-start-0 ps-0" placeholder="Password" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-warning btn-lg w-100 fw-bold shadow-sm rounded-3 text-white mb-3" style="background-color: #fd7e14; border: none;">
                            LOG IN <i class="bi bi-chevron-right ms-1"></i>
                        </button>
                    </form>

                    <div class="text-center mt-4">
                        <hr class="opacity-10">
                        <p class="mb-0 text-muted fw-light" style="font-size: 0.75rem;">
                            © 2026 <span class="fw-bold text-warning">THEELIN CON CO.,LTD.</span>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>

<?php if ($login_status === 'success'): ?>
    let timerInterval;
    Swal.fire({
        title: "เข้าสู่ระบบสำเร็จ!",
        html: "กำลังพาคุณไปยังหน้าหลักใน <b></b> วินาที",
        icon: "success",
        timer: 3000,
        timerProgressBar: true,
        confirmButtonColor: '#fd7e14',
        didOpen: () => {
            Swal.showLoading();
            const b = Swal.getHtmlContainer().querySelector('b');
            timerInterval = setInterval(() => {
                b.textContent = Math.ceil(Swal.getTimerLeft() / 1000);
            }, 100);
        },
        willClose: () => clearInterval(timerInterval)
    }).then(() => {
        window.location.href = "<?= htmlspecialchars(app_path('index.php')) ?>";
    });
<?php elseif ($login_status === 'fail_password'): ?>
    Swal.fire({ icon: 'error', title: 'รหัสผ่านไม่ถูกต้อง', confirmButtonColor: '#fd7e14' });
<?php elseif ($login_status === 'fail_user'): ?>
    Swal.fire({ icon: 'warning', title: 'ไม่พบผู้ใช้งานนี้', confirmButtonColor: '#fd7e14' });
<?php endif; ?>
</script>
</body>
</html>
