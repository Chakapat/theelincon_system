<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

require_once __DIR__ . '/stock_site_data.php';

if (!function_exists('tnc_stock_pivot_report_build')) {
    /**
     * รายงานสต็อกแบบไขว้: แถว = ไซต์, คอลัมน์ = สินค้า, ค่า = ยอดคงเหลือ
     *
     * @return array{
     *   sites: list<array{id: int, name: string}>,
     *   products: list<array{id: int, name: string, code: string, unit: string}>,
     *   matrix: array<int, array<int, float>>,
     *   generated_at: string
     * }
     */
    function tnc_stock_pivot_report_build(bool $hideZeroProducts = true, bool $hideZeroSites = false): array
    {
        $productsById = tnc_stock_active_products();
        uasort($productsById, static function (array $a, array $b): int {
            return strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''))
                ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $sites = Db::tableRows('sites');
        usort($sites, static function (array $a, array $b): int {
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        /** @var array<int, array<int, float>> $matrix */
        $matrix = [];
        foreach (Db::tableRows('stock_movements') as $m) {
            $siteId = (int) ($m['site_id'] ?? 0);
            $productId = (int) ($m['product_id'] ?? 0);
            if ($siteId <= 0 || $productId <= 0 || !isset($productsById[$productId])) {
                continue;
            }
            $matrix[$siteId][$productId] = ($matrix[$siteId][$productId] ?? 0.0) + (float) ($m['qty'] ?? 0);
        }

        $siteRows = [];
        foreach ($sites as $site) {
            $siteId = (int) ($site['id'] ?? 0);
            if ($siteId <= 0) {
                continue;
            }
            $siteRows[] = [
                'id' => $siteId,
                'name' => trim((string) ($site['name'] ?? '')),
            ];
        }

        $productCols = [];
        foreach ($productsById as $productId => $product) {
            $hasAny = false;
            foreach ($siteRows as $siteRow) {
                $qty = (float) ($matrix[$siteRow['id']][$productId] ?? 0.0);
                if (abs($qty) > 0.0001) {
                    $hasAny = true;
                    break;
                }
            }
            if ($hideZeroProducts && !$hasAny) {
                continue;
            }
            $productCols[] = [
                'id' => (int) $productId,
                'name' => trim((string) ($product['name'] ?? '')),
                'code' => trim((string) ($product['code'] ?? '')),
                'unit' => trim((string) ($product['unit'] ?? 'ชิ้น')),
            ];
        }

        if ($hideZeroSites) {
            $siteRows = array_values(array_filter($siteRows, static function (array $siteRow) use ($matrix, $productCols): bool {
                foreach ($productCols as $productCol) {
                    $qty = (float) ($matrix[$siteRow['id']][$productCol['id']] ?? 0.0);
                    if (abs($qty) > 0.0001) {
                        return true;
                    }
                }

                return false;
            }));
        }

        return [
            'sites' => $siteRows,
            'products' => $productCols,
            'matrix' => $matrix,
            'generated_at' => date('d/m/Y H:i'),
        ];
    }
}

if (!function_exists('tnc_stock_pivot_cell_qty')) {
    function tnc_stock_pivot_cell_qty(array $matrix, int $siteId, int $productId): float
    {
        return (float) ($matrix[$siteId][$productId] ?? 0.0);
    }
}

if (!function_exists('tnc_stock_pivot_format_qty')) {
    function tnc_stock_pivot_format_qty(float $qty): string
    {
        if (abs($qty) < 0.0001) {
            return '—';
        }
        $decimals = fmod(abs($qty), 1.0) < 0.0001 ? 0 : 2;

        return number_format($qty, $decimals);
    }
}

if (!defined('TNC_STOCK_PRINT_COLS_PER_PAGE')) {
    define('TNC_STOCK_PRINT_COLS_PER_PAGE', 7);
}

if (!defined('TNC_STOCK_PRINT_ROWS_PER_PAGE')) {
    define('TNC_STOCK_PRINT_ROWS_PER_PAGE', 22);
}

if (!function_exists('tnc_stock_pivot_print_pages')) {
    /**
     * แบ่งตาราง pivot สำหรับพิมพ์ A4 แนวนอน — แยกทั้งคอลัมน์สินค้าและแถวไซต์
     *
     * @param list<array{id: int, name: string}> $sites
     * @param list<array{id: int, name: string, code: string, unit: string}> $products
     * @return list<array{
     *   sites: list<array{id: int, name: string}>,
     *   products: list<array{id: int, name: string, code: string, unit: string}>,
     *   page_num: int,
     *   page_total: int,
     *   product_from: int,
     *   product_to: int,
     *   site_from: int,
     *   site_to: int
     * }>
     */
    function tnc_stock_pivot_print_pages(array $sites, array $products, int $colsPerPage = TNC_STOCK_PRINT_COLS_PER_PAGE, int $rowsPerPage = TNC_STOCK_PRINT_ROWS_PER_PAGE): array
    {
        if ($sites === [] || $products === []) {
            return [];
        }
        if ($colsPerPage < 1) {
            $colsPerPage = TNC_STOCK_PRINT_COLS_PER_PAGE;
        }
        if ($rowsPerPage < 1) {
            $rowsPerPage = TNC_STOCK_PRINT_ROWS_PER_PAGE;
        }

        $siteChunks = array_values(array_chunk($sites, $rowsPerPage, false));
        $productChunks = array_values(array_chunk($products, $colsPerPage, false));
        $pageTotal = count($siteChunks) * count($productChunks);
        $pages = [];
        $pageNum = 0;
        $siteOffset = 0;

        foreach ($siteChunks as $siteChunk) {
            $productOffset = 0;
            foreach ($productChunks as $productChunk) {
                $pageNum++;
                $productFrom = $productOffset + 1;
                $productTo = $productOffset + count($productChunk);
                $siteFrom = $siteOffset + 1;
                $siteTo = $siteOffset + count($siteChunk);
                $pages[] = [
                    'sites' => $siteChunk,
                    'products' => $productChunk,
                    'page_num' => $pageNum,
                    'page_total' => $pageTotal,
                    'product_from' => $productFrom,
                    'product_to' => $productTo,
                    'site_from' => $siteFrom,
                    'site_to' => $siteTo,
                ];
                $productOffset += count($productChunk);
            }
            $siteOffset += count($siteChunk);
        }

        return $pages;
    }
}

if (!function_exists('tnc_stock_pivot_render_table')) {
    /**
     * @param list<array{id: int, name: string}> $sites
     * @param list<array{id: int, name: string, code: string, unit: string}> $products
     */
    function tnc_stock_pivot_render_table(array $sites, array $products, array $matrix, string $tableClass = 'stock-pivot-table'): void
    {
        $esc = static function (string $value): string {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        };
        ?>
        <table class="table table-sm align-middle mb-0 <?= $esc($tableClass) ?>">
            <thead>
                <tr>
                    <th class="stock-pivot-sticky-col stock-pivot-corner" scope="col">
                        <span class="stock-pivot-corner-label">ไซต์</span>
                        <span class="stock-pivot-corner-sub">↓ แถว</span>
                    </th>
                    <?php foreach ($products as $product): ?>
                        <th class="stock-pivot-col-head text-center" scope="col">
                            <div class="stock-pivot-product-name"><?= $esc((string) $product['name']) ?></div>
                            <?php if ((string) $product['code'] !== ''): ?>
                                <div class="stock-pivot-product-code"><?= $esc((string) $product['code']) ?></div>
                            <?php endif; ?>
                            <div class="stock-pivot-product-unit"><?= $esc((string) $product['unit']) ?></div>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sites as $siteIdx => $site): ?>
                    <tr class="<?= ($siteIdx % 2) === 1 ? 'stock-pivot-row--alt' : '' ?>">
                        <th class="stock-pivot-sticky-col stock-pivot-row-head" scope="row">
                            <?= $esc((string) $site['name']) ?>
                        </th>
                        <?php foreach ($products as $product): ?>
                            <?php
                            $qty = tnc_stock_pivot_cell_qty($matrix, (int) $site['id'], (int) $product['id']);
                            $qtyClass = $qty < 0 ? 'is-negative' : ($qty > 0 ? 'is-positive' : 'is-empty');
                            ?>
                            <td class="text-center stock-pivot-cell <?= $qtyClass ?>">
                                <?= $esc(tnc_stock_pivot_format_qty($qty)) ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
