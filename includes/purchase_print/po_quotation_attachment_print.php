<?php

declare(strict_types=1);

/** @var string $po_quotation_attach_url from app_path() */
/** @var string $po_quotation_attach_caption safe label */
/** @var bool $po_quotation_attach_is_pdf */
/** @var bool $po_slip_page_break_before ขึ้นหน้าใหม่ก่อนแนบ (เมื่อมีเอกสารก่อนหน้า) */
/** @var bool $po_quotation_from_pr optional */
$poQtBreakClass = !empty($po_slip_page_break_before) ? '' : ' po-quotation-attach-print-wrap--first';
$poQtCaption = trim((string) ($po_quotation_attach_caption ?? ''));
$poQtFromPr = !empty($po_quotation_from_pr);
$poQtSubtitle = $poQtCaption !== '' ? $poQtCaption : 'ไฟล์แนบใบเสนอราคา';
if ($poQtFromPr) {
    $poQtSubtitle .= ' · จาก PR';
}
?>
<div id="po-quotation-doc" class="po-quotation-attach-print-wrap po-quotation-doc<?= $poQtBreakClass ?>">
    <div class="invoice-box po-quotation-doc-sheet">
        <header class="po-quotation-doc-header no-print">
            <div class="po-quotation-doc-header__meta">
                <div class="po-quotation-doc-kicker">Purchase Module</div>
                <h2 class="po-quotation-doc-title">QUOTATION</h2>
                <div class="po-quotation-doc-subtitle">ใบเสนอราคา<?= $poQtFromPr ? ' (อ้างอิงจาก PR)' : '' ?></div>
            </div>
            <div class="po-quotation-doc-header__file">
                <div class="po-quotation-doc-file-label">ไฟล์แนบ</div>
                <div class="po-quotation-doc-file-name" title="<?= htmlspecialchars($poQtCaption, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($poQtSubtitle, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <a
                    class="btn btn-sm btn-outline-success rounded-pill px-3 mt-2"
                    href="<?= htmlspecialchars($po_quotation_attach_url, ENT_QUOTES, 'UTF-8') ?>"
                    target="_blank"
                    rel="noopener"
                ><i class="bi bi-box-arrow-up-right me-1"></i>เปิดไฟล์เต็ม</a>
            </div>
        </header>
        <div class="po-quotation-doc-header-print d-none">
            <div class="po-quotation-attach-kicker">เอกสารแนบ</div>
            <div class="po-quotation-attach-title">ใบเสนอราคา</div>
            <?php if ($poQtCaption !== ''): ?>
                <div class="po-quotation-attach-file"><?= htmlspecialchars($poQtCaption, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
        <div class="po-quotation-doc-body">
            <?php if ($po_quotation_attach_is_pdf): ?>
                <iframe
                    class="po-quotation-pdf-iframe tnc-po-deferred-print-iframe"
                    title="<?= htmlspecialchars($poQtSubtitle, ENT_QUOTES, 'UTF-8') ?>"
                    src="<?= htmlspecialchars($po_quotation_attach_url, ENT_QUOTES, 'UTF-8') ?>#toolbar=0&navpanes=0&scrollbar=1&view=FitH"
                ></iframe>
            <?php else: ?>
                <div class="po-quotation-attach-img-wrap">
                    <img
                        src="<?= htmlspecialchars($po_quotation_attach_url, ENT_QUOTES, 'UTF-8') ?>"
                        alt="<?= htmlspecialchars($poQtSubtitle, ENT_QUOTES, 'UTF-8') ?>"
                        class="po-quotation-attach-img tnc-po-deferred-print-img"
                        loading="eager"
                        decoding="sync"
                        fetchpriority="high"
                    >
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
