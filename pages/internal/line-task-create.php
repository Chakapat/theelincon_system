<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/line_task_assignees.php';
require_once dirname(__DIR__, 2) . '/includes/line_notify_runtime.php';
require_once dirname(__DIR__, 2) . '/includes/line_task_order.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_flash.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_shell_head.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_ui.php';

use Theelincon\Rtdb\Db;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_can('page.internal.line_task')) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
}

$assignees = line_task_assignees_list(true);
$sites = line_task_sites_list();
$assigneeCount = count($assignees);
$siteCount = count($sites);
$taskGroupId = line_effective_task_group_id();
$hasToken = line_effective_channel_access_token() !== '';
$setupReady = $hasToken && $taskGroupId !== '' && $assignees !== [] && $sites !== [];

$errorCode = trim((string) ($_GET['error'] ?? ''));
$sentOk = !empty($_GET['sent']);
$sentTaskId = (int) ($_GET['task_id'] ?? 0);
$deletedOk = !empty($_GET['deleted']);

$errorMessages = [
    'csrf' => 'คำขอไม่ถูกต้อง กรุณาลองใหม่',
    'need_assignee' => 'กรุณาเลือกผู้รับผิดชอบ',
    'need_destination' => 'กรุณาเลือกไซต์ปลายทาง',
    'site_not_found' => 'ไม่พบไซต์ที่เลือก',
    'need_details' => 'กรุณาระบุรายละเอียดงาน',
    'invalid_due' => 'กำหนดวันสิ้นสุดไม่ถูกต้อง',
    'assignee_not_found' => 'ไม่พบผู้รับผิดชอบที่เลือก',
    'no_token' => 'ยังไม่ได้ตั้งค่า Channel Access Token',
    'no_group' => 'ยังไม่ได้ตั้งค่ากลุ่ม LINE สั่งงาน',
    'push_failed' => 'ส่ง LINE ไม่สำเร็จ ตรวจสอบ Token และ Group ID',
    'send_failed' => 'ส่งใบสั่งงานไม่สำเร็จ',
    'invalid_id' => 'ไม่พบรายการที่ต้องการลบ',
    'task_not_found' => 'ไม่พบใบสั่งงานที่ต้องการลบ',
    'delete_failed' => 'ลบใบสั่งงานไม่สำเร็จ',
];
$errorText = $errorMessages[$errorCode] ?? ($errorCode !== '' ? $errorCode : '');

$flash = null;
if ($errorText !== '') {
    $flash = ['type' => 'danger', 'message' => $errorText];
} elseif ($sentOk) {
    $flashMsg = 'ส่งใบสั่งงานเรียบร้อย';
    if ($sentTaskId > 0) {
        $flashMsg .= ' (' . line_task_order_no($sentTaskId) . ')';
    }
    $flash = ['type' => 'success', 'message' => $flashMsg, 'audio' => 'create'];
} elseif ($deletedOk) {
    $flash = ['type' => 'success', 'message' => 'ลบใบสั่งงานเรียบร้อย'];
}

$recentTasks = Db::tableRows(LINE_TASKS_TABLE);
usort($recentTasks, static function (array $a, array $b): int {
    return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
});
$recentTasks = array_slice($recentTasks, 0, 10);

$deleteTaskAction = app_path('actions/line-task-handler.php?action=delete_task');
$defaultDueDate = date('d/m/Y', strtotime('+1 day'));
$siteNameById = [];
foreach ($sites as $siteRow) {
    $sid = (int) ($siteRow['id'] ?? 0);
    if ($sid > 0) {
        $siteNameById[$sid] = (string) ($siteRow['name'] ?? '');
    }
}
$lineTaskJsPath = app_path('assets/js/line-task-create.js');
$lineTaskJsVer = @filemtime(dirname(__DIR__, 2) . '/assets/js/line-task-create.js') ?: time();
$configPath = app_path('pages/internal/line-notify-config.php');
$assigneesPath = app_path('pages/internal/line-task-assignees.php');
$sitesPath = app_path('pages/sites/site-picker.php');
$formDisabled = !$setupReady;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php tnc_shell_head([
        'title' => 'สั่งงาน LINE | THEELIN CON',
        'extra_css' => ['assets/css/line-task-create.css'],
        'flatpickr' => true,
        'sarabun_weights' => '400;600;700;800',
    ]); ?>
