<?php
declare(strict_types=1);

/**
 * The fixtures of the cable / internet tests, made through the application classes: the cooperative set-up of
 * tools/coop_fixtures.php (two branches, each with its class), two service areas, four plans, a small fiber network
 * (OLT -> splitter -> two NAPs), the integration simulator as the network device, a technician with a van site, two
 * stocked network items, users of the ISP roles, and the administrator's cashier shift.
 */

/** A user with one or more roles (made once) */
function isp_t_user(string $username, string $fullName, array $roles, ?int $branchId = null, ?int $cash = null): int
{
    $id = DB::value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
    if ($id) {
        return (int)$id;
    }
    $id = DB::insert('users', ['username' => $username, 'password_hash' => password_hash('Test@12345', PASSWORD_DEFAULT), 'full_name' => $fullName,
        'status' => 'active', 'must_change_password' => 0, 'password_changed_at' => date('Y-m-d H:i:s'), 'coop_branch_id' => $branchId, 'coop_cash_account_id' => $cash]);
    foreach ($roles as $r) {
        $rid = (int)DB::value('SELECT id FROM roles WHERE name = :n', ['n' => $r]);
        if (!$rid) {
            throw new RuntimeException("Role $r is missing.");
        }
        DB::insert('user_roles', ['user_id' => $id, 'role_id' => $rid]);
    }
    return $id;
}

/** A date in a month before the current one: $monthsAgo months back, day $day (clamped to the month) */
function ymd(int $monthsAgo, int $day): string
{
    $t = new DateTime(date('Y-m-01'));
    $t->modify('-' . $monthsAgo . ' months');
    $last = (int)$t->format('t');
    return $t->format('Y-m-') . sprintf('%02d', min($day, $last));
}

function days_in_month(string $date): int
{
    return (int)date('t', strtotime($date));
}

/** Gross amount split as the invoice does for a VAT-inclusive price at 12%: [net, vat] */
function vat_incl(float $gross): array
{
    $net = round($gross / 1.12, 2);
    return [$net, round($gross - $net, 2)];
}

