<?php
declare(strict_types=1);

/**
 * Subscription applications (Workflow A):
 *   New Application -> For Verification -> Site Survey -> Approved -> For Installation -> Installed -> For Activation -> Active
 * with the identity check, the coverage and feasibility check, the free NAP port (reserved), the quotation (installation,
 * materials, deposit), the contract acceptance, the approval, then the subscriber, the account and the installation work
 * order. Rejected and cancelled applications stay on file with their reason.
 */
final class IspApplications
{
    public const STATUSES = ['new' => 'New application', 'for_verification' => 'For verification', 'site_survey' => 'Site survey', 'approved' => 'Approved',
        'for_installation' => 'For installation', 'installed' => 'Installed', 'for_activation' => 'For activation', 'active' => 'Active', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
    public const CHANNELS = ['walkin' => 'Walk-in', 'online' => 'Online (portal)', 'agent' => 'Sales agent', 'phone' => 'Phone'];
    private const MOVES = [
        'new' => ['for_verification', 'rejected', 'cancelled'],
        'for_verification' => ['site_survey', 'rejected', 'cancelled'],
        'site_survey' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['for_installation', 'cancelled'],
        'for_installation' => ['installed', 'cancelled'],
        'installed' => ['for_activation'],
        'for_activation' => ['active', 'cancelled'],
        'active' => [], 'rejected' => [], 'cancelled' => [],
    ];
    public const ID_TYPES = ['PhilSys National ID', 'Driver\'s license', 'Passport', 'UMID', 'SSS ID', 'PRC ID', 'Postal ID', 'Voter\'s ID', 'Senior citizen ID', 'Company / school ID', 'Business permit / SEC / DTI'];

    public static function get(int $id): ?array
    {
        return DB::row('SELECT a.*, p.name AS plan_name, ar.name AS area_name, ag.name AS agent_name FROM isp_applications a LEFT JOIN isp_plans p ON p.id = a.plan_id
                          LEFT JOIN isp_areas ar ON ar.id = a.area_id LEFT JOIN isp_agents ag ON ag.id = a.agent_id WHERE a.id = :id', ['id' => $id]);
    }

    public static function name(array $a): string
    {
        if (trim((string)$a['business_name']) !== '' && $a['classification'] !== 'residential') {
            return (string)$a['business_name'];
        }
        return Party::personName($a['first_name'] ?? null, $a['middle_name'] ?? null, $a['last_name'] ?? null, null);
    }

    public static function create(array $f, string $channel = 'walkin'): int
    {
        $f = array_map(fn($v) => is_string($v) ? trim($v) : $v, $f);
        if (empty($f['party_id']) && ($f['last_name'] ?? '') === '' && ($f['business_name'] ?? '') === '') {
            fail('Enter the applicant\'s name.');
        }
        if (($f['install_address'] ?? '') === '') {
            fail('Enter the installation address.');
        }
        if (empty($f['plan_id'])) {
            fail('Choose the plan.');
        }
        if (($f['mobile'] ?? '') === '' && empty($f['party_id'])) {
            fail('Enter a mobile number: the subscriber is reached on it.');
        }
        if (empty($f['privacy_consent'])) {
            fail('The applicant has to agree to the data privacy notice.');
        }
        $plan = IspPlans::get((int)$f['plan_id']) ?? fail('Choose the plan.');
        $areaId = !empty($f['area_id']) ? (int)$f['area_id'] : null;
        if (!IspPlans::offered($plan, today(), $areaId)) {
            fail($plan['name'] . ' is not offered in the chosen area.');
        }
        if (!empty($f['party_id'])) {
            $p = Party::get((int)$f['party_id']) ?? fail('The person on file was not found.');
            if ($p['party_type'] === 'organization') {
                $f['business_name'] = ($f['business_name'] ?? '') ?: $p['legal_name'];
            } else {
                $f['last_name'] = ($f['last_name'] ?? '') ?: ($p['last_name'] ?: $p['legal_name']);
                $f['first_name'] = ($f['first_name'] ?? '') ?: $p['first_name'];
                $f['middle_name'] = ($f['middle_name'] ?? '') ?: $p['middle_name'];
            }
            $f['mobile'] = ($f['mobile'] ?? '') ?: $p['mobile'];
            $f['email'] = ($f['email'] ?? '') ?: $p['email'];
        }
        $area = $areaId ? DB::row('SELECT * FROM isp_areas WHERE id = :a', ['a' => $areaId]) : null;
        $row = ['application_no' => Seq::next('isp_application'), 'channel' => isset(self::CHANNELS[$channel]) ? $channel : 'walkin', 'status' => 'new',
            'party_id' => !empty($f['party_id']) ? (int)$f['party_id'] : null, 'classification' => $f['classification'] ?? 'residential',
            'last_name' => ($f['last_name'] ?? '') ?: null, 'first_name' => ($f['first_name'] ?? '') ?: null, 'middle_name' => ($f['middle_name'] ?? '') ?: null,
            'business_name' => ($f['business_name'] ?? '') ?: null, 'mobile' => ($f['mobile'] ?? '') ?: null, 'email' => ($f['email'] ?? '') ?: null,
            'billing_address' => ($f['billing_address'] ?? '') ?: null, 'install_address' => mb_substr($f['install_address'], 0, 255), 'landmark' => ($f['landmark'] ?? '') ?: null,
            'area_id' => $areaId, 'branch_id' => !empty($f['branch_id']) ? (int)$f['branch_id'] : ($area['branch_id'] ?? null),
            'latitude' => ($f['latitude'] ?? '') !== '' ? (float)$f['latitude'] : null, 'longitude' => ($f['longitude'] ?? '') !== '' ? (float)$f['longitude'] : null,
            'plan_id' => (int)$plan['id'], 'agent_id' => !empty($f['agent_id']) ? (int)$f['agent_id'] : null, 'id_type' => ($f['id_type'] ?? '') ?: null, 'id_number' => ($f['id_number'] ?? '') ?: null,
            'coverage_ok' => $area ? (int)$area['is_serviceable'] : null, 'privacy_consent' => 1, 'preferred_date' => ($f['preferred_date'] ?? '') ?: null,
            'notes' => ($f['notes'] ?? '') ?: null, 'created_by' => Auth::id()];
        $id = DB::insert('isp_applications', $row);
        DB::insert('isp_application_log', ['application_id' => $id, 'from_status' => null, 'to_status' => 'new', 'remarks' => 'Application received (' . self::CHANNELS[$row['channel']] . ')', 'user_id' => Auth::id()]);
        self::notifyContact($row, 'application_received', ['application_no' => $row['application_no'], 'plan' => $plan['name']], 'APP-' . $id);
        Audit::log('create', 'isp', 'isp_application', $id, $row['application_no']);
        return $id;
    }

    /** A notice to an applicant who is not a subscriber yet (logged like every other message) */
    private static function notifyContact(array $app, string $code, array $vars, string $ref): void
    {
        $name = self::name($app + ['first_name' => null, 'middle_name' => null]);
        $vars += ['name' => $name, 'company' => Settings::get('company_name'), 'date' => fdate(today())];
        foreach (['sms' => $app['mobile'] ? IspNotify::normalizeMobile((string)$app['mobile']) : '', 'email' => filter_var((string)$app['email'], FILTER_VALIDATE_EMAIL) ? (string)$app['email'] : ''] as $ch => $to) {
            if ($to === '') {
                continue;
            }
            $tpl = DB::row('SELECT * FROM isp_notif_templates WHERE event_code = :e AND channel = :c AND is_active = 1', ['e' => $code, 'c' => $ch]);
            $key = mb_substr($code . ':' . $ref . ':' . $ch, 0, 120);
            if (!$tpl || DB::value('SELECT id FROM isp_notifications WHERE dedupe_key = :k', ['k' => $key])) {
                continue;
            }
            $ok = IspNotify::configured($ch);
            DB::insert('isp_notifications', ['event_code' => $code, 'channel' => $ch, 'recipient' => $to, 'subject' => $tpl['subject'] ? IspNotify::render((string)$tpl['subject'], $vars) : null,
                'body' => IspNotify::render((string)$tpl['body'], $vars), 'dedupe_key' => $key, 'status' => $ok ? 'queued' : 'skipped',
                'last_error' => $ok ? null : 'No ' . IspNotify::CHANNELS[$ch] . ' provider is configured: not sent.', 'next_try_at' => $ok ? date('Y-m-d H:i:s') : null]);
        }
    }

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::MOVES[$from] ?? [], true);
    }

