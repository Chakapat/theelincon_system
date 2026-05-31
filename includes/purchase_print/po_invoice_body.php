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
            <div class="fw-bold mt-2 po-company-name"><?= $data['name']; ?></div>
            <div class="small text-muted po-company-detail">
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
            <?php if ($orderType === 'hire' && $installmentNo > 0 && $installmentTotal > 0): ?>
                <div class="small text-muted">งวดที่ <?= number_format($installmentNo) ?> / <?= number_format($installmentTotal) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($poSiteDisplay !== ''): ?>
    <div class="row mb-2 doc-site-row">
        <div class="col-12">
            <div class="doc-site-block">
                <span class="doc-site-label">ไซต์งาน:</span>
                <span class="doc-site-value"><?= htmlspecialchars($poSiteDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row mb-2 mt-3 doc-meta-row">
        <div class="col-7 border-start border-4 ps-3 po-side-accent">
            <div class="po-section-kicker mb-1"><?= $orderType === 'hire' ? 'ผู้รับจ้าง' : 'Vendor / ผู้ขาย' ?></div>
            <?php if ($orderType === 'hire' && (trim((string) ($contractorPrint['name_th'] ?? '')) !== '' || trim((string) ($contractorName ?? '')) !== '')): ?>
                <?php
                if (trim((string) ($contractorPrint['name_th'] ?? '')) === '' && trim((string) ($contractorName ?? '')) !== '') {
                    $contractorPrint['name_th'] = trim((string) $contractorName);
                }
                $contractorPrintLayout = 'detail';
                include __DIR__ . '/contractor_print_block.php';
                ?>
            <?php elseif ($orderType === 'hire'): ?>
            <div class="po-section-title text-dark">-</div>
            <?php else: ?>
            <div class="po-section-title text-dark"><?= htmlspecialchars((string) ($data['s_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="small text-muted po-section-detail">
                <?= htmlspecialchars((string) ($data['s_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><br>
                <strong>Tax ID:</strong> <?= htmlspecialchars((string) ($data['s_tax'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> | <strong>โทร:</strong> <?= htmlspecialchars((string) ($data['s_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-5 text-end">
            <div class="po-section-kicker mb-1 text-end">วันที่ออกบิล</div>
            <div class="po-section-title text-dark text-end"><?= htmlspecialchars(tnc_po_format_date_thai($issueDate), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>

    <table class="table table-custom po-items-table mt-2">
        <thead>
            <?php if ($orderType === 'hire'): ?>
            <tr class="text-center">
                <th style="width:5%;" class="text-center po-th-num">#</th>
                <th style="width:20%;" class="text-start po-th-desc">รายการ</th>
                <th style="width:8%;" class="text-center po-th-num">จำนวน</th>
                <th style="width:7%;" class="text-center po-th-num">หน่วย</th>
                <th style="width:10%;" class="text-end po-th-num">ค่าวัสดุ</th>
                <th style="width:10%;" class="text-end po-th-num">ค่าแรง</th>
                <th style="width:10%;" class="text-end po-th-num po-th-price">ราคา/หน่วย</th>
                <th style="width:11%;" class="text-end po-th-num">ราคารวม</th>
            </tr>
            <?php else: ?>
            <tr class="text-center">
                <th style="width:38%;" class="text-start po-th-desc">รายละเอียดสินค้า / บริการ</th>
                <th style="width:10%;" class="text-center po-th-num">จำนวน</th>
                <th style="width:8%;" class="text-center po-th-num">หน่วย</th>
                <th style="width:11%;" class="text-end po-th-num po-th-price"><span class="text-nowrap">ราคา/หน่วย</span></th>
                <th style="width:11%;" class="text-end po-th-num">ส่วนลด</th>
                <th style="width:13%;" class="text-end po-th-num">ยอดรวม</th>
            </tr>
            <?php endif; ?>
        </thead>
        <tbody>
            <?php if (count($items) === 0): ?>
            <tr>
                <td colspan="<?= $orderType === 'hire' ? 8 : 6 ?>" class="text-center text-muted py-4">ไม่พบรายการสินค้าในใบสั่งซื้อนี้</td>
            </tr>
            <?php else: ?>
                <?php
                $poDisplayItems = $orderType === 'hire'
                    ? tnc_hire_lines_apply_display_numbers($items)
                    : $items;
                foreach ($poDisplayItems as $item):
                $isGroup = $orderType === 'hire' && tnc_hire_line_is_group($item);
                $displayNo = (string) ($item['display_no'] ?? '');
                $unitCell = trim((string) ($item['unit'] ?? ''));
                if ($orderType === 'hire' && !$isGroup) {
                    $parts = tnc_hire_item_material_labor($item);
                    $matPrice = $parts['material'];
                    $laborPrice = $parts['labor'];
                    $unitPrice = round($matPrice + $laborPrice, 2);
                    if ($unitPrice <= 0) {
                        $unitPrice = (float) ($item['unit_price'] ?? 0);
                    }
                }
                ?>
                <tr<?= $isGroup ? ' class="hire-print-group"' : '' ?>>
                    <?php if ($orderType === 'hire'): ?>
                    <td class="text-center po-td-num <?= $isGroup ? 'fw-bold' : 'text-muted' ?>"><?= htmlspecialchars($displayNo, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="<?= $isGroup ? 'fw-bold' : 'fw-bold text-dark' ?> text-start po-td-desc"><?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <?php if ($isGroup): ?>
                    <td class="text-center po-td-num text-muted">—</td>
                    <td class="text-center po-td-num text-muted">—</td>
                    <td class="text-end po-td-num text-muted">—</td>
                    <td class="text-end po-td-num text-muted">—</td>
                    <td class="text-end po-td-num text-muted">—</td>
                    <td class="text-end po-td-num text-muted">—</td>
                    <?php else: ?>
                    <td class="text-center po-td-num"><?= number_format((float) ($item['quantity'] ?? 0), 2); ?></td>
                    <td class="text-center po-td-num text-muted"><?= $unitCell !== '' ? htmlspecialchars($unitCell, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                    <td class="text-end po-td-num"><?= number_format($matPrice, 2); ?></td>
                    <td class="text-end po-td-num"><?= number_format($laborPrice, 2); ?></td>
                    <td class="text-end po-td-num"><?= number_format($unitPrice, 2); ?></td>
                    <td class="text-end fw-bold po-td-num"><?= number_format((float) ($item['total'] ?? 0), 2); ?></td>
                    <?php endif; ?>
                    <?php else: ?>
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
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="footer-sticky">
        <div class="row po-footer-row align-items-start mb-3">
            <div class="col-7 po-footer-notes-col">
                <div class="po-notes-wrap">
                    <?php if ($poNotePo !== ''): ?>
                        <?php tnc_po_render_note_panel($orderType === 'hire' ? 'หมายเหตุ' : 'หมายเหตุ PO', $poNotePo, $poNoteQt !== ''); ?>
                    <?php endif; ?>
                    <?php if ($poNoteQt !== ''): ?>
                        <?php tnc_po_render_note_panel($poNotePo !== '' ? 'หมายเหตุ / เงื่อนไข (QT)' : 'หมายเหตุ', $poNoteQt, false); ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-5 po-footer-totals-col">
                <div class="summary-box po-total-sheet">
                    <div class="summary-item">
                        <span><?= $orderType === 'hire' ? 'ยอดรวม (Subtotal)' : 'ยอดรายการ' ?></span>
                        <span><?= number_format((float) ($poVatPrint['line_amount'] ?? $po_subtotal), 2); ?></span>
                    </div>
                    <?php if ($orderType === 'hire' || ($po_vat_enabled && (float) ($poVatPrint['vat_amount'] ?? 0) > 0)): ?>
                    <div class="summary-item po-vat-line vat-print-line<?= $orderType === 'hire' ? ' text-primary' : '' ?>">
                        <span><?= $orderType === 'hire' ? 'VAT (+)' : htmlspecialchars((string) ($poVatPrint['vat_label'] ?? 'ภาษีมูลค่าเพิ่ม'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><?= $orderType === 'hire' ? '+ ' : '' ?><?= number_format((float) ($poVatPrint['vat_amount'] ?? 0), 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($hasDeductionsPrint): ?>
                    <div class="summary-item<?= $orderType === 'hire' ? ' border-bottom pb-2 mb-1' : '' ?>">
                        <span><?= $orderType === 'hire' ? 'ยอดรวม VAT' : 'ยอดก่อนหัก' ?></span>
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
