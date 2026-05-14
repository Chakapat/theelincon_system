<?php

declare(strict_types=1);

/** @var string $po_quotation_attach_url from app_path() */
/** @var string $po_quotation_attach_caption safe label */
/** @var bool $po_quotation_attach_is_pdf */
/** @var string $po_doc_header_po_number เลขที่ PO สำหรับหัวกระดาษ */
?>
<div class="po-payment-slip-print-wrap po-quotation-attach-print-wrap po-payment-evidence-card">
    <div class="no-print po-slip-print-hint small text-center text-muted py-2 px-3 mx-auto mb-2" style="max-width: 210mm;">
        ไฟล์แนบใบเสนอราคาด้านล่างจะถูก<strong>พิมพ์หน้าถัดไป</strong>หลังใบ PO / หลักฐานการจ่าย (ถ้ามี)
    </div>
    <div class="po-payment-slip-sheet">
        <header class="po-slip-paper-header">
            <div class="po-slip-paper-header-kicker">เอกสารประกอบใบสั่งซื้อ</div>
            <div class="po-slip-po-line">เลขที่ PO <span class="po-slip-po-number"><?= htmlspecialchars($po_doc_header_po_number, ENT_QUOTES, 'UTF-8') ?></span></div>
        </header>
        <div class="po-slip-print-caption text-center small mb-2"><?= htmlspecialchars($po_quotation_attach_caption, ENT_QUOTES, 'UTF-8') ?></div>
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
                    alt="<?= htmlspecialchars($po_quotation_attach_caption, ENT_QUOTES, 'UTF-8') ?>"
                    class="po-payment-slip-img tnc-po-deferred-print-img img-fluid rounded border"
                    loading="eager"
                    decoding="sync"
                    fetchpriority="high"
                >
            </div>
        <?php endif; ?>
    </div>
</div>
