<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_flash.php';
require_once dirname(__DIR__, 2) . '/includes/banks.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_shell_head.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$is_admin = user_is_admin_role();

$suppliers = Db::tableRows('suppliers');
Db::sortRows($suppliers, 'name', false);
$supplierCount = count($suppliers);

/**
 * Format Thai tax ID as X-XXXX-XXXXX-XX-X when 13 digits.
 */
function tnc_supplier_format_tax_id(string $taxId): string
{
    $digits = preg_replace('/\D+/', '', $taxId) ?? '';
    if (strlen($digits) !== 13) {
        return $taxId;
    }

    return substr($digits, 0, 1)
        . '-' . substr($digits, 1, 4)
        . '-' . substr($digits, 5, 5)
        . '-' . substr($digits, 10, 2)
        . '-' . substr($digits, 12, 1);
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php tnc_shell_head([
        'title' => 'จัดการผู้ขาย (Suppliers)',
        'extra_css' => ['assets/css/supplier-list.css'],
        'sarabun_weights' => '400;600;700;800',
        'sweetalert' => true,
    ]); ?>
</head>
<body class="tnc-app-body tnc-layout-list">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container mt-5">
    <?php
    $supplierFlash = tnc_flash_from_query($_GET);
    if ($supplierFlash !== null && isset($_GET['success'])) {
        $supplierFlash['message'] = 'บันทึกข้อมูลเรียบร้อยแล้ว';
    }
    if (isset($_GET['error']) && $_GET['error'] === 'in_use') {
        $supplierFlash = ['type' => 'danger', 'message' => 'ไม่สามารถลบได้: ผู้ขายรายนี้ถูกใช้ในใบสั่งซื้อ (PO) แล้ว'];
    }
    tnc_render_flash($supplierFlash);
    ?>

    <div class="tnc-page-head">
        <div>
            <p class="tnc-page-kicker">Organization</p>
            <h1 class="tnc-list-title">
                <span class="tnc-list-title__icon me-2"><i class="bi bi-truck" aria-hidden="true"></i></span>
                ระบบจัดการผู้ขาย
                <?php if ($supplierCount > 0): ?>
                    <span class="supplier-list-meta"><?= (int) $supplierCount ?> ราย</span>
                <?php endif; ?>
            </h1>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php
            require_once dirname(__DIR__, 2) . '/includes/tnc_ui.php';
            echo tnc_ui_back_previous_button();
            ?>
            <a href="<?= htmlspecialchars(app_path('pages/suppliers/supplier-form.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange px-4 shadow-sm">
                <i class="bi bi-plus-lg me-2" aria-hidden="true"></i>เพิ่มผู้ขายใหม่
            </a>
        </div>
    </div>

    <div class="card main-card tnc-list-card supplier-list-shell">
        <div class="table-responsive tnc-mobile-table-wrap">
            <table class="table table-hover align-middle tnc-mobile-table mb-0" id="supplierTable" style="width:100%">
                <thead>
                    <tr>
                        <th scope="col">ผู้ขาย</th>
                        <th scope="col">เลขผู้เสียภาษี</th>
                        <th scope="col">ที่อยู่บริษัท</th>
                        <th scope="col">บัญชีรับโอน</th>
                        <th scope="col" class="col-actions">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($supplierCount === 0): ?>
                        <tr>
                            <td colspan="5" class="sup-empty-state">
                                <div class="sup-empty-state__icon" aria-hidden="true"><i class="bi bi-truck"></i></div>
                                <p class="sup-empty-state__title">ยังไม่มีผู้ขายในระบบ</p>
                                <p class="sup-empty-state__hint">เพิ่มผู้ขายเพื่อใช้เลือกในใบขอซื้อและใบสั่งซื้อ</p>
                                <a href="<?= htmlspecialchars(app_path('pages/suppliers/supplier-form.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-orange rounded-pill px-4">
                                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>เพิ่มผู้ขายใหม่
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                    <?php foreach ($suppliers as $row): ?>
                    <?php
                        $rowName = trim((string) ($row['name'] ?? ''));
                        $rowTaxId = trim((string) ($row['tax_id'] ?? ''));
                        $rowAddress = trim((string) ($row['address'] ?? ''));
                        $rowBank = trim((string) ($row['bank_name'] ?? ''));
                        $rowAccNo = trim((string) ($row['bank_account_number'] ?? ''));
                        $rowAccName = trim((string) ($row['bank_account_name'] ?? ''));
                        $rowBankLogo = $rowBank !== '' ? tnc_bank_logo_url($rowBank) : '';
                        $hasBank = $rowBank !== '' || $rowAccNo !== '' || $rowAccName !== '';
                        $taxDisplay = $rowTaxId !== '' ? tnc_supplier_format_tax_id($rowTaxId) : '';
                    ?>
                    <tr>
                        <td class="tnc-mobile-primary fw-bold" data-label="ผู้ขาย">
                            <div class="sup-name"><?= htmlspecialchars($rowName !== '' ? $rowName : '—', ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <td data-label="เลขประจำตัวผู้เสียภาษี">
                            <?php if ($taxDisplay !== ''): ?>
                                <span class="sup-tax" title="<?= htmlspecialchars($rowTaxId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($taxDisplay, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span class="sup-tax is-empty">ยังไม่ระบุ</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="ที่อยู่บริษัท">
                            <?php if ($rowAddress !== ''): ?>
                                <div class="sup-address" title="<?= htmlspecialchars($rowAddress, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($rowAddress, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php else: ?>
                                <span class="sup-empty">ยังไม่ระบุ</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="บัญชีรับโอน">
                            <?php if (!$hasBank): ?>
                                <span class="sup-empty">ยังไม่ระบุ</span>
                            <?php else: ?>
                                <div class="sup-bank">
                                    <?php if ($rowBank !== '' || $rowBankLogo !== ''): ?>
                                    <div class="sup-bank__head">
                                        <?php if ($rowBankLogo !== ''): ?>
                                            <img src="<?= htmlspecialchars($rowBankLogo, ENT_QUOTES, 'UTF-8') ?>" alt="" class="bank-logo-chip">
                                        <?php endif; ?>
                                        <span><?= htmlspecialchars($rowBank !== '' ? $rowBank : 'ธนาคาร', ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($rowAccName !== ''): ?>
                                        <div class="sup-bank__name"><?= htmlspecialchars($rowAccName, ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if ($rowAccNo !== ''): ?>
                                        <div class="sup-bank__acc"><?= htmlspecialchars($rowAccNo, ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end tnc-mobile-actions" data-label="จัดการ">
                            <div class="sup-actions">
                                <a href="<?= htmlspecialchars(app_path('pages/suppliers/supplier-form.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= (int) $row['id'] ?>"
                                   class="btn btn-sm btn-outline-warning tnc-icon-action"
                                   aria-label="แก้ไขผู้ขาย <?= htmlspecialchars($rowName, ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                </a>
                                <?php if ($is_admin): ?>
                                <button type="button"
                                        onclick="deleteSup(<?= (int) $row['id'] ?>)"
                                        class="btn btn-sm btn-outline-danger tnc-icon-action"
                                        aria-label="ลบผู้ขาย <?= htmlspecialchars($rowName, ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<script>
const actionHandlerUrl = <?= json_encode(app_path('actions/action-handler.php'), JSON_UNESCAPED_SLASHES) ?>;
const csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
function deleteSup(id) {
    Swal.fire({
        icon: 'warning',
        title: 'ยืนยันการลบ',
        html: 'ลบผู้ขายรายนี้ถาวร กรุณาใส่<strong>รหัสผ่านของคุณ</strong>',
        input: 'password',
        inputPlaceholder: 'รหัสผ่าน',
        showCancelButton: true,
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#ea580c',
        focusCancel: true,
        preConfirm: function (pw) {
            if (!pw || !String(pw).trim()) {
                Swal.showValidationMessage('กรุณากรอกรหัสผ่าน');
                return false;
            }
            return pw;
        }
    }).then(function (result) {
        if (!result.isConfirmed || !result.value) return;
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = actionHandlerUrl;
        form.style.display = 'none';
        [['action', 'delete_supplier'], ['id', String(id)], ['_csrf', csrfToken], ['confirm_password', result.value]].forEach(function (pair) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = pair[0];
            inp.value = pair[1];
            form.appendChild(inp);
        });
        document.body.appendChild(form);
        form.submit();
    });
}
</script>
<script>
(function ($) {
    if ($('#supplierTable tbody tr td[colspan]').length === 0 && $('#supplierTable tbody tr').length) {
        $('#supplierTable').DataTable({
            order: [[0, 'asc']],
            pageLength: 25,
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' },
            columnDefs: [{ targets: [-1], orderable: false, searchable: false }]
        });
    }
    var u = <?= json_encode(app_path('actions/live-datasets.php?dataset=mirror_table&table=suppliers'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
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
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
