<?php
declare(strict_types=1);

/**
 * Set-up of the cable / internet service provider system: its settings, accounts, line of business, number series,
 * roles and page access, disconnection rules, notification templates and scheduled jobs.
 *
 * installDefaults() is safe to run again: it only adds what is missing (tools/install.php runs it after the
 * migration; ISP Setup > Billing & Disconnection Policies has a button for it).
 */
final class IspSetup
{
    /** setting => default (stored in the settings table) */
    public const SETTINGS = [
        // tax and documents (Philippines)
        'isp_vat_registered'      => '1',
        'isp_vat_rate'            => '12',
        'isp_invoice_title'       => 'SERVICE INVOICE',
        'isp_receipt_title'       => 'COLLECTION RECEIPT',
        'isp_ack_title'           => 'PAYMENT ACKNOWLEDGMENT (not an invoice)',
        'isp_bir_atp'             => '',      // Authority to Print / CAS permit no., printed on the invoice
        'isp_bir_series'          => '',      // the approved serial range, printed on the invoice
        'isp_invoice_footer'      => "Please pay on or before the due date to avoid service interruption.\nThis document is system-generated.",
        'isp_payment_instructions'=> "Pay at any of our offices, by GCash / Maya, or by bank transfer. Use your account number as the reference.",
        'isp_privacy_notice'      => 'Your personal data is processed by the cooperative for your subscription, billing and service support only, as allowed by the Data Privacy Act of 2012 (RA 10173).',
        // billing policy
        'isp_default_cycle_day'   => '1',
        'isp_due_days'            => '15',
        'isp_billing_basis'       => 'calendar',   // calendar / anniversary
        'isp_bill_timing'         => 'advance',    // advance / arrears
        'isp_prorate_first'       => '1',
        'isp_prorate_changes'     => '1',          // upgrades / downgrades mid-cycle are prorated
        'isp_bill_lead_days'      => '0',          // an advance invoice is issued this many days before the period starts
        'isp_runs_need_approval'  => '1',          // a billing run is approved before it posts
        'isp_auto_apply_credit'   => '1',          // advance payments are applied to new invoices when they post
        'isp_late_fee_after_days' => '0',          // days after the due date before the late charge is assessed
        'isp_reminder_days'       => '3',          // the due-date reminder goes this many days before the due date
        'isp_overdue_reminder_days' => '2',        // the overdue reminder goes this many days after the due date
        // service control
        'isp_auto_reconnect'      => '1',
        'isp_recon_fee_policy'    => 'next_bill',  // next_bill (charged on the next invoice) / prepay (must be paid first) / none
        'isp_partial_recon_pct'   => '100',        // the share of the qualifying arrears that must be paid to reconnect
        'isp_temp_recon_hours'    => '72',
        'isp_disc_retry_minutes'  => '15',
        // support
        'isp_sla_critical_hours'  => '4',
        'isp_sla_high_hours'      => '8',
        'isp_sla_normal_hours'    => '24',
        'isp_sla_low_hours'       => '72',
        'isp_escalate_after_hours'=> '24',
        // portal
        'isp_portal_enabled'      => '1',
        'isp_portal_proof_upload' => '1',
        'isp_portal_self_register'=> '1',
        // messages (credentials are encrypted with IspCrypto)
        'isp_sms_provider'        => 'none',       // none / semaphore / generic_http
        'isp_sms_api_url'         => '',
        'isp_sms_api_key_enc'     => '',
        'isp_sms_sender'          => '',
        'isp_smtp_host'           => '',
        'isp_smtp_port'           => '587',
        'isp_smtp_secure'         => 'tls',        // tls / ssl / none
        'isp_smtp_user'           => '',
        'isp_smtp_pass_enc'       => '',
        'isp_mail_from'           => '',
        'isp_mail_from_name'      => '',
        // payment gateway
        'isp_gateway_provider'    => 'none',       // none / paymongo / xendit / generic
        'isp_gateway_mode'        => 'test',
        'isp_gateway_secret_enc'  => '',
        'isp_gateway_webhook_secret_enc' => '',
        'isp_gateway_checkout_url'=> '',
        'isp_gateway_fee_pct'     => '0',
        'isp_gateway_fee_fixed'   => '0',
        // numbering and set-up
        'isp_account_prefix'      => '',           // e.g. a branch letter in front of the account number
        'isp_stock_site_id'       => '',           // the network stockroom the field materials are issued from
        'isp_business_unit_id'    => '',
        'isp_defaults_version'    => '',
        'isp_map_tiles'           => '',           // a tile server URL template ({z}/{x}/{y}); empty: the plain grid map
    ];

    /** number series: key => [prefix, pad] */
    public const SEQUENCES = [
        'isp_customer' => ['CUS-', 6], 'isp_account' => ['', 7], 'isp_application' => ['APP-', 6], 'isp_invoice' => ['SI-', 7],
        'isp_receipt' => ['CR-', 7], 'isp_adjustment' => ['IAJ-', 6], 'isp_dispute' => ['DSP-', 5], 'isp_arrangement' => ['PA-', 5],
        'isp_wo' => ['WO-', 6], 'isp_ticket' => ['TKT-', 6], 'isp_outage' => ['OUT-', 5], 'isp_disc' => ['DQ-', 6], 'isp_recon' => ['RCN-', 6],
        'isp_netop' => ['NOP-', 7], 'isp_run' => ['BR-', 5], 'isp_contract' => ['CT-', 6], 'isp_shift' => ['SH-', 6], 'isp_request' => ['REQ-', 6],
    ];

