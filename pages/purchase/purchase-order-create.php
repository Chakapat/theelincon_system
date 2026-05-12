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

$po_number = Purchase::generatePONumber();
$supplier_rows = Db::tableRows('suppliers');
Db::sortRows($supplier_rows, 'name', false);

$errorCode = trim((string) ($_GET['error'] ?? ''));

$issueDateDefault = date('Y-m-d');
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สร้างใบสั่งซื้อ (PO)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background: linear-gradient(165deg, #f0f4f8 0%, #f8f9fb 45%, #eef2f7 100%); font-family: 'Sarabun', sans-serif; min-height: 100vh; }
        .po-create-wrap { max-width: 1100px; }
        .po-create-hero {
            background: linear-gradient(125deg, #0d6efd 0%, #3d8bfd 42%, #6ea8fe 100%);
            border-radius: 1rem;
            box-shadow: 0 12px 40px rgba(13, 110, 253, 0.22);
            color: #fff;
        }
        .po-create-hero .hero-kicker { font-size: 0.72rem; letter-spacing: 0.12em; text-transform: uppercase; opacity: 0.92; font-weight: 700; }
        .po-create-hero h1 { font-size: clamp(1.35rem, 3.5vw, 1.75rem); font-weight: 800; letter-spacing: -0.02em; }
        .po-create-hero .hero-lead { opacity: 0.9; font-size: 0.9rem; max-width: 26rem; }
        .po-create-hero .btn-light { border: 0; font-weight: 600; }
        .po-create-hero .btn-primary { background: #fff; color: #0d6efd; border: 0; font-weight: 700; }
        .po-create-hero .btn-primary:hover { background: #f0f6ff; color: #0a58ca; }
        .card-soft {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 1rem;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
            background: #fff;
        }
        .po-section-head {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 1.1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #eef2f7;
        }
        .po-section-head .po-section-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.65rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, #e8f1ff, #f0f6ff);
            color: #0d6efd;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .section-title { font-size: 1.05rem; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.02em; }
        .section-sub { font-size: 0.8rem; color: #64748b; margin: 0.2rem 0 0; line-height: 1.4; }
        .po-field-label { font-size: 0.78rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.35rem; }
        .form-control, .form-select, .input-group-text { border-radius: 0.5rem; }
        .po-meta-card .form-control:focus { box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.12); }
        .po-po-number { font-size: 1.05rem; letter-spacing: 0.02em; }
        .po-qt-toggle {
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 0.85rem 1rem;
            background: #fafbfc;
            transition: background 0.15s ease, border-color 0.15s ease;
        }
        .po-qt-toggle:hover { border-color: #cbd5e1; background: #f8fafc; }
        .po-qt-toggle .form-check-input { width: 2.5rem; height: 1.25rem; cursor: pointer; }
        .po-qt-toggle .form-check-label { cursor: pointer; padding-top: 0.1rem; }
        #quotation_panel { border-color: #e2e8f0 !important; background: #f8fafc !important; border-radius: 0.75rem !important; }
        .po-table-wrap { border: 1px solid #e8ecf1; border-radius: 0.75rem; overflow: hidden; background: #fff; }
        .po-table-wrap .table { margin-bottom: 0; }
        .po-table-wrap thead th {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
            font-weight: 700;
            background: #f1f5f9 !important;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.65rem 0.5rem;
            white-space: nowrap;
        }
        .po-table-wrap tbody td { padding: 0.5rem 0.45rem; vertical-align: middle; }
        .po-table-wrap .form-control-sm { min-height: calc(1.5em + 0.6rem + 2px); }
        .po-wht-box {
            border: 1px solid #fee2e2;
            border-radius: 0.75rem;
            padding: 0.85rem 1rem;
            background: linear-gradient(180deg, #fffefe 0%, #fff7f7 100%);
        }
        .po-wht-box .form-check-input { cursor: pointer; }
        .summary-box {
            background: linear-gradient(180deg, #f8fbff 0%, #f0f7ff 100%);
            border: 1px solid #c7dbfa;
            border-radius: 0.85rem;
            padding: 1.1rem 1.15rem;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
        }
        @media (min-width: 992px) {
            .po-summary-sticky { position: sticky; top: 5.5rem; }
        }
        .summary-line { display: grid; grid-template-columns: 1fr minmax(6.5rem, max-content); align-items: center; gap: 12px; width: 100%; margin-bottom: 10px; }
        .summary-line:last-child { margin-bottom: 0; }
        .summary-label { color: #475569; font-weight: 600; font-size: 0.9rem; }
        .summary-value { font-weight: 700; white-space: nowrap; text-align: right; justify-self: end; font-variant-numeric: tabular-nums; }
        .summary-grand { padding-top: 0.35rem; margin-top: 0.25rem; border-top: 2px dashed rgba(13, 110, 253, 0.25); }
        .summary-grand .summary-label { font-size: 1rem; color: #0f172a; }
        .summary-grand .summary-value { font-size: 1.25rem; color: #0d6efd !important; }
        .po-vat-panel { background: #f8faff; border: 1px solid #dbe7ff; border-radius: 0.75rem; }
        .po-actions-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eef2f7; }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container container-lg py-4 py-md-5 mb-5 po-create-wrap">
    <form action="<?= htmlspecialchars(app_path('actions/action-handler.php')) ?>?action=create_po_direct" method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>

        <header class="po-create-hero p-4 p-md-4 mb-4">
            <div class="row align-items-center g-3">
                <div class="col-lg">
                    <div class="hero-kicker">จัดซื้อจัดจ้าง</div>
                    <h1 class="mb-2 mt-1"><i class="bi bi-file-earmark-plus-fill me-2 opacity-90"></i>สร้างใบสั่งซื้อ (PO)</h1>
                    <p class="hero-lead mb-0">กรอกข้อมูลหลัก รายการสินค้า และภาษี แล้วกดบันทึก — ระบบจะพาไปหน้ารายการ PO เมื่อสำเร็จ</p>
                </div>
                <div class="col-lg-auto d-flex flex-wrap gap-2 justify-content-lg-end">
                    <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-list.php')) ?>" class="btn btn-light rounded-pill px-4 shadow-sm"><i class="bi bi-arrow-left me-1"></i>รายการ PO</a>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 shadow"><i class="bi bi-check2-circle me-1"></i>สร้างใบสั่งซื้อ</button>
                </div>
            </div>
        </header>

        <div class="card card-soft p-4 p-md-4 mb-4 po-meta-card">
            <div class="po-section-head">
                <div class="po-section-icon" aria-hidden="true"><i class="bi bi-info-lg"></i></div>
                <div>
                    <h2 class="section-title">ข้อมูลเอกสาร</h2>
                    <p class="section-sub">เลขที่ PO ออกโดยระบบ · ระบุวันที่ออกใบสั่งซื้อ · เลือกผู้ขายจากรายการแนะนำ (ถ้ามี)</p>
                </div>
            </div>
            <div class="row g-3 g-md-4">
                <div class="col-md-4">
                    <label class="po-field-label" for="po_number_display">เลขที่ใบสั่งซื้อ</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-primary border-end-0"><i class="bi bi-hash"></i></span>
                        <input type="text" id="po_number_display" class="form-control po-po-number bg-light text-primary fw-bold border-start-0" value="<?= htmlspecialchars($po_number, ENT_QUOTES, 'UTF-8') ?>" readonly>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="po-field-label" for="issue_date">วันที่ออกใบสั่งซื้อ <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-white text-primary" title="ปฏิทิน"><i class="bi bi-calendar3"></i></span>
                        <input
                            type="date"
                            class="form-control"
                            name="issue_date"
                            id="issue_date"
                            value="<?= htmlspecialchars($issueDateDefault, ENT_QUOTES, 'UTF-8') ?>"
                            required
                            lang="th"
                            autocomplete="off"
                        >
                    </div>
                    <div class="form-text small">แตะช่องวันที่เพื่อเปิดปฏิทิน</div>
                </div>
                <div class="col-md-4">
                    <label class="po-field-label" for="supplier_search">ผู้ขาย <span class="text-muted fw-normal text-lowercase" style="letter-spacing:0;">(ไม่บังคับ)</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-white text-secondary"><i class="bi bi-shop"></i></span>
                        <input type="text" id="supplier_search" class="form-control" list="supplier_list" placeholder="พิมพ์ชื่อแล้วเลือกจากรายการ">
                    </div>
                    <datalist id="supplier_list">
                        <?php foreach ($supplier_rows as $s): ?>
                            <option
                                value="<?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-id="<?= (int) ($s['id'] ?? 0) ?>"
                            ></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="supplier_id" id="supplier_id">
                </div>
            </div>
        </div>

        <div class="card card-soft p-4 p-md-4 mb-4">
            <div class="po-section-head">
                <div class="po-section-icon" aria-hidden="true"><i class="bi bi-file-earmark-text"></i></div>
                <div>
                    <h2 class="section-title">ใบเสนอราคา (QT)</h2>
                    <p class="section-sub">ถ้ามี QT ให้เปิดสวิตช์ด้านล่าง แล้วกรอกเลขที่หรือแนบไฟล์อย่างใดอย่างหนึ่ง</p>
                </div>
            </div>
            <div class="po-qt-toggle mb-3">
                <div class="form-check form-switch d-flex align-items-center gap-3 mb-0">
                    <input class="form-check-input flex-shrink-0 ms-0" type="checkbox" name="has_quotation" id="has_quotation" value="1">
                    <div>
                        <label class="form-check-label fw-bold mb-0" for="has_quotation">มีใบเสนอราคา</label>
                        <div class="small text-muted">เปิดเมื่อต้องการแนบหลักฐานหรือเลขที่ QT</div>
                    </div>
                </div>
            </div>
            <div id="quotation_panel" class="collapse border p-3 p-md-4 bg-light">
                <p class="small text-secondary mb-3"><i class="bi bi-lightbulb me-1 text-warning"></i>กรอกเลขที่ QT หรือแนบไฟล์ (อย่างใดอย่างหนึ่ง หรือทั้งคู่)</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="fw-semibold small text-secondary" for="quotation_number">เลขที่ใบเสนอราคา (QT No.)</label>
                        <input type="text" name="quotation_number" id="quotation_number" class="form-control mt-1" maxlength="120" placeholder="เช่น QT-2026-015" disabled>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-semibold small text-secondary" for="quotation_file">แนบไฟล์ใบเสนอราคา</label>
                        <input type="file" name="quotation_file" id="quotation_file" class="form-control mt-1" accept=".pdf,image/*,image/jpeg,image/png,image/webp,image/gif" disabled>
                        <div class="form-text small">PDF หรือรูปภาพ (JPG, PNG, WEBP, GIF)</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-soft p-4 p-md-4 mb-4">
            <div class="po-section-head">
                <div class="po-section-icon" aria-hidden="true"><i class="bi bi-list-check"></i></div>
                <div class="flex-grow-1">
                    <h2 class="section-title">รายการสินค้า / บริการ</h2>
                    <p class="section-sub mb-0">ระบุรายการ จำนวน ราคา — ยอดรวมคำนวณอัตโนมัติ</p>
                </div>
            </div>

            <div class="table-responsive po-table-wrap">
                <table class="table align-middle table-hover" id="poTable">
                    <thead>
                        <tr>
                            <th style="width:3rem;">#</th>
                            <th>รายการ</th>
                            <th style="width:6.5rem;">จำนวน</th>
                            <th style="width:6.5rem;">หน่วย</th>
                            <th style="width:7.5rem;">ราคา/หน่วย</th>
                            <th style="width:7.5rem;">ยอดรวม</th>
                            <th style="width:2.75rem;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="row-number text-secondary small fw-semibold">1</td>
                            <td><input type="text" name="item_description[]" class="form-control form-control-sm" required placeholder="ระบุรายการ"></td>
                            <td><input type="number" name="item_qty[]" class="form-control form-control-sm qty" step="0.01" min="0" required oninput="calculateTotal()"></td>
                            <td><input type="text" name="item_unit[]" class="form-control form-control-sm" placeholder="ชิ้น"></td>
                            <td><input type="number" name="item_price[]" class="form-control form-control-sm price" step="0.01" min="0" required oninput="calculateTotal()"></td>
                            <td><input type="text" class="form-control form-control-sm row-total bg-light text-end fw-semibold" value="0.00" readonly tabindex="-1"></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="po-actions-bar">
                <span class="small text-muted"><i class="bi bi-grip-vertical me-1"></i>เพิ่มแถวได้หลายรายการ</span>
                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 shadow-sm" onclick="addRow()">
                    <i class="bi bi-plus-lg me-1"></i>เพิ่มรายการ
                </button>
            </div>

            <div class="row g-4 mt-1">
                <div class="col-lg-7 order-2 order-lg-1">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="rounded-circle bg-primary bg-opacity-10 text-primary p-2"><i class="bi bi-percent"></i></span>
                        <div>
                            <div class="fw-bold text-dark">ภาษีและการหักเงิน</div>
                            <div class="small text-muted">ตั้งค่า VAT 7% และหัก ณ ที่จ่าย (ถ้ามี)</div>
                        </div>
                    </div>
                    <label class="small fw-bold text-secondary text-uppercase mb-2" style="letter-spacing:0.05em;">ภาษีมูลค่าเพิ่ม</label>
                    <div class="po-vat-panel p-3 mb-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="vat_enabled" id="vat_enabled" value="1" onchange="calculateTotal()">
                            <label class="form-check-label fw-semibold" for="vat_enabled">มี VAT</label>
                        </div>
                        <input type="hidden" name="vat_mode" id="vat_mode" value="inclusive">
                        <div id="vat_basis_wrap" class="pt-2 border-top border-opacity-50">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_inclusive" value="inclusive" checked onchange="calculateTotal()">
                                <label class="form-check-label" for="vat_basis_inclusive">รวม VAT <span class="text-muted small">(ราคารวมภาษีแล้ว)</span></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="vat_basis" id="vat_basis_exclusive" value="exclusive" onchange="calculateTotal()">
                                <label class="form-check-label" for="vat_basis_exclusive">แยก VAT <span class="text-muted small">(บวก 7% เพิ่มจากฐาน)</span></label>
                            </div>
                        </div>
                        <div class="mt-3 mb-0">
                            <label class="small text-muted mb-1" for="vat_rate">อัตรา VAT (%)</label>
                            <input type="number" class="form-control form-control-sm bg-white" id="vat_rate" step="0.01" min="0" max="100" value="7" readonly aria-readonly="true">
                        </div>
                    </div>
                    <div class="po-wht-box">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="wht_enabled" onchange="calculateTotal()">
                            <label class="form-check-label fw-semibold text-danger" for="wht_enabled">หัก ณ ที่จ่าย 3%</label>
                        </div>
                        <div class="small text-muted ps-1 mt-1">คิดจากยอดก่อน VAT</div>
                    </div>
                </div>
                <div class="col-lg-5 order-1 order-lg-2">
                    <div class="small fw-bold text-secondary text-uppercase mb-2" style="letter-spacing:0.06em;">สรุปยอดเงิน</div>
                    <div class="summary-box po-summary-sticky">
                        <div class="summary-line small text-muted"><span class="summary-label" id="subtotal_label">ยอดรายการ (ก่อน VAT)</span><strong class="summary-value text-end"><span id="subtotal_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-success" id="vat_row" style="display:none;"><span class="summary-label">VAT 7%</span><strong class="summary-value text-end"><span id="vat_display">0.00</span> บาท</strong></div>
                        <div class="summary-line small text-danger" id="wht_row" style="display:none;"><span class="summary-label">หัก ณ ที่จ่าย</span><strong class="summary-value text-end">- <span id="wht_display">0.00</span> บาท</strong></div>
                        <div class="summary-line summary-grand fw-bold"><span class="summary-label">ยอดรวมสุทธิ</span><span class="summary-value text-primary"><span id="grand_total">0.00</span> บาท</span></div>
                    </div>
                    <input type="hidden" name="total_amount" id="total_amount_input" value="0">
                    <input type="hidden" name="withholding_type" id="withholding_type" value="none">
                    <input type="hidden" name="retention_type" value="none">
                    <input type="hidden" name="retention_value" value="0">
                </div>
            </div>
        </div>

        <div class="card card-soft p-4 p-md-4 mb-2">
            <div class="po-section-head border-0 pb-0 mb-3">
                <div class="po-section-icon" aria-hidden="true"><i class="bi bi-chat-left-text"></i></div>
                <div>
                    <h2 class="section-title">หมายเหตุ</h2>
                    <p class="section-sub">แสดงบนใบ PO (ถ้ามี)</p>
                </div>
            </div>
            <textarea name="quotation_note" id="quotation_note" class="form-control" rows="3" maxlength="500" placeholder="เช่น เงื่อนไขจัดส่ง กำหนดส่งของ หรือข้อตกลงพิเศษ"></textarea>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2 d-md-none">
            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-list.php')) ?>" class="btn btn-outline-secondary rounded-pill">ย้อนกลับ</a>
            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold"><i class="bi bi-check2-circle me-1"></i>สร้างใบสั่งซื้อ</button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const poCreateErrorCode = <?= json_encode($errorCode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
(function () {
    if (!poCreateErrorCode) return;
    const messages = {
        no_items: 'กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ และกรอกจำนวน/ราคาให้ถูกต้อง',
        invalid_items: 'กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ และกรอกจำนวน/ราคาให้ถูกต้อง',
        contract: 'ไม่พบข้อมูลสัญญาจ้างที่อ้างอิง กรุณาตรวจสอบใหม่',
        supplier: 'กรุณาเลือกผู้ขายจากรายการที่ระบบแนะนำ',
        quotation_required: 'เมื่อระบุว่ามีใบเสนอราคา กรุณากรอกเลขที่ QT หรือแนบไฟล์อย่างน้อยหนึ่งอย่าง',
        quotation_upload_failed: 'อัปโหลดไฟล์ใบเสนอราคาไม่สำเร็จ กรุณาลองใหม่',
        quotation_upload_type: 'ไฟล์ใบเสนอราคาต้องเป็น PDF หรือรูปภาพ (JPG, PNG, WEBP, GIF ฯลฯ)'
    };
    const text = messages[poCreateErrorCode] || 'บันทึกใบ PO ไม่สำเร็จ กรุณาลองใหม่';
    Swal.fire({
        icon: 'error',
        title: 'บันทึกไม่สำเร็จ',
        text: text,
        confirmButtonText: 'ตกลง'
    });
})();

(function () {
    const cb = document.getElementById('has_quotation');
    const panel = document.getElementById('quotation_panel');
    const qNum = document.getElementById('quotation_number');
    const qFile = document.getElementById('quotation_file');
    if (!cb || !panel || !qNum || !qFile) return;

    function applyQuotationPanel() {
        const on = cb.checked;
        qNum.disabled = !on;
        qFile.disabled = !on;
        if (typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
            const inst = bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false });
            if (on) {
                inst.show();
            } else {
                inst.hide();
                qNum.value = '';
                qFile.value = '';
            }
        } else {
            panel.classList.toggle('d-none', !on);
            if (!on) {
                qNum.value = '';
                qFile.value = '';
            }
        }
    }

    cb.addEventListener('change', applyQuotationPanel);
    applyQuotationPanel();
})();

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

function addRow() {
    const table = document.getElementById('poTable').getElementsByTagName('tbody')[0];
    const newRow = table.insertRow();
    const rowCount = table.rows.length;

    newRow.innerHTML = `
        <td class="row-number text-secondary small fw-semibold">${rowCount}</td>
        <td><input type="text" name="item_description[]" class="form-control form-control-sm" required placeholder="ระบุรายการ"></td>
        <td><input type="number" name="item_qty[]" class="form-control form-control-sm qty" step="0.01" min="0" required oninput="calculateTotal()"></td>
        <td><input type="text" name="item_unit[]" class="form-control form-control-sm" placeholder="ชิ้น"></td>
        <td><input type="number" name="item_price[]" class="form-control form-control-sm price" step="0.01" min="0" required oninput="calculateTotal()"></td>
        <td><input type="text" class="form-control form-control-sm row-total bg-light text-end fw-semibold" value="0.00" readonly tabindex="-1"></td>
        <td><button type="button" class="btn btn-outline-danger btn-sm border-0 rounded-3" onclick="removeRow(this)" title="ลบแถว"><i class="bi bi-trash-fill"></i></button></td>
    `;
}

function removeRow(btn) {
    const row = btn.parentNode.parentNode;
    row.parentNode.removeChild(row);
    updateRowNumbers();
    calculateTotal();
}

function updateRowNumbers() {
    const rows = document.querySelectorAll('.row-number');
    rows.forEach((td, index) => {
        td.innerText = index + 1;
    });
}

function updatePoVatBasisUi() {
    const vatBasisWrap = document.getElementById('vat_basis_wrap');
    const vatEnabled = document.getElementById('vat_enabled');
    if (!vatBasisWrap || !vatEnabled) return;
    const on = vatEnabled.checked;
    vatBasisWrap.classList.toggle('opacity-50', !on);
    vatBasisWrap.style.pointerEvents = on ? '' : 'none';
    vatBasisWrap.setAttribute('aria-disabled', on ? 'false' : 'true');
}

function calculateTotal() {
    const FIXED_VAT_RATE = 7;
    const vatModeInput = document.getElementById('vat_mode');
    const vatRateInput = document.getElementById('vat_rate');
    const vatOn = document.getElementById('vat_enabled').checked;
    let vatMode = 'exclusive';
    if (vatOn) {
        const selectedBasis = document.querySelector('input[name="vat_basis"]:checked');
        vatMode = selectedBasis ? selectedBasis.value : 'inclusive';
    }
    if (!['inclusive', 'exclusive'].includes(vatMode)) vatMode = 'exclusive';
    if (vatModeInput) vatModeInput.value = vatMode;
    if (vatRateInput) vatRateInput.value = String(FIXED_VAT_RATE);

    let lineAmount = 0;
    const rows = document.getElementById('poTable').getElementsByTagName('tbody')[0].rows;
    const whtOn = document.getElementById('wht_enabled').checked;

    for (const row of rows) {
        const qty = parseFloat(row.querySelector('.qty').value) || 0;
        const price = parseFloat(row.querySelector('.price').value) || 0;
        const total = qty * price;
        row.querySelector('.row-total').value = total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        lineAmount += total;
    }

    lineAmount = Math.round(lineAmount * 100) / 100;
    let subtotal = lineAmount;
    let vat = 0;
    let gross = lineAmount;
    const rate = FIXED_VAT_RATE;
    if (vatOn) {
        if (vatMode === 'exclusive') {
            vat = Math.round(subtotal * rate / 100 * 100) / 100;
            gross = Math.round((subtotal + vat) * 100) / 100;
        } else if (rate > 0) {
            const base = Math.round((lineAmount / (1 + rate / 100)) * 100) / 100;
            vat = Math.round((lineAmount - base) * 100) / 100;
            subtotal = base;
            gross = lineAmount;
        }
    }
    const whtType = whtOn ? 'wht3' : 'none';
    const whtRate = whtOn ? 0.03 : 0;
    const wht = Math.round(subtotal * whtRate * 100) / 100;
    const grand = Math.round((gross - wht) * 100) / 100;
    const withholdingTypeInput = document.getElementById('withholding_type');
    if (withholdingTypeInput) {
        withholdingTypeInput.value = whtType;
    }
    updatePoVatBasisUi();
    const subtotalLabel = document.getElementById('subtotal_label');
    if (subtotalLabel) {
        subtotalLabel.textContent = vatOn && vatMode === 'inclusive'
            ? 'ยอดก่อน VAT (ถอดจากยอดรวม)'
            : 'ยอดรายการ (ก่อน VAT)';
    }

    document.getElementById('subtotal_display').innerText = subtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const vatRow = document.getElementById('vat_row');
    if (vatOn) {
        vatRow.style.display = 'block';
        document.getElementById('vat_display').innerText = vat.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        vatRow.style.display = 'none';
    }
    const whtRow = document.getElementById('wht_row');
    if (wht > 0) {
        whtRow.style.display = 'block';
        document.getElementById('wht_display').innerText = wht.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        whtRow.style.display = 'none';
    }
    document.getElementById('grand_total').innerText = grand.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('total_amount_input').value = grand.toFixed(2);
}

document.addEventListener('DOMContentLoaded', calculateTotal);
</script>

</body>
</html>
