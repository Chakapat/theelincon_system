<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!isset($_SESSION['role']) || (string) $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo 'ไม่มีสิทธิ์เข้าถึง — เฉพาะผู้ดูแลระบบ';
    exit;
}

$docPath = dirname(__DIR__, 2) . '/docs/SYSTEM_IMPROVEMENT_BACKLOG.md';
$docHint = is_readable($docPath)
    ? 'แก้ checklist ฉบับเต็มใน repo: <code>docs/SYSTEM_IMPROVEMENT_BACKLOG.md</code>'
    : 'สร้างไฟล์ <code>docs/SYSTEM_IMPROVEMENT_BACKLOG.md</code> เพื่อเก็บเวอร์ชันใน Git';

$quickLinks = [
    ['หน้าแรก / ใบแจ้งหนี้', app_path('index.php'), 'bi-house'],
    ['ใบขอซื้อ', app_path('pages/purchase/purchase-request-list.php'), 'bi-cart-plus'],
    ['ใบสั่งซื้อ', app_path('pages/purchase/purchase-order-list.php'), 'bi-bag-check'],
    ['เบิกเงินล่วงหน้า', app_path('pages/advance-cash/advance-cash-list.php'), 'bi-cash-coin'],
    ['บัตรค่าแรง', app_path('pages/labor-payroll/labor-payroll.php'), 'bi-calculator'],
    ['ประวัติตัดยอดค่าแรง', app_path('pages/labor-payroll/labor-payroll-history.php'), 'bi-archive'],
    ['สรุปสดย่อย (Dashboard)', app_path('pages/cash-ledger/cash-ledger-dashboard.php'), 'bi-speedometer2'],
    ['บันทึกสดย่อย', app_path('pages/cash-ledger/cash-ledger.php'), 'bi-cash-stack'],
    ['คลังสินค้า', app_path('pages/stock/stock-list.php'), 'bi-box-seam'],
    ['ตั้งค่า LINE', app_path('pages/internal/line-notify-config.php'), 'bi-bell'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แผนพัฒนาและงานค้าง | THEELIN</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f6f8fb; }
        .hub-card { border-radius: 14px; border: 1px solid #e3e8ef; box-shadow: 0 4px 18px rgba(15, 23, 42, 0.06); }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4 mt-2">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-kanban text-primary me-2"></i>แผนพัฒนาและงานค้าง (Backlog)</h4>
            <p class="text-muted small mb-0">ใช้คุยใน meeting — รายการสอดคล้องกับ <span class="font-monospace small">docs/SYSTEM_IMPROVEMENT_BACKLOG.md</span></p>
        </div>
        <a href="<?= htmlspecialchars(app_path('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill">หน้าแรก</a>
    </div>

    <div class="alert alert-light border hub-card py-3 mb-4">
        <div class="small mb-0"><?= $docHint ?></div>
    </div>

    <h5 class="fw-bold mb-3"><i class="bi bi-link-45deg me-1"></i>ลิงก์ด่วนไปโมดูลหลัก</h5>
    <div class="row g-2 mb-4">
        <?php foreach ($quickLinks as $ql): ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="<?= htmlspecialchars($ql[1], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-primary w-100 text-start small py-2 rounded-3">
                <i class="bi <?= htmlspecialchars($ql[2], ENT_QUOTES, 'UTF-8') ?> me-2"></i><?= htmlspecialchars($ql[0], ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card hub-card mb-4">
        <div class="card-header bg-white fw-bold py-3">P0 — ควรตกลงก่อนลงมือแก้ระบบ</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>#</th><th>รายการ</th><th>หมายเหตุ</th><th>ผู้รับผิดชอบ</th></tr></thead>
                <tbody class="small">
                    <tr><td>1</td><td>นิยาม <strong>สถานะมาตรฐาน</strong> ต่อเอกสารหลัก</td><td>กี่ขั้น ใครอนุมัติ จุดไหนถือว่าปิด</td><td class="text-muted">( )</td></tr>
                    <tr><td>2</td><td>กำหนด <strong>บทบาท (RACI)</strong> ต่อขั้นอนุมัติ</td><td>ลดช่องว่างสิทธิ์ / งานซ้ำ</td><td class="text-muted">( )</td></tr>
                    <tr><td>3</td><td>ตกลง <strong>ความหมายยอดเงิน</strong> (สดย่อย / advance / รายจ่ายไซต์)</td><td>ฝ่ายบัญชี–ไซต์–การเงินใช้คำเดียวกัน</td><td class="text-muted">( )</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card hub-card mb-4">
        <div class="card-header bg-white fw-bold py-3">P1 — พัฒนาให้ทำงานร่วมกันชัดขึ้น</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>#</th><th>รายการ</th><th>หมายเหตุ</th><th>ผู้รับผิดชอบ</th></tr></thead>
                <tbody class="small">
                    <tr><td>1</td><td><strong>ศูนย์กลางงานค้าง</strong> หรือแดชบอร์ดสรุปสถานะ</td><td>รออนุมัติ / รอเอกสาร จากหลายโมดูล</td><td class="text-muted">( )</td></tr>
                    <tr><td>2</td><td><strong>แผนผังเอกสาร 1 หน้า</strong></td><td>Quotation → Invoice → ใบกำกับ / PR → PO → Bill → Stock?</td><td class="text-muted">( )</td></tr>
                    <tr><td>3</td><td>ทบทวน <strong>การแจ้งเตือน</strong> (LINE ฯลฯ)</td><td>ครอบคลุม flow ที่ใช้จริง</td><td class="text-muted">( )</td></tr>
                    <tr><td>4</td><td><strong>Traceability</strong> — อ้างอิงย้อนกลับระหว่างเอกสาร</td><td>จากใบหนึ่งไปหาเอกสารต้นทาง</td><td class="text-muted">( )</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card hub-card mb-4">
        <div class="card-header bg-white fw-bold py-3">P2 — คุณภาพระบบและระยะยาว</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>#</th><th>รายการ</th><th>หมายเหตุ</th><th>ผู้รับผิดชอบ</th></tr></thead>
                <tbody class="small">
                    <tr><td>1</td><td><strong>Audit log</strong> เอกสารสำคัญ</td><td>ใครแก้ เมื่อไหร่</td><td class="text-muted">( )</td></tr>
                    <tr><td>2</td><td>ทบทวน <strong>สิทธิ์ role</strong> ทั้งระบบ</td><td>admin / Accounting / user ฯลฯ</td><td class="text-muted">( )</td></tr>
                    <tr><td>3</td><td><strong>รายงานสรุป / export</strong> รายเดือนข้ามโมดูล</td><td>ภาพรวมบริหาร</td><td class="text-muted">( )</td></tr>
                    <tr><td>4</td><td>แยก vision <strong>ปฏิบัติการ</strong> vs <strong>บัญชีเต็มรูป (GL)</strong></td><td>ถ้าต้องการงบการเงินแบบ ERP</td><td class="text-muted">( )</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card hub-card mb-4">
        <div class="card-header bg-white fw-bold py-3">โมดูล — คำถามที่ควรยืนยันกับทีม</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>โมดูล</th><th>คำถาม</th></tr></thead>
                <tbody class="small">
                    <tr><td>จัดซื้อ + สต็อก</td><td>PO / บิลซื้อ <strong>ตัดสต็อก</strong> อัตโนมัติหรือมือ?</td></tr>
                    <tr><td>การเงิน</td><td>Advance → ออกใบเสร็จ → ลง petty / รายงาน อย่างไร?</td></tr>
                    <tr><td>ค่าแรง / ไซต์</td><td>ต้องการ <strong>ผูกต้นทุนต่อไซต์</strong> ในระบบหรือไม่?</td></tr>
                    <tr><td>ลา / payroll</td><td>ขั้นอนุมัติลา <strong>สอดคล้อง</strong> กับการจ่ายเงินเดือนหรือไม่?</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card hub-card">
        <div class="card-header bg-white fw-bold py-3">UX / ความสม่ำเสมอ</div>
        <ul class="list-group list-group-flush small">
            <li class="list-group-item">ใช้ pattern เดียวกันในสายงานเดียวกัน (list + popup vs หน้าเต็ม)</li>
            <li class="list-group-item">หลังบันทึกสำเร็จ — ข้อความยืนยัน + เส้นทางกลับชัด</li>
            <li class="list-group-item">ตรวจชื่อเมนูภาษาไทย–อังกฤษให้ตรงกับที่ทีมเรียกจริง</li>
        </ul>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