    /** default account key => [code, name, account type, category, normal balance] made when the code is free */
    public const ACCOUNTS = [
        'isp_ar'               => ['1105', 'Accounts Receivable - Subscribers', 'Accounts Receivable', 'Asset', 'Debit'],
        'isp_cash'             => ['1003', 'Cash on Hand - Subscriber Collections', 'Bank', 'Asset', 'Debit'],
        'isp_bank_clearing'    => ['1052', 'Bank Deposits and Transfers Clearing', 'Other Current Asset', 'Asset', 'Debit'],
        'isp_ewallet_clearing' => ['1066', 'GCash / Maya / Gateway Clearing', 'Other Current Asset', 'Asset', 'Debit'],
        'isp_autodebit_clearing' => ['1067', 'Savings Auto-Debit Clearing', 'Other Current Asset', 'Asset', 'Debit'],
        'isp_deposits'         => ['2160', 'Subscriber Security Deposits', 'Other Current Liability', 'Liability', 'Credit'],
        'isp_revenue'          => ['4600', 'Internet Service Revenue', 'Income', 'Income', 'Credit'],
        'isp_catv_revenue'     => ['4605', 'Cable TV Service Revenue', 'Income', 'Income', 'Credit'],
        'isp_install_revenue'  => ['4610', 'Installation and Activation Revenue', 'Income', 'Income', 'Credit'],
        'isp_rental_revenue'   => ['4620', 'Equipment Rental and Add-on Revenue', 'Income', 'Income', 'Credit'],
        'isp_late_fee_revenue' => ['4630', 'Late Payment Charges', 'Income', 'Income', 'Credit'],
        'isp_reconnect_revenue'=> ['4640', 'Reconnection Fees', 'Income', 'Income', 'Credit'],
        'isp_other_revenue'    => ['4650', 'Other Subscriber Charges', 'Income', 'Income', 'Credit'],
        'isp_discounts'        => ['4660', 'Subscriber Discounts and Promotions', 'Income', 'Income', 'Debit'],
        'isp_service_credits'  => ['4670', 'Service Credits - Outages and Adjustments', 'Income', 'Income', 'Debit'],
        'isp_install_expense'  => ['5300', 'Installation Materials and Cost of Service', 'Cost of Goods Sold', 'Expense', 'Debit'],
        'isp_maint_expense'    => ['5310', 'Network Repairs and Maintenance Materials', 'Cost of Goods Sold', 'Expense', 'Debit'],
        'isp_labor_expense'    => ['5320', 'Field Labor and Travel', 'Cost of Goods Sold', 'Expense', 'Debit'],
        'isp_gateway_fees'     => ['6085', 'Payment Gateway Fees', 'Expense', 'Expense', 'Debit'],
        'isp_cash_over_short'  => ['6205', 'Cashier Over and Short - Subscriber Collections', 'Expense', 'Expense', 'Debit'],
        'isp_bad_debts'        => ['6300', 'Bad Debts - Subscriber Write-offs', 'Expense', 'Expense', 'Debit'],
        'isp_commission_expense' => ['6310', 'Sales Agent Commissions', 'Expense', 'Expense', 'Debit'],
    ];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                DB::value('SELECT COUNT(*) FROM isp_accounts WHERE 1 = 0');
                DB::value('SELECT COUNT(*) FROM isp_postings WHERE 1 = 0');
                self::$ready = true;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function reset(): void
    {
        self::$ready = null;
    }

    public static function get(string $key): string
    {
        return Settings::get($key, self::SETTINGS[$key] ?? '');
    }

    public static function int(string $key): int
    {
        return (int)self::get($key);
    }

    public static function float(string $key): float
    {
        return (float)self::get($key);
    }

    public static function vatRegistered(): bool
    {
        return self::get('isp_vat_registered') === '1';
    }

    public static function vatRate(): float
    {
        return self::vatRegistered() ? max(0.0, self::float('isp_vat_rate')) : 0.0;
    }

    /** The Cable & Internet line of business */
    public static function unitId(): ?int
    {
        $id = (int)self::get('isp_business_unit_id');
        if ($id <= 0 && BusinessUnits::ready()) {
            $id = (int)(DB::value("SELECT id FROM business_units WHERE code = 'CI'") ?: 0);
        }
        return $id > 0 ? $id : null;
    }

    /** A default account of the ISP module (Financial > Module Default Accounts > Cable & Internet) */
    public static function account(string $key): int
    {
        return Accts::get($key);
    }

    /** The class a branch posts under (coop_branches.class_id) */
    public static function classOfBranch(?int $branchId): ?int
    {
        if (!$branchId) {
            return null;
        }
        $c = DB::value('SELECT class_id FROM coop_branches WHERE id = :b', ['b' => $branchId]);
        return $c ? (int)$c : null;
    }

    /** The branch the logged-in user works in, null when the user sees every branch */
    public static function userBranch(): ?int
    {
        $u = Auth::user();
        if (!$u || empty($u['coop_branch_id']) || Access::isAdmin() || Access::special('coop_all_branches')) {
            return null;
        }
        return (int)$u['coop_branch_id'];
    }

    /** " AND <col> = :ub" limiting a list to the user's branch (nothing for users who see every branch) */
    public static function branchSql(string $col, array &$params): string
    {
        $b = self::userBranch();
        if ($b === null) {
            return '';
        }
        $params['ub'] = $b;
        return " AND ($col = :ub OR $col IS NULL)";
    }

