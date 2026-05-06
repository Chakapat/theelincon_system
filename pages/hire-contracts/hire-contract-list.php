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
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', system-ui, sans-serif; background: linear-gradient(165deg, #f0f4ff 0%, #fafbff 40%, #fff8f0 100%); min-height: 100vh; }
        .hc-hero {
            border-radius: 12px;
            background: linear-gradient(125deg, #1a365d 0%, #2c5282 28%, #3182ce 62%, #4299e1 100%);
            color: #fff;
            padding: 1.5rem 1.5rem 1.6rem;
            box-shadow: 0 10px 36px rgba(30, 64, 120, 0.22);
            position: relative;
            overflow: hidden;
        }
        .hc-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 85% 70% at 100% 0%, rgba(255, 255, 255, 0.14) 0%, transparent 55%);
            pointer-events: none;
        }
        .hc-hero-inner { position: relative; z-index: 1; }
        .hc-hero-stat {
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.22);
            padding: 0.35rem 1rem 0.35rem 0.85rem;
            backdrop-filter: blur(6px);
        }
        .hc-hero-stat .hc-hero-stat-value {
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -0.02em;
        }
        .hc-hero .btn-create-hc {
            border-radius: 12px;
            font-weight: 600;
            padding: 0.55rem 1.15rem;
            border: none;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.12);
        }
        .hc-hero .btn-create-hc:hover {
            background: #fff !important;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.14);
        }
        .hc-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }
        .hc-toolbar .dataTables_length label {
            margin-bottom: 0;
            font-size: 0.875rem;
            color: #495057;
        }
        .hc-toolbar .dataTables_length select {
            border-radius: 10px;
            margin: 0 0.35rem;
        }
        .hc-search-wrap {
            position: relative;
            flex: 1 1 220px;
            min-width: 200px;
            max-width: 420px;
        }
        .hc-search-wrap .bi-search {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #868e96;
            font-size: 1rem;
            pointer-events: none;
        }
        .hc-search-wrap .form-control {
            border-radius: 12px;
            padding-left: 2.5rem;
            border: 1px solid #e9ecef;
            box-shadow: none;
        }
        .hc-search-wrap .form-control:focus {
            border-color: #ced4da;
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.12);
        }
        #hireContractDT.dataTable thead th {
            font-weight: 600;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #64748b;
            border-bottom: 2px solid #e9ecef !important;
            padding-top: 0.9rem;
            padding-bottom: 0.9rem;
        }
        #hireContractDT.dataTable tbody td {
            vertical-align: middle;
            font-size: 0.95rem;
            padding-top: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eef1f4;
        }
        #hireContractDT.dataTable tbody tr:last-child td { border-bottom: none; }
        .hc-num { font-variant-numeric: tabular-nums; }
        .hc-remaining-zero { color: #adb5bd !important; font-weight: 500 !important; }
        .hc-btn-icon {
            width: 2.25rem;
            height: 2.25rem;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            border: none;
            text-decoration: none;
            transition: transform 0.12s ease, box-shadow 0.12s ease, filter 0.12s ease;
        }
        .hc-btn-icon:hover { transform: translateY(-1px); }
        .hc-btn-view {
            background: #e7f1ff;
            color: #0d6efd;
        }
        .hc-btn-view:hover { background: #cfe2ff; color: #0a58ca; }
        .hc-btn-po {
            background: #fff4e6;
            color: #d97706;
        }
        .hc-btn-po:hover { background: #ffe8cc; color: #b45309; }
        #hireContractDT_wrapper .dataTables_info,
        #hireContractDT_wrapper .dataTables_paginate { padding-top: 0.75rem; }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 pb-5">
    <div class="hc-hero mb-4">
        <div class="hc-hero-inner d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="flex-grow-1" style="min-width: 220px;">
                <div class="small text-white-50 text-uppercase fw-semibold mb-2" style="letter-spacing: 0.04em;">Hire contracts</div>
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <h1 class="h3 fw-bold mb-0">สัญญาจ้าง</h1>
                    <div class="hc-hero-stat d-inline-flex align-items-center gap-2">
                        <span class="small text-white-50 text-nowrap">สัญญาทั้งหมด</span>
                        <span class="hc-hero-stat-value text-white"><?= count($dtRows) ?></span>
                    </div>
                </div>
            </div>
            <div class="flex-shrink-0">
                <a href="<?= htmlspecialchars(app_path('pages/hire-contracts/hire-contract-create.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light btn-create-hc">
                    <i class="bi bi-plus-lg me-1"></i>สร้างสัญญาจ้าง
                </a>
            </div>
        </div>
    </div>

    <div class="hc-card bg-white p-3 p-md-4">
        <div class="hc-toolbar d-flex flex-wrap align-items-center gap-2 gap-md-3 mb-3">
            <div class="hc-search-wrap">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="hcSearchInput">ค้นหา</label>
                <input type="search" id="hcSearchInput" class="form-control" placeholder="ค้นหาเลขที่เอกสาร, ผู้รับจ้าง..." autocomplete="off">
            </div>
            <div id="hcLengthSlot" class="ms-md-auto"></div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 w-100" id="hireContractDT" style="width:100%">
                <thead>
                    <tr>
                        <th>เลขที่เอกสาร</th>
                        <th>วันเริ่ม</th>
                        <th>ผู้รับจ้าง</th>
                        <th class="text-end hc-num">มูลค่าสัญญา</th>
                        <th class="text-end hc-num">จ่ายแล้ว</th>
                        <th class="text-center">งวด</th>
                        <th class="text-end hc-num">คงเหลือ</th>
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
        dom: 'lrtip',
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
        initComplete: function () {
            var api = this.api();
            var $len = $('#hireContractDT_wrapper .dataTables_length');
            if ($len.length && $('#hcLengthSlot').length) {
                $('#hcLengthSlot').append($len);
            }
            $('#hcSearchInput').on('keyup search input', function () {
                api.search(this.value).draw();
            });
        },
        columns: [
            { data: 'pr_number', className: 'fw-bold' },
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
            {
                data: 'remaining_amount',
                className: 'text-end hc-num',
                render: function (v) {
                    var n = Number(v) || 0;
                    var isZero = Math.abs(n) < 0.000001;
                    var cls = isZero ? 'hc-remaining-zero' : 'fw-semibold';
                    return '<span class="' + cls + '">' + fmtMoney(v) + '</span>';
                }
            },
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
                    return '<div class="d-inline-flex align-items-center justify-content-center gap-1 flex-wrap">' +
                        '<a href="' + viewBase + viewQ + '" class="hc-btn-icon hc-btn-view" title="ดูรายละเอียด"><i class="bi bi-eye-fill"></i></a>' +
                        '<a href="' + poUrl + '" class="hc-btn-icon hc-btn-po" title="ออก PO"><i class="bi bi-file-earmark-plus-fill"></i></a>' +
                        '</div>';
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
