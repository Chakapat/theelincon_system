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
 * @var string $retentionLabel
 * @var list<array{label:string,sign:string,value_type:string,amount:float}> $poAdjustments
 * @var bool $hasAdjustmentsPrint
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
$tncCompanyLogoUrl = tnc_company_logo_url($data['logo'] ?? '');
if (!function_exists('tnc_purchase_quotation_info')) {
    require_once dirname(__DIR__) . '/purchase_quotation_attachment.php';
}
$poQtInfoHeader = tnc_purchase_quotation_info($data, !empty($data['quotation_attachment_from_pr']));
$poAdjPrintCount = 0;
if (!empty($hasAdjustmentsPrint) && is_array($poAdjustments ?? null)) {
    foreach ($poAdjustments as $poAdjRow) {
        if (is_array($poAdjRow) && (float) ($poAdjRow['amount'] ?? 0) > 0.0) {
            $poAdjPrintCount++;
        }
    }
}
$itemPageChunks = tnc_doc_paginate_items($items, [
    'doc' => 'po',
    'first_page_overhead_mm' => tnc_doc_po_first_page_overhead_mm([
        'has_logo' => $tncCompanyLogoUrl !== '',
        'has_site' => $poSiteDisplay !== '' || trim((string) ($poCostCategoryName ?? '')) !== '',
        'has_supplier_address' => trim((string) ($data['s_address'] ?? '')) !== '' || trim((string) ($data['s_tax'] ?? '')) !== '',
        'has_reference_pr' => $referencePrNumber !== '',
        'has_qt_header' => trim((string) ($data['quotation_number'] ?? '')) !== '' || !empty($poQtInfoHeader['has']),
    ]),
    'footer_mm' => tnc_doc_po_footer_height_mm([
        'po_note_po' => $poNotePo,
        'po_note_qt' => $poNoteQt,
        'has_deductions' => $hasDeductionsPrint,
        'has_wht' => $hasWhtPrint,
        'has_retention' => !empty($hasRetentionPrint),
        'adjustment_count' => $poAdjPrintCount,
    ]),
]);
$totalDocPages = count($itemPageChunks);
$isMultiPageDoc = $totalDocPages > 1;

if ($isMultiPageDoc): ?>
<div class="tnc-doc-pages-wrap tnc-doc-pages-wrap--po">
<?php endif; ?>

<?php foreach ($itemPageChunks as $pageIdx => $pageItems):
    $pageNum = $pageIdx + 1;
    $isFirstPage = ($pageNum === 1);
    $isLastPage = ($pageNum === $totalDocPages);
    $sheetClass = 'invoice-box po-purchase-order-doc';
    if ($isMultiPageDoc) {
        $sheetClass .= ' tnc-doc-sheet' . ($isLastPage ? ' tnc-doc-sheet--last' : '');
    }
    $pageIndicatorLabel = tnc_doc_page_indicator_label($pageNum, $totalDocPages);
    ?>
