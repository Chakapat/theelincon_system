<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

/**
 * Active stock products keyed by product id.
 *
 * @return array<int, array<string, mixed>>
 */
function tnc_stock_active_products(): array
{
    $products = [];
    foreach (Db::tableRows('stock_products') as $p) {
        if (empty($p['is_active'])) {
            continue;
        }
        $pid = (int) ($p['id'] ?? 0);
        if ($pid > 0) {
            $products[$pid] = $p;
        }
    }

    return $products;
}

/**
 * Raw movement rows for one site (sorted newest first) + checksum for live sync.
 *
 * @return array{products: array<int, array<string, mixed>>, movements: list<array<string, mixed>>, checksum: string}
 */
function tnc_stock_site_live_payload(int $siteId): array
{
    $products = tnc_stock_active_products();
    $movements = [];
    foreach (Db::filter('stock_movements', static function (array $r) use ($siteId): bool {
        return (int) ($r['site_id'] ?? 0) === $siteId;
    }) as $m) {
        $pid = (int) ($m['product_id'] ?? 0);
        if ($pid <= 0 || !isset($products[$pid])) {
            continue;
        }
        $movements[] = $m;
    }
    Db::sortRows($movements, 'created_at', true);

    return [
        'products' => $products,
        'movements' => $movements,
        'checksum' => hash('sha256', json_encode($movements, JSON_UNESCAPED_UNICODE)),
    ];
}
