<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;
use Theelincon\Rtdb\Purchase;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$pr_id = isset($_GET['pr_id']) ? (int) $_GET['pr_id'] : 0;

$pr = Db::findFirst('purchase_requests', static function (array $r) use ($pr_id): bool {
    return isset($r['id']) && (int) $r['id'] === $pr_id;
});
if (!$pr) {
    echo "<script>alert('ไม่พบข้อมูลใบขอซื้อ'); window.location.href='" . htmlspecialchars(app_path('pages/purchase/purchase-request-list.php'), ENT_QUOTES) . "';</script>";
    exit();
}

$requestType = trim((string) ($pr['request_type'] ?? 'purchase'));
if (!in_array($requestType, ['purchase', 'hire'], true)) {
    $requestType = 'purchase';
}
$contractorName = trim((string) ($pr['contractor_name'] ?? ''));
$installmentTotal = (int) ($pr['installment_total'] ?? 1);
if ($installmentTotal < 1) {
    $installmentTotal = 1;
}

$dup = Db::findFirst('purchase_orders', static function (array $r) use ($pr_id): bool {
    return isset($r['pr_id']) && (int) $r['pr_id'] === $pr_id;
});
if ($requestType !== 'hire' && $dup !== null) {
    $msg = 'ใบขอซื้อนี้ออกใบสั่งซื้อ (PO) เลขที่ ' . ($dup['po_number'] ?? '') . ' แล้ว ไม่สามารถออกซ้ำได้';
    $view = htmlspecialchars(app_path('pages/purchase/purchase-order-view.php?id=' . (int) ($dup['id'] ?? 0)), ENT_QUOTES);
    echo "<script>alert(" . json_encode($msg, JSON_UNESCAPED_UNICODE) . "); window.location.href='" . $view . "';</script>";
    exit();
}

$issuedInstallments = [];
$paidAmountSoFar = 0.0;
$hireContract = null;
$hirePaymentRows = [];
if ($requestType === 'hire') {
    foreach (Db::tableRows('purchase_orders') as $row) {
        if ((int) ($row['pr_id'] ?? 0) !== $pr_id) {
            continue;
        }
        $paidAmountSoFar += (float) (($row['subtotal_amount'] ?? '') !== '' ? $row['subtotal_amount'] : ($row['payable_amount'] ?? 0));
        $no = (int) ($row['installment_no'] ?? 0);
        if ($no > 0) {
            $issuedInstallments[$no] = true;
        }
    }
    $hireContract = Db::findFirst('hire_contracts', static function (array $r) use ($pr_id): bool {
        return (int) ($r['pr_id'] ?? 0) === $pr_id;
    });
    $hirePaymentRows = Db::filter('hire_contract_payments', static function (array $r) use ($pr_id): bool {
        return (int) ($r['pr_id'] ?? 0) === $pr_id;
    });
    Db::sortRows($hirePaymentRows, 'installment_no', false);
}
$remainingInstallments = $requestType === 'hire' ? max(0, $installmentTotal - count($issuedInstallments)) : 0;

$supplier_rows = Db::tableRows('suppliers');
Db::sortRows($supplier_rows, 'name', false);

