<?php

declare(strict_types=1);

/**
 * Fallback เมื่อ includes/tnc_flash.php ยังไม่ถูก deploy — กันหน้า 500
 */

if (!function_exists('tnc_audio_kinds')) {
    function tnc_audio_kinds(): array
    {
        return ['create', 'update', 'approve', 'complete', 'delete'];
    }
}

if (!function_exists('tnc_audio_from_query')) {
    function tnc_audio_from_query(array $q): ?string
    {
        return null;
    }
}

if (!function_exists('tnc_flash_from_query')) {
    function tnc_flash_from_query(array $get): ?array
    {
        return null;
    }
}

if (!function_exists('tnc_render_flash')) {
    function tnc_render_flash(?array $flash, bool $dismissible = true, string $extraAttr = ''): void
    {
    }
}
