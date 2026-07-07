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
?>
<div class="invoice-box pr-purchase-requisition-doc">
    <?php if (!empty($isPoCancelled)): ?>
    <div class="pr-cancelled-watermark" aria-hidden="true">CANCELLED</div>
    <?php endif; ?>
    <div class="pr-doc-main">
        <div class="pr-doc-content">
        <div class="row align-items-start mb-2">
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
                <?php if ($quotationAttach !== ''): ?>
                    <?php $attachLabel = $quotationName !== '' ? $quotationName : 'เปิดไฟล์'; ?>
                    <div class="small text-muted">ไฟล์ใบเสนอราคา:
                        <a href="<?= htmlspecialchars(app_path($quotationAttach), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="no-print"><?= htmlspecialchars($attachLabel, ENT_QUOTES, 'UTF-8') ?></a>
                        <span class="d-none d-print-inline"><?= htmlspecialchars($attachLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>
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

        <table class="table table-custom pr-items-table mt-2">
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
                <?php if (count($item_rows) === 0): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">ไม่พบรายการสินค้า / บริการ</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($item_rows as $item):
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
                        <span><?= htmlspecialchars((string) ($vatPrint['vat_label'] ?? 'แยก VAT'), ENT_QUOTES, 'UTF-8') ?></span>
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
    </div>
</div>
