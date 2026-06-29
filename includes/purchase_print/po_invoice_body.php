<?php

declare(strict_types=1);

/**
 * เนื้อหาใบ PO สำหรับพิมพ์ — ตัวแปรจาก tnc_purchase_po_print_prepare()
 *
 * @var array $po
 * @var array $data
 * @var array<int, array<string, mixed>> $items
 * @var string $orderType
 * @var string $supplierName
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
 * @var string $poCostCategoryName
 * @var int $po_vat_enabled
 * @var string $poVatMode
 * @var array{vat_mode: string, line_amount: float, vat_label: string, vat_amount: float, net_amount: float} $poVatPrint
 * @var float $po_vat_amount
 * @var float $po_grand_total
 * @var float $po_subtotal
 * @var float $po_gross_amount
 * @var float $poPayableAmount
 * @var bool $hasDeductionsPrint
 * @var bool $hasRetentionPrint
 * @var bool $hasWhtPrint
 * @var string $issueDate
 * @var bool $isPoCancelled
 */

$poNumberDisplay = trim((string) ($data['po_number'] ?? ''));
if ($poNumberDisplay === '') {
    $poNumberDisplay = 'PO-' . (int) ($po['id'] ?? 0);
}
$poDocDateSubtitle = $poNumberDisplay . ' · ' . tnc_po_format_date_thai($issueDate);
$poTableColCount = 6;

