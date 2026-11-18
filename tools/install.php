<?php
declare(strict_types=1);

/**
 * Command-line installer of the G-SMaRT Coop Cable System.
 *
 *   php tools/install.php                  create the database (if missing), tables, triggers and set-up data
 *   php tools/install.php --demo           ... plus sample transactions (accounting, cooperative, cable / internet)
 *   php tools/install.php --drop           drop and recreate the database first (DESTROYS DATA)
 *
 * Database settings come from config/config.php (or DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS).
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/seed.php';

$opts = getopt('', ['demo', 'drop']);
$name = $GLOBALS['CONFIG']['db']['name'];

$server = server_pdo();
if (isset($opts['drop'])) {
    out("Dropping database `$name` ...");
    $server->exec("DROP DATABASE IF EXISTS `$name`");
}
$server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$pdo = DB::pdo();
$existing = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn();
if ($existing && !isset($opts['drop'])) {
    out('The database already has tables. Use --drop to reinstall (this deletes all data).');
    exit(1);
}

out('Creating tables ...');
run_sql_file($pdo, APP_ROOT . '/database/schema.sql');
out('Creating audit trail protection triggers ...');
run_sql_file($pdo, APP_ROOT . '/database/triggers.sql');

out('Loading master data ...');
DB::tx(function () {
    seed_master_data();
});

// the cooperative module: its accounts, default accounts, roles and page access, number sequences, savings
// transaction codes, member types and policies come from its migration (written to be run again safely)
out('Loading the cooperative module set-up ...');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2026_11_01_cooperative.sql');
// the multipurpose set-up: the Parties menu and its page access, the business units and their defaults. The
// cooperative migration above gives its roles every page of the cooperative menus, the two pages this one adds
// there included; on a new database those two are left to this migration, so a new install gets the same rights
// as an upgraded one.
out('Loading the multipurpose set-up (parties, lines of business) ...');
$pdo->exec("DELETE a FROM role_page_access a JOIN pages p ON p.id = a.page_id WHERE p.page_key IN ('coop.business_units', 'rpt.sop_business_unit')");
run_sql_file($pdo, APP_ROOT . '/database/migrations/2026_11_15_multipurpose_party.sql');
// the class columns of the cooperative transactions and the journal entry changes with approval (their page gets the
// access of Make Journal Entries, which the cooperative roles were given above)
out('Loading the class columns and journal entry changes ...');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2026_12_20_member_lists_class_je_changes.sql');
// the member's customer and supplier defaults, the savings transaction center and its codes, the passbook
// layout and the cash account of a user profile (Savings Transaction Center gets the access of Savings Account
// Transaction, given to the cooperative roles above)
out('Loading the member defaults and the savings transaction center ...');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2026_12_27_member_defaults_savings_center.sql');
Settings::flush();

// the Loan Collection Form: error correction of an erroneous payslip (loan_txns.corrects_id / corrected_by_id)
out('Loading the loan collection error correction ...');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_01_03_loan_collection_error_correct.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_01_10_teller_menu_loan_release_vouchers.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_01_17_passbook_designer.sql');
// the account types (the fifteen built-in ones; Chart of Accounts > Account Types adds the user's own) and the summary
// statements' pages with their access
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_01_24_account_types.sql');
// loan adjustments printed in the loan passbook (Loan Adjustments > Print to Passbook)
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_01_31_adjustment_passbook.sql');
// the menus of version 39 and the savings transaction code INI of the imported balances
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_02_21_menus_imports_codes.sql');
// the cable / internet service provider system: its tables, menus, pages, roles and page access, accounts, line of
// business, disconnection rules, notification templates and scheduled jobs
out('Loading the cable / internet service provider set-up ...');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_04_01_isp_cable_system.sql');
Settings::flush();
IspSetup::installDefaults();
// the passbooks installed with the program: the savings passbook and the loan passbook of the former program, each
// with its own design (Cooperative > Passbook Types)
foreach (['Regular Passbook' => 'savings', 'Loan Passbook' => 'loan'] as $pbName => $pbLedger) {
    DB::update('passbook_types', Passbook::defaultSetup($pbLedger) + ['ledger' => $pbLedger, 'skip_lines' => null, 'layout_json' => json_encode(Passbook::defaultLayout($pbLedger))],
        'name = :n AND layout_json IS NULL', ['n' => $pbName]);
}

if (isset($opts['demo'])) {
    require __DIR__ . '/demo_data.php';
    out('Recording sample transactions ...');
    load_demo_data();
    require __DIR__ . '/coop_demo.php';
    out('Recording sample cooperative members, accounts and loans ...');
    load_coop_demo();
    // gives any sample record made without the program's save its party and document snapshot (safe to run again)
    run_sql_file($pdo, APP_ROOT . '/database/migrations/2026_11_15_multipurpose_party.sql');
}

if (isset($opts['demo'])) {
    require __DIR__ . '/isp_demo.php';
    out('Recording sample cable / internet subscribers, billing, collections and network activity ...');
    foreach (load_isp_demo() as $line) {
        out('  ' . $line);
    }
}

out('Done. Log in as admin / Admin@12345 (you will be asked to choose a new password).');