<div class="<?= $sheetClass ?>">
    <?php if ($isPoCancelled): ?>
    <div class="po-cancelled-watermark" aria-hidden="true">ยกเบิกใบสั่งซื้อ</div>
    <?php endif; ?>
    <div class="po-doc-main">
    <div class="po-doc-content">
    <?php if ($isFirstPage): ?>
    <div class="row align-items-start mb-2 tnc-doc-header tnc-doc-header--full">
        <div class="col-6">
            <?php if ($tncCompanyLogoUrl !== ''): ?>
                <img src="<?= htmlspecialchars($tncCompanyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" class="company-logo" alt="Logo">
            <?php endif; ?>
            <div class="fw-bold mt-2 po-company-name"><?= htmlspecialchars((string) ($data['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="small text-muted po-company-detail doc-company-meta">
                <?php
                require_once __DIR__ . '/company_detail.php';
                echo tnc_doc_company_detail_html($data, 'เลขผู้เสียภาษี');
                ?>
            </div>
        </div>
        <div class="col-6 text-end">
            <div class="invoice-title">PURCHASE ORDER</div>
            <div class="fw-bold text-muted small"><?= htmlspecialchars($poDocDateSubtitle, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php
            echo tnc_purchase_quotation_doc_header_html(
                $poQtInfoHeader,
                trim((string) ($data['quotation_number'] ?? ''))
            );
            ?>
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
            <?php if (trim((string) ($data['s_address'] ?? '')) !== '' || trim((string) ($data['s_tax'] ?? '')) !== ''): ?>
            <div class="doc-site-block mt-2">
                <span class="doc-site-label">ที่อยู่ / ติดต่อ:</span>
                <span class="doc-site-value">
                    <?= htmlspecialchars((string) ($data['s_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    <?php if (trim((string) ($data['s_tax'] ?? '')) !== ''): ?>
                    | เลขผู้เสียภาษี: <?= htmlspecialchars((string) ($data['s_tax'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <table class="table table-custom po-items-table<?= $isFirstPage ? ' mt-2' : ' mt-0' ?>">
        <thead>
            <tr>
                <th style="width:38%;" class="text-start po-th-desc">รายละเอียดสินค้า / บริการ</th>
                <th style="width:10%;" class="text-end po-th-num">จำนวน</th>
                <th style="width:8%;" class="text-center po-th-unit">หน่วย</th>
                <th style="width:11%;" class="text-end po-th-num po-th-price"><span class="text-nowrap">ราคา/หน่วย</span></th>
                <th style="width:11%;" class="text-end po-th-num">ส่วนลด</th>
                <th style="width:13%;" class="text-end po-th-num">ยอดรวม</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($pageItems) === 0): ?>
            <tr>
                <td colspan="<?= $poTableColCount ?>" class="text-center text-muted py-4">ไม่พบรายการสินค้าในใบสั่งซื้อนี้</td>
            </tr>
            <?php else: ?>
                <?php foreach ($pageItems as $item):
                $unitCell = trim((string) ($item['unit'] ?? ''));
                ?>
                <tr>
                    <td class="fw-bold text-dark text-start"><?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><?php if ((int) ($item['vat_exempt'] ?? 0) === 1): ?><span class="text-muted fw-normal small"> (ไม่คิด VAT)</span><?php endif; ?></td>
                    <td class="text-end po-td-num"><?= number_format((float) ($item['quantity'] ?? 0), 2); ?></td>
                    <td class="text-center po-td-unit text-muted"><?= $unitCell !== '' ? htmlspecialchars($unitCell, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
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

    <?php if ($isLastPage): ?>
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
                    $poVatModeResolved = in_array((string) ($poVatMode ?? ''), ['inclusive', 'exclusive'], true)
                        ? (string) $poVatMode
                        : (in_array((string) ($poVatPrint['vat_mode'] ?? ''), ['inclusive', 'exclusive'], true)
                            ? (string) $poVatPrint['vat_mode']
                            : 'exclusive');
                    $poVatDisplayLabel = tnc_purchase_vat_label_for_print(
                        $poVatModeResolved,
                        (string) ($poVatPrint['vat_label'] ?? '')
                    );
                    ?>
                    <div class="summary-item po-vat-line vat-print-line">
                        <span><?= htmlspecialchars($poVatDisplayLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><?= number_format((float) ($poVatPrint['vat_amount'] ?? 0), 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($hasDeductionsPrint): ?>
                    <div class="summary-item">
                        <span>ยอดรวมภาษี</span>
                        <span><?= number_format($po_gross_amount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($hasWhtPrint): ?>
                    <div class="summary-item text-danger">
                        <span>หัก ณ ที่จ่าย 3%</span>
                        <span>-<?= number_format($withholdingAmount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($hasAdjustmentsPrint) && is_array($poAdjustments ?? null)): ?>
                    <?php foreach ($poAdjustments as $poAdj): ?>
                    <?php
                    if (!is_array($poAdj) || (float) ($poAdj['amount'] ?? 0) <= 0.0) {
                        continue;
                    }
                    $adjSign = (($poAdj['sign'] ?? 'subtract') === 'add') ? 'add' : 'subtract';
                    $adjLabel = trim((string) ($poAdj['label'] ?? tnc_po_retention_label_default()));
                    $adjAmount = (float) ($poAdj['amount'] ?? 0);
                    ?>
                    <div class="summary-item <?= $adjSign === 'add' ? 'text-success' : 'text-danger' ?>">
                        <span><?= htmlspecialchars($adjLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= $adjSign === 'add' ? '+' : '-' ?><?= number_format($adjAmount, 2); ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php elseif (!empty($hasRetentionPrint)): ?>
                    <div class="summary-item text-danger">
                        <span><?= htmlspecialchars($retentionLabel ?? tnc_po_retention_label_default(), ENT_QUOTES, 'UTF-8') ?></span>
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
        </div>
    </div>
    <?php endif; ?>

    <?php if ($pageIndicatorLabel !== ''): ?>
    <div class="tnc-doc-page-indicator" aria-label="หมายเลขหน้า"><?= htmlspecialchars($pageIndicatorLabel, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if ($isMultiPageDoc): ?>
</div>
<?php endif; ?>
