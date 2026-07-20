<?php

declare(strict_types=1);

/**
 * เนื้อหาใบ PR สำหรับพิมพ์ — โครงสร้างสอดคล้อง po_invoice_body.php
 *
 * @var array $pr
 * @var array $com
 * @var array<int, array<string, mixed>> $item_rows
 * @var string $requesterDisplay
 * @var string $creatorDisplay
 * @var float $pv
 * @var float $pg
 * @var float $ps
 * @var bool $vatOn
 * @var string $vatMode
 * @var array{vat_mode: string, line_amount: float, vat_label: string, vat_amount: float, net_amount: float} $vatPrint
 * @var string $siteDisplay
 * @var string $prCostCategoryName
 * @var string $createdRaw
 * @var array|null $existing_po
 * @var string $quotationAttach
 * @var string $quotationName
 * @var string $detailsText
 */
$docDateDisplay = tnc_pr_format_date_thai($createdRaw !== '' ? $createdRaw : date('Y-m-d'));
$prNumberDisplay = trim((string) ($pr['pr_number'] ?? ''));
if ($prNumberDisplay === '') {
    $prNumberDisplay = 'PR-' . (int) ($pr['id'] ?? 0);
}
$prDocDateSubtitle = $prNumberDisplay . ' · ' . $docDateDisplay;
$requesterLine = trim($requesterDisplay !== '' ? $requesterDisplay : '-');
$prFooterHasNotes = $detailsText !== '';
$itemPageChunks = tnc_doc_paginate_items($item_rows);
$totalDocPages = count($itemPageChunks);
$isMultiPageDoc = $totalDocPages > 1;

if ($isMultiPageDoc): ?>
<div class="tnc-doc-pages-wrap tnc-doc-pages-wrap--pr">
<?php endif; ?>

<?php foreach ($itemPageChunks as $pageIdx => $pageItems):
    $pageNum = $pageIdx + 1;
    $isFirstPage = ($pageNum === 1);
    $isLastPage = ($pageNum === $totalDocPages);
    $sheetClass = 'invoice-box pr-purchase-requisition-doc';
    if ($isMultiPageDoc) {
        $sheetClass .= ' tnc-doc-sheet' . ($isLastPage ? ' tnc-doc-sheet--last' : '');
    }
    $pageIndicatorLabel = tnc_doc_page_indicator_label($pageNum, $totalDocPages);
    ?>
