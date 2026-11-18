<?php
declare(strict_types=1);

/**
 * Writes one importable .sql file (structure + every row + triggers) from the current database, and optionally the
 * master (platform) database of the multi-company set-up in the same file.
 *
 *   php tools/export_sql.php --out=database/gsmart_coop_cable_sample.sql
 *   php tools/export_sql.php --out=file.sql --master          also the master database (Super Admin, companies, codes)
 *   php tools/export_sql.php --title="..." --note="line 1\nline 2"
 *   php tools/export_sql.php --no-create-db                    leave out CREATE DATABASE / USE (import into a chosen database)
 *
 * The structure is written in the MySQL 5.5 form of the program's own files (database/schema.sql, the cable / internet
 * tables of database/migrations/2027_04_01_isp_cable_system.sql and database/master_schema.sql), never as the server
 * that runs the export would describe it, so the file imports on MySQL 5.5 as well as on newer MySQL and MariaDB.
 *
 * Import: phpMyAdmin (Import tab) or  mysql -u root -p < file.sql . Importing replaces every table of the databases.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

$opts = getopt('', ['out::', 'no-create-db', 'title::', 'note::', 'master']);
$out = $opts['out'] ?? (APP_ROOT . '/database/gsmart_coop_cable.sql');
$name = DB::databaseName();
$pdo = DB::pdo();

$schema = (string)file_get_contents(APP_ROOT . '/database/schema.sql');
$schema = preg_replace('/^SET FOREIGN_KEY_CHECKS\s*=\s*[01];\s*$/mi', '', $schema);
$schema = preg_replace('/^SET NAMES utf8mb4;\s*$/mi', '', $schema);
$triggers = trim((string)file_get_contents(APP_ROOT . '/database/triggers.sql'));
[$ispSql, $ispTables] = create_tables_of(APP_ROOT . '/database/migrations/2027_04_01_isp_cable_system.sql');
$tables = array_values(array_unique(array_merge(schema_tables(APP_ROOT . '/database/schema.sql'), $ispTables)));

// every table of the database must be in the files (a table the files do not describe would be lost)
$inDb = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
$missing = array_diff($inDb, $tables);
if ($missing) {
    fwrite(STDERR, 'These tables are not described by the program files and would be lost: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}
$tables = array_values(array_intersect($tables, $inDb));
$counts = [];
foreach ($tables as $t) {
    $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}

$master = null;
if (isset($opts['master'])) {
    if (!Master::enabled()) {
        fwrite(STDERR, "--master: multi-company is not set up on this installation.\n");
        exit(1);
    }
    $masterName = Master::dbName();
    $mpdo = Sql::to($masterName)->pdo;
    [$masterSql, $masterTables] = create_tables_of(APP_ROOT . '/database/master_schema.sql');
    $mcounts = [];
    foreach ($masterTables as $t) {
        $mcounts[$t] = (int)$mpdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    }
    $master = [$masterName, $mpdo, $masterSql, $masterTables, $mcounts];
}

$title = $opts['title'] ?? 'G-SMaRT Coop Cable System - complete database';
$head = "-- =====================================================================\n"
    . "--  $title\n"
    . '--  Generated ' . date('Y-m-d H:i:s P') . "\n"
    . "--  Company database `$name`: " . count($tables) . ' tables (' . (count($tables) - count($ispTables)) . ' accounting / cooperative, ' . count($ispTables) . " cable / internet),\n"
    . '--  ' . number_format(array_sum($counts)) . ' rows (' . ($counts['transactions'] ?? 0) . ' ledger documents, ' . ($counts['isp_accounts'] ?? 0) . " subscriber accounts)\n"
    . ($master ? "--  Master database `{$master[0]}` (Super Admin, companies, Company Codes): " . count($master[3]) . ' tables, ' . array_sum($master[4]) . " rows\n" : '')
    . "--\n"
    . "--  Import:  phpMyAdmin > Import > choose this file > Go\n"
    . '--      or:  mysql -u root -p < ' . basename($out) . "\n"
    . "--  Works on MySQL 5.5.3 or newer (5.5, 5.6, 5.7, 8.x) and MariaDB 5.5 or newer.\n"
    . "--  Import as root (or a user with CREATE, INSERT, REFERENCES and TRIGGER privileges).\n"
    . "--  WARNING: importing drops and recreates every table of the database(s) below.\n";
foreach (array_filter(explode("\n", str_replace('\n', "\n", (string)($opts['note'] ?? '')))) as $line) {
    $head .= '--  ' . $line . "\n";
}
$head .= "-- =====================================================================\n\n"
    . "SET NAMES utf8mb4;\n"
    . "SET FOREIGN_KEY_CHECKS = 0;\n"
    . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
    . "SET time_zone = '" . date('P') . "';\n\n";

$fh = fopen($out, 'wb');
fwrite($fh, $head);
if (!isset($opts['no-create-db'])) {
    fwrite($fh, "CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nUSE `$name`;\n\n");
}
fwrite($fh, "-- ---------------------------------------------------------------------\n--  1. TABLES (database/schema.sql)\n-- ---------------------------------------------------------------------\n"
    . trim($schema) . "\n\n"
    . "-- ---------------------------------------------------------------------\n--  2. CABLE / INTERNET TABLES (database/migrations/2027_04_01_isp_cable_system.sql)\n-- ---------------------------------------------------------------------\n"
    . $ispSql . "\n"
    . "-- ---------------------------------------------------------------------\n--  3. DATA\n-- ---------------------------------------------------------------------\n");
foreach ($tables as $t) {
    fwrite($fh, export_data($pdo, [$t]));
}
fwrite($fh, "\n-- ---------------------------------------------------------------------\n--  4. AUDIT TRAIL PROTECTION (created after the data is loaded)\n-- ---------------------------------------------------------------------\n"
    . $triggers . "\n\n");

if ($master) {
    [$masterName, $mpdo, $masterSql, $masterTables] = $master;
    fwrite($fh, "-- =====================================================================\n--  MASTER (PLATFORM) DATABASE: the Super Admin, the companies, their\n"
        . "--  databases and Company Codes, and which company each username belongs to\n-- =====================================================================\n\n");
    if (!isset($opts['no-create-db'])) {
        fwrite($fh, "CREATE DATABASE IF NOT EXISTS `$masterName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nUSE `$masterName`;\n\n");
    }
    fwrite($fh, $masterSql . "\n");
    foreach ($masterTables as $t) {
        fwrite($fh, export_data($mpdo, [$t]));
    }
    fwrite($fh, "\nDROP TRIGGER IF EXISTS trg_platform_audit_no_update;\nDROP TRIGGER IF EXISTS trg_platform_audit_no_delete;\nDELIMITER $$\n"
        . "CREATE TRIGGER trg_platform_audit_no_update BEFORE UPDATE ON platform_audit FOR EACH ROW\nBEGIN\n  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform audit entries are read-only.';\nEND$$\n"
        . "CREATE TRIGGER trg_platform_audit_no_delete BEFORE DELETE ON platform_audit FOR EACH ROW\nBEGIN\n  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform audit entries are permanent.';\nEND$$\nDELIMITER ;\n\n");
    if (!isset($opts['no-create-db'])) {
        fwrite($fh, "USE `$name`;\n");
    }
}
fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
fclose($fh);
out(sprintf('Wrote %s (%s KB, %d tables, %d rows%s).', $out, number_format(filesize($out) / 1024), count($tables), array_sum($counts),
    $master ? ', master database ' . $master[0] . ' with ' . array_sum($master[4]) . ' rows' : ''));

/**
 * The CREATE TABLE statements of a program SQL file, each preceded by DROP TABLE IF EXISTS (an import replaces the
 * table), and the table names. Everything else in the file (INSERTs of set-up rows, SET statements) is left out: the
 * data section carries the rows as they are in the database exported.
 *
 * @return array{0:string,1:string[]}
 */
function create_tables_of(string $file): array
{
    $sql = '';
    $names = [];
    foreach (Migrator::split((string)file_get_contents($file)) as $stmt) {
        $s = trim($stmt);
        if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $s, $m)) {
            $names[] = $m[1];
            $sql .= "DROP TABLE IF EXISTS `{$m[1]}`;\n" . preg_replace('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+/i', 'CREATE TABLE ', $s) . ";\n\n";
        }
    }
    if (!$names) {
        throw new RuntimeException("No tables found in $file.");
    }
    return [$sql, $names];
}
