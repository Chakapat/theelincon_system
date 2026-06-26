<?php

declare(strict_types=1);

/**
 * โทนสีเอกสาร (PR / PO / Invoice / Tax Invoice) — RTDB document_color_config/default
 * ตั้งค่าที่ pages/internal/config_color_docs.php
 */

require_once __DIR__ . '/../config/connect_database.php';

use Theelincon\Rtdb\Db;

const DOCUMENT_COLOR_CONFIG_TABLE = 'document_color_config';
const DOCUMENT_COLOR_CONFIG_PK = 'default';

/**
 * @return array<string, array{key: string, label: string, label_th: string, default: string}>
 */
function tnc_doc_color_definitions(): array
{
    return [
        'pr' => [
            'key' => 'pr',
            'label' => 'Purchase Request (PR)',
            'label_th' => 'ใบขอซื้อ (PR)',
            'default' => '#28a745',
        ],
        'po' => [
            'key' => 'po',
            'label' => 'Purchase Order (PO)',
            'label_th' => 'ใบสั่งซื้อ (PO)',
            'default' => '#ea580c',
        ],
        'invoice' => [
            'key' => 'invoice',
            'label' => 'Invoice',
            'label_th' => 'ใบแจ้งหนี้ (Invoice)',
            'default' => '#ff6600',
        ],
        'tax_invoice' => [
            'key' => 'tax_invoice',
            'label' => 'Tax Invoice',
            'label_th' => 'ใบกำกับภาษี (Tax Invoice)',
            'default' => '#ff6600',
        ],
    ];
}

function tnc_doc_color_rtdb_key(string $docKey): string
{
    return 'color_' . $docKey;
}

/**
 * @return array<string, mixed>
 */
function tnc_doc_color_config_row(): array
{
    if (array_key_exists('tnc_doc_color_config_cache', $GLOBALS)
        && is_array($GLOBALS['tnc_doc_color_config_cache'])) {
        return $GLOBALS['tnc_doc_color_config_cache'];
    }
    try {
        $r = Db::row(DOCUMENT_COLOR_CONFIG_TABLE, DOCUMENT_COLOR_CONFIG_PK);
        $cached = is_array($r) ? $r : [];
    } catch (\Throwable $e) {
        $cached = [];
    }

    $GLOBALS['tnc_doc_color_config_cache'] = $cached;

    return $cached;
}

function tnc_doc_color_clear_cache(): void
{
    unset($GLOBALS['tnc_doc_color_config_cache']);
}

function tnc_doc_color_normalize(?string $hex, string $fallback): string
{
    $hex = trim((string) $hex);
    if (preg_match('/^#([0-9a-fA-F]{6})$/', $hex) === 1) {
        return strtolower($hex);
    }
    if (preg_match('/^#([0-9a-fA-F]{3})$/', $hex, $m) === 1) {
        $h = $m[1];

        return strtolower(
            '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]
        );
    }

    return strtolower($fallback);
}

/**
 * @return array{0: int, 1: int, 2: int}
 */
function tnc_doc_color_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');

    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function tnc_doc_color_to_hex(int $r, int $g, int $b): string
{
    return sprintf(
        '#%02x%02x%02x',
        max(0, min(255, $r)),
        max(0, min(255, $g)),
        max(0, min(255, $b))
    );
}

function tnc_doc_color_mix_white(string $hex, float $whiteRatio): string
{
    [$r, $g, $b] = tnc_doc_color_rgb($hex);
    $ratio = max(0.0, min(1.0, $whiteRatio));

    return tnc_doc_color_to_hex(
        (int) round($r + (255 - $r) * $ratio),
        (int) round($g + (255 - $g) * $ratio),
        (int) round($b + (255 - $b) * $ratio)
    );
}

function tnc_doc_color_darken(string $hex, float $amount): string
{
    [$r, $g, $b] = tnc_doc_color_rgb($hex);
    $factor = 1.0 - max(0.0, min(1.0, $amount));

    return tnc_doc_color_to_hex(
        (int) round($r * $factor),
        (int) round($g * $factor),
        (int) round($b * $factor)
    );
}

/**
 * @return array{primary: string, deep: string, soft: string, border: string}
 */