<div class="<?= $sheetClass ?>">
    <?php if (!empty($isPrCancelled)): ?>
    <div class="pr-cancelled-watermark" aria-hidden="true">ยกเลิกใบขอซื้อ</div>
    <?php elseif (!empty($isPoCancelled)): ?>
    <div class="pr-cancelled-watermark" aria-hidden="true">CANCELLED</div>
    <?php elseif (isset($prApprovalStatus) && in_array($prApprovalStatus, ['approved', 'ready', 'rejected'], true)):
        $prApprovalWmClass = ($prApprovalStatus === 'rejected') ? 'rejected' : 'approved';
        $prApprovalWmLabel = trim((string) ($prApprovalLabel ?? ''));
        if ($prApprovalWmLabel === '') {
            $prApprovalWmLabel = ($prApprovalWmClass === 'rejected') ? 'ไม่อนุมัติ' : 'อนุมัติแล้ว';
        }
        ?>
    <div class="pr-approval-watermark pr-approval-watermark--<?= htmlspecialchars($prApprovalWmClass, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"><?= htmlspecialchars($prApprovalWmLabel, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <div class="pr-doc-main">
        <div class="pr-doc-content">
        <?php if ($isFirstPage): ?>
        <div class="row align-items-start mb-2 tnc-doc-header tnc-doc-header--full">
            <div class="col-6">
                <?php $tncCompanyLogoUrl = tnc_company_logo_url($com['logo'] ?? ''); ?>
                <?php if ($tncCompanyLogoUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($tncCompanyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" class="company-logo" alt="Logo">
                <?php endif; ?>
                <div class="fw-bold mt-2 pr-company-name"><?= htmlspecialchars((string) ($com['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="small text-muted pr-company-detail doc-company-meta">
                    <?php
                    require_once __DIR__ . '/company_detail.php';
                    echo tnc_doc_company_detail_html($com, 'เลขประจำตัวผู้เสียภาษีอากร');
                    ?>
                </div>
            </div>
            <div class="col-6 text-end">
                <div class="invoice-title">PURCHASE REQUISITION</div>
                <div class="fw-bold text-muted small"><?= htmlspecialchars($prDocDateSubtitle, ENT_QUOTES, 'UTF-8') ?></div>
                <?php
                if (!function_exists('tnc_purchase_quotation_info')) {
                    require_once dirname(__DIR__) . '/purchase_quotation_attachment.php';
                }
                echo tnc_purchase_quotation_doc_header_html(
                    tnc_purchase_quotation_info([
                        'quotation_attachment_path' => $quotationAttach,
                        'quotation_attachment_name' => $quotationName,
                    ])
                );
                ?>
            </div>
        </div>

        <div class="row mb-2 doc-site-row">
            <div class="col-12">
                <div class="doc-site-block doc-site-block--pr-triple">
                    <div class="doc-site-seg doc-site-seg--place">
                        <span class="doc-site-label">สถานที่:</span>
                        <span class="doc-site-value"><?= htmlspecialchars($siteDisplay !== '' ? $siteDisplay : '—', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="doc-site-seg doc-site-seg--cat">
                        <span class="doc-site-label">หมวดหมู่:</span>
                        <span class="doc-site-value"><?= htmlspecialchars(trim((string) ($prCostCategoryName ?? '')) !== '' ? trim((string) $prCostCategoryName) : '—', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="doc-site-seg doc-site-seg--requester">
                        <span class="doc-site-label">ผู้ขอ:</span>
                        <span class="doc-site-value"><?= htmlspecialchars($requesterLine, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
                <?php if ($creatorDisplay !== '' && $creatorDisplay !== $requesterDisplay): ?>
                <div class="doc-site-block mt-2">
                    <span class="doc-site-label">ผู้บันทึกในระบบ:</span>
                    <span class="doc-site-value"><?= htmlspecialchars($creatorDisplay, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <table class="table table-custom pr-items-table<?= $isFirstPage ? ' mt-2' : ' mt-0' ?>">
            <thead>
                <tr class="text-center">
                    <th style="width:38%;" class="text-start pr-th-desc">รายละเอียดสินค้า / บริการ</th>
                    <th style="width:10%;" class="text-center pr-th-num">จำนวน</th>
                    <th style="width:8%;" class="text-center pr-th-num">หน่วย</th>
                    <th style="width:11%;" class="text-end pr-th-num pr-th-price"><span class="text-nowrap">ราคา/หน่วย</span></th>
                    <th style="width:11%;" class="text-end pr-th-num">ส่วนลด</th>
                    <th style="width:13%;" class="text-end pr-th-num">ยอดรวม</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($pageItems) === 0): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">ไม่พบรายการสินค้า / บริการ</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($pageItems as $item):
                    $unitCell = trim((string) ($item['unit'] ?? ''));
                    ?>
                    <tr>
                        <td class="fw-bold text-dark text-start pr-td-desc"><?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?php if ((int) ($item['vat_exempt'] ?? 0) === 1): ?><span class="text-muted fw-normal small"> (ไม่คิด VAT)</span><?php endif; ?></td>
                        <td class="text-center pr-td-num"><?= number_format((float) ($item['quantity'] ?? 0), 2) ?></td>
                        <td class="text-center pr-td-num text-muted"><?= $unitCell !== '' ? htmlspecialchars($unitCell, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                        <td class="text-end pr-td-num"><?= number_format((float) ($item['unit_price'] ?? 0), 2) ?></td>
                        <td class="text-end text-muted small pr-td-num"><?php
                            $dIn = trim((string) ($item['discount_input'] ?? ''));
                            $dAmt = (float) ($item['discount_amount'] ?? 0);
                            if ($dIn !== '') {
                                echo htmlspecialchars($dIn, ENT_QUOTES, 'UTF-8');
                            } elseif ($dAmt > 0) {
                                echo htmlspecialchars(number_format($dAmt, 2), ENT_QUOTES, 'UTF-8');
                            } else {
                                echo '—';
                            }
                        ?></td>
                        <td class="text-end fw-bold pr-td-num"><?= number_format((float) ($item['total'] ?? 0), 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div><!-- /.pr-doc-content -->

    <?php if ($isLastPage): ?>
    <div class="footer-sticky doc-footer">
        <div class="row pr-footer-row align-items-start mb-3<?= $prFooterHasNotes ? ' pr-footer-row--has-notes' : '' ?>">
            <div class="col-7 pr-footer-notes-col">
                <div class="pr-notes-wrap">
                    <?php if ($detailsText !== ''): ?>
                    <div class="pr-notes-panel">
                        <div class="pr-note-heading">รายละเอียด / วัตถุประสงค์</div>
                        <div class="pr-note-body"><?= htmlspecialchars($detailsText, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-5 pr-footer-totals-col">
                <div class="summary-box pr-total-sheet">
                    <div class="summary-item">
                        <span>ยอดรายการ</span>
                        <span><?= number_format((float) ($vatPrint['line_amount'] ?? $ps), 2) ?></span>
                    </div>
                    <?php if ($vatOn && (float) ($vatPrint['vat_amount'] ?? 0) > 0): ?>
                    <div class="summary-item pr-vat-line vat-print-line">
                        <span><?= htmlspecialchars(tnc_purchase_vat_label_for_print(
                            in_array((string) ($vatMode ?? ''), ['inclusive', 'exclusive'], true)
                                ? (string) $vatMode
                                : (string) ($vatPrint['vat_mode'] ?? 'exclusive'),
                            (string) ($vatPrint['vat_label'] ?? '')
                        ), ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= number_format((float) $vatPrint['vat_amount'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="grand-total-row" role="group" aria-label="ยอดสุทธิ">
                        <span class="fw-bold" style="font-size: 14px;">ยอดสุทธิ</span>
                        <span style="font-size: 18px; font-weight: 800;">฿ <?= number_format((float) ($vatPrint['net_amount'] ?? $pg), 2) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="signature-grid">
            <div>
                <div class="sig-space"></div>
                <div class="sig-box">ผู้ขอซื้อ / ผู้รับผิดชอบ<br><small>(Requester Signature)</small></div>
            </div>
            <div>
                <div class="sig-space"></div>
                <div class="sig-box">ผู้มีอำนาจลงนาม<br><small>(Authorized Signature)</small></div>
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
