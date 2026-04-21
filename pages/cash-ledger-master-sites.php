<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_name'])) {
    if (!csrf_verify_request()) {
        header('Location: ' . app_path('pages/cash-ledger-master-sites.php'));
        exit;
    }
    $n = trim((string) $_POST['add_name']);
    if ($n !== '' && strlen($n) <= 200) {
        $nid = Db::nextNumericId('cash_ledger_sites', 'id');
        Db::setRow('cash_ledger_sites', (string) $nid, [
            'id' => $nid,
            'name' => $n,
            'sort_order' => 0,
            'is_active' => 1,
        ]);
    }
    header('Location: ' . app_path('pages/cash-ledger-master-sites.php') . '?ok=1');
    exit;
}

if (isset($_GET['toggle'])) {
    if (!csrf_verify_request()) {
        header('Location: ' . app_path('pages/cash-ledger-master-sites.php'));
        exit;
    }
    $id = (int) $_GET['toggle'];
    if ($id > 0) {
        $cur = Db::row('cash_ledger_sites', (string) $id);
        if ($cur !== null) {
            $active = !empty($cur['is_active']) ? 1 : 0;
            Db::mergeRow('cash_ledger_sites', (string) $id, ['is_active' => $active ? 0 : 1]);
        }
    }
    header('Location: ' . app_path('pages/cash-ledger-master-sites.php'));
    exit;
}

$list = Db::tableRows('cash_ledger_sites');
usort($list, static function (array $a, array $b): int {
    $ia = !empty($a['is_active']) ? 1 : 0;
    $ib = !empty($b['is_active']) ? 1 : 0;
    if ($ia !== $ib) {
        return $ib <=> $ia;
    }
    $so = ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
    if ($so !== 0) {
        return $so;
    }

    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ไซต์งาน (รายรับรายจ่าย) | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }</style>
</head>
<body>
<?php include __DIR__ . '/../components/navbar.php'; ?>
<div class="container pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h4 class="fw-bold mb-0"><i class="bi bi-geo-alt me-2 text-warning"></i>ไซต์งาน / สถานที่ใช้ (ไม่เกี่ยว PR/PO)</h4>
        <a href="<?= htmlspecialchars(app_path('pages/cash-ledger.php')) ?>" class="btn btn-outline-secondary rounded-pill">กลับบันทึก</a>
    </div>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3">เพิ่มไซต์ใหม่</h6>
            <form method="post" class="row g-2 align-items-end">
                <?php csrf_field(); ?>
                <div class="col-md-8">
                    <label class="form-label small">ชื่อไซต์ / โครงการ</label>
                    <input type="text" name="add_name" class="form-control rounded-3" maxlength="200" required placeholder="เช่น โครงการ ABC">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn text-white rounded-3 w-100" style="background-color:#fd7e14;">บันทึก</button>
                </div>
            </form>
        </div>
    </div>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th class="ps-4">ชื่อ</th><th>สถานะ</th><th class="pe-4 text-end">จัดการ</th></tr></thead>
                <tbody>
                    <?php foreach ($list as $r): ?>
                    <tr>
                        <td class="ps-4"><?= htmlspecialchars((string) ($r['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= !empty($r['is_active']) ? '<span class="badge bg-success-subtle text-success">ใช้งาน</span>' : '<span class="badge bg-secondary-subtle text-secondary">ปิด</span>' ?></td>
                        <td class="pe-4 text-end">
                            <a class="btn btn-sm btn-outline-secondary rounded-3" href="?toggle=<?= (int) ($r['id'] ?? 0) ?>&amp;_csrf=<?= rawurlencode(csrf_token()) ?>"><?= !empty($r['is_active']) ? 'ปิดการใช้งาน' : 'เปิดใช้งาน' ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
