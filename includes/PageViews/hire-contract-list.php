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

$contracts = Db::tableRows('hire_contracts');
Db::sortRows($contracts, 'id', true);
$purchaseRequests = Db::tableKeyed('purchase_requests');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สัญญาจ้าง</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<?php include THEELINCON_ROOT . '/components/navbar.php'; ?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-ruled text-primary me-2"></i>รายการสัญญาจ้าง</h4>
        <div style="min-width: 280px;">
            <input type="search" id="contractSearch" class="form-control" placeholder="ค้นหา">
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>PR No.</th>
                        <th class="text-center">วันเริ่มสัญญา</th>
                        <th>ผู้รับจ้าง</th>
                        <th class="text-end">มูลค่าสัญญา</th>
                        <th class="text-end">จ่ายแล้ว</th>
                        <th class="text-center">งวด</th>
                        <th class="text-end">คงเหลือ</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody id="contractTableBody">
                <?php if (count($contracts) === 0): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มีสัญญาจ้าง</td></tr>  
                <?php else: ?>
                    <?php foreach ($contracts as $c): ?>
                        <?php
                            $prId = (int) ($c['pr_id'] ?? 0);
                            $prRow = $purchaseRequests[(string) $prId] ?? null;
                            $startDate = trim((string) ($prRow['created_at'] ?? ''));
                            $startDateText = '-';
                            if ($startDate !== '') {
                                $ts = strtotime($startDate);
                                if ($ts !== false) {
                                    $startDateText = date('d/m/Y', $ts);
                                }
                            }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($c['pr_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center"><?= htmlspecialchars($startDateText, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($c['contractor_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-end"><?= number_format((float) ($c['contract_amount'] ?? 0), 2) ?></td>
                            <td class="text-end"><?= number_format((float) ($c['paid_amount'] ?? 0), 2) ?></td>
                            <td class="text-center"><?= (int) ($c['paid_installments'] ?? 0) ?>/<?= (int) ($c['installment_total'] ?? 0) ?></td>
                            <td class="text-end"><?= number_format((float) ($c['remaining_amount'] ?? 0), 2) ?></td>
                            <td class="text-center">
                                <a href="hire-contract-view.php?pr_id=<?= $prId ?>" class="btn btn-sm btn-outline-primary" title="ดูรายละเอียด">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="purchase-order-from-pr.php?pr_id=<?= $prId ?>" class="btn btn-sm btn-primary" title="ออก PO สั่งจ่าย">
                                    <i class="bi bi-file-earmark-plus"></i>
                                </a>
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
<script>
(function () {
    const input = document.getElementById('contractSearch');
    const tbody = document.getElementById('contractTableBody');
    if (!input || !tbody) return;

    input.addEventListener('input', function () {
        const q = (input.value || '').trim().toLowerCase();
        const rows = Array.from(tbody.querySelectorAll('tr'));
        rows.forEach((row) => {
            const txt = (row.textContent || '').toLowerCase();
            const isEmptyRow = txt.includes('ยังไม่มีสัญญาจ้าง');
            if (isEmptyRow) return;
            row.style.display = q === '' || txt.includes(q) ? '' : 'none';
        });
    });
})();
</script>
</body>
</html>
