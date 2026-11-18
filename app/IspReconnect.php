<?php
declare(strict_types=1);

/**
 * Reconnection (Workflow D): after a verified payment (or an approved credit / arrangement) the account's eligibility
 * is recalculated; a disconnection for nonpayment is reconnected automatically when the qualifying arrears are paid
 * (or the paid share the policy asks for), the reconnection fee is charged as the policy says, and the network is told
 * to restore the service. The account shows Active only when the device confirms; a failed reconnection stays visible
 * and becomes a task for the network administrator.
 *
 * Kinds: automatic, manual (authorized), temporary (with an expiry), arrangement, partial (partial-payment policy),
 * emergency (restored first, settled later).
 */
final class IspReconnect
{
    public const TYPES = ['automatic' => 'Automatic (payment)', 'manual' => 'Manual (authorized)', 'temporary' => 'Temporary (expires)', 'arrangement' => 'Under a payment arrangement',
        'partial' => 'Partial-payment policy', 'emergency' => 'Emergency restoration'];
    public const STATUSES = ['requested' => 'Requested', 'pending_approval' => 'Waiting for approval', 'approved' => 'Approved', 'executing' => 'Executing', 'restored' => 'Restored (confirmed)',
        'failed' => 'Failed', 'cancelled' => 'Cancelled', 'expired' => 'Expired (temporary)'];

    /** What still blocks an automatic reconnection: null when the account qualifies */
    public static function blocker(array $a): ?string
    {
        if ($a['service_status'] !== 'disconnected') {
            return 'the account is not disconnected';
        }
        $last = DB::row("SELECT * FROM isp_disc_queue WHERE account_id = :a AND status = 'disconnected' ORDER BY id DESC LIMIT 1", ['a' => $a['id']]);
        if ($last && in_array($last['reason_type'], IspDisconnect::BLOCKING, true)) {
            return 'it was disconnected for ' . strtolower(IspDisconnect::REASON_TYPES[$last['reason_type']]) . ': an authorized reconnection is needed';
        }
        if (IspSetup::get('isp_auto_reconnect') !== '1') {
            return 'automatic reconnection is switched off';
        }
        $rule = IspDisconnect::rule('overdue') ?? ['grace_days' => 0, 'exclude_types' => 'deposit', 'protect_disputes' => 1, 'protect_arrangements' => 1];
        $ar = IspDisconnect::arrears((int)$a['id'], $rule);
        if ($ar['protected']) {
            // an approved arrangement or exemption: the service is restored while it is kept
            return null;
        }
        if ($ar['amount'] <= 0.004) {
            return null;
        }
        $pct = max(0.0, min(100.0, IspSetup::float('isp_partial_recon_pct')));
        if ($pct < 100) {
            // the share of the arrears at disconnection that must be paid
            $atDisc = (float)($last['eligible_amount'] ?? $ar['amount']);
            $paid = $atDisc - $ar['amount'];
            if ($atDisc > 0 && $paid / $atDisc * 100 + 0.0001 >= $pct) {
                return null;
            }
        }
        return money($ar['amount']) . ' of qualifying arrears is still unpaid';
    }

