<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$embed = isset($_GET['embed']) && (string) $_GET['embed'] === '1';

$need = $id > 0 ? Db::rowByIdField('purchase_needs', $id) : null;
if (!$need) {
    header('Location: ' . app_path('pages/purchase/purchase-need-list.php') . '?error=invalid_need');
    exit;
}

$items = Db::filter('purchase_need_items', static function (array $row) use ($id): bool {
    return (int) ($row['need_id'] ?? 0) === $id;
});
usort($items, static function (array $a, array $b): int {
    return ((int) ($a['line_no'] ?? 0)) <=> ((int) ($b['line_no'] ?? 0));
});

$users = Db::tableKeyed('users');
$requester = $users[(string) ($need['requested_by'] ?? '')] ?? null;
$requesterName = trim((string) ($requester['fname'] ?? '') . ' ' . (string) ($requester['lname'] ?? ''));
if ($requesterName === '') {
    $requesterName = '-';
}

$statusRaw = strtolower((string) ($need['status'] ?? 'pending'));
$statusUpper = strtoupper($statusRaw);
$remarks = trim((string) ($need['remarks'] ?? ''));

$badgeClass = 'st-pending';
if ($statusRaw === 'approved') {
    $badgeClass = 'st-approved';
} elseif ($statusRaw === 'rejected') {
    $badgeClass = 'st-rejected';
}

