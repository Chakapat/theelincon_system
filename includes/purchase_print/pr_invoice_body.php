<?php

declare(strict_types=1);

/**
 * เนื้อหาใบ PR สำหรับพิมพ์ — ตัวแปรมาจาก tnc_purchase_pr_print_prepare() / extract ใน tnc_purchase_pr_print_render()
 *
 * @var array $pr
 * @var array $com
 * @var array<int, array<string, mixed>> $item_rows
 * @var string $requestType
 * @var string $contractorName
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
 * @var string $quotationAttach
 * @var string $quotationName
 * @var string $detailsText
 * @var bool $hireTableNote
 */
?>
<div class="invoice-box pr-purchase-requisition-doc">
    <?php if (!empty($isPoCancelled)): ?>
    <div class="pr-cancelled-watermark" aria-hidden="true">CANCELLED</div>
    <?php endif; ?>
    <div class="pr-doc-main">
    <div class="row align-items-start pr-doc-header g-0">
        <div class="col-6 pr-doc-header-left">
            <?php if (!empty($com['logo'])): ?>
                <img src="<?= htmlspecialchars(upload_logo_url((string) $com['logo']), ENT_QUOTES, 'UTF-8') ?>" class="company-logo" alt="Logo">
            <?php endif; ?>
            <div class="pr-company-name"><?= htmlspecialchars((string) ($com['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="pr-company-detail">
                <?= htmlspecialchars((string) ($com['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?><br>
                โทร: <?= htmlspecialchars((string) ($com['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?> | Tax ID: <?= htmlspecialchars((string) ($com['tax_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
        <div class="col-6 pr-doc-header-right">
            <div class="invoice-title"><?= $requestType === 'hire' ? 'REQUISITION (HIRE)' : 'PURCHASE REQUISITION' ?></div>
            <div class="pr-doc-subtitle"><?= $requestType === 'hire' ? 'ใบขอจ้าง / จัดจ้าง' : 'ใบขอซื้อ (PR)' ?></div>
            <div class="pr-doc-number">เลขที่: <?= htmlspecialchars((string) ($pr['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($quotationAttach !== ''): ?>
                <?php
                $attachLabel = $quotationName !== '' ? $quotationName : 'เปิดไฟล์แนบ';
                ?>
                <div class="small text-muted mt-2">แนบใบเสนอราคา:
                    <a href="<?= htmlspecialchars(app_path($quotationAttach), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="no-print"><?= htmlspecialchars($attachLabel, ENT_QUOTES, 'UTF-8') ?></a>
                    <span class="d-none d-print-inline"><?= htmlspecialchars($attachLabel, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row mb-2 mt-3 doc-meta-row">
        <div class="col-7 border-start border-4 border-success ps-3">
            <div style="font-size: 10px; color: var(--brand-color); font-weight: bold; text-transform: uppercase;">ผู้ขอซื้อ / ผู้รับผิดชอบ</div>
            <div class="fw-bold" style="font-size: 15px;"><?= htmlspecialchars($requesterDisplay !== '' ? $requesterDisplay : '-', ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($creatorDisplay !== '' && $creatorDisplay !== $requesterDisplay): ?>
                <div class="small text-muted">ผู้บันทึกในระบบ: <?= htmlspecialchars($creatorDisplay, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
        <div class="col-5 text-end doc-meta-date-col">
            <div style="font-size: 10px; color: var(--brand-color); font-weight: bold; text-transform: uppercase;">วันที่เอกสาร</div>
            <div class="fw-bold" style="font-size: 15px;"><?= htmlspecialchars(tnc_pr_format_date_thai($createdRaw !== '' ? $createdRaw : date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($requestType === 'hire'): ?>
                <div class="small text-muted mt-1">ประเภท: จัดจ้าง</div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($siteDisplay !== ''): ?>
    <div class="row mb-2 doc-site-row">
        <div class="col-12">
            <div class="doc-site-block">
                <span class="doc-site-label">สถานที่:</span>
                <span class="doc-site-value"><?= htmlspecialchars($siteDisplay, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($requestType === 'hire'): ?>
    <div class="row mb-3">
        <div class="col-12 pr-contractor-card">
            <?php
            $contractorPrintLayout = 'panel';
            include __DIR__ . '/contractor_print_block.php';
            ?>
            <div class="pr-contractor-meta">
                <strong>มูลค่าสัญญา:</strong> <?= number_format($contractValue, 2) ?> บาท
                <span class="pr-contractor-meta-sep">|</span>
                <strong>จำนวนงวด:</strong> <?= number_format($installmentTotal) ?> งวด
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($detailsText !== ''): ?>
    <div class="mb-3 p-2 border rounded-2" style="font-size: 12px;">
        <div class="fw-bold text-secondary text-uppercase mb-1" style="font-size: 10px;">รายละเอียด / วัตถุประสงค์</div>
        <div style="white-space: pre-line;"><?= htmlspecialchars($detailsText, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php endif; ?>

    <table class="table table-custom">
        <thead>
            <?php if ($requestType === 'hire'): ?>
            <tr class="text-center">
                <th style="width:5%;" class="text-center pr-th-index">#</th>
                <th style="width:24%;" class="text-start">รายการ</th>
                <th style="width:8%;" class="text-end pr-th-num">จำนวน</th>
                <th style="width:7%;" class="text-end pr-th-num">หน่วย</th>
                <th style="width:10%;" class="text-end pr-th-num">ค่าวัสดุ</th>
                <th style="width:10%;" class="text-end pr-th-num">ค่าแรง</th>
                <th style="width:10%;" class="text-end pr-th-num">ราคา/หน่วย</th>
                <th style="width:11%;" class="text-end pr-th-num">ราคารวม</th>
            </tr>
            <?php else: ?>
            <tr class="text-center">
                <th style="width:5%;" class="text-center">#</th>
                <th style="width:32%;" class="text-start">รายละเอียดสินค้า / บริการ</th>
                <th style="width:10%;">จำนวน</th>
                <th style="width:8%;">หน่วย</th>
                <th style="width:11%;" class="text-end">ราคา/หน่วย</th>
                <th style="width:11%;" class="text-end">ส่วนลด</th>
                <th style="width:11%;" class="text-end">ยอดรวม</th>
            </tr>
            <?php endif; ?>
        </thead>
        <tbody>
            <?php if ($hireTableNote): ?>
            <tr>
                <td class="text-center text-muted">1</td>
                <td class="text-start fw-semibold" style="white-space: pre-line;"><?= htmlspecialchars($hireScope, ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-end pr-td-num">1</td>
                <td class="pr-td-empty text-end">—</td>
                <?php if ($requestType === 'hire'): ?>
                <td class="pr-td-empty text-end">—</td>
                <td class="pr-td-empty text-end">—</td>
                <td class="text-end pr-td-num"><?= number_format($contractValue, 2) ?></td>
                <td class="text-end pr-td-num fw-bold"><?= number_format($contractValue, 2) ?></td>
                <?php else: ?>
                <td class="text-end"><?= number_format($contractValue, 2) ?></td>
                <td class="text-end text-muted">—</td>
                <td class="text-end fw-bold"><?= number_format($contractValue, 2) ?></td>
                <?php endif; ?>
            </tr>
            <?php elseif (count($item_rows) === 0): ?>
            <tr>
                <td colspan="<?= $requestType === 'hire' ? 8 : 7 ?>" class="text-center text-muted py-4">ไม่มีรายการบรรทัด (งานจัดจ้างอาจสรุปเป็นยอดเดียว)</td>
            </tr>
            <?php else: ?>
                <?php
                $hireDisplayRows = $requestType === 'hire'
                    ? tnc_hire_lines_apply_display_numbers($item_rows)
                    : $item_rows;
                $i = 1;
                foreach ($hireDisplayRows as $item):
                $isGroup = $requestType === 'hire' && tnc_hire_line_is_group($item);
                $displayNo = (string) ($item['display_no'] ?? (string) $i);
                $unitCell = trim((string) ($item['unit'] ?? ''));
                if ($requestType === 'hire' && !$isGroup) {
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
                    <td class="text-center <?= $isGroup ? 'fw-bold' : 'text-muted' ?>"><?= htmlspecialchars($displayNo, ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="<?= $isGroup ? 'fw-bold' : 'fw-bold text-dark' ?> text-start"><?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <?php if ($isGroup): ?>
                    <td class="pr-td-empty text-center">—</td>
                    <td class="pr-td-empty text-end">—</td>
                    <td class="pr-td-empty text-end">—</td>
                    <td class="pr-td-empty text-end">—</td>
                    <td class="pr-td-empty text-end">—</td>
                    <td class="pr-td-empty text-end">—</td>
                    <?php elseif ($requestType === 'hire'): ?>
                    <td class="text-end pr-td-num"><?= number_format((float) ($item['quantity'] ?? 0), 2) ?></td>
                    <td class="text-end pr-td-unit"><?= $unitCell !== '' ? htmlspecialchars($unitCell, ENT_QUOTES, 'UTF-8') : '<span class="pr-td-empty-char">—</span>' ?></td>
                    <td class="text-end pr-td-num"><?= number_format($matPrice, 2) ?></td>
                    <td class="text-end pr-td-num"><?= number_format($laborPrice, 2) ?></td>
                    <td class="text-end pr-td-num"><?= number_format($unitPrice, 2) ?></td>
                    <td class="text-end pr-td-num fw-bold"><?= number_format((float) ($item['total'] ?? 0), 2) ?></td>
                    <?php else: ?>
                    <td class="text-center"><?= number_format((float) ($item['quantity'] ?? 0), 2) ?></td>
                    <td class="text-center"><?= $unitCell !== '' ? htmlspecialchars($unitCell, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                    <td class="text-end"><?= number_format((float) ($item['unit_price'] ?? 0), 2) ?></td>
                    <td class="text-end text-muted small"><?php
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
                    <td class="text-end fw-bold"><?= number_format((float) ($item['total'] ?? 0), 2) ?></td>
                    <?php endif; ?>
                </tr>
                <?php $i++; endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="footer-sticky pr-footer-panel">
        <div class="row align-items-end mb-3">
            <div class="col-7 small text-muted">
                <?php if ($requestType === 'hire' && $hireScope !== '' && !$hireTableNote): ?>
                    <div style="font-size: 11px; font-weight: 700; color: #111; margin-bottom: 4px;">เงื่อนไขการชำระเงิน / ขอบเขตการทำงาน</div>
                    <div style="font-size: 12px; line-height: 1.45; color: #444; white-space: pre-line;"><?= htmlspecialchars($hireScope, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
            <div class="col-5">
                <div class="summary-box">
                    <?php if ($requestType === 'hire'): ?>
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
                    <div class="summary-item text-success vat-print-line">
                        <span><?= $requestType === 'hire' ? 'VAT 7%' : htmlspecialchars((string) ($vatPrint['vat_label'] ?? 'ภาษีมูลค่าเพิ่ม'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= $requestType === 'hire' ? '+' : '' ?><?= number_format((float) $vatPrint['vat_amount'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="grand-total-row">
                        <span class="grand-total-label"><?= $requestType === 'hire' ? 'Grand Total' : 'ยอดสุทธิ' ?></span>
                        <span class="grand-total-value">฿ <?= number_format((float) ($vatPrint['net_amount'] ?? $pg), 2) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="signature-grid">
            <div>
                <div class="sig-space"></div>
                <div class="sig-box">ผู้ขอซื้อ / ผู้รับผิดชอบ<br><small>(Requester)</small></div>
            </div>
            <div>
                <div class="sig-space"></div>
                <div class="sig-box">ผู้มีอำนาจลงนาม<br><small>(Authorized)</small></div>
            </div>
        </div>
    </div>
</div>
