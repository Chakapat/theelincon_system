<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/banks.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$title = 'เพิ่มรายชื่อผู้ขายใหม่';
$action_type = 'add_supplier';

$supplier = [
    'name' => '',
    'tax_id' => '',
    'contact_person' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'bank_name' => '',
    'bank_account_name' => '',
    'bank_account_number' => '',
];

$banks = tnc_bank_options();
$bankLogos = tnc_bank_logo_url_map();

if ($id > 0) {
    $row = Db::rowByIdField('suppliers', $id);
    if ($row !== null) {
        $supplier = array_merge($supplier, $row);
        $title = 'แก้ไขข้อมูลผู้ขาย';
        $action_type = 'save_supplier';
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { background-color: #f4f7f6; font-family: 'Sarabun', sans-serif; }
        .form-card { 
            border: none; 
            border-radius: 20px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.05); 
            background: #ffffff;
        }
        .form-label { font-weight: 600; color: #495057; }
        .input-group-text { background-color: #f8f9fa; border-right: none; }
        .form-control { border-left: none; }
        .form-control:focus { box-shadow: none; border-color: #dee2e6; }
        .btn-save { background-color: #198754; color: white; border-radius: 10px; padding: 12px 30px; font-weight: 600; transition: 0.3s; }
        .btn-save:hover { background-color: #146c43; transform: translateY(-2px); }
        .bank-logo-chip { width: 22px; height: 22px; object-fit: contain; border-radius: 4px; flex-shrink: 0; }
        .bank-select-preview { display: inline-flex; align-items: center; gap: 6px; min-height: 22px; font-size: 12px; color: #6b7280; }
    </style>
</head>
<body class="tnc-app-body">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            
            <div class="d-flex align-items-center mb-4">
                <a href="<?= htmlspecialchars(app_path('pages/suppliers/supplier-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm rounded-circle me-3">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <h2 class="fw-bold mb-0 text-dark"><?= $title ?></h2>
            </div>

            <div class="card form-card p-4 p-md-5">
                <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=save_supplier" method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="id" value="<?= $id ?>">

                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label">ชื่อบริษัท / ชื่อผู้ขาย <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-building"></i></span>
                                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($supplier['name']) ?>" required placeholder="ระบุชื่อบริษัทหรือชื่อร้านค้า">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">เลขประจำตัวผู้เสียภาษี</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-card-checklist"></i></span>
                                <input type="text" name="tax_id" class="form-control" value="<?= htmlspecialchars($supplier['tax_id']) ?>" placeholder="เลข 13 หลัก (ถ้ามี)">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">ชื่อผู้ติดต่อ / เซลล์</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($supplier['contact_person']) ?>" placeholder="ชื่อบุคคลที่ประสานงานด้วย">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">เบอร์โทรศัพท์</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($supplier['phone']) ?>" placeholder="012-345-6789">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">อีเมล</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($supplier['email']) ?>" placeholder="example@company.com">
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">ที่อยู่บริษัท</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                                <textarea name="address" class="form-control" rows="3" placeholder="ระบุที่อยู่จัดส่งเอกสารหรือที่อยู่ตามทะเบียนภาษี"><?= htmlspecialchars($supplier['address']) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="border-top pt-4 mt-2 mb-4">
                        <div class="small fw-bold text-muted mb-3"><i class="bi bi-bank2 me-1"></i>บัญชีรับโอน (PAYMENT INFO)</div>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">ธนาคาร</label>
                                <select name="bank_name" class="form-select js-bank-select" data-form-scope="supplier">
                                    <option value="">— ไม่ระบุ —</option>
                                    <?php foreach ($banks as $bank): ?>
                                    <option value="<?= htmlspecialchars($bank, ENT_QUOTES, 'UTF-8') ?>"<?= ((string) ($supplier['bank_name'] ?? '') === $bank) ? ' selected' : '' ?>><?= htmlspecialchars($bank, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="bank-select-preview mt-1 js-bank-logo-preview" data-form-scope="supplier"></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">ชื่อบัญชี</label>
                                <input type="text" name="bank_account_name" class="form-control" maxlength="200" value="<?= htmlspecialchars((string) ($supplier['bank_account_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="ชื่อบัญชีตามสมุดธนาคาร">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">เลขที่บัญชี</label>
                                <input type="text" name="bank_account_number" class="form-control font-monospace" maxlength="20" inputmode="numeric" value="<?= htmlspecialchars((string) ($supplier['bank_account_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="ตัวเลขเท่านั้น">
                            </div>
                        </div>
                    </div>

                    <hr class="my-5">

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= htmlspecialchars(app_path('pages/suppliers/supplier-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light px-4 py-2 border">ยกเลิก</a>
                        <button type="submit" class="btn btn-save shadow-sm">
                            <i class="bi bi-check-lg me-2"></i> บันทึกข้อมูล
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const BANK_LOGOS = <?= json_encode($bankLogos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function updateBankLogoPreview(scope, bankName) {
    const el = document.querySelector('.js-bank-logo-preview[data-form-scope="' + scope + '"]');
    if (!el) return;
    const name = String(bankName || '').trim();
    const url = name && BANK_LOGOS[name] ? BANK_LOGOS[name] : '';
    if (!name) {
        el.innerHTML = '';
        return;
    }
    el.innerHTML = url
        ? '<img src="' + url + '" alt="" class="bank-logo-chip"><span>' + name + '</span>'
        : '<span>' + name + '</span>';
}

document.querySelectorAll('.js-bank-select').forEach(function (sel) {
    const scope = sel.getAttribute('data-form-scope') || 'supplier';
    sel.addEventListener('change', function () { updateBankLogoPreview(scope, sel.value); });
    updateBankLogoPreview(scope, sel.value);
});
</script>
</body>
</html>