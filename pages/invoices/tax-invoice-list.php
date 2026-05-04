<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$is_admin = user_is_admin_role();
$csrfQ = '&_csrf=' . rawurlencode(csrf_token());

$taxRows = Db::tableRows('tax_invoices');
$invoices = Db::tableKeyed('invoices');
$customers = Db::tableKeyed('customers');
$users = Db::tableKeyed('users');

$listRows = [];
$grandTotalSum = 0.0;
foreach ($taxRows as $tax) {
    $invoiceId = (string) ($tax['invoice_id'] ?? '');
    $inv = $invoices[$invoiceId] ?? null;
    if ($inv === null) {
        continue;
    }

    $cust = $customers[(string) ($inv['customer_id'] ?? '')] ?? null;
    $issuer = $users[(string) ($inv['created_by'] ?? '')] ?? null;
    $grand = (float) ($tax['grand_total'] ?? ($inv['total_amount'] ?? 0));

    $listRows[] = [
        'tax_id' => (int) ($tax['id'] ?? 0),
        'tax_invoice_number' => strtoupper((string) ($tax['tax_invoice_number'] ?? '')),
        'tax_date' => (string) ($tax['tax_date'] ?? ''),
        'invoice_id' => (int) ($inv['id'] ?? 0),
        'invoice_number' => (string) ($inv['invoice_number'] ?? ''),
        'customer_name' => (string) ($cust['name'] ?? ''),
        'issuer_name' => trim((string) (($issuer['fname'] ?? '') . ' ' . ($issuer['lname'] ?? ''))),
        'grand_total' => $grand,
    ];
    $grandTotalSum += $grand;
}

usort($listRows, static function (array $a, array $b): int {
    $dateCmp = strcmp((string) ($b['tax_date'] ?? ''), (string) ($a['tax_date'] ?? ''));
    if ($dateCmp !== 0) {
        return $dateCmp;
    }
    return strcmp((string) ($b['tax_invoice_number'] ?? ''), (string) ($a['tax_invoice_number'] ?? ''));
});

$totalCount = count($listRows);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการ Tax Invoice</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .table-card { border: none; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
        .btn-orange { background-color: #fd7e14; color: white; border: none; }
        .btn-orange:hover { background-color: #e86c00; color: white; }
        .summary-card { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 0 15px rgba(0,0,0,0.04); }
        .summary-icon { width: 56px; height: 56px; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-4 mb-5">
    <?php if (!empty($_GET['created'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php
            $createdNo = isset($_GET['tax_no']) ? trim((string) $_GET['tax_no']) : '';
            echo $createdNo !== ''
                ? 'บันทึก Tax INV สำเร็จแล้ว เลขที่ ' . htmlspecialchars($createdNo, ENT_QUOTES, 'UTF-8')
                : 'บันทึก Tax INV สำเร็จแล้ว';
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ลบรายการ Tax INV เรียบร้อยแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold mb-0">
            <i class="bi bi-file-earmark-break-fill text-success me-2"></i>รายการใบกำกับภาษี
        </h3>
        <a href="<?= htmlspecialchars(app_path('pages/invoices/tax-invoice-receipt.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange rounded-pill px-4 shadow-sm">
            <i class="bi bi-plus-lg me-1"></i>สร้างใบกำกับภาษี
        </a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="summary-card h-100">
                <div class="d-flex align-items-center gap-3">
                    <span class="summary-icon bg-success-subtle text-success"><i class="bi bi-receipt"></i></span>
                    <div>
                        <div class="text-muted small">จำนวนใบกำกับภาษีทั้งหมด</div>
                        <div class="fw-bold fs-4"><?= number_format($totalCount) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="summary-card h-100">
                <div class="d-flex align-items-center gap-3">
                    <span class="summary-icon bg-warning-subtle text-warning"><i class="bi bi-cash-coin"></i></span>
                    <div>
                        <div class="text-muted small">ยอดเงินรวมใบกำกับภาษี</div>
                        <div class="fw-bold fs-4">฿ <?= number_format($grandTotalSum, 2) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card table-card p-3 p-md-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="taxTable" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่ใบกำกับภาษี</th>
                        <th>วันที่</th>
                        <th>อ้างอิงใบแจ้งหนี้</th>
                        <th>ลูกค้า</th>
                        <th>ผู้ออกใบ</th>
                        <th class="text-end">ยอดสุทธิ</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($totalCount > 0): ?>
                        <?php foreach ($listRows as $row): ?>
                            <tr>
                                <td class="fw-bold text-success"><?= htmlspecialchars($row['tax_invoice_number'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $row['tax_date'] !== '' ? htmlspecialchars(date('d/m/Y', strtotime($row['tax_date'])), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                                <td><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><?= htmlspecialchars($row['invoice_number'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars($row['customer_name'] !== '' ? $row['customer_name'] : 'ไม่ระบุ', ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="small"><?= htmlspecialchars($row['issuer_name'] !== '' ? $row['issuer_name'] : '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-end fw-bold">฿ <?= number_format((float) $row['grand_total'], 2) ?></td>
                                <td class="text-center">
                                    <a href="<?= htmlspecialchars(app_path('pages/invoices/tax-invoice-receipt.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $row['invoice_id'] ?>" class="btn btn-sm btn-outline-success" title="ดูเอกสาร Tax INV">
                                        <i class="bi bi-eye-fill"></i>
                                    </a>
                                    <a href="<?= htmlspecialchars(app_path('pages/invoices/tax-invoice-receipt.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $row['invoice_id'] ?>&edit=1" class="btn btn-sm btn-outline-info" title="แก้ไข Tax INV">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                    <?php if ($is_admin): ?>
                                        <a href="<?= htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8') ?>?action=delete&type=tax_invoice&id=<?= (int) $row['tax_id'] ?><?= htmlspecialchars($csrfQ, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-danger" title="ลบรายการ Tax INV" onclick="return confirm('ยืนยันการลบ Tax INV <?= htmlspecialchars($row['tax_invoice_number'], ENT_QUOTES, 'UTF-8') ?> ?');">
                                            <i class="bi bi-trash-fill"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">ยังไม่มีรายการ Tax INV</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function ($) {
    if ($('#taxTable tbody tr td[colspan]').length === 0 && $('#taxTable tbody tr').length) {
        $('#taxTable').DataTable({
            order: [[1, 'desc']],
            pageLength: 25,
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
            columnDefs: [{ targets: [6], orderable: false, searchable: false }]
        });
    }
    var u = <?= json_encode(app_path('actions/live-datasets.php?dataset=mirror_table&table=tax_invoices'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var c = '';
    setInterval(function () {
        if (document.hidden) return;
        fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.ok) return;
            if (c === '') { c = d.checksum; return; }
            if (d.checksum !== c) window.location.reload();
        }).catch(function () {});
    }, 6000);
})(jQuery);
</script>

</body>
</html>
