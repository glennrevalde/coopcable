<?php
declare(strict_types=1);

/**
 * Sales agents and their commissions. The rules are on each agent (documented on the agent form):
 *  - activation commission: once per account, when it is activated: a fixed amount or a percent of the monthly fee;
 *  - first-payment commission (optional): once per account, on its first posted payment: fixed or percent of it;
 *  - a cancellation within the clawback days reverses the commissions not yet paid, and records a clawback against
 *    the next payout for those already paid;
 *  - commissions are approved, then paid as a bill of the agent's supplier record (accounts payable).
 */
final class IspAgents
{
    public const TYPES = ['none' => 'None', 'fixed' => 'Fixed amount', 'percent' => 'Percent'];
    public const STATUSES = ['pending' => 'Pending approval', 'approved' => 'Approved', 'paid' => 'Paid', 'reversed' => 'Reversed', 'rejected' => 'Rejected'];

    private static function add(int $agentId, int $accountId, string $basis, float $amount, string $note): ?int
    {
        $amount = r2($amount);
        if (abs($amount) < 0.005) {
            return null;
        }
        $key = 'A' . $agentId . '-' . $accountId . '-' . $basis;
        if (DB::value('SELECT id FROM isp_commissions WHERE commission_key = :k', ['k' => $key])) {
            return null;
        }
        $id = DB::insert('isp_commissions', ['agent_id' => $agentId, 'account_id' => $accountId, 'basis' => $basis, 'commission_key' => $key, 'amount' => $amount,
            'rule_note' => mb_substr($note, 0, 160), 'status' => 'pending']);
        IspAccounts::event($accountId, 'commission', 'Agent commission (' . str_replace('_', ' ', $basis) . ') ' . money($amount) . ': ' . $note, 'commission', $id);
        return $id;
    }

    public static function onActivation(int $accountId): void
    {
        $a = IspAccounts::get($accountId);
        if (!$a || !$a['agent_id']) {
            return;
        }
        $ag = DB::row('SELECT * FROM isp_agents WHERE id = :i AND is_active = 1', ['i' => $a['agent_id']]);
        if (!$ag || $ag['activation_type'] === 'none') {
            return;
        }
        $fee = IspPlans::priceFor(IspPlans::get((int)$a['plan_id']), $a);
        $amt = $ag['activation_type'] === 'percent' ? $fee * (float)$ag['activation_value'] / 100 : (float)$ag['activation_value'];
        self::add((int)$ag['id'], $accountId, 'activation', $amt, $ag['activation_type'] === 'percent' ? (float)$ag['activation_value'] . '% of ' . money($fee) : 'fixed per activation');
    }

    public static function onFirstPayment(int $accountId, int $paymentId): void
    {
        $a = IspAccounts::get($accountId);
        if (!$a || !$a['agent_id']) {
            return;
        }
        $ag = DB::row('SELECT * FROM isp_agents WHERE id = :i AND is_active = 1', ['i' => $a['agent_id']]);
        if (!$ag || $ag['first_payment_type'] === 'none') {
            return;
        }
        $first = (int)DB::value("SELECT id FROM isp_payments WHERE account_id = :a AND status = 'posted' ORDER BY pay_date, id LIMIT 1", ['a' => $accountId]);
        if ($first !== $paymentId) {
            return;
        }
        $amt = (float)DB::value('SELECT amount FROM isp_payments WHERE id = :p', ['p' => $paymentId]);
        $c = $ag['first_payment_type'] === 'percent' ? $amt * (float)$ag['first_payment_value'] / 100 : (float)$ag['first_payment_value'];
        self::add((int)$ag['id'], $accountId, 'first_payment', $c, $ag['first_payment_type'] === 'percent' ? (float)$ag['first_payment_value'] . '% of the first payment ' . money($amt) : 'fixed on the first payment');
    }

    public static function onCancellation(int $accountId, string $date): void
    {
        $a = IspAccounts::get($accountId);
        if (!$a || !$a['agent_id'] || !$a['activation_date']) {
            return;
        }
        $ag = DB::row('SELECT * FROM isp_agents WHERE id = :i', ['i' => $a['agent_id']]);
        if (!$ag || IspPlans::days($a['activation_date'], $date) - 1 > (int)$ag['clawback_days']) {
            return;
        }
        foreach (DB::all("SELECT * FROM isp_commissions WHERE account_id = :a AND agent_id = :g AND amount > 0 AND status IN ('pending', 'approved', 'paid')", ['a' => $accountId, 'g' => $ag['id']]) as $c) {
            if ($c['status'] === 'paid') {
                self::add((int)$ag['id'], $accountId, 'clawback_' . $c['basis'], -(float)$c['amount'], 'Clawback: cancelled within ' . (int)$ag['clawback_days'] . ' days of activation');
            } else {
                DB::update('isp_commissions', ['status' => 'reversed', 'reversed_reason' => 'Account cancelled within ' . (int)$ag['clawback_days'] . ' days of activation'], 'id = :id', ['id' => $c['id']]);
            }
        }
    }

    public static function approve(array $ids, bool $approve, string $note = ''): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $c = DB::row('SELECT * FROM isp_commissions WHERE id = :id', ['id' => (int)$id]);
            if (!$c || $c['status'] !== 'pending') {
                continue;
            }
            DB::update('isp_commissions', ['status' => $approve ? 'approved' : 'rejected', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s'),
                'reversed_reason' => $approve ? null : mb_substr($note, 0, 255)], 'id = :id', ['id' => $id]);
            $n++;
        }
        return $n;
    }

    /** Pays the approved commissions of an agent (clawbacks netted) with one bill of the agent's supplier record */
    public static function pay(int $agentId, string $date): int
    {
        return DB::tx(function () use ($agentId, $date) {
            $ag = DB::row('SELECT * FROM isp_agents WHERE id = :i', ['i' => $agentId]) ?? fail('Agent not found.');
            $rows = DB::all("SELECT c.*, a.account_no FROM isp_commissions c JOIN isp_accounts a ON a.id = c.account_id WHERE c.agent_id = :g AND c.status = 'approved' ORDER BY c.id", ['g' => $agentId]);
            $total = r2(array_sum(array_map(fn($r) => (float)$r['amount'], $rows)));
            if (!$rows || $total <= 0) {
                fail('There is no approved commission to pay (or the clawbacks are more than the commissions).');
            }
            $vendor = (int)($ag['vendor_id'] ?: 0);
            if (!$vendor) {
                $party = Party::create(['party_type' => 'person', 'legal_name' => $ag['name'], 'mobile' => $ag['mobile'], 'email' => $ag['email']], null, null, 'isp.agents');
                $vendor = Party::grant($party, 'supplier', 'Sales agent ' . $ag['code'] . ' (commissions)', [], 'isp.agents');
                DB::run('UPDATE isp_agents SET vendor_id = :v WHERE id = :i', ['v' => $vendor, 'i' => $agentId]);
            }
            $txn = IspPosting::postCommissionBill($agentId, $vendor, $rows, $date);
            DB::run("UPDATE isp_commissions SET status = 'paid', bill_txn_id = :t WHERE agent_id = :g AND status = 'approved'", ['t' => $txn, 'g' => $agentId]);
            Audit::log('pay', 'isp', 'isp_agent', $agentId, (string)$ag['code'], ['amount' => $total]);
            return $txn;
        });
    }
}