    public static function branches(): array
    {
        try {
            return DB::pairs('SELECT id, name FROM coop_branches WHERE is_active = 1 ORDER BY is_head_office DESC, name');
        } catch (Throwable $e) {
            return [];
        }
    }

    /** The technician record of the logged-in user (technicians see their own jobs) */
    public static function myTechnicianId(): ?int
    {
        $id = Auth::id();
        if (!$id) {
            return null;
        }
        $t = DB::value('SELECT id FROM isp_technicians WHERE user_id = :u AND is_active = 1', ['u' => $id]);
        return $t ? (int)$t : null;
    }

    /** Runs $fn as the scheduler's own account (a background job has no user of its own) */
    public static function asSystem(callable $fn)
    {
        $prev = Auth::id();
        $sys = (int)(DB::value("SELECT id FROM users WHERE username = 'isp.scheduler'") ?: 0);
        if (!$sys) {
            $sys = self::systemUser();
        }
        // the scheduler's account is locked against log-ins; it is accepted as the acting user only inside this call
        $wasSystem = Auth::$systemRun;
        Auth::$systemRun = true;
        Auth::actAs($sys);
        try {
            return $fn();
        } finally {
            Auth::$systemRun = $wasSystem;
            if ($prev) {
                Auth::actAs($prev);
            } else {
                // nobody was logged in (cron, a webhook): nothing of the job's identity stays in the session
                unset($_SESSION['user_id']);
                Auth::flush();
                Access::reset();
            }
        }
    }

    // ------------------------------------------------------------------ installing the defaults

    /** Adds every missing default; returns what it did (lines for the installer) */
    public static function installDefaults(): array
    {
        if (!self::ready()) {
            fail('The ISP tables are not installed yet. Run database/migrations/2027_04_01_isp_cable_system.sql first.');
        }
        $log = [];
        DB::tx(function () use (&$log) {
            $log[] = self::installSettings();
            $log[] = self::installSequences();
            $log[] = self::installPages();
            $log[] = self::installAccounts();
            $log[] = self::installBusinessUnit();
            $log[] = self::installRoles();
            $log[] = self::installRules();
            $log[] = self::installTemplates();
            $log[] = self::installJobs();
            $log[] = self::installContractTemplate();
            $log[] = self::installItemCategories();
            self::systemUser();
            Settings::set('isp_defaults_version', '2027-04-01');
        });
        Settings::flush();
        Accts::reset();
        Access::reset();
        return array_values(array_filter($log));
    }

    private static function installSettings(): string
    {
        $n = 0;
        foreach (self::SETTINGS as $k => $v) {
            if (DB::value('SELECT setting_key FROM settings WHERE setting_key = :k', ['k' => $k]) === null) {
                DB::insert('settings', ['setting_key' => $k, 'setting_value' => $v]);
                $n++;
            }
        }
        Settings::flush();
        return $n ? "$n ISP settings" : '';
    }

    private static function installSequences(): string
    {
        $n = 0;
        foreach (self::SEQUENCES as $k => [$prefix, $pad]) {
            if (!DB::value('SELECT seq_key FROM sequences WHERE seq_key = :k', ['k' => $k])) {
                DB::insert('sequences', ['seq_key' => $k, 'prefix' => $prefix, 'next_no' => $k === 'isp_account' ? 1000001 : 1, 'pad' => $pad]);
                $n++;
            }
        }
        return $n ? "$n number series" : '';
    }

    /** The modules and pages of app/IspPages.php (a new install has them from the seeder already) */
    private static function installPages(): string
    {
        $n = 0;
        foreach (IspPages::MODULES as $code => [$name, $icon, $sort]) {
            if (!DB::value('SELECT id FROM modules WHERE code = :c', ['c' => $code])) {
                DB::insert('modules', ['code' => $code, 'name' => $name, 'icon' => $icon, 'sort_order' => $sort]);
            }
        }
        $sort = (int)DB::value('SELECT COALESCE(MAX(sort_order), 0) FROM pages');
        $pages = IspPages::registry();
        // the dashboard last: the database probe of app/Schema.php waits for it
        $dash = ['isp.dashboard' => $pages['isp.dashboard']];
        unset($pages['isp.dashboard']);
        foreach ($pages + $dash as $key => [$module, $name, $type, $group, , , $menu]) {
            $mid = (int)DB::value('SELECT id FROM modules WHERE code = :c', ['c' => $module]);
            $pid = DB::value('SELECT id FROM pages WHERE page_key = :k', ['k' => $key]);
            if ($pid) {
                DB::update('pages', ['module_id' => $mid, 'name' => $name, 'page_type' => $type, 'menu_group' => $group, 'show_in_menu' => $menu], 'id = :id', ['id' => $pid]);
            } else {
                DB::insert('pages', ['page_key' => $key, 'module_id' => $mid, 'name' => $name, 'page_type' => $type, 'menu_group' => $group, 'sort_order' => $sort += 10, 'show_in_menu' => $menu]);
                $n++;
            }
        }
        return $n ? "$n pages" : '';
    }