function isp_t_setup(): array
{
    $ids = coop_fx_setup();
    Settings::set('company_name', 'Bayanihan Multi-Purpose Cooperative (Cable & Internet)');
    Settings::set('company_tin', '123-456-789-000');
    Settings::flush();

    // ---------------------------------------------------------------- areas: a municipality with one barangay per branch
    $mun = DB::insert('isp_areas', ['area_type' => 'municipality', 'code' => 'SI', 'name' => 'San Isidro', 'branch_id' => $ids['branch.HO'], 'is_serviceable' => 1, 'is_active' => 1]);
    $ids['area.SI'] = $mun;
    $ids['area.SR'] = DB::insert('isp_areas', ['parent_id' => $mun, 'area_type' => 'barangay', 'code' => 'SI-SR', 'name' => 'San Roque', 'branch_id' => $ids['branch.HO'], 'is_serviceable' => 1, 'is_active' => 1]);
    $ids['area.PB'] = DB::insert('isp_areas', ['parent_id' => $mun, 'area_type' => 'barangay', 'code' => 'SI-PB', 'name' => 'Poblacion', 'branch_id' => $ids['branch.BR2'], 'is_serviceable' => 1, 'is_active' => 1]);
    $ids['area.FAR'] = DB::insert('isp_areas', ['parent_id' => $mun, 'area_type' => 'barangay', 'code' => 'SI-FAR', 'name' => 'Sitio Malayo', 'branch_id' => $ids['branch.HO'], 'is_serviceable' => 0, 'is_active' => 1]);

    // ---------------------------------------------------------------- plans
    $base = ['category' => 'residential', 'service_type' => 'postpaid', 'technology' => 'fiber', 'vat_mode' => 'inclusive', 'status' => 'active', 'contract_months' => 12,
        'late_fee_type' => 'fixed', 'late_fee_value' => 100, 'reconnection_fee' => 300, 'upload_mbps' => 0];
    $ids['plan.F50'] = IspPlans::save(array_merge($base, ['code' => 'FIB50', 'name' => 'Fiber 50', 'monthly_fee' => 1500, 'member_monthly_fee' => 1350, 'download_mbps' => 50, 'upload_mbps' => 50,
        'installation_fee' => 1000, 'equipment_deposit' => 0, 'lock_in_months' => 0, 'early_termination_fee' => 0, 'network_profile' => 'FIB50']), null);
    $ids['plan.F100'] = IspPlans::save(array_merge($base, ['code' => 'FIB100', 'name' => 'Fiber 100', 'monthly_fee' => 2000, 'member_monthly_fee' => null, 'download_mbps' => 100, 'upload_mbps' => 100,
        'installation_fee' => 1500, 'equipment_deposit' => 1000, 'lock_in_months' => 12, 'early_termination_fee' => 3000, 'network_profile' => 'FIB100']), null);
    $ids['plan.F25'] = IspPlans::save(array_merge($base, ['code' => 'FIB25', 'name' => 'Fiber 25', 'monthly_fee' => 999, 'member_monthly_fee' => null, 'download_mbps' => 25, 'upload_mbps' => 25,
        'installation_fee' => 0, 'equipment_deposit' => 0, 'lock_in_months' => 0, 'early_termination_fee' => 0, 'late_fee_type' => 'percent', 'late_fee_value' => 2]), null);
    $ids['plan.CATV'] = IspPlans::save(array_merge($base, ['code' => 'CATV', 'name' => 'Cable TV Basic', 'technology' => 'cable', 'includes_catv' => 1, 'monthly_fee' => 450, 'member_monthly_fee' => null,
        'download_mbps' => 1, 'installation_fee' => 500, 'equipment_deposit' => 0, 'lock_in_months' => 0, 'early_termination_fee' => 0, 'late_fee_type' => 'none', 'late_fee_value' => 0]), null);
    // Fiber 25 is offered only in Poblacion (and the places inside it)
    DB::insert('isp_plan_areas', ['plan_id' => $ids['plan.F25'], 'area_id' => $ids['area.PB']]);
    IspPlans::flush();

    // ---------------------------------------------------------------- the network: OLT -> splitter -> two NAPs of 16 drop ports
    $olt = IspInfra::saveNode(['node_type' => 'olt', 'code' => 'OLT-01', 'name' => 'San Isidro OLT', 'status' => 'active', 'port_count' => 8, 'area_id' => $ids['area.SI'], 'installed_on' => '2025-01-10'], null);
    $oltPort = (int)DB::value('SELECT id FROM isp_net_ports WHERE node_id = :n AND port_no = 1', ['n' => $olt]);
    $spl = IspInfra::saveNode(['node_type' => 'splitter', 'code' => 'SPL-01', 'name' => 'Splitter San Roque 1:8', 'status' => 'active', 'port_count' => 8, 'split_ratio' => '1:8',
        'upstream_port_id' => $oltPort, 'area_id' => $ids['area.SR'], 'installed_on' => '2025-01-12'], null);
    $splPorts = DB::column('SELECT id FROM isp_net_ports WHERE node_id = :n ORDER BY port_no', ['n' => $spl]);
    $ids['node.OLT'] = $olt;
    $ids['node.SPL'] = $spl;
    $ids['nap.1'] = IspInfra::saveNode(['node_type' => 'nap', 'code' => 'NAP-SR-01', 'name' => 'NAP San Roque 1', 'status' => 'active', 'port_count' => 96, 'upstream_port_id' => (int)$splPorts[0],
        'area_id' => $ids['area.SR'], 'latitude' => '15.0350', 'longitude' => '120.6850', 'installed_on' => '2025-01-15'], null);
    $ids['nap.2'] = IspInfra::saveNode(['node_type' => 'nap', 'code' => 'NAP-PB-01', 'name' => 'NAP Poblacion 1', 'status' => 'active', 'port_count' => 48, 'upstream_port_id' => (int)$splPorts[1],
        'area_id' => $ids['area.PB'], 'installed_on' => '2025-01-15'], null);
    // a second, independent OLT branch for outage scoping: NAP 3 hangs from OLT port 2 directly
    $oltPort2 = (int)DB::value('SELECT id FROM isp_net_ports WHERE node_id = :n AND port_no = 2', ['n' => $olt]);
    $ids['nap.3'] = IspInfra::saveNode(['node_type' => 'nap', 'code' => 'NAP-SR-02', 'name' => 'NAP San Roque 2', 'status' => 'active', 'port_count' => 16, 'upstream_port_id' => $oltPort2,
        'area_id' => $ids['area.SR'], 'installed_on' => '2025-02-01'], null);

    // ---------------------------------------------------------------- the network device: the integration simulator
    $ids['device.sim'] = IspNetwork::saveDevice(['name' => 'Integration simulator', 'adapter' => 'simulator', 'host' => '', 'is_default' => 1, 'is_active' => 1, 'options' => ''], null, 'sim-secret-123');

    // ---------------------------------------------------------------- stock: the network stockroom, a technician van, two items
    $ids['site.WH'] = (int)DB::value("SELECT id FROM sites WHERE code = 'WH'");
    $ids['site.VAN'] = (int)DB::value("SELECT id FROM sites WHERE code = 'VAN1'");
    Settings::set('isp_stock_site_id', (string)$ids['site.WH']);
    $tpl = DB::row("SELECT * FROM items WHERE code = 'LAP-001'");
    foreach ([['ONU-HG8145', 'ONU Huawei HG8145V5', 'ONU / ONT', 'pc'], ['DROP-1C', 'Fiber drop cable 1-core', 'Drop Wire', 'm']] as [$code, $name, $cat, $unit]) {
        $ids['item.' . $code] = DB::insert('items', ['item_type' => 'inventory', 'code' => $code, 'name' => $name, 'unit' => $unit,
            'category_id' => DB::value('SELECT id FROM item_categories WHERE name = :n', ['n' => $cat]), 'income_account_id' => $tpl['income_account_id'],
            'cogs_account_id' => $tpl['cogs_account_id'], 'asset_account_id' => $tpl['asset_account_id'], 'tax_code_id' => $tpl['tax_code_id'], 'is_active' => 1]);
    }
    $vendor = (int)DB::value('SELECT id FROM vendors ORDER BY id LIMIT 1');
    Txn::save('bill', ['txn_date' => ymd(6, 1), 'vendor_id' => $vendor, 'site_id' => $ids['site.WH'], 'ref_no' => 'NETSUP-001', 'terms_id' => null, 'due_date' => ymd(5, 1),
        'account_id' => Accts::get('ap'), 'memo' => 'Network stock'], parse_item_lines([
        ['item_id' => $ids['item.ONU-HG8145'], 'qty' => 20, 'rate' => 1200, 'tax_code_id' => null, 'site_id' => $ids['site.WH']],
        ['item_id' => $ids['item.DROP-1C'], 'qty' => 3000, 'rate' => 8, 'tax_code_id' => null, 'site_id' => $ids['site.WH']],
    ], 'purchase'));

    // ---------------------------------------------------------------- users of the ISP roles
    $ids['user.tech'] = isp_t_user('tech1', 'Rodel Santos', ['Installation Technician']);
    $ids['user.tech2'] = isp_t_user('tech2', 'Jun Mercado', ['Maintenance Technician']);
    $ids['user.billing'] = isp_t_user('billing1', 'Ana Lim', ['Billing Officer']);
    $ids['user.acct'] = isp_t_user('ispacct', 'Ben Cruz', ['Accountant']);
    $ids['user.cashier'] = isp_t_user('ispcash1', 'Carla Diaz', ['Cashier']);
    $ids['user.cashier2'] = isp_t_user('ispcash2', 'Dina Flores', ['Cashier']);
    $ids['user.csr'] = isp_t_user('csr1', 'Ella Gomez', ['Customer Service Representative']);
    $ids['user.netadmin'] = isp_t_user('netadmin', 'Fred Hidalgo', ['Network Administrator']);
    $ids['user.br2coll'] = isp_t_user('br2coll', 'Gina Ilagan', ['Collection Officer'], $ids['branch.BR2']);
    $ids['user.supervisor'] = isp_t_user('techsup', 'Hector Javier', ['Technical Supervisor']);
    $ids['user.gm2'] = isp_t_user('ispgm', 'Irene Kho', ['General Manager']);

    // ---------------------------------------------------------------- technicians (the installer works from the van)
    $ids['tech.1'] = DB::insert('isp_technicians', ['code' => 'T-01', 'name' => 'Rodel Santos', 'user_id' => $ids['user.tech'], 'team' => 'Installation', 'role' => 'installer',
        'site_id' => $ids['site.VAN'], 'mobile' => '09171110001', 'is_active' => 1]);
    $ids['tech.2'] = DB::insert('isp_technicians', ['code' => 'T-02', 'name' => 'Jun Mercado', 'user_id' => $ids['user.tech2'], 'team' => 'Maintenance', 'role' => 'maintenance',
        'site_id' => null, 'mobile' => '09171110002', 'is_active' => 1]);

    // ---------------------------------------------------------------- policies used by the tests
    DB::run("UPDATE isp_disc_rules SET grace_days = 5, min_overdue_amount = 100, min_overdue_invoices = 1, final_notice_hours = 24, require_approval = 1, auto_execute = 0,
              exclude_types = 'deposit', protect_disputes = 1, protect_arrangements = 1, run_days = '1,2,3,4,5,6,7', run_from_hour = 0, run_to_hour = 24 WHERE code = 'NONPAY'");
    Settings::flush();
    IspSetup::reset();

    // ---------------------------------------------------------------- the administrator collects cash in an open shift
    $ids['shift.admin'] = IspCashier::open((int)Auth::id(), Accts::get('isp_cash'), 1000, $ids['branch.HO']);
    Access::reset();
    return $ids;
}

/** The simulator answers normally again (a test may have set failures) */
function isp_t_reset_device(): void
{
    global $ids;
    if (!empty($ids['device.sim'])) {
        DB::run("UPDATE isp_devices SET options = '', is_active = 1 WHERE id = :d", ['d' => $ids['device.sim']]);
    }
}

function isp_t_device_options(string $opts): void
{
    global $ids;
    DB::run('UPDATE isp_devices SET options = :o WHERE id = :d', ['o' => $opts, 'd' => $ids['device.sim']]);
}

/**
 * A subscriber account the way the screens make it: application, identity, survey (with a free NAP port for fiber),
 * contract, approval, installation scheduled and completed, activation on $o['date'] (default today).
 * $o: plan, area, nap, date, cycle_day, bill_timing, billing_basis, due_days, prorate_first, party_id, mobile, last,
 * first, email, pending (stop before activation), with_materials (issue an ONU and 120 m of drop cable).
 */
function isp_t_account(array $o = []): int
{
    global $ids;
    static $n = 0;
    $n++;
    $plan = (int)($o['plan'] ?? $ids['plan.F50']);
    $area = (int)($o['area'] ?? $ids['area.SR']);
    $app = IspApplications::create(['last_name' => $o['last'] ?? ('Subscriber' . $n), 'first_name' => $o['first'] ?? 'Test', 'mobile' => $o['mobile'] ?? sprintf('0917%07d', 2000000 + $n),
        'email' => $o['email'] ?? null, 'install_address' => 'Purok ' . $n . ', ' . ($area === $ids['area.PB'] ? 'Poblacion' : 'San Roque') . ', San Isidro',
        'plan_id' => $plan, 'area_id' => $area, 'privacy_consent' => 1, 'party_id' => $o['party_id'] ?? null, 'agent_id' => $o['agent_id'] ?? null], $o['channel'] ?? 'walkin');
    IspApplications::verifyIdentity($app, 'PhilSys National ID', 'PSN-' . (100000 + $n));
    $port = null;
    if (IspPlans::get($plan)['technology'] === 'fiber') {
        $nap = (int)($o['nap'] ?? ($area === $ids['area.PB'] ? $ids['nap.2'] : $ids['nap.1']));
        $port = (int)DB::value("SELECT id FROM isp_net_ports WHERE node_id = :n AND status = 'free' ORDER BY port_no LIMIT 1", ['n' => $nap]);
        if (!$port) {
            throw new RuntimeException('No free port left on the test NAP.');
        }
    }
    IspApplications::survey($app, ['coverage_ok' => 1, 'survey_feasible' => 1, 'port_id' => $port, 'est_cable_m' => 80, 'survey_notes' => 'Clear line of poles']);
    IspApplications::acceptContract($app, 'Test Subscriber ' . $n);
    $r = IspApplications::approve($app, 'Approved for installation');
    $acct = (int)$r['account_id'];
    $set = array_intersect_key($o, array_flip(['cycle_day', 'bill_timing', 'billing_basis', 'due_days', 'prorate_first', 'router_rental']));
    if ($set) {
        DB::update('isp_accounts', $set, 'id = :id', ['id' => $acct]);
    }
    $date = $o['date'] ?? today();
    IspWorkOrders::schedule((int)$r['work_order_id'], $ids['tech.1'], $date, 'AM');
    if (!empty($o['with_materials'])) {
        IspWorkOrders::issue((int)$r['work_order_id'], [[$ids['item.ONU-HG8145'], 1, 'HWTC' . (900000 + $n)], [$ids['item.DROP-1C'], 120]], $date);
    }
    $mats = [];
    if (!empty($o['with_materials'])) {
        foreach (DB::all('SELECT id, item_id FROM isp_wo_materials WHERE work_order_id = :w', ['w' => $r['work_order_id']]) as $m) {
            $mats[(int)$m['id']] = [(int)$m['item_id'] === $ids['item.DROP-1C'] ? 100 : 1, null];
        }
    }
    if (!empty($o['pending_install'])) {
        return $acct;
    }
    IspWorkOrders::complete((int)$r['work_order_id'], ['checklist' => range(0, 6), 'cable_used_m' => 80, 'rx_power_dbm' => -19.5, 'speed_test_down' => 48, 'speed_test_up' => 47,
        'customer_accepted_by' => 'Test Subscriber ' . $n, 'onu_serial' => 'HWTC' . (900000 + $n), 'materials' => $mats]);
    if (!empty($o['pending'])) {
        return $acct;
    }
    IspAccounts::activate($acct, $date);
    return $acct;
}

/** A payment by bank transfer with a fresh reference (no cashier shift needed) */
function isp_t_pay(int $acct, float $amount, string $channel = 'bank_transfer', array $o = []): int
{
    static $ref = 0;
    $ref++;
    if ($channel !== 'cash' && empty($o['reference_no'])) {
        $o['reference_no'] = 'TST' . date('ymd') . sprintf('%05d', $ref) . '-' . $acct;
    }
    return IspPayments::collect($acct, $amount, $channel, $o);
}

function isp_t_invoices(int $acct, ?string $kind = null): array
{
    return DB::all('SELECT * FROM isp_invoices WHERE account_id = :a' . ($kind ? ' AND kind = :k' : '') . ' ORDER BY id', ['a' => $acct] + ($kind ? ['k' => $kind] : []));
}

function isp_t_invoice(int $id): array
{
    return DB::row('SELECT * FROM isp_invoices WHERE id = :i', ['i' => $id]);
}

function isp_t_acct(int $id): array
{
    return IspAccounts::get($id);
}

/** The general-ledger balance (debit - credit) of an account, optionally for one customer */
function isp_t_gl(int $accountId, ?int $customerId = null): float
{
    return round((float)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE account_id = :a' . ($customerId ? ' AND customer_id = :c' : ''),
        ['a' => $accountId] + ($customerId ? ['c' => $customerId] : [])), 2);
}

