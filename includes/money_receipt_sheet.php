<?php

declare(strict_types=1);

/**
 * HTML แผ่นใบเสร็จรับเงิน (หน้าจอ / พิมพ์)
 *
 * @param array<string, mixed> $receipt
 * @param array<string, mixed> $company
 */
function money_receipt_render_sheet(array $receipt, array $company): void
{
    $items = money_receipt_items_from_json_field((string) ($receipt['items_json'] ?? ''));
    $t = money_receipt_totals($items);
    $dateTh = money_receipt_format_date_th((string) ($receipt['doc_date'] ?? ''));
    $issuerName = trim((string) ($receipt['issuer_name'] ?? ''));
    if ($issuerName === '') {
        $issuerName = 'ผู้ใช้งานระบบ';
    }
    $issuerName = htmlspecialchars($issuerName, ENT_QUOTES, 'UTF-8');
    $receiptNo = trim((string) ($receipt['receipt_no'] ?? ''));
    if ($receiptNo === '') {
        $fallbackId = (int) ($receipt['id'] ?? 0);
        $receiptNo = $fallbackId > 0 ? ('#' . $fallbackId) : '-';
    }
    $receiptNo = htmlspecialchars($receiptNo, ENT_QUOTES, 'UTF-8');
    $coName = htmlspecialchars((string) ($company['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $coTax = htmlspecialchars(trim((string) ($company['tax_id'] ?? '')), ENT_QUOTES, 'UTF-8');
    $coAddr = nl2br(htmlspecialchars(trim((string) ($company['address'] ?? '')), ENT_QUOTES, 'UTF-8'));
    $coPhone = htmlspecialchars(trim((string) ($company['phone'] ?? '')), ENT_QUOTES, 'UTF-8');
    $coEmail = htmlspecialchars(trim((string) ($company['email'] ?? '')), ENT_QUOTES, 'UTF-8');
    $logo = trim((string) ($company['logo'] ?? ''));
    $logoUrl = $logo !== '' ? htmlspecialchars(upload_logo_url($logo), ENT_QUOTES, 'UTF-8') : '';

    $payCash = !empty($receipt['pay_cash']);
    $payTransfer = !empty($receipt['pay_transfer']);
    $payCheck = !empty($receipt['pay_check']);

    $slipRel = trim((string) ($receipt['transfer_slip'] ?? ''));
    $slipUrl = $slipRel !== '' ? htmlspecialchars(money_receipt_slip_web_url($slipRel), ENT_QUOTES, 'UTF-8') : '';

    $fmt = static function (float $n): string {
        return number_format($n, 2);
    };
    $netThai = htmlspecialchars(money_receipt_baht_text((float) $t['net']), ENT_QUOTES, 'UTF-8');
    ?>
<div class="invoice-box mr-sheet">
    <div class="mr-page-flex">
        <table class="mr-header-table mb-2" role="presentation">
            <tr>
                <td class="mr-header-left align-top">
                    <?php if ($logoUrl !== ''): ?>
                        <img src="<?= $logoUrl ?>" class="company-logo" alt="Logo">
                    <?php endif; ?>
                </td>
                <td class="mr-header-right text-end align-top">
                    <div class="co-name fw-bold"><?= $coName ?></div>
                    <div class="small text-muted company-meta">
                        <?php if ($coTax !== ''): ?>เลขประจำตัวผู้เสียภาษี <?= $coTax ?><br><?php endif; ?>
                        <?= $coAddr ?>
                        <?php if ($coPhone !== '' || $coEmail !== ''): ?>
                            <br><?php if ($coPhone !== ''): ?>โทร. <?= $coPhone ?><?php endif; ?>
                            <?php if ($coEmail !== ''): ?><?= $coPhone !== '' ? ' · ' : '' ?>อีเมล <?= $coEmail ?><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>

        <div class="invoice-title text-center mb-2">ใบเสร็จรับเงิน</div>
        <div class="text-end mb-2 small"><span class="text-muted">เลขที่เอกสาร</span> <span class="fw-semibold"><?= $receiptNo ?></span></div>

        <div class="d-flex flex-wrap justify-content-between gap-2 mb-3 small">
            <div><span class="text-muted">วันที่</span> <span class="fw-semibold"><?= htmlspecialchars($dateTh, ENT_QUOTES, 'UTF-8') ?></span></div>
            <div><span class="text-muted">ผู้ออกเอกสาร</span> <span class="fw-semibold"><?= $issuerName ?></span></div>
        </div>

        <table class="table table-bordered table-custom mb-2">
            <thead class="table-light">
                <tr>
                    <th style="width:52px;" class="text-center">#</th>
                    <th>รายละเอียด</th>
                    <th style="width:110px;" class="text-end">ยอดหัก</th>
                    <th style="width:110px;" class="text-end">ยอดรับ</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $i = 0;
                foreach ($items as $row):
                    ++$i;
                    ?>
                    <tr>
                        <td class="text-center"><?= $i ?></td>
                        <td><?= htmlspecialchars($row['detail'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-end"><?= $fmt($row['deduct']) ?></td>
                        <td class="text-end"><?= $fmt($row['receive']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($i === 0): ?>
                    <tr><td colspan="4" class="text-center text-muted">—</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="fw-semibold">
                    <td colspan="2" class="text-end border-end-0">รวม</td>
                    <td class="text-end"><?= $fmt($t['sum_deduct']) ?></td>
                    <td class="text-end"><?= $fmt($t['sum_receive']) ?></td>
                </tr>
                <tr class="fw-bold">
                    <td colspan="3" class="text-end border-end-0 align-middle">ยอดสุทธิ</td>
                    <td class="text-end align-middle"><?= $fmt($t['net']) ?></td>
                </tr>
                <tr>
                    <td colspan="4" class="small text-end">(<span class="fw-semibold"><?= $netThai ?></span>)</td>
                </tr>
            </tfoot>
        </table>

        <div class="payment-info-box border rounded p-2 mb-2 small">
            <span class="fw-semibold me-2">วิธีชำระเงิน:</span>
            <span class="me-3"><?= $payCash ? '☑' : '☐' ?> เงินสด</span>
            <span class="me-3"><?= $payTransfer ? '☑' : '☐' ?> เงินโอน</span>
            <span><?= $payCheck ? '☑' : '☐' ?> เช็คธนาคาร</span>
        </div>

        <?php if ($payTransfer && $slipUrl !== ''): ?>
        <div class="mb-2">
            <div class="small fw-semibold mb-1">สลิปการโอน</div>
            <a href="<?= $slipUrl ?>" target="_blank" rel="noopener" class="d-inline-block border rounded p-1 bg-light">
                <img src="<?= $slipUrl ?>" alt="สลิปโอนเงิน" class="img-fluid slip-thumb" style="max-height: 220px; max-width: 100%;">
            </a>
        </div>
        <?php endif; ?>

        <div class="row g-4 mt-auto pt-3 sig-row">
            <div class="col-6 text-center">
                <div class="sig-box border-top border-dark d-inline-block pt-2 px-4 mx-auto" style="min-width: 70%;">
                    <span class="small">ผู้รับทราบ</span>
                </div>
            </div>
            <div class="col-6 text-center">
                <div class="sig-box border-top border-dark d-inline-block pt-2 px-4 mx-auto" style="min-width: 70%;">
                    <span class="small">ผู้รับเงิน</span>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
}