    /** After a payment, a credit or an arrangement: reconnect the account when it now qualifies (or cancel its queue item) */
    public static function onPayment(int $accountId, ?int $paymentId): ?int
    {
        $a = IspAccounts::get($accountId);
        if (!$a) {
            return null;
        }
        if ($a['service_status'] === 'active') {
            // still connected: a waiting disconnection that no longer qualifies is dropped
            $rule = IspDisconnect::rule('overdue');
            foreach (DB::all("SELECT id FROM isp_disc_queue WHERE account_id = :a AND reason_type = 'overdue' AND status IN ('candidate', 'approved', 'notified')", ['a' => $accountId]) as $q) {
                if ($rule && !IspDisconnect::eligible($a, $rule)[0]) {
                    IspDisconnect::skipped((int)$q['id'], 'Paid: the arrears no longer qualify');
                }
            }
            return null;
        }
        // a reconnection already approved and waiting (its prepaid fee) goes ahead as soon as it is paid
        $waiting = DB::value("SELECT id FROM isp_reconnections WHERE live_key = :k AND status = 'approved'", ['k' => (string)$accountId]);
        if ($waiting) {
            self::execute((int)$waiting);
            return (int)$waiting;
        }
        if (self::blocker($a) !== null) {
            return null;
        }
        $type = $paymentId ? 'automatic' : (DB::value("SELECT id FROM isp_arrangements WHERE account_id = :a AND status = 'active'", ['a' => $accountId]) ? 'arrangement' : 'automatic');
        if (IspSetup::float('isp_partial_recon_pct') < 100 && IspAccounts::balances($accountId)['overdue'] > 0.004 && $type === 'automatic') {
            $type = 'partial';
        }
        return self::create($accountId, $type, 'Qualifying arrears settled' . ($paymentId ? ' by payment ' . DB::value('SELECT receipt_no FROM isp_payments WHERE id = :p', ['p' => $paymentId]) : ''), $paymentId, null, true);
    }

    /** A reconnection asked by a person: manual, temporary (with expiry), emergency */
    public static function manual(int $accountId, string $type, string $reason, ?string $expiresAt = null): int
    {
        if (!isset(self::TYPES[$type]) || $type === 'automatic') {
            fail('Choose the kind of reconnection.');
        }
        if (trim($reason) === '') {
            fail('Enter the reason for the reconnection.');
        }
        if (!Access::special('isp_reconnect')) {
            fail('Reconnecting by hand needs the permission "Cable & Internet: can reconnect subscribers manually".');
        }
        if ($type === 'temporary' && (!$expiresAt || strtotime($expiresAt) <= time())) {
            $expiresAt = date('Y-m-d H:i:s', time() + IspSetup::int('isp_temp_recon_hours') * 3600);
        }
        return self::create($accountId, $type, $reason, null, $expiresAt, true)
            ?? fail('A reconnection of this account is already in progress (see Reconnections).');
    }

    private static function create(int $accountId, string $type, string $reason, ?int $paymentId, ?string $expiresAt, bool $approved): ?int
    {
        $id = DB::tx(function () use ($accountId, $type, $reason, $paymentId, $expiresAt, $approved) {
            $a = IspAccounts::get($accountId, true);
            if ($a['service_status'] !== 'disconnected') {
                fail('Account ' . $a['account_no'] . ' is not disconnected.');
            }
            if ($live = DB::row('SELECT * FROM isp_reconnections WHERE live_key = :k', ['k' => (string)$accountId])) {
                return null;
            }
            $plan = IspPlans::get((int)$a['plan_id']);
            $rule = IspDisconnect::rule('overdue');
            $fee = $rule && $rule['reconnection_fee'] !== null ? (float)$rule['reconnection_fee'] : (float)$plan['reconnection_fee'];
            $policy = IspSetup::get('isp_recon_fee_policy');
            $last = DB::row("SELECT * FROM isp_disc_queue WHERE account_id = :a AND status = 'disconnected' ORDER BY id DESC LIMIT 1", ['a' => $accountId]);
            if (!$last || $last['reason_type'] !== 'overdue' || in_array($type, ['emergency'], true)) {
                $fee = 0.0;
            }
            $id = DB::insert('isp_reconnections', ['recon_no' => Seq::next('isp_recon'), 'account_id' => $accountId, 'recon_type' => $type, 'status' => $approved ? 'approved' : 'pending_approval',
                'payment_id' => $paymentId, 'live_key' => (string)$accountId, 'fee_amount' => $policy === 'none' ? 0 : r2($fee), 'expires_at' => $expiresAt,
                'reason' => mb_substr($reason, 0, 255), 'approved_by' => $approved ? Auth::id() : null, 'approved_at' => $approved ? date('Y-m-d H:i:s') : null, 'requested_by' => Auth::id()]);
            if ($fee > 0 && $policy !== 'none') {
                $cid = IspBilling::addCharge($accountId, 'reconnection_fee', 'Reconnection fee', $fee, today(), 'RECON-' . $id, $plan['vat_mode'], null, 'reconnection', $id);
                DB::run('UPDATE isp_reconnections SET fee_charge_id = :c WHERE id = :id', ['c' => $cid, 'id' => $id]);
                if ($policy === 'prepay') {
                    // the fee is billed now and must be paid before the service is restored
                    IspBilling::chargesInvoice($accountId, today());
                }
            }
            IspAccounts::event($accountId, 'reconnection', self::TYPES[$type] . ' reconnection requested: ' . $reason . ($expiresAt ? ' (until ' . fdatetime($expiresAt) . ')' : ''), 'reconnection', $id);
            return $id;
        });
        if ($id) {
            self::execute($id);
        }
        return $id;
    }