/** The subscriber's A/R in the books: the receivable less the subscriber's unapplied credit */
function isp_t_gl_ar_of(int $acctId): float
{
    $a = isp_t_acct($acctId);
    return isp_t_gl(Accts::get('isp_ar'), IspAccounts::arCustomer((int)$a['isp_customer_id']));
}

/** Every posted transaction balances; the subscriber ledger (open invoices less credits) equals the A/R account */
function isp_t_assert_books(string $what): void
{
    $bad = DB::all('SELECT txn_id, SUM(debit) d, SUM(credit) c FROM journal_lines GROUP BY txn_id HAVING ROUND(SUM(debit), 2) <> ROUND(SUM(credit), 2)');
    check(!$bad, "$what: unbalanced transactions " . json_encode($bad));
    $t = DB::row('SELECT COALESCE(SUM(debit), 0) d, COALESCE(SUM(credit), 0) c FROM journal_lines');
    eqm($t['d'], $t['c'], "$what: trial balance debits = credits");
    $r = IspPosting::reconcile();
    eqm($r['difference'], 0, "$what: subscriber ledger (open " . $r['isp_open'] . ' less credit ' . $r['isp_credit'] . ') = A/R - Subscribers (' . $r['gl'] . ')');
    check(!$r['rows'], "$what: invoices whose open balance differs from the books: " . json_encode(array_slice($r['rows'], 0, 5)));
}

