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

function tnc_stock_site_name(int $siteId): string
{
    if ($siteId <= 0) {
        return '';
    }
    $row = Db::rowByIdField('sites', $siteId);

    return trim((string) ($row['name'] ?? ''));
}

function tnc_stock_site_id_by_name(string $name): int
{
    $needle = mb_strtolower(trim($name), 'UTF-8');
    if ($needle === '') {
        return 0;
    }
    foreach (Db::tableRows('sites') as $s) {
        $sid = (int) ($s['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        if (mb_strtolower(trim((string) ($s['name'] ?? '')), 'UTF-8') === $needle) {
            return $sid;
        }
    }

    return 0;
}

/** Next free numeric movement id (checks Firebase PK and logical id field). */
function tnc_stock_next_movement_id(): int
{
    $id = Db::nextNumericId('stock_movements', 'id');
    while (
        Db::row('stock_movements', (string) $id) !== null
        || Db::rowByIdField('stock_movements', $id) !== null
    ) {
        ++$id;
    }

    return $id;
}

/**
 * Allocate two free numeric movement ids (out/in) without colliding on PK or logical id.
 *
 * @return array{0: int, 1: int}
 */
function tnc_stock_allocate_transfer_ids(): array
{
    $outId = tnc_stock_next_movement_id();
    $inId = $outId + 1;
    while (
        Db::row('stock_movements', (string) $inId) !== null
        || Db::rowByIdField('stock_movements', $inId) !== null
    ) {
        ++$inId;
    }

    return [$outId, $inId];
}

/**
 * Whether an outbound movement targets the given destination site (by id or name).
 *
 * @param array<string, mixed> $m
 */
function tnc_stock_out_targets_site(array $m, int $toSiteId, string $toName = ''): bool
{
    if ((string) ($m['movement_type'] ?? '') !== 'out') {
        return false;
    }
    $counter = (int) ($m['counter_site_id'] ?? 0);
    if ($counter > 0 && $counter === $toSiteId) {
        return true;
    }
    if ($toName === '') {
        $toName = tnc_stock_site_name($toSiteId);
    }
    $counterName = trim((string) ($m['counter_site_name'] ?? ''));
    if ($toName !== '' && $counterName !== ''
        && mb_strtolower($counterName, 'UTF-8') === mb_strtolower($toName, 'UTF-8')) {
        return true;
    }

    return false;
}

/**
 * Resolve destination site id from an outbound transfer row.
 *
 * @param array<string, mixed> $out
 */
function tnc_stock_resolve_transfer_to_site_id(array $out): int
{
    $to = (int) ($out['counter_site_id'] ?? 0);
    if ($to > 0) {
        return $to;
    }
    $name = trim((string) ($out['counter_site_name'] ?? ''));
    if ($name === '') {
        $note = trim((string) ($out['note'] ?? ''));
        if (str_starts_with($note, 'โอนไปยัง ')) {
            $name = trim(mb_substr($note, mb_strlen('โอนไปยัง ', 'UTF-8'), null, 'UTF-8'));
        }
    }
    if ($name === '') {
        return 0;
    }

    return tnc_stock_site_id_by_name($name);
}

/**
 * Create the missing inbound leg when an outbound transfer points at $toSiteId
 * but no matching inbound movement exists.
 *
 * @return int number of inbound rows created
 */
function tnc_stock_ensure_inbound_transfer_legs(int $toSiteId): int
{
    if ($toSiteId <= 0) {
        return 0;
    }

    $toName = tnc_stock_site_name($toSiteId);
    /** @var array<string, list<array<string, mixed>>> $byRef */
    $byRef = [];
    /** @var list<array<string, mixed>> $orphanOuts */
    $orphanOuts = [];

    foreach (Db::tableRows('stock_movements') as $m) {
        $ref = trim((string) ($m['transfer_ref'] ?? ''));
        if ($ref !== '') {
            $byRef[$ref][] = $m;
            continue;
        }
        if (tnc_stock_out_targets_site($m, $toSiteId, $toName)) {
            $orphanOuts[] = $m;
        }
    }

    $created = 0;

    foreach ($byRef as $ref => $rows) {
        $out = null;
        $hasInOnTo = false;
        foreach ($rows as $m) {
            $type = (string) ($m['movement_type'] ?? '');
            $sid = (int) ($m['site_id'] ?? 0);
            if ($type === 'out' && tnc_stock_out_targets_site($m, $toSiteId, $toName)) {
                $out = $m;
            }
            if ($type === 'in' && $sid === $toSiteId) {
                $hasInOnTo = true;
            }
        }
        if ($out === null || $hasInOnTo || tnc_stock_has_matching_inbound($out, $toSiteId)) {
            continue;
        }
        // Backfill counter_site_id when only the name was stored.
        if ((int) ($out['counter_site_id'] ?? 0) <= 0) {
            $outPk = Db::pkForLogicalId('stock_movements', (int) ($out['id'] ?? 0));
            Db::mergeRow('stock_movements', $outPk, [
                'counter_site_id' => $toSiteId,
                'counter_site_name' => $toName !== '' ? $toName : (string) ($out['counter_site_name'] ?? ''),
            ]);
            $out['counter_site_id'] = $toSiteId;
        }
        if (tnc_stock_create_inbound_from_out($out, $toSiteId, $toName, $ref)) {
            ++$created;
        }
    }

    foreach ($orphanOuts as $out) {
        $fromSite = (int) ($out['site_id'] ?? 0);
        $productId = (int) ($out['product_id'] ?? 0);
        $qtyAbs = abs((float) ($out['qty'] ?? 0));
        if ($fromSite <= 0 || $productId <= 0 || $qtyAbs <= 0) {
            continue;
        }
        if (tnc_stock_has_matching_inbound($out, $toSiteId)) {
            continue;
        }

        $ref = bin2hex(random_bytes(8));
        $outPk = Db::pkForLogicalId('stock_movements', (int) ($out['id'] ?? 0));
        Db::mergeRow('stock_movements', $outPk, [
            'transfer_ref' => $ref,
            'counter_site_id' => $toSiteId,
            'counter_site_name' => $toName !== '' ? $toName : (string) ($out['counter_site_name'] ?? ''),
        ]);
        $out['transfer_ref'] = $ref;
        $out['counter_site_id'] = $toSiteId;
        $out['counter_site_name'] = $toName !== '' ? $toName : (string) ($out['counter_site_name'] ?? '');

        if (tnc_stock_create_inbound_from_out($out, $toSiteId, $toName, $ref)) {
            ++$created;
        }
    }

    return $created;
}

/**
 * When viewing a source site, also create missing inbound legs on every destination
 * that this site has transferred to.
 *
 * @return int number of inbound rows created across destinations
 */
function tnc_stock_ensure_outbound_destination_legs(int $fromSiteId): int
{
    if ($fromSiteId <= 0) {
        return 0;
    }

    /** @var array<int, true> $destIds */
    $destIds = [];
    foreach (Db::tableRows('stock_movements') as $m) {
        if ((int) ($m['site_id'] ?? 0) !== $fromSiteId) {
            continue;
        }
        if ((string) ($m['movement_type'] ?? '') !== 'out') {
            continue;
        }
        $ref = trim((string) ($m['transfer_ref'] ?? ''));
        $counter = (int) ($m['counter_site_id'] ?? 0);
        $counterName = trim((string) ($m['counter_site_name'] ?? ''));
        if ($ref === '' && $counter <= 0 && $counterName === '') {
            continue;
        }
        $to = tnc_stock_resolve_transfer_to_site_id($m);
        if ($to > 0 && $to !== $fromSiteId) {
            $destIds[$to] = true;
        }
    }

    $created = 0;
    foreach (array_keys($destIds) as $toId) {
        $created += tnc_stock_ensure_inbound_transfer_legs($toId);
    }

    return $created;
}

/**
 * True when destination site already has an inbound that matches this outbound transfer.
 *
 * @param array<string, mixed> $out
 */
function tnc_stock_has_matching_inbound(array $out, int $toSiteId): bool
{
    $productId = (int) ($out['product_id'] ?? 0);
    $qtyAbs = abs((float) ($out['qty'] ?? 0));
    $createdAt = (string) ($out['created_at'] ?? '');
    $fromSite = (int) ($out['site_id'] ?? 0);
    $ref = trim((string) ($out['transfer_ref'] ?? ''));

    foreach (Db::tableRows('stock_movements') as $m) {
        if ((string) ($m['movement_type'] ?? '') !== 'in') {
            continue;
        }
        if ((int) ($m['site_id'] ?? 0) !== $toSiteId) {
            continue;
        }
        $mRef = trim((string) ($m['transfer_ref'] ?? ''));
        if ($ref !== '' && $mRef === $ref) {
            return true;
        }
        if ((int) ($m['product_id'] ?? 0) !== $productId) {
            continue;
        }
        if (abs(abs((float) ($m['qty'] ?? 0)) - $qtyAbs) > 0.0001) {
            continue;
        }
        if ($createdAt !== '' && (string) ($m['created_at'] ?? '') !== $createdAt) {
            continue;
        }
        $sourceId = (int) ($m['source_site_id'] ?? 0);
        if ($sourceId > 0 && $fromSite > 0 && $sourceId !== $fromSite) {
            continue;
        }

        return true;
    }

    return false;
}

/**
 * @param array<string, mixed> $out
 */
function tnc_stock_create_inbound_from_out(array $out, int $toSiteId, string $toName, string $transferRef): bool
{
    $fromSite = (int) ($out['site_id'] ?? 0);
    $productId = (int) ($out['product_id'] ?? 0);
    $qtyAbs = abs((float) ($out['qty'] ?? 0));
    if ($fromSite <= 0 || $productId <= 0 || $qtyAbs <= 0 || $transferRef === '') {
        return false;
    }

    $fromName = tnc_stock_site_name($fromSite);
    if ($fromName === '') {
        $fromName = 'ไซต์ #' . $fromSite;
    }
    if ($toName === '') {
        $toName = tnc_stock_site_name($toSiteId);
    }

    $note = trim((string) ($out['note'] ?? ''));
    // Prefer a destination-facing note when the out note is the default "โอนไปยัง …".
    if ($note === '' || str_starts_with($note, 'โอนไปยัง ')) {
        $note = 'รับจาก ' . $fromName;
    }

    $inId = tnc_stock_next_movement_id();

    Db::setRow('stock_movements', (string) $inId, [
        'id' => $inId,
        'site_id' => $toSiteId,
        'product_id' => $productId,
        'person_name' => mb_substr(trim((string) ($out['person_name'] ?? '')), 0, 120, 'UTF-8'),
        'qty' => $qtyAbs,
        'movement_type' => 'in',
        'note' => $note,
        'transfer_ref' => $transferRef,
        'source_site_id' => $fromSite,
        'source_site_name' => $fromName,
        'created_by' => (int) ($out['created_by'] ?? 0),
        'created_at' => (string) ($out['created_at'] ?? date('Y-m-d H:i:s')),
    ]);

    return Db::row('stock_movements', (string) $inId) !== null
        || Db::rowByIdField('stock_movements', $inId) !== null;
}

/**
 * Raw movement rows for one site (sorted newest first) + checksum for live sync.
 * Ensures inbound legs exist for transfers that target this site, and also
 * backfills destinations for transfers that originated from this site.
 *
 * @return array{products: array<int, array<string, mixed>>, movements: list<array<string, mixed>>, checksum: string}
 */
function tnc_stock_site_live_payload(int $siteId): array
{
    if ($siteId > 0) {
        tnc_stock_ensure_inbound_transfer_legs($siteId);
        tnc_stock_ensure_outbound_destination_legs($siteId);
    }

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
