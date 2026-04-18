<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

$quotes = Db::tableRows('quotations');
$customers = Db::tableKeyed('customers');
$users = Db::tableKeyed('users');
$list_rows = [];
foreach ($quotes as $q) {
    $cid = (string) ($q['customer_id'] ?? '');
    $cust = $customers[$cid] ?? null;
    $issuer = $users[(string) ($q['created_by'] ?? '')] ?? null;
    $list_rows[] = array_merge($q, [
        'customer_name' => $cust['name'] ?? '',
        'created_by_name' => trim(($issuer['fname'] ?? '') . ' ' . ($issuer['lname'] ?? '')),
    ]);
}

usort($list_rows, static function (array $a, array $b): int {
    $da = strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
    if ($da !== 0) {
        return $da;
    }

    return strcmp((string) ($b['quote_number'] ?? ''), (string) ($a['quote_number'] ?? ''));
});

$total_quotes = count($list_rows);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบจัดการใบเสนอราคา (Quotations)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .card { border: none; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        
        /* สไตล์สำหรับ Summary Card ตามรูปภาพ */
        .summary-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 25px;
            display: flex;
            align-items: center;
            transition: transform 0.2s;
        }
        .summary-card:hover { transform: translateY(-5px); }
        .icon-box {
            width: 70px;
            height: 70px;
            background-color: #FFF4E5;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
        }
        .icon-box i { font-size: 32px; color: #FF9900; }
        .stat-label { color: #888; font-size: 16px; margin-bottom: 5px; font-weight: 500; }
        .stat-value { color: #333; font-size: 32px; font-weight: 700; line-height: 1; }

        /* ตารางและปุ่ม */
        .table-container { background: white; border-radius: 15px; padding: 20px; margin-top: 20px; }
        .thead-custom { background-color: #fafafa; }
        .btn-orange { 
            background: linear-gradient(135deg, #FF9966 0%, #FF6600 100%); 
            color: white; border: none; border-radius: 10px; font-weight: 600;
        }
        .btn-orange:hover { color: white; opacity: 0.9; }
        .badge-status { border-radius: 8px; padding: 6px 12px; font-weight: 600; }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?> 

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-dark"><i class="bi bi-file-earmark-text-fill text-warning me-2"></i> รายการใบเสนอราคา</h3>
        <a href="quotation-create.php" class="btn btn-orange px-4 shadow-sm">
            <i class="bi bi-plus-lg me-2"></i> สร้างใบเสนอราคาใหม่
        </a>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="summary-card shadow-sm">
                <div class="icon-box">
                    <i class="bi bi-file-earmark-richtext"></i>
                </div>
                <div>
                    <div class="stat-label">จำนวนใบเสนอราคาทั้งหมด</div>
                    <div class="stat-value"><?= number_format($total_quotes); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="table-container shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="thead-custom">
                    <tr class="text-muted small uppercase">
                        <th class="py-3">เลขที่ใบเสนอราคา</th>
                        <th class="py-3">วันที่</th>
                        <th class="py-3">ลูกค้า</th>
                        <th class="py-3">ผู้ออกใบ</th>
                        <th class="py-3 text-end">จำนวนเงินสุทธิ</th>
                        <th class="py-3 text-center">สถานะ</th>
                        <th class="py-3 text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($list_rows) > 0): ?>
                        <?php foreach ($list_rows as $row): ?>
                        <tr>
                            <td class="fw-bold text-primary"><?= $row['quote_number']; ?></td>
                            <td><?= date('d/m/Y', strtotime($row['date'])); ?></td>
                            <td><?= htmlspecialchars($row['customer_name'] ?? 'ไม่ระบุ'); ?></td>
                            <td class="small"><?php $cb = trim((string)($row['created_by_name'] ?? '')); echo $cb !== '' ? htmlspecialchars($cb) : '<span class="text-muted">—</span>'; ?></td>
                            <td class="text-end fw-bold text-dark">
                                <?= number_format($row['grand_total'], 2); ?>
                            </td>
                            <td class="text-center">
                                <?php 
                                    $status_class = ($row['status'] == 'approved') ? 'bg-success' : 'bg-warning text-dark';
                                    $status_text = ($row['status'] == 'approved') ? 'อนุมัติแล้ว' : 'รออนุมัติ';
                                ?>
                                <span class="badge badge-status <?= $status_class; ?>"><?= $status_text; ?></span>
                            </td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <a href="quotation-view.php?id=<?= $row['id']; ?>" class="btn btn-sm btn-outline-info" title="ดูรายละเอียด"><i class="bi bi-eye"></i></a>
                                    <a href="quotation-edit.php?id=<?= $row['id']; ?>" class="btn btn-sm btn-outline-warning" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                                    <?php if($is_admin): ?>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteQuote(<?= $row['id']; ?>, '<?= $row['quote_number']; ?>')" title="ลบ"><i class="bi bi-trash"></i></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">ยังไม่มีข้อมูลใบเสนอราคาในระบบ</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const actionHandlerUrl = <?= json_encode(app_path('actions/action-handler.php'), JSON_UNESCAPED_SLASHES) ?>;
function deleteQuote(id, number) {
    Swal.fire({
        title: 'ยืนยันการลบ?',
        text: `คุณต้องการลบใบเสนอราคาเลขที่ ${number} ใช่หรือไม่?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'ใช่, ลบเลย!',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `${actionHandlerUrl}?action=delete_quotation&id=${id}`;
        }
    })
}
</script>

</body>
</html>