</head>
<body class="tnc-app-body">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 pb-5 line-task-page">
    <?php tnc_ui_page_head([
        'kicker' => 'LINE Task',
        'title' => 'สั่งงาน LINE',
        'icon' => 'bi-clipboard-check',
        'class' => 'mb-3',
        'actions_html' => '<a href="' . htmlspecialchars($assigneesPath, ENT_QUOTES, 'UTF-8') . '" class="btn btn-outline-orange rounded-pill">'
            . '<i class="bi bi-people me-1" aria-hidden="true"></i>รายชื่อผู้รับงาน</a>'
            . '<a href="' . htmlspecialchars($configPath, ENT_QUOTES, 'UTF-8') . '" class="btn btn-outline-secondary rounded-pill">'
            . '<i class="bi bi-gear me-1" aria-hidden="true"></i>ตั้งค่า LINE</a>',
    ]); ?>

    <div class="line-task-setup" role="list" aria-label="สถานะการตั้งค่า LINE">
        <a href="<?= htmlspecialchars($configPath, ENT_QUOTES, 'UTF-8') ?>"
           class="line-task-setup__item <?= $hasToken ? 'is-ok' : 'is-warn' ?>" role="listitem">
            <i class="bi <?= $hasToken ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>" aria-hidden="true"></i>
            Channel Token
        </a>
        <a href="<?= htmlspecialchars($configPath, ENT_QUOTES, 'UTF-8') ?>"
           class="line-task-setup__item <?= $taskGroupId !== '' ? 'is-ok' : 'is-warn' ?>" role="listitem">
            <i class="bi <?= $taskGroupId !== '' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>" aria-hidden="true"></i>
            กลุ่มสั่งงาน
        </a>
        <a href="<?= htmlspecialchars($assigneesPath, ENT_QUOTES, 'UTF-8') ?>"
           class="line-task-setup__item <?= $assignees !== [] ? 'is-ok' : 'is-warn' ?>" role="listitem">
            <i class="bi <?= $assignees !== [] ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>" aria-hidden="true"></i>
            ผู้รับงาน (<?= (int) $assigneeCount ?>)
        </a>
        <a href="<?= htmlspecialchars($sitesPath, ENT_QUOTES, 'UTF-8') ?>"
           class="line-task-setup__item <?= $sites !== [] ? 'is-ok' : 'is-warn' ?>" role="listitem">
            <i class="bi <?= $sites !== [] ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>" aria-hidden="true"></i>
            ไซต์งาน (<?= (int) $siteCount ?>)
        </a>
    </div>

    <?php tnc_render_flash($flash); ?>

    <div class="row g-4 align-items-start">
        <div class="col-lg-7">
            <section class="tnc-list-card line-task-form-card" aria-labelledby="line-task-form-heading">
                <h2 id="line-task-form-heading" class="h5 fw-bold mb-1">ฟอร์มสั่งงาน</h2>
                <form method="post"
                      action="<?= htmlspecialchars(app_path('actions/line-task-handler.php?action=send_task'), ENT_QUOTES, 'UTF-8') ?>"
                      id="task-form"
                      novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_task">

                    <p class="line-task-section-title">ผู้รับผิดชอบ</p>
                    <div class="mb-4">
                        <label class="form-label" for="assignee_id">เลือกผู้รับงาน <span class="text-danger">*</span></label>
                        <select class="form-select" id="assignee_id" name="assignee_id" required <?= $formDisabled ? 'disabled' : '' ?>>
                            <option value="">— เลือกผู้รับงาน —</option>
                            <?php foreach ($assignees as $a): ?>
                                <option value="<?= (int) ($a['id'] ?? 0) ?>"
                                    data-name="<?= htmlspecialchars((string) ($a['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string) ($a['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($assignees === []): ?>
                            <div class="form-text">
                                <a href="<?= htmlspecialchars($assigneesPath, ENT_QUOTES, 'UTF-8') ?>">เพิ่มรายชื่อผู้รับงาน</a> ก่อนส่งใบสั่งงาน
                            </div>
                        <?php endif; ?>
                    </div>

                    <p class="line-task-section-title">รายละเอียดงาน</p>
                    <div class="mb-3">
                        <label class="form-label" for="site_id">สถานที่ปลายทาง <span class="text-danger">*</span></label>
                        <select class="form-select" id="site_id" name="site_id" required <?= $formDisabled ? 'disabled' : '' ?>>
                            <option value="">— เลือกไซต์งาน —</option>
                            <?php foreach ($sites as $site): ?>
                                <?php $sid = (int) ($site['id'] ?? 0); ?>
                                <?php $siteName = (string) ($site['name'] ?? ''); ?>
                                <option value="<?= $sid ?>"
                                    data-name="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($sites === []): ?>
                            <div class="form-text">
                                <a href="<?= htmlspecialchars($sitesPath, ENT_QUOTES, 'UTF-8') ?>">เพิ่มไซต์งาน</a> ก่อนส่งใบสั่งงาน
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="details">รายละเอียด <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="details" name="details" rows="5" required maxlength="2000"
                                  <?= $formDisabled ? 'disabled' : '' ?>></textarea>
                        <div class="line-task-char-count" id="details-char-count" aria-live="polite">0 / 2000</div>
                    </div>

                    <p class="line-task-section-title">กำหนดเสร็จ</p>
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <label class="form-label" for="due_date">วันสิ้นสุด <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="due_date" name="due_date" required
                                   inputmode="numeric" autocomplete="off" placeholder="วัน/เดือน/ปี เช่น 04/07/2026"
                                   value="<?= htmlspecialchars($defaultDueDate, ENT_QUOTES, 'UTF-8') ?>"
                                   <?= $formDisabled ? 'disabled' : '' ?>>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="due_time">เวลา <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="due_time" name="due_time" required value="17:00"
                                   <?= $formDisabled ? 'disabled' : '' ?>>
                        </div>
                    </div>

                    <div class="d-none d-lg-block">
                        <button type="submit" class="btn btn-orange rounded-pill px-4 fw-bold" id="btn-send-task"
                                <?= $formDisabled ? 'disabled' : '' ?>>
                            <span class="label-default"><i class="bi bi-send-fill me-1" aria-hidden="true"></i>ส่งใบสั่งงานไป LINE</span>
                            <span class="label-loading d-none"><span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>กำลังส่ง...</span>
                        </button>
                    </div>
                </form>
            </section>
        </div>

        <div class="col-lg-5">
            <aside class="line-task-preview-sticky" aria-labelledby="line-preview-heading">
                <h2 id="line-preview-heading" class="visually-hidden">ตัวอย่าง Card ใน LINE</h2>
                <div class="line-preview-card" aria-live="polite">
                    <div class="line-preview-card__head">
                        <p class="line-preview-card__kicker">ใบสั่งงาน</p>
                        <p class="line-preview-card__no" id="preview-no">WO-00000</p>
                    </div>
                    <div class="line-preview-card__body">
                        <div class="line-preview-kv">
                            <span class="line-preview-kv__label">วันที่สั่ง</span>
                            <span class="line-preview-kv__value" id="preview-ordered"><?= htmlspecialchars(line_task_format_now_th(), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="line-preview-kv">
                            <span class="line-preview-kv__label">สถานที่ปลายทาง</span>
                            <span class="line-preview-kv__value" id="preview-destination">—</span>
                        </div>
                        <div class="line-preview-kv">
                            <span class="line-preview-kv__label">ผู้รับผิดชอบ</span>
                            <span class="line-preview-kv__value text-primary" id="preview-assignee">—</span>
                        </div>
                        <div class="line-preview-kv">
                            <span class="line-preview-kv__label">ภายใน</span>
                            <span class="line-preview-kv__value" id="preview-due">—</span>
                        </div>
                        <div class="line-preview-details">
                            <div class="line-preview-details__label">รายละเอียด</div>
                            <div class="line-preview-details__text" id="preview-details">—</div>
                        </div>
                    </div>
                    <div class="line-preview-card__foot">
                        <div class="line-preview-btns-row" aria-hidden="true">
                            <span class="line-preview-btn line-preview-btn--reject">ปฏิเสธ</span>
                            <span class="line-preview-btn line-preview-btn--done">เสร็จสิ้น</span>
                        </div>
                        <span class="line-preview-btn line-preview-btn--accept" aria-hidden="true">รับงาน</span>
                    </div>
                </div>
            </aside>
        </div>
    </div>

    <section class="tnc-list-card line-task-recent-card index-table-card" aria-labelledby="line-recent-heading">
        <div class="card-header">
            <p class="line-task-recent-kicker">ประวัติ</p>
            <h2 id="line-recent-heading" class="line-task-recent-title">ใบสั่งงานล่าสุด</h2>
        </div>
        <?php if ($recentTasks === []): ?>
            <div class="line-task-empty">
                <div class="line-task-empty__icon" aria-hidden="true"><i class="bi bi-inbox"></i></div>
                <p class="mb-0 fw-semibold">ยังไม่มีใบสั่งงาน</p>
                <p class="small mb-0 mt-1">ส่งใบแรกจากฟอร์มด้านบน</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 line-task-table">
                    <caption class="visually-hidden">รายการใบสั่งงาน LINE 10 รายการล่าสุด</caption>
                    <thead>
                        <tr>
                            <th scope="col">เลขที่</th>
                            <th scope="col">ผู้รับผิดชอบ</th>
                            <th scope="col">ปลายทาง</th>
                            <th scope="col">ภายใน</th>
                            <th scope="col">สถานะ</th>
                            <th scope="col" class="text-end">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTasks as $t): ?>
                            <?php
                            $tid = (int) ($t['id'] ?? 0);
                            $orderNo = line_task_order_no($tid);
                            $aid = (int) ($t['assignee_id'] ?? 0);
                            $a = line_task_assignee_by_id($aid);
                            $stNorm = line_task_normalize_status($t);
                            $stLabel = line_task_status_label_th($stNorm);
                            $stClass = line_task_status_badge_class($stNorm);
                            $deleteConfirm = 'ลบใบสั่งงาน ' . $orderNo . '?';
                            ?>
                            <tr>
                                <td><span class="line-task-order-no"><?= htmlspecialchars($orderNo, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars((string) ($a['name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(line_task_destination_label($t), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-nowrap"><?= htmlspecialchars(line_task_format_datetime_th((string) ($t['due_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="line-task-status badge rounded-pill border <?= htmlspecialchars($stClass, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($stLabel, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <form method="post"
                                          action="<?= htmlspecialchars($deleteTaskAction, ENT_QUOTES, 'UTF-8') ?>"
                                          class="d-inline line-task-delete-form"
                                          onsubmit="return confirm(<?= json_encode($deleteConfirm, JSON_UNESCAPED_UNICODE) ?>);">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_task">
                                        <input type="hidden" name="id" value="<?= $tid ?>">
                                        <button type="submit"
                                                class="btn btn-sm btn-outline-danger rounded-pill px-3 line-task-delete-btn"
                                                aria-label="ลบใบสั่งงาน <?= htmlspecialchars($orderNo, ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                            <span class="d-none d-md-inline ms-1">ลบ</span>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php if (!$formDisabled): ?>
<div class="tnc-mobile-sticky-cta d-lg-none line-task-sticky">
    <div class="tnc-mobile-sticky-inner">
        <button type="submit" form="task-form" class="btn btn-orange fw-bold w-100 rounded-pill" id="btn-send-task-mobile">
            <i class="bi bi-send-fill me-1" aria-hidden="true"></i>ส่งใบสั่งงานไป LINE
        </button>
    </div>
</div>
<?php endif; ?>

<script>
window.lineTaskSiteNames = <?= json_encode($siteNameById, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="<?= htmlspecialchars($lineTaskJsPath, ENT_QUOTES, 'UTF-8') ?>?v=<?= (int) $lineTaskJsVer ?>"></script>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
