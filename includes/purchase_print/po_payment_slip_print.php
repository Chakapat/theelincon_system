<?php

declare(strict_types=1);

/** @var string $po_slip_image_url absolute URL path จาก app_path() */
/** @var string $po_doc_header_po_number เลขที่ PO สำหรับหัวกระดาษ */
?>
<div class="po-payment-slip-print-wrap po-payment-evidence-card">
    <div class="no-print po-slip-print-hint small text-center text-muted py-2 px-3 mx-auto mb-2" style="max-width: 210mm;">
        หลักฐานการจ่ายด้านล่างจะถูก<strong>พิมพ์หน้าถัดไป</strong>หลังใบ PO อัตโนมัติ
    </div>
    <div class="po-payment-slip-sheet">
        <header class="po-slip-paper-header">
            <div class="po-slip-paper-header-kicker">เอกสารประกอบใบสั่งซื้อ</div>
            <div class="po-slip-po-line">เลขที่ PO <span class="po-slip-po-number"><?= htmlspecialchars($po_doc_header_po_number, ENT_QUOTES, 'UTF-8') ?></span></div>
        </header>
        <div class="po-slip-print-caption text-center small mb-2">หลักฐานการจ่ายเงิน</div>
        <div class="po-slip-img-wrap text-center">
            <img src="<?= htmlspecialchars($po_slip_image_url, ENT_QUOTES, 'UTF-8') ?>" alt="หลักฐานการจ่ายเงิน" class="po-payment-slip-img tnc-po-deferred-print-img img-fluid rounded border" loading="eager" decoding="sync" fetchpriority="high">
        </div>
    </div>
</div>
