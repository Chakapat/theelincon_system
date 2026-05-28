<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

/** ~LINE Flex Message practical JSON size limit (official ~12KB; stay under for safety). */
const CASH_LEDGER_FLEX_JSON_BUDGET = 10000;

function cash_ledger_daily_money(float $n): string
{
    return number_format($n, 2, '.', ',');
}

/** @param string $ymd `Y-m-d` from ledger */
function cash_ledger_daily_report_date_display(string $ymd): string
{
    $ymd = trim($ymd);
    if ($ymd === '') {
        return '';
    }
    $ts = strtotime($ymd . ' 12:00:00');
    if ($ts === false) {
        return $ymd;
    }

    return date('d / m / Y', $ts);
}

/**
 * Running balance across all ledger rows (income +, expense −), chronological.
 */
function cash_ledger_daily_current_balance(): float
{
    $rows = [];
    foreach (Db::tableRows('cash_ledger') as $c) {
        $ed = (string) ($c['entry_date'] ?? '');
        if ($ed === '') {
            continue;
        }
        $rows[] = $c;
    }
    usort(
        $rows,
        static function (array $a, array $b): int {
            $cmp = strcmp((string) ($a['entry_date'] ?? ''), (string) ($b['entry_date'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
        }
    );
    $bal = 0.0;
    foreach ($rows as $c) {
        $amt = (float) ($c['amount'] ?? 0);
        $bal += (($c['entry_type'] ?? '') === 'income') ? $amt : -$amt;
    }

    return round($bal, 2);
}

/**
 * @return list<array<string, mixed>>
 */
function cash_ledger_daily_entries_for_date(string $ymd): array
{
    $out = [];
    foreach (Db::tableRows('cash_ledger') as $c) {
        if ((string) ($c['entry_date'] ?? '') !== $ymd) {
            continue;
        }
        $out[] = $c;
    }
    usort(
        $out,
        static function (array $a, array $b): int {
            $cmp = (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) ($a['entry_date'] ?? ''), (string) ($b['entry_date'] ?? ''));
        }
    );

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function cash_ledger_daily_lines_for_ledger(int $ledgerId): array
{
    $lines = [];
    foreach (Db::filter('cash_ledger_lines', static function (array $r) use ($ledgerId): bool {
        return isset($r['ledger_id']) && (int) $r['ledger_id'] === $ledgerId;
    }) as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $lines[] = $ln;
    }
    usort(
        $lines,
        static function (array $a, array $b): int {
            return (int) ($a['line_no'] ?? 0) <=> (int) ($b['line_no'] ?? 0);
        }
    );

    return $lines;
}

/**
 * @return array{0: float, 1: float} [daily_expense_total, current_remaining_balance]
 */
function cash_ledger_daily_report_totals(array $allEntriesForDay): array
{
    $dailyExpense = 0.0;
    foreach ($allEntriesForDay as $entry) {
        if (($entry['entry_type'] ?? '') === 'expense') {
            $dailyExpense += round((float) ($entry['amount'] ?? 0), 2);
        }
    }

    return [$dailyExpense, cash_ledger_daily_current_balance()];
}

function cash_ledger_daily_flex_truncate(string $text, int $maxLen = 1800): string
{
    $t = trim($text);
    if (mb_strlen($t, 'UTF-8') <= $maxLen) {
        return $t;
    }

    return rtrim(mb_substr($t, 0, $maxLen - 1, 'UTF-8')) . '…';
}

function cash_ledger_daily_entry_title_head(array $entry): string
{
    $category = trim((string) ($entry['category'] ?? ''));
    $desc = trim((string) ($entry['description'] ?? ''));
    $headBits = array_filter([$category !== '' ? $category : null, $desc !== '' ? $desc : null], static fn ($x) => $x !== null);

    return $headBits !== [] ? implode(' · ', $headBits) : '(no title)';
}

function cash_ledger_daily_entry_main_title(array $entry): string
{
    $type = (string) ($entry['entry_type'] ?? '');
    $head = cash_ledger_daily_entry_title_head($entry);
    $typeLabel = $type === 'income' ? 'รับ' : ($type === 'expense' ? 'จ่าย' : $type);

    return cash_ledger_daily_flex_truncate("{$typeLabel} {$head}", 600);
}

/**
 * @return list<array<string, mixed>>
 */
function cash_ledger_daily_flex_entry_sections(array $entry): array
{
    $type = (string) ($entry['entry_type'] ?? '');
    if ($type === 'expense') {
        $prefix = 'จ่าย ';
        $prefixColor = '#dc2626';
    } elseif ($type === 'income') {
        $prefix = 'รับ ';
        $prefixColor = '#059669';
    } else {
        $prefix = ($type !== '' ? $type . ' ' : '? ');
        $prefixColor = '#6b7280';
    }

    $id = (int) ($entry['id'] ?? 0);
    $subLines = $id > 0 ? cash_ledger_daily_lines_for_ledger($id) : [];

    $lineBlocks = [];
    if (count($subLines) === 0) {
        $head = cash_ledger_daily_flex_truncate(cash_ledger_daily_entry_title_head($entry), 580);
        $lineBlocks[] = [
            'type' => 'text',
            'wrap' => true,
            'contents' => [
                ['type' => 'span', 'text' => $prefix, 'weight' => 'bold', 'size' => 'md', 'color' => $prefixColor],
                ['type' => 'span', 'text' => $head, 'weight' => 'bold', 'size' => 'md', 'color' => '#111827'],
            ],
        ];
    } else {
        foreach ($subLines as $sl) {
            $name = trim((string) ($sl['item_description'] ?? ''));
            if ($name === '') {
                $name = '(item)';
            }
            $name = cash_ledger_daily_flex_truncate($name, 450);
            $qty = (float) ($sl['quantity'] ?? 0);
            $price = (float) ($sl['unit_price'] ?? 0);
            $lineTotal = round((float) ($sl['line_total'] ?? 0), 2);
            if ($lineTotal <= 0.0 && ($qty > 0.0 || $price > 0.0)) {
                $lineTotal = round($qty * $price, 2);
            }
            $detail = ' ' . cash_ledger_daily_flex_truncate('฿' . cash_ledger_daily_money($lineTotal), 200);

            $lineBlocks[] = [
                'type' => 'text',
                'wrap' => true,
                'contents' => [
                    ['type' => 'span', 'text' => $prefix, 'weight' => 'bold', 'size' => 'md', 'color' => $prefixColor],
                    ['type' => 'span', 'text' => $name, 'weight' => 'bold', 'size' => 'md', 'color' => '#111827'],
                    ['type' => 'span', 'text' => $detail, 'size' => 'xs', 'color' => '#9ca3af'],
                ],
            ];
        }
    }

    return [[
        'type' => 'box',
        'layout' => 'vertical',
        'margin' => 'sm',
        'spacing' => 'xs',
        'contents' => $lineBlocks,
    ]];
}

/**
 * @param list<array<string, mixed>> $entryChunks
 */
function cash_ledger_daily_flex_message_size_bytes(string $reportDate, array $entryChunks, float $dailyExpense, float $remainingBalance, int $part, int $partsTotal, bool $showTotalsFooter): int
{
    $bubble = cash_ledger_daily_flex_build_bubble($reportDate, $entryChunks, $dailyExpense, $remainingBalance, $part, $partsTotal, $showTotalsFooter);
    $msg = [
        'type' => 'flex',
        'altText' => 'รายงานสดย่อย',
        'contents' => $bubble,
    ];

    return strlen(json_encode($msg, JSON_UNESCAPED_UNICODE));
}

/**
 * @param list<array<string, mixed>> $entriesSlice
 *
 * @return array<string, mixed>
 */
function cash_ledger_daily_flex_build_bubble(
    string $reportDate,
    array $entriesSlice,
    float $dailyExpense,
    float $remainingBalance,
    int $partIndex,
    int $partsTotal,
    bool $showTotalsFooter
): array {
    $bodyContents = [];

    if (count($entriesSlice) === 0) {
        $bodyContents[] = [
            'type' => 'text',
            'text' => 'ไม่มีรายการในวันนี้',
            'size' => 'sm',
            'color' => '#6b7280',
            'wrap' => true,
        ];
    } else {
        foreach ($entriesSlice as $idx => $entry) {
            if ($idx > 0) {
                $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];
            }
            foreach (cash_ledger_daily_flex_entry_sections($entry) as $block) {
                $bodyContents[] = $block;
            }
        }
    }

    $headerSub = cash_ledger_daily_report_date_display($reportDate);
    if ($partsTotal > 1) {
        $headerSub .= " · ส่วน {$partIndex}/{$partsTotal}";
    }

    $bubble = [
        'type' => 'bubble',
        'size' => 'giga',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '18px',
            'backgroundColor' => '#1e40af',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => 'รายงานสดย่อย',
                    'weight' => 'bold',
                    'size' => 'xl',
                    'color' => '#ffffff',
                ],
                [
                    'type' => 'text',
                    'text' => $headerSub,
                    'size' => 'sm',
                    'color' => '#bfdbfe',
                    'margin' => '6px',
                ],
            ],
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '16px',
            'spacing' => 'sm',
            'contents' => $bodyContents,
        ],
    ];

    if ($showTotalsFooter) {
        $bubble['footer'] = [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '16px',
            'backgroundColor' => '#f8fafc',
            'spacing' => 'md',
            'contents' => [
                ['type' => 'separator', 'color' => '#e2e8f0'],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => 'ยอดจ่ายวันนี้',
                            'size' => 'sm',
                            'flex' => 3,
                            'weight' => 'bold',
                            'color' => '#b91c1c',
                            'wrap' => true,
                        ],
                        [
                            'type' => 'text',
                            'text' => '฿' . cash_ledger_daily_money($dailyExpense),
                            'size' => 'md',
                            'flex' => 2,
                            'align' => 'end',
                            'weight' => 'bold',
                            'color' => '#dc2626',
                        ],
                    ],
                ],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => 'ยอดคงเหลือ',
                            'size' => 'sm',
                            'flex' => 3,
                            'weight' => 'bold',
                            'color' => '#374151',
                            'wrap' => true,
                        ],
                        [
                            'type' => 'text',
                            'text' => '฿' . cash_ledger_daily_money($remainingBalance),
                            'size' => 'md',
                            'flex' => 2,
                            'align' => 'end',
                            'weight' => 'bold',
                            'color' => '#059669',
                        ],
                    ],
                ],
            ],
        ];
    } else {
        $bubble['footer'] = [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '12px',
            'backgroundColor' => '#f8fafc',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => "ส่วน {$partIndex}/{$partsTotal} — ดูยอดรวมในข้อความถัดไป",
                    'size' => 'xs',
                    'color' => '#94a3b8',
                    'align' => 'center',
                    'wrap' => true,
                ],
            ],
        ];
    }

    return $bubble;
}