$bodyClass = $embed ? 'embed-preview' : '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars((string) ($need['need_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?> — ใบต้องการซื้อ</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --tnc-orange: #fd7e14;
            --tnc-orange-dark: #e8590c;
            --ink: #1c1917;
            --muted: #57534e;
            --line: #e7e5e4;
            --paper: #ffffff;
            --cream: #fffaf5;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Sarabun', system-ui, sans-serif;
            margin: 0;
            color: var(--ink);
            font-size: 15px;
            line-height: 1.55;
            background: var(--cream);
            min-height: 100vh;
            padding: 1.5rem 1rem 2rem;
        }
        body.embed-preview {
            background: #f3f4f6;
            padding: 0;
            min-height: auto;
        }
        .toolbar {
            max-width: 900px;
            margin: 0 auto 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .toolbar .btn-print {
            font-family: inherit;
            font-weight: 600;
            padding: 0.55rem 1.35rem;
            border-radius: 999px;
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, var(--tnc-orange) 0%, var(--tnc-orange-dark) 100%);
            color: #fff;
            box-shadow: 0 4px 14px rgba(253, 126, 20, 0.35);
        }
        .toolbar .btn-print:hover { filter: brightness(1.05); }
        .toolbar .link-back {
            color: #57534e;
            text-decoration: none;
            font-weight: 500;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
        }
        .toolbar .link-back:hover { border-color: var(--tnc-orange); color: var(--tnc-orange-dark); }

        .sheet {
            max-width: 900px;
            margin: 0 auto;
            background: var(--paper);
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.06);
        }
        body.embed-preview .sheet {
            border-radius: 0;
            box-shadow: none;
            border: none;
            max-width: none;
        }

        .sheet-top {
            height: 6px;
            background: linear-gradient(90deg, var(--tnc-orange) 0%, #ffb366 100%);
        }
        .sheet-inner { padding: 1.75rem 1.85rem 2rem; }

        .doc-head {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--line);
        }
        .brand .co {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            color: var(--tnc-orange-dark);
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }
        .brand h1 {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--ink);
            letter-spacing: -0.02em;
        }
        .brand .doc-title {
            margin: 0.35rem 0 0;
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--muted);
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        .st-pending { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
        .st-approved { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .st-rejected { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.85rem 1.25rem;
            margin-bottom: 1.5rem;
        }
        .meta-card {
            background: linear-gradient(180deg, #fafaf9 0%, #fff 100%);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 0.65rem 0.85rem;
        }
        .meta-card .lbl {
            font-size: 0.72rem;
            font-weight: 600;
            color: #78716c;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.2rem;
        }
        .meta-card .val {
            font-weight: 600;
            color: var(--ink);
            word-break: break-word;
        }

        .section-label {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: var(--muted);
            text-transform: uppercase;
            margin: 0 0 0.6rem;
        }

        table.items {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--line);
        }
        table.items thead th {
            background: linear-gradient(180deg, #fff7ed 0%, #ffedd5 100%);
            color: #9a3412;
            font-weight: 700;
            font-size: 0.82rem;
            text-align: left;
            padding: 0.65rem 0.85rem;
            border-bottom: 1px solid #fed7aa;
        }
        table.items thead th:first-child { width: 3rem; text-align: center; }
        table.items thead th:nth-child(3) { text-align: right; }
        table.items thead th:nth-child(4) { text-align: center; }
        table.items tbody td {
            padding: 0.6rem 0.85rem;
            border-bottom: 1px solid #f5f5f4;
            vertical-align: top;
        }
        table.items tbody tr:last-child td { border-bottom: none; }
        table.items tbody tr:nth-child(even) td { background: #fafaf9; }
        table.items td.num { text-align: center; color: #78716c; font-weight: 600; width: 3rem; }
        table.items td.qty { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; color: var(--ink); }
        table.items td.unit { text-align: center; white-space: nowrap; color: var(--muted); }

        .remarks-box {
            margin-top: 1.35rem;
            padding: 1rem 1.1rem;
            border-radius: 12px;
            border: 1px dashed #d6d3d1;
            background: #fafaf9;
        }
        .remarks-box .rm-title {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 0.45rem;
        }

        .foot {
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--line);
            font-size: 0.78rem;
            color: #a8a29e;
            text-align: right;
        }

        @media print {
            body,
            body.embed-preview {
                background: #fff;
                padding: 10mm 12mm;
            }
            .toolbar { display: none !important; }
            .sheet {
                box-shadow: none;
                border-radius: 0;
                border: none;
                max-width: none;
            }
            .sheet-top { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table.items thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table.items tbody tr:nth-child(even) td { background: #fafafa !important; }
        }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>">
<?php if (!$embed): ?>
<div class="toolbar no-print">
    <button type="button" class="btn-print" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
    <a class="link-back" href="<?= htmlspecialchars(app_path('pages/purchase/purchase-need-view.php') . '?id=' . $id, ENT_QUOTES, 'UTF-8') ?>">กลับรายละเอียด</a>
</div>
<?php endif; ?>

<div class="sheet">
    <div class="sheet-top" aria-hidden="true"></div>
    <div class="sheet-inner">
        <header class="doc-head">
            <div class="brand">
                <div class="co">THEELIN CON CO.,LTD.</div>
                <h1>ใบต้องการซื้อ</h1>
                <p class="doc-title">Purchase Requisition</p>
            </div>
            <div>
                <span class="badge-status <?= htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusUpper, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </header>

        <div class="meta-grid">
            <div class="meta-card">
                <div class="lbl">เลขที่เอกสาร</div>
                <div class="val"><?= htmlspecialchars((string) ($need['need_number'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="meta-card">
                <div class="lbl">วันที่เอกสาร</div>
                <div class="val"><?= htmlspecialchars(format_thai_doc_date((string) ($need['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="meta-card">
                <div class="lbl">ผู้ขอ</div>
                <div class="val"><?= htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="meta-card">
                <div class="lbl">ไซต์งาน</div>
                <div class="val"><?= htmlspecialchars((string) ($need['site_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

        <p class="section-label">รายการที่ขอซื้อ</p>
        <table class="items">
            <thead>
                <tr>
                    <th>#</th>
                    <th>รายละเอียด</th>
                    <th>จำนวน</th>
                    <th>หน่วย</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($items) === 0): ?>
                    <tr><td colspan="4" style="text-align:center;padding:1.5rem;color:#78716c;">ไม่มีรายการ</td></tr>
                <?php else: ?>
                    <?php $n = 0; foreach ($items as $row): $n++; ?>
                        <tr>
                            <td class="num"><?= $n ?></td>
                            <td><?= htmlspecialchars((string) ($row['description'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="qty"><?= htmlspecialchars(number_format((float) ($row['quantity'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="unit"><?= htmlspecialchars((string) ($row['unit'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($remarks !== ''): ?>
            <div class="remarks-box">
                <div class="rm-title">หมายเหตุ</div>
                <div><?= nl2br(htmlspecialchars($remarks, ENT_QUOTES, 'UTF-8')) ?></div>
            </div>
        <?php endif; ?>

        <div class="foot">พิมพ์เมื่อ <?= date('d/m/Y H:i') ?></div>
    </div>
</div>
</body>
</html>