    /** Moves the application (the conditions of each step are checked) */
    public static function move(int $id, string $to, string $remarks = ''): void
    {
        DB::tx(function () use ($id, $to, $remarks) {
            $a = DB::row('SELECT * FROM isp_applications WHERE id = :id FOR UPDATE', ['id' => $id]) ?? fail('Application not found.');
            if ($a['status'] === $to) {
                return;
            }
            if (!self::canMove($a['status'], $to)) {
                fail('Application ' . $a['application_no'] . ' cannot go from ' . self::STATUSES[$a['status']] . ' to ' . (self::STATUSES[$to] ?? $to) . '.');
            }
            if ($to === 'site_survey' && (int)$a['id_verified'] !== 1) {
                fail('Verify the applicant\'s identity first (ID type and number, checked against the ID presented).');
            }
            if ($to === 'approved') {
                $missing = [];
                if ((int)$a['coverage_ok'] !== 1) {
                    $missing[] = 'the service coverage';
                }
                if ((int)$a['survey_feasible'] !== 1) {
                    $missing[] = 'a feasible site survey';
                }
                $plan = IspPlans::get((int)$a['plan_id']);
                if ($plan['technology'] === 'fiber' && !$a['port_id']) {
                    $missing[] = 'a free NAP port reserved for the drop';
                }
                if ($a['quote_total'] === null) {
                    $missing[] = 'the installation quotation';
                }
                if (!$a['contract_accepted_at']) {
                    $missing[] = 'the applicant\'s acceptance of the contract';
                }
                if ($missing) {
                    fail('The application cannot be approved yet: it needs ' . implode(', ', $missing) . '.');
                }
            }
            if (in_array($to, ['rejected', 'cancelled'], true)) {
                if (trim($remarks) === '') {
                    fail('Enter the reason (it is kept with the application).');
                }
                if ($a['port_id']) {
                    DB::run("UPDATE isp_net_ports SET status = 'free', application_id = NULL WHERE id = :p AND status = 'reserved'", ['p' => $a['port_id']]);
                }
            }
            $set = ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')];
            if ($to === 'rejected' || $to === 'cancelled') {
                $set['rejected_reason'] = mb_substr($remarks, 0, 255);
            }
            DB::update('isp_applications', $set, 'id = :id', ['id' => $id]);
            DB::insert('isp_application_log', ['application_id' => $id, 'from_status' => $a['status'], 'to_status' => $to, 'remarks' => mb_substr($remarks, 0, 255) ?: null, 'user_id' => Auth::id()]);
            if ($to === 'rejected' && $a['work_order_id']) {
                IspWorkOrders::cancel((int)$a['work_order_id'], 'Application rejected: ' . $remarks);
            }
        });
    }

