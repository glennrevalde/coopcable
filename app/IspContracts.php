<?php
declare(strict_types=1);

/** Subscriber contracts: made from the template at activation, accepted, renewed, amended, terminated; renewal notices */
final class IspContracts
{
    public const STATUSES = ['draft' => 'Draft', 'active' => 'Active', 'amended' => 'Amended', 'renewed' => 'Renewed', 'terminated' => 'Terminated', 'expired' => 'Ended (month to month)'];

    public static function render(string $body, array $a, string $date): string
    {
        $plan = IspPlans::get((int)$a['plan_id']);
        $fee = IspPlans::priceFor($plan, $a);
        $rule = IspDisconnect::rule('overdue');
        $months = (int)$plan['contract_months'];
        $vars = [
            'date' => fdate($date), 'company' => Settings::get('company_name'), 'company_address' => Settings::get('company_address'), 'name' => $a['account_name'],
            'address' => $a['install_address'], 'account_no' => $a['account_no'], 'plan' => $plan['name'], 'download' => rtrim(rtrim((string)$plan['download_mbps'], '0'), '.'),
            'upload' => rtrim(rtrim((string)$plan['upload_mbps'], '0'), '.'), 'fair_use' => $plan['fair_use_policy'] ?: ($plan['data_cap_gb'] ? $plan['data_cap_gb'] . ' GB data allowance' : 'no data cap'),
            'monthly_fee' => money($fee), 'vat_note' => IspSetup::vatRegistered() && $plan['vat_mode'] === 'inclusive' ? ' (VAT inclusive)' : (IspSetup::vatRegistered() && $plan['vat_mode'] === 'exclusive' ? ' plus VAT' : ''),
            'billing' => $a['bill_timing'] === 'arrears' ? 'monthly in arrears' : 'monthly in advance', 'due_days' => (string)$a['due_days'], 'installation_fee' => money($plan['installation_fee']),
            'deposit' => money($a['security_deposit']), 'start_date' => fdate($date), 'months' => (string)$months,
            'lock_in' => (int)$plan['lock_in_months'] ? ', with a lock-in period of ' . (int)$plan['lock_in_months'] . ' months' : '', 'etf' => money($plan['early_termination_fee']),
            'grace_days' => (string)($rule['grace_days'] ?? 5), 'reconnection_fee' => money($plan['reconnection_fee']), 'sla_hours' => '24', 'privacy' => IspSetup::get('isp_privacy_notice'),
        ];
        return IspNotify::render($body, $vars);
    }

    public static function createFor(int $accountId, string $date): int
    {
        $a = IspAccounts::get($accountId);
        if ($have = DB::value("SELECT id FROM isp_contracts WHERE account_id = :a AND status IN ('active', 'draft')", ['a' => $accountId])) {
            return (int)$have;
        }
        $tpl = DB::row('SELECT * FROM isp_contract_templates WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1');
        $plan = IspPlans::get((int)$a['plan_id']);
        $app = $a['application_id'] ? DB::row('SELECT contract_accepted_at, contract_accepted_name FROM isp_applications WHERE id = :i', ['i' => $a['application_id']]) : null;
        $id = DB::insert('isp_contracts', ['contract_no' => Seq::next('isp_contract'), 'account_id' => $accountId, 'template_id' => $tpl['id'] ?? null, 'plan_id' => $plan['id'],
            'start_date' => $date, 'end_date' => $a['contract_end'], 'lock_in_months' => (int)$plan['lock_in_months'], 'early_termination_fee' => $plan['early_termination_fee'],
            'body_snapshot' => $tpl ? self::render((string)$tpl['body'], $a, $date) : null, 'accepted_at' => $app['contract_accepted_at'] ?? null,
            'accepted_name' => $app['contract_accepted_name'] ?? null, 'status' => 'active', 'created_by' => Auth::id()]);
        IspAccounts::event($accountId, 'contract', 'Contract ' . DB::value('SELECT contract_no FROM isp_contracts WHERE id = :i', ['i' => $id]) . ' from ' . fdate($date) . ($a['contract_end'] ? ' to ' . fdate($a['contract_end']) : ''), 'contract', $id);
        return $id;
    }

    /** A renewal: the current contract is marked renewed and a new one starts the day after it ends */
    public static function renew(int $contractId, int $months, string $acceptedBy): int
    {
        return DB::tx(function () use ($contractId, $months, $acceptedBy) {
            $c = DB::row('SELECT * FROM isp_contracts WHERE id = :id FOR UPDATE', ['id' => $contractId]) ?? fail('Contract not found.');
            if (!in_array($c['status'], ['active', 'expired'], true)) {
                fail('Only an active or ended contract is renewed.');
            }
            if ($months < 1) {
                fail('Enter the months of the renewal.');
            }
            $a = IspAccounts::get((int)$c['account_id']);
            $start = $c['end_date'] && $c['end_date'] >= today() ? IspPlans::addDays($c['end_date'], 1) : today();
            $end = IspPlans::addDays(IspPlans::addMonths($start, $months), -1);
            DB::update('isp_contracts', ['status' => 'renewed'], 'id = :id', ['id' => $contractId]);
            $tpl = DB::row('SELECT * FROM isp_contract_templates WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1');
            $id = DB::insert('isp_contracts', ['contract_no' => Seq::next('isp_contract'), 'account_id' => $c['account_id'], 'template_id' => $tpl['id'] ?? null, 'plan_id' => $a['plan_id'],
                'start_date' => $start, 'end_date' => $end, 'lock_in_months' => 0, 'early_termination_fee' => 0, 'body_snapshot' => $tpl ? self::render((string)$tpl['body'], $a, $start) : null,
                'accepted_at' => date('Y-m-d H:i:s'), 'accepted_name' => mb_substr($acceptedBy, 0, 160), 'accepted_ip' => client_ip(), 'status' => 'active', 'amends_id' => $contractId, 'created_by' => Auth::id()]);
            DB::update('isp_accounts', ['contract_end' => $end], 'id = :id', ['id' => $c['account_id']]);
            IspAccounts::event((int)$c['account_id'], 'contract', 'Contract renewed for ' . $months . ' months, to ' . fdate($end), 'contract', $id);
            return $id;
        });
    }

    /** The daily job: notices 30 days before the end; ended contracts continue month to month */
    public static function notices(): int
    {
        $n = 0;
        foreach (DB::all("SELECT * FROM isp_contracts WHERE status = 'active' AND end_date IS NOT NULL AND end_date BETWEEN :t AND :e", ['t' => today(), 'e' => IspPlans::addDays(today(), 30)]) as $c) {
            $n += IspNotify::event('contract_expiration', (int)$c['account_id'], ['date' => fdate($c['end_date'])], 'CTX-' . $c['id']);
        }
        foreach (DB::all("SELECT * FROM isp_contracts WHERE status = 'active' AND end_date IS NOT NULL AND end_date < :t", ['t' => today()]) as $c) {
            DB::update('isp_contracts', ['status' => 'expired'], 'id = :id', ['id' => $c['id']]);
            IspAccounts::event((int)$c['account_id'], 'contract', 'Contract ' . $c['contract_no'] . ' ended; the service continues month to month', 'contract', (int)$c['id']);
            $n++;
        }
        return $n;
    }
}
