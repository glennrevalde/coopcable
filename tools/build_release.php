<?php
declare(strict_types=1);

/**
 * Builds the two delivered database files from scratch (for the maintainers of the program):
 *
 *   php tools/build_release.php clean    -> database/gsmart_coop_cable_clean.sql
 *   php tools/build_release.php sample   -> database/gsmart_coop_cable_sample.sql
 *
 * Each run: drops and installs the company database (tools/install.php, with --demo for the sample), finishes it
 * (clean: removes the example names of the base set-up and leaves generic company details; both: one user per role),
 * registers it as Company 1 of the multi-company platform with the Super Admin (the master database), and exports the
 * company and master databases into one file (tools/export_sql.php --master).
 *
 * DESTROYS the databases named in config/config.php (and its _master database). Never run it on a live server.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$mode = $argv[1] ?? '';
$step = $argv[2] ?? '';
if (!in_array($mode, ['clean', 'sample'], true)) {
    fwrite(STDERR, "Usage: php tools/build_release.php clean|sample\n");
    exit(1);
}
const SUPERADMIN = ['username' => 'superadmin', 'full_name' => 'Platform Super Admin', 'email' => '', 'password' => 'SuperAdmin@2027'];

$root = dirname(__DIR__);
$php = escapeshellarg(PHP_BINARY);
$run = function (string $cmd) {
    passthru($cmd, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Failed: $cmd\n");
        exit($code);
    }
};

if ($step === '') {
    // ---------------------------------------------------------------- the whole build, each step in a fresh process
    require $root . '/app/bootstrap.php';
    $flag = $root . Master::FLAG_FILE;
    if (is_file($flag)) {
        unlink($flag);
    }
    $db = (string)$GLOBALS['CONFIG']['db']['name'];
    $masterDb = substr($db, 0, 57) . '_master';
    require $root . '/tools/lib.php';
    server_pdo()->exec("DROP DATABASE IF EXISTS `$masterDb`");
    echo "== Installing ($mode)\n";
    $run("$php " . escapeshellarg($root . '/tools/install.php') . ' --drop' . ($mode === 'sample' ? ' --demo' : ''));
    echo "== Finishing\n";
    $run("$php " . escapeshellarg(__FILE__) . " $mode finish");
    echo "== Exporting\n";
    $file = $root . '/database/gsmart_coop_cable_' . $mode . '.sql';
    $title = $mode === 'clean' ? 'G-SMaRT Coop Cable System - complete database, CLEAN DATA (set-up, roles, one user per role, Super Admin)'
        : 'G-SMaRT Coop Cable System - complete database with ALL SAMPLE DATA (accounting, cooperative, cable / internet)';
    $note = "Two databases: `$db` (Company 1) and `$masterDb` (multi-company platform).\\n"
        . "Keep storage/platform/multi_company.json in the program folder (it switches the platform on).\\n"
        . 'Super Admin: ' . SUPERADMIN['username'] . ' / ' . SUPERADMIN['password'] . ($mode === 'clean' ? ' (a new password is asked at the first log-in)' : '') . "\\n"
        . ($mode === 'clean' ? 'Company users: admin / Admin@12345, the others Demo@12345 (each must choose a new password at the first log-in).'
            : 'Company users: admin / Admin@12345, the others Demo@12345. Portal: CUS-000001 / Portal@2027.') . "\\n"
        . 'See docs/USER_ACCOUNTS.md for every account.';
    $run("$php " . escapeshellarg($root . '/tools/export_sql.php') . ' --master --out=' . escapeshellarg($file) . ' --title=' . escapeshellarg($title) . ' --note=' . escapeshellarg($note));
    $run("$php " . escapeshellarg(__FILE__) . " $mode report");
    exit(0);
}

require $root . '/app/bootstrap.php';
require $root . '/tools/lib.php';
require $root . '/tools/isp_demo.php';
$_SESSION = [];
$adminId = (int)DB::value("SELECT id FROM users WHERE username = 'admin'");
Auth::actAs($adminId);

if ($step === 'finish') {
    DB::tx(function () use ($mode) {
        if ($mode === 'clean') {
            clean_out_examples();
        } else {
            foreach (['company_name' => 'Sample Multi-Purpose Cooperative', 'company_address' => 'Paseo del Congreso, City of Malolos, Bulacan',
                      'company_email' => 'office@samplecoop.test', 'company_phone' => '(044) 791-0000'] as $k => $v) {
                Settings::set($k, $v);
            }
        }
        isp_demo_make_users($mode === 'clean');
        if ($mode === 'clean') {
            // every account chooses its own password at the first log-in; no sample e-mail addresses
            DB::run("UPDATE users SET must_change_password = 1, email = NULL WHERE created_via IS NULL OR created_via NOT IN ('system')");
        }
    });
    Settings::flush();
    $company = ['name' => Settings::get('company_name'), 'address' => Settings::get('company_address'), 'contact_person' => '', 'phone' => Settings::get('company_phone'),
        'email' => Settings::get('company_email'), 'tin' => Settings::get('company_tin')];
    $r = Provision::setupPrimary(SUPERADMIN, $company, $adminId, false);
    if ($mode === 'clean') {
        Master::sql()->run('UPDATE super_admins SET must_change_password = 1 WHERE username = :u', ['u' => SUPERADMIN['username']]);
    }
    echo 'Company 1 registered on the platform: code ' . CompanyCode::display($r['code']) . ', ' . $r['users'] . " users listed\n";
    exit(0);
}

if ($step === 'report') {
    // the facts the documentation needs, written next to the SQL file
    $c = Master::companies()[0] ?? null;
    $users = DB::all("SELECT u.username, u.full_name, GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') AS roles, u.must_change_password FROM users u
        LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id WHERE u.created_via IS NULL OR u.created_via <> 'system' GROUP BY u.id ORDER BY u.username");
    $out = ['mode' => $mode, 'company' => Settings::get('company_name'), 'company_code' => $c ? CompanyCode::display((string)$c['code']) : '', 'master_db' => Master::dbName(),
        'users' => $users, 'portal_users' => DB::all('SELECT p.username, c.name FROM isp_portal_users p JOIN isp_customers c ON c.id = p.isp_customer_id ORDER BY p.username'),
        'counts' => ['subscribers' => (int)DB::value('SELECT COUNT(*) FROM isp_accounts'), 'invoices' => (int)DB::value('SELECT COUNT(*) FROM isp_invoices'),
            'payments' => (int)DB::value('SELECT COUNT(*) FROM isp_payments'), 'members' => (int)DB::value('SELECT COUNT(*) FROM members'),
            'transactions' => (int)DB::value('SELECT COUNT(*) FROM transactions')]];
    file_put_contents($root . '/storage/build_' . $mode . '.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo 'Company code ' . $out['company_code'] . ', ' . count($users) . ' users, ' . $out['counts']['subscribers'] . " subscriber accounts\n";
    exit(0);
}

/** The clean database: the example names of the base set-up are removed, the company details left generic */
function clean_out_examples(): void
{
    $exec = fn(string $sql) => DB::run($sql);
    // the example customers, suppliers, items and their parties (no document uses them in a clean database: every
    // row of these tables goes, so the self-references (sub-customers, item components) are switched off meanwhile)
    $exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['item_components', 'price_level_items', 'process_formula_lines', 'process_formulas', 'items', 'customers', 'sales_reps', 'vendors'] as $t) {
        $exec("DELETE FROM `$t`");
    }
    $exec('DELETE FROM party_role_log');
    $exec('DELETE FROM party_crosswalk');
    $exec('DELETE FROM parties');
    $exec('SET FOREIGN_KEY_CHECKS = 1');
    // one office site and the damaged-goods site; one counter
    $wh = (int)DB::value("SELECT id FROM sites WHERE code = 'WH'");
    $exec("DELETE FROM user_terminals WHERE terminal_id IN (SELECT id FROM pos_terminals WHERE code <> 'POS-01')");
    $exec("DELETE FROM pos_terminals WHERE code <> 'POS-01'");
    DB::run("UPDATE pos_terminals SET name = 'Counter 1', site_id = :s, receipt_prefix = 'C1-' WHERE code = 'POS-01'", ['s' => $wh]);
    DB::run("DELETE FROM user_sites WHERE site_id NOT IN (:a, (SELECT id FROM (SELECT id FROM sites WHERE code = 'DMG') x))", ['a' => $wh]);
    DB::run("DELETE FROM role_sites WHERE site_id NOT IN (:a, (SELECT id FROM (SELECT id FROM sites WHERE code = 'DMG') x))", ['a' => $wh]);
    $exec("DELETE FROM sites WHERE code IN ('ST1', 'ST2', 'VAN1')");
    DB::run("UPDATE sites SET name = 'Main Warehouse and Network Stockroom' WHERE id = :s", ['s' => $wh]);
    foreach (DB::column('SELECT id FROM users') as $uid) {
        if (!DB::value('SELECT COUNT(*) FROM user_sites WHERE user_id = :u', ['u' => $uid]) && DB::value('SELECT COUNT(*) FROM user_terminals WHERE user_id = :u', ['u' => $uid])) {
            DB::insert('user_sites', ['user_id' => $uid, 'site_id' => $wh]);
        }
    }
    Settings::set('isp_stock_site_id', (string)$wh);
    foreach (['company_name' => 'Your Cooperative Name', 'company_address' => '', 'company_tin' => '', 'company_phone' => '', 'company_email' => '', 'company_logo' => ''] as $k => $v) {
        Settings::set($k, $v);
    }
    foreach (DB::all('SELECT setting_key FROM settings WHERE setting_value LIKE :x', ['x' => '%sampletrading%']) as $s) {
        Settings::set((string)$s['setting_key'], '');
    }
}