    public static function verifyIdentity(int $id, string $type, string $number): void
    {
        if (trim($type) === '' || trim($number) === '') {
            fail('Enter the ID presented and its number.');
        }
        DB::update('isp_applications', ['id_type' => mb_substr($type, 0, 40), 'id_number' => mb_substr($number, 0, 60), 'id_verified' => 1], 'id = :id', ['id' => $id]);
        $a = self::get($id);
        if ($a['status'] === 'new') {
            self::move($id, 'for_verification', 'Identity documents received');
        }
        self::move($id, 'site_survey', 'Identity verified: ' . $type);
    }

    /** The site survey: coverage, feasibility, the NAP port (reserved), the estimated cable length and the quotation */
    public static function survey(int $id, array $f): void
    {
        DB::tx(function () use ($id, $f) {
            $a = DB::row('SELECT * FROM isp_applications WHERE id = :id FOR UPDATE', ['id' => $id]) ?? fail('Application not found.');
            if (!in_array($a['status'], ['site_survey', 'for_verification'], true)) {
                fail('The site survey is recorded while the application is at the survey step.');
            }
            $plan = IspPlans::get((int)$a['plan_id']);
            $inst = ($f['quote_installation'] ?? '') === '' ? (float)$plan['installation_fee'] : (float)$f['quote_installation'];
            $mat = (float)($f['quote_materials'] ?? 0);
            $dep = ($f['quote_deposit'] ?? '') === '' ? (float)$plan['equipment_deposit'] : (float)$f['quote_deposit'];
            DB::update('isp_applications', ['coverage_ok' => (int)!empty($f['coverage_ok']), 'survey_feasible' => (int)!empty($f['survey_feasible']), 'survey_notes' => ($f['survey_notes'] ?? '') ?: null,
                'survey_date' => ($f['survey_date'] ?? '') ?: today(), 'surveyed_by' => Auth::id(), 'est_cable_m' => ($f['est_cable_m'] ?? '') === '' ? null : (float)$f['est_cable_m'],
                'quote_installation' => r2($inst), 'quote_materials' => r2($mat), 'quote_deposit' => r2($dep), 'quote_total' => r2($inst + $mat + $dep),
                'latitude' => ($f['latitude'] ?? '') !== '' ? (float)$f['latitude'] : $a['latitude'], 'longitude' => ($f['longitude'] ?? '') !== '' ? (float)$f['longitude'] : $a['longitude']], 'id = :id', ['id' => $id]);
            if (!empty($f['port_id'])) {
                IspInfra::reservePort((int)$f['port_id'], $id);
                DB::run('UPDATE isp_applications SET port_id = :p WHERE id = :id', ['p' => (int)$f['port_id'], 'id' => $id]);
            }
            DB::insert('isp_application_log', ['application_id' => $id, 'from_status' => $a['status'], 'to_status' => $a['status'],
                'remarks' => 'Site survey: ' . (!empty($f['survey_feasible']) ? 'feasible' : 'NOT feasible') . ($f['survey_notes'] ?? '' ? ' - ' . mb_substr((string)$f['survey_notes'], 0, 150) : ''), 'user_id' => Auth::id()]);
        });
    }

