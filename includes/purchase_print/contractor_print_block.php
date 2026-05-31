<?php

declare(strict_types=1);

/**
 * บล็อกข้อมูลผู้รับจ้างสำหรับพิมพ์ PR/PO
 *
 * @var array{name_th: string, national_id: string, address: string, payment_lines: list<string>, found: bool} $contractorPrint
 * @var string $contractorPrintLayout panel|detail
 */
$contractorPrintLayout = $contractorPrintLayout ?? 'panel';
$nameTh = trim((string) ($contractorPrint['name_th'] ?? ''));
if ($nameTh === '') {
    return;
}
$nationalId = trim((string) ($contractorPrint['national_id'] ?? ''));
$address = trim((string) ($contractorPrint['address'] ?? ''));
$paymentLines = is_array($contractorPrint['payment_lines'] ?? null) ? $contractorPrint['payment_lines'] : [];
?>
<?php if ($contractorPrintLayout === 'detail'): ?>
<div class="po-section-title text-dark"><?= htmlspecialchars($nameTh, ENT_QUOTES, 'UTF-8') ?></div>
<div class="small text-muted po-section-detail contractor-print-detail">
    <?php if ($nationalId !== ''): ?>
        <div><strong>เลขบัตรประชาชน:</strong> <?= htmlspecialchars($nationalId, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($address !== ''): ?>
        <div><strong>ที่อยู่:</strong> <?= htmlspecialchars($address, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($paymentLines !== []): ?>
        <div class="contractor-print-payment">
            <strong>ช่องทางการชำระ:</strong>
            <?php foreach ($paymentLines as $payLine): ?>
                <div><?= htmlspecialchars((string) $payLine, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="contractor-print-panel small">
    <div class="contractor-print-row"><strong>ผู้รับจ้าง:</strong> <?= htmlspecialchars($nameTh, ENT_QUOTES, 'UTF-8') ?></div>
    <?php if ($nationalId !== ''): ?>
        <div class="contractor-print-row"><strong>เลขบัตรประชาชน:</strong> <?= htmlspecialchars($nationalId, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($address !== ''): ?>
        <div class="contractor-print-row"><strong>ที่อยู่:</strong> <?= htmlspecialchars($address, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($paymentLines !== []): ?>
        <div class="contractor-print-row contractor-print-payment">
            <strong>ช่องทางการชำระ:</strong>
            <?php foreach ($paymentLines as $payLine): ?>
                <div><?= htmlspecialchars((string) $payLine, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
