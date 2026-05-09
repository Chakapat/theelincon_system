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

$totalContracts = count($dtRows);
$totalContractValue = 0.0;
$totalRemaining = 0.0;
foreach ($dtRows as $r) {
    $totalContractValue += (float) ($r['contract_amount'] ?? 0);
    $totalRemaining += (float) ($r['remaining_amount'] ?? 0);
}
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
        body { font-family: 'Sarabun', system-ui, sans-serif; background: #f6f7f9; min-height: 100vh; }
        .hc-page-wrap { max-width: 1480px; }
        .hc-header-card {
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 0.3rem 1rem rgba(0, 0, 0, 0.05);
            padding: 1rem 1.1rem;
        }
        .hc-header-title { color: #1f2937; letter-spacing: .01em; }
        .hc-header-subtitle { color: #6b7280; font-size: .9rem; }
        .hc-summary-card {
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 0.26rem .9rem rgba(0, 0, 0, 0.045);
            padding: .95rem 1rem;
        }
        .hc-summary-value {
            font-size: clamp(1.2rem, 2.5vw, 1.85rem);
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -.02em;
        }
        .hc-summary-label {
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .04em;
            color: #6b7280;
            text-transform: uppercase;
        }
        .hc-card {
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 14px;
            box-shadow: 0 0.3rem 1rem rgba(0, 0, 0, 0.05);
            overflow: hidden;
            background: #fff;
        }
        .btn-create-hc {
            border-radius: 999px;
            font-weight: 700;
            padding: .56rem 1.1rem;
            border: none;
            background: linear-gradient(135deg, #fd7e14 0%, #f76707 100%);
            color: #fff;
            box-shadow: 0 0.38rem .95rem rgba(253,126,20,.3);
        }
        .btn-create-hc:hover { color: #fff; filter: brightness(1.03); transform: translateY(-1px); }
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
            min-height: 42px;
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
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc !important;
        }
        #hireContractDT.dataTable tbody td {
            vertical-align: middle;
            font-size: 0.95rem;
            padding-top: 1.05rem;
            padding-bottom: 1.05rem;
            border-bottom: 1px solid #eef1f4;
        }
        #hireContractDT.dataTable tbody tr { transition: background-color .15s ease, box-shadow .15s ease; }
        #hireContractDT.dataTable tbody tr:hover { background: #fff9f2; box-shadow: inset 0 0 0 1px rgba(253,126,20,.1); }
        #hireContractDT.dataTable tbody tr:last-child td { border-bottom: none; }
        .hc-num {
            font-variant-numeric: tabular-nums;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        }
        .hc-remaining-zero { color: #adb5bd !important; font-weight: 600 !important; }
        .hc-remaining-alert { color: #b02a37 !important; font-weight: 700 !important; }
        .hc-btn-action {
            width: 2.3rem;
            height: 2.3rem;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: .6rem;
            border: 1px solid transparent;
            text-decoration: none;
            transition: transform 0.12s ease, box-shadow 0.12s ease;
        }
        .hc-btn-action:hover { transform: translateY(-1px); }
        .hc-btn-view {
            background: rgba(13, 110, 253, 0.14);
            color: #0a58ca;
            border-color: rgba(13, 110, 253, 0.2);
        }
        .hc-btn-view:hover { background: rgba(13, 110, 253, 0.22); color: #084298; }
        .hc-btn-po {
            background: rgba(108, 117, 125, 0.16);
            color: #495057;
            border-color: rgba(108, 117, 125, 0.22);
        }
        .hc-btn-po:hover { background: rgba(108, 117, 125, 0.24); color: #343a40; }
        #hireContractDT_wrapper .dataTables_info,
        #hireContractDT_wrapper .dataTables_paginate { padding-top: 0.75rem; }
        .hc-table-wrap {
            max-height: min(70vh, 620px);
            overflow: auto;
        }
        @media (max-width: 767.98px) {
            .hc-page-wrap { padding-left: .6rem; padding-right: .6rem; }
            .btn-create-hc { width: 100%; }
            .hc-search-wrap { max-width: none; min-width: 0; width: 100%; }
            .hc-table-wrap { max-height: none; overflow: visible; }
            #hireContractDT.dataTable thead { display: none; }
            #hireContractDT.dataTable tbody tr {
                display: block;
                border: 1px solid rgba(0, 0, 0, 0.1);
                border-radius: .75rem;
                background: #fff;
                margin: .6rem 0;
                box-shadow: 0 .12rem .65rem rgba(0,0,0,.04);
            }
            #hireContractDT.dataTable tbody td {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: .65rem;
                text-align: right !important;
                border-bottom: 1px dashed rgba(0,0,0,.1);
                padding: .62rem .75rem !important;
            }
            #hireContractDT.dataTable tbody td::before {
                content: attr(data-label);
                flex: 0 0 6.15rem;
                text-align: left;
                color: #6b7280;
                font-size: .74rem;
                font-weight: 700;
                letter-spacing: .03em;
                text-transform: uppercase;
            }
            #hireContractDT.dataTable tbody td:last-child { border-bottom: 0; }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 pb-5 hc-page-wrap">
    <div class="hc-header-card mb-3">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
            <div>
                <div class="small text-muted text-uppercase fw-semibold mb-1" style="letter-spacing:.04em;">Hire Contracts</div>
                <h1 class="h3 fw-bold mb-1 hc-header-title">สัญญาจ้าง</h1>
                <p class="hc-header-subtitle mb-0">ติดตามสถานะสัญญา มูลค่า จ่ายแล้ว และยอดคงเหลือแบบเรียลไทม์</p>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="hc-summary-card h-100">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary" style="width:2.1rem;height:2.1rem;"><i class="bi bi-file-earmark-text"></i></span>
                    <span class="hc-summary-label">Total Contracts</span>
                </div>
                <div class="hc-summary-value text-dark"><?= number_format($totalContracts) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="hc-summary-card h-100">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-success-subtle text-success" style="width:2.1rem;height:2.1rem;"><i class="bi bi-wallet2"></i></span>
                    <span class="hc-summary-label">Total Contract Value</span>
                </div>
                <div class="hc-summary-value text-success hc-num"><?= number_format($totalContractValue, 2) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="hc-summary-card h-100">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-warning-subtle text-warning" style="width:2.1rem;height:2.1rem;"><i class="bi bi-hourglass-split"></i></span>
                    <span class="hc-summary-label">Total Remaining Balance</span>
                </div>
                <div class="hc-summary-value <?= $totalRemaining > 0 ? 'text-danger' : 'text-dark' ?> hc-num"><?= number_format($totalRemaining, 2) ?></div>
            </div>
        </div>
    </div>

    <div class="hc-card bg-white p-3 p-md-4">
        <div class="hc-toolbar d-flex flex-wrap align-items-center gap-2 gap-md-3 mb-3">
            <a href="<?= htmlspecialchars(app_path('pages/hire-contracts/hire-contract-create.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-create-hc">
                <i class="bi bi-plus-lg me-1"></i>สร้างสัญญาจ้าง
            </a>
            <div class="hc-search-wrap">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="hcSearchInput">ค้นหา</label>
                <input type="search" id="hcSearchInput" class="form-control" placeholder="ค้นหาเลขที่เอกสาร, ผู้รับจ้าง..." autocomplete="off">
            </div>
            <div id="hcLengthSlot" class="ms-md-auto"></div>
        </div>
        <div class="table-responsive hc-table-wrap">
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
                    var cls = isZero ? 'hc-remaining-zero' : 'hc-remaining-alert';
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
                        '<a href="' + viewBase + viewQ + '" class="hc-btn-action hc-btn-view" title="ดูรายละเอียด"><i class="bi bi-eye-fill"></i></a>' +
                        '<a href="' + poUrl + '" class="hc-btn-action hc-btn-po" title="จัดการเอกสาร"><i class="bi bi-pencil-square"></i></a>' +
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

    function applyMobileDataLabels() {
        var labels = ['เลขที่เอกสาร','วันเริ่ม','ผู้รับจ้าง','มูลค่าสัญญา','จ่ายแล้ว','งวด','คงเหลือ','จัดการ'];
        $('#hireContractDT tbody tr').each(function () {
            $(this).children('td').each(function (idx) {
                this.setAttribute('data-label', labels[idx] || '');
            });
        });
    }
    dt.on('draw', applyMobileDataLabels);
    applyMobileDataLabels();
})();
</script>
</body>
</html>
