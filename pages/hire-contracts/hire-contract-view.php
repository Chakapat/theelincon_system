<?php
declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$contractId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$prId = isset($_GET['pr_id']) ? (int) $_GET['pr_id'] : 0;

$contract = null;
if ($contractId > 0) {
    $contract = Db::row('hire_contracts', (string) $contractId);
} elseif ($prId > 0) {
    $contract = Db::findFirst('hire_contracts', static function (array $r) use ($prId): bool {
        return (int) ($r['pr_id'] ?? 0) === $prId;
    });
}

if ($contract === null) {
    header('Location: ' . app_path('pages/hire-contracts/hire-contract-list.php') . '?error=not_found');
    exit();
}

$resolvedContractId = (int) ($contract['id'] ?? 0);
$resolvedPrId = (int) ($contract['pr_id'] ?? 0);

$payments = Db::filter('hire_contract_payments', static function (array $r) use ($resolvedContractId, $resolvedPrId): bool {
    $hid = (int) ($r['hire_contract_id'] ?? 0);
    if ($resolvedContractId > 0 && $hid > 0) {
        return $hid === $resolvedContractId;
    }

    return $resolvedPrId > 0 && (int) ($r['pr_id'] ?? 0) === $resolvedPrId;
});
Db::sortRows($payments, 'installment_no', false);

