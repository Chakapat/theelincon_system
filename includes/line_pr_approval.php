<?php

declare(strict_types=1);

require_once __DIR__ . '/line_notify_runtime.php';
require_once __DIR__ . '/line_messaging.php';
require_once __DIR__ . '/web_notifications.php';
require_once __DIR__ . '/hire_line_items.php';

use Theelincon\Rtdb\Db;

/** สถานะ PR ที่อนุมัติแล้ว (รวมข้อมูลเก่า status=ready) */
function line_pr_is_approved_for_po(array $pr): bool
{
    $st = line_pr_normalize_status($pr);

    return $st === 'approved' || $st === 'ready';
}

function line_pr_normalize_status(array $pr): string
{
    $st = strtolower(trim((string) ($pr['status'] ?? '')));
    if (in_array($st, ['pending', 'approved', 'rejected'], true)) {
        return $st;
    }
    if ($st === 'ready') {
        return 'ready';
    }

    return 'pending';
}

function line_pr_status_label_th(string $status): string
{
    return match (line_pr_normalize_status(['status' => $status])) {
        'pending' => 'รออนุมัติ',
        'approved', 'ready' => 'อนุมัติแล้ว',
        'rejected' => 'ไม่อนุมัติ',
        default => 'รออนุมัติ',
    };
}

function line_pr_status_badge_class(string $status): string
{
    return match (line_pr_normalize_status(['status' => $status])) {
        'pending' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        'approved', 'ready' => 'bg-success-subtle text-success-emphasis border border-success-subtle',
        'rejected' => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
        default => 'bg-secondary-subtle text-secondary-emphasis border',
    };
}

/**
 * แก้ไข PR ได้เมื่อมี pr.update, ยังไม่มี PO และยังไม่อนุมัติ
 * PR อนุมัติแล้ว — เฉพาะ ADMIN เท่านั้น
 */
function line_pr_user_can_edit(array $pr, bool $hasPo = false): bool
{
    if (!function_exists('user_can') || !user_can('pr.update')) {
        return false;
    }
    if ($hasPo) {
        return false;
    }
    $st = line_pr_normalize_status($pr);
    if (in_array($st, ['approved', 'ready'], true)) {
        return function_exists('user_is_admin_only_role') && user_is_admin_only_role();
    }

    return true;
}

function line_pr_new_approval_token(): string
{
    return bin2hex(random_bytes(16));
}

/** @return list<string> Group ID สำหรับ push (ส่งไปกลุ่มเท่านั้น — สิทธิ์กดอนุมัติดูจากรายชื่อผู้อนุมัติ) */
function line_pr_notify_push_targets(): array
{
    $group = line_effective_target_group_id();

    return $group !== '' ? [$group] : [];
}

/**
 * @return array{ok: bool, sent: int, error: ?string}
 */
function line_pr_send_approval_request(int $prId): array
{
    $pr = Db::rowByIdField('purchase_requests', $prId);
    if ($pr === null) {
        return ['ok' => false, 'sent' => 0, 'error' => 'not_found'];
    }

    $token = line_effective_channel_access_token();
    if ($token === '') {
        return ['ok' => false, 'sent' => 0, 'error' => 'missing_token'];
    }

    $targets = line_pr_notify_push_targets();
    if ($targets === []) {
        return ['ok' => false, 'sent' => 0, 'error' => 'missing_target'];
    }

    $messages = line_pr_build_flex_messages($pr);
    $sent = 0;
    foreach ($targets as $to) {
        if (line_messaging_push($token, $to, $messages)) {
            $sent++;
        }
    }

    return [
        'ok' => $sent > 0,
        'sent' => $sent,
        'error' => $sent > 0 ? null : 'push_failed',
    ];
}

function line_pr_flex_trunc(string $text, int $maxLen): string
{
    $text = trim($text);
    if ($text === '') {
        return '—';
    }
    if (mb_strlen($text) <= $maxLen) {
        return $text;
    }

    return mb_substr($text, 0, $maxLen - 1) . '…';
}

