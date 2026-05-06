<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/includes/line_notify_runtime.php';

use Theelincon\Rtdb\Db;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_is_admin_role()) {
    http_response_code(403);
    echo 'ไม่มีสิทธิ์เข้าถึง';
    exit;
}

/**
 * @return list<string>
 */
function line_notify_split_line_user_ids(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $raw) as $part) {
        $p = trim($part);
        if ($p !== '') {
            $out[] = $p;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @param array<int, array<string, mixed>> $userRows
 * @param list<string> $lineIds
 *
 * @return list<int>
 */
function line_notify_internal_ids_matching_line_ids(array $userRows, array $lineIds): array
{
    if ($lineIds === []) {
        return [];
    }
    $set = array_fill_keys($lineIds, true);
    $out = [];
    foreach ($userRows as $u) {
        $lid = trim((string) ($u['user_line_id'] ?? ''));
        if ($lid === '' || !isset($set[$lid])) {
            continue;
        }
        $uid = (int) ($u['userid'] ?? 0);
        if ($uid > 0) {
            $out[] = $uid;
        }
    }

    return array_values(array_unique($out));
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
        $targetGroup = trim((string) ($_POST['target_group_id'] ?? ''));
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

        if ($missingNames !== []) {
            $configError = 'missing_line:' . implode('|', $missingNames);
        } else {
            $approverCsv = implode(',', $lineIds);
            Db::mergeRow(LINE_NOTIFY_CONFIG_TABLE, LINE_NOTIFY_CONFIG_PK, [
                'target_group_id' => $targetGroup === '' ? null : $targetGroup,
                'approver_user_id' => $approverCsv === '' ? null : $approverCsv,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => (int) $_SESSION['user_id'],
            ]);
            header('Location: ' . app_path('pages/internal/line-notify-config.php') . '?saved=1');
            exit;
        }
    }
}

$row = line_notify_config_row();
$formGroup = line_notify_field_string($row, 'target_group_id');
$savedLineIds = line_notify_split_line_user_ids(line_notify_field_string($row, 'approver_user_id'));
$formApproverUserIds = line_notify_internal_ids_matching_line_ids($userRows, $savedLineIds);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_line_notify']) && $configError !== '') {
    $formGroup = trim((string) ($_POST['target_group_id'] ?? ''));
    $selectedRaw = $_POST['approver_userids'] ?? [];
    $formApproverUserIds = is_array($selectedRaw)
        ? array_values(array_unique(array_filter(array_map('intval', $selectedRaw), static fn (int $x): bool => $x > 0)))
        : [];
}

$effectiveGroup = line_effective_target_group_id();
$effectiveApproverRaw = line_effective_approver_user_id();
$effectiveLineIds = line_notify_split_line_user_ids($effectiveApproverRaw);

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

