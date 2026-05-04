<?php

declare(strict_types=1);

/**
 * แสดง SweetAlert2 แล้วส่งกลับไปหน้าที่กำหนด (ค่าเริ่มต้น index)
 * ต้องโหลด config/connect_database.php หรือ foundation แล้ว (มี app_path)
 *
 * @var string $access_denied_title
 * @var string $access_denied_text
 * @var string|null $access_denied_redirect
 */
$__denyTitle = isset($access_denied_title) ? (string) $access_denied_title : 'ไม่มีสิทธิ์';
$__denyText = isset($access_denied_text) ? (string) $access_denied_text : 'คุณไม่มีสิทธิ์เข้าหน้านี้';
$__denyRedirect = isset($access_denied_redirect) && (string) $access_denied_redirect !== ''
    ? (string) $access_denied_redirect
    : app_path('index.php');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($__denyTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<script>
(function () {
    var title = <?= json_encode($__denyTitle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var text = <?= json_encode($__denyText, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var url = <?= json_encode($__denyRedirect, JSON_UNESCAPED_SLASHES) ?>;
    Swal.fire({
        icon: 'warning',
        title: title,
        text: text,
        confirmButtonText: 'กลับหน้าหลัก',
        confirmButtonColor: '#fd7e14'
    }).then(function () {
        window.location.href = url;
    });
})();
</script>
</body>
</html>