$po_number = Purchase::generatePONumber();
$errorCode = trim((string) ($_GET['error'] ?? ''));
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $requestType === 'hire' ? 'ใบสั่งจ่าย PO' : 'สร้างใบสั่งซื้อจาก PR' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(165deg, #f1f5f9 0%, #e8f4fc 45%, #f8fafc 100%); font-family: 'Sarabun', system-ui, sans-serif; min-height: 100vh; }
        .po-from-pr-shell { max-width: 720px; }
        .po-from-pr-card {
            border: none; border-radius: 1.25rem;
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.08);
            overflow: hidden; background: #fff;
        }
        .po-from-pr-head {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: #fff; padding: 1.35rem 1.5rem; margin: -1px -1px 0 -1px;
        }
        .po-from-pr-head h1 { font-size: 1.35rem; font-weight: 700; margin: 0; letter-spacing: -0.02em; }
        .po-from-pr-head .sub { opacity: 0.92; font-size: 0.875rem; font-weight: 500; margin-top: 0.35rem; }
        .po-field-label { font-size: 0.8rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.35rem; }
        .po-panel {
            border: 1px solid #e2e8f0; border-radius: 0.875rem; background: #f8fafc;
            padding: 1rem 1.15rem;
        }
        .po-panel-muted { background: #fff; border-color: #e9ecef; }
        .section-card { border: 1px solid #e9ecef; border-radius: 12px; background: #fff; }
        .section-title { font-size: 1rem; font-weight: 700; color: #0d6efd; margin-bottom: 12px; }
        .form-control:focus, .form-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.12); }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
    <div class="container py-4 py-md-5">
        <div class="row justify-content-center">
            <div class="<?= $requestType === 'hire' ? 'col-lg-10' : 'col-xl-8' ?>">
                <div class="po-from-pr-shell mx-auto">
                <div class="card po-from-pr-card border-0">
                    <div class="po-from-pr-head">
                        <h1 class="d-flex align-items-center gap-2">
                            <i class="bi bi-file-earmark-plus-fill opacity-90"></i>
                            <?= $requestType === 'hire' ? 'ใบสั่งจ่าย PO' : 'สร้างใบสั่งซื้อ' ?>
                        </h1>
                        <div class="sub"><?= $requestType === 'hire' ? 'ออกเอกสารสั่งจ่ายจากใบขอจัดจ้าง' : 'ออก PO จากใบขอซื้อ (PR) — กรอกเฉพาะข้อมูลที่มี' ?></div>
                    </div>
                    <div class="p-4 p-md-4">
                    <?php if ($errorCode === 'invalid_installment'): ?>
                        <div class="alert alert-warning py-2">งวดที่เลือกไม่ถูกต้อง</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'duplicate_installment'): ?>
                        <div class="alert alert-warning py-2">งวดนี้ถูกออกเอกสารแล้ว กรุณาเลือกงวดอื่น</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'invalid_installment_amount'): ?>
                        <div class="alert alert-warning py-2">มูลค่างวดต้องมากกว่า 0</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'invalid_installment_description'): ?>
                        <div class="alert alert-warning py-2">กรุณากรอกรายละเอียดการสั่งจ่ายงวดนี้</div>
                    <?php endif; ?>
                    <?php if ($errorCode === 'invalid_hire_rows'): ?>
                        <div class="alert alert-warning py-2">กรุณากรอกรายการสั่งจ่ายอย่างน้อย 1 รายการให้ถูกต้อง</div>
                    <?php endif; ?>
                    <?php if ($requestType === 'hire' && $remainingInstallments === 0): ?>
                        <div class="alert alert-info py-2">ออกใบสั่งจ่ายครบทุกงวดแล้ว</div>
                    <?php endif; ?>
                    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=create_po_from_pr" method="POST" data-tnc-fullnav="1">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="pr_id" value="<?= $pr['id'] ?>">
                        <?php if ($requestType === 'hire' && $hireContract !== null): ?>
                        <input type="hidden" name="hire_contract_id" value="<?= (int) ($hireContract['id'] ?? 0) ?>">
                        <?php endif; ?>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="po-field-label">อ้างอิงใบขอซื้อ (PR)</div>
                                <input type="text" class="form-control form-control-lg bg-light border-0" value="<?= htmlspecialchars((string) ($pr['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <div class="po-field-label">เลขที่ PO (อัตโนมัติ)</div>
                                <input type="text" name="po_number" class="form-control form-control-lg bg-light border-0" value="<?= htmlspecialchars((string) $po_number, ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </div>
                        </div>
                        <?php if ($requestType === 'hire'): ?>
                        <div class="po-panel po-panel-muted mb-4">
                            <div class="d-flex flex-wrap align-items-center gap-2 small">
                                <span class="badge rounded-pill bg-info-subtle text-info-emphasis border border-info-subtle">จัดจ้าง</span>
                                <span class="text-secondary"><strong class="text-dark">ผู้รับจ้าง:</strong> <?= htmlspecialchars($contractorName !== '' ? $contractorName : '-', ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="text-secondary"><strong class="text-dark">งวด:</strong> <?= number_format($installmentTotal) ?> งวด</span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($requestType === 'hire'): ?>
                        <div class="border rounded-3 p-3 mb-4 bg-white">
                            <h6 class="fw-bold mb-2 text-primary"><i class="bi bi-journal-text me-1"></i>รายละเอียด PR (งานจัดจ้าง)</h6>
                            <div class="small text-muted" style="white-space: pre-wrap;"><?= htmlspecialchars((string) ($pr['details'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>

                        <div class="border rounded-3 p-3 mb-4 bg-light">
                            <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-file-earmark-ruled me-1"></i>ตารางสัญญาจ้าง</h6>
                            <?php
                                $hcRow = is_array($hireContract) ? $hireContract : [];
                                $paidInstallmentsDisplay = (int) ($hcRow['paid_installments'] ?? count($issuedInstallments));
                                $paidAmountDisplay = (float) (($hcRow['paid_amount'] ?? '') !== '' ? $hcRow['paid_amount'] : $paidAmountSoFar);
                            ?>
                            <div class="row g-3 mb-2 small">
                                <div class="col-md-6"><strong>จ่ายแล้ว:</strong> <?= number_format($paidAmountDisplay, 2) ?> บาท</div>
                                <div class="col-md-6"><strong>งวดที่จ่ายแล้ว:</strong> <?= number_format($paidInstallmentsDisplay) ?>/<?= number_format($installmentTotal) ?></div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle mb-0" id="tncHirePaidInstallmentsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="18%">PO No.</th>
                                            <th width="18%">งวด</th>
                                            <th width="24%">มูลค่างวด</th>
                                            <th width="20%">วันที่บันทึก</th>
                                            <th width="20%">สถานะ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($hirePaymentRows) === 0): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted">ยังไม่มีการจ่ายงวด</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($hirePaymentRows as $payment): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars((string) ($payment['po_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td>งวด <?= (int) ($payment['installment_no'] ?? 0) ?>/<?= (int) ($payment['installment_total'] ?? $installmentTotal) ?></td>
                                                    <td><?= number_format((float) ($payment['amount'] ?? 0), 2) ?> บาท</td>
                                                    <td><?= htmlspecialchars((string) ($payment['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><span class="badge bg-success">จ่ายแล้ว</span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="mb-4<?= $requestType === 'hire' ? ' d-none' : '' ?>">
                            <div class="po-field-label">ผู้ขาย (Supplier) <span class="text-muted fw-normal text-lowercase" style="letter-spacing:0;">— ไม่บังคับ</span></div>
                            <input type="text" id="supplier_search" class="form-control form-control-lg" list="supplier_list" placeholder="พิมพ์ชื่อผู้ขายเพื่อค้นหา (เว้นว่างได้)" autocomplete="off">
                            <datalist id="supplier_list">
                                <?php foreach ($supplier_rows as $s): ?>
                                    <option
                                        value="<?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-id="<?= (int) ($s['id'] ?? 0) ?>"
                                    ></option>
                                <?php endforeach; ?>
                            </datalist>
                            <input type="hidden" name="supplier_id" id="supplier_id" value="">
                            <div class="form-text mt-1">เลือกจากรายการให้ตรงกันทั้งบรรทัด ระบบจะบันทึกรหัสผู้ขายให้อัตโนมัติ</div>
                        </div>

                        <div class="mb-4<?= $requestType === 'hire' ? ' d-none' : '' ?>">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" value="1" id="has_qt" name="has_qt">
                                <label class="form-check-label fw-semibold" for="has_qt">มีข้อมูลใบเสนอราคา (QT)</label>
                            </div>
                            <div class="rounded-3 border bg-white p-3 p-md-4 mt-2 d-none" id="qt_panel">
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold text-secondary mb-1" for="qt_quotation_number">เลขที่ QT</label>
                                    <input type="text" name="quotation_number" id="qt_quotation_number" class="form-control" maxlength="120" placeholder="เช่น QT-2026-015" disabled>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold text-secondary mb-1" for="qt_quotation_date">วันที่ใบเสนอราคา</label>
                                    <input type="date" name="quotation_date" id="qt_quotation_date" class="form-control" value="" disabled>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label small fw-semibold text-secondary mb-1" for="qt_quotation_note">หมายเหตุ QT</label>
                                    <textarea name="quotation_note" id="qt_quotation_note" class="form-control" rows="2" maxlength="500" placeholder="รายละเอียดอ้างอิง QT" disabled></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4<?= $requestType === 'hire' ? ' d-none' : '' ?>">
                            <label class="form-label fw-semibold" for="po_note">หมายเหตุ PO</label>
                            <textarea name="po_note" id="po_note" class="form-control" rows="2" maxlength="500" placeholder="เช่น เงื่อนไขการส่งมอบ ที่อยู่จัดส่ง หรือข้อควรทราบบนใบสั่งซื้อ (ไม่บังคับ)"></textarea>
                        </div>

                        <?php if ($requestType === 'hire'): ?>
                        <input type="hidden" name="installment_no" value="<?php for ($i = 1; $i <= $installmentTotal; $i++) { if (!isset($issuedInstallments[$i])) { echo $i; break; } } ?>">
                        <input type="hidden" name="installment_amount" id="installment_amount" value="0">
                        <input type="hidden" name="installment_description" id="installment_description" value="">

                        <div class="section-card p-3 mb-3">
                            <div class="section-title"><i class="bi bi-1-circle me-1"></i>ตารางรายละเอียดสั่งจ่าย</div>
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-2" id="hireInstallmentTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th width="42%">รายละเอียด</th>
                                                    <th width="12%" class="text-end">จำนวน</th>
                                                    <th width="18%" class="text-end">ราคา/หน่วย</th>
                                                    <th width="18%" class="text-end">ยอดรวม</th>
                                                    <th width="10%" class="text-center">ลบ</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><input type="text" name="hire_description[]" class="form-control hire-desc" required placeholder="เช่น ค่าแรง DC"></td>
                                                    <td><input type="number" name="hire_qty[]" class="form-control hire-qty text-end" min="0" step="0.01" value="1"></td>
                                                    <td><input type="number" name="hire_unit_price[]" class="form-control hire-price text-end" min="0" step="0.01" value="0"></td>
                                                    <td><input type="text" class="form-control hire-line-total text-end bg-light" readonly value="0.00"></td>
                                                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger hire-remove-row" disabled><i class="bi bi-trash"></i></button></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="addHireRowBtn"><i class="bi bi-plus-circle me-1"></i>เพิ่มบรรทัด</button>
                                </div>
                            </div>
                        </div>

                        <div class="section-card p-3 mb-4">
                            <div class="section-title"><i class="bi bi-2-circle me-1"></i>สรุปยอด</div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <h6 class="fw-bold mb-3">การตั้งค่าภาษีและเงินหัก</h6>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="vat_enabled" id="vat_enabled">
                                        <label class="form-check-label fw-bold text-primary" for="vat_enabled">บวกภาษีมูลค่าเพิ่ม VAT 7% (+)</label>
                                    </div>
                                    <label class="form-label text-danger fw-bold">หักประกันผลงาน Retention (บาท)</label>
                                    <input type="text" name="retention_value" id="retention_value" class="form-control" value="0" placeholder="0">
                                    <input type="hidden" name="withholding_type" id="withholding_type" value="none">
                                    <input type="hidden" name="retention_type" id="retention_type" value="fixed">
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex justify-content-between mb-2"><span>ยอดรวม (Subtotal):</span> <span id="subtotal_text" class="fw-bold">0.00</span></div>
                                    <div class="d-flex justify-content-between mb-2 text-primary"><span>VAT (+):</span> <span id="vat_text" class="fw-bold">0.00</span></div>
                                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2"><span class="text-muted fw-bold">ยอดรวม VAT:</span> <span id="total_after_vat_text" class="fw-bold">0.00</span></div>
                                    <div id="retention_summary_row" class="d-flex justify-content-between mb-2 text-danger" style="display:none;"><span>หักประกันผลงาน (-):</span> <span id="retention_display" class="fw-bold">0.00</span></div>
                                    <hr class="my-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="h6 fw-bold mb-0">ยอดสุทธิ:</span>
                                        <span class="fw-bold fs-4 text-primary" id="grand_total">0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="withholding_type" value="none">
                        <?php endif; ?>

                        <?php
                        $pr_vat_on = (int) ($pr['vat_enabled'] ?? 0);
                        $pr_vat = (float)($pr['vat_amount'] ?? 0);
                        $pr_grand = (float)$pr['total_amount'];
                        if (isset($pr['subtotal_amount']) && $pr['subtotal_amount'] !== null && $pr['subtotal_amount'] !== '') {
                            $pr_sub = (float)$pr['subtotal_amount'];
                        } else {
                            $pr_sub = round($pr_grand - $pr_vat, 2);
                        }
                        ?>
                        <?php if ($requestType !== 'hire'): ?>
                        <div class="po-panel mb-4">
                            <div class="small fw-semibold text-secondary text-uppercase mb-2" style="letter-spacing:0.06em;">สรุปยอดจาก PR</div>
                            <div class="d-flex justify-content-between align-items-center py-1"><span class="text-secondary">ยอดรายการ (ก่อน VAT)</span><strong><?= number_format($pr_sub, 2) ?> บาท</strong></div>
                            <?php if ($pr_vat_on): ?>
                            <div class="d-flex justify-content-between align-items-center py-1 text-success"><span>VAT 7%</span><strong><?= number_format($pr_vat, 2) ?> บาท</strong></div>
                            <?php else: ?>
                            <div class="text-muted small py-1">ไม่รวม VAT</div>
                            <?php endif; ?>
                            <hr class="my-2 border-secondary-subtle">
                            <div class="d-flex justify-content-between align-items-center"><span class="fw-bold">ยอดรวมสุทธิ</span><strong class="fs-5 text-primary"><?= number_format($pr_grand, 2) ?> บาท</strong></div>
                        </div>
                        <?php endif; ?>

                        <div class="d-grid gap-2 mt-1">
                            <button type="submit" class="btn btn-primary btn-lg rounded-pill shadow-sm fw-semibold py-3"<?= $requestType === 'hire' && $remainingInstallments === 0 ? ' disabled' : '' ?>><?= $requestType === 'hire' ? 'ยืนยันสร้างใบสั่งจ่ายงวดนี้' : 'สร้างใบสั่งซื้อ' ?></button>
                            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-request-view.php'), ENT_QUOTES, 'UTF-8') ?>?id=<?= $pr_id ?>" class="btn btn-outline-danger btn-lg rounded-pill fw-semibold py-2">ยกเลิก</a>
                        </div>
                    </form>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </div>
<?php include dirname(__DIR__, 2) . '/includes/datatables_bundle.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function ($) {
    if (typeof window.TncLiveDT === 'undefined' || !$ || !$.fn.DataTable) return;
    var $t = $('#tncHirePaidInstallmentsTable');
    if (!$t.length) return;
    if ($t.find('tbody tr').length === 1 && $t.find('tbody td[colspan]').length) return;
    TncLiveDT.init('#tncHirePaidInstallmentsTable', { order: [[2, 'desc']], pageLength: 10 });
})(jQuery);
</script>
<script>
(function () {
    const searchInput = document.getElementById('supplier_search');
    const supplierIdInput = document.getElementById('supplier_id');
    const datalist = document.getElementById('supplier_list');
    if (!searchInput || !supplierIdInput || !datalist) {
        return;
    }

    function syncSupplierId() {
        const typed = (searchInput.value || '').trim();
        if (typed === '') {
            supplierIdInput.value = '';
            return;
        }
        const options = datalist.querySelectorAll('option');
        let matchedId = '';
        options.forEach((opt) => {
            const optValue = (opt.value || '').trim();
            if (matchedId === '' && optValue.toLowerCase() === typed.toLowerCase()) {
                matchedId = (opt.getAttribute('data-id') || '').trim();
            }
        });
        supplierIdInput.value = matchedId;
    }

    searchInput.addEventListener('input', syncSupplierId);
    searchInput.addEventListener('change', syncSupplierId);

    const form = searchInput.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            syncSupplierId();
        });
    }
})();

(function () {
    const cb = document.getElementById('has_qt');
    const panel = document.getElementById('qt_panel');
    const fields = ['qt_quotation_number', 'qt_quotation_date', 'qt_quotation_note'].map(function (id) { return document.getElementById(id); }).filter(Boolean);
    if (!cb || !panel) return;

    function setQtEnabled(on) {
        fields.forEach(function (el) {
            el.disabled = !on;
            if (!on) {
                if (el.type === 'checkbox') return;
                el.value = '';
            }
        });
    }

    function toggleQtPanel() {
        const on = cb.checked;
        panel.classList.toggle('d-none', !on);
        setQtEnabled(on);
    }

    cb.addEventListener('change', toggleQtPanel);
    setQtEnabled(false);
    panel.classList.add('d-none');
})();

(function () {
    const installmentAmountInput = document.getElementById('installment_amount');
    const subtotalTextEl = document.getElementById('subtotal_text');
    const vatTextEl = document.getElementById('vat_text');
    const totalAfterVatTextEl = document.getElementById('total_after_vat_text');
    const retentionDisplayEl = document.getElementById('retention_display');
    const grandTotalEl = document.getElementById('grand_total');
    const retentionSummaryRowEl = document.getElementById('retention_summary_row');
    const withholdingTypeEl = document.getElementById('withholding_type');
    const retentionTypeEl = document.getElementById('retention_type');
    const retentionValueEl = document.getElementById('retention_value');
    const installmentDescriptionEl = document.getElementById('installment_description');
    const vatEnabledEl = document.getElementById('vat_enabled');
    const table = document.getElementById('hireInstallmentTable');
    const addRowBtn = document.getElementById('addHireRowBtn');
    if (!installmentAmountInput || !subtotalTextEl || !table) {
        return;
    }

    const recalc = () => {
        let subtotal = 0;
        let firstDescription = '';
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach((row) => {
            const descEl = row.querySelector('.hire-desc');
            const qtyEl = row.querySelector('.hire-qty');
            const priceEl = row.querySelector('.hire-price');
            const lineTotalEl = row.querySelector('.hire-line-total');
            const qty = parseFloat(qtyEl?.value || '0') || 0;
            const unitPrice = parseFloat(priceEl?.value || '0') || 0;
            const lineTotal = qty * unitPrice;
            subtotal += lineTotal;
            if (lineTotalEl) {
                lineTotalEl.value = lineTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            if (firstDescription === '' && (descEl?.value || '').trim() !== '') {
                firstDescription = (descEl?.value || '').trim();
            }
        });
        if (installmentDescriptionEl) {
            installmentDescriptionEl.value = firstDescription !== '' ? firstDescription : 'สั่งจ่ายตามตารางรายการ';
        }
        subtotal = Math.round(subtotal * 100) / 100;
        installmentAmountInput.value = subtotal > 0 ? String(subtotal) : '';

        const vat = vatEnabledEl?.checked ? Math.round(subtotal * 0.07 * 100) / 100 : 0;
        const whtType = 'none';
        if (withholdingTypeEl) {
            withholdingTypeEl.value = whtType;
        }
        const whtRate = whtType === 'wht3' ? 0.03 : 0;
        const wht = Math.round(subtotal * whtRate * 100) / 100;

        const retentionType = (retentionTypeEl?.value || 'fixed');
        let retentionValueRaw = (retentionValueEl?.value || '').toString().trim();
        retentionValueRaw = retentionValueRaw.replace('%', '');
        let retentionValue = parseFloat(retentionValueRaw) || 0;
        if (retentionValue < 0) retentionValue = 0;
        let retention = 0;
        if (retentionType === 'percent') {
            if (retentionValue > 100) retentionValue = 100;
            retention = Math.round(subtotal * (retentionValue / 100) * 100) / 100;
        } else if (retentionType === 'fixed') {
            retention = Math.round(retentionValue * 100) / 100;
        }
        const totalAfterVat = Math.round((subtotal + vat) * 100) / 100;
        const afterWht = Math.round((totalAfterVat - wht) * 100) / 100;
        const net = Math.round((afterWht - retention) * 100) / 100;

        const fmt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
        subtotalTextEl.textContent = subtotal.toLocaleString(undefined, fmt);
        if (vatTextEl) vatTextEl.textContent = '+ ' + vat.toLocaleString(undefined, fmt);
        if (totalAfterVatTextEl) totalAfterVatTextEl.textContent = totalAfterVat.toLocaleString(undefined, fmt);
        if (retentionDisplayEl) retentionDisplayEl.textContent = '- ' + retention.toLocaleString(undefined, fmt);
        if (grandTotalEl) grandTotalEl.textContent = net.toLocaleString(undefined, fmt);
        if (retentionSummaryRowEl) retentionSummaryRowEl.style.display = retention > 0 ? 'flex' : 'none';
    };

    const updateRemoveButtons = () => {
        const rows = table.querySelectorAll('tbody tr');
        const disableRemove = rows.length <= 1;
        rows.forEach((row) => {
            const btn = row.querySelector('.hire-remove-row');
            if (btn) {
                btn.disabled = disableRemove;
            }
        });
    };

    const bindRow = (row) => {
        row.querySelectorAll('.hire-desc, .hire-qty, .hire-price').forEach((el) => {
            el?.addEventListener('input', recalc);
        });
        const removeBtn = row.querySelector('.hire-remove-row');
        removeBtn?.addEventListener('click', () => {
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            if (tbody.querySelectorAll('tr').length <= 1) return;
            row.remove();
            updateRemoveButtons();
            recalc();
        });
    };
    table.querySelectorAll('tbody tr').forEach(bindRow);
    addRowBtn?.addEventListener('click', () => {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" name="hire_description[]" class="form-control hire-desc" required placeholder="เช่น ค่าแรง DC"></td>
            <td><input type="number" name="hire_qty[]" class="form-control hire-qty text-end" min="0" step="0.01" value="1"></td>
            <td><input type="number" name="hire_unit_price[]" class="form-control hire-price text-end" min="0" step="0.01" value="0"></td>
            <td><input type="text" class="form-control hire-line-total text-end bg-light" readonly value="0.00"></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger hire-remove-row"><i class="bi bi-trash"></i></button></td>
        `;
        tbody.appendChild(tr);
        bindRow(tr);
        updateRemoveButtons();
        recalc();
    });

    withholdingTypeEl?.addEventListener('change', recalc);
    retentionTypeEl?.addEventListener('change', recalc);
    retentionValueEl?.addEventListener('input', recalc);
    vatEnabledEl?.addEventListener('change', recalc);
    const form = document.querySelector('form[action*="create_po_from_pr"]');
    form?.addEventListener('submit', () => {
        if (installmentDescriptionEl && installmentDescriptionEl.value.trim() === '') {
            installmentDescriptionEl.value = 'สั่งจ่ายตามตารางรายการ';
        }
        if (withholdingTypeEl) withholdingTypeEl.value = 'none';
        if (retentionTypeEl) retentionTypeEl.value = 'fixed';
    });

    updateRemoveButtons();
    recalc();
})();
</script>
</body>
</html>