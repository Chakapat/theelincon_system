<?php

/**
 * ลบโหนดโมดูลที่ถอดออกจาก Firebase RTDB (theelincon_mirror)
 * รันครั้งเดียวหลังถอดระบบที่ไม่ใช้แล้ว
 *
 *   php scripts/purge-removed-modules-rtdb.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config/connect_database.php';

use Theelincon\Rtdb\Db;

foreach (['leave_requests', 'advance_cash_requests', 'attendance_logs', 'quotations', 'quotation_items'] as $table) {
    Db::tableRef($table)->remove();
    fwrite(STDOUT, "Removed RTDB tree: {$table}\n");
}

fwrite(STDOUT, "Done.\n");