    private static function installAccounts(): string
    {
        $made = 0;
        foreach (self::ACCOUNTS as $key => [$code, $name, $type, $cat, $normal]) {
            $id = (int)(DB::value('SELECT account_id FROM default_accounts WHERE setting_key = :k', ['k' => $key]) ?: 0);
            if ($id) {
                continue;
            }
            // an account of the same name is the one; a code taken by another account (the cooperative's own 44xx
            // income, a chart of the user's) is never reused: the next free code is taken instead
            $id = (int)(DB::value('SELECT id FROM accounts WHERE name = :n', ['n' => $name]) ?: 0);
            while (!$id && DB::value('SELECT id FROM accounts WHERE code = :c', ['c' => $code])) {
                $code = (string)((int)$code + 1);
            }
            if (!$id) {
                $id = DB::insert('accounts', ['code' => $code, 'name' => $name, 'account_type' => $type, 'category' => $cat, 'normal_balance' => $normal,
                    'description' => 'Cable & Internet (ISP module)', 'is_active' => 1, 'is_system' => in_array($key, ['isp_ar', 'isp_deposits'], true) ? 1 : 0]);
                $made++;
            }
            $label = Accts::KEYS[$key][0] ?? $name;
            if (DB::value('SELECT setting_key FROM default_accounts WHERE setting_key = :k', ['k' => $key])) {
                DB::run('UPDATE default_accounts SET account_id = :a WHERE setting_key = :k', ['a' => $id, 'k' => $key]);
            } else {
                DB::insert('default_accounts', ['setting_key' => $key, 'label' => $label, 'module' => 'isp', 'account_id' => $id]);
            }
        }
        // the subscriber receivable keeps a subsidiary ledger by name (the A/R account type does it by customer already)
        Accts::reset();
        return $made ? "$made accounts in the chart of accounts" : '';
    }

    private static function installBusinessUnit(): string
    {
        if (!BusinessUnits::ready()) {
            return '';
        }
        $id = (int)(DB::value("SELECT id FROM business_units WHERE code = 'CI'") ?: 0);
        $made = false;
        if (!$id) {
            $id = DB::insert('business_units', ['code' => 'CI', 'name' => 'Cable & Internet Services', 'kind' => 'service', 'nonmember_policy' => 'allow',
                'patronage_eligible' => 1, 'description' => 'Cable TV and internet subscriptions of members and the public', 'sort_order' => 3]);
            $made = true;
        }
        Settings::set('isp_business_unit_id', (string)$id);
        BusinessUnits::reset();
        return $made ? 'the Cable & Internet Services line of business' : '';
    }

    // ------------------------------------------------------------------ roles and page access

