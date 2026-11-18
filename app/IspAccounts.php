<?php
declare(strict_types=1);

/**
 * Subscribers (isp_customers: one per party of the Party Master) and their subscription accounts (isp_accounts: one per
 * service address). The account keeps the service status and the network state apart: a paid account is never shown
 * as connected because it was paid, only when the network says so.
 */
final class IspAccounts
{
    public const SERVICE_STATUSES = ['pending' => 'Pending activation', 'active' => 'Active', 'suspended' => 'Suspended (voluntary)', 'disconnected' => 'Disconnected',
        'terminated' => 'Terminated', 'cancelled' => 'Cancelled'];
    public const NETWORK_STATES = ['not_provisioned' => 'Not provisioned', 'provisioning' => 'Provisioning', 'enabled' => 'Enabled', 'disabled' => 'Disabled',
        'failed' => 'Operation failed', 'unknown' => 'Unknown'];
    public const CLASSIFICATIONS = ['residential' => 'Residential', 'business' => 'Business', 'government' => 'Government', 'institutional' => 'Institutional'];
    public const BILLING_BASES = ['calendar' => 'Fixed cycle day', 'anniversary' => 'Activation day (anniversary)'];
    public const BILL_TIMINGS = ['advance' => 'In advance (the coming period)', 'arrears' => 'In arrears (the period just ended)'];

    /** allowed service status moves */
    private const MOVES = [
        'pending' => ['active', 'cancelled'],
        'active' => ['suspended', 'disconnected', 'terminated'],
        'suspended' => ['active', 'disconnected', 'terminated'],
        'disconnected' => ['active', 'terminated'],
        'terminated' => [],
        'cancelled' => [],
    ];

    // ------------------------------------------------------------------ reading

