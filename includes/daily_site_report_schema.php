<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

/** เลขที่รายงาน DSR-YYYYMMDD-### — จากตาราง daily_site_reports ใน RTDB */
function daily_site_report_next_number(): string
{
    $prefix = 'DSR-' . date('Ymd') . '-';
    $max = 0;
    foreach (Db::tableRows('daily_site_reports') as $r) {
        $no = (string) ($r['report_no'] ?? '');
        if (strncmp($no, $prefix, strlen($prefix)) === 0 && preg_match('/-(\d+)$/', $no, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }

    return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
}
