<?php
declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$docType = trim((string) ($_GET['doc'] ?? 'voucher')); // voucher|receipt
$docType = in_array($docType, ['voucher', 'receipt'], true) ? $docType : 'voucher';

$po = Db::rowByIdField('purchase_orders', $id);
if (!$po) {
    die('ไม่พบข้อมูลใบสั่งซื้อ/สั่งจ่าย');
}

$sup = Db::rowByIdField('suppliers', (int) ($po['supplier_id'] ?? 0));
$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', false);
$com = array_values($companies)[0] ?? [];

$orderType = trim((string) ($po['order_type'] ?? 'purchase'));
$contractorName = trim((string) ($po['contractor_name'] ?? ''));
$partyName = $orderType === 'hire' && $contractorName !== '' ? $contractorName : (string) ($sup['name'] ?? '-');

$items = Db::filter('purchase_order_items', static function (array $r) use ($id): bool {
    return (int) ($r['po_id'] ?? 0) === $id || (int) ($r['purchase_order_id'] ?? 0) === $id;
});
Db::sortRows($items, 'id', false);

$subtotal = (float) ($po['subtotal_amount'] ?? 0);
$vat = (float) ($po['vat_amount'] ?? 0);
$wht = (float) ($po['withholding_amount'] ?? 0);
$ret = (float) ($po['retention_amount'] ?? 0);
$total = (float) ($po['payable_amount'] ?? ($po['total_amount'] ?? 0));

