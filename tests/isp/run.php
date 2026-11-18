<?php
declare(strict_types=1);

/**
 * Automated tests of the cable / internet service provider system (app/Isp*.php), run against a real MySQL /
 * MariaDB database through the same classes the pages use.
 *
 *   DB_NAME=gsc_i DB_PASS='' php tests/isp/run.php            builds a fresh test database, runs every test
 *   php tests/isp/run.php --filter=R1                         only the tests whose name contains "R1"
 *   php tests/isp/run.php --no-install                        reuses the database as it is (after a failed setup, for debugging)
 *   The test database is left in place afterwards for inspection; the next run rebuilds it.
 *
 * The database named by DB_NAME (default isp_test) is DROPPED and recreated by tools/install.php --drop, then the
 * fixtures of tests/isp/fixtures.php are made through the application classes. Expected amounts are worked out in
 * the tests with plain arithmetic on the plan prices, independently of the billing engine.
 *
 *   tests/isp/cases_required.php   R01-R25: the 25 required tests of the specification (section 38)
 *   tests/isp/cases_workflows.php  WA-WI: the nine end-to-end business workflows (section 35)
 *   tests/isp/cases_boundary.php   B01-B12: boundary cases (leap years, month ends, zero balances, allocation errors,
 *                                  concurrent cashiers, network downtime, state conflicts, branch isolation, portal
 *                                  ownership, encrypted secrets, member savings auto-debit)
 *
 * Results are printed and written to tests/isp/RESULTS.md.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['filter:', 'no-install']);
if (!getenv('DB_NAME')) {
    putenv('DB_NAME=isp_test');
}
if (getenv('DB_PASS') === false) {
    putenv('DB_PASS=');
}
require __DIR__ . '/../../app/bootstrap.php';
require APP_ROOT . '/tools/lib.php';
require APP_ROOT . '/tools/coop_fixtures.php';
require APP_ROOT . '/modules/txn/_common.php';

$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$dbName = $GLOBALS['CONFIG']['db']['name'];
if (in_array($dbName, ['gsmart_coop_cable', 'accounting_system'], true) || str_ends_with($dbName, '_master')) {
    fwrite(STDERR, "Refusing to run the tests on the database `$dbName`. Set DB_NAME to a throw-away database.\n");
    exit(1);
}
if (is_file(APP_ROOT . '/storage/platform/multi_company.json')) {
    fwrite(STDERR, "Several companies are set up (storage/platform/multi_company.json): run the tests on a single-company copy of the program.\n");
    exit(1);
}

// ---------------------------------------------------------------------------------------- the test database
$started = microtime(true);
if (!isset($opts['no-install'])) {
    echo "Installing a fresh database `$dbName` (tools/install.php --drop) ...\n";
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tools/install.php') . ' --drop 2>&1';
    exec($cmd, $outLines, $code);
    if ($code !== 0) {
        fwrite(STDERR, "The installer failed:\n" . implode("\n", $outLines) . "\n");
        exit(1);
    }
}
Settings::flush();
Accts::reset();
Access::reset();
IspSetup::reset();
$adminId = (int)DB::value("SELECT id FROM users WHERE username = 'admin'");
Auth::actAs($adminId);

require __DIR__ . '/fixtures.php';
$ids = isp_t_setup();
$setupSeconds = round(microtime(true) - $started, 1);

// ---------------------------------------------------------------------------------------- tiny test harness
$results = [];
$filter = $opts['filter'] ?? '';

final class AssertionFailed extends RuntimeException
{
}

function test(string $name, callable $fn): void
{
    global $results, $filter;
    if ($filter !== '' && stripos($name, $filter) === false) {
        return;
    }
    $t0 = microtime(true);
    $GLOBALS['CHECKS'] = 0;
    try {
        $fn();
        $results[] = ['name' => $name, 'ok' => true, 'checks' => $GLOBALS['CHECKS'], 'ms' => (int)((microtime(true) - $t0) * 1000), 'msg' => ''];
        echo "  PASS  $name ({$GLOBALS['CHECKS']} checks)\n";
    } catch (Throwable $e) {
        while (DB::inTransaction()) {
            DB::rollBack();
        }
        $where = $e instanceof AssertionFailed ? '' : ' [' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        $results[] = ['name' => $name, 'ok' => false, 'checks' => $GLOBALS['CHECKS'], 'ms' => (int)((microtime(true) - $t0) * 1000), 'msg' => $e->getMessage() . $where];
        echo "  FAIL  $name\n        " . $e->getMessage() . $where . "\n";
        if (!$e instanceof AssertionFailed && !$e instanceof UserError) {
            echo '        ' . str_replace("\n", "\n        ", $e->getTraceAsString()) . "\n";
        }
    }
    // every test leaves the ledger balanced and the subscriber ledger equal to the books
    try {
        isp_t_reset_device();
        Auth::actAs($GLOBALS['adminId']);
        $_POST = [];
        $bad = DB::all('SELECT txn_id, SUM(debit) d, SUM(credit) c FROM journal_lines GROUP BY txn_id HAVING ROUND(SUM(debit), 2) <> ROUND(SUM(credit), 2)');
        if ($bad) {
            echo "        !! after $name: unbalanced ledger transactions " . json_encode($bad) . "\n";
        }
    } catch (Throwable $e) {
        echo '        !! after ' . $name . ': ' . $e->getMessage() . "\n";
    }
}

function check(bool $cond, string $what): void
{
    $GLOBALS['CHECKS']++;
    if (!$cond) {
        throw new AssertionFailed($what);
    }
}

/** money equality to the centavo */
function eqm($actual, $expected, string $what): void
{
    $a = number_format(round((float)$actual, 2), 2, '.', '');
    $e = number_format(round((float)$expected, 2), 2, '.', '');
    check($a === $e, "$what: expected $e, got $a");
}