function tnc_doc_color_palette(string $docKey): array
{
    $defs = tnc_doc_color_definitions();
    if (!isset($defs[$docKey])) {
        $docKey = 'po';
    }

    $default = (string) $defs[$docKey]['default'];
    $row = tnc_doc_color_config_row();
    $stored = $row[tnc_doc_color_rtdb_key($docKey)] ?? '';
    $primary = tnc_doc_color_normalize(is_scalar($stored) ? (string) $stored : '', $default);

    return [
        'primary' => $primary,
        'deep' => tnc_doc_color_darken($primary, 0.28),
        'soft' => tnc_doc_color_mix_white($primary, 0.92),
        'border' => tnc_doc_color_mix_white($primary, 0.75),
    ];
}

function tnc_doc_color_primary(string $docKey): string
{
    return tnc_doc_color_palette($docKey)['primary'];
}

function tnc_doc_color_css_var_prefix(string $docKey): string
{
    return match ($docKey) {
        'pr' => 'doc-pr',
        'po' => 'doc-po',
        'invoice' => 'doc-inv',
        'tax_invoice' => 'doc-tax',
        default => 'doc-po',
    };
}

/**
 * @return array<string, string>
 */
function tnc_doc_color_css_variables(): array
{
    $vars = [];
    foreach (array_keys(tnc_doc_color_definitions()) as $docKey) {
        $prefix = tnc_doc_color_css_var_prefix($docKey);
        $palette = tnc_doc_color_palette($docKey);
        $vars['--' . $prefix . '-primary'] = $palette['primary'];
        $vars['--' . $prefix . '-deep'] = $palette['deep'];
        $vars['--' . $prefix . '-soft'] = $palette['soft'];
        $vars['--' . $prefix . '-border'] = $palette['border'];
    }

    $vars['--dark'] = '#333333';

    return $vars;
}

/**
 * @param array{primary: string, deep: string, soft: string, border: string} $palette
 */
function tnc_doc_color_scope_vars(string $prefix, array $palette): string
{
    return '--' . $prefix . '-primary:' . $palette['primary']
        . ';--' . $prefix . '-deep:' . $palette['deep']
        . ';--' . $prefix . '-soft:' . $palette['soft']
        . ';--' . $prefix . '-border:' . $palette['border'] . ';';
}

function tnc_doc_color_css_string(): string
{
    $parts = [];
    foreach (tnc_doc_color_css_variables() as $name => $value) {
        $parts[] = $name . ':' . $value;
    }

    $pr = tnc_doc_color_palette('pr');
    $po = tnc_doc_color_palette('po');
    $inv = tnc_doc_color_palette('invoice');
    $tax = tnc_doc_color_palette('tax_invoice');

    $css = ':root{' . implode(';', $parts) . ';}';

    $css .= 'body.tnc-doc-pr-view,body.tnc-doc-pr-view .pr-purchase-requisition-doc{'
        . tnc_doc_color_scope_vars('doc-pr', $pr)
        . '--brand-color:' . $pr['primary'] . ';--brand-color-deep:' . $pr['deep']
        . ';--brand-color-soft:' . $pr['soft'] . ';--brand-border-soft:' . $pr['border'] . ';}';

    $css .= 'body.tnc-doc-po-view,body.tnc-doc-po-view .po-purchase-order-doc,.po-purchase-order-doc{'
        . tnc_doc_color_scope_vars('doc-po', $po)
        . '--brand-color:' . $po['primary'] . ';--brand-color-deep:' . $po['deep']
        . ';--brand-color-soft:' . $po['soft'] . ';--brand-border-soft:' . $po['border'] . ';}';

    $css .= 'body.tnc-doc-inv,body.tnc-doc-inv .inv-sales-doc,.inv-sales-doc.invoice-box{'
        . tnc_doc_color_scope_vars('doc-inv', $inv)
        . '--orange:' . $inv['primary'] . ';}';

    $css .= 'body.tnc-doc-tax,body.tnc-doc-tax .inv-sales-doc{'
        . tnc_doc_color_scope_vars('doc-tax', $tax)
        . '--orange:' . $tax['primary'] . ';--tir-accent:' . $tax['primary']
        . ';--tir-accent-dark:' . $tax['deep'] . ';}';

    $css .= '.pr-purchase-requisition-doc{'
        . tnc_doc_color_scope_vars('doc-pr', $pr)
        . '--brand-color:' . $pr['primary'] . ';--brand-color-deep:' . $pr['deep'] . ';}';

    $css .= '.pr-bundle-inline{--brand-color:' . $pr['primary'] . ';--brand-color-deep:' . $pr['deep'] . ';}';

    return $css;
}

function tnc_doc_color_config_revision(): string
{
    $row = tnc_doc_color_config_row();
    $updatedAt = trim((string) ($row['updated_at'] ?? ''));

    return $updatedAt !== '' ? $updatedAt : 'default';
}
