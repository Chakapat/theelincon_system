<?php

declare(strict_types=1);

namespace Theelincon\Rtdb;

/** เลขที่เอกสาร LG-YYYY-###### + ตาราง labor_payroll_doc_seq */
final class LaborPayroll
{
    public static function nextDocNumber(string $periodYm): string
    {
        $year = substr($periodYm, 0, 4);
        if (!preg_match('/^\d{4}$/', $year)) {
            $year = date('Y');
        }
        $maxSeq = 0;
        foreach (Db::tableRows('labor_payroll_archive') as $a) {
            $dn = trim((string) ($a['doc_number'] ?? ''));
            if (preg_match('/^LG-' . preg_quote($year, '/') . '-(\d+)$/', $dn, $m)) {
                $maxSeq = max($maxSeq, (int) $m[1]);
            }
        }
        $seqRow = Db::row('labor_payroll_doc_seq', $year);
        if ($seqRow !== null && isset($seqRow['last_no'])) {
            $maxSeq = max($maxSeq, (int) $seqRow['last_no']);
        }
        $next = $maxSeq + 1;
        Db::setRow('labor_payroll_doc_seq', $year, [
            'seq_y' => $year,
            'last_no' => $next,
        ]);

        return sprintf('LG-%s-%06d', $year, $next);
    }
}