function eq($actual, $expected, string $what): void
{
    check($actual === $expected, "$what: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
}

/** The callable must stop with a user error whose text contains $needle; nothing it did stays */
function refuses(callable $fn, string $needle, string $what): void
{
    try {
        $fn();
    } catch (UserError $e) {
        while (DB::inTransaction()) {
            DB::rollBack();
        }
        check(stripos($e->getMessage(), $needle) !== false, "$what: refused, but with \"" . $e->getMessage() . "\" instead of \"$needle\"");
        return;
    }
    check(false, "$what: was accepted, it should have been refused (\"$needle\")");
}

// ---------------------------------------------------------------------------------------- the tests
echo "Setup done in {$setupSeconds}s. Running the tests ...\n";
require __DIR__ . '/cases_required.php';
require __DIR__ . '/cases_workflows.php';
require __DIR__ . '/cases_boundary.php';

// the whole ledger at the end
test('Z01 Final: every posted transaction balances and the subscriber ledger equals the general ledger', function () {
    isp_t_assert_books('end of the run');
});

// ---------------------------------------------------------------------------------------- the results
$pass = count(array_filter($results, fn($r) => $r['ok']));
$fail = count($results) - $pass;
$checks = array_sum(array_column($results, 'checks'));
$secs = round(microtime(true) - $started, 1);
echo "\n" . count($results) . " tests, $pass passed, $fail failed, $checks checks, {$secs}s\n";

if ($filter === '') {
    $ver = (string)DB::value('SELECT VERSION()');
    $md = "# Cable / internet system: automated test results\n\n"
        . '- Date: ' . date('Y-m-d H:i:s T') . "\n"
        . '- Command: `DB_NAME=' . $dbName . " DB_PASS='' php tests/isp/run.php`\n"
        . '- PHP ' . PHP_VERSION . ', database server ' . $ver . "\n"
        . '- Database: `' . $dbName . "` (dropped and rebuilt by tools/install.php --drop, fixtures by tests/isp/fixtures.php)\n"
        . '- ' . count($results) . " tests, **$pass passed, $fail failed**, $checks checks, {$secs} s\n\n"
        . "| # | Test | Result | Checks | ms |\n|---|---|---|---:|---:|\n";
    $i = 0;
    foreach ($results as $r) {
        $md .= '| ' . ++$i . ' | ' . str_replace('|', '\\|', $r['name']) . ' | ' . ($r['ok'] ? 'PASS' : 'FAIL: ' . str_replace(['|', "\n"], ['\\|', ' '], $r['msg'])) . ' | ' . $r['checks'] . ' | ' . $r['ms'] . " |\n";
    }
    $md .= "\nR01-R25 are the required tests of the specification (section 38), WA-WI the end-to-end workflows A to I (section 35), "
        . "B01-B12 the boundary cases. After every test the runner also checks that no posted transaction is unbalanced.\n";
    file_put_contents(__DIR__ . '/RESULTS.md', $md);
    echo "Results written to tests/isp/RESULTS.md\n";
}
exit($fail ? 1 : 0);
