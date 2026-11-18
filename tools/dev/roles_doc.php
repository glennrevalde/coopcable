<?php
declare(strict_types=1);

/**
 * Writes docs/ROLES_PERMISSIONS.md from the roles, page access and special permissions of a database
 * (the installed defaults): what every role opens and may do, menu by menu.
 *
 *   DB_NAME=gsmart_coop_cable php tools/dev/roles_doc.php [--out=docs/ROLES_PERMISSIONS.md]
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../../app/bootstrap.php';
$opts = getopt('', ['out::']);
$out = $opts['out'] ?? APP_ROOT . '/docs/ROLES_PERMISSIONS.md';

$roles = DB::all('SELECT * FROM roles ORDER BY is_admin DESC, name');
$modules = DB::pairs('SELECT id, name FROM modules ORDER BY sort_order');
$special = Access::SPECIAL;
$rank = ['Company Administrator', 'General Manager', 'Branch Manager', 'Accountant', 'Bookkeeper', 'Auditor', 'Network Administrator', 'Technical Supervisor',
    'Billing Officer', 'Collection Officer', 'Credit Committee', 'Loan Officer', 'Membership Officer', 'Customer Service Representative', 'Inventory Custodian',
    'POS Supervisor', 'A/R Clerk', 'A/P Clerk', 'Inventory Clerk', 'Teller', 'Cashier', 'Collector', 'Sales Representative', 'Sales Agent',
    'Maintenance Technician', 'Installation Technician'];
usort($roles, fn($a, $b) => (array_search($a['name'], $rank, true) === false ? 99 : array_search($a['name'], $rank, true)) <=> (array_search($b['name'], $rank, true) === false ? 99 : array_search($b['name'], $rank, true)));

$md = "# User roles and permissions\n\n"
    . "Generated from the installed defaults (" . date('Y-m-d') . "). Roles, their page access and their special permissions can be\n"
    . "changed by the Company Administrator: **System Administration > User Roles**, **Page Access Configuration** and\n"
    . "**Effective Access** (what a user can really do, all roles combined). Every denied page is recorded in the audit trail.\n\n"
    . "Rights on a page: **View** (open it), **Create**, **Edit**, **Delete / Void / Reverse**, **Print / Export**, **Approve / Post**.\n"
    . "Special permissions add limits and powers that are not tied to one page (amount limits, network control, sensitive data).\n"
    . "Users may also be limited to a branch (cooperative branch of the user profile) and to inventory sites.\n\n"
    . "Roles from the highest to the lowest level of authority:\n\n";
foreach ($roles as $r) {
    $md .= '* **' . $r['name'] . '**' . ($r['description'] ? ': ' . $r['description'] : '') . "\n";
}
$md .= "\nThe platform **Super Admin** is above every company: it is not a role of a company but an account of the master database\n"
    . "(adds companies, Company Codes, owners, database updates, platform audit log) and can enter any company.\n\n";

$md .= "## Special permissions\n\n| Permission | Meaning | Roles |\n|---|---|---|\n";
$sp = DB::all('SELECT s.*, r.name FROM role_special_permissions s JOIN roles r ON r.id = s.role_id ORDER BY r.name');
foreach ($special as $k => [$label, $type]) {
    $who = [];
    foreach ($sp as $s) {
        if ($s['perm_key'] === $k && (string)$s['perm_value'] !== '' && (string)$s['perm_value'] !== '0') {
            $who[] = $s['name'] . ($type !== 'bool' ? ' (' . $s['perm_value'] . ')' : '');
        }
    }
    $md .= '| `' . $k . '` | ' . str_replace('|', '/', $label) . ' | ' . ($who ? implode(', ', $who) : 'Company Administrator') . " |\n";
}

foreach ($roles as $r) {
    $md .= "\n## " . $r['name'] . "\n\n";
    if ((int)$r['is_admin'] === 1) {
        $md .= "Every page with every right, every special permission (administrator role).\n";
        continue;
    }
    if ($r['description']) {
        $md .= $r['description'] . "\n\n";
    }
    $rows = DB::all('SELECT p.page_key, p.name, p.page_type, p.module_id, a.* FROM role_page_access a JOIN pages p ON p.id = a.page_id
        WHERE a.role_id = :r AND a.allowed = 1 ORDER BY p.module_id, p.page_type = \'report\', p.sort_order, p.name', ['r' => $r['id']]);
    if (!$rows) {
        $md .= "No pages.\n";
        continue;
    }
    $md .= "| Menu | Page | Rights |\n|---|---|---|\n";
    $reports = [];
    foreach ($rows as $p) {
        $rights = ['View'];
        foreach (['can_create' => 'Create', 'can_edit' => 'Edit', 'can_delete' => 'Delete', 'can_print' => 'Print', 'can_approve' => 'Approve'] as $c => $l) {
            if ((int)$p[$c] === 1) {
                $rights[] = $l;
            }
        }
        $mod = $modules[$p['module_id']] ?? '';
        if ($p['page_type'] === 'report') {
            $reports[$mod][] = $p['name'];
            continue;
        }
        $md .= '| ' . $mod . ' | ' . $p['name'] . ' | ' . implode(', ', $rights) . " |\n";
    }
    foreach ($reports as $mod => $names) {
        $md .= '| ' . $mod . ' | Reports (' . count($names) . '): ' . implode(', ', $names) . " | View, Print |\n";
    }
    $mine = array_filter($sp, fn($s) => (int)$s['role_id'] === (int)$r['id'] && (string)$s['perm_value'] !== '' && (string)$s['perm_value'] !== '0');
    if ($mine) {
        $md .= "\nSpecial permissions: " . implode(', ', array_map(fn($s) => '`' . $s['perm_key'] . '`' . (($special[$s['perm_key']][1] ?? 'bool') !== 'bool' ? ' = ' . $s['perm_value'] : ''), $mine)) . "\n";
    }
}
file_put_contents($out, $md);
echo 'Wrote ' . $out . ' (' . count($roles) . " roles)\n";
