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

$partyName = (string) ($sup['name'] ?? '-');

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
    <?php
    require_once dirname(__DIR__, 2) . '/includes/tnc_ops_head.php';
    tnc_ops_head([
        'title' => $titleTh . ' - ' . (string) ($po['po_number'] ?? ''),
        'po_payment' => true,
        'include_ops_ui' => false,
    ]);
    ?>
</head>
<body class="tnc-app-body">
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
            <?php $tncCompanyLogoUrl = tnc_company_logo_url($com['logo'] ?? ''); ?>
            <?php if ($tncCompanyLogoUrl !== ''): ?>
                <img src="<?= htmlspecialchars($tncCompanyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" class="company-logo mb-2" alt="Company Logo">
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
    <div class="mb-2"><strong>ผู้ขาย:</strong> <?= htmlspecialchars($partyName, ENT_QUOTES, 'UTF-8') ?></div>
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
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script>
(function ($) {
    if (typeof window.TncLiveDT === 'undefined' || !$ || !$.fn.DataTable) return;
    if (!$('#tncPoPayDocTable').length) return;
    if ($('#tncPoPayDocTable tbody tr').length === 1 && $('#tncPoPayDocTable tbody td[colspan]').length) return;
    TncLiveDT.init('#tncPoPayDocTable', { order: [], columnDefs: [{ orderable: false, targets: '_all' }] });
})(jQuery);
</script>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