?>
<div class="invoice-box po-purchase-order-doc">
    <?php if ($isPoCancelled): ?>
    <div class="po-cancelled-watermark" aria-hidden="true">ยกเบิกใบสั่งซื้อ</div>
    <?php endif; ?>
    <div class="po-doc-main">
    <div class="po-doc-content">
    <div class="row align-items-start mb-2">
        <div class="col-6">
            <?php $tncCompanyLogoUrl = tnc_company_logo_url($data['logo'] ?? ''); ?>
            <?php if ($tncCompanyLogoUrl !== ''): ?>
                <img src="<?= htmlspecialchars($tncCompanyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" class="company-logo" alt="Logo">
            <?php endif; ?>
            <div class="fw-bold mt-2 po-company-name"><?= $data['name']; ?></div>
            <div class="small text-muted po-company-detail">
                <?= $data['address']; ?><br>
                โทร: <?= $data['phone']; ?> | เลขผู้เสียภาษี: <?= $data['tax_id']; ?>
            </div>
        </div>
        <div class="col-6 text-end">
            <div class="invoice-title">PURCHASE ORDER</div>
            <div class="fw-bold text-muted small"><?= htmlspecialchars($poDocDateSubtitle, ENT_QUOTES, 'UTF-8'); ?></div>
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
                    <a href="<?= htmlspecialchars(app_path($quotationAttach), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="no-print"><?= htmlspecialchars($attachLabel, ENT_QUOTES, 'UTF-8') ?></a>
                    <span class="d-none d-print-inline"><?= htmlspecialchars($attachLabel, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>
            <?php if ($referencePrNumber !== ''): ?>
                <div class="small text-muted">อ้างถึงใบขอซื้อ : <?= htmlspecialchars($referencePrNumber, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($poSiteDisplay !== '' || trim((string) ($poCostCategoryName ?? '')) !== ''): ?>
    <div class="row mb-2 doc-site-row">
        <div class="col-12">
            <div class="doc-site-block doc-site-block--po-split">
                <?php if ($poSiteDisplay !== ''): ?>
                <span class="doc-site-main">
                    <span class="doc-site-label">สถานที่:</span>
                    <span class="doc-site-value"><?= htmlspecialchars($poSiteDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
                <?php endif; ?>
                <?php if (trim((string) ($poCostCategoryName ?? '')) !== ''): ?>
                <span class="doc-site-category">
                    <span class="doc-site-label">หมวดหมู่:</span>
                    <span class="doc-site-value"><?= htmlspecialchars(trim((string) $poCostCategoryName), ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row mb-2 doc-site-row">
        <div class="col-12">
                            <div class="doc-site-block">
                    <span class="doc-site-label">ผู้ขาย:</span>
                    <span class="doc-site-value"><?= htmlspecialchars((string) ($data['s_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php if (trim((string) ($data['s_address'] ?? '')) !== '' || trim((string) ($data['s_tax'] ?? '')) !== '' || trim((string) ($data['s_phone'] ?? '')) !== ''): ?>
                <div class="doc-site-block mt-2">
                    <span class="doc-site-label">ที่อยู่ / ติดต่อ:</span>
                    <span class="doc-site-value">
                        <?= htmlspecialchars((string) ($data['s_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (trim((string) ($data['s_tax'] ?? '')) !== ''): ?>
                        | เลขผู้เสียภาษี: <?= htmlspecialchars((string) ($data['s_tax'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                        <?php if (trim((string) ($data['s_phone'] ?? '')) !== ''): ?>
                        | โทร: <?= htmlspecialchars((string) ($data['s_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
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
                <td colspan="<?= $poTableColCount ?>" class="text-center text-muted py-4">ไม่พบรายการสินค้าในใบสั่งซื้อนี้</td>
            </tr>
            <?php else: ?>
                <?php foreach ($items as $item):
                $unitCell = trim((string) ($item['unit'] ?? ''));
                ?>
                <tr>
                    <td class="fw-bold text-dark text-start"><?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-center po-td-num"><?= number_format((float) ($item['quantity'] ?? 0), 2); ?></td>
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

    <div class="footer-sticky doc-footer">
        <div class="row po-footer-row align-items-start mb-3">
            <div class="col-7 po-footer-notes-col">
                <div class="po-notes-wrap">
                    <?php if ($poNotePo !== ''): ?>
                        <?php
                        $poNoteHeading = 'หมายเหตุ PO';
                        tnc_po_render_note_panel($poNoteHeading, $poNotePo, $poNoteQt !== '');
                        ?>
                    <?php endif; ?>
                    <?php if ($poNoteQt !== ''): ?>
                        <?php tnc_po_render_note_panel($poNotePo !== '' ? 'หมายเหตุ / เงื่อนไข (QT)' : 'หมายเหตุ', $poNoteQt, false); ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-5 po-footer-totals-col">
                <div class="summary-box po-total-sheet">
                    <div class="summary-item">
                        <span>ยอดรายการ</span>
                        <span><?= number_format((float) ($poVatPrint['line_amount'] ?? $po_subtotal), 2); ?></span>
                    </div>
                    <?php if ($po_vat_enabled && (float) ($poVatPrint['vat_amount'] ?? 0) > 0): ?>
                    <?php
                    $poVatModePrint = (string) ($poVatPrint['vat_mode'] ?? '');
                    $poVatDisplayLabel = match ($poVatModePrint) {
                        'inclusive' => 'รวม VAT',
                        'exclusive' => 'แยก VAT',
                        default => (string) ($poVatPrint['vat_label'] ?? 'แยก VAT'),
                    };
                    ?>
                    <div class="summary-item po-vat-line vat-print-line">
                        <span><?= htmlspecialchars($poVatDisplayLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><?= number_format((float) ($poVatPrint['vat_amount'] ?? 0), 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($hasDeductionsPrint): ?>
                    <div class="summary-item">
                        <span>ยอดก่อนหัก</span>
                        <span><?= number_format($po_gross_amount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($hasWhtPrint): ?>
                    <div class="summary-item text-danger">
                        <span>หัก ณ ที่จ่าย 3%</span>
                        <span>-<?= number_format($withholdingAmount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($hasRetentionPrint): ?>
                    <div class="summary-item text-danger">
                        <span>หักประกันผลงาน<?= $retentionType === 'percent' ? ' (%)' : '' ?></span>
                        <span>-<?= number_format($retentionAmount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="grand-total-row" role="group" aria-label="ยอดสุทธิ">
                        <span class="fw-bold" style="font-size: 14px;">ยอดสุทธิ</span>
                        <span style="font-size: 18px; font-weight: 800;"><?= number_format((float) ($poPayableAmount ?? ($poVatPrint['net_amount'] ?? $po_grand_total)), 2); ?></span>
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
</div>
