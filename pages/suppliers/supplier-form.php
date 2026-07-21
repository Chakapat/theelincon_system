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
$isEditMode = false;

$supplier = [
    'name' => '',
    'tax_id' => '',
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
        $isEditMode = true;
    } else {
        $id = 0;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <?php
    require_once dirname(__DIR__, 2) . '/includes/tnc_ops_head.php';
    tnc_ops_head([
        'title' => $title,
        'extra_css' => ['assets/css/supplier-form.css'],
        'sarabun_weights' => '400;600;700;800',
    ]);
    ?>
</head>
<body class="tnc-app-body tnc-layout-form">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<main class="container supplier-form-page py-5">
    <div class="supplier-form-head">
        <div class="supplier-form-head__main">
            <div>
                <?php
                require_once dirname(__DIR__, 2) . '/includes/tnc_ui.php';
                echo tnc_ui_back_previous_button([
                    'fallback' => app_path('pages/suppliers/supplier-list.php'),
                ]);
                ?>
            </div>
            <span class="supplier-form-head__icon" aria-hidden="true">
                <i class="bi bi-truck"></i>
            </span>
            <div>
                <h1 class="supplier-form-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="supplier-form-subtitle">
                    กรอกข้อมูลที่ใช้ในใบขอซื้อ ใบสั่งซื้อ และการโอนชำระเงิน
                </p>
            </div>
        </div>
        <span class="supplier-form-mode">
            <i class="bi <?= $isEditMode ? 'bi-pencil-square' : 'bi-plus-circle' ?>" aria-hidden="true"></i>
            <?= $isEditMode ? 'กำลังแก้ไขข้อมูล' : 'ผู้ขายรายใหม่' ?>
        </span>
    </div>

    <form class="supplier-form-surface" action="<?= htmlspecialchars(app_path('actions/action-handler.php'), ENT_QUOTES, 'UTF-8') ?>?action=save_supplier" method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">

        <section class="supplier-form-section" aria-labelledby="supplier-general-heading">
            <div class="supplier-form-section__head">
                <span class="supplier-form-section__icon" aria-hidden="true"><i class="bi bi-building"></i></span>
                <div>
                    <h2 class="supplier-form-section__title" id="supplier-general-heading">ข้อมูลผู้ขาย</h2>
                    <p class="supplier-form-section__hint">ข้อมูลสำหรับระบุผู้ขายในเอกสารจัดซื้อ</p>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-12 col-md-8 supplier-form-field">
                    <label class="form-label" for="supplier-name">
                        ชื่อบริษัท / ชื่อผู้ขาย <span class="supplier-form-required" aria-label="จำเป็น">*</span>
                    </label>
                    <div class="supplier-form-control">
                        <i class="bi bi-building" aria-hidden="true"></i>
                        <input type="text"
                               id="supplier-name"
                               name="name"
                               class="form-control"
                               value="<?= htmlspecialchars((string) $supplier['name'], ENT_QUOTES, 'UTF-8') ?>"
                               required
                               autocomplete="organization"
                               autofocus
                               placeholder="เช่น บริษัท ตัวอย่าง จำกัด">
                    </div>
                    <div class="supplier-form-help">ชื่อนี้จะแสดงในรายการผู้ขายและเอกสาร PO</div>
                </div>

                <div class="col-12 col-md-4 supplier-form-field">
                    <label class="form-label" for="supplier-tax-id">เลขประจำตัวผู้เสียภาษี</label>
                    <div class="supplier-form-control">
                        <i class="bi bi-card-checklist" aria-hidden="true"></i>
                        <input type="text"
                               id="supplier-tax-id"
                               name="tax_id"
                               class="form-control"
                               value="<?= htmlspecialchars((string) $supplier['tax_id'], ENT_QUOTES, 'UTF-8') ?>"
                               maxlength="13"
                               inputmode="numeric"
                               autocomplete="off"
                               placeholder="เลข 13 หลัก">
                    </div>
                </div>

                <div class="col-12 supplier-form-field">
                    <label class="form-label" for="supplier-address">ที่อยู่บริษัท</label>
                    <div class="supplier-form-control supplier-form-control--textarea">
                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                        <textarea id="supplier-address"
                                  name="address"
                                  class="form-control"
                                  rows="3"
                                  autocomplete="street-address"
                                  placeholder="ที่อยู่ตามทะเบียนภาษีหรือที่อยู่สำหรับจัดส่งเอกสาร"><?= htmlspecialchars((string) $supplier['address'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                </div>
            </div>
        </section>

        <section class="supplier-form-section" aria-labelledby="supplier-payment-heading">
            <div class="supplier-form-section__head">
                <span class="supplier-form-section__icon" aria-hidden="true"><i class="bi bi-bank2"></i></span>
                <div>
                    <h2 class="supplier-form-section__title" id="supplier-payment-heading">บัญชีรับโอน</h2>
                    <p class="supplier-form-section__hint">ไม่บังคับกรอก ใช้เป็นข้อมูลประกอบการชำระเงิน</p>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-12 col-md-4 supplier-form-field">
                    <label class="form-label" for="supplier-bank">ธนาคาร</label>
                    <div class="supplier-form-control">
                        <i class="bi bi-bank" aria-hidden="true"></i>
                        <select id="supplier-bank" name="bank_name" class="form-select js-bank-select" data-form-scope="supplier">
                            <option value="">ไม่ระบุธนาคาร</option>
                            <?php foreach ($banks as $bank): ?>
                            <option value="<?= htmlspecialchars($bank, ENT_QUOTES, 'UTF-8') ?>"<?= ((string) ($supplier['bank_name'] ?? '') === $bank) ? ' selected' : '' ?>><?= htmlspecialchars($bank, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bank-select-preview js-bank-logo-preview" data-form-scope="supplier" aria-live="polite"></div>
                </div>

                <div class="col-12 col-md-4 supplier-form-field">
                    <label class="form-label" for="supplier-account-name">ชื่อบัญชี</label>
                    <div class="supplier-form-control">
                        <i class="bi bi-person-vcard" aria-hidden="true"></i>
                        <input type="text"
                               id="supplier-account-name"
                               name="bank_account_name"
                               class="form-control"
                               maxlength="200"
                               value="<?= htmlspecialchars((string) ($supplier['bank_account_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="off"
                               placeholder="ชื่อบัญชีตามธนาคาร">
                    </div>
                </div>

                <div class="col-12 col-md-4 supplier-form-field">
                    <label class="form-label" for="supplier-account-number">เลขที่บัญชี</label>
                    <div class="supplier-form-control">
                        <i class="bi bi-credit-card-2-front" aria-hidden="true"></i>
                        <input type="text"
                               id="supplier-account-number"
                               name="bank_account_number"
                               class="form-control font-monospace"
                               maxlength="20"
                               inputmode="numeric"
                               value="<?= htmlspecialchars((string) ($supplier['bank_account_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="off"
                               placeholder="เลขที่บัญชีรับโอน">
                    </div>
                </div>
            </div>
        </section>

        <div class="supplier-form-actions">
            <a href="<?= htmlspecialchars(app_path('pages/suppliers/supplier-list.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn supplier-form-cancel">
                ยกเลิก
            </a>
            <button type="submit" class="btn supplier-form-save">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                <?= $isEditMode ? 'บันทึกการแก้ไข' : 'เพิ่มผู้ขาย' ?>
            </button>
        </div>
    </form>
</main>

<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
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

const supplierForm = document.querySelector('.supplier-form-surface');
if (supplierForm) {
    supplierForm.addEventListener('submit', function () {
        const submitButton = supplierForm.querySelector('.supplier-form-save');
        if (!submitButton) return;
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>กำลังบันทึก';
    });
}
</script>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>