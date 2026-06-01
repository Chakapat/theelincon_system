<?php

declare(strict_types=1);

/** @var string $po_quotation_attach_url from app_path() */
/** @var string $po_quotation_attach_caption safe label */
/** @var bool $po_quotation_attach_is_pdf */
/** @var bool $po_slip_page_break_before ขึ้นหน้าใหม่ก่อนแนบ (เมื่อมีเอกสารก่อนหน้า) */
$poQtBreakClass = !empty($po_slip_page_break_before) ? '' : ' po-payment-slip-print-wrap--first';
?>
<div class="po-payment-slip-print-wrap po-quotation-attach-print-wrap po-payment-evidence-card<?= $poQtBreakClass ?>">
    <div class="po-payment-slip-sheet po-payment-slip-sheet--full">
        <?php if ($po_quotation_attach_is_pdf): ?>
            <iframe
                class="po-quotation-pdf-iframe tnc-po-deferred-print-iframe"
                title="<?= htmlspecialchars($po_quotation_attach_caption, ENT_QUOTES, 'UTF-8') ?>"
                src="<?= htmlspecialchars($po_quotation_attach_url, ENT_QUOTES, 'UTF-8') ?>#view=FitH"
            ></iframe>
        <?php else: ?>
            <div class="po-slip-img-wrap text-center">
                <img
                    src="<?= htmlspecialchars($po_quotation_attach_url, ENT_QUOTES, 'UTF-8') ?>"
                    alt=""
                    class="po-payment-slip-img tnc-po-deferred-print-img img-fluid"
                    loading="eager"
                    decoding="sync"
                    fetchpriority="high"
                >
            </div>
        <?php endif; ?>
    </div>
</div>
