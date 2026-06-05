<?php

declare(strict_types=1);

if (!function_exists('tnc_bank_logo_url')) {
    require_once __DIR__ . '/banks.php';
}

$bankName = trim((string) ($data['bank_name'] ?? ''));
$bankLogoUrl = $bankName !== '' ? tnc_bank_logo_url($bankName) : '';
$bankAccountName = trim((string) ($data['bank_account_name'] ?? ''));
$bankAccountNumber = trim((string) ($data['bank_account_number'] ?? ''));
?>
<strong>ธนาคาร:</strong>
<?php if ($bankName !== ''): ?>
<span class="inv-bank-display">
    <?= htmlspecialchars($bankName, ENT_QUOTES, 'UTF-8') ?>
    <?php if ($bankLogoUrl !== ''): ?>
    · <img src="<?= htmlspecialchars($bankLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="inv-bank-logo" width="18" height="18">
    <?php endif; ?>
</span>
<?php endif; ?>
<br>
<strong>ชื่อบัญชี:</strong> <?= htmlspecialchars($bankAccountName, ENT_QUOTES, 'UTF-8') ?><br>
<strong>เลขที่บัญชี:</strong> <span style="font-family: monospace; font-weight: bold; font-size: 13px;"><?= htmlspecialchars($bankAccountNumber, ENT_QUOTES, 'UTF-8') ?></span>
