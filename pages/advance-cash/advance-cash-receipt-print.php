<?php
declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$isFinanceRole = isset($_SESSION['role']) && in_array((string) $_SESSION['role'], ['admin', 'Accounting'], true);
if (!$isFinanceRole) {
    header('Location: ' . app_path('pages/advance-cash/advance-cash-list.php'));
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$row = Db::rowByIdField('advance_cash_requests', $id);
if ($row === null) {
    header('Location: ' . app_path('pages/advance-cash/advance-cash-list.php') . '?error=not_found');
    exit;
}
if ((string) ($row['receipt_status'] ?? 'none') !== 'issued') {
    header('Location: ' . app_path('pages/advance-cash/advance-cash-view.php') . '?id=' . $id . '&error=receipt_not_issued');
    exit;
}

$users = Db::tableKeyed('users');
$requester = $users[(string) ((int) ($row['requested_by'] ?? 0))] ?? [];
$requesterName = trim((string) ($requester['fname'] ?? '') . ' ' . (string) ($requester['lname'] ?? ''));
if ($requesterName === '') {
    $requesterName = 'ผู้ขอเบิก';
}

$receiptNumber = trim((string) ($row['receipt_number'] ?? ''));
$receiptDate = trim((string) ($row['receipt_date'] ?? ''));
if ($receiptDate === '') {
    $receiptDate = date('Y-m-d');
}
$paymentMethod = (string) ($row['receipt_payment_method'] ?? '');
$paymentMethodLabel = $paymentMethod === 'transfer' ? 'โอนเงิน' : 'เงินสด';
$slipUrl = trim((string) ($row['receipt_transfer_slip_url'] ?? ''));
$slipPath = trim((string) ($row['receipt_transfer_slip_path'] ?? ''));
$slipRef = $slipPath !== '' ? $slipPath : $slipUrl;
$slipExt = strtolower(pathinfo($slipRef, PATHINFO_EXTENSION));
$isSlipImage = in_array($slipExt, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'], true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>พิมพ์ใบเสร็จรับเงินเบิกล่วงหน้า</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background: #f4f4f4;
            margin: 0;
            color: #222;
        }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: #fff;
            padding: 12mm 15mm 16mm;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            border-top: 8px solid #0d6efd;
            position: relative;
        }
        .title {
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 2px;
        }
        .title-sub {
            font-size: 16px;
            color: #555;
            margin-bottom: 20px;
        }
        .meta-label {
            font-size: 12px;
            color: #666;
        }
        .meta-value {
            font-size: 15px;
            font-weight: 600;
        }
        .section-box {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 14px;
            margin-top: 12px;
            background: #fcfdff;
        }
        .amount {
            font-size: 32px;
            font-weight: 700;
            color: #198754;
        }
        .slip-preview {
            margin-top: 10px;
            border: 1px solid #d9d9d9;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
        }
        .slip-preview img {
            display: block;
            width: 100%;
            max-height: 500px;
            object-fit: contain;
            background: #f8f9fa;
        }
        .signature-area {
            position: absolute;
            right: 15mm;
            bottom: 12mm;
            width: 72mm;
        }
        .sig-line {
            margin-top: 24px;
            border-top: 1px solid #222;
            padding-top: 6px;
            text-align: center;
            font-size: 12px;
        }
        .no-print {
            text-align: center;
            padding: 14px;
            background: #212529;
            margin-bottom: 12px;
        }
        @media print {
            @page { size: A4; margin: 0; }
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet {
                margin: 0;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
<div class="no-print">
    <button onclick="window.print()" class="btn btn-success btn-sm px-4">พิมพ์ใบเสร็จ</button>
    <a href="<?= htmlspecialchars(app_path('pages/advance-cash/advance-cash-view.php') . '?id=' . $id, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-light btn-sm ms-2">กลับหน้ารายละเอียด</a>
</div>

<div class="sheet">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <div class="title">ใบเสร็จรับเงิน</div>
            <div class="title-sub">Advance Cash Receipt</div>
        </div>
        <div class="text-end">
            <div class="meta-label">เลขที่ใบเสร็จ</div>
            <div class="meta-value"><?= htmlspecialchars($receiptNumber !== '' ? $receiptNumber : '-', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="meta-label mt-2">วันที่ออกใบเสร็จ</div>
            <div class="meta-value"><?= htmlspecialchars($receiptDate, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </div>

    <div class="section-box">
        <div class="row g-3 align-items-end">
            <div class="col-md-8">
                <div class="meta-label">ผู้รับเงิน</div>
                <div class="meta-value"><?= htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="meta-label mt-3">วัตถุประสงค์</div>
                <div><?= nl2br(htmlspecialchars((string) ($row['purpose'] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div>
                <div class="meta-label mt-3">วิธีรับเงิน</div>
                <div class="meta-value"><?= htmlspecialchars($paymentMethodLabel, ENT_QUOTES, 'UTF-8') ?></div>
                <?php if ($paymentMethod === 'transfer' && $slipUrl !== ''): ?>
                    <div class="meta-label mt-2">สลิปโอนเงิน</div>
                    <?php if ($isSlipImage): ?>
                        <div class="slip-preview">
                            <img src="<?= htmlspecialchars($slipUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Transfer Slip">
                        </div>
                    <?php else: ?>
                        <div><a href="<?= htmlspecialchars($slipUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank">เปิดดูสลิปแนบ</a></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="col-md-4 text-md-end">
                <div class="meta-label">จำนวนเงิน</div>
                <div class="amount">฿<?= number_format((float) ($row['amount'] ?? 0), 2) ?></div>
            </div>
        </div>
    </div>

    <div class="signature-area">
        <div>
            <div class="sig-line">ผู้รับเงิน / ผู้ขอเบิก</div>
        </div>
    </div>
</div>
</body>
</html>
