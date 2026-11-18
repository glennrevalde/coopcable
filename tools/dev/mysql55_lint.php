<?php
declare(strict_types=1);

/**
 * Checks SQL files for what MySQL 5.5 does not accept (the program's database files must import on MySQL 5.5):
 *   - more than one TIMESTAMP column with DEFAULT / ON UPDATE CURRENT_TIMESTAMP in a table
 *   - DATETIME / DATE columns defaulting to CURRENT_TIMESTAMP or NOW()
 *   - JSON columns, generated columns, CHECK constraints, fractional seconds (DATETIME(6)), 0900 collations
 *   - indexed VARCHAR / CHAR columns longer than 191 characters in utf8mb4 (767-byte index limit)
 *   - MariaDB / MySQL 8 only syntax: ADD COLUMN IF NOT EXISTS, DROP INDEX IF EXISTS, CREATE OR REPLACE TABLE, CTEs (WITH ... AS), window functions
 *
 *   php tools/dev/mysql55_lint.php database/schema.sql database/migrations/*.sql database/gsmart_coop_cable_clean.sql
 */
$files = array_slice($argv, 1);
$problems = 0;
foreach ($files as $file) {
    $sql = (string)file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    // only the structure matters: strip string literals of INSERT rows quickly
    $ddl = [];
    if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*?)\)\s*ENGINE\s*=\s*\w+[^;]*;/is', $sql, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $ddl[$m[1]] = $m[2];
        }
    }
    $report = function (string $what) use ($file, &$problems) {
        $problems++;
        echo basename($file) . ': ' . $what . PHP_EOL;
    };
    foreach ($ddl as $table => $body) {
        $cols = [];
        foreach (preg_split('/,\s*\n/', $body) as $line) {
            $line = trim($line);
            if (preg_match('/^`?(\w+)`?\s+(\w+)(\((\d+)(?:,\d+)?\))?(.*)$/is', $line, $c) && !preg_match('/^(PRIMARY|KEY|UNIQUE|INDEX|CONSTRAINT|FOREIGN|FULLTEXT|CHECK)$/i', $c[1])) {
                $cols[strtolower($c[1])] = ['type' => strtoupper($c[2]), 'len' => ($c[4] ?? '') !== '' ? (int)$c[4] : null, 'rest' => $c[5]];
            }
        }
        $ts = 0;
        foreach ($cols as $name => $c) {
            $rest = strtoupper($c['rest']);
            if ($c['type'] === 'TIMESTAMP' && (str_contains($rest, 'CURRENT_TIMESTAMP') || str_contains($rest, 'NOW()'))) {
                $ts++;
            }
            if (in_array($c['type'], ['DATETIME', 'DATE'], true) && preg_match('/DEFAULT\s+(CURRENT_TIMESTAMP|NOW\(\))|ON\s+UPDATE/i', $c['rest'])) {
                $report("$table.$name: {$c['type']} cannot default to CURRENT_TIMESTAMP on MySQL 5.5");
            }
            if ($c['type'] === 'JSON') {
                $report("$table.$name: JSON type");
            }
            if (preg_match('/\b(GENERATED\s+ALWAYS|AS\s*\(.*\)\s*(STORED|VIRTUAL))/i', $c['rest'])) {
                $report("$table.$name: generated column");
            }
            if (in_array($c['type'], ['DATETIME', 'TIMESTAMP', 'TIME'], true) && $c['len'] !== null) {
                $report("$table.$name: fractional seconds");
            }
        }
        if ($ts > 1) {
            $report("$table: $ts TIMESTAMP columns with CURRENT_TIMESTAMP (MySQL 5.5 allows one)");
        }
        // indexed text columns
        if (preg_match_all('/(?:PRIMARY\s+KEY|UNIQUE(?:\s+KEY)?|KEY|INDEX)\s*`?\w*`?\s*\(((?:[^()]|\(\d+\))*)\)/i', $body, $km)) {
            foreach ($km[1] as $list) {
                foreach (explode(',', $list) as $part) {
                    if (preg_match('/`?(\w+)`?\s*(?:\((\d+)\))?/', trim($part), $p)) {
                        $c = $cols[strtolower($p[1])] ?? null;
                        $len = ($p[2] ?? '') !== '' ? (int)$p[2] : ($c['len'] ?? 0);
                        if ($c && in_array($c['type'], ['VARCHAR', 'CHAR'], true) && $len > 191) {
                            $report("$table.{$p[1]}: indexed {$c['type']}($len) exceeds 767 bytes in utf8mb4");
                        }
                        if ($c && in_array($c['type'], ['TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'BLOB'], true) && ($p[2] ?? '') === '') {
                            $report("$table.{$p[1]}: indexed {$c['type']} without a prefix length");
                        }
                    }
                }
            }
        }
        if (preg_match('/\bCHECK\s*\(/i', $body)) {
            $report("$table: CHECK constraint (ignored by 5.5, refused by some tools)");
        }
    }
    foreach (['/utf8mb4_0900_\w+/i' => 'MySQL 8 collation', '/ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS/i' => 'ADD COLUMN IF NOT EXISTS',
              '/DROP\s+(INDEX|COLUMN)\s+IF\s+EXISTS/i' => 'DROP INDEX/COLUMN IF EXISTS', '/CREATE\s+OR\s+REPLACE\s+TABLE/i' => 'CREATE OR REPLACE TABLE',
              '/^\s*WITH\s+(RECURSIVE\s+)?\w+\s+AS\s*\(/im' => 'common table expression', '/\b(ROW_NUMBER|RANK|DENSE_RANK|LAG|LEAD)\s*\(\s*\)\s*OVER\b/i' => 'window function',
              '/\bJSON_(EXTRACT|OBJECT|ARRAY|TABLE|UNQUOTE)\s*\(/i' => 'JSON function', '/\bcurrent_timestamp\(\)/' => 'current_timestamp() (MariaDB SHOW CREATE form)'] as $re => $what) {
        if (preg_match($re, $sql)) {
            $report($what);
        }
    }
    echo basename($file) . ': ' . count($ddl) . " tables checked\n";
}
exit($problems ? 1 : 0);