function cash_ledger_daily_flex_alt_text(string $reportDate, int $part, int $partsTotal): string
{
    $d = cash_ledger_daily_report_date_display($reportDate);
    $alt = "รายงานสดย่อย {$d}";
    if ($partsTotal > 1) {
        $alt .= " ({$part}/{$partsTotal})";
    }

    return cash_ledger_daily_flex_truncate($alt, 380);
}

/**
 * Split entries into chunks so each Flex message stays under JSON budget.
 *
 * @param list<array<string, mixed>> $entries
 *
 * @return list<list<array<string, mixed>>>
 */
function cash_ledger_daily_flex_chunk_entries(string $reportDate, array $entries, float $dailyExpense, float $remainingBalance): array
{
    if (count($entries) === 0) {
        return [[]];
    }

    $chunks = [];
    $current = [];

    foreach ($entries as $entry) {
        $trial = array_merge($current, [$entry]);
        $bytes = cash_ledger_daily_flex_message_size_bytes($reportDate, $trial, $dailyExpense, $remainingBalance, 1, 1, true);

        if ($bytes <= CASH_LEDGER_FLEX_JSON_BUDGET) {
            $current = $trial;
            continue;
        }

        if (count($current) > 0) {
            $chunks[] = $current;
            $current = [];
        }

        $singleBytes = cash_ledger_daily_flex_message_size_bytes($reportDate, [$entry], $dailyExpense, $remainingBalance, 1, 1, true);
        if ($singleBytes > CASH_LEDGER_FLEX_JSON_BUDGET) {
            $chunks[] = [$entry];
            continue;
        }

        $current = [$entry];
    }

    if (count($current) > 0) {
        $chunks[] = $current;
    }

    return count($chunks) > 0 ? $chunks : [[]];
}

