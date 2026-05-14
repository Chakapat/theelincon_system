<?php

declare(strict_types=1);

/**
 * เนื้อหาใบ PO สำหรับพิมพ์ — ตัวแปรจาก tnc_purchase_po_print_prepare()
 *
 * @var array $po
 * @var array $data
 * @var array<int, array<string, mixed>> $items
 * @var string $orderType
 * @var string $contractorName
 * @var int $installmentNo
 * @var int $installmentTotal
 * @var string $referencePrNumber
 * @var string $withholdingType
 * @var float $withholdingAmount
 * @var string $retentionType
 * @var float $retentionAmount
 * @var string $poNotePo
 * @var string $poNoteQt
 * @var string $poSiteDisplay
 * @var int $po_vat_enabled
 * @var float $po_vat_amount
 * @var float $po_grand_total
 * @var float $po_subtotal
 * @var float $po_gross_amount
 * @var string $issueDate
 * @var bool $isPoCancelled
 */

$poPaymentStatusRow = strtolower(trim((string) ($po['payment_status'] ?? 'unpaid')));
?>
<div class="invoice-box po-purchase-order-doc">
    <?php if ($isPoCancelled): ?>
    <div class="po-cancelled-watermark" aria-hidden="true">ยกเบิกใบสั่งซื้อ</div>
    <?php endif; ?>
    <div class="po-doc-main">
    <div class="row align-items-start mb-2">
        <div class="col-6">
            <?php if (!empty($data['logo'])): ?>
                <img src="<?= htmlspecialchars(upload_logo_url($data['logo'])) ?>" class="company-logo" alt="Logo">
            <?php endif; ?>
            <div class="fw-bold mt-2" style="font-size: 16px;"><?= $data['name']; ?></div>
            <div class="small text-muted" style="font-size: 11px; line-height: 1.4;">
                <?= $data['address']; ?><br>
                โทร: <?= $data['phone']; ?> | Tax ID: <?= $data['tax_id']; ?>
            </div>
        </div>
        <div class="col-6 text-end">
            <div class="invoice-title"><?= $orderType === 'hire' ? 'PAYMENT ORDER' : 'PURCHASE ORDER' ?></div>
            <div class="fw-bold text-muted small"><?= $orderType === 'hire' ? 'ใบสั่งจ่าย / ใบสั่งจ้าง' : 'ใบสั่งซื้อสินค้า' ?></div>
            <div class="po-po-number-row d-flex flex-wrap align-items-center justify-content-end gap-2 mt-2">
                <span class="po-po-number-label text-dark fw-bold" style="font-size: 18px;"><?= $orderType === 'hire' ? 'เลขที่ใบสั่งจ่าย' : 'เลขที่ใบสั่งซื้อ' ?>:</span>
                <span class="po-po-number-value text-dark fw-bold" style="font-size: 18px;"><?= htmlspecialchars((string) ($data['po_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($isPoCancelled): ?>
                    <span class="badge rounded-pill text-bg-danger po-po-status-badge">ยกเลิก</span>
                <?php elseif ($poPaymentStatusRow !== 'paid'): ?>
                    <span class="badge rounded-pill border text-secondary bg-white po-po-status-badge">รอชำระ</span>
                <?php endif; ?>
            </div>
            <?php $quotationNo = trim((string) ($data['quotation_number'] ?? '')); ?>
            <?php $quotationAttach = trim((string) ($data['quotation_attachment_path'] ?? '')); ?>
            <?php if ($quotationNo !== ''): ?>
                <div class="small text-muted">อ้างอิงใบเสนอราคา: <?= htmlspecialchars($quotationNo, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($quotationAttach !== ''): ?>
                <?php
                $attachLabel = trim((string) ($data['quotation_attachment_name'] ?? ''));
                if ($attachLabel === '') {
                    $attachLabel = 'เปิดไฟล์';
                }
                ?>
                <div class="small text-muted">ไฟล์ใบเสนอราคา:
                    <a href="<?= htmlspecialchars(app_path($quotationAttach), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($attachLabel, ENT_QUOTES, 'UTF-8') ?></a>
                </div>
            <?php endif; ?>
            <?php if ($referencePrNumber !== ''): ?>
                <div class="small text-muted"><?= $orderType === 'hire' ? 'อ้างอิงสัญญา' : 'อ้างอิง PR' ?>: <?= htmlspecialchars($referencePrNumber, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($poSiteDisplay !== ''): ?>
                <div class="small text-muted">ไซต์งาน: <?= htmlspecialchars($poSiteDisplay, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($orderType === 'hire' && $installmentNo > 0 && $installmentTotal > 0): ?>
                <div class="small text-muted">งวดที่ <?= number_format($installmentNo) ?> / <?= number_format($installmentTotal) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row mb-2 mt-3">
        <div class="col-7 border-start border-4 ps-3 po-side-accent">
            <div class="po-section-kicker mb-1"><?= $orderType === 'hire' ? 'ผู้รับจ้าง' : 'Vendor / ผู้ขาย' ?></div>
            <div class="po-section-title text-dark"><?= htmlspecialchars($orderType === 'hire' && $contractorName !== '' ? $contractorName : (string) ($data['s_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="small text-muted po-section-detail">
                <?= htmlspecialchars((string) ($data['s_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><br>
                <strong>Tax ID:</strong> <?= htmlspecialchars((string) ($data['s_tax'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> | <strong>โทร:</strong> <?= htmlspecialchars((string) ($data['s_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
        <div class="col-5 text-end">
            <div class="po-section-kicker mb-1 text-end">วันที่ออกบิล</div>
            <div class="po-section-title text-dark text-end"><?= htmlspecialchars(tnc_po_format_date_thai($issueDate), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>

    <table class="table table-custom po-items-table mt-2">
        <thead>
            <tr class="text-center">
                <th style="width:38%;" class="text-start po-th-desc">รายละเอียดสินค้า / บริการ</th>
                <th style="width:10%;" class="text-center po-th-num">จำนวน</th>
                <th style="width:8%;" class="text-center po-th-num">หน่วย</th>
                <th style="width:11%;" class="text-end po-th-num po-th-price"><span class="text-nowrap">ราคา/หน่วย</span></th>
                <th style="width:11%;" class="text-end po-th-num">ส่วนลด</th>
                <th style="width:13%;" class="text-end po-th-num">ยอดรวม</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($items) === 0): ?>
            <tr>
                <td colspan="6" class="text-center text-muted py-4">ไม่พบรายการสินค้าในใบสั่งซื้อนี้</td>
            </tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                <?php
                $unitCell = trim((string) ($item['unit'] ?? ''));
                ?>
                <tr>
                    <td class="fw-bold text-dark text-start"><?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-center po-td-num"><?= number_format((float) ($item['quantity'] ?? 0), 0); ?></td>
                    <td class="text-center po-td-num text-muted"><?= $unitCell !== '' ? htmlspecialchars($unitCell, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                    <td class="text-end po-td-num"><?= number_format((float) ($item['unit_price'] ?? 0), 2); ?></td>
                    <td class="text-end text-muted small po-td-num">
                        <?php
                        $discIn = trim((string) ($item['discount_input'] ?? ''));
                        $discAmt = (float) ($item['discount_amount'] ?? 0);
                        if ($discIn !== '') {
                            echo htmlspecialchars($discIn, ENT_QUOTES, 'UTF-8');
                        } elseif ($discAmt > 0) {
                            echo htmlspecialchars(number_format($discAmt, 2), ENT_QUOTES, 'UTF-8');
                        } else {
                            echo '—';
                        }
                        ?>
                    </td>
                    <td class="text-end fw-bold po-td-num"><?= number_format((float) ($item['total'] ?? 0), 2); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="footer-sticky">
        <div class="row align-items-end mb-3">
            <div class="col-7 small text-muted italic">
                <?php if ($poNotePo !== ''): ?>
                    <div style="font-size: 11px; font-weight: 700; color: #111; margin-bottom: 4px;">หมายเหตุ PO</div>
                    <div style="font-size: 12px; line-height: 1.45; color: #444; white-space: pre-line; margin-bottom: <?= $poNoteQt !== '' ? '12px' : '0' ?>;">
                        <?= htmlspecialchars($poNotePo, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <?php if ($poNoteQt !== ''): ?>
                    <div style="font-size: 11px; font-weight: 700; color: #111; margin-bottom: 4px;"><?= $poNotePo !== '' ? 'หมายเหตุ / เงื่อนไข (QT)' : 'หมายเหตุ' ?></div>
                    <div style="font-size: 12px; line-height: 1.45; color: #444; white-space: pre-line;">
                        <?= htmlspecialchars($poNoteQt, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-5">
                <div class="summary-box po-total-sheet">
                    <div class="summary-item">
                        <span>ยอดรวม</span>
                        <span><?= number_format($po_subtotal, 2); ?></span>
                    </div>
                    <?php if ($po_vat_enabled && $po_vat_amount > 0): ?>
                    <div class="summary-item po-vat-line">
                        <span>VAT 7%</span>
                        <span><?= number_format($po_vat_amount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($withholdingType !== 'none' && $withholdingAmount > 0): ?>
                    <div class="summary-item text-danger">
                        <span>หัก ณ ที่จ่าย 3%</span>
                        <span>-<?= number_format($withholdingAmount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($retentionType !== 'none' && $retentionAmount > 0): ?>
                    <div class="summary-item text-danger">
                        <span>หักประกันผลงาน<?= $retentionType === 'percent' ? ' (%)' : '' ?></span>
                        <span>-<?= number_format($retentionAmount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (($withholdingType !== 'none' && $withholdingAmount > 0) || ($retentionType !== 'none' && $retentionAmount > 0)): ?>
                    <div class="summary-item">
                        <span>ยอดก่อนหัก</span>
                        <span><?= number_format($po_gross_amount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="grand-total-row" role="group" aria-label="ยอดสุทธิทั้งสิ้น">
                        <span class="fw-bold" style="font-size: 14px;">ยอดสุทธิทั้งสิ้น</span>
                        <span style="font-size: 18px; font-weight: 800;"><?= number_format($po_grand_total, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="signature-grid">
            <div>
                <div class="sig-space"></div>
                <div class="sig-box">ผู้สั่งซื้อ / สั่งจ้าง<br><small>(Authorized Signature)</small></div>
            </div>
            <div>
                <div class="sig-space"></div>
                <div class="sig-box">ผู้อนุมัติสั่งซื้อ / สั่งจ่าย<br><small>(Approver Signature)</small></div>
            </div>
        </div>
    </div>
</div>
