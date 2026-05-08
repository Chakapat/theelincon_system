<?php
declare(strict_types=1);


require_once __DIR__ . '/_page_root.php';
use Theelincon\Rtdb\Db;

session_start();
require_once THEELINCON_ROOT . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$prId = isset($_GET['pr_id']) ? (int) $_GET['pr_id'] : 0;
if ($prId <= 0) {
    header('Location: ' . app_path('pages/hire-contract-list.php'));
    exit();
}

$contract = Db::findFirst('hire_contracts', static function (array $r) use ($prId): bool {
    return (int) ($r['pr_id'] ?? 0) === $prId;
});
if ($contract === null) {
    header('Location: ' . app_path('pages/hire-contract-list.php') . '?error=not_found');
    exit();
}

$payments = Db::filter('hire_contract_payments', static function (array $r) use ($prId): bool {
    return (int) ($r['pr_id'] ?? 0) === $prId;
});
Db::sortRows($payments, 'installment_no', false);

$pr = Db::row('purchase_requests', (string) $prId);
$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', false);
$company = array_values($companies)[0] ?? [];
$employerName = trim((string) ($company['name'] ?? ''));
$employerAddress = trim((string) ($company['address'] ?? ''));
$employerTaxId = trim((string) ($company['tax_id'] ?? ''));
$employerPhone = trim((string) ($company['phone'] ?? ''));
$poRows = Db::filter('purchase_orders', static function (array $r) use ($prId): bool {
    return (int) ($r['pr_id'] ?? 0) === $prId && trim((string) ($r['order_type'] ?? 'purchase')) === 'hire';
});
Db::sortRows($poRows, 'installment_no', false);
$poByNumber = [];
foreach ($poRows as $poRow) {
    $poNum = trim((string) ($poRow['po_number'] ?? ''));
    if ($poNum !== '') {
        $poByNumber[$poNum] = $poRow;
    }
}
$installmentTotalCount = max(1, (int) ($contract['installment_total'] ?? 0));
$paidInstallmentsCount = max((int) ($contract['paid_installments'] ?? 0), count($payments));
$isInstallmentCompleted = $paidInstallmentsCount >= $installmentTotalCount;