    public static function acceptContract(int $id, string $name): void
    {
        if (trim($name) === '') {
            fail('Enter the name of the person accepting the contract.');
        }
        DB::update('isp_applications', ['contract_accepted_at' => date('Y-m-d H:i:s'), 'contract_accepted_name' => mb_substr(trim($name), 0, 160)], 'id = :id', ['id' => $id]);
        DB::insert('isp_application_log', ['application_id' => $id, 'from_status' => null, 'to_status' => (string)DB::value('SELECT status FROM isp_applications WHERE id = :i', ['i' => $id]),
            'remarks' => 'Contract terms accepted by ' . trim($name) . ' (' . client_ip() . ')', 'user_id' => Auth::id()]);
    }

    /**
     * Approves the application: the subscriber (on the Party Master, a member keeps the same party), the pending account
     * with the reserved port, and the installation work order; the application is then For Installation.
     */
    public static function approve(int $id, string $remarks = ''): array
    {
        return DB::tx(function () use ($id, $remarks) {
            $a = DB::row('SELECT * FROM isp_applications WHERE id = :id FOR UPDATE', ['id' => $id]) ?? fail('Application not found.');
            self::move($id, 'approved', $remarks ?: 'Approved');
            $name = self::name($a);
            $cust = $a['isp_customer_id'] ? (int)$a['isp_customer_id'] : IspAccounts::ensureCustomer($a['party_id'] ? (int)$a['party_id'] : null, [
                'name' => $name, 'classification' => $a['classification'], 'mobile' => $a['mobile'], 'email' => $a['email'], 'billing_address' => $a['billing_address'] ?: $a['install_address'],
                'install_address' => $a['install_address'], 'last_name' => $a['last_name'], 'first_name' => $a['first_name'], 'middle_name' => $a['middle_name'], 'business_name' => $a['business_name'],
                'id_type' => $a['id_type'], 'id_number' => $a['id_number'], 'privacy_consent' => 1]);
            DB::run('UPDATE isp_customers SET id_verified_at = COALESCE(id_verified_at, :t), id_verified_by = COALESCE(id_verified_by, :u) WHERE id = :c', ['t' => date('Y-m-d H:i:s'), 'u' => Auth::id(), 'c' => $cust]);
            $acct = IspAccounts::open($cust, ['plan_id' => (int)$a['plan_id'], 'install_address' => $a['install_address'], 'landmark' => $a['landmark'], 'area_id' => $a['area_id'],
                'branch_id' => $a['branch_id'], 'latitude' => $a['latitude'], 'longitude' => $a['longitude'], 'agent_id' => $a['agent_id'], 'application_id' => $id,
                'security_deposit' => $a['quote_deposit'], 'account_name' => $name]);
            if ($a['port_id']) {
                IspInfra::assignPort((int)$a['port_id'], $acct, $id);
            }
            $wo = IspWorkOrders::create(['wo_type' => 'installation', 'account_id' => $acct, 'application_id' => $id, 'description' => 'Install ' . IspPlans::get((int)$a['plan_id'])['name'] . ' for ' . $name,
                'address' => $a['install_address'], 'area_id' => $a['area_id'], 'priority' => 'normal', 'scheduled_date' => $a['preferred_date']]);
            DB::update('isp_applications', ['isp_customer_id' => $cust, 'account_id' => $acct, 'work_order_id' => $wo, 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            self::move($id, 'for_installation', 'Installation work order created');
            IspNotify::event('application_approved', $acct, ['application_no' => $a['application_no']], 'APPOK-' . $id);
            Audit::log('approve', 'isp', 'isp_application', $id, (string)$a['application_no'], ['reason' => $remarks]);
            return ['customer_id' => $cust, 'account_id' => $acct, 'work_order_id' => $wo];
        });
    }
}
