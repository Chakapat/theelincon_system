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
 * @var string $siteDisplay
 * @var string $createdRaw
 * @var string $quotationAttach
 * @var string $quotationName
 * @var string $detailsText
 * @var bool $hireTableNote
 */
?>
<div class="invoice-box">
    <div class="pr-doc-main">
    <div class="row align-items-start mb-2">
        <div class="col-6">
            <?php if (!empty($com['logo'])): ?>
                <img src="<?= htmlspecialchars(upload_logo_url((string) $com['logo']), ENT_QUOTES, 'UTF-8') ?>" class="company-logo" alt="Logo">
            <?php endif; ?>
            <div class="fw-bold mt-2" style="font-size: 16px;"><?= htmlspecialchars((string) ($com['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="small text-muted" style="font-size: 11px; line-height: 1.4;">
                <?= htmlspecialchars((string) ($com['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?><br>
                โทร: <?= htmlspecialchars((string) ($com['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?> | Tax ID: <?= htmlspecialchars((string) ($com['tax_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
        <div class="col-6 text-end">
            <div class="invoice-title"><?= $requestType === 'hire' ? 'REQUISITION (HIRE)' : 'PURCHASE REQUISITION' ?></div>
            <div class="fw-bold text-muted small"><?= $requestType === 'hire' ? 'ใบขอจ้าง / จัดจ้าง' : 'ใบขอซื้อ (PR)' ?></div>
            <div class="fw-bold text-dark mt-2" style="font-size: 18px;">เลขที่: <?= htmlspecialchars((string) ($pr['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
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

    <div class="row mb-2 mt-3">
        <div class="col-7 border-start border-4 border-success ps-3">
            <div style="font-size: 10px; color: var(--brand-color); font-weight: bold; text-transform: uppercase;">ผู้ขอซื้อ / ผู้รับผิดชอบ</div>
            <div class="fw-bold" style="font-size: 15px;"><?= htmlspecialchars($requesterDisplay !== '' ? $requesterDisplay : '-', ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($creatorDisplay !== '' && $creatorDisplay !== $requesterDisplay): ?>
                <div class="small text-muted">ผู้บันทึกในระบบ: <?= htmlspecialchars($creatorDisplay, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
        <div class="col-5 text-end">
            <div style="font-size: 10px; color: var(--brand-color); font-weight: bold; text-transform: uppercase;">วันที่เอกสาร</div>
            <div class="fw-bold" style="font-size: 15px;"><?= htmlspecialchars(tnc_pr_format_date_thai($createdRaw !== '' ? $createdRaw : date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($requestType === 'hire'): ?>
                <div class="small text-muted mt-1">ประเภท: จัดจ้าง</div>
            <?php endif; ?>
            <?php if ($siteDisplay !== ''): ?>
                <div class="small text-muted mt-1">สถานที่: <?= htmlspecialchars($siteDisplay, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($requestType === 'hire'): ?>
    <div class="row mb-3 small">
        <div class="col-12 border rounded-2 p-2 bg-light">
            <strong>ผู้รับจ้าง:</strong> <?= htmlspecialchars($contractorName !== '' ? $contractorName : '-', ENT_QUOTES, 'UTF-8') ?>
            &nbsp;|&nbsp; <strong>มูลค่าสัญญา:</strong> <?= number_format($contractValue, 2) ?> บาท
            &nbsp;|&nbsp; <strong>จำนวนงวด:</strong> <?= number_format($installmentTotal) ?> งวด
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
            <tr class="text-center">
                <th style="width:5%;" class="text-center">#</th>
                <th style="width:32%;" class="text-start">รายละเอียดสินค้า / บริการ</th>
                <th style="width:10%;">จำนวน</th>
                <th style="width:8%;">หน่วย</th>
                <th style="width:11%;" class="text-end">ราคา/หน่วย</th>
                <th style="width:11%;" class="text-end">ส่วนลด</th>
                <th style="width:11%;" class="text-end">ยอดรวม</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($hireTableNote): ?>
            <tr>
                <td class="text-center text-muted">1</td>
                <td class="text-start fw-semibold" style="white-space: pre-line;"><?= htmlspecialchars($hireScope, ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-center">1</td>
                <td class="text-center text-muted">—</td>
                <td class="text-end"><?= number_format($contractValue, 2) ?></td>
                <td class="text-end text-muted">—</td>
                <td class="text-end fw-bold"><?= number_format($contractValue, 2) ?></td>
            </tr>
            <?php elseif (count($item_rows) === 0): ?>
            <tr>
                <td colspan="7" class="text-center text-muted py-4">ไม่มีรายการบรรทัด (งานจัดจ้างอาจสรุปเป็นยอดเดียว)</td>
            </tr>
            <?php else: ?>
                <?php $i = 1; foreach ($item_rows as $item): ?>
                <tr>
                    <td class="text-center text-muted"><?= $i++ ?></td>
                    <td class="fw-bold text-dark text-start"><?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-center"><?= number_format((float) ($item['quantity'] ?? 0), 2) ?></td>
                    <td class="text-center"><?php
                        $unitCell = trim((string) ($item['unit'] ?? ''));
                        echo $unitCell !== '' ? htmlspecialchars($unitCell, ENT_QUOTES, 'UTF-8') : '—';
                    ?></td>
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
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="footer-sticky">
        <div class="row align-items-end mb-3">
            <div class="col-7 small text-muted">
                <?php if ($requestType === 'hire' && $hireScope !== '' && !$hireTableNote): ?>
                    <div style="font-size: 11px; font-weight: 700; color: #111; margin-bottom: 4px;">ขอบเขตงาน</div>
                    <div style="font-size: 12px; line-height: 1.45; color: #444; white-space: pre-line;"><?= htmlspecialchars($hireScope, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
            <div class="col-5">
                <div class="summary-box" style="background: #f8fbff; border: 1px solid #c7dbfa; border-radius: 0.5rem; padding: 0.75rem 1rem;">
                    <div class="summary-item">
                        <span>ยอดรายการ (ก่อน VAT)</span>
                        <span><?= number_format($ps, 2) ?></span>
                    </div>
                    <?php if ($vatOn && $pv > 0): ?>
                    <div class="summary-item text-success">
                        <span>VAT 7%</span>
                        <span><?= number_format($pv, 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="grand-total-row">
                        <span class="fw-bold" style="font-size: 14px;">ยอดรวมสุทธิ</span>
                        <span style="font-size: 18px; font-weight: 800;">฿ <?= number_format($pg, 2) ?></span>
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