/**
 * Build LINE Messaging API flex messages (one push may contain multiple bubbles as separate messages).
 *
 * @param list<array<string, mixed>> $entries
 *
 * @return list<array{type: string, altText: string, contents: array<string, mixed>}>
 */
function cash_ledger_daily_build_flex_messages(string $reportDate, array $entries): array
{
    [$dailyExpense, $remainingBalance] = cash_ledger_daily_report_totals($entries);
    $chunks = cash_ledger_daily_flex_chunk_entries($reportDate, $entries, $dailyExpense, $remainingBalance);
    $partsTotal = count($chunks);
    $out = [];
    $i = 0;

    foreach ($chunks as $slice) {
        ++$i;
        $showTotals = ($i === $partsTotal);
        $bubble = cash_ledger_daily_flex_build_bubble(
            $reportDate,
            $slice,
            $dailyExpense,
            $remainingBalance,
            $i,
            $partsTotal,
            $showTotals
        );
        $out[] = [
            'type' => 'flex',
            'altText' => cash_ledger_daily_flex_alt_text($reportDate, $i, $partsTotal),
            'contents' => $bubble,
        ];
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $messages Each element: LINE message object (e.g. flex)
 */
function cash_ledger_daily_line_push(string $channelToken, string $targetId, array $messages): bool
{
    $okAll = true;
    $batchIdx = 0;
    $batches = array_chunk($messages, 5);
    $totalBatches = count($batches);
    foreach ($batches as $batch) {
        ++$batchIdx;
        $payload = [
            'to' => $targetId,
            'messages' => array_values($batch),
        ];
        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $channelToken,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo = curl_errno($ch);
        curl_close($ch);
        $ok = $errNo === 0 && $response !== false && $status >= 200 && $status < 300;
        if (!$ok) {
            $okAll = false;
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "LINE push failed batch {$batchIdx}/{$totalBatches} HTTP {$status} errno {$errNo}\n");
                if (is_string($response)) {
                    fwrite(STDERR, $response . "\n");
                }
            }
        }
    }

    return $okAll;
}

