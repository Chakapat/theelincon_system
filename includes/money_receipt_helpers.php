<?php

declare(strict_types=1);

/**
 * ใบเสร็จรับเงิน — ฟังก์ชันร่วม (แปลงวันที่ไทย, parse รายการ, คำนวณยอด)
 */

if (!function_exists('money_receipt_thai_months')) {
    /** @return array<int, string> */
    function money_receipt_thai_months(): array
    {
        return [
            1 => 'มกราคม',
            2 => 'กุมภาพันธ์',
            3 => 'มีนาคม',
            4 => 'เมษายน',
            5 => 'พฤษภาคม',
            6 => 'มิถุนายน',
            7 => 'กรกฎาคม',
            8 => 'สิงหาคม',
            9 => 'กันยายน',
            10 => 'ตุลาคม',
            11 => 'พฤศจิกายน',
            12 => 'ธันวาคม',
        ];
    }
}

if (!function_exists('money_receipt_format_date_th')) {
    /** แปลง Y-m-d → «วัน เดือน พ.ศ.» */
    function money_receipt_format_date_th(string $ymd): string
    {
        $ymd = trim($ymd);
        if ($ymd === '' || $ymd === '0000-00-00') {
            return '';
        }
        $ts = strtotime($ymd);
        if ($ts === false) {
            return $ymd;
        }
        $months = money_receipt_thai_months();
        $d = (int) date('j', $ts);
        $mo = (int) date('n', $ts);
        $be = (int) date('Y', $ts) + 543;

        return $d . ' ' . ($months[$mo] ?? '') . ' พ.ศ. ' . $be;
    }
}

if (!function_exists('money_receipt_parse_money')) {
    function money_receipt_parse_money(string $raw): float
    {
        $s = str_replace([',', ' '], '', trim($raw));

        return $s === '' ? 0.0 : (float) $s;
    }
}

if (!function_exists('money_receipt_parse_items_from_post')) {
    /**
     * @return list<array{detail:string, deduct:float, receive:float}>
     */
    function money_receipt_parse_items_from_post(array $post): array
    {
        $details = $post['item_detail'] ?? [];
        $deducts = $post['item_deduct'] ?? [];
        $receives = $post['item_receive'] ?? [];
        if (!is_array($details)) {
            $details = [];
        }
        if (!is_array($deducts)) {
            $deducts = [];
        }
        if (!is_array($receives)) {
            $receives = [];
        }
        $n = max(count($details), count($deducts), count($receives));
        $items = [];
        for ($i = 0; $i < $n; ++$i) {
            $detail = trim((string) ($details[$i] ?? ''));
            $deduct = money_receipt_parse_money((string) ($deducts[$i] ?? ''));
            $receive = money_receipt_parse_money((string) ($receives[$i] ?? ''));
            if ($detail === '' && abs($deduct) < 0.000001 && abs($receive) < 0.000001) {
                continue;
            }
            $items[] = ['detail' => $detail, 'deduct' => round($deduct, 2), 'receive' => round($receive, 2)];
        }

        return $items;
    }
}

if (!function_exists('money_receipt_items_from_json_field')) {
    /**
     * @return list<array{detail:string, deduct:float, receive:float}>
     */
    function money_receipt_items_from_json_field(?string $json): array
    {
        $json = trim((string) $json);
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'detail' => trim((string) ($row['detail'] ?? '')),
                'deduct' => round((float) ($row['deduct'] ?? 0), 2),
                'receive' => round((float) ($row['receive'] ?? 0), 2),
            ];
        }

        return $out;
    }
}

if (!function_exists('money_receipt_totals')) {
    /**
     * @param list<array{detail:string, deduct:float, receive:float}> $items
     * @return array{sum_deduct:float, sum_receive:float, net:float}
     */
    function money_receipt_totals(array $items): array
    {
        $sumD = 0.0;
        $sumR = 0.0;
        foreach ($items as $it) {
            $sumD += $it['deduct'];
            $sumR += $it['receive'];
        }
        $sumD = round($sumD, 2);
        $sumR = round($sumR, 2);
        $net = round($sumR - $sumD, 2);

        return ['sum_deduct' => $sumD, 'sum_receive' => $sumR, 'net' => $net];
    }
}