    public static function get(int $id, bool $lock = false): ?array
    {
        return DB::row('SELECT a.*, c.customer_no, c.name AS customer_name, c.party_id, c.customer_id, c.mobile, c.email, c.classification, c.billing_address,
                               c.notify_sms, c.notify_email, p.name AS plan_name, p.code AS plan_code
                          FROM isp_accounts a JOIN isp_customers c ON c.id = a.isp_customer_id JOIN isp_plans p ON p.id = a.plan_id
                         WHERE a.id = :id' . ($lock ? ' FOR UPDATE' : ''), ['id' => $id]);
    }

    public static function byNo(string $no): ?array
    {
        $id = DB::value('SELECT id FROM isp_accounts WHERE account_no = :n', ['n' => trim($no)]);
        return $id ? self::get((int)$id) : null;
    }

    public static function customer(int $id): ?array
    {
        return DB::row('SELECT * FROM isp_customers WHERE id = :id', ['id' => $id]);
    }

    /** The accounts of a party with their balances (Party Center, Member Information) */
    public static function ofParty(int $partyId): array
    {
        $rows = DB::all('SELECT a.*, p.name AS plan_name FROM isp_accounts a JOIN isp_customers c ON c.id = a.isp_customer_id JOIN isp_plans p ON p.id = a.plan_id
                          WHERE c.party_id = :p ORDER BY a.account_no', ['p' => $partyId]);
        foreach ($rows as &$r) {
            $r += self::balances((int)$r['id']);
        }
        return $rows;
    }

    /** open_invoices (posted, unpaid), credit (unapplied payments), balance (open less credit), overdue */
    public static function balances(int $accountId, ?string $asOf = null): array
    {
        $open = (float)DB::value("SELECT COALESCE(SUM(balance), 0) FROM isp_invoices WHERE account_id = :a AND status IN ('posted', 'partially_paid', 'overdue', 'disputed')", ['a' => $accountId]);
        $credit = (float)DB::value("SELECT COALESCE(SUM(unapplied_amount), 0) FROM isp_payments WHERE account_id = :a AND status = 'posted'", ['a' => $accountId]);
        $overdue = (float)DB::value("SELECT COALESCE(SUM(balance), 0) FROM isp_invoices WHERE account_id = :a AND status IN ('posted', 'partially_paid', 'overdue', 'disputed') AND due_date < :d",
            ['a' => $accountId, 'd' => $asOf ?? today()]);
        return ['open_invoices' => r2($open), 'credit' => r2($credit), 'balance' => r2($open - $credit), 'overdue' => r2($overdue)];
    }

    public static function event(int $accountId, string $type, string $summary, ?string $refType = null, $refId = null, ?float $amount = null, ?string $when = null): void
    {
        DB::insert('isp_account_events', ['account_id' => $accountId, 'event_date' => $when ?? date('Y-m-d H:i:s'), 'event_type' => $type,
            'summary' => mb_substr($summary, 0, 255), 'ref_type' => $refType, 'ref_id' => $refId !== null ? (int)$refId : null, 'amount' => $amount, 'user_id' => Auth::id()]);
    }

    public static function pppoePassword(array $a): ?string
    {
        return IspCrypto::decrypt($a['pppoe_password_enc'] ?? null);
    }

    // ------------------------------------------------------------------ customers

    /**
     * The subscriber record of a party, made when it has none. $f: name, classification, mobile, email, billing_address,
     * tin, id_type, id_number. A party is made from the details when $partyId is null.
     */
    public static function ensureCustomer(?int $partyId, array $f, string $pageKey = 'isp.applications'): int
    {
        return DB::tx(function () use ($partyId, $f, $pageKey) {
            if ($partyId) {
                $have = DB::value("SELECT id FROM isp_customers WHERE party_id = :p AND status <> 'merged'", ['p' => $partyId]);
                if ($have) {
                    return (int)$have;
                }
                $party = Party::get($partyId) ?? fail('The person or organization was not found.');
                $name = (string)$party['legal_name'];
            } else {
                $name = trim((string)($f['name'] ?? ''));
                if ($name === '') {
                    fail('Enter the subscriber\'s name.');
                }
                $org = in_array($f['classification'] ?? 'residential', ['business', 'government', 'institutional'], true) && trim((string)($f['business_name'] ?? '')) !== '';
                $partyId = Party::create([
                    'party_type' => $org ? 'organization' : 'person', 'legal_name' => $name, 'display_name' => $name,
                    'last_name' => $org ? null : ($f['last_name'] ?? null), 'first_name' => $org ? null : ($f['first_name'] ?? null), 'middle_name' => $org ? null : ($f['middle_name'] ?? null),
                    'mobile' => $f['mobile'] ?? null, 'email' => $f['email'] ?? null, 'address' => $f['billing_address'] ?? ($f['install_address'] ?? null), 'tin' => $f['tin'] ?? null,
                ], null, null, $pageKey);
            }
            $party = Party::get($partyId);
            $id = DB::insert('isp_customers', [
                'customer_no' => Seq::next('isp_customer'), 'party_id' => $partyId, 'classification' => $f['classification'] ?? 'residential',
                'name' => mb_substr($name ?: (string)$party['legal_name'], 0, 160),
                'billing_address' => mb_substr((string)($f['billing_address'] ?? ($party['address'] ?? '')), 0, 255) ?: null,
                'mobile' => ($f['mobile'] ?? null) ?: ($party['mobile'] ?? null), 'email' => ($f['email'] ?? null) ?: ($party['email'] ?? null), 'tin' => ($f['tin'] ?? null) ?: ($party['tin'] ?? null),
                'id_type' => $f['id_type'] ?? null, 'id_number' => $f['id_number'] ?? null,
                'privacy_consent_at' => !empty($f['privacy_consent']) ? date('Y-m-d H:i:s') : null, 'created_by' => Auth::id(),
            ]);
            Party::log($partyId, 'subscriber', $id, 'granted', 'Cable / internet subscriber');
            Audit::log('create', 'isp', 'isp_customer', $id, (string)$name, ['page_key' => $pageKey]);
            return $id;
        });
    }

    /** The A/R customer record the subscriber's invoices post to (the party's own customer role) */
    public static function arCustomer(int $ispCustomerId): int
    {
        $c = self::customer($ispCustomerId) ?? fail('Subscriber not found.');
        if (!empty($c['customer_id'])) {
            $ok = DB::row('SELECT id, is_active FROM customers WHERE id = :id', ['id' => $c['customer_id']]);
            if ($ok && (int)$ok['is_active'] === 1) {
                return (int)$ok['id'];
            }
        }
        return DB::tx(function () use ($c) {
            $partyId = (int)$c['party_id'];
            $row = DB::row('SELECT id, is_active FROM customers WHERE party_id = :p AND parent_id IS NULL ORDER BY is_active DESC, id LIMIT 1', ['p' => $partyId]);
            if ($row && (int)$row['is_active'] === 1) {
                $cid = (int)$row['id'];
            } elseif ($row) {
                Party::restore('customer', (int)$row['id'], 'Cable / internet subscriber ' . $c['customer_no'], 'isp.account');
                $cid = (int)$row['id'];
            } else {
                $cid = Party::grant($partyId, 'customer', 'Cable / internet subscriber ' . $c['customer_no'], [], 'isp.account');
            }
            DB::run('UPDATE isp_customers SET customer_id = :c WHERE id = :id', ['c' => $cid, 'id' => $c['id']]);
            return $cid;
        });
    }

    /** Is the subscriber a member of the cooperative (the member price, the savings auto-debit)? */
    public static function isMember(int $ispCustomerId): bool
    {
        $c = self::customer($ispCustomerId);
        return $c && $c['party_id'] && Party::isActiveMember((int)$c['party_id']);
    }

    /** The member's savings accounts that can pay bills by auto-debit: id => label */
    public static function savingsOptions(int $ispCustomerId): array
    {
        $c = self::customer($ispCustomerId);
        if (!$c || !$c['party_id'] || !Coop::ready()) {
            return [];
        }
        $m = Party::member((int)$c['party_id']);
        if (!$m) {
            return [];
        }
        return DB::pairs("SELECT a.id, CONCAT(a.account_no, ' ', p.name, ' (', FORMAT(a.balance, 2), ')') FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id
                           WHERE a.member_id = :m AND a.status = 'active' AND p.product_type IN ('ordinary', 'special') ORDER BY a.account_no", ['m' => $m['id']]);
    }

    // ------------------------------------------------------------------ opening an account

    /**
     * Opens a subscription account (status pending): $f plan_id, install_address, area_id, branch_id, latitude, longitude,
     * landmark, agent_id, application_id, member_price, billing_basis, bill_timing, cycle_day, due_days, router_rental,
     * static_ip, pppoe_username, port_id, device_id, notes.
     */
    public static function open(int $ispCustomerId, array $f, string $pageKey = 'isp.applications'): int
    {
        return DB::tx(function () use ($ispCustomerId, $f, $pageKey) {
            $c = self::customer($ispCustomerId) ?? fail('Subscriber not found.');
            $plan = IspPlans::get((int)$f['plan_id']) ?? fail('Choose the plan.');
            if (trim((string)($f['install_address'] ?? '')) === '') {
                fail('Enter the installation address.');
            }
            $no = trim((string)($f['account_no'] ?? ''));
            if ($no === '') {
                $no = IspSetup::get('isp_account_prefix') . Seq::next('isp_account');
            }
            if (DB::value('SELECT id FROM isp_accounts WHERE account_no = :n', ['n' => $no])) {
                fail('Account number ' . $no . ' is already used.');
            }
            $user = trim((string)($f['pppoe_username'] ?? '')) ?: strtolower(preg_replace('/[^a-z0-9]/i', '', $no));
            if (DB::value('SELECT id FROM isp_accounts WHERE pppoe_username = :u', ['u' => $user])) {
                $user .= '-' . random_int(10, 99);
            }
            $member = self::isMember($ispCustomerId);
            $id = DB::insert('isp_accounts', [
                'account_no' => $no, 'isp_customer_id' => $ispCustomerId, 'account_name' => mb_substr((string)($f['account_name'] ?? $c['name']), 0, 160),
                'branch_id' => !empty($f['branch_id']) ? (int)$f['branch_id'] : null, 'area_id' => !empty($f['area_id']) ? (int)$f['area_id'] : null,
                'plan_id' => (int)$plan['id'], 'member_price' => $member && $plan['member_monthly_fee'] !== null ? 1 : 0,
                'install_address' => mb_substr(trim((string)$f['install_address']), 0, 255), 'landmark' => $f['landmark'] ?? null,
                'latitude' => ($f['latitude'] ?? '') !== '' ? (float)$f['latitude'] : null, 'longitude' => ($f['longitude'] ?? '') !== '' ? (float)$f['longitude'] : null,
                'billing_basis' => $f['billing_basis'] ?? IspSetup::get('isp_billing_basis'), 'bill_timing' => $f['bill_timing'] ?? IspSetup::get('isp_bill_timing'),
                'cycle_day' => max(1, min(28, (int)($f['cycle_day'] ?? IspSetup::int('isp_default_cycle_day')))), 'due_days' => max(0, (int)($f['due_days'] ?? IspSetup::int('isp_due_days'))),
                'prorate_first' => (int)($f['prorate_first'] ?? IspSetup::int('isp_prorate_first')),
                'router_rental' => !empty($f['router_rental']) ? 1 : 0, 'static_ip' => ($f['static_ip'] ?? '') ?: null,
                'pppoe_username' => $user, 'pppoe_password_enc' => IspCrypto::encrypt(($f['pppoe_password'] ?? '') ?: IspCrypto::password(10)),
                'device_id' => !empty($f['device_id']) ? (int)$f['device_id'] : (IspNetwork::defaultDevice()['id'] ?? null),
                'port_id' => !empty($f['port_id']) ? (int)$f['port_id'] : null, 'agent_id' => !empty($f['agent_id']) ? (int)$f['agent_id'] : null,
                'application_id' => !empty($f['application_id']) ? (int)$f['application_id'] : null,
                'security_deposit' => r2((float)($f['security_deposit'] ?? $plan['equipment_deposit'])),
                'service_status' => 'pending', 'network_state' => 'not_provisioned', 'notes' => $f['notes'] ?? null, 'created_by' => Auth::id(),
            ]);
            if (!empty($f['port_id'])) {
                IspInfra::assignPort((int)$f['port_id'], $id);
            }
            DB::insert('isp_account_plans', ['account_id' => $id, 'plan_id' => (int)$plan['id'], 'monthly_fee' => IspPlans::priceFor($plan, ['member_price' => $member ? 1 : 0]),
                'effective_from' => today(), 'change_type' => 'new', 'user_id' => Auth::id()]);
            self::event($id, 'opened', 'Subscription account opened on the ' . $plan['name'] . ' plan');
            Audit::log('create', 'isp', 'isp_account', $id, $no, ['page_key' => $pageKey]);
            return $id;
        });
    }

    // ------------------------------------------------------------------ status changes

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::MOVES[$from] ?? [], true);
    }

    /** Moves the service status (validated); the network state is changed by IspNetwork only */
    public static function setStatus(int $id, string $to, string $why, array $extra = []): void
    {
        $a = self::get($id, true) ?? fail('Account not found.');
        if ($a['service_status'] === $to) {
            return;
        }
        if (!self::canMove($a['service_status'], $to)) {
            fail('Account ' . $a['account_no'] . ' cannot go from ' . (self::SERVICE_STATUSES[$a['service_status']] ?? $a['service_status']) . ' to ' . (self::SERVICE_STATUSES[$to] ?? $to) . '.');
        }
        DB::update('isp_accounts', $extra + ['service_status' => $to, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        self::event($id, $to, (self::SERVICE_STATUSES[$to] ?? $to) . ($why !== '' ? ': ' . $why : ''));
        Audit::log('status', 'isp', 'isp_account', $id, (string)$a['account_no'], ['changes' => [['field' => 'service_status', 'old' => $a['service_status'], 'new' => $to]], 'reason' => $why]);
    }

    /**
     * Activates a pending account: the installation must be complete, the billing schedule is set from the activation
     * date, the initial invoice (installation, deposit, the first period, prorated) is made and posted, the network is
     * told to enable the subscriber, the subscriber is notified and the agent's activation commission is recorded.
     */
    public static function activate(int $id, string $date, string $pageKey = 'isp.applications'): array
    {
        return DB::tx(function () use ($id, $date, $pageKey) {
            $a = self::get($id, true) ?? fail('Account not found.');
            if ($a['service_status'] !== 'pending') {
                fail('Account ' . $a['account_no'] . ' is not waiting for activation.');
            }
            if (!valid_date($date)) {
                fail('Enter the activation date.');
            }
            // the installation conditions: a completed (and verified) installation work order
            $wo = DB::row("SELECT * FROM isp_work_orders WHERE account_id = :a AND wo_type = 'installation' AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", ['a' => $id]);
            if (!$wo || !in_array($wo['status'], ['completed', 'verified'], true)) {
                fail('The service cannot be activated before its installation work order is completed' . ($wo ? ' (' . $wo['wo_no'] . ' is ' . str_replace('_', ' ', $wo['status']) . ')' : '') . '.');
            }
            $missing = [];
            if (trim((string)$wo['customer_accepted_by']) === '') {
                $missing[] = 'the subscriber\'s acceptance';
            }
            if (trim((string)$a['pppoe_username']) === '') {
                $missing[] = 'the PPPoE user name';
            }
            $plan = IspPlans::get((int)$a['plan_id']);
            if ($plan['technology'] === 'fiber' && !$a['port_id']) {
                $missing[] = 'the NAP port of the drop cable';
            }
            if ($missing) {
                fail('Activation is held: the installation is missing ' . implode(', ', $missing) . '.');
            }
            $cycle = $a['billing_basis'] === 'anniversary' ? max(1, min(28, (int)substr($date, 8, 2))) : (int)$a['cycle_day'];
            $months = max(0, (int)$plan['contract_months']);
            $set = ['activation_date' => $date, 'cycle_day' => $cycle, 'contract_start' => $date,
                'contract_end' => $months ? IspPlans::addDays(IspPlans::addMonths($date, $months), -1) : null,
                'lock_in_until' => (int)$plan['lock_in_months'] ? IspPlans::addDays(IspPlans::addMonths($date, (int)$plan['lock_in_months']), -1) : null];
            self::setStatus($id, 'active', 'activated on ' . fdate($date), $set);
            DB::run("UPDATE isp_account_plans SET effective_from = :d WHERE account_id = :a AND effective_to IS NULL", ['d' => $date, 'a' => $id]);
            // the contract
            IspContracts::createFor($id, $date);
            // the first invoice: installation, deposit and the first (prorated) period
            $inv = IspBilling::initialInvoice($id, $date);
            // the network: enable the subscriber (a real device, the simulator, or a manual work order)
            $op = IspNetwork::request($id, 'activate', 'Service activation', 'activation', $id);
            if ($a['application_id']) {
                IspApplications::move((int)$a['application_id'], 'active', 'Activated, account ' . $a['account_no']);
            }
            IspNotify::event('service_activated', $id, ['plan' => $plan['name']], 'ACT-' . $id);
            IspAgents::onActivation($id);
            Audit::log('activate', 'isp', 'isp_account', $id, (string)$a['account_no'], ['page_key' => $pageKey]);
            return ['invoice_id' => $inv, 'net_op_id' => $op];
        });
    }

    /**
     * Upgrade, downgrade or migration to another plan from $date. The issued invoices keep their price; a mid-period
     * change under advance billing credits the unused part of the old plan and charges the new one for the rest of
     * the period (both on the next invoice), when the policy prorates changes.
     */
    public static function changePlan(int $id, int $planId, string $date, string $reason, string $pageKey = 'isp.account'): void
    {
        DB::tx(function () use ($id, $planId, $date, $reason, $pageKey) {
            $a = self::get($id, true) ?? fail('Account not found.');
            if (!in_array($a['service_status'], ['active', 'suspended', 'pending', 'disconnected'], true)) {
                fail('A terminated account cannot change plans.');
            }
            $new = IspPlans::get($planId) ?? fail('Choose the new plan.');
            $old = IspPlans::get((int)$a['plan_id']);
            if ((int)$a['plan_id'] === $planId) {
                fail('The account is already on that plan.');
            }
            if (!IspPlans::offered($new, $date, $a['area_id'] ? (int)$a['area_id'] : null)) {
                fail($new['name'] . ' is not offered in this area on ' . fdate($date) . '.');
            }
            if (trim($reason) === '') {
                fail('Enter the reason for the plan change.');
            }
            $oldFee = IspPlans::priceFor($old, $a);
            $newFee = IspPlans::priceFor($new, $a);
            $type = $newFee > $oldFee ? 'upgrade' : ($newFee < $oldFee ? 'downgrade' : 'migration');
            $note = '';
            if ($a['service_status'] !== 'pending' && IspSetup::get('isp_prorate_changes') === '1' && $a['billed_through'] && $date <= $a['billed_through']) {
                // the period already billed: credit the old plan and charge the new one for the days left
                [$ps, $pe] = IspPlans::periodOf($date, (int)$a['cycle_day']);
                $factor = IspPlans::prorateFactor($date, $a['billed_through'], $ps, $pe);
                $credit = r2($oldFee * $factor);
                $charge = r2($newFee * $factor);
                if ($credit > 0) {
                    IspBilling::addCharge($id, 'plan_change', 'Unused ' . $old['name'] . ' from ' . fdate($date) . ' to ' . fdate($a['billed_through']), -$credit, $date, 'PLC-' . $id . '-' . $planId . '-' . $date . '-C', $old['vat_mode']);
                }
                if ($charge > 0) {
                    IspBilling::addCharge($id, 'plan_change', $new['name'] . ' from ' . fdate($date) . ' to ' . fdate($a['billed_through']), $charge, $date, 'PLC-' . $id . '-' . $planId . '-' . $date . '-D', $new['vat_mode']);
                }
                $note = 'prorated: credit ' . money($credit) . ', charge ' . money($charge);
            }
            DB::run('UPDATE isp_account_plans SET effective_to = :d WHERE account_id = :a AND effective_to IS NULL', ['d' => IspPlans::addDays($date, -1), 'a' => $id]);
            DB::insert('isp_account_plans', ['account_id' => $id, 'plan_id' => $planId, 'monthly_fee' => $newFee, 'effective_from' => $date, 'change_type' => $type,
                'proration' => $note ?: null, 'user_id' => Auth::id()]);
            DB::update('isp_accounts', ['plan_id' => $planId, 'member_price' => (int)$a['member_price'] === 1 && $new['member_monthly_fee'] !== null ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            self::event($id, 'plan_change', ucfirst($type) . ' from ' . $old['name'] . ' to ' . $new['name'] . ' effective ' . fdate($date) . ($note ? ' (' . $note . ')' : '') . ': ' . $reason);
            Audit::log('plan_change', 'isp', 'isp_account', $id, (string)$a['account_no'], ['page_key' => $pageKey, 'reason' => $reason,
                'changes' => [['field' => 'plan', 'old' => $old['code'], 'new' => $new['code']]]]);
            if (in_array($a['service_status'], ['active'], true)) {
                IspNetwork::request($id, 'change_plan', 'Plan changed to ' . $new['name'], 'plan_change', $planId);
            }
        });
    }

    /** A voluntary suspension asked by the subscriber (not a disconnection for nonpayment): the network is disabled */
    public static function suspend(int $id, ?string $until, string $reason, string $pageKey = 'isp.account'): void
    {
        DB::tx(function () use ($id, $until, $reason, $pageKey) {
            $a = self::get($id, true) ?? fail('Account not found.');
            if ($a['service_status'] !== 'active') {
                fail('Only an active account can be suspended.');
            }
            self::setStatus($id, 'suspended', $reason, ['suspended_until' => $until ?: null]);
            IspNetwork::request($id, 'suspend', 'Voluntary suspension: ' . $reason, 'suspension', $id);
        });
    }

    public static function resume(int $id, string $reason, string $pageKey = 'isp.account'): void
    {
        DB::tx(function () use ($id, $reason) {
            $a = self::get($id, true) ?? fail('Account not found.');
            if ($a['service_status'] !== 'suspended') {
                fail('The account is not suspended.');
            }
            self::setStatus($id, 'active', $reason, ['suspended_until' => null]);
            IspNetwork::request($id, 'reconnect', 'Resumed after a voluntary suspension', 'resume', $id);
        });
    }

    /**
     * Cancellation / termination (Workflow G): the final invoice up to the end date (with the early termination fee
     * inside the lock-in), the disconnection, an equipment recovery work order and the security deposit applied to the
     * balance. Financial obligations and history stay: the account closes only once its balance is settled.
     */
    public static function terminate(int $id, string $date, string $reason, bool $chargeEtf, string $pageKey = 'isp.account'): array
    {
        return DB::tx(function () use ($id, $date, $reason, $chargeEtf, $pageKey) {
            $a = self::get($id, true) ?? fail('Account not found.');
            if (!in_array($a['service_status'], ['active', 'suspended', 'disconnected'], true)) {
                fail('Only an active, suspended or disconnected account can be terminated.');
            }
            if (trim($reason) === '') {
                fail('Enter the reason for the termination.');
            }
            $plan = IspPlans::get((int)$a['plan_id']);
            $out = [];
            if ($chargeEtf && $a['lock_in_until'] && $date <= $a['lock_in_until'] && (float)$plan['early_termination_fee'] > 0) {
                IspBilling::addCharge($id, 'termination_fee', 'Early termination fee (lock-in until ' . fdate($a['lock_in_until']) . ')', (float)$plan['early_termination_fee'], $date, 'ETF-' . $id, $plan['vat_mode']);
            }
            $out['final_invoice_id'] = IspBilling::finalInvoice($id, $date);
            if (in_array($a['network_state'], ['enabled', 'unknown', 'failed', 'provisioning'], true) || $a['service_status'] === 'active') {
                $out['net_op_id'] = IspNetwork::request($id, 'deprovision', 'Termination: ' . $reason, 'termination', $id);
            }
            self::setStatus($id, 'terminated', $reason, ['terminated_at' => $date . ' ' . date('H:i:s'), 'termination_reason' => mb_substr($reason, 0, 255)]);
            // the equipment lent or rented to the subscriber comes back
            $eq = DB::all("SELECT * FROM isp_equipment WHERE account_id = :a AND status = 'installed' AND ownership = 'company'", ['a' => $id]);
            if ($eq) {
                $out['recovery_wo_id'] = IspWorkOrders::create(['wo_type' => 'recovery', 'account_id' => $id, 'description' => 'Recover ' . count($eq) . ' equipment after termination',
                    'priority' => 'normal', 'address' => $a['install_address'], 'area_id' => $a['area_id']]);
                foreach ($eq as $e) {
                    IspEquipment::move((int)$e['id'], 'for_recovery', 'Subscription terminated', $id, $out['recovery_wo_id']);
                }
            }
            // the security deposit settles the final balance (the rest is refunded by the cashier)
            if ((float)$a['security_deposit'] > 0 && in_array($a['deposit_status'], ['held', 'billed'], true)) {
                $out['deposit'] = IspBilling::applyDeposit($id, 'Deposit applied to the final balance');
            }
            if ($a['port_id']) {
                IspInfra::releasePort((int)$a['port_id']);
            }
            DB::run("UPDATE isp_contracts SET status = 'terminated', terminated_at = :t WHERE account_id = :a AND status IN ('active', 'amended', 'renewed')", ['t' => date('Y-m-d H:i:s'), 'a' => $id]);
            IspAgents::onCancellation($id, $date);
            Audit::log('terminate', 'isp', 'isp_account', $id, (string)$a['account_no'], ['page_key' => $pageKey, 'reason' => $reason]);
            return $out;
        });
    }

    /** Saves the editable details of the subscriber and the account */
    public static function saveDetails(int $id, array $cust, array $acct, string $pageKey = 'isp.account'): void
    {
        DB::tx(function () use ($id, $cust, $acct, $pageKey) {
            $a = self::get($id, true) ?? fail('Account not found.');
            $c = self::customer((int)$a['isp_customer_id']);
            $cSet = array_intersect_key($cust, array_flip(['classification', 'mobile', 'email', 'billing_address', 'notify_sms', 'notify_email', 'id_type', 'id_number', 'notes']));
            $aSet = array_intersect_key($acct, array_flip(['account_name', 'install_address', 'landmark', 'latitude', 'longitude', 'area_id', 'branch_id', 'due_days', 'custom_due_day',
                'cycle_day', 'billing_basis', 'bill_timing', 'router_rental', 'static_ip', 'ip_pool', 'mac_address', 'device_id', 'notes', 'autodebit_sav_account_id', 'agent_id']));
            if (isset($aSet['cycle_day'])) {
                $aSet['cycle_day'] = max(1, min(28, (int)$aSet['cycle_day']));
            }
            if (array_key_exists('autodebit_sav_account_id', $aSet) && $aSet['autodebit_sav_account_id'] && !isset(self::savingsOptions((int)$a['isp_customer_id'])[(int)$aSet['autodebit_sav_account_id']])) {
                fail('The savings account chosen for the auto-debit is not an active savings account of the subscriber.');
            }
            foreach (['latitude', 'longitude'] as $k) {
                if (array_key_exists($k, $aSet)) {
                    $aSet[$k] = $aSet[$k] === '' || $aSet[$k] === null ? null : (float)$aSet[$k];
                }
            }
            foreach (['area_id', 'branch_id', 'device_id', 'custom_due_day', 'autodebit_sav_account_id', 'agent_id'] as $k) {
                if (array_key_exists($k, $aSet)) {
                    $aSet[$k] = $aSet[$k] ? (int)$aSet[$k] : null;
                }
            }
            if ($cSet) {
                DB::update('isp_customers', $cSet + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $c['id']]);
            }
            if ($aSet) {
                DB::update('isp_accounts', $aSet + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            }
            $diff = Audit::diff(array_merge($c, $a), array_merge($c, $a, $cSet, $aSet));
            if ($diff) {
                self::event($id, 'profile', 'Details changed: ' . implode(', ', array_unique(array_map(fn($d) => str_replace('_', ' ', (string)$d['field']), $diff))));
                Audit::log('edit', 'isp', 'isp_account', $id, (string)$a['account_no'], ['page_key' => $pageKey, 'changes' => $diff]);
            }
        });
    }
}