$issueDate = trim((string) ($po['issue_date'] ?? $po['created_at'] ?? date('Y-m-d')));
$docNo = ($docType === 'receipt' ? 'RCPT' : 'PV') . '-' . trim((string) ($po['po_number'] ?? ('PO-' . $id)));
$titleTh = $docType === 'receipt' ? 'ใบเสร็จรับเงิน' : 'ใบสำคัญจ่าย';
$titleEn = $docType === 'receipt' ? 'RECEIPT' : 'PAYMENT VOUCHER';
$paymentMethod = trim((string) ($_GET['method'] ?? 'cash'));
if (!in_array($paymentMethod, ['cash', 'transfer', 'cheque'], true)) {
    $paymentMethod = 'cash';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titleTh, ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) ($po['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f5f5f5; }
        .doc-wrap { max-width: 1000px; margin: 24px auto; background: #fff; padding: 28px; border-top: 6px solid #198754; min-height: 1120px; }
        .doc-content { display: flex; flex-direction: column; min-height: 1040px; }
        .doc-title { font-size: 2rem; font-weight: 800; line-height: 1; }
        .company-logo { max-height: 76px; width: auto; max-width: 220px; object-fit: contain; }
        .bill-table thead th { border-bottom: 2px solid #198754 !important; background: #f9faf9; }
        .summary-box { max-width: 380px; margin-left: auto; border-top: 1px dashed #b7b7b7; padding-top: 10px; }
        .sig-line { border-bottom: 1px solid #333; height: 56px; margin-bottom: 8px; }
        .signature-footer { margin-top: auto; padding-top: 24px; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            @page { size: A4; margin: 8mm; }
            .doc-wrap { margin: 0; max-width: 100%; padding: 0; border: 0; min-height: auto; height: 281mm; }
            .doc-content { min-height: auto; height: 100%; }
            .signature-footer { margin-top: auto; break-inside: avoid; page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="no-print">
    <?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
</div>
<div class="container no-print py-3 text-center">
    <div class="d-inline-flex align-items-center gap-3">
        <span class="fw-bold">Payment method</span>
        <label class="form-check-label"><input type="checkbox" class="form-check-input method-check me-1" value="cash"<?= $paymentMethod === 'cash' ? ' checked' : '' ?>>เงินสด</label>
        <label class="form-check-label"><input type="checkbox" class="form-check-input method-check me-1" value="transfer"<?= $paymentMethod === 'transfer' ? ' checked' : '' ?>>เงินโอน</label>
        <label class="form-check-label"><input type="checkbox" class="form-check-input method-check me-1" value="cheque"<?= $paymentMethod === 'cheque' ? ' checked' : '' ?>>เช็คธนาคาร (Cheque)</label>
    </div>
    <button class="btn btn-success ms-2" onclick="window.print()">พิมพ์เอกสาร</button>
    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-view.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= $id ?>" class="btn btn-outline-secondary ms-2">กลับหน้า PO</a>
</div>

<div class="doc-wrap">
    <div class="doc-content">
    <div class="row align-items-start">
        <div class="col-6">
            <?php if (!empty($com['logo'])): ?>
                <img src="<?= htmlspecialchars(upload_logo_url((string) $com['logo']), ENT_QUOTES, 'UTF-8') ?>" class="company-logo mb-2" alt="Company Logo">
            <?php endif; ?>
            <div class="fw-bold fs-5"><?= htmlspecialchars((string) ($com['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="small text-muted"><?= htmlspecialchars((string) ($com['address'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="col-6 text-end">
            <div class="doc-title"><?= htmlspecialchars($titleEn, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="fw-bold"><?= htmlspecialchars($titleTh, ENT_QUOTES, 'UTF-8') ?></div>
            <div>เลขที่เอกสาร: <strong><?= htmlspecialchars($docNo, ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div>อ้างอิง PO: <strong><?= htmlspecialchars((string) ($po['po_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div>วันที่: <strong><?= htmlspecialchars($issueDate, ENT_QUOTES, 'UTF-8') ?></strong></div>
        </div>
    </div>

    <hr>
    <div class="mb-2"><strong><?= $orderType === 'hire' ? 'ผู้รับจ้าง' : 'ผู้ขาย' ?>:</strong> <?= htmlspecialchars($partyName, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="mb-3">
        <strong>Payment method</strong>
        <div class="mt-1">
            <span id="pm_cash"><?= $paymentMethod === 'cash' ? '&#9745;' : '&#9744;' ?></span> เงินสด
            &nbsp;&nbsp;
            <span id="pm_transfer"><?= $paymentMethod === 'transfer' ? '&#9745;' : '&#9744;' ?></span> เงินโอน
            &nbsp;&nbsp;
            <span id="pm_cheque"><?= $paymentMethod === 'cheque' ? '&#9745;' : '&#9744;' ?></span> เช็คธนาคาร (Cheque)
        </div>
    </div>

    <table class="table table-bordered align-middle bill-table" id="tncPoPayDocTable">
        <thead class="table-light">
            <tr>
                <th>รายละเอียด</th>
                <th class="text-end" width="12%">จำนวน</th>
                <th class="text-end" width="18%">ราคา/หน่วย</th>
                <th class="text-end" width="18%">ยอดรวม</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($items) === 0): ?>
                <tr><td colspan="4" class="text-center text-muted">ไม่พบรายการ</td></tr>
            <?php else: ?>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($it['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-end"><?= number_format((float) ($it['quantity'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float) ($it['unit_price'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float) ($it['total'] ?? 0), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="summary-box">
        <div class="d-flex justify-content-between"><span>ยอดรวม</span><strong><?= number_format($subtotal, 2) ?></strong></div>
        <div class="d-flex justify-content-between"><span>VAT</span><strong><?= number_format($vat, 2) ?></strong></div>
        <?php if ($wht > 0): ?><div class="d-flex justify-content-between text-danger"><span>หัก ณ ที่จ่าย</span><strong>-<?= number_format($wht, 2) ?></strong></div><?php endif; ?>
        <?php if ($ret > 0): ?><div class="d-flex justify-content-between text-danger"><span>หักประกันผลงาน</span><strong>-<?= number_format($ret, 2) ?></strong></div><?php endif; ?>
        <hr class="my-2">
        <div class="d-flex justify-content-between align-items-center fs-5 pt-1">
            <span><strong>ยอดสุทธิ</strong></span>
            <strong class="ps-3"><?= number_format($total, 2) ?></strong>
        </div>
    </div>

    <div class="row signature-footer">
        <div class="col-6 text-center">
            <div class="sig-line"></div>
            <div class="fw-bold">ผู้จ่าย</div>
            <div class="small text-muted">(Payer Signature)</div>
        </div>
        <div class="col-6 text-center">
            <div class="sig-line"></div>
            <div class="fw-bold">ผู้รับเงิน</div>
            <div class="small text-muted">(Receiver Signature)</div>
        </div>
    </div>
    </div>
</div>
<script>
(function () {
    const checks = Array.from(document.querySelectorAll('.method-check'));
    const cashEl = document.getElementById('pm_cash');
    const transferEl = document.getElementById('pm_transfer');
    const chequeEl = document.getElementById('pm_cheque');
    if (checks.length === 0 || !cashEl || !transferEl || !chequeEl) return;

    const updateDocChecks = () => {
        const selected = checks.find((c) => c.checked)?.value || 'cash';
        cashEl.innerHTML = selected === 'cash' ? '&#9745;' : '&#9744;';
        transferEl.innerHTML = selected === 'transfer' ? '&#9745;' : '&#9744;';
        chequeEl.innerHTML = selected === 'cheque' ? '&#9745;' : '&#9744;';
    };

    checks.forEach((check) => {
        check.addEventListener('change', function () {
            if (this.checked) {
                checks.forEach((other) => {
                    if (other !== this) other.checked = false;
                });
            } else if (!checks.some((c) => c.checked)) {
                this.checked = true;
            }
            updateDocChecks();
        });
    });
    updateDocChecks();
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script>
(function ($) {
    if (typeof window.TncLiveDT === 'undefined' || !$ || !$.fn.DataTable) return;
    if (!$('#tncPoPayDocTable').length) return;
    if ($('#tncPoPayDocTable tbody tr').length === 1 && $('#tncPoPayDocTable tbody td[colspan]').length) return;
    TncLiveDT.init('#tncPoPayDocTable', { order: [], columnDefs: [{ orderable: false, targets: '_all' }] });
})(jQuery);
</script>
</body>
</html>
