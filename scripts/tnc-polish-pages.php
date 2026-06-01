<?php

declare(strict_types=1);

/**
 * Batch apply Impeccable / DESIGN.md polish to pages PHP files
 * Run: php scripts/tnc-polish-pages.php [--dry-run]
 */

$root = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv ?? [], true);
$stats = ['files' => 0, 'changed' => 0];

$skipFiles = [
    'purchase-batch-print.php',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/pages', RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $basename = $file->getFilename();
    if (in_array($basename, $skipFiles, true)) {
        continue;
    }

    $path = $file->getPathname();
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $content = file_get_contents($path);
    if ($content === false || !str_contains($content, 'navbar.php')) {
        continue;
    }

    $original = $content;
    $stats['files']++;

    // Legacy orange → copper
    $content = str_replace('#fd7e14', '#ea580c', $content);
    $content = str_replace('#e8590c', '#c2410c', $content);
    $content = str_replace('#e86c00', '#c2410c', $content);
    $content = str_replace('#f76707', '#c2410c', $content);

    // Primary buttons → orange utility
    $content = preg_replace('/\bbtn btn-primary\b/', 'btn btn-orange', $content) ?? $content;

    // Brand text-primary on headings (keep bg-primary-subtle etc.)
    $content = preg_replace(
        '/(<h[1-6][^>]*class="[^"]*)\btext-primary\b/',
        '$1text-tnc-orange',
        $content
    ) ?? $content;
    $content = preg_replace(
        '/(<i[^>]*class="[^"]*)\btext-primary\b/',
        '$1text-tnc-orange',
        $content
    ) ?? $content;

    // Body shell class
    if (!str_contains($content, 'tnc-app-body')) {
        if (preg_match('/<body\s*>/', $content)) {
            $content = preg_replace('/<body\s*>/', '<body class="tnc-app-body">', $content, 1) ?? $content;
        } elseif (preg_match('/<body class="([^"]*)">/', $content, $m)) {
            $content = preg_replace(
                '/<body class="' . preg_quote($m[1], '/') . '">/',
                '<body class="' . $m[1] . ' tnc-app-body">',
                $content,
                1
            ) ?? $content;
        }
    }

    // Drop redundant body bg when using tnc-app-body
    $content = preg_replace(
        '/body\s*\{\s*background-color:\s*#f8f9fa;\s*font-family:\s*[\'"]Sarabun[\'"],\s*sans-serif;\s*\}/',
        '/* body canvas: tnc-app.css */',
        $content
    ) ?? $content;

    // Duplicate btn-orange inline (common pattern)
    $content = preg_replace(
        '/\.btn-orange\s*\{\s*background-color:\s*#ea580c;[^}]+\}\s*\.btn-orange:hover[^}]+\}/',
        '/* .btn-orange: tnc-app.css */',
        $content
    ) ?? $content;

    if ($content !== $original) {
        $stats['changed']++;
        if (!$dryRun) {
            file_put_contents($path, $content);
        }
        echo ($dryRun ? '[dry] ' : '') . "polished: {$rel}\n";
    }
}

// sign-in (no navbar)
$signIn = $root . '/sign-in.php';
if (is_file($signIn)) {
    $content = file_get_contents($signIn);
    $original = $content;
    $content = str_replace('#fd7e14', '#ea580c', $content);
    $content = str_replace('#e8590c', '#c2410c', $content);
    if ($content !== $original) {
        $stats['changed']++;
        if (!$dryRun) {
            file_put_contents($signIn, $content);
        }
        echo ($dryRun ? '[dry] ' : '') . "polished: sign-in.php\n";
    }
}

echo "\nDone. Scanned with navbar: {$stats['files']}, changed: {$stats['changed']}" . ($dryRun ? ' (dry-run)' : '') . "\n";
