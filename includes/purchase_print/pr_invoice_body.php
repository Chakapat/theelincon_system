<?php

declare(strict_types=1);

/**
 * เนื้อหาใบ PR สำหรับพิมพ์ — โครงสร้างสอดคล้อง po_invoice_body.php
 *
 * @var array $pr
 * @var array $com
 * @var array<int, array<string, mixed>> $item_rows
 * @var string $requestType
 * @var string $contractorName
 * @var array $contractorPrint
 * @var float $contractValue
 * @var int $installmentTotal
 * @var string $hireScope
 * @var string $requesterDisplay
 * @var string $creatorDisplay
 * @var float $pv
 * @var float $pg
 * @var float $ps
 * @var bool $vatOn
 * @var string $vatMode
 * @var array{vat_mode: string, line_amount: float, vat_label: string, vat_amount: float, net_amount: float} $vatPrint
 * @var string $siteDisplay
 * @var string $createdRaw
 * @var array|null $existing_po
 * @var string $quotationAttach
 * @var string $quotationName
 * @var string $detailsText
 * @var bool $hireTableNote
 */
$isHireDoc = $requestType === 'hire';
$docDateDisplay = tnc_pr_format_date_thai($createdRaw !== '' ? $createdRaw : date('Y-m-d'));
$prNumberDisplay = trim((string) ($pr['pr_number'] ?? ''));
if ($prNumberDisplay === '') {
    $prNumberDisplay = 'PR-' . (int) ($pr['id'] ?? 0);
}
$prDocDateSubtitle = $prNumberDisplay . ' · ' . $docDateDisplay;
$requesterLine = trim($requesterDisplay !== '' ? $requesterDisplay : '-');
if ($isHireDoc && trim((string) ($contractorPrint['name_th'] ?? '')) === '' && trim($contractorName) !== '') {
    $contractorPrint['name_th'] = trim($contractorName);
}
$contractorIdentityLine = '';
$contractorPaymentLine = '';
if ($isHireDoc) {
    $contractorIdentityLine = trim((string) ($contractorPrint['identity_line'] ?? ''));
    if ($contractorIdentityLine === '' && trim($contractorName) !== '') {
        $contractorIdentityLine = trim($contractorName);
    }
    $contractorPaymentLine = trim((string) ($contractorPrint['transfer_line'] ?? ''));
}
$showHireInfoStack = $isHireDoc && ($siteDisplay !== '' || $contractorIdentityLine !== '' || $contractorPaymentLine !== '');
?>
<div class="invoice-box pr-purchase-requisition-doc">
    <?php if (!empty($isPoCancelled)): ?>
    <div class="pr-cancelled-watermark" aria-hidden="true">CANCELLED</div>
    <?php endif; ?>
    <div class="pr-doc-main">
        <div class="pr-doc-content">
        <div class="row align-items-start mb-2">
            <div class="col-6">
                <?php if (!empty($com['logo'])): ?>
                    <img src="<?= htmlspecialchars(upload_logo_url((string) $com['logo']), ENT_QUOTES, 'UTF-8') ?>" class="company-logo" alt="Logo">
                <?php endif; ?>
                <div class="fw-bold mt-2 pr-company-name"><?= htmlspecialchars((string) ($com['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="small text-muted pr-company-detail">
                    <?= htmlspecialchars((string) ($com['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?><br>
                    <span class="pr-company-phone">โทร: <?= htmlspecialchars((string) ($com['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span><br>
                    <span class="pr-company-tax">เลขประจำตัวผู้เสียภาษีอากร: <?= htmlspecialchars((string) ($com['tax_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
            <div class="col-6 text-end">
                <div class="invoice-title"><?= $isHireDoc ? 'REQUISITION (HIRE)' : 'PURCHASE REQUISITION' ?></div>
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

        <?php if (!$isHireDoc && $siteDisplay !== ''): ?>
        <div class="row mb-2 doc-site-row">
            <div class="col-12">
                <div class="doc-site-block">
                    <span class="doc-site-label">ไซต์งาน:</span>
                    <span class="doc-site-value"><?= htmlspecialchars($siteDisplay, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$isHireDoc): ?>
        <div class="row mb-2 doc-site-row">
            <div class="col-12">
                <div class="doc-site-block">
                    <span class="doc-site-label">ผู้ขอซื้อ / ผู้รับผิดชอบ:</span>
                    <span class="doc-site-value"><?= htmlspecialchars($requesterLine, ENT_QUOTES, 'UTF-8') ?></span>
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

        <?php if ($showHireInfoStack): ?>
        <div class="row mb-2 pr-hire-info-row-wrap">
            <div class="col-12">
                <div class="pr-hire-info-stack">
                    <?php if ($siteDisplay !== ''): ?>
                    <div class="pr-hire-info-box">
                        <span class="pr-hire-info-label">สถานที่:</span>
                        <span class="pr-hire-info-value"><?= htmlspecialchars($siteDisplay, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($contractorIdentityLine !== ''): ?>
                    <div class="pr-hire-info-box">
                        <span class="pr-hire-info-label">ผู้รับจ้าง:</span>
                        <span class="pr-hire-info-value"><?= htmlspecialchars($contractorIdentityLine, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($contractorPaymentLine !== ''): ?>
                    <div class="pr-hire-info-box">
                        <span class="pr-hire-info-label">ช่องทางการชำระ:</span>
                        <span class="pr-hire-info-value"><?= htmlspecialchars($contractorPaymentLine, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($detailsText !== '' && !$isHireDoc): ?>
        <div class="row mb-2">
            <div class="col-12">
                <div class="pr-notes-panel">
                    <div class="pr-note-heading">รายละเอียด / วัตถุประสงค์</div>
                    <div class="pr-note-body"><?= htmlspecialchars($detailsText, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <table class="table table-custom pr-items-table mt-2">
            <thead>
                <?php if ($isHireDoc): ?>
                <tr class="text-center">
                    <th style="width:5%;" class="text-center pr-th-num">#</th>
                    <th style="width:20%;" class="text-start pr-th-desc">รายการ</th>
                    <th style="width:8%;" class="text-center pr-th-num">จำนวน</th>
                    <th style="width:7%;" class="text-center pr-th-num">หน่วย</th>
                    <th style="width:10%;" class="text-end pr-th-num">ค่าวัสดุ</th>
                    <th style="width:10%;" class="text-end pr-th-num">ค่าแรง</th>
                    <th style="width:10%;" class="text-end pr-th-num pr-th-price">ราคา/หน่วย</th>
                    <th style="width:11%;" class="text-end pr-th-num">ราคารวม</th>
                </tr>
                <?php else: ?>
                <tr class="text-center">
                    <th style="width:38%;" class="text-start pr-th-desc">รายละเอียดสินค้า / บริการ</th>
                    <th style="width:10%;" class="text-center pr-th-num">จำนวน</th>
                    <th style="width:8%;" class="text-center pr-th-num">หน่วย</th>
                    <th style="width:11%;" class="text-end pr-th-num pr-th-price"><span class="text-nowrap">ราคา/หน่วย</span></th>
                    <th style="width:11%;" class="text-end pr-th-num">ส่วนลด</th>
                    <th style="width:13%;" class="text-end pr-th-num">ยอดรวม</th>
                </tr>
                <?php endif; ?>
            </thead>
            <tbody>
                <?php if ($hireTableNote): ?>
                <tr>
                    <?php if ($isHireDoc): ?>
                    <td class="text-center pr-td-num text-muted">1</td>
                    <td class="text-start fw-semibold pr-td-desc" style="white-space: pre-line;"><?= htmlspecialchars($hireScope, ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-center pr-td-num">1</td>
                    <td class="text-center pr-td-num text-muted">—</td>
                    <td class="text-end pr-td-num text-muted">—</td>
                    <td class="text-end pr-td-num text-muted">—</td>
                    <td class="text-end pr-td-num"><?= number_format($contractValue, 2) ?></td>
                    <td class="text-end fw-bold pr-td-num"><?= number_format($contractValue, 2) ?></td>
                    <?php else: ?>
                    <td class="fw-bold text-dark text-start pr-td-desc" style="white-space: pre-line;"><?= htmlspecialchars($hireScope, ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-center pr-td-num">1</td>
                    <td class="text-center pr-td-num text-muted">—</td>
                    <td class="text-end pr-td-num"><?= number_format($contractValue, 2) ?></td>
                    <td class="text-end pr-td-num text-muted">—</td>
                    <td class="text-end fw-bold pr-td-num"><?= number_format($contractValue, 2) ?></td>
                    <?php endif; ?>
                </tr>
                <?php elseif (count($item_rows) === 0): ?>
                <tr>
                    <td colspan="<?= $isHireDoc ? 8 : 6 ?>" class="text-center text-muted py-4"><?= $isHireDoc ? 'ไม่มีรายการบรรทัด (งานจัดจ้างอาจสรุปเป็นยอดเดียว)' : 'ไม่พบรายการสินค้า / บริการ' ?></td>
                </tr>
                <?php else: ?>
                    <?php
                    $hireDisplayRows = $isHireDoc
                        ? tnc_hire_lines_apply_display_numbers($item_rows)
                        : $item_rows;
                    $i = 1;
                    foreach ($hireDisplayRows as $item):
                    $isGroup = $isHireDoc && tnc_hire_line_is_group($item);
                    $displayNo = (string) ($item['display_no'] ?? (string) $i);
                    $unitCell = trim((string) ($item['unit'] ?? ''));
                    if ($isHireDoc && !$isGroup) {
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
                        <?php if ($isHireDoc): ?>
                        <td class="text-center pr-td-num <?= $isGroup ? 'fw-bold' : 'text-muted' ?>"><?= htmlspecialchars($displayNo, ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="<?= $isGroup ? 'fw-bold' : 'fw-bold text-dark' ?> text-start pr-td-desc"><?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <?php if ($isGroup): ?>
                        <td class="text-center pr-td-num text-muted">—</td>
                        <td class="text-center pr-td-num text-muted">—</td>
                        <td class="text-end pr-td-num text-muted">—</td>
                        <td class="text-end pr-td-num text-muted">—</td>
                        <td class="text-end pr-td-num text-muted">—</td>
                        <td class="text-end pr-td-num text-muted">—</td>
                        <?php else: ?>
                        <td class="text-center pr-td-num"><?= number_format((float) ($item['quantity'] ?? 0), 2) ?></td>
                        <td class="text-center pr-td-num text-muted"><?= $unitCell !== '' ? htmlspecialchars($unitCell, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                        <td class="text-end pr-td-num"><?= number_format($matPrice, 2) ?></td>
                        <td class="text-end pr-td-num"><?= number_format($laborPrice, 2) ?></td>
                        <td class="text-end pr-td-num"><?= number_format($unitPrice, 2) ?></td>
                        <td class="text-end fw-bold pr-td-num"><?= number_format((float) ($item['total'] ?? 0), 2) ?></td>
                        <?php endif; ?>
                        <?php else: ?>
                        <td class="fw-bold text-dark text-start pr-td-desc"><?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
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
                        <?php endif; ?>
                    </tr>
                    <?php $i++; endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div><!-- /.pr-doc-content -->

    <div class="footer-sticky doc-footer">
        <div class="row pr-footer-row align-items-start mb-3">
            <div class="col-7 pr-footer-notes-col">
                <div class="pr-notes-wrap">
                    <?php if ($isHireDoc && $hireScope !== '' && !$hireTableNote): ?>
                    <div class="pr-notes-panel">
                        <div class="pr-note-heading">เงื่อนไขการชำระเงิน / ขอบเขตการทำงาน</div>
                        <div class="pr-note-body"><?= htmlspecialchars($hireScope, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <?php elseif ($isHireDoc && $detailsText !== ''): ?>
                    <div class="pr-notes-panel">
                        <div class="pr-note-heading">รายละเอียด / วัตถุประสงค์</div>
                        <div class="pr-note-body"><?= htmlspecialchars($detailsText, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-5 pr-footer-totals-col">
                <div class="summary-box pr-total-sheet">
                    <?php if ($isHireDoc): ?>
                    <?php
                    $hireDirect = (float) ($pr['hire_direct_subtotal'] ?? 0);
                    if ($hireDirect <= 0 && count($item_rows) > 0) {
                        foreach ($item_rows as $ir) {
                            if (tnc_hire_line_is_group($ir)) {
                                continue;
                            }
                            $hireDirect += (float) ($ir['total'] ?? 0);
                        }
                        $hireDirect = round($hireDirect, 2);
                    }
                    $ohPct = (float) ($pr['overhead_percent'] ?? 0);
                    $prePct = (float) ($pr['preliminary_percent'] ?? 0);
                    $ohAmt = (float) ($pr['overhead_amount'] ?? 0);
                    $preAmt = (float) ($pr['preliminary_amount'] ?? 0);
                    if ($ohAmt <= 0 && $ohPct > 0 && $hireDirect > 0) {
                        $ohAmt = round($hireDirect * $ohPct / 100, 2);
                    }
                    if ($preAmt <= 0 && $prePct > 0 && $hireDirect > 0) {
                        $preAmt = round($hireDirect * $prePct / 100, 2);
                    }
                    $excludedVat = (float) ($vatPrint['line_amount'] ?? $ps);
                    ?>
                    <div class="summary-item">
                        <span>ยอดรายการ</span>
                        <span><?= number_format($hireDirect, 2) ?></span>
                    </div>
                    <?php if ($ohPct > 0 || $ohAmt > 0): ?>
                    <div class="summary-item text-muted">
                        <span>Overhead cost (<?= rtrim(rtrim(number_format($ohPct, 2), '0'), '.') ?>%)</span>
                        <span>+ <?= number_format($ohAmt, 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($prePct > 0 || $preAmt > 0): ?>
                    <div class="summary-item text-muted">
                        <span>Preliminary cost (<?= rtrim(rtrim(number_format($prePct, 2), '0'), '.') ?>%)</span>
                        <span>+ <?= number_format($preAmt, 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="summary-item">
                        <span>ราคารวมทั้งหมด (Excluded VAT)</span>
                        <span><?= number_format($excludedVat, 2) ?></span>
                    </div>
                    <?php else: ?>
                    <div class="summary-item">
                        <span>ยอดรายการ</span>
                        <span><?= number_format((float) ($vatPrint['line_amount'] ?? $ps), 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($vatOn && (float) ($vatPrint['vat_amount'] ?? 0) > 0): ?>
                    <div class="summary-item pr-vat-line vat-print-line<?= $isHireDoc ? ' text-success' : '' ?>">
                        <span><?= $isHireDoc ? 'VAT 7%' : htmlspecialchars((string) ($vatPrint['vat_label'] ?? 'ภาษีมูลค่าเพิ่ม'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= $isHireDoc ? '+ ' : '' ?><?= number_format((float) $vatPrint['vat_amount'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="grand-total-row" role="group" aria-label="<?= $isHireDoc ? 'Grand Total' : 'ยอดสุทธิ' ?>">
                        <span class="fw-bold" style="font-size: 14px;"><?= $isHireDoc ? 'Grand Total' : 'ยอดสุทธิ' ?></span>
                        <span style="font-size: 18px; font-weight: 800;">฿ <?= number_format((float) ($vatPrint['net_amount'] ?? $pg), 2) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="signature-grid">
            <div>
                <div class="sig-space"></div>
                <div class="sig-box"><?= $isHireDoc ? 'ผู้ขอ / ผู้รับผิดชอบ' : 'ผู้ขอซื้อ / ผู้รับผิดชอบ' ?><br><small>(Requester Signature)</small></div>
            </div>
            <div>
                <div class="sig-space"></div>
                <div class="sig-box">ผู้มีอำนาจลงนาม<br><small>(Authorized Signature)</small></div>
            </div>
        </div>
    </div>
    </div>
</div>
