<?php

declare(strict_types=1);

/** @var string $po_slip_image_url absolute URL path จาก app_path() */
/** @var bool $po_slip_page_break_before ขึ้นหน้าใหม่ก่อนสลิป (เมื่อมีใบ PO ก่อนหน้า) */
$poSlipBreakClass = !empty($po_slip_page_break_before) ? '' : ' po-payment-slip-print-wrap--first';
?>
<div class="po-payment-slip-print-wrap po-payment-evidence-card po-slip-a4-page<?= $poSlipBreakClass ?>">
    <div class="po-payment-slip-sheet po-payment-slip-sheet--full">
        <div class="po-slip-img-wrap">
            <img src="<?= htmlspecialchars($po_slip_image_url, ENT_QUOTES, 'UTF-8') ?>" alt="" class="po-payment-slip-img tnc-po-deferred-print-img" loading="eager" decoding="sync" fetchpriority="high">
        </div>
    </div>
</div>