function formatThaiDateTime(string $raw): string
{
    $value = trim($raw);
    if ($value === '') {
        return '-';
    }
    try {
        $dt = new DateTime($value, new DateTimeZone('Asia/Bangkok'));
        $dt->setTimezone(new DateTimeZone('Asia/Bangkok'));
        return $dt->format('d/m/Y');
    } catch (Throwable $e) {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดสัญญาจ้าง</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .print-sheet { border-top: 5px solid #0d6efd; }
        .summary-card { background: #f8fbff; border: 1px solid #dbeafe; border-radius: 10px; }
        .summary-item { display: flex; justify-content: space-between; padding: 4px 0; }
        .table thead th { white-space: nowrap; }
        @media print {
            @page { size: A4; margin: 10mm; }
            body { background: #fff !important; }
            nav, .no-print { display: none !important; }
            .container { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
            .card { box-shadow: none !important; border: 1px solid #d9d9d9 !important; break-inside: avoid; }
            .print-sheet { border-top: 4px solid #0d6efd; }
            .table { font-size: 12px; }
        }
    </style>
</head>
<body class="bg-light">
<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h4 class="fw-bold mb-0">
            <a href="hire-contract-list.php" class="btn btn-sm btn-outline-secondary me-2" title="กลับรายการสัญญา">
                <i class="bi bi-chevron-left"></i>
            </a>
            <i class="bi bi-file-earmark-ruled text-primary me-2"></i>รายละเอียดสัญญาจ้าง
        </h4>
        <div class="d-flex gap-2">
            <?php if ($isInstallmentCompleted): ?>
                <button type="button" class="btn btn-secondary" disabled title="จ่ายครบจำนวนงวดแล้ว">
                    ออกใบสั่งจ่าย (ครบงวดแล้ว)
                </button>
            <?php else: ?>
                <a href="purchase-order-from-pr.php?pr_id=<?= $prId ?>" class="btn btn-primary">ออกใบสั่งจ่าย</a>
            <?php endif; ?>
            <button type="button" class="btn btn-dark" onclick="window.print()"><i class="bi bi-printer me-1"></i>พิมพ์</button>
        </div>
    </div>

    <div class="card border-0 shadow-sm p-3 mb-3 print-sheet">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
                <h5 class="fw-bold mb-1">*</h5>
            </div>
            <div class="text-end small">
                <div><?= htmlspecialchars((string) ($contract['pr_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>วันที่จัดจ้าง:</strong> <?= formatThaiDateTime((string) ($pr['created_at'] ?? '')) ?></div>
            </div>
        </div>
        <div class="row g-2">
            <div class="col-12">
                <strong>ผู้ว่าจ้าง:</strong>
                <?= htmlspecialchars($employerName !== '' ? $employerName : '-', ENT_QUOTES, 'UTF-8') ?>
                <?php if ($employerTaxId !== '' || $employerPhone !== ''): ?>
                    <span class="text-muted small">
                        (Tax ID: <?= htmlspecialchars($employerTaxId !== '' ? $employerTaxId : '-', ENT_QUOTES, 'UTF-8') ?> | โทร: <?= htmlspecialchars($employerPhone !== '' ? $employerPhone : '-', ENT_QUOTES, 'UTF-8') ?>)
                    </span>
                <?php endif; ?>
                <?php if ($employerAddress !== ''): ?>
                    <div class="small text-muted"><?= htmlspecialchars($employerAddress, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
            <div class="col-12"><strong>ผู้รับจ้าง:</strong> <?= htmlspecialchars((string) ($contract['contractor_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="col-12"><strong>รายละเอียดสัญญา:</strong> <?= nl2br(htmlspecialchars((string) ($contract['title'] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div>
            <div class="col-md-6"><strong>มูลค่าสัญญา:</strong> <?= number_format((float) ($contract['contract_amount'] ?? 0), 2) ?> บาท</div>
            <div class="col-md-6"><strong>จำนวนงวดตามสัญญา:</strong> <?= (int) ($contract['installment_total'] ?? 0) ?> งวด | <strong>จ่ายแล้ว:</strong> <?= (int) ($contract['paid_installments'] ?? 0) ?>/<?= (int) ($contract['installment_total'] ?? 0) ?></div>
            <div class="col-md-6"><strong>จ่ายแล้ว:</strong> <?= number_format((float) ($contract['paid_amount'] ?? 0), 2) ?> บาท</div>
            <div class="col-md-6"><strong>คงเหลือ:</strong> <?= number_format((float) ($contract['remaining_amount'] ?? 0), 2) ?> บาท</div>
        </div>
    </div>

    <div class="card border-0 shadow-sm print-sheet">
        <div class="card-header bg-white fw-bold">ตารางการจัดจ้าง / ประวัติจ่ายงวด</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>PO No.</th>
                        <th class="text-center">วันที่</th>
                        <th class="text-center">จ่ายแล้ว/จำนวนงวด</th>
                        <th class="text-end">ยอดก่อนภาษี</th>
                        <th class="text-end">VAT</th>
                        <th class="text-end">หัก ณ ที่จ่าย</th>
                        <th class="text-end">สุทธิจ่าย</th>
                        <th class="text-end">มูลค่าสัญญา</th>
                        <th class="text-center no-print">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($payments) === 0): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">ยังไม่มีการสั่งจ่าย</td></tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <?php
                            $poNumber = (string) ($p['po_number'] ?? '');
                            $po = $poByNumber[$poNumber] ?? null;
                            $poId = (int) ($po['id'] ?? 0);
                            $subAmt = (float) ($po['subtotal_amount'] ?? $p['amount'] ?? 0);
                            $vatAmt = (float) ($po['vat_amount'] ?? 0);
                            $whtAmt = (float) ($po['withholding_amount'] ?? 0);
                            $netAmt = (float) (($po['payable_amount'] ?? '') !== '' ? $po['payable_amount'] : ($subAmt + $vatAmt - $whtAmt));
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($poNumber !== '' ? $poNumber : '-', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center"><?= formatThaiDateTime((string) ($p['created_at'] ?? '')) ?></td>
                            <td class="text-center"><?= (int) ($p['installment_no'] ?? 0) ?>/<?= (int) ($p['installment_total'] ?? 0) ?></td>
                            <td class="text-end"><?= number_format($subAmt, 2) ?></td>
                            <td class="text-end"><?= number_format($vatAmt, 2) ?></td>
                            <td class="text-end"><?= number_format($whtAmt, 2) ?></td>
                            <td class="text-end fw-bold"><?= number_format($netAmt, 2) ?></td>
                            <td class="text-end"><?= number_format((float) ($p['amount'] ?? 0), 2) ?></td>
                            <td class="text-center no-print">
                                <?php if ($poId > 0): ?>
                                    <a href="purchase-order-view.php?id=<?= $poId ?>" class="btn btn-sm btn-outline-primary" title="เปิด PO">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
