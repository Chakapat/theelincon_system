<?php

declare(strict_types=1);


use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$me = (int) $_SESSION['user_id'];
$handler = app_path('actions/announcement-handler.php');
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

if ($isAdmin && $editId > 0) {
    $er = Db::rowByIdField('internal_announcements', $editId);
    if ($er !== null) {
        $editRow = [
            'id' => $er['id'] ?? $editId,
            'title' => $er['title'] ?? '',
            'body' => $er['body'] ?? '',
            'is_pinned' => $er['is_pinned'] ?? 0,
            'must_ack' => $er['must_ack'] ?? 0,
        ];
    }
}

$users = Db::tableKeyed('users');
$list = [];
foreach (Db::tableRows('internal_announcements') as $a) {
    $uid = (string) ($a['created_by'] ?? '');
    $u = $users[$uid] ?? null;
    $list[] = array_merge($a, [
        'author_name' => trim(($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')),
    ]);
}

usort($list, static function (array $x, array $y): int {
    $px = !empty($x['is_pinned']) ? 1 : 0;
    $py = !empty($y['is_pinned']) ? 1 : 0;
    if ($px !== $py) {
        return $py <=> $px;
    }

    return strcmp((string) ($y['created_at'] ?? ''), (string) ($x['created_at'] ?? ''));
});

$acknowledgedIds = [];
foreach (Db::tableRows('announcement_reads') as $r) {
    if (isset($r['user_id']) && (int) $r['user_id'] === $me && isset($r['announcement_id'])) {
        $acknowledgedIds[(int) $r['announcement_id']] = true;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>กระดานประกาศภายใน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }
        .ann-card { border-radius: 16px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,.06); }
        .ann-pinned { border-left: 4px solid #fd7e14; }
        .ann-body { white-space: pre-wrap; line-height: 1.7; }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-megaphone text-warning me-2"></i>กระดานประกาศภายใน</h4>
            <p class="text-muted small mb-0">ประกาศจากผู้ดูแลระบบ — รายการที่ตั้ง &ldquo;ต้องรับทราบ&rdquo; จะแสดงบังคับจนกว่าจะกดรับทราบ</p>
        </div>
        <a href="<?= htmlspecialchars(app_path('index.php')) ?>" class="btn btn-outline-secondary rounded-pill">หน้าหลัก</a>
    </div>

    <?php if ($isAdmin): ?>
    <div class="card ann-card mb-4">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3"><?= $editRow ? 'แก้ไขประกาศ' : 'ประกาศใหม่' ?></h5>
            <form method="post" action="<?= htmlspecialchars($handler) ?>?action=save">
                <?php csrf_field(); ?>
                <?php if ($editRow): ?>
                    <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label fw-bold small">หัวข้อ</label>
                    <input type="text" name="title" class="form-control rounded-3 border-0 bg-light" required maxlength="255"
                           value="<?= htmlspecialchars($editRow['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold small">เนื้อหา</label>
                    <textarea name="body" class="form-control rounded-3 border-0 bg-light" rows="6" required><?= htmlspecialchars($editRow['body'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_pinned" value="1" id="is_pinned"
                                <?= !empty($editRow['is_pinned']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_pinned">ปักหมุดด้านบน</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="must_ack" value="1" id="must_ack"
                                <?= ($editRow === null || (int)($editRow['must_ack'] ?? 0) === 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="must_ack">บังคับให้ทุกคนรับทราบ (แสดงหน้าต่างบังคับจนกว่าจะกดรับทราบ)</label>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn btn-warning text-white fw-bold rounded-pill px-4">บันทึกประกาศ</button>
                    <?php if ($editRow): ?>
                        <a href="<?= htmlspecialchars(app_path('pages/internal/announcements.php')) ?>" class="btn btn-outline-secondary rounded-pill">ยกเลิกการแก้ไข</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($list === []): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox display-4 opacity-25"></i>
            <p class="mt-3 mb-0">ยังไม่มีประกาศ</p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($list as $row):
                $aid = (int) $row['id'];
                $acknowledged = !empty($acknowledgedIds[$aid]);
                $needAck = !empty($row['must_ack']);
            ?>
            <div class="col-12">
                <div class="card ann-card <?= !empty($row['is_pinned']) ? 'ann-pinned' : '' ?>">
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start mb-2">
                            <div>
                                <h5 class="fw-bold mb-1"><?= htmlspecialchars($row['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></h5>
                                <div class="small text-muted">
                                    <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$row['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                                    · <?= htmlspecialchars(trim($row['author_name'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-1 align-items-center">
                                <?php if (!empty($row['is_pinned'])): ?>
                                    <span class="badge bg-warning text-dark rounded-pill">ปักหมุด</span>
                                <?php endif; ?>
                                <?php if ($needAck): ?>
                                    <span class="badge bg-danger-subtle text-danger rounded-pill">ต้องรับทราบ</span>
                                <?php endif; ?>
                                <?php if ($needAck && !$acknowledged): ?>
                                    <span class="badge bg-secondary rounded-pill">ยังไม่รับทราบ</span>
                                <?php elseif ($needAck && $acknowledged): ?>
                                    <span class="badge bg-success-subtle text-success rounded-pill">รับทราบแล้ว</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="ann-body text-dark"><?= nl2br(htmlspecialchars((string)($row['body'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>

                        <?php if ($needAck && !$acknowledged): ?>
                            <div class="mt-3 pt-3 border-top">
                                <button type="button" class="btn btn-outline-warning fw-bold rounded-pill btn-ack-single" data-id="<?= $aid ?>">
                                    <i class="bi bi-check2-circle me-1"></i>รับทราบประกาศนี้
                                </button>
                            </div>
                        <?php endif; ?>

                        <?php if ($isAdmin): ?>
                            <div class="mt-3 pt-3 border-top d-flex gap-2 flex-wrap">
                                <a href="<?= htmlspecialchars(app_path('pages/internal/announcements.php')) ?>?edit=<?= $aid ?>" class="btn btn-sm btn-light border rounded-pill">แก้ไข</a>
                                <a href="<?= htmlspecialchars($handler) ?>?action=delete&id=<?= $aid ?>&_csrf=<?= rawurlencode(csrf_token()) ?>" class="btn btn-sm btn-outline-danger rounded-pill" onclick="return confirm('ลบประกาศนี้?');">ลบ</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const ackUrl = <?= json_encode($handler . '?action=ack', JSON_UNESCAPED_SLASHES) ?>;
const csrfAck = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
document.querySelectorAll('.btn-ack-single').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = parseInt(this.getAttribute('data-id'), 10);
        if (!id) return;
        btn.disabled = true;
        fetch(ackUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfAck },
            body: JSON.stringify({ ids: [id] })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) window.location.reload();
                else { btn.disabled = false; Swal.fire('ผิดพลาด', 'บันทึกไม่สำเร็จ', 'error'); }
            })
            .catch(function () { btn.disabled = false; Swal.fire('ผิดพลาด', 'เชื่อมต่อล้มเหลว', 'error'); });
    });
});

const p = new URLSearchParams(window.location.search);
if (p.get('success') === '1') {
    Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', confirmButtonColor: '#fd7e14' });
    history.replaceState({}, '', window.location.pathname);
}
if (p.get('deleted') === '1') {
    Swal.fire({ icon: 'success', title: 'ลบประกาศแล้ว', confirmButtonColor: '#fd7e14' });
    history.replaceState({}, '', window.location.pathname);
}
if (p.get('error') === 'required') {
    Swal.fire({ icon: 'warning', title: 'กรุณากรอกหัวข้อและเนื้อหา', confirmButtonColor: '#fd7e14' });
    history.replaceState({}, '', window.location.pathname);
}
</script>
</body>
</html>