    /**
     * The roles of the ISP operation and what each opens. Pattern keys: an exact page key, "module:*",
     * "module:report", "module:!report", "group:<menu group>". Letters: C create, E edit, D delete/void/reverse,
     * P print/export, A approve/post.
     */
    public static function roleDefs(): array
    {
        $reportsAll = ['isp:report' => 'P', 'ispbill:report' => 'P', 'ispnet:report' => 'P', 'ispfield:report' => 'P'];
        return [
            'Branch Manager' => [
                'desc' => 'Everything of the branch: cooperative, subscribers, billing approvals, collections, disconnection approvals, field service and the branch reports.',
                'pages' => ['home:*' => 'P', 'isp:*' => 'CEDPA', 'ispbill:*' => 'CEDPA', 'ispnet:*' => 'CEPA', 'ispfield:*' => 'CEDPA', 'ispset.jobs' => 'P'] + $reportsAll,
                'special' => ['isp_post_billing' => '1', 'isp_reverse_payment' => '1', 'isp_write_off' => '1', 'isp_max_adjustment' => '20000', 'isp_disconnect' => '1',
                    'isp_reconnect' => '1', 'isp_exempt' => '1', 'isp_view_sensitive' => '1'],
            ],
            'General Manager' => [
                'desc' => 'Every cooperative page and every cable / internet page with approval rights, and every report.',
                'pages' => ['isp:*' => 'CEDPA', 'ispbill:*' => 'CEDPA', 'ispnet:*' => 'CEDPA', 'ispfield:*' => 'CEDPA', 'ispset:*' => 'CEDPA'],
                'special' => ['isp_post_billing' => '1', 'isp_reverse_payment' => '1', 'isp_write_off' => '1', 'isp_max_adjustment' => '', 'isp_disconnect' => '1',
                    'isp_reconnect' => '1', 'isp_exempt' => '1', 'isp_view_sensitive' => '1', 'isp_network_admin' => '1'],
            ],
            'Billing Officer' => [
                'desc' => 'Billing runs (preview and generation), invoices, adjustments to request, statements of account, disputes and payment arrangements to record, and the billing reports.',
                'pages' => ['home.dashboard' => '', 'home.reports' => 'P', 'isp.dashboard' => 'P', 'isp.subscribers' => 'P', 'isp.account' => 'EP', 'isp.contracts' => 'P', 'isp.requests' => 'CEP',
                    'ispbill.runs' => 'CEP', 'ispbill.invoices' => 'CEP', 'ispbill.adjustments' => 'CEP', 'ispbill.disputes' => 'CEP', 'ispbill.soa' => 'P', 'ispbill.payments' => 'P',
                    'group:Billing Reports' => 'P', 'group:Collection Reports' => 'P', 'rpt.isp_master' => 'P', 'rpt.isp_plan_dist' => 'P', 'ispnet.disconnections' => 'P'],
                'special' => ['isp_max_adjustment' => '1000'],
            ],
            'Cashier' => [
                'desc' => 'Collects subscriber payments in an open cashier shift, prints collection receipts, counts the cash at the end of the shift; also the POS register at the assigned terminal.',
                'pages' => ['isp.subscribers' => 'P', 'isp.account' => 'P', 'ispbill.collect' => 'CP', 'ispbill.payments' => 'CP', 'ispbill.shifts' => 'CP', 'ispbill.soa' => 'P',
                    'rpt.isp_daily_collection' => 'P', 'rpt.isp_cashier_collection' => 'P'],
                'special' => [],
            ],
            'Collection Officer' => [
                'desc' => 'Follows up overdue subscribers: verifies bank / e-wallet payment references, records field collections, payment arrangements and collection notes; sees the aging, delinquency and disconnection lists.',
                'pages' => ['home.dashboard' => '', 'isp.dashboard' => 'P', 'isp.subscribers' => 'P', 'isp.account' => 'EP', 'ispbill.collect' => 'CP', 'ispbill.payments' => 'CEP', 'ispbill.shifts' => 'CP',
                    'ispbill.disputes' => 'CEP', 'ispbill.soa' => 'P', 'ispnet.disconnections' => 'P', 'ispnet.recovery' => 'CEP', 'group:Collection Reports' => 'P', 'rpt.isp_unpaid' => 'P',
                    'rpt.isp_due_disconnect' => 'P', 'rpt.isp_disc_outstanding' => 'P'],
                'special' => [],
            ],
            'Accountant' => [
                'desc' => 'The general ledger, banking, the subscriber receivables and their reconciliation with the ledger, adjustments and write-offs to approve, cashier shift certification and every report.',
                'pages' => ['isp.dashboard' => 'P', 'isp.subscribers' => 'P', 'isp.account' => 'P', 'ispbill.runs' => 'PA', 'ispbill.invoices' => 'P', 'ispbill.payments' => 'PA', 'ispbill.shifts' => 'PA',
                    'ispbill.adjustments' => 'EPA', 'ispbill.disputes' => 'P', 'ispbill.soa' => 'P', 'isp.agents' => 'PA', 'ispset.policies' => 'P', 'ispset.gateways' => 'P'] + $reportsAll,
                'special' => ['isp_post_billing' => '1', 'isp_write_off' => '1', 'isp_max_adjustment' => '', 'isp_reverse_payment' => '1'],
            ],
            'Network Administrator' => [
                'desc' => 'Network devices and integrations (MikroTik, RADIUS, OLT, TR-069), provisioning, the network operations queue and retries, disconnections and reconnections, infrastructure and the network map, outages.',
                'pages' => ['home.dashboard' => '', 'isp.dashboard' => 'P', 'isp.subscribers' => 'P', 'isp.account' => 'EP', 'ispnet:*' => 'CEDPA', 'ispfield.workorders' => 'CEP', 'ispfield.tickets' => 'CEP',
                    'ispfield.equipment' => 'P', 'ispset.jobs' => 'CEP', 'group:Technical Reports' => 'P', 'group:Disconnection Reports' => 'P'],
                'special' => ['isp_network_admin' => '1', 'isp_disconnect' => '1', 'isp_reconnect' => '1', 'isp_view_sensitive' => '1'],
            ],
            'Technical Supervisor' => [
                'desc' => 'Dispatches and verifies work orders, assigns technicians, approves installations and equipment replacements, manages tickets, outages and the field reports.',
                'pages' => ['home.dashboard' => '', 'isp.dashboard' => 'P', 'isp.subscribers' => 'P', 'isp.account' => 'EP', 'isp.applications' => 'CEPA', 'isp.requests' => 'CEP',
                    'ispfield:*' => 'CEDPA', 'ispnet.infra' => 'CEP', 'ispnet.map' => 'P', 'ispnet.outages' => 'CEPA', 'ispnet.operations' => 'P', 'ispnet.recovery' => 'CEP', 'ispnet.reconnections' => 'CP',
                    'group:Technical Reports' => 'P', 'inv.center' => 'P', 'inv.transfers' => 'CP', 'rpt.qoh_by_site' => 'P'],
                'special' => ['isp_reconnect' => '1'],
            ],
            'Installation Technician' => [
                'desc' => 'Field installer: own task list, accepts and completes installation work orders, records materials used, serial numbers, signal readings, photos and the subscriber acceptance. No billing or financial pages.',
                'pages' => ['ispfield.mytasks' => 'CEP', 'ispfield.workorders' => 'EP', 'ispnet.map' => 'P'],
                'special' => [],
            ],
            'Maintenance Technician' => [
                'desc' => 'Field repairs: own task list, repair, relocation, recovery and replacement work orders and the tickets assigned, materials used and returned. No billing or financial pages.',
                'pages' => ['ispfield.mytasks' => 'CEP', 'ispfield.workorders' => 'EP', 'ispfield.tickets' => 'EP', 'ispnet.map' => 'P', 'ispnet.infra' => 'P'],
                'special' => [],
            ],
            'Customer Service Representative' => [
                'desc' => 'Front desk and hotline: subscriber look-up, applications, support tickets, service requests, statements of account, announcements; no collections, adjustments or network commands.',
                'pages' => ['home.dashboard' => '', 'isp.dashboard' => 'P', 'isp.subscribers' => 'P', 'isp.account' => 'EP', 'isp.applications' => 'CEP', 'isp.contracts' => 'P', 'isp.requests' => 'CEP',
                    'ispbill.soa' => 'P', 'ispbill.invoices' => 'P', 'ispbill.payments' => 'P', 'ispbill.disputes' => 'CP', 'ispfield.tickets' => 'CEP', 'ispnet.outages' => 'P', 'ispset.templates' => 'CEP',
                    'rpt.isp_master' => 'P', 'rpt.isp_notif_log' => 'P'],
                'special' => [],
            ],
            'Inventory Custodian' => [
                'desc' => 'The network stockroom: equipment and material items, receiving, transfers to technicians, returns, damaged stock, counts, serial numbers and the inventory reports.',
                'pages' => ['home.dashboard' => '', 'inv.center' => 'P', 'inv.items' => 'CEP', 'inv.categories' => 'CEP', 'inv.sites' => 'CEP', 'inv.transfers' => 'CEP', 'inv.adjust' => 'CEP',
                    'inv.physical_count' => 'P', 'vend.receive_items' => 'CEP', 'vend.purchase_orders' => 'CP', 'ispfield.equipment' => 'CEDP', 'ispfield.workorders' => 'P',
                    'inv:report' => 'P', 'rpt.isp_materials' => 'P', 'rpt.isp_equipment_failures' => 'P'],
                'special' => ['view_costs' => '1'],
            ],
            'Sales Agent' => [
                'desc' => 'Signs up subscribers: new applications (own), checks coverage and plans, follows the status of the applications and sees own commissions.',
                'pages' => ['isp.applications' => 'CP', 'isp.agents' => 'P'],
                'special' => [],
            ],
            'Auditor' => [
                'desc' => 'View-only access to all transactions, reports, and the audit trail. Cannot create or change anything.',
                'pages' => ['isp:*' => 'P', 'ispbill:*' => 'P', 'ispnet:*' => 'P', 'ispfield:*' => 'P', 'ispset:*' => 'P'],
                'deny' => ['ispbill.collect', 'ispbill.shifts', 'ispfield.mytasks'],
                'special' => ['isp_view_sensitive' => '0'],
            ],
        ];
    }