$pr = [];
if ($resolvedPrId > 0) {
    $prFound = Db::findFirst('purchase_requests', static function (array $r) use ($resolvedPrId): bool {
        return isset($r['id']) && (int) $r['id'] === $resolvedPrId;
    });
    if ($prFound !== null) {
        $pr = $prFound;
    }
}
$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', false);
$company = array_values($companies)[0] ?? [];
$employerName = trim((string) ($company['name'] ?? ''));
$employerAddress = trim((string) ($company['address'] ?? ''));
$employerTaxId = trim((string) ($company['tax_id'] ?? ''));
$employerPhone = trim((string) ($company['phone'] ?? ''));
$poRows = Db::filter('purchase_orders', static function (array $r) use ($resolvedContractId, $resolvedPrId): bool {
    if (trim((string) ($r['order_type'] ?? 'purchase')) !== 'hire') {
        return false;
    }
    if ($resolvedContractId > 0 && (int) ($r['hire_contract_id'] ?? 0) === $resolvedContractId) {
        return true;
    }

    return $resolvedPrId > 0 && (int) ($r['pr_id'] ?? 0) === $resolvedPrId;
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

        return $dt->format('d/m/Y');
    } catch (Throwable $e) {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

$payRows = [];
foreach ($payments as $p) {
    $poNumber = (string) ($p['po_number'] ?? '');
    $po = $poByNumber[$poNumber] ?? null;
    $poId = (int) ($po['id'] ?? 0);
    $subAmt = (float) ($po['subtotal_amount'] ?? $p['amount'] ?? 0);
    $vatAmt = (float) ($po['vat_amount'] ?? 0);
    $whtAmt = (float) ($po['withholding_amount'] ?? 0);
    $netAmt = (float) (($po['payable_amount'] ?? '') !== '' ? $po['payable_amount'] : ($subAmt + $vatAmt - $whtAmt));
    $payRows[] = [
        'po_number' => $poNumber !== '' ? $poNumber : '-',
        'po_id' => $poId,
        'created_at' => formatThaiDateTime((string) ($p['created_at'] ?? '')),
        'installment' => (int) ($p['installment_no'] ?? 0) . '/' . (int) ($p['installment_total'] ?? 0),
        'sub' => $subAmt,
        'vat' => $vatAmt,
        'wht' => $whtAmt,
        'net' => $netAmt,
        'contract_line' => (float) ($p['amount'] ?? 0),
    ];
}

$listUrl = app_path('pages/hire-contracts/hire-contract-list.php');
$poViewBase = app_path('pages/purchase/purchase-order-view.php');
$poFromUrl = $resolvedPrId > 0
    ? app_path('pages/purchase/purchase-order-from-pr.php') . '?pr_id=' . $resolvedPrId
    : app_path('pages/purchase/purchase-order-from-hire-contract.php') . '?hire_contract_id=' . $resolvedContractId;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สัญญาจ้าง <?= htmlspecialchars((string) ($contract['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?> | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', system-ui, sans-serif; background: #f4f7fb; min-height: 100vh; }
        .hcv-shell { max-width: 1100px; margin: 0 auto; }
        .hcv-hero {
            border-radius: 1.25rem;
            background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 50%, #2563eb 100%);
            color: #fff;
            padding: 1.5rem 1.25rem;
            box-shadow: 0 16px 48px rgba(29, 78, 216, 0.28);
        }
        .hcv-kpi {
            border-radius: 1rem;
            border: 1px solid #e2e8f0;
            background: #fff;
            padding: 1rem 1.1rem;
            height: 100%;
        }
        .hcv-kpi .label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; font-weight: 600; }
        .hcv-kpi .value { font-size: 1.35rem; font-weight: 700; font-variant-numeric: tabular-nums; }
        .hcv-section {
            border-radius: 1rem;
            background: #fff;
            border: 1px solid #e8edf4;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.05);
        }
        .hcv-section h2 { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; font-weight: 700; }
        .dl-row { display: grid; grid-template-columns: 140px 1fr; gap: 0.35rem 1rem; padding: 0.35rem 0; border-bottom: 1px solid #f1f5f9; font-size: 0.95rem; }
        .dl-row:last-child { border-bottom: 0; }
        .dl-row dt { color: #64748b; font-weight: 500; margin: 0; }
        .dl-row dd { margin: 0; color: #0f172a; }
        @media print {
            nav, .no-print { display: none !important; }
            body { background: #fff !important; }
            .hcv-hero { color: #000; background: #fff; border: 1px solid #ccc; box-shadow: none; }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 pb-5 hcv-shell">
    <?php if (isset($_GET['created']) && (string) $_GET['created'] === '1'): ?>
        <div class="alert alert-success py-2 no-print">บันทึกสัญญาจ้างเรียบร้อยแล้ว</div>
    <?php endif; ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
        <a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm rounded-pill">
            <i class="bi bi-arrow-left me-1"></i>กลับรายการ
        </a>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($isInstallmentCompleted): ?>
                <span class="btn btn-secondary btn-sm disabled rounded-pill">ครบงวดแล้ว</span>
            <?php else: ?>
                <a href="<?= htmlspecialchars($poFromUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary btn-sm rounded-pill fw-semibold">ออกใบสั่งจ่าย</a>
            <?php endif; ?>
            <button type="button" class="btn btn-dark btn-sm rounded-pill no-print" onclick="window.print()"><i class="bi bi-printer me-1"></i>พิมพ์</button>
        </div>
    </div>

    <div class="hcv-hero mb-4">
        <div class="small text-white-50 fw-semibold text-uppercase mb-1">สัญญาจ้าง</div>
        <h1 class="h4 fw-bold mb-2"><?= htmlspecialchars((string) ($contract['pr_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="text-white-50 small">วันที่จัดจ้าง <?= htmlspecialchars(formatThaiDateTime((string) (($pr['created_at'] ?? '') !== '' ? $pr['created_at'] : ($contract['created_at'] ?? ''))), ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="hcv-kpi">
                <div class="label">มูลค่าสัญญา</div>
                <div class="value text-primary"><?= number_format((float) ($contract['contract_amount'] ?? 0), 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="hcv-kpi">
                <div class="label">จ่ายแล้ว</div>
                <div class="value text-success"><?= number_format((float) ($contract['paid_amount'] ?? 0), 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="hcv-kpi">
                <div class="label">คงเหลือ</div>
                <div class="value text-danger"><?= number_format((float) ($contract['remaining_amount'] ?? 0), 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="hcv-kpi">
                <div class="label">งวด (จ่ายแล้ว/ทั้งหมด)</div>
                <div class="value"><?= (int) ($contract['paid_installments'] ?? 0) ?> / <?= (int) ($contract['installment_total'] ?? 0) ?></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="hcv-section p-4 mb-4 mb-lg-0">
                <h2 class="mb-3">คู่สัญญา</h2>
                <dl class="m-0">
                    <div class="dl-row">
                        <dt>ผู้ว่าจ้าง</dt>
                        <dd><?= htmlspecialchars($employerName !== '' ? $employerName : '-', ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <?php if ($employerTaxId !== '' || $employerPhone !== ''): ?>
                    <div class="dl-row">
                        <dt>เลขผู้เสียภาษี / โทร</dt>
                        <dd><?= htmlspecialchars($employerTaxId !== '' ? $employerTaxId : '-', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($employerPhone !== '' ? $employerPhone : '-', ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <?php endif; ?>
                    <?php if ($employerAddress !== ''): ?>
                    <div class="dl-row">
                        <dt>ที่อยู่</dt>
                        <dd><?= nl2br(htmlspecialchars($employerAddress, ENT_QUOTES, 'UTF-8')) ?></dd>
                    </div>
                    <?php endif; ?>
                    <div class="dl-row">
                        <dt>ผู้รับจ้าง</dt>
                        <dd class="fw-semibold"><?= htmlspecialchars((string) ($contract['contractor_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                </dl>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="hcv-section p-4">
                <h2 class="mb-2">ขอบเขต / รายละเอียด</h2>
                <div class="text-secondary" style="line-height: 1.65;">
                    <?= nl2br(htmlspecialchars((string) ($contract['title'] ?? '-'), ENT_QUOTES, 'UTF-8')) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="hcv-section p-3 p-md-4 mt-4">
        <h2 class="mb-3">ประวัติจ่ายงวด</h2>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 w-100" id="hirePayDT">
                <thead class="table-light">
                    <tr>
                        <th>PO No.</th>
                        <th class="text-center">วันที่</th>
                        <th class="text-center">งวด</th>
                        <th class="text-end">ก่อนภาษี</th>
                        <th class="text-end">VAT</th>
                        <th class="text-end">หัก ณ ที่จ่าย</th>
                        <th class="text-end">สุทธิจ่าย</th>
                        <th class="text-end">มูลค่าสัญญา (บรรทัด)</th>
                        <th class="text-center no-print">จัดการ</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var rows = <?= json_encode($payRows, JSON_UNESCAPED_UNICODE) ?>;
    var poViewBase = <?= json_encode($poViewBase, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function fmt(n) {
        var x = Number(n) || 0;
        return x.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    $('#hirePayDT').DataTable({
        data: rows,
        order: [[1, 'desc']],
        pageLength: 10,
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
        columns: [
            { data: 'po_number' },
            { data: 'created_at', className: 'text-center text-nowrap' },
            { data: 'installment', className: 'text-center' },
            { data: 'sub', className: 'text-end', render: fmt },
            { data: 'vat', className: 'text-end', render: fmt },
            { data: 'wht', className: 'text-end', render: fmt },
            { data: 'net', className: 'text-end fw-bold', render: fmt },
            { data: 'contract_line', className: 'text-end', render: fmt },
            {
                data: 'po_id',
                className: 'text-center no-print',
                orderable: false,
                searchable: false,
                render: function (id) {
                    if (!id) return '<span class="text-muted">—</span>';
                    return '<a href="' + poViewBase + '?id=' + id + '" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-up-right"></i></a>';
                }
            }
        ]
    });
})();
</script>
</body>
</html>
