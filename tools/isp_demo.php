<?php
declare(strict_types=1);

/**
 * Sample data of the cable / internet operation (php tools/install.php --demo, after the accounting and cooperative
 * sample data), and the user accounts of every role (also used for the clean database, see isp_demo_make_users()).
 *
 * Everything is recorded through the service classes the pages use (applications, work orders, activation, billing
 * runs, collections, cashier shifts, the disconnection and reconnection engines, tickets, outages ...), so the general
 * ledger, the subscriber ledger, the postings bridge and the audit trail agree with each other.
 *
 * Six months of history are simulated day by day: tools work "as of" each day ($GLOBALS['APP_TODAY'] for today(), and
 * the database session clock with SET TIMESTAMP), and the times written by the program during a simulated day are moved
 * to that day afterwards (IspDemoClock), so documents, ledgers, audit trail and time stamps all carry the simulated
 * dates. Values that must be secret (PPPoE passwords, the webhook secret) use the published SAMPLE key of IspCrypto.
 */
if (!defined('APP_ROOT')) {
    require_once __DIR__ . '/../app/bootstrap.php';
    require_once __DIR__ . '/lib.php';
}
require_once APP_ROOT . '/views/layout.php';
require_once APP_ROOT . '/modules/txn/_common.php';

/**
 * The user accounts of every role, from the lowest level of the hierarchy to the highest:
 * [role, username, password, full name, coop branch code or null]. The platform Super Admin (multi-company) is
 * separate (the platform has its own accounts).
 */
function isp_demo_users(): array
{
    $pw = 'Demo@12345';
    return [
        ['Sales Agent', 'agent1', $pw, 'Ramon Dela Cruz', null],
        ['Installation Technician', 'installer1', $pw, 'Jomar Pascual', '01'],
        ['Maintenance Technician', 'maintech1', $pw, 'Rodel Manalo', '01'],
        ['Collector', 'collector1', $pw, 'Alvin Soriano', '01'],
        ['Teller', 'teller1', $pw, 'Teresa Villanueva', '01'],
        ['Cashier', 'cashier1', $pw, 'Ella Ramos', '01'],
        ['Customer Service Representative', 'csr1', $pw, 'Kristine Lopez', null],
        ['Sales Representative', 'salesrep', $pw, 'Maria Santos', null],
        ['Inventory Clerk', 'invclerk', $pw, 'Dan Villanueva', null],
        ['Inventory Custodian', 'custodian1', $pw, 'Noel Bautista', null],
        ['A/R Clerk', 'arclerk', $pw, 'Ben Garcia', null],
        ['A/P Clerk', 'apclerk', $pw, 'Carla Lim', null],
        ['Membership Officer', 'membership', $pw, 'Nina Bautista', '01'],
        ['Collection Officer', 'collections1', $pw, 'Liza Mendoza', null],
        ['Billing Officer', 'billing1', $pw, 'Janet Ocampo', null],
        ['Loan Officer', 'loanofficer', $pw, 'Marvin Uy', '01'],
        ['Credit Committee', 'creditcom', $pw, 'Eduardo Santos', null],
        ['POS Supervisor', 'supervisor', $pw, 'Fred Tan', null],
        ['Bookkeeper', 'bookkeeper', $pw, 'Joy Fernandez', null],
        ['Accountant', 'accountant', $pw, 'Ana Cruz', null],
        ['Network Administrator', 'netadmin1', $pw, 'Arnold Castillo', null],
        ['Technical Supervisor', 'techsup1', $pw, 'Rey Gonzales', null],
        ['Auditor', 'auditor', $pw, 'Grace Uy', null],
        ['Branch Manager', 'branchmgr', $pw, 'Carlo Reyes', '02'],
        ['General Manager', 'manager', $pw, 'Rosario Dizon', null],
        ['Company Administrator', 'admin', 'Admin@12345', 'System Administrator', null],
    ];
}

/**
 * Creates the users of isp_demo_users() that are missing (an existing username is kept, given the role when it lacks
 * it), the technician records of the technician users and the sales agent record of the agent user.
 * $mustChange: the users choose a new password at their first log-in (the clean database). Returns username => id.
 */
function isp_demo_make_users(bool $mustChange): array
{
    $ids = [];
    $branch = function (?string $code): ?int {
        if ($code === null) {
            return null;
        }
        try {
            $id = DB::value('SELECT id FROM coop_branches WHERE code = :c AND is_active = 1', ['c' => $code]);
        } catch (Throwable $e) {
            $id = null;
        }
        return $id ? (int)$id : null;
    };
    $no = 100;
    foreach (isp_demo_users() as [$role, $username, $password, $full, $br]) {
        $no++;
        $rid = (int)(DB::value('SELECT id FROM roles WHERE name = :n', ['n' => $role]) ?: 0);
        if (!$rid) {
            continue;
        }
        $bid = $branch($br);
        $uid = (int)(DB::value('SELECT id FROM users WHERE username = :u', ['u' => $username]) ?: 0);
        if (!$uid) {
            $uid = DB::insert('users', ['username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'full_name' => $full,
                'email' => $username . '@gsmartcable.test', 'employee_no' => 'EMP-' . $no, 'status' => 'active', 'approval_status' => 'approved',
                'must_change_password' => $mustChange ? 1 : 0, 'password_changed_at' => date('Y-m-d H:i:s'), 'coop_branch_id' => $bid]);
            Audit::log('create', 'admin', 'user', $uid, $username, ['reason' => 'User account of the ' . $role . ' role']);
        } elseif ($username !== 'admin') {
            DB::run('UPDATE users SET must_change_password = :m, coop_branch_id = COALESCE(coop_branch_id, :b) WHERE id = :id', ['m' => $mustChange ? 1 : 0, 'b' => $bid, 'id' => $uid]);
        }
        if (!DB::value('SELECT user_id FROM user_roles WHERE user_id = :u AND role_id = :r', ['u' => $uid, 'r' => $rid])) {
            DB::insert('user_roles', ['user_id' => $uid, 'role_id' => $rid]);
        }
        $ids[$username] = $uid;
    }
    // the field people and the sales agent behind the user accounts
    foreach ([['installer1', 'TEC-01', 'Installation Team A', 'installer', 'FTTH installation, drop cabling, ONU set-up'],
              ['maintech1', 'TEC-02', 'Maintenance Team', 'maintenance', 'Troubleshooting, fiber splicing, OTDR, coaxial repair'],
              ['techsup1', 'TEC-03', 'Field Supervisors', 'supervisor', 'Dispatch, quality checks, OLT and PON management']] as [$u, $code, $team, $role, $skills]) {
        if (isset($ids[$u]) && !DB::value('SELECT id FROM isp_technicians WHERE code = :c OR user_id = :u', ['c' => $code, 'u' => $ids[$u]])) {
            $name = (string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $ids[$u]]);
            DB::insert('isp_technicians', ['code' => $code, 'name' => $name, 'user_id' => $ids[$u], 'team' => $team, 'role' => $role, 'skills' => $skills,
                'mobile' => '0917' . substr((string)(5550000 + $ids[$u] * 131), -7), 'work_days' => '1,2,3,4,5,6', 'is_active' => 1]);
        }
    }
    if (isset($ids['agent1']) && !DB::value('SELECT id FROM isp_agents WHERE code = :c OR user_id = :u', ['c' => 'AG-001', 'u' => $ids['agent1']])) {
        DB::insert('isp_agents', ['code' => 'AG-001', 'name' => (string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $ids['agent1']]), 'user_id' => $ids['agent1'],
            'mobile' => '09185550142', 'email' => 'agent1@gsmartcable.test', 'activation_type' => 'fixed', 'activation_value' => 500, 'first_payment_type' => 'percent',
            'first_payment_value' => 10, 'clawback_days' => 90, 'is_active' => 1]);
    }
    return $ids;
}

/**
 * The simulated clock of the sample data. at() makes the program work as of a day and time: today() answers the day,
 * the database clock (SET TIMESTAMP) stamps the rows, and every date-time the program wrote with the real clock since
 * the previous at() is moved by the same distance (so "now + 24 hours" stays 24 hours after the simulated moment).
 * Every simulated moment is before the real moment the run started, which keeps the moved values apart.
 */
final class IspDemoClock
{
    private static array $cols = [];
    private static ?string $mark = null;
    private static int $sim = 0;
    private static int $start = 0;
    private static int $late = 0;

