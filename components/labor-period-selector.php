<?php

declare(strict_types=1);

/**
 * Toolbar เลือกเดือน/งวดสำหรับโมดูล Labor — ต้องกำหนดก่อน include:
 * - $laborPeriodYm (string Y-m)
 * - $laborPeriodHalf (int 1|2)
 * - $laborPeriodAction (string URL ปลายทางของฟอร์ม GET เช่น app_path('pages/labor-payroll/labor-payroll.php'))
 * - $laborPeriodPreserve (array คีย์เพิ่มใน query เช่น group_id)
 * - $laborPeriodInputId (string ไอดี input type=month — ถ้าไม่ส่งจะใช้ laborMonthPick)
 */
$lpYm = preg_match('/^\d{4}-\d{2}$/', (string) ($laborPeriodYm ?? '')) ? (string) $laborPeriodYm : date('Y-m');
$lpHalf = (int) ($laborPeriodHalf ?? 1) === 2 ? 2 : 1;
$lpAction = (string) ($laborPeriodAction ?? '');
$lpPreserve = is_array($laborPeriodPreserve ?? null) ? $laborPeriodPreserve : [];
$lpInputId = isset($laborPeriodInputId) && is_string($laborPeriodInputId) && $laborPeriodInputId !== ''
    ? $laborPeriodInputId
    : 'laborMonthPick';

$dt = DateTime::createFromFormat('Y-m-d', $lpYm . '-01');
if (!$dt) {
    $dt = new DateTime('first day of this month');
    $lpYm = $dt->format('Y-m');
}
$nowYm = (new DateTime('first day of this month'))->format('Y-m');
$prevYm = (clone $dt)->modify('-1 month')->format('Y-m');
$nextYm = (clone $dt)->modify('+1 month')->format('Y-m');
$nextDisabled = $nextYm > $nowYm;

$lpQuery = static function (string $ym, int $half) use ($lpPreserve): array {
    $q = ['month' => $ym, 'half' => $half];
    foreach ($lpPreserve as $k => $v) {
        if ($k === 'month' || $k === 'half') {
            continue;
        }
        $q[$k] = $v;
    }

    return $q;
};

$lpUrl = static function (string $ym, int $half) use ($lpAction, $lpQuery): string {
    $sep = str_contains($lpAction, '?') ? '&' : '?';

    return $lpAction . $sep . http_build_query($lpQuery($ym, $half));
};

$hrefPrev = htmlspecialchars($lpUrl($prevYm, $lpHalf), ENT_QUOTES, 'UTF-8');
$hrefNext = htmlspecialchars($lpUrl($nextYm, $lpHalf), ENT_QUOTES, 'UTF-8');
$hrefToday = htmlspecialchars($lpUrl($nowYm, $lpHalf), ENT_QUOTES, 'UTF-8');
$maxYm = htmlspecialchars($nowYm, ENT_QUOTES, 'UTF-8');

?>
<div class="labor-period-toolbar d-flex flex-wrap align-items-center gap-2">
    <form method="get" action="<?= htmlspecialchars($lpAction, ENT_QUOTES, 'UTF-8') ?>" class="d-flex flex-wrap align-items-center gap-2">
        <?php foreach ($lpPreserve as $pk => $pv): ?>
            <?php if ($pk === 'month' || $pk === 'half') {
                continue;
            } ?>
            <input type="hidden" name="<?= htmlspecialchars((string) $pk, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) $pv, ENT_QUOTES, 'UTF-8') ?>">
        <?php endforeach; ?>
        <label class="small text-muted mb-0" for="<?= htmlspecialchars($lpInputId, ENT_QUOTES, 'UTF-8') ?>">เดือน</label>
        <input type="month" name="month" id="<?= htmlspecialchars($lpInputId, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm"
               style="width:11rem;max-width:100%;" value="<?= htmlspecialchars($lpYm, ENT_QUOTES, 'UTF-8') ?>"
               min="2000-01" max="<?= $maxYm ?>">
        <input type="hidden" name="half" value="<?= $lpHalf ?>">
        <button type="submit" class="btn btn-sm btn-outline-primary">ไป</button>
    </form>
    <div class="btn-group btn-group-sm" role="group" aria-label="เลื่อนเดือน">
        <a class="btn btn-outline-secondary" href="<?= $hrefPrev ?>" title="เดือนก่อน">‹</a>
        <?php if ($nextDisabled): ?>
            <span class="btn btn-outline-secondary disabled" tabindex="-1" title="ยังเลือกเดือนอนาคตไม่ได้">›</span>
        <?php else: ?>
            <a class="btn btn-outline-secondary" href="<?= $hrefNext ?>" title="เดือนถัดไป">›</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= $hrefToday ?>" title="กลับเดือนปัจจุบัน (ยังคงงวดเดิม)">เดือนนี้</a>
    </div>
</div>
