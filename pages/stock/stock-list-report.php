<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/stock_pivot_report.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_shell_head.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

function stock_report_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$hideZeroProducts = !isset($_GET['show_zero_products']) || (string) $_GET['show_zero_products'] !== '1';
$hideZeroSites = isset($_GET['hide_zero_sites']) && (string) $_GET['hide_zero_sites'] === '1';

$pivot = tnc_stock_pivot_report_build($hideZeroProducts, $hideZeroSites);
$sites = $pivot['sites'];
$products = $pivot['products'];
$matrix = $pivot['matrix'];
$generatedAt = (string) ($pivot['generated_at'] ?? date('d/m/Y H:i'));
$siteCount = count($sites);
$productCount = count($products);
$filledCells = 0;
$negativeCells = 0;
foreach ($sites as $site) {
    foreach ($products as $product) {
        $qty = tnc_stock_pivot_cell_qty($matrix, (int) $site['id'], (int) $product['id']);
        if (abs($qty) > 0.0001) {
            $filledCells++;
        }
        if ($qty < -0.0001) {
            $negativeCells++;
        }
    }
}
$hasData = $products !== [] && $sites !== [];
$printPages = $hasData ? tnc_stock_pivot_print_pages($sites, $products) : [];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php tnc_shell_head([
        'title' => 'รายงานสต็อก (ไซต์ × สินค้า) | THEELIN CON',
        'extra_css' => ['assets/css/stock-list-report.css'],
        'sarabun_weights' => '400;600;700;800',
    ]); ?>
</head>
<body class="tnc-app-body tnc-layout-list stock-report-body">

<div class="no-print">
    <?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>
</div>