    public static function init(): void
    {
        self::$start = time();
        self::$cols = [];
        foreach (DB::all("SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name LIKE 'isp\\_%'
                           AND data_type IN ('datetime', 'timestamp') ORDER BY table_name, ordinal_position") as $r) {
            self::$cols[$r['t']][] = $r['c'];
        }
    }

    public static function at(string $date, string $time = '09:00'): void
    {
        self::flush();
        $sim = strtotime($date . ' ' . $time . ':00');
        if ($sim >= self::$start - 120) {
            // the last simulated day: kept before the moment the run started
            $sim = min($sim, self::$start - 1800 + (self::$late++) * 30);
        }
        self::$sim = $sim;
        $GLOBALS['APP_TODAY'] = $date;
        DB::run('SET TIMESTAMP = ' . $sim);
        // a value written from now on is at or after this moment (one second of margin for the clock's rounding)
        self::$mark = date('Y-m-d H:i:s', max(self::$start, time()) - 1);
    }

    public static function now(): int
    {
        return self::$sim;
    }

    /**
     * Moves the date-times written since the last at() to the simulated moment. While the history is recorded the moved
     * values are parked PARK years back (so a moved value that lands after the real moment is never moved twice);
     * end() brings them forward again.
     */
    public static function flush(): void
    {
        if (self::$mark === null) {
            return;
        }
        $delta = self::$sim - strtotime(self::$mark);
        $m = "'" . self::$mark . "'";
        foreach (self::$cols as $t => $cols) {
            $set = implode(', ', array_map(fn($c) => "`$c` = IF(`$c` >= $m, `$c` + INTERVAL $delta SECOND - INTERVAL " . self::PARK . " YEAR, `$c`)", $cols));
            $where = implode(' OR ', array_map(fn($c) => "`$c` >= $m", $cols));
            DB::run("UPDATE `$t` SET $set WHERE $where");
        }
        self::$mark = null;
    }

    public const PARK = 20;

    /** The simulated moment as the parked values carry it (for comparisons while the history is recorded) */
    public static function parked(): string
    {
        return date('Y-m-d H:i:s', strtotime('-' . self::PARK . ' years', self::$sim));
    }

    /** Runs $fn writing exact date-times of its own (they are not moved) */
    public static function raw(callable $fn): void
    {
        self::flush();
        $fn();
        self::$mark = date('Y-m-d H:i:s', time() + 1);
    }

    public static function end(): void
    {
        self::flush();
        $lo = "'" . (2000 - self::PARK + 10) . "-01-01'";
        $hi = "'" . (date('Y') - self::PARK + 5) . "-12-31'";
        foreach (self::$cols as $t => $cols) {
            $set = implode(', ', array_map(fn($c) => "`$c` = IF(`$c` >= $lo AND `$c` <= $hi, `$c` + INTERVAL " . self::PARK . " YEAR, `$c`)", $cols));
            $where = implode(' OR ', array_map(fn($c) => "(`$c` >= $lo AND `$c` <= $hi)", $cols));
            DB::run("UPDATE `$t` SET $set WHERE $where");
        }
        DB::run('SET TIMESTAMP = DEFAULT');
        unset($GLOBALS['APP_TODAY']);
    }
}

function load_isp_demo(): array
{
    if (DB::value('SELECT id FROM isp_accounts LIMIT 1')) {
        return ['cable / internet sample data already present; skipped'];
    }
    $t0 = microtime(true);
    mt_srand(2027);
    $lines = [];
    $users = isp_demo_make_users(false);
    $U = fn(string $u) => (int)($users[$u] ?? DB::value('SELECT id FROM users WHERE username = :u', ['u' => $u]));
    $as = function (string $u) use ($U): void {
        Auth::actAs($U($u));
    };
    $as('admin');

    $T = date('Y-m-d');
    $S = date('Y-m-01', strtotime('-6 months', strtotime(date('Y-m-01'))));
    $add = fn(string $d, int $n) => date('Y-m-d', strtotime($d . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));
    $ago = fn(int $n) => date('Y-m-d', strtotime($T . ' -' . $n . ' days'));

    // the books of the sample company are closed some weeks back; the subscriber history goes further, so the closing
    // date is lifted while it is recorded and put back afterwards
    $closing = Settings::get('closing_date');
    Settings::set('closing_date', '');
    IspCrypto::$sample = true;
    IspDemoClock::init();
    try {
        IspDemoClock::at($add($S, -20), '09:00');
        $ctx = isp_demo_setup($U, $as, $add($S, -20));
        $lines[] = count($ctx['areas']) . ' service areas, ' . count($ctx['plans']) . ' plans, ' . DB::value('SELECT COUNT(*) FROM isp_net_nodes') . ' network nodes with '
            . DB::value('SELECT COUNT(*) FROM isp_net_ports') . ' ports, ' . DB::value('SELECT COUNT(*) FROM isp_cable_segments') . ' cable segments';
        $lines = array_merge($lines, isp_demo_history($ctx, $U, $as, $S, $T, $add, $ago));
    } finally {
        IspDemoClock::end();
        IspCrypto::$sample = false;
        Settings::set('closing_date', $closing);
        Settings::flush();
        $as('admin');
    }
    // the totals
    $lines[] = DB::value('SELECT COUNT(*) FROM isp_accounts') . ' subscriber accounts (' . implode(', ', array_map(fn($r) => $r['n'] . ' ' . $r['service_status'],
        DB::all('SELECT service_status, COUNT(*) AS n FROM isp_accounts GROUP BY service_status ORDER BY n DESC'))) . '), '
        . DB::value("SELECT COUNT(*) FROM isp_invoices WHERE status <> 'draft'") . ' invoices, ' . DB::value('SELECT COUNT(*) FROM isp_payments') . ' payments';
    $rec = IspPosting::reconcile();
    $lines[] = 'Subscriber ledger vs general ledger (A/R - Subscribers): ' . (abs((float)$rec['difference']) < 0.005 && !$rec['rows'] ? 'in agreement, ' . money($rec['gl'])
        : 'DIFFERENCE ' . money($rec['difference']) . ', ' . count($rec['rows']) . ' invoice(s) to check');
    $lines[] = sprintf('(%.1f s)', microtime(true) - $t0);
    return $lines;
}

/** The set-up: areas, plans, the network, the devices, the stockroom and its stock, field teams and settings */
function isp_demo_setup(callable $U, callable $as, string $day): array
{
    $ctx = ['areas' => [], 'plans' => [], 'naps' => [], 'taps' => [], 'items' => [], 'tech' => [], 'branch' => []];
    foreach (['01' => 'HO', '02' => 'BR2'] as $code => $k) {
        $ctx['branch'][$k] = (int)(DB::value('SELECT id FROM coop_branches WHERE code = :c', ['c' => $code]) ?: 0) ?: null;
    }
    // ---------------------------------------------------------------- settings of the operation
    foreach (['isp_bir_atp' => 'OCN 2AU0001234567 (sample)', 'isp_bir_series' => 'SI-0000001 to SI-0999999', 'isp_account_prefix' => '',
              'isp_payment_instructions' => "Pay at our offices in Malolos (Rizal St.) and Calumpit (Poblacion), by GCash / Maya to 0917-555-0100, or by bank transfer to BDO 0012-3456-7890.\nUse your account number as the reference."] as $k => $v) {
        Settings::set($k, $v);
    }
    // ---------------------------------------------------------------- service areas (province > municipality > barangay > zone)
    $area = function (string $code, string $name, string $type, ?string $parent, ?string $br, float $lat, float $lng, int $serviceable = 1, string $notes = '') use (&$ctx): int {
        $id = DB::insert('isp_areas', ['parent_id' => $parent ? $ctx['areas'][$parent] : null, 'area_type' => $type, 'code' => $code, 'name' => $name,
            'branch_id' => $br ? $ctx['branch'][$br] : null, 'latitude' => $lat, 'longitude' => $lng, 'is_serviceable' => $serviceable, 'notes' => $notes ?: null, 'is_active' => 1]);
        $ctx['areas'][$code] = $id;
        $ctx['area_geo'][$code] = [$lat, $lng, $name];
        return $id;
    };
    $area('BUL', 'Bulacan', 'province', null, null, 14.8527, 120.8160);
    $area('MAL', 'City of Malolos', 'municipality', 'BUL', 'HO', 14.8433, 120.8114);
    $area('CAL', 'Calumpit', 'municipality', 'BUL', 'BR2', 14.9165, 120.7660);
    $area('HAG', 'Hagonoy', 'municipality', 'BUL', 'BR2', 14.8340, 120.7330);
    foreach ([['MAL-ATL', 'Atlag', 14.8360, 120.8200], ['MAL-BUL', 'Bulihan', 14.8550, 120.8170], ['MAL-GUI', 'Guinhawa', 14.8440, 120.8090], ['MAL-LON', 'Longos', 14.8370, 120.8030],
              ['MAL-MOJ', 'Mojon', 14.8520, 120.8230], ['MAL-STR', 'Santo Rosario', 14.8460, 120.8150], ['MAL-TIK', 'Tikay', 14.8600, 120.8330]] as [$c, $n, $la, $lo]) {
        $area($c, $n, 'barangay', 'MAL', 'HO', $la, $lo);
    }
    foreach ([['CAL-POB', 'Poblacion', 14.9165, 120.7660], ['CAL-CAN', 'Caniogan', 14.9060, 120.7700], ['CAL-GAT', 'Gatbuca', 14.8960, 120.7590], ['CAL-SAP', 'Sapang Bayan', 14.9230, 120.7590]] as [$c, $n, $la, $lo]) {
        $area($c, $n, 'barangay', 'CAL', 'BR2', $la, $lo);
    }
    foreach ([['HAG-SSB', 'San Sebastian', 14.8340, 120.7330], ['HAG-SNI', 'Santo Niño', 14.8300, 120.7290], ['HAG-SGF', 'Sagrada Familia', 14.8390, 120.7400]] as [$c, $n, $la, $lo]) {
        $area($c, $n, 'barangay', 'HAG', 'BR2', $la, $lo);
    }
    $area('HAG-CAR', 'Carillo', 'barangay', 'HAG', 'BR2', 14.7900, 120.7600, 0, 'No fiber or coaxial plant yet (planned 2027)');
    $area('MAL-ATL-P1', 'Atlag Purok 1', 'zone', 'MAL-ATL', 'HO', 14.8352, 120.8193);
    $area('MAL-ATL-P2', 'Atlag Purok 2 (Riverside)', 'zone', 'MAL-ATL', 'HO', 14.8368, 120.8212);

    // ---------------------------------------------------------------- plans
    $plan = function (array $p, array $areas = []) use (&$ctx, $day): int {
        $p += ['description' => null, 'category' => 'residential', 'service_type' => 'postpaid', 'technology' => 'fiber', 'includes_catv' => 0, 'member_monthly_fee' => null,
            'upload_mbps' => $p['download_mbps'], 'data_cap_gb' => null, 'fair_use_policy' => null, 'installation_fee' => 1500, 'router_rental_fee' => 0, 'static_ip_fee' => 0,
            'equipment_deposit' => 0, 'contract_months' => 12, 'lock_in_months' => 12, 'early_termination_fee' => 0, 'vat_mode' => 'inclusive', 'late_fee_type' => 'fixed',
            'late_fee_value' => 100, 'reconnection_fee' => 300, 'promo_discount_pct' => 0, 'promo_months' => 0, 'available_from' => '2025-01-01', 'available_to' => null,
            'network_profile' => null, 'income_account_id' => null, 'status' => 'active', 'sort_order' => count($ctx['plans']) + 1];
        $p['area_ids'] = array_map(fn($c) => $ctx['areas'][$c], $areas);
        $id = IspPlans::save($p, null);
        $ctx['plans'][$p['code']] = $id;
        return $id;
    };
    $plan(['code' => 'FBR30', 'name' => 'Fiber 30 Mbps', 'description' => 'Unlimited fiber internet for light households: browsing, social media and HD streaming',
        'monthly_fee' => 999, 'member_monthly_fee' => 949, 'download_mbps' => 30, 'early_termination_fee' => 3000, 'network_profile' => 'FTTH-30M']);
    $plan(['code' => 'FBR50', 'name' => 'Fiber 50 Mbps', 'description' => 'Unlimited fiber internet for families: work from home, online classes and streaming',
        'monthly_fee' => 1299, 'member_monthly_fee' => 1249, 'download_mbps' => 50, 'early_termination_fee' => 3500, 'network_profile' => 'FTTH-50M']);
    $plan(['code' => 'FBR100', 'name' => 'Fiber 100 Mbps', 'description' => 'Unlimited fiber for heavy users and several 4K streams', 'monthly_fee' => 1699, 'member_monthly_fee' => 1599,
        'download_mbps' => 100, 'installation_fee' => 1000, 'contract_months' => 24, 'lock_in_months' => 24, 'early_termination_fee' => 5000, 'network_profile' => 'FTTH-100M']);
    $plan(['code' => 'FBR200', 'name' => 'Fiber 200 Mbps', 'description' => 'Unlimited fiber with a Wi-Fi 6 router', 'monthly_fee' => 2299, 'member_monthly_fee' => 2199,
        'download_mbps' => 200, 'installation_fee' => 0, 'contract_months' => 24, 'lock_in_months' => 24, 'early_termination_fee' => 6000, 'network_profile' => 'FTTH-200M']);
    $plan(['code' => 'FBR500', 'name' => 'Fiber 500 Mbps Premium', 'description' => 'Premium fiber with a mesh Wi-Fi 6 router (rented) and priority support', 'monthly_fee' => 3499,
        'member_monthly_fee' => 3349, 'download_mbps' => 500, 'installation_fee' => 0, 'router_rental_fee' => 150, 'equipment_deposit' => 1500, 'contract_months' => 24, 'lock_in_months' => 24,
        'early_termination_fee' => 8000, 'network_profile' => 'FTTH-500M']);
    $plan(['code' => 'BIZ300', 'name' => 'Business Fiber 300 Mbps + Static IP', 'description' => 'Business-grade fiber with one static public IP, 4-hour support response, VAT added to the price',
        'category' => 'commercial', 'monthly_fee' => 4999, 'download_mbps' => 300, 'installation_fee' => 2500, 'static_ip_fee' => 500, 'equipment_deposit' => 2000, 'contract_months' => 24,
        'lock_in_months' => 24, 'early_termination_fee' => 15000, 'vat_mode' => 'exclusive', 'late_fee_type' => 'percent', 'late_fee_value' => 2, 'reconnection_fee' => 500,
        'network_profile' => 'BIZ-300M'], ['MAL']);
    $plan(['code' => 'CATV50', 'name' => 'Cable TV + Internet 50 Mbps Bundle', 'description' => '80 cable TV channels (12 in HD) with 50 Mbps internet over the coaxial plant',
        'category' => 'bundle', 'technology' => 'cable', 'includes_catv' => 1, 'monthly_fee' => 1499, 'member_monthly_fee' => 1449, 'download_mbps' => 50, 'upload_mbps' => 10,
        'installation_fee' => 1000, 'early_termination_fee' => 2500, 'network_profile' => 'DOCSIS-50M'], ['HAG']);
    $plan(['code' => 'LGCY10', 'name' => 'Fixed Wireless 10 Mbps (legacy)', 'description' => 'The fixed wireless plan of the old tower, kept for its subscribers until they move to fiber',
        'technology' => 'wireless', 'monthly_fee' => 799, 'member_monthly_fee' => 749, 'download_mbps' => 10, 'upload_mbps' => 5, 'data_cap_gb' => 300, 'fair_use_policy' => '300 GB a month, then 3 Mbps',
        'installation_fee' => 500, 'contract_months' => 6, 'lock_in_months' => 6, 'early_termination_fee' => 1000, 'network_profile' => 'WL-10M'], ['CAL']);
    $plan(['code' => 'PROMO100', 'name' => 'Fiber 100 Mbps Holiday Promo', 'description' => '30% off the first 3 months, free installation', 'monthly_fee' => 1699, 'download_mbps' => 100,
        'installation_fee' => 0, 'contract_months' => 24, 'lock_in_months' => 24, 'early_termination_fee' => 5000, 'promo_discount_pct' => 30, 'promo_months' => 3,
        'available_from' => date('Y-m-d', strtotime('-75 days')), 'available_to' => date('Y-12-31'), 'network_profile' => 'FTTH-100M']);

    // ---------------------------------------------------------------- network devices
    $ctx['device'] = IspNetwork::saveDevice(['name' => 'Integration Simulator (no real device)', 'adapter' => 'simulator', 'host' => '', 'port' => null, 'use_tls' => 0, 'username' => null,
        'options' => '', 'branch_id' => null, 'is_default' => 1, 'is_active' => 1], null, null);
    IspNetwork::saveDevice(['name' => 'MikroTik CCR2004 - Malolos POP (not yet connected)', 'adapter' => 'mikrotik', 'host' => '10.10.0.1', 'port' => 8729, 'use_tls' => 1, 'username' => 'gsmart-api',
        'options' => "ppp_profile_prefix = FTTH-\naddress_list = BLOCKED-NONPAY", 'branch_id' => $ctx['branch']['HO'], 'is_default' => 0, 'is_active' => 0], null, null);

    // ---------------------------------------------------------------- the physical network
    $node = function (array $f) use (&$ctx): int {
        $f += ['parent_id' => null, 'upstream_port_id' => null, 'area_id' => null, 'branch_id' => null, 'latitude' => '', 'longitude' => '', 'address' => null, 'vendor' => null, 'model' => null,
            'serial_no' => null, 'split_ratio' => null, 'port_count' => 0, 'specs' => null, 'device_id' => null, 'installed_on' => '2025-02-15', 'status' => 'active', 'notes' => null];
        return IspInfra::saveNode($f, null);
    };
    $port = fn(int $nodeId, int $no) => (int)DB::value('SELECT id FROM isp_net_ports WHERE node_id = :n AND port_no = :p', ['n' => $nodeId, 'p' => $no]);
    $seg = function (string $code, string $type, int $cores, ?int $from, ?int $to, array $path, string $status = 'active', string $notes = ''): int {
        return IspInfra::saveSegment(['code' => $code, 'cable_type' => $type, 'core_count' => $cores, 'from_node_id' => $from, 'to_node_id' => $to, 'length_m' => '',
            'path' => implode(';', array_map(fn($p) => sprintf('%.6f,%.6f', $p[0], $p[1]), $path)), 'installed_on' => '2025-03-01', 'status' => $status, 'notes' => $notes ?: null], null);
    };
    $pops = ['MAL' => [14.8433, 120.8114, 'HO', ['MAL-ATL', 'MAL-BUL', 'MAL-GUI', 'MAL-LON', 'MAL-MOJ', 'MAL-STR', 'MAL-TIK']],
             'CAL' => [14.9165, 120.7660, 'BR2', ['CAL-POB', 'CAL-CAN', 'CAL-GAT', 'CAL-SAP']]];
    foreach ($pops as $m => [$la, $lo, $br, $brgys]) {
        $olt = $node(['node_type' => 'olt', 'code' => 'OLT-' . $m . '-01', 'name' => 'OLT ' . $ctx['area_geo'][$m][2] . ' POP', 'area_id' => $ctx['areas'][$m], 'branch_id' => $ctx['branch'][$br],
            'latitude' => $la, 'longitude' => $lo, 'address' => $m === 'MAL' ? 'NOC, Rizal St., Malolos' : 'Calumpit branch office, Poblacion', 'vendor' => 'Huawei', 'model' => 'MA5608T',
            'serial_no' => 'HW' . $m . '5608T01', 'specs' => 'GPON, 2 x 10G uplink', 'device_id' => $ctx['device']]);
        $card = $node(['node_type' => 'olt_card', 'code' => 'OLT-' . $m . '-01-C1', 'name' => 'GPON board 0/1', 'parent_id' => $olt, 'area_id' => $ctx['areas'][$m], 'branch_id' => $ctx['branch'][$br],
            'latitude' => $la, 'longitude' => $lo, 'vendor' => 'Huawei', 'model' => 'GPFD 16-port', 'port_count' => 8]);
        $i = 0;
        foreach ($brgys as $bc) {
            $i++;
            [$bla, $blo, $bname] = $ctx['area_geo'][$bc];
            $short = substr($bc, 4);
            $spl = $node(['node_type' => 'splitter', 'code' => 'SPL-' . $short . '-01', 'name' => 'Splitter ' . $bname, 'upstream_port_id' => $port($card, $i), 'area_id' => $ctx['areas'][$bc],
                'branch_id' => $ctx['branch'][$br], 'latitude' => $bla + 0.0006, 'longitude' => $blo - 0.0005, 'address' => 'Cabinet near the barangay hall, ' . $bname, 'vendor' => 'Fiberhome',
                'model' => 'PLC 1x8 SC/APC', 'split_ratio' => '1:8', 'port_count' => 8]);
            $seg('FB-' . $m . '-' . $short, 'fiber', 24, $olt, $spl, [[$la, $lo], [($la + $bla) / 2 + 0.0008, ($lo + $blo) / 2 - 0.0006], [$bla + 0.0006, $blo - 0.0005]],
                $bc === 'MAL-LON' ? 'damaged' : 'active', $bc === 'MAL-LON' ? 'Sheath damaged by a truck at Km 42 (cores intact, splice closure scheduled)' : '');
            foreach ([1, 2] as $k) {
                $nla = $bla + ($k === 1 ? 0.0012 : -0.0010);
                $nlo = $blo + ($k === 1 ? 0.0011 : -0.0012);
                $nap = $node(['node_type' => 'nap', 'code' => 'NAP-' . $short . '-0' . $k, 'name' => 'NAP ' . $bname . ' ' . $k, 'upstream_port_id' => $port($spl, $k), 'area_id' => $ctx['areas'][$bc],
                    'branch_id' => $ctx['branch'][$br], 'latitude' => $nla, 'longitude' => $nlo, 'address' => 'Pole ' . $short . '-' . (10 + $k * 7), 'vendor' => 'Fiberhome', 'model' => 'FAT 8-port',
                    'port_count' => 8, 'status' => $bc === 'MAL-TIK' && $k === 2 ? 'maintenance' : 'active', 'notes' => $bc === 'MAL-TIK' && $k === 2 ? 'Enclosure replaced after water entry; back in service soon' : null]);
                $ctx['naps'][$bc][] = $nap;
                $seg('DS-' . $short . '-0' . $k, 'fiber', 12, $spl, $nap, [[$bla + 0.0006, $blo - 0.0005], [$nla, $nlo]]);
            }
        }
    }
    // Hagonoy: the coaxial plant of the cable TV bundle
    $amp = $node(['node_type' => 'coax_amp', 'code' => 'AMP-HAG-01', 'name' => 'Line amplifier Hagonoy', 'area_id' => $ctx['areas']['HAG'], 'branch_id' => $ctx['branch']['BR2'],
        'latitude' => 14.8345, 'longitude' => 120.7335, 'vendor' => 'Blonder Tongue', 'model' => 'BT-860', 'port_count' => 4]);
    $j = 0;
    foreach (['HAG-SSB', 'HAG-SNI', 'HAG-SGF'] as $bc) {
        $j++;
        [$bla, $blo, $bname] = $ctx['area_geo'][$bc];
        $tap = $node(['node_type' => 'tap', 'code' => 'TAP-' . substr($bc, 4) . '-01', 'name' => 'Tap ' . $bname, 'upstream_port_id' => $port($amp, $j), 'area_id' => $ctx['areas'][$bc],
            'branch_id' => $ctx['branch']['BR2'], 'latitude' => $bla + 0.0007, 'longitude' => $blo + 0.0004, 'vendor' => 'Pico Macom', 'model' => '8-way tap', 'port_count' => 8]);
        $ctx['taps'][$bc] = $tap;
        $seg('CX-' . substr($bc, 4), 'coax', 0, $amp, $tap, [[14.8345, 120.7335], [$bla + 0.0007, $blo + 0.0004]]);
    }
    $seg('FB-MAL-CAL-BB', 'fiber', 48, null, null, [[14.8433, 120.8114], [14.8700, 120.7950], [14.8950, 120.7800], [14.9165, 120.7660]], 'active', 'Backbone Malolos - Calumpit along MacArthur Highway');

    // ---------------------------------------------------------------- the stockroom, the vans and the stock
    $site = fn(string $code, string $name, string $type, string $addr) => (int)(DB::value('SELECT id FROM sites WHERE code = :c', ['c' => $code])
        ?: DB::insert('sites', ['code' => $code, 'name' => $name, 'site_type' => $type, 'address' => $addr, 'is_active' => 1]));
    $ctx['noc'] = $site('NOC', 'Network Stockroom (NOC Malolos)', 'stockroom', 'Rizal St., Malolos');
    $vanA = $site('VAN-INS', 'Installer van - Team A', 'van', 'Malolos');
    $vanM = $site('VAN-MNT', 'Maintenance van', 'van', 'Malolos');
    Settings::set('isp_stock_site_id', (string)$ctx['noc']);
    DB::run('UPDATE isp_technicians SET site_id = :s WHERE code = :c', ['s' => $vanA, 'c' => 'TEC-01']);
    DB::run('UPDATE isp_technicians SET site_id = :s WHERE code = :c', ['s' => $vanM, 'c' => 'TEC-02']);
    foreach ([['TEC-04', 'Jun Abad', 'Installation Team B', 'installer', 'FTTH installation, coaxial / cable TV'], ['TEC-05', 'Bong Salazar', 'Maintenance Team', 'splicer', 'Fusion splicing, OTDR testing']] as [$code, $name, $team, $role, $skills]) {
        DB::insert('isp_technicians', ['code' => $code, 'name' => $name, 'team' => $team, 'role' => $role, 'skills' => $skills, 'mobile' => '0928555' . substr($code, -2) . '17', 'work_days' => '1,2,3,4,5,6', 'is_active' => 1]);
    }
    foreach (DB::all('SELECT id, code FROM isp_technicians') as $t) {
        $ctx['tech'][$t['code']] = (int)$t['id'];
    }
    $ids = implode(',', array_map(fn($c) => $ctx['areas'][$c], ['MAL-ATL', 'MAL-BUL', 'MAL-GUI', 'MAL-LON', 'MAL-MOJ', 'MAL-STR', 'MAL-TIK']));
    DB::run('UPDATE isp_technicians SET area_ids = :a WHERE code IN (\'TEC-01\', \'TEC-02\')', ['a' => $ids]);
    $acct = fn(string $code) => (int)DB::value('SELECT id FROM accounts WHERE code = :c', ['c' => $code]);
    $vat = (int)DB::value("SELECT id FROM tax_codes WHERE code = 'VAT12'");
    $cat = fn(string $n) => (int)DB::value('SELECT id FROM item_categories WHERE name = :n', ['n' => $n]);
    foreach ([['ONU-HG8145', 'Huawei HG8145V5 GPON ONU (dual-band Wi-Fi)', 'ONU / ONT', 'pc', 1450, 80],
              ['ONU-F670L', 'ZTE F670L GPON ONU', 'ONU / ONT', 'pc', 1300, 20],
              ['RTR-AX1500', 'TP-Link Archer AX1500 Wi-Fi 6 router', 'Routers', 'pc', 2100, 30],
              ['STB-HD', 'Cable TV set-top box (HD)', 'Set-top Boxes', 'pc', 1200, 20],
              ['CPE-WL5', 'Fixed wireless CPE 5 GHz', 'Routers', 'pc', 1800, 10],
              ['DRP-FIG8', 'Fiber drop cable figure-8, 1 core (per meter)', 'Drop Wire', 'm', 6.5, 8000],
              ['COAX-RG6', 'RG6 coaxial cable (per meter)', 'Coaxial Cable', 'm', 14, 1500],
              ['CON-SCAPC', 'SC/APC fast connector', 'Connectors & Adapters', 'pc', 35, 500],
              ['CLMP-FTTH', 'FTTH drop clamp (S-hook)', 'Installation Consumables', 'pc', 12, 800],
              ['PTC-SCAPC', 'Patch cord SC/APC 3 m', 'Patch Cords', 'pc', 85, 200],
              ['SPL-PLC18', 'PLC splitter 1x8 SC/APC', 'Splitters', 'pc', 650, 10]] as [$code, $name, $c, $unit, $cost, $qty]) {
        $ctx['items'][$code] = DB::insert('items', ['item_type' => 'inventory', 'code' => $code, 'name' => $name, 'purchase_description' => $name, 'category_id' => $cat($c) ?: null, 'unit' => $unit,
            'cost' => $cost, 'avg_cost' => 0, 'sales_price' => 0, 'income_account_id' => $acct('4000'), 'cogs_account_id' => $acct('5000'), 'asset_account_id' => $acct('1200'), 'tax_code_id' => $vat,
            'reorder_point' => $unit === 'm' ? 1000 : 10, 'is_active' => 1]);
        $ctx['stock'][$code] = $qty;
    }
    // the supplier and the purchase of the network stock (received in the stockroom, on account)
    $vp = Party::create(['party_type' => 'organization', 'legal_name' => 'Fiberhome Solutions Philippines Inc.', 'display_name' => 'Fiberhome Solutions Philippines Inc.',
        'mobile' => '09175550190', 'email' => 'sales@fiberhome-ph.test', 'address' => 'Ortigas Center, Pasig City', 'tin' => '009-882-114-000'], null, null, 'isp.dashboard');
    $ctx['vendor'] = Party::grant($vp, 'supplier', 'Network equipment and materials supplier', [], 'isp.dashboard');
    $rows = [];
    foreach ($ctx['items'] as $code => $iid) {
        $rows[] = ['item_id' => $iid, 'qty' => $ctx['stock'][$code], 'rate' => (float)DB::value('SELECT cost FROM items WHERE id = :i', ['i' => $iid]), 'tax_code_id' => $vat, 'site_id' => $ctx['noc']];
    }
    $terms = (int)(DB::value("SELECT id FROM terms WHERE name = 'Net 30'") ?: DB::value('SELECT id FROM terms ORDER BY id LIMIT 1'));
    Txn::save('bill', ['txn_date' => $day, 'vendor_id' => $ctx['vendor'], 'site_id' => $ctx['noc'], 'ref_no' => 'FH-SI-24518', 'terms_id' => $terms, 'due_date' => date('Y-m-d', strtotime($day . ' +30 days')),
        'account_id' => Accts::get('ap'), 'memo' => 'ONUs, routers and FTTH materials for the network stockroom', 'business_unit_id' => IspSetup::unitId()], parse_item_lines($rows, 'purchase'));
    // spare units with their serial numbers on file
    foreach (range(1, 6) as $k) {
        IspEquipment::register($ctx['items']['ONU-HG8145'], sprintf('HWTC9A%06X', 0x31A000 + $k * 37), ['site_id' => $ctx['noc'], 'unit_cost' => 1450, 'notes' => 'Spare unit']);
    }
    // the sales agent's commission rules and supplier record are made on the first payment run
    $ctx['agent'] = (int)(DB::value("SELECT id FROM isp_agents WHERE code = 'AG-001'") ?: 0) ?: null;
    $ctx['agent2'] = DB::insert('isp_agents', ['code' => 'AG-002', 'name' => 'Cherry Mae Robles', 'mobile' => '09195550177', 'email' => 'cherry.robles@mail.test', 'activation_type' => 'percent',
        'activation_value' => 50, 'first_payment_type' => 'none', 'first_payment_value' => 0, 'clawback_days' => 60, 'is_active' => 1]);
    return $ctx;
}

/** People and businesses of the sample subscribers (deterministic) */
function isp_demo_people(): array
{
    $first = ['Juan', 'Maricel', 'Rodrigo', 'Jennifer', 'Mark Anthony', 'Rowena', 'Christian', 'Lovely', 'Ronaldo', 'Angelica', 'Jayson', 'Princess', 'Ferdinand', 'Kimberly', 'Ariel',
        'Mylene', 'Joel', 'Rachelle', 'Dennis', 'Shiela', 'Rommel', 'Analyn', 'Vincent', 'Hazel', 'Ernesto', 'Charmaine', 'Gilbert', 'Jocelyn', 'Noel', 'Kristine', 'Allan', 'Marites',
        'Reynaldo', 'Michelle', 'Elmer', 'Diana', 'Jerome', 'Cristina', 'Melvin', 'Bea', 'Teodoro', 'Imelda', 'Paolo', 'Liezl', 'Benjamin', 'Gemma', 'Arnold', 'Lorna', 'Randy', 'Sarah',
        'Edwin', 'Joy', 'Oscar', 'Leah', 'Ramil', 'Nerissa', 'Romeo', 'Carmela', 'Wilfredo', 'Rhea'];
    $last = ['Dela Cruz', 'Santiago', 'Mercado', 'Navarro', 'Pangilinan', 'Manalastas', 'Lapuz', 'Dizon', 'Sison', 'Galang', 'Mangahas', 'Bernardo', 'Tolentino', 'Cunanan', 'Yap',
        'De Guzman', 'Lacson', 'Mallari', 'Vergara', 'Concepcion', 'Bautista', 'Esguerra', 'Roxas', 'Samonte', 'Valdez', 'Ocampo', 'Caballero', 'Agustin', 'Salvador', 'Pineda'];
    $out = [];
    for ($i = 0; $i < 60; $i++) {
        $out[] = [$last[($i * 7) % count($last)], $first[$i]];
    }
    return $out;
}

/** The six months of operation, simulated day by day */
function isp_demo_history(array $ctx, callable $U, callable $as, string $S, string $T, callable $add, callable $ago): array
{
    $lines = [];
    $P = $ctx['plans'];
    $A = $ctx['areas'];
    $people = isp_demo_people();
    $members = DB::all("SELECT m.id, m.party_id, m.last_name, m.first_name, m.mobile FROM members m WHERE m.status = 'active' AND m.party_id IS NOT NULL ORDER BY m.id");
    $mob = fn(int $i) => '09' . ['17', '18', '19', '20', '21', '27', '28', '55', '56', '66', '77', '95', '98'][$i % 13] . str_pad((string)((4410000 + $i * 7919) % 10000000), 7, '0', STR_PAD_LEFT);
    $daysIn = max(60, (int)((strtotime($T) - strtotime($S)) / 86400));

    // ---------------------------------------------------------------- the subscribers and how each behaves
    // profile: prompt | late | partial | autodebit | advance | delinquent_pay | delinquent_arr | delinquent_term | delinquent | delinquent_fail
    //          | disputer | exempt | suspend | etf | upgrade | downgrade | renew | pending
    $subs = [];
    $spec = function (array $s) use (&$subs) {
        $s['i'] = count($subs);
        $subs[] = $s + ['party' => null, 'class' => 'residential', 'business' => null, 'cycle' => 1, 'agent' => null, 'channel' => 'cash', 'profile' => 'prompt'];
    };
    $mal = ['MAL-ATL', 'MAL-BUL', 'MAL-GUI', 'MAL-LON', 'MAL-MOJ', 'MAL-STR', 'MAL-TIK', 'MAL-ATL-P1', 'MAL-ATL-P2'];
    $cal = ['CAL-POB', 'CAL-CAN', 'CAL-GAT', 'CAL-SAP'];
    $hag = ['HAG-SSB', 'HAG-SNI', 'HAG-SGF'];
    $fiberPlans = ['FBR30', 'FBR50', 'FBR50', 'FBR100', 'FBR50', 'FBR30', 'FBR100', 'FBR200', 'FBR50', 'FBR30', 'FBR100', 'FBR500'];
    $profiles = ['prompt', 'prompt', 'late', 'prompt', 'prompt', 'partial', 'prompt', 'late', 'prompt', 'prompt'];
    $channels = ['cash', 'cash', 'gcash', 'cash', 'bank_transfer', 'cash', 'maya', 'gcash', 'cash', 'bank_deposit'];
    $p = 0;
    // the people of the cooperative first: member price, some pay from their savings
    foreach (array_slice($members, 0, 8) as $k => $m) {
        $spec(['party' => (int)$m['party_id'], 'last' => $m['last_name'], 'first' => $m['first_name'], 'mobile' => $m['mobile'], 'area' => $mal[$k % 7], 'plan' => ['FBR50', 'FBR30', 'FBR100', 'FBR50', 'FBR30', 'FBR100', 'FBR50', 'FBR200'][$k],
            'act' => $add($S, 2 + $k * 3), 'profile' => $k >= 3 && $k <= 7 ? 'autodebit' : 'prompt', 'channel' => 'cash']);
    }
    // businesses and institutions
    foreach ([['Malolos Print & Copy Center', 'MAL-STR', 'BIZ300', 'business', 'check'], ['Bulihan Hardware & Construction Supply', 'MAL-BUL', 'BIZ300', 'business', 'bank_transfer'],
              ['Santos Family Pharmacy', 'MAL-GUI', 'FBR200', 'business', 'cash'], ['Calumpit Rural Health Unit', 'CAL-POB', 'FBR100', 'government', 'check'],
              ['St. Anne Learning Center of Hagonoy', 'HAG-SSB', 'CATV50', 'institutional', 'bank_deposit']] as $k => [$bn, $ar, $pl, $cl, $ch]) {
        $spec(['business' => $bn, 'last' => null, 'first' => null, 'area' => $ar, 'plan' => $pl, 'class' => $cl, 'act' => $add($S, 4 + $k * 5), 'channel' => $ch, 'profile' => 'prompt',
            'mobile' => $mob(900 + $k)]);
    }
    // residential subscribers
    for ($k = 0; $k < 44; $k++) {
        $early = $k < 32;
        $area = $k % 9 === 4 ? $cal[$k % 4] : ($k % 9 === 7 ? $hag[$k % 3] : $mal[$k % 9]);
        $plan = substr($area, 0, 3) === 'HAG' ? 'CATV50' : (substr($area, 0, 3) === 'CAL' && $early ? 'LGCY10' : $fiberPlans[$k % count($fiberPlans)]);
        [$l, $f] = $people[$p++];
        $spec(['last' => $l, 'first' => $f, 'area' => $area, 'plan' => $plan, 'act' => $early ? $add($S, 3 + (int)($k * 1.1)) : $add($S, 45 + ($k - 32) * (int)max(3, ($daysIn - 52) / 11)),
            'profile' => $profiles[$k % 10], 'channel' => $channels[$k % 10], 'cycle' => $k % 5 === 3 ? 15 : 1, 'agent' => $k % 4 === 1 ? 'agent' : ($k % 7 === 2 ? 'agent2' : null)]);
    }
    // the stories (on early accounts, so they have history): reassign the profiles of chosen residential accounts
    $story = ['delinquent_pay' => 13, 'delinquent_pay2' => 14, 'delinquent_arr' => 15, 'delinquent_term' => 16, 'delinquent' => 17, 'delinquent_fail' => 18, 'disputer' => 19,
        'exempt' => 20, 'suspend' => 21, 'etf' => 22, 'upgrade' => 23, 'downgrade' => 24, 'advance' => 25, 'advance2' => 26, 'delinquent2' => 28];
    foreach ($story as $prof => $i) {
        $subs[$i]['profile'] = preg_replace('/\d$/', '', $prof);
        $subs[$i]['channel'] = 'cash';
        $subs[$i]['cycle'] = 1;
    }
    $subs[$story['downgrade']]['plan'] = 'FBR200';
    $subs[$story['downgrade']]['area'] = 'MAL-MOJ';
    $subs[$story['upgrade']]['plan'] = 'FBR30';
    $subs[$story['upgrade']]['area'] = 'MAL-ATL';
    $subs[$story['etf']]['plan'] = 'FBR50';
    $subs[$story['etf']]['area'] = 'MAL-BUL';
    foreach (['delinquent_pay', 'delinquent_pay2', 'delinquent_arr', 'delinquent_term', 'delinquent', 'delinquent2', 'delinquent_fail', 'exempt', 'disputer', 'suspend'] as $prof) {
        if (in_array($subs[$story[$prof]]['plan'], ['LGCY10', 'CATV50'], true)) {
            $subs[$story[$prof]]['plan'] = 'FBR50';
            $subs[$story[$prof]]['area'] = 'MAL-GUI';
        }
    }
    // the stop months of the delinquents (invoices issued on or after this date are not paid)
    $stop = ['delinquent_pay' => $add($S, 61), 'delinquent_pay2' => $add($S, 92), 'delinquent_arr' => $add($S, 61), 'delinquent_term' => $add($S, 32), 'delinquent' => $add($S, 120),
        'delinquent_fail' => $add($S, 122), 'delinquent2' => $add($S, 150), 'exempt' => $ago(45), 'disputer' => $ago(40)];
    foreach ($stop as $prof => $d) {
        $subs[$story[$prof]]['stop'] = $d;
    }
    $subs[$story['delinquent_pay']]['payback'] = $ago(38);
    $subs[$story['delinquent_pay2']]['payback'] = $ago(9);
    // the legacy subscribers whose six-month contract is ending
    $legacy = array_values(array_filter(array_keys($subs), fn($i) => $subs[$i]['plan'] === 'LGCY10'));
    // the pending installations (approved, waiting for the technician) and the applications in each step
    foreach ([['MAL-STR', 'FBR100'], ['CAL-CAN', 'FBR50'], ['MAL-TIK', 'PROMO100']] as $k => [$ar, $pl]) {
        [$l, $f] = $people[$p++];
        $spec(['last' => $l, 'first' => $f, 'area' => $ar, 'plan' => $pl, 'act' => null, 'pending' => $ago(4 - $k), 'installed' => $k === 2 ? $ago(1) : null, 'profile' => 'pending',
            'agent' => $k === 0 ? 'agent' : null]);
    }
    $lines[] = count($subs) . ' subscribers planned';

    // ---------------------------------------------------------------- helpers
    $areaPoint = function (string $code, int $i) use ($ctx): array {
        [$la, $lo] = $ctx['area_geo'][$code];
        return [round($la + (($i * 37) % 17 - 8) * 0.00022, 6), round($lo + (($i * 53) % 19 - 9) * 0.00021, 6)];
    };
    $freePort = function (string $areaCode) use ($ctx, $A): ?int {
        $bc = substr($areaCode, 0, 7);
        $nodes = $ctx['naps'][$bc] ?? [];
        foreach ($nodes as $nap) {
            $pid = DB::value("SELECT p.id FROM isp_net_ports p JOIN isp_net_nodes n ON n.id = p.node_id WHERE p.node_id = :n AND p.status = 'free' AND n.status = 'active' ORDER BY p.port_no LIMIT 1", ['n' => $nap]);
            if ($pid) {
                return (int)$pid;
            }
        }
        $ids = array_keys(IspInfra::freePorts($A[$bc] ?? null));
        return $ids ? (int)$ids[0] : null;
    };
    $serial = fn(string $prefix, int $n) => $prefix . strtoupper(substr(hash('crc32b', $prefix . $n), 0, 8));
    $refNo = fn(string $ch, int $n) => match ($ch) {
        'gcash' => (string)(9000000000000 + $n * 7919 % 999999999),
        'maya' => 'MP' . (string)(70000000 + $n * 613),
        'bank_transfer' => 'IP' . date('ymd', strtotime((string)today())) . str_pad((string)($n % 100000), 6, '0', STR_PAD_LEFT),
        'bank_deposit' => 'BDO-DS-' . (string)(500000 + $n),
        default => 'REF' . $n,
    };
    $refSeq = 0;
    $accounts = [];       // subscriber index => account id
    $acctSub = [];        // account id => subscriber index
    $appOf = [];
    $woOf = [];

    // onboarding step 1: the application, identity, survey, contract, approval, scheduling (a few days before)
    $apply = function (int $i, string $installDate) use (&$subs, &$appOf, &$woOf, &$accounts, &$acctSub, $as, $ctx, $P, $A, $freePort, $areaPoint, $mob) {
        $s = $subs[$i];
        $ch = $s['agent'] ? 'agent' : (['walkin', 'walkin', 'online', 'phone'][$i % 4]);
        $as($ch === 'agent' ? 'agent1' : 'csr1');
        [$lat, $lng] = $areaPoint($s['area'], $i);
        $plan = IspPlans::get($P[$s['plan']]);
        $addr = ($s['business'] ? 'Unit ' . (2 + $i % 8) . ', ' : (($i * 13) % 180 + 3) . ' ') . ['Rizal St.', 'Mabini St.', 'Del Pilar St.', 'M.H. del Pilar Ave.', 'Burgos St.', 'Luna St.', 'Bonifacio St.', 'Purok ' . (1 + $i % 6)][$i % 8]
            . ', Brgy. ' . preg_replace('/ (Purok|\().*/', '', (string)DB::value('SELECT name FROM isp_areas WHERE id = :a', ['a' => $A[substr($s['area'], 0, 7)]]))
            . ', ' . ['MAL' => 'City of Malolos', 'CAL' => 'Calumpit', 'HAG' => 'Hagonoy'][substr($s['area'], 0, 3)] . ', Bulacan';
        $app = IspApplications::create(['party_id' => $s['party'], 'classification' => $s['class'], 'last_name' => $s['business'] ? null : $s['last'], 'first_name' => $s['business'] ? null : $s['first'],
            'business_name' => $s['business'], 'mobile' => $s['mobile'] ?? $mob($i), 'email' => $i % 3 === 0 ? strtolower(preg_replace('/[^a-z]/i', '', ($s['first'] ?? 'info') . '.' . ($s['last'] ?? 'office'))) . '@mail.test' : '',
            'billing_address' => $addr, 'install_address' => $addr, 'landmark' => ['Near the chapel', 'Beside the sari-sari store', 'Across the basketball court', 'Green gate', ''][$i % 5],
            'area_id' => $A[$s['area']], 'latitude' => $lat, 'longitude' => $lng, 'plan_id' => $plan['id'], 'agent_id' => $s['agent'] ? $ctx[$s['agent']] : null,
            'preferred_date' => $installDate, 'privacy_consent' => 1, 'notes' => $s['business'] ? 'Business account: official receipts and invoices in the business name' : ''], $ch);
        $as('csr1');
        IspApplications::verifyIdentity($app, $s['business'] ? 'Business permit / DTI / SEC registration' : ['PhilSys National ID', 'Driver\'s license', 'UMID', 'Postal ID'][$i % 4],
            $s['business'] ? 'BP-2026-' . (1200 + $i) : sprintf('%04d-%04d-%04d', 1000 + $i * 7, 2000 + $i * 11, 3000 + $i * 13));
        $as('techsup1');
        $port = $plan['technology'] === 'fiber' ? $freePort($s['area']) : null;
        IspApplications::survey($app, ['coverage_ok' => 1, 'survey_feasible' => 1, 'survey_notes' => $port ? 'Drop from ' . IspInfra::portLabel($port) . '; aerial route along the poles' : 'Coaxial drop from the tap; aerial',
            'est_cable_m' => 60 + ($i * 17) % 120, 'port_id' => $port, 'quote_materials' => 0, 'latitude' => $lat, 'longitude' => $lng]);
        $as('csr1');
        IspApplications::acceptContract($app, $s['business'] ? 'Authorized representative of ' . $s['business'] : $s['first'] . ' ' . $s['last']);
        $as('techsup1');
        $r = IspApplications::approve($app, 'Survey feasible, documents complete');
        $accounts[$i] = (int)$r['account_id'];
        $acctSub[(int)$r['account_id']] = $i;
        $appOf[$i] = $app;
        $woOf[$i] = (int)$r['work_order_id'];
        // account details decided at sign-up: the billing cycle, a static IP, the router rental
        $set = [];
        if ($s['cycle'] !== 1) {
            $set['cycle_day'] = $s['cycle'];
        }
        if ($s['plan'] === 'BIZ300') {
            $set['static_ip'] = '203.177.45.' . (18 + $i);
        }
        if ($s['plan'] === 'FBR500') {
            $set['router_rental'] = 1;
        }
        if ($set) {
            IspAccounts::saveDetails($accounts[$i], [], $set);
        }
        $tech = $plan['technology'] === 'cable' ? $ctx['tech']['TEC-04'] : ($i % 3 === 0 ? $ctx['tech']['TEC-04'] : $ctx['tech']['TEC-01']);
        IspWorkOrders::schedule($woOf[$i], $tech, $installDate, $i % 2 ? 'PM' : 'AM', 1 + $i % 4);
    };

    // onboarding step 2: the installation (materials, completion) and the activation
    $install = function (int $i, string $d, bool $activate = true) use (&$subs, &$accounts, &$woOf, $as, $ctx, $P, $serial) {
        $s = $subs[$i];
        $plan = IspPlans::get($P[$s['plan']]);
        $wo = $woOf[$i];
        $as('techsup1');
        $w = IspWorkOrders::get($wo);
        $as($w['technician_id'] === $ctx['tech']['TEC-01'] ? 'installer1' : 'techsup1');
        $cable = $plan['technology'] === 'cable';
        $unit = match ($plan['technology']) { 'cable' => 'STB-HD', 'wireless' => 'CPE-WL5', default => $i % 5 === 4 ? 'ONU-F670L' : 'ONU-HG8145' };
        $sn = $serial(match ($unit) { 'STB-HD' => 'STB', 'CPE-WL5' => 'UBNT', 'ONU-F670L' => 'ZTEG', default => 'HWTC' }, $i);
        $len = (float)DB::value('SELECT est_cable_m FROM isp_applications WHERE account_id = :a', ['a' => $accounts[$i]]) ?: 80;
        $mats = [[$ctx['items'][$unit], 1, $sn]];
        if ($plan['technology'] === 'fiber') {
            $mats[] = [$ctx['items']['DRP-FIG8'], $len + 20];
            $mats[] = [$ctx['items']['CON-SCAPC'], 2];
            $mats[] = [$ctx['items']['CLMP-FTTH'], 4];
            $mats[] = [$ctx['items']['PTC-SCAPC'], 1];
        } elseif ($cable) {
            $mats[] = [$ctx['items']['COAX-RG6'], $len + 15];
        }
        if (in_array($s['plan'], ['FBR200', 'FBR500', 'BIZ300'], true)) {
            $mats[] = [$ctx['items']['RTR-AX1500'], 1, $serial('TPLK', $i)];
        }
        IspWorkOrders::step($wo, 'accepted');
        IspWorkOrders::issue($wo, $mats, $d);
        IspWorkOrders::step($wo, 'in_progress');
        $used = [];
        foreach (DB::all("SELECT m.id, m.qty_issued, i.code FROM isp_wo_materials m JOIN items i ON i.id = m.item_id WHERE m.work_order_id = :w AND m.status = 'issued'", ['w' => $wo]) as $m) {
            $used[(int)$m['id']] = [in_array($m['code'], ['DRP-FIG8', 'COAX-RG6'], true) ? $len : (float)$m['qty_issued'], null];
        }
        IspWorkOrders::complete($wo, ['checklist' => array_keys(IspWorkOrders::CHECKLISTS['installation']), 'materials' => $used, 'cable_used_m' => $len,
            'onu_serial' => $plan['technology'] === 'fiber' ? $sn : '', 'router_serial' => in_array($s['plan'], ['FBR200', 'FBR500', 'BIZ300'], true) ? $serial('TPLK', $i) : ($cable || $plan['technology'] === 'wireless' ? $sn : ''),
            'rx_power_dbm' => $plan['technology'] === 'fiber' ? -17.2 - ($i % 9) * 0.6 : '', 'tx_power_dbm' => $plan['technology'] === 'fiber' ? 2.1 : '',
            'speed_test_down' => round((float)$plan['download_mbps'] * (0.93 + ($i % 5) * 0.012), 1), 'speed_test_up' => round((float)$plan['upload_mbps'] * 0.95, 1),
            'labor_hours' => 2 + $i % 3, 'findings' => 'Installed and tested, subscriber satisfied', 'customer_accepted_by' => $s['business'] ? 'Office manager of ' . $s['business'] : $s['first'] . ' ' . $s['last']]);
        $as('techsup1');
        IspWorkOrders::verify($wo, 'Photos and readings checked');
        if ($activate) {
            $as('netadmin1');
            IspAccounts::activate($accounts[$i], $d);
            if ($s['profile'] === 'autodebit') {
                $as('membership');
                $opts = IspAccounts::savingsOptions((int)DB::value('SELECT isp_customer_id FROM isp_accounts WHERE id = :a', ['a' => $accounts[$i]]));
                $fee = IspPlans::priceFor($plan, IspAccounts::get($accounts[$i]));
                $sav = $opts ? Savings::account((int)array_key_first($opts)) : null;
                if ($sav && (float)Savings::available($sav) >= 7 * $fee) {
                    IspAccounts::saveDetails($accounts[$i], ['notify_sms' => 1], ['autodebit_sav_account_id' => array_key_first($opts)]);
                } else {
                    $subs[$i]['profile'] = 'prompt';
                }
            }
        }
    };

    // the cashier's day: a shift opened at the first cash payment, closed and counted in the evening
    $shift = null;
    $shiftDays = [];
    $openShift = function (string $d) use (&$shift, $as) {
        if ($shift) {
            return;
        }
        $as('cashier1');
        $shift = IspCashier::open(Auth::id(), Accts::get('isp_cash'), 2000, IspSetup::userBranch());
    };
    $collect = function (int $accountId, float $amount, string $channel, string $d, array $o = []) use (&$refSeq, $refNo, $as, $openShift): ?int {
        $amount = r2($amount);
        if ($amount <= 0) {
            return null;
        }
        $refSeq++;
        $o += ['pay_date' => $d, 'page_key' => 'ispbill.collect'];
        if ($channel === 'cash') {
            $openShift($d);
            $as('cashier1');
        } elseif ($channel === 'check') {
            $openShift($d);
            $as('cashier1');
            $o += ['check_no' => (string)(100200 + $refSeq * 3), 'check_bank' => ['BDO', 'Metrobank', 'Land Bank', 'BPI'][$refSeq % 4], 'source' => 'counter'];
        } else {
            $o += ['reference_no' => $refNo($channel, $refSeq)];
            $as($o['user'] ?? 'collections1');
        }
        unset($o['user']);
        try {
            return IspPayments::collect($accountId, $amount, $channel, $o);
        } catch (UserError $e) {
            return null;
        }
    };

    // the planned payments: [account id, amount (null: the open balance of the invoices due), channel, options]
    $openBalance = fn(int $accountId) => r2((float)DB::value("SELECT COALESCE(SUM(balance), 0) FROM isp_invoices WHERE account_id = :a AND status IN ('posted', 'partially_paid', 'overdue', 'disputed')", ['a' => $accountId]));
    $planDay = function (array $s, array $inv) use ($add): ?array {
        $id = (int)$inv['id'];
        $kind = $inv['kind'];
        if ($kind === 'initial') {
            return [$add($inv['invoice_date'], $s['profile'] === 'late' ? 3 : 1), 1.0];
        }
        if (!empty($s['stop']) && $inv['invoice_date'] >= $s['stop'] && empty($s['resumed'])) {
            return null;
        }
        return match ($s['profile']) {
            'late' => [$add($inv['due_date'], 1 + $id % 4), 1.0],
            'partial' => [$add($inv['invoice_date'], 4 + $id % 3), 0.6],
            'autodebit' => [$add($inv['due_date'], 2), 1.0],
            'advance', 'pending' => null,
            default => [$add($inv['invoice_date'], 2 + ($id * 7) % 9), 1.0],
        };
    };
    $nextBusiness = function (string $d) use ($add): string {
        do {
            $d = $add($d, 1);
        } while ((int)date('N', strtotime($d)) > 5);
        return $d;
    };

    // the events of each day: [phase => [callables]]
    $events = [];
    $on = function (string $d, string $phase, callable $fn) use (&$events, $T) {
        if ($d <= $T) {
            $events[$d][$phase][] = $fn;
        }
    };
    foreach ($subs as $i => $s) {
        if ($s['act']) {
            $on($add($s['act'], -5), '09:30', fn() => $apply($i, $s['act']));
            $on($s['act'], '13:00', fn() => $install($i, $s['act']));
        } elseif (!empty($s['pending']) && !empty($s['installed'])) {
            // installed yesterday, waiting for the network administrator's activation
            $on($s['pending'], '10:00', fn() => $apply($i, $s['installed']));
            $on($s['installed'], '15:00', fn() => $install($i, $s['installed'], false));
        } elseif (!empty($s['pending'])) {
            $on($s['pending'], '10:00', fn() => $apply($i, $add($T, 2 + $i % 3)));
        }
    }

    // ---------------------------------------------------------------- the stories (scripted events)
    $acc = function (string $prof) use (&$accounts, $story): ?int {
        return $accounts[$story[$prof]] ?? null;
    };
    // advance payments: six months paid ahead at sign-up
    foreach (['advance', 'advance2'] as $k => $prof) {
        $i = $story[$prof];
        $on($add($subs[$i]['act'], 1), '11:00', function () use (&$accounts, $i, $k, $collect, $add, $subs, $openBalance, $P) {
            $a = IspAccounts::get($accounts[$i]);
            $fee = IspPlans::priceFor(IspPlans::get((int)$a['plan_id']), $a);
            $months = $k === 0 ? 6 : 9;
            $collect($accounts[$i], $openBalance($accounts[$i]) + $months * $fee, 'bank_transfer', (string)today(), ['notes' => $months . ' months paid in advance']);
        });
    }
    // voluntary suspension (working abroad)
    $on($ago(18), '14:00', function () use ($acc, $as, $add, $T) {
        $as('csr1');
        IspAccounts::suspend($acc('suspend'), $add($T, 42), 'Subscriber working abroad for two months; asked for a voluntary suspension');
    });
    // upgrade and downgrade in the middle of the billing period
    $on($ago(44), '15:00', function () use ($acc, $as, $P) {
        $as('csr1');
        IspAccounts::changePlan($acc('upgrade'), $P['FBR100'], (string)today(), 'Upgrade requested: the children study online');
    });
    $on($ago(33), '15:00', function () use ($acc, $as, $P) {
        $as('csr1');
        IspAccounts::changePlan($acc('downgrade'), $P['FBR50'], (string)today(), 'Downgrade requested to lower the monthly bill');
    });
    // termination inside the lock-in with the early termination fee; the equipment is recovered
    $etfWo = null;
    $on($ago(25), '10:00', function () use ($acc, $as, &$etfWo) {
        $as('branchmgr');
        $r = IspAccounts::terminate($acc('etf'), (string)today(), 'Family moving to Cebu (outside the coverage); termination inside the lock-in period', true);
        $etfWo = $r['recovery_wo_id'] ?? null;
    });
    $on($ago(24), '11:00', function () use ($acc, $collect, $openBalance) {
        $collect($acc('etf'), $openBalance($acc('etf')), 'cash', (string)today(), ['notes' => 'Final bill settled']);
    });
    $on($ago(20), '14:00', function () use (&$etfWo, $acc, $as, $ctx) {
        if (!$etfWo) {
            return;
        }
        $as('techsup1');
        IspWorkOrders::schedule($etfWo, $ctx['tech']['TEC-02'], (string)today(), 'PM');
        $as('maintech1');
        $eq = array_map(fn($e) => (int)$e['id'], DB::all("SELECT id FROM isp_equipment WHERE account_id = :a AND status = 'for_recovery'", ['a' => $acc('etf')]));
        IspWorkOrders::complete($etfWo, ['checklist' => array_keys(IspWorkOrders::CHECKLISTS['recovery']), 'recovered' => array_fill_keys($eq, 'recovered'),
            'findings' => 'ONU and router collected in good condition', 'customer_accepted_by' => 'Subscriber']);
    });
    // the payment arrangement of a disconnected subscriber (restores the service)
    $on($ago(30), '11:00', function () use (&$subs, $story, $acc, $as, $add, $collect, $openBalance) {
        $a = $acc('delinquent_arr');
        $subs[$story['delinquent_arr']]['resumed'] = true;
        $bal = $openBalance($a);
        if ($bal <= 0) {
            return;
        }
        $as('collections1');
        $third = r2($bal / 3);
        $arr = IspCredit::createArrangement($a, 'installment', [[(string)today(), $third], [$add((string)today(), 30), $third], [$add((string)today(), 60), r2($bal - 2 * $third)]],
            true, true, 'Three monthly installments; new bills are paid on time', 'Disconnection again if an installment is missed');
        $as('manager');
        IspCredit::decideArrangement($arr, true, 'Good payment history before the job loss');
        $collect($a, $third, 'cash', (string)today(), ['notes' => 'First installment of the arrangement']);
    });
    $on($ago(0), '11:00', function () use ($acc, $collect) {
        $line = DB::row("SELECT l.* FROM isp_arrangement_lines l JOIN isp_arrangements r ON r.id = l.arrangement_id WHERE r.account_id = :a AND r.status = 'active' AND l.paid_amount < l.amount ORDER BY l.due_date LIMIT 1",
            ['a' => (int)$acc('delinquent_arr')]);
        if ($line && $line['due_date'] <= today()) {
            $collect((int)$acc('delinquent_arr'), (float)$line['amount'] - (float)$line['paid_amount'], 'gcash', (string)today(), ['notes' => 'Installment of the payment arrangement']);
        }
    });
    // the long-disconnected subscriber: the account is closed, the equipment is to be recovered, the rest written off
    $on($ago(12), '10:00', function () use ($acc, $as) {
        $as('branchmgr');
        $a = IspAccounts::get($acc('delinquent_term'));
        if ($a['service_status'] === 'disconnected') {
            IspAccounts::terminate($acc('delinquent_term'), (string)today(), 'Disconnected for nonpayment for over 60 days: account closed, balance for collection', false);
        }
    });
    $on($ago(6), '15:00', function () use ($acc, $as) {
        $inv = DB::row("SELECT id, balance FROM isp_invoices WHERE account_id = :a AND status IN ('posted','partially_paid','overdue') AND balance > 0 ORDER BY due_date LIMIT 1", ['a' => $acc('delinquent_term')]);
        if ($inv) {
            $as('billing1');
            $adj = IspCredit::request($acc('delinquent_term'), (int)$inv['id'], 'write_off', (float)$inv['balance'], 'Uncollectible: subscriber left the area, demand letters returned');
            $as('accountant');
            IspCredit::decide($adj, true, 'Approved for write-off per the collection policy');
        }
    });
    // billing adjustments: an approved credit and requests still waiting
    $on($ago(29), '15:30', function () use (&$accounts, $as) {
        $a = $accounts[2] ?? null;
        $inv = $a ? DB::row("SELECT id, balance FROM isp_invoices WHERE account_id = :a AND balance > 0 ORDER BY invoice_date DESC LIMIT 1", ['a' => $a]) : null;
        if ($inv) {
            $as('billing1');
            $adj = IspCredit::request($a, (int)$inv['id'], 'credit', min(200, (float)$inv['balance']), 'Double charge of the router rental corrected');
            $as('accountant');
            IspCredit::decide($adj, true, 'Checked against the plan');
        }
    });
    $on($ago(2), '15:30', function () use (&$accounts, $as) {
        $as('billing1');
        foreach ([[30, 'credit', 150, 'Service credit for a 2-day slow connection (ticket)'], [31, 'debit', 350, 'Replacement of a damaged patch cord (subscriber caused)']] as [$i, $type, $amt, $why]) {
            $a = $accounts[$i] ?? null;
            if (!$a) {
                continue;
            }
            $inv = $type === 'debit' ? null : DB::row("SELECT id, balance FROM isp_invoices WHERE account_id = :a AND balance >= :b ORDER BY invoice_date DESC LIMIT 1", ['a' => $a, 'b' => $amt]);
            if ($type === 'debit' || $inv) {
                IspCredit::request($a, $inv ? (int)$inv['id'] : null, $type, $amt, $why);
            }
        }
    });
    // a bounced check reversed
    $bounce = null;
    $on($ago(52), '11:00', function () use (&$bounce, &$accounts, $collect, $openBalance) {
        $a = $accounts[8] ?? null;   // the print shop pays by check
        if ($a && ($bal = $openBalance($a)) > 0) {
            $bounce = $collect($a, $bal, 'check', (string)today(), ['notes' => 'Check payment']);
        }
    });
    $on($ago(47), '10:00', function () use (&$bounce, $as, &$accounts, $collect, $openBalance) {
        if (!$bounce) {
            return;
        }
        $as('manager');
        IspPayments::reverse($bounce, 'Check returned by the bank: drawn against insufficient funds (DAIF)');
        $collect($accounts[8], $openBalance($accounts[8]), 'cash', (string)today(), ['notes' => 'Replacement of the returned check, paid in cash']);
    });
    // a legacy subscriber renews the contract; another moves to fiber (service request done)
    if ($legacy) {
        $on($ago(10), '14:30', function () use (&$accounts, $legacy, $as, $subs) {
            $a = $accounts[$legacy[0]] ?? null;
            $c = $a ? DB::value("SELECT id FROM isp_contracts WHERE account_id = :a AND status IN ('active','expired') ORDER BY id DESC LIMIT 1", ['a' => $a]) : null;
            if ($c) {
                $as('csr1');
                IspContracts::renew((int)$c, 12, $subs[$legacy[0]]['first'] . ' ' . $subs[$legacy[0]]['last']);
            }
        });
    }
    // the network: a fiber cut on the Guinhawa feeder (credits for the downtime) and an outage being repaired today
    $outage = null;
    $on($ago(22), '07:00', function () use (&$outage, $as) {
        $as('netadmin1');
        $spl = (int)DB::value("SELECT id FROM isp_net_nodes WHERE code = 'SPL-GUI-01'");
        $outage = IspOutages::create(['title' => 'Guinhawa feeder fiber cut', 'scope' => 'node', 'node_id' => $spl, 'verified' => 1, 'started_at' => date('Y-m-d', strtotime((string)today())) . ' 06:10:00',
            'sla_hours' => 24, 'credit_policy' => 'prorated']);
        IspOutages::move($outage, 'in_repair', ['repair_notes' => 'Splicing team dispatched with the OTDR; break located 380 m from the splitter']);
    });
    $on($ago(21), '16:00', function () use (&$outage, $as) {
        if (!$outage) {
            return;
        }
        $as('netadmin1');
        IspOutages::move($outage, 'restored', ['root_cause' => 'Feeder cable cut by a backhoe of the road widening project', 'repair_notes' => '24 cores re-spliced in a new closure',
            'restored_at' => date('Y-m-d', strtotime((string)today())) . ' 15:40:00']);
        $as('manager');
        try {
            IspOutages::postCredits($outage);
        } catch (UserError $e) {
        }
        $as('netadmin1');
        IspOutages::move($outage, 'closed');
    });
    $on($ago(0), '07:30', function () use ($as) {
        $as('netadmin1');
        $napRow = DB::row("SELECT n.id, n.name FROM isp_net_nodes n JOIN isp_net_ports p ON p.node_id = n.id AND p.account_id IS NOT NULL WHERE n.node_type = 'nap' AND n.code NOT LIKE 'NAP-GUI%'
                            AND n.status = 'active' GROUP BY n.id, n.name ORDER BY COUNT(*) DESC, n.id LIMIT 1");
        $nap = (int)$napRow['id'];
        $o = IspOutages::create(['title' => $napRow['name'] . ' down (water entered the enclosure)', 'scope' => 'node', 'node_id' => $nap, 'verified' => 1,
            'started_at' => date('Y-m-d', strtotime((string)today())) . ' 05:40:00', 'sla_hours' => 24, 'credit_policy' => 'prorated']);
        IspOutages::move($o, 'in_repair', ['repair_notes' => 'Maintenance team on site; enclosure being replaced']);
    });

    // tickets in every status
    $ticket = function (int $i, string $type, string $subject, string $desc, string $priority, string $source) use (&$accounts, $as): ?int {
        $a = $accounts[$i] ?? null;
        if (!$a) {
            return null;
        }
        $as('csr1');
        return IspTickets::create(['account_id' => $a, 'ticket_type' => $type, 'subject' => $subject, 'description' => $desc, 'priority' => $priority, 'source' => $source]);
    };
    $tk = [];
    $on($ago(60), '10:00', function () use (&$tk, $ticket) {
        $tk[1] = $ticket(3, 'slow', 'Slow internet in the evening', 'Speed drops to 5 Mbps between 7 and 11 PM', 'normal', 'phone');
    });
    $on($ago(60), '10:25', function () use (&$tk, $as) {
        $as('csr1');
        IspTickets::move($tk[1], 'classified', ['note' => 'Checked the ONU remotely: Wi-Fi channel congestion']);
        IspTickets::note($tk[1], 'Changed the Wi-Fi channel and band remotely; asked the subscriber to test tonight', null, true);
    });
    $on($ago(59), '09:15', function () use (&$tk, $as) {
        $as('csr1');
        IspTickets::move($tk[1], 'resolved', ['resolution' => 'Wi-Fi moved to the 5 GHz band, channel 149; speed test 48 Mbps', 'root_cause' => 'Wi-Fi interference from neighboring routers']);
    });
    $on($ago(59), '14:00', function () use (&$tk, $as) {
        $as('techsup1');
        IspTickets::move($tk[1], 'verified');
    });
    $on($ago(58), '10:00', function () use (&$tk, $as) {
        $as('csr1');
        IspTickets::move($tk[1], 'closed', ['rating' => 5, 'feedback' => 'Fast and polite support. Salamat po!']);
    });
    $on($ago(22), '07:30', function () use (&$tk, $ticket, $as, &$accounts) {
        // a subscriber behind the cut Guinhawa feeder
        $a = DB::value("SELECT oa.account_id FROM isp_outage_accounts oa ORDER BY oa.id LIMIT 1");
        $i = $a ? array_search((int)$a, $accounts, true) : 5;
        $tk[2] = $ticket((int)$i, 'outage', 'No internet since 6 AM', 'All lights red on the ONU (LOS)', 'high', 'phone');
        if ($tk[2]) {
            $o = DB::value('SELECT id FROM isp_outages ORDER BY id DESC LIMIT 1');
            DB::run('UPDATE isp_tickets SET outage_id = :o WHERE id = :t', ['o' => $o, 't' => $tk[2]]);
            IspTickets::note($tk[2], 'Linked to the Guinhawa feeder outage', null, true);
        }
    });
    $on($ago(21), '16:30', function () use (&$tk, $as) {
        if (!empty($tk[2])) {
            $as('csr1');
            IspTickets::move($tk[2], 'resolved', ['resolution' => 'Service restored after the feeder was re-spliced; ONU online', 'root_cause' => 'Feeder fiber cut (outage)']);
            IspTickets::move($tk[2], 'closed', ['rating' => 4]);
        }
    });
    // a broken ONU replaced in the field (the old unit comes back damaged)
    $woRep = null;
    $on($ago(14), '09:30', function () use (&$tk, &$woRep, $ticket, $as, $ctx) {
        $tk[3] = $ticket(10, 'broken_device', 'ONU keeps restarting', 'The ONU reboots every few minutes; power adapter is warm', 'high', 'walkin');
        if ($tk[3]) {
            $as('techsup1');
            IspTickets::move($tk[3], 'classified', ['ticket_type' => 'broken_device']);
            $woRep = IspTickets::dispatch($tk[3], $ctx['tech']['TEC-02'], (string)today(), 'PM');
        }
    });
    $on($ago(14), '13:40', function () use (&$woRep, $as, $ctx) {
        if (!$woRep) {
            return;
        }
        $as('maintech1');
        IspWorkOrders::step($woRep, 'accepted');
        $spare = DB::row("SELECT e.id, e.serial_no FROM isp_equipment e WHERE e.status = 'in_stock' AND e.item_id = :i ORDER BY e.id LIMIT 1", ['i' => $ctx['items']['ONU-HG8145']]);
        IspWorkOrders::issue($woRep, [[$ctx['items']['ONU-HG8145'], 1, $spare['serial_no']], [$ctx['items']['PTC-SCAPC'], 1]]);
        IspWorkOrders::step($woRep, 'in_progress');
    });
    $on($ago(14), '15:20', function () use (&$tk, &$woRep, $as) {
        if (!$woRep) {
            return;
        }
        $as('maintech1');
        $w = IspWorkOrders::get($woRep);
        $new = (string)DB::value("SELECT serial_no FROM isp_wo_materials WHERE work_order_id = :w AND serial_no IS NOT NULL LIMIT 1", ['w' => $woRep]);
        $old = array_map(fn($e) => (int)$e['id'], DB::all("SELECT e.id FROM isp_equipment e JOIN items i ON i.id = e.item_id WHERE e.account_id = :a AND e.status = 'installed' AND i.code LIKE 'ONU%'", ['a' => $w['account_id']]));
        IspWorkOrders::complete($woRep, ['checklist' => array_keys(IspWorkOrders::CHECKLISTS['replacement']), 'recovered' => array_fill_keys($old, 'damaged'),
            'onu_serial' => $new, 'rx_power_dbm' => -18.4, 'findings' => 'ONU power board failed; replaced with a spare unit', 'customer_accepted_by' => 'Subscriber']);
        $as('csr1');
        IspTickets::move($tk[3], 'resolved', ['resolution' => 'ONU replaced (' . $new . '), service tested', 'root_cause' => 'Defective ONU power board']);
    });
    $on($ago(13), '09:10', function () use (&$tk, $as) {
        if (!empty($tk[3])) {
            $as('techsup1');
            IspTickets::move($tk[3], 'verified');
        }
    });
    // a damaged drop cable repaired (materials used)
    $woCab = null;
    $on($ago(9), '10:00', function () use (&$tk, &$woCab, $ticket, $as, $ctx, $add) {
        $tk[4] = $ticket(11, 'damaged_cable', 'Drop cable hanging low after the storm', 'The cable sags over the road near the gate', 'normal', 'sms');
        if ($tk[4]) {
            $as('techsup1');
            $woCab = IspTickets::dispatch($tk[4], $ctx['tech']['TEC-05'], $add((string)today(), 1), 'AM');
        }
    });
    $on($ago(8), '08:40', function () use (&$woCab, $as, $ctx) {
        if (!$woCab) {
            return;
        }
        $as('techsup1');
        IspWorkOrders::step($woCab, 'in_progress');
        IspWorkOrders::issue($woCab, [[$ctx['items']['DRP-FIG8'], 60], [$ctx['items']['CLMP-FTTH'], 4], [$ctx['items']['CON-SCAPC'], 2]]);
    });
    $on($ago(8), '11:10', function () use (&$tk, &$woCab, $as, $ctx) {
        if (!$woCab) {
            return;
        }
        $as('techsup1');
        $mats = [];
        foreach (DB::all('SELECT id, item_id FROM isp_wo_materials WHERE work_order_id = :w', ['w' => $woCab]) as $m) {
            $mats[(int)$m['id']] = [(int)$m['item_id'] === $ctx['items']['DRP-FIG8'] ? 45 : null, null];
        }
        IspWorkOrders::complete($woCab, ['checklist' => array_keys(IspWorkOrders::CHECKLISTS['repair']), 'materials' => $mats, 'rx_power_dbm' => -19.1,
            'findings' => 'Re-strung 45 m of drop cable with new clamps', 'labor_hours' => 2]);
        $as('csr1');
        IspTickets::move($tk[4], 'resolved', ['resolution' => 'Drop cable re-strung and secured', 'root_cause' => 'Storm damage to the drop span']);
    });
    $on($ago(5), '11:00', function () use (&$tk, $ticket, $as) {
        $tk[5] = $ticket(27, 'intermittent', 'Internet disconnects several times a day', 'PPPoE session drops 5-6 times a day since last week', 'normal', 'portal');
        if ($tk[5]) {
            IspTickets::move($tk[5], 'classified', ['note' => 'Session logs show LOS alarms; needs a site visit']);
            IspTickets::move($tk[5], 'on_hold', ['note' => 'Subscriber is available only on Saturday; visit scheduled then']);
        }
    });
    $on($ago(3), '09:00', function () use (&$tk, $ticket, $as, $ctx, $add) {
        $tk[6] = $ticket(33, 'low_signal', 'Red LOS light blinking', 'The ONU shows a blinking red light in the morning', 'high', 'phone');
        if ($tk[6]) {
            $as('techsup1');
            $wo = IspTickets::dispatch($tk[6], $ctx['tech']['TEC-02'], $add((string)today(), 4), 'AM');
        }
        $tk[7] = $ticket(34, 'no_internet', 'No internet after a brownout', 'Router lights normal but no internet', 'normal', 'phone');
        if ($tk[7]) {
            $as('techsup1');
            $wo = IspTickets::dispatch($tk[7], $ctx['tech']['TEC-01'], (string)today(), 'PM');
            $as('installer1');
            IspWorkOrders::step($wo, 'accepted');
            IspWorkOrders::step($wo, 'in_progress');
            $as('csr1');
            IspTickets::note($tk[7], 'Technician on site, checking the ONU and the drop', null, true);
        }
    });
    $on($ago(1), '16:00', function () use (&$tk, $ticket) {
        $tk[8] = $ticket(35, 'billing', 'Question on the late charge', 'Subscriber asks why there is a late charge on the bill', 'low', 'email');
        $tk[9] = $ticket(36, 'slow', 'Slow speed on the 100 Mbps plan', 'Speed test shows 40 Mbps on cable', 'normal', 'portal');
        if ($tk[9]) {
            IspTickets::move($tk[9], 'classified');
        }
    });
    $on($ago(0), '08:00', function () use (&$tk, $ticket) {
        $tk[10] = $ticket(37, 'no_internet', 'No internet since last night', 'Called the hotline twice', 'critical', 'phone');
        $tk[11] = $ticket(38, 'relocation', 'Request to move the modem to the 2nd floor', 'Wants the ONU moved upstairs with a longer drop', 'low', 'walkin');
    });
    // service requests (from the portal and the counter)
    $on($ago(7), '14:00', function () use (&$accounts, $as, $P, $legacy) {
        $as('csr1');
        $req = function (int $i, string $type, string $details, string $status, ?int $plan = null, ?string $resp = null) use ($accounts) {
            $a = $accounts[$i] ?? null;
            if ($a) {
                DB::insert('isp_service_requests', ['request_no' => Seq::next('isp_request'), 'account_id' => $a, 'request_type' => $type, 'details' => $details, 'requested_plan_id' => $plan,
                    'status' => $status, 'response' => $resp, 'handled_by' => $status !== 'open' ? Auth::id() : null, 'handled_at' => $status !== 'open' ? date('Y-m-d H:i:s') : null, 'created_by' => Auth::id()]);
            }
        };
        $req(40, 'upgrade', 'Requesting an upgrade to Fiber 200 Mbps from next month', 'open', $P['FBR200']);
        $req(41, 'relocation', 'Moving to Brgy. Mojon in December; same account please', 'in_progress', null, 'Site survey of the new address scheduled');
        $req(42, 'profile_update', 'Change of mobile number and billing e-mail', 'done', null, 'Contact details updated');
        if (isset($legacy[1])) {
            $req($legacy[1], 'upgrade', 'Move from the wireless plan to fiber', 'declined', $P['FBR50'], 'Fiber is not yet available at this address (planned next quarter); we will call you');
        }
    });
    // announcements
    $on($ago(3), '17:00', fn() => IspDemoClock::raw(function () use ($as, $A, $add, $T) {
        $as('csr1');
        $now = date('Y-m-d H:i:s', IspDemoClock::now());
        DB::insert('isp_announcements', ['title' => 'Scheduled maintenance: OLT firmware upgrade', 'body' => 'Internet service in Malolos may be interrupted for up to 30 minutes between 1:00 AM and 4:00 AM on ' . fdate($add($T, 6)) . ' while we upgrade the OLT firmware.',
            'area_id' => $A['MAL'], 'starts_at' => $now, 'ends_at' => $add($T, 7) . ' 06:00:00', 'is_active' => 1, 'created_by' => Auth::id()]);
        DB::insert('isp_announcements', ['title' => 'Holiday promo: Fiber 100 Mbps at 30% off', 'body' => 'Sign up or upgrade to Fiber 100 Mbps and get 30% off the first three months, with free installation. Visit our office or apply online.',
            'area_id' => null, 'starts_at' => $now, 'ends_at' => date('Y-12-31') . ' 23:59:00', 'is_active' => 1, 'created_by' => Auth::id()]);
    }));
    // applications at the early steps (not subscribers yet)
    $on($ago(2), '10:30', function () use ($as, $P, $A, $people, $mob, $ctx) {
        $mk = function (int $n, string $area, string $plan, string $channel, string $to, string $why = '') use ($as, $P, $A, $people, $mob, $ctx) {
            [$l, $f] = $people[50 + $n];
            $as($channel === 'agent' ? 'agent1' : 'csr1');
            $app = IspApplications::create(['last_name' => $l, 'first_name' => $f, 'mobile' => $mob(700 + $n), 'install_address' => (20 + $n * 9) . ' Mabini St., ' . DB::value('SELECT name FROM isp_areas WHERE id = :a', ['a' => $A[$area]]),
                'area_id' => $A[$area], 'plan_id' => $P[$plan], 'agent_id' => $channel === 'agent' ? $ctx['agent'] : null, 'privacy_consent' => 1, 'classification' => 'residential'], $channel);
            $as('csr1');
            if ($to === 'for_verification') {
                IspApplications::move($app, 'for_verification', 'Waiting for a copy of the ID');
            } elseif ($to === 'site_survey') {
                IspApplications::verifyIdentity($app, 'PhilSys National ID', '5821-' . (4400 + $n) . '-7713');
            } elseif (in_array($to, ['rejected', 'cancelled'], true)) {
                IspApplications::move($app, $to, $why);
            }
        };
        $mk(0, 'MAL-MOJ', 'FBR50', 'online', 'new');
        $mk(1, 'MAL-BUL', 'FBR100', 'agent', 'new');
        $mk(2, 'CAL-POB', 'FBR30', 'walkin', 'for_verification');
        $mk(3, 'MAL-STR', 'FBR200', 'phone', 'site_survey');
        $mk(4, 'HAG-CAR', 'FBR50', 'walkin', 'rejected', 'No coverage in Carillo yet: no fiber or coaxial plant (planned 2027)');
        $mk(5, 'MAL-LON', 'FBR30', 'online', 'cancelled', 'Applicant decided to stay with the current provider');
    });

    // ---------------------------------------------------------------- the days
    $pendingRuns = [];
    $failTried = false;
    $stats = ['runs' => 0];
    $isLast = false;
    // the payments of a day (11:30): what each subscriber pays, by the channel the subscriber uses
    $verN = 0;
    $doPayments = function (string $d, int $dow) use (&$subs, &$accounts, &$acctSub, &$stats, &$verN, $collect, $openBalance, $planDay, $add, $as, $T) {
        foreach ($accounts as $i => $aid) {
            $s = $subs[$i];
            $a = IspAccounts::get($aid);
            if (!in_array($a['service_status'], ['active', 'disconnected', 'suspended'], true)) {
                continue;
            }
            // a delinquent who settles everything (the service is restored automatically)
            // a portal reference that was rejected: paid again the next day
            if (!empty($s['retry']) && $s['retry'] === $d) {
                unset($subs[$i]['retry']);
                if (($bal = $openBalance($aid)) > 0) {
                    $collect($aid, $bal, $dow === 7 ? 'gcash' : 'cash', $d, ['notes' => 'Paid again after the rejected reference']);
                }
                continue;
            }
            if (!empty($s['payback']) && $s['payback'] === $d) {
                $subs[$i]['resumed'] = true;
                $collect($aid, $openBalance($aid), 'cash', $d, ['notes' => 'Full settlement of the arrears']);
                continue;
            }
            // the subscriber who disputes a bill, and the one in the hospital: when the first unpaid bill falls due
            if (in_array($s['profile'], ['disputer', 'exempt'], true) && empty($s['protected'])) {
                $inv = DB::row("SELECT * FROM isp_invoices WHERE account_id = :a AND status IN ('posted','partially_paid','overdue') AND balance > 0 AND invoice_date >= :s AND due_date <= :d
                                 ORDER BY due_date LIMIT 1", ['a' => $aid, 's' => $s['stop'], 'd' => $d]);
                if ($inv) {
                    $subs[$i]['protected'] = true;
                    $as('manager');
                    if ($s['profile'] === 'disputer') {
                        $dsp = IspCredit::openDispute($aid, (int)$inv['id'], (float)$inv['balance'], 'Subscriber says the service was down for five days and asks for a credit before paying', true);
                        IspCredit::noteDispute($dsp, 'Network logs requested from the NOC; ticket history being checked');
                    } else {
                        IspCredit::grantExemption($aid, $d, $add($T, 20), 'Subscriber hospitalized (family request); no disconnection until discharge, the balance to be settled after');
                    }
                }
            }
            $due = 0.0;
            foreach (IspPayments::openInvoices($aid) as $inv) {
                $pl = $planDay($s, $inv);
                if ($pl && $pl[0] === $d) {
                    $due += (float)$inv['balance'] * $pl[1];
                } elseif ($s['profile'] === 'partial' && $pl && $add($inv['due_date'], 2) === $d) {
                    $due += (float)$inv['balance'];
                }
            }
            if ($due <= 0.004) {
                continue;
            }
            $ch = $s['channel'];
            if ($dow === 7 && in_array($ch, ['cash', 'check'], true)) {
                $ch = 'gcash';        // Sunday: the office is closed
            }
            if (in_array($ch, ['gcash', 'maya'], true) && $i % 3 === 0) {
                // sent through the portal: a person verifies the reference first
                $collect($aid, $due, $ch, $d, ['source' => 'portal', 'status' => 'pending', 'user' => 'collections1', 'notes' => 'Reference submitted on the customer portal']);
            } else {
                $collect($aid, $due, $ch, $d);
            }
        }
        // the portal references of earlier days are checked by the collection officer
        if ($dow <= 6) {
            $as('collections1');
            foreach (DB::all("SELECT id, account_id, reference_no FROM isp_payments WHERE status = 'pending' AND pay_date < :d", ['d' => $d]) as $pp) {
                if ($verN++ % 9 === 4) {
                    IspPayments::verify((int)$pp['id'], false, 'Reference not found in the GCash business account; please send the screenshot again');
                    if (isset($acctSub[(int)$pp['account_id']])) {
                        $subs[$acctSub[(int)$pp['account_id']]]['retry'] = $add($d, 1);
                    }
                } else {
                    IspPayments::verify((int)$pp['id'], true, 'Reference matched in the e-wallet business account');
                }
            }
        }
    };
    // 17:00 the cashier closes and counts (on the last day the shift stays open: the cashier is on duty)
    $doClose = function (string $d, int $dow) use (&$shift, &$shiftDays, &$lines, &$isLast, $as) {
        if (!$shift) {
            return;
        }
        $sh = DB::row('SELECT * FROM isp_shifts WHERE id = :s', ['s' => $shift]);
        if ($isLast) {
            $lines[] = 'Cashier shift ' . $sh['shift_no'] . ' left open (the cashier is on duty)';
        } else {
            $as('cashier1');
            $exp = IspCashier::expectedCash($sh);
            $n = count($shiftDays) + (int)DB::value("SELECT COUNT(*) FROM isp_shifts WHERE status <> 'open'");
            $short = $n % 11 === 4 ? 20.0 : ($n % 13 === 7 ? -10.0 : 0.0);
            IspCashier::close($shift, isp_demo_denoms($exp - $short), $short > 0 ? 'Short by 20.00: change given twice' : ($short < 0 ? 'Over by 10.00: customer left the change' : ''));
            $shiftDays[] = [$shift, $d];
        }
        $shift = null;
    };
    for ($d = $add($S, -5); $d <= $T; $d = $add($d, 1)) {
        $dow = (int)date('N', strtotime($d));
        $isLast = $d === $T;
        // 08:00 the scheduler: overdue invoices, late charges, savings auto-debits, the disconnection engine; billing runs
        IspDemoClock::at($d, '08:00');
        $as('admin');
        IspSetup::asSystem(function () use ($d) {
            IspBilling::markOverdue($d);
            IspBilling::assessLateFees($d);
            IspPayments::runAutoDebits(0);
        });
        $day = (int)substr($d, 8, 2);
        if ($d > $S && ($day === 1 || $day === 15)) {
            // one run per branch office
            foreach (array_filter([$ctx['branch']['HO'], $ctx['branch']['BR2']]) as $br) {
                if (IspBilling::dueAccounts($d, $day, (int)$br)) {
                    $pendingRuns[] = IspSetup::asSystem(fn() => IspBilling::generate($d, $day, (int)$br, 'scheduler', false));
                    $stats['runs']++;
                }
            }
        }
        if ($dow <= 5) {
            // runs waiting since an earlier day (or today) are approved by the General Manager and posted
            foreach ($pendingRuns as $k => $run) {
                $r = DB::row('SELECT * FROM isp_billing_runs WHERE id = :r', ['r' => $run]);
                // the latest run of the second branch waits for the General Manager's approval
                if ($r && (int)$r['branch_id'] === (int)$ctx['branch']['BR2'] && (strtotime($T) - strtotime($r['bill_date'])) / 86400 <= 5 && (int)$r['invoice_count'] > 0) {
                    continue;
                }
                unset($pendingRuns[$k]);
                if (!$r || $r['status'] !== 'draft') {
                    continue;
                }
                if ((int)$r['invoice_count'] === 0) {
                    IspSetup::asSystem(fn() => IspBilling::cancelRun($run, 'Nothing was due on this cycle day'));
                    continue;
                }
                $as('manager');
                IspBilling::approveRun($run);
                IspBilling::postRun($run);
            }
            // disconnections: notified items whose notice time came are executed; then today's evaluation
            IspSetup::asSystem(function () use (&$failTried, $acc, $ctx) {
                foreach (DB::all("SELECT * FROM isp_disc_queue WHERE status = 'notified' AND execute_after <= :n", ['n' => IspDemoClock::parked()]) as $q) {
                    $fail = (int)$q['account_id'] === $acc('delinquent_fail') && !$failTried;
                    if ($fail) {
                        $failTried = true;
                        DB::run('UPDATE isp_devices SET options = :o WHERE id = :d', ['o' => 'fail_accounts = ' . DB::value('SELECT account_no FROM isp_accounts WHERE id = :a', ['a' => $q['account_id']]), 'd' => $ctx['device']]);
                    }
                    IspDisconnect::execute((int)$q['id'], true);
                    if ($fail) {
                        // the scheduler retries the failed command; after the last attempt the network administrator gets a work order
                        $op = (int)DB::value('SELECT net_op_id FROM isp_disc_queue WHERE id = :q', ['q' => $q['id']]);
                        for ($k = 0; $k < 6 && DB::value('SELECT state FROM isp_net_ops WHERE id = :o', ['o' => $op]) === 'queued'; $k++) {
                            IspNetwork::execute($op);
                        }
                        DB::run("UPDATE isp_devices SET options = '' WHERE id = :d", ['d' => $ctx['device']]);
                    }
                }
                IspDisconnect::evaluate();
            });
            // candidates of earlier days: approved by the branch manager (the final notice goes out)
            $cand = DB::column("SELECT id FROM isp_disc_queue WHERE status = 'candidate' AND DATE(created_at) < :d", ['d' => $d]);
            if ($cand) {
                $as('branchmgr');
                IspDisconnect::approve(array_map('intval', $cand));
            }
        }
        // 08:30 the cash of the shifts of earlier days: certified by the branch manager and remitted to the bank
        if ($dow <= 6 && $shiftDays && !$isLast) {
            IspDemoClock::at($d, '08:30');
            foreach ($shiftDays as $k => [$sid, $sd]) {
                if ($sd >= $d) {
                    continue;
                }
                $sh = DB::row('SELECT * FROM isp_shifts WHERE id = :s', ['s' => $sid]);
                $as('branchmgr');
                if ($sh['status'] === 'closed') {
                    IspCashier::certify($sid, 'Count checked');
                }
                $as('cashier1');
                $amt = r2((float)$sh['counted_cash'] - (float)$sh['opening_cash']);
                if ($amt > 0) {
                    IspCashier::remit($sid, (int)DB::value("SELECT id FROM accounts WHERE code = '1010'"), $amt, 'BDO deposit ' . date('md', strtotime($d)) . '-' . $sid);
                }
                unset($shiftDays[$k]);
            }
        }
        // the events of the day with the payments and the cashier's closing, in the order of their times
        $phases = $events[$d] ?? [];
        $phases['11:30'][] = 'payments';
        $phases['17:00'][] = 'close';
        if ($d === date('Y-m-t', strtotime($d)) && $d < $T) {
            $phases['17:30'][] = 'commissions';
        }
        if ($d === $add($S, 75)) {
            $phases['17:45'][] = 'retire';
        }
        ksort($phases);
        foreach ($phases as $hm => $fns) {
            IspDemoClock::at($d, $hm);
            foreach ($fns as $fn) {
                if ($fn === 'payments') {
                    $doPayments($d, $dow);
                } elseif ($fn === 'close') {
                    $doClose($d, $dow);
                } elseif ($fn === 'commissions') {
                    // month end: the commissions of the month before approved and paid
                    $as('accountant');
                    $ids = DB::column("SELECT id FROM isp_commissions WHERE status = 'pending' AND created_at < :d", ['d' => date('Y-m-01', strtotime($d))]);
                    if ($ids) {
                        IspAgents::approve(array_map('intval', $ids), true, 'Checked against the activations');
                        foreach (DB::column("SELECT DISTINCT agent_id FROM isp_commissions WHERE status = 'approved'") as $ag) {
                            try {
                                IspAgents::pay((int)$ag, $d);
                            } catch (UserError $e) {
                            }
                        }
                    }
                } elseif ($fn === 'retire') {
                    // the legacy wireless plan is no longer sold (its subscribers keep it)
                    $as('manager');
                    $pl = IspPlans::get($P['LGCY10']);
                    $f = array_intersect_key($pl, array_flip(['code', 'name', 'description', 'category', 'service_type', 'technology', 'includes_catv', 'monthly_fee', 'member_monthly_fee', 'download_mbps',
                        'upload_mbps', 'data_cap_gb', 'fair_use_policy', 'installation_fee', 'router_rental_fee', 'static_ip_fee', 'equipment_deposit', 'contract_months', 'lock_in_months', 'early_termination_fee',
                        'vat_mode', 'late_fee_type', 'late_fee_value', 'reconnection_fee', 'promo_discount_pct', 'promo_months', 'available_from', 'available_to', 'network_profile', 'income_account_id', 'sort_order']));
                    $f['status'] = 'retired';
                    $f['area_ids'] = DB::column('SELECT area_id FROM isp_plan_areas WHERE plan_id = :p', ['p' => $P['LGCY10']]);
                    IspPlans::save($f, (int)$P['LGCY10']);
                } else {
                    $fn();
                }
            }
        }
    }
    // the last day: subscribers paying at the counter this morning (the cashier's shift stays open)
    if ((int)date('N', strtotime($T)) <= 6) {
        IspDemoClock::at($T, '10:15');
        $n = 0;
        foreach ($accounts as $i => $aid) {
            $a = IspAccounts::get($aid);
            if ($n < 4 && $a['service_status'] === 'active' && $subs[$i]['channel'] === 'cash' && $subs[$i]['profile'] === 'prompt' && ($bal = $openBalance($aid)) > 0) {
                $collect($aid, $bal, 'cash', $T, ['notes' => 'Paid at the counter']);
                $n++;
            }
        }
        $isLast = true;
        $doClose($T, (int)date('N', strtotime($T)));
    }
    // payment references sent through the portal today: waiting for the collection officer
    IspDemoClock::at($T, '12:00');
    $n = 0;
    foreach ($accounts as $i => $aid) {
        $a = IspAccounts::get($aid);
        if ($n < 2 && $a['service_status'] === 'active' && $subs[$i]['profile'] === 'prompt' && in_array($subs[$i]['channel'], ['gcash', 'maya'], true)) {
            $amt = $openBalance($aid) ?: IspPlans::priceFor(IspPlans::get((int)$a['plan_id']), $a);
            $collect($aid, $amt, $subs[$i]['channel'], $T, ['source' => 'portal', 'status' => 'pending', 'user' => 'collections1', 'notes' => 'Reference submitted on the customer portal (screenshot attached)']);
            $n++;
        }
    }
    // a webhook payment from the payment gateway (signed), and a forged one (refused)
    IspDemoClock::at($T, '12:10');
    $as('admin');
    Settings::set('isp_gateway_provider', 'generic');
    Settings::set('isp_gateway_mode', 'test');
    Settings::set('isp_gateway_webhook_secret_enc', (string)IspCrypto::encrypt('sample-webhook-secret'));
    Settings::flush();
    IspSetup::reset();
    $gw = null;
    foreach ($accounts as $i => $aid) {
        $a = IspAccounts::get($aid);
        $bal = $openBalance($aid);
        if ($a['service_status'] === 'active' && $subs[$i]['profile'] === 'prompt' && $i >= 13) {
            $gw = [$a, $bal > 0 ? $bal : IspPlans::priceFor(IspPlans::get((int)$a['plan_id']), $a)];
            break;
        }
    }
    if ($gw) {
        [$a, $bal] = $gw;
        $raw = json_encode(['event_id' => 'evt_sample_' . substr(md5($a['account_no']), 0, 12), 'type' => 'payment.paid', 'status' => 'paid', 'account_no' => $a['account_no'], 'amount' => $bal, 'fee' => r2($bal * 0.022),
            'reference' => 'PG-' . strtoupper(substr(md5('pg' . $a['account_no']), 0, 10))]);
        [$code, $msg] = IspGateway::handle('generic', (string)$raw, ['X-Signature' => hash_hmac('sha256', (string)$raw, 'sample-webhook-secret')], '203.0.113.10');
        $lines[] = 'Payment gateway webhook for account ' . $a['account_no'] . ': ' . $msg;
        $forged = json_encode(['event_id' => 'evt_forged_001', 'status' => 'paid', 'account_no' => $a['account_no'], 'amount' => 5000, 'reference' => 'FAKE-001']);
        [$code, $msg] = IspGateway::handle('generic', (string)$forged, ['X-Signature' => str_repeat('0', 64)], '198.51.100.23');
        $lines[] = 'Forged webhook (wrong signature): ' . $msg;
    }
    // the portal logins of three subscribers (known sample passwords)
    IspDemoClock::at($T, '12:20');
    $as('csr1');
    $portal = [];
    foreach ([0, 9, $story['delinquent_pay2']] as $i) {
        if (!isset($accounts[$i])) {
            continue;
        }
        $a = IspAccounts::get($accounts[$i]);
        IspPortal::grant((int)$a['isp_customer_id']);
        DB::run('UPDATE isp_portal_users SET password_hash = :h, must_change_password = 0 WHERE isp_customer_id = :c', ['h' => password_hash('Portal@2027', PASSWORD_DEFAULT), 'c' => $a['isp_customer_id']]);
        $portal[] = DB::value('SELECT customer_no FROM isp_customers WHERE id = :c', ['c' => $a['isp_customer_id']]) . ' (' . $a['account_name'] . ', account ' . $a['account_no'] . ')';
    }
    $lines[] = 'Customer portal logins (password Portal@2027): ' . implode('; ', $portal);
    $lines[] = count(isp_demo_users()) . ' user accounts, one per role (password Demo@12345; admin keeps Admin@12345): '
        . implode(', ', array_map(fn($u) => $u[1] . ' (' . $u[0] . ')', isp_demo_users()));
    $lines[] = $stats['runs'] . ' billing runs, ' . DB::value("SELECT COUNT(*) FROM isp_disc_queue WHERE status = 'disconnected'") . ' disconnections, '
        . DB::value("SELECT COUNT(*) FROM isp_reconnections WHERE status = 'restored'") . ' reconnections, ' . DB::value('SELECT COUNT(*) FROM isp_shifts') . ' cashier shifts, '
        . DB::value('SELECT COUNT(*) FROM isp_tickets') . ' tickets, ' . DB::value('SELECT COUNT(*) FROM isp_work_orders') . ' work orders';
    return $lines;
}

/** The pieces of a counted amount: face value => pieces */
function isp_demo_denoms(float $amount): array
{
    $out = [];
    $left = (int)round($amount * 100);
    foreach (IspCashier::DENOMINATIONS as $d) {
        $c = (int)round((float)$d * 100);
        $n = intdiv($left, $c);
        if ($n > 0) {
            $out[$d] = $n;
            $left -= $n * $c;
        }
    }
    return $out;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    foreach (load_isp_demo() as $l) {
        echo $l, PHP_EOL;
    }
}