/** The isp_postings row of an event, or null */
function isp_t_posting(string $key): ?array
{
    return DB::row('SELECT * FROM isp_postings WHERE event_key = :k', ['k' => $key]);
}

/** The latest disconnection queue item of an account */
function isp_t_queue(int $acct): ?array
{
    return DB::row('SELECT * FROM isp_disc_queue WHERE account_id = :a ORDER BY id DESC LIMIT 1', ['a' => $acct]);
}

/** Makes an account's open invoices fall due $daysAgo days ago (as if time had passed), then marks them overdue */
function isp_t_age(int $acct, int $daysAgo): void
{
    $due = date('Y-m-d', strtotime("-$daysAgo days"));
    DB::run("UPDATE isp_invoices SET due_date = :d, overdue_since = NULL WHERE account_id = :a AND status IN ('posted', 'partially_paid', 'overdue')", ['d' => $due, 'a' => $acct]);
    IspBilling::markOverdue();
}

/** Executes a queue item the way the scheduler does once its time came (no waiting for the final-notice hours) */
function isp_t_disconnect_now(int $acct): string
{
    $q = isp_t_queue($acct);
    if (!$q) {
        throw new RuntimeException('Nothing queued for the account.');
    }
    if ($q['status'] === 'candidate') {
        IspDisconnect::approve([(int)$q['id']]);
    }
    return IspDisconnect::execute((int)$q['id'], true);
}

