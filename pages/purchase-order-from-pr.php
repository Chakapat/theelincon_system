<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$pr_id = isset($_GET['pr_id']) ? (int) $_GET['pr_id'] : 0;

$pr = Db::row('purchase_requests', (string) $pr_id);
if (!$pr || ($pr['status'] ?? '') !== 'approved') {
    echo "<script>alert('ไม่พบข้อมูลหรือ PR ยังไม่อนุมัติ'); window.location.href='" . htmlspecialchars(app_path('pages/purchase-request-list.php'), ENT_QUOTES) . "';</script>";
    exit();
}

$dup = Db::findFirst('purchase_orders', static function (array $r) use ($pr_id): bool {
    return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
});
if ($dup !== null) {
    $msg = 'ใบขอซื้อนี้ออกใบสั่งซื้อ (PO) เลขที่ ' . ($dup['po_number'] ?? '') . ' แล้ว ไม่สามารถออกซ้ำได้';
    $view = htmlspecialchars(app_path('pages/purchase-order-view.php?id=' . (int) ($dup['id'] ?? 0)), ENT_QUOTES);
    echo "<script>alert(" . json_encode($msg, JSON_UNESCAPED_UNICODE) . "); window.location.href='" . $view . "';</script>";
    exit();
}

$supplier_rows = Db::tableRows('suppliers');
Db::sortRows($supplier_rows, 'name', false);

$po_number = Purchase::generatePONumber();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>สร้างใบ PO จาก PR</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .card { border-radius: 15px; border: none; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card p-4">
                    <h4 class="fw-bold text-center mb-4"><i class="bi bi-cart-check text-primary"></i> ออกใบสั่งซื้อ (PO)</h4>
                    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=create_po_from_pr" method="POST">
                        <input type="hidden" name="pr_id" value="<?= $pr['id'] ?>">

                        <div class="mb-3">
                            <label class="form-label fw-bold">เลขที่ใบสั่งซื้อ (อัตโนมัติ)</label>
                            <input type="text" name="po_number" class="form-control bg-light" value="<?= $po_number ?>" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">อ้างอิงใบขอซื้อ (PR)</label>
                            <input type="text" class="form-control bg-light" value="<?= $pr['pr_number'] ?>" readonly>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-danger">เลือกผู้ขาย (Supplier) *</label>
                            <select name="supplier_id" class="form-select form-select-lg border-primary" required>
                                <option value="">-- กรุณาเลือกคู่ค้า --</option>
                                <?php foreach ($supplier_rows as $s): ?>
                                    <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars((string) $s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php
                        $pr_vat_on = (int) ($pr['vat_enabled'] ?? 0);
                        $pr_vat = (float)($pr['vat_amount'] ?? 0);
                        $pr_grand = (float)$pr['total_amount'];
                        if (isset($pr['subtotal_amount']) && $pr['subtotal_amount'] !== null && $pr['subtotal_amount'] !== '') {
                            $pr_sub = (float)$pr['subtotal_amount'];
                        } else {
                            $pr_sub = round($pr_grand - $pr_vat, 2);
                        }
                        ?>
                        <div class="alert alert-info py-3 small">
                            <div class="d-flex justify-content-between"><span>ยอดรายการ (ก่อน VAT)</span><strong><?= number_format($pr_sub, 2) ?> บาท</strong></div>
                            <?php if ($pr_vat_on): ?>
                            <div class="d-flex justify-content-between text-success"><span>VAT 7%</span><strong><?= number_format($pr_vat, 2) ?> บาท</strong></div>
                            <?php else: ?>
                            <div class="text-muted">ไม่รวม VAT</div>
                            <?php endif; ?>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between fs-6"><span>ยอดรวมสุทธิ</span><strong><?= number_format($pr_grand, 2) ?> บาท</strong></div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg shadow">ยืนยันการสร้างใบ PO</button>
                            <a href="purchase-request-view.php?id=<?= $pr_id ?>" class="btn btn-light">ยกเลิก</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>