/**
 * Reply API: up to 5 messages per request.
 *
 * @param list<array<string, mixed>> $messages
 */
function cash_ledger_daily_line_reply(string $channelToken, string $replyToken, array $messages): bool
{
    $messages = array_values(array_slice($messages, 0, 5));
    if ($messages === []) {
        return false;
    }
    $payload = [
        'replyToken' => $replyToken,
        'messages' => $messages,
    ];
    $ch = curl_init('https://api.line.me/v2/bot/message/reply');
    if ($ch === false) {
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $channelToken,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo = curl_errno($ch);
    curl_close($ch);

    return $errNo === 0 && $response !== false && $status >= 200 && $status < 300;
}

/**
 * First batch uses replyToken (max 5 messages); further batches push to group/user/room (max 5 each).
 *
 * @param list<array<string, mixed>> $messages
 */
function cash_ledger_daily_line_deliver_reply_then_push(string $channelToken, string $replyToken, string $pushToId, array $messages): bool
{
    if ($messages === []) {
        return false;
    }
    $batches = array_chunk($messages, 5);
    $ok = true;
    foreach ($batches as $idx => $batch) {
        if ($idx === 0) {
            $ok = cash_ledger_daily_line_reply($channelToken, $replyToken, $batch) && $ok;
            continue;
        }
        if ($pushToId === '') {
            return false;
        }
        $ok = cash_ledger_daily_line_push($channelToken, $pushToId, array_values($batch)) && $ok;
    }

    return $ok;
}

/** @deprecated Plain-text report; use cash_ledger_daily_build_flex_messages() for LINE. */
function cash_ledger_daily_build_message(string $reportDate, array $entries): string
{
    $lines = [];
    $lines[] = 'รายงานสดย่อย: ' . cash_ledger_daily_report_date_display($reportDate);
    $lines[] = str_repeat('—', 24);

    $dailyExpense = 0.0;

    if (count($entries) === 0) {
        $lines[] = '(No transactions for this date.)';
    } else {
        $n = 0;
        foreach ($entries as $entry) {
            ++$n;
            $id = (int) ($entry['id'] ?? 0);
            $type = (string) ($entry['entry_type'] ?? '');
            $category = trim((string) ($entry['category'] ?? ''));
            $desc = trim((string) ($entry['description'] ?? ''));
            $amount = round((float) ($entry['amount'] ?? 0), 2);

            if ($type === 'expense') {
                $dailyExpense += $amount;
            }

            $headBits = array_filter([$category !== '' ? $category : null, $desc !== '' ? $desc : null], static fn ($x) => $x !== null);
            $head = $headBits !== [] ? implode(' — ', $headBits) : '(no title)';
            $typeLabel = $type === 'income' ? 'รับ' : ($type === 'expense' ? 'จ่าย' : $type);
            $lines[] = sprintf('#%d %s %s', $n, $typeLabel, $head);

            $subLines = $id > 0 ? cash_ledger_daily_lines_for_ledger($id) : [];
            if (count($subLines) === 0) {
                $lines[] = '  (single amount) | — | — | ' . cash_ledger_daily_money($amount);
            } else {
                foreach ($subLines as $sl) {
                    $name = trim((string) ($sl['item_description'] ?? ''));
                    if ($name === '') {
                        $name = '(item)';
                    }
                    $qty = (float) ($sl['quantity'] ?? 0);
                    $price = (float) ($sl['unit_price'] ?? 0);
                    $sub = round((float) ($sl['line_total'] ?? 0), 2);
                    $lines[] = sprintf(
                        '  %s | %s | %s | %s',
                        $name,
                        rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.'),
                        cash_ledger_daily_money($price),
                        cash_ledger_daily_money($sub)
                    );
                }
            }
            $lines[] = '  Entry total: ' . cash_ledger_daily_money($amount);
            $lines[] = '';
        }
    }

    $remaining = cash_ledger_daily_current_balance();
    $lines[] = str_repeat('—', 24);
    $lines[] = 'Total Daily Expense: ' . cash_ledger_daily_money($dailyExpense);
    $lines[] = 'Current Remaining Balance: ' . cash_ledger_daily_money($remaining);

    return implode("\n", $lines);
}

/** @deprecated Used with plain-text messages only. */
function cash_ledger_daily_chunk_message(string $text, int $maxLen = 4800): array
{
    if (strlen($text) <= $maxLen) {
        return [$text];
    }
    $chunks = [];
    $remaining = $text;
    while ($remaining !== '') {
        if (strlen($remaining) <= $maxLen) {
            $chunks[] = $remaining;
            break;
        }
        $cut = strrpos(substr($remaining, 0, $maxLen), "\n");
        if ($cut === false || $cut < $maxLen / 2) {
            $cut = $maxLen;
        }
        $chunks[] = substr($remaining, 0, $cut);
        $remaining = ltrim(substr($remaining, $cut));
    }

    return $chunks;
}