function line_pr_flex_money(float $amount): string
{
    return number_format($amount, 2);
}

/**
 * @param array<string, mixed> $opts
 *
 * @return array<string, mixed>
 */
function line_pr_flex_text(string $text, array $opts = []): array
{
    $row = [
        'type' => 'text',
        'text' => $text,
        'wrap' => true,
        'size' => (string) ($opts['size'] ?? 'sm'),
        'color' => (string) ($opts['color'] ?? '#374151'),
    ];
    if (!empty($opts['weight'])) {
        $row['weight'] = (string) $opts['weight'];
    }
    if (!empty($opts['margin'])) {
        $row['margin'] = (string) $opts['margin'];
    }

    return $row;
}

/** @return array<string, mixed> */
function line_pr_flex_separator(): array
{
    return [
        'type' => 'separator',
        'margin' => 'md',
        'color' => '#e5e7eb',
    ];
}

/**
 * @return array<string, mixed>
 */
function line_pr_flex_kv(string $label, string $value, bool $boldValue = false): array
{
    return [
        'type' => 'box',
        'layout' => 'horizontal',
        'spacing' => 'sm',
        'contents' => [
            line_pr_flex_text($label, ['size' => 'xs', 'color' => '#6b7280']),
            [
                'type' => 'text',
                'text' => $value !== '' ? $value : '—',
                'size' => 'xs',
                'color' => '#111827',
                'align' => 'end',
                'wrap' => true,
                'flex' => 2,
                'weight' => $boldValue ? 'bold' : 'regular',
            ],
        ],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function line_pr_flex_load_item_rows(int $prId, string $requestType): array
{
    $items = Db::filter('purchase_request_items', static function (array $r) use ($prId): bool {
        return isset($r['pr_id']) && (int) $r['pr_id'] === $prId;
    });
    Db::sortRows($items, 'id', false);
    $out = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (trim((string) ($row['description'] ?? '')) === '') {
            continue;
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * @param array<string, mixed> $item
 *
 * @return list<array<string, mixed>>
 */
function line_pr_flex_item_block(int $lineNo, array $item, bool $isHire = false): array
{
    if ($isHire && function_exists('tnc_hire_line_is_group') && tnc_hire_line_is_group($item)) {
        $no = (string) ($item['display_no'] ?? $lineNo);
        $desc = line_pr_flex_trunc((string) ($item['description'] ?? ''), 80);

        return [
            line_pr_flex_text($no . '  ' . $desc, ['weight' => 'bold', 'size' => 'sm', 'color' => '#111827', 'margin' => 'md']),
        ];
    }

    $desc = line_pr_flex_trunc((string) ($item['description'] ?? ''), 80);
    $noLabel = (string) ($item['display_no'] ?? $lineNo);
    $qty = (float) ($item['quantity'] ?? 0);
    $unit = trim((string) ($item['unit'] ?? ''));
    $unitPrice = (float) ($item['unit_price'] ?? 0);
    $lineTotal = (float) ($item['total'] ?? 0);
    $discIn = trim((string) ($item['discount_input'] ?? ''));
    $discAmt = (float) ($item['discount_amount'] ?? 0);
    $discShow = $discIn !== '' ? $discIn : ($discAmt > 0 ? line_pr_flex_money($discAmt) : '—');

    $priceRows = [];
    if ($isHire) {
        if (!function_exists('tnc_hire_item_material_labor')) {
            require_once dirname(__DIR__) . '/hire_line_items.php';
        }
        $parts = tnc_hire_item_material_labor($item);
        $matPrice = $parts['material'];
        $laborPrice = $parts['labor'];
        $unitSum = round($matPrice + $laborPrice, 2);
        if ($unitSum <= 0) {
            $unitSum = $unitPrice;
        }
        $priceRows = [
            line_pr_flex_kv('ราคา/หน่วย · ค่าวัสดุ', line_pr_flex_money($matPrice)),
            line_pr_flex_kv('ราคา/หน่วย · ค่าแรง', line_pr_flex_money($laborPrice)),
            line_pr_flex_kv('ราคา/หน่วย', line_pr_flex_money($unitSum), true),
        ];
    } else {
        $priceRows = [
            line_pr_flex_kv('ราคา/หน่วย', line_pr_flex_money($unitPrice)),
            line_pr_flex_kv('ส่วนลด', $discShow),
        ];
    }

    $boxContents = [
        line_pr_flex_kv('จำนวน', line_pr_flex_money($qty)),
        line_pr_flex_kv('หน่วย', $unit !== '' ? $unit : '—'),
    ];
    foreach ($priceRows as $row) {
        $boxContents[] = $row;
    }
    if ($isHire) {
        $boxContents[] = line_pr_flex_kv('ราคารวม', line_pr_flex_money($lineTotal), true);
    } else {
        $boxContents[] = line_pr_flex_kv('ยอดรวม', line_pr_flex_money($lineTotal), true);
    }

    $titlePrefix = ($isHire && isset($item['display_no']))
        ? ($noLabel . '  ' . $desc)
        : ($noLabel . '. ' . $desc);

    return [
        line_pr_flex_text($titlePrefix, ['weight' => 'bold', 'size' => 'sm', 'color' => '#111827']),
        [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'sm',
            'spacing' => 'xs',
            'backgroundColor' => '#f9fafb',
            'cornerRadius' => 'md',
            'paddingAll' => 'md',
            'contents' => $boxContents,
        ],
    ];
}

/**
 * @param array<string, mixed> $ctx
 *
 * @return list<array<string, mixed>>
 */
function line_pr_flex_meta_body_rows(array $ctx): array
{
    $rows = [
        line_pr_flex_kv('เลขที่ PR', (string) $ctx['pr_no']),
        line_pr_flex_kv('วันที่', (string) $ctx['date_th']),
        line_pr_flex_kv('ประเภท', (string) $ctx['type_label']),
        line_pr_flex_kv('ไซต์งาน', (string) $ctx['site']),
        line_pr_flex_kv('ผู้ขอ', (string) $ctx['requester']),
    ];
    if ((string) $ctx['creator'] !== '' && (string) $ctx['creator'] !== (string) $ctx['requester']) {
        $rows[] = line_pr_flex_kv('ผู้บันทึก', (string) $ctx['creator']);
    }
    if ((string) $ctx['quotation_name'] !== '') {
        $rows[] = line_pr_flex_kv('แนบใบเสนอราคา', (string) $ctx['quotation_name']);
    }
    if ((string) $ctx['details'] !== '' && (string) ($ctx['type_label'] ?? '') !== 'จัดจ้าง') {
        $rows[] = line_pr_flex_separator();
        $rows[] = line_pr_flex_text('รายละเอียด / วัตถุประสงค์', ['size' => 'xs', 'color' => '#9a3412', 'weight' => 'bold']);
        $rows[] = line_pr_flex_text((string) $ctx['details'], ['size' => 'xs', 'color' => '#4b5563']);
    }

    return $rows;
}

/**
 * @param array<string, mixed> $ctx
 *
 * @return list<array<string, mixed>>
 */
function line_pr_flex_hire_body_rows(array $ctx): array
{
    return [
        line_pr_flex_separator(),
        line_pr_flex_text('รายละเอียดจัดจ้าง', ['weight' => 'bold', 'size' => 'sm', 'color' => '#9a3412']),
        line_pr_flex_kv('ผู้รับจ้าง', (string) $ctx['contractor']),
        line_pr_flex_kv('มูลค่าสัญญา', line_pr_flex_money((float) $ctx['contract_value']) . ' บาท', true),
        line_pr_flex_kv('จำนวนงวด', (string) $ctx['installment_total'] . ' งวด'),
        line_pr_flex_text('เงื่อนไขการชำระเงิน / ขอบเขตการทำงาน', ['size' => 'xs', 'color' => '#6b7280', 'margin' => 'md']),
        line_pr_flex_text(line_pr_flex_trunc((string) $ctx['hire_scope'], 500), ['size' => 'xs', 'color' => '#374151']),
    ];
}

/**
 * @param array<string, mixed> $ctx
 *
 * @return list<array<string, mixed>>
 */
function line_pr_flex_summary_rows(array $ctx): array
{
    $vatPrint = $ctx['vat_print'];
    if (!is_array($vatPrint)) {
        $vatPrint = [];
    }
    $rows = [
        line_pr_flex_separator(),
        line_pr_flex_text('สรุปยอด', ['weight' => 'bold', 'size' => 'sm', 'color' => '#111827']),
        line_pr_flex_kv('ยอดรายการ', line_pr_flex_money((float) ($vatPrint['line_amount'] ?? $ctx['subtotal'])) . ' บาท'),
    ];
    $vatAmt = (float) ($vatPrint['vat_amount'] ?? 0);
    if (!empty($ctx['vat_on']) && $vatAmt > 0) {
        $vatLabel = trim((string) ($vatPrint['vat_label'] ?? 'ภาษีมูลค่าเพิ่ม'));
        $rows[] = line_pr_flex_kv($vatLabel !== '' ? $vatLabel : 'VAT', line_pr_flex_money($vatAmt) . ' บาท');
    }
    $rows[] = line_pr_flex_kv('ยอดสุทธิ', line_pr_flex_money((float) ($vatPrint['net_amount'] ?? $ctx['grand_total'])) . ' บาท', true);

    return $rows;
}

/**
 * @return array<string, mixed>
 */
function line_pr_flex_footer(string $postbackBase, string $prNo): array
{
    return [
        'type' => 'box',
        'layout' => 'vertical',
        'spacing' => 'sm',
        'contents' => [
            line_pr_flex_text('กดปุ่มเพื่ออนุมัติหรือไม่อนุมัติ', ['size' => 'xs', 'color' => '#9ca3af']),
            [
                'type' => 'box',
                'layout' => 'horizontal',
                'spacing' => 'sm',
                'contents' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => '#16a34a',
                        'action' => [
                            'type' => 'postback',
                            'label' => 'อนุมัติ',
                            'data' => $postbackBase . '&decision=approve',
                            'displayText' => 'อนุมัติ ' . $prNo,
                        ],
                    ],
                    [
                        'type' => 'button',
                        'style' => 'secondary',
                        'action' => [
                            'type' => 'postback',
                            'label' => 'ไม่อนุมัติ',
                            'data' => $postbackBase . '&decision=reject',
                            'displayText' => 'ไม่อนุมัติ ' . $prNo,
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @param array<string, mixed> $pr
 *
 * @return list<array<string, mixed>>
 */
function line_pr_build_flex_messages(array $pr): array
{
    $prId = (int) ($pr['id'] ?? 0);
    $prNo = trim((string) ($pr['pr_number'] ?? ('PR-' . $prId)));
    $reqType = trim((string) ($pr['request_type'] ?? ($pr['procurement_type'] ?? 'purchase')));
    if ($reqType !== 'hire') {
        $reqType = 'purchase';
    }
    $typeLabel = $reqType === 'hire' ? 'จัดจ้าง' : 'จัดซื้อ';
    $approvalToken = trim((string) ($pr['line_approval_token'] ?? ''));
    $postbackBase = 'action=line_pr_decision&id=' . $prId . '&token=' . rawurlencode($approvalToken);

    $site = trim((string) ($pr['site_name'] ?? ''));
    $siteId = (int) ($pr['site_id'] ?? 0);
    if ($site === '' && $siteId > 0) {
        $siteRow = Db::row('sites', (string) $siteId);
        if (is_array($siteRow)) {
            $site = trim((string) ($siteRow['name'] ?? ''));
        }
    }

    $users = Db::tableKeyed('users');
    $rb = $users[(string) ($pr['requested_by'] ?? '')] ?? null;
    $cb = $users[(string) ($pr['created_by'] ?? '')] ?? null;
    $requester = trim((string) (($rb['fname'] ?? '') . ' ' . ($rb['lname'] ?? '')));
    $creator = trim((string) (($cb['fname'] ?? '') . ' ' . ($cb['lname'] ?? '')));

    $createdRaw = trim((string) ($pr['created_at'] ?? ''));
    $dateTh = '-';
    if ($createdRaw !== '') {
        $ts = strtotime($createdRaw);
        if ($ts !== false) {
            $dateTh = date('d/m/Y', $ts);
        }
    }

    $details = trim((string) ($pr['details'] ?? ''));
    if ($details === '' && $reqType === 'hire') {
        $details = trim((string) ($pr['hire_scope_details'] ?? ''));
    }

    $pv = (float) ($pr['vat_amount'] ?? 0);
    $pg = (float) ($pr['total_amount'] ?? 0);
    $ps = isset($pr['subtotal_amount']) && $pr['subtotal_amount'] !== null && $pr['subtotal_amount'] !== ''
        ? (float) $pr['subtotal_amount']
        : round($pg - $pv, 2);
    $vatOn = (int) ($pr['vat_enabled'] ?? 0) === 1;
    $vatMode = trim((string) ($pr['vat_mode'] ?? 'exclusive'));
    if (!in_array($vatMode, ['exclusive', 'inclusive'], true)) {
        $vatMode = 'exclusive';
    }
    if (!function_exists('tnc_purchase_vat_print_summary')) {
        require_once __DIR__ . '/purchase_print/vat_print_summary.php';
    }
    $vatPrint = tnc_purchase_vat_print_summary($vatOn, $vatMode, $ps, $pv, $pg);

    $itemRows = line_pr_flex_load_item_rows($prId, $reqType);
    $contractor = trim((string) ($pr['contractor_name'] ?? ($pr['hire_contractor_name'] ?? '')));
    $contractValue = (float) ($pr['contract_value'] ?? ($pr['hire_total_value'] ?? 0));
    $installmentTotal = (int) ($pr['installment_total'] ?? ($pr['hire_installment_count'] ?? 1));
    if ($installmentTotal < 1) {
        $installmentTotal = 1;
    }
    $hireScope = trim((string) ($pr['hire_scope_details'] ?? ''));

    $ctx = [
        'pr_no' => $prNo,
        'date_th' => $dateTh,
        'type_label' => $typeLabel,
        'site' => $site,
        'requester' => $requester !== '' ? $requester : '—',
        'creator' => $creator,
        'quotation_name' => trim((string) ($pr['quotation_attachment_name'] ?? '')),
        'details' => $details,
        'contractor' => $contractor,
        'contract_value' => $contractValue,
        'installment_total' => $installmentTotal,
        'hire_scope' => $hireScope,
        'subtotal' => $ps,
        'grand_total' => $pg,
        'vat_on' => $vatOn,
        'vat_print' => $vatPrint,
    ];

    $itemBlocks = [];
    if ($reqType === 'hire' && $itemRows === [] && $hireScope !== '') {
        $itemBlocks = line_pr_flex_item_block(1, [
            'description' => line_pr_flex_trunc($hireScope, 80),
            'quantity' => 1,
            'unit' => 'งาน',
            'unit_price' => $contractValue,
            'total' => $contractValue,
            'discount_input' => '',
            'discount_amount' => 0,
        ], true);
    } elseif ($itemRows === []) {
        $itemBlocks[] = line_pr_flex_text('ไม่มีรายการบรรทัด', ['size' => 'sm', 'color' => '#6b7280']);
    } else {
        $lineNo = 1;
        $maxLines = 25;
        $displayItems = $reqType === 'hire'
            ? tnc_hire_lines_apply_display_numbers($itemRows)
            : $itemRows;
        foreach ($displayItems as $item) {
            if ($lineNo > $maxLines) {
                break;
            }
            foreach (line_pr_flex_item_block($lineNo, $item, $reqType === 'hire') as $block) {
                $itemBlocks[] = $block;
            }
            $lineNo++;
        }
        if (count($displayItems) > $maxLines) {
            $itemBlocks[] = line_pr_flex_text(
                '… และอีก ' . (count($displayItems) - $maxLines) . ' รายการ (ดูในระบบ)',
                ['size' => 'xs', 'color' => '#6b7280', 'margin' => 'md']
            );
        }
    }

    $bodyMeta = line_pr_flex_meta_body_rows($ctx);
    $bodyItemsHeader = [
        line_pr_flex_separator(),
        line_pr_flex_text($reqType === 'hire' ? 'รายการงานจัดจ้าง' : 'รายการสินค้า / บริการ', ['weight' => 'bold', 'size' => 'sm', 'color' => '#9a3412']),
        line_pr_flex_text(
            $reqType === 'hire'
                ? 'รายการ · จำนวน · หน่วย · ค่าวัสดุ · ค่าแรง · ราคา/หน่วย · ราคารวม'
                : 'รายการ · จำนวน · หน่วย · ราคา/หน่วย · ส่วนลด · ยอดรวม',
            ['size' => 'xxs', 'color' => '#9ca3af']
        ),
    ];
    $bodyHire = $reqType === 'hire' ? line_pr_flex_hire_body_rows($ctx) : [];
    $bodySummary = line_pr_flex_summary_rows($ctx);

    $perBubbleMax = 4;
    $itemChunks = array_chunk($itemBlocks, $perBubbleMax * 2);
    if ($itemChunks === []) {
        $itemChunks = [[]];
    }

    $bubbles = [];
    $chunkCount = count($itemChunks);
    foreach ($itemChunks as $idx => $chunk) {
        $isFirst = ($idx === 0);
        $isLast = ($idx === $chunkCount - 1);
        $bodyContents = [];
        if ($isFirst) {
            $bodyContents = array_merge($bodyMeta, $bodyHire, $bodyItemsHeader);
        } else {
            $bodyContents[] = line_pr_flex_text(
                'รายการ (ต่อ) — ' . $prNo,
                ['weight' => 'bold', 'size' => 'sm', 'color' => '#6b7280']
            );
        }
        $bodyContents = array_merge($bodyContents, $chunk);
        if ($isLast) {
            $bodyContents = array_merge($bodyContents, $bodySummary);
        }

        $bubble = [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    line_pr_flex_text('ขออนุมัติใบขอซื้อ (PR)', ['weight' => 'bold', 'size' => 'md', 'color' => '#9a3412']),
                    line_pr_flex_text($prNo . ($chunkCount > 1 ? ' (' . ($idx + 1) . '/' . $chunkCount . ')' : ''), [
                        'weight' => 'bold',
                        'size' => 'xl',
                        'color' => '#1f2937',
                        'margin' => 'md',
                    ]),
                ],
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => $bodyContents,
            ],
        ];
        if ($isLast) {
            $bubble['footer'] = line_pr_flex_footer($postbackBase, $prNo);
        }
        $bubbles[] = $bubble;
    }

    $altParts = [
        'ขออนุมัติ ' . $prNo,
        $typeLabel,
        'ยอดสุทธิ ' . line_pr_flex_money($pg) . ' บาท',
        count($itemRows) . ' รายการ',
    ];
    $alt = line_pr_flex_trunc(implode(' | ', $altParts), 400);

    $contents = count($bubbles) === 1
        ? $bubbles[0]
        : ['type' => 'carousel', 'contents' => $bubbles];

    return [[
        'type' => 'flex',
        'altText' => $alt,
        'contents' => $contents,
    ]];
}

/**
 * @param array<string, mixed> $meta
 *
 * @return array{ok: bool, message: string, pr_no: string}
 */
function line_pr_persist_decision(int $prId, string $decision, array $meta): array
{
    $decision = strtolower(trim($decision));
    if (!in_array($decision, ['approve', 'reject'], true)) {
        return ['ok' => false, 'message' => 'คำสั่งไม่ถูกต้อง', 'pr_no' => ''];
    }

    $pr = Db::rowByIdField('purchase_requests', $prId);
    if ($pr === null) {
        return ['ok' => false, 'message' => 'ไม่พบใบขอซื้อ', 'pr_no' => ''];
    }

    $st = line_pr_normalize_status($pr);
    if ($st !== 'pending') {
        return ['ok' => false, 'message' => 'รายการนี้ดำเนินการไปแล้ว (สถานะ: ' . line_pr_status_label_th($st) . ')', 'pr_no' => ''];
    }

    $nextStatus = $decision === 'approve' ? 'approved' : 'rejected';
    $pk = Db::pkForLogicalId('purchase_requests', $prId);
    $before = Db::row('purchase_requests', $pk) ?? [];
    Db::setRow('purchase_requests', $pk, array_merge($before, [
        'status' => $nextStatus,
        'line_decision' => $decision,
        'line_decided_at' => date('Y-m-d H:i:s'),
        'line_decided_by_line_user_id' => trim((string) ($meta['line_user_id'] ?? '')),
        'line_decided_by_user_id' => (int) ($meta['web_user_id'] ?? 0),
        'line_decision_source' => trim((string) ($meta['source'] ?? '')),
        // ไม่ล้างโทเคน เพื่อให้ลิงก์ไม่ "หมดอายุ" — ป้องกันกดซ้ำด้วยการเช็คสถานะ (pending) แทน
    ]));
    $after = Db::row('purchase_requests', $pk);
    $prNo = trim((string) ($after['pr_number'] ?? ''));
    if ($prNo === '') {
        $prNo = 'PR #' . $prId;
    }

    if (function_exists('tnc_audit_log')) {
        tnc_audit_log('update', 'purchase_request', (string) $prId, $prNo, [
            'source' => (string) ($meta['audit_source'] ?? 'line_pr_approval'),
            'action' => (string) ($meta['audit_action'] ?? 'pr_decision'),
            'before' => $before,
            'after' => $after,
            'meta' => $meta,
        ]);
    }

    // PR จัดจ้างที่ "อนุมัติ" → สร้างสัญญาจ้าง (hire_contracts) อัตโนมัติ เพื่อให้ออก PO ตามงวดได้
    if ($decision === 'approve' && is_array($after) && line_pr_is_approved_for_po($after)) {
        $afterType = trim((string) ($after['request_type'] ?? ($after['procurement_type'] ?? 'purchase')));
        if (in_array($afterType, ['hire', 'จัดจ้าง'], true) && class_exists(\Theelincon\Rtdb\Purchase::class)) {
            try {
                \Theelincon\Rtdb\Purchase::createHireContractIfNeededForPr($prId);
            } catch (\Throwable $e) {
                // ไม่ให้การสร้างสัญญาล้มเหลวกระทบผลการอนุมัติ
            }
        }
    }

    // แจ้งเตือนกลับมายังเว็บ (ผู้บันทึก/ผู้ขอ) ว่า PR ถูกอนุมัติ/ไม่อนุมัติ
    if (function_exists('tnc_pr_decision_notify')) {
        try {
            tnc_pr_decision_notify($prId, $decision, is_array($after) ? $after : []);
        } catch (\Throwable $e) {
            // ไม่ให้การแจ้งเตือนล้มเหลวกระทบผลการบันทึก
        }
    }

    $label = $decision === 'approve' ? 'อนุมัติ' : 'ไม่อนุมัติ';

    return ['ok' => true, 'message' => 'บันทึกผลแล้ว: ' . $label . ' (' . $prNo . ')', 'pr_no' => $prNo];
}

/**
 * @return array{ok: bool, message: string}
 */
function line_pr_apply_decision(int $prId, string $decision, string $lineUserId, string $token = ''): array
{
    $approvers = line_notify_approver_line_user_ids();
    if ($approvers === []) {
        return ['ok' => false, 'message' => 'ยังไม่ได้ตั้งผู้อนุมัติในหน้าตั้งค่า LINE'];
    }
    if ($lineUserId === '' || !in_array($lineUserId, $approvers, true)) {
        return ['ok' => false, 'message' => 'บัญชี LINE นี้ไม่มีสิทธิ์อนุมัติ PR'];
    }

    $pr = Db::rowByIdField('purchase_requests', $prId);
    if ($pr === null) {
        return ['ok' => false, 'message' => 'ไม่พบใบขอซื้อ'];
    }

    $expectedToken = trim((string) ($pr['line_approval_token'] ?? ''));
    $token = trim($token);
    if ($expectedToken === '' || $token === '' || !hash_equals($expectedToken, $token)) {
        return ['ok' => false, 'message' => 'ลิงก์อนุมัติไม่ถูกต้อง'];
    }

    $result = line_pr_persist_decision($prId, $decision, [
        'line_user_id' => $lineUserId,
        'web_user_id' => 0,
        'source' => 'line',
        'audit_source' => 'line-webhook',
        'audit_action' => 'line_pr_decision_postback',
        'decision' => $decision,
    ]);

    return ['ok' => $result['ok'], 'message' => $result['message']];
}

/**
 * อนุมัติ/ไม่อนุมัติจากเว็บ (ADMIN เท่านั้น — ตรวจที่ action-handler)
 *
 * @return array{ok: bool, message: string}
 */
function line_pr_apply_decision_web(int $prId, string $decision, int $adminUserId): array
{
    if ($adminUserId <= 0) {
        return ['ok' => false, 'message' => 'ไม่พบผู้ใช้งาน'];
    }

    $result = line_pr_persist_decision($prId, $decision, [
        'line_user_id' => '',
        'web_user_id' => $adminUserId,
        'source' => 'web_admin',
        'audit_source' => 'action-handler',
        'audit_action' => 'pr_web_decision',
        'decision' => $decision,
    ]);

    return ['ok' => $result['ok'], 'message' => $result['message']];
}

/**
 * ตั้งโทเคน + ส่งคำขออนุมัติไป LINE (เรียกเมื่อผู้ใช้ยืนยันส่ง)
 *
 * @return array{ok: bool, sent: int, error: ?string}
 */
function line_pr_prepare_and_send_line(int $prId): array
{
    $pr = Db::rowByIdField('purchase_requests', $prId);
    if ($pr === null) {
        return ['ok' => false, 'sent' => 0, 'error' => 'not_found'];
    }

    $st = line_pr_normalize_status($pr);
    if (!in_array($st, ['pending', 'rejected'], true)) {
        return ['ok' => false, 'sent' => 0, 'error' => 'invalid_status'];
    }

    $pk = Db::pkForLogicalId('purchase_requests', $prId);
    $before = Db::row('purchase_requests', $pk) ?? [];
    // โทเคนคงที่ถาวรต่อ PR: ถ้าเคยมีแล้วให้ใช้ตัวเดิม เพื่อให้ลิงก์ที่ส่งไปก่อนหน้ายังใช้ได้ (ไม่หมดอายุ)
    $stableToken = trim((string) ($before['line_approval_token'] ?? ''));
    if ($stableToken === '') {
        $stableToken = line_pr_new_approval_token();
    }
    Db::setRow('purchase_requests', $pk, array_merge($before, [
        'status' => 'pending',
        'line_approval_token' => $stableToken,
        'line_decision' => '',
        'line_decided_at' => '',
        'line_decided_by_line_user_id' => '',
        'line_decided_by_user_id' => 0,
        'line_decision_source' => '',
    ]));

    return line_pr_send_approval_request($prId);
}
