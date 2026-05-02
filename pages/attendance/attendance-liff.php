<?php

declare(strict_types=1);


require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/config/line_settings.php';

$liffId = trim((string) (defined('LINE_ATTENDANCE_LIFF_ID') ? LINE_ATTENDANCE_LIFF_ID : ''));
if ($liffId === '') {
    $liffId = trim((string) (getenv('LINE_ATTENDANCE_LIFF_ID') ?: ''));
}
if ($liffId === '') {
    // Final fallback for immediate testing in LINE while server cache/config is being aligned.
    $liffId = '2009884791-xPyWjCBN';
}
$saveUrl = app_path('actions/attendance-handler.php');
$qrPassword = trim((string) (defined('ATTENDANCE_QR_PASSWORD') ? ATTENDANCE_QR_PASSWORD : ''));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ลงเวลาเข้าออกงาน</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f8fafc; font-family: "Sarabun", sans-serif; }
        .shell { max-width: 560px; margin: 0 auto; min-height: 100vh; padding: 1rem; }
        .card-clean { border: 1px solid #e2e8f0; border-radius: 1rem; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06); }
        .event-btn.active { border-color: #ea580c; color: #9a3412; background: #fff7ed; }
        .submit-wrap { display: none; }
        .submit-wrap.ready { display: block; }
    </style>
</head>
<body>
<div class="shell">
    <div class="card card-clean">
        <div class="card-body p-4">
            <h1 class="h5 mb-1">ลงเวลาเข้า/ออกงาน</h1>
            <p class="text-muted small mb-3">สแกน QR เพื่อยืนยันตัวตนก่อนบันทึกเวลา</p>

            <div class="mb-3">
                <div class="small fw-semibold mb-2">ประเภทการลงเวลา</div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-success flex-fill event-btn active" data-event-type="checkin">
                        <i class="bi bi-box-arrow-in-right me-1"></i>เข้างาน
                    </button>
                    <button type="button" class="btn btn-outline-danger flex-fill event-btn" data-event-type="checkout">
                        <i class="bi bi-box-arrow-right me-1"></i>ออกงาน
                    </button>
                </div>
            </div>

            <div class="mb-3">
                <button type="button" id="scanBtn" class="btn btn-warning w-100 fw-semibold">
                    <i class="bi bi-qr-code-scan me-1"></i>สแกน QR
                </button>
            </div>

            <div id="statusBox" class="alert alert-secondary small mb-3">
                พร้อมใช้งาน
            </div>

            <div id="submitWrap" class="submit-wrap">
                <button type="button" id="submitBtn" class="btn btn-primary w-100 fw-semibold">
                    บันทึกเวลา
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
<script>
(function () {
    var liffId = <?= json_encode($liffId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var saveUrl = <?= json_encode($saveUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var qrPassword = <?= json_encode($qrPassword, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var activeEventType = 'checkin';
    var lineUserId = '';
    var scannedQrValue = '';

    var eventButtons = document.querySelectorAll('.event-btn');
    var scanBtn = document.getElementById('scanBtn');
    var submitWrap = document.getElementById('submitWrap');
    var submitBtn = document.getElementById('submitBtn');
    var statusBox = document.getElementById('statusBox');

    function setStatus(text, level) {
        statusBox.className = 'alert small mb-3 alert-' + level;
        statusBox.textContent = text;
    }

    function normalizeQr(value) {
        return (value || '').replace(/[\u200B-\u200D\uFEFF]/g, '').replace(/\s+/g, '').trim();
    }

    function hideSubmit() {
        if (submitWrap) {
            submitWrap.classList.remove('ready');
        }
        if (submitBtn) {
            submitBtn.disabled = false;
        }
    }

    function showSubmit() {
        if (submitWrap) {
            submitWrap.classList.add('ready');
        }
        if (submitBtn) {
            submitBtn.disabled = false;
        }
    }

    function setActiveEventType(nextType) {
        activeEventType = nextType;
        eventButtons.forEach(function (btn) {
            if (btn.dataset.eventType === nextType) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
    }

    eventButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            setActiveEventType(btn.dataset.eventType || 'checkin');
        });
    });

    async function initLiff() {
        if (!liffId) {
            setStatus('ยังไม่ได้ตั้งค่า LINE_ATTENDANCE_LIFF_ID', 'danger');
            return;
        }
        try {
            await liff.init({ liffId: liffId });
            if (!liff.isLoggedIn()) {
                liff.login();
                return;
            }
            var profile = await liff.getProfile();
            lineUserId = profile.userId || '';
            hideSubmit();
            setStatus('พร้อมลงเวลา: ' + (profile.displayName || 'LINE User'), 'success');
        } catch (err) {
            setStatus('เชื่อมต่อ LIFF ไม่สำเร็จ: ' + (err && err.message ? err.message : 'unknown'), 'danger');
        }
    }

    async function scanQr() {
        try {
            if (!liff.isInClient()) {
                setStatus('กรุณาเปิดหน้านี้ผ่านแอป LINE เพื่อสแกน QR', 'warning');
                return;
            }
            var result = await liff.scanCodeV2();
            if (!result || !result.value) {
                setStatus('ไม่พบข้อมูลจาก QR', 'warning');
                hideSubmit();
                return;
            }
            scannedQrValue = result.value;
            var normalizedScanned = normalizeQr(scannedQrValue);
            var normalizedPassword = normalizeQr(qrPassword);
            if (normalizedPassword === '' || normalizedScanned !== normalizedPassword) {
                hideSubmit();
                setStatus('QR ไม่ถูกต้อง กรุณาสแกน QR ที่หน้างานที่ได้รับอนุญาต', 'danger');
                return;
            }
            showSubmit();
            setStatus('สแกนถูกต้อง กดบันทึกเวลาได้เลย', 'success');
        } catch (err) {
            setStatus('สแกน QR ไม่สำเร็จ: ' + (err && err.message ? err.message : 'unknown'), 'danger');
            hideSubmit();
        }
    }

    async function submitAttendance() {
        if (!lineUserId) {
            setStatus('ไม่พบ LINE User ID', 'danger');
            return;
        }
        var qrValue = normalizeQr(scannedQrValue);
        if (!qrValue) {
            setStatus('กรุณาสแกน QR ก่อนบันทึก', 'warning');
            return;
        }

        submitBtn.disabled = true;
        setStatus('กำลังบันทึกข้อมูล...', 'secondary');
        try {
            var res = await fetch(saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    line_user_id: lineUserId,
                    event_type: activeEventType,
                    qr_value: qrValue,
                    event_at: new Date().toISOString()
                })
            });
            var data = await res.json();
            if (!res.ok || !data.ok) {
                var errorText = 'บันทึกไม่สำเร็จ: ' + (data.error || 'unknown_error');
                if (data.error === 'invalid_qr_password') {
                    var scanned = data.scanned_qr_normalized || data.scanned_qr || '';
                    errorText += ' | scanned=' + scanned;
                }
                setStatus(errorText, 'danger');
                submitBtn.disabled = false;
                return;
            }
            if (data.status === 'duplicate_ignored') {
                setStatus('รายการซ้ำ: ระบบไม่บันทึกซ้ำให้แล้ว', 'warning');
                submitBtn.disabled = false;
                return;
            }
            var eventLabel = activeEventType === 'checkin' ? 'เข้างาน' : 'ออกงาน';
            setStatus('บันทึก' + eventLabel + 'สำเร็จ (' + (data.site_name || '-') + ')', 'success');
            scannedQrValue = '';
            hideSubmit();
        } catch (err) {
            setStatus('บันทึกไม่สำเร็จ: ' + (err && err.message ? err.message : 'network_error'), 'danger');
            submitBtn.disabled = false;
        }
    }

    scanBtn.addEventListener('click', scanQr);
    submitBtn.addEventListener('click', submitAttendance);
    initLiff();
})();
</script>
</body>
</html>