/** Notifications of an account for an event */
function isp_t_notes(int $acct, string $code): array
{
    return DB::all('SELECT * FROM isp_notifications WHERE account_id = :a AND event_code = :e ORDER BY id', ['a' => $acct, 'e' => $code]);
}

/** Audit rows of a record */
function isp_t_audit(string $recordType, int $id, ?string $action = null): array
{
    return DB::all('SELECT * FROM audit_trail WHERE record_type = :t AND record_id = :i' . ($action ? ' AND action = :ac' : '') . ' ORDER BY id',
        ['t' => $recordType, 'i' => $id] + ($action ? ['ac' => $action] : []));
}

/** Forget the cached rights and the portal subscriber (another user takes over) */
function isp_t_as(int $userId): void
{
    Auth::actAs($userId);
    Access::reset();
}

function isp_t_portal_as(?int $customerId): void
{
    $rp = new ReflectionProperty(IspPortal::class, 'customer');
    $rp->setAccessible(true);
    $rp->setValue(null, null);
    if ($customerId === null) {
        unset($_SESSION['portal']);
        return;
    }
    $puid = (int)DB::value('SELECT id FROM isp_portal_users WHERE isp_customer_id = :c', ['c' => $customerId]);
    $_SESSION['portal'] = ['puid' => $puid, 'cid' => $customerId, 'last' => time()];
}