<div class="container py-4 pb-5 stock-report-container">
    <header class="tnc-page-head mb-3 no-print">
        <div>
            <p class="tnc-page-kicker">คลังสินค้า · รายงาน</p>
            <h1 class="tnc-list-title">
                <span class="tnc-list-title__icon me-2" aria-hidden="true"><i class="bi bi-grid-3x3-gap"></i></span>
                ยอดคงเหลือไซต์ × สินค้า
            </h1>
        </div>
        <div class="stock-report-head-actions">
            <a href="<?= stock_report_h(app_path('pages/stock/stock-list.php')) ?>" class="btn btn-outline-secondary rounded-pill">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>กลับคลังสินค้า
            </a>
            <button type="button" class="btn btn-outline-orange rounded-pill" onclick="window.print()">
                <i class="bi bi-printer me-1" aria-hidden="true"></i>พิมพ์รายงาน
            </button>
        </div>
    </header>

    <div class="stock-report-print-pages print-only" aria-hidden="true">
        <?php foreach ($printPages as $printPage): ?>
            <section class="stock-report-print-page<?= (int) $printPage['page_num'] === (int) $printPage['page_total'] ? ' stock-report-print-page--last' : '' ?>">
                <header class="stock-report-print-header">
                    <h1 class="stock-report-print-title">รายงานยอดคงเหลือสต็อก — ไซต์ × สินค้า</h1>
                    <p class="stock-report-print-meta">
                        อัปเดต <?= stock_report_h($generatedAt) ?>
                        · สินค้า <?= (int) $printPage['product_from'] ?>–<?= (int) $printPage['product_to'] ?> จาก <?= number_format($productCount) ?>
                        · ไซต์ <?= (int) $printPage['site_from'] ?>–<?= (int) $printPage['site_to'] ?> จาก <?= number_format($siteCount) ?>
                    </p>
                </header>
                <?php tnc_stock_pivot_render_table($printPage['sites'], $printPage['products'], $matrix, 'stock-pivot-table stock-pivot-table--print'); ?>
                <div class="stock-report-page-indicator">หน้า <?= (int) $printPage['page_num'] ?>/<?= (int) $printPage['page_total'] ?></div>
            </section>
        <?php endforeach; ?>
    </div>

    <?php if ($hasData): ?>
    <div class="row g-3 mb-3 stock-report-kpi-row no-print">
        <div class="col-6 col-md-3">
            <div class="stock-report-kpi">
                <span class="stock-report-kpi__label">ไซต์งาน</span>
                <strong class="stock-report-kpi__value"><?= number_format($siteCount) ?></strong>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stock-report-kpi">
                <span class="stock-report-kpi__label">รายการสินค้า</span>
                <strong class="stock-report-kpi__value"><?= number_format($productCount) ?></strong>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stock-report-kpi">
                <span class="stock-report-kpi__label">เซลล์มียอด</span>
                <strong class="stock-report-kpi__value"><?= number_format($filledCells) ?></strong>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stock-report-kpi<?= $negativeCells > 0 ? ' stock-report-kpi--alert' : '' ?>">
                <span class="stock-report-kpi__label">ยอดติดลบ</span>
                <strong class="stock-report-kpi__value"><?= number_format($negativeCells) ?></strong>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <section class="stock-report-toolbar-card no-print" aria-label="ตัวกรองรายงาน">
        <form method="get" class="report-filter-form stock-report-filter">
            <div class="report-toolbar">
                <div class="report-toolbar__main">
                    <fieldset class="stock-report-filter-fieldset">
                        <legend class="stock-report-filter-legend">แสดงข้อมูล</legend>
                        <div class="stock-report-filter-options">
                            <div class="form-check stock-report-check">
                                <input class="form-check-input" type="checkbox" name="show_zero_products" value="1" id="showZeroProducts"<?= $hideZeroProducts ? '' : ' checked' ?>>
                                <label class="form-check-label" for="showZeroProducts">รวมสินค้ายอดศูนย์ทุกไซต์</label>
                            </div>
                            <div class="form-check stock-report-check">
                                <input class="form-check-input" type="checkbox" name="hide_zero_sites" value="1" id="hideZeroSites"<?= $hideZeroSites ? ' checked' : '' ?>>
                                <label class="form-check-label" for="hideZeroSites">ซ่อนไซต์ที่ไม่มียอด</label>
                            </div>
                        </div>
                    </fieldset>
                </div>
                <div class="report-toolbar__tools">
                    <button type="submit" class="btn btn-orange rounded-pill report-toolbar__submit">
                        <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>อัปเดตรายงาน
                    </button>
                </div>
            </div>
        </form>
    </section>

    <?php if ($products === []): ?>
        <div class="stock-report-empty" role="status">
            <div class="stock-report-empty__icon" aria-hidden="true"><i class="bi bi-inbox"></i></div>
            <h2 class="stock-report-empty__title">ยังไม่มีสินค้าในรายงาน</h2>
            <p class="stock-report-empty__text">เพิ่มประเภทสินค้า/วัสดุก่อน หรือเปิดตัวเลือกแสดงสินค้ายอดศูนย์</p>
            <a href="<?= stock_report_h(app_path('pages/stock/stock-product-form.php')) ?>" class="btn btn-orange rounded-pill">เพิ่มประเภทสินค้า</a>
        </div>
    <?php elseif ($sites === []): ?>
        <div class="stock-report-empty" role="status">
            <div class="stock-report-empty__icon" aria-hidden="true"><i class="bi bi-geo-alt"></i></div>
            <h2 class="stock-report-empty__title">ยังไม่มีไซต์งาน</h2>
            <p class="stock-report-empty__text">เพิ่มไซต์งานในระบบก่อนจึงจะแสดงรายงานได้</p>
            <a href="<?= stock_report_h(app_path('pages/sites/site-picker.php')) ?>" class="btn btn-outline-orange rounded-pill">จัดการไซต์งาน</a>
        </div>
    <?php else: ?>
        <section class="stock-report-sheet-card stock-report-sheet-card--screen" aria-labelledby="stockPivotTableTitle">
            <div class="stock-report-sheet-head no-print">
                <div>
                    <h2 class="stock-report-sheet-title" id="stockPivotTableTitle">ตารางไซต์ × สินค้า</h2>
                    <p class="stock-report-sheet-sub">อัปเดต <?= stock_report_h($generatedAt) ?></p>
                </div>
                <div class="stock-report-legend" aria-label="คำอธิบายสีตัวเลข">
                    <span class="stock-report-legend__item"><span class="stock-report-legend__swatch is-positive"></span>มียอด</span>
                    <span class="stock-report-legend__item"><span class="stock-report-legend__swatch is-empty"></span>ไม่มียอด</span>
                    <span class="stock-report-legend__item"><span class="stock-report-legend__swatch is-negative"></span>ติดลบ</span>
                </div>
            </div>

            <div class="stock-report-scroll-wrap">
                <p class="stock-report-scroll-hint no-print" id="stockScrollHint">
                    <i class="bi bi-arrows-expand" aria-hidden="true"></i>
                    เลื่อนแนวนอนเพื่อดูสินค้าทั้งหมด
                </p>
                <div class="stock-report-scroll" tabindex="0" aria-describedby="stockScrollHint">
                    <?php tnc_stock_pivot_render_table($sites, $products, $matrix, 'stock-pivot-table stock-pivot-table--screen'); ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
</body>
</html>
