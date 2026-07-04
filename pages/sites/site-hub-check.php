<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

/**
 * ตรวจว่า site-hub โหลด dependencies ได้ครบ (ลบไฟล์นี้หลังแก้ปัญหาแล้ว)
 */
header('Content-Type: text/plain; charset=UTF-8');

$root = dirname(__DIR__, 2);
$steps = [
    'foundation' => $root . '/config/foundation.php',
    'connect_database' => $root . '/config/connect_database.php',
    'site_budget' => $root . '/includes/site_budget.php',
    'site_cost_categories' => $root . '/includes/site_cost_categories.php',
    'sites' => $root . '/includes/sites.php',
    'site-hub.php syntax' => $root . '/pages/sites/site-hub.php',
];

$deploySizes = [
    'pages/sites/site-hub.php' => null,
    'includes/site_cost_categories.php' => null,
];

echo "=== Deploy size check (compare with local) ===\n";
foreach ($deploySizes as $rel => $_) {
    $path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (is_file($path)) {
        echo number_format((int) filesize($path)) . " bytes  {$rel}\n";
    } else {
        echo "MISSING  {$rel}\n";
    }
}
echo "\n";

foreach ($steps as $label => $path) {
    if ($label === 'site-hub.php syntax') {
        try {
            token_get_all((string) file_get_contents($path), TOKEN_PARSE);
            echo "OK    {$label}\n";
        } catch (ParseError $e) {
            echo "FAIL  {$label} — " . $e->getMessage() . "\n";
            echo '      ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
        continue;
    }
    if (!is_file($path)) {
        echo "FAIL  {$label} — file missing: {$path}\n";
        continue;
    }
    try {
        require_once $path;
        echo "OK    {$label}\n";
    } catch (Throwable $e) {
        echo "FAIL  {$label} — " . $e->getMessage() . "\n";
        echo '      ' . $e->getFile() . ':' . $e->getLine() . "\n";
        break;
    }
}

$requiredFns = [
    'tnc_site_category_references_site_index',
    'tnc_site_category_list_references',
    'tnc_site_category_remap_documents_for_site',
    'tnc_site_category_is_valid_for_site',
];
foreach ($requiredFns as $fn) {
    echo (function_exists($fn) ? 'OK' : 'FAIL') . "    {$fn}()\n";
}

echo "\n=== Runtime load (site_id=1) ===\n";
try {
    $siteId = 1;
    $site = Db::rowByIdField('sites', $siteId);
    if (!is_array($site)) {
        echo "SKIP  site id=1 not found\n";
    } else {
        $t0 = microtime(true);
        $summary = tnc_site_budget_site_summary_for_site_row($siteId, $site);
        $t1 = microtime(true);
        echo 'OK    budget summary — ' . count($summary['categories'] ?? []) . ' categories (' . round($t1 - $t0, 2) . "s)\n";

        $t0 = microtime(true);
        $index = tnc_site_category_references_site_index($siteId);
        $t1 = microtime(true);
        $prCats = count($index['prs_by_cat'] ?? []);
        $poCats = count($index['pos_by_cat'] ?? []);
        echo "OK    references index — PR cats={$prCats}, PO cats={$poCats} (" . round($t1 - $t0, 2) . "s)\n";

        $t0 = microtime(true);
        $docsMap = [];
        foreach ($summary['categories'] ?? [] as $catRow) {
            $cid = (int) ($catRow['id'] ?? 0);
            if ($cid > 0) {
                $docsMap[$cid] = tnc_site_category_list_references($cid, $siteId);
            }
            foreach ($catRow['children'] ?? [] as $childRow) {
                $childId = (int) ($childRow['id'] ?? 0);
                if ($childId > 0) {
                    $docsMap[$childId] = tnc_site_category_list_references($childId, $siteId);
                }
            }
        }
        $json = json_encode($docsMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
        $t1 = microtime(true);
        echo 'OK    docsMap json — ' . strlen((string) $json) . ' bytes (' . round($t1 - $t0, 2) . "s)\n";
        echo '      peak memory ' . number_format((int) memory_get_peak_usage(true)) . " bytes\n";
    }
} catch (Throwable $e) {
    echo 'FAIL  runtime — ' . $e->getMessage() . "\n";
    echo '      ' . $e->getFile() . ':' . $e->getLine() . "\n";
}

echo "\nDone.\n";