    /** Page keys matching a pattern of roleDefs() */
    public static function selectPages(string $pattern): array
    {
        $defs = Access::allPageDefs();
        if (isset($defs[$pattern])) {
            return [$pattern];
        }
        [$a, $b] = explode(':', $pattern, 2) + [1 => '*'];
        $out = [];
        foreach ($defs as $k => $d) {
            if ($a === 'group') {
                if ($d['group'] === $b) {
                    $out[] = $k;
                }
                continue;
            }
            if ($d['module'] !== $a) {
                continue;
            }
            if ($b === '*' || ($b === 'report' && $d['type'] === 'report') || ($b === '!report' && $d['type'] !== 'report')) {
                $out[] = $k;
            }
        }
        return $out;
    }

    private static function installRoles(): string
    {
        $pageIds = DB::pairs('SELECT page_key, id FROM pages');
        $first = (string)DB::value("SELECT setting_value FROM settings WHERE setting_key = 'isp_defaults_version'") === '';
        $made = 0;
        foreach (self::roleDefs() as $name => $def) {
            $rid = (int)(DB::value('SELECT id FROM roles WHERE name = :n', ['n' => $name]) ?: 0);
            if (!$rid) {
                $rid = DB::insert('roles', ['name' => $name, 'description' => $def['desc'], 'is_admin' => 0, 'is_system' => 1, 'all_sites' => 1]);
                $made++;
            } elseif (in_array($name, ['Billing Officer', 'Collection Officer', 'Network Administrator', 'Technical Supervisor', 'Installation Technician',
                'Maintenance Technician', 'Customer Service Representative', 'Inventory Custodian', 'Sales Agent', 'Cashier'], true)) {
                DB::update('roles', ['description' => $def['desc']], 'id = :id', ['id' => $rid]);
            }
            $grant = [];
            foreach ($def['pages'] as $pattern => $letters) {
                foreach (self::selectPages($pattern) as $k) {
                    $grant[$k] = ($grant[$k] ?? '') . $letters;
                }
            }
            foreach ($def['deny'] ?? [] as $k) {
                unset($grant[$k]);
                // a role that already existed (the Auditor of the accounting system sees every page) loses the pages it
                // must not open, the first time the cable / internet defaults are installed only: later runs never
                // undo what the administrator gave since
                if ($first && isset($pageIds[$k])) {
                    DB::run('UPDATE role_page_access SET allowed = 0, can_create = 0, can_edit = 0, can_delete = 0, can_print = 0, can_approve = 0 WHERE role_id = :r AND page_id = :p',
                        ['r' => $rid, 'p' => $pageIds[$k]]);
                }
            }
            foreach ($grant as $k => $letters) {
                if (!isset($pageIds[$k])) {
                    continue;
                }
                $row = ['allowed' => 1, 'can_create' => (int)str_contains($letters, 'C'), 'can_edit' => (int)str_contains($letters, 'E'),
                    'can_delete' => (int)str_contains($letters, 'D'), 'can_print' => (int)str_contains($letters, 'P'), 'can_approve' => (int)str_contains($letters, 'A')];
                $have = DB::row('SELECT * FROM role_page_access WHERE role_id = :r AND page_id = :p', ['r' => $rid, 'p' => $pageIds[$k]]);
                if (!$have) {
                    DB::insert('role_page_access', ['role_id' => $rid, 'page_id' => $pageIds[$k]] + $row);
                }
            }
            foreach ($def['special'] as $k => $v) {
                if (!DB::value('SELECT role_id FROM role_special_permissions WHERE role_id = :r AND perm_key = :k', ['r' => $rid, 'k' => $k])) {
                    DB::insert('role_special_permissions', ['role_id' => $rid, 'perm_key' => $k, 'perm_value' => $v]);
                }
            }
        }
        return $made ? "$made roles" : '';
    }

    // ------------------------------------------------------------------ rules, templates, jobs