if (!function_exists('money_receipt_slip_web_url')) {
    function money_receipt_slip_web_url(string $relative): string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return '';
        }

        return app_path($relative);
    }
}

if (!function_exists('money_receipt_pay_flags')) {
    /**
     * @return array{pay_cash:int, pay_transfer:int, pay_check:int}
     */
    function money_receipt_pay_flags(array $post): array
    {
        $cash = isset($post['pay_cash']) ? 1 : 0;
        $transfer = isset($post['pay_transfer']) ? 1 : 0;
        $check = isset($post['pay_check']) ? 1 : 0;

        return ['pay_cash' => $cash, 'pay_transfer' => $transfer, 'pay_check' => $check];
    }
}

if (!function_exists('money_receipt_validate_doc_date')) {
    function money_receipt_validate_doc_date(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $raw);

        return $dt instanceof DateTimeImmutable ? $dt->format('Y-m-d') : null;
    }
}

if (!function_exists('money_receipt_next_receipt_no')) {
    /** สร้างเลขเอกสารรูปแบบ RCPT-TNC-YYMM-### */
    function money_receipt_next_receipt_no(string $docDateYmd): string
    {
        $ts = strtotime($docDateYmd !== '' ? $docDateYmd : date('Y-m-d'));
        if ($ts === false) {
            $ts = time();
        }
        $yyMm = date('ym', $ts);
        $prefix = 'RCPT-TNC-' . $yyMm . '-';
        $max = 0;
        foreach (\Theelincon\Rtdb\Db::tableRows('money_receipts') as $row) {
            $no = strtoupper(trim((string) ($row['receipt_no'] ?? '')));
            if (strpos($no, strtoupper($prefix)) !== 0) {
                continue;
            }
            $tail = (int) substr($no, strlen($prefix));
            if ($tail > $max) {
                $max = $tail;
            }
        }

        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('money_receipt_baht_text')) {
    /**
     * แปลงตัวเลขเป็นคำอ่านบาทไทย เช่น 30000.00 => สามหมื่นบาทถ้วน
     * อิงรูปแบบการอ่านไทยมาตรฐานแนวเดียวกับไลบรารี Thai Baht Text
     */
    function money_receipt_baht_text(float $amount): string
    {
        $amount = round($amount, 2);
        if ($amount == 0.0) {
            return 'ศูนย์บาทถ้วน';
        }

        $number = number_format(abs($amount), 2, '.', '');
        [$intPart, $satangPart] = explode('.', $number);

        $readNumber = null;
        $readNumber = static function (string $num) use (&$readNumber): string {
            $digitText = ['ศูนย์', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
            $unitText = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน', 'ล้าน'];
            $num = ltrim($num, '0');
            if ($num === '') {
                return '';
            }
            $len = strlen($num);
            $out = '';
            for ($i = 0; $i < $len; ++$i) {
                $digit = (int) $num[$i];
                if ($digit === 0) {
                    continue;
                }
                $pos = $len - $i - 1;
                if ($pos >= 6) {
                    $left = substr($num, 0, $len - 6);
                    $right = substr($num, $len - 6);

                    return $readNumber($left) . 'ล้าน' . $readNumber($right);
                }
                if ($pos === 0 && $digit === 1 && $len > 1) {
                    $out .= 'เอ็ด';
                } elseif ($pos === 1 && $digit === 1) {
                    $out .= 'สิบ';
                } elseif ($pos === 1 && $digit === 2) {
                    $out .= 'ยี่สิบ';
                } else {
                    $out .= $digitText[$digit] . $unitText[$pos];
                }
            }

            return $out;
        };

        $text = $readNumber($intPart) . 'บาท';
        if ($satangPart === '00') {
            $text .= 'ถ้วน';
        } else {
            $text .= $readNumber($satangPart) . 'สตางค์';
        }

        return $amount < 0 ? 'ลบ' . $text : $text;
    }
}
