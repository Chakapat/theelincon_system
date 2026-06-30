<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/banks.php';
require_once dirname(__DIR__, 2) . '/includes/party_logo.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

if (!user_can('page.org.company')) {
    header('Location: ' . app_path('index.php') . '?error=forbidden');
    exit();
}

$is_admin = user_is_admin_role();

$companies = Db::tableRows('company');
Db::sortRows($companies, 'id', true);
$banks = tnc_bank_options();

/**
 * @param array<string, mixed> $row
 */
function company_type_key(array $row): string
{
    $t = trim((string) ($row['company_type'] ?? ''));
    return $t === 'individual' ? 'individual' : 'company';
}

function company_type_label_th(string $type): string
{
    return $type === 'individual' ? 'บุคคลธรรมดา' : 'นิติบุคคล';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการบริษัท | Invoice System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background-color: #fffaf5; }
        .logo-preview { height: 88px; width: 88px; object-fit: contain; border-radius: 10px; background: var(--tnc-surface, #f6f7f9); }
        .bank-logo-chip { width: 22px; height: 22px; object-fit: contain; border-radius: 4px; flex-shrink: 0; }
        .bank-select-preview { display: inline-flex; align-items: center; gap: 6px; min-height: 22px; font-size: 12px; color: #6b7280; }
    </style>
</head>
<body class="tnc-app-body tnc-layout-list">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4">
    <div class="tnc-page-head">
        <div>
            <p class="tnc-page-kicker">Organization</p>
            <h1 class="tnc-list-title"><span class="tnc-list-title__icon me-2"><i class="bi bi-building-add"></i></span>ข้อมูลบริษัท</h1>
        </div>
    </div>

    <div class="row g-4 tnc-mobile-master">
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-4 text-warning">
                        <i class="bi bi-plus-circle-fill fs-4 me-2"></i>
                        <h5 class="fw-bold mb-0 text-dark">เพิ่มบริษัทใหม่</h5>
                    </div>
                    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=add_company" method="POST" enctype="multipart/form-data" data-tnc-soft-reload="1">
                        <?php csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted js-company-label-logo" data-form-scope="add">โลโก้บริษัท</label>
                            <input type="file" name="logo" class="form-control border-0 bg-light py-2 rounded-3" accept="image/*">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">ประเภท</label>
                            <select name="company_type" id="add_company_type" class="form-select border-0 bg-light rounded-3 js-company-type-select" data-form-scope="add">
                                <option value="company">นิติบุคคล (บริษัท / ห้างหุ้นส่วน)</option>
                                <option value="individual">บุคคลธรรมดา</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted js-company-label-name" data-form-scope="add">ชื่อนิติบุคคล / บริษัท</label>
                            <input type="text" name="name" class="form-control border-0 bg-light py-2 rounded-3 js-company-input-name" data-form-scope="add" placeholder="ชื่อบริษัทเต็ม" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted js-company-label-tax" data-form-scope="add">เลขประจำตัวผู้เสียภาษี</label>
                            <input type="text" name="tax_id" class="form-control border-0 bg-light py-2 rounded-3 js-company-input-tax" data-form-scope="add" placeholder="เลขประจำตัว 13 หลัก" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted js-company-label-address" data-form-scope="add">ที่อยู่</label>
                            <textarea name="address" class="form-control border-0 bg-light rounded-3 js-company-input-address" data-form-scope="add" rows="3" placeholder="ที่อยู่จดทะเบียน" required></textarea>
                        </div>
                        <div class="border-top pt-3 mb-4">
                            <div class="small fw-bold text-muted mb-2"><i class="bi bi-bank2 me-1"></i>บัญชีรับชำระ (PAYMENT INFO)</div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">ธนาคาร</label>
                                <select name="bank_name" class="form-select border-0 bg-light rounded-3 js-bank-select" data-form-scope="add">
                                    <option value="">— ไม่ระบุ —</option>
                                    <?php foreach ($banks as $bank): ?>
                                    <option value="<?= htmlspecialchars($bank, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($bank, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="bank-select-preview mt-1 js-bank-logo-preview" data-form-scope="add"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">ชื่อบัญชี</label>
                                <input type="text" name="bank_account_name" class="form-control border-0 bg-light py-2 rounded-3" maxlength="200" placeholder="ชื่อบัญชีตามสมุดธนาคาร">
                            </div>
                            <div class="mb-0">
                                <label class="form-label small fw-bold text-muted">เลขที่บัญชี</label>
                                <input type="text" name="bank_account_number" class="form-control border-0 bg-light py-2 rounded-3 font-monospace" maxlength="20" inputmode="numeric" placeholder="ตัวเลขเท่านั้น">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-orange w-100 py-2 fw-bold shadow-sm"><i class="bi bi-save2 me-2"></i>บันทึกข้อมูล</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="p-4 border-bottom bg-white"><h5 class="fw-bold mb-0">รายชื่อบริษัททั้งหมด</h5></div>
                <div class="table-responsive tnc-mobile-table-wrap">
                    <table class="table table-hover align-middle mb-0 tnc-mobile-table" id="companyTable" width="100%">
                        <thead class="bg-light text-muted small">
                            <tr>
                                <th class="ps-4 border-0">ชื่อ / ประเภท</th>
                                <th class="border-0">เลขผู้เสียภาษี</th>
                                <th class="border-0">บัญชีรับชำระ</th>
                                <?php if($is_admin): ?>
                                <th class="border-0 pe-4 text-end">จัดการ</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($companies as $row):
                                $ctype = company_type_key($row);
                            ?>
                            <tr>
                                <td class="ps-4 tnc-mobile-primary" data-label="ชื่อ / ประเภท">
                                    <div class="d-flex align-items-center">
                                        <?php $rowLogoUrl = tnc_party_logo_public_url($row['logo'] ?? ''); ?>
                                        <?php if ($rowLogoUrl !== ''): ?>
                                            <img src="<?= htmlspecialchars($rowLogoUrl, ENT_QUOTES, 'UTF-8') ?>" class="logo-preview me-3 border">
                                        <?php else: ?>
                                            <div class="logo-preview me-3 d-flex align-items-center justify-content-center border text-muted"><i class="bi bi-<?= $ctype === 'individual' ? 'person' : 'building' ?>"></i></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars($row['name']) ?></div>
                                            <div class="text-muted small"><?= htmlspecialchars(company_type_label_th($ctype)) ?></div>
                                            <small class="text-muted d-inline-block text-truncate" style="max-width: 180px;"><?= htmlspecialchars($row['address']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="เลขผู้เสียภาษี"><span class="badge bg-warning-subtle text-warning border px-3 py-2 rounded-pill"><?= htmlspecialchars($row['tax_id']) ?></span></td>
                                <td class="small" data-label="บัญชีรับชำระ">
                                    <?php
                                    $rowBank = trim((string) ($row['bank_name'] ?? ''));
                                    $rowAccNo = trim((string) ($row['bank_account_number'] ?? ''));
                                    if ($rowBank !== '' || $rowAccNo !== ''):
                                        $rowBankLogo = $rowBank !== '' ? tnc_bank_logo_url($rowBank) : '';
                                    ?>
                                    <div class="text-muted d-flex align-items-center gap-1">
                                        <?php if ($rowBankLogo !== ''): ?><img src="<?= htmlspecialchars($rowBankLogo, ENT_QUOTES, 'UTF-8') ?>" alt="" class="bank-logo-chip"><?php endif; ?>
                                        <span><?= htmlspecialchars($rowBank !== '' ? $rowBank : '—') ?><?= $rowAccNo !== '' ? ' · ' . htmlspecialchars($rowAccNo) : '' ?></span>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4 tnc-mobile-actions" data-label="จัดการ">
                                    <?php if($is_admin): ?>
                                    <button onclick="editCompany(<?= $row['id'] ?>)" class="btn btn-sm btn-outline-warning rounded-circle me-1"><i class="bi bi-pencil-square"></i></button>
                                    <button onclick="confirmDelete(<?= $row['id'] ?>, 'company')" class="btn btn-sm btn-outline-danger rounded-circle"><i class="bi bi-trash3"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($companies) === 0): ?>
                    <div class="text-center py-5 text-muted opacity-50"><i class="bi bi-inbox fs-1"></i><p>ยังไม่มีข้อมูลบริษัท</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editCompanyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-md-down">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=edit_company" method="POST" enctype="multipart/form-data" data-tnc-soft-reload="1">
                <?php csrf_field(); ?>
                <div class="modal-header border-0 pt-4 px-4">
                    <h5 class="fw-bold"><i class="bi bi-pencil-square me-2 text-warning"></i>แก้ไขข้อมูล</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 pt-0">
                    <input type="hidden" name="id" id="edit_id">
                    <input type="hidden" name="remove_logo" id="edit_remove_logo" value="0">
                    <div class="text-center mb-3">
                        <div id="edit_logo_preview" class="mb-2"></div>
                        <div class="d-flex flex-wrap justify-content-center gap-2 mb-2" id="edit_logo_actions" hidden>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="edit_logo_remove_btn">
                                <i class="bi bi-trash3 me-1"></i>ลบโลโก้
                            </button>
                        </div>
                        <input type="file" name="logo" id="edit_logo_file" class="form-control bg-light border-0 py-2 rounded-3" accept="image/*">
                        <div class="form-text">อัปโหลดไฟล์ใหม่จะแทนที่โลโก้เดิม</div>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold">ประเภท</label>
                        <select name="company_type" id="edit_company_type" class="form-select bg-light border-0 py-2 rounded-3 js-company-type-select" data-form-scope="edit">
                            <option value="company">นิติบุคคล (บริษัท / ห้างหุ้นส่วน)</option>
                            <option value="individual">บุคคลธรรมดา</option>
                        </select>
                    </div>
                    <div class="mb-3"><label class="small fw-bold js-company-label-name" data-form-scope="edit">ชื่อนิติบุคคล / บริษัท</label><input type="text" name="name" id="edit_name" class="form-control bg-light border-0 py-2 rounded-3 js-company-input-name" data-form-scope="edit" required></div>
                    <div class="mb-3"><label class="small fw-bold js-company-label-tax" data-form-scope="edit">เลขประจำตัวผู้เสียภาษี</label><input type="text" name="tax_id" id="edit_tax_id" class="form-control bg-light border-0 py-2 rounded-3 js-company-input-tax" data-form-scope="edit" required></div>
                    <div class="mb-3"><label class="small fw-bold js-company-label-address" data-form-scope="edit">ที่อยู่</label><textarea name="address" id="edit_address" class="form-control bg-light border-0 rounded-3 js-company-input-address" data-form-scope="edit" rows="2" required></textarea></div>
                    <div class="border-top pt-3">
                        <div class="small fw-bold text-muted mb-2"><i class="bi bi-bank2 me-1"></i>บัญชีรับชำระ (PAYMENT INFO)</div>
                        <div class="mb-3">
                            <label class="small fw-bold">ธนาคาร</label>
                            <select name="bank_name" id="edit_bank_name" class="form-select bg-light border-0 py-2 rounded-3 js-bank-select" data-form-scope="edit">
                                <option value="">— ไม่ระบุ —</option>
                                <?php foreach ($banks as $bank): ?>
                                <option value="<?= htmlspecialchars($bank, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($bank, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="bank-select-preview mt-1 js-bank-logo-preview" data-form-scope="edit"></div>
                        </div>
                        <div class="mb-3">
                            <label class="small fw-bold">ชื่อบัญชี</label>
                            <input type="text" name="bank_account_name" id="edit_bank_account_name" class="form-control bg-light border-0 py-2 rounded-3" maxlength="200">
                        </div>
                        <div class="mb-0">
                            <label class="small fw-bold">เลขที่บัญชี</label>
                            <input type="text" name="bank_account_number" id="edit_bank_account_number" class="form-control bg-light border-0 py-2 rounded-3 font-monospace" maxlength="20" inputmode="numeric">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0"><button type="submit" class="btn btn-orange w-100 py-2 fw-bold">บันทึกการแก้ไข</button></div>
            </form>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const actionHandlerUrl = <?= json_encode(app_path('actions/action-handler.php'), JSON_UNESCAPED_SLASHES) ?>;
const csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const uploadsLogosBase = <?= json_encode(upload_logos_base_url(), JSON_UNESCAPED_SLASHES) ?>;
const BANK_LOGOS = <?= json_encode(tnc_bank_logo_url_map(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const COMPANY_FORM_LABELS = {
    company: {
        name: 'ชื่อนิติบุคคล / บริษัท',
        namePh: 'ชื่อบริษัทเต็ม',
        tax: 'เลขประจำตัวผู้เสียภาษี',
        taxPh: 'เลขประจำตัว 13 หลัก',
        address: 'ที่อยู่จดทะเบียน',
        addressPh: 'ที่อยู่จดทะเบียน',
        logo: 'โลโก้บริษัท'
    },
    individual: {
        name: 'ชื่อ-นามสกุล',
        namePh: 'เช่น นายธีรยุทธ์ หนุนุน',
        tax: 'เลขประจำตัวผู้เสียภาษี / เลขบัตรประชาชน',
        taxPh: 'เลขประจำตัว 13 หลัก',
        address: 'ที่อยู่ตามบัตรประชาชน',
        addressPh: 'ที่อยู่ตามบัตรประชาชน',
        logo: 'รูปโปรไฟล์ (ถ้ามี)'
    }
};

function applyCompanyTypeUi(scope, type) {
    const key = type === 'individual' ? 'individual' : 'company';
    const labels = COMPANY_FORM_LABELS[key];
    document.querySelectorAll('.js-company-label-name[data-form-scope="' + scope + '"]').forEach(function (el) { el.textContent = labels.name; });
    document.querySelectorAll('.js-company-label-tax[data-form-scope="' + scope + '"]').forEach(function (el) { el.textContent = labels.tax; });
    document.querySelectorAll('.js-company-label-address[data-form-scope="' + scope + '"]').forEach(function (el) { el.textContent = labels.address; });
    document.querySelectorAll('.js-company-label-logo[data-form-scope="' + scope + '"]').forEach(function (el) { el.textContent = labels.logo; });
    document.querySelectorAll('.js-company-input-name[data-form-scope="' + scope + '"]').forEach(function (el) { el.placeholder = labels.namePh; });
    document.querySelectorAll('.js-company-input-tax[data-form-scope="' + scope + '"]').forEach(function (el) { el.placeholder = labels.taxPh; });
    document.querySelectorAll('.js-company-input-address[data-form-scope="' + scope + '"]').forEach(function (el) { el.placeholder = labels.addressPh; });
}

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
    const scope = sel.getAttribute('data-form-scope') || 'add';
    sel.addEventListener('change', function () { updateBankLogoPreview(scope, sel.value); });
    updateBankLogoPreview(scope, sel.value);
});

document.querySelectorAll('.js-company-type-select').forEach(function (sel) {
    const scope = sel.getAttribute('data-form-scope') || 'add';
    sel.addEventListener('change', function () { applyCompanyTypeUi(scope, sel.value); });
    applyCompanyTypeUi(scope, sel.value);
});

function confirmDelete(id, type) {
    Swal.fire({
        title: 'ยืนยันการลบ?',
        html: 'ข้อมูลจะถูกลบถาวร กรุณาใส่<strong>รหัสผ่านเข้าระบบของคุณ</strong>',
        icon: 'warning',
        input: 'password',
        inputPlaceholder: 'รหัสผ่าน',
        showCancelButton: true,
        confirmButtonColor: '#ea580c',
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true,
        focusCancel: true,
        preConfirm: function (pw) {
            if (!pw || !String(pw).trim()) {
                Swal.showValidationMessage('กรุณากรอกรหัสผ่าน');
                return false;
            }
            return pw;
        }
    }).then(function (r) {
        if (!r.isConfirmed || !r.value) return;
        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('type', type);
        fd.append('id', String(id));
        fd.append('_csrf', csrfToken);
        fd.append('_tnc_ajax', '1');
        fd.append('confirm_password', r.value);
        fetch(actionHandlerUrl, {
            method: 'POST',
            body: fd,
            headers: { 'X-Tnc-Ajax': '1', Accept: 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (j) {
                if (j.ok) {
                    Swal.fire({ icon: 'success', title: j.message || 'ลบแล้ว', toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
                    setTimeout(function () { window.location.reload(); }, 500);
                } else {
                    Swal.fire({ icon: 'error', title: j.message || 'ลบไม่สำเร็จ' });
                }
            })
            .catch(function () { Swal.fire({ icon: 'error', title: 'เครือข่ายผิดพลาด' }); });
    });
}

function renderCompanyLogoPreview(storedFilename, companyType, logoUrl) {
    const icon = companyType === 'individual' ? 'person' : 'building';
    const url = String(logoUrl || '').trim();
    if (url) {
        return '<img src="' + url.replace(/"/g, '&quot;') + '" class="logo-preview border p-1 shadow-sm mx-auto" style="width:100px;height:100px;object-fit:contain" alt="">';
    }
    const filename = String(storedFilename || '').trim();
    if (filename) {
        return '<img src="' + uploadsLogosBase + encodeURIComponent(filename) + '" class="logo-preview border p-1 shadow-sm mx-auto" style="width:100px;height:100px;object-fit:contain" alt="">';
    }
    return '<div class="logo-preview mx-auto d-flex align-items-center justify-content-center border" style="width:100px;height:100px"><i class="bi bi-' + icon + ' fs-2 text-muted"></i></div>';
}

function syncEditLogoRemoveUi(hasLogo) {
    const actions = document.getElementById('edit_logo_actions');
    const removeInput = document.getElementById('edit_remove_logo');
    if (actions) {
        actions.hidden = !hasLogo;
    }
    if (removeInput && hasLogo) {
        removeInput.value = '0';
    }
}

function editCompany(id) {
    fetch(`${actionHandlerUrl}?action=get_data&type=company&id=${id}&_csrf=${encodeURIComponent(csrfToken)}`)
    .then(function (res) {
        if (!res.ok) {
            throw new Error('forbidden');
        }
        return res.json();
    })
    .then(function (data) {
        if (!data || data.error) {
            Swal.fire({ icon: 'error', title: 'โหลดข้อมูลไม่สำเร็จ', confirmButtonColor: '#ea580c' });
            return;
        }
        ['id','name','tax_id','address','bank_account_name','bank_account_number'].forEach(function (k) {
            const el = document.getElementById('edit_' + k);
            if (el) el.value = data[k] || '';
        });
        const bankSel = document.getElementById('edit_bank_name');
        if (bankSel) {
            bankSel.value = data.bank_name || '';
            updateBankLogoPreview('edit', bankSel.value);
        }
        const typeSel = document.getElementById('edit_company_type');
        const companyType = (data.company_type === 'individual') ? 'individual' : 'company';
        if (typeSel) {
            typeSel.value = companyType;
            applyCompanyTypeUi('edit', typeSel.value);
        }
        const removeInput = document.getElementById('edit_remove_logo');
        const fileInput = document.getElementById('edit_logo_file');
        if (removeInput) removeInput.value = '0';
        if (fileInput) fileInput.value = '';
        const preview = document.getElementById('edit_logo_preview');
        const logoUrl = data.logo_url || '';
        const hasLogo = !!(logoUrl || (data.logo && String(data.logo).trim()));
        if (preview) {
            preview.innerHTML = renderCompanyLogoPreview(data.logo || '', companyType, logoUrl);
        }
        syncEditLogoRemoveUi(hasLogo);
        new bootstrap.Modal(document.getElementById('editCompanyModal')).show();
    })
    .catch(function () {
        Swal.fire({ icon: 'error', title: 'โหลดข้อมูลไม่สำเร็จ', confirmButtonColor: '#ea580c' });
    });
}

(function () {
    const removeBtn = document.getElementById('edit_logo_remove_btn');
    const removeInput = document.getElementById('edit_remove_logo');
    const fileInput = document.getElementById('edit_logo_file');
    const preview = document.getElementById('edit_logo_preview');
    const typeSel = document.getElementById('edit_company_type');
    const modal = document.getElementById('editCompanyModal');

    if (removeBtn && removeInput && preview) {
        removeBtn.addEventListener('click', function () {
            removeInput.value = '1';
            const companyType = typeSel ? typeSel.value : 'company';
            preview.innerHTML = renderCompanyLogoPreview('', companyType, '');
            syncEditLogoRemoveUi(false);
            if (fileInput) fileInput.value = '';
        });
    }
    if (fileInput && removeInput && preview) {
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files.length > 0) {
                removeInput.value = '0';
                const companyType = typeSel ? typeSel.value : 'company';
                const objectUrl = URL.createObjectURL(fileInput.files[0]);
                preview.innerHTML = renderCompanyLogoPreview('', companyType, objectUrl);
                syncEditLogoRemoveUi(true);
            }
        });
    }
    if (modal && removeInput) {
        modal.addEventListener('hidden.bs.modal', function () {
            removeInput.value = '0';
            if (fileInput) fileInput.value = '';
        });
    }
})();

const params = new URLSearchParams(window.location.search);
if (params.get('success')) Swal.fire({ icon: 'success', title: 'สำเร็จ!', confirmButtonColor: '#ea580c' });
if (params.has('deleted')) Swal.fire({ icon: 'success', title: 'ลบเรียบร้อย!', confirmButtonColor: '#ea580c' });
if (params.get('error') === 'logo_upload_failed') Swal.fire({ icon: 'error', title: 'อัปโหลดโลโก้ไม่สำเร็จ', confirmButtonColor: '#ea580c' });
if (params.get('error') === 'logo_upload_type') Swal.fire({ icon: 'error', title: 'ไฟล์โลโก้ไม่รองรับ', text: 'ใช้ JPG, PNG, WEBP หรือ GIF', confirmButtonColor: '#ea580c' });
if (params.get('error') === 'confirm_password_required') Swal.fire({ icon: 'warning', title: 'กรุณากรอกรหัสผ่านเพื่อยืนยันการลบ', confirmButtonColor: '#ea580c' });
if (params.get('error') === 'confirm_password_invalid') Swal.fire({ icon: 'error', title: 'รหัสผ่านไม่ถูกต้อง', confirmButtonColor: '#ea580c' });
(function () {
    if (typeof $ === 'undefined' || !$.fn.DataTable) return;
    $('#companyTable').DataTable({ order: [[0, 'asc']] });
})();
</script>
</body>
</html>