    private static function installRules(): string
    {
        $n = 0;
        foreach ([
            ['NONPAY', 'Overdue payment after the grace period', 'overdue', 5, 100, 1, 24, 1, 0, 'Accounts with unpaid invoices past their due date and grace period, above the minimum amount'],
            ['CONTRACT', 'Contract ended without renewal', 'contract_end', 0, 0, 0, 72, 1, 0, ''],
            ['REQUEST', 'Customer-requested suspension', 'customer_request', 0, 0, 0, 0, 0, 0, ''],
            ['FRAUD', 'Fraud or unauthorized connection', 'fraud', 0, 0, 0, 0, 1, 0, ''],
            ['TECH', 'Technical disconnection', 'technical', 0, 0, 0, 0, 1, 0, ''],
            ['ADMIN', 'Administrative disconnection', 'administrative', 0, 0, 0, 0, 1, 0, ''],
            ['CANCEL', 'Permanent cancellation', 'cancellation', 0, 0, 0, 0, 1, 0, ''],
        ] as [$code, $name, $type, $grace, $min, $minInv, $notice, $approval, $auto]) {
            if (!DB::value('SELECT id FROM isp_disc_rules WHERE code = :c', ['c' => $code])) {
                DB::insert('isp_disc_rules', ['code' => $code, 'name' => $name, 'rule_type' => $type, 'grace_days' => $grace, 'min_overdue_amount' => $min,
                    'min_overdue_invoices' => $minInv, 'final_notice_hours' => $notice, 'require_approval' => $approval, 'auto_execute' => $auto,
                    // contracts renew month to month unless the cooperative switches this rule on
                    'is_active' => $code === 'CONTRACT' ? 0 : 1]);
                $n++;
            }
        }
        return $n ? "$n disconnection rules" : '';
    }

    public const TEMPLATES = [
        'application_received' => ['Application received', 'Hi {name}, we received your application {application_no} for {plan}. We will contact you for the site survey. - {company}'],
        'application_approved' => ['Application approved', 'Hi {name}, your application {application_no} is approved. Installation will be scheduled soon. - {company}'],
        'installation_schedule' => ['Installation schedule', 'Hi {name}, your installation ({wo_no}) is set on {date} {slot}. Technician: {technician}. - {company}'],
        'service_activated' => ['Service activated', 'Hi {name}, account {account_no} ({plan}) is now active. Welcome to {company}!'],
        'invoice_generated' => ['Invoice {invoice_no}', 'Hi {name}, your bill {invoice_no} for {period} is {amount}, due {due_date}. Account {account_no}. - {company}'],
        'due_reminder' => ['Payment reminder', 'Reminder: {amount} for account {account_no} is due on {due_date}. Pay early to keep your service. - {company}'],
        'payment_overdue' => ['Payment overdue', 'Hi {name}, {amount} on account {account_no} is overdue since {due_date}. Please settle to avoid disconnection. - {company}'],
        'final_notice' => ['Final disconnection notice', 'FINAL NOTICE: account {account_no} will be disconnected after {date} for unpaid {amount}. Pay now to avoid interruption. - {company}'],
        'service_disconnected' => ['Service disconnected', 'Account {account_no} was disconnected for {reason}. Settle {amount} and the service is restored automatically. - {company}'],
        'payment_received' => ['Payment received', 'Thank you {name}! We received {amount} for account {account_no} (receipt {receipt_no}). Balance: {balance}. - {company}'],
        'service_restored' => ['Service restored', 'Hi {name}, the service of account {account_no} is restored. Thank you! - {company}'],
        'technician_dispatched' => ['Technician dispatched', 'Hi {name}, technician {technician} is on the way for {wo_no}. - {company}'],
        'ticket_resolved' => ['Ticket resolved', 'Hi {name}, ticket {ticket_no} is resolved: {resolution}. Reply if the problem continues. - {company}'],
        'network_outage' => ['Network outage', 'Advisory: service interruption in {area} since {date} ({title}). Our team is working on it. - {company}'],
        'service_maintenance' => ['Scheduled maintenance', 'Advisory: scheduled maintenance in {area} on {date}. Service may be interrupted. - {company}'],
        'contract_expiration' => ['Contract expiring', 'Hi {name}, the contract of account {account_no} ends on {date}. Visit us or reply to renew. - {company}'],
        'payment_pending' => ['Payment reference received', 'Hi {name}, we received your payment reference {reference} for {amount}. It is credited once verified. - {company}'],
    ];

    private static function installTemplates(): string
    {
        $n = 0;
        foreach (self::TEMPLATES as $code => [$subject, $body]) {
            foreach (['sms', 'email', 'inapp'] as $ch) {
                if (!DB::value('SELECT id FROM isp_notif_templates WHERE event_code = :e AND channel = :c', ['e' => $code, 'c' => $ch])) {
                    DB::insert('isp_notif_templates', ['event_code' => $code, 'channel' => $ch, 'subject' => $ch === 'sms' ? null : $subject,
                        'body' => $ch === 'email' ? str_replace(' - {company}', "\n\n{company}", $body) : $body, 'is_active' => 1]);
                    $n++;
                }
            }
        }
        return $n ? "$n notification templates" : '';
    }

