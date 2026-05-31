<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/contractors.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$title = 'ลงทะเบียนผู้รับจ้างใหม่';
$errorCode = trim((string) ($_GET['error'] ?? ''));

$contractor = [
    'title_prefix_th' => '',
    'first_name_th' => '',
    'last_name_th' => '',
    'title_prefix_en' => '',
    'first_name_en' => '',
    'last_name_en' => '',
    'national_id' => '',
    'birth_date' => '',
    'address' => '',
    'payment_method' => 'bank_transfer',
    'bank_account_no' => '',
    'bank_name' => '',
    'bank_account_name' => '',
    'id_card_photo_path' => '',
    'id_card_photo_name' => '',
];

if ($id > 0) {
    $row = Db::rowByIdField('contractors', $id);
    if ($row !== null) {
        $contractor = array_merge($contractor, $row);
        $title = 'แก้ไขข้อมูลผู้รับจ้าง';
    }
}

$banks = tnc_contractor_bank_options();
$paymentMethods = tnc_contractor_payment_methods();
$titlePrefixThOptions = tnc_contractor_title_prefix_th_options();
$titlePrefixEnOptions = tnc_contractor_title_prefix_en_options();
$photoUrl = tnc_contractor_id_photo_url($contractor);
$hasPhoto = trim((string) ($contractor['id_card_photo_path'] ?? '')) !== '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background: linear-gradient(165deg, #f0f4f8 0%, #f8f9fb 100%); font-family: 'Sarabun', sans-serif; min-height: 100vh; }
        .form-card { border: none; border-radius: 1rem; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08); background: #fff; }
        .section-head { font-size: 0.78rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #64748b; margin-bottom: 0.75rem; }
        .photo-preview { max-width: 280px; border-radius: 0.75rem; border: 1px solid #e2e8f0; }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-xl-9">
            <div class="d-flex align-items-center mb-4">
                <a href="<?= htmlspecialchars(app_path('pages/contractors/contractor-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm rounded-circle me-3"><i class="bi bi-chevron-left"></i></a>
                <h2 class="fw-bold mb-0"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
            </div>

            <?php if ($errorCode === 'required'): ?>
                <div class="alert alert-warning">กรุณากรอกข้อมูลให้ครบทุกช่อง</div>
            <?php elseif ($errorCode === 'invalid_national_id'): ?>
                <div class="alert alert-warning">เลขบัตรประชาชนไม่ถูกต้อง</div>
            <?php elseif ($errorCode === 'duplicate_national_id'): ?>
                <div class="alert alert-danger">เลขบัตรประชาชนนี้มีในระบบแล้ว</div>
            <?php elseif ($errorCode === 'upload_type'): ?>
                <div class="alert alert-warning">รูปบัตรประชาชนรองรับเฉพาะ JPG, PNG, WEBP</div>
            <?php elseif ($errorCode === 'upload_failed' || $errorCode === 'photo_required'): ?>
                <div class="alert alert-warning">กรุณาแนบรูปบัตรประชาชน</div>
            <?php endif; ?>

            <div class="card form-card p-4 p-md-5">
                <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=save_contractor" method="POST" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="id" value="<?= $id ?>">

                    <div class="section-head">ข้อมูลส่วนตัว</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">คำนำหน้า (ไทย) <span class="text-danger">*</span></label>
                            <select name="title_prefix_th" class="form-select" required>
                                <option value="">— เลือก —</option>
                                <?php foreach ($titlePrefixThOptions as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"<?= ((string) ($contractor['title_prefix_th'] ?? '') === $value) ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">ชื่อ (ภาษาไทย) <span class="text-danger">*</span></label>
                            <input type="text" name="first_name_th" class="form-control" required maxlength="120" value="<?= htmlspecialchars((string) ($contractor['first_name_th'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">นามสกุล (ภาษาไทย) <span class="text-danger">*</span></label>
                            <input type="text" name="last_name_th" class="form-control" required maxlength="120" value="<?= htmlspecialchars((string) ($contractor['last_name_th'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">คำนำหน้า (อังกฤษ) <span class="text-danger">*</span></label>
                            <select name="title_prefix_en" class="form-select" required>
                                <option value="">— Select —</option>
                                <?php foreach ($titlePrefixEnOptions as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"<?= ((string) ($contractor['title_prefix_en'] ?? '') === $value) ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">ชื่อ (ภาษาอังกฤษ) <span class="text-danger">*</span></label>
                            <input type="text" name="first_name_en" class="form-control" required maxlength="120" value="<?= htmlspecialchars((string) ($contractor['first_name_en'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">นามสกุล (ภาษาอังกฤษ) <span class="text-danger">*</span></label>
                            <input type="text" name="last_name_en" class="form-control" required maxlength="120" value="<?= htmlspecialchars((string) ($contractor['last_name_en'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">เลขบัตรประชาชน <span class="text-danger">*</span></label>
                            <input type="text" name="national_id" class="form-control" required maxlength="17" inputmode="numeric" pattern="[0-9\- ]{13,17}" value="<?= htmlspecialchars((string) ($contractor['national_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="13 หลัก">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">วันเกิด <span class="text-danger">*</span></label>
                            <input type="date" name="birth_date" class="form-control" required value="<?= htmlspecialchars((string) ($contractor['birth_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">ที่อยู่ <span class="text-danger">*</span></label>
                            <textarea name="address" class="form-control" rows="3" required maxlength="1000" placeholder="ที่อยู่ตามบัตรประชาชน"><?= htmlspecialchars((string) ($contractor['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                    </div>

                    <div class="section-head">ช่องทางการชำระเงิน</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">ช่องทางการชำระ <span class="text-danger">*</span></label>
                            <select name="payment_method" class="form-select" required>
                                <?php foreach ($paymentMethods as $key => $label): ?>
                                    <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"<?= ((string) ($contractor['payment_method'] ?? 'bank_transfer') === $key) ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">เลขบัญชี <span class="text-danger">*</span></label>
                            <input type="text" name="bank_account_no" class="form-control" required maxlength="30" value="<?= htmlspecialchars((string) ($contractor['bank_account_no'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">ธนาคาร <span class="text-danger">*</span></label>
                            <select name="bank_name" class="form-select" required>
                                <option value="">— เลือกธนาคาร —</option>
                                <?php foreach ($banks as $bank): ?>
                                    <option value="<?= htmlspecialchars($bank, ENT_QUOTES, 'UTF-8') ?>"<?= ((string) ($contractor['bank_name'] ?? '') === $bank) ? ' selected' : '' ?>><?= htmlspecialchars($bank, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">ชื่อบัญชีธนาคาร <span class="text-danger">*</span></label>
                            <input type="text" name="bank_account_name" class="form-control" required maxlength="200" value="<?= htmlspecialchars((string) ($contractor['bank_account_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>

                    <div class="section-head">รูปบัตรประชาชน</div>
                    <div class="mb-4">
                        <?php if ($hasPhoto && $photoUrl !== ''): ?>
                            <div class="mb-3">
                                <img src="<?= htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="รูปบัตรประชาชน" class="photo-preview img-fluid">
                                <div class="small text-muted mt-1"><?= htmlspecialchars((string) ($contractor['id_card_photo_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        <?php endif; ?>
                        <label class="form-label fw-semibold">อัปโหลดรูปบัตรประชาชน <?= $hasPhoto ? '' : '<span class="text-danger">*</span>' ?></label>
                        <input type="file" name="id_card_photo" class="form-control" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"<?= $hasPhoto ? '' : ' required' ?>>
                        <div class="form-text"><?= $hasPhoto ? 'อัปโหลดใหม่เพื่อเปลี่ยนรูป (ถ้าไม่เลือกจะใช้รูปเดิม)' : 'รองรับ JPG, PNG, WEBP' ?></div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= htmlspecialchars(app_path('pages/contractors/contractor-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light border px-4">ยกเลิก</a>
                        <button type="submit" class="btn btn-primary px-4 fw-semibold"><i class="bi bi-check-lg me-1"></i>บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