/** สำหรับการ์ดสรุป: แต่ละ LINE user id → ชื่อพนักงาน */
$lineIdToName = [];
foreach ($userRows as $u) {
    $lid = trim((string) ($u['user_line_id'] ?? ''));
    if ($lid === '') {
        continue;
    }
    $nm = trim((string) (($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')));
    $code = trim((string) ($u['user_code'] ?? ''));
    $lineIdToName[$lid] = $nm !== '' ? $nm : ($code !== '' ? $code : ('#' . (int) ($u['userid'] ?? 0)));
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่า LINE แจ้งเตือน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background:#f8f9fa; font-family:'Sarabun', sans-serif; }
        .approver-chip { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.65rem; border-radius: 2rem;
            background: #e7f5ff; border: 1px solid #74c0fc; font-size: 0.9rem; }
        .approver-chip .btn-chip-remove { border: none; background: transparent; color: #c92a2a; padding: 0 0.2rem; line-height: 1; }
        .approver-chip .btn-chip-remove:hover { color: #862e2e; }
        .btn-add-approver { width: 2.25rem; height: 2.25rem; padding: 0; display: inline-flex; align-items: center; justify-content: center; }
        #approver-picker-list .form-check { padding-top: 0.35rem; padding-bottom: 0.35rem; border-bottom: 1px solid #eee; }
        #approver-picker-list .form-check:last-child { border-bottom: none; }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4" style="max-width: 720px;">
    <h4 class="fw-bold mb-3"><i class="bi bi-bell-fill me-2 text-success"></i>ตั้งค่า LINE แจ้งเตือน</h4>

    <?php if (!empty($_GET['saved'])): ?>
        <div class="alert alert-success rounded-3">บันทึกการตั้งค่าแล้ว</div>
    <?php endif; ?>

    <?php if ($configError === 'csrf'): ?>
        <div class="alert alert-danger rounded-3">เซสชันหมดอายุหรือ token ไม่ถูกต้อง กรุณารีเฟรชแล้วลองใหม่</div>
    <?php elseif (str_starts_with($configError, 'missing_line:')): ?>
        <?php
        $names = array_filter(explode('|', substr($configError, strlen('missing_line:'))));
        ?>
        <div class="alert alert-warning rounded-3">
            <strong>ยังไม่มี LINE User ID</strong> ในข้อมูลสมาชิกสำหรับ: <?= htmlspecialchars(implode(', ', $names), ENT_QUOTES, 'UTF-8') ?>
            — กรุณาแก้ไขสมาชิกให้กรอก LINE User ID ก่อน แล้วเลือกรายชื่อใหม่
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body">
            <dl class="row small mb-0">
                <dt class="col-sm-4 text-muted">กลุ่มปลายทาง</dt>
                <dd class="col-sm-8 font-monospace text-break"><?= htmlspecialchars($effectiveGroup !== '' ? $effectiveGroup : '(ว่าง)', ENT_QUOTES, 'UTF-8') ?></dd>
                <dt class="col-sm-4 text-muted">ผู้อนุมัติผ่าน LINE</dt>
                <dd class="col-sm-8">
                    <?php if ($effectiveLineIds === []): ?>
                        <span class="text-muted">(ยังไม่เลือก)</span>
                    <?php else: ?>
                        <ul class="mb-0 ps-3">
                            <?php foreach ($effectiveLineIds as $lid): ?>
                                <?php
                                $label = $lineIdToName[$lid] ?? '';
                                $show = $label !== '' ? ($label . ' — ') : '';
                                $show .= $lid;
                                ?>
                                <li class="font-monospace small text-break"><?= htmlspecialchars($show, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </dd>
            </dl>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body">
            <form method="post" id="line-notify-form">
                <?php csrf_field(); ?>
                <input type="hidden" name="save_line_notify" value="1">

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="target_group_id">LINE Target Group Token</label>
                    <input type="text" class="form-control font-monospace" id="target_group_id" name="target_group_id"
                           value="<?= htmlspecialchars($formGroup, ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="off" placeholder="กลุ่มรับข้อความ (ถ้ามี)">
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold d-block">ผู้อนุมัติ</label>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <div id="approver-selected-list" class="d-flex flex-wrap gap-2 flex-grow-1"></div>
                        <button type="button" class="btn btn-outline-success btn-add-approver rounded-circle shadow-sm" id="btn-open-approver-picker"
                                title="เพิ่มผู้อนุมัติ" data-bs-toggle="modal" data-bs-target="#approverPickerModal">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    </div>
                    <div id="approver-hidden-inputs" class="visually-hidden" aria-hidden="true"></div>
                    <div class="form-text">กด <strong>+</strong> เพื่อเลือกสมาชิกจากรายการ แล้วกด <strong>บันทึก</strong> ด้านล่าง</div>
                </div>

                <button type="submit" class="btn btn-warning fw-semibold px-4 rounded-pill">
                    <i class="bi bi-check2-circle me-1"></i>บันทึก
                </button>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="approverPickerModal" tabindex="-1" aria-labelledby="approverPickerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
            var chip = document.createElement('span');
            chip.className = 'approver-chip';
            chip.innerHTML = '<span>' + escapeHtml(label + code) + '</span>' +
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

    renderChips();
    syncHiddenInputs();
})();
</script>
</body>
</html>