    /** Approves a reconnection waiting for approval and sends it to the network */
    public static function approve(int $id, string $note = ''): string
    {
        if (!Access::special('isp_reconnect')) {
            fail('Approving reconnections needs the permission "Cable & Internet: can reconnect subscribers manually".');
        }
        DB::tx(function () use ($id, $note) {
            $r = DB::row('SELECT * FROM isp_reconnections WHERE id = :id FOR UPDATE', ['id' => $id]) ?? fail('Reconnection not found.');
            if (!in_array($r['status'], ['requested', 'pending_approval'], true)) {
                fail('Reconnection ' . $r['recon_no'] . ' is not waiting for approval.');
            }
            DB::update('isp_reconnections', ['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            IspAccounts::event((int)$r['account_id'], 'reconnection', 'Reconnection ' . $r['recon_no'] . ' approved' . ($note !== '' ? ': ' . $note : ''), 'reconnection', $id);
            Audit::log('approve', 'ispnet', 'isp_reconnection', $id, (string)$r['recon_no'], ['reason' => $note]);
        });
        return self::execute($id);
    }

    public static function execute(int $id): string
    {
        $r = DB::row('SELECT * FROM isp_reconnections WHERE id = :id', ['id' => $id]);
        if (!$r || !in_array($r['status'], ['approved'], true)) {
            return 'skipped';
        }
        if ($r['fee_charge_id'] && IspSetup::get('isp_recon_fee_policy') === 'prepay') {
            $inv = DB::value('SELECT invoice_id FROM isp_charges WHERE id = :c', ['c' => $r['fee_charge_id']]);
            if ($inv && (float)DB::value('SELECT balance FROM isp_invoices WHERE id = :i', ['i' => $inv]) > 0.004) {
                IspAccounts::event((int)$r['account_id'], 'reconnection', 'Reconnection waits for the payment of the reconnection fee', 'reconnection', $id);
                return 'waiting';
            }
        }
        DB::update('isp_reconnections', ['status' => 'executing', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        $op = IspNetwork::request((int)$r['account_id'], 'reconnect', 'Reconnection ' . $r['recon_no'] . ': ' . $r['reason'], 'reconnection', $id, false);
        DB::run('UPDATE isp_reconnections SET net_op_id = :o WHERE id = :id', ['o' => $op, 'id' => $id]);
        return IspNetwork::execute($op);
    }

    /** Called just before the command is sent: a reason to stop, or null */
    public static function recheck(int $id): ?string
    {
        $r = DB::row('SELECT * FROM isp_reconnections WHERE id = :id', ['id' => $id]);
        if (!$r || $r['status'] !== 'executing') {
            return 'the reconnection is no longer waiting';
        }
        $a = IspAccounts::get((int)$r['account_id'], true);
        if (in_array($a['service_status'], ['terminated', 'cancelled'], true)) {
            return 'the account is ' . $a['service_status'];
        }
        if (in_array($r['recon_type'], ['automatic', 'partial', 'arrangement'], true) && ($b = self::blocker($a)) !== null) {
            return ucfirst($b) . ' (checked just before sending; was a payment reversed?)';
        }
        return null;
    }

    public static function confirmed(int $id, int $opId): void
    {
        $r = DB::row('SELECT * FROM isp_reconnections WHERE id = :id', ['id' => $id]);
        if (!$r || $r['status'] === 'restored') {
            return;
        }
        DB::update('isp_reconnections', ['status' => 'restored', 'restored_at' => date('Y-m-d H:i:s'), 'live_key' => $r['recon_type'] === 'temporary' ? $r['live_key'] : null,
            'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        $a = IspAccounts::get((int)$r['account_id']);
        if ($a['service_status'] === 'disconnected') {
            IspAccounts::setStatus((int)$a['id'], 'active', 'Reconnected (' . strtolower(self::TYPES[$r['recon_type']]) . ')', ['reconnected_at' => date('Y-m-d H:i:s'), 'disconnect_reason' => null]);
            IspBilling::resumeFrom((int)$a['id'], today());
        }
        IspNotify::event('service_restored', (int)$a['id'], [], 'RCN-' . $id);
    }

    public static function failed(int $id, string $message): void
    {
        $r = DB::row('SELECT * FROM isp_reconnections WHERE id = :id', ['id' => $id]);
        if (!$r) {
            return;
        }
        DB::update('isp_reconnections', ['status' => 'failed', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        IspAccounts::event((int)$r['account_id'], 'reconnection', 'Reconnection ' . $r['recon_no'] . ' FAILED; the service is NOT restored: ' . $message, 'reconnection', $id);
    }

    public static function cancelled(int $id, string $why): void
    {
        DB::update('isp_reconnections', ['status' => 'cancelled', 'live_key' => null, 'reason' => mb_substr($why, 0, 255), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    }

    public static function cancel(int $id, string $reason): void
    {
        $r = DB::row('SELECT * FROM isp_reconnections WHERE id = :id', ['id' => $id]) ?? fail('Reconnection not found.');
        if (!in_array($r['status'], ['requested', 'pending_approval', 'approved', 'failed'], true)) {
            fail('Only a waiting or failed reconnection is cancelled.');
        }
        self::cancelled($id, 'Cancelled: ' . $reason);
        IspAccounts::event((int)$r['account_id'], 'reconnection', 'Reconnection ' . $r['recon_no'] . ' cancelled: ' . $reason, 'reconnection', $id);
    }

    /** Temporary reconnections that expired with arrears still unpaid are disconnected again */
    public static function expireTemporary(): int
    {
        $n = 0;
        foreach (DB::all("SELECT * FROM isp_reconnections WHERE recon_type = 'temporary' AND status = 'restored' AND expires_at IS NOT NULL AND expires_at <= :n", ['n' => date('Y-m-d H:i:s')]) as $r) {
            DB::update('isp_reconnections', ['status' => 'expired', 'live_key' => null], 'id = :id', ['id' => $r['id']]);
            $a = IspAccounts::get((int)$r['account_id']);
            $rule = IspDisconnect::rule('overdue');
            if ($a['service_status'] === 'active' && $rule && IspDisconnect::eligible($a, $rule)[0]) {
                IspSetup::asSystem(function () use ($a, $r) {
                    $q = DB::insert('isp_disc_queue', ['queue_no' => Seq::next('isp_disc'), 'account_id' => $a['id'], 'reason_type' => 'overdue', 'reason' => 'Temporary reconnection ' . $r['recon_no'] . ' expired unpaid',
                        'eligible_amount' => IspAccounts::balances((int)$a['id'])['overdue'], 'live_key' => (string)$a['id'], 'status' => 'notified', 'execute_after' => date('Y-m-d H:i:s'),
                        'approved_by' => $r['approved_by'], 'approved_at' => date('Y-m-d H:i:s'), 'initiated_by' => 'scheduler']);
                    IspDisconnect::execute($q, true);
                });
            }
            $n++;
        }
        return $n;
    }
}
