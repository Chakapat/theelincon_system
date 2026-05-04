<?php
declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$contracts = Db::tableRows('hire_contracts');
Db::sortRows($contracts, 'id', true);
$purchaseRequests = Db::tableKeyed('purchase_requests');

$dtRows = [];
foreach ($contracts as $c) {
    $prId = (int) ($c['pr_id'] ?? 0);
    $prRow = $purchaseRequests[(string) $prId] ?? null;
    $startDate = trim((string) ($prRow['created_at'] ?? ''));
    if ($startDate === '' && $prId === 0) {
        $startDate = trim((string) ($c['created_at'] ?? ''));
    }
    $startDateText = '-';
    if ($startDate !== '') {
        $ts = strtotime($startDate);
        if ($ts !== false) {
            $startDateText = date('d/m/Y', $ts);
        }
    }
    $dtRows[] = [
        'hire_contract_id' => (int) ($c['id'] ?? 0),
        'pr_id' => $prId,
        'pr_number' => (string) ($c['pr_number'] ?? '-'),
        'start_date' => $startDateText,
        'contractor_name' => (string) ($c['contractor_name'] ?? '-'),
        'contract_amount' => (float) ($c['contract_amount'] ?? 0),
        'paid_amount' => (float) ($c['paid_amount'] ?? 0),
        'paid_installments' => (int) ($c['paid_installments'] ?? 0),
        'installment_total' => (int) ($c['installment_total'] ?? 0),
        'remaining_amount' => (float) ($c['remaining_amount'] ?? 0),
    ];
}

$liveUrl = app_path('actions/live-datasets.php?dataset=hire_contracts');
$viewUrl = app_path('pages/hire-contracts/hire-contract-view.php');
$poFromPrUrl = app_path('pages/purchase/purchase-order-from-pr.php');
$poFromHireUrl = app_path('pages/purchase/purchase-order-from-hire-contract.php');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สัญญาจ้าง | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', system-ui, sans-serif; background: linear-gradient(165deg, #f0f4ff 0%, #fafbff 40%, #fff8f0 100%); min-height: 100vh; }
        .hc-hero {
            border-radius: 1.25rem;
            background: linear-gradient(120deg, #1e3a5f 0%, #2563eb 55%, #3b82f6 100%);
            color: #fff;
            padding: 1.75rem 1.5rem;
            box-shadow: 0 12px 40px rgba(37, 99, 235, 0.25);
        }
        .hc-card {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }
        .hc-card .dataTables_wrapper .dataTables_filter input {
            border-radius: 999px;
            padding: 0.45rem 1rem;
            border: 1px solid #e2e8f0;
        }
        table.dataTable thead th { font-weight: 600; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; border-bottom: 2px solid #e2e8f0 !important; }
        table.dataTable tbody td { vertical-align: middle; font-size: 0.95rem; }
        .hc-num { font-variant-numeric: tabular-nums; }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 pb-5">
    <div class="hc-hero mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <div class="small text-white-50 text-uppercase fw-semibold mb-1">Hire contracts</div>
                <h1 class="h3 fw-bold mb-1">สัญญาจ้าง</h1>
            </div>
            <div class="text-end d-flex flex-column align-items-end gap-2">
                <a href="<?= htmlspecialchars(app_path('pages/hire-contracts/hire-contract-create.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light btn-sm fw-semibold rounded-pill shadow-sm">
                    <i class="bi bi-plus-lg me-1"></i>สร้างสัญญาจ้าง (HC)
                </a>
                <div class="rounded-4 bg-white bg-opacity-10 px-3 py-2">
                    <div class="small text-white-50">จำนวนสัญญา</div>
                    <div class="fs-2 fw-bold"><?= count($dtRows) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="hc-card bg-white p-3 p-md-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 w-100" id="hireContractDT" style="width:100%">
                <thead>
                    <tr>
                        <th>เลขที่เอกสาร</th>
                        <th>วันเริ่ม</th>
                        <th>ผู้รับจ้าง</th>
                        <th class="text-end">มูลค่าสัญญา</th>
                        <th class="text-end">จ่ายแล้ว</th>
                        <th class="text-center">งวด</th>
                        <th class="text-end">คงเหลือ</th>
                        <th class="text-center">จัดการ</th>
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
    var initial = <?= json_encode($dtRows, JSON_UNESCAPED_UNICODE) ?>;
    var viewBase = <?= json_encode($viewUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var poFromPrBase = <?= json_encode($poFromPrUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var poFromHireBase = <?= json_encode($poFromHireUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var liveUrl = <?= json_encode($liveUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function fmtMoney(n) {
        var x = Number(n) || 0;
        return x.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    var dt = window.TncLiveDT.init('#hireContractDT', {
        data: initial,
        order: [[0, 'desc']],
        pageLength: 25,
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
        columns: [
            { data: 'pr_number', className: 'fw-semibold' },
            { data: 'start_date', className: 'text-center text-nowrap' },
            { data: 'contractor_name' },
            { data: 'contract_amount', className: 'text-end hc-num', render: function (v) { return fmtMoney(v); } },
            { data: 'paid_amount', className: 'text-end hc-num', render: function (v) { return fmtMoney(v); } },
            {
                data: null,
                className: 'text-center text-nowrap',
                render: function (row) {
                    return (row.paid_installments || 0) + '/' + (row.installment_total || 0);
                }
            },
            { data: 'remaining_amount', className: 'text-end hc-num fw-semibold', render: function (v) { return fmtMoney(v); } },
            {
                data: null,
                orderable: false,
                searchable: false,
                className: 'text-center text-nowrap',
                render: function (row) {
                    var pid = row.pr_id || 0;
                    var hid = row.hire_contract_id || 0;
                    var viewQ = pid > 0 ? ('?pr_id=' + pid) : ('?id=' + hid);
                    var poUrl = pid > 0 ? (poFromPrBase + '?pr_id=' + pid) : (poFromHireBase + '?hire_contract_id=' + hid);
                    return '<a href="' + viewBase + viewQ + '" class="btn btn-sm btn-outline-primary me-1" title="ดูรายละเอียด"><i class="bi bi-eye"></i></a>' +
                        '<a href="' + poUrl + '" class="btn btn-sm btn-primary" title="ออก PO"><i class="bi bi-file-earmark-plus"></i></a>';
                }
            }
        ]
    }, {
        url: liveUrl,
        intervalMs: 5000,
        mapRows: function (resp) {
            return resp.rows || [];
        }
    });
})();
</script>
</body>
</html>
