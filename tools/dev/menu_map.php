<?php
declare(strict_types=1);

/**
 * Writes docs/MENU_MAP.md: every menu, its groups and pages (with the page keys used by page access), from the page
 * registry (app/registry.php + app/IspPages.php).
 *
 *   php tools/dev/menu_map.php [--out=docs/MENU_MAP.md]
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../../app/bootstrap.php';
$opts = getopt('', ['out::']);
$out = $opts['out'] ?? APP_ROOT . '/docs/MENU_MAP.md';
$reg = require APP_ROOT . '/app/registry.php';
$mods = $reg['modules'];
uasort($mods, fn($a, $b) => $a[2] <=> $b[2]);
$md = "# Menu map\n\nThe side menu of the staff pages: every menu, its groups and pages, with the page key used by page access\n"
    . "(System Administration > Page Access Configuration). A user sees only the pages a role gives.\n";
$total = 0;
foreach ($mods as $code => [$name, $icon]) {
    $groups = [];
    foreach ($reg['pages'] as $k => $d) {
        if ($d[0] !== $code || empty($d[6])) {
            continue;
        }
        $g = $d[2] === 'report' ? 'Reports: ' . $d[3] : $d[3];
        $groups[$g][] = $d[1] . ' `' . $k . '`';
        $total++;
    }
    if (!$groups) {
        continue;
    }
    $md .= "\n## " . $name . "\n\n";
    uksort($groups, fn($a, $b) => (str_starts_with($a, 'Reports') <=> str_starts_with($b, 'Reports')) ?: 0);
    foreach ($groups as $g => $items) {
        $md .= '- **' . $g . '** (' . count($items) . '): ' . implode('; ', $items) . "\n";
    }
}
$md .= "\n" . $total . " pages and reports in the menu.\n";
file_put_contents($out, $md);
echo "Wrote $out ($total entries)\n";