    public const JOBS = [
        // code => [name, description, interval minutes, daily hour]
        'billing'        => ['Daily invoice generation', 'Generates (and, when runs need no approval, posts) the invoices of accounts due today', 1440, 1],
        'due_check'      => ['Billing due-date checks', 'Marks unpaid invoices overdue after their due date', 60, null],
        'late_fees'      => ['Late-charge assessment', 'Charges the plan late fee once per overdue invoice', 1440, 2],
        'reminders'      => ['Payment reminders', 'Due-date reminders before the due date and overdue reminders after it', 1440, 8],
        'disc_eval'      => ['Disconnection eligibility evaluation', 'Builds the disconnection queue from the rules and sends the final notices', 120, null],
        'disc_execute'   => ['Disconnection execution', 'Executes approved disconnections inside the rule hours, rechecking eligibility under a lock', 15, null],
        'reconnect'      => ['Reconnection processing', 'Requests and executes reconnections of accounts that qualify after payment', 5, null],
        'net_ops'        => ['Network operation retries', 'Sends queued network operations and retries failed ones within their limit', 5, null],
        'net_reconcile'  => ['Network reconciliation', 'Compares the expected subscriber state with the device state', 360, null],
        'temp_recon'     => ['Expired temporary reconnections', 'Disconnects again the temporary reconnections that expired unpaid', 60, null],
        'contracts'      => ['Contract expiration notices', 'Notifies subscribers of contracts ending within 30 days and marks expired ones', 1440, 9],
        'equipment'      => ['Equipment maintenance reminders', 'Lists infrastructure due for maintenance and equipment pending recovery', 1440, 7],
        'ticket_alerts'  => ['Overdue support-ticket alerts', 'Escalates tickets past their SLA deadline', 30, null],
        'daily_summary'  => ['Daily financial summary', 'Writes the day\'s billing and collection totals to the job log', 1440, 23],
        'backup'         => ['Database backup', 'Writes a full database backup to storage/backups', 1440, 0],
        'notify_retry'   => ['Notification delivery and retries', 'Sends queued SMS / e-mail and retries failed ones within their limit', 5, null],
        'arrangements'   => ['Payment arrangement follow-up', 'Marks missed installments and ends defaulted arrangements', 1440, 3],
    ];

    private static function installJobs(): string
    {
        $n = 0;
        foreach (self::JOBS as $code => [$name, $desc, $every, $hour]) {
            if (!DB::value('SELECT id FROM isp_jobs WHERE job_code = :c', ['c' => $code])) {
                DB::insert('isp_jobs', ['job_code' => $code, 'name' => $name, 'description' => $desc, 'interval_min' => $every, 'run_hour' => $hour, 'is_enabled' => 1, 'max_retries' => 3]);
                $n++;
            }
        }
        return $n ? "$n scheduled jobs" : '';
    }

    private static function installContractTemplate(): string
    {
        if (DB::value('SELECT id FROM isp_contract_templates LIMIT 1')) {
            return '';
        }
        $body = <<<TXT
SUBSCRIPTION AGREEMENT

This agreement is made on {date} between {company}, {company_address} (the Provider), and {name}, {address} (the Subscriber), for account {account_no}.

1. Service. The Provider supplies the {plan} plan: up to {download} Mbps download and {upload} Mbps upload, {fair_use}. Speeds are best effort and depend on the line and the devices used.
2. Fees. The monthly fee is {monthly_fee}{vat_note}, billed {billing}, due {due_days} days after the invoice date. Installation fee {installation_fee}; security deposit {deposit}.
3. Term. The agreement starts on {start_date} for {months} months{lock_in}. An early termination during the lock-in period is charged {etf}.
4. Late payment. An unpaid bill {grace_days} days after its due date may lead to disconnection after a final notice. A reconnection fee of {reconnection_fee} may apply.
5. Equipment. Equipment lent or rented to the Subscriber remains the Provider's property and is returned when the service ends; lost or damaged equipment is charged at cost, less the deposit.
6. Interruptions. Outages beyond {sla_hours} hours caused by the Provider's network are credited in proportion to the downtime, upon verification.
7. Privacy. {privacy}
8. Complaints. Complaints may be filed at any office or through the customer portal, and with the National Telecommunications Commission.

Accepted by the Subscriber: ______________________   Date: __________
For the Provider: ______________________
TXT;
        DB::insert('isp_contract_templates', ['name' => 'Standard Residential / Business Subscription Agreement', 'body' => $body, 'is_default' => 1, 'is_active' => 1]);
        return 'the standard subscription agreement';
    }

    private static function installItemCategories(): string
    {
        $n = 0;
        foreach (['Fiber Optic Cable', 'Coaxial Cable', 'Drop Wire', 'Connectors & Adapters', 'Splitters', 'Routers', 'ONU / ONT', 'Optical Transceivers',
                  'Network Switches', 'Power Supplies', 'Patch Cords', 'Tools', 'Spare Parts', 'Installation Consumables', 'Set-top Boxes'] as $c) {
            if (!DB::value('SELECT id FROM item_categories WHERE name = :n', ['n' => $c])) {
                DB::insert('item_categories', ['name' => $c]);
                $n++;
            }
        }
        return $n ? "$n equipment and material categories" : '';
    }

    /** The scheduler's own account: an administrator account nobody can log into (locked, random password) */
    public static function systemUser(): int
    {
        $id = (int)(DB::value("SELECT id FROM users WHERE username = 'isp.scheduler'") ?: 0);
        if ($id) {
            return $id;
        }
        $id = DB::insert('users', ['username' => 'isp.scheduler', 'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
            'full_name' => 'ISP Scheduler (system)', 'status' => 'locked', 'must_change_password' => 0, 'created_via' => 'system', 'is_platform' => 0]);
        $admin = DB::value('SELECT id FROM roles WHERE is_admin = 1 ORDER BY id LIMIT 1');
        if ($admin) {
            DB::insert('user_roles', ['user_id' => $id, 'role_id' => $admin]);
        }
        return $id;
    }
}
