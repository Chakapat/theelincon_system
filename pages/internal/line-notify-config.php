<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/includes/line_notify_runtime.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_flash.php';

use Theelincon\Rtdb\Db;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

// เฉพาะบทบาท ADMIN — CEO และบทบาทอื่นเข้าไม่ได้
if (!user_can('page.internal.line')) {
    http_response_code(403);
    echo 'ไม่มีสิทธิ์เข้าถึง — หน้านี้สำหรับผู้ดูแลระบบ (ADMIN) เท่านั้น';
    exit;
}

$userRows = Db::tableRows('users');
usort($userRows, static function (array $a, array $b): int {
    return strcasecmp(
        trim((string) ($a['fname'] ?? '') . ' ' . (string) ($a['lname'] ?? '')),
        trim((string) ($b['fname'] ?? '') . ' ' . (string) ($b['lname'] ?? ''))
    );
});

$configError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_line_notify'])) {
    if (!csrf_verify_request()) {
        $configError = 'csrf';
    } else {
        $channelAccessToken = trim((string) ($_POST['channel_access_token'] ?? ''));
        $channelSecret = trim((string) ($_POST['channel_secret'] ?? ''));
        $targetGroupId = trim((string) ($_POST['target_group_id'] ?? ''));
        $taskTargetGroupId = trim((string) ($_POST['task_target_group_id'] ?? ''));
        $selectedRaw = $_POST['approver_userids'] ?? [];
        $selectedIds = is_array($selectedRaw) ? array_map('intval', $selectedRaw) : [];
        $selectedIds = array_values(array_unique(array_filter($selectedIds, static fn (int $id): bool => $id > 0)));

        $lineIds = [];
        $missingNames = [];
        foreach ($selectedIds as $uid) {
            $u = Db::row('users', (string) $uid);
            if ($u === null) {
                continue;
            }
            $lid = trim((string) ($u['user_line_id'] ?? ''));
            if ($lid === '') {
                $nm = trim((string) (($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')));
                $missingNames[] = $nm !== '' ? $nm : ('user #' . $uid);
                continue;
            }
            $lineIds[] = $lid;
        }
        $lineIds = array_values(array_unique($lineIds));

        if ($targetGroupId === '') {
            $configError = 'need_group';
        } elseif ($lineIds === []) {
            $configError = 'need_approver';
        } elseif ($missingNames !== []) {
            $configError = 'missing_line:' . implode('|', $missingNames);
        } else {
            $lineCfgBefore = Db::row(LINE_NOTIFY_CONFIG_TABLE, LINE_NOTIFY_CONFIG_PK);
            $approverCsv = implode(',', $lineIds);
            Db::mergeRow(LINE_NOTIFY_CONFIG_TABLE, LINE_NOTIFY_CONFIG_PK, [
                'channel_access_token' => $channelAccessToken === '' ? null : $channelAccessToken,
                'channel_secret' => $channelSecret === '' ? null : $channelSecret,
                'bot_user_id' => null,
                'target_group_id' => $targetGroupId,
                'task_target_group_id' => $taskTargetGroupId === '' ? null : $taskTargetGroupId,
                'approver_user_id' => $approverCsv,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => (int) $_SESSION['user_id'],
            ]);
            $lineCfgAfter = Db::row(LINE_NOTIFY_CONFIG_TABLE, LINE_NOTIFY_CONFIG_PK);
            tnc_audit_log('update', 'line_notify_config', LINE_NOTIFY_CONFIG_PK, 'ตั้งค่า LINE แจ้งอนุมัติ', [
                'source' => 'line-notify-config.php',
                'action' => 'save_line_notify',
                'before' => is_array($lineCfgBefore) ? $lineCfgBefore : null,
                'after' => is_array($lineCfgAfter) ? $lineCfgAfter : null,
                'meta' => [
                    'approver_internal_user_ids' => $selectedIds,
                ],
            ]);
            header('Location: ' . app_path('pages/internal/line-notify-config.php') . '?saved=1');
            exit;
        }
    }
}

$formChannelToken = line_notify_field('channel_access_token');
$formChannelSecret = line_notify_field('channel_secret');
$formTargetGroupId = line_effective_target_group_id();
$formTaskTargetGroupId = line_effective_task_group_id();
$lineGroupOptions = line_notify_captured_groups();
$formApproverUserIds = line_notify_internal_ids_matching_line_ids(
    $userRows,
    line_notify_split_csv_ids(line_effective_approver_user_id())
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_line_notify']) && $configError !== '') {
    $formChannelToken = trim((string) ($_POST['channel_access_token'] ?? ''));
    $formChannelSecret = trim((string) ($_POST['channel_secret'] ?? ''));
    $formTargetGroupId = trim((string) ($_POST['target_group_id'] ?? ''));
    $formTaskTargetGroupId = trim((string) ($_POST['task_target_group_id'] ?? ''));
    $selectedRaw = $_POST['approver_userids'] ?? [];
    $formApproverUserIds = is_array($selectedRaw)
        ? array_values(array_unique(array_filter(array_map('intval', $selectedRaw), static fn (int $x): bool => $x > 0)))
        : [];
}

/** @var list<array{id: int, name: string, code: string, lineId: string, hasLine: bool}> $usersForJs */
$usersForJs = [];
foreach ($userRows as $u) {
    $uid = (int) ($u['userid'] ?? 0);
    if ($uid <= 0) {
        continue;
    }
    $nm = trim((string) (($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')));
    $code = trim((string) ($u['user_code'] ?? ''));
    $name = $nm !== '' ? $nm : ($code !== '' ? $code : ('User #' . $uid));
    $lid = trim((string) ($u['user_line_id'] ?? ''));
    $usersForJs[] = [
        'id' => $uid,
        'name' => $name,
        'code' => $code,
        'lineId' => $lid,
        'hasLine' => $lid !== '',
    ];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php
    require_once dirname(__DIR__, 2) . '/includes/tnc_ops_head.php';
    tnc_ops_head([
        'title' => 'ตั้งค่า LINE แจ้งเตือน | THEELIN CON',
        'line_notify' => true,
        'include_ops_ui' => false,
    ]);
    ?>
</head>
<body class="tnc-app-body tnc-layout-form">
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 line-shell">
    <h4 class="fw-bold mb-3"><i class="bi bi-bell-fill me-2 text-success"></i>ตั้งค่า LINE แจ้งเตือน</h4>

    <?php
    $lineFlash = tnc_flash_from_query($_GET);
    if ($lineFlash !== null && !empty($_GET['saved'])) {
        $lineFlash['message'] = 'บันทึกการตั้งค่าแล้ว';
    }
    tnc_render_flash($lineFlash);
    ?>

    <?php if ($configError === 'csrf'): ?>
        <div class="alert alert-danger rounded-3">เซสชันหมดอายุหรือ token ไม่ถูกต้อง กรุณารีเฟรชแล้วลองใหม่</div>
    <?php elseif ($configError === 'need_group'): ?>
        <div class="alert alert-warning rounded-3">กรุณาเลือกหรือกรอก <strong>กลุ่ม LINE</strong> สำหรับส่งคำขออนุมัติ PR</div>
    <?php elseif ($configError === 'need_approver'): ?>
        <div class="alert alert-warning rounded-3">กรุณาเพิ่ม <strong>ผู้อนุมัติ</strong> อย่างน้อย 1 คน (ใช้ตรวจสิทธิ์กดปุ่มอนุมัติในกลุ่ม)</div>
    <?php elseif (str_starts_with($configError, 'missing_line:')): ?>
        <?php
        $names = array_filter(explode('|', substr($configError, strlen('missing_line:'))));
        ?>
        <div class="alert alert-warning rounded-3">
            <strong>ยังไม่มี LINE User ID</strong> ในข้อมูลสมาชิกสำหรับ: <?= htmlspecialchars(implode(', ', $names), ENT_QUOTES, 'UTF-8') ?>
            — กรุณาแก้ไขสมาชิกให้กรอก LINE User ID ก่อน แล้วเลือกรายชื่อใหม่
        </div>
    <?php endif; ?>

    <div class="card line-card">
        <div class="card-body p-4">
            <form method="post" id="line-notify-form">
                <?php csrf_field(); ?>
                <input type="hidden" name="save_line_notify" value="1">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="channel_access_token">Channel Access Token</label>
                    <div class="input-group token-group">
                        <input type="password" class="form-control" id="channel_access_token" name="channel_access_token"
                               value="<?= htmlspecialchars($formChannelToken, ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="off" placeholder="จาก LINE Developers Console">
                        <button class="btn token-action-btn" type="button" data-toggle-target="channel_access_token" title="แสดง/ซ่อน" aria-label="แสดงหรือซ่อน">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="channel_secret">Channel Secret</label>
                    <div class="input-group token-group">
                        <input type="password" class="form-control" id="channel_secret" name="channel_secret"
                               value="<?= htmlspecialchars($formChannelSecret, ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="off" placeholder="ใช้ตรวจสอบลายเซ็น Webhook">
                        <button class="btn token-action-btn" type="button" data-toggle-target="channel_secret" title="แสดง/ซ่อน" aria-label="แสดงหรือซ่อน">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-4 p-3 rounded-3 border bg-light">
                    <label class="form-label fw-semibold" for="target_group_id">
                        <i class="bi bi-people-fill me-1 text-success"></i>กลุ่ม LINE สำหรับแจ้งอนุมัติ PR <span class="text-danger">*</span>
                    </label>
                    <?php if ($lineGroupOptions !== []): ?>
                        <select class="form-select font-monospace mb-2" id="target_group_pick" aria-label="เลือกกลุ่ม LINE">
                            <option value="">— เลือกกลุ่ม —</option>
                            <?php foreach ($lineGroupOptions as $g): ?>
                                <option value="<?= htmlspecialchars((string) $g['id'], ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $formTargetGroupId === (string) $g['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $g['id'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($g['last_seen'])): ?>
                                        (ล่าสุด <?= htmlspecialchars((string) $g['last_seen'], ENT_QUOTES, 'UTF-8') ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <p class="small text-warning mb-2">
                            <i class="bi bi-info-circle me-1"></i>ยังไม่มี Group ID ในระบบ — เพิ่มบอทเข้ากลุ่ม LINE แล้วส่งข้อความในกลุ่ม (หรือกดปุ่มอนุมัติทดสอบ) จากนั้นรีเฟรชหน้านี้
                        </p>
                    <?php endif; ?>
                    <label class="form-label small text-muted mb-1" for="target_group_id">Group ID</label>
                    <input type="text" class="form-control font-monospace" id="target_group_id" name="target_group_id" required
                           value="<?= htmlspecialchars($formTargetGroupId, ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="off">
                </div>

                <div class="mb-4 p-3 rounded-3 border bg-light">
                    <label class="form-label fw-semibold" for="task_target_group_id">
                        <i class="bi bi-clipboard-check-fill me-1 text-warning"></i>กลุ่ม LINE สั่งงาน
                    </label>
                    <?php if ($lineGroupOptions !== []): ?>
                        <select class="form-select font-monospace mb-2" id="task_target_group_pick" aria-label="เลือกกลุ่ม LINE สั่งงาน">
                            <option value="">— เลือกกลุ่ม —</option>
                            <?php foreach ($lineGroupOptions as $g): ?>
                                <option value="<?= htmlspecialchars((string) $g['id'], ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $formTaskTargetGroupId === (string) $g['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $g['id'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($g['last_seen'])): ?>
                                        (ล่าสุด <?= htmlspecialchars((string) $g['last_seen'], ENT_QUOTES, 'UTF-8') ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <label class="form-label small text-muted mb-1" for="task_target_group_id">Group ID</label>
                    <input type="text" class="form-control font-monospace" id="task_target_group_id" name="task_target_group_id"
                           value="<?= htmlspecialchars($formTaskTargetGroupId, ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="off" placeholder="Cxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold d-block">ผู้อนุมัติ <span class="text-danger">*</span></label>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <div id="approver-selected-list" class="d-flex flex-wrap gap-2 flex-grow-1"></div>
                        <button type="button" class="btn btn-outline-success btn-add-approver shadow-sm" id="btn-open-approver-picker"
                                title="เพิ่มผู้อนุมัติ" data-bs-toggle="modal" data-bs-target="#approverPickerModal">
                            <i class="bi bi-search"></i><span>เพิ่มผู้อนุมัติ</span>
                        </button>
                    </div>
                    <div id="approver-hidden-inputs" class="visually-hidden" aria-hidden="true"></div>
                </div>

                <div class="d-none d-lg-block">
                <button type="submit" class="btn btn-save-primary fw-semibold px-4" id="btn-save-line-config">
                    <span class="label-default"><i class="bi bi-check2-circle me-1"></i>บันทึก</span>
                    <span class="label-loading d-none"><span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>กำลังบันทึก...</span>
                </button>
                </div>

                <div class="tnc-mobile-sticky-cta d-lg-none">
                    <div class="tnc-mobile-sticky-inner">
                        <div class="tnc-mobile-sticky-actions w-100">
                            <button type="submit" class="btn btn-save-primary fw-semibold w-100">
                                <span class="label-default"><i class="bi bi-check2-circle me-1"></i>บันทึกการตั้งค่า</span>
                                <span class="label-loading d-none"><span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>กำลังบันทึก...</span>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="approverPickerModal" tabindex="-1" aria-labelledby="approverPickerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-md-down">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="approverPickerModalLabel"><i class="bi bi-people me-2 text-success"></i>เลือกผู้อนุมัติ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body pt-2">
                <p class="small text-muted mb-2">ติ๊กสมาชิกที่ต้องการเพิ่ม แล้วกด «เพิ่มที่เลือก»</p>
                <div id="approver-picker-list" class="rounded-3 border bg-white px-2" style="max-height: 55vh; overflow-y: auto;"></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-success rounded-pill px-4 fw-semibold" id="btn-approver-picker-apply">
                    <i class="bi bi-check2 me-1"></i>เพิ่มที่เลือก
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<script>
(function () {
    const LINE_NOTIFY_USERS = <?= json_encode($usersForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const userById = new Map(LINE_NOTIFY_USERS.map(function (u) { return [u.id, u]; }));

    let selectedIds = <?= json_encode(array_values($formApproverUserIds), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function syncHiddenInputs() {
        var box = document.getElementById('approver-hidden-inputs');
        box.innerHTML = '';
        selectedIds.forEach(function (id) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'approver_userids[]';
            inp.value = String(id);
            box.appendChild(inp);
        });
    }

    function renderChips() {
        var list = document.getElementById('approver-selected-list');
        list.innerHTML = '';
        if (selectedIds.length === 0) {
            var empty = document.createElement('span');
            empty.className = 'text-muted small';
            empty.textContent = 'ยังไม่มีผู้อนุมัติ — กดปุ่ม + เพื่อเพิ่ม';
            list.appendChild(empty);
            return;
        }
        selectedIds.forEach(function (id) {
            var u = userById.get(id);
            var label = u ? u.name : ('#' + id);
            var code = u && u.code ? ' (' + u.code + ')' : '';
            var initials = getInitials(label);
            var chip = document.createElement('span');
            chip.className = 'approver-chip';
            chip.innerHTML = '<span class="chip-avatar">' + escapeHtml(initials) + '</span>' +
                '<span>' + escapeHtml(label + code) + '</span>' +
                '<button type="button" class="btn-chip-remove" data-remove-id="' + id + '" title="เอาออก"><i class="bi bi-x-lg"></i></button>';
            list.appendChild(chip);
        });
        list.querySelectorAll('[data-remove-id]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var rid = parseInt(btn.getAttribute('data-remove-id'), 10);
                selectedIds = selectedIds.filter(function (x) { return x !== rid; });
                renderChips();
                syncHiddenInputs();
            });
        });
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function getInitials(name) {
        var n = String(name || '').trim();
        if (!n) return 'U';
        var parts = n.split(/\s+/).filter(Boolean);
        if (parts.length === 1) return parts[0].slice(0, 2);
        return (parts[0].slice(0, 1) + parts[1].slice(0, 1));
    }

    function renderPickerList() {
        var container = document.getElementById('approver-picker-list');
        container.innerHTML = '';
        var selectedSet = new Set(selectedIds);
        var added = 0;
        LINE_NOTIFY_USERS.forEach(function (u) {
            if (selectedSet.has(u.id)) {
                return;
            }
            added++;
            var id = 'pick_approver_' + u.id;
            var wrap = document.createElement('div');
            wrap.className = 'form-check';
            var disabled = !u.hasLine;
            var sub = u.hasLine
                ? '<span class="font-monospace text-success small d-block">' + escapeHtml(u.lineId) + '</span>'
                : '<span class="text-warning small d-block"><i class="bi bi-exclamation-triangle"></i> ยังไม่มี LINE User ID</span>';
            wrap.innerHTML =
                '<input class="form-check-input" type="checkbox" value="' + u.id + '" id="' + id + '" data-pick-approver ' + (disabled ? 'disabled' : '') + '>' +
                '<label class="form-check-label w-100" for="' + id + '">' +
                '<span class="fw-semibold">' + escapeHtml(u.name) + '</span>' +
                (u.code ? ' <span class="text-muted small">(' + escapeHtml(u.code) + ')</span>' : '') +
                sub +
                '</label>';
            container.appendChild(wrap);
        });
        if (added === 0) {
            container.innerHTML = '<p class="text-muted small mb-0 py-3 text-center">เลือกครบทุกคนในระบบแล้ว</p>';
        }
    }

    document.getElementById('approverPickerModal').addEventListener('show.bs.modal', function () {
        renderPickerList();
    });

    document.getElementById('btn-approver-picker-apply').addEventListener('click', function () {
        var toAdd = [];
        document.querySelectorAll('#approver-picker-list input[data-pick-approver]:checked:not(:disabled)').forEach(function (cb) {
            toAdd.push(parseInt(cb.value, 10));
        });
        toAdd.forEach(function (id) {
            if (selectedIds.indexOf(id) === -1) {
                selectedIds.push(id);
            }
        });
        renderChips();
        syncHiddenInputs();
        var modal = bootstrap.Modal.getInstance(document.getElementById('approverPickerModal'));
        if (modal) {
            modal.hide();
        }
    });

    document.querySelectorAll('[data-toggle-target]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-toggle-target');
            var inp = id ? document.getElementById(id) : null;
            if (!inp) return;
            var isHidden = inp.type === 'password';
            inp.type = isHidden ? 'text' : 'password';
            btn.innerHTML = isHidden ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
        });
    });

    var formEl = document.getElementById('line-notify-form');
    if (formEl) {
        formEl.addEventListener('submit', function () {
            formEl.querySelectorAll('.btn-save-primary').forEach(function (saveBtn) {
                saveBtn.classList.add('is-loading');
                saveBtn.disabled = true;
                var d = saveBtn.querySelector('.label-default');
                var l = saveBtn.querySelector('.label-loading');
                if (d) d.classList.add('d-none');
                if (l) l.classList.remove('d-none');
            });
        });
    }

    var groupPick = document.getElementById('target_group_pick');
    var groupInput = document.getElementById('target_group_id');
    if (groupPick && groupInput) {
        groupPick.addEventListener('change', function () {
            if (groupPick.value) {
                groupInput.value = groupPick.value;
            }
        });
        groupInput.addEventListener('input', function () {
            var v = groupInput.value.trim();
            var opts = groupPick.options;
            for (var i = 0; i < opts.length; i++) {
                if (opts[i].value === v) {
                    groupPick.selectedIndex = i;
                    return;
                }
            }
            if (groupPick.selectedIndex > 0 && groupPick.value !== v) {
                groupPick.selectedIndex = 0;
            }
        });
    }

    var taskGroupPick = document.getElementById('task_target_group_pick');
    var taskGroupInput = document.getElementById('task_target_group_id');
    if (taskGroupPick && taskGroupInput) {
        taskGroupPick.addEventListener('change', function () {
            if (taskGroupPick.value) {
                taskGroupInput.value = taskGroupPick.value;
            }
        });
        taskGroupInput.addEventListener('input', function () {
            var v = taskGroupInput.value.trim();
            var opts = taskGroupPick.options;
            for (var i = 0; i < opts.length; i++) {
                if (opts[i].value === v) {
                    taskGroupPick.selectedIndex = i;
                    return;
                }
            }
            if (taskGroupPick.selectedIndex > 0 && taskGroupPick.value !== v) {
                taskGroupPick.selectedIndex = 0;
            }
        });
    }

    renderChips();
    syncHiddenInputs();
})();
</script